<?php
/**
 * Event details attached to a ticket list: when, where, how it looks, and
 * which files ride along with the ticket email.
 *
 * A ticket list is the event. Everything that renders a ticket -- the email,
 * the PDF, the wallet passes, the calendar file -- reads from here.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Events {

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

    public static function normalise($row) {
        foreach (['venue', 'address', 'organizer', 'description', 'design', 'attachments'] as $k) {
            $row->$k = isset($row->$k) ? (string)$row->$k : '';
        }
        foreach (['event_start', 'event_end'] as $k) {
            $v = isset($row->$k) ? (string)$row->$k : '';
            $row->$k = ($v === '' || strpos($v, '0000') === 0) ? '' : $v;
        }
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
     * Save event details. Unknown keys are ignored.
     */
    public static function save($list_id, $data) {
        global $wpdb;

        $row = [
            'name'        => sanitize_text_field($data['name'] ?? ''),
            'event_start' => self::sanitize_datetime($data['event_start'] ?? ''),
            'event_end'   => self::sanitize_datetime($data['event_end'] ?? ''),
            'venue'       => sanitize_text_field($data['venue'] ?? ''),
            'address'     => sanitize_text_field($data['address'] ?? ''),
            'organizer'   => sanitize_text_field($data['organizer'] ?? ''),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'design'      => array_key_exists($data['design'] ?? '', SNN_T_Design::presets()) ? $data['design'] : '',
            'attachments' => implode(',', self::parse_attachments(implode(',', (array)($data['attachments'] ?? [])))),
        ];

        if ($row['name'] === '') unset($row['name']);

        // An end before the start is a typo, not a plan.
        if ($row['event_start'] && $row['event_end'] && strtotime($row['event_end']) < strtotime($row['event_start'])) {
            $row['event_end'] = null;
        }

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
