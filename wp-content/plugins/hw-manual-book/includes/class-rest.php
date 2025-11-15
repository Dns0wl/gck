<?php
declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class HWMB_REST
{
    private HWMB_Renderer $renderer;
    private HWMB_Files $files;
    private HWMB_Data_Source $data_source;
    private HWMB_Logger $logger;
    private string $cache_option = 'hwmb_cache_buster';

    public function __construct(HWMB_Renderer $renderer, HWMB_Files $files, HWMB_Data_Source $data_source, HWMB_Logger $logger)
    {
        $this->renderer    = $renderer;
        $this->files       = $files;
        $this->data_source = $data_source;
        $this->logger      = $logger;
    }

    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('hw-manual/v1', '/list', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'list_items'],
            'permission_callback' => '__return_true',
            'args'                => [
                'search'    => ['sanitize_callback' => 'sanitize_text_field'],
                'page'      => ['sanitize_callback' => 'absint', 'default' => 1],
                'per_page'  => ['sanitize_callback' => 'absint', 'default' => 10],
                'material'  => ['sanitize_callback' => 'sanitize_text_field'],
                'leather'   => ['sanitize_callback' => 'sanitize_text_field'],
                'date_from' => ['sanitize_callback' => 'sanitize_text_field'],
                'date_to'   => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route('hw-manual/v1', '/item/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_item'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('hw-manual/v1', '/build/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'build_item'],
            'permission_callback' => function () {
                return current_user_can('edit_serialnumbers');
            },
        ]);

        register_rest_route('hw-manual/v1', '/process/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'process_item'],
            'permission_callback' => function () {
                return current_user_can('edit_serialnumbers');
            },
        ]);
    }

    public function list_items(WP_REST_Request $request): WP_REST_Response
    {
        $args = [
            'post_type'      => 'serialnumber',
            'post_status'    => 'publish',
            's'              => $request->get_param('search'),
            'posts_per_page' => $request->get_param('per_page') ?: 10,
            'paged'          => $request->get_param('page') ?: 1,
            'fields'         => 'ids',
        ];

        $meta_query = [];
        if ($material = $request->get_param('material')) {
            $meta_query[] = [
                'key'   => 'material',
                'value' => $material,
            ];
        }
        if ($leather = $request->get_param('leather')) {
            $meta_query[] = [
                'key'   => 'leather_type',
                'value' => $leather,
            ];
        }
        if ($meta_query) {
            $args['meta_query'] = $meta_query;
        }
        if ($request->get_param('date_from') || $request->get_param('date_to')) {
            $args['date_query'] = [
                [
                    'after'     => $request->get_param('date_from') ?: null,
                    'before'    => $request->get_param('date_to') ?: null,
                    'inclusive' => true,
                ],
            ];
        }

        $cache_key = 'hwmb_list_' . get_option($this->cache_option, '1') . '_' . md5(wp_json_encode($args));
        $cached    = get_transient($cache_key);
        if ($cached) {
            return new WP_REST_Response($cached);
        }

        $query = new WP_Query($args);
        $items = [];
        foreach ($query->posts as $id) {
            $items[] = $this->prepare_item((int) $id);
        }

        $response = [
            'items' => $items,
            'meta'  => [
                'total'   => (int) $query->found_posts,
                'pages'   => (int) $query->max_num_pages,
                'page'    => (int) $args['paged'],
                'perPage' => (int) $args['posts_per_page'],
            ],
        ];
        set_transient($cache_key, $response, MINUTE_IN_SECONDS * 2);

        return new WP_REST_Response($response);
    }

    public function get_item(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->prepare_item((int) $request['id']));
    }

    public function build_item(WP_REST_Request $request): WP_REST_Response
    {
        $nonce = $request->get_header('X-WP-Nonce');
        if (! wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_REST_Response(['message' => __('Invalid nonce', 'hw-manual-book')], 403);
        }
        $post_id = (int) $request['id'];
        try {
            $result = $this->renderer->build_pdf($post_id);
            $this->bust_cache();
            return new WP_REST_Response(['success' => true, 'data' => $result]);
        } catch (Throwable $e) {
            $this->logger->log('REST build failed: ' . $e->getMessage(), 'error');
            return new WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function process_item(WP_REST_Request $request): WP_REST_Response
    {
        $nonce = $request->get_header('X-WP-Nonce');
        if (! wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_REST_Response(['message' => __('Invalid nonce', 'hw-manual-book')], 403);
        }

        $post_id = (int) $request['id'];
        $params  = $request->get_json_params() ?: [];
        $name    = isset($params['customer_name']) ? sanitize_text_field($params['customer_name']) : '';
        $mode    = isset($params['mode']) ? sanitize_key((string) $params['mode']) : 'now';
        $rawDate = isset($params['order_date']) ? sanitize_text_field((string) $params['order_date']) : '';

        if ('' === $name) {
            return new WP_REST_Response(['message' => __('Customer name is required.', 'hw-manual-book')], 400);
        }

        $overrides = [
            '{{customer_name}}' => $name,
            '{{order_date}}'    => $this->format_modal_date($rawDate, $mode),
        ];

        try {
            $result = $this->renderer->build_pdf($post_id, '', $overrides);
            $this->bust_cache();
            $item   = $this->prepare_item($post_id);
            return new WP_REST_Response([
                'success' => true,
                'pdf'     => $item['pdf'],
                'data'    => $result,
                'item'    => $item,
            ]);
        } catch (Throwable $e) {
            $this->logger->log('REST process failed: ' . $e->getMessage(), 'error');
            return new WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function prepare_item(int $post_id): array
    {
        $rel      = get_post_meta($post_id, '_hw_manual_pdf_relative', true);
        $settings = $this->files->get_settings();
        $url      = $rel ? ('protected' === $settings['mode'] ? $this->files->get_signed_url($rel, DAY_IN_SECONDS) : $this->files->public_url($rel)) : '';

        $timestamp   = get_post_time('U', true, $post_id);
        $date        = $timestamp ? wp_date('d/m/Y', $timestamp) : '';
        $customer    = sanitize_text_field((string) get_post_meta($post_id, '_hw_manual_customer', true));
        $order_date  = sanitize_text_field((string) get_post_meta($post_id, '_hw_manual_order_date', true));
        $order_final = $order_date ?: (string) get_post_meta($post_id, 'transaction_date', true);
        if ($order_final) {
            $order_final = $this->format_modal_date((string) $order_final, 'choose');
        }

        return [
            'id'       => $post_id,
            'title'    => get_the_title($post_id),
            'serial'   => get_post_meta($post_id, 'serial_code', true),
            'material' => get_post_meta($post_id, 'material', true),
            'leather'  => get_post_meta($post_id, 'leather_type', true),
            'date'     => $date,
            'pdf'      => $url,
            'customer' => $customer,
            'orderDate'=> $order_final,
        ];
    }

    private function format_modal_date(string $value, string $mode): string
    {
        if ('now' === $mode || empty($value)) {
            return wp_date('d/m/y', current_time('timestamp'));
        }

        $timestamp = null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $timestamp = strtotime($value . ' 00:00:00');
        } elseif (preg_match('/^\d{2}\/\d{2}\/\d{2,4}$/', $value)) {
            $dt = \DateTime::createFromFormat('d/m/Y', $value) ?: \DateTime::createFromFormat('d/m/y', $value);
            if ($dt) {
                $timestamp = $dt->getTimestamp();
            }
        }

        if (! $timestamp) {
            return $value;
        }

        return wp_date('d/m/y', $timestamp);
    }

    private function bust_cache(): void
    {
        update_option($this->cache_option, (string) microtime(true));
    }
}
