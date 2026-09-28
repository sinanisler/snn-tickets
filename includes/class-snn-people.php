<?php
/**
 * People: everyone connected to an event, in one list.
 *
 * A person is either a ticket ("t12") or a sign-up that has no ticket yet
 * because it is waiting for approval or was declined ("s45"). An approved
 * sign-up shows up once, as its ticket.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_People {

    const PER_PAGE = 50;

    /** Filter key => label. */
    public static function filters() {
        return [
            'all'      => __('Everyone', 'snn-tickets'),
            'waiting'  => __('Waiting for you', 'snn-tickets'),
            'ticket'   => __('Has ticket', 'snn-tickets'),
            'in'       => __('Checked in', 'snn-tickets'),
            'problems' => __('Problems', 'snn-tickets'),
            'off'      => __('Cancelled & declined', 'snn-tickets'),
        ];
    }

    /** The union of tickets and ticket-less sign-ups for one event. */
    private static function base_sql($list_id) {
        global $wpdb;
        $t = SNN_T_DB::tickets();
        $s = SNN_T_DB::submissions();
        $f = SNN_T_DB::forms();
        $q = SNN_T_DB::queue();

        return $wpdb->prepare("
            SELECT 't' AS kind, t.id AS id, t.name AS name, t.email AS email, t.status AS st,
                   t.validate_count AS vc, t.last_validated AS lv, t.created_at AS created,
                   t.ticket_code AS code, t.source AS source, t.submission_id AS sid, sd.data AS answers,
                   (SELECT COUNT(*) FROM {$q} q WHERE q.ticket_id = t.id AND q.role = 'ticket' AND q.status = 'failed') AS failed,
                   (SELECT COUNT(*) FROM {$q} q WHERE q.ticket_id = t.id AND q.role = 'ticket' AND q.status IN ('pending','sending','sent')) AS mailed,
                   '' AS reason
            FROM {$t} t LEFT JOIN {$s} sd ON sd.id = t.submission_id
            WHERE t.list_id = %d
            UNION ALL
            SELECT 's', s.id, s.name, s.email, s.status, 0, NULL, s.created_at, '', 'form', s.id, s.data, 0, 0, s.decision_reason
            FROM {$s} s JOIN {$f} f ON f.id = s.form_id
            WHERE f.list_id = %d AND (s.ticket_id IS NULL OR s.ticket_id = 0) AND s.status IN ('pending','rejected')
        ", (int)$list_id, (int)$list_id);
    }

    private static function filter_sql($filter) {
        switch ($filter) {
            case 'waiting':  return "p.kind = 's' AND p.st = 'pending'";
            case 'ticket':   return "p.kind = 't' AND p.st = 'active'";
            case 'in':       return "p.kind = 't' AND p.st = 'active' AND p.vc > 0";
            case 'problems': return "p.kind = 't' AND p.st = 'active' AND p.failed > 0";
            case 'off':      return "((p.kind = 't' AND p.st = 'revoked') OR (p.kind = 's' AND p.st = 'rejected'))";
        }
        return '1=1';
    }

    /**
     * @return array [rows, total]
     */
    public static function query($list_id, $filter = 'all', $search = '', $paged = 1, $per = self::PER_PAGE) {
        global $wpdb;
        $where = self::filter_sql($filter);
        $args  = [];
        if ($search !== '') {
            $like  = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (p.name LIKE %s OR p.email LIKE %s OR p.code LIKE %s OR p.answers LIKE %s)';
            $args  = [$like, $like, $like, $like];
        }
        $from  = '(' . self::base_sql($list_id) . ') p';
        $count = "SELECT COUNT(*) FROM {$from} WHERE {$where}";
        $total = (int)$wpdb->get_var($args ? $wpdb->prepare($count, $args) : $count);

        $sql = "SELECT * FROM {$from} WHERE {$where} ORDER BY (p.kind = 's' AND p.st = 'pending') DESC, p.created DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($args, [(int)$per, max(0, ($paged - 1) * $per)])));
        return [array_map([__CLASS__, 'shape'], (array)$rows), $total];
    }

    public static function counts($list_id) {
        global $wpdb;
        $parts = [];
        foreach (array_keys(self::filters()) as $k) {
            $parts[] = 'SUM(CASE WHEN ' . self::filter_sql($k) . " THEN 1 ELSE 0 END) AS `{$k}`";
        }
        $row = $wpdb->get_row('SELECT ' . implode(', ', $parts) . ' FROM (' . self::base_sql($list_id) . ') p', ARRAY_A);
        $out = [];
        foreach (array_keys(self::filters()) as $k) $out[$k] = (int)($row[$k] ?? 0);
        return $out;
    }

    /** Add the derived status to a raw row. */
    public static function shape($r) {
        $r->key = $r->kind . (int)$r->id;
        $r->vc  = (int)$r->vc;
        $answers = json_decode((string)$r->answers, true);
        $r->answers = is_array($answers) ? $answers : [];

        if ($r->kind === 's') {
            $r->state = $r->st === 'pending' ? 'waiting' : 'declined';
        } elseif ($r->st === 'revoked') {
            $r->state = 'cancelled';
        } elseif ($r->vc > 0) {
            $r->state = 'in';
        } elseif ((int)$r->failed > 0) {
            $r->state = 'bounced';
        } elseif ($r->name === '' && $r->email === '') {
            $r->state = 'blank';
        } elseif ((int)$r->mailed > 0) {
            $r->state = 'sent';
        } else {
            $r->state = 'unsent';
        }
        return $r;
    }

    /** state => [label, chip class] */
    public static function states() {
        return [
            'waiting'   => [__('Waiting for you', 'snn-tickets'), 'warn'],
            'declined'  => [__('Declined', 'snn-tickets'), ''],
            'cancelled' => [__('Cancelled', 'snn-tickets'), ''],
            'in'        => [__('Checked in', 'snn-tickets'), 'ok'],
            'bounced'   => [__('Email failed', 'snn-tickets'), 'bad'],
            'blank'     => [__('Blank ticket', 'snn-tickets'), ''],
            'sent'      => [__('Ticket sent', 'snn-tickets'), 'info'],
            'unsent'    => [__('Not emailed yet', 'snn-tickets'), ''],
        ];
    }

    /** Look one person up by "t12" / "s45". */
    public static function find($list_id, $key) {
        global $wpdb;
        if (!preg_match('/^([ts])(\d+)$/', (string)$key, $m)) return null;
        $sql = 'SELECT * FROM (' . self::base_sql($list_id) . ') p WHERE p.kind = %s AND p.id = %d';
        $row = $wpdb->get_row($wpdb->prepare($sql, $m[1], (int)$m[2]));
        if (!$row && $m[1] === 's') {
            // An approved sign-up lives on as its ticket.
            $sub = SNN_T_Submissions::get((int)$m[2]);
            if ($sub && $sub->ticket_id) return self::find($list_id, 't' . (int)$sub->ticket_id);
        }
        return $row ? self::shape($row) : null;
    }

    /* ------------------------------------------------------------------
     * Actions
     * ---------------------------------------------------------------- */

    /**
     * Apply one action to one person.
     *
     * @return true|WP_Error
     */
    public static function act($list_id, $key, $do, $extra = []) {
        $p = self::find($list_id, $key);
        if (!$p) return new WP_Error('snn_p_missing', __('That person is no longer on this event.', 'snn-tickets'));
        $user = get_current_user_id();
        $note = sanitize_text_field($extra['note'] ?? '');

        if ($p->kind === 's') {
            switch ($do) {
                case 'approve': $r = SNN_T_Submissions::approve($p->id, $user, $note); return is_wp_error($r) ? $r : true;
                case 'decline': return SNN_T_Submissions::reject($p->id, $user, $note);
                case 'delete':  SNN_T_Submissions::delete($p->id); return true;
            }
            return new WP_Error('snn_p_na', __('That does not apply to someone without a ticket.', 'snn-tickets'));
        }

        switch ($do) {
            case 'resend':
                $r = SNN_T_Mailer::queue_ticket(SNN_T_Tickets::get($p->id));
                return is_wp_error($r) ? $r : true;
            case 'cancel':  SNN_T_Tickets::set_status($p->id, 'revoked'); return true;
            case 'restore': SNN_T_Tickets::set_status($p->id, 'active'); return true;
            case 'undo':    SNN_T_Tickets::undo_checkin($p->id); return true;
            case 'approve': return true; // already has a ticket
            case 'delete':
                if ((int)$p->sid) SNN_T_Submissions::delete((int)$p->sid, true);
                else SNN_T_Tickets::delete_ticket($p->id);
                return true;
        }
        return new WP_Error('snn_p_na', __('That does not apply to a ticket.', 'snn-tickets'));
    }

    /**
     * Add one named person and optionally email their ticket.
     *
     * @return int|WP_Error ticket id
     */
    public static function add_one($list_id, $name, $email, $send = true) {
        $name  = sanitize_text_field($name);
        $email = strtolower(sanitize_email($email));
        if ($name === '' && $email === '') return new WP_Error('snn_p_empty', __('Add a name or an email address.', 'snn-tickets'));
        if ($email !== '' && !is_email($email)) return new WP_Error('snn_p_email', __('That email address does not look right.', 'snn-tickets'));
        $id = SNN_T_Tickets::insert($list_id, $name, $email, SNN_T_Tickets::unique_code(8), null, 'manual');
        if ($id && $send && $email !== '') SNN_T_Mailer::queue_ticket(SNN_T_Tickets::get($id));
        return $id;
    }

    /** @return int number made */
    public static function add_blank($list_id, $count, $length = 8) {
        $count  = max(1, min(5000, (int)$count));
        $length = max(6, min(64, (int)$length));
        for ($i = 0; $i < $count; $i++) {
            SNN_T_Tickets::insert($list_id, '', '', SNN_T_Tickets::unique_code($length), null, 'blank');
        }
        return $count;
    }

    /* ------------------------------------------------------------------
     * Spreadsheets
     * ---------------------------------------------------------------- */

    /**
     * Read a CSV into its header and rows, whatever Excel did to it: BOM,
     * semicolons, Windows-1254.
     *
     * @return array|WP_Error ['headers' => [], 'rows' => [[]]]
     */
    public static function read_csv($path) {
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') return new WP_Error('snn_csv_empty', __('The file is empty.', 'snn-tickets'));

        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
        if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1254,ISO-8859-1');

        $first = strtok($raw, "\n");
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $h = fopen('php://temp', 'r+');
        fwrite($h, $raw);
        rewind($h);
        $headers = array_map(function ($c) { return trim((string)$c); }, (array)fgetcsv($h, 0, $delim, '"', '\\'));
        $rows = [];
        while (($row = fgetcsv($h, 0, $delim, '"', '\\')) !== false) {
            if ($row === [null] || !array_filter($row, 'strlen')) continue;
            $rows[] = $row;
        }
        fclose($h);
        return ['headers' => $headers, 'rows' => $rows];
    }

    /** Best guess at which columns hold the name and the email. */
    public static function guess_columns($headers) {
        $map = ['name' => null, 'email' => null];
        foreach ((array)$headers as $i => $col) {
            $c = mb_strtolower(trim((string)$col));
            if ($map['name'] === null && in_array($c, ['name', 'full name', 'fullname', 'ad soyad', 'adı soyadı', 'isim', 'ad', 'attendee'], true)) $map['name'] = $i;
            if ($map['email'] === null && in_array($c, ['email', 'e-mail', 'email address', 'e-posta', 'eposta', 'mail'], true)) $map['email'] = $i;
        }
        return $map;
    }

    /** Turn raw rows into [['name' =>, 'email' =>]] with the given columns. */
    public static function map_rows($rows, $name_col, $email_col) {
        $out = [];
        foreach ((array)$rows as $row) {
            $name  = $name_col !== null && $name_col !== '' ? sanitize_text_field($row[(int)$name_col] ?? '') : '';
            $email = $email_col !== null && $email_col !== '' ? sanitize_email($row[(int)$email_col] ?? '') : '';
            if ($name === '' && $email === '') continue;
            $out[] = ['name' => $name, 'email' => is_email($email) ? strtolower($email) : ''];
        }
        return $out;
    }

    /**
     * Parse a CSV with Name and Email columns found automatically.
     *
     * @return array|WP_Error [['name' =>, 'email' =>], ...]
     */
    public static function parse_csv($path) {
        $csv = self::read_csv($path);
        if (is_wp_error($csv)) return $csv;
        $map = self::guess_columns($csv['headers']);
        if ($map['name'] === null && $map['email'] === null) {
            return new WP_Error('snn_csv_header', __('The file needs a header row with Name and Email columns.', 'snn-tickets'));
        }
        return self::map_rows($csv['rows'], $map['name'], $map['email']);
    }

    /**
     * @return array [added, skipped]
     */
    public static function import($list_id, $people, $skip_dupes = true, $send = false, $length = 8) {
        $added = 0; $skipped = 0;
        foreach ($people as $p) {
            if ($skip_dupes && $p['email'] !== '' && SNN_T_Tickets::count_for_email($list_id, $p['email'])) { $skipped++; continue; }
            $id = SNN_T_Tickets::insert($list_id, $p['name'], $p['email'], SNN_T_Tickets::unique_code($length), null, 'import');
            if ($id && $send && $p['email'] !== '') SNN_T_Mailer::queue_ticket(SNN_T_Tickets::get($id));
            $added++;
        }
        return [$added, $skipped];
    }

    /* ------------------------------------------------------------------
     * Emailing everyone
     * ---------------------------------------------------------------- */

    /** How many would get an email: [unsent, all]. */
    public static function send_counts($list_id) {
        global $wpdb;
        $t = SNN_T_DB::tickets(); $q = SNN_T_DB::queue();
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT COUNT(*) AS all_n,
                   SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM {$q} q WHERE q.ticket_id = t.id AND q.role = 'ticket' AND q.status IN ('pending','sending','sent')) THEN 1 ELSE 0 END) AS unsent
            FROM {$t} t WHERE t.list_id = %d AND t.email <> '' AND t.status = 'active'", (int)$list_id));
        return ['unsent' => (int)($row->unsent ?? 0), 'all' => (int)($row->all_n ?? 0)];
    }

    /** @return int queued */
    public static function email_everyone($list_id, $who = 'unsent', $template = '') {
        global $wpdb;
        $tickets = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . SNN_T_DB::tickets() . " WHERE list_id = %d AND email <> '' AND status = 'active'", (int)$list_id));
        $q = SNN_T_DB::queue();
        $n = 0;
        foreach ((array)$tickets as $ticket) {
            if ($who === 'unsent') {
                $already = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$q} WHERE ticket_id = %d AND role = 'ticket' AND status IN ('pending','sending','sent')", (int)$ticket->id));
                if ($already) continue;
            }
            if (!is_wp_error(SNN_T_Mailer::queue_ticket($ticket, $template))) $n++;
        }
        return $n;
    }

    /* ------------------------------------------------------------------
     * History
     * ---------------------------------------------------------------- */

    /** Emails that went to (or about) a person, newest first. */
    public static function emails_for($p) {
        global $wpdb;
        $q = SNN_T_DB::queue();
        $conds = [];
        if ($p->kind === 't') $conds[] = $wpdb->prepare('ticket_id = %d', (int)$p->id);
        if ((int)$p->sid) $conds[] = $wpdb->prepare('submission_id = %d', (int)$p->sid);
        if (!$conds) return [];
        return (array)$wpdb->get_results("SELECT id, role, status, subject, to_email, attachments, sent_at, scheduled_at, created_at, last_error, attempts FROM {$q} WHERE " . implode(' OR ', $conds) . " ORDER BY id DESC");
    }
}
