<?php

if (!defined('ABSPATH')) {
    exit;
}

class RRB_Parser {
    public static function validate_url($url) {
        $parts = wp_parse_url($url);
        if (empty($parts['host']) || empty($parts['path'])) {
            return array('valid' => false, 'message' => 'آدرس معتبر نیست.');
        }
        if (substr($parts['host'], -strlen('behranfilter.ir')) !== 'behranfilter.ir') {
            return array('valid' => false, 'message' => 'دامنه باید behranfilter.ir باشد.');
        }
        if (strpos($parts['path'], '/product/') === false) {
            return array('valid' => false, 'message' => 'مسیر باید شامل /product/ باشد.');
        }
        return array('valid' => true);
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

        $response = wp_remote_get($url, array(
            'timeout' => 20,
            'headers' => array(
                'User-Agent' => 'RakamReferenceBuilder/1.0; ' . home_url(),
            ),
        ));

        if (is_wp_error($response)) {
            return array('status' => 'retry', 'error_message' => 'خطا در دریافت صفحه.');
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
        $mapping = apply_filters('rrb_brand_mapping', array(
            'MANN' => array('brand_fa' => 'مان', 'brand_en' => 'mann'),
        ));

        if (isset($mapping[$brand_label])) {
            return $mapping[$brand_label];
        }

        return array(
            'brand_fa' => $brand_label,
            'brand_en' => sanitize_title($brand_label),
        );
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
