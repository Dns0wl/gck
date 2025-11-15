<?php
/**
 * Logger helper.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class HWMB_Logger
{
    private string $log_file;

    public function __construct()
    {
        $upload_dir     = wp_upload_dir();
        $base_dir       = trailingslashit($upload_dir['basedir']) . 'hw-manual-book';
        wp_mkdir_p($base_dir);
        $this->log_file = $base_dir . '/hw-manual.log';
    }

    public function log(string $message, string $level = 'info'): void
    {
        $line = sprintf('[%s][%s] %s%s', gmdate('c'), strtoupper($level), $message, PHP_EOL);
        file_put_contents($this->log_file, $line, FILE_APPEND);
    }

    public function get_entries(int $limit = 200): array
    {
        if (! file_exists($this->log_file)) {
            return [];
        }

        $lines = file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_slice(array_reverse($lines), 0, $limit);
        return array_map(static function ($line) {
            $parts = explode('] ', trim($line), 2);
            return [
                'time'    => substr($parts[0], 1) ?? '',
                'message' => $parts[1] ?? '',
            ];
        }, $lines);
    }

    public function get_file(): string
    {
        return $this->log_file;
    }
}
