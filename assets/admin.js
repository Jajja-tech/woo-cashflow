/* CashFlow Sync v4 — Admin JS
   Behaviour is unchanged from v3; only the visual bits (emoji → inline Lucide
   SVG icons, matching the app design system) differ. All action IDs/hooks kept. */
(function($) {
    'use strict';

    const { ajaxUrl, nonce } = cashflowAdmin;

    // ── Inline Lucide icons (mirror includes/class-icons.php) ──────
    const svg = (paths, size) =>
        '<svg class="cf-ic" width="' + (size||14) + '" height="' + (size||14) + '" viewBox="0 0 24 24" ' +
        'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' +
        'stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
    const IC = {
        check:  svg('<path d="M20 6 9 17l-5-5"/>'),
        x:      svg('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'),
        ok:     svg('<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>'),
        err:    svg('<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>'),
        warn:   svg('<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>'),
        eye:    svg('<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>', 13),
        eyeOff: svg('<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/>', 13),
    };
    const spin = '<span class="cf-spin"></span>';

    // ── Helpers ────────────────────────────────────────────────────
    function msg(el, html, type) {
        $(el).attr('class', 'cf-msg ' + type).html(html).css('display', 'flex').show();
    }

    function setBtn(btn, loading, text) {
        if (loading) {
            $(btn).data('orig', $(btn).html())
                  .html(spin + ' ' + text)
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
        $(this).html((isPass ? IC.eyeOff : IC.eye) + ' <span>' + (isPass ? 'Hide' : 'Show') + '</span>');
    });

    // ── Connect ────────────────────────────────────────────────────
    $('#cf-connect-btn').on('click', function() {
        const btn   = this;
        const token = $('#cf-token-input').val().trim();
        const msgEl = '#cf-connect-msg';

        if (!token) {
            msg(msgEl, IC.warn + ' Please paste your CashFlow token', 'err');
            return;
        }

        setBtn(btn, true, 'Connecting…');
        msg(msgEl, spin + ' Starting secure connection…', 'info');

        // Reset steps
        ['cf-step-1','cf-step-2','cf-step-3','cf-step-4'].forEach(s => {
            $('#' + s).removeClass('active done failed').find('.cf-step-status').html('');
        });

        // Step 1: Verify token
        step('cf-step-1', 'active', spin);
        msg(msgEl, spin + ' Step 1: Verifying your CashFlow token…', 'info');

        ajax('cashflow_pre_check', { cashflow_token: token },
            (preData) => {
                step('cf-step-1', 'done', IC.check);
                msg(msgEl, spin + ` Step 2: Verifying store ownership for <strong>${esc(preData.site_url)}</strong>…`, 'info');

                // Step 2: Full connect (includes ownership + key gen + webhooks)
                step('cf-step-2', 'active', spin);

                ajax('cashflow_connect', { cashflow_token: token },
                    (data) => {
                        step('cf-step-2', 'done', IC.check);
                        step('cf-step-3', 'done', IC.check);
                        step('cf-step-4', 'done', IC.check);
                        msg(msgEl, IC.ok + ` ${esc(data.message)} — ${data.webhooks?.registered || 0} webhooks registered`, 'ok');
                        setBtn(btn, false);
                        setTimeout(() => location.reload(), 1800);
                    },
                    (err) => {
                        step('cf-step-2', 'failed', IC.x);
                        msg(msgEl, IC.err + ` ${esc(err)}`, 'err');
                        setBtn(btn, false);
                    }
                );
            },
            (err) => {
                step('cf-step-1', 'failed', IC.x);
                msg(msgEl, IC.err + ` ${esc(err)}`, 'err');
                setBtn(btn, false);
            }
        );
    });

    // ── Disconnect ─────────────────────────────────────────────────
    $('#cf-disconnect-btn').on('click', function() {
        if (!confirm('Disconnect this store from CashFlow?\n\nThis will remove API keys and webhooks from WooCommerce.')) return;
        setBtn(this, true, 'Disconnecting…');
        ajax('cashflow_disconnect', {},
            () => location.reload(),
            (e) => { msg('#cf-connect-msg', IC.err + ` ${esc(e)}`, 'err'); setBtn(this, false); }
        );
    });

    // ── Verify ─────────────────────────────────────────────────────
    $('#cf-verify-btn').on('click', function() {
        setBtn(this, true, 'Verifying…');
        ajax('cashflow_verify', {},
            (d) => {
                let html = IC.ok + ` ${esc(d.message)}`;
                const parts = [];
                if (d.cashflow_api)  parts.push(`CashFlow API: ${esc(d.cashflow_api)}`);
                if (d.rest_endpoint) parts.push(`REST: ${esc(d.rest_endpoint)}`);
                if (parts.length) html += `<br><small>${parts.join(' &nbsp; ')}</small>`;
                msg('#cf-connect-msg', html, 'ok');
                setBtn(this, false);
            },
            (e) => { msg('#cf-connect-msg', IC.err + ` ${esc(e)}`, 'err'); setBtn(this, false); }
        );
    });

    // ── Re-register webhooks ───────────────────────────────────────
    $('#cf-reregister-btn').on('click', function() {
        setBtn(this, true, 'Registering…');
        ajax('cashflow_reregister_webhooks', {},
            (d) => { msg('#cf-connect-msg', IC.ok + ` ${esc(d.message)}`, 'ok'); setBtn(this, false); },
            (e) => { msg('#cf-connect-msg', IC.err + ` ${esc(e)}`, 'err'); setBtn(this, false); }
        );
    });

    // ── Save settings ──────────────────────────────────────────────
    $('#cf-save-settings-btn').on('click', function() {
        const data = {};
        $('.cf-toggle input[type=checkbox]').each(function() {
            data[$(this).attr('name')] = $(this).is(':checked') ? 1 : 0;
        });
        setBtn(this, true, 'Saving…');
        ajax('cashflow_save_settings', data,
            () => {
                const el = document.getElementById('cf-settings-msg');
                if (el) {
                    el.innerHTML = IC.check + ' Saved';
                    el.style.display = 'inline-flex';
                    el.style.color = '#16a34a';
                    setTimeout(() => { el.style.display = 'none'; }, 2500);
                }
                setBtn(this, false);
            },
            (e) => { msg('#cf-settings-msg', IC.err + ` ${esc(e)}`, 'err'); setBtn(this, false); }
        );
    });

    // ── Sync log ───────────────────────────────────────────────────
    $('#cf-load-log-btn').on('click', function() {
        setBtn(this, true, 'Loading…');
        ajax('cashflow_get_sync_log', {},
            (d) => {
                const logs = d.logs || [];
                if (!logs.length) {
                    $('#cf-log-container').html('<p class="cf-log-empty">No sync events yet.</p>');
                    setBtn(this, false); return;
                }
                let t = '<table class="cf-log-table"><thead><tr><th>Event</th><th>Type</th><th>ID</th><th>Status</th><th>Message</th><th>Time</th></tr></thead><tbody>';
                logs.forEach(l => {
                    const ok = l.status === 'success';
                    const badge = ok
                        ? `<span class="cf-log-ok">${IC.check} ${esc(l.status)}</span>`
                        : `<span class="cf-log-err">${IC.x} ${esc(l.status)}</span>`;
                    t += `<tr><td>${esc(l.event_type)}</td><td>${esc(l.object_type)}</td><td>${esc(l.object_id)}</td><td>${badge}</td><td>${esc(l.message||'—')}</td><td style="color:var(--muted);white-space:nowrap">${esc(l.created_at)}</td></tr>`;
                });
                t += '</tbody></table>';
                $('#cf-log-container').html(t);
                setBtn(this, false);
            },
            (e) => { $('#cf-log-container').html(`<p style="color:var(--err)">${esc(e)}</p>`); setBtn(this, false); }
        );
    });

})(jQuery);
