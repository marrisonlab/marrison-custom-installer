<?php
/**
 * Plugin Name: Marrison Custom Installer
 * Plugin URI:  https://github.com/marrisonlab/marrison-custom-installer
 * Description: This plugin is used to install plugins from a personal repository.
 * Version: 1.8
 * Author: Angelo Marra
 * Author URI:  https://marrisonlab.com
 */

class Marrison_Custom_Installer {

    private $repo_url = '';
    private $cache_duration = 6 * HOUR_IN_SECONDS;

    public function __construct() {
        add_filter('site_transient_update_plugins', [$this, 'check_for_updates']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_marrison_install_plugin', [$this, 'install_plugin']);
        add_action('admin_post_marrison_bulk_install', [$this, 'bulk_install']);
        add_action('admin_post_marrison_clear_cache', [$this, 'clear_cache']);
        add_action('admin_post_marrison_save_repo_url', [$this, 'save_repo_url']);
        add_action('admin_post_marrison_force_check_mci', [$this, 'force_check_mci']);
        add_action('admin_post_marrison_download_repo_file', [$this, 'download_repo_file']);
        
        add_action('wp_ajax_marrison_install_plugin_ajax', [$this, 'install_plugin_ajax']);
        add_action('wp_ajax_marrison_bulk_install_ajax', [$this, 'bulk_install_ajax']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        add_filter('plugin_action_links', [$this, 'add_marrison_action_links'], 10, 2);
        add_filter('plugin_row_meta', [$this, 'add_plugin_row_meta'], 10, 2);
        
        add_action('admin_menu', [$this, 'add_menu_notification_badge'], 999);
        add_action('admin_head', [$this, 'add_menu_badge_styles']);
        add_action('admin_init', [$this, 'check_available_installs']);
        
        add_filter('auto_update_plugin', [$this, 'auto_update_specific_plugins'], 10, 2);
        add_action('delete_site_transient_update_plugins', [$this, 'force_clear_github_cache']);
    }

    public function force_clear_github_cache() {
        delete_transient('marrison_installer_github_version');
    }

    public function auto_update_specific_plugins($update, $item) {
        if (isset($item->slug) && $item->slug === 'marrison-custom-installer') {
            return true;
        }
        return $update;
    }

    public function check_available_installs() {
        $installs = $this->get_available_installs();
        $plugins = get_plugins();
        $update_count = 0;
        
        foreach ($installs as $u) {
            foreach ($plugins as $file => $data) {
                $slug = dirname($file);
                if ($slug === '.' || $slug === '') $slug = basename($file, '.php');
                
                if ($slug === $u['slug'] && version_compare($data['Version'], $u['version'], '<')) {
                    $update_count++;
                    break;
                }
            }
        }
        
        update_option('marrison_available_installs_count', $update_count);
    }

    public function add_menu_notification_badge() {
        $update_count = get_option('marrison_available_installs_count', 0);
        
        if ($update_count > 0) {
            global $menu;
            
            foreach ($menu as $key => $item) {
                if (isset($item[2]) && $item[2] === 'marrison-installer') {
                    $menu[$key][0] .= ' <span class="marrison-install-badge awaiting-mod count-' . $update_count . '">' . $update_count . '</span>';
                    break;
                }
            }
        }
    }

    public function add_menu_badge_styles() {
        ?>
        <style>
        #toplevel_page_marrison-installer .wp-menu-image img {
            display: none;
        }
        #toplevel_page_marrison-installer .wp-menu-image {
            background-color: currentColor !important;
            -webkit-mask-image: url('<?php echo plugin_dir_url(__FILE__) . 'icon.svg'; ?>');
            mask-image: url('<?php echo plugin_dir_url(__FILE__) . 'icon.svg'; ?>');
            -webkit-mask-repeat: no-repeat;
            mask-repeat: no-repeat;
            -webkit-mask-position: center;
            mask-position: center;
            -webkit-mask-size: 20px auto;
            mask-size: 20px auto;
        }

        .marrison-install-badge {
            display: inline-block;
            background: linear-gradient(135deg, #d63638 0%, #c72c2f 100%);
            color: #fff;
            font-size: 9px;
            line-height: 17px;
            font-weight: 700;
            margin: 1px 0 0 2px;
            vertical-align: top;
            border-radius: 10px;
            z-index: 26;
            min-width: 7px;
            padding: 0 6px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(214, 54, 56, 0.3);
        }
        
        #adminmenu .marrison-install-badge {
            position: relative;
            top: -1px;
            left: 2px;
        }
        
        #adminmenu .wp-submenu a[href="admin.php?page=marrison-installer"] {
            position: relative;
        }
        
        #adminmenu .wp-submenu a[href="admin.php?page=marrison-installer"]:after {
            content: "";
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 8px;
            height: 8px;
            background-color: #d63638;
            border-radius: 50%;
            display: <?php echo get_option('marrison_available_installs_count', 0) > 0 ? 'block' : 'none'; ?>;
            box-shadow: 0 0 0 2px rgba(214, 54, 56, 0.2);
        }
        </style>
        <?php
    }

    private function get_available_installs() {
        $custom_repo_url = get_option('marrison_repo_url');
        $repo_url = !empty($custom_repo_url) ? trailingslashit($custom_repo_url) : $this->repo_url;

        if (empty($repo_url)) return [];

        $cached = get_transient('marrison_available_installs');
        
        if ($cached !== false && is_array($cached)) {
            $is_clean = true;
            foreach ($cached as $u) {
                if (isset($u['name']) && (strpos($u['name'], '/i\'') !== false || strpos($u['name'], '$') !== false)) {
                    $is_clean = false;
                    break;
                }
            }
            if ($is_clean) return $cached;
        }

        $response = wp_remote_get($repo_url . 'index.php', ['timeout' => 15]);
        if (is_wp_error($response)) return [];

        $installs = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($installs)) return [];

        $installs = array_filter($installs, function($u) {
            if (!isset($u['slug'])) return false;
            if (isset($u['name']) && (strpos($u['name'], '$') !== false || strpos($u['name'], '/i\'') !== false)) return false;
            if (isset($u['version']) && strpos($u['version'], '$') !== false) return false;
            return true;
        });

        $installs = array_values($installs);

        set_transient('marrison_available_installs', $installs, $this->cache_duration);
        return $installs;
    }

    public function check_for_updates($transient) {
        if (!is_object($transient)) $transient = new stdClass();
        
        if (!isset($transient->response)) $transient->response = [];
        if (!isset($transient->no_update)) $transient->no_update = [];
        if (!isset($transient->checked)) $transient->checked = [];

        $this->check_self_update($transient);

        return $transient;
    }

    private function check_self_update($transient) {
        $plugin_file = plugin_basename(__FILE__);
        $plugins = get_plugins();

        if (!isset($plugins[$plugin_file])) return;

        $installed = $plugins[$plugin_file]['Version'];
        $remote = $this->get_github_version();

        $item = (object)[
            'id'          => 'marrison-custom-installer',
            'slug'        => 'marrison-custom-installer',
            'plugin'      => $plugin_file,
            'new_version' => $remote,
            'url'         => 'https://github.com/marrisonlab/marrison-custom-installer',
            'package'     => 'https://github.com/marrisonlab/marrison-custom-installer/archive/refs/tags/v' . $remote . '.zip',
            'tested'      => '6.9',
            'requires_php' => '7.4',
            'icons'       => [],
            'banners'     => [],
            'banners_rtl' => [],
            'compatibility' => new stdClass(),
        ];

        if (version_compare($installed, $remote, '<')) {
            $transient->response[$plugin_file] = $item;
        } else {
            $transient->no_update[$plugin_file] = $item;
        }

        $transient->checked[$plugin_file] = $installed;
    }

    private function get_github_version() {
        $cached = get_transient('marrison_installer_github_version');
        if ($cached !== false) return $cached;

        $response = wp_remote_get('https://api.github.com/repos/marrisonlab/marrison-custom-installer/releases/latest', [
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
        set_transient('marrison_installer_github_version', $version, 6 * HOUR_IN_SECONDS);

        return $version;
    }

    private function find_plugin_file($slug) {
        foreach (get_plugins() as $file => $data) {
            $dir = dirname($file);
            if ($dir === '.' || $dir === '') $dir = basename($file, '.php');
            if ($dir === $slug) return $file;
        }
        return null;
    }

    public function plugin_info($false, $action, $args) {
        if ($action !== 'plugin_information') return $false;
        
        if ($args->slug !== 'marrison-custom-installer') return $false;

        $response = wp_remote_get('https://raw.githubusercontent.com/marrisonlab/marrison-custom-installer/stable/readme.txt', [
            'timeout' => 10
        ]);

        if (is_wp_error($response)) return $false;

        $readme = wp_remote_retrieve_body($response);
        if (empty($readme)) return $false;

        return $this->parse_readme($readme);
    }

    private function parse_readme($readme) {
        $info = new stdClass();
        
        if (preg_match('/== Description ==\s*(.*?)\s*== /s', $readme, $match)) {
            $info->description = $this->parsedown(trim($match[1]));
        } else {
            $info->description = '';
        }

        if (preg_match('/== Changelog ==\s*(.*?)$/s', $readme, $match)) {
            $info->changelog = $this->parsedown(trim($match[1]));
        } else {
            $info->changelog = '';
        }

        // Parse Tested up to
        if (preg_match('/Tested up to:\s*([0-9\.]+)/i', $readme, $match)) {
            $tested_wp = trim($match[1]);
        } else {
            $tested_wp = '6.4';
        }

        $github_version = $this->get_github_version();
        $info->name = 'Marrison Custom Installer';
        $info->slug = 'marrison-custom-installer';
        $info->version = $github_version ? $github_version : '1.0.0';
        $info->author = 'Angelo Marra';
        $info->author_profile = 'https://marrisonlab.com';
        $info->plugin_url = 'https://github.com/marrisonlab/marrison-custom-installer';
        $info->download_url = $info->version ? 'https://github.com/marrisonlab/marrison-custom-installer/archive/refs/tags/v' . $info->version . '.zip' : '';
        $info->requires_php = '7.4';
        $info->requires = '5.0';
        $info->tested = $tested_wp;
        $info->last_updated = current_time('mysql');
        $info->homepage = 'https://github.com/marrisonlab/marrison-custom-installer';
        $info->active_installs = 0;
        $info->rating = 100;
        $info->ratings = array(5 => 100);
        $info->num_ratings = 0;
        $info->support_url = 'https://github.com/marrisonlab/marrison-custom-installer/issues';
        $info->sections = array(
            'description' => $info->description ? $info->description : 'Plugin per aggiornamenti personalizzati',
            'changelog' => $info->changelog ? $info->changelog : 'Consultare il repository GitHub'
        );
        $info->banners = array();

        return $info;
    }

    private function parsedown($text) {
        // Convert headers = Version = to <h4>
        $text = preg_replace('/^= (.*?) =/m', '<h4>$1</h4>', $text);
        
        // Convert * items to list items
        $text = preg_replace('/^\* (.*?)$/m', '<li>$1</li>', $text);
        
        // Wrap adjacent <li> in <ul>
        $text = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $text);
        
        // Fix multiple <ul> wrapping (simple cleanup)
        $text = str_replace('</ul><ul>', '', $text);
        
        // Convert double newlines to paragraphs for other text
        $text = wpautop($text);
        
        return $text;
    }

    public function add_marrison_action_links($actions, $plugin_file) {
        if (strpos($plugin_file, 'custom_installer.php') === false && strpos($plugin_file, 'marrison') === false) {
            return $actions;
        }

        $actions['marrison_settings'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=marrison-installer')),
            esc_html__('Impostazioni', 'marrison-custom-installer')
        );

        return $actions;
    }

    public function add_plugin_row_meta($links, $file) {
        if (strpos($file, 'custom_installer.php') !== false || strpos($file, 'marrison-custom-installer') !== false) {
            $row_meta = [
                'docs' => '<a href="https://github.com/marrisonlab/marrison-custom-installer" target="_blank">' . esc_html__('Visita il sito', 'marrison-custom-installer') . '</a>',
            ];
            return array_merge($links, $row_meta);
        }
        return $links;
    }

    private function perform_install($slug) {
        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();

        if (!$wp_filesystem) return false;

        foreach ($this->get_available_installs() as $install) {
            if ($install['slug'] !== $slug) continue;

            $zip = download_url($install['download_url']);
            if (is_wp_error($zip)) return false;

            $upgrade_dir = WP_CONTENT_DIR . '/upgrade/marrison-' . $slug;
            wp_mkdir_p($upgrade_dir);

            unzip_file($zip, $upgrade_dir);
            unlink($zip);

            $dirs = glob($upgrade_dir . '/*', GLOB_ONLYDIR);
            if (empty($dirs)) return false;

            $source = trailingslashit($dirs[0]);
            $dest   = trailingslashit(WP_PLUGIN_DIR . '/' . $slug);

            if ($wp_filesystem->is_dir($dest)) {
                $wp_filesystem->delete($dest, true);
            }

            copy_dir($source, $dest);
            $wp_filesystem->delete($upgrade_dir, true);

            delete_site_transient('update_plugins');
            wp_clean_plugins_cache(true);

            return true;
        }

        return false;
    }

    private function perform_self_update($download_url) {
        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();

        if (!$wp_filesystem) return false;

        $zip = download_url($download_url);
        if (is_wp_error($zip)) return false;

        $upgrade_dir = WP_CONTENT_DIR . '/upgrade/marrison-custom-installer-temp';
        wp_mkdir_p($upgrade_dir);

        unzip_file($zip, $upgrade_dir);
        unlink($zip);

        $dirs = glob($upgrade_dir . '/*', GLOB_ONLYDIR);
        if (empty($dirs)) return false;

        $source = trailingslashit($dirs[0]);
        $dest   = trailingslashit(WP_PLUGIN_DIR . '/marrison-custom-installer');

        if ($wp_filesystem->is_dir($dest)) {
            $wp_filesystem->delete($dest, true);
        }

        copy_dir($source, $dest);
        $wp_filesystem->delete($upgrade_dir, true);

        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);

        return true;
    }

    public function install_plugin() {
        $slug = sanitize_text_field($_GET['slug'] ?? '');
        check_admin_referer('marrison_install_' . $slug);

        $this->perform_install($slug);

        wp_redirect(admin_url('admin.php?page=marrison-installer&updated=' . $slug));
        exit;
    }

    public function bulk_install() {
        check_admin_referer('marrison_bulk_install');

        $updated = [];
        foreach ($_POST['plugins'] ?? [] as $slug) {
            if ($this->perform_install(sanitize_text_field($slug))) {
                $updated[] = $slug;
            }
        }

        $query = http_build_query(['bulk_updated' => $updated]);
        wp_redirect(admin_url('admin.php?page=marrison-installer&' . $query));
        exit;
    }

    public function clear_cache() {
        check_admin_referer('marrison_clear_cache');
        delete_transient('marrison_available_installs');
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);

        $redirect = !empty($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : admin_url('admin.php?page=marrison-installer-settings&cache_cleared=1');
        wp_redirect($redirect);
        exit;
    }

    public function force_check_mci() {
        check_admin_referer('marrison_force_check_mci');
        
        delete_transient('marrison_installer_github_version');
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);
        wp_update_plugins();
        
        $redirect = !empty($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : admin_url('admin.php?page=marrison-installer&mci_checked=1');
        wp_redirect($redirect);
        exit;
    }

    public function save_repo_url() {
        check_admin_referer('marrison_save_repo_url');

        if (isset($_POST['marrison_remove_repo_url'])) {
            delete_option('marrison_repo_url');
            $redirect_url = admin_url('admin.php?page=marrison-installer-settings&settings-updated=removed');
        } else {
            $url = sanitize_url($_POST['marrison_repo_url']);
            update_option('marrison_repo_url', $url);
            $redirect_url = admin_url('admin.php?page=marrison-installer-settings&settings-updated=saved');
        }

        delete_transient('marrison_available_installs');
        delete_site_transient('update_plugins');

        wp_redirect($redirect_url);
        exit;
    }
    
    public function download_repo_file() {
        check_admin_referer('marrison_download_repo_file');
        if (!current_user_can('manage_options')) {
            wp_die('Permessi insufficienti');
        }
        
        $source_dir = plugin_dir_path(__FILE__) . 'add_this_file_to_your_repo_folder/';
        $file = $source_dir . 'index.php';
        $filename = 'index.php';
        
        if (!file_exists($file)) {
            wp_die('File non trovato: ' . esc_html($file));
        }
        
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    public function install_plugin_ajax() {
        $slug = sanitize_text_field($_POST['slug'] ?? '');
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        
        $nonce_valid = wp_verify_nonce($nonce, 'marrison_install_' . $slug) || 
                       wp_verify_nonce($nonce, 'marrison_bulk_install') ||
                       wp_verify_nonce($nonce, 'marrison_install_marrison-custom-installer');
        
        if (!$nonce_valid) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $result = false;
        
        if ($slug === 'marrison-custom-installer') {
            $transient = get_site_transient('update_plugins');
            if (isset($transient->response[plugin_basename(__FILE__)])) {
                $update = $transient->response[plugin_basename(__FILE__)];
                $result = $this->perform_self_update($update->package);
            }
        } else {
            $result = $this->perform_install($slug);
        }

        if ($result) {
            wp_send_json_success('Plugin aggiornato con successo');
            $this->check_available_installs();
        } else {
            wp_send_json_error('Errore durante l\'aggiornamento del plugin');
        }
    }

    public function bulk_install_ajax() {
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        $plugins = isset($_POST['plugins']) ? array_map('sanitize_text_field', $_POST['plugins']) : [];
        
        if (!wp_verify_nonce($nonce, 'marrison_bulk_install')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        if (empty($plugins)) {
            wp_send_json_error('Nessun plugin selezionato');
        }

        $results = [];
        $success_count = 0;
        
        foreach ($plugins as $slug) {
            $result = false;
            
            if ($slug === 'marrison-custom-installer') {
                $transient = get_site_transient('update_plugins');
                if (isset($transient->response[plugin_basename(__FILE__)])) {
                    $update = $transient->response[plugin_basename(__FILE__)];
                    $result = $this->perform_self_update($update->package);
                }
            } else {
                $result = $this->perform_install($slug);
            }
            
            $results[$slug] = $result;
            if ($result) {
                $success_count++;
            }
        }

        if ($success_count > 0) {
            wp_send_json_success([
                'message' => sprintf('%d plugin aggiornati con successo', $success_count),
                'results' => $results,
                'success_count' => $success_count,
                'total_count' => count($plugins)
            ]);
            $this->check_available_installs();
        } else {
            wp_send_json_error('Nessun plugin è stato aggiornato');
        }
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_marrison-installer' && $hook !== 'marrison-installer_page_marrison-installer-settings') {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_style('dashicons');
        wp_enqueue_style('mci-admin-style', plugin_dir_url(__FILE__) . 'assets/css/admin-style.css', [], '1.8');
        
        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                // Single Install/Update
                $(".mci-dashboard-grid .mci-button-primary").on("click", function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var url = $btn.attr("href");
                    
                    var urlParams = new URLSearchParams(url.split("?")[1]);
                    var slug = urlParams.get("slug");
                    var nonce = urlParams.get("_wpnonce");
                    
                    if (!slug || !nonce) return;
                    
                    startProgress();
                    updateProgress(10, "Inizio installazione di " + slug + "...");
                    
                    $.ajax({
                        url: "' . admin_url('admin-ajax.php') . '",
                        type: "POST",
                        data: {
                            action: "marrison_install_plugin_ajax",
                            slug: slug,
                            nonce: nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                updateProgress(100, "Completato!");
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            } else {
                                showError(response.data || "Errore sconosciuto");
                            }
                        },
                        error: function() {
                            showError("Errore di connessione");
                        }
                    });
                });

                // Bulk Install
                $("#marrison-bulk-form").on("submit", function(e) {
                    e.preventDefault();
                    var $form = $(this);
                    var plugins = [];
                    $("input[name=\'plugins[]\']:checked").each(function() {
                        plugins.push($(this).val());
                    });
                    
                    if (plugins.length === 0) {
                        alert("Seleziona almeno un plugin");
                        return;
                    }
                    
                    var nonce = $form.find("input[name=\'_wpnonce\']").val();
                    
                    startProgress();
                    updateProgress(5, "Preparazione installazione massiva...");
                    
                    $.ajax({
                        url: "' . admin_url('admin-ajax.php') . '",
                        type: "POST",
                        data: {
                            action: "marrison_bulk_install_ajax",
                            plugins: plugins,
                            nonce: nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                updateProgress(100, response.data.message || "Completato!");
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            } else {
                                showError(response.data || "Errore sconosciuto");
                            }
                        },
                        error: function() {
                            showError("Errore di connessione");
                        }
                    });
                });

                function startProgress() {
                    $("#marrison-progress-container").slideDown();
                    $(".mci-dashboard-grid, #marrison-bulk-form").css("opacity", "0.5").css("pointer-events", "none");
                    $("html, body").animate({
                        scrollTop: $(".mci-wrap").offset().top
                    }, 500);
                }

                function updateProgress(percent, status) {
                    $("#marrison-progress-bar").css("width", percent + "%");
                    $("#marrison-progress-percent").text(percent + "%");
                    $("#marrison-progress-status").text(status);
                }
                
                function showError(msg) {
                    $("#marrison-progress-container").removeClass("mci-progress-container").addClass("mci-notice mci-notice-error").html("<p>Error: " + msg + "</p>");
                    $(".mci-dashboard-grid, #marrison-bulk-form").css("opacity", "1").css("pointer-events", "auto");
                }
            });
        ');
    }

    public function add_admin_menu() {
        add_menu_page(
            'Marrison Installer',
            'AM Installer',
            'manage_options',
            'marrison-installer',
            [$this,'admin_page'],
            plugin_dir_url(__FILE__) . 'icon.svg',
            30
        );

        add_submenu_page(
            'marrison-installer',
            'Installer',
            'Installer',
            'manage_options',
            'marrison-installer',
            [$this, 'admin_page']
        );

        add_submenu_page(
            'marrison-installer',
            'Impostazioni',
            'Impostazioni',
            'manage_options',
            'marrison-installer-settings',
            [$this, 'settings_page']
        );
    }

    public function settings_page() {
        $settingsUpdated = $_GET['settings-updated'] ?? '';
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
        ?>
        <div class="mci-wrap">
            <div class="mci-header">
                <h1><span class="dashicons dashicons-admin-settings"></span> Impostazioni Marrison Installer</h1>
            </div>
            
            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <a href="?page=marrison-installer-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">Generale</a>
                <a href="?page=marrison-installer-settings&tab=howto" class="nav-tab <?php echo $active_tab == 'howto' ? 'nav-tab-active' : ''; ?>">Guida & Download</a>
            </h2>

            <?php if ($settingsUpdated === 'saved'): ?>
                <div class="mci-notice mci-notice-success"><span class="dashicons dashicons-yes-alt"></span> Impostazioni salvate correttamente.</div>
            <?php elseif ($settingsUpdated === 'removed'): ?>
                <div class="mci-notice mci-notice-success"><span class="dashicons dashicons-yes-alt"></span> URL del repository ripristinato ai valori predefiniti.</div>
            <?php endif; ?>

            <?php if (isset($_GET['cache_cleared'])): ?>
                <div class="mci-notice mci-notice-success"><span class="dashicons dashicons-update"></span> Cache pulita con successo</div>
            <?php endif; ?>

            <?php if (isset($_GET['mci_checked'])): ?>
                <div class="mci-notice mci-notice-success"><span class="dashicons dashicons-yes-alt"></span> Controllo aggiornamenti MCI forzato con successo.</div>
            <?php endif; ?>

            <?php if ($active_tab == 'general'): ?>
                <div class="mci-card">
                    <div class="mci-card-header">
                        <h2 class="mci-card-title"><span class="dashicons dashicons-database"></span> Repository</h2>
                    </div>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <?php wp_nonce_field('marrison_save_repo_url'); ?>
                        <input type="hidden" name="action" value="marrison_save_repo_url">
                        
                        <div style="padding: 20px;">
                            <label for="marrison_repo_url" style="display:block; margin-bottom:8px; font-weight:600;">Indirizzo Repository</label>
                            <input type="url" id="marrison_repo_url" name="marrison_repo_url" value="<?php echo esc_attr(get_option('marrison_repo_url', $this->repo_url)); ?>" class="regular-text" placeholder="https://example.com/wp-repo/" style="width:100%;">
                            <p class="description">Inserisci l'URL completo del repository personalizzato</p>
                        </div>

                        <div style="padding: 20px; border-top: 1px solid #f0f0f1; display:flex; gap:10px;">
                            <button type="submit" class="mci-button mci-button-primary">💾 Salva Impostazioni</button>
                            <button type="submit" name="marrison_remove_repo_url" value="1" class="mci-button mci-button-secondary">🔄 Ripristina Default</button>
                        </div>
                    </form>
                </div>

                <div class="mci-card" style="margin-top: 20px;">
                    <div class="mci-card-header">
                        <h2 class="mci-card-title"><span class="dashicons dashicons-admin-tools"></span> Strumenti Avanzati</h2>
                    </div>

                    <div style="padding: 20px;">
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #f0f0f1;">
                            <?php wp_nonce_field('marrison_clear_cache'); ?>
                            <input type="hidden" name="action" value="marrison_clear_cache">
                            <button type="submit" class="mci-button mci-button-secondary">
                                <span class="dashicons dashicons-trash"></span> Pulisci Cache
                            </button>
                            <p class="description" style="margin-top:5px;">Rimuove la cache locale dei plugin disponibili</p>
                        </form>

                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <?php wp_nonce_field('marrison_force_check_mci'); ?>
                            <input type="hidden" name="action" value="marrison_force_check_mci">
                            <button type="submit" class="mci-button mci-button-secondary">
                                <span class="dashicons dashicons-update"></span> Forza Controllo Aggiornamenti
                            </button>
                            <p class="description" style="margin-top:5px;">Controlla immediatamente se è disponibile una nuova versione del plugin</p>
                        </form>
                    </div>
                </div>

                <div class="mci-card" style="margin-top: 20px;">
                    <div class="mci-card-header">
                        <h2 class="mci-card-title">ℹ️ Informazioni</h2>
                    </div>
                    <div style="padding: 20px;">
                        <p><strong>Versione Plugin:</strong> 1.8</p>
                        <p><strong>Autore:</strong> Angelo Marra</p>
                        <p><strong>Sito:</strong> <a href="https://marrisonlab.com" target="_blank">marrisonlab.com</a></p>
                        <p><strong>Repository:</strong> <a href="https://github.com/marrisonlab/marrison-custom-installer" target="_blank">GitHub</a></p>
                    </div>
                </div>
            <?php else: ?>
                <div class="mci-card">
                    <div class="mci-card-header">
                        <h2 class="mci-card-title"><span class="dashicons dashicons-book"></span> Guida all'uso</h2>
                    </div>
                    <div style="padding: 20px;">
                        <p>Per trasformare una cartella del tuo server in un Repository Privato compatibile con Marrison Custom Installer, segui questi passaggi:</p>
                        <h3 style="margin-top: 20px;">Repository Plugin</h3>
                        <ol style="margin-left: 20px; list-style: decimal;">
                            <li>Crea una cartella pubblica sul tuo server (es. <code>https://tuosito.com/my-repo/plugins/</code>).</li>
                            <li>Scarica il file <code>index.php</code> qui sotto.</li>
                            <li>Carica il file nella cartella appena creata.</li>
                            <li>Carica i file <code>.zip</code> dei tuoi plugin nella stessa cartella.</li>
                            <li>Inserisci l'URL della cartella (es. <code>https://tuosito.com/my-repo/plugins/</code>) nelle Impostazioni di questo plugin.</li>
                        </ol>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top: 15px;">
                            <?php wp_nonce_field('marrison_download_repo_file'); ?>
                            <input type="hidden" name="action" value="marrison_download_repo_file">
                            <button type="submit" class="mci-button mci-button-primary"><span class="dashicons dashicons-download"></span> Scarica index.php per Plugin</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function admin_page() {
        $installs    = $this->get_available_installs();
        $plugins     = get_plugins();
        $updated     = $_GET['updated'] ?? '';
        $bulkUpdated = $_GET['bulk_updated'] ?? [];
        if (!is_array($bulkUpdated)) $bulkUpdated = [$bulkUpdated];

        ?>
        <div class="mci-wrap">
            <div class="mci-header">
                <h1>📦 Marrison Installer</h1>
            </div>

            <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=marrison_clear_cache&redirect_to=' . urlencode(admin_url('admin.php?page=marrison-installer&cache_cleared=1'))), 'marrison_clear_cache'); ?>" class="mci-button mci-button-secondary">
                    <span class="dashicons dashicons-trash"></span> Pulisci Cache
                </a>
                <a href="<?php echo admin_url('admin.php?page=marrison-installer-settings'); ?>" class="mci-button mci-button-secondary">
                    <span class="dashicons dashicons-admin-generic"></span> Impostazioni
                </a>
            </div>
            
            <div class="mci-progress-container" id="marrison-progress-container">
                <div class="mci-progress-header">
                    <span id="marrison-progress-title">Aggiornamento in corso...</span>
                    <span id="marrison-progress-percent">0%</span>
                </div>
                <div class="mci-progress-track">
                    <div class="mci-progress-bar" id="marrison-progress-bar" style="width: 0%"></div>
                </div>
                <div class="mci-progress-status" id="marrison-progress-status">Preparazione...</div>
            </div>

            <div class="mci-dashboard-grid">
                <?php if (empty($installs)): ?>
                    <div class="mci-card" style="grid-column: 1 / -1;">
                        <div class="mci-empty-state">
                            <span class="dashicons dashicons-warning"></span>
                            <p>Nessun plugin trovato nel repository.</p>
                            <p><a href="<?php echo admin_url('admin.php?page=marrison-installer-settings'); ?>">Controlla le impostazioni del repository</a></p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($installs as $u): 
                        if (!isset($u['slug'])) continue;
                        $slug = $u['slug'];
                        $plugin_file = $this->find_plugin_file($slug);
                        $is_installed = !empty($plugin_file);
                        $is_active = $is_installed && is_plugin_active($plugin_file);
                        $installed_version = $is_installed ? $plugins[$plugin_file]['Version'] : null;
                        $has_update = $is_installed && version_compare($installed_version, $u['version'], '<');
                    ?>
                    <div class="mci-card" style="padding: 0; display: flex; flex-direction: column;">
                        <div class="mci-card-header" style="background: linear-gradient(to bottom, #fcfcfc, #f5f5f5); padding: 12px 15px;">
                            <h3 class="mci-card-title" style="font-size: 14px; font-weight: 700; color: #1d2327;"><?php echo esc_html($u['name']); ?></h3>
                            <input type="checkbox" name="plugins[]" value="<?php echo esc_attr($slug); ?>" form="marrison-bulk-form" style="width: 18px; height: 18px; cursor: pointer;">
                        </div>
                        
                        <div style="padding: 15px; flex: 1;">
                            <div style="margin-bottom: 10px;">
                                <?php if ($is_active): ?>
                                    <span class="mci-badge mci-badge-success">Attivo</span>
                                <?php elseif ($is_installed): ?>
                                    <span class="mci-badge mci-badge-warning">Installato</span>
                                <?php else: ?>
                                    <span class="mci-badge mci-badge-primary">Disponibile</span>
                                <?php endif; ?>

                                <?php if ($has_update): ?>
                                    <span class="mci-badge mci-badge-danger">Aggiornamento</span>
                                <?php endif; ?>
                            </div>
                            
                            <p style="font-size: 13px; color: #646970; margin: 0 0 5px 0;">Versione Repo: <strong><?php echo esc_html($u['version']); ?></strong></p>
                            <?php if ($is_installed): ?>
                                <p style="font-size: 13px; color: #646970; margin: 0;">Versione Inst.: <strong><?php echo esc_html($installed_version); ?></strong></p>
                            <?php endif; ?>
                        </div>

                        <div style="padding: 12px 15px; background: #f6f7f7; border-top: 1px solid #f0f0f1; display: flex; justify-content: flex-end;">
                            <?php if ($is_installed): ?>
                                <?php if ($has_update): ?>
                                    <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=marrison_install_plugin&slug=' . $slug), 'marrison_install_' . $slug); ?>" class="mci-button mci-button-primary mci-button-sm">
                                        Aggiorna
                                    </a>
                                <?php else: ?>
                                    <button class="mci-button mci-button-sm" disabled>Installato</button>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=marrison_install_plugin&slug=' . $slug), 'marrison_install_' . $slug); ?>" class="mci-button mci-button-primary mci-button-sm">
                                    Installa
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($installs)): ?>
                <form id="marrison-bulk-form" method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #c3c4c7; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <?php wp_nonce_field('marrison_bulk_install'); ?>
                    <input type="hidden" name="action" value="marrison_bulk_install">
                    
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <label style="font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" id="marrison-select-all" style="width: 18px; height: 18px;"> Seleziona Tutti
                        </label>
                        <button type="submit" class="mci-button mci-button-primary">Installa/Aggiorna Selezionati</button>
                    </div>
                </form>
                
                <script>
                jQuery(document).ready(function($) {
                    $('#marrison-select-all').on('change', function() {
                        $('input[name="plugins[]"]').prop('checked', $(this).is(':checked'));
                    });
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }
}

new Marrison_Custom_Installer();
