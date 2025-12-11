<?php
/**
 * Plugin Name: Schneider Model Code Extractor
 * Description: استخراج کد مدل محصولات اشنایدر از نام و ثبت آن به عنوان شناسه محصول با رابط کاربری مدیریت شامل پروگرس بار و لاگ زنده.
 * Version: 1.0.0
 * Author: OpenAI ChatGPT
 * Text Domain: sme-model-extractor
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('SME_Model_Code_Extractor')) {
    class SME_Model_Code_Extractor
    {
        const NONCE_ACTION = 'sme_model_code_extractor';
        const META_KEY = '_sme_model_code';

        public function __construct()
        {
            add_action('admin_menu', [$this, 'register_menu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
            add_action('wp_ajax_sme_process_products', [$this, 'ajax_process_products']);
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

            wp_enqueue_style('sme-model-extractor', plugin_dir_url(__FILE__) . 'assets/admin.css', [], '1.0.0');
            wp_enqueue_script('sme-model-extractor', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery'], '1.0.0', true);

            $product_counts = wc_get_products([
                'limit'  => 1,
                'return' => 'ids',
                'paginate' => true,
                'status' => ['publish', 'draft', 'pending', 'private'],
            ]);

            $total = isset($product_counts->total) ? (int) $product_counts->total : 0;

            wp_localize_script('sme-model-extractor', 'smeExtractor', [
                'ajaxUrl'       => admin_url('admin-ajax.php'),
                'nonce'         => wp_create_nonce(self::NONCE_ACTION),
                'totalProducts' => $total,
                'batchSize'     => 20,
            ]);
        }

        public function render_page(): void
        {
            if (!current_user_can('manage_woocommerce')) {
                wp_die(__('You do not have permission to access this page.', 'sme-model-extractor'));
            }
            ?>
            <div class="wrap sme-model-extractor">
                <h1><?php echo esc_html(__('Schneider Model Code Extractor', 'sme-model-extractor')); ?></h1>
                <p><?php echo esc_html(__('با اجرای این ابزار، کد مدل محصولات موجود در عنوان، به‌صورت خودکار استخراج و به عنوان شناسه محصول (SKU) ذخیره می‌شود.', 'sme-model-extractor')); ?></p>
                <button id="sme-start" class="button button-primary"><?php echo esc_html(__('شروع استخراج', 'sme-model-extractor')); ?></button>
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

            $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
            $batch_size = isset($_POST['batchSize']) ? (int) $_POST['batchSize'] : 20;

            $query = wc_get_products([
                'limit'    => $batch_size,
                'offset'   => $offset,
                'status'   => ['publish', 'draft', 'pending', 'private'],
                'paginate' => false,
                'return'   => 'objects',
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
    }

    new SME_Model_Code_Extractor();
}
