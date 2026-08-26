<?php
/**
 * Admin screens for forms: the list table and the visual builder.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Forms_Admin {

    public static function init() {
        add_action('admin_post_snn_save_form',   [__CLASS__, 'handle_save']);
        add_action('admin_post_snn_delete_form', [__CLASS__, 'handle_delete']);
    }

    private static function cap() {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');
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
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Forms</h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-forms&action=new')); ?>" class="page-title-action">Add New</a>

            <?php if (isset($_GET['snn_msg'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(wp_unslash($_GET['snn_msg'])); ?></p></div>
            <?php endif; ?>

            <p class="description" style="margin-top:12px;">
                Drop a form onto any page with its shortcode. Submissions land in
                <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-submissions')); ?>">Submissions</a>,
                and tickets go out from the mail queue.
            </p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>Name</th>
                        <th>Ticket list</th>
                        <th style="width:150px;">Approval</th>
                        <th style="width:130px;">Capacity</th>
                        <th style="width:150px;">Submissions</th>
                        <th>Shortcode</th>
                        <th style="width:140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$forms): ?>
                    <tr><td colspan="8">No forms yet. <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-forms&action=new')); ?>">Create the first one.</a></td></tr>
                <?php endif; ?>
                <?php foreach ($forms as $form):
                    $counts = SNN_T_Submissions::counts((int)$form->id);
                    $max    = (int)$form->settings['max_tickets'];
                    $taken  = SNN_T_Forms::issued_count($form);
                    $modes  = ['auto' => 'Automatic', 'manual' => 'Manual review', 'conditional' => 'Conditional'];
                    ?>
                    <tr>
                        <td><?php echo (int)$form->id; ?></td>
                        <td>
                            <strong><a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-forms&action=edit&form=' . (int)$form->id)); ?>"><?php echo esc_html($form->name); ?></a></strong>
                            <?php if ($form->status === 'closed'): ?>
                                <span style="color:#b3261e;font-weight:600;"> — closed</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(SNN_T_Forms::list_name($form->list_id) ?: '— missing —'); ?></td>
                        <td><?php echo esc_html($modes[$form->settings['approval_mode']] ?? ''); ?></td>
                        <td><?php echo $max ? esc_html($taken . ' / ' . $max) : esc_html($taken . ' / ∞'); ?></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-submissions&status=pending&form_id=' . (int)$form->id)); ?>">
                                <?php echo (int)$counts['pending']; ?> pending
                            </a>,
                            <?php echo (int)$counts['approved']; ?> approved
                        </td>
                        <td><code>[snn_ticket_form id="<?php echo (int)$form->id; ?>"]</code></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-forms&action=edit&form=' . (int)$form->id)); ?>">Edit</a>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;"
                                  onsubmit="return confirm('Delete this form? Submissions and tickets already created are kept.');">
                                <input type="hidden" name="action" value="snn_delete_form">
                                <input type="hidden" name="form_id" value="<?php echo (int)$form->id; ?>">
                                <?php wp_nonce_field('snn_delete_form'); ?>
                                <button class="button button-small button-link-delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
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
        ?>
        <div class="wrap snn-builder">
            <h1><?php echo $form->id ? 'Edit form' : 'New form'; ?></h1>

            <?php if (!$lists): ?>
                <div class="notice notice-warning"><p>
                    You need at least one ticket list before a form can issue tickets.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-generator')); ?>">Create one</a>.
                </p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="snn-form-builder">
                <input type="hidden" name="action" value="snn_save_form">
                <input type="hidden" name="form_id" value="<?php echo (int)$form->id; ?>">
                <?php wp_nonce_field('snn_save_form'); ?>
                <input type="hidden" name="fields_json" id="snn-fields-json">
                <input type="hidden" name="settings_json" id="snn-settings-json">

                <div class="snn-builder-grid">

                    <div class="snn-col-main">
                        <div class="snn-card">
                            <h2>Form</h2>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><label for="snn-form-name">Name</label></th>
                                    <td><input type="text" id="snn-form-name" name="name" class="regular-text"
                                               value="<?php echo esc_attr($form->name); ?>" placeholder="Summer Meetup Registration" required></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="snn-list-id">Ticket list</label></th>
                                    <td>
                                        <select id="snn-list-id" name="list_id" required>
                                            <option value="">Choose a list…</option>
                                            <?php foreach ($lists as $l): ?>
                                                <option value="<?php echo (int)$l->id; ?>" <?php selected((int)$form->list_id, (int)$l->id); ?>>
                                                    <?php echo esc_html($l->name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description">Approved registrations become tickets in this list.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Status</th>
                                    <td>
                                        <label><input type="radio" name="status" value="active" <?php checked($form->status, 'active'); ?>> Accepting submissions</label><br>
                                        <label><input type="radio" name="status" value="closed" <?php checked($form->status, 'closed'); ?>> Closed</label>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="snn-card">
                            <h2>Fields</h2>
                            <p class="description">Drag to reorder. Map one field to <strong>Name</strong> and one to <strong>Email</strong> — the email mapping is what the ticket gets sent to.</p>
                            <div id="snn-field-list"></div>
                            <p>
                                <select id="snn-new-field-type">
                                    <?php foreach ($types as $k => $label): ?>
                                        <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="button" id="snn-add-field">Add field</button>
                            </p>
                        </div>

                        <div class="snn-card">
                            <h2>Live preview</h2>
                            <div id="snn-preview" class="snn-preview"></div>
                        </div>
                    </div>

                    <div class="snn-col-side">
                        <div class="snn-card">
                            <h2>When someone submits</h2>
                            <p>
                                <label><input type="radio" name="approval_mode" value="auto"> <strong>Approve automatically</strong></label><br>
                                <span class="description snn-indent">Ticket is created and emailed straight away.</span>
                            </p>
                            <p>
                                <label><input type="radio" name="approval_mode" value="manual"> <strong>Hold for review</strong></label><br>
                                <span class="description snn-indent">Submitter gets a confirmation email; you approve manually.</span>
                            </p>
                            <p>
                                <label><input type="radio" name="approval_mode" value="conditional"> <strong>Decide by rules</strong></label><br>
                                <span class="description snn-indent">Auto-approve only when the answers match.</span>
                            </p>

                            <div id="snn-rules-box" class="snn-subbox">
                                <p>
                                    Approve when
                                    <select id="snn-rules-match">
                                        <option value="all">all</option>
                                        <option value="any">any</option>
                                    </select>
                                    of these match:
                                </p>
                                <div id="snn-rule-list"></div>
                                <p><button type="button" class="button button-small" id="snn-add-rule">Add rule</button></p>
                                <p>
                                    Otherwise:
                                    <select id="snn-rules-fallback">
                                        <option value="manual">hold for review</option>
                                        <option value="reject">reject</option>
                                    </select>
                                </p>
                            </div>
                        </div>

                        <div class="snn-card">
                            <h2>Capacity</h2>
                            <p>
                                <label for="snn-max">Maximum registrations</label><br>
                                <input type="number" id="snn-max" min="0" step="1" class="small-text">
                                <span class="description">0 = unlimited</span>
                            </p>
                            <p><label><input type="checkbox" id="snn-one-per-email"> One registration per email address</label></p>
                        </div>

                        <div class="snn-card">
                            <h2>Emails</h2>
                            <p>
                                <label for="snn-tpl-ticket">Ticket email</label><br>
                                <select id="snn-tpl-ticket" class="widefat">
                                    <option value="">Built-in default</option>
                                    <?php foreach (SNN_T_Mailer::templates_for_role('ticket') as $name => $t): ?>
                                        <option value="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                            <p><label><input type="checkbox" id="snn-send-confirmation"> Send a confirmation email when held for review</label></p>
                            <p>
                                <label for="snn-tpl-confirmation">Confirmation email</label><br>
                                <select id="snn-tpl-confirmation" class="widefat">
                                    <option value="">Built-in default</option>
                                    <?php foreach (SNN_T_Mailer::templates_for_role('confirmation') as $name => $t): ?>
                                        <option value="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                            <p><label><input type="checkbox" id="snn-send-rejection"> Send an email when rejected</label></p>
                            <p>
                                <label for="snn-tpl-rejection">Rejection email</label><br>
                                <select id="snn-tpl-rejection" class="widefat">
                                    <option value="">Built-in default</option>
                                    <?php foreach (SNN_T_Mailer::templates_for_role('rejection') as $name => $t): ?>
                                        <option value="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                            <hr>
                            <p><label><input type="checkbox" id="snn-notify-admin"> Notify an admin on every submission</label></p>
                            <p>
                                <label for="snn-notify-email">Notification address</label><br>
                                <input type="email" id="snn-notify-email" class="widefat"
                                       placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                            </p>
                        </div>

                        <div class="snn-card">
                            <h2>Messages</h2>
                            <p><label for="snn-msg-submit">Submit button</label><input type="text" id="snn-msg-submit" class="widefat"></p>
                            <p><label for="snn-msg-success">After auto-approval</label><input type="text" id="snn-msg-success" class="widefat"></p>
                            <p><label for="snn-msg-pending">After held for review</label><input type="text" id="snn-msg-pending" class="widefat"></p>
                            <p><label for="snn-msg-full">When full or closed</label><input type="text" id="snn-msg-full" class="widefat"></p>
                            <p><label for="snn-msg-duplicate">Duplicate email</label><input type="text" id="snn-msg-duplicate" class="widefat"></p>
                            <p><label for="snn-msg-error">Generic error</label><input type="text" id="snn-msg-error" class="widefat"></p>
                            <p>
                                <label for="snn-redirect">Redirect after submit (optional)</label>
                                <input type="url" id="snn-redirect" class="widefat" placeholder="https://…">
                            </p>
                        </div>

                        <?php if ($form->id): ?>
                        <div class="snn-card">
                            <h2>Shortcode</h2>
                            <p><code>[snn_ticket_form id="<?php echo (int)$form->id; ?>"]</code></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large">Save form</button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-forms')); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>

        <style>
        .snn-builder-grid{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:20px;align-items:start;margin-top:16px}
        @media (max-width:1100px){.snn-builder-grid{grid-template-columns:1fr}}
        .snn-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 20px;margin-bottom:20px}
        .snn-card h2{margin-top:0;font-size:15px;border-bottom:1px solid #f0f0f1;padding-bottom:10px}
        .snn-indent{display:inline-block;margin-left:24px}
        .snn-subbox{border-left:3px solid #dcdcde;padding-left:14px;margin-top:8px}
        .snn-field-card{border:1px solid #dcdcde;border-radius:5px;margin-bottom:10px;background:#fbfbfc}
        .snn-field-card.snn-dragging{opacity:.4}
        .snn-field-head{display:flex;align-items:center;gap:10px;padding:10px 12px;cursor:grab}
        .snn-field-head .snn-grip{color:#8c8f94;font-size:16px;line-height:1}
        .snn-field-head .snn-fname{font-weight:600;flex:1}
        .snn-field-head .snn-ftype{color:#646970;font-size:12px}
        .snn-field-body{display:none;padding:0 12px 12px;border-top:1px solid #ececec}
        .snn-field-card.snn-open .snn-field-body{display:block}
        .snn-field-body label{display:block;margin:10px 0 3px;font-weight:600;font-size:12px}
        .snn-field-body input[type=text],.snn-field-body select,.snn-field-body textarea{width:100%}
        .snn-field-body .snn-inline{display:flex;gap:12px;flex-wrap:wrap}
        .snn-field-body .snn-inline>div{flex:1;min-width:140px}
        .snn-rule{display:flex;gap:6px;margin-bottom:6px;align-items:center}
        .snn-rule select,.snn-rule input{flex:1;min-width:0}
        .snn-preview{border:1px dashed #c3c4c7;border-radius:5px;padding:16px;background:#fdfdfd}
        .snn-preview .snn-pf{margin-bottom:14px}
        .snn-preview label{display:block;font-weight:600;margin-bottom:4px;font-size:13px}
        .snn-preview input,.snn-preview select,.snn-preview textarea{width:100%;padding:8px;border:1px solid #c3c4c7;border-radius:4px;box-sizing:border-box}
        .snn-preview .snn-pc{font-weight:400;display:block}
        .snn-preview .snn-req{color:#b3261e}
        .snn-empty{color:#646970;font-style:italic;padding:10px 0}
        </style>

        <script>
        (function(){
            var TYPES    = <?php echo wp_json_encode($types); ?>;
            var OPS      = <?php echo wp_json_encode($ops); ?>;
            var fields   = <?php echo wp_json_encode(array_values($form->fields)); ?>;
            var settings = <?php echo wp_json_encode($form->settings); ?>;

            var NEEDS_OPTIONS = ['select','radio','checkbox'];

            var listEl    = document.getElementById('snn-field-list');
            var previewEl = document.getElementById('snn-preview');
            var ruleListEl= document.getElementById('snn-rule-list');
            var formEl    = document.getElementById('snn-form-builder');

            function esc(s){
                return String(s == null ? '' : s)
                    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
                    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            function slug(s){
                return String(s || '').toLowerCase()
                    .replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'').slice(0,40);
            }

            function uniqueKey(base, skipIndex){
                var key = slug(base) || 'field', n = 2, taken;
                do {
                    taken = fields.some(function(f, i){ return i !== skipIndex && f.key === key; });
                    if (taken) { key = slug(base) + '_' + n; n++; }
                } while (taken);
                return key;
            }

            /* ---------- field list ---------- */

            function renderFields(){
                if (!fields.length) {
                    listEl.innerHTML = '<p class="snn-empty">No fields yet. Add one below.</p>';
                    renderPreview(); renderRules();
                    return;
                }

                listEl.innerHTML = fields.map(function(f, i){
                    var showOpts = NEEDS_OPTIONS.indexOf(f.type) !== -1;
                    return ''
                    + '<div class="snn-field-card" draggable="true" data-i="' + i + '">'
                    +   '<div class="snn-field-head" data-toggle="' + i + '">'
                    +     '<span class="snn-grip">⋮⋮</span>'
                    +     '<span class="snn-fname">' + esc(f.label || '(untitled)') + '</span>'
                    +     '<span class="snn-ftype">' + esc(TYPES[f.type] || f.type) + (f.required ? ' · required' : '') + '</span>'
                    +     '<button type="button" class="button button-small" data-del="' + i + '">Remove</button>'
                    +   '</div>'
                    +   '<div class="snn-field-body">'
                    +     '<div class="snn-inline">'
                    +       '<div><label>Label</label><input type="text" data-set="label" data-i="' + i + '" value="' + esc(f.label) + '"></div>'
                    +       '<div><label>Type</label><select data-set="type" data-i="' + i + '">'
                    +         Object.keys(TYPES).map(function(t){
                                  return '<option value="' + t + '"' + (t === f.type ? ' selected' : '') + '>' + esc(TYPES[t]) + '</option>';
                              }).join('')
                    +       '</select></div>'
                    +     '</div>'
                    +     '<div class="snn-inline">'
                    +       '<div><label>Key <span style="font-weight:400;color:#646970;">(used by rules and {field:key})</span></label>'
                    +         '<input type="text" data-set="key" data-i="' + i + '" value="' + esc(f.key) + '"></div>'
                    +       '<div><label>Maps to</label><select data-set="map_to" data-i="' + i + '">'
                    +         '<option value="">Nothing in particular</option>'
                    +         '<option value="name"' + (f.map_to === 'name' ? ' selected' : '') + '>Ticket holder name</option>'
                    +         '<option value="email"' + (f.map_to === 'email' ? ' selected' : '') + '>Ticket holder email</option>'
                    +       '</select></div>'
                    +     '</div>'
                    +     '<label>Placeholder</label><input type="text" data-set="placeholder" data-i="' + i + '" value="' + esc(f.placeholder) + '">'
                    +     (showOpts
                            ? '<label>Choices (one per line)</label><textarea rows="4" data-set="options" data-i="' + i + '">'
                              + esc((f.options || []).join('\n')) + '</textarea>'
                            : '')
                    +     '<label style="font-weight:400;margin-top:12px;"><input type="checkbox" data-set="required" data-i="' + i + '"'
                    +       (f.required ? ' checked' : '') + '> Required</label>'
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
                    renderFields();
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
                } else {
                    f[prop] = el.value;
                    if (prop === 'label' && !f._keyTouched) {
                        f.key = uniqueKey(el.value, i);
                    }
                }

                // A type change restructures the card, so redraw it.
                if (prop === 'type') {
                    var open = el.closest('.snn-field-card').classList.contains('snn-open');
                    renderFields();
                    if (open) listEl.querySelector('[data-i="' + i + '"]').classList.add('snn-open');
                    return;
                }

                if (prop === 'label') {
                    var card = listEl.querySelector('.snn-field-card[data-i="' + i + '"] .snn-fname');
                    if (card) card.textContent = f.label || '(untitled)';
                }

                renderPreview();
                renderRules();
            }

            /* ---------- drag to reorder ---------- */

            var dragIndex = null;

            listEl.addEventListener('dragstart', function(e){
                var card = e.target.closest('.snn-field-card');
                if (!card) return;
                dragIndex = parseInt(card.dataset.i, 10);
                card.classList.add('snn-dragging');
                e.dataTransfer.effectAllowed = 'move';
                // Firefox needs data set for the drag to start.
                e.dataTransfer.setData('text/plain', String(dragIndex));
            });

            listEl.addEventListener('dragend', function(){
                listEl.querySelectorAll('.snn-dragging').forEach(function(c){ c.classList.remove('snn-dragging'); });
                dragIndex = null;
            });

            listEl.addEventListener('dragover', function(e){
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
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
                renderFields();
            });

            document.getElementById('snn-add-field').addEventListener('click', function(){
                var type = document.getElementById('snn-new-field-type').value;
                var label = TYPES[type];
                fields.push({
                    key: uniqueKey(label, -1),
                    type: type,
                    label: label,
                    placeholder: '',
                    required: 0,
                    options: NEEDS_OPTIONS.indexOf(type) !== -1 ? ['Option one', 'Option two'] : [],
                    map_to: '',
                    'default': ''
                });
                renderFields();
                var cards = listEl.querySelectorAll('.snn-field-card');
                if (cards.length) cards[cards.length - 1].classList.add('snn-open');
            });

            /* ---------- preview ---------- */

            function renderPreview(){
                if (!fields.length) {
                    previewEl.innerHTML = '<p class="snn-empty">Add a field to see the form.</p>';
                    return;
                }

                previewEl.innerHTML = fields.map(function(f){
                    if (f.type === 'hidden') return '';
                    var req = f.required ? ' <span class="snn-req">*</span>' : '';
                    var label = esc(f.label) + req;
                    var body;

                    if (f.type === 'textarea') {
                        body = '<label>' + label + '</label><textarea rows="3" placeholder="' + esc(f.placeholder) + '"></textarea>';
                    } else if (f.type === 'select') {
                        body = '<label>' + label + '</label><select><option>' + esc(f.placeholder || 'Choose…') + '</option>'
                             + (f.options || []).map(function(o){ return '<option>' + esc(o) + '</option>'; }).join('')
                             + '</select>';
                    } else if (f.type === 'radio' || f.type === 'checkbox') {
                        body = '<label>' + label + '</label>'
                             + (f.options || []).map(function(o){
                                   return '<span class="snn-pc"><input type="' + f.type + '" disabled> ' + esc(o) + '</span>';
                               }).join('');
                    } else if (f.type === 'consent') {
                        body = '<span class="snn-pc"><input type="checkbox" disabled> ' + label + '</span>';
                    } else {
                        body = '<label>' + label + '</label><input type="' + esc(f.type) + '" placeholder="' + esc(f.placeholder) + '" disabled>';
                    }

                    return '<div class="snn-pf">' + body + '</div>';
                }).join('') + '<p><button type="button" class="button button-primary" disabled>'
                  + esc(document.getElementById('snn-msg-submit').value || 'Register') + '</button></p>';
            }

            /* ---------- rules ---------- */

            function renderRules(){
                var rules = settings.rules || [];
                if (!rules.length) {
                    ruleListEl.innerHTML = '<p class="snn-empty">No rules yet.</p>';
                    return;
                }

                var fieldOpts = fields.map(function(f){
                    return '<option value="' + esc(f.key) + '">' + esc(f.label || f.key) + '</option>';
                }).join('');

                ruleListEl.innerHTML = rules.map(function(r, i){
                    return '<div class="snn-rule">'
                        + '<select data-rule="field" data-i="' + i + '">' + fieldOpts + '</select>'
                        + '<select data-rule="op" data-i="' + i + '">'
                        +   Object.keys(OPS).map(function(o){
                                return '<option value="' + o + '">' + esc(OPS[o]) + '</option>';
                            }).join('')
                        + '</select>'
                        + '<input type="text" data-rule="value" data-i="' + i + '" value="' + esc(r.value) + '" placeholder="value">'
                        + '<button type="button" class="button button-small" data-rule-del="' + i + '">×</button>'
                        + '</div>';
                }).join('');

                // Set current selections after the markup exists.
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
                renderRules();
            });

            ruleListEl.addEventListener('change', function(e){
                var el = e.target.closest('[data-rule]');
                if (!el) return;
                var i = parseInt(el.dataset.i, 10);
                if (settings.rules[i]) settings.rules[i][el.dataset.rule] = el.value;
            });

            ruleListEl.addEventListener('input', function(e){
                var el = e.target.closest('[data-rule="value"]');
                if (!el) return;
                var i = parseInt(el.dataset.i, 10);
                if (settings.rules[i]) settings.rules[i].value = el.value;
            });

            document.getElementById('snn-add-rule').addEventListener('click', function(){
                if (!fields.length) { alert('Add a field first.'); return; }
                settings.rules = settings.rules || [];
                settings.rules.push({ field: fields[0].key, op: 'equals', value: '' });
                renderRules();
            });

            /* ---------- settings binding ---------- */

            var BOOL = {
                'snn-one-per-email':     'one_per_email',
                'snn-send-confirmation': 'send_confirmation',
                'snn-send-rejection':    'send_rejection',
                'snn-notify-admin':      'notify_admin'
            };
            var TEXT = {
                'snn-max':              'max_tickets',
                'snn-tpl-ticket':       'template_ticket',
                'snn-tpl-confirmation': 'template_confirmation',
                'snn-tpl-rejection':    'template_rejection',
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
                var mode = settings.approval_mode || 'auto';
                var radio = formEl.querySelector('input[name=approval_mode][value="' + mode + '"]');
                if (radio) radio.checked = true;
                toggleRulesBox();
            }

            function bindSettings(){
                Object.keys(BOOL).forEach(function(id){
                    var el = document.getElementById(id);
                    if (el) el.addEventListener('change', function(){ settings[BOOL[id]] = el.checked ? 1 : 0; });
                });
                Object.keys(TEXT).forEach(function(id){
                    var el = document.getElementById(id);
                    if (!el) return;
                    var handler = function(){
                        settings[TEXT[id]] = el.value;
                        if (id === 'snn-msg-submit') renderPreview();
                    };
                    el.addEventListener('input', handler);
                    el.addEventListener('change', handler);
                });
                formEl.querySelectorAll('input[name=approval_mode]').forEach(function(r){
                    r.addEventListener('change', function(){
                        settings.approval_mode = r.value;
                        toggleRulesBox();
                    });
                });
            }

            function toggleRulesBox(){
                document.getElementById('snn-rules-box').style.display =
                    settings.approval_mode === 'conditional' ? '' : 'none';
            }

            /* ---------- submit ---------- */

            formEl.addEventListener('submit', function(e){
                if (!fields.length) {
                    e.preventDefault();
                    alert('Add at least one field before saving.');
                    return;
                }
                var hasEmail = fields.some(function(f){ return f.map_to === 'email'; });
                if (!hasEmail && !confirm('No field is mapped to the ticket holder email. Tickets cannot be emailed. Save anyway?')) {
                    e.preventDefault();
                    return;
                }
                settings.max_tickets = parseInt(settings.max_tickets, 10) || 0;
                document.getElementById('snn-fields-json').value = JSON.stringify(fields);
                document.getElementById('snn-settings-json').value = JSON.stringify(settings);
            });

            loadSettings();
            bindSettings();
            renderFields();
        })();
        </script>
        <?php
    }

    /* ------------------------------------------------------------------
     * Save / delete
     * ---------------------------------------------------------------- */

    public static function handle_save() {
        self::cap();
        check_admin_referer('snn_save_form');

        $id = isset($_POST['form_id']) ? (int)$_POST['form_id'] : 0;

        $fields   = json_decode(wp_unslash($_POST['fields_json'] ?? '[]'), true);
        $settings = json_decode(wp_unslash($_POST['settings_json'] ?? '{}'), true);

        $id = SNN_T_Forms::save($id, [
            'name'     => wp_unslash($_POST['name'] ?? ''),
            'list_id'  => (int)($_POST['list_id'] ?? 0),
            'status'   => sanitize_key($_POST['status'] ?? 'active'),
            'fields'   => is_array($fields) ? $fields : [],
            'settings' => is_array($settings) ? $settings : [],
        ]);

        wp_safe_redirect(add_query_arg([
            'page'    => 'snn-tickets-forms',
            'action'  => 'edit',
            'form'    => $id,
            'snn_msg' => rawurlencode('Form saved.'),
        ], admin_url('admin.php')));
        exit;
    }

    public static function handle_delete() {
        self::cap();
        check_admin_referer('snn_delete_form');

        $id = isset($_POST['form_id']) ? (int)$_POST['form_id'] : 0;
        if ($id) SNN_T_Forms::delete($id);

        wp_safe_redirect(add_query_arg([
            'page'    => 'snn-tickets-forms',
            'snn_msg' => rawurlencode('Form deleted.'),
        ], admin_url('admin.php')));
        exit;
    }
}
