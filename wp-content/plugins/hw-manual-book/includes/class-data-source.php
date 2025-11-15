<?php
/**
 * Data collection helpers.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class HWMB_Data_Source
{
    private HWMB_Logger $logger;

    public function __construct(HWMB_Logger $logger)
    {
        $this->logger = $logger;
    }

    public function get_tokens(): array
    {
        return [
            '{{transaction_date}}',
            '{{product_name}}',
            '{{material}}',
            '{{leather_type}}',
            '{{color}}',
            '{{size}}',
            '{{serial_code}}',
            '{{qr_url}}',
            '{{brand_slogan}}',
            '{{footer_phone}}',
            '{{generated_at}}',
            '{{version}}',
            '{{qr_svg}}',
            '{{logo}}',
            '{{customer_name}}',
            '{{order_date}}',
        ];
    }

    public function get_mappings(): array
    {
        return get_option('hwmb_mappings', []);
    }

    public function get_templates(): array
    {
        $templates = get_option('hwmb_templates', []);
        if (empty($templates)) {
            $templates['default'] = [
                'id'          => 'default',
                'name'        => __('Default Template', 'hw-manual-book'),
                'description' => __('Bundled default layout.', 'hw-manual-book'),
                'html'        => file_get_contents(HWMB_PLUGIN_DIR . 'templates/default.html.php'),
                'css'         => file_get_contents(HWMB_PLUGIN_DIR . 'templates/default.css.php'),
            ];
            update_option('hwmb_templates', $templates);
        }

        return $templates;
    }

    public function get_template(string $template_id = ''): array
    {
        $templates = $this->get_templates();
        $settings  = get_option('hwmb_settings', []);
        $selected  = $template_id ?: ($settings['template'] ?? 'default');
        if (isset($templates[$selected])) {
            return $templates[$selected];
        }

        return reset($templates);
    }

    public function save_template(array $data): void
    {
        $templates              = $this->get_templates();
        $templates[$data['id']] = $data;
        update_option('hwmb_templates', $templates);
    }

    public function delete_template(string $template_id): void
    {
        $templates = $this->get_templates();
        if (isset($templates[$template_id])) {
            unset($templates[$template_id]);
            update_option('hwmb_templates', $templates);
        }
    }

    public function build_payload(int $post_id, array $overrides = []): array
    {
        $post = get_post($post_id);
        if (! $post || 'serialnumber' !== $post->post_type) {
            return [];
        }

        $settings = hwmb()->files->get_settings();
        $meta     = array_map('maybe_unserialize', get_post_meta($post_id));
        $serial   = get_post_meta($post_id, 'serial_code', true) ?: $post->post_title;

        $customer = get_post_meta($post_id, '_hw_manual_customer', true);
        $order    = get_post_meta($post_id, '_hw_manual_order_date', true);

        $tokens = [
            '{{transaction_date}}' => $this->format_date(get_post_meta($post_id, 'transaction_date', true)),
            '{{product_name}}'     => $this->format_title(get_post_meta($post_id, 'product_name', true) ?: $post->post_title),
            '{{material}}'         => $this->safe_text(get_post_meta($post_id, 'material', true)),
            '{{leather_type}}'     => $this->safe_text(get_post_meta($post_id, 'leather_type', true)),
            '{{color}}'            => $this->safe_text(get_post_meta($post_id, 'color', true)),
            '{{size}}'             => $this->safe_text(get_post_meta($post_id, 'size', true)),
            '{{serial_code}}'      => $serial,
            '{{qr_url}}'           => esc_url_raw(trailingslashit($settings['qr_base']) . rawurlencode($serial)),
            '{{brand_slogan}}'     => $settings['brand_slogan'],
            '{{footer_phone}}'     => $settings['footer_phone'],
            '{{generated_at}}'     => $this->format_date(current_time('mysql')),
            '{{version}}'          => HWMB_VERSION,
            '{{qr_svg}}'           => hwmb()->qr->generate_svg(trailingslashit($settings['qr_base']) . rawurlencode($serial)),
            '{{logo}}'             => $settings['logo'],
            '{{customer_name}}'    => $this->safe_text($customer),
            '{{order_date}}'       => $order ? $this->safe_text($order) : $this->format_short_date(get_post_meta($post_id, 'transaction_date', true)),
        ];

        foreach ($this->get_mappings() as $token => $map) {
            if (empty($token)) {
                continue;
            }
            $meta_key = $map['meta'] ?? '';
            $fallback = $map['fallback'] ?? '';
            if (! isset($tokens[$token])) {
                $tokens[$token] = '';
            }
            if ($meta_key) {
                $value = get_post_meta($post_id, $meta_key, true);
                if ('' !== $value && null !== $value) {
                    $tokens[$token] = $this->safe_text((string) $value);
                    continue;
                }
            }
            if ($fallback && '' === $tokens[$token]) {
                $tokens[$token] = $fallback;
            }
        }

        foreach ($overrides as $token => $value) {
            if (! is_string($token)) {
                continue;
            }
            $normalized              = $this->normalize_override_key($token);
            $tokens[$normalized] = $this->safe_text((string) $value);
        }

        return $tokens;
    }

    private function normalize_override_key(string $token): string
    {
        $token = trim($token);
        if (str_starts_with($token, '{{') && str_ends_with($token, '}}')) {
            return $token;
        }

        $token = trim($token, '{}');

        return '{{' . $token . '}}';
    }

    private function format_date(?string $value): string
    {
        if (empty($value)) {
            return '';
        }

        $timestamp = strtotime($value);
        if (! $timestamp) {
            return $value;
        }

        return gmdate('d F Y', $timestamp);
    }

    private function format_short_date(?string $value): string
    {
        if (empty($value)) {
            return '';
        }

        $timestamp = strtotime($value);
        if (! $timestamp) {
            return $value;
        }

        return gmdate('d/m/y', $timestamp);
    }

    private function format_title(string $value): string
    {
        $value = strtolower($value);
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    private function safe_text(?string $value): string
    {
        $value = (string) $value;
        return trim(wp_kses_post($value));
    }
}
