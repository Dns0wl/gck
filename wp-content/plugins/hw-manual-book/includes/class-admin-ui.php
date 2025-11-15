<?php
declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class HWMB_Admin_UI
{
    private HWMB_Renderer $renderer;
    private HWMB_Data_Source $data;
    private HWMB_Files $files;
    private HWMB_Logger $logger;

    public function __construct(HWMB_Renderer $renderer, HWMB_Data_Source $data, HWMB_Files $files, HWMB_Logger $logger)
    {
        $this->renderer = $renderer;
        $this->data     = $data;
        $this->files    = $files;
        $this->logger   = $logger;
    }

    public function init(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'maybe_handle_admin_actions']);
        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('save_post_serialnumber', [$this, 'save_metabox'], 20, 2);
        add_filter('bulk_actions-edit-serialnumber', [$this, 'register_bulk_action']);
        add_filter('handle_bulk_actions-edit-serialnumber', [$this, 'handle_bulk_action'], 10, 3);
        add_action('admin_post_hwmb_generate', [$this, 'handle_generate']);
        add_action('admin_post_hwmb_template', [$this, 'handle_template_save']);
        add_action('admin_post_hwmb_delete_template', [$this, 'handle_template_delete']);
        add_action('admin_post_hwmb_tools', [$this, 'handle_tools']);
        add_action('admin_post_hwmb_save_mappings', [$this, 'handle_mapping_save']);
        register_setting('hwmb_settings', 'hwmb_settings');
    }

    public function register_menu(): void
    {
        $hook = add_menu_page(
            __('Manual Book', 'hw-manual-book'),
            __('Manual Book', 'hw-manual-book'),
            'manage_options',
            'hwmb',
            [$this, 'render_settings_page'],
            'dashicons-media-spreadsheet',
            58
        );

        add_submenu_page('hwmb', __('Templates', 'hw-manual-book'), __('Templates', 'hw-manual-book'), 'manage_options', 'hwmb-templates', [$this, 'render_templates_page']);
        add_submenu_page('hwmb', __('Tools', 'hw-manual-book'), __('Tools', 'hw-manual-book'), 'manage_options', 'hwmb-tools', [$this, 'render_tools_page']);
        add_submenu_page('hwmb', __('Logs', 'hw-manual-book'), __('Logs', 'hw-manual-book'), 'manage_options', 'hwmb-logs', [$this, 'render_logs_page']);

    }

    public function register_metabox(): void
    {
        add_meta_box('hwmb-metabox', __('Manual Book PDF', 'hw-manual-book'), [$this, 'render_metabox'], 'serialnumber', 'side', 'high');
    }

    public function render_metabox(WP_Post $post): void
    {
        wp_nonce_field('hwmb_metabox', 'hwmb_metabox_nonce');
        $path   = get_post_meta($post->ID, '_hw_manual_pdf_path', true);
        $rel    = get_post_meta($post->ID, '_hw_manual_pdf_relative', true);
        $hash   = get_post_meta($post->ID, '_hw_manual_pdf_hash', true);
        $date   = get_post_meta($post->ID, '_hw_manual_generated_at', true);
        $locked = (bool) get_post_meta($post->ID, '_hw_manual_lock', true);
        $settings = $this->files->get_settings();
        $url = '';
        if ($rel) {
            $url = 'protected' === $settings['mode'] ? $this->files->get_signed_url($rel, DAY_IN_SECONDS) : $this->files->public_url($rel);
        }
        ?>
        <p>
            <strong><?php esc_html_e('Status', 'hw-manual-book'); ?>:</strong><br/>
            <?php if ($date) : ?>
                <?php printf(esc_html__('Generated on %s', 'hw-manual-book'), esc_html($date)); ?><br/>
                <?php printf(esc_html__('Hash %s', 'hw-manual-book'), esc_html(substr((string) $hash, 0, 10))); ?>
            <?php else : ?>
                <?php esc_html_e('No PDF generated yet.', 'hw-manual-book'); ?>
            <?php endif; ?>
        </p>
        <?php if ($url) : ?>
            <p>
                <a class="button button-small" href="<?php echo esc_url($url); ?>" target="_blank"><?php esc_html_e('View PDF', 'hw-manual-book'); ?></a>
            </p>
        <?php endif; ?>
        <p>
            <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hwmb_generate&post_id=' . $post->ID), 'hwmb_generate_' . $post->ID)); ?>"><?php esc_html_e('Generate / Regenerate', 'hw-manual-book'); ?></a>
        </p>
        <p>
            <label>
                <input type="checkbox" name="hwmb_lock" value="1" <?php checked($locked); ?> />
                <?php esc_html_e('Lock current PDF', 'hw-manual-book'); ?>
            </label>
        </p>
        <?php
    }

    public function save_metabox(int $post_id, WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (! current_user_can('edit_post', $post_id)) {
            return;
        }
        if (! isset($_POST['hwmb_metabox_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hwmb_metabox_nonce'])), 'hwmb_metabox')) { // phpcs:ignore
            return;
        }

        if (isset($_POST['hwmb_lock'])) { // phpcs:ignore
            update_post_meta($post_id, '_hw_manual_lock', 1);
        } else {
            delete_post_meta($post_id, '_hw_manual_lock');
        }
    }

    public function register_bulk_action(array $actions): array
    {
        $actions['hwmb_generate'] = __('Generate Manual Book PDF', 'hw-manual-book');
        return $actions;
    }

    public function handle_bulk_action(string $redirect_url, string $action, array $ids): string
    {
        if ('hwmb_generate' !== $action) {
            return $redirect_url;
        }

        foreach ($ids as $id) {
            hwmb()->scheduler->queue_post((int) $id, 5);
        }

        return add_query_arg('hwmb_bulk', count($ids), $redirect_url);
    }

    public function handle_generate(): void
    {
        $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0; // phpcs:ignore
        check_admin_referer('hwmb_generate_' . $post_id);
        if (! current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('Not allowed.', 'hw-manual-book'));
        }

        try {
            $this->renderer->build_pdf($post_id);
            $this->logger->log('Manual build triggered for #' . $post_id);
            wp_safe_redirect(get_edit_post_link($post_id, 'url'));
        } catch (Throwable $e) {
            $this->logger->log('Manual build failed: ' . $e->getMessage(), 'error');
            wp_die(esc_html($e->getMessage()));
        }
        exit;
    }

    public function render_settings_page(): void
    {
        $settings  = $this->files->get_settings();
        $mappings  = $this->data->get_mappings();
        $templates = $this->data->get_templates();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Manual Book Settings', 'hw-manual-book'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('hwmb_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Logo URL', 'hw-manual-book'); ?></th>
                        <td><input type="text" name="hwmb_settings[logo]" value="<?php echo esc_attr($settings['logo']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Brand slogan', 'hw-manual-book'); ?></th>
                        <td><input type="text" name="hwmb_settings[brand_slogan]" value="<?php echo esc_attr($settings['brand_slogan']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Footer phone', 'hw-manual-book'); ?></th>
                        <td><input type="text" name="hwmb_settings[footer_phone]" value="<?php echo esc_attr($settings['footer_phone']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('QR base URL', 'hw-manual-book'); ?></th>
                        <td><input type="text" name="hwmb_settings[qr_base]" value="<?php echo esc_attr($settings['qr_base']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Default template', 'hw-manual-book'); ?></th>
                        <td>
                            <select name="hwmb_settings[template]">
                                <?php foreach ($templates as $tpl) : ?>
                                    <option value="<?php echo esc_attr($tpl['id']); ?>" <?php selected($settings['template'] ?? '', $tpl['id']); ?>><?php echo esc_html($tpl['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Mode', 'hw-manual-book'); ?></th>
                        <td>
                            <select name="hwmb_settings[mode]">
                                <option value="public" <?php selected('public', $settings['mode']); ?>><?php esc_html_e('Public (direct URL)', 'hw-manual-book'); ?></option>
                                <option value="protected" <?php selected('protected', $settings['mode']); ?>><?php esc_html_e('Protected (signed URL)', 'hw-manual-book'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('DPI', 'hw-manual-book'); ?></th>
                        <td><input type="number" name="hwmb_settings[dpi]" value="<?php echo esc_attr((string) $settings['dpi']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Image quality', 'hw-manual-book'); ?></th>
                        <td><input type="number" name="hwmb_settings[image_quality]" value="<?php echo esc_attr((string) $settings['image_quality']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Margin (mm)', 'hw-manual-book'); ?></th>
                        <td><input type="number" name="hwmb_settings[margin]" value="<?php echo esc_attr((string) $settings['margin']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Embed Inter font', 'hw-manual-book'); ?></th>
                        <td><label><input type="checkbox" name="hwmb_settings[embed_font]" value="1" <?php checked(! empty($settings['embed_font'])); ?> /> <?php esc_html_e('Include Inter font into PDF', 'hw-manual-book'); ?></label></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr />
            <h2><?php esc_html_e('Field Mapping', 'hw-manual-book'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('hwmb_mapping'); ?>
                <input type="hidden" name="action" value="hwmb_save_mappings" />
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Token', 'hw-manual-book'); ?></th>
                            <th><?php esc_html_e('Meta key', 'hw-manual-book'); ?></th>
                            <th><?php esc_html_e('Fallback', 'hw-manual-book'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->data->get_tokens() as $token) :
                            $meta = $mappings[$token]['meta'] ?? '';
                            $fallback = $mappings[$token]['fallback'] ?? '';
                            ?>
                            <tr>
                                <td><code><?php echo esc_html($token); ?></code></td>
                                <td><input type="text" name="mappings[<?php echo esc_attr($token); ?>][meta]" value="<?php echo esc_attr($meta); ?>" /></td>
                                <td><input type="text" name="mappings[<?php echo esc_attr($token); ?>][fallback]" value="<?php echo esc_attr($fallback); ?>" /></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button(__('Save Mapping', 'hw-manual-book')); ?>
            </form>
        </div>
        <?php
    }

    public function handle_mapping_save(): void
    {
        check_admin_referer('hwmb_mapping');
        $data = isset($_POST['mappings']) ? (array) $_POST['mappings'] : []; // phpcs:ignore
        $clean = [];
        foreach ($data as $token => $row) {
            $clean[$token] = [
                'meta'     => sanitize_key($row['meta'] ?? ''),
                'fallback' => sanitize_text_field($row['fallback'] ?? ''),
            ];
        }
        update_option('hwmb_mappings', $clean);
        wp_safe_redirect(add_query_arg('updated', 'true', wp_get_referer()));
        exit;
    }

    public function render_templates_page(): void
    {
        $templates = $this->data->get_templates();
        $editing   = isset($_GET['template']) ? sanitize_text_field(wp_unslash($_GET['template'])) : ''; // phpcs:ignore
        $current   = $editing && isset($templates[$editing]) ? $templates[$editing] : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Manual Book Templates', 'hw-manual-book'); ?></h1>
            <div class="hwmb-templates">
                <div class="hwmb-template-list">
                    <h2><?php esc_html_e('Available Templates', 'hw-manual-book'); ?></h2>
                    <ul>
                        <?php foreach ($templates as $template) : ?>
                            <li>
                                <strong><?php echo esc_html($template['name']); ?></strong><br />
                                <span><?php echo esc_html($template['description']); ?></span><br />
                                <a href="<?php echo esc_url(add_query_arg('template', $template['id'])); ?>" class="button button-small"><?php esc_html_e('Edit', 'hw-manual-book'); ?></a>
                                <?php if ('default' !== $template['id']) : ?>
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hwmb_delete_template&template=' . $template['id']), 'hwmb_delete_' . $template['id'])); ?>" class="button button-link-delete"><?php esc_html_e('Delete', 'hw-manual-book'); ?></a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="hwmb-template-editor">
                    <h2><?php echo $current ? esc_html__('Edit Template', 'hw-manual-book') : esc_html__('Add Template', 'hw-manual-book'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('hwmb_template'); ?>
                        <input type="hidden" name="action" value="hwmb_template" />
                        <input type="hidden" name="template[id]" value="<?php echo esc_attr($current['id'] ?? uniqid('tpl_', true)); ?>" />
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e('Name', 'hw-manual-book'); ?></th>
                                <td><input type="text" name="template[name]" value="<?php echo esc_attr($current['name'] ?? ''); ?>" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Description', 'hw-manual-book'); ?></th>
                                <td><input type="text" name="template[description]" value="<?php echo esc_attr($current['description'] ?? ''); ?>" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('HTML Layout', 'hw-manual-book'); ?></th>
                                <td>
                                    <textarea name="template[html]" rows="12" class="large-text code" required><?php echo esc_textarea($current['html'] ?? ''); ?></textarea>
                                    <p class="description"><?php esc_html_e('Use the tokens listed below.', 'hw-manual-book'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('CSS', 'hw-manual-book'); ?></th>
                                <td><textarea name="template[css]" rows="10" class="large-text code"><?php echo esc_textarea($current['css'] ?? ''); ?></textarea></td>
                            </tr>
                        </table>
                        <p><?php esc_html_e('Tokens:', 'hw-manual-book'); ?>
                            <?php foreach ($this->data->get_tokens() as $token) : ?>
                                <button type="button" class="button hwmb-token" data-token="<?php echo esc_attr($token); ?>"><?php echo esc_html($token); ?></button>
                            <?php endforeach; ?>
                        </p>
                        <?php submit_button(__('Save Template', 'hw-manual-book')); ?>
                    </form>
                </div>
            </div>
        </div>
        <script>
            document.querySelectorAll('.hwmb-token').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var textarea = document.querySelector('textarea[name="template[html]"]');
                    if(!textarea){return;}
                    var start = textarea.selectionStart;
                    var end = textarea.selectionEnd;
                    var value = textarea.value;
                    textarea.value = value.substring(0,start)+this.dataset.token+value.substring(end);
                });
            });
        </script>
        <?php
    }

    public function handle_template_save(): void
    {
        check_admin_referer('hwmb_template');
        $template = isset($_POST['template']) ? (array) $_POST['template'] : []; // phpcs:ignore
        $template = [
            'id'          => sanitize_key($template['id'] ?? uniqid('tpl_', true)),
            'name'        => sanitize_text_field($template['name'] ?? ''),
            'description' => sanitize_text_field($template['description'] ?? ''),
            'html'        => wp_kses_post($template['html'] ?? ''),
            'css'         => wp_strip_all_tags($template['css'] ?? ''),
        ];
        $this->data->save_template($template);
        wp_safe_redirect(add_query_arg(['page' => 'hwmb-templates', 'template' => $template['id'], 'updated' => 'true'], admin_url('admin.php')));
        exit;
    }

    public function handle_template_delete(): void
    {
        $template = isset($_GET['template']) ? sanitize_key(wp_unslash($_GET['template'])) : ''; // phpcs:ignore
        check_admin_referer('hwmb_delete_' . $template);
        if ($template) {
            $this->data->delete_template($template);
        }
        wp_safe_redirect(add_query_arg(['page' => 'hwmb-templates'], admin_url('admin.php')));
        exit;
    }

    public function render_tools_page(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Manual Book Tools', 'hw-manual-book'); ?></h1>
            <div class="card">
                <h2><?php esc_html_e('Bulk Generate', 'hw-manual-book'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('hwmb_tools'); ?>
                    <input type="hidden" name="action" value="hwmb_tools" />
                    <input type="hidden" name="tool" value="bulk" />
                    <p><label><?php esc_html_e('From date', 'hw-manual-book'); ?> <input type="date" name="from" /></label></p>
                    <p><label><?php esc_html_e('To date', 'hw-manual-book'); ?> <input type="date" name="to" /></label></p>
                    <p>
                        <label><?php esc_html_e('Status', 'hw-manual-book'); ?>
                            <select name="status">
                                <option value="publish"><?php esc_html_e('Published', 'hw-manual-book'); ?></option>
                                <option value="draft"><?php esc_html_e('Draft', 'hw-manual-book'); ?></option>
                                <option value="any"><?php esc_html_e('Any', 'hw-manual-book'); ?></option>
                            </select>
                        </label>
                    </p>
                    <?php submit_button(__('Queue generation', 'hw-manual-book')); ?>
                </form>
            </div>
            <div class="card">
                <h2><?php esc_html_e('Rebuild All', 'hw-manual-book'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('hwmb_tools'); ?>
                    <input type="hidden" name="action" value="hwmb_tools" />
                    <input type="hidden" name="tool" value="rebuild" />
                    <?php submit_button(__('Rebuild now', 'hw-manual-book'), 'delete'); ?>
                </form>
            </div>
            <div class="card">
                <h2><?php esc_html_e('Cleanup orphan files', 'hw-manual-book'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('hwmb_tools'); ?>
                    <input type="hidden" name="action" value="hwmb_tools" />
                    <input type="hidden" name="tool" value="cleanup" />
                    <?php submit_button(__('Run cleanup', 'hw-manual-book')); ?>
                </form>
            </div>
            <div class="card">
                <h2><?php esc_html_e('Template Export / Import', 'hw-manual-book'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1rem;">
                    <?php wp_nonce_field('hwmb_tools'); ?>
                    <input type="hidden" name="action" value="hwmb_tools" />
                    <input type="hidden" name="tool" value="export" />
                    <?php submit_button(__('Export templates JSON', 'hw-manual-book'), 'secondary'); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('hwmb_tools'); ?>
                    <input type="hidden" name="action" value="hwmb_tools" />
                    <input type="hidden" name="tool" value="import" />
                    <p><textarea name="template_json" rows="5" class="large-text code" placeholder="<?php esc_attr_e('Paste template JSON here', 'hw-manual-book'); ?>"></textarea></p>
                    <?php submit_button(__('Import templates', 'hw-manual-book')); ?>
                </form>
            </div>
        </div>
        <?php
    }

    public function handle_tools(): void
    {
        check_admin_referer('hwmb_tools');
        $tool = isset($_POST['tool']) ? sanitize_key($_POST['tool']) : ''; // phpcs:ignore
        switch ($tool) {
            case 'bulk':
                $from = isset($_POST['from']) ? sanitize_text_field(wp_unslash($_POST['from'])) : ''; // phpcs:ignore
                $to   = isset($_POST['to']) ? sanitize_text_field(wp_unslash($_POST['to'])) : ''; // phpcs:ignore
                $status = isset($_POST['status']) ? sanitize_key($_POST['status']) : 'publish'; // phpcs:ignore
                $args = [
                    'post_type'      => 'serialnumber',
                    'posts_per_page' => -1,
                    'post_status'    => 'any' === $status ? 'any' : $status,
                    'fields'         => 'ids',
                ];
                if ($from || $to) {
                    $args['date_query'] = [
                        'after'     => $from ?: null,
                        'before'    => $to ?: null,
                        'inclusive' => true,
                    ];
                }
                $posts = get_posts($args);
                foreach ($posts as $id) {
                    hwmb()->scheduler->queue_post((int) $id, 5);
                }
                break;
            case 'rebuild':
                $posts = get_posts([
                    'post_type'      => 'serialnumber',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                ]);
                foreach ($posts as $id) {
                    hwmb()->scheduler->queue_post((int) $id, 5, false);
                }
                break;
            case 'cleanup':
                $count = $this->files->cleanup_orphans();
                $this->logger->log('Cleanup removed ' . $count . ' files.');
                break;
            case 'export':
                $templates = wp_json_encode($this->data->get_templates());
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="hwmb-templates.json"');
                echo $templates; // phpcs:ignore
                exit;
            case 'import':
                $json = isset($_POST['template_json']) ? wp_unslash($_POST['template_json']) : ''; // phpcs:ignore
                $data = json_decode($json, true);
                if (is_array($data)) {
                    $prepared = [];
                    foreach ($data as $template) {
                        $id                = sanitize_key($template['id'] ?? uniqid('tpl_', true));
                        $prepared[$id] = [
                            'id'          => $id,
                            'name'        => sanitize_text_field($template['name'] ?? ''),
                            'description' => sanitize_text_field($template['description'] ?? ''),
                            'html'        => wp_kses_post($template['html'] ?? ''),
                            'css'         => wp_strip_all_tags($template['css'] ?? ''),
                        ];
                    }
                    if ($prepared) {
                        update_option('hwmb_templates', $prepared);
                    }
                }
                break;
        }
        $redirect = wp_get_referer() ?: admin_url('admin.php?page=hwmb-tools');
        wp_safe_redirect($redirect);
        exit;
    }

    public function render_logs_page(): void
    {
        $entries = $this->logger->get_entries();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Manual Book Logs', 'hw-manual-book'); ?></h1>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Time', 'hw-manual-book'); ?></th>
                        <th><?php esc_html_e('Message', 'hw-manual-book'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry) : ?>
                        <tr>
                            <td><?php echo esc_html($entry['time']); ?></td>
                            <td><?php echo esc_html($entry['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><a class="button" href="<?php echo esc_url(add_query_arg(['download' => 'log'])); ?>"><?php esc_html_e('Download log', 'hw-manual-book'); ?></a></p>
        </div>
        <?php
    }

    public function maybe_handle_admin_actions(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        if (isset($_GET['download']) && 'log' === $_GET['download']) { // phpcs:ignore
            $file = $this->logger->get_file();
            if (file_exists($file)) {
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="hw-manual.log"');
                readfile($file);
                exit;
            }
        }
    }
}
