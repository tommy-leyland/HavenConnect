<?php
/**
 * Shortcode: [hcn_gallery]
 *
 * Reads post meta keys like:
 *   hcn_gallery_url_001
 *   hcn_gallery_url_002
 *   ...
 * and outputs a simple gallery.
 *
 * Usage:
 *   [hcn_gallery]
 *   [hcn_gallery columns="4" size="medium" class="my-gallery" link="file"]
 *   [hcn_gallery id="123"]
 */

add_shortcode('hcn_gallery', function ($atts) {
    $atts = shortcode_atts([
        'id'      => 0,          // post ID (defaults to current post)
        'columns' => 3,          // how many columns
        'size'    => 'large',    // used only for <img> class / styling hooks
        'class'   => 'hcn-gallery',
        'link'    => 'none',     // none|file
        'limit'   => 0,          // 0 = no limit
        'start'   => 1,          // minimum numeric suffix (e.g. 1 = 001)
        'end'     => 0,          // 0 = no max
        'lazy'    => '1',        // 1|0
    ], $atts, 'hcn_gallery');

    $post_id = (int) $atts['id'];
    if (!$post_id) {
        $post_id = get_the_ID();
    }
    if (!$post_id) {
        return '';
    }

    // Pull all meta for the post (single query), then filter by key pattern.
    $all_meta = get_post_meta($post_id);
    if (empty($all_meta) || !is_array($all_meta)) {
        return '';
    }

    $pattern = '/^hcn_gallery_url_(\d+)$/'; // captures the numeric suffix
    $items = [];

    foreach ($all_meta as $key => $vals) {
        if (!preg_match($pattern, $key, $m)) {
            continue;
        }

        $n = (int) $m[1];

        // Apply start/end filters if provided
        if ($n < (int)$atts['start']) {
            continue;
        }
        if ((int)$atts['end'] > 0 && $n > (int)$atts['end']) {
            continue;
        }

        // Meta values come back as arrays; take first
        $url = is_array($vals) ? (string) ($vals[0] ?? '') : (string) $vals;
        $url = trim($url);

        if (!$url) {
            continue;
        }

        // Basic URL safety
        $url = esc_url_raw($url);
        if (!$url) {
            continue;
        }

        $items[$n] = $url;
    }

    if (empty($items)) {
        return '';
    }

    ksort($items, SORT_NUMERIC);

    if ((int)$atts['limit'] > 0) {
        $items = array_slice($items, 0, (int)$atts['limit'], true);
    }

    $columns = max(1, min(8, (int)$atts['columns']));
    $wrap_class = sanitize_html_class($atts['class']);

    // Output
    ob_start();

    ?>
    <div class="<?php echo esc_attr($wrap_class); ?>" style="display:grid;gap:12px;grid-template-columns:repeat(<?php echo (int)$columns; ?>,minmax(0,1fr));">
        <?php foreach ($items as $n => $url): ?>
            <?php
            $img_attrs = [
                'src'   => $url,
                'alt'   => '',
                'class' => 'hcn-gallery__img hcn-gallery__img--' . sanitize_html_class($atts['size']),
            ];
            if ($atts['lazy'] === '1') {
                $img_attrs['loading'] = 'lazy';
                $img_attrs['decoding'] = 'async';
            }

            $img_html = '<img';
            foreach ($img_attrs as $k => $v) {
                $img_html .= ' ' . $k . '="' . esc_attr($v) . '"';
            }
            $img_html .= ' />';
            ?>

            <figure class="hcn-gallery__item" style="margin:0;">
                <?php if ($atts['link'] === 'file'): ?>
                    <a class="hcn-gallery__link" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">
                        <?php echo $img_html; ?>
                    </a>
                <?php else: ?>
                    <?php echo $img_html; ?>
                <?php endif; ?>
            </figure>
        <?php endforeach; ?>
    </div>
    <?php

    return ob_get_clean();
});

add_shortcode('hcn_cf_url', function($atts){
  $atts = shortcode_atts([
    'key' => '',
    'id'  => 0,
  ], $atts, 'hcn_cf_url');

  $key = trim((string)$atts['key']);
  if (!$key) return '';

  $post_id = (int)$atts['id'];
  if (!$post_id) $post_id = get_the_ID();
  if (!$post_id) return '';

  $val = get_post_meta($post_id, $key, true);
  return esc_url((string)$val);
});

add_shortcode('hcn_cf_img', function($atts){
  $atts = shortcode_atts([
    'key'   => '',
    'id'    => 0,
    'alt'   => '',
    'class' => '',
  ], $atts, 'hcn_cf_img');

  $key = trim((string)$atts['key']);
  if (!$key) return '';

  $post_id = (int)$atts['id'];
  if (!$post_id) $post_id = get_the_ID();
  if (!$post_id) return '';

  $url = get_post_meta($post_id, $key, true);
  $url = esc_url($url);
  if (!$url) return '';

  $alt = esc_attr($atts['alt']);
  $class = trim((string)$atts['class']);
  $class_attr = $class ? ' class="' . esc_attr($class) . '"' : '';

  return '<img src="' . $url . '" alt="' . $alt . '"' . $class_attr . ' loading="lazy" decoding="async">';
});