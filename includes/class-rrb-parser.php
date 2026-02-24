<?php

if (!defined('ABSPATH')) {
    exit;
}

class RRB_Parser {
    public static function validate_source_text($text) {
        if (!is_string($text) || trim($text) === '') {
            return array('valid' => false, 'message' => 'متن رفرنس خالی است.');
        }

        return array('valid' => true);
    }

    public static function parse_source_text($text) {
        $normalized_text = str_replace("\r", '', wp_strip_all_tags((string) $text));
        $lines = preg_split('/\n+/', $normalized_text);
        $results = array();
        $current_brand = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $brand = self::normalize_brand($line);
            if ($brand['allowed']) {
                $current_brand = $brand;
                continue;
            }

            if (!$current_brand) {
                continue;
            }

            if (self::looks_like_brand_label($line)) {
                $current_brand = null;
                continue;
            }

            $codes = self::extract_codes($line);
            if (empty($codes)) {
                continue;
            }

            $results[] = array(
                'brand_label' => $current_brand['brand_label'],
                'brand_fa' => $current_brand['brand_fa'],
                'brand_en' => $current_brand['brand_en'],
                'codes' => $codes,
            );
        }

        $results = self::normalize_results($results);
        if (empty($results)) {
            return array('status' => 'error', 'error_message' => 'هیچ برند مجاز یا کد معتبری در متن پیدا نشد.');
        }

        return array('status' => 'ok', 'result' => array_values($results));
    }

    public static function fetch_and_parse($url, $args = array()) {
        $defaults = array(
            'force_refresh' => false,
        );
        $args = wp_parse_args($args, $defaults);

        $cache_enabled = (bool) get_option('rrb_cache_enabled', 1);
        $cache_key = 'rrb_cache_' . md5($url);
        if ($cache_enabled && !$args['force_refresh']) {
            $cached = get_transient($cache_key);
            if ($cached) {
                return array('status' => 'ok', 'result' => $cached);
            }
        }

        $response = self::request_page($url);

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            if (!$message) {
                $message = 'خطا در دریافت صفحه.';
            }
            return array('status' => 'retry', 'error_message' => $message);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code === 200) {
            $parsed = self::parse_html($body);
            if (!$parsed['success']) {
                return array('status' => 'error', 'error_message' => $parsed['message']);
            }
            if ($cache_enabled) {
                $ttl_days = (int) get_option('rrb_cache_ttl_days', 30);
                set_transient($cache_key, $parsed['result'], max(1, $ttl_days) * DAY_IN_SECONDS);
            }
            return array('status' => 'ok', 'result' => $parsed['result']);
        }

        if (in_array($code, array(429, 502, 503, 504), true)) {
            return array('status' => 'retry', 'error_message' => 'پاسخ موقت از سرور دریافت شد.');
        }

        if (in_array($code, array(404, 410), true)) {
            return array('status' => 'error', 'error_message' => 'صفحه یافت نشد.');
        }

        if ($code === 403) {
            return array('status' => 'error', 'error_message' => 'دسترسی به صفحه مسدود است.');
        }

        return array('status' => 'error', 'error_message' => 'خطای نامشخص در دریافت صفحه.');
    }

    private static function request_page($url) {
        $primary_args = array(
            'timeout' => 30,
            'redirection' => 5,
            'headers' => array(
                'User-Agent' => 'RakamReferenceBuilder/1.0; ' . home_url(),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7',
            ),
        );

        $response = wp_safe_remote_get($url, $primary_args);
        if (!is_wp_error($response)) {
            return $response;
        }

        if (self::is_external_http_blocked($response)) {
            $curl_response = self::request_with_curl($url, $primary_args);
            if (!is_wp_error($curl_response)) {
                return $curl_response;
            }
        }

        $fallback_args = $primary_args;
        $fallback_args['headers']['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
        $fallback_args['sslverify'] = false;
        return wp_safe_remote_get($url, $fallback_args);
    }

    private static function is_external_http_blocked($error) {
        if (!is_wp_error($error)) {
            return false;
        }

        $code = $error->get_error_code();
        $message = $error->get_error_message();

        if ($code === 'http_request_not_executed') {
            return true;
        }

        return strpos($message, 'HTTP request') !== false && strpos($message, 'blocked') !== false;
    }

    private static function request_with_curl($url, $args = array()) {
        if (!function_exists('curl_init')) {
            return new WP_Error('rrb_curl_unavailable', 'cURL در سرور فعال نیست.');
        }

        $timeout = isset($args['timeout']) ? (int) $args['timeout'] : 30;
        $headers = isset($args['headers']) ? (array) $args['headers'] : array();

        $formatted_headers = array();
        foreach ($headers as $name => $value) {
            $formatted_headers[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formatted_headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $body = curl_exec($ch);
        $status_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return new WP_Error('rrb_curl_failed', $curl_error ? $curl_error : 'خطا در درخواست cURL.');
        }

        return array(
            'response' => array(
                'code' => $status_code,
                'message' => '',
            ),
            'body' => $body,
            'headers' => array(),
            'cookies' => array(),
            'filename' => null,
        );
    }

    public static function parse_html($html) {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        if (!$loaded) {
            return self::parse_with_regex($html);
        }

        $xpath = new DOMXPath($doc);
        $heading_nodes = $xpath->query("//*[contains(normalize-space(text()), 'کد فنی سازندگان معتبر')]");
        if ($heading_nodes->length === 0) {
            return array('success' => false, 'message' => 'بخش رفرنس‌ها پیدا نشد');
        }

        $heading = $heading_nodes->item(0);
        $section_nodes = array();
        $current = $heading;
        while ($current = $current->nextSibling) {
            if ($current->nodeType === XML_ELEMENT_NODE) {
                $text = trim($current->textContent);
                if (mb_strpos($text, 'ابعاد') !== false) {
                    break;
                }
                $section_nodes[] = $current;
            }
        }

        if (empty($section_nodes)) {
            $section_nodes[] = $heading->parentNode;
        }

        $results = array();
        foreach ($section_nodes as $node) {
            $results = array_merge($results, self::parse_section_node($xpath, $node));
        }

        $results = self::normalize_results($results);
        if (empty($results)) {
            return array('success' => false, 'message' => 'بخش رفرنس‌ها پیدا نشد');
        }

        return array('success' => true, 'result' => array_values($results));
    }

    private static function parse_section_node($xpath, $node) {
        $results = array();
        $tables = $xpath->query('.//table', $node);
        if ($tables->length > 0) {
            foreach ($tables as $table) {
                $rows = $xpath->query('.//tr', $table);
                foreach ($rows as $row) {
                    $cells = $xpath->query('./th|./td', $row);
                    if ($cells->length >= 2) {
                        $brand_label = trim($cells->item(0)->textContent);
                        $codes_text = trim($cells->item(1)->textContent);
                        $codes = self::extract_codes($codes_text . ' ' . self::extract_links_text($cells->item(1)));
                        if ($brand_label && $codes) {
                            $results[] = self::format_brand_codes($brand_label, $codes);
                        }
                    }
                }
            }
        }

        $label_nodes = $xpath->query('.//strong|.//b|.//h4|.//h5|.//h6', $node);
        foreach ($label_nodes as $label_node) {
            $brand_label = trim($label_node->textContent);
            if (!$brand_label) {
                continue;
            }
            $codes = array();
            $sibling = $label_node->nextSibling;
            while ($sibling) {
                if ($sibling->nodeType === XML_ELEMENT_NODE && in_array(strtolower($sibling->nodeName), array('strong', 'b', 'h4', 'h5', 'h6'), true)) {
                    break;
                }
                $codes = array_merge($codes, self::extract_codes($sibling->textContent));
                $sibling = $sibling->nextSibling;
            }
            if (!empty($codes)) {
                $results[] = self::format_brand_codes($brand_label, $codes);
            }
        }

        return $results;
    }

    private static function extract_links_text($node) {
        $text = '';
        foreach ($node->getElementsByTagName('a') as $link) {
            $text .= ' ' . $link->textContent;
        }
        return $text;
    }


    private static function looks_like_brand_label($line) {
        if (preg_match('/[0-9]/u', $line)) {
            return false;
        }

        $plain = preg_replace('/[^\p{L}\s]/u', '', $line);
        return mb_strlen(trim($plain)) >= 2;
    }

    private static function extract_codes($text) {
        $text = wp_strip_all_tags($text);
        preg_match_all('/[A-Za-z0-9\-\/]{4,}/u', $text, $matches);
        $codes = array_map('trim', $matches[0]);
        $codes = array_filter($codes, function ($code) {
            return mb_strlen($code) >= 4;
        });
        $codes = array_map('strtoupper', $codes);
        return array_values(array_unique($codes));
    }

    private static function format_brand_codes($brand_label, $codes) {
        $normalized = self::normalize_brand($brand_label);
        return array(
            'brand_label' => $brand_label,
            'brand_fa' => $normalized['brand_fa'],
            'brand_en' => $normalized['brand_en'],
            'codes' => array_values(array_unique($codes)),
        );
    }

    private static function normalize_results($results) {
        $final = array();
        foreach ($results as $entry) {
            $key = md5($entry['brand_label']);
            if (!isset($final[$key])) {
                $final[$key] = $entry;
            } else {
                $final[$key]['codes'] = array_values(array_unique(array_merge($final[$key]['codes'], $entry['codes'])));
            }
        }
        return $final;
    }

    private static function normalize_brand($brand_label) {
        $normalized_label = trim(mb_strtolower((string) $brand_label));

        $mapping = apply_filters('rrb_brand_mapping', array(
            'دونالدسون' => array('brand_fa' => 'دونالدسون', 'brand_en' => 'donaldson', 'allowed' => true),
            'donaldson' => array('brand_fa' => 'دونالدسون', 'brand_en' => 'donaldson', 'allowed' => true),
            'ساکورا' => array('brand_fa' => 'ساکورا', 'brand_en' => 'sakura', 'allowed' => true),
            'sakura' => array('brand_fa' => 'ساکورا', 'brand_en' => 'sakura', 'allowed' => true),
            'مان' => array('brand_fa' => 'مان', 'brand_en' => 'mann', 'allowed' => true),
            'maan' => array('brand_fa' => 'مان', 'brand_en' => 'mann', 'allowed' => true),
            'mann' => array('brand_fa' => 'مان', 'brand_en' => 'mann', 'allowed' => true),
            'فلیدگارد' => array('brand_fa' => 'فلیدگارد', 'brand_en' => 'fleetguard', 'allowed' => true),
            'fleetguard' => array('brand_fa' => 'فلیدگارد', 'brand_en' => 'fleetguard', 'allowed' => true),
            'هنگست' => array('brand_fa' => 'هنگست', 'brand_en' => 'hengst', 'allowed' => true),
            'hengst' => array('brand_fa' => 'هنگست', 'brand_en' => 'hengst', 'allowed' => true),
        ));

        if (isset($mapping[$normalized_label])) {
            $mapped = $mapping[$normalized_label];
            $mapped['brand_en'] = self::format_english_brand_name($mapped['brand_en'] ?? '');

            return array_merge(
                array('brand_label' => $brand_label),
                $mapped
            );
        }

        return array(
            'brand_label' => $brand_label,
            'brand_fa' => $brand_label,
            'brand_en' => self::format_english_brand_name(sanitize_title($brand_label)),
            'allowed' => false,
        );
    }

    private static function format_english_brand_name($brand_name) {
        $normalized_name = trim(str_replace('-', ' ', (string) $brand_name));
        if ($normalized_name === '') {
            return '';
        }

        return ucwords(strtolower($normalized_name));
    }

    private static function parse_with_regex($html) {
        if (!preg_match('/کد فنی سازندگان معتبر(.*?)(ابعاد|$)/su', $html, $matches)) {
            return array('success' => false, 'message' => 'بخش رفرنس‌ها پیدا نشد');
        }

        $section = strip_tags($matches[1]);
        $lines = preg_split('/\r?\n/', $section);
        $results = array();
        foreach ($lines as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            list($brand_label, $codes_text) = array_map('trim', explode(':', $line, 2));
            $codes = self::extract_codes($codes_text);
            if ($brand_label && $codes) {
                $results[] = self::format_brand_codes($brand_label, $codes);
            }
        }

        if (empty($results)) {
            return array('success' => false, 'message' => 'بخش رفرنس‌ها پیدا نشد');
        }

        return array('success' => true, 'result' => array_values($results));
    }
}
