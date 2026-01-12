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
        
        wp_add_inline_script('jquery', '
            var marrisonInstaller = {
                ajaxurl: "' . admin_url('admin-ajax.php') . '"
            };
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
        ?>
        <div class="wrap marrison-wrap">
            <div class="marrison-header">
                <h1>⚙️ Impostazioni Marrison Installer</h1>
                <p class="marrison-subtitle">Gestisci il tuo repository personalizzato di plugin</p>
            </div>

            <?php if ($settingsUpdated === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p><span class="dashicons dashicons-yes-alt"></span> Impostazioni salvate correttamente.</p></div>
            <?php elseif ($settingsUpdated === 'removed'): ?>
                <div class="notice notice-success is-dismissible"><p><span class="dashicons dashicons-yes-alt"></span> URL del repository ripristinato ai valori predefiniti.</p></div>
            <?php endif; ?>

            <?php if (isset($_GET['cache_cleared'])): ?>
                <div class="notice notice-info"><p><span class="dashicons dashicons-update"></span> Cache pulita con successo</p></div>
            <?php endif; ?>

            <?php if (isset($_GET['mci_checked'])): ?>
                <div class="notice notice-success is-dismissible"><p><span class="dashicons dashicons-yes-alt"></span> Controllo aggiornamenti MCI forzato con successo.</p></div>
            <?php endif; ?>

            <div class="marrison-settings-grid">
                <div class="marrison-settings-card">
                    <div class="marrison-card-header">
                        <h2>📦 Repository</h2>
                        <p>Configura l'URL del tuo repository personalizzato</p>
                    </div>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="marrison-form">
                        <?php wp_nonce_field('marrison_save_repo_url'); ?>
                        <input type="hidden" name="action" value="marrison_save_repo_url">
                        
                        <div class="marrison-form-group">
                            <label for="marrison_repo_url">Indirizzo Repository</label>
                            <input type="url" id="marrison_repo_url" name="marrison_repo_url" value="<?php echo esc_attr(get_option('marrison_repo_url', $this->repo_url)); ?>" class="marrison-input" placeholder="https://example.com/wp-repo/">
                            <small>Inserisci l'URL completo del repository personalizzato</small>
                        </div>

                        <div class="marrison-button-group">
                            <button type="submit" class="button button-primary marrison-btn-primary">💾 Salva Impostazioni</button>
                            <button type="submit" name="marrison_remove_repo_url" value="1" class="button marrison-btn-secondary">🔄 Ripristina Default</button>
                        </div>
                    </form>
                </div>

                <div class="marrison-settings-card">
                    <div class="marrison-card-header">
                        <h2>🛠️ Strumenti Avanzati</h2>
                        <p>Gestisci cache e aggiornamenti</p>
                    </div>

                    <div class="marrison-tools">
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-bottom: 15px;">
                            <?php wp_nonce_field('marrison_clear_cache'); ?>
                            <input type="hidden" name="action" value="marrison_clear_cache">
                            <button type="submit" class="button marrison-btn-tool">
                                <span class="dashicons dashicons-trash"></span> Pulisci Cache
                            </button>
                            <small>Rimuove la cache locale dei plugin disponibili</small>
                        </form>

                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <?php wp_nonce_field('marrison_force_check_mci'); ?>
                            <input type="hidden" name="action" value="marrison_force_check_mci">
                            <button type="submit" class="button marrison-btn-tool">
                                <span class="dashicons dashicons-update"></span> Forza Controllo Aggiornamenti
                            </button>
                            <small>Controlla immediatamente se è disponibile una nuova versione del plugin</small>
                        </form>
                    </div>
                </div>

                <div class="marrison-settings-card marrison-info-card">
                    <div class="marrison-card-header">
                        <h2>ℹ️ Informazioni</h2>
                    </div>
                    <div class="marrison-info-content">
                        <p><strong>Versione Plugin:</strong> 1.3</p>
                        <p><strong>Autore:</strong> Angelo Marra</p>
                        <p><strong>Sito:</strong> <a href="https://marrisonlab.com" target="_blank">marrisonlab.com</a></p>
                        <p><strong>Repository:</strong> <a href="https://github.com/marrisonlab/marrison-custom-installer" target="_blank">GitHub</a></p>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .marrison-wrap {
                max-width: 1200px;
            }

            .marrison-header {
                margin: 20px 0 30px;
                padding-bottom: 20px;
                border-bottom: 2px solid #f0f0f1;
            }

            .marrison-header h1 {
                font-size: 28px;
                margin: 0 0 8px 0;
                color: #1d2327;
            }

            .marrison-subtitle {
                margin: 0;
                color: #646970;
                font-size: 14px;
            }

            .marrison-settings-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }

            .marrison-settings-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
                transition: all 0.3s ease;
            }

            .marrison-settings-card:hover {
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                border-color: #2271b1;
            }

            .marrison-card-header {
                padding: 20px;
                background: linear-gradient(135deg, #f5f7f7 0%, #fcfcfc 100%);
                border-bottom: 1px solid #e5e5e5;
            }

            .marrison-card-header h2 {
                margin: 0 0 8px 0;
                font-size: 16px;
                color: #1d2327;
            }

            .marrison-card-header p {
                margin: 0;
                font-size: 13px;
                color: #646970;
            }

            .marrison-form {
                padding: 20px;
            }

            .marrison-form-group {
                margin-bottom: 20px;
            }

            .marrison-form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: #1d2327;
            }

            .marrison-input {
                width: 100%;
                padding: 10px 12px;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                font-size: 14px;
                transition: all 0.2s ease;
                box-sizing: border-box;
            }

            .marrison-input:focus {
                outline: none;
                border-color: #2271b1;
                box-shadow: 0 0 0 3px rgba(34, 113, 177, 0.1);
            }

            .marrison-form-group small {
                display: block;
                margin-top: 6px;
                color: #646970;
                font-size: 12px;
            }

            .marrison-button-group {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }

            .marrison-btn-primary,
            .marrison-btn-secondary {
                flex: 1;
                min-width: 160px;
                padding: 10px 16px !important;
                font-size: 14px;
                font-weight: 600;
                border-radius: 4px;
                transition: all 0.2s ease;
                border: none !important;
                cursor: pointer;
            }

            .marrison-btn-primary {
                background: linear-gradient(135deg, #2271b1 0%, #1963a3 100%);
                color: white;
                box-shadow: 0 2px 6px rgba(34, 113, 177, 0.3);
            }

            .marrison-btn-primary:hover {
                background: linear-gradient(135deg, #1963a3 0%, #0f4a8f 100%);
                box-shadow: 0 4px 12px rgba(34, 113, 177, 0.4);
                transform: translateY(-2px);
            }

            .marrison-btn-secondary {
                background: #f0f0f1;
                color: #1d2327;
                border: 1px solid #c3c4c7 !important;
            }

            .marrison-btn-secondary:hover {
                background: #e5e5e5;
                border-color: #8c8f94 !important;
            }

            .marrison-tools {
                padding: 20px;
            }

            .marrison-tools form {
                margin-bottom: 20px;
                padding-bottom: 20px;
                border-bottom: 1px solid #f0f0f1;
            }

            .marrison-tools form:last-child {
                margin-bottom: 0;
                padding-bottom: 0;
                border-bottom: none;
            }

            .marrison-btn-tool {
                display: flex;
                align-items: center;
                gap: 8px;
                width: 100%;
                padding: 10px 14px !important;
                background: #fff;
                border: 1px solid #c3c4c7 !important;
                border-radius: 4px;
                color: #1d2327;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s ease;
            }

            .marrison-btn-tool:hover {
                background: #f5f5f5;
                border-color: #2271b1 !important;
                color: #2271b1;
            }

            .marrison-btn-tool .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
            }

            .marrison-tools small {
                display: block;
                margin-top: 6px;
                color: #646970;
                font-size: 12px;
            }

            .marrison-info-card {
                grid-column: auto;
            }

            .marrison-info-content {
                padding: 20px;
            }

            .marrison-info-content p {
                margin: 12px 0;
                font-size: 13px;
                color: #1d2327;
            }

            .marrison-info-content a {
                color: #2271b1;
                text-decoration: none;
            }

            .marrison-info-content a:hover {
                text-decoration: underline;
            }

            @media (max-width: 782px) {
                .marrison-settings-grid {
                    grid-template-columns: 1fr;
                }

                .marrison-button-group {
                    flex-direction: column;
                }

                .marrison-btn-primary,
                .marrison-btn-secondary {
                    min-width: auto;
                }
            }
        </style>
        <?php
    }

    public function admin_page() {
        $installs    = $this->get_available_installs();
        $plugins     = get_plugins();
        $updated     = $_GET['updated'] ?? '';
        $bulkUpdated = $_GET['bulk_updated'] ?? [];
        if (!is_array($bulkUpdated)) $bulkUpdated = [$bulkUpdated];

        ?>
        <div class="wrap marrison-wrap">
            <div class="marrison-header">
                <h1>📦 Marrison Installer</h1>
                <p class="marrison-subtitle">Gestisci l'installazione e l'aggiornamento dei plugin</p>
            </div>

            <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=marrison_clear_cache&redirect_to=' . urlencode(admin_url('admin.php?page=marrison-installer&cache_cleared=1'))), 'marrison_clear_cache'); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-trash" style="vertical-align: middle; margin-right: 5px;"></span> Pulisci Cache
                </a>
                <a href="<?php echo admin_url('admin.php?page=marrison-installer-settings'); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-admin-generic" style="vertical-align: middle; margin-right: 5px;"></span> Impostazioni
                </a>
            </div>
            
            <style>
                .marrison-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                    gap: 15px;
                    margin-top: 20px;
                }

                .marrison-card {
                    background: #fff;
                    border: 1px solid #c3c4c7;
                    border-radius: 8px;
                    padding: 0;
                    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
                    transition: all 0.3s ease;
                    display: flex;
                    flex-direction: column;
                    overflow: hidden;
                }

                .marrison-card:hover {
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                    transform: translateY(-2px);
                    border-color: #2271b1;
                }

                .marrison-card-header {
                    padding: 12px 15px;
                    border-bottom: 1px solid #f0f0f1;
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-start;
                    background: linear-gradient(to bottom, #fcfcfc, #f5f5f5);
                }

                .marrison-card-title {
                    font-size: 14px;
                    font-weight: 700;
                    color: #1d2327;
                    margin: 0;
                    line-height: 1.3;
                    flex: 1;
                }

                .marrison-card-cb {
                    width: 20px;
                    height: 20px;
                    cursor: pointer;
                    margin-left: 10px;
                }

                .marrison-card-body {
                    padding: 12px 15px;
                    flex: 1;
                }

                .marrison-card-footer {
                    padding: 12px 15px;
                    background: #f6f7f7;
                    border-top: 1px solid #f0f0f1;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }

                .marrison-status {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    font-size: 12px;
                    margin-bottom: 8px;
                    padding: 4px 8px;
                    border-radius: 4px;
                }

                .status-installed {
                    color: #007017;
                    background: #edfaef;
                }

                .status-update {
                    color: #d63638;
                    background: #fcf0f1;
                }

                .status-new {
                    color: #646970;
                    background: #f0f0f1;
                }

                .version-info {
                    font-size: 12px;
                    color: #50575e;
                    line-height: 1.6;
                }

                .version-badge {
                    background: #f0f0f1;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-family: monospace;
                    font-size: 11px;
                }

                .marrison-actions-bar {
                    background: #fff;
                    padding: 12px 15px;
                    border: 1px solid #c3c4c7;
                    border-left: 4px solid #2271b1;
                    margin: 20px 0;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 15px;
                    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
                    border-radius: 4px;
                    flex-wrap: wrap;
                }

                .marrison-actions-bar label {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    font-weight: 600;
                    cursor: pointer;
                }

                #marrison-install-progress {
                    background: linear-gradient(to right, #f0f7ff, #fff);
                    border-left: 4px solid #2271b1 !important;
                    margin: 20px 0 !important;
                    padding: 15px 15px !important;
                }

                .marrison-install-btn {
                    padding: 6px 12px !important;
                    font-size: 12px;
                    border-radius: 4px;
                    font-weight: 600;
                    transition: all 0.2s ease;
                    border: none !important;
                    white-space: nowrap;
                }

                .marrison-install-btn.button-primary {
                    background: linear-gradient(135deg, #2271b1 0%, #1963a3 100%);
                    color: white;
                    box-shadow: 0 2px 4px rgba(34, 113, 177, 0.2);
                }

                .marrison-install-btn.button-primary:hover {
                    background: linear-gradient(135deg, #1963a3 0%, #0f4a8f 100%);
                    box-shadow: 0 4px 8px rgba(34, 113, 177, 0.3);
                }

                .marrison-install-btn.button-secondary {
                    background: #f0f0f1;
                    color: #1d2327;
                }

                .marrison-install-btn.button-secondary:hover {
                    background: #e5e5e5;
                }

                .marrison-install-btn:disabled {
                    opacity: 0.6;
                    cursor: not-allowed;
                }

                @media (max-width: 782px) {
                    .marrison-grid {
                        grid-template-columns: 1fr;
                    }

                    .marrison-actions-bar {
                        flex-direction: column;
                        align-items: stretch;
                    }

                    .marrison-actions-bar > button {
                        width: 100%;
                    }
                }
            </style>

            <?php if ($bulkUpdated): ?>
                <div class="notice notice-success"><p><span class="dashicons dashicons-yes-alt"></span> Installazione massiva completata ✓</p></div>
            <?php endif; ?>

            <?php if (isset($_GET['cache_cleared'])): ?>
                <div class="notice notice-info"><p><span class="dashicons dashicons-update"></span> Cache pulita ✓</p></div>
            <?php endif; ?>

            <div id="marrison-install-progress" class="notice notice-info" style="display:none; padding: 15px;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div class="spinner is-active" style="float:none; width:24px; height:24px; margin:0;"></div>
                    <div style="flex: 1;">
                        <div id="marrison-install-status" style="font-weight: 600; margin-bottom: 8px;">Operazione in corso...</div>
                        <div style="background: #e5e5e5; border-radius: 4px; height: 8px; overflow: hidden;">
                            <div id="marrison-install-bar" style="background: linear-gradient(90deg, #2271b1, #0f4a8f); height: 100%; width: 0%; transition: width 0.3s ease; border-radius: 4px;"></div>
                        </div>
                        <div id="marrison-install-info" style="font-size: 12px; color: #646970; margin-top: 6px;"></div>
                    </div>
                </div>
            </div>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_bulk_install'); ?>
                <input type="hidden" name="action" value="marrison_bulk_install">

                <div class="marrison-actions-bar">
                    <div>
                        <label>
                            <input type="checkbox" id="cb-select-all-main">
                            <strong>Seleziona tutti</strong>
                        </label>
                    </div>
                    <button type="submit" class="button button-primary">📥 Installa/Aggiorna Selezionati</button>
                </div>

                <?php if (empty($installs)): ?>
                    <div class="notice notice-warning inline">
                        <p>⚠️ Nessun plugin trovato nel repository. Verifica l'URL nelle <a href="<?php echo admin_url('admin.php?page=marrison-installer-settings'); ?>">impostazioni</a>.</p>
                    </div>
                <?php else: ?>
                    <div class="marrison-grid">
                    <?php
                        foreach ($installs as $u):
                            $slug = $u['slug'];
                            $name = $u['name'];
                            $repo_version = $u['version'];
                            
                            $is_installed = false;
                            $current_version = '';
                            $plugin_file = $this->find_plugin_file($slug);
                            
                            if ($plugin_file && isset($plugins[$plugin_file])) {
                                $is_installed = true;
                                $current_version = $plugins[$plugin_file]['Version'];
                                $name = $plugins[$plugin_file]['Name'];
                            }
                            
                            $status_html = '';
                            $can_update = false;
                            
                            if (!$is_installed) {
                                $status_html = '<span class="status-new">⊕ Nuovo</span>';
                            } elseif (version_compare($current_version, $repo_version, '<')) {
                                $status_html = '<span class="status-update">⟳ Aggiornamento</span>';
                                $can_update = true;
                            } else {
                                $status_html = '<span class="status-installed">✓ Installato</span>';
                            }
                    ?>
                        <div class="marrison-card">
                            <div class="marrison-card-header">
                                <h3 class="marrison-card-title"><?php echo esc_html($name); ?></h3>
                                <input type="checkbox" name="plugins[]" value="<?php echo esc_attr($slug); ?>" class="marrison-card-cb">
                            </div>
                            <div class="marrison-card-body">
                                <div><?php echo $status_html; ?></div>
                                <div class="version-info">
                                    <?php if ($is_installed): ?>
                                        <strong>Attuale:</strong> <span class="version-badge"><?php echo esc_html($current_version); ?></span><br>
                                    <?php endif; ?>
                                    <strong>Repository:</strong> <span class="version-badge"><?php echo esc_html($repo_version); ?></span>
                                </div>
                            </div>
                            <div class="marrison-card-footer">
                                <div></div>
                                <div>
                                    <?php if ($updated === $slug || in_array($slug, $bulkUpdated, true)): ?>
                                        <strong style="color:#007017;">✓ Fatto</strong>
                                    <?php else: ?>
                                        <?php 
                                        $is_self_update = ($slug === 'marrison-custom-installer');
                                        $nonce = $is_self_update ? wp_create_nonce('marrison_install_marrison-custom-installer') : wp_create_nonce('marrison_install_' . $slug);
                                        
                                        $btn_label = $is_installed ? ($can_update ? 'Aggiorna' : 'Reinstalla') : 'Installa';
                                        $btn_class = $can_update || !$is_installed ? 'button-primary' : 'button-secondary';
                                        ?>
                                        <button type="button" class="button <?php echo $btn_class; ?> marrison-install-btn" 
                                                data-slug="<?php echo esc_attr($slug); ?>"
                                                data-nonce="<?php echo esc_attr($nonce); ?>">
                                            <?php echo esc_html($btn_label); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <script>
            jQuery(document).ready(function($) {
                
                function updateProgressBar(percent, status, info) {
                    $('#marrison-install-bar').css('width', percent + '%');
                    $('#marrison-install-status').text(status);
                    if (info) {
                        $('#marrison-install-info').text(info);
                    }
                }

                function showProgressBar() {
                    $('#marrison-install-progress').show();
                    updateProgressBar(0, 'Preparazione...', '');
                }

                function hideProgressBar() {
                    setTimeout(function() {
                        $('#marrison-install-progress').fadeOut();
                    }, 1500);
                }

                $('.marrison-install-btn').on('click', function(e) {
                    e.preventDefault();
                    
                    var $btn = $(this);
                    var slug = $btn.data('slug');
                    var nonce = $btn.data('nonce');
                    var actionName = $btn.text().trim();
                    
                    if (actionName === 'Reinstalla' && !confirm('Sei sicuro di voler reinstallare il plugin? La versione corrente verrà sovrascritta.')) {
                        return;
                    }
                    
                    $btn.prop('disabled', true).text('In corso...');
                    showProgressBar();
                    
                    var progress = 0;
                    var progressInterval = setInterval(function() {
                        progress += Math.random() * 15;
                        if (progress > 90) progress = 90;
                        
                        if (progress < 30) {
                            updateProgressBar(progress, 'Download del plugin...', 'Scaricamento in corso');
                        } else if (progress < 60) {
                            updateProgressBar(progress, 'Estrazione file...', 'Decompressione archivio');
                        } else if (progress < 90) {
                            updateProgressBar(progress, 'Installazione...', 'Copia file');
                        }
                    }, 300);
                    
                    $.ajax({
                        url: marrisonInstaller.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'marrison_install_plugin_ajax',
                            slug: slug,
                            nonce: nonce
                        },
                        success: function(response) {
                            clearInterval(progressInterval);
                            
                            if (response.success) {
                                updateProgressBar(100, 'Completato!', 'Operazione riuscita');
                                $btn.replaceWith('<strong style="color:#007017;">✓ Completato</strong>');
                                
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            } else {
                                updateProgressBar(0, 'Errore', response.data || 'Si è verificato un errore');
                                $btn.prop('disabled', false).text(actionName);
                            }
                            
                            hideProgressBar();
                        },
                        error: function() {
                            clearInterval(progressInterval);
                            updateProgressBar(0, 'Errore di connessione', 'Impossibile contattare il server');
                            $btn.prop('disabled', false).text(actionName);
                            hideProgressBar();
                        }
                    });
                });
                
                $('#cb-select-all-main').on('change', function() {
                    $('.marrison-card-cb').prop('checked', $(this).prop('checked'));
                });
            });
        </script>
        <?php
    }
}

new Marrison_Custom_Installer;

add_action('upgrader_process_complete', function($upgrader, $hook_extra) {
    if (empty($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
        return;
    }

    $plugins_dir = WP_PLUGIN_DIR;
    $expected    = $plugins_dir . '/marrison-custom-installer';

    foreach (glob($plugins_dir . '/marrison-custom-installer-*', GLOB_ONLYDIR) as $dir) {
        if (is_dir($expected)) {
            continue;
        }

        if (rename($dir, $expected)) {
            wp_clean_plugins_cache(true);
        }

        break;
    }
}, 10, 2);