<?php
declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class HWMB_Frontend
{
    private HWMB_Files $files;

    public function __construct(HWMB_Files $files)
    {
        $this->files = $files;
    }

    public function init(): void
    {
        add_action('init', [self::class, 'register_rewrite']);
        add_filter('query_vars', [$this, 'register_query_var']);
        add_action('template_redirect', [$this, 'render']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public static function register_rewrite(): void
    {
        add_rewrite_tag('%hwmb_mnb%', '([0-1])');
        add_rewrite_rule('^mnb/?$', 'index.php?hwmb_mnb=1', 'top');
    }

    public function register_query_var(array $vars): array
    {
        $vars[] = 'hwmb_mnb';
        return $vars;
    }

    public function enqueue(): void
    {
        if (! get_query_var('hwmb_mnb')) {
            return;
        }

        wp_enqueue_style('hwmb-base', HWMB_PLUGIN_URL . 'assets/css/base.css', [], HWMB_VERSION);
        wp_enqueue_script('hwmb-mnb', HWMB_PLUGIN_URL . 'assets/js/mnb.js', [], HWMB_VERSION, true);
        wp_script_add_data('hwmb-mnb', 'type', 'module');
        $can_manage = current_user_can('edit_serialnumbers') || current_user_can('edit_posts');

        wp_localize_script('hwmb-mnb', 'HWMBApp', [
            'rest'     => esc_url_raw(rest_url('hw-manual/v1')),
            'nonce'    => wp_create_nonce('wp_rest'),
            'canBuild' => $can_manage,
        ]);
    }

    public function render(): void
    {
        if (! get_query_var('hwmb_mnb')) {
            return;
        }

        status_header(200);
        nocache_headers();
        $settings = $this->files->get_settings();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <?php wp_head(); ?>
        </head>
        <body class="hwmb-dashboard">
            <div id="hwmb-app" data-logo="<?php echo esc_url($settings['logo']); ?>"></div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        exit;
    }
}
