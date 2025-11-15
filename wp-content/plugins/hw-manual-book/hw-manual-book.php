<?php
/**
 * Plugin Name: HW Manual Book
 * Plugin URI:  https://hayuwidyas.com/manual-book
 * Description: Generate Manual Book PDF for Hayu Widyas Handmade serial numbers with a lightweight dashboard.
 * Version:     1.0.0
 * Author:      Hayu Widyas Handmade
 * Author URI:  https://hayuwidyas.com
 * Text Domain: hw-manual-book
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('HWMB_VERSION', '1.0.0');
define('HWMB_PLUGIN_FILE', __FILE__);
define('HWMB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HWMB_PLUGIN_URL', plugin_dir_url(__FILE__));

do_action('hwmb/before_load');

require_once HWMB_PLUGIN_DIR . 'includes/class-logger.php';
require_once HWMB_PLUGIN_DIR . 'includes/class-files.php';
require_once HWMB_PLUGIN_DIR . 'includes/class-data-source.php';
require_once HWMB_PLUGIN_DIR . 'includes/class-qr.php';
require_once HWMB_PLUGIN_DIR . 'includes/class-renderer.php';
require_once HWMB_PLUGIN_DIR . 'includes/class-admin-ui.php';
require_once HWMB_PLUGIN_DIR . 'includes/class-rest.php';
require_once HWMB_PLUGIN_DIR . 'includes/class-frontend.php';
require_once HWMB_PLUGIN_DIR . 'includes/class-scheduler.php';
require_once HWMB_PLUGIN_DIR . 'includes/class-cli.php';

if (! class_exists('HWMB_Plugin')) {
    final class HWMB_Plugin
    {
        public const REWRITE_VERSION = '20240604';

        public static ?HWMB_Plugin $instance = null;
        public HWMB_Logger $logger;
        public HWMB_Files $files;
        public HWMB_Data_Source $data_source;
        public HWMB_QR $qr;
        public HWMB_Renderer $renderer;
        public HWMB_Admin_UI $admin_ui;
        public HWMB_REST $rest;
        public HWMB_Frontend $frontend;
        public HWMB_Scheduler $scheduler;
        public HWMB_CLI $cli;

        public static function instance(): HWMB_Plugin
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        private function __construct()
        {
            $this->logger      = new HWMB_Logger();
            $this->files       = new HWMB_Files($this->logger);
            $this->data_source = new HWMB_Data_Source($this->logger);
            $this->qr          = new HWMB_QR();
            $this->renderer    = new HWMB_Renderer($this->files, $this->data_source, $this->qr, $this->logger);
            $this->admin_ui    = new HWMB_Admin_UI($this->renderer, $this->data_source, $this->files, $this->logger);
            $this->rest        = new HWMB_REST($this->renderer, $this->files, $this->data_source, $this->logger);
            $this->frontend    = new HWMB_Frontend($this->files);
            $this->scheduler   = new HWMB_Scheduler($this->renderer, $this->logger);
            $this->cli         = new HWMB_CLI($this->renderer, $this->logger);

            add_action('plugins_loaded', [$this, 'boot']);
        }

        public function boot(): void
        {
            load_plugin_textdomain('hw-manual-book', false, basename(dirname(__FILE__)) . '/languages');
            $this->files->init();
            $this->admin_ui->init();
            $this->rest->init();
            $this->frontend->init();
            $this->scheduler->init();
            $this->cli->init();

            add_action('init', [$this, 'maybe_flush_rewrite'], 20);

            add_action('save_post_serialnumber', [$this, 'queue_generation'], 10, 3);
        }

        public function maybe_flush_rewrite(): void
        {
            $stored = get_option('hwmb_rewrite_version');
            if ($stored === self::REWRITE_VERSION) {
                return;
            }

            HWMB_Frontend::register_rewrite();
            flush_rewrite_rules(false);
            update_option('hwmb_rewrite_version', self::REWRITE_VERSION);
        }

        public function queue_generation(int $post_id, WP_Post $post, bool $update): void
        {
            if (wp_is_post_revision($post_id) || 'auto-draft' === $post->post_status) {
                return;
            }

            if (get_post_meta($post_id, '_hw_manual_lock', true)) {
                return;
            }

            $this->scheduler->queue_post($post_id, 20);
        }

        public static function activate(): void
        {
            hwmb();
            HWMB_Frontend::register_rewrite();
            HWMB_Scheduler::register_cron();
            flush_rewrite_rules();
            update_option('hwmb_rewrite_version', self::REWRITE_VERSION);
        }

        public static function deactivate(): void
        {
            HWMB_Scheduler::clear_cron();
            flush_rewrite_rules();
            delete_option('hwmb_rewrite_version');
        }
    }
}

function hwmb(): HWMB_Plugin
{
    return HWMB_Plugin::instance();
}

register_activation_hook(__FILE__, ['HWMB_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['HWMB_Plugin', 'deactivate']);

hwmb();
