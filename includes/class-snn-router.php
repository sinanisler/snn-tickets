<?php
/**
 * Built-in front-end URLs, so an event is live without creating a page:
 *
 *   /events/{slug}/        sign-up page (inside the theme)
 *   /events/{slug}/door/   door scanner for that event only
 *   /events/door/          door scanner for every event
 *   /events/ticket/{code}/ the attendee's own ticket (?k=signature)
 *
 * "events" is the base slug, changeable in Settings. Without pretty
 * permalinks the same pages answer to ?snn_route=... query strings.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Router {

    const BASE_OPTION    = 'snn_tickets_base_slug';
    const REWRITE_OPTION = 'snn_tickets_rewrite_ver';
    const REWRITE_VER    = '1';

    public static function init() {
        add_action('init', [__CLASS__, 'rewrites']);
        add_filter('query_vars', [__CLASS__, 'query_vars']);
        add_action('template_redirect', [__CLASS__, 'dispatch'], 1);
        add_filter('redirect_canonical', [__CLASS__, 'no_canonical'], 10, 2);
    }

    public static function base() {
        $b = sanitize_title((string)get_option(self::BASE_OPTION, 'events'));
        return $b !== '' ? $b : 'events';
    }

    public static function pretty() {
        return (bool)get_option('permalink_structure');
    }

    public static function rewrites() {
        $b = preg_quote(self::base(), '#');
        add_rewrite_rule("^{$b}/door/?$", 'index.php?snn_route=door', 'top');
        add_rewrite_rule("^{$b}/ticket/([^/]+)/?$", 'index.php?snn_route=ticket&snn_code=$matches[1]', 'top');
        add_rewrite_rule("^{$b}/([^/]+)/door/?$", 'index.php?snn_route=door&snn_event=$matches[1]', 'top');
        add_rewrite_rule("^{$b}/([^/]+)/?$", 'index.php?snn_route=event&snn_event=$matches[1]', 'top');

        // Rebuild the rules once whenever the base or this code changes.
        $ver = self::REWRITE_VER . '|' . self::base();
        if (get_option(self::REWRITE_OPTION) !== $ver) {
            flush_rewrite_rules(false);
            update_option(self::REWRITE_OPTION, $ver);
        }
    }

    public static function query_vars($vars) {
        return array_merge($vars, ['snn_route', 'snn_event', 'snn_code']);
    }

    /** Our URLs are final: do not let WordPress "correct" them. */
    public static function no_canonical($redirect, $requested) {
        return get_query_var('snn_route') ? false : $redirect;
    }

    /* ------------------------------------------------------------------
     * URLs
     * ---------------------------------------------------------------- */

    private static function url($route, $args = []) {
        if (self::pretty()) {
            $parts = [self::base()];
            if ($route === 'event') $parts[] = $args['snn_event'];
            if ($route === 'door' && !empty($args['snn_event'])) $parts[] = $args['snn_event'];
            if ($route === 'door') $parts[] = 'door';
            if ($route === 'ticket') { $parts[] = 'ticket'; $parts[] = $args['snn_code']; }
            $url = home_url(user_trailingslashit(implode('/', array_map('rawurlencode', $parts))));
            $extra = array_diff_key($args, ['snn_event' => 1, 'snn_code' => 1]);
            return $extra ? add_query_arg($extra, $url) : $url;
        }
        return add_query_arg(array_merge(['snn_route' => $route], $args), home_url('/'));
    }

    /** Sign-up page of an event (object or id). */
    public static function event_url($event) {
        if (!is_object($event)) $event = SNN_T_Events::get((int)$event);
        if (!$event || $event->slug === '') return '';
        return self::url('event', ['snn_event' => $event->slug]);
    }

    /** Door scanner, for one event or (null) for all of them. */
    public static function door_url($event = null) {
        if ($event !== null && !is_object($event)) $event = SNN_T_Events::get((int)$event);
        return self::url('door', $event ? ['snn_event' => $event->slug] : []);
    }

    public static function ticket_url($code) {
        return self::url('ticket', ['snn_code' => $code, 'k' => SNN_T_Files::key($code)]);
    }

    /** A real page already using the base path would be hidden by our URLs. */
    public static function base_conflict() {
        $page = get_page_by_path(self::base());
        return $page ? $page : null;
    }

    /* ------------------------------------------------------------------
     * Rendering
     * ---------------------------------------------------------------- */

    public static function dispatch() {
        $route = get_query_var('snn_route');
        if (!$route) return;

        $slug = sanitize_title((string)get_query_var('snn_event'));

        if ($route === 'ticket') {
            $code = sanitize_text_field(wp_unslash(get_query_var('snn_code')));
            $key  = sanitize_text_field(wp_unslash($_GET['k'] ?? ''));
            $ticket = ($code !== '' && (hash_equals(SNN_T_Files::key($code), $key) || current_user_can('manage_options')))
                ? SNN_T_Tickets::get_by_code($code) : null;
            nocache_headers();
            if (!$ticket) self::not_found(__('This ticket link is not valid.', 'snn-tickets'));
            SNN_T_Files::render_ticket_page(SNN_T_Events::ticket_data($ticket));
            exit;
        }

        $event = $slug !== '' ? SNN_T_Events::get_by_slug($slug) : null;
        if ($slug !== '' && !$event) {
            // An old slug: send people to where the event lives now.
            $moved = SNN_T_Events::redirected_slug($slug);
            $moved = $moved ? SNN_T_Events::get($moved) : null;
            if ($moved) {
                $to = $route === 'door' ? self::door_url($moved) : self::event_url($moved);
                if (!empty($_SERVER['QUERY_STRING'])) $to .= (strpos($to, '?') === false ? '?' : '&') . wp_unslash($_SERVER['QUERY_STRING']);
                wp_safe_redirect($to, 301);
                exit;
            }
            self::not_found(__('This event could not be found.', 'snn-tickets'));
        }

        if ($route === 'door') {
            nocache_headers();
            SNN_T_Scanner::render_page($event);
            exit;
        }

        if ($route === 'event' && $event) {
            self::render_in_theme($event->name, self::event_content($event));
            exit;
        }

        self::not_found(__('This page could not be found.', 'snn-tickets'));
    }

    private static function not_found($message) {
        status_header(404);
        nocache_headers();
        self::render_in_theme(__('Not found', 'snn-tickets'), '<p>' . esc_html($message) . '</p>', true);
        exit;
    }

    /** What the sign-up page shows above and around the form. */
    public static function event_content($event) {
        $form = SNN_T_Forms::for_list($event->id);
        $when  = SNN_T_Events::format_when($event);
        $where = SNN_T_Events::format_where($event);

        $out  = '<div class="snn-event-page">';
        $out .= '<header class="snn-event-head">';
        if ($when !== '') $out .= '<p class="snn-event-when">' . esc_html($when) . '</p>';
        $out .= '<h1 class="snn-event-title">' . esc_html($event->name) . '</h1>';
        if ($where !== '') $out .= '<p class="snn-event-where">' . esc_html($where) . '</p>';
        $out .= '</header>';
        if ($event->description !== '') $out .= '<div class="snn-event-desc">' . wpautop(esc_html($event->description)) . '</div>';
        // Tickets for sale, when the event sells them in the shop.
        $shop = (string)apply_filters('snn_tickets_event_shop', '', $event);
        $out .= $shop;
        if ($shop === '' || ($form && $form->status !== 'closed')) {
            $out .= $form
                ? SNN_T_Forms::shortcode(['id' => $form->id])
                : '<p class="snn-form-notice snn-warn">' . esc_html__('Sign-ups are not open for this event.', 'snn-tickets') . '</p>';
        }
        $out .= '</div>';
        $out .= '<style>.snn-event-page{max-width:640px;margin:0 auto;padding:32px 16px 48px}.snn-event-when{margin:0 0 6px;font-size:.85em;font-weight:600;letter-spacing:.04em;text-transform:uppercase;opacity:.75}.snn-event-title{margin:0 0 6px}.snn-event-where{margin:0 0 20px;opacity:.8}.snn-event-desc{margin:0 0 24px}</style>';
        return $out;
    }

    /**
     * Print a page inside the active theme: the classic header/footer, or
     * the header and footer template parts of a block theme.
     */
    public static function render_in_theme($title, $content, $noindex = false) {
        global $wp_query;
        if ($wp_query) { $wp_query->is_404 = false; $wp_query->is_home = false; $wp_query->is_page = true; }
        if (!headers_sent()) status_header($noindex ? 404 : 200);

        add_filter('pre_get_document_title', function () use ($title) {
            return $title . ' – ' . get_bloginfo('name');
        }, 99);
        if ($noindex) add_filter('wp_robots', 'wp_robots_no_robots');

        if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
            $header = do_blocks('<!-- wp:template-part {"slug":"header","tagName":"header"} /-->');
            $footer = do_blocks('<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->');
            ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if (!current_theme_supports('title-tag')): ?><title><?php echo esc_html(wp_get_document_title()); ?></title><?php endif; ?>
<?php wp_head(); ?>
</head>
<body <?php body_class('snn-tickets-page'); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
<?php echo $header; // rendered block markup ?>
<main class="wp-block-group snn-main"><?php echo $content; // built from escaped parts ?></main>
<?php echo $footer; // rendered block markup ?>
</div>
<?php wp_footer(); ?>
</body>
</html><?php
            return;
        }

        get_header();
        echo '<main id="primary" class="site-main snn-main">' . $content . '</main>'; // built from escaped parts
        get_footer();
    }
}
