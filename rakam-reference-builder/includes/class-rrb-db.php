<?php

if (!defined('ABSPATH')) {
    exit;
}

class RRB_DB {
    const TABLE = 'rrb_queue';

    public static function init() {
        // No-op for now.
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function activate() {
        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            behran_url TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            last_run_at DATETIME NULL,
            last_error_message TEXT NULL,
            result_json LONGTEXT NULL,
            created_term_ids_json LONGTEXT NULL,
            force_refresh TINYINT(1) NOT NULL DEFAULT 0,
            dry_run TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function insert_item($data) {
        global $wpdb;
        $table = self::table_name();
        $defaults = array(
            'status' => 'pending',
            'attempts' => 0,
            'force_refresh' => 0,
            'dry_run' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );
        $data = wp_parse_args($data, $defaults);
        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    public static function update_item($id, $data) {
        global $wpdb;
        $table = self::table_name();
        $data['updated_at'] = current_time('mysql');
        return $wpdb->update($table, $data, array('id' => $id));
    }

    public static function get_item($id) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
    }

    public static function get_item_by_product($product_id) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE product_id = %d ORDER BY id DESC LIMIT 1", $product_id));
    }

    public static function get_items($args = array()) {
        global $wpdb;
        $table = self::table_name();
        $defaults = array(
            'status' => null,
            'limit' => 20,
            'offset' => 0,
        );
        $args = wp_parse_args($args, $defaults);
        $where = '1=1';
        $params = array();
        if (!empty($args['status'])) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }
        $limit_sql = $wpdb->prepare(' LIMIT %d OFFSET %d', $args['limit'], $args['offset']);
        if (!empty($params)) {
            $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC" . $limit_sql, $params);
        } else {
            $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC" . $limit_sql;
        }
        return $wpdb->get_results($sql);
    }

    public static function count_items() {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    public static function get_next_queue_item() {
        global $wpdb;
        $table = self::table_name();
        $candidates = $wpdb->get_results("SELECT * FROM {$table} WHERE status IN ('queued','pending') ORDER BY id ASC LIMIT 5");
        if (empty($candidates)) {
            return null;
        }
        $interval_minutes = (int) get_option('rrb_interval_minutes', 5);
        foreach ($candidates as $item) {
            if (empty($item->last_run_at)) {
                return $item;
            }
            $attempts = (int) $item->attempts;
            if ($attempts === 0) {
                return $item;
            }
            $backoff_minutes = pow(2, $attempts - 1) * max(1, $interval_minutes);
            $next_time = strtotime($item->last_run_at) + ($backoff_minutes * MINUTE_IN_SECONDS);
            if (time() >= $next_time) {
                return $item;
            }
        }
        return null;
    }
}
