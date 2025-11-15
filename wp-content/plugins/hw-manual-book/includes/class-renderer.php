<?php
declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class HWMB_Renderer
{
    private HWMB_Files $files;
    private HWMB_Data_Source $data_source;
    private HWMB_QR $qr;
    private HWMB_Logger $logger;

    public function __construct(HWMB_Files $files, HWMB_Data_Source $data_source, HWMB_QR $qr, HWMB_Logger $logger)
    {
        $this->files       = $files;
        $this->data_source = $data_source;
        $this->qr          = $qr;
        $this->logger      = $logger;
    }

    public function render_html(int $post_id, string $template_id = '', array $overrides = []): string
    {
        $normalized_overrides = $this->normalize_overrides($overrides);
        [$html, $css] = $this->prepare_document($post_id, $template_id, $normalized_overrides);
        if ('' === $html) {
            return '';
        }

        return '<style>' . $css . '</style>' . $html;
    }

    public function build_pdf(int $post_id, string $template_id = '', array $overrides = []): array
    {
        $normalized_overrides = $this->normalize_overrides($overrides);
        [$html, $css, $tokens] = $this->prepare_document($post_id, $template_id, $normalized_overrides, true);
        if ('' === $html) {
            throw new RuntimeException(__('Unable to build payload for PDF.', 'hw-manual-book'));
        }

        $this->boot_mpdf();
        if (! class_exists('\\Mpdf\\Mpdf')) {
            throw new RuntimeException(__('mPDF library is not available. Upload it to vendor/mpdf.', 'hw-manual-book'));
        }

        $settings = $this->files->get_settings();
        if (! empty($settings['embed_font'])) {
            $font_file = HWMB_PLUGIN_DIR . 'assets/fonts/Inter-Regular.ttf';
            if (file_exists($font_file)) {
                $css = "@font-face { font-family: 'Inter'; src: url('" . $font_file . "'); font-weight: normal; font-style: normal; }\n" . $css;
            }
        }
        $config   = [
            'format'        => 'A4',
            'margin_left'   => (float) $settings['margin'],
            'margin_right'  => (float) $settings['margin'],
            'margin_top'    => (float) $settings['margin'],
            'margin_bottom' => (float) $settings['margin'],
            'tempDir'       => WP_CONTENT_DIR . '/uploads/hw-manual-book/tmp',
            'mode'          => 'utf-8',
        ];

        wp_mkdir_p($config['tempDir']);

        $mpdf = new \Mpdf\Mpdf($config);
        $mpdf->SetDisplayMode('fullwidth');
        $mpdf->SetTitle(get_the_title($post_id) . ' Manual Book');
        if (class_exists('\\Mpdf\\HTMLParserMode')) {
            $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
            $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
        } else {
            $mpdf->WriteHTML('<style>' . $css . '</style>' . $html);
        }

        $binary = $mpdf->Output('', 'S');
        $saved  = $this->files->save_pdf($binary, $tokens['{{serial_code}}'] ?? (string) $post_id);
        $attach = $this->files->attach($saved['path'], $post_id);
        $hash   = hash('sha256', $binary);
        $time   = current_time('mysql');

        update_post_meta($post_id, '_hw_manual_pdf_id', $attach);
        update_post_meta($post_id, '_hw_manual_pdf_path', $saved['path']);
        update_post_meta($post_id, '_hw_manual_pdf_relative', $saved['relative']);
        update_post_meta($post_id, '_hw_manual_pdf_version', HWMB_VERSION);
        update_post_meta($post_id, '_hw_manual_pdf_hash', $hash);
        update_post_meta($post_id, '_hw_manual_generated_at', $time);

        $this->store_context($post_id, $normalized_overrides, $tokens);

        $this->logger->log(sprintf('PDF built for #%d saved to %s', $post_id, $saved['relative']));

        return [
            'attachment_id' => $attach,
            'path'          => $saved['path'],
            'relative'      => $saved['relative'],
            'hash'          => $hash,
            'generated_at'  => $time,
        ];
    }

    private function boot_mpdf(): void
    {
        if (class_exists('\\Mpdf\\Mpdf')) {
            return;
        }

        $autoload = HWMB_PLUGIN_DIR . 'vendor/mpdf/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

    private function prepare_document(int $post_id, string $template_id, array $overrides, bool $strict = false): array
    {
        $tokens = $this->data_source->build_payload($post_id, $overrides);
        if (! $tokens) {
            return ['', '', []];
        }

        $template = $this->data_source->get_template($template_id);
        $html     = $template['html'] ?? '';
        $css      = $template['css'] ?? '';

        if ($strict && ('' === $html || '' === $css)) {
            throw new RuntimeException(__('Template contents missing.', 'hw-manual-book'));
        }

        $search  = array_keys($tokens);
        $replace = array_values($tokens);

        $html = str_replace($search, $replace, $html);
        $css  = str_replace($search, $replace, $css);

        return [$html, $css, $tokens];
    }

    private function normalize_overrides(array $overrides): array
    {
        $normalized = [];
        foreach ($overrides as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $token = trim($key);
            if (! str_starts_with($token, '{{')) {
                $token = '{{' . trim($token, '{}') . '}}';
            }
            $normalized[$token] = $value;
        }

        return $normalized;
    }

    private function store_context(int $post_id, array $overrides, array $tokens): void
    {
        $has_customer = isset($overrides['{{customer_name}}']);
        $has_order    = isset($overrides['{{order_date}}']);

        if ($has_customer && ! empty($tokens['{{customer_name}}'])) {
            update_post_meta($post_id, '_hw_manual_customer', $tokens['{{customer_name}}']);
        }
        if ($has_order && ! empty($tokens['{{order_date}}'])) {
            update_post_meta($post_id, '_hw_manual_order_date', $tokens['{{order_date}}']);
        }
    }
}
