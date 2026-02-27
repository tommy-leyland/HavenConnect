<?php
if (!defined('ABSPATH')) exit;

/**
 * HavenConnect — Styled Admin Meta Box (Read-Only)
 * Creates a tabbed interface to display imported meta.
 */

add_action('add_meta_boxes', function () {
    add_meta_box(
        'hcn_property_meta',
        'Property Details',
        'hcn_render_property_meta_box',
        'hcn_property',
        'normal',
        'high'
    );
});

/**
 * Render the meta box UI
 */
function hcn_render_property_meta_box($post) {

    // Gather meta fields
    $fields = [
        'sleeps'        => get_post_meta($post->ID, 'sleeps', true),
        'bedrooms'      => get_post_meta($post->ID, 'bedrooms', true),
        'bathrooms'     => get_post_meta($post->ID, 'bathrooms', true),
        'beds'          => get_post_meta($post->ID, 'beds', true),

        'address_line1' => get_post_meta($post->ID, 'address_line1', true),
        'address_line2' => get_post_meta($post->ID, 'address_line2', true),
        'state'         => get_post_meta($post->ID, 'state', true),
        'city'          => get_post_meta($post->ID, 'city', true),
        'postcode'      => get_post_meta($post->ID, 'postcode', true),
        'latitude'      => get_post_meta($post->ID, 'latitude', true),
        'longitude'     => get_post_meta($post->ID, 'longitude', true),

        '_hcn_featured_image_url' => get_post_meta($post->ID, '_hcn_featured_image_url', true),
    ];
    ?>

    <style>
        .hcn-tabs { margin-top: 15px; }
        .hcn-tabs-nav { margin-bottom: 10px; }
        .hcn-tabs-nav a {
            display: inline-block;
            padding: 8px 14px;
            background: #f1f1f1;
            margin-right: 5px;
            border-radius: 3px;
            text-decoration: none;
            color: #333;
        }
        .hcn-tabs-nav a.active {
            background: #007cba;
            color: #fff;
        }
        .hcn-tab-pane {
            display: none;
            padding: 15px;
            background: #fff;
            border: 1px solid #ddd;
        }
        .hcn-tab-pane.active { display: block; }
        .hcn-meta-table td { padding: 6px 10px; vertical-align: top; }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function(){
            const tabs = document.querySelectorAll('.hcn-tabs-nav a');
            tabs.forEach(tab => {
                tab.addEventListener('click', function(e){
                    e.preventDefault();
                    tabs.forEach(t => t.classList.remove('active'));
                    document.querySelectorAll('.hcn-tab-pane').forEach(p => p.classList.remove('active'));
                    tab.classList.add('active');
                    document.querySelector(tab.getAttribute('href')).classList.add('active');
                });
            });

            // Auto-activate first tab
            if (tabs[0]) tabs[0].click();
        });
    </script>

    <div class="hcn-tabs">

        <div class="hcn-tabs-nav">
            <a href="#hcn-tab-basics">Basics</a>
            <a href="#hcn-tab-location">Location</a>
            <a href="#hcn-tab-descriptions">Descriptions</a>
            <a href="#hcn-tab-images">Images</a>
        </div>

        <!-- BASICS TAB -->
        <div id="hcn-tab-basics" class="hcn-tab-pane">
            <table class="hcn-meta-table">

                <?php
                // Helper: render text field + lock
                function hcn_render_editable_row($label, $meta_key, $post_id) {
                    $value = get_post_meta($post_id, $meta_key, true);
                    $locked = get_post_meta($post_id, "{$meta_key}_locked", true);
                    ?>

                    <tr>
                        <td><strong><?= esc_html($label); ?>:</strong></td>
                        <td>
                            <input 
                                type="text"
                                name="hcn_edit[<?= esc_attr($meta_key); ?>]"
                                value="<?= esc_attr($value); ?>"
                                style="width:200px;"
                            >
                            <label style="margin-left:10px;">
                                <input 
                                    type="checkbox"
                                    name="hcn_edit[<?= esc_attr($meta_key); ?>_locked]"
                                    value="1"
                                    <?= checked($locked, "1", false); ?>
                                >
                                Lock (prevent sync overwrite)
                            </label>
                        </td>
                    </tr>

                <?php
                }
                ?>

                <?php hcn_render_editable_row("Guests",     "sleeps",    $post->ID); ?>
                <?php hcn_render_editable_row("Bedrooms",   "bedrooms",  $post->ID); ?>
                <?php hcn_render_editable_row("Bathrooms",  "bathrooms", $post->ID); ?>
                <?php hcn_render_editable_row("Beds",       "beds",      $post->ID); ?>

            </table>
        </div>

        <!-- LOCATION TAB -->
        <div id="hcn-tab-location" class="hcn-tab-pane">
            <table class="hcn-meta-table">
                <tr><td><strong>Address 1:</strong></td><td><?= esc_html($fields['address_line1']); ?></td></tr>
                <tr><td><strong>Address 2:</strong></td><td><?= esc_html($fields['address_line2']); ?></td></tr>
                <tr><td><strong>State:</strong></td><td><?= esc_html($fields['state']); ?></td></tr>
                <tr><td><strong>City:</strong></td><td><?= esc_html($fields['city']); ?></td></tr>
                <tr><td><strong>Postcode:</strong></td><td><?= esc_html($fields['postcode']); ?></td></tr>
                <tr><td><strong>Latitude:</strong></td><td><?= esc_html($fields['latitude']); ?></td></tr>
                <tr><td><strong>Longitude:</strong></td><td><?= esc_html($fields['longitude']); ?></td></tr>
            </table>
        </div>

        <!-- DESCRIPTIONS TAB -->
        <div id="hcn-tab-descriptions" class="hcn-tab-pane">

            <style>
                .hcn-textarea {
                    width: 100%;
                    max-width: 100%;
                    min-height: 110px;
                    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
                    font-size: 12px;
                    line-height: 1.35;
                }
                .hcn-desc-row td { padding: 10px 10px; }
                .hcn-desc-label { width: 180px; }
            </style>

            <?php
            // Helper: render textarea field + lock
            if (!function_exists('hcn_render_editable_textarea_row')) {
                function hcn_render_editable_textarea_row($label, $meta_key, $post_id) {
                    $value  = (string) get_post_meta($post_id, $meta_key, true);
                    $locked = get_post_meta($post_id, "{$meta_key}_locked", true);
                    ?>
                    <tr class="hcn-desc-row">
                        <td class="hcn-desc-label"><strong><?= esc_html($label); ?>:</strong></td>
                        <td>
                            <textarea
                                class="hcn-textarea"
                                name="hcn_edit[<?= esc_attr($meta_key); ?>]"
                            ><?= esc_textarea($value); ?></textarea>

                            <label style="display:inline-block;margin-top:6px;">
                                <input
                                    type="checkbox"
                                    name="hcn_edit[<?= esc_attr($meta_key); ?>_locked]"
                                    value="1"
                                    <?= checked($locked, "1", false); ?>
                                >
                                Lock (prevent sync overwrite)
                            </label>
                        </td>
                    </tr>
                    <?php
                }
            }
            ?>

            <table class="hcn-meta-table" style="width:100%;">
                <?php hcn_render_editable_textarea_row("The space",        "property_space",         $post->ID); ?>
                <?php hcn_render_editable_textarea_row("Neighbourhood",    "property_neighbourhood", $post->ID); ?>
                <?php hcn_render_editable_textarea_row("Access",           "property_access",        $post->ID); ?>
                <?php hcn_render_editable_textarea_row("Getting around",   "property_transit",       $post->ID); ?>
                <?php hcn_render_editable_textarea_row("Interaction",      "property_interaction",   $post->ID); ?>
                <?php hcn_render_editable_textarea_row("Notes",            "property_notes",         $post->ID); ?>
            </table>

        </div>

        <!-- IMAGES TAB -->
        <div id="hcn-tab-images" class="hcn-tab-pane">

            <?php
            $post_id = $post->ID;

            // 1. Primary source — consolidated array field
            $gallery = get_post_meta($post_id, '_hcn_gallery_urls', true);
            $urls = [];

            if (is_array($gallery) && !empty($gallery)) {
                $urls = $gallery;
            }

            // 2. Fallback — discrete fields (if array not present)
            if (empty($urls)) {
                for ($i = 1; $i <= 200; $i++) {
                    $key = 'hcn_gallery_url_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
                    $u   = get_post_meta($post_id, $key, true);
                    if (!empty($u)) $urls[] = $u;
                }
            }
            ?>

            <style>
                .hcn-image-grid {
                    display:flex;
                    flex-wrap:wrap;
                    gap:12px;
                    margin-top:10px;
                }
                .hcn-image-box {
                    width:140px;
                    text-align:center;
                }
                .hcn-image-box img {
                    width:140px;
                    height:100px;
                    object-fit:cover;
                    border:1px solid #ddd;
                    border-radius:3px;
                    background:#fafafa;
                }
                .hcn-image-url {
                    font-size:11px;
                    margin-top:4px;
                    word-break:break-all;
                }
            </style>

            <p><strong>Featured Image</strong></p>
            <?php if (!empty($fields['_hcn_featured_image_url'])) : ?>
                <img width="140" height="100" style="object-fit:cover;border:1px solid #ddd;"
                    src="<?= esc_url($fields['_hcn_featured_image_url']); ?>">
            <?php else : ?>
                <p>No featured image set.</p>
            <?php endif; ?>

            <hr style="margin:18px 0;">

            <p><strong>All Imported Gallery Images (<?= count($urls); ?>)</strong></p>

            <div class="hcn-image-grid">

                <?php if (!empty($urls)) : ?>
                    <?php foreach ($urls as $url) : ?>
                        <?php $esc = esc_url($url); ?>
                        <div class="hcn-image-box">
                            <a href="<?= $esc; ?>" target="_blank">
                                <img src="<?= $esc; ?>">
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p>No gallery images found for this property.</p>
                <?php endif; ?>

            </div>
        </div>

    </div>

    <?php
}