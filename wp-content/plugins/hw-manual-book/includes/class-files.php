<?php
/**
 * File helper utilities.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class HWMB_Files
{
    private HWMB_Logger $logger;

    public function __construct(HWMB_Logger $logger)
    {
        $this->logger = $logger;
    }

    public function init(): void
    {
        add_action('template_redirect', [$this, 'handle_secure_download']);
    }

    public function get_settings(): array
    {
        $defaults = [
            'logo'          => HWMB_PLUGIN_URL . 'assets/img/logo.svg',
            'brand_slogan'  => 'Crafted for the Journey',
            'footer_phone'  => '+62 812-0000-0000',
            'qr_base'       => home_url('/'),
            'mode'          => 'public',
            'dpi'           => 180,
            'image_quality' => 80,
            'margin'        => 10,
            'embed_font'    => true,
            'template'      => 'default',
        ];

        $settings = get_option('hwmb_settings', []);
        return wp_parse_args($settings, $defaults);
    }

    public function ensure_upload_dir(): array
    {
        $upload_dir = wp_upload_dir();
        $subdir     = '/hw-manual-book/' . gmdate('Y') . '/' . gmdate('m');
        $path       = $upload_dir['basedir'] . $subdir;
        $url        = $upload_dir['baseurl'] . $subdir;
        wp_mkdir_p($path);

        return [
            'path' => $path,
            'url'  => $url,
        ];
    }

    public function build_filename(string $serial_code): string
    {
        $safe_code = preg_replace('/[^A-Za-z0-9_-]+/', '-', $serial_code);
        return sprintf('HW-Manual-%s-%s.pdf', strtoupper($safe_code), gmdate('Ymd'));
    }

    public function relativize(string $file_path): string
    {
        $upload_dir = wp_upload_dir();
        return ltrim(str_replace($upload_dir['basedir'], '', $file_path), '/');
    }

    public function absolute_from_relative(string $relative): string
    {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['basedir']) . ltrim($relative, '/');
    }

    public function public_url(string $relative): string
    {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['baseurl']) . ltrim($relative, '/');
    }

    public function save_pdf(string $binary, string $serial_code): array
    {
        $dir     = $this->ensure_upload_dir();
        $file    = trailingslashit($dir['path']) . $this->build_filename($serial_code);
        file_put_contents($file, $binary);
        return [
            'path'     => $file,
            'relative' => $this->relativize($file),
        ];
    }

    public function attach(string $file_path, int $post_id): int
    {
        $upload_dir = wp_upload_dir();
        $filetype   = wp_check_filetype(basename($file_path), null);

        $attachment = [
            'post_mime_type' => $filetype['type'] ?? 'application/pdf',
            'post_title'     => get_the_title($post_id) . ' Manual Book',
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $file_path, $post_id);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $file_path));

        return (int) $attach_id;
    }

    public function cleanup_orphans(): int
    {
        $upload_dir = wp_upload_dir();
        $base       = trailingslashit($upload_dir['basedir']) . 'hw-manual-book';
        if (! file_exists($base)) {
            return 0;
        }

        $count = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }
            if ('pdf' !== strtolower($file->getExtension())) {
                continue;
            }
            $path = $file->getPathname();
            $in_use = get_posts([
                'post_type'  => 'serialnumber',
                'meta_query' => [
                    [
                        'key'   => '_hw_manual_pdf_path',
                        'value' => $path,
                    ],
                ],
                'fields'     => 'ids',
                'numberposts'=> 1,
            ]);
            if (empty($in_use)) {
                unlink($path);
                ++$count;
            }
        }

        return $count;
    }

    public function get_signed_url(string $relative, int $expiry = 900): string
    {
        $expires = time() + $expiry;
        $token   = hash_hmac('sha256', $relative . '|' . $expires, wp_salt('auth'));
        return add_query_arg([
            'hwmb_pdf' => rawurlencode($relative),
            'exp'      => $expires,
            'sig'      => $token,
        ], home_url('/'));
    }

    public function handle_secure_download(): void
    {
        if (! isset($_GET['hwmb_pdf'])) { // phpcs:ignore
            return;
        }

        $relative_param = wp_unslash($_GET['hwmb_pdf']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $relative       = rawurldecode($relative_param);
        $relative       = sanitize_text_field($relative);
        $expires  = isset($_GET['exp']) ? (int) $_GET['exp'] : 0; // phpcs:ignore
        $sig      = isset($_GET['sig']) ? sanitize_text_field(wp_unslash($_GET['sig'])) : ''; // phpcs:ignore

        if ($expires < time()) {
            wp_die(esc_html__('Link has expired.', 'hw-manual-book'));
        }

        $expected = hash_hmac('sha256', $relative . '|' . $expires, wp_salt('auth'));
        if (! hash_equals($expected, $sig)) {
            wp_die(esc_html__('Invalid signature.', 'hw-manual-book'));
        }

        $path      = $this->absolute_from_relative($relative);
        $base_path = trailingslashit(wp_upload_dir()['basedir']);
        $real      = realpath($path) ?: $path;
        if (0 !== strpos($real, $base_path)) {
            wp_die(esc_html__('Invalid path.', 'hw-manual-book'));
        }
        if (! file_exists($path)) {
            wp_die(esc_html__('File not found.', 'hw-manual-book'));
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Disposition: inline; filename="' . basename($path) . '"');
        readfile($path);
        exit;
    }
}
