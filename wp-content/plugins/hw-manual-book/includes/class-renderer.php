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
        $tokens   = $this->data_source->build_payload($post_id, $overrides);
        $template = $this->data_source->get_template($template_id);
        $html     = $template['html'] ?? '';
        $css      = $template['css'] ?? '';
        if (! $tokens) {
            return '';
        }

        $search  = array_keys($tokens);
        $replace = array_values($tokens);

        $html = str_replace($search, $replace, $html);
        $css  = str_replace($search, $replace, $css);

        return '<style>' . $css . '</style>' . $html;
    }

    public function build_pdf(int $post_id, string $template_id = '', array $overrides = [], bool $persist = true): array
    {
        $tokens   = $this->data_source->build_payload($post_id, $overrides);
        $template = $this->data_source->get_template($template_id);
        if (! $tokens) {
            throw new RuntimeException(__('Unable to build payload for PDF.', 'hw-manual-book'));
        }

        $css  = $template['css'] ?? '';
        $html = $template['html'] ?? '';
        $html = str_replace(array_keys($tokens), array_values($tokens), $html);
        $css  = str_replace(array_keys($tokens), array_values($tokens), $css);

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
            'format'        => 'A5',
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
        if (! $persist) {
            $filename = sanitize_file_name(($tokens['{{serial_code}}'] ?? (string) $post_id) . '-manual-book.pdf');
            return [
                'binary'   => $binary,
                'filename' => $filename,
            ];
        }

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
}
