/* CashFlow Sync v2 — Admin JS */
(function($) {
    'use strict';

    const { ajaxUrl, nonce } = cashflowAdmin;

    // ── Helpers ────────────────────────────────────────────────────
    function msg(el, text, type) {
        $(el).attr('class','cf-msg ' + type).html(text).show();
    }

    function setBtn(btn, loading, text) {
        if (loading) {
            $(btn).data('orig', $(btn).html())
                  .html('<span class="cf-spin"></span> ' + text)
                  .prop('disabled', true);
        } else {
            $(btn).html($(btn).data('orig') || $(btn).html())
                  .prop('disabled', false);
        }
    }

    function step(id, state, icon) {
        const el = $('#' + id);
        el.removeClass('active done failed').addClass(state);
        el.find('.cf-step-status').html(icon || '');
    }

    function ajax(action, data, onOk, onErr) {
        return $.post(ajaxUrl, { action, nonce, ...data })
            .done(r => r.success ? onOk(r.data) : onErr && onErr(r.data?.message || 'Error'))
            .fail(() => onErr && onErr('Network error'));
    }

    function esc(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ── Token visibility toggle ────────────────────────────────────
    $('#cf-toggle-token').on('click', function() {
        const inp = $('#cf-token-input');
        const isPass = inp.attr('type') === 'password';
        inp.attr('type', isPass ? 'text' : 'password');
        $(this).text(isPass ? 'Hide' : 'Show');
    });

    // ── Connect ────────────────────────────────────────────────────
    $('#cf-connect-btn').on('click', function() {
        const btn   = this;
        const token = $('#cf-token-input').val().trim();
        const msgEl = '#cf-connect-msg';

        if (!token) {
            msg(msgEl, '⚠️ Please paste your CashFlow token', 'err');
            return;
        }

        setBtn(btn, true, 'Connecting...');
        msg(msgEl, '<span class="cf-spin"></span> Starting secure connection...', 'info');

        // Reset steps
        ['cf-step-1','cf-step-2','cf-step-3','cf-step-4'].forEach(s => {
            $('#' + s).removeClass('active done failed').find('.cf-step-status').html('');
        });

        // Step 1: Verify token
        step('cf-step-1', 'active', '<span class="cf-spin"></span>');
        msg(msgEl, '<span class="cf-spin"></span> Step 1: Verifying your CashFlow token...', 'info');

        ajax('cashflow_pre_check', { cashflow_token: token },
            (preData) => {
                step('cf-step-1', 'done', '✓');
                msg(msgEl, `<span class="cf-spin"></span> Step 2: Verifying store ownership for <strong>${esc(preData.site_url)}</strong>...`, 'info');

                // Step 2: Full connect (includes ownership + key gen + webhooks)
                step('cf-step-2', 'active', '<span class="cf-spin"></span>');

                ajax('cashflow_connect', { cashflow_token: token },
                    (data) => {
                        step('cf-step-2', 'done', '✓');
                        step('cf-step-3', 'done', '✓');
                        step('cf-step-4', 'done', '✓');
                        msg(msgEl, `✅ ${esc(data.message)} — ${data.webhooks?.registered || 0} webhooks registered`, 'ok');
                        setBtn(btn, false);
                        setTimeout(() => location.reload(), 1800);
                    },
                    (err) => {
                        step('cf-step-2', 'failed', '✗');
                        msg(msgEl, `❌ ${esc(err)}`, 'err');
                        setBtn(btn, false);
                    }
                );
            },
            (err) => {
                step('cf-step-1', 'failed', '✗');
                msg(msgEl, `❌ ${esc(err)}`, 'err');
                setBtn(btn, false);
            }
        );
    });

    // ── Disconnect ─────────────────────────────────────────────────
    $('#cf-disconnect-btn').on('click', function() {
        if (!confirm('Disconnect this store from CashFlow?\n\nThis will remove API keys and webhooks from WooCommerce.')) return;
        setBtn(this, true, 'Disconnecting...');
        ajax('cashflow_disconnect', {},
            () => location.reload(),
            (e) => { msg('#cf-connect-msg', `❌ ${esc(e)}`, 'err'); setBtn(this, false); }
        );
    });

    // ── Verify ─────────────────────────────────────────────────────
    $('#cf-verify-btn').on('click', function() {
        setBtn(this, true, 'Verifying...');
        ajax('cashflow_verify', {},
            (d) => {
                let html = `✅ ${esc(d.message)}<br><small>`;
                if (d.cashflow_api)  html += `CashFlow API: ${esc(d.cashflow_api)} &nbsp;`;
                if (d.rest_endpoint) html += `REST: ${esc(d.rest_endpoint)}`;
                html += '</small>';
                msg('#cf-connect-msg', html, 'ok');
                setBtn(this, false);
            },
            (e) => { msg('#cf-connect-msg', `❌ ${esc(e)}`, 'err'); setBtn(this, false); }
        );
    });

    // ── Re-register webhooks ───────────────────────────────────────
    $('#cf-reregister-btn').on('click', function() {
        setBtn(this, true, 'Registering...');
        ajax('cashflow_reregister_webhooks', {},
            (d) => { msg('#cf-connect-msg', `✅ ${esc(d.message)}`, 'ok'); setBtn(this, false); },
            (e) => { msg('#cf-connect-msg', `❌ ${esc(e)}`, 'err'); setBtn(this, false); }
        );
    });

    // ── Save settings ──────────────────────────────────────────────
    $('#cf-save-settings-btn').on('click', function() {
        const data = {};
        $('.cf-toggle input[type=checkbox]').each(function() {
            data[$(this).attr('name')] = $(this).is(':checked') ? 1 : 0;
        });
        setBtn(this, true, 'Saving...');
        ajax('cashflow_save_settings', data,
            () => {
                const el = document.getElementById('cf-settings-msg');
                if (el) { el.textContent = '✅ Saved'; el.style.display='inline'; el.style.color='#10b981'; setTimeout(()=>el.style.display='none', 2500); }
                setBtn(this, false);
            },
            (e) => { msg('#cf-settings-msg', `❌ ${esc(e)}`, 'err'); setBtn(this, false); }
        );
    });

    // ── Sync log ───────────────────────────────────────────────────
    $('#cf-load-log-btn').on('click', function() {
        setBtn(this, true, 'Loading...');
        ajax('cashflow_get_sync_log', {},
            (d) => {
                const logs = d.logs || [];
                if (!logs.length) {
                    $('#cf-log-container').html('<p style="color:#9ca3af;font-size:13px">No sync events yet.</p>');
                    setBtn(this, false); return;
                }
                let t = '<table class="cf-log-table"><thead><tr><th>Event</th><th>Type</th><th>ID</th><th>Status</th><th>Message</th><th>Time</th></tr></thead><tbody>';
                logs.forEach(l => {
                    const sc = l.status==='success' ? 'cf-log-ok' : 'cf-log-err';
                    t += `<tr><td>${esc(l.event_type)}</td><td>${esc(l.object_type)}</td><td>${esc(l.object_id)}</td><td class="${sc}">${esc(l.status)}</td><td>${esc(l.message||'—')}</td><td style="color:#9ca3af;white-space:nowrap">${esc(l.created_at)}</td></tr>`;
                });
                t += '</tbody></table>';
                $('#cf-log-container').html(t);
                setBtn(this, false);
            },
            (e) => { $('#cf-log-container').html(`<p style="color:var(--err)">${esc(e)}</p>`); setBtn(this, false); }
        );
    });

})(jQuery);
