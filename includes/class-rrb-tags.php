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
            return '';
        }

        $results = json_decode($results_json, true);
        if (empty($results) || !is_array($results)) {
            return '';
        }

        $links = array();
        foreach ($results as $entry) {
            $brand_name = ucwords(strtolower((string) ($entry['brand_en'] ?? '')));
            foreach (($entry['codes'] ?? array()) as $code) {
                $code = strtoupper(trim((string) $code));
                if ($code === '') {
                    continue;
                }

                $label = $brand_name . ': ' . $code;
                $slug = self::generate_slug($code, (string) ($entry['brand_en'] ?? ''));
                $term = get_term_by('slug', $slug, 'product_tag');

                if ($term) {
                    $links[] = '<a class="rrb-reference-tag" href="' . esc_url(get_term_link($term)) . '">' . esc_html($label) . '</a>';
                } else {
                    $links[] = '<span class="rrb-reference-tag is-static">' . esc_html($label) . '</span>';
                }
            }
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
