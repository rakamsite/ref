<?php

if (!defined('ABSPATH')) {
    exit;
}

class RRB_Queue {
    const ACTION_HOOK = 'rrb_process_queue_item';
    const LOCK_KEY = 'rrb_queue_lock';
    const PAUSE_OPTION = 'rrb_queue_paused';

    public static function init() {
        add_action(self::ACTION_HOOK, array(__CLASS__, 'process_queue_item'));
        add_filter('cron_schedules', array(__CLASS__, 'register_cron_interval'));
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::ACTION_HOOK);
    }

    public static function register_cron_interval($schedules) {
        $minutes = (int) get_option('rrb_interval_minutes', 5);
        $schedules['rrb_interval'] = array(
            'interval' => max(60, $minutes * 60),
            'display' => __('RRB Interval', 'rakam-reference-builder'),
        );
        return $schedules;
    }

    public static function schedule_runner() {
        if (class_exists('ActionScheduler')) {
            if (!as_next_scheduled_action(self::ACTION_HOOK)) {
                as_schedule_recurring_action(time() + 60, max(60, (int) get_option('rrb_interval_minutes', 5) * 60), self::ACTION_HOOK, array(), 'rrb');
            }
            return;
        }

        if (!wp_next_scheduled(self::ACTION_HOOK)) {
            wp_schedule_event(time() + 60, 'rrb_interval', self::ACTION_HOOK);
        }
    }

    public static function pause_queue() {
        update_option(self::PAUSE_OPTION, 1);
    }

    public static function resume_queue() {
        update_option(self::PAUSE_OPTION, 0);
        self::schedule_runner();
    }

    public static function is_paused() {
        return (bool) get_option(self::PAUSE_OPTION, 0);
    }

    public static function process_queue_item() {
        if (self::is_paused()) {
            return;
        }

        if (!self::acquire_lock()) {
            return;
        }

        $item = RRB_DB::get_next_queue_item();
        if (!$item) {
            self::release_lock();
            return;
        }

        RRB_DB::update_item($item->id, array(
            'status' => 'running',
            'last_run_at' => current_time('mysql'),
        ));

        $result = self::handle_item($item);

        if ($result['status'] === 'done') {
            RRB_DB::update_item($item->id, array(
                'status' => 'done',
                'last_error_message' => null,
                'result_json' => wp_json_encode($result['result']),
                'created_term_ids_json' => wp_json_encode($result['created_term_ids']),
                'attempts' => $item->attempts + 1,
            ));
        } else {
            RRB_DB::update_item($item->id, array(
                'status' => $result['status'],
                'last_error_message' => $result['error_message'],
                'attempts' => $item->attempts + 1,
            ));
        }

        self::release_lock();
    }

    private static function handle_item($item) {
        $dry_run = (bool) $item->dry_run;
        $force_refresh = (bool) $item->force_refresh;
        $retry_limit = (int) get_option('rrb_retry_count', 2);

        $validation = RRB_Parser::validate_url($item->behran_url);
        if (!$validation['valid']) {
            return array(
                'status' => 'error',
                'error_message' => $validation['message'],
            );
        }

        $response = RRB_Parser::fetch_and_parse($item->behran_url, array(
            'force_refresh' => $force_refresh,
        ));

        if ($response['status'] === 'retry') {
            if ($item->attempts + 1 <= $retry_limit) {
                RRB_DB::update_item($item->id, array(
                    'status' => 'queued',
                    'last_error_message' => $response['error_message'],
                ));
                return array(
                    'status' => 'queued',
                    'error_message' => $response['error_message'],
                );
            }
            return array(
                'status' => 'error',
                'error_message' => $response['error_message'],
            );
        }

        if ($response['status'] !== 'ok') {
            return array(
                'status' => 'error',
                'error_message' => $response['error_message'],
            );
        }

        $created_term_ids = array();
        if (!$dry_run) {
            $tag_result = RRB_Tags::create_and_attach_tags($item->product_id, $response['result']);
            if (!$tag_result['success']) {
                return array(
                    'status' => 'error',
                    'error_message' => $tag_result['message'],
                );
            }
            $created_term_ids = $tag_result['term_ids'];
            update_post_meta($item->product_id, '_rakam_ref_last_created_term_ids', wp_json_encode($created_term_ids));
            update_post_meta($item->product_id, '_rakam_ref_last_result_json', wp_json_encode($response['result']));
        }

        update_post_meta($item->product_id, '_rakam_ref_last_source_url', esc_url_raw($item->behran_url));

        return array(
            'status' => 'done',
            'result' => $response['result'],
            'created_term_ids' => $created_term_ids,
        );
    }

    private static function acquire_lock() {
        if (get_transient(self::LOCK_KEY)) {
            return false;
        }
        set_transient(self::LOCK_KEY, 1, 60 * 5);
        return true;
    }

    private static function release_lock() {
        delete_transient(self::LOCK_KEY);
    }
}
