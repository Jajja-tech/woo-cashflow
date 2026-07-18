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
        check: svg('<path d="M20 6 9 17l-5-5"/>'),
        x:     svg('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'),
        ok:    svg('<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>'),
        err:   svg('<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>'),
    };
    // Brand loader — SVG port of the app's CascadeLoader (mirrors cf_logo() in
    // includes/class-icons.php). One loader language across app + plugin.
    function brandMark(size, loading) {
        const rect = (cx, cy, w, h, d) =>
            `<g transform="rotate(-45 ${cx} ${cy})"><rect class="cf-ink" style="animation-delay:${d}" x="${cx - w/2}" y="${cy - h/2}" width="${w}" height="${h}" rx="17"/></g>`;
        const arrow =
            `<g transform="translate(31.3 221.6) rotate(-45) translate(0 -33)"><g class="cf-ink" style="animation-delay:.26s">` +
            `<rect x="0" y="16" width="241" height="34" rx="17"/><polygon points="226,0 226,66 270,33"/></g></g>`;
        return `<svg class="cf-mark${loading ? ' is-loading' : ''}" width="${size}" height="${size}" viewBox="0 0 250 250" fill="var(--cf)" style="vertical-align:-3px" aria-hidden="true">` +
            rect(51,55,40,34,'0s') + rect(88,93,143,34,'.13s') + arrow + rect(161,165,143,34,'.39s') + rect(197,202,40,34,'.52s') +
            `</svg>`;
    }
    const spin = brandMark(15, true);

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

    function ajax(action, data, onOk, onErr) {
        return $.post(ajaxUrl, { action, nonce, ...data })
            .done(r => r.success ? onOk(r.data) : onErr && onErr(r.data?.message || 'Error'))
            .fail(() => onErr && onErr('Network error'));
    }

    function esc(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ── Tabs (app ScrollableTabs behaviour) ────────────────────────
    $('.cf-tab').on('click', function() {
        const t = $(this).data('tab');
        $('.cf-tab').removeClass('is-active');
        $(this).addClass('is-active');
        $('.cf-panel').removeClass('is-active');
        $('.cf-panel[data-panel="' + t + '"]').addClass('is-active');
    });

    // ── Disconnect ─────────────────────────────────────────────────
    $('#cf-disconnect-btn').on('click', function() {
        if (!confirm('Disconnect this store locally? You can reconnect from the CashFlow app.')) return;
        setBtn(this, true, 'Disconnecting…');
        ajax('cashflow_disconnect', {},
            () => location.reload(),
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
