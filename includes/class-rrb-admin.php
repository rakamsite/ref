<?php

if (!defined('ABSPATH')) {
    exit;
}

class RRB_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('wp_ajax_rrb_save_url', array(__CLASS__, 'ajax_save_url'));
        add_action('wp_ajax_rrb_queue_item', array(__CLASS__, 'ajax_queue_item'));
        add_action('wp_ajax_rrb_bulk_apply', array(__CLASS__, 'ajax_bulk_apply'));
        add_action('wp_ajax_rrb_toggle_queue', array(__CLASS__, 'ajax_toggle_queue'));
        add_action('wp_ajax_rrb_poll_status', array(__CLASS__, 'ajax_poll_status'));
        add_action('wp_ajax_rrb_force_refresh', array(__CLASS__, 'ajax_force_refresh'));
        add_action('wp_ajax_rrb_undo', array(__CLASS__, 'ajax_undo'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
    }

    public static function register_menu() {
        $capability = current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
        add_submenu_page(
            'woocommerce',
            'ساخت رفرنس‌ها',
            'ساخت رفرنس‌ها',
            $capability,
            'rrb-reference-builder',
            array(__CLASS__, 'render_reference_page')
        );
        add_submenu_page(
            'woocommerce',
            'تنظیمات رفرنس‌ها',
            'تنظیمات رفرنس‌ها',
            $capability,
            'rrb-reference-settings',
            array(__CLASS__, 'render_settings_page')
        );
    }

    public static function enqueue_assets($hook) {
        if (strpos($hook, 'rrb-reference-builder') === false) {
            return;
        }
        wp_enqueue_style('rrb-admin', RRB_PLUGIN_URL . 'assets/admin.css', array(), '1.0.0');
        wp_enqueue_script('rrb-admin', RRB_PLUGIN_URL . 'assets/admin.js', array('jquery'), '1.0.0', true);
        wp_localize_script('rrb-admin', 'RRBSettings', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rrb_admin_nonce'),
        ));
    }

    public static function register_settings() {
        register_setting('rrb_settings', 'rrb_interval_minutes', array('type' => 'integer', 'default' => 5));
        register_setting('rrb_settings', 'rrb_retry_count', array('type' => 'integer', 'default' => 2));
        register_setting('rrb_settings', 'rrb_cache_enabled', array('type' => 'boolean', 'default' => 1));
        register_setting('rrb_settings', 'rrb_cache_ttl_days', array('type' => 'integer', 'default' => 30));
        register_setting('rrb_settings', 'rrb_dry_run', array('type' => 'boolean', 'default' => 0));
        register_setting('rrb_settings', 'rrb_tag_template', array('type' => 'string', 'default' => 'فیلتر هواکش {CODE} {BRAND_FA} {BRAND_EN}'));
    }

    public static function render_reference_page() {
        $capability = current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
        if (!current_user_can($capability)) {
            wp_die('دسترسی ندارید.');
        }

        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $per_page = 10;
        $offset = ($paged - 1) * $per_page;
        $query = new WC_Product_Query(array(
            'limit' => $per_page,
            'offset' => $offset,
            'status' => array('publish', 'draft', 'private'),
        ));
        $products = $query->get_products();
        $total_products = $query->get_total();
        $total_pages = max(1, (int) ceil($total_products / $per_page));

        $paused = RRB_Queue::is_paused();
        $processed_count = self::count_processed();
        $queued_count = self::count_total_queue();

        echo '<div class="wrap rrb-admin">';
        echo '<h1>ساخت رفرنس‌ها</h1>';

        echo '<div class="rrb-controls">';
        echo '<div class="rrb-bulk">';
        echo '<label for="rrb-bulk-input">ورودی گروهی</label>';
        echo '<textarea id="rrb-bulk-input" rows="5" placeholder="PRODUCT_ID|BEHRAN_URL"></textarea>';
        echo '<button class="button button-primary" id="rrb-bulk-apply">اعمال</button>';
        echo '</div>';
        echo '<div class="rrb-queue-buttons">';
        echo '<button class="button button-primary" id="rrb-start-queue">شروع پردازش صف</button>';
        echo '<button class="button" id="rrb-pause-queue">توقف</button>';
        echo '<button class="button" id="rrb-resume-queue">ادامه</button>';
        echo '</div>';
        echo '<div class="rrb-progress">';
        echo '<strong>پیشرفت:</strong> <span id="rrb-progress-count">' . esc_html($processed_count) . '</span> / ' . esc_html($queued_count) . ' پردازش شده';
        echo '</div>';
        echo '<div class="rrb-status">وضعیت صف: ' . ($paused ? 'متوقف' : 'فعال') . '</div>';
        echo '</div>';

        echo '<table class="widefat fixed striped rrb-table">';
        echo '<thead><tr>';
        echo '<th>محصول</th><th>لینک بهران</th><th>وضعیت</th><th>نتیجه</th><th>خطا</th><th>عملیات</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($products as $product) {
            $item = RRB_DB::get_item_by_product($product->get_id());
            $status = $item ? $item->status : 'pending';
            $url = $item ? $item->behran_url : get_post_meta($product->get_id(), '_rakam_ref_last_source_url', true);
            $result_html = self::render_result_links($item);
            $error = $item ? $item->last_error_message : '';

            echo '<tr data-product-id="' . esc_attr($product->get_id()) . '">';
            echo '<td>';
            echo '<strong>' . esc_html($product->get_name()) . '</strong><br>';
            echo '<a href="' . esc_url(get_permalink($product->get_id())) . '" target="_blank">نمایش</a> | ';
            echo '<a href="' . esc_url(get_edit_post_link($product->get_id())) . '">ویرایش</a>';
            echo '</td>';
            echo '<td><input type="text" class="rrb-url-input" value="' . esc_attr($url) . '" placeholder="https://behranfilter.ir/product/..." /></td>';
            echo '<td><span class="rrb-status-badge rrb-status-' . esc_attr($status) . '">' . esc_html(self::status_label($status)) . '</span></td>';
            echo '<td class="rrb-result">' . $result_html . '</td>';
            echo '<td class="rrb-error">' . esc_html($error) . '</td>';
            echo '<td class="rrb-actions">';
            echo '<button class="button rrb-queue">صف‌بندی</button>';
            echo '<button class="button rrb-run">اجرا</button>';
            echo '<button class="button rrb-force-refresh">Force Refresh</button>';
            echo '<button class="button rrb-undo">Undo</button>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        echo '<div class="tablenav">';
        echo '<div class="tablenav-pages">';
        $base_url = remove_query_arg('paged');
        for ($page = 1; $page <= $total_pages; $page++) {
            $class = $page === $paged ? ' class="page-numbers current"' : ' class="page-numbers"';
            echo '<a' . $class . ' href="' . esc_url(add_query_arg('paged', $page, $base_url)) . '">' . esc_html($page) . '</a> ';
        }
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    public static function render_settings_page() {
        $capability = current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
        if (!current_user_can($capability)) {
            wp_die('دسترسی ندارید.');
        }
        echo '<div class="wrap">';
        echo '<h1>تنظیمات رفرنس‌ها</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('rrb_settings');
        echo '<table class="form-table">';

        self::render_setting_field('rrb_interval_minutes', 'فاصله بین درخواست‌ها (دقیقه)');
        self::render_setting_field('rrb_retry_count', 'تعداد تلاش مجدد');
        self::render_setting_checkbox('rrb_cache_enabled', 'فعال‌سازی کش');
        self::render_setting_field('rrb_cache_ttl_days', 'مدت کش (روز)');
        self::render_setting_checkbox('rrb_dry_run', 'فقط نمایش (بدون ساخت تگ)');
        self::render_setting_field('rrb_tag_template', 'الگوی نام تگ');

        echo '</table>';
        submit_button('ذخیره تنظیمات');
        echo '</form>';
        echo '</div>';
    }

    private static function render_setting_field($option, $label) {
        $value = get_option($option);
        echo '<tr>'; 
        echo '<th scope="row"><label for="' . esc_attr($option) . '">' . esc_html($label) . '</label></th>';
        echo '<td><input type="text" name="' . esc_attr($option) . '" id="' . esc_attr($option) . '" value="' . esc_attr($value) . '" class="regular-text" /></td>';
        echo '</tr>';
    }

    private static function render_setting_checkbox($option, $label) {
        $value = get_option($option);
        echo '<tr>'; 
        echo '<th scope="row"><label for="' . esc_attr($option) . '">' . esc_html($label) . '</label></th>';
        echo '<td><input type="checkbox" name="' . esc_attr($option) . '" id="' . esc_attr($option) . '" value="1" ' . checked($value, 1, false) . ' /></td>';
        echo '</tr>';
    }

    public static function ajax_save_url() {
        self::verify_ajax();
        $product_id = absint($_POST['product_id'] ?? 0);
        $url = esc_url_raw($_POST['url'] ?? '');
        if (!$product_id || empty($url)) {
            wp_send_json_error('اطلاعات ناقص است.');
        }

        update_post_meta($product_id, '_rakam_ref_last_source_url', $url);
        wp_send_json_success();
    }

    public static function ajax_queue_item() {
        self::verify_ajax();
        $product_id = absint($_POST['product_id'] ?? 0);
        $url = esc_url_raw($_POST['url'] ?? '');
        $run_now = !empty($_POST['run_now']);
        if (!$product_id || empty($url)) {
            wp_send_json_error('اطلاعات ناقص است.');
        }

        $item = RRB_DB::get_item_by_product($product_id);
        $data = array(
            'product_id' => $product_id,
            'behran_url' => $url,
            'status' => 'queued',
            'force_refresh' => 0,
            'dry_run' => (int) get_option('rrb_dry_run', 0),
        );
        if ($item) {
            RRB_DB::update_item($item->id, $data);
            $item_id = $item->id;
        } else {
            $item_id = RRB_DB::insert_item($data);
        }

        update_post_meta($product_id, '_rakam_ref_last_source_url', $url);
        RRB_Queue::schedule_runner();
        if ($run_now) {
            if (class_exists('ActionScheduler')) {
                as_enqueue_async_action(RRB_Queue::ACTION_HOOK, array(), 'rrb');
            } else {
                wp_schedule_single_event(time() + 5, RRB_Queue::ACTION_HOOK);
            }
        }

        wp_send_json_success(array('item_id' => $item_id));
    }

    public static function ajax_force_refresh() {
        self::verify_ajax();
        $product_id = absint($_POST['product_id'] ?? 0);
        $url = esc_url_raw($_POST['url'] ?? '');
        if (!$product_id || empty($url)) {
            wp_send_json_error('اطلاعات ناقص است.');
        }
        $item = RRB_DB::get_item_by_product($product_id);
        if ($item) {
            RRB_DB::update_item($item->id, array(
                'behran_url' => $url,
                'status' => 'queued',
                'force_refresh' => 1,
                'dry_run' => (int) get_option('rrb_dry_run', 0),
            ));
        } else {
            RRB_DB::insert_item(array(
                'product_id' => $product_id,
                'behran_url' => $url,
                'status' => 'queued',
                'force_refresh' => 1,
                'dry_run' => (int) get_option('rrb_dry_run', 0),
            ));
        }
        RRB_Queue::schedule_runner();
        wp_send_json_success();
    }

    public static function ajax_bulk_apply() {
        self::verify_ajax();
        $bulk = sanitize_textarea_field($_POST['bulk'] ?? '');
        if (empty($bulk)) {
            wp_send_json_error('ورودی خالی است.');
        }
        $lines = preg_split('/\r?\n/', $bulk);
        $applied = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) !== 2) {
                continue;
            }
            $product_id = absint($parts[0]);
            $url = esc_url_raw($parts[1]);
            if (!$product_id || empty($url)) {
                continue;
            }
            update_post_meta($product_id, '_rakam_ref_last_source_url', $url);
            $applied[] = array('product_id' => $product_id, 'url' => $url);
        }
        wp_send_json_success(array('applied' => $applied));
    }

    public static function ajax_toggle_queue() {
        self::verify_ajax();
        $action = sanitize_text_field($_POST['queue_action'] ?? '');
        if ($action === 'pause') {
            RRB_Queue::pause_queue();
        } elseif ($action === 'resume' || $action === 'start') {
            RRB_Queue::resume_queue();
        }
        wp_send_json_success();
    }

    public static function ajax_poll_status() {
        self::verify_ajax();
        $ids = array_map('absint', $_POST['product_ids'] ?? array());
        $data = array();
        foreach ($ids as $product_id) {
            $item = RRB_DB::get_item_by_product($product_id);
            if ($item) {
                $data[$product_id] = array(
                    'status' => $item->status,
                    'status_label' => self::status_label($item->status),
                    'error' => $item->last_error_message,
                    'result_html' => self::render_result_links($item),
                );
            }
        }
        wp_send_json_success($data);
    }

    public static function ajax_undo() {
        self::verify_ajax();
        $product_id = absint($_POST['product_id'] ?? 0);
        if (!$product_id) {
            wp_send_json_error('محصول یافت نشد.');
        }
        $result = RRB_Tags::undo_tags($product_id);
        if (!$result['success']) {
            wp_send_json_error($result['message']);
        }
        wp_send_json_success();
    }

    private static function status_label($status) {
        $labels = array(
            'pending' => 'در انتظار',
            'queued' => 'صف‌شده',
            'running' => 'در حال اجرا',
            'done' => 'انجام شد',
            'error' => 'خطا',
        );
        return $labels[$status] ?? $status;
    }

    private static function render_result_links($item) {
        if (!$item || empty($item->result_json)) {
            return '';
        }
        $results = json_decode($item->result_json, true);
        if (empty($results)) {
            return '';
        }
        $links = array();
        foreach ($results as $entry) {
            foreach ($entry['codes'] as $code) {
                $tag_name = $code . ' ' . $entry['brand_fa'];
                $slug = 'ref-airfilter-' . sanitize_title($code) . '-' . sanitize_title($entry['brand_en']);
                $term = get_term_by('slug', $slug, 'product_tag');
                if ($term) {
                    $links[] = '<a href="' . esc_url(get_term_link($term)) . '" target="_blank">' . esc_html($tag_name) . '</a>';
                } else {
                    $links[] = esc_html($tag_name);
                }
            }
        }
        return implode('<br>', $links);
    }

    private static function verify_ajax() {
        check_ajax_referer('rrb_admin_nonce', 'nonce');
        $capability = current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
        if (!current_user_can($capability)) {
            wp_send_json_error('دسترسی ندارید.');
        }
    }

    private static function count_processed() {
        global $wpdb;
        $table = RRB_DB::table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'done'");
    }

    private static function count_total_queue() {
        global $wpdb;
        $table = RRB_DB::table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }
}
