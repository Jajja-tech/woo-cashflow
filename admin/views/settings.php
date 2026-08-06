<?php
defined( 'ABSPATH' ) || exit;
$settings  = CashFlow_Plugin::get_settings();
$connected = ! empty( $settings['connected'] );
$site_url  = CashFlow_Security::get_verified_site_url();
?>
<div class="wrap cashflow-wrap">

  <!-- PageHeader (app style) -->
  <div class="cf-ph">
    <div class="cf-ph-left">
      <span class="cf-ph-icon"><?php echo cf_logo( 22 ); ?></span>
      <div>
        <div class="cf-ph-titlerow">
          <h1>CashFlow Sync</h1>
          <span class="cf-ver">v<?php echo esc_html( CASHFLOW_VERSION ); ?></span>
        </div>
        <p>Secure bi-directional WooCommerce &#8596; CashFlow sync</p>
      </div>
    </div>
    <div class="cf-ph-right">
      <?php if ( $connected ) : ?>
        <span class="cf-pill cf-pill-ok"><span class="cf-dot"></span> Connected</span>
      <?php else : ?>
        <span class="cf-pill cf-pill-off"><span class="cf-dot"></span> Not connected</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ( $connected ) : ?>
  <!-- ── CONNECTED STATE — tabbed ── -->

  <div class="cf-tabs" role="tablist">
    <button class="cf-tab is-active" data-tab="store"     role="tab"><?php echo cf_icon( 'plug', 14 ); ?> Store</button>
    <button class="cf-tab"           data-tab="sync"      role="tab"><?php echo cf_icon( 'settings', 14 ); ?> Sync Settings</button>
    <button class="cf-tab"           data-tab="endpoints" role="tab"><?php echo cf_icon( 'link-2', 14 ); ?> Endpoints</button>
    <button class="cf-tab"           data-tab="activity"  role="tab"><?php echo cf_icon( 'scroll-text', 14 ); ?> Activity</button>
  </div>

  <!-- Panel: Store -->
  <div class="cf-panel is-active" data-panel="store">
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
            <span class="cf-info-label">REST API</span>
            <code><?php echo esc_url( $site_url ); ?>/wp-json/cashflow/v1/ping</code>
          </div>
        </div>
        <div class="cf-actions">
          <button class="cf-btn cf-btn-danger" id="cf-disconnect-btn"><?php echo cf_icon( 'power', 15 ); ?> Disconnect</button>
        </div>
        <div id="cf-connect-msg" class="cf-msg" style="display:none"></div>
      </div>
    </div>

    <!-- Pull Sync: order edits queued in CashFlow, applied here by the minutely poller -->
    <?php $cf_pull = class_exists( 'CashFlow_Sync_Pull' ) ? CashFlow_Sync_Pull::status_summary() : null; ?>
    <?php if ( $cf_pull ) : ?>
    <div class="cf-card" style="margin-top:16px">
      <div class="cf-card-header">
        <h2><?php echo cf_icon( 'refresh-cw', 16 ); ?> Pull Sync</h2>
        <p>Order edits made in CashFlow are pulled and applied to WooCommerce about once a minute</p>
      </div>
      <div class="cf-card-body">
        <?php if ( ! $cf_pull['available'] ) : ?>
          <p class="cf-guidance-text" style="color:#b32d2e">
            <strong>Pull sync is unavailable</strong> — Action Scheduler was not found. It ships
            inside WooCommerce, so this usually means WooCommerce is inactive or broken. Order
            edits made in CashFlow will not be applied to this store until it is restored.
          </p>
        <?php else : ?>
          <div class="cf-info-grid">
            <div class="cf-info-item">
              <span class="cf-info-label">Scheduled</span>
              <span><?php echo $cf_pull['scheduled'] ? 'Yes — runs every minute' : 'Not yet scheduled'; ?></span>
            </div>
            <div class="cf-info-item">
              <span class="cf-info-label">Last poll (UTC)</span>
              <span><?php echo $cf_pull['last_poll_at'] ? esc_html( $cf_pull['last_poll_at'] ) : 'Never'; ?></span>
            </div>
            <div class="cf-info-item">
              <span class="cf-info-label">Last outcome</span>
              <span><?php echo '' !== $cf_pull['last_ack_outcome'] ? esc_html( $cf_pull['last_ack_outcome'] ) : '—'; ?></span>
            </div>
            <div class="cf-info-item">
              <span class="cf-info-label">Edits applied</span>
              <span><?php echo (int) $cf_pull['applied_count']; ?></span>
            </div>
          </div>
          <?php if ( '' !== $cf_pull['last_error'] ) : ?>
            <p class="cf-guidance-text" style="color:#b32d2e; margin-top:12px">
              <strong>Last error:</strong> <?php echo esc_html( $cf_pull['last_error'] ); ?>
            </p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Panel: Sync Settings -->
  <div class="cf-panel" data-panel="sync">
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
  </div>

  <!-- Panel: Endpoints -->
  <div class="cf-panel" data-panel="endpoints">
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
  </div>

  <!-- Panel: Activity -->
  <div class="cf-panel" data-panel="activity">
    <div class="cf-card">
      <div class="cf-card-header">
        <h2><?php echo cf_icon( 'scroll-text', 16 ); ?> Recent Sync Events</h2>
      </div>
      <div class="cf-card-body">
        <button class="cf-btn cf-btn-ghost" id="cf-load-log-btn"><?php echo cf_icon( 'refresh-cw', 15 ); ?> Load Log</button>
        <div id="cf-log-container" style="margin-top:14px"></div>
      </div>
    </div>
  </div>

  <?php else : ?>
  <!-- ── DISCONNECTED STATE — passive guidance ── -->

  <div class="cf-card">
    <div class="cf-card-header">
      <h2><?php echo cf_icon( 'plug', 16 ); ?> Not Connected</h2>
      <p>This site isn't linked to a CashFlow store yet</p>
    </div>
    <div class="cf-card-body">

      <p class="cf-guidance-text">
        Connections are started from the CashFlow app, not from this plugin — there's
        no token to paste here. Open your store's Integrations page in CashFlow and
        connect this WooCommerce site from there; the plugin picks up the connection
        automatically once it's made.
      </p>

      <a href="https://app.cashflow.pk/integrations" target="_blank" rel="noopener" class="cf-btn cf-btn-primary">
        <?php echo cf_icon( 'arrow-up-right', 15 ); ?> Open CashFlow Integrations
      </a>

      <div class="cf-detected-site">
        <span class="cf-detected-label"><?php echo cf_icon( 'globe', 13 ); ?> Your Store URL (auto-detected)</span>
        <code><?php echo esc_html( $site_url ); ?></code>
        <span class="cf-detected-note">Confirm this matches the URL you connect to in the CashFlow app</span>
      </div>

    </div>
  </div>

  <?php endif; ?>

</div><!-- .cashflow-wrap -->
