<?php
if (!defined('ABSPATH')) exit;

/**
 * Persist editable meta and per-field locks from the HavenConnect admin UI.
 * Looks for POST array: hcn_edit[<meta_key>] and hcn_edit[<meta_key>_locked]
 */
add_action('save_post_hcn_property', function ($post_id) {

    // Don't run on autosave, revisions, or without capabilities
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (empty($_POST['hcn_edit']) || !is_array($_POST['hcn_edit'])) return;

    // Nonce is optional if your metabox adds one; add/verify here if you do.
    // if (empty($_POST['hcn_meta_nonce']) || !wp_verify_nonce($_POST['hcn_meta_nonce'], 'hcn_meta_save')) return;

    foreach ($_POST['hcn_edit'] as $key => $value) {

        // Handle lock flags
        if (str_ends_with($key, '_locked')) {
            // Explicitly set lock when checkbox is ticked
            update_post_meta($post_id, $key, '1');
            continue;
        }

        // Save the value (text inputs)
        update_post_meta($post_id, $key, sanitize_text_field($value));

        // If the matching *_locked checkbox was NOT sent, remove the lock
        $lock_key = "{$key}_locked";
        if (!isset($_POST['hcn_edit'][$lock_key])) {
            delete_post_meta($post_id, $lock_key);
        }
    }
});