<?php

if (!defined('ABSPATH')) {
    exit;
}

class RRB_Tags {
    public static function render_reference_links_for_product($product_id) {
        $product_id = absint($product_id);
        if (!$product_id) {
            return '';
        }

        $results_json = get_post_meta($product_id, '_rakam_ref_last_result_json', true);
        if (!$results_json) {
            return self::render_links_from_terms($product_id);
        }

        $results = self::normalize_results_payload(json_decode($results_json, true));
        if (empty($results)) {
            return self::render_links_from_terms($product_id);
        }

        $product_terms = wp_get_post_terms($product_id, 'product_tag');
        $terms_by_slug = array();
        if (!is_wp_error($product_terms)) {
            foreach ($product_terms as $term) {
                $terms_by_slug[$term->slug] = $term;
            }
        }

        $links = array();
        foreach ($results as $entry) {
            $brand_name = trim((string) ($entry['brand_fa'] ?? ''));
            if ($brand_name === '') {
                $brand_name = ucwords(strtolower((string) ($entry['brand_en'] ?? '')));
            }

            foreach ($entry['codes'] as $code) {
                $code = strtoupper(trim((string) $code));
                if ($code === '') {
                    continue;
                }

                $slug = self::generate_slug($code, (string) ($entry['brand_en'] ?? ''));
                $term = $terms_by_slug[$slug] ?? get_term_by('slug', $slug, 'product_tag');
                $link = $term ? get_term_link($term) : get_term_link($slug, 'product_tag');

                if (is_wp_error($link)) {
                    continue;
                }

                $links[] = '<a class="rrb-reference-tag" href="' . esc_url($link) . '"><span class="rrb-reference-tag__name">' . esc_html($brand_name) . '</span><span class="rrb-reference-tag__code"><span class="rrb-reference-tag__icon" aria-hidden="true">🔖</span>' . esc_html($code) . '</span></a>';
            }
        }

        if (empty($links)) {
            return self::render_links_from_terms($product_id);
        }

        return '<div class="rrb-reference-links">' . implode('', $links) . '</div>';
    }

    private static function render_links_from_terms($product_id) {
        $product_terms = wp_get_post_terms($product_id, 'product_tag');
        if (empty($product_terms) || is_wp_error($product_terms)) {
            return '';
        }

        $links = array();
        foreach ($product_terms as $term) {
            $link = get_term_link($term);
            if (is_wp_error($link)) {
                continue;
            }

            $links[] = '<a class="rrb-reference-tag" href="' . esc_url($link) . '"><span class="rrb-reference-tag__name">' . esc_html($term->name) . '</span></a>';
        }

        if (empty($links)) {
            return '';
        }

        return '<div class="rrb-reference-links">' . implode('', $links) . '</div>';
    }

    public static function create_and_attach_tags($product_id, $result) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return array('success' => false, 'message' => 'محصول یافت نشد.');
        }

        $template = get_option('rrb_tag_template', 'فیلتر {CODE} {BRAND_FA} {BRAND_EN}');
        $term_ids = array();

        foreach ($result as $entry) {
            foreach ($entry['codes'] as $code) {
                $name = self::apply_template($template, $code, $entry['brand_fa'], $entry['brand_en']);
                $slug = self::generate_slug($code, $entry['brand_en']);
                $term = self::find_existing_term($slug, $name);
                if (!$term) {
                    $created = wp_insert_term($name, 'product_tag', array('slug' => $slug));
                    if (is_wp_error($created)) {
                        $existing_term_id = (int) $created->get_error_data('term_exists');
                        if ($existing_term_id > 0) {
                            $term_ids[] = $existing_term_id;
                            continue;
                        }
                        return array('success' => false, 'message' => 'خطا در ساخت تگ.');
                    }
                    $term_id = (int) $created['term_id'];
                } else {
                    $term_id = (int) $term->term_id;
                }
                $term_ids[] = $term_id;
            }
        }

        $term_ids = array_values(array_unique($term_ids));
        if (!empty($term_ids)) {
            wp_set_object_terms($product_id, $term_ids, 'product_tag', true);
        }

        return array('success' => true, 'term_ids' => $term_ids);
    }

    public static function undo_tags($product_id) {
        $ids_json = get_post_meta($product_id, '_rakam_ref_last_created_term_ids', true);
        $term_ids = json_decode($ids_json, true);
        if (empty($term_ids) || !is_array($term_ids)) {
            return array('success' => false, 'message' => 'موردی برای بازگشت وجود ندارد.');
        }
        wp_remove_object_terms($product_id, $term_ids, 'product_tag');
        delete_post_meta($product_id, '_rakam_ref_last_created_term_ids');
        return array('success' => true);
    }


    private static function normalize_results_payload($results) {
        if (empty($results) || !is_array($results)) {
            return array();
        }

        $normalized = array();
        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $codes = $entry['codes'] ?? array();
            if (is_string($codes)) {
                $codes = preg_split('/[\s,|؛;]+/u', $codes);
            }

            if (!is_array($codes)) {
                $codes = array();
            }

            $clean_codes = array();
            foreach ($codes as $code) {
                $code = strtoupper(trim((string) $code));
                if ($code === '') {
                    continue;
                }
                $clean_codes[] = $code;
            }

            $clean_codes = array_values(array_unique($clean_codes));
            if (empty($clean_codes)) {
                continue;
            }

            $entry['codes'] = $clean_codes;
            $normalized[] = $entry;
        }

        return $normalized;
    }

    private static function find_existing_term($slug, $name) {
        $term = get_term_by('slug', $slug, 'product_tag');
        if ($term) {
            return $term;
        }

        return get_term_by('name', $name, 'product_tag');
    }

    private static function apply_template($template, $code, $brand_fa, $brand_en) {
        $replacements = array(
            '{CODE}' => $code,
            '{BRAND_FA}' => $brand_fa,
            '{BRAND_EN}' => $brand_en,
        );
        return trim(str_replace(array_keys($replacements), array_values($replacements), $template));
    }

    private static function generate_slug($code, $brand_en) {
        $slug = sanitize_title(trim($brand_en . ' ' . $code));
        if ($slug !== '') {
            return $slug;
        }

        return sanitize_title($code);
    }
}
