<?php
declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class HWMB_Scheduler
{
    public const HOOK = 'hwmb_process_queue';

    private HWMB_Renderer $renderer;
    private HWMB_Logger $logger;

    public function __construct(HWMB_Renderer $renderer, HWMB_Logger $logger)
    {
        $this->renderer = $renderer;
        $this->logger   = $logger;
    }

    public function init(): void
    {
        add_filter('cron_schedules', [self::class, 'filter_schedules']);
        add_action(self::HOOK, [$this, 'process_queue']);
    }

    public static function filter_schedules(array $schedules): array
    {
        if (! isset($schedules['hwmb_minutely'])) {
            $schedules['hwmb_minutely'] = [
                'interval' => 60,
                'display'  => __('HW Manual Queue (1 min)', 'hw-manual-book'),
            ];
        }
        return $schedules;
    }

    public static function register_cron(): void
    {
        add_filter('cron_schedules', [self::class, 'filter_schedules']);
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 30, 'hwmb_minutely', self::HOOK);
        }
    }

    public static function clear_cron(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    public function queue_post(int $post_id, int $delay = 20, bool $force = false): void
    {
        $queue   = get_option('hwmb_queue', []);
        $queue[] = [
            'post_id' => $post_id,
            'force'   => $force,
        ];
        update_option('hwmb_queue', $this->deduplicate_queue($queue));

        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + $delay, self::HOOK);
        }
    }

    private function deduplicate_queue(array $queue): array
    {
        $unique = [];
        foreach ($queue as $item) {
            $unique[$item['post_id']] = $item;
        }
        return array_values($unique);
    }

    public function process_queue(): void
    {
        $lock_key = 'hwmb_queue_lock';
        if (get_transient($lock_key)) {
            return;
        }
        set_transient($lock_key, 1, 30);

        $queue = get_option('hwmb_queue', []);
        if (empty($queue)) {
            delete_transient($lock_key);
            return;
        }

        $processed = [];
        $batch     = array_splice($queue, 0, 25);
        update_option('hwmb_queue', $queue);

        foreach ($batch as $item) {
            $post_id = (int) $item['post_id'];
            $force   = (bool) ($item['force'] ?? false);
            if (get_post_meta($post_id, '_hw_manual_lock', true) && ! $force) {
                continue;
            }
            try {
                $this->renderer->build_pdf($post_id);
                $processed[] = $post_id;
            } catch (Throwable $e) {
                $this->logger->log('Queue build failed for #' . $post_id . ' : ' . $e->getMessage(), 'error');
            }
        }

        delete_transient($lock_key);

        if (! empty($queue)) {
            wp_schedule_single_event(time() + 60, self::HOOK);
        }
    }
}
