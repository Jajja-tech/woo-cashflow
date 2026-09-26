<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Sync_Pull
 *
 * Pull-based order-edit sync: CashFlow → WooCommerce (R1b of the sync
 * redesign). The backend queues an order edit as a JOB; this class polls
 * for jobs once a minute, applies each one through WooCommerce's OWN REST
 * order machinery (never hand-rolled money math), and acks each job with
 * the outcome + the full REST-serialized order.
 *
 * WHY PULL, NOT PUSH: the backend's direct writes to wp-json are
 * intermittently answered by the host's bot-challenge edge (SiteGround
 * Anti-Bot, Cloudflare IP scoring) INSTEAD of WordPress — a datacenter-IP
 * problem no header fix permanently solves. An outbound request from this
 * site to CashFlow has no such wall.
 *
 * THE TWO WALLS AGAINST DOUBLE-APPLYING (the doubling bug this system
 * exists to prevent):
 *   1. Reconcile: shipping/fee lines arrive ID-LESS from the backend (it
 *      cannot know live WC item ids). Passing an id-less line to WC APPENDS
 *      a duplicate, so desired lines are matched to existing lines by key
 *      (method_id / name) in PHP first — mirror of the backend's
 *      reconcileLinesByKey (woocommerce.adapter.js).
 *   2. Idempotence: every applied job stamps `cashflow_sync_key` order meta
 *      in the SAME save as the economics. A redelivered job (lease expiry,
 *      lost ack) whose sync_key already matches acks `already_applied`
 *      without touching the order.
 *
 * Plus the conflict guard: the user's explicit no-last-write-wins decision.
 * If the order's date_modified moved past the job's base_modified_at, the
 * edit is NOT applied and the backend is told `conflict`.
 *
 * Scheduling uses Action Scheduler (bundled with WooCommerce, which this
 * plugin hard-requires): its claim-locking means two overlapping runners
 * cannot double-pull, which raw wp_schedule_event cannot guarantee.
 */
class CashFlow_Sync_Pull {

    const TICK_HOOK     = 'cashflow_sync_pull_tick';
    const AS_GROUP      = 'cashflow';
    const STATS_OPTION  = 'cashflow_sync_pull_stats';
    const SYNC_KEY_META = 'cashflow_sync_key';
    const POLL_LIMIT    = 3;

    /**
     * What this plugin can DO, declared on every poll. The backend hands out a
     * command only for a capability declared in that same poll — never by
     * comparing version numbers — so a store on an older plugin simply never
     * receives work it cannot run, and nothing waits on it by accident.
     * Add a capability here only when the code that runs it ships with it.
     */
    // catalog.push@1: this plugin pushes its own products to CashFlow's catalogue routes
    // (CashFlow_Catalog). Declared here because the poll is where CashFlow
    // learns what a store can do; the frontend shows "Resend catalogue" only
    // for a store that has declared it. It is NOT a command capability —
    // run_command still refuses anything but order.create.
    const SUPPORTS = [ 'order.create@1', 'catalog.push@1' ];
    const INTERVAL      = 60; // seconds

    public function __construct() {
        // Register on init: Action Scheduler's as_* functions are guaranteed
        // registered by then (AS bootstraps on plugins_loaded priority 0).
        add_action( 'init', [ $this, 'maybe_schedule' ] );
        add_action( self::TICK_HOOK, [ $this, 'tick' ] );
    }

    // ── Scheduling ──────────────────────────────────────────────────

    /** Action Scheduler present? (It ships inside WooCommerce.) */
    public static function is_available() {
        return function_exists( 'as_schedule_recurring_action' )
            && function_exists( 'as_next_scheduled_action' );
    }

    /** Is the recurring tick scheduled (or running right now)? */
    public static function is_scheduled() {
        if ( ! self::is_available() ) { return false; }
        // as_next_scheduled_action returns a timestamp, false, or true
        // (true = running at this moment) — all we surface is the boolean.
        return (bool) as_next_scheduled_action( self::TICK_HOOK );
    }

    public function maybe_schedule() {
        // Action Scheduler missing is NOT a silent no-op: the admin panel's
        // Pull Sync card reads is_available() and shows an explicit
        // "unavailable" row (admin/views/settings.php).
        if ( ! self::is_available() ) { return; }
        if ( as_next_scheduled_action( self::TICK_HOOK ) ) { return; }
        // $unique=true: two concurrent requests can BOTH pass the pre-check
        // above (read-then-schedule race) and register two recurring ticks
        // — double polling forever. AS ≥3.4 refuses the duplicate itself;
        // on an older AS the surplus arg is silently ignored by PHP and
        // the pre-check remains the (imperfect) guard.
        as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::TICK_HOOK, [], self::AS_GROUP, true );
    }

    // ── The tick ────────────────────────────────────────────────────

    /**
     * One poll cycle. NEVER throws out of the tick — an exception here
     * would mark the Action Scheduler action failed and spam its log;
     * the next tick is the retry mechanism.
     */
    public function tick() {
        try {
            $this->run_tick();
        } catch ( Throwable $e ) {
            CashFlow_Plugin::log( 'sync_pull_poll', 'store', 0, 'error', 'Tick crashed: ' . $e->getMessage() );
            self::update_stats( [ 'last_error' => 'Tick crashed: ' . $e->getMessage() ] );
        }
    }

    private function run_tick() {
        // Bail fast when not configured — the same option class-advance
        // reads for its api() calls. Not an error: an unconnected store
        // has nothing to pull, and the panel already says "Not connected".
        $secret = get_option( 'cashflow_connection_secret', '' );
        if ( empty( $secret ) ) { return; }

        // Same transport + auth as class-advance's api(): the connection
        // secret rides as `Authorization: Bearer <secret>` via
        // CashFlow_Plugin::api_request(). Never Authorization: Basic — WP
        // core's Application Passwords eats Basic on custom namespaces;
        // irrelevant outbound, but the convention stands app-wide.
        $poll_body = [
            'version'  => CASHFLOW_VERSION,
            'limit'    => self::POLL_LIMIT,
            'supports' => self::SUPPORTS,
        ];
        // ABSENT means "not known this poll" (the backend keeps whatever it
        // already stored) — so a tick that could not read WC's gateways at
        // all must never send the key, let alone send it empty. An EMPTY
        // array is a real, positive report ("this store has no enabled
        // gateway right now") and is sent.
        $gateways = self::enabled_gateways();
        if ( null !== $gateways ) {
            $poll_body['gateways'] = $gateways;
        }

        $res = CashFlow_Plugin::api_request( '/plugin/sync/poll', 'POST', $poll_body, $secret );

        if ( empty( $res['ok'] ) ) {
            $why = self::describe_failure( $res );
            CashFlow_Plugin::log( 'sync_pull_poll', 'store', 0, 'error', 'Poll failed: ' . $why );
            self::update_stats( [ 'last_error' => 'Poll failed: ' . $why ] );
            return; // next tick retries
        }

        // An empty jobs array is a heartbeat — still a successful poll.
        // Heartbeats update the stats option only, never the sync log
        // (one row a minute would drown the Activity tab). last_error is
        // deliberately STICKY — a healthy heartbeat one minute after a
        // failed job must not erase the evidence the panel exists to show;
        // only a newer error replaces it.
        self::update_stats( [ 'last_poll_at' => gmdate( 'c' ) ] );

        $jobs = ( is_array( $res['data'] ) && isset( $res['data']['jobs'] ) && is_array( $res['data']['jobs'] ) )
            ? $res['data']['jobs']
            : [];

        foreach ( $jobs as $job ) {
            if ( ! is_array( $job ) || empty( $job['job_id'] ) ) {
                // Can't even ack a job without its id — log and move on.
                CashFlow_Plugin::log( 'sync_pull_apply', 'order', 0, 'error', 'Skipped a job with no job_id' );
                continue;
            }
            // Each job in its own try/catch: one poisoned job must not
            // abort the batch or stop its siblings being acked.
            try {
                $result = $this->apply_job( $job );
            } catch ( Throwable $e ) {
                $result = [ 'outcome' => 'failed', 'error' => 'Apply crashed: ' . $e->getMessage() ];
            }
            $this->ack( $secret, $job, $result );
        }

        $commands = ( is_array( $res['data'] ) && isset( $res['data']['commands'] ) && is_array( $res['data']['commands'] ) )
            ? $res['data']['commands']
            : [];

        foreach ( $commands as $command ) {
            if ( ! is_array( $command ) || empty( $command['command_id'] ) ) {
                CashFlow_Plugin::log( 'sync_pull_command', 'order', 0, 'error', 'Skipped a command with no command_id' );
                continue;
            }
            try {
                $result = $this->run_command( $command );
            } catch ( Throwable $e ) {
                $result = [ 'outcome' => 'failed', 'error' => [ 'code' => 'exception', 'message' => 'Command crashed: ' . $e->getMessage() ] ];
            }
            $this->ack_command( $secret, $command, $result );
        }
    }

    // ── Commands ────────────────────────────────────────────────────

    /**
     * Run one command. Only a kind AND capability this build declares is run;
     * anything else is acked `unsupported` — loudly, so a backend that sent
     * work we never declared learns about it instead of waiting forever.
     *
     * @return array { outcome, order?, error?: { code, message } }
     */
    private function run_command( array $command ) {
        $kind       = isset( $command['kind'] ) ? (string) $command['kind'] : '';
        $capability = isset( $command['capability'] ) ? (string) $command['capability'] : '';

        if ( 'order.create' !== $kind || 'order.create@1' !== $capability || ! in_array( $capability, self::SUPPORTS, true ) ) {
            return [ 'outcome' => 'unsupported', 'error' => [ 'code' => 'unsupported_command',
                'message' => sprintf( 'This plugin (v%s) does not run %s / %s.', CASHFLOW_VERSION, $kind ?: '?', $capability ?: '?' ) ] ];
        }

        $applier = self::get_applier();
        if ( ! $applier ) {
            return [ 'outcome' => 'failed', 'error' => [ 'code' => 'applier_unavailable', 'message' => 'WooCommerce REST controllers unavailable' ] ];
        }
        return $applier->create( $command );
    }

    /**
     * Ack one command. Like the job ack, a failed ack is logged and NOT
     * retried here: the backend's lease expiry redelivers the command, and
     * create()'s idempotency lookup answers `already_created` — that is
     * exactly the case the key exists for.
     */
    private function ack_command( $secret, array $command, array $result ) {
        $outcome = (string) $result['outcome'];
        $body = [
            'command_id'      => (string) $command['command_id'],
            'idempotency_key' => isset( $command['idempotency_key'] ) ? (string) $command['idempotency_key'] : '',
            'outcome'         => $outcome,
        ];
        if ( isset( $result['order'] ) ) { $body['order'] = $result['order']; }
        if ( isset( $result['error'] ) ) { $body['error'] = $result['error']; }

        $res = CashFlow_Plugin::api_request( '/plugin/commands/ack', 'POST', $body, $secret );

        $wc_id = isset( $result['order']['id'] ) ? (int) $result['order']['id'] : 0;
        $num   = isset( $command['order']['order_number'] ) ? (string) $command['order']['order_number'] : '';
        $why   = isset( $result['error']['message'] ) ? (string) $result['error']['message'] : '';
        self::bump_outcome( $outcome, $why );
        if ( 'created' === $outcome || 'already_created' === $outcome ) {
            CashFlow_Plugin::log( 'sync_pull_command', 'order', $wc_id, 'success',
                ( 'created' === $outcome ? 'Created ' : 'Already created ' ) . $num . ' (command ' . $body['command_id'] . ')' );
        } else {
            CashFlow_Plugin::log( 'sync_pull_command', 'order', $wc_id, 'error',
                $outcome . ' ' . $num . ': ' . ( isset( $result['error']['code'] ) ? $result['error']['code'] . ' — ' : '' ) . $why
                . ' (command ' . $body['command_id'] . ')' );
        }

        if ( empty( $res['ok'] ) ) {
            $fail = self::describe_failure( $res );
            CashFlow_Plugin::log( 'sync_pull_ack', 'order', $wc_id, 'error', 'Ack failed for command ' . $body['command_id'] . ' (' . $outcome . '): ' . $fail );
            self::update_stats( [ 'last_error' => 'Ack failed: ' . $fail ] );
        }
    }

    // ── Apply one job ───────────────────────────────────────────────

    /**
     * Apply a queued CashFlow edit to the live WC order.
     *
     * @param array $job One job from /plugin/sync/poll.
     * @return array { outcome, error?, order? } — the ack payload pieces.
     */
    private function apply_job( $job ) {
        $external_id = isset( $job['external_id'] ) ? (int) $job['external_id'] : 0;
        $sync_key    = isset( $job['sync_key'] ) ? (string) $job['sync_key'] : '';
        if ( $external_id <= 0 || '' === $sync_key ) {
            return [ 'outcome' => 'failed', 'error' => 'malformed job: external_id and sync_key are required' ];
        }

        // Belt: the backend filters advance-carrying intents out of this
        // queue (advance edits are push-only — they have their own money
        // model in class-advance). If one arrives anyway, refuse loudly.
        if ( isset( $job['intent']['advance_amount'] ) ) {
            return [ 'outcome' => 'failed', 'error' => 'advance edits are push-only' ];
        }

        $applier = self::get_applier();
        if ( ! $applier ) {
            return [ 'outcome' => 'failed', 'error' => 'WooCommerce REST controllers unavailable' ];
        }

        // instanceof WC_Order also excludes refund objects, which
        // wc_get_order can return for a refund id.
        $order = wc_get_order( $external_id );
        if ( ! $order instanceof WC_Order ) {
            return [ 'outcome' => 'failed', 'error' => 'order not found in WooCommerce' ];
        }

        // (3b) IDEMPOTENCE — the second wall. A redelivered job (lease
        // expiry after a lost ack) whose sync_key we already stamped means
        // the edit IS on this order: report it applied, touch nothing.
        // Checked BEFORE the conflict guard on purpose: our own apply moved
        // date_modified past base_modified_at, so a redelivery would
        // otherwise always read as a conflict.
        if ( (string) $order->get_meta( self::SYNC_KEY_META ) === $sync_key ) {
            return [ 'outcome' => 'already_applied', 'order' => $applier->serialize( $order ) ];
        }

        // (3c) CONFLICT GUARD — no last-write-wins. COMPARES THE MONEY, NOT
        // THE CLOCK.
        //
        // 🔴 THIS COMPARED date_modified UNTIL 2026-08-06 AND PARKED EVERY
        // SECOND EDIT. date_modified moves on ANY $order->save() — a gateway
        // flip, a WP cron, another plugin, and above all OUR OWN apply of an
        // earlier job for the same order. Observed live on 1SH-33009: job 1
        // applied 09:00:51, job 2 with a byte-identical intent parked 09:03:45
        // with nothing whatsoever changed. The (3b) idempotence check does not
        // rescue it, because the second job carries a DIFFERENT sync_key.
        //
        // The same mistake was made and fixed twice on the backend the same day
        // (syncKeyVerdict.js, then inboundFieldMask.js) before anyone noticed it
        // also lived here. A conflict is only a conflict if the MONEY moved.
        //
        // base_modified_at is still accepted and still recorded as evidence on a
        // parked job — it simply no longer decides anything.
        $base_total    = isset( $job['base_total'] )    && is_numeric( $job['base_total'] )    ? (float) $job['base_total']    : null;
        $base_shipping = isset( $job['base_shipping'] ) && is_numeric( $job['base_shipping'] ) ? (float) $job['base_shipping'] : null;

        if ( null !== $base_total || null !== $base_shipping ) {
            // Money is integer PKR on this fleet; the tolerance absorbs float
            // noise in the JSON round-trip, never real disagreement.
            $eps = 0.01;
            if ( null !== $base_total && abs( (float) $order->get_total() - $base_total ) > $eps ) {
                return [ 'outcome' => 'conflict', 'error' => 'the order total changed in WooCommerce after this edit was queued' ];
            }
            if ( null !== $base_shipping && abs( (float) $order->get_shipping_total() - $base_shipping ) > $eps ) {
                return [ 'outcome' => 'conflict', 'error' => 'the order shipping changed in WooCommerce after this edit was queued' ];
            }
        }
        // No money base recorded = no check ran. Deliberately NOT falling back
        // to the clock: that fallback is the defect this replaced.

        $ops  = ( isset( $job['ops'] ) && is_array( $job['ops'] ) ) ? $job['ops'] : [];
        $body = [ 'id' => $order->get_id() ]; // prepare_object_for_database loads the order from $request['id']

        // line_items pass through VERBATIM — they arrive in the REST v3
        // dialect already (id = update, id-less = add, quantity 0 = remove).
        if ( isset( $ops['lineItems'] ) && is_array( $ops['lineItems'] ) ) {
            $body['line_items'] = $ops['lineItems'];
        }

        // 🔴 STATUS. Absent until 2026-08-07, and the omission was silent:
        // the backend's ops carried no status, this body never asked for one,
        // and the applier had no set_status anywhere — so every confirmation an
        // operator made in CashFlow was saved into WooCommerce with the status
        // untouched, acked back stale, and written over their own decision.
        // 40 reverts across 35 orders in 26 hours, every job acked `applied`.
        //
        // `! empty` deliberately: absent and blank are both "assert nothing",
        // matching buildDesiredOps, which omits the key rather than sending ''.
        // CashFlow's own vocabulary (booked/shipped/returned) rides verbatim —
        // class-statuses.php registers those with WooCommerce.
        if ( ! empty( $ops['status'] ) && is_string( $ops['status'] ) ) {
            $body['status'] = $ops['status'];
        }

        if ( ! empty( $ops['paymentMethod'] ) && is_string( $ops['paymentMethod'] ) ) {
            $body['payment_method']       = $ops['paymentMethod'];
            $body['payment_method_title'] = self::payment_method_title( $ops['paymentMethod'] );
        }

        // 🔴 BILLING / SHIPPING — added 2026-08-17, and the omission here was the
        // last door the address edit could not get through.
        //
        // CashFlow's operators retype unusable addresses so a courier can find
        // the house ("Hfiz.Jamll.matro.asteshan" → "Hafiz Jamal Metro Station").
        // That edit was sent by DIRECT PUSH from CashFlow's server to the store —
        // the one hop the host's bot-wall poisons — so it was refused every time
        // (12 refusals in one morning), and until CashFlow took ownership of the
        // order the next webhook wrote the customer's raw text back over it.
        // 1SH-33042 shipped to Peshawar instead of Hangu because of exactly this.
        //
        // Nothing in the applier needed changing: billing/shipping go through
        // WooCommerce's own prepare_object_for_database, which DOES apply them
        // (unlike status, the one field it skips by design), and the applier
        // already gates calculate_totals on isset($request['billing']) —
        // core's own rule, because an address can move a tax or shipping zone.
        // The body simply never carried them.
        //
        // array_key_exists, not `! empty`: an address is a PARTIAL object by
        // nature (a merchant may correct address_1 and nothing else), so the
        // absent-vs-present distinction is the contract, exactly as for the line
        // categories below. WooCommerce merges the given keys over the existing
        // address, so a partial object updates rather than blanks the rest.
        if ( array_key_exists( 'billing', $ops ) && is_array( $ops['billing'] ) ) {
            $body['billing'] = $ops['billing'];
        }
        if ( array_key_exists( 'shipping', $ops ) && is_array( $ops['shipping'] ) ) {
            $body['shipping'] = $ops['shipping'];
        }

        // (3d) RECONCILE — the first wall. ABSENT key = do not touch that
        // category at all; [] = remove every existing line; entries =
        // desired end-state. array_key_exists, not isset: the absent-vs-
        // present distinction is the whole contract (same absent-vs-empty
        // discipline as /configure's order_prefix handling in class-rest).
        if ( array_key_exists( 'shippingLines', $ops ) ) {
            $body['shipping_lines'] = self::reconcile_lines_by_key(
                self::existing_lines( $order, 'shipping' ),
                is_array( $ops['shippingLines'] ) ? $ops['shippingLines'] : [],
                'method_id'
            );
        }
        if ( array_key_exists( 'feeLines', $ops ) ) {
            $body['fee_lines'] = self::reconcile_lines_by_key(
                self::existing_lines( $order, 'fee' ),
                is_array( $ops['feeLines'] ) ? $ops['feeLines'] : [],
                'name'
            );
        }
        // Coupons are DELIBERATELY NOT id-reconciled: WC core's
        // calculate_coupons() rejects any coupon line carrying an id
        // ("Coupon item ID is readonly.") and already implements desired-
        // end-state natively — it removes every existing coupon and
        // re-applies exactly the listed codes. Sending reconciled
        // {id, code} / {id, code: null} entries would 400 every job.
        // Codes only; [] correctly strips all coupons.
        if ( array_key_exists( 'couponLines', $ops ) ) {
            $body['coupon_lines'] = self::coupon_codes_only( $ops['couponLines'] );
        }

        // Internal REST request — never over HTTP (loopback HTTP is exactly
        // the hop the host's bot-wall can poison). set_body_params puts the
        // payload where WP_REST_Request::get_param resolves it for any
        // method. Values come from our own authenticated backend, so the
        // REST schema validation layer (which only runs on dispatched HTTP
        // requests) is intentionally not re-run here.
        $request = new WP_REST_Request( 'PUT', '/wc/v3/orders/' . $order->get_id() );
        $request->set_body_params( $body );

        $applied = $applier->apply( $request, $sync_key );
        if ( is_wp_error( $applied ) ) {
            return [ 'outcome' => 'failed', 'error' => $applied->get_error_message() ];
        }

        // The backend reads date_modified_gmt from this payload for its
        // sibling-advance bookkeeping — core's serializer provides it.
        return [ 'outcome' => 'applied', 'order' => $applier->serialize( $applied ) ];
    }

    // ── Ack ─────────────────────────────────────────────────────────

    /**
     * Ack one job. If the ack ITSELF fails (network), log and do NOT
     * retry in-loop: the backend's lease expiry redelivers the job, and
     * the sync-key idempotence wall (3b) makes that redelivery safe —
     * that is exactly why 3b exists.
     */
    private function ack( $secret, $job, $result ) {
        $order_id = isset( $job['external_id'] ) ? (int) $job['external_id'] : 0;
        $outcome  = $result['outcome'];

        $body = [ 'job_id' => (string) $job['job_id'], 'outcome' => $outcome ];
        if ( isset( $result['error'] ) ) { $body['error'] = $result['error']; }
        if ( isset( $result['order'] ) ) { $body['order'] = $result['order']; }

        $res = CashFlow_Plugin::api_request( '/plugin/sync/ack', 'POST', $body, $secret );

        // Outcome bookkeeping — one place for stats + sync-log rows.
        self::bump_outcome( $outcome, isset( $result['error'] ) ? (string) $result['error'] : '' );
        if ( 'applied' === $outcome ) {
            CashFlow_Plugin::log( 'sync_pull_apply', 'order', $order_id, 'success', 'Applied CashFlow edit (job ' . $job['job_id'] . ')' );
        } elseif ( 'already_applied' === $outcome ) {
            CashFlow_Plugin::log( 'sync_pull_apply', 'order', $order_id, 'success', 'Already applied — redelivery skipped (job ' . $job['job_id'] . ')' );
        } else {
            CashFlow_Plugin::log( 'sync_pull_apply', 'order', $order_id, 'error', $outcome . ': ' . ( $result['error'] ?? '' ) . ' (job ' . $job['job_id'] . ')' );
        }

        if ( empty( $res['ok'] ) ) {
            $why = self::describe_failure( $res );
            CashFlow_Plugin::log( 'sync_pull_ack', 'order', $order_id, 'error', 'Ack failed for job ' . $job['job_id'] . ' (' . $outcome . '): ' . $why );
            self::update_stats( [ 'last_error' => 'Ack failed: ' . $why ] );
        }
    }

    // ── The applier (WC's own REST machinery) ───────────────────────

    /**
     * CashFlow_Order_Applier extends WC_REST_Orders_Controller, so its
     * file can only be parsed once that class is loadable. WC's autoloader
     * (registered when the WooCommerce main file loads) resolves it via
     * class_exists; if that fails, booting the REST server makes WC load
     * its controllers. Still missing → the job acks failed with a named
     * reason, never a silent skip.
     */
    private static function get_applier() {
        if ( ! class_exists( 'WC_REST_Orders_Controller' ) && function_exists( 'rest_get_server' ) ) {
            rest_get_server(); // fires rest_api_init; idempotent, WC loads its REST controllers
        }
        if ( ! class_exists( 'WC_REST_Orders_Controller' ) ) {
            return null;
        }
        if ( ! class_exists( 'CashFlow_Order_Applier' ) ) {
            require_once CASHFLOW_PLUGIN_DIR . 'includes/class-order-applier.php';
        }
        return new CashFlow_Order_Applier();
    }

    // ── Reconcile (mirror of the backend's reconcileLinesByKey) ─────

    /**
     * Match desired lines to existing lines by the VALUE of $key, so the
     * request addresses live WC item ids instead of re-appending:
     *   - desired matching an existing key  → { id: existing, ...desired } (update in place)
     *   - desired with no match             → { ...desired }               (add — WC creates it)
     *   - existing with no desired match    → { id, $key: null }           (WC removes a line whose key is null)
     * Each existing line is consumed at most once (first unmatched wins),
     * exactly like the backend's `matched` set.
     */
    private static function reconcile_lines_by_key( $existing, $desired, $key ) {
        $ex      = array_values( is_array( $existing ) ? $existing : [] );
        $de      = array_values( is_array( $desired ) ? $desired : [] );
        $out     = [];
        $matched = [];

        foreach ( $de as $d ) {
            if ( ! is_array( $d ) ) { continue; }
            $idx = -1;
            foreach ( $ex as $i => $e ) {
                if ( isset( $matched[ $i ] ) || ! is_array( $e ) ) { continue; }
                if ( isset( $e[ $key ], $d[ $key ] ) && (string) $e[ $key ] === (string) $d[ $key ] ) {
                    $idx = $i;
                    break;
                }
            }
            if ( $idx >= 0 ) {
                $matched[ $idx ] = true;
                $out[] = array_merge( [ 'id' => $ex[ $idx ]['id'] ], $d );
            } else {
                $out[] = $d;
            }
        }
        foreach ( $ex as $i => $e ) {
            if ( ! isset( $matched[ $i ] ) && is_array( $e ) ) {
                $out[] = [ 'id' => $e['id'], $key => null ];
            }
        }
        return $out;
    }

    /**
     * The order's CURRENT lines of one category, reduced to { id, <key> } —
     * all the reconciler needs. HPOS-safe: WC CRUD item accessors only.
     */
    private static function existing_lines( $order, $type ) {
        $lines = [];
        foreach ( $order->get_items( $type ) as $item ) {
            switch ( $type ) {
                case 'shipping':
                    $lines[] = [ 'id' => $item->get_id(), 'method_id' => $item->get_method_id() ];
                    break;
                case 'fee':
                    $lines[] = [ 'id' => $item->get_id(), 'name' => $item->get_name() ];
                    break;
                case 'coupon':
                    $lines[] = [ 'id' => $item->get_id(), 'code' => $item->get_code() ];
                    break;
            }
        }
        return $lines;
    }

    /** Coupon lines stripped to bare codes — see the readonly-id note above. */
    private static function coupon_codes_only( $lines ) {
        $out = [];
        foreach ( ( is_array( $lines ) ? $lines : [] ) as $line ) {
            if ( is_array( $line ) && isset( $line['code'] ) && '' !== (string) $line['code'] ) {
                $out[] = [ 'code' => (string) $line['code'] ];
            }
        }
        return $out;
    }

    /**
     * The store's own enabled payment gateways, reported on every poll so the
     * order editor can offer exactly these — never a fixed cod/bacs stand-in
     * (Golden Rule #6: a store that has not been asked says so; nothing here
     * invents an answer for it).
     *
     * DELIBERATELY `enabled`, never get_available_payment_gateways(): the
     * latter depends on a cart/checkout context (currency, shipping zone,
     * customer country) and returns NOTHING when this runs on a cron tick —
     * it would report every store as having zero payment methods.
     *
     * Never throws. Any failure — WC not ready, a misbehaving gateway's
     * get_title() — is caught and answered as null so one bad gateway does
     * not stop the tick's jobs/commands from being processed. null is also
     * the "not known this poll" signal the caller uses to omit the key
     * entirely, distinct from a genuine empty list.
     *
     * Bounds (matched on the backend, so the two sides can never disagree
     * about what a report means): at most 50 entries, in WooCommerce's own
     * gateway order; id trimmed, 1–100 chars, duplicate ids keep the first
     * seen; title trimmed, empty → the id, over 200 chars → cut to 200.
     *
     * @return array|null [ [ 'id' => string, 'title' => string ], ... ] or null.
     */
    public static function enabled_gateways() {
        try {
            if ( ! function_exists( 'WC' ) || ! is_callable( [ WC(), 'payment_gateways' ] ) ) {
                return null;
            }
            $pg = WC()->payment_gateways();
            if ( ! $pg || ! is_callable( [ $pg, 'payment_gateways' ] ) ) {
                return null;
            }
            $gateways = $pg->payment_gateways();
            if ( ! is_array( $gateways ) ) {
                return null;
            }

            $out  = [];
            $seen = [];
            foreach ( $gateways as $key => $g ) {
                if ( count( $out ) >= 50 ) { break; }
                if ( ! is_object( $g ) ) { continue; }
                $enabled = isset( $g->enabled ) ? (string) $g->enabled : '';
                if ( 'yes' !== $enabled ) { continue; }

                // The array key is WC's own indexing (normally the gateway
                // id itself); fall back to the object's own ->id when the
                // key is not usable as one (e.g. a plain numeric list).
                $id = ( is_string( $key ) && '' !== $key ) ? $key : (string) ( $g->id ?? '' );
                $id = trim( $id );
                if ( '' === $id || mb_strlen( $id, 'UTF-8' ) > 100 ) { continue; }
                if ( isset( $seen[ $id ] ) ) { continue; } // duplicate ids keep the first
                $seen[ $id ] = true;

                $title = is_callable( [ $g, 'get_title' ] ) ? (string) $g->get_title() : '';
                $title = trim( $title );
                if ( '' === $title ) { $title = $id; }
                // Counted and cut in characters, never bytes: a byte cut can
                // split an Urdu character, and one invalid byte sequence makes
                // the whole poll body unencodable.
                if ( mb_strlen( $title, 'UTF-8' ) > 200 ) { $title = mb_substr( $title, 0, 200, 'UTF-8' ); }

                $out[] = [ 'id' => $id, 'title' => $title ];
            }
            return $out;
        } catch ( Throwable $e ) {
            return null;
        }
    }

    /**
     * The human title for a gateway id, looked up from the live gateways so
     * the order shows the store's own wording. Falls back to the id — a raw
     * "cod" label beats an empty one.
     */
    public static function payment_method_title( $method_id ) {
        if ( function_exists( 'WC' ) && is_callable( [ WC(), 'payment_gateways' ] ) && WC()->payment_gateways() ) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if ( isset( $gateways[ $method_id ] ) && is_callable( [ $gateways[ $method_id ], 'get_title' ] ) ) {
                $title = (string) $gateways[ $method_id ]->get_title();
                if ( '' !== $title ) { return $title; }
            }
        }
        return (string) $method_id;
    }

    // ── Failure wording ─────────────────────────────────────────────

    /** One line naming why an api_request failed (status + backend message). */
    public static function describe_failure( $res ) {
        if ( ! empty( $res['data']['error'] ) && is_string( $res['data']['error'] ) ) {
            $status = isset( $res['status'] ) ? (int) $res['status'] : 0;
            return 'HTTP ' . $status . ': ' . $res['data']['error'];
        }
        if ( ! empty( $res['error'] ) ) {
            return (string) $res['error']; // transport failure (WP_Error message)
        }
        $status = isset( $res['status'] ) ? (int) $res['status'] : 0;
        return 0 === $status ? 'CashFlow could not be reached (transport failure)' : 'HTTP ' . $status;
    }

    // ── Stats (bounded option: counters + timestamps + last error) ──

    private static function stats() {
        $s = get_option( self::STATS_OPTION, [] );
        return is_array( $s ) ? $s : [];
    }

    private static function update_stats( $patch ) {
        // autoload=false: this option is rewritten every tick and is only
        // read by the admin panel + /status — it has no place in alloptions.
        update_option( self::STATS_OPTION, array_merge( self::stats(), $patch ), false );
    }

    private static function bump_outcome( $outcome, $error ) {
        $s   = self::stats();
        $key = $outcome . '_count';
        $s[ $key ]             = (int) ( $s[ $key ] ?? 0 ) + 1;
        $s['last_ack_outcome'] = $outcome;
        if ( '' !== $error ) { $s['last_error'] = $error; }
        update_option( self::STATS_OPTION, $s, false );
    }

    // ── Status surfaces (admin panel + public /status) ──────────────

    /** ISO timestamp of the last successful poll, or null. */
    public static function last_poll_at() {
        $s = self::stats();
        return ! empty( $s['last_poll_at'] ) ? (string) $s['last_poll_at'] : null;
    }

    /** Everything the admin panel's Pull Sync card renders. No secrets. */
    public static function status_summary() {
        $s = self::stats();
        return [
            'available'        => self::is_available(),
            'scheduled'        => self::is_scheduled(),
            'last_poll_at'     => self::last_poll_at(),
            'last_ack_outcome' => ! empty( $s['last_ack_outcome'] ) ? (string) $s['last_ack_outcome'] : '',
            'applied_count'    => (int) ( $s['applied_count'] ?? 0 ),
            'last_error'       => ! empty( $s['last_error'] ) ? (string) $s['last_error'] : '',
        ];
    }
}
