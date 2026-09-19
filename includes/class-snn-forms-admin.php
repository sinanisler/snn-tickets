<?php
/**
 * Admin screens for forms: the list table and the visual builder.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Forms_Admin {

    public static function init() {
        add_action('admin_post_snn_save_form',      [__CLASS__, 'handle_save']);
        add_action('admin_post_snn_delete_form',    [__CLASS__, 'handle_delete']);
        add_action('admin_post_snn_duplicate_form', [__CLASS__, 'handle_duplicate']);
    }

    private static function cap() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions', 'snn-tickets'));
    }

    /* ------------------------------------------------------------------
     * Routing
     * ---------------------------------------------------------------- */

    public static function render_page() {
        self::cap();

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';

        if ($action === 'edit' || $action === 'new') {
            self::render_builder($action === 'edit' ? (int)($_GET['form'] ?? 0) : 0);
            return;
        }

        self::render_list();
    }

    /* ------------------------------------------------------------------
     * List
     * ---------------------------------------------------------------- */

    private static function render_list() {
        $forms = SNN_T_Forms::all();
        $modes = [
            'auto'        => __('Automatic', 'snn-tickets'),
            'manual'      => __('Manual review', 'snn-tickets'),
            'conditional' => __('By rules', 'snn-tickets'),
        ];
        ?>
        <div class="wrap snn-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Forms', 'snn-tickets'); ?></h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-forms&action=new')); ?>" class="page-title-action"><?php esc_html_e('Add New', 'snn-tickets'); ?></a>
            <?php SNN_T_Admin::notice(); ?>

            <p class="snn-muted" style="margin-top:12px;">
                <?php printf(esc_html__('Put a form on any page with its shortcode (click to copy). Submissions land in %s.', 'snn-tickets'),
                    '<a href="' . esc_url(admin_url('admin.php?page=snn-tickets-submissions')) . '">' . esc_html__('Submissions', 'snn-tickets') . '</a>'); ?>
            </p>

            <?php if (!$forms): ?>
                <div class="snn-card snn-empty-state">
                    <span class="dashicons dashicons-feedback"></span>
                    <h2><?php esc_html_e('No forms yet', 'snn-tickets'); ?></h2>
                    <p class="snn-muted"><?php esc_html_e('A form collects registrations and turns them into tickets — automatically, after your review, or by rules.', 'snn-tickets'); ?></p>
                    <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-forms&action=new')); ?>"><?php esc_html_e('Create your first form', 'snn-tickets'); ?></a></p>
                </div>
            <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Form', 'snn-tickets'); ?></th>
                        <th><?php esc_html_e('Event', 'snn-tickets'); ?></th>
                        <th style="width:130px;"><?php esc_html_e('Approval', 'snn-tickets'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Capacity', 'snn-tickets'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Submissions', 'snn-tickets'); ?></th>
                        <th style="width:220px;"><?php esc_html_e('Shortcode', 'snn-tickets'); ?></th>
                        <th style="width:210px;"><?php esc_html_e('Actions', 'snn-tickets'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($forms as $form):
                    $counts = SNN_T_Submissions::counts((int)$form->id);
                    $max    = (int)$form->settings['max_tickets'];
                    $taken  = SNN_T_Forms::issued_count($form);
                    $edit   = admin_url('admin.php?page=snn-tickets-forms&action=edit&form=' . (int)$form->id);
                    ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url($edit); ?>"><?php echo esc_html($form->name); ?></a></strong>
                            <?php if ($form->status === 'closed'): ?> <span class="snn-badge"><?php esc_html_e('closed', 'snn-tickets'); ?></span><?php endif; ?>
                        </td>
                        <td><?php $ln = SNN_T_Forms::list_name($form->list_id);
                            echo $ln !== '' ? '<a href="' . esc_url(admin_url('admin.php?page=snn-tickets-lists&list=' . (int)$form->list_id)) . '">' . esc_html($ln) . '</a>' : '<span class="snn-badge bad">' . esc_html__('missing', 'snn-tickets') . '</span>'; ?></td>
                        <td><?php echo esc_html($modes[$form->settings['approval_mode']] ?? ''); ?></td>
                        <td>
                            <?php if ($max): ?><div class="snn-progress"><i style="width:<?php echo (int)min(100, round(100 * $taken / $max)); ?>%;background:#2271b1"></i></div><?php endif; ?>
                            <span class="snn-muted" style="font-size:12px"><?php echo esc_html($taken . ' / ' . ($max ?: '∞')); ?></span>
                        </td>
                        <td>
                            <?php if ($counts['pending']): ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-submissions&status=pending&form_id=' . (int)$form->id)); ?>"><span class="snn-badge warn"><?php printf(esc_html__('%d pending', 'snn-tickets'), (int)$counts['pending']); ?></span></a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-submissions&status=all&form_id=' . (int)$form->id)); ?>" class="snn-muted" style="font-size:12px"><?php printf(esc_html__('%d approved', 'snn-tickets'), (int)$counts['approved']); ?></a>
                        </td>
                        <td><?php echo SNN_T_Admin::copy_code('[snn_ticket_form id="' . (int)$form->id . '"]'); ?></td>
                        <td>
                            <div class="snn-actions">
                                <a class="button button-small" href="<?php echo esc_url($edit); ?>"><?php esc_html_e('Edit', 'snn-tickets'); ?></a>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="snn_duplicate_form">
                                    <input type="hidden" name="form_id" value="<?php echo (int)$form->id; ?>">
                                    <?php wp_nonce_field('snn_duplicate_form'); ?>
                                    <button class="button button-small"><?php esc_html_e('Duplicate', 'snn-tickets'); ?></button>
                                </form>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                      onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(__('Delete this form? Submissions and tickets already created are kept.', 'snn-tickets'))); ?>);">
                                    <input type="hidden" name="action" value="snn_delete_form">
                                    <input type="hidden" name="form_id" value="<?php echo (int)$form->id; ?>">
                                    <?php wp_nonce_field('snn_delete_form'); ?>
                                    <button class="button button-small button-link-delete"><?php esc_html_e('Delete', 'snn-tickets'); ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
        SNN_T_Admin::email_tools_script_only();
    }

    /* ------------------------------------------------------------------
     * Builder
     * ---------------------------------------------------------------- */

    private static function render_builder($form_id) {
        global $wpdb;

        $form = $form_id ? SNN_T_Forms::get($form_id) : null;

        if (!$form) {
            $form = (object)[
                'id'       => 0,
                'name'     => '',
                'list_id'  => 0,
                'status'   => 'active',
                'fields'   => SNN_T_Forms::default_fields(),
                'settings' => SNN_T_Forms::default_settings(),
            ];
        }

        $lists = $wpdb->get_results("SELECT id, name FROM " . SNN_T_DB::lists() . " ORDER BY id DESC");
        $types = SNN_T_Forms::field_types();
        $ops   = SNN_T_Forms::operators();
        $tab   = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'fields';
        $tabs  = [
            'fields'   => __('Fields', 'snn-tickets'),
            'logic'    => __('Approval & capacity', 'snn-tickets'),
            'emails'   => __('Emails', 'snn-tickets'),
            'messages' => __('Messages & style', 'snn-tickets'),
        ];
        if (!isset($tabs[$tab])) $tab = 'fields';

        $def_conf   = SNN_T_Mailer::default_template('confirmation');
        $def_ticket = SNN_T_Mailer::default_template('ticket');
        ?>
        <div class="wrap snn-wrap snn-builder">
            <h1 class="wp-heading-inline"><?php echo $form->id ? esc_html__('Edit form', 'snn-tickets') : esc_html__('New form', 'snn-tickets'); ?></h1>
            <a class="page-title-action" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-forms')); ?>"><?php esc_html_e('All forms', 'snn-tickets'); ?></a>
            <?php SNN_T_Admin::notice(); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="snn-form-builder">
                <input type="hidden" name="action" value="snn_save_form">
                <input type="hidden" name="form_id" value="<?php echo (int)$form->id; ?>">
                <input type="hidden" name="tab" id="snn-tab-input" value="<?php echo esc_attr($tab); ?>">
                <?php wp_nonce_field('snn_save_form'); ?>
                <input type="hidden" name="fields_json" id="snn-fields-json">
                <input type="hidden" name="settings_json" id="snn-settings-json">

                <div class="snn-card snn-form-head">
                    <div class="snn-head-grid">
                        <p>
                            <label for="snn-form-name"><strong><?php esc_html_e('Form name', 'snn-tickets'); ?></strong></label>
                            <input type="text" id="snn-form-name" name="name" class="widefat" value="<?php echo esc_attr($form->name); ?>" placeholder="<?php esc_attr_e('Summer Meetup Registration', 'snn-tickets'); ?>" required>
                        </p>
                        <p>
                            <label for="snn-list-id"><strong><?php esc_html_e('Tickets go to event', 'snn-tickets'); ?></strong></label>
                            <select id="snn-list-id" name="list_id" class="widefat">
                                <option value="0" <?php selected((int)$form->list_id, 0); ?>><?php esc_html_e('New event named after this form', 'snn-tickets'); ?></option>
                                <?php foreach ($lists as $l): ?>
                                    <option value="<?php echo (int)$l->id; ?>" <?php selected((int)$form->list_id, (int)$l->id); ?>><?php echo esc_html($l->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Status', 'snn-tickets'); ?></strong><br>
                            <label><input type="radio" name="status" value="active" <?php checked($form->status, 'active'); ?>> <?php esc_html_e('Open', 'snn-tickets'); ?></label>
                            <label style="margin-left:10px"><input type="radio" name="status" value="closed" <?php checked($form->status, 'closed'); ?>> <?php esc_html_e('Closed', 'snn-tickets'); ?></label>
                        </p>
                        <p class="snn-head-actions">
                            <?php if ($form->id): ?>
                                <?php echo SNN_T_Admin::copy_code('[snn_ticket_form id="' . (int)$form->id . '"]'); ?>
                                <?php if ((int)$form->list_id): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-lists&list=' . (int)$form->list_id . '&edit=1')); ?>"><?php esc_html_e('Event date & venue', 'snn-tickets'); ?></a><?php endif; ?>
                            <?php endif; ?>
                            <button type="submit" class="button button-primary"><?php esc_html_e('Save form', 'snn-tickets'); ?></button>
                        </p>
                    </div>
                </div>

                <nav class="nav-tab-wrapper snn-tabs" id="snn-builder-tabs">
                    <?php foreach ($tabs as $k => $label): ?>
                        <a href="#<?php echo esc_attr($k); ?>" data-tab="<?php echo esc_attr($k); ?>" class="nav-tab <?php echo $tab === $k ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </nav>

                <!-- Fields -->
                <section class="snn-pane" data-pane="fields" <?php echo $tab === 'fields' ? '' : 'hidden'; ?>>
                    <div class="snn-grid snn-grid-2">
                        <div class="snn-card">
                            <h2><?php esc_html_e('Fields', 'snn-tickets'); ?></h2>
                            <p class="snn-muted"><?php esc_html_e('Drag to reorder, click to edit. Map one field to Name and one to Email — the email mapping is where the ticket goes.', 'snn-tickets'); ?></p>
                            <div id="snn-field-list"></div>
                            <p class="snn-actions">
                                <select id="snn-new-field-type">
                                    <?php foreach ($types as $k => $label): ?>
                                        <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="button" id="snn-add-field"><?php esc_html_e('Add field', 'snn-tickets'); ?></button>
                            </p>
                        </div>
                        <div class="snn-card">
                            <h2><?php esc_html_e('Live preview', 'snn-tickets'); ?></h2>
                            <div id="snn-preview" class="snn-preview"></div>
                        </div>
                    </div>
                </section>

                <!-- Approval & capacity -->
                <section class="snn-pane" data-pane="logic" <?php echo $tab === 'logic' ? '' : 'hidden'; ?>>
                    <div class="snn-grid snn-grid-2">
                        <div class="snn-card">
                            <h2><?php esc_html_e('When someone submits', 'snn-tickets'); ?></h2>
                            <p><label><input type="radio" name="approval_mode" value="auto"> <strong><?php esc_html_e('Issue a ticket automatically', 'snn-tickets'); ?></strong></label><br>
                                <span class="description snn-indent"><?php esc_html_e('Every submission gets a ticket straight away.', 'snn-tickets'); ?></span></p>
                            <p><label><input type="radio" name="approval_mode" value="manual"> <strong><?php esc_html_e('Hold for review', 'snn-tickets'); ?></strong></label><br>
                                <span class="description snn-indent"><?php esc_html_e('They get the confirmation email now; the ticket follows when you approve.', 'snn-tickets'); ?></span></p>
                            <p><label><input type="radio" name="approval_mode" value="conditional"> <strong><?php esc_html_e('Decide by rules', 'snn-tickets'); ?></strong></label><br>
                                <span class="description snn-indent"><?php esc_html_e('Auto-approve only when the answers match.', 'snn-tickets'); ?></span></p>

                            <div id="snn-rules-box" class="snn-subbox">
                                <p><?php esc_html_e('Approve when', 'snn-tickets'); ?>
                                    <select id="snn-rules-match"><option value="all"><?php esc_html_e('all', 'snn-tickets'); ?></option><option value="any"><?php esc_html_e('any', 'snn-tickets'); ?></option></select>
                                    <?php esc_html_e('of these match:', 'snn-tickets'); ?></p>
                                <div id="snn-rule-list"></div>
                                <p><button type="button" class="button button-small" id="snn-add-rule"><?php esc_html_e('Add rule', 'snn-tickets'); ?></button></p>
                                <p><?php esc_html_e('Otherwise:', 'snn-tickets'); ?>
                                    <select id="snn-rules-fallback"><option value="manual"><?php esc_html_e('hold for review', 'snn-tickets'); ?></option><option value="reject"><?php esc_html_e('reject', 'snn-tickets'); ?></option></select></p>
                            </div>
                        </div>
                        <div class="snn-card">
                            <h2><?php esc_html_e('Capacity', 'snn-tickets'); ?></h2>
                            <p><label for="snn-max"><?php esc_html_e('Maximum registrations', 'snn-tickets'); ?></label><br>
                                <input type="number" id="snn-max" min="0" step="1" class="small-text"> <span class="description"><?php esc_html_e('0 = unlimited. Pending registrations count as taken.', 'snn-tickets'); ?></span></p>
                            <p><label><input type="checkbox" id="snn-show-remaining"> <?php esc_html_e('Show "spots left" above the form', 'snn-tickets'); ?></label></p>
                            <p><label><input type="checkbox" id="snn-one-per-email"> <?php esc_html_e('One registration per email address', 'snn-tickets'); ?></label></p>
                            <h2 style="margin-top:24px"><?php esc_html_e('Admin notification', 'snn-tickets'); ?></h2>
                            <p><label><input type="checkbox" id="snn-notify-admin"> <?php esc_html_e('Email me on every submission', 'snn-tickets'); ?></label></p>
                            <p><label for="snn-notify-email"><?php esc_html_e('Send it to', 'snn-tickets'); ?></label><br>
                                <input type="email" id="snn-notify-email" class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"></p>
                        </div>
                    </div>
                </section>

                <!-- Emails -->
                <section class="snn-pane" data-pane="emails" <?php echo $tab === 'emails' ? '' : 'hidden'; ?>>
                    <div class="snn-card">
                        <p class="snn-muted" style="margin-top:0"><?php printf(esc_html__('Held registrations get the confirmation email; approved ones get the ticket email. Leave a box empty to use the saved template or built-in default. Colours and logo come from %s.', 'snn-tickets'),
                            '<a href="' . esc_url(admin_url('admin.php?page=snn-tickets-design')) . '">' . esc_html__('Design', 'snn-tickets') . '</a>'); ?></p>
                    </div>

                    <div class="snn-card">
                        <h2><?php esc_html_e('Ticket email', 'snn-tickets'); ?> <span class="snn-muted" style="font-weight:400">— <?php esc_html_e('sent when a registration is approved', 'snn-tickets'); ?></span></h2>
                        <p><label for="snn-ticket-subject"><?php esc_html_e('Subject', 'snn-tickets'); ?></label>
                            <input type="text" id="snn-ticket-subject" class="widefat" placeholder="<?php echo esc_attr($def_ticket['subject']); ?>"></p>
                        <?php SNN_T_Admin::editor('snnticketbody', 'snn_ticket_body_editor', $form->settings['ticket_body'], 12); ?>
                        <p class="snn-muted" style="font-size:12px"><?php esc_html_e('Tip: {ticket_card} draws the designed ticket with its QR; {wallet_buttons} adds Apple/Google Wallet, PDF and calendar buttons.', 'snn-tickets'); ?></p>
                        <p><label for="snn-tpl-ticket"><?php esc_html_e('When empty, use', 'snn-tickets'); ?></label>
                            <select id="snn-tpl-ticket">
                                <option value=""><?php esc_html_e('Built-in default', 'snn-tickets'); ?></option>
                                <?php foreach (SNN_T_Mailer::templates_for_role('ticket') as $name => $t): ?><option value="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?>
                            </select></p>
                        <?php SNN_T_Admin::email_tools(['role' => 'ticket', 'subject_el' => 'snn-ticket-subject', 'body_el' => 'snnticketbody', 'template_el' => 'snn-tpl-ticket', 'list_el' => 'snn-list-id']); ?>
                    </div>

                    <div class="snn-card">
                        <h2><?php esc_html_e('Confirmation email', 'snn-tickets'); ?> <span class="snn-muted" style="font-weight:400">— <?php esc_html_e('sent when a registration is held for review', 'snn-tickets'); ?></span></h2>
                        <p><label><input type="checkbox" id="snn-send-confirmation"> <?php esc_html_e('Send it', 'snn-tickets'); ?></label></p>
                        <p><label for="snn-conf-subject"><?php esc_html_e('Subject', 'snn-tickets'); ?></label>
                            <input type="text" id="snn-conf-subject" class="widefat" placeholder="<?php echo esc_attr($def_conf['subject']); ?>"></p>
                        <?php SNN_T_Admin::editor('snnconfbody', 'snn_conf_body_editor', $form->settings['confirmation_body'], 8); ?>
                        <p><label for="snn-tpl-confirmation"><?php esc_html_e('When empty, use', 'snn-tickets'); ?></label>
                            <select id="snn-tpl-confirmation">
                                <option value=""><?php esc_html_e('Built-in default', 'snn-tickets'); ?></option>
                                <?php foreach (SNN_T_Mailer::templates_for_role('confirmation') as $name => $t): ?><option value="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?>
                            </select></p>
                        <?php SNN_T_Admin::email_tools(['role' => 'confirmation', 'subject_el' => 'snn-conf-subject', 'body_el' => 'snnconfbody', 'template_el' => 'snn-tpl-confirmation', 'list_el' => 'snn-list-id']); ?>
                    </div>

                    <div class="snn-card">
                        <h2><?php esc_html_e('Rejection email', 'snn-tickets'); ?></h2>
                        <p><label><input type="checkbox" id="snn-send-rejection"> <?php esc_html_e('Send an email when a registration is rejected', 'snn-tickets'); ?></label></p>
                        <p><label for="snn-tpl-rejection"><?php esc_html_e('Template', 'snn-tickets'); ?></label>
                            <select id="snn-tpl-rejection">
                                <option value=""><?php esc_html_e('Built-in default', 'snn-tickets'); ?></option>
                                <?php foreach (SNN_T_Mailer::templates_for_role('rejection') as $name => $t): ?><option value="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?>
                            </select></p>
                    </div>
                </section>

                <!-- Messages & style -->
                <section class="snn-pane" data-pane="messages" <?php echo $tab === 'messages' ? '' : 'hidden'; ?>>
                    <div class="snn-grid snn-grid-2">
                        <div class="snn-card">
                            <h2><?php esc_html_e('Messages', 'snn-tickets'); ?></h2>
                            <p><label for="snn-msg-submit"><?php esc_html_e('Submit button', 'snn-tickets'); ?></label><input type="text" id="snn-msg-submit" class="widefat"></p>
                            <p><label for="snn-msg-success"><?php esc_html_e('After auto-approval', 'snn-tickets'); ?></label><input type="text" id="snn-msg-success" class="widefat"></p>
                            <p><label for="snn-msg-pending"><?php esc_html_e('After held for review', 'snn-tickets'); ?></label><input type="text" id="snn-msg-pending" class="widefat"></p>
                            <p><label for="snn-msg-full"><?php esc_html_e('When full or closed', 'snn-tickets'); ?></label><input type="text" id="snn-msg-full" class="widefat"></p>
                            <p><label for="snn-msg-duplicate"><?php esc_html_e('Duplicate email', 'snn-tickets'); ?></label><input type="text" id="snn-msg-duplicate" class="widefat"></p>
                            <p><label for="snn-msg-error"><?php esc_html_e('Generic error', 'snn-tickets'); ?></label><input type="text" id="snn-msg-error" class="widefat"></p>
                            <p><label for="snn-redirect"><?php esc_html_e('Redirect after submit (optional)', 'snn-tickets'); ?></label><input type="url" id="snn-redirect" class="widefat" placeholder="https://…"></p>
                        </div>
                        <div class="snn-card">
                            <h2><?php esc_html_e('Style', 'snn-tickets'); ?></h2>
                            <p><label for="snn-accent"><?php esc_html_e('Accent colour (button, focus, checkboxes)', 'snn-tickets'); ?></label><br>
                                <input type="color" id="snn-accent" value="#111111"> <button type="button" class="button button-small" id="snn-accent-reset"><?php esc_html_e('Default', 'snn-tickets'); ?></button></p>
                            <p class="snn-muted"><?php esc_html_e('Everything else follows your theme. The form markup has stable class names (snn-field, snn-input, snn-submit-button…) for custom CSS.', 'snn-tickets'); ?></p>
                        </div>
                    </div>
                </section>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large"><?php esc_html_e('Save form', 'snn-tickets'); ?></button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-forms')); ?>" class="button"><?php esc_html_e('Cancel', 'snn-tickets'); ?></a>
                    <span class="snn-muted" id="snn-dirty" hidden><?php esc_html_e('Unsaved changes', 'snn-tickets'); ?></span>
                </p>
            </form>
        </div>

        <style>
        .snn-head-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr) auto auto;gap:16px;align-items:end}
        .snn-head-grid p{margin:0}
        .snn-head-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        @media(max-width:1100px){.snn-head-grid{grid-template-columns:1fr 1fr}}
        @media(max-width:782px){.snn-head-grid{grid-template-columns:1fr}}
        .snn-indent{display:inline-block;margin-left:24px}
        .snn-subbox{border-left:3px solid #dcdcde;padding-left:14px;margin-top:8px}
        .snn-field-card{border:1px solid #dcdcde;border-radius:6px;margin-bottom:8px;background:#fbfbfc}
        .snn-field-card.snn-dragging{opacity:.4}
        .snn-field-card.snn-drop{border-color:#2271b1;box-shadow:0 -2px 0 #2271b1}
        .snn-field-head{display:flex;align-items:center;gap:8px;padding:9px 12px;cursor:pointer}
        .snn-field-head .snn-grip{color:#8c8f94;font-size:16px;line-height:1;cursor:grab}
        .snn-field-head .snn-fname{font-weight:600;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .snn-field-head .snn-ftype{color:#646970;font-size:12px;white-space:nowrap}
        .snn-field-head .snn-map{font-size:11px}
        .snn-field-body{display:none;padding:0 12px 12px;border-top:1px solid #ececec}
        .snn-field-card.snn-open .snn-field-body{display:block}
        .snn-field-body label{display:block;margin:10px 0 3px;font-weight:600;font-size:12px}
        .snn-field-body input[type=text],.snn-field-body select,.snn-field-body textarea{width:100%}
        .snn-field-body .snn-inline{display:flex;gap:12px;flex-wrap:wrap}
        .snn-field-body .snn-inline>div{flex:1;min-width:140px}
        .snn-rule{display:flex;gap:6px;margin-bottom:6px;align-items:center}
        .snn-rule select,.snn-rule input{flex:1;min-width:0}
        .snn-preview{border:1px dashed #c3c4c7;border-radius:6px;padding:18px;background:#fff;--acc:#111}
        .snn-preview .snn-pf{margin-bottom:14px}
        .snn-preview label{display:block;font-weight:600;margin-bottom:4px;font-size:13px}
        .snn-preview input:not([type=checkbox]):not([type=radio]),.snn-preview select,.snn-preview textarea{width:100%;padding:8px 10px;border:1px solid #c3c4c7;border-radius:6px;box-sizing:border-box;background:#fff}
        .snn-preview .snn-pc{font-weight:400;display:flex;gap:8px;align-items:center;margin:0 0 4px;font-size:13px}
        .snn-preview .snn-pc input{margin:0;accent-color:var(--acc)}
        .snn-preview .snn-req{color:#b3261e}
        .snn-preview .snn-pbtn{background:var(--acc);color:#fff;border:0;border-radius:6px;padding:10px 20px;font-weight:600}
        .snn-empty{color:#646970;font-style:italic;padding:10px 0}
        .snn-builder .wp-editor-wrap{margin-top:4px}
        </style>

        <script>
        (function(){
            var TYPES    = <?php echo wp_json_encode($types); ?>;
            var OPS      = <?php echo wp_json_encode($ops); ?>;
            var fields   = <?php echo wp_json_encode(array_values($form->fields)); ?>;
            var settings = <?php echo wp_json_encode($form->settings); ?>;
            var T = <?php echo wp_json_encode([
                'untitled'   => __('(untitled)', 'snn-tickets'),
                'required'   => __('required', 'snn-tickets'),
                'remove'     => __('Remove', 'snn-tickets'),
                'duplicate'  => __('Duplicate', 'snn-tickets'),
                'label'      => __('Label', 'snn-tickets'),
                'type'       => __('Type', 'snn-tickets'),
                'key'        => __('Key', 'snn-tickets'),
                'keyHelp'    => __('(used by rules and {field:key})', 'snn-tickets'),
                'mapsTo'     => __('Maps to', 'snn-tickets'),
                'nothing'    => __('Nothing in particular', 'snn-tickets'),
                'mapName'    => __('Ticket holder name', 'snn-tickets'),
                'mapEmail'   => __('Ticket holder email', 'snn-tickets'),
                'placeholder'=> __('Placeholder', 'snn-tickets'),
                'default'    => __('Default value', 'snn-tickets'),
                'choices'    => __('Choices (one per line)', 'snn-tickets'),
                'isRequired' => __('Required', 'snn-tickets'),
                'noFields'   => __('No fields yet. Add one below.', 'snn-tickets'),
                'addField'   => __('Add a field to see the form.', 'snn-tickets'),
                'noRules'    => __('No rules yet.', 'snn-tickets'),
                'value'      => __('value', 'snn-tickets'),
                'choose'     => __('Choose…', 'snn-tickets'),
                'addFirst'   => __('Add a field first.', 'snn-tickets'),
                'needField'  => __('Add at least one field before saving.', 'snn-tickets'),
                'noEmail'    => __('No field is mapped to the ticket holder email, so tickets cannot be emailed. Save anyway?', 'snn-tickets'),
                'opt1'       => __('Option one', 'snn-tickets'),
                'opt2'       => __('Option two', 'snn-tickets'),
                'name'       => __('name', 'snn-tickets'),
                'email'      => __('email', 'snn-tickets'),
            ]); ?>;

            var NEEDS_OPTIONS = ['select','radio','checkbox'];

            var listEl    = document.getElementById('snn-field-list');
            var previewEl = document.getElementById('snn-preview');
            var ruleListEl= document.getElementById('snn-rule-list');
            var formEl    = document.getElementById('snn-form-builder');
            var dirtyEl   = document.getElementById('snn-dirty');
            var dirty = false, submitting = false;

            function markDirty(){ if (!dirty) { dirty = true; dirtyEl.hidden = false; } }
            window.addEventListener('beforeunload', function(e){ if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; } });

            function esc(s){
                return String(s == null ? '' : s)
                    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
                    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }
            function slug(s){
                return String(s || '').toLowerCase()
                    .normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ı/g, 'i')
                    .replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'').slice(0,40);
            }
            function uniqueKey(base, skipIndex){
                var key = slug(base) || 'field', n = 2, taken;
                do {
                    taken = fields.some(function(f, i){ return i !== skipIndex && f.key === key; });
                    if (taken) { key = (slug(base) || 'field') + '_' + n; n++; }
                } while (taken);
                return key;
            }

            /* ---------- tabs ---------- */
            var tabInput = document.getElementById('snn-tab-input');
            document.getElementById('snn-builder-tabs').addEventListener('click', function(e){
                var a = e.target.closest('[data-tab]');
                if (!a) return;
                e.preventDefault();
                var tab = a.getAttribute('data-tab');
                document.querySelectorAll('#snn-builder-tabs .nav-tab').forEach(function(t){ t.classList.toggle('nav-tab-active', t === a); });
                document.querySelectorAll('.snn-pane').forEach(function(p){ p.hidden = p.getAttribute('data-pane') !== tab; });
                tabInput.value = tab;
                if (history.replaceState) { var u = new URL(location.href); u.searchParams.set('tab', tab); history.replaceState(null, '', u); }
            });

            /* ---------- field list ---------- */
            function renderFields(){
                if (!fields.length) {
                    listEl.innerHTML = '<p class="snn-empty">' + esc(T.noFields) + '</p>';
                    renderPreview(); renderRules();
                    return;
                }

                listEl.innerHTML = fields.map(function(f, i){
                    var showOpts = NEEDS_OPTIONS.indexOf(f.type) !== -1;
                    var map = f.map_to ? ' <span class="snn-badge info snn-map">→ ' + esc(f.map_to === 'name' ? T.name : T.email) + '</span>' : '';
                    return ''
                    + '<div class="snn-field-card" draggable="true" data-i="' + i + '">'
                    +   '<div class="snn-field-head" data-toggle="' + i + '">'
                    +     '<span class="snn-grip" aria-hidden="true">⋮⋮</span>'
                    +     '<span class="snn-fname">' + esc(f.label || T.untitled) + map + '</span>'
                    +     '<span class="snn-ftype">' + esc(TYPES[f.type] || f.type) + (f.required ? ' · ' + esc(T.required) : '') + '</span>'
                    +     '<button type="button" class="button button-small" data-dup="' + i + '">' + esc(T.duplicate) + '</button>'
                    +     '<button type="button" class="button button-small button-link-delete" data-del="' + i + '">' + esc(T.remove) + '</button>'
                    +   '</div>'
                    +   '<div class="snn-field-body">'
                    +     '<div class="snn-inline">'
                    +       '<div><label>' + esc(T.label) + '</label><input type="text" data-set="label" data-i="' + i + '" value="' + esc(f.label) + '"></div>'
                    +       '<div><label>' + esc(T.type) + '</label><select data-set="type" data-i="' + i + '">'
                    +         Object.keys(TYPES).map(function(t){
                                  return '<option value="' + t + '"' + (t === f.type ? ' selected' : '') + '>' + esc(TYPES[t]) + '</option>';
                              }).join('')
                    +       '</select></div>'
                    +     '</div>'
                    +     '<div class="snn-inline">'
                    +       '<div><label>' + esc(T.key) + ' <span style="font-weight:400;color:#646970;">' + esc(T.keyHelp) + '</span></label>'
                    +         '<input type="text" data-set="key" data-i="' + i + '" value="' + esc(f.key) + '"></div>'
                    +       '<div><label>' + esc(T.mapsTo) + '</label><select data-set="map_to" data-i="' + i + '">'
                    +         '<option value="">' + esc(T.nothing) + '</option>'
                    +         '<option value="name"' + (f.map_to === 'name' ? ' selected' : '') + '>' + esc(T.mapName) + '</option>'
                    +         '<option value="email"' + (f.map_to === 'email' ? ' selected' : '') + '>' + esc(T.mapEmail) + '</option>'
                    +       '</select></div>'
                    +     '</div>'
                    +     '<div class="snn-inline">'
                    +       '<div><label>' + esc(T.placeholder) + '</label><input type="text" data-set="placeholder" data-i="' + i + '" value="' + esc(f.placeholder) + '"></div>'
                    +       '<div><label>' + esc(T['default']) + '</label><input type="text" data-set="default" data-i="' + i + '" value="' + esc(f['default'] || '') + '"></div>'
                    +     '</div>'
                    +     (showOpts
                            ? '<label>' + esc(T.choices) + '</label><textarea rows="4" data-set="options" data-i="' + i + '">'
                              + esc((f.options || []).join('\n')) + '</textarea>'
                            : '')
                    +     '<label style="font-weight:400;margin-top:12px;"><input type="checkbox" data-set="required" data-i="' + i + '"'
                    +       (f.required ? ' checked' : '') + '> ' + esc(T.isRequired) + '</label>'
                    +   '</div>'
                    + '</div>';
                }).join('');

                renderPreview();
                renderRules();
            }

            listEl.addEventListener('click', function(e){
                var del = e.target.closest('[data-del]');
                if (del) {
                    e.stopPropagation();
                    fields.splice(parseInt(del.dataset.del, 10), 1);
                    markDirty(); renderFields();
                    return;
                }
                var dup = e.target.closest('[data-dup]');
                if (dup) {
                    e.stopPropagation();
                    var i = parseInt(dup.dataset.dup, 10);
                    var copy = JSON.parse(JSON.stringify(fields[i]));
                    copy.key = uniqueKey(copy.key, -1);
                    copy.map_to = '';
                    delete copy._keyTouched;
                    fields.splice(i + 1, 0, copy);
                    markDirty(); renderFields();
                    var c = listEl.querySelector('.snn-field-card[data-i="' + (i + 1) + '"]');
                    if (c) c.classList.add('snn-open');
                    return;
                }
                var head = e.target.closest('[data-toggle]');
                if (head) head.parentNode.classList.toggle('snn-open');
            });

            listEl.addEventListener('input',  function(e){ onFieldEdit(e, false); });
            listEl.addEventListener('change', function(e){ onFieldEdit(e, true); });

            function onFieldEdit(e, committed){
                var el = e.target.closest('[data-set]');
                if (!el) return;
                markDirty();

                var i = parseInt(el.dataset.i, 10);
                var prop = el.dataset.set;
                var f = fields[i];
                if (!f) return;

                if (prop === 'required') {
                    f.required = el.checked ? 1 : 0;
                } else if (prop === 'options') {
                    f.options = el.value.split('\n').map(function(s){ return s.trim(); })
                                 .filter(function(s){ return s !== ''; });
                } else if (prop === 'key') {
                    // Normalising the key mid-keystroke would fight the
                    // cursor, so only tidy it once the field is committed.
                    f._keyTouched = true;
                    f.key = el.value;
                    if (committed) {
                        f.key = uniqueKey(el.value, i);
                        el.value = f.key;
                        renderRules();
                    }
                    return;
                } else if (prop === 'map_to') {
                    // Only one field can hold each mapping.
                    if (el.value) fields.forEach(function(o, j){ if (j !== i && o.map_to === el.value) o.map_to = ''; });
                    f.map_to = el.value;
                    var open = el.closest('.snn-field-card').classList.contains('snn-open');
                    renderFields();
                    if (open) listEl.querySelector('.snn-field-card[data-i="' + i + '"]').classList.add('snn-open');
                    return;
                } else {
                    f[prop] = el.value;
                    if (prop === 'label' && !f._keyTouched && !f._saved) {
                        f.key = uniqueKey(el.value, i);
                        var keyInput = listEl.querySelector('[data-set="key"][data-i="' + i + '"]');
                        if (keyInput) keyInput.value = f.key;
                    }
                }

                // A type change restructures the card, so redraw it.
                if (prop === 'type') {
                    if (NEEDS_OPTIONS.indexOf(f.type) !== -1 && !(f.options || []).length) f.options = [T.opt1, T.opt2];
                    var wasOpen = el.closest('.snn-field-card').classList.contains('snn-open');
                    renderFields();
                    if (wasOpen) listEl.querySelector('.snn-field-card[data-i="' + i + '"]').classList.add('snn-open');
                    return;
                }

                if (prop === 'label') {
                    var card = listEl.querySelector('.snn-field-card[data-i="' + i + '"] .snn-fname');
                    if (card) card.firstChild.textContent = f.label || T.untitled;
                }

                renderPreview();
                if (committed) renderRules();
            }

            /* ---------- drag to reorder ---------- */
            var dragIndex = null;
            listEl.addEventListener('dragstart', function(e){
                var card = e.target.closest('.snn-field-card');
                if (!card || e.target.closest('input,textarea,select')) { e.preventDefault(); return; }
                dragIndex = parseInt(card.dataset.i, 10);
                card.classList.add('snn-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', String(dragIndex));
            });
            listEl.addEventListener('dragend', function(){
                listEl.querySelectorAll('.snn-dragging,.snn-drop').forEach(function(c){ c.classList.remove('snn-dragging', 'snn-drop'); });
                dragIndex = null;
            });
            listEl.addEventListener('dragover', function(e){
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                listEl.querySelectorAll('.snn-drop').forEach(function(c){ c.classList.remove('snn-drop'); });
                var card = e.target.closest('.snn-field-card');
                if (card) card.classList.add('snn-drop');
            });
            listEl.addEventListener('drop', function(e){
                e.preventDefault();
                if (dragIndex === null) return;
                var card = e.target.closest('.snn-field-card');
                var to = card ? parseInt(card.dataset.i, 10) : fields.length - 1;
                if (to === dragIndex) return;
                var moved = fields.splice(dragIndex, 1)[0];
                fields.splice(to, 0, moved);
                dragIndex = null;
                markDirty(); renderFields();
            });

            document.getElementById('snn-add-field').addEventListener('click', function(){
                var type = document.getElementById('snn-new-field-type').value;
                var label = TYPES[type];
                fields.push({
                    key: uniqueKey(label, -1), type: type, label: label, placeholder: '', required: 0,
                    options: NEEDS_OPTIONS.indexOf(type) !== -1 ? [T.opt1, T.opt2] : [],
                    map_to: '', 'default': ''
                });
                markDirty(); renderFields();
                var cards = listEl.querySelectorAll('.snn-field-card');
                if (cards.length) {
                    var last = cards[cards.length - 1];
                    last.classList.add('snn-open');
                    var lab = last.querySelector('[data-set="label"]');
                    if (lab) { lab.focus(); lab.select(); }
                }
            });

            /* ---------- preview ---------- */
            function renderPreview(){
                previewEl.style.setProperty('--acc', settings.accent_color || '#111111');
                if (!fields.length) {
                    previewEl.innerHTML = '<p class="snn-empty">' + esc(T.addField) + '</p>';
                    return;
                }
                var remaining = (Number(settings.show_remaining) && Number(settings.max_tickets) > 0)
                    ? '<p style="font-weight:600;color:var(--acc)">' + Number(settings.max_tickets) + ' …</p>' : '';

                previewEl.innerHTML = remaining + fields.map(function(f){
                    if (f.type === 'hidden') return '';
                    var req = f.required ? ' <span class="snn-req">*</span>' : '';
                    var label = esc(f.label) + req;
                    var body;

                    if (f.type === 'textarea') {
                        body = '<label>' + label + '</label><textarea rows="3" placeholder="' + esc(f.placeholder) + '">' + esc(f['default'] || '') + '</textarea>';
                    } else if (f.type === 'select') {
                        body = '<label>' + label + '</label><select><option>' + esc(f.placeholder || T.choose) + '</option>'
                             + (f.options || []).map(function(o){ return '<option>' + esc(o) + '</option>'; }).join('')
                             + '</select>';
                    } else if (f.type === 'radio' || f.type === 'checkbox') {
                        body = '<label>' + label + '</label>'
                             + (f.options || []).map(function(o){
                                   return '<label class="snn-pc"><input type="' + f.type + '" name="pv_' + esc(f.key) + '"> ' + esc(o) + '</label>';
                               }).join('');
                    } else if (f.type === 'consent') {
                        body = '<label class="snn-pc"><input type="checkbox"> <span>' + label + '</span></label>';
                    } else {
                        body = '<label>' + label + '</label><input type="' + esc(f.type) + '" placeholder="' + esc(f.placeholder) + '" value="' + esc(f['default'] || '') + '">';
                    }

                    return '<div class="snn-pf">' + body + '</div>';
                }).join('') + '<p><button type="button" class="snn-pbtn">'
                  + esc(document.getElementById('snn-msg-submit').value || 'Register') + '</button></p>';
            }

            /* ---------- rules ---------- */
            function renderRules(){
                var rules = settings.rules || [];
                if (!rules.length) {
                    ruleListEl.innerHTML = '<p class="snn-empty">' + esc(T.noRules) + '</p>';
                    return;
                }
                var fieldOpts = fields.map(function(f){
                    return '<option value="' + esc(f.key) + '">' + esc(f.label || f.key) + '</option>';
                }).join('');

                ruleListEl.innerHTML = rules.map(function(r, i){
                    return '<div class="snn-rule">'
                        + '<select data-rule="field" data-i="' + i + '">' + fieldOpts + '</select>'
                        + '<select data-rule="op" data-i="' + i + '">'
                        +   Object.keys(OPS).map(function(o){ return '<option value="' + o + '">' + esc(OPS[o]) + '</option>'; }).join('')
                        + '</select>'
                        + '<input type="text" data-rule="value" data-i="' + i + '" value="' + esc(r.value) + '" placeholder="' + esc(T.value) + '">'
                        + '<button type="button" class="button button-small" data-rule-del="' + i + '" aria-label="' + esc(T.remove) + '">×</button>'
                        + '</div>';
                }).join('');

                rules.forEach(function(r, i){
                    var fs = ruleListEl.querySelector('[data-rule="field"][data-i="' + i + '"]');
                    var os = ruleListEl.querySelector('[data-rule="op"][data-i="' + i + '"]');
                    if (fs) fs.value = r.field;
                    if (os) os.value = r.op;
                });
            }

            ruleListEl.addEventListener('click', function(e){
                var del = e.target.closest('[data-rule-del]');
                if (!del) return;
                settings.rules.splice(parseInt(del.dataset.ruleDel, 10), 1);
                markDirty(); renderRules();
            });
            ruleListEl.addEventListener('change', function(e){
                var el = e.target.closest('[data-rule]');
                if (!el) return;
                var i = parseInt(el.dataset.i, 10);
                if (settings.rules[i]) settings.rules[i][el.dataset.rule] = el.value;
                markDirty();
            });
            ruleListEl.addEventListener('input', function(e){
                var el = e.target.closest('[data-rule="value"]');
                if (!el) return;
                var i = parseInt(el.dataset.i, 10);
                if (settings.rules[i]) settings.rules[i].value = el.value;
            });
            document.getElementById('snn-add-rule').addEventListener('click', function(){
                if (!fields.length) { alert(T.addFirst); return; }
                settings.rules = settings.rules || [];
                settings.rules.push({ field: fields[0].key, op: 'equals', value: '' });
                markDirty(); renderRules();
            });

            /* ---------- settings binding ---------- */
            var BOOL = {
                'snn-one-per-email':     'one_per_email',
                'snn-send-confirmation': 'send_confirmation',
                'snn-send-rejection':    'send_rejection',
                'snn-notify-admin':      'notify_admin',
                'snn-show-remaining':    'show_remaining'
            };
            var TEXT = {
                'snn-max':              'max_tickets',
                'snn-tpl-ticket':       'template_ticket',
                'snn-tpl-confirmation': 'template_confirmation',
                'snn-tpl-rejection':    'template_rejection',
                'snn-conf-subject':     'confirmation_subject',
                'snn-ticket-subject':   'ticket_subject',
                'snn-notify-email':     'notify_email',
                'snn-msg-submit':       'submit_label',
                'snn-msg-success':      'success_message',
                'snn-msg-pending':      'pending_message',
                'snn-msg-full':         'full_message',
                'snn-msg-duplicate':    'duplicate_message',
                'snn-msg-error':        'error_message',
                'snn-redirect':         'redirect_url',
                'snn-rules-match':      'rules_match',
                'snn-rules-fallback':   'rules_fallback'
            };

            function loadSettings(){
                Object.keys(BOOL).forEach(function(id){
                    var el = document.getElementById(id);
                    if (el) el.checked = !!Number(settings[BOOL[id]]);
                });
                Object.keys(TEXT).forEach(function(id){
                    var el = document.getElementById(id);
                    if (el) el.value = settings[TEXT[id]] != null ? settings[TEXT[id]] : '';
                });
                document.getElementById('snn-accent').value = settings.accent_color || '#111111';
                var mode = settings.approval_mode || 'auto';
                var radio = formEl.querySelector('input[name=approval_mode][value="' + mode + '"]');
                if (radio) radio.checked = true;
                toggleRulesBox();
            }

            function bindSettings(){
                Object.keys(BOOL).forEach(function(id){
                    var el = document.getElementById(id);
                    if (el) el.addEventListener('change', function(){ settings[BOOL[id]] = el.checked ? 1 : 0; renderPreview(); });
                });
                Object.keys(TEXT).forEach(function(id){
                    var el = document.getElementById(id);
                    if (!el) return;
                    var handler = function(){
                        settings[TEXT[id]] = el.value;
                        if (id === 'snn-msg-submit' || id === 'snn-max') renderPreview();
                    };
                    el.addEventListener('input', handler);
                    el.addEventListener('change', handler);
                });
                var accent = document.getElementById('snn-accent');
                accent.addEventListener('input', function(){ settings.accent_color = accent.value; renderPreview(); });
                document.getElementById('snn-accent-reset').addEventListener('click', function(){ settings.accent_color = ''; accent.value = '#111111'; markDirty(); renderPreview(); });
                formEl.querySelectorAll('input[name=approval_mode]').forEach(function(r){
                    r.addEventListener('change', function(){ settings.approval_mode = r.value; toggleRulesBox(); });
                });
            }

            function toggleRulesBox(){
                document.getElementById('snn-rules-box').style.display = settings.approval_mode === 'conditional' ? '' : 'none';
            }

            // Anything touched outside the field list also counts as a change.
            formEl.addEventListener('input', markDirty);
            formEl.addEventListener('change', markDirty);
            formEl.addEventListener('click', function(e){ if (e.target.closest('.snn-tagbar [data-tag]')) markDirty(); });
            function watchEditors(){
                if (!window.tinymce) return;
                ['snnticketbody', 'snnconfbody'].forEach(function(id){
                    var ed = tinymce.get(id);
                    if (ed && !ed._snnWatched) { ed._snnWatched = true; ed.on('change keyup', markDirty); }
                });
            }
            if (window.tinymce) { tinymce.on('AddEditor', function(){ setTimeout(watchEditors, 0); }); setTimeout(watchEditors, 500); }

            /* ---------- submit ---------- */
            formEl.addEventListener('submit', function(e){
                if (!fields.length) {
                    e.preventDefault();
                    alert(T.needField);
                    return;
                }
                var hasEmail = fields.some(function(f){ return f.map_to === 'email'; });
                if (!hasEmail && !confirm(T.noEmail)) {
                    e.preventDefault();
                    return;
                }
                settings.max_tickets = parseInt(settings.max_tickets, 10) || 0;
                settings.ticket_body = window.snnEditorValue('snnticketbody');
                settings.confirmation_body = window.snnEditorValue('snnconfbody');
                document.getElementById('snn-fields-json').value = JSON.stringify(fields.map(function(f){ var c = Object.assign({}, f); delete c._keyTouched; delete c._saved; return c; }));
                document.getElementById('snn-settings-json').value = JSON.stringify(settings);
                submitting = true;
            });

            // Keys of saved fields are referenced by rules and emails, so do
            // not let relabelling silently change them.
            <?php if ($form->id): ?>fields.forEach(function(f){ f._saved = true; });<?php endif; ?>

            loadSettings();
            bindSettings();
            renderFields();
        })();
        </script>
        <?php
    }

    /* ------------------------------------------------------------------
     * Save / delete / duplicate
     * ---------------------------------------------------------------- */

    public static function handle_save() {
        self::cap();
        check_admin_referer('snn_save_form');

        $id = isset($_POST['form_id']) ? (int)$_POST['form_id'] : 0;

        $fields   = json_decode(wp_unslash($_POST['fields_json'] ?? '[]'), true);
        $settings = json_decode(wp_unslash($_POST['settings_json'] ?? '{}'), true);

        $name    = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $list_id = (int)($_POST['list_id'] ?? 0);
        $msg     = __('Form saved.', 'snn-tickets');

        // No list chosen: a form is nothing without one, so give it its own,
        // named after the form. That is the default path for a new form.
        if ($list_id <= 0 || !SNN_T_Forms::list_name($list_id)) {
            $list_name = $name !== '' ? $name : __('Untitled form', 'snn-tickets');
            $list_id   = SNN_T_Tickets::create_list($list_name);
            if ($list_id) {
                $msg = sprintf(__('Form saved. Event "%s" was created for it — add its date and venue in the event settings.', 'snn-tickets'), $list_name);
            }
        }

        $id = SNN_T_Forms::save($id, [
            'name'     => $name,
            'list_id'  => $list_id,
            'status'   => sanitize_key($_POST['status'] ?? 'active'),
            'fields'   => is_array($fields) ? $fields : [],
            'settings' => is_array($settings) ? $settings : [],
        ]);

        $tab = sanitize_key($_POST['tab'] ?? 'fields');
        wp_safe_redirect(add_query_arg([
            'page'    => 'snn-tickets-forms',
            'action'  => 'edit',
            'form'    => $id,
            'tab'     => $tab,
            'snn_msg' => rawurlencode($msg),
        ], admin_url('admin.php')));
        exit;
    }

    public static function handle_delete() {
        self::cap();
        check_admin_referer('snn_delete_form');

        $id = isset($_POST['form_id']) ? (int)$_POST['form_id'] : 0;
        if ($id) SNN_T_Forms::delete($id);

        SNN_T_Admin::back('snn-tickets-forms', __('Form deleted.', 'snn-tickets'));
    }

    public static function handle_duplicate() {
        self::cap();
        check_admin_referer('snn_duplicate_form');

        $form = SNN_T_Forms::get((int)($_POST['form_id'] ?? 0));
        if (!$form) SNN_T_Admin::back('snn-tickets-forms', __('Form not found.', 'snn-tickets'), [], true);

        // The copy feeds the same event; switch it in the builder if needed.
        $id = SNN_T_Forms::save(0, [
            'name'     => sprintf(__('%s (copy)', 'snn-tickets'), $form->name),
            'list_id'  => (int)$form->list_id,
            'status'   => 'closed',
            'fields'   => $form->fields,
            'settings' => $form->settings,
        ]);

        wp_safe_redirect(add_query_arg([
            'page'    => 'snn-tickets-forms',
            'action'  => 'edit',
            'form'    => $id,
            'snn_msg' => rawurlencode(__('Form duplicated. The copy starts closed so it cannot take registrations until you open it.', 'snn-tickets')),
        ], admin_url('admin.php')));
        exit;
    }
}
