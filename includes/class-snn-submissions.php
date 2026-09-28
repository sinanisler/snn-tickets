<?php
/**
 * Form submissions: storage and approve / decline decisions.
 *
 * Admins never see "submissions" as such: the People tab of an event shows
 * a submission that is still waiting (or was declined) as a person, and an
 * approved one as the ticket it became.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Submissions {

    public static function init() {}

    public static function create($args) {
        global $wpdb;

        $ok = $wpdb->insert(SNN_T_DB::submissions(), [
            'form_id'    => (int)$args['form_id'],
            'status'     => sanitize_key($args['status'] ?? 'pending'),
            'name'       => sanitize_text_field($args['name'] ?? ''),
            'email'      => sanitize_email($args['email'] ?? ''),
            'data'       => wp_json_encode($args['data'] ?? []),
            'ip'         => sanitize_text_field($args['ip'] ?? ''),
            'created_at' => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s']);

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    public static function get($id) {
        global $wpdb;
        $table = SNN_T_DB::submissions();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int)$id));
        if ($row) {
            $decoded = json_decode((string)$row->data, true);
            $row->data = is_array($decoded) ? $decoded : [];
        }
        return $row;
    }

    /**
     * Turn a submission into a ticket and queue the ticket email.
     *
     * @return int|WP_Error ticket id
     */
    public static function approve($id, $user_id = 0, $reason = '') {
        global $wpdb;

        $submission = self::get($id);
        if (!$submission) return new WP_Error('snn_sub_missing', __('That sign-up no longer exists.', 'snn-tickets'));

        $form = SNN_T_Forms::get($submission->form_id);
        if (!$form) return new WP_Error('snn_sub_form', __('The event for this sign-up no longer exists.', 'snn-tickets'));

        // Already approved with a ticket? Do not issue a second one.
        $ticket_id = (int)$submission->ticket_id;
        if (!$ticket_id) {
            $ticket_id = SNN_T_Tickets::insert(
                (int)$form->list_id,
                $submission->name,
                $submission->email,
                null,
                (int)$submission->id,
                'form'
            );
            if (!$ticket_id) {
                return new WP_Error('snn_sub_ticket', __('Could not create a ticket.', 'snn-tickets'));
            }
        }

        $wpdb->update(SNN_T_DB::submissions(), [
            'status'          => 'approved',
            'ticket_id'       => $ticket_id,
            'decided_at'      => current_time('mysql'),
            'decided_by'      => $user_id ?: get_current_user_id(),
            'decision_reason' => sanitize_text_field($reason),
        ], ['id' => (int)$id], ['%s', '%d', '%s', '%d', '%s'], ['%d']);

        $ticket = SNN_T_Tickets::get($ticket_id);
        if ($ticket && $ticket->email) {
            SNN_T_Mailer::queue_ticket($ticket);
        }

        return $ticket_id;
    }

    public static function reject($id, $user_id = 0, $reason = '') {
        global $wpdb;

        $submission = self::get($id);
        if (!$submission) return new WP_Error('snn_sub_missing', __('That sign-up no longer exists.', 'snn-tickets'));

        $form = SNN_T_Forms::get($submission->form_id);

        $wpdb->update(SNN_T_DB::submissions(), [
            'status'          => 'rejected',
            'decided_at'      => current_time('mysql'),
            'decided_by'      => $user_id ?: get_current_user_id(),
            'decision_reason' => sanitize_text_field($reason),
        ], ['id' => (int)$id], ['%s', '%s', '%d', '%s'], ['%d']);

        if ($form && $submission->email) {
            SNN_T_Mailer::send_event_email('rejection', (int)$form->list_id, [
                'name'          => $submission->name,
                'email'         => $submission->email,
                'form_name'     => $form->name,
                'fields'        => $submission->data,
                'submission_id' => (int)$id,
            ]);
        }

        return true;
    }

    /** Update the name and email of a sign-up that has no ticket yet. */
    public static function update_contact($id, $name, $email) {
        global $wpdb;
        $email = trim((string)$email);
        if ($email !== '' && !is_email($email)) {
            return new WP_Error('snn_s_email', __('That email address does not look right.', 'snn-tickets'));
        }
        $wpdb->update(SNN_T_DB::submissions(), ['name' => sanitize_text_field($name), 'email' => sanitize_email($email)], ['id' => (int)$id], ['%s', '%s'], ['%d']);
        return true;
    }

    public static function set_note($id, $note) {
        global $wpdb;
        $wpdb->update(SNN_T_DB::submissions(), ['decision_reason' => sanitize_text_field($note)], ['id' => (int)$id], ['%s'], ['%d']);
    }

    /**
     * Erase a submission and everything derived from it. Used for privacy
     * erasure requests as well as ordinary cleanup.
     */
    public static function delete($id, $delete_ticket = true) {
        global $wpdb;

        $submission = self::get($id);
        if (!$submission) return false;

        if ($delete_ticket && $submission->ticket_id) {
            SNN_T_Tickets::delete_ticket((int)$submission->ticket_id);
        }

        $wpdb->delete(SNN_T_DB::queue(), ['submission_id' => (int)$id], ['%d']);
        return (bool)$wpdb->delete(SNN_T_DB::submissions(), ['id' => (int)$id], ['%d']);
    }

    /** Waiting / approved / declined counts, optionally for one event. */
    public static function counts($list_id = 0) {
        global $wpdb;
        $s = SNN_T_DB::submissions();
        $f = SNN_T_DB::forms();

        $sql = "SELECT s.status, COUNT(*) AS n FROM {$s} s";
        if ($list_id) {
            $sql = $wpdb->prepare($sql . " JOIN {$f} f ON f.id = s.form_id WHERE f.list_id = %d", (int)$list_id);
        }
        $sql .= " GROUP BY s.status";

        $out = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ((array)$wpdb->get_results($sql) as $r) {
            $out[$r->status] = (int)$r->n;
        }
        return $out;
    }

    /** key => label for every question of a form, for readable answers. */
    public static function field_labels($form) {
        $out = [];
        if ($form) {
            foreach ($form->fields as $field) $out[$field['key']] = $field['label'];
        }
        return $out;
    }

    public static function answer($v) {
        if (is_array($v)) return implode(', ', $v);
        if ($v === '1') return '✓';
        return (string)$v;
    }
}
