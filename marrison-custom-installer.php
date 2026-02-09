<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/custom_installer.php';

add_action('plugins_loaded', function () {
    if (!is_admin()) {
        return;
    }

    if (!current_user_can('activate_plugins')) {
        return;
    }

    $old = 'marrison-custom-installer/marrison-custom-installer.php';
    $new = 'marrison-custom-installer/custom_installer.php';

    $active = (array) get_option('active_plugins', []);
    $changed = false;

    $old_index = array_search($old, $active, true);
    if ($old_index !== false) {
        unset($active[$old_index]);
        $changed = true;
    }

    if (!in_array($new, $active, true)) {
        $active[] = $new;
        $changed = true;
    }

    if ($changed) {
        $active = array_values($active);
        update_option('active_plugins', $active);
        wp_clean_plugins_cache(true);
    }

    if (is_multisite()) {
        $network_active = (array) get_site_option('active_sitewide_plugins', []);
        $changed_network = false;

        if (isset($network_active[$old])) {
            unset($network_active[$old]);
            $changed_network = true;
        }

        if (!isset($network_active[$new])) {
            $network_active[$new] = time();
            $changed_network = true;
        }

        if ($changed_network) {
            update_site_option('active_sitewide_plugins', $network_active);
            wp_clean_plugins_cache(true);
        }
    }
}, 1);
