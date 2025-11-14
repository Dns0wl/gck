<?php
declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class HWMB_QR
{
    public function generate_svg(string $data): string
    {
        $endpoint = add_query_arg([
            'data'   => rawurlencode($data),
            'format' => 'svg',
            'size'   => '300x300',
        ], 'https://api.qrserver.com/v1/create-qr-code/');

        $response = wp_remote_get($endpoint, [
            'timeout' => 10,
        ]);

        if (! is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
            $body = wp_remote_retrieve_body($response);
            if (str_contains((string) $body, '<svg')) {
                return (string) $body;
            }
        }

        $escaped = esc_html($data);
        return '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160"><rect width="160" height="160" fill="#eee"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="sans-serif" font-size="12">' . $escaped . '</text></svg>';
    }
}
