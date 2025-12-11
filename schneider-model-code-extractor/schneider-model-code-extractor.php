<?php
/**
 * Plugin Name: Schneider Model Code Extractor
 * Plugin URI: https://example.com/schneider-model-code-extractor
 * Description: استخراج کد مدل محصولات اشنایدر از نام و ثبت آن به عنوان شناسه محصول با رابط کاربری مدیریت شامل پروگرس بار و لاگ زنده.
 * Version: 1.0.1
 * Author: OpenAI ChatGPT
 * Text Domain: sme-model-extractor
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('SME_Model_Code_Extractor')) {
    class SME_Model_Code_Extractor
    {
        const NONCE_ACTION = 'sme_model_code_extractor';
        const META_KEY = '_sme_model_code';
        const VERSION = '1.0.1';
        const OPTION_STATE = 'sme_model_code_state';
        const CRON_HOOK = 'sme_model_code_extractor_cron';

        /** @var array<string,int>|null */
        private $cached_counts = null;

        public function __construct()
        {
            add_action('admin_menu', [$this, 'register_menu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
            add_action('wp_ajax_sme_process_products', [$this, 'ajax_process_products']);
            add_action('wp_ajax_sme_start_processing', [$this, 'ajax_start_processing']);
            add_action('wp_ajax_sme_stop_processing', [$this, 'ajax_stop_processing']);
            add_action('wp_ajax_sme_status_processing', [$this, 'ajax_status_processing']);
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
            add_action('admin_notices', [$this, 'maybe_show_woocommerce_notice']);
            add_action(self::CRON_HOOK, [$this, 'run_background_batch']);
        }

        public function add_settings_link(array $links): array
        {
            $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=sme-model-code-extractor')) . '">' . esc_html__('اجرای استخراج', 'sme-model-extractor') . '</a>';
            array_unshift($links, $settings_link);
            return $links;
        }

        public function maybe_show_woocommerce_notice(): void
        {
            if (!is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
                return;
            }

            if (!class_exists('WooCommerce')) {
                echo '<div class="notice notice-error"><p>' . esc_html__('برای استفاده از افزونه «Schneider Model Code Extractor» نیاز است ووکامرس فعال باشد.', 'sme-model-extractor') . '</p></div>';
            }
        }

        public function register_menu(): void
        {
            add_menu_page(
                __('Model Code Extractor', 'sme-model-extractor'),
                __('Model Code Extractor', 'sme-model-extractor'),
                'manage_woocommerce',
                'sme-model-code-extractor',
                [$this, 'render_page'],
                'dashicons-products'
            );
        }

        public function enqueue_assets(string $hook): void
        {
            if ($hook !== 'toplevel_page_sme-model-code-extractor') {
                return;
            }

            $counts = $this->get_product_counts();

            wp_enqueue_style('sme-model-extractor', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
            wp_enqueue_script('sme-model-extractor', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery'], self::VERSION, true);

            wp_localize_script('sme-model-extractor', 'smeExtractor', [
                'ajaxUrl'       => admin_url('admin-ajax.php'),
                'nonce'         => wp_create_nonce(self::NONCE_ACTION),
                'totalProducts' => $counts['missing'],
                'withCode'      => $counts['with_code'],
                'missingCount'  => $counts['missing'],
                'batchSize'     => 20,
                'state'         => $this->get_state(),
            ]);
        }

        public function render_page(): void
        {
            if (!current_user_can('manage_woocommerce')) {
                wp_die(__('You do not have permission to access this page.', 'sme-model-extractor'));
            }

            $counts = $this->get_product_counts();
            ?>
            <div class="wrap sme-model-extractor">
                <h1><?php echo esc_html(__('Schneider Model Code Extractor', 'sme-model-extractor')); ?></h1>
                <p><?php echo esc_html(__('با اجرای این ابزار، کد مدل محصولات موجود در عنوان، به‌صورت خودکار استخراج و به عنوان شناسه محصول (SKU) ذخیره می‌شود.', 'sme-model-extractor')); ?></p>

                <div class="sme-stats">
                    <div class="sme-stat-box has-code">
                        <h3><?php echo esc_html(__('محصولات دارای کد', 'sme-model-extractor')); ?></h3>
                        <p id="sme-with-count"><?php echo esc_html(number_format_i18n($counts['with_code'])); ?></p>
                    </div>
                    <div class="sme-stat-box missing-code">
                        <h3><?php echo esc_html(__('محصولات بدون کد', 'sme-model-extractor')); ?></h3>
                        <p id="sme-missing-count"><?php echo esc_html(number_format_i18n($counts['missing'])); ?></p>
                    </div>
                </div>

                <button id="sme-start" class="button button-primary"><?php echo esc_html(__('شروع استخراج', 'sme-model-extractor')); ?></button>
                <button id="sme-stop" class="button button-secondary" disabled><?php echo esc_html(__('توقف', 'sme-model-extractor')); ?></button>
                <div class="sme-progress-wrapper">
                    <div class="sme-progress-bar" id="sme-progress-bar"></div>
                </div>
                <div class="sme-progress-info">
                    <span id="sme-progress-text"><?php echo esc_html(__('منتظر شروع...', 'sme-model-extractor')); ?></span>
                    <span id="sme-eta"></span>
                </div>
                <h2><?php echo esc_html(__('Log زنده', 'sme-model-extractor')); ?></h2>
                <div id="sme-log" class="sme-log"></div>
            </div>
            <?php
        }

        public function ajax_process_products(): void
        {
            check_ajax_referer(self::NONCE_ACTION, 'nonce');

            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error(__('Unauthorized', 'sme-model-extractor'), 403);
            }

            if (!class_exists('WooCommerce')) {
                wp_send_json_error(__('WooCommerce must be active برای اجرای این پردازش.', 'sme-model-extractor'), 400);
            }

            $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
            $batch_size = isset($_POST['batchSize']) ? (int) $_POST['batchSize'] : 20;

            $query = wc_get_products([
                'limit'    => $batch_size,
                'offset'   => $offset,
                'status'   => ['publish', 'draft', 'pending', 'private'],
                'paginate' => false,
                'return'   => 'objects',
                'meta_query' => $this->get_missing_products_meta_query(),
            ]);

            $processed = 0;
            $updated = 0;
            $logs = [];

            foreach ($query as $product) {
                $processed++;
                $name = $product->get_name();
                $model_code = $this->extract_model_code($name);

                if (!$model_code) {
                    $logs[] = sprintf(__('هیچ کد مدلی در محصول "%s" پیدا نشد.', 'sme-model-extractor'), $name);
                    continue;
                }

                $existing_sku = $product->get_sku();
                if ($existing_sku === $model_code) {
                    $logs[] = sprintf(__('کد مدل "%s" پیش‌تر به‌عنوان SKU برای "%s" ثبت شده بود.', 'sme-model-extractor'), $model_code, $name);
                    continue;
                }

                $product->set_sku($model_code);
                $product->update_meta_data(self::META_KEY, $model_code);
                $product->save();
                $updated++;
                $logs[] = sprintf(__('کد مدل "%s" برای "%s" ثبت شد.', 'sme-model-extractor'), $model_code, $name);
            }

            $new_offset = $offset + $batch_size;
            $total_products = isset($_POST['totalProducts']) ? (int) $_POST['totalProducts'] : 0;
            $complete = $new_offset >= $total_products || $processed === 0;

            wp_send_json_success([
                'processed' => $processed,
                'updated'   => $updated,
                'offset'    => $new_offset,
                'complete'  => $complete,
                'logs'      => $logs,
            ]);
        }

        public function ajax_start_processing(): void
        {
            check_ajax_referer(self::NONCE_ACTION, 'nonce');

            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error(__('Unauthorized', 'sme-model-extractor'), 403);
            }

            if (!class_exists('WooCommerce')) {
                wp_send_json_error(__('WooCommerce must be active برای اجرای این پردازش.', 'sme-model-extractor'), 400);
            }

            $counts = $this->get_product_counts(true);

            if ($counts['missing'] <= 0) {
                wp_send_json_error(__('هیچ محصول بدون کدی برای پردازش وجود ندارد.', 'sme-model-extractor'), 400);
            }

            $state = [
                'running'       => true,
                'processed'     => 0,
                'updated'       => 0,
                'total'         => $counts['missing'],
                'last_logs'     => [],
                'last_run'      => time(),
                'batch_size'    => 20,
            ];

            update_option(self::OPTION_STATE, $state, false);
            $this->schedule_next_run();
            $this->cached_counts = null;

            wp_send_json_success([
                'state'  => $state,
                'counts' => $counts,
            ]);
        }

        public function ajax_stop_processing(): void
        {
            check_ajax_referer(self::NONCE_ACTION, 'nonce');

            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error(__('Unauthorized', 'sme-model-extractor'), 403);
            }

            $state = $this->get_state();
            $state['running'] = false;
            update_option(self::OPTION_STATE, $state, false);
            wp_clear_scheduled_hook(self::CRON_HOOK);

            $this->cached_counts = null;

            wp_send_json_success(['state' => $state, 'counts' => $this->get_product_counts(true)]);
        }

        public function ajax_status_processing(): void
        {
            check_ajax_referer(self::NONCE_ACTION, 'nonce');

            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error(__('Unauthorized', 'sme-model-extractor'), 403);
            }

            $state = $this->get_state();
            $counts = $this->get_product_counts(true);

            wp_send_json_success([
                'state'  => $state,
                'counts' => $counts,
            ]);
        }

        public function run_background_batch(): void
        {
            $state = $this->get_state();

            if (empty($state['running'])) {
                return;
            }

            if (!class_exists('WooCommerce')) {
                $state['running'] = false;
                update_option(self::OPTION_STATE, $state, false);
                wp_clear_scheduled_hook(self::CRON_HOOK);
                return;
            }

            $batch_size = isset($state['batch_size']) ? (int) $state['batch_size'] : 20;

            $query = wc_get_products([
                'limit'      => $batch_size,
                'status'     => ['publish', 'draft', 'pending', 'private'],
                'paginate'   => false,
                'return'     => 'objects',
                'meta_query' => $this->get_missing_products_meta_query(),
            ]);

            $processed = 0;
            $updated = 0;
            $logs = [];

            foreach ($query as $product) {
                $processed++;
                $name = $product->get_name();
                $model_code = $this->extract_model_code($name);

                if (!$model_code) {
                    $logs[] = sprintf(__('هیچ کد مدلی در محصول "%s" پیدا نشد.', 'sme-model-extractor'), $name);
                    continue;
                }

                $existing_sku = $product->get_sku();
                if ($existing_sku === $model_code) {
                    $logs[] = sprintf(__('کد مدل "%s" پیش‌تر به‌عنوان SKU برای "%s" ثبت شده بود.', 'sme-model-extractor'), $model_code, $name);
                    continue;
                }

                $product->set_sku($model_code);
                $product->update_meta_data(self::META_KEY, $model_code);
                $product->save();
                $updated++;
                $logs[] = sprintf(__('کد مدل "%s" برای "%s" ثبت شد.', 'sme-model-extractor'), $model_code, $name);
            }

            $state['processed'] = isset($state['processed']) ? ((int) $state['processed']) + $processed : $processed;
            $state['updated'] = isset($state['updated']) ? ((int) $state['updated']) + $updated : $updated;
            $state['last_logs'] = $logs;
            $state['last_run'] = time();

            if (count($query) < $batch_size) {
                $state['running'] = false;
                wp_clear_scheduled_hook(self::CRON_HOOK);
            } else {
                $this->schedule_next_run();
            }

            update_option(self::OPTION_STATE, $state, false);
            $this->cached_counts = null;
        }

        private function extract_model_code(string $name): ?string
        {
            $normalized = preg_replace('/[^A-Za-z0-9\s-]/u', ' ', $name);
            if (!$normalized) {
                return null;
            }

            $patterns = [
                '/\b([A-Z]{2,}\d{2,}[A-Z0-9]{0,})\b/u',
                '/\b([A-Z]{2,}\d{1,}[A-Z]{1,}\d{0,})\b/u',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $normalized, $matches)) {
                    return strtoupper($matches[1]);
                }
            }

            return null;
        }

        /**
         * @return array<string,int>
         */
        private function get_product_counts(bool $reset_cache = false): array
        {
            if (!$reset_cache && is_array($this->cached_counts)) {
                return $this->cached_counts;
            }

            $total_query = wc_get_products([
                'limit'    => 1,
                'return'   => 'ids',
                'paginate' => true,
                'status'   => ['publish', 'draft', 'pending', 'private'],
            ]);

            $missing_query = wc_get_products([
                'limit'      => 1,
                'return'     => 'ids',
                'paginate'   => true,
                'status'     => ['publish', 'draft', 'pending', 'private'],
                'meta_query' => $this->get_missing_products_meta_query(),
            ]);

            $total = isset($total_query->total) ? (int) $total_query->total : 0;
            $missing = isset($missing_query->total) ? (int) $missing_query->total : 0;
            $with_code = max(0, $total - $missing);

            $this->cached_counts = [
                'total'      => $total,
                'missing'    => $missing,
                'with_code'  => $with_code,
            ];

            return $this->cached_counts;
        }

        /**
         * @return array<int,array<string,string>>
         */
        private function get_missing_products_meta_query(): array
        {
            return [
                'relation' => 'AND',
                [
                    'relation' => 'OR',
                    [
                        'key'     => self::META_KEY,
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => self::META_KEY,
                        'value'   => '',
                        'compare' => '=',
                    ],
                ],
                [
                    'relation' => 'OR',
                    [
                        'key'     => '_sku',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => '_sku',
                        'value'   => '',
                        'compare' => '=',
                    ],
                ],
            ];
        }

        /**
         * @return array<string,mixed>
         */
        private function get_state(): array
        {
            $state = get_option(self::OPTION_STATE, []);

            if (!is_array($state)) {
                $state = [];
            }

            $defaults = [
                'running'   => false,
                'processed' => 0,
                'updated'   => 0,
                'total'     => 0,
                'last_logs' => [],
                'last_run'  => 0,
                'batch_size'=> 20,
            ];

            return array_merge($defaults, $state);
        }

        private function schedule_next_run(): void
        {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_single_event(time() + 10, self::CRON_HOOK);
            }
        }
    }

    new SME_Model_Code_Extractor();
}
