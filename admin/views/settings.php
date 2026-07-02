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
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M12 2L2 7l10 5 10-5-10-5z" stroke="#7c3aed" stroke-width="2" stroke-linejoin="round"/>
        <path d="M2 17l10 5 10-5" stroke="#7c3aed" stroke-width="2" stroke-linejoin="round"/>
        <path d="M2 12l10 5 10-5" stroke="#8b5cf6" stroke-width="2" stroke-linejoin="round"/>
      </svg>
      <div>
        <h1>CashFlow Sync <span style="font-size:11px;font-weight:400;color:#9ca3af;vertical-align:middle">v<?php echo CASHFLOW_VERSION; ?></span></h1>
        <p>Secure bi-directional WooCommerce ↔ CashFlow sync</p>
      </div>
    </div>
    <span class="cf-badge <?php echo $connected ? 'cf-badge-connected' : 'cf-badge-disconnected'; ?>">
      <?php echo $connected ? '🟢 Connected' : '🔴 Not Connected'; ?>
    </span>
  </div>

  <?php if ( $connected ) : ?>
  <!-- ── CONNECTED STATE ── -->

  <!-- Store Info -->
  <div class="cf-card">
    <div class="cf-card-header">
      <h2>🔗 Connected Store</h2>
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
        <button class="cf-btn cf-btn-secondary" id="cf-verify-btn">🔍 Verify Connection</button>
        <button class="cf-btn cf-btn-secondary" id="cf-reregister-btn">🔄 Re-register Webhooks</button>
        <button class="cf-btn cf-btn-danger"    id="cf-disconnect-btn">Disconnect</button>
      </div>
      <div id="cf-connect-msg" class="cf-msg" style="display:none"></div>
    </div>
  </div>

  <!-- Sync Settings -->
  <div class="cf-card">
    <div class="cf-card-header">
      <h2>⚙️ Sync Settings</h2>
      <p>Control what gets synced between WooCommerce and CashFlow</p>
    </div>
    <div class="cf-card-body">
      <?php
      $toggles = [
        'sync_courier_meta' => [ '🚚 Courier Meta','Update WC order meta when courier is booked in CashFlow'    ],
        'bidirectional'     => [ '↔️ Bi-directional','Allow CashFlow to update order status back in WooCommerce' ],
      ];
      foreach ( $toggles as $key => [ $label, $desc ] ) :
        $checked = ! empty( $settings[$key] );
      ?>
      <label class="cf-toggle">
        <div class="cf-toggle-info">
          <strong><?php echo esc_html( $label ); ?></strong>
          <span><?php echo esc_html( $desc ); ?></span>
        </div>
        <div class="cf-switch">
          <input type="checkbox" name="<?php echo esc_attr($key); ?>" <?php checked($checked); ?>>
          <span class="cf-switch-slider"></span>
        </div>
      </label>
      <?php endforeach; ?>
      <div style="margin-top:16px;display:flex;gap:8px;align-items:center">
        <button class="cf-btn cf-btn-primary" id="cf-save-settings-btn">Save Settings</button>
        <span id="cf-settings-msg" style="display:none"></span>
      </div>
    </div>
  </div>

  <!-- REST Endpoints -->
  <div class="cf-card">
    <div class="cf-card-header">
      <h2>🔗 REST Endpoints</h2>
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
        <span class="cf-ep-method cf-ep-<?php echo strtolower($m); ?>"><?php echo $m; ?></span>
        <code><?php echo esc_html( $base . $p ); ?></code>
        <span class="cf-ep-desc"><?php echo esc_html( $d ); ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Sync Log -->
  <div class="cf-card">
    <div class="cf-card-header">
      <h2>📋 Recent Sync Events</h2>
    </div>
    <div class="cf-card-body">
      <button class="cf-btn cf-btn-ghost" id="cf-load-log-btn">Load Log</button>
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
          <span class="cf-security-icon">🔐</span>
          <div>
            <strong>Store Ownership Verified</strong>
            <p>Your store URL is detected automatically — no one can steal another store's keys</p>
          </div>
        </div>
        <div class="cf-security-item">
          <span class="cf-security-icon">⚡</span>
          <div>
            <strong>Auto API Key Generation</strong>
            <p>WooCommerce REST API keys are generated automatically — no manual copy-paste</p>
          </div>
        </div>
        <div class="cf-security-item">
          <span class="cf-security-icon">🔄</span>
          <div>
            <strong>Bi-directional Sync</strong>
            <p>Orders, inventory, courier status — sync both ways in real-time</p>
          </div>
        </div>
        <div class="cf-security-item">
          <span class="cf-security-icon">🛡️</span>
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
      <h2>🔌 Connect to CashFlow</h2>
      <p>Enter your CashFlow token — everything else is automatic</p>
    </div>
    <div class="cf-card-body">

      <!-- Detected site -->
      <div class="cf-detected-site">
        <span class="cf-detected-label">🌐 Your Store URL (auto-detected)</span>
        <code><?php echo esc_html( $site_url ); ?></code>
        <span class="cf-detected-note">This URL will be verified with CashFlow — you cannot change it</span>
      </div>

      <div class="cf-form-group">
        <label for="cf-token-input">
          <strong>CashFlow API Token</strong>
          <a href="https://app.cashflow.pk/settings?tab=api" target="_blank" class="cf-help-link">Where to find it? →</a>
        </label>
        <div class="cf-token-wrap">
          <input type="password" id="cf-token-input" placeholder="Paste your CashFlow token here..." class="cf-input" autocomplete="off">
          <button type="button" class="cf-toggle-visibility" id="cf-toggle-token">Show</button>
        </div>
        <p class="cf-input-hint">Found in CashFlow → Settings → API Tokens</p>
      </div>

      <div id="cf-pre-check-result" style="display:none"></div>

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
        ⚡ Connect to CashFlow
      </button>

      <div id="cf-connect-msg" class="cf-msg" style="display:none"></div>
    </div>
  </div>

  <?php endif; ?>

</div><!-- .cashflow-wrap -->
