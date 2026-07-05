<?php
defined( 'ABSPATH' ) || exit;
$settings      = CashFlow_Plugin::get_settings();
$connected     = ! empty( $settings['connected'] );
$site_url      = CashFlow_Security::get_verified_site_url();
$webhook_count = count( get_option( 'cashflow_webhook_ids', [] ) );
?>
<div class="wrap cashflow-wrap">

  <!-- Header -->
  <div class="cf-header">
    <div class="cf-logo">
      <span class="cf-logo-mark"><?php echo cf_icon( 'box', 24 ); ?></span>
      <div>
        <h1>CashFlow Sync <span class="cf-ver">v<?php echo esc_html( CASHFLOW_VERSION ); ?></span></h1>
        <p>Secure bi-directional WooCommerce &#8596; CashFlow sync</p>
      </div>
    </div>
    <?php if ( $connected ) : ?>
      <span class="cf-pill cf-pill-ok"><span class="cf-dot"></span> Connected</span>
    <?php else : ?>
      <span class="cf-pill cf-pill-off"><span class="cf-dot"></span> Not connected</span>
    <?php endif; ?>
  </div>

  <?php if ( $connected ) : ?>
  <!-- ── CONNECTED STATE ── -->

  <!-- Store Info -->
  <div class="cf-card">
    <div class="cf-card-header">
      <h2><?php echo cf_icon( 'plug', 16 ); ?> Connected Store</h2>
    </div>
    <div class="cf-card-body">
      <div class="cf-info-grid">
        <div class="cf-info-item">
          <span class="cf-info-label">Store URL</span>
          <code><?php echo esc_html( $settings['store_url'] ); ?></code>
        </div>
        <div class="cf-info-item">
          <span class="cf-info-label">Store ID</span>
          <code><?php echo esc_html( $settings['store_id'] ); ?></code>
        </div>
        <div class="cf-info-item">
          <span class="cf-info-label">Connected</span>
          <span><?php echo esc_html( $settings['connected_at'] ); ?></span>
        </div>
        <div class="cf-info-item">
          <span class="cf-info-label">Webhooks</span>
          <span><?php echo esc_html( $webhook_count ); ?> registered</span>
        </div>
        <div class="cf-info-item">
          <span class="cf-info-label">REST API</span>
          <code><?php echo esc_url( $site_url ); ?>/wp-json/cashflow/v1/ping</code>
        </div>
      </div>
      <div class="cf-actions">
        <button class="cf-btn cf-btn-secondary" id="cf-verify-btn"><?php echo cf_icon( 'search', 15 ); ?> Verify Connection</button>
        <button class="cf-btn cf-btn-secondary" id="cf-reregister-btn"><?php echo cf_icon( 'refresh-cw', 15 ); ?> Re-register Webhooks</button>
        <button class="cf-btn cf-btn-danger"    id="cf-disconnect-btn"><?php echo cf_icon( 'power', 15 ); ?> Disconnect</button>
      </div>
      <div id="cf-connect-msg" class="cf-msg" style="display:none"></div>
    </div>
  </div>

  <!-- Sync Settings -->
  <div class="cf-card">
    <div class="cf-card-header">
      <h2><?php echo cf_icon( 'settings', 16 ); ?> Sync Settings</h2>
      <p>Control what gets synced between WooCommerce and CashFlow</p>
    </div>
    <div class="cf-card-body">
      <?php
      $toggles = [
        'sync_courier_meta' => [ 'truck',            'Courier Meta',    'Update WC order meta when courier is booked in CashFlow'    ],
        'bidirectional'     => [ 'arrow-left-right', 'Bi-directional', 'Allow CashFlow to update order status back in WooCommerce' ],
      ];
      foreach ( $toggles as $key => [ $icon, $label, $desc ] ) :
        $checked = ! empty( $settings[$key] );
      ?>
      <label class="cf-toggle">
        <span class="cf-toggle-info">
          <?php echo cf_icon( $icon, 17 ); ?>
          <span>
            <strong><?php echo esc_html( $label ); ?></strong>
            <span><?php echo esc_html( $desc ); ?></span>
          </span>
        </span>
        <span class="cf-switch">
          <input type="checkbox" name="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?>>
          <span class="cf-switch-slider"></span>
        </span>
      </label>
      <?php endforeach; ?>
      <div class="cf-save-row">
        <button class="cf-btn cf-btn-primary" id="cf-save-settings-btn"><?php echo cf_icon( 'check', 15 ); ?> Save Settings</button>
        <span id="cf-settings-msg" class="cf-save-note"></span>
      </div>
    </div>
  </div>

  <!-- REST Endpoints -->
  <div class="cf-card">
    <div class="cf-card-header">
      <h2><?php echo cf_icon( 'link-2', 16 ); ?> REST Endpoints</h2>
      <p>CashFlow backend calls these to update your WooCommerce store</p>
    </div>
    <div class="cf-card-body">
      <?php
      $base = $site_url . '/wp-json/cashflow/v1';
      $eps  = [
        ['POST', '/order-status', 'Update WC order status from CashFlow'],
        ['POST', '/update-stock', 'Update product stock'],
        ['POST', '/courier-meta', 'Update courier tracking meta on WC order'],
        ['GET',  '/ping',         'Health check'],
        ['GET',  '/status',       'Plugin + connection status'],
      ];
      foreach ( $eps as [$m, $p, $d] ) :
      ?>
      <div class="cf-ep">
        <span class="cf-ep-method cf-ep-<?php echo strtolower( $m ); ?>"><?php echo esc_html( $m ); ?></span>
        <code><?php echo esc_html( $base . $p ); ?></code>
        <span class="cf-ep-desc"><?php echo esc_html( $d ); ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Sync Log -->
  <div class="cf-card">
    <div class="cf-card-header">
      <h2><?php echo cf_icon( 'scroll-text', 16 ); ?> Recent Sync Events</h2>
    </div>
    <div class="cf-card-body">
      <button class="cf-btn cf-btn-ghost" id="cf-load-log-btn"><?php echo cf_icon( 'refresh-cw', 15 ); ?> Load Log</button>
      <div id="cf-log-container" style="margin-top:14px"></div>
    </div>
  </div>

  <?php else : ?>
  <!-- ── DISCONNECTED STATE ── -->

  <!-- Security info -->
  <div class="cf-card cf-security-card">
    <div class="cf-card-body">
      <div class="cf-security-grid">
        <div class="cf-security-item">
          <span class="cf-security-ic"><?php echo cf_icon( 'shield-check', 18 ); ?></span>
          <div>
            <strong>Store Ownership Verified</strong>
            <p>Your store URL is detected automatically — no one can steal another store's keys</p>
          </div>
        </div>
        <div class="cf-security-item">
          <span class="cf-security-ic"><?php echo cf_icon( 'zap', 18 ); ?></span>
          <div>
            <strong>Auto API Key Generation</strong>
            <p>WooCommerce REST API keys are generated automatically — no manual copy-paste</p>
          </div>
        </div>
        <div class="cf-security-item">
          <span class="cf-security-ic"><?php echo cf_icon( 'arrow-left-right', 18 ); ?></span>
          <div>
            <strong>Bi-directional Sync</strong>
            <p>Orders, inventory, courier status — sync both ways in real-time</p>
          </div>
        </div>
        <div class="cf-security-item">
          <span class="cf-security-ic"><?php echo cf_icon( 'key', 18 ); ?></span>
          <div>
            <strong>HMAC Signed Requests</strong>
            <p>Every request between CashFlow and this plugin is cryptographically signed</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Connect form -->
  <div class="cf-card">
    <div class="cf-card-header">
      <h2><?php echo cf_icon( 'plug', 16 ); ?> Connect to CashFlow</h2>
      <p>Enter your CashFlow token — everything else is automatic</p>
    </div>
    <div class="cf-card-body">

      <!-- Detected site -->
      <div class="cf-detected-site">
        <span class="cf-detected-label"><?php echo cf_icon( 'globe', 13 ); ?> Your Store URL (auto-detected)</span>
        <code><?php echo esc_html( $site_url ); ?></code>
        <span class="cf-detected-note">This URL will be verified with CashFlow — you cannot change it</span>
      </div>

      <div class="cf-form-group">
        <label for="cf-token-input">
          <strong>CashFlow API Token</strong>
          <a href="https://app.cashflow.pk/settings?tab=api" target="_blank" rel="noopener" class="cf-help-link">Where to find it? <?php echo cf_icon( 'arrow-up-right', 12 ); ?></a>
        </label>
        <div class="cf-token-wrap">
          <input type="password" id="cf-token-input" placeholder="Paste your CashFlow token here…" class="cf-input" autocomplete="off">
          <button type="button" class="cf-toggle-visibility" id="cf-toggle-token"><?php echo cf_icon( 'eye', 13 ); ?> <span>Show</span></button>
        </div>
        <p class="cf-input-hint">Found in CashFlow &#8594; Settings &#8594; API Tokens</p>
      </div>

      <div class="cf-connect-steps">
        <div class="cf-step" id="cf-step-1">
          <span class="cf-step-num">1</span>
          <span>Verifying your CashFlow token</span>
          <span class="cf-step-status"></span>
        </div>
        <div class="cf-step" id="cf-step-2">
          <span class="cf-step-num">2</span>
          <span>Verifying store ownership</span>
          <span class="cf-step-status"></span>
        </div>
        <div class="cf-step" id="cf-step-3">
          <span class="cf-step-num">3</span>
          <span>Generating WooCommerce API keys</span>
          <span class="cf-step-status"></span>
        </div>
        <div class="cf-step" id="cf-step-4">
          <span class="cf-step-num">4</span>
          <span>Registering webhooks</span>
          <span class="cf-step-status"></span>
        </div>
      </div>

      <button class="cf-btn cf-btn-primary cf-btn-large" id="cf-connect-btn">
        <?php echo cf_icon( 'zap', 16 ); ?> Connect to CashFlow
      </button>

      <div id="cf-connect-msg" class="cf-msg" style="display:none"></div>
    </div>
  </div>

  <?php endif; ?>

</div><!-- .cashflow-wrap -->
