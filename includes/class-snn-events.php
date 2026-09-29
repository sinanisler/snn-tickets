<?php
/**
 * Events: when, where, how the ticket looks, which files ride along with
 * the ticket email, the event's own emails, its URL slug and its spots.
 *
 * An event is stored as a "ticket list" row. Everything that renders a
 * ticket -- the email, the PDF, the wallet passes, the calendar file --
 * reads from here.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Events {

    /** Words the built-in URLs use for themselves. */
    const RESERVED_SLUGS = ['door', 'ticket', 'page', 'feed', 'claim', 'tickets'];

    const OLD_SLUGS_OPTION = 'snn_tickets_old_slugs';

    /** Files a list can attach to its ticket emails. */
    public static function attachment_types() {
        return [
            'pdf'    => __('PDF ticket', 'snn-tickets'),
            'pkpass' => __('Apple Wallet pass (.pkpass)', 'snn-tickets'),
            'ics'    => __('Calendar invite (.ics)', 'snn-tickets'),
        ];
    }

    /**
     * The list row with event fields normalised. Returns null for an
     * unknown list.
     */
    public static function get($list_id) {
        global $wpdb;
        $table = SNN_T_DB::lists();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int)$list_id));
        return $row ? self::normalise($row) : null;
    }

    public static function get_by_slug($slug) {
        global $wpdb;
        $table = SNN_T_DB::lists();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s", (string)$slug));
        return $row ? self::normalise($row) : null;
    }

    public static function all() {
        global $wpdb;
        return array_map([__CLASS__, 'normalise'], (array)$wpdb->get_results("SELECT * FROM " . SNN_T_DB::lists() . " ORDER BY id DESC"));
    }

    public static function normalise($row) {
        foreach (['name', 'slug', 'venue', 'address', 'organizer', 'description', 'design', 'attachments'] as $k) {
            $row->$k = isset($row->$k) ? (string)$row->$k : '';
        }
        foreach (['event_start', 'event_end'] as $k) {
            $v = isset($row->$k) ? (string)$row->$k : '';
            $row->$k = ($v === '' || strpos($v, '0000') === 0) ? '' : $v;
        }
        $row->id = (int)($row->id ?? 0);
        $row->require_names = (int)($row->require_names ?? 0);
        $row->attachment_list = self::parse_attachments($row->attachments);
        return $row;
    }

    public static function parse_attachments($csv) {
        $valid = array_keys(self::attachment_types());
        $out   = [];
        foreach (explode(',', (string)$csv) as $a) {
            $a = trim($a);
            if (in_array($a, $valid, true) && !in_array($a, $out, true)) $out[] = $a;
        }
        return $out;
    }

    /**
     * Save event details. Unknown keys are ignored; keys that are missing
     * are left alone.
     */
    public static function save($list_id, $data) {
        global $wpdb;

        $row = [];
        if (isset($data['name']) && sanitize_text_field($data['name']) !== '') $row['name'] = sanitize_text_field($data['name']);
        foreach (['event_start', 'event_end'] as $k) {
            if (array_key_exists($k, $data)) $row[$k] = self::sanitize_datetime($data[$k]);
        }
        foreach (['venue', 'address', 'organizer'] as $k) {
            if (array_key_exists($k, $data)) $row[$k] = sanitize_text_field($data[$k]);
        }
        if (array_key_exists('description', $data)) $row['description'] = sanitize_textarea_field($data['description']);
        if (array_key_exists('design', $data)) {
            $row['design'] = array_key_exists((string)$data['design'], SNN_T_Design::presets()) ? (string)$data['design'] : '';
        }
        if (array_key_exists('attachments', $data)) {
            $row['attachments'] = implode(',', self::parse_attachments(implode(',', (array)$data['attachments'])));
        }
        if (array_key_exists('require_names', $data)) $row['require_names'] = !empty($data['require_names']) ? 1 : 0;

        // An end before the start is a typo, not a plan.
        $start = $row['event_start'] ?? null;
        $end   = $row['event_end'] ?? null;
        if ($start && $end && strtotime($end) < strtotime($start)) {
            $row['event_end'] = null;
        }

        if (!$row) return true;
        return false !== $wpdb->update(SNN_T_DB::lists(), $row, ['id' => (int)$list_id]);
    }

    /**
     * Accepts "Y-m-d H:i", "Y-m-d\TH:i" (datetime-local) or blank.
     *
     * @return string|null MySQL datetime or null
     */
    public static function sanitize_datetime($value) {
        $value = trim(str_replace('T', ' ', (string)$value));
        if ($value === '') return null;
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    /* ------------------------------------------------------------------
     * Slugs
     * ---------------------------------------------------------------- */

    public static function slugify($text) {
        $text = strtr((string)$text, [
            'ı' => 'i', 'İ' => 'i', 'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'Ğ' => 'g',
            'ç' => 'c', 'Ç' => 'c', 'ö' => 'o', 'Ö' => 'o', 'ü' => 'u', 'Ü' => 'u',
        ]);
        if (function_exists('remove_accents')) $text = remove_accents($text);
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
        return trim(substr($slug, 0, 80), '-');
    }

    /** A slug no other event uses. */
    public static function unique_slug($text, $except_id = 0) {
        global $wpdb;
        $base = self::slugify($text);
        if ($base === '' || in_array($base, self::RESERVED_SLUGS, true)) {
            $base = 'event' . ($base !== '' ? '-' . $base : '');
        }
        $slug = $base; $n = 2;
        while ((int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . SNN_T_DB::lists() . " WHERE slug = %s AND id <> %d", $slug, (int)$except_id))) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    /**
     * Change an event's slug, keeping the old one as a redirect so links
     * that were already shared keep working.
     */
    public static function set_slug($list_id, $wanted) {
        global $wpdb;
        $event = self::get($list_id);
        if (!$event) return '';
        $slug = self::unique_slug($wanted !== '' ? $wanted : $event->name, $list_id);
        if ($slug === $event->slug) return $slug;
        $old = (array)get_option(self::OLD_SLUGS_OPTION, []);
        if ($event->slug !== '') $old[$event->slug] = (int)$list_id;
        unset($old[$slug]);
        update_option(self::OLD_SLUGS_OPTION, $old, false);
        $wpdb->update(SNN_T_DB::lists(), ['slug' => $slug], ['id' => (int)$list_id]);
        return $slug;
    }

    /** Event id an old slug used to point at, or 0. */
    public static function redirected_slug($slug) {
        $old = (array)get_option(self::OLD_SLUGS_OPTION, []);
        return (int)($old[$slug] ?? 0);
    }

    /* ------------------------------------------------------------------
     * Lifecycle
     * ---------------------------------------------------------------- */

    /** @return int new event id */
    public static function create($data) {
        global $wpdb;
        $name = sanitize_text_field($data['name'] ?? '');
        if ($name === '') $name = __('Untitled event', 'snn-tickets');
        $wpdb->insert(SNN_T_DB::lists(), [
            'name'       => $name,
            'slug'       => self::unique_slug(($data['slug'] ?? '') !== '' ? $data['slug'] : $name),
            'emails'     => wp_json_encode(self::sanitize_emails($data['emails'] ?? [])),
            'created_at' => current_time('mysql'),
        ]);
        $id = (int)$wpdb->insert_id;
        if ($id) {
            unset($data['name']);
            self::save($id, $data);
        }
        return $id;
    }

    /**
     * Copy an event with its form, emails and look. People are not copied,
     * and the copy's sign-ups start closed.
     *
     * @return int new event id
     */
    public static function duplicate($list_id) {
        $event = self::get($list_id);
        if (!$event) return 0;
        $data = [
            'name'        => sprintf(__('%s (copy)', 'snn-tickets'), $event->name),
            'event_start' => $event->event_start,
            'event_end'   => $event->event_end,
            'venue'       => $event->venue,
            'address'     => $event->address,
            'organizer'   => $event->organizer,
            'description' => $event->description,
            'design'      => $event->design,
            'attachments' => $event->attachment_list,
            'emails'      => self::emails($event),
        ];
        $new  = self::create($data);
        $form = SNN_T_Forms::for_list($list_id);
        if ($new && $form) {
            SNN_T_Forms::save(0, [
                'name'     => $data['name'],
                'list_id'  => $new,
                'status'   => 'closed',
                'fields'   => $form->fields,
                'settings' => $form->settings,
            ]);
        }
        return $new;
    }

    /**
     * Delete an event with its form, people, tickets and emails.
     *
     * @return int number of tickets removed
     */
    public static function delete($list_id) {
        global $wpdb;
        $list_id = (int)$list_id;
        $tickets = $wpdb->get_results($wpdb->prepare("SELECT id, ticket_code FROM " . SNN_T_DB::tickets() . " WHERE list_id = %d", $list_id));
        foreach ((array)$tickets as $t) {
            SNN_T_QR::delete($t->ticket_code);
            $wpdb->delete(SNN_T_DB::queue(), ['ticket_id' => (int)$t->id], ['%d']);
        }
        $n = (int)$wpdb->delete(SNN_T_DB::tickets(), ['list_id' => $list_id], ['%d']);

        foreach (SNN_T_Forms::all_for_list($list_id) as $f) {
            $subs = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . SNN_T_DB::submissions() . " WHERE form_id = %d", (int)$f->id));
            foreach ((array)$subs as $sid) {
                $wpdb->delete(SNN_T_DB::queue(), ['submission_id' => (int)$sid], ['%d']);
            }
            $wpdb->delete(SNN_T_DB::submissions(), ['form_id' => (int)$f->id], ['%d']);
            SNN_T_Forms::delete((int)$f->id);
        }

        $wpdb->delete(SNN_T_DB::lists(), ['id' => $list_id], ['%d']);
        do_action('snn_tickets_event_deleted', $list_id);
        return $n;
    }

    /* ------------------------------------------------------------------
     * Spots
     * ---------------------------------------------------------------- */

    /**
     * Places taken: active tickets plus people still waiting for a
     * decision. The one place capacity is counted, whatever fills it.
     */
    public static function spots_taken($list_id) {
        global $wpdb;
        $list_id = (int)$list_id;
        $tickets = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . SNN_T_DB::tickets() . " WHERE list_id = %d AND status = 'active'", $list_id));
        $waiting = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . SNN_T_DB::submissions() . " s JOIN " . SNN_T_DB::forms() . " f ON f.id = s.form_id
             WHERE f.list_id = %d AND s.status = 'pending' AND (s.ticket_id IS NULL OR s.ticket_id = 0)", $list_id));
        // Other sources of held places, such as shop orders waiting for payment.
        return (int)apply_filters('snn_tickets_spots_taken', $tickets + $waiting, $list_id);
    }

    /** Spot limit from the event's form; 0 = no limit. */
    public static function spot_limit($list_id) {
        $form = SNN_T_Forms::for_list($list_id);
        return $form ? (int)$form->settings['max_tickets'] : 0;
    }

    public static function is_full($list_id) {
        $max = self::spot_limit($list_id);
        return $max > 0 && self::spots_taken($list_id) >= $max;
    }

    /* ------------------------------------------------------------------
     * Emails
     * ---------------------------------------------------------------- */

    /**
     * The emails an event sends. A blank subject or body means the
     * built-in wording is used.
     */
    public static function default_emails() {
        return [
            'ticket'       => ['on' => 1, 'subject' => '', 'body' => ''],
            'confirmation' => ['on' => 1, 'subject' => '', 'body' => ''],
            'rejection'    => ['on' => 0, 'subject' => '', 'body' => ''],
            'admin'        => ['on' => 1, 'subject' => '', 'body' => '', 'when' => 'waiting', 'to' => '', 'claims' => 1],
            // Shop orders: the buyer's list of tickets, and a ticket passed on to someone.
            'order'        => ['on' => 1, 'subject' => '', 'body' => ''],
            'gift'         => ['on' => 1, 'subject' => '', 'body' => ''],
        ];
    }

    public static function sanitize_emails($in) {
        $out = self::default_emails();
        $in  = is_array($in) ? $in : [];
        foreach ($out as $role => $d) {
            $r = (isset($in[$role]) && is_array($in[$role])) ? $in[$role] : [];
            if (array_key_exists('on', $r)) $out[$role]['on'] = !empty($r['on']) ? 1 : 0;
            $out[$role]['subject'] = sanitize_text_field($r['subject'] ?? '');
            $out[$role]['body']    = trim(wp_kses_post((string)($r['body'] ?? '')));
        }
        $when = $in['admin']['when'] ?? '';
        $out['admin']['when'] = in_array($when, ['waiting', 'all'], true) ? $when : 'waiting';
        $out['admin']['to'] = implode(', ', self::parse_recipients($in['admin']['to'] ?? ''));
        // Events saved before this switch existed get the notice.
        $out['admin']['claims'] = isset($in['admin']['claims']) ? (!empty($in['admin']['claims']) ? 1 : 0) : 1;
        return $out;
    }

    /** "a@x.com, b@y.com" → valid addresses only. */
    public static function parse_recipients($raw) {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string)$raw) as $a) {
            $a = strtolower(sanitize_email($a));
            if ($a !== '' && is_email($a) && !in_array($a, $out, true)) $out[] = $a;
        }
        return $out;
    }

    /** Who gets an event's "Notice to you": its own list, else the site admin. */
    public static function admin_recipients($event) {
        $to = self::parse_recipients(self::emails($event)['admin']['to']);
        if (!$to && is_email(get_option('admin_email'))) $to = [get_option('admin_email')];
        return $to;
    }

    public static function emails($event) {
        $raw = is_object($event) ? ($event->emails ?? '') : '';
        $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
        return self::sanitize_emails(is_array($decoded) ? $decoded : []);
    }

    public static function save_emails($list_id, $emails) {
        global $wpdb;
        return false !== $wpdb->update(SNN_T_DB::lists(),
            ['emails' => wp_json_encode(self::sanitize_emails($emails))], ['id' => (int)$list_id]);
    }

    /** Upgrade path: what a pre-4 form said about its emails. */
    public static function emails_from_form($form) {
        $e = self::default_emails();
        if (!$form) return $e;
        $s = $form->settings;
        $pick = function ($role, $subject_key, $body_key) use ($s) {
            $tpl = !empty($s['template_' . $role]) ? SNN_T_Mailer::get_template($s['template_' . $role]) : null;
            $subject = $subject_key !== '' ? (string)($s[$subject_key] ?? '') : '';
            $body    = $body_key !== '' ? (string)($s[$body_key] ?? '') : '';
            return [
                'subject' => $subject !== '' ? $subject : (string)($tpl['subject'] ?? ''),
                'body'    => $body !== '' ? $body : (string)($tpl['body'] ?? ''),
            ];
        };
        $e['ticket']       = array_merge($e['ticket'], $pick('ticket', 'ticket_subject', 'ticket_body'));
        $e['confirmation'] = array_merge($e['confirmation'], $pick('confirmation', 'confirmation_subject', 'confirmation_body'),
                                         ['on' => !empty($s['send_confirmation']) ? 1 : 0]);
        $e['rejection']    = array_merge($e['rejection'], $pick('rejection', '', ''),
                                         ['on' => !empty($s['send_rejection']) ? 1 : 0]);
        $e['admin']['on']   = !empty($s['notify_admin']) ? 1 : 0;
        $e['admin']['when'] = 'all';
        $e['admin']['to']   = (string)($s['notify_email'] ?? '');
        return self::sanitize_emails($e);
    }

    /* ------------------------------------------------------------------
     * Formatting
     * ---------------------------------------------------------------- */

    public static function format_date($event) {
        if (!$event || !$event->event_start) return '';
        return date_i18n(get_option('date_format', 'F j, Y'), strtotime($event->event_start));
    }

    public static function format_time($event) {
        if (!$event || !$event->event_start) return '';
        $tf  = get_option('time_format', 'H:i');
        $out = date_i18n($tf, strtotime($event->event_start));
        if ($event->event_end) {
            $same_day = substr($event->event_start, 0, 10) === substr($event->event_end, 0, 10);
            $out .= ' – ' . ($same_day
                ? date_i18n($tf, strtotime($event->event_end))
                : date_i18n(get_option('date_format', 'F j, Y') . ' ' . $tf, strtotime($event->event_end)));
        }
        return $out;
    }

    /** "Saturday, October 3, 2026 · 19:00 – 23:00" */
    public static function format_when($event) {
        $date = self::format_date($event);
        if ($date === '') return '';
        return $date . ' · ' . self::format_time($event);
    }

    public static function format_where($event) {
        if (!$event) return '';
        return trim($event->venue . ($event->venue && $event->address ? ', ' : '') . $event->address);
    }

    /**
     * Convert a site-local MySQL datetime to a UTC timestamp.
     */
    public static function to_utc_timestamp($local) {
        if (!$local) return 0;
        try {
            $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
            $dt = new DateTimeImmutable($local, $tz);
            return $dt->getTimestamp();
        } catch (Throwable $e) {
            return (int)strtotime($local);
        }
    }

    /* ------------------------------------------------------------------
     * Ticket data
     * ---------------------------------------------------------------- */

    /**
     * Everything a renderer needs to draw one ticket, from a ticket row.
     * Also used with made-up data for previews and test emails.
     */
    public static function ticket_data($ticket) {
        $event = self::get((int)$ticket->list_id);
        return self::build_ticket_data([
            'code'   => $ticket->ticket_code,
            'name'   => $ticket->name,
            'email'  => $ticket->email,
            'status' => $ticket->status ?? 'active',
        ], $event);
    }

    public static function build_ticket_data($t, $event) {
        return [
            'code'        => (string)($t['code'] ?? ''),
            'name'        => (string)($t['name'] ?? ''),
            'email'       => (string)($t['email'] ?? ''),
            'status'      => (string)($t['status'] ?? 'active'),
            'list_id'     => $event ? (int)$event->id : 0,
            'event'       => $event ? (string)$event->name : '',
            'when'        => self::format_when($event),
            'date'        => self::format_date($event),
            'time'        => self::format_time($event),
            'venue'       => $event ? $event->venue : '',
            'address'     => $event ? $event->address : '',
            'organizer'   => $event && $event->organizer !== '' ? $event->organizer : get_bloginfo('name'),
            'description' => $event ? $event->description : '',
            'start'       => $event ? $event->event_start : '',
            'end'         => $event ? $event->event_end : '',
            'design'      => SNN_T_Design::for_list($event),
            'scan_url'    => $t['code'] !== '' ? SNN_T_QR::scan_url($t['code']) : '',
        ];
    }

    /** Sample data for previews when no real ticket is at hand. */
    public static function sample_ticket_data($list_id = 0) {
        $event = $list_id ? self::get($list_id) : null;
        if (!$event) {
            $event = self::normalise((object)[
                'id'          => 0,
                'name'        => __('Design Summit 2026', 'snn-tickets'),
                'event_start' => date('Y-m-d 19:00:00', strtotime('+30 days')),
                'event_end'   => date('Y-m-d 23:00:00', strtotime('+30 days')),
                'venue'       => __('Grand Hall', 'snn-tickets'),
                'address'     => __('12 Harbour Street, Istanbul', 'snn-tickets'),
                'organizer'   => get_bloginfo('name'),
                'description' => __('Doors open 30 minutes before the start. Bring this ticket on your phone or printed.', 'snn-tickets'),
                'design'      => '',
                'attachments' => '',
            ]);
        }
        return self::build_ticket_data([
            'code'  => 'SAMPLE-TICKET',
            'name'  => __('Ada Lovelace', 'snn-tickets'),
            'email' => 'ada@example.com',
        ], $event);
    }
}
