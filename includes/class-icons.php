<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow icon system — inline Lucide SVGs.
 *
 * ONE reusable primitive for every glyph in the admin UI (Golden Rule #11).
 * Matches the CashFlow app, which uses lucide-react — NEVER emojis. Icons are
 * stroke-based (stroke=currentColor) so they inherit the surrounding text color.
 *
 * Usage:  echo cf_icon( 'plug' );            // 16px, inherits color
 *         echo cf_icon( 'refresh-cw', 14 );  // custom size
 */

if ( ! function_exists( 'cf_icon' ) ) {

    /**
     * Return an inline Lucide SVG string.
     *
     * @param string $name  Icon key (see $paths below).
     * @param int    $size  Width/height in px.
     * @param string $class Extra CSS class(es) for the <svg>.
     * @return string Sanitised, echo-ready SVG (empty string for unknown names).
     */
    function cf_icon( $name, $size = 16, $class = '' ) {
        $paths = cf_icon_paths();
        if ( ! isset( $paths[ $name ] ) ) {
            return '';
        }
        $size  = (int) $size;
        $class = trim( 'cf-ic ' . $class );
        return sprintf(
            '<svg class="%s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" '
            . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" '
            . 'stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
            esc_attr( $class ),
            $size,
            $size,
            $paths[ $name ] // trusted static SVG path data — not user input
        );
    }

    /**
     * Static Lucide path data (lucide.dev). Kept as one map so the icon set is
     * auditable in a single place.
     */
    function cf_icon_paths() {
        return [
            'plug'            => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
            'link-2'          => '<path d="M9 17H7A5 5 0 0 1 7 7h2"/><path d="M15 7h2a5 5 0 1 1 0 10h-2"/><line x1="8" x2="16" y1="12" y2="12"/>',
            'webhook'         => '<path d="M18 16.98h-5.99c-1.1 0-1.95.94-2.48 1.9A4 4 0 0 1 2 17c.01-.7.2-1.4.57-2"/><path d="m6 17 3.13-5.78c.53-.97.1-2.18-.5-3.1a4 4 0 1 1 6.89-4.06"/><path d="m12 6 3.13 5.73C15.66 12.7 16.9 13 18 13a4 4 0 0 1 0 8"/>',
            'settings'        => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
            'check'           => '<path d="M20 6 9 17l-5-5"/>',
            'x'               => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            'check-circle'    => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
            'x-circle'        => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
            'refresh-cw'      => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
            'shield-check'    => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
            'zap'             => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
            'arrow-left-right'=> '<path d="M8 3 4 7l4 4"/><path d="M4 7h16"/><path d="m16 21 4-4-4-4"/><path d="M20 17H4"/>',
            'truck'           => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 18.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
            'globe'           => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
            'scroll-text'     => '<path d="M15 12h-5"/><path d="M15 8h-5"/><path d="M19 17V5a2 2 0 0 0-2-2H4"/><path d="M8 21h12a2 2 0 0 0 2-2v-1a1 1 0 0 0-1-1H11a1 1 0 0 0-1 1v1a2 2 0 1 1-4 0V5a2 2 0 1 0-4 0v2a1 1 0 0 0 1 1h3"/>',
            'alert-triangle'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            'eye'             => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
            'eye-off'         => '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/>',
            'search'          => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'power'           => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
            'key'             => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
            'arrow-up-right'  => '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
        ];
    }
}

if ( ! function_exists( 'cf_logo' ) ) {

    /**
     * The CashFlow brand mark — a byte-for-byte port of the app's CascadeLoader
     * artwork (cashflow-frontend/src/components/shared/CascadeLoader.jsx). Static
     * it is the LOGO; with $animated=true it is the LOADER (the same one loader
     * language as the app). Colour inherits from `--cf`, so it renders in brand
     * violet wherever it's placed.
     *
     * @param int  $size     Rendered width/height in px (artwork authored at 250).
     * @param bool $animated true → cascade loading animation; false → static logo.
     */
    function cf_logo( $size = 40, $animated = false ) {
        $size  = (int) $size;
        $scale = $size / 250;
        // [ left, top, width, height, animation-delay ] — from CascadeLoader.
        $bars = [
            [ 51,  55,  40,  34, '0s'   ],   // short top-left
            [ 88,  93,  143, 34, '.13s' ],   // long upper
            [ 161, 165, 143, 34, '.39s' ],   // long lower
            [ 197, 202, 40,  34, '.52s' ],   // short bottom-right
        ];
        $ink = 'position:absolute;inset:0;background:var(--cf);transform-origin:center;border-radius:17px';

        $out  = sprintf(
            '<span class="cf-mark%s" style="width:%dpx;height:%dpx;position:relative;display:inline-block;flex-shrink:0" role="img" aria-label="CashFlow">',
            $animated ? ' is-loading' : '', $size, $size
        );
        $out .= sprintf(
            '<span style="position:absolute;top:0;left:0;width:250px;height:250px;transform:scale(%s);transform-origin:top left">',
            rtrim( rtrim( sprintf( '%.5f', $scale ), '0' ), '.' )
        );
        foreach ( $bars as [ $l, $t, $w, $h, $d ] ) {
            $out .= sprintf(
                '<span style="position:absolute;left:%dpx;top:%dpx;width:%dpx;height:%dpx;transform:translate(-50%%,-50%%) rotate(-45deg)">'
                . '<span class="cf-ink" style="%s;animation-delay:%s"></span></span>',
                $l, $t, $w, $h, $ink, $d
            );
        }
        // Arrow (shaft + head), grouped — delay .26s.
        $out .= '<span style="position:absolute;left:31.3px;top:221.6px;width:270px;height:66px;transform:translate(0,-50%) rotate(-45deg);transform-origin:left center">'
            . '<span class="cf-ink" style="position:absolute;inset:0;background:none;transform-origin:center;animation-delay:.26s">'
            . '<span style="position:absolute;left:0;top:16px;width:241px;height:34px;background:var(--cf);border-radius:17px 0 0 17px"></span>'
            . '<span style="position:absolute;left:226px;top:0;width:44px;height:66px;background:var(--cf);clip-path:polygon(0 0,0 100%,100% 50%)"></span>'
            . '</span></span>';
        $out .= '</span></span>';
        return $out;
    }
}
