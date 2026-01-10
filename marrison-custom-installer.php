<?php
/**
 * Plugin Name: Marrison Custom Installer
 * Plugin URI:  https://github.com/marrisonlab/marrison-custom-installer
 * Description: This plugin is used to install plugins from a personal repository.
 * Version: 6.0
 * Author: Angelo Marra
 * Author URI:  https://marrisonlab.com
 * Text Domain: marrison-custom-installer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Marrison_Custom_Installer {

    private $github_repo = 'marrisonlab/marrison-custom-installer';
    private $slug = 'marrison-custom-installer';

    public function __construct() {
        // Self-update hooks
        add_filter('site_transient_update_plugins', [$this, 'check_for_updates']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        
        // Admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Links
        add_filter('plugin_action_links', [$this, 'add_action_links'], 10, 2);
        add_filter('plugin_row_meta', [$this, 'add_row_meta'], 10, 2);
        
        // Clear cache on force update
        add_action('delete_site_transient_update_plugins', [$this, 'force_clear_github_cache']);
    }

    public function add_admin_menu() {
        add_menu_page(
            'Marrison Installer',
            'Installer',
            'manage_options',
            'marrison-installer',
            [$this, 'admin_page'],
            'dashicons-download',
            30
        );
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Marrison Custom Installer</h1>
            <p>Welcome to Marrison Custom Installer.</p>
            <p>Version: <?php echo esc_html($this->get_plugin_version()); ?></p>
        </div>
        <?php
    }

    private function get_plugin_version() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_data = get_plugin_data(__FILE__);
        return $plugin_data['Version'];
    }

    /* ===================== SELF UPDATE ===================== */

    public function force_clear_github_cache() {
        delete_transient('marrison_installer_github_version');
        delete_transient('marrison_installer_github_info');
    }

    public function check_for_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote_version = $this->get_github_version();

        if ($remote_version) {
            $plugin_file = plugin_basename(__FILE__);
            $current_version = $this->get_plugin_version();

            if (version_compare($current_version, $remote_version, '<')) {
                $obj = new stdClass();
                $obj->slug = $this->slug;
                $obj->new_version = $remote_version;
                $obj->url = 'https://github.com/' . $this->github_repo;
                $obj->package = 'https://github.com/' . $this->github_repo . '/archive/refs/tags/v' . $remote_version . '.zip';
                $obj->requires = '6.0';
                $obj->requires_php = '7.4';
                
                $transient->response[$plugin_file] = $obj;
            }
        }

        return $transient;
    }

    private function get_github_version() {
        $cached = get_transient('marrison_installer_github_version');
        if ($cached !== false) return $cached;

        $response = wp_remote_get('https://api.github.com/repos/' . $this->github_repo . '/releases/latest', [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/MarrisonCustomInstaller'
            ]
        ]);

        if (is_wp_error($response)) return false;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['tag_name'])) return false;

        $version = str_replace('v', '', $body['tag_name']);
        set_transient('marrison_installer_github_version', $version, 12 * HOUR_IN_SECONDS);

        return $version;
    }

    public function plugin_info($false, $action, $args) {
        if ($action !== 'plugin_information') return $false;
        if ($args->slug !== $this->slug) return $false;

        $response = wp_remote_get('https://raw.githubusercontent.com/' . $this->github_repo . '/main/readme.txt', [
            'timeout' => 10
        ]);

        if (is_wp_error($response)) return $false;

        $readme = wp_remote_retrieve_body($response);
        
        $info = new stdClass();
        $info->name = 'Marrison Custom Installer';
        $info->slug = $this->slug;
        $info->version = $this->get_github_version();
        $info->author = 'Angelo Marra';
        $info->homepage = 'https://github.com/' . $this->github_repo;
        $info->download_url = 'https://github.com/' . $this->github_repo . '/archive/refs/tags/v' . $info->version . '.zip';
        
        // Simple parsing for description
        $description = 'Marrison Custom Installer';
        if (preg_match('/== Description ==\s*(.*?)\s*== /s', $readme, $match)) {
            $description = trim($match[1]);
        }
        
        $info->sections = [
            'description' => $description,
        ];

        return $info;
    }

    /* ===================== UI LINKS ===================== */

    public function add_action_links($actions, $plugin_file) {
        if (strpos($plugin_file, basename(__FILE__)) === false) {
            return $actions;
        }
        $settings_link = '<a href="' . admin_url('admin.php?page=marrison-installer') . '">' . __('Settings', 'marrison-custom-installer') . '</a>';
        array_unshift($actions, $settings_link);
        return $actions;
    }

    public function add_row_meta($links, $file) {
        if (strpos($file, basename(__FILE__)) !== false) {
            $row_meta = [
                'docs' => '<a href="https://github.com/' . $this->github_repo . '" target="_blank">GitHub</a>',
            ];
            return array_merge($links, $row_meta);
        }
        return $links;
    }
}

new Marrison_Custom_Installer();
