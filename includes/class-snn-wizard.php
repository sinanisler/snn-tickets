<?php
/**
 * Add New Event: a five-step guide from nothing to a live sign-up page.
 * Every step has working defaults, so pressing Continue five times gives
 * a usable event; everything can be changed later on the event page.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Wizard {

    public static function init() {
        add_action('admin_post_snn_wizard', [__CLASS__, 'handle']);
    }

    public static function render() {
        SNN_T_Admin::cap();
        $fields   = SNN_T_Forms::default_fields();
        $settings = SNN_T_Forms::default_settings();
        $settings['one_per_email'] = 1;
        $cfg  = SNN_T_Events_Admin::builder_cfg($fields, $settings, false);
        $site = SNN_T_Design::settings()['preset'];
        $base = SNN_T_Router::pretty() ? preg_replace('#^https?://#', '', home_url(user_trailingslashit(SNN_T_Router::base() . '/'))) : '';
        $steps = [__('The event', 'snn-tickets'), __('Questions', 'snn-tickets'), __('Who gets a ticket', 'snn-tickets'), __('Ticket & email', 'snn-tickets'), __('Go live', 'snn-tickets')];
        ?>
        <div class="wrap snn-wrap">
            <p class="snn-crumb"><a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-events')); ?>"><?php esc_html_e('Events', 'snn-tickets'); ?></a> › <?php esc_html_e('Add New Event', 'snn-tickets'); ?></p>
            <h1><?php esc_html_e('Add New Event', 'snn-tickets'); ?></h1>
            <?php SNN_T_Admin::notice(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="snn-page" data-wizard data-dirty>
                <input type="hidden" name="action" value="snn_wizard">
                <input type="hidden" name="fields_json"><input type="hidden" name="settings_json">
                <?php wp_nonce_field('snn_wizard'); ?>
                <ol class="snn-wsteps"><?php foreach ($steps as $i => $s): ?><li><?php echo esc_html(($i + 1) . '. ' . $s); ?></li><?php endforeach; ?></ol>

                <div class="snn-side" data-builder data-cfg="<?php echo esc_attr(wp_json_encode($cfg)); ?>">
                    <div class="snn-card">
                        <!-- 1 -->
                        <div class="snn-col" data-wstep="1">
                            <h2><?php esc_html_e("What's the event?", 'snn-tickets'); ?></h2>
                            <label class="snn-field"><span><?php esc_html_e('Event name', 'snn-tickets'); ?></span><input type="text" id="w-name" name="name" placeholder="<?php esc_attr_e('Autumn Networking Night', 'snn-tickets'); ?>" required></label>
                            <div class="snn-grid3">
                                <label class="snn-field"><span><?php esc_html_e('Date', 'snn-tickets'); ?></span><input type="date" id="w-date" name="date"></label>
                                <label class="snn-field"><span><?php esc_html_e('Starts', 'snn-tickets'); ?></span><input type="time" id="w-start" name="start" value="19:00"></label>
                                <label class="snn-field"><span><?php esc_html_e('Ends', 'snn-tickets'); ?> <small><?php esc_html_e('(optional)', 'snn-tickets'); ?></small></span><input type="time" name="end"></label>
                            </div>
                            <label class="snn-field"><span><?php esc_html_e('Venue', 'snn-tickets'); ?></span><input type="text" id="w-venue" name="venue" placeholder="<?php esc_attr_e('Rooftop Terrace', 'snn-tickets'); ?>"></label>
                            <label class="snn-field"><span><?php esc_html_e('Address', 'snn-tickets'); ?> <small><?php esc_html_e('(optional)', 'snn-tickets'); ?></small></span><input type="text" name="address"></label>
                            <label class="snn-field"><span><?php esc_html_e('Good to know', 'snn-tickets'); ?> <small><?php esc_html_e('(optional, printed on the ticket)', 'snn-tickets'); ?></small></span><textarea name="description" rows="2" placeholder="<?php esc_attr_e('Doors open at 18:30. Bring a photo ID.', 'snn-tickets'); ?>"></textarea></label>
                            <label class="snn-switch"><input type="checkbox" id="w-limit" name="limit" value="1" checked><i></i><?php esc_html_e('Limit the number of spots', 'snn-tickets'); ?></label>
                            <label class="snn-field" id="w-cap-wrap" style="max-width:180px"><span><?php esc_html_e('Spots', 'snn-tickets'); ?></span><input type="number" min="1" value="100" data-setting="max_tickets" id="w-cap"></label>
                            <p class="snn-muted snn-small" style="margin:0"><?php echo esc_html(sprintf(__('Times use your site timezone (%s). No date yet? Leave it empty and add it later.', 'snn-tickets'), wp_timezone_string())); ?></p>
                        </div>

                        <!-- 2 -->
                        <div class="snn-col" data-wstep="2" hidden>
                            <h2><?php esc_html_e('What do you ask people?', 'snn-tickets'); ?></h2>
                            <p class="snn-muted" style="margin:0"><?php esc_html_e("Name and email are always there: that's who the ticket is for and where it goes. Click a question to change it, or add more.", 'snn-tickets'); ?></p>
                            <div class="snn-qs" data-questions></div>
                            <div class="snn-addq" data-add-question></div>
                        </div>

                        <!-- 3 -->
                        <div class="snn-col" data-wstep="3" hidden>
                            <h2><?php esc_html_e('Who gets a ticket?', 'snn-tickets'); ?></h2>
                            <?php SNN_T_Events_Admin::approval_choice('approval_mode', 'auto'); ?>
                            <div data-rules-box hidden><div data-rules></div></div>
                            <label class="snn-check"><input type="checkbox" data-setting="one_per_email" checked> <span><?php esc_html_e('One ticket per email address', 'snn-tickets'); ?> <span class="snn-muted snn-small"><?php esc_html_e("(the same person can't sign up twice)", 'snn-tickets'); ?></span></span></label>
                        </div>

                        <!-- 4 -->
                        <div class="snn-col" data-wstep="4" hidden>
                            <h2><?php esc_html_e('How should the ticket look?', 'snn-tickets'); ?></h2>
                            <?php echo SNN_T_Admin::looks_picker('design', '', true); // escaped inside ?>
                            <h3 style="margin:6px 0 0"><?php esc_html_e('Send these with the ticket email', 'snn-tickets'); ?></h3>
                            <label class="snn-check"><input type="checkbox" name="attachments[]" value="pdf" checked> <span><?php esc_html_e('PDF ticket', 'snn-tickets'); ?> <span class="snn-muted snn-small"><?php esc_html_e('(for printing)', 'snn-tickets'); ?></span></span></label>
                            <label class="snn-check"><input type="checkbox" name="attachments[]" value="ics" checked> <span><?php esc_html_e('Calendar invite', 'snn-tickets'); ?> <span class="snn-muted snn-small"><?php esc_html_e('(adds the event to their calendar; needs a date)', 'snn-tickets'); ?></span></span></label>
                            <?php if (SNN_T_Wallet::apple_ready()): ?>
                                <label class="snn-check"><input type="checkbox" name="attachments[]" value="pkpass"> <span><?php esc_html_e('Apple Wallet pass', 'snn-tickets'); ?></span></label>
                            <?php endif; ?>
                            <div class="snn-hint"><?php esc_html_e('The ticket email is already written for you. Change the wording any time on the event\'s Emails tab.', 'snn-tickets'); ?></div>
                        </div>

                        <!-- 5 -->
                        <div class="snn-col" data-wstep="5" hidden>
                            <h2><?php esc_html_e('Go live', 'snn-tickets'); ?></h2>
                            <p style="margin:0"><?php esc_html_e('Nothing to set up: the event gets its own sign-up page and door scanner as soon as you press Finish.', 'snn-tickets'); ?></p>
                            <?php if ($base !== ''): ?>
                                <div class="snn-hint" style="font-size:14px"><b><?php esc_html_e('Sign-up link:', 'snn-tickets'); ?></b> <span class="snn-mono" data-live-url data-base="<?php echo esc_attr($base); ?>"></span><br>
                                    <span class="snn-small snn-muted"><?php esc_html_e('You can change the end of the link later in Event settings.', 'snn-tickets'); ?></span></div>
                            <?php endif; ?>
                            <label class="snn-switch"><input type="checkbox" name="open" value="1" checked><i></i><?php esc_html_e('Open for sign-ups now', 'snn-tickets'); ?></label>
                            <p class="snn-muted snn-small" style="margin:0"><?php esc_html_e('Want the form inside one of your own pages? After Finish, "Share & place" gives you a shortcode.', 'snn-tickets'); ?></p>
                        </div>

                        <div class="snn-wfoot">
                            <button type="button" class="button" data-wback>← <?php esc_html_e('Back', 'snn-tickets'); ?></button>
                            <span class="snn-spacer"></span>
                            <span class="snn-muted snn-small" data-whint></span>
                            <button type="button" class="button button-primary button-large" data-wnext><?php esc_html_e('Continue', 'snn-tickets'); ?></button>
                            <button class="button button-primary button-large" data-wfinish hidden><?php esc_html_e('Finish', 'snn-tickets'); ?></button>
                        </div>
                    </div>

                    <aside class="snn-fpv"><div class="snn-fpv-h"><span><?php esc_html_e('Sign-up form', 'snn-tickets'); ?></span><span><?php esc_html_e('Live preview', 'snn-tickets'); ?></span></div><div class="snn-fpv-b" data-form-preview></div></aside>
                </div>
            </form>
        </div>
        <?php
    }

    public static function handle() {
        SNN_T_Admin::cap();
        check_admin_referer('snn_wizard');
        $in = wp_unslash($_POST);

        $fields   = json_decode($in['fields_json'] ?? '', true);
        $settings = json_decode($in['settings_json'] ?? '', true);
        if (!is_array($fields) || !$fields) $fields = SNN_T_Forms::default_fields();
        $settings = array_merge(SNN_T_Forms::default_settings(), is_array($settings) ? $settings : []);
        if (empty($in['limit'])) $settings['max_tickets'] = 0;

        $id = SNN_T_Events::create(array_merge([
            'name'        => $in['name'] ?? '',
            'venue'       => $in['venue'] ?? '',
            'address'     => $in['address'] ?? '',
            'description' => $in['description'] ?? '',
            'design'      => $in['design'] ?? '',
            'attachments' => (array)($in['attachments'] ?? []),
        ], SNN_T_Events_Admin::datetimes($in)));
        if (!$id) SNN_T_Admin::go(admin_url('admin.php?page=snn-tickets-new'), __('The event could not be created.', 'snn-tickets'), true);

        $event = SNN_T_Events::get($id);
        SNN_T_Forms::save(0, [
            'name'     => $event->name,
            'list_id'  => $id,
            'status'   => !empty($in['open']) ? 'active' : 'closed',
            'fields'   => $fields,
            'settings' => $settings,
        ]);

        SNN_T_Admin::go(SNN_T_Admin::event_admin_url($id, ['created' => 1]));
    }
}
