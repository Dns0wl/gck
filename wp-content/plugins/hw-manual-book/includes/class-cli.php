<?php
declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class HWMB_CLI
{
    private HWMB_Renderer $renderer;
    private HWMB_Logger $logger;

    public function __construct(HWMB_Renderer $renderer, HWMB_Logger $logger)
    {
        $this->renderer = $renderer;
        $this->logger   = $logger;
    }

    public function init(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
            \WP_CLI::add_command('hw-manual', new HWMB_CLI_Command($this->renderer, $this->logger));
        }
    }
}

if (defined('WP_CLI') && WP_CLI && ! class_exists('HWMB_CLI_Command')) {
    class HWMB_CLI_Command extends WP_CLI_Command
    {
        private HWMB_Renderer $renderer;
        private HWMB_Logger $logger;

        public function __construct(HWMB_Renderer $renderer, HWMB_Logger $logger)
        {
            $this->renderer = $renderer;
            $this->logger   = $logger;
        }

        /**
         * Build a manual for a serialnumber post.
         *
         * ## OPTIONS
         *
         * --post_id=<id>
         */
        public function build(array $args, array $assoc_args): void
        {
            $post_id = isset($assoc_args['post_id']) ? (int) $assoc_args['post_id'] : 0;
            if (! $post_id) {
                WP_CLI::error('post_id is required');
            }
            $this->renderer->build_pdf($post_id);
            WP_CLI::success('PDF generated for #' . $post_id);
        }

        /**
         * Rebuild manual books for a range.
         *
         * ## OPTIONS
         *
         * [--from=<date>]
         * [--to=<date>]
         * [--batch=<size>]
         */
        public function rebuild(array $args, array $assoc_args): void
        {
            $from  = $assoc_args['from'] ?? null;
            $to    = $assoc_args['to'] ?? null;
            $batch = isset($assoc_args['batch']) ? (int) $assoc_args['batch'] : 50;
            $query = [
                'post_type'      => 'serialnumber',
                'post_status'    => 'publish',
                'posts_per_page' => $batch,
                'paged'          => 1,
                'date_query'     => [],
                'fields'         => 'ids',
            ];
            if ($from || $to) {
                $query['date_query'][] = [
                    'after'     => $from,
                    'before'    => $to,
                    'inclusive' => true,
                ];
            }

            $page = 1;
            do {
                $query['paged'] = $page;
                $wp_query       = new WP_Query($query);
                foreach ($wp_query->posts as $post_id) {
                    try {
                        $this->renderer->build_pdf((int) $post_id);
                        WP_CLI::log('Built #' . $post_id);
                    } catch (Throwable $e) {
                        $this->logger->log('CLI build failed for #' . $post_id . ' : ' . $e->getMessage(), 'error');
                        WP_CLI::warning($e->getMessage());
                    }
                }
                ++$page;
            } while ($wp_query->max_num_pages >= $page);

            WP_CLI::success('Rebuild complete.');
        }
    }
}
