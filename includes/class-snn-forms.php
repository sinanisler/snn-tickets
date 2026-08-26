<?php
/**
 * Registration forms: storage, front-end rendering, submission handling and
 * the approval logic that decides whether a submission becomes a ticket.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Forms {

    const NONCE = 'snn_ticket_form_submit';

    /** Minimum seconds between a form rendering and a genuine submission. */
    const MIN_FILL_SECONDS = 3;

    public static function init() {
        add_shortcode('snn_ticket_form', [__CLASS__, 'shortcode']);
        add_action('admin_post_snn_ticket_form_submit',        [__CLASS__, 'handle_submit']);
        add_action('admin_post_nopriv_snn_ticket_form_submit', [__CLASS__, 'handle_submit']);
    }

    /* ------------------------------------------------------------------
     * Model
     * ---------------------------------------------------------------- */

    public static function field_types() {
        return [
            'text'     => 'Single line text',
            'email'    => 'Email address',
            'tel'      => 'Phone number',
            'number'   => 'Number',
            'date'     => 'Date',
            'textarea' => 'Paragraph text',
            'select'   => 'Dropdown',
            'radio'    => 'Radio buttons',
            'checkbox' => 'Checkboxes (multiple)',
            'consent'  => 'Consent checkbox',
            'hidden'   => 'Hidden value',
        ];
    }

    public static function operators() {
        return [
            'equals'       => 'is exactly',
            'not_equals'   => 'is not',
            'contains'     => 'contains',
            'starts_with'  => 'starts with',
            'is_empty'     => 'is empty',
            'not_empty'    => 'is not empty',
            'checked'      => 'is checked',
            'not_checked'  => 'is not checked',
            'email_domain' => 'email domain is',
            'in_list'      => 'is one of (comma separated)',
        ];
    }

    public static function default_settings() {
        return [
            'approval_mode'         => 'auto',
            'rules'                 => [],
            'rules_match'           => 'all',
            'rules_fallback'        => 'manual',
            'max_tickets'           => 0,
            'one_per_email'         => 1,
            'send_confirmation'     => 1,
            'send_rejection'        => 0,
            'template_confirmation' => '',
            'template_ticket'       => '',
            'template_rejection'    => '',
            // Per-form wording. Blank falls back to the selected template,
            // then to the built-in default.
            'confirmation_subject'  => '',
            'confirmation_body'     => '',
            'ticket_subject'        => '',
            'ticket_body'           => '',
            'notify_admin'          => 1,
            'notify_email'          => '',
            'submit_label'          => 'Register',
            'success_message'       => 'You are confirmed. Your ticket is on its way to your inbox.',
            'pending_message'       => 'Thanks! Your registration is being reviewed and we will email you shortly.',
            'full_message'          => 'Sorry, this event is fully booked.',
            'duplicate_message'     => 'You have already registered with this email address.',
            'error_message'         => 'Something went wrong. Please try again.',
            'redirect_url'          => '',
        ];
    }

    public static function default_fields() {
        return [
            [
                'key'         => 'name',
                'type'        => 'text',
                'label'       => 'Full name',
                'placeholder' => '',
                'required'    => 1,
                'options'     => [],
                'map_to'      => 'name',
            ],
            [
                'key'         => 'email',
                'type'        => 'email',
                'label'       => 'Email address',
                'placeholder' => '',
                'required'    => 1,
                'options'     => [],
                'map_to'      => 'email',
            ],
        ];
    }

    public static function get($id) {
        global $wpdb;
        $table = SNN_T_DB::forms();
        $form  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int)$id));
        return $form ? self::hydrate($form) : null;
    }

    public static function all() {
        global $wpdb;
        $table = SNN_T_DB::forms();
        $rows  = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC");
        return array_map([__CLASS__, 'hydrate'], $rows);
    }

    private static function hydrate($form) {
        $fields   = json_decode((string)$form->fields, true);
        $settings = json_decode((string)$form->settings, true);

        $form->fields   = is_array($fields) ? $fields : self::default_fields();
        $form->settings = array_merge(self::default_settings(), is_array($settings) ? $settings : []);

        return $form;
    }

    public static function save($id, $data) {
        global $wpdb;
        $table = SNN_T_DB::forms();
        $now   = current_time('mysql');

        $row = [
            'name'       => sanitize_text_field($data['name'] ?? 'Untitled form'),
            'list_id'    => (int)($data['list_id'] ?? 0),
            'status'     => in_array($data['status'] ?? 'active', ['active', 'closed'], true) ? $data['status'] : 'active',
            'fields'     => wp_json_encode(self::sanitize_fields($data['fields'] ?? [])),
            'settings'   => wp_json_encode(self::sanitize_settings($data['settings'] ?? [])),
            'updated_at' => $now,
        ];
        $formats = ['%s', '%d', '%s', '%s', '%s', '%s'];

        if ($id) {
            $wpdb->update($table, $row, ['id' => (int)$id], $formats, ['%d']);
            return (int)$id;
        }

        $row['created_at'] = $now;
        $formats[] = '%s';
        $wpdb->insert($table, $row, $formats);
        return (int)$wpdb->insert_id;
    }

    public static function delete($id) {
        global $wpdb;
        $wpdb->delete(SNN_T_DB::forms(), ['id' => (int)$id], ['%d']);
    }

    public static function sanitize_fields($fields) {
        $out  = [];
        $seen = [];
        $types = self::field_types();

        foreach ((array)$fields as $f) {
            $label = sanitize_text_field($f['label'] ?? '');
            if ($label === '') continue;

            $type = isset($types[$f['type'] ?? '']) ? $f['type'] : 'text';

            $key = sanitize_key($f['key'] ?? '');
            if ($key === '') {
                $key = sanitize_key(sanitize_title($label));
            }
            if ($key === '') $key = 'field';
            // Keys address fields in rules and {field:key} tags, so they
            // have to stay unique.
            $base = $key; $n = 2;
            while (isset($seen[$key])) { $key = $base . '_' . $n; $n++; }
            $seen[$key] = true;

            $options = [];
            foreach ((array)($f['options'] ?? []) as $opt) {
                $opt = sanitize_text_field($opt);
                if ($opt !== '') $options[] = $opt;
            }

            $map_to = in_array($f['map_to'] ?? '', ['name', 'email'], true) ? $f['map_to'] : '';

            $out[] = [
                'key'         => $key,
                'type'        => $type,
                'label'       => $label,
                'placeholder' => sanitize_text_field($f['placeholder'] ?? ''),
                'required'    => !empty($f['required']) ? 1 : 0,
                'options'     => $options,
                'map_to'      => $map_to,
                'default'     => sanitize_text_field($f['default'] ?? ''),
            ];
        }

        return $out;
    }

    public static function sanitize_settings($settings) {
        $d   = self::default_settings();
        $s   = is_array($settings) ? $settings : [];
        $out = $d;

        $out['approval_mode']  = in_array($s['approval_mode'] ?? '', ['auto', 'manual', 'conditional'], true) ? $s['approval_mode'] : 'auto';
        $out['rules_match']    = in_array($s['rules_match'] ?? '', ['any', 'all'], true) ? $s['rules_match'] : 'all';
        $out['rules_fallback'] = in_array($s['rules_fallback'] ?? '', ['manual', 'reject'], true) ? $s['rules_fallback'] : 'manual';

        $ops = self::operators();
        $rules = [];
        foreach ((array)($s['rules'] ?? []) as $r) {
            $field = sanitize_key($r['field'] ?? '');
            $op    = isset($ops[$r['op'] ?? '']) ? $r['op'] : 'equals';
            if ($field === '') continue;
            $rules[] = [
                'field' => $field,
                'op'    => $op,
                'value' => sanitize_text_field($r['value'] ?? ''),
            ];
        }
        $out['rules'] = $rules;

        $out['max_tickets']   = max(0, (int)($s['max_tickets'] ?? 0));
        $out['one_per_email'] = !empty($s['one_per_email']) ? 1 : 0;

        foreach (['send_confirmation', 'send_rejection', 'notify_admin'] as $k) {
            $out[$k] = !empty($s[$k]) ? 1 : 0;
        }
        foreach (['template_confirmation', 'template_ticket', 'template_rejection'] as $k) {
            $out[$k] = sanitize_text_field($s[$k] ?? '');
        }
        foreach (['confirmation_subject', 'ticket_subject'] as $k) {
            $out[$k] = sanitize_text_field($s[$k] ?? '');
        }
        // Bodies are HTML emails the admin writes, so keep the markup that
        // post content is allowed to carry.
        foreach (['confirmation_body', 'ticket_body'] as $k) {
            $out[$k] = trim(wp_kses_post((string)($s[$k] ?? '')));
        }

        $notify = sanitize_email($s['notify_email'] ?? '');
        $out['notify_email'] = ($notify && is_email($notify)) ? $notify : '';

        foreach (['submit_label', 'success_message', 'pending_message', 'full_message',
                  'duplicate_message', 'error_message'] as $k) {
            $val = sanitize_text_field($s[$k] ?? '');
            $out[$k] = $val !== '' ? $val : $d[$k];
        }

        $redirect = esc_url_raw($s['redirect_url'] ?? '');
        $out['redirect_url'] = $redirect;

        return $out;
    }

    /* ------------------------------------------------------------------
     * Approval logic
     * ---------------------------------------------------------------- */

    /**
     * Evaluate one rule against submitted data.
     */
    public static function rule_matches($rule, $data) {
        $raw = $data[$rule['field']] ?? '';
        $value = is_array($raw) ? implode(', ', $raw) : (string)$raw;
        $target = (string)($rule['value'] ?? '');

        switch ($rule['op']) {
            case 'equals':
                return strcasecmp(trim($value), trim($target)) === 0;
            case 'not_equals':
                return strcasecmp(trim($value), trim($target)) !== 0;
            case 'contains':
                return $target !== '' && stripos($value, $target) !== false;
            case 'starts_with':
                return $target !== '' && stripos($value, $target) === 0;
            case 'is_empty':
                return trim($value) === '';
            case 'not_empty':
                return trim($value) !== '';
            case 'checked':
                return !empty($raw);
            case 'not_checked':
                return empty($raw);
            case 'email_domain':
                $domain = strtolower(substr(strrchr($value, '@') ?: '', 1));
                return $domain !== '' && $domain === strtolower(trim($target));
            case 'in_list':
                $list = array_filter(array_map('trim', explode(',', $target)));
                foreach ($list as $candidate) {
                    if (strcasecmp(trim($value), $candidate) === 0) return true;
                }
                return false;
        }

        return false;
    }

    /**
     * Decide what happens to a submission.
     *
     * @return string 'approved'|'pending'|'rejected'
     */
    public static function decide($form, $data) {
        $mode = $form->settings['approval_mode'];

        if ($mode === 'auto')   return 'approved';
        if ($mode === 'manual') return 'pending';

        // conditional
        $rules = $form->settings['rules'];
        if (empty($rules)) {
            return $form->settings['rules_fallback'] === 'reject' ? 'rejected' : 'pending';
        }

        $matched = 0;
        foreach ($rules as $rule) {
            if (self::rule_matches($rule, $data)) $matched++;
        }

        $passes = $form->settings['rules_match'] === 'any'
            ? $matched > 0
            : $matched === count($rules);

        if ($passes) return 'approved';

        return $form->settings['rules_fallback'] === 'reject' ? 'rejected' : 'pending';
    }

    /* ------------------------------------------------------------------
     * Front end
     * ---------------------------------------------------------------- */

    public static function shortcode($atts) {
        $atts = shortcode_atts(['id' => 0], $atts, 'snn_ticket_form');
        $form = self::get((int)$atts['id']);

        if (!$form) {
            return current_user_can('manage_options')
                ? '<p><strong>SNN Tickets:</strong> no form with id ' . (int)$atts['id'] . '.</p>'
                : '';
        }

        ob_start();
        self::render_form($form);
        return ob_get_clean();
    }

    private static function is_full($form) {
        $max = (int)$form->settings['max_tickets'];
        if ($max <= 0) return false;
        return self::issued_count($form) >= $max;
    }

    /**
     * Places taken: issued tickets plus submissions still awaiting a decision.
     */
    public static function issued_count($form) {
        global $wpdb;
        $subs = SNN_T_DB::submissions();
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$subs} WHERE form_id = %d AND status IN ('approved','pending')",
            (int)$form->id
        ));
    }

    private static function render_form($form) {
        $result = isset($_GET['snn_result']) ? sanitize_key(wp_unslash($_GET['snn_result'])) : '';
        $for_id = isset($_GET['snn_form']) ? (int)$_GET['snn_form'] : 0;
        $errors = [];
        $old    = [];

        if (!empty($_GET['snn_err'])) {
            $stash = get_transient('snn_t_err_' . sanitize_key(wp_unslash($_GET['snn_err'])));
            if (is_array($stash)) {
                $errors = $stash['errors'] ?? [];
                $old    = $stash['values'] ?? [];
            }
        }

        echo '<div class="snn-ticket-form-wrap" id="snn-form-' . (int)$form->id . '">';
        self::render_styles();

        if ($for_id === (int)$form->id && $result) {
            $messages = [
                'approved'  => ['ok',   $form->settings['success_message']],
                'pending'   => ['ok',   $form->settings['pending_message']],
                'rejected'  => ['warn', $form->settings['pending_message']],
                'full'      => ['warn', $form->settings['full_message']],
                'duplicate' => ['warn', $form->settings['duplicate_message']],
                'error'     => ['err',  $form->settings['error_message']],
            ];
            if (isset($messages[$result])) {
                list($kind, $text) = $messages[$result];
                echo '<div class="snn-form-notice snn-' . esc_attr($kind) . '">' . esc_html($text) . '</div>';
                if (in_array($result, ['approved', 'pending', 'rejected'], true)) {
                    echo '</div>';
                    return; // form done, do not re-render the fields
                }
            }
        }

        if ($form->status === 'closed') {
            echo '<div class="snn-form-notice snn-warn">' . esc_html($form->settings['full_message']) . '</div></div>';
            return;
        }

        if (self::is_full($form)) {
            echo '<div class="snn-form-notice snn-warn">' . esc_html($form->settings['full_message']) . '</div></div>';
            return;
        }

        if ($errors) {
            echo '<div class="snn-form-notice snn-err"><ul style="margin:0;padding-left:18px;">';
            foreach ($errors as $e) echo '<li>' . esc_html($e) . '</li>';
            echo '</ul></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="snn-ticket-form">';
        echo '<input type="hidden" name="action" value="snn_ticket_form_submit">';
        echo '<input type="hidden" name="form_id" value="' . (int)$form->id . '">';
        echo '<input type="hidden" name="redirect_to" value="' . esc_attr(self::current_url()) . '">';
        echo '<input type="hidden" name="snn_t" value="' . esc_attr(time()) . '">';
        wp_nonce_field(self::NONCE, 'snn_nonce');

        // Honeypot: a real person never fills this in.
        echo '<div class="snn-hp" aria-hidden="true">'
           . '<label>Leave this field empty<input type="text" name="snn_website" tabindex="-1" autocomplete="off"></label>'
           . '</div>';

        foreach ($form->fields as $field) {
            self::render_field($field, $old[$field['key']] ?? null);
        }

        echo '<p class="snn-form-submit"><button type="submit">'
           . esc_html($form->settings['submit_label']) . '</button></p>';
        echo '</form></div>';
    }

    private static function render_field($field, $old = null) {
        $id       = 'snn-f-' . esc_attr($field['key']);
        $name     = 'snn_field[' . esc_attr($field['key']) . ']';
        $required = !empty($field['required']);
        $value    = $old !== null ? $old : ($field['default'] ?? '');

        if ($field['type'] === 'hidden') {
            echo '<input type="hidden" name="' . $name . '" value="' . esc_attr($value) . '">';
            return;
        }

        echo '<div class="snn-field snn-field-' . esc_attr($field['type']) . '">';

        $label_html = esc_html($field['label']) . ($required ? ' <span class="snn-req">*</span>' : '');

        switch ($field['type']) {
            case 'textarea':
                echo '<label for="' . $id . '">' . $label_html . '</label>';
                echo '<textarea id="' . $id . '" name="' . $name . '" rows="4"'
                   . ($required ? ' required' : '')
                   . ' placeholder="' . esc_attr($field['placeholder']) . '">' . esc_textarea((string)$value) . '</textarea>';
                break;

            case 'select':
                echo '<label for="' . $id . '">' . $label_html . '</label>';
                echo '<select id="' . $id . '" name="' . $name . '"' . ($required ? ' required' : '') . '>';
                echo '<option value="">' . esc_html($field['placeholder'] ?: 'Choose…') . '</option>';
                foreach ($field['options'] as $opt) {
                    echo '<option value="' . esc_attr($opt) . '"' . selected($value, $opt, false) . '>'
                       . esc_html($opt) . '</option>';
                }
                echo '</select>';
                break;

            case 'radio':
                echo '<span class="snn-label">' . $label_html . '</span>';
                foreach ($field['options'] as $i => $opt) {
                    $oid = $id . '-' . $i;
                    echo '<label class="snn-choice" for="' . $oid . '">'
                       . '<input type="radio" id="' . $oid . '" name="' . $name . '" value="' . esc_attr($opt) . '"'
                       . checked($value, $opt, false) . ($required ? ' required' : '') . '> '
                       . esc_html($opt) . '</label>';
                }
                break;

            case 'checkbox':
                echo '<span class="snn-label">' . $label_html . '</span>';
                $selected = is_array($value) ? $value : [];
                foreach ($field['options'] as $i => $opt) {
                    $oid = $id . '-' . $i;
                    echo '<label class="snn-choice" for="' . $oid . '">'
                       . '<input type="checkbox" id="' . $oid . '" name="' . $name . '[]" value="' . esc_attr($opt) . '"'
                       . (in_array($opt, $selected, true) ? ' checked' : '') . '> '
                       . esc_html($opt) . '</label>';
                }
                break;

            case 'consent':
                echo '<label class="snn-choice snn-consent" for="' . $id . '">'
                   . '<input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"'
                   . checked((string)$value, '1', false) . ($required ? ' required' : '') . '> '
                   . $label_html . '</label>';
                break;

            default: // text, email, tel, number, date
                echo '<label for="' . $id . '">' . $label_html . '</label>';
                echo '<input type="' . esc_attr($field['type']) . '" id="' . $id . '" name="' . $name . '"'
                   . ' value="' . esc_attr((string)$value) . '"'
                   . ' placeholder="' . esc_attr($field['placeholder']) . '"'
                   . ($required ? ' required' : '')
                   . ($field['type'] === 'email' ? ' autocomplete="email"' : '')
                   . '>';
        }

        echo '</div>';
    }

    private static function render_styles() {
        static $done = false;
        if ($done) return;
        $done = true;
        ?>
        <style>
        .snn-ticket-form-wrap{max-width:560px}
        .snn-ticket-form .snn-field{margin-bottom:16px}
        .snn-ticket-form label,.snn-ticket-form .snn-label{display:block;font-weight:600;margin-bottom:6px}
        .snn-ticket-form input[type=text],.snn-ticket-form input[type=email],
        .snn-ticket-form input[type=tel],.snn-ticket-form input[type=number],
        .snn-ticket-form input[type=date],.snn-ticket-form textarea,
        .snn-ticket-form select{width:100%;padding:10px 12px;border:1px solid #c3c4c7;border-radius:4px;
            font:inherit;background:#fff;box-sizing:border-box}
        .snn-ticket-form textarea{resize:vertical}
        .snn-ticket-form .snn-choice{display:block;font-weight:400;margin:0 0 6px}
        .snn-ticket-form .snn-choice input{margin-right:8px}
        .snn-ticket-form .snn-req{color:#b3261e}
        .snn-ticket-form .snn-form-submit button{padding:11px 22px;border:0;border-radius:4px;
            background:#111;color:#fff;font:inherit;font-weight:600;cursor:pointer}
        .snn-ticket-form .snn-form-submit button:hover{background:#333}
        .snn-hp{position:absolute!important;left:-9999px!important;height:1px;overflow:hidden}
        .snn-form-notice{padding:12px 14px;border-radius:4px;margin-bottom:16px;border-left:4px solid}
        .snn-form-notice.snn-ok{background:#edf7ed;border-color:#0a7d32}
        .snn-form-notice.snn-warn{background:#fff8e5;border-color:#dba617}
        .snn-form-notice.snn-err{background:#fcf0f1;border-color:#b3261e}
        </style>
        <?php
    }

    private static function current_url() {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = $_SERVER['HTTP_HOST'] ?? '';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        return esc_url_raw($scheme . $host . $uri);
    }

    /* ------------------------------------------------------------------
     * Submission
     * ---------------------------------------------------------------- */

    public static function handle_submit() {
        $form_id = isset($_POST['form_id']) ? (int)$_POST['form_id'] : 0;
        $form    = self::get($form_id);

        $redirect = isset($_POST['redirect_to'])
            ? esc_url_raw(wp_unslash($_POST['redirect_to']))
            : home_url('/');

        if (!$form || $form->status === 'closed') {
            self::bounce($redirect, $form_id, 'error');
        }

        if (!isset($_POST['snn_nonce']) || !wp_verify_nonce(wp_unslash($_POST['snn_nonce']), self::NONCE)) {
            self::bounce($redirect, $form_id, 'error');
        }

        // Spam gates: honeypot, minimum fill time, per-IP throttle.
        if (!empty($_POST['snn_website'])) {
            self::bounce($redirect, $form_id, 'error');
        }
        $started = isset($_POST['snn_t']) ? (int)$_POST['snn_t'] : 0;
        if ($started && (time() - $started) < self::MIN_FILL_SECONDS) {
            self::bounce($redirect, $form_id, 'error');
        }
        if (!self::submit_rate_ok()) {
            self::bounce($redirect, $form_id, 'error');
        }

        $raw = isset($_POST['snn_field']) && is_array($_POST['snn_field'])
            ? wp_unslash($_POST['snn_field'])
            : [];

        list($data, $errors, $name, $email) = self::collect($form, $raw);

        if ($errors) {
            self::bounce_with_errors($redirect, $form_id, $errors, $data);
        }

        // Capacity
        if (self::is_full($form)) {
            self::bounce($redirect, $form_id, 'full');
        }

        // One registration per email address
        if ($form->settings['one_per_email'] && $email) {
            global $wpdb;
            $subs = SNN_T_DB::submissions();
            $dupe = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$subs} WHERE form_id = %d AND email = %s AND status IN ('approved','pending')",
                (int)$form->id, $email
            ));
            if ($dupe > 0) {
                self::bounce($redirect, $form_id, 'duplicate');
            }
        }

        $decision = self::decide($form, $data);

        $submission_id = SNN_T_Submissions::create([
            'form_id' => (int)$form->id,
            'status'  => $decision === 'approved' ? 'approved' : ($decision === 'rejected' ? 'rejected' : 'pending'),
            'name'    => $name,
            'email'   => $email,
            'data'    => $data,
            'ip'      => SNN_T_Tickets::client_ip(),
        ]);

        if (!$submission_id) {
            self::bounce($redirect, $form_id, 'error');
        }

        if ($decision === 'approved') {
            SNN_T_Submissions::approve($submission_id, 0, 'Auto-approved by form logic');
        } elseif ($decision === 'rejected') {
            SNN_T_Submissions::reject($submission_id, 0, 'Auto-rejected by form logic');
        } elseif ($form->settings['send_confirmation'] && $email) {
            // Awaiting review: acknowledge receipt now, the ticket comes later.
            SNN_T_Mailer::enqueue_from_template('confirmation', $form->settings['template_confirmation'], [
                'name'          => $name,
                'email'         => $email,
                'list_name'     => self::list_name($form->list_id),
                'form_name'     => $form->name,
                'fields'        => $data,
                'submission_id' => $submission_id,
            ], self::mail_override($form, 'confirmation'));
        }

        self::notify_admin($form, $submission_id, $name, $email, $decision);

        if ($form->settings['redirect_url']) {
            wp_safe_redirect($form->settings['redirect_url']);
            exit;
        }

        self::bounce($redirect, $form_id, $decision);
    }

    /**
     * Validate and normalise the posted values.
     *
     * @return array [data, errors, name, email]
     */
    private static function collect($form, $raw) {
        $data   = [];
        $errors = [];
        $name   = '';
        $email  = '';

        foreach ($form->fields as $field) {
            $key   = $field['key'];
            $value = $raw[$key] ?? ($field['type'] === 'checkbox' ? [] : '');

            if ($field['type'] === 'checkbox') {
                $value = array_values(array_filter(array_map('sanitize_text_field', (array)$value)));
                // Only accept values the form actually offers.
                $value = array_values(array_intersect($value, $field['options']));
                $empty = empty($value);
            } elseif ($field['type'] === 'consent') {
                $value = !empty($value) ? '1' : '';
                $empty = $value === '';
            } elseif ($field['type'] === 'textarea') {
                $value = sanitize_textarea_field((string)$value);
                $empty = trim($value) === '';
            } elseif ($field['type'] === 'email') {
                $value = sanitize_email(trim((string)$value));
                $empty = $value === '';
                if (!$empty && !is_email($value)) {
                    $errors[] = sprintf('%s does not look like a valid email address.', $field['label']);
                }
            } else {
                $value = sanitize_text_field((string)$value);
                $empty = trim($value) === '';

                if (in_array($field['type'], ['select', 'radio'], true) && !$empty
                    && !in_array($value, $field['options'], true)) {
                    $errors[] = sprintf('%s is not one of the available choices.', $field['label']);
                }
            }

            if (!empty($field['required']) && $empty) {
                $errors[] = sprintf('%s is required.', $field['label']);
            }

            $data[$key] = $value;

            if ($field['map_to'] === 'name'  && $name === '')  $name  = is_array($value) ? '' : (string)$value;
            if ($field['map_to'] === 'email' && $email === '') $email = is_array($value) ? '' : (string)$value;
        }

        // A form with no field mapped to email cannot deliver a ticket.
        if ($email === '') {
            foreach ($data as $v) {
                if (!is_array($v) && is_email($v)) { $email = $v; break; }
            }
        }

        if ($email !== '' && !is_email($email)) {
            $errors[] = 'A valid email address is required.';
        }

        return [$data, $errors, $name, $email];
    }

    private static function submit_rate_ok() {
        $key  = 'snn_t_sub_' . md5(SNN_T_Tickets::client_ip());
        $hits = (int)get_transient($key);
        if ($hits >= 10) return false;
        set_transient($key, $hits + 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    /**
     * The per-form subject/body pair for a mail role, or null when the form
     * has not overridden that role's wording.
     */
    public static function mail_override($form, $role) {
        if (!$form || !in_array($role, ['confirmation', 'ticket'], true)) return null;

        $subject = trim((string)($form->settings[$role . '_subject'] ?? ''));
        $body    = trim((string)($form->settings[$role . '_body'] ?? ''));
        if ($subject === '' && $body === '') return null;

        return ['subject' => $subject, 'body' => $body];
    }

    public static function list_name($list_id) {
        global $wpdb;
        $lists = SNN_T_DB::lists();
        return (string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$lists} WHERE id = %d", (int)$list_id));
    }

    public static function notify_admin($form, $submission_id, $name, $email, $status) {
        if (empty($form->settings['notify_admin'])) return;

        $to = $form->settings['notify_email'] ?: get_option('admin_email');
        if (!$to || !is_email($to)) return;

        $link = admin_url('admin.php?page=snn-tickets-submissions&submission=' . (int)$submission_id);

        $subject = sprintf('[%s] New registration: %s', get_bloginfo('name'), $form->name);
        $body    = '<p>A new registration came in on <strong>' . esc_html($form->name) . '</strong>.</p>'
                 . '<p><strong>Name:</strong> ' . esc_html($name ?: '—') . '<br>'
                 . '<strong>Email:</strong> ' . esc_html($email ?: '—') . '<br>'
                 . '<strong>Status:</strong> ' . esc_html($status) . '</p>'
                 . '<p><a href="' . esc_url($link) . '">Review it in the dashboard</a></p>';

        SNN_T_Mailer::enqueue([
            'to_email'      => $to,
            'subject'       => $subject,
            'body'          => $body,
            'role'          => 'admin_notice',
            'submission_id' => $submission_id,
        ]);
    }

    private static function bounce($redirect, $form_id, $result) {
        wp_safe_redirect(add_query_arg([
            'snn_form'   => (int)$form_id,
            'snn_result' => $result,
        ], $redirect) . '#snn-form-' . (int)$form_id);
        exit;
    }

    private static function bounce_with_errors($redirect, $form_id, $errors, $values) {
        $token = wp_generate_password(12, false, false);
        set_transient('snn_t_err_' . $token, [
            'errors' => $errors,
            'values' => $values,
        ], 5 * MINUTE_IN_SECONDS);

        wp_safe_redirect(add_query_arg([
            'snn_form' => (int)$form_id,
            'snn_err'  => $token,
        ], $redirect) . '#snn-form-' . (int)$form_id);
        exit;
    }
}
