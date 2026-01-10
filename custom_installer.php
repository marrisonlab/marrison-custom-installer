<?php
/**
 * Plugin Name: Marrison Custom Installer
 * Plugin URI:  https://github.com/marrisonlab/marrison-custom-installer
 * Description: This plugin is used to install plugins from a personal repository.
 * Version: 1.0
 * Author: Angelo Marra
 * Author URI:  https://marrisonlab.com
 */

class Marrison_Custom_Installer {

    private $repo_url = 'https://marrisonlab.com/wp-repo/';
    private $cache_duration = 6 * HOUR_IN_SECONDS;

    public function __construct() {
        // Hook solo per l'auto-aggiornamento DI QUESTO plugin
        add_filter('site_transient_update_plugins', [$this, 'check_for_updates']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_marrison_install_plugin', [$this, 'install_plugin']);
        add_action('admin_post_marrison_bulk_install', [$this, 'bulk_install']);
        add_action('admin_post_marrison_clear_cache', [$this, 'clear_cache']);
        add_action('admin_post_marrison_save_repo_url', [$this, 'save_repo_url']);
        add_action('admin_post_marrison_force_check_mci', [$this, 'force_check_mci']);
        
        // Hook per AJAX
        add_action('wp_ajax_marrison_install_plugin_ajax', [$this, 'install_plugin_ajax']);
        add_action('wp_ajax_marrison_bulk_install_ajax', [$this, 'bulk_install_ajax']);
        // Aggiungi script e stili per la pagina admin
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Hook per aggiungere link al plugin Marrison Installer nella pagina dei plugin
        add_filter('plugin_action_links', [$this, 'add_marrison_action_links'], 10, 2);
        add_filter('plugin_row_meta', [$this, 'add_plugin_row_meta'], 10, 2);
        
        // Hook per aggiungere notifiche al menu
        add_action('admin_menu', [$this, 'add_menu_notification_badge'], 999);
        add_action('admin_head', [$this, 'add_menu_badge_styles']);
        add_action('admin_init', [$this, 'check_available_installs']);
        
        // Filtro per abilitare auto-update per questo plugin
        add_filter('auto_update_plugin', [$this, 'auto_update_specific_plugins'], 10, 2);
        
        // Hook per pulire la cache GitHub quando si forza il controllo aggiornamenti WP
        add_action('delete_site_transient_update_plugins', [$this, 'force_clear_github_cache']);
    }

    public function force_clear_github_cache() {
        delete_transient('marrison_github_version');
    }

    public function auto_update_specific_plugins($update, $item) {
        if (isset($item->slug) && $item->slug === 'marrison-custom-installer') {
            return true;
        }
        return $update;
    }

    /* ===================== MENU NOTIFICATION BADGE ===================== */

    public function check_available_installs() {
        // Salva il numero di aggiornamenti disponibili in un'opzione per accesso rapido
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
            
            // Trova il menu Marrison Installer e aggiungi il conteggio
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
            background-color: #d63638;
            color: #fff;
            font-size: 9px;
            line-height: 17px;
            font-weight: 600;
            margin: 1px 0 0 2px;
            vertical-align: top;
            -webkit-border-radius: 10px;
            border-radius: 10px;
            z-index: 26;
            min-width: 7px;
            padding: 0 6px;
            text-align: center;
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
        }
        </style>
        <?php
    }

    /* ===================== UPDATE SOURCE ===================== */

    private function get_available_installs() {
        $custom_repo_url = get_option('marrison_repo_url');
        $repo_url = !empty($custom_repo_url) ? trailingslashit($custom_repo_url) : $this->repo_url;

        // Prova a recuperare la cache
        $cached = get_transient('marrison_available_installs');
        
        // Se la cache esiste, controlla se è pulita
        if ($cached !== false && is_array($cached)) {
            $is_clean = true;
            foreach ($cached as $u) {
                // Se troviamo elementi corrotti nella cache, invalida tutto
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

        // Filtra risultati che sembrano contenere codice PHP (errore nel generatore JSON lato server)
        $installs = array_filter($installs, function($u) {
            if (!isset($u['slug'])) return false;
            // Rimuove elementi con variabili PHP o regex nel nome/versione
            if (isset($u['name']) && (strpos($u['name'], '$') !== false || strpos($u['name'], '/i\'') !== false)) return false;
            if (isset($u['version']) && strpos($u['version'], '$') !== false) return false;
            return true;
        });

        // Re-index array
        $installs = array_values($installs);

        set_transient('marrison_available_installs', $installs, $this->cache_duration);
        return $installs;
    }

    /* ===================== WP UPDATE HOOK ===================== */

    public function check_for_updates($transient) {
        if (!is_object($transient)) $transient = new stdClass();
        
        // Assicurati che le proprietà esistano
        if (!isset($transient->response)) $transient->response = [];
        if (!isset($transient->no_update)) $transient->no_update = [];
        if (!isset($transient->checked)) $transient->checked = [];

        // Rimosso loop per aggiornamento plugin da repo privata

        // Controlla anche il plugin stesso da GitHub
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
            'tested'      => '6.6',
            'requires_php' => '7.4',
            'icons'       => [],
            'banners'     => [],
            'banners_rtl' => [],
            'compatibility' => new stdClass(),
        ];

        if (version_compare($installed, $remote, '<')) {
            $transient->response[$plugin_file] = $item;
        } else {
            // Importante: popolare no_update permette a WP di mostrare i controlli per auto-update
            $transient->no_update[$plugin_file] = $item;
        }

        $transient->checked[$plugin_file] = $installed;
    }

    private function get_github_version() {
        $cached = get_transient('marrison_github_version');
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
        set_transient('marrison_github_version', $version, 6 * HOUR_IN_SECONDS);

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
        
        // Controlla se è il nostro plugin
        if ($args->slug !== 'marrison-custom-installer') return $false;

        // Leggi le informazioni dal readme.txt su GitHub
        $response = wp_remote_get('https://raw.githubusercontent.com/marrisonlab/marrison-custom-installer/stable/readme.txt', [
            'timeout' => 10
        ]);

        if (is_wp_error($response)) return $false;

        $readme = wp_remote_retrieve_body($response);
        if (empty($readme)) return $false;

        // Parsa il readme.txt
        return $this->parse_readme($readme);
    }

    private function parse_readme($readme) {
        $info = new stdClass();
        
        // Estrai la descrizione
        if (preg_match('/== Description ==\s*(.*?)\s*== /s', $readme, $match)) {
            $info->description = trim($match[1]);
        } else {
            $info->description = '';
        }

        // Estrai il changelog
        if (preg_match('/== Changelog ==\s*(.*?)$/s', $readme, $match)) {
            $info->changelog = trim($match[1]);
        } else {
            $info->changelog = '';
        }

        // Dati base
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
        $info->tested = '6.4';
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

    /* ===================== PLUGIN ACTION LINKS ===================== */

    public function add_marrison_action_links($actions, $plugin_file) {
        // Controlla se è il file del plugin Marrison Custom Installer
        if (strpos($plugin_file, 'custom_installer.php') === false && strpos($plugin_file, 'marrison') === false) {
            return $actions;
        }

        // Link alla pagina del Marrison Installer
        $actions['marrison_settings'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=marrison-installer')),
            esc_html__('Setting', 'marrison-custom-installer')
        );

        return $actions;
    }

    public function add_plugin_row_meta($links, $file) {
        if (strpos($file, 'custom_installer.php') !== false || strpos($file, 'marrison-custom-installer') !== false) {
            $row_meta = [
                'docs' => '<a href="https://github.com/marrisonlab/marrison-custom-installer" target="_blank" aria-label="' . esc_attr__('Visita il sito del plugin', 'marrison-custom-installer') . '">' . esc_html__('Visita il sito del plugin', 'marrison-custom-installer') . '</a>',
            ];
            return array_merge($links, $row_meta);
        }
        return $links;
    }

    /* ===================== INSTALL ENGINE ===================== */

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

        // Trova la cartella estratta (potrebbe avere nome diverso come marrison-custom-installer-v1.5)
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



    /* ===================== ACTIONS ===================== */

    public function install_plugin() {
        $slug = sanitize_text_field($_GET['slug'] ?? '');
        check_admin_referer('marrison_install_' . $slug);

        $this->perform_install($slug); // perform_install works for install too if zip is downloaded and extracted

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
        
        // Pulisce cache specifica GitHub
        delete_transient('marrison_github_version');
        
        // Forza controllo aggiornamenti WP
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);
        
        // Richiedi aggiornamento immediato (simula cron)
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

        // Pulisce la cache dopo aver modificato l'URL
        delete_transient('marrison_available_installs');
        delete_site_transient('update_plugins');

        wp_redirect($redirect_url);
        exit;
    }

    /* ===================== AJAX HANDLER ===================== */

    public function install_plugin_ajax() {
        // Verifica il nonce - accetta sia nonce specifico che generico
        $slug = sanitize_text_field($_POST['slug'] ?? '');
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        
        // Controlla nonce specifico per il plugin o nonce bulk generico
        $nonce_valid = wp_verify_nonce($nonce, 'marrison_install_' . $slug) || 
                       wp_verify_nonce($nonce, 'marrison_bulk_install') ||
                       wp_verify_nonce($nonce, 'marrison_install_marrison-custom-installer');
        
        if (!$nonce_valid) {
            wp_send_json_error('Security check failed');
        }

        // Verifica i permessi
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Esegui l'aggiornamento
        $result = false;
        
        // Controlla se è il plugin stesso (Marrison Custom Installer)
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
            
            // Aggiorna il conteggio delle notifiche
            $this->check_available_installs();
        } else {
            wp_send_json_error('Errore durante l\'aggiornamento del plugin');
        }
    }

    /* ===================== BULK UPDATE AJAX HANDLER ===================== */

    public function bulk_install_ajax() {
        // Verifica il nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        $plugins = isset($_POST['plugins']) ? array_map('sanitize_text_field', $_POST['plugins']) : [];
        
        if (!wp_verify_nonce($nonce, 'marrison_bulk_install')) {
            wp_die('Security check failed');
        }

        // Verifica i permessi
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
            
            // Controlla se è il plugin stesso (Marrison Custom Installer)
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
            
            // Aggiorna il conteggio delle notifiche
            $this->check_for_available_updates();
        } else {
            wp_send_json_error('Nessun plugin è stato aggiornato');
        }
    }

    /* ===================== ADMIN UI ===================== */

    public function enqueue_admin_scripts($hook) {
        // Carica gli script solo sulla nostra pagina
        if ($hook !== 'toplevel_page_marrison-installer') {
            return;
        }
        
        // Assicurati che jQuery sia caricato
        wp_enqueue_script('jquery');
        
        // Aggiungi lo script per la barra di caricamento
        wp_add_inline_script('jquery', '
            var marrisonInstaller = {
                ajaxurl: "' . admin_url('admin-ajax.php') . '"
            };
        ');
    }

    public function add_admin_menu() {
        // Aggiungi menu principale con icona carina
        add_menu_page(
            'Marrison Installer',
            'MCI',
            'manage_options',
            'marrison-installer',
            [$this,'admin_page'],
            plugin_dir_url(__FILE__) . 'icon.svg', // Icona personalizzata
            30 // Posizione nel menu (dopo Dashboard e Media)
        );

        // Sottomenu Aggiornamenti (default)
        add_submenu_page(
            'marrison-installer',
            'Installer',
            'Installer',
            'manage_options',
            'marrison-installer',
            [$this, 'admin_page']
        );

        // Sottomenu Impostazioni
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
        <div class="wrap">
            <h1>Impostazioni Marrison Installer</h1>

            <?php if ($settingsUpdated === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Impostazioni salvate correttamente.</p></div>
            <?php elseif ($settingsUpdated === 'removed'): ?>
                <div class="notice notice-success is-dismissible"><p>URL del repository ripristinato ai valori predefiniti.</p></div>
            <?php endif; ?>

            <?php if (isset($_GET['cache_cleared'])): ?>
                <div class="notice notice-info"><p>Cache pulita ✓</p></div>
            <?php endif; ?>

            <?php if (isset($_GET['mci_checked'])): ?>
                <div class="notice notice-success is-dismissible"><p>Controllo aggiornamenti MCI forzato con successo.</p></div>
            <?php endif; ?>

            <h2>Impostazioni Repository</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_save_repo_url'); ?>
                <input type="hidden" name="action" value="marrison_save_repo_url">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="marrison_repo_url">Indirizzo Repository</label></th>
                        <td>
                            <input type="url" id="marrison_repo_url" name="marrison_repo_url" value="<?php echo esc_attr(get_option('marrison_repo_url', $this->repo_url)); ?>" class="regular-text">
                            <p class="description">Inserisci l'URL del repository personalizzato.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button class="button button-primary" type="submit">Salva</button>
                    <button class="button" type="submit" name="marrison_remove_repo_url" value="1">Rimuovi e ripristina default</button>
                </p>
            </form>

            <hr>

            <h2>Strumenti Avanzati</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_force_check_mci'); ?>
                <input type="hidden" name="action" value="marrison_force_check_mci">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('admin.php?page=marrison-installer-settings&mci_checked=1')); ?>">
                <button class="button button-secondary">Forza controllo aggiornamenti MCI</button>
                <p class="description">Usa questo pulsante se hai appena rilasciato una nuova versione su GitHub e non viene rilevata.</p>
            </form>
        </div>
        <?php
    }


    public function admin_page() {
        $installs    = $this->get_available_installs();
        $plugins     = get_plugins();
        $updated     = $_GET['updated'] ?? '';

        $bulkUpdated = $_GET['bulk_updated'] ?? [];
        if (!is_array($bulkUpdated)) $bulkUpdated = [$bulkUpdated];
        $settingsUpdated = $_GET['settings-updated'] ?? '';

        ?>
        <div class="wrap">
            <h1>Marrison Installer</h1>

            <!-- Barra di caricamento -->
            <div id="marrison-install-progress" class="notice notice-info" style="display:none; padding: 15px; margin: 20px 0;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div class="spinner is-active" style="float:none; width:20px; height:20px; margin:0;"></div>
                    <div style="flex: 1;">
                        <div id="marrison-install-status" style="font-weight: 600; margin-bottom: 8px;">Operazione in corso...</div>
                        <div style="background: #f0f0f1; border-radius: 4px; height: 8px; overflow: hidden;">
                            <div id="marrison-install-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                        </div>
                        <div id="marrison-install-info" style="font-size: 12px; color: #646970; margin-top: 4px;"></div>
                    </div>
                </div>
            </div>

            <?php if ($settingsUpdated === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Impostazioni salvate correttamente.</p></div>
            <?php elseif ($settingsUpdated === 'removed'): ?>
                <div class="notice notice-success is-dismissible"><p>URL del repository ripristinato ai valori predefiniti.</p></div>
            <?php endif; ?>

            <?php if ($bulkUpdated): ?>
                <div class="notice notice-success"><p>Installazione massiva completata ✓</p></div>
            <?php endif; ?>



            <?php if (isset($_GET['cache_cleared'])): ?>
                <div class="notice notice-info"><p>Cache pulita ✓</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_bulk_install'); ?>
                <input type="hidden" name="action" value="marrison_bulk_install">

                <h2 style="margin-top: 30px;">Repository Plugin</h2>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <td id="cb" class="manage-column column-cb check-column"><label class="screen-reader-text" for="cb-select-all-1">Seleziona tutto</label><input id="cb-select-all-1" type="checkbox"></td>
                            <th>Plugin</th>
                            <th>Stato</th>
                            <th>Versione Repo</th>
                            <th>Azione</th>
                        </tr>
                    </thead>
                    <tbody>

                    <?php 
                    if (empty($installs)): ?>
                        <tr><td colspan="5">Nessun plugin trovato nel repository. Verifica l'URL nelle impostazioni.</td></tr>
                    <?php else:
                        foreach ($installs as $u):
                            $slug = $u['slug'];
                            $name = $u['name'];
                            $repo_version = $u['version'];
                            
                            // Cerca se installato
                            $is_installed = false;
                            $current_version = '';
                            $plugin_file = $this->find_plugin_file($slug);
                            
                            if ($plugin_file && isset($plugins[$plugin_file])) {
                                $is_installed = true;
                                $current_version = $plugins[$plugin_file]['Version'];
                                $name = $plugins[$plugin_file]['Name']; // Usa nome locale se disponibile
                            }
                            
                            // Determina stato
                            $status_label = '';
                            $row_class = '';
                            $can_update = false;
                            
                            if (!$is_installed) {
                                $status_label = '<span class="dashicons dashicons-plus" style="color: #646970;"></span> Non installato';
                            } elseif (version_compare($current_version, $repo_version, '<')) {
                                $status_label = '<strong style="color: #d63638;">Aggiornamento disponibile</strong> (' . $current_version . ' → ' . $repo_version . ')';
                                $can_update = true;
                            } else {
                                $status_label = '<span class="dashicons dashicons-yes" style="color: green;"></span> Installato (v' . $current_version . ')';
                            }
                    ?>
                        <tr>
                            <td><input type="checkbox" name="plugins[]" value="<?php echo esc_attr($slug); ?>"></td>
                            <td>
                                <strong><?php echo esc_html($name); ?></strong>
                                <br><small><?php echo esc_html($slug); ?></small>
                            </td>
                            <td><?php echo $status_label; ?></td>
                            <td><?php echo esc_html($repo_version); ?></td>
                            <td>
                                <?php if ($updated === $slug || in_array($slug, $bulkUpdated, true)): ?>
                                    <strong style="color:green;">✓ Completato</strong>
                                <?php else: ?>
                                    <?php 
                                    $is_self_update = ($slug === 'marrison-custom-installer');
                                    $nonce = $is_self_update ? wp_create_nonce('marrison_install_marrison-custom-installer') : wp_create_nonce('marrison_install_' . $slug);
                                    
                                    $btn_label = $is_installed ? ($can_update ? 'Aggiorna' : 'Reinstalla') : 'Installa';
                                    $btn_class = $can_update ? 'button-primary' : 'button-secondary';
                                    ?>
                                    <button class="button <?php echo $btn_class; ?> marrison-install-btn" 
                                            data-slug="<?php echo esc_attr($slug); ?>"
                                            data-nonce="<?php echo esc_attr($nonce); ?>">
                                        <?php echo esc_html($btn_label); ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php
                        endforeach; 
                    endif; ?>

                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="manage-column column-cb check-column"><label class="screen-reader-text" for="cb-select-all-2">Seleziona tutto</label><input id="cb-select-all-2" type="checkbox"></td>
                            <th>Plugin</th>
                            <th>Stato</th>
                            <th>Versione Repo</th>
                            <th>Azione</th>
                        </tr>
                    </tfoot>
                </table>

                <p>
                    <button class="button button-primary">Installa/Aggiorna selezionati</button>
                </p>
            </form>

            <hr style="margin-top: 30px;">
            
            <h2 style="margin-top: 30px;">Strumenti</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_clear_cache'); ?>
                <input type="hidden" name="action" value="marrison_clear_cache">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('admin.php?page=marrison-installer&cache_cleared=1')); ?>">
                <button class="button">Pulisci cache</button>
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
                    }, 2000);
                }

                // Gestione click sui pulsanti di installazione/aggiornamento singoli
                $('.marrison-install-btn').on('click', function(e) {
                    e.preventDefault();
                    
                    var $btn = $(this);
                    var slug = $btn.data('slug');
                    var nonce = $btn.data('nonce');
                    var actionName = $btn.text().trim();
                    
                    if (actionName === 'Reinstalla' && !confirm('Sei sicuro di voler reinstallare il plugin? La versione corrente verrà sovrascritta.')) {
                        return;
                    }
                    
                    // Disabilita il pulsante
                    $btn.prop('disabled', true).text('In corso...');
                    
                    // Mostra la barra di caricamento
                    showProgressBar();
                    
                    // Simula progresso
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
                    
                    // Esegui l'aggiornamento via AJAX
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
                                $btn.replaceWith('<strong style="color:green;">✓ Completato</strong>');
                                
                                // Ricarica la pagina dopo 1.5 secondi
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
                
                // Gestione installazione multipla via AJAX
                $('form input[name="action"][value="marrison_bulk_install"]').closest('form').on('submit', function(e) {
                    e.preventDefault();
                    
                    var $checked = $('input[name="plugins[]"]:checked');
                    if ($checked.length === 0) {
                        alert('Seleziona almeno un plugin');
                        return;
                    }
                    
                    var plugins = [];
                    $checked.each(function() {
                        plugins.push($(this).val());
                    });
                    
                    // Mostra la barra di caricamento
                    showProgressBar();
                    updateProgressBar(0, 'Preparazione...', 'Plugin selezionati: ' + plugins.length);
                    
                    var bulkNonce = '<?php echo wp_create_nonce("marrison_bulk_install"); ?>';
                    
                    // Aggiorna i plugin uno per uno
                    var currentIndex = 0;
                    var successCount = 0;
                    
                    function updateNextPlugin() {
                        if (currentIndex >= plugins.length) {
                            // Tutti gli aggiornamenti completati
                            if (successCount > 0) {
                                updateProgressBar(100, 'Completato!', 'Operazione riuscita su ' + successCount + ' plugin');
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            } else {
                                updateProgressBar(0, 'Errore', 'Nessuna operazione riuscita');
                                hideProgressBar();
                            }
                            return;
                        }
                        
                        var slug = plugins[currentIndex];
                        var progressPercent = Math.round((currentIndex / plugins.length) * 100);
                        
                        // Aggiorna lo stato della barra
                        updateProgressBar(progressPercent, 'Elaborazione in corso...', 'Plugin ' + (currentIndex + 1) + ' di ' + plugins.length + ': ' + slug);
                        
                        // Esegui via AJAX
                        $.ajax({
                            url: marrisonInstaller.ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'marrison_install_plugin_ajax',
                                slug: slug,
                                nonce: bulkNonce
                            },
                            success: function(response) {
                                if (response.success) {
                                    successCount++;
                                }
                                currentIndex++;
                                updateNextPlugin();
                            },
                            error: function(error) {
                                currentIndex++;
                                updateNextPlugin();
                            }
                        });
                    }
                    
                    // Avvia
                    updateNextPlugin();
                });
                
                // Gestione checkbox "Seleziona tutto"
                const selectAll1 = document.getElementById('cb-select-all-1');
                const selectAll2 = document.getElementById('cb-select-all-2');
                const checkboxes = document.querySelectorAll('input[name="plugins[]"]');

                function toggleCheckboxes(source) {
                    checkboxes.forEach(function(checkbox) {
                        checkbox.checked = source.checked;
                    });
                    if(source === selectAll1 && selectAll2) selectAll2.checked = source.checked;
                    if(source === selectAll2 && selectAll1) selectAll1.checked = source.checked;
                }

                if (selectAll1) {
                    selectAll1.addEventListener('change', function() {
                        toggleCheckboxes(this);
                    });
                }
                if (selectAll2) {
                    selectAll2.addEventListener('change', function() {
                        toggleCheckboxes(this);
                    });
                }
            });
        </script>
        <?php
    }
}

new Marrison_Custom_Installer;

/**
 * Fix definitivo GitHub installer:
 * - rinomina la cartella del plugin con suffisso versione (es. -1.9)
 * - forza il refresh della cache plugin per mostrare la versione corretta in WP
 */
add_action( 'upgrader_process_complete', function ( $upgrader, $hook_extra ) {

    // Agisce solo sui plugin
    if ( empty( $hook_extra['type'] ) || $hook_extra['type'] !== 'plugin' ) {
        return;
    }

    $plugins_dir = WP_PLUGIN_DIR;
    $expected    = $plugins_dir . '/marrison-custom-installer';

    // Cerca directory tipo: marrison-custom-installer-*
    foreach ( glob( $plugins_dir . '/marrison-custom-installer-*', GLOB_ONLYDIR ) as $dir ) {

        // Se la directory corretta esiste già, salta
        if ( is_dir( $expected ) ) {
            continue;
        }

        // Rinomina e pulisce la cache plugin
        if ( rename( $dir, $expected ) ) {
            wp_clean_plugins_cache( true );
        }

        break;
    }
}, 10, 2 );
