# WooCommerce Plugin v4 — Design-system rebuild + Re-register fix

Date: 2026-07-05 · Branch: `local-macbook` (all 3 repos) · Skill: cashflow v3.9

## Goal

1. **Rebuild the plugin's WP-admin settings UI (v3.1.0 → v4.0.0)** so it is *completely*
   consistent with the CASHFLOW app design system (violet brand, Lucide icons — **no emojis**,
   app card/button/toggle/pill styling, app loader language, dark-mode aware).
2. **Fix the one real bug:** registering / re-registering the WooCommerce connection *from the
   CashFlow app → Integrations* silently breaks the (working) plugin sync. Plugin's own
   re-register keeps working; the app's does not.
3. **No breaking functionality.** Find + correct mistakes spotted along the way (surgically, or
   flag as validate-first chips when out of scope).

---

## Part A — The re-register bug (ROOT CAUSE, verified in code)

**Diagnose:** The plugin and the app register WooCommerce webhooks by two *different* methods that
produce *incompatible* webhooks. The app's method deletes the plugin's webhooks and replaces them
with ones the backend can no longer authenticate.

**Evidence:**
- The plugin (`includes/class-webhooks.php:76`) creates each WC webhook with
  `$webhook->set_secret( $consumer_secret )` — i.e. WooCommerce signs every delivery
  (`X-WC-Webhook-Signature`) with the store's **WC consumer_secret**.
- The backend webhook receiver verifies that signature **against the same consumer_secret**
  (`src/routes/orders.js:388-396` resolves it from the channel, else `stores.consumer_secret`;
  `src/lib/webhookAuth.js:59` does the HMAC-SHA256 compare). So the plugin's webhooks verify. ✅
- The app's "Register webhooks" button (`IntegrationModal.jsx:69-82`) →
  `POST /stores/:id/register-webhook` (`stores.js:393`) →
  `woo.registerWebhooks(creds, { callbackUrl })`
  (`src/integrations/adapters/platform/woocommerce.adapter.js:79-135`).
- That adapter:
  1. **Deletes every existing webhook whose `delivery_url` matches** the CashFlow receive URL
     (`woocommerce.adapter.js:96-106`). The plugin's 8 webhooks share that exact URL
     (`/stores/webhook-receive/{store_id}`), so **the plugin's working webhooks are all deleted.**
  2. **Creates the replacements with NO `secret`** in the POST body
     (`woocommerce.adapter.js:118-123`). WooCommerce therefore does **not** sign them with the
     consumer_secret, so the backend's HMAC check can never match. Under
     `WEBHOOK_AUTH_ENFORCE=true` they are rejected (401); regardless, they are no longer the
     plugin's verifiable webhooks. ❌

**The Fix (backend, one file):** Make the app/adapter re-register produce webhooks *identical in
verification* to the plugin's — sign each created webhook with the store's `consumer_secret`.

In `woocommerce.adapter.js registerWebhooks`, add `secret: creds.consumer_secret` to the webhook
create body:

```js
body: JSON.stringify({ name, topic, delivery_url: webhookUrl, status: 'active',
                       secret: creds.consumer_secret }),
```

Why this is correct in **every** path:
- The receive route resolves the verification secret from the **same channel/store creds**
  (`orders.js:389-393`) that `register-webhook` passed to the adapter (`stores.js:409-411`). So
  signing with `creds.consumer_secret` guarantees the receiver's compare matches — for
  plugin-connected stores (store fallback) **and** app-connected channels alike.
- It also *fixes* app-only WooCommerce stores, whose webhooks are currently signed with an empty
  secret and only "work" because enforcement is off — this makes enabling enforcement safe.

**Scope guard (money/credential path — Golden Rule #2):** this is a credential/webhook change.
Build on `local-macbook`, cover with a test, **HOLD for user verification, ship only on MRS.**

**Deliberately NOT changed** (out of scope; flagged as chips):
- The plugin registers 8 topics (order/product/customer); the backend receiver only *processes*
  order topics (`webhookProcessor.service.js:36-42` treats any non-`order.deleted` as an order).
  Product/customer webhooks are latent no-ops/mis-maps — a pre-existing plugin concern, not this
  bug. The adapter's 3 order topics are the correct, sufficient set for sync; the fix does not
  reduce any *working* behavior. → chip to validate/normalize the plugin topic set separately.
- The dual credential stores (`stores` vs `integration_connections`) are an architecture item;
  this fix makes the *webhook* path consistent without touching that split.

---

## Part B — v4 design-system rebuild (plugin UI)

**Files rebuilt (plugin only):** `admin/views/settings.php`, `assets/admin.css`,
`assets/admin.js`, `admin/class-admin.php` (menu icon), version bump in `woo-cashflow.php`
(+ `CASHFLOW_VERSION`) to **4.0.0**. No change to sync/meta/statuses/prefix/REST logic.

**Design tokens (from the app; light primary + dark-mode block):**
- Brand: `--cf: #7c3aed`, `--cf-2: #8b5cf6`, `--cf-3: #a78bfa` (canonical brand, matches app §9 +
  existing plugin). Accent-only; green reserved for status.
- Text: primary `#101517`, secondary `#3c434a`, muted `#646970`.
- Surfaces: page `#f6f7f7`, card `#ffffff`, border `#dcdcde`.
- Status: success `#22c55e`, warning `#f59e0b`, danger `#ef4444`.
- Radius: cards **14px**, buttons **8px**, inputs **10px**, pills **20px**.
- Shadow: card `0 2px 8px rgba(0,0,0,.08)` → hover `0 2px 24px rgba(0,0,0,.14)` + 1px lift.
- Type: system stack tuned to the app (`Inter`-like: `-apple-system,'Segoe UI',Inter,Roboto,sans-serif`);
  headings a touch tighter. No external font fetch (keeps the admin page self-contained/fast).
- Dark: `@media (prefers-color-scheme: dark)` mirror using app dark tokens
  (page `#0a080e`, card `#110f18`, border `#241e35`, text `#e9edec`, brand `#8b5cf6`).

**Icon system (Golden Rule #11 — one reusable primitive):** a PHP helper `cf_icon($name, $size)`
returning inline **Lucide** SVG (stroke `currentColor`, width 2). Replaces **every** emoji:
plug/link → `plug`/`link-2`, settings → `settings`, connected/disconnected → `check-circle`/`x-circle`,
re-register/refresh → `refresh-cw`, security → `shield-check`, auto-keys → `zap`, bi-directional →
`arrow-left-right`, courier → `truck`, globe → `globe`, log → `scroll-text`, success/error/warn →
`check`/`x`/`alert-triangle`, show/hide → `eye`/`eye-off`.

**Components restyled to match the app** (same DOM structure, so JS keeps working):
- Header (logo + version chip + connection **status pill** with dot — no emoji).
- Cards = app `SectionCard` (14px radius, header with title + subtext, body).
- Buttons = app `PrimaryBtn`/`SecondaryBtn`/danger/ghost (violet primary w/ soft shadow + lift).
- Toggle switches, token input with eye toggle, connect-step tracker (kept — good UX, restyled),
  REST-endpoint rows, sync-log table — all re-tokenized.
- Loader language: keep the stepped connect tracker; action buttons use one consistent
  brand spinner; completion = inline toast-style success message (app uses toasts). No new spinners.

**Structure:** same two states (connected / disconnected) and same feature set — **pure visual
re-skin + icon swap**, no behavioral change. `admin.js` action IDs/hooks unchanged.

---

## Testing & verification
- **Backend fix:** extend `woocommerce.adapter.test.js` — assert every registered webhook's POST
  body includes `secret === creds.consumer_secret`. Full backend suite green.
- **Plugin:** no PHP test harness in-repo; verify by (a) PHP lint (`php -l`) on every changed file,
  (b) confirm no emoji bytes remain, (c) confirm all `admin.js` element IDs still exist in the new
  `settings.php`, (d) manual render review.
- Pre-MRS Reviewer before MRS. Version bumped. Money-path fix held for user verification.

## Non-goals
- No architecture change to the dual credential stores.
- No change to order/prefix/sync/status logic, courier flows, or the plugin's connect steps.
- No plugin topic-set change (chip instead).
