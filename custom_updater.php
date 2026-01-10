<?php
/**
 * Plugin Name: Marrison Custom Updater
 * Plugin URI:  https://github.com/marrisonlab/marrison-custom-updater
 * Description: This plugin is used to add a personal repository for updating plugins.
 * Version: 6.0
 * Author: Angelo Marra
 * Author URI:  https://marrisonlab.com
 */

class Marrison_Custom_Updater {

    private $updates_url = 'https://marrisonlab.com/wp-repo/';
    private $cache_duration = 6 * HOUR_IN_SECONDS;
    private $runtime_permissions_cache = null;

    public function __construct() {
        // Usa site_transient_update_plugins invece di pre_set_site_transient_update_plugins
        // per iniettare gli aggiornamenti in tempo reale quando WP controlla la cache
        add_filter('site_transient_update_plugins', [$this, 'check_for_updates']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_marrison_update_plugin', [$this, 'update_plugin']);
        add_action('admin_post_marrison_restore_plugin', [$this, 'restore_plugin']);
        add_action('admin_post_marrison_bulk_update', [$this, 'bulk_update']);
        add_action('admin_post_marrison_clear_cache', [$this, 'clear_cache']);
        add_action('admin_post_marrison_save_repo_url', [$this, 'save_repo_url']);
        add_action('admin_post_marrison_force_check_mcu', [$this, 'force_check_mcu']);
        add_action('admin_post_marrison_bulk_install', [$this, 'bulk_install']);
        add_action('admin_post_marrison_check_permissions', [$this, 'check_permissions_action']);
        
        // Hook per AJAX
        add_action('wp_ajax_marrison_update_plugin_ajax', [$this, 'update_plugin_ajax']);
        add_action('wp_ajax_marrison_bulk_update_ajax', [$this, 'bulk_update_ajax']);
        add_action('wp_ajax_marrison_auto_update_ajax', [$this, 'auto_update_ajax']);
        add_action('wp_ajax_marrison_restore_plugin_ajax', [$this, 'restore_plugin_ajax']);
        
        // Aggiungi script e stili per la pagina admin
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Hook per aggiungere link al plugin Marrison Updater nella pagina dei plugin
        add_filter('plugin_action_links', [$this, 'add_marrison_action_links'], 10, 2);
        add_filter('plugin_row_meta', [$this, 'add_plugin_row_meta'], 10, 2);
        
        // Hook per aggiungere notifiche al menu
        add_action('admin_menu', [$this, 'add_menu_notification_badge'], 999);
        add_action('admin_head', [$this, 'add_menu_badge_styles']);
        add_action('admin_init', [$this, 'check_for_available_updates']);
        
        // Filtro per abilitare auto-update per questo plugin
        add_filter('auto_update_plugin', [$this, 'auto_update_specific_plugins'], 10, 2);
        
        // Hook per pulire la cache GitHub quando si forza il controllo aggiornamenti WP
        add_action('delete_site_transient_update_plugins', [$this, 'force_clear_github_cache']);
    }

    public function force_clear_github_cache() {
        delete_transient('marrison_github_version');
    }

    public function auto_update_specific_plugins($update, $item) {
        if (isset($item->slug) && $item->slug === 'marrison-custom-updater') {
            return true;
        }
        return $update;
    }

    /* ===================== PERMISSIONS CHECK ===================== */

    public function check_permissions_action() {
        check_admin_referer('marrison_check_permissions');
        delete_transient('marrison_remote_permissions');
        
        $redirect = !empty($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : admin_url('admin.php?page=marrison-updater');
        wp_redirect($redirect);
        exit;
    }

    private function check_remote_permissions() {
        // Cache runtime (per singola richiesta) per evitare chiamate multiple nella stessa pagina
        if ($this->runtime_permissions_cache !== null) {
            return $this->runtime_permissions_cache;
        }

        // NOTA: Cache persistente (transient) rimossa per garantire controllo in tempo reale

        $current_domain = parse_url(get_site_url(), PHP_URL_HOST);
        // Remove www. if present for better matching
        $current_domain = preg_replace('/^www\./', '', $current_domain);

        $response = wp_remote_get('https://www.marrisonlab.com/wp-json/custom-api/v1/gestione-siti', ['timeout' => 15]);
        
        $permissions = [
            'updater' => false,
            'installer' => false
        ];

        if (is_wp_error($response)) {
            $this->runtime_permissions_cache = $permissions;
            return $permissions;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!is_array($data)) {
            $this->runtime_permissions_cache = $permissions;
            return $permissions;
        }

        foreach ($data as $site) {
            if (!isset($site['url'])) continue;
            
            $site_url = $site['url'];
            // Normalize site url
            $site_url = preg_replace('/^www\./', '', $site_url);
            
            if ($site_url === $current_domain) {
                $permissions['updater'] = isset($site['updater']) ? filter_var($site['updater'], FILTER_VALIDATE_BOOLEAN) : false;
                $permissions['installer'] = isset($site['installer']) ? filter_var($site['installer'], FILTER_VALIDATE_BOOLEAN) : false;
                break;
            }
        }

        $this->runtime_permissions_cache = $permissions;
        return $permissions;
    }

    /* ===================== AUTO UPDATE AJAX HANDLER ===================== */

    public function auto_update_ajax() {
        // Verifica il nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        
        if (!wp_verify_nonce($nonce, 'marrison_auto_update')) {
            wp_die('Security check failed');
        }

        // Verifica i permessi
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        // Ottieni tutti i plugin con aggiornamenti automatici attivati
        $auto_update_plugins = (array) get_site_option('auto_update_plugins', []);
        
        // Forza il controllo degli aggiornamenti WordPress
        wp_update_plugins();
        $transient = get_site_transient('update_plugins');
        
        if (empty($transient->response)) {
            wp_send_json_error('Nessun aggiornamento disponibile');
        }

        // Identifica i plugin del repository privato per ESCLUDERLI
        $private_updates = $this->get_available_updates();
        $private_slugs = [];
        foreach ($private_updates as $u) {
            $private_slugs[] = $u['slug'];
        }

        $plugins_to_update = [];
        $slugs_map = []; // Mappa slug => file
        
        foreach ($transient->response as $file => $data) {
            $slug = dirname($file);
            if ($slug === '.' || $slug === '') $slug = basename($file, '.php');

            // ESCLUDI i plugin del repository privato
            if (in_array($slug, $private_slugs)) {
                continue;
            }

            // Includi TUTTI i plugin standard che hanno un aggiornamento, non solo quelli con auto-update
            $plugins_to_update[] = $file;
            $slugs_map[$slug] = $file;
        }
        
        if (empty($plugins_to_update)) {
            wp_send_json_error('Nessun plugin "normale" ha aggiornamenti disponibili');
        }

        // Carica le classi necessarie per l'aggiornamento
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        
        // Usa Automatic_Upgrader_Skin per evitare output HTML
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        
        // Esegui l'aggiornamento
        $results = $upgrader->bulk_upgrade($plugins_to_update);
        
        $success_count = 0;
        $formatted_results = [];

        // Analizza i risultati
        // bulk_upgrade restituisce un array indicizzato dai file path, con valore true/false/WP_Error/array info
        foreach ($slugs_map as $slug => $file) {
            $result = isset($results[$file]) ? $results[$file] : false;
            
            // Verifica se il risultato è positivo (non false e non WP_Error)
            // A volte restituisce un array con 'destination_name', etc.
            $is_success = $result && !is_wp_error($result);
            $formatted_results[$slug] = $is_success;
            
            if ($is_success) {
                $success_count++;
            }
        }

        if ($success_count > 0) {
            // Pulisci la cache degli aggiornamenti per evitare che vengano mostrati di nuovo
            wp_clean_plugins_cache( true );
            delete_site_transient('update_plugins');

            wp_send_json_success([
                'message' => sprintf('%d plugin aggiornati con successo', $success_count),
                'results' => $formatted_results,
                'success_count' => $success_count,
                'total_count' => count($plugins_to_update)
            ]);
            
            // Aggiorna il conteggio delle notifiche
            $this->check_for_available_updates();
        } else {
            wp_send_json_error('Nessun plugin è stato aggiornato');
        }
    }

    /* ===================== MENU NOTIFICATION BADGE ===================== */

    public function check_for_available_updates() {
        // Salva il numero di aggiornamenti disponibili in un'opzione per accesso rapido
        $updates = $this->get_available_updates();
        $plugins = get_plugins();
        $update_count = 0;
        
        foreach ($updates as $u) {
            foreach ($plugins as $file => $data) {
                $slug = dirname($file);
                if ($slug === '.' || $slug === '') $slug = basename($file, '.php');
                
                if ($slug === $u['slug'] && version_compare($data['Version'], $u['version'], '<')) {
                    $update_count++;
                    break;
                }
            }
        }
        
        update_option('marrison_available_updates_count', $update_count);
    }

    public function add_menu_notification_badge() {
        $update_count = get_option('marrison_available_updates_count', 0);
        
        if ($update_count > 0) {
            global $menu;
            
            // Trova il menu Marrison Updater e aggiungi il conteggio
            foreach ($menu as $key => $item) {
                if (isset($item[2]) && $item[2] === 'marrison-updater') {
                    $menu[$key][0] .= ' <span class="marrison-update-badge awaiting-mod count-' . $update_count . '">' . $update_count . '</span>';
                    break;
                }
            }
        }
    }

    public function add_menu_badge_styles() {
        ?>
        <style>
        .marrison-update-badge {
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
        
        #adminmenu .marrison-update-badge {
            position: relative;
            top: -1px;
            left: 2px;
        }
        
        #adminmenu .wp-submenu a[href="admin.php?page=marrison-updater"] {
            position: relative;
        }
        
        #adminmenu .wp-submenu a[href="admin.php?page=marrison-updater"]:after {
            content: "";
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 8px;
            height: 8px;
            background-color: #d63638;
            border-radius: 50%;
            display: <?php echo get_option('marrison_available_updates_count', 0) > 0 ? 'block' : 'none'; ?>;
        }
        </style>
        <?php
    }

    /* ===================== UPDATE SOURCE ===================== */

    private function get_available_updates() {
        $custom_repo_url = get_option('marrison_repo_url');
        $repo_url = !empty($custom_repo_url) ? trailingslashit($custom_repo_url) : $this->updates_url;

        // Prova a recuperare la cache
        $cached = get_transient('marrison_available_updates');
        
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

        $updates = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($updates)) return [];

        // Filtra risultati che sembrano contenere codice PHP (errore nel generatore JSON lato server)
        $updates = array_filter($updates, function($u) {
            if (!isset($u['slug'])) return false;
            // Rimuove elementi con variabili PHP o regex nel nome/versione
            if (isset($u['name']) && (strpos($u['name'], '$') !== false || strpos($u['name'], '/i\'') !== false)) return false;
            if (isset($u['version']) && strpos($u['version'], '$') !== false) return false;
            return true;
        });

        // Re-index array
        $updates = array_values($updates);

        set_transient('marrison_available_updates', $updates, $this->cache_duration);
        return $updates;
    }

    /* ===================== WP UPDATE HOOK ===================== */

    public function check_for_updates($transient) {
        if (!is_object($transient)) $transient = new stdClass();
        
        // Assicurati che le proprietà esistano
        if (!isset($transient->response)) $transient->response = [];
        if (!isset($transient->no_update)) $transient->no_update = [];
        if (!isset($transient->checked)) $transient->checked = [];

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();

        foreach ($this->get_available_updates() as $update) {
            $file = $this->find_plugin_file($update['slug']);
            if (!$file || !isset($plugins[$file])) continue;

            $installed = $plugins[$file]['Version'];
            $remote    = $update['version'];

            if (version_compare($installed, $remote, '<')) {
                $transient->response[$file] = (object)[
                    'slug'        => $update['slug'],
                    'new_version' => $remote,
                    'package'     => $update['download_url'],
                ];
            }

            $transient->checked[$file] = $installed;
        }

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
            'id'          => 'marrison-custom-updater',
            'slug'        => 'marrison-custom-updater',
            'plugin'      => $plugin_file,
            'new_version' => $remote,
            'url'         => 'https://github.com/marrisonlab/Marrison-Custom-Updater',
            'package'     => 'https://github.com/marrisonlab/Marrison-Custom-Updater/archive/refs/tags/v' . $remote . '.zip',
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

        $response = wp_remote_get('https://api.github.com/repos/marrisonlab/marrison-custom-updater/releases/latest', [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/MarrisonCustomUpdater'
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
        if ($args->slug !== 'marrison-custom-updater') return $false;

        // Leggi le informazioni dal readme.txt su GitHub
        $response = wp_remote_get('https://raw.githubusercontent.com/marrisonlab/marrison-custom-updater/stable/readme.txt', [
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
        $info->name = 'Marrison Custom Updater';
        $info->slug = 'marrison-custom-updater';
        $info->version = $github_version ? $github_version : '1.0.0';
        $info->author = 'Angelo Marra';
        $info->author_profile = 'https://marrisonlab.com';
        $info->plugin_url = 'https://github.com/marrisonlab/marrison-custom-updater';
        $info->download_url = $info->version ? 'https://github.com/marrisonlab/marrison-custom-updater/archive/refs/tags/v' . $info->version . '.zip' : '';
        $info->requires_php = '7.4';
        $info->requires = '5.0';
        $info->tested = '6.4';
        $info->last_updated = current_time('mysql');
        $info->homepage = 'https://github.com/marrisonlab/marrison-custom-updater';
        $info->active_installs = 0;
        $info->rating = 100;
        $info->ratings = array(5 => 100);
        $info->num_ratings = 0;
        $info->support_url = 'https://github.com/marrisonlab/marrison-custom-updater/issues';
        $info->sections = array(
            'description' => $info->description ? $info->description : 'Plugin per aggiornamenti personalizzati',
            'changelog' => $info->changelog ? $info->changelog : 'Consultare il repository GitHub'
        );

        return $info;
    }

    /* ===================== PLUGIN ACTION LINKS ===================== */

    public function add_marrison_action_links($actions, $plugin_file) {
        // Controlla se è il file del plugin Marrison Custom Updater
        if (strpos($plugin_file, 'custom_updater.php') === false && strpos($plugin_file, 'marrison') === false) {
            return $actions;
        }

        // Link alla pagina del Marrison Updater
        $actions['marrison_settings'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=marrison-updater')),
            esc_html__('Setting', 'marrison-custom-updater')
        );

        return $actions;
    }

    public function add_plugin_row_meta($links, $file) {
        if (strpos($file, 'custom_updater.php') !== false || strpos($file, 'marrison-custom-updater') !== false) {
            $row_meta = [
                'docs' => '<a href="https://github.com/marrisonlab/marrison-custom-updater" target="_blank" aria-label="' . esc_attr__('Visita il sito del plugin', 'marrison-custom-updater') . '">' . esc_html__('Visita il sito del plugin', 'marrison-custom-updater') . '</a>',
            ];
            return array_merge($links, $row_meta);
        }
        return $links;
    }

    /* ===================== REAL UPDATE ENGINE ===================== */

    private function perform_update($slug) {

        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();

        if (!$wp_filesystem) return false;

        foreach ($this->get_available_updates() as $update) {
            if ($update['slug'] !== $slug) continue;

            $zip = download_url($update['download_url']);
            if (is_wp_error($zip)) return false;

            // Trova versione corrente per il backup
            $current_version = '';
            $plugin_file = $this->find_plugin_file($slug);
            if ($plugin_file) {
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $all_plugins = get_plugins();
                if (isset($all_plugins[$plugin_file])) {
                    $current_version = $all_plugins[$plugin_file]['Version'];
                }
            }

            // Crea backup prima di procedere
            $this->create_backup($slug, $current_version);

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

        $upgrade_dir = WP_CONTENT_DIR . '/upgrade/marrison-custom-updater-temp';
        wp_mkdir_p($upgrade_dir);

        unzip_file($zip, $upgrade_dir);
        unlink($zip);

        // Trova la cartella estratta (potrebbe avere nome diverso come marrison-custom-updater-v1.5)
        $dirs = glob($upgrade_dir . '/*', GLOB_ONLYDIR);
        if (empty($dirs)) return false;

        $source = trailingslashit($dirs[0]);
        $dest   = trailingslashit(WP_PLUGIN_DIR . '/marrison-custom-updater');

        if ($wp_filesystem->is_dir($dest)) {
            $wp_filesystem->delete($dest, true);
        }

        copy_dir($source, $dest);
        $wp_filesystem->delete($upgrade_dir, true);

        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);

        return true;
    }

    /* ===================== BACKUP & ROLLBACK ===================== */

    private function get_backup_dir() {
        $dir = WP_CONTENT_DIR . '/marrison-backups';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/index.php', '<?php // Silence is golden');
            file_put_contents($dir . '/.htaccess', 'deny from all');
        }
        return $dir;
    }

    private function create_backup($slug, $version = '') {
        $plugin_file = $this->find_plugin_file($slug);
        if (!$plugin_file) return false;

        $source = WP_PLUGIN_DIR . '/' . $slug; // Assume folder structure
        
        // Se non è una directory (plugin singolo file), salta backup per ora
        if (!is_dir($source)) return false;

        $backup_dir = $this->get_backup_dir();
        
        // Rimuovi vecchi backup per questo slug se si vuole mantenere solo l'ultimo, 
        // oppure mantieni multipli. Per ora manteniamo multipli se hanno versione diversa.
        // Ma l'utente ha detto "la versione vecchia viene sovrascritta", quindi puliamo i precedenti.
        $old_files = glob($backup_dir . '/' . $slug . '-*-backup.zip');
        foreach ($old_files as $f) {
            @unlink($f);
        }
        // Rimuovi anche formato vecchio
        if (file_exists($backup_dir . '/' . $slug . '-backup.zip')) {
            @unlink($backup_dir . '/' . $slug . '-backup.zip');
        }
        
        $version_part = $version ? '-v' . $version : '';
        $zip_file = $backup_dir . '/' . $slug . $version_part . '-backup.zip';
        
        if (file_exists($zip_file)) @unlink($zip_file);

        if (!class_exists('PclZip')) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }
        
        $archive = new PclZip($zip_file);
        
        // Rimuove il percorso assoluto per mantenere struttura relativa
        $v_list = $archive->create($source, PCLZIP_OPT_REMOVE_PATH, WP_PLUGIN_DIR);
        
        return ($v_list != 0);
    }

    public function restore_plugin() {
        // Aumenta limiti esecuzione per evitare crash durante operazioni file
        @ignore_user_abort(true);
        @set_time_limit(0);

        $filename = sanitize_file_name($_GET['file'] ?? '');
        $slug_param = sanitize_text_field($_GET['slug'] ?? '');
        
        if (!empty($filename)) {
             check_admin_referer('marrison_restore_' . $filename);
        } elseif (!empty($slug_param)) {
             check_admin_referer('marrison_restore_' . $slug_param);
             // Fallback per vecchi link: cerca backup standard
             $filename = $slug_param . '-backup.zip';
             
             // Se non esiste, cerca se c'è un backup versionato
             $backup_dir = $this->get_backup_dir();
             if (!file_exists($backup_dir . '/' . $filename)) {
                 $files = glob($backup_dir . '/' . $slug_param . '-*-backup.zip');
                 if (!empty($files)) {
                     $filename = basename($files[0]);
                 }
             }
        } else {
             wp_die('Missing parameters');
        }

        if (!current_user_can('install_plugins')) wp_die('Insufficient permissions');

        $result = $this->perform_restore($filename);

        if (is_wp_error($result)) {
            wp_die('Error restoring backup: ' . $result->get_error_message());
        }
        
        $redirect_to = !empty($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : admin_url('admin.php?page=marrison-updater-backups&restored=' . $result);
        wp_redirect($redirect_to);
        exit;
    }

    public function restore_plugin_ajax() {
        @ignore_user_abort(true);
        @set_time_limit(0);

        $filename = sanitize_file_name($_POST['file'] ?? '');
        $nonce = $_POST['nonce'] ?? '';

        if (!wp_verify_nonce($nonce, 'marrison_restore_' . $filename)) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('install_plugins')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (empty($filename)) {
            wp_send_json_error('Missing filename');
        }

        $result = $this->perform_restore($filename);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(['slug' => $result]);
    }

    private function perform_restore($filename) {
        try {
            $backup_dir = $this->get_backup_dir();
            $zip_file = $backup_dir . '/' . $filename;

            if (!file_exists($zip_file)) {
                return new WP_Error('not_found', 'Backup not found');
            }
            
            // Estrai slug dal filename
            $slug = '';
            if (preg_match('/^(.*)-v(.*)-backup\.zip$/', $filename, $matches)) {
                $slug = $matches[1];
            } else {
                $slug = str_replace('-backup.zip', '', $filename);
            }
            
            if (empty($slug) || strpos($slug, '.') !== false || strpos($slug, '/') !== false || strpos($slug, '\\') !== false) {
                 return new WP_Error('invalid_slug', 'Invalid plugin slug derived from filename');
            }

            global $wp_filesystem;
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            
            // Tenta inizializzazione Filesystem
            if ( ! WP_Filesystem() ) {
                return new WP_Error('fs_error', 'Filesystem error - Could not initialize');
            }

            if (!$wp_filesystem) {
                return new WP_Error('fs_error', 'Filesystem error - Object is null');
            }

            // Elimina plugin corrente
            $dest = WP_PLUGIN_DIR . '/' . $slug;
            
            // Protezione extra
            if (realpath($dest) === realpath(WP_PLUGIN_DIR)) {
                 return new WP_Error('invalid_dest', 'Destination invalid');
            }

            if ($wp_filesystem->is_dir($dest)) {
                // Tenta cancellazione diretta
                $deleted = $wp_filesystem->delete($dest, true);
                
                // Se fallisce (es. Windows file lock), prova strategia move-then-delete
                if (!$deleted) {
                     $trash_dir = WP_PLUGIN_DIR . '/.' . $slug . '_trash_' . time();
                     if ($wp_filesystem->move($dest, $trash_dir)) {
                         // Se spostato con successo, prova a cancellare il trash (se fallisce non importa, è nascosto)
                         $wp_filesystem->delete($trash_dir, true);
                     } else {
                         // Se non riesco nemmeno a spostare, potrebbe fallire unzip se non sovrascrive tutto
                         // Ma proviamo comunque a continuare
                     }
                }
            }

            // Estrai backup
            $result = unzip_file($zip_file, WP_PLUGIN_DIR);

            if (is_wp_error($result)) {
                return $result;
            }
            
            // Pulisce cache
            delete_site_transient('update_plugins');
            wp_clean_plugins_cache(true);

            // Pulisce OPcache se attiva per evitare di servire file vecchi/misti
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            return $slug;
        } catch (Throwable $e) {
            return new WP_Error('exception', 'Critical error during restore: ' . $e->getMessage());
        } catch (Exception $e) {
            return new WP_Error('exception', 'Exception during restore: ' . $e->getMessage());
        }
    }

    /* ===================== ACTIONS ===================== */

    public function update_plugin() {
        $slug = sanitize_text_field($_GET['slug'] ?? '');
        check_admin_referer('marrison_update_' . $slug);

        $this->perform_update($slug);

        wp_redirect(admin_url('admin.php?page=marrison-updater&updated=' . $slug));
        exit;
    }

    public function bulk_update() {
        check_admin_referer('marrison_bulk_update');

        $updated = [];
        foreach ($_POST['plugins'] ?? [] as $slug) {
            if ($this->perform_update(sanitize_text_field($slug))) {
                $updated[] = $slug;
            }
        }

        $query = http_build_query(['bulk_updated' => $updated]);
        wp_redirect(admin_url('admin.php?page=marrison-updater&' . $query));
        exit;
    }

    public function bulk_install() {
        check_admin_referer('marrison_bulk_install');

        $installed = [];
        foreach ($_POST['plugins'] ?? [] as $slug) {
            // perform_update gestisce anche l'installazione (scarica e copia)
            if ($this->perform_update(sanitize_text_field($slug))) {
                $installed[] = $slug;
            }
        }

        $query = http_build_query(['installed' => $installed]);
        wp_redirect(admin_url('admin.php?page=marrison-updater-installer&' . $query));
        exit;
    }

    public function clear_cache() {
        check_admin_referer('marrison_clear_cache');
        delete_transient('marrison_available_updates');
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);

        $redirect = !empty($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : admin_url('admin.php?page=marrison-updater-settings&cache_cleared=1');
        wp_redirect($redirect);
        exit;
    }

    public function force_check_mcu() {
        check_admin_referer('marrison_force_check_mcu');
        
        // Pulisce cache specifica GitHub
        delete_transient('marrison_github_version');
        
        // Forza controllo aggiornamenti WP
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);
        
        // Richiedi aggiornamento immediato (simula cron)
        wp_update_plugins();
        
        $redirect = !empty($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : admin_url('admin.php?page=marrison-updater&mcu_checked=1');
        wp_redirect($redirect);
        exit;
    }

    public function save_repo_url() {
        check_admin_referer('marrison_save_repo_url');

        if (isset($_POST['marrison_remove_repo_url'])) {
            delete_option('marrison_repo_url');
            $redirect_url = admin_url('admin.php?page=marrison-updater-settings&settings-updated=removed');
        } else {
            $url = sanitize_url($_POST['marrison_repo_url']);
            update_option('marrison_repo_url', $url);
            $redirect_url = admin_url('admin.php?page=marrison-updater-settings&settings-updated=saved');
        }

        // Pulisce la cache dopo aver modificato l'URL
        delete_transient('marrison_available_updates');
        delete_site_transient('update_plugins');

        wp_redirect($redirect_url);
        exit;
    }

    /* ===================== AJAX HANDLER ===================== */

    public function update_plugin_ajax() {
        // Verifica il nonce - accetta sia nonce specifico che generico
        $slug = sanitize_text_field($_POST['slug'] ?? '');
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        
        // Controlla nonce specifico per il plugin o nonce bulk generico
        $nonce_valid = wp_verify_nonce($nonce, 'marrison_update_' . $slug) || 
                       wp_verify_nonce($nonce, 'marrison_bulk_update') ||
                       wp_verify_nonce($nonce, 'marrison_update_marrison-custom-updater');
        
        if (!$nonce_valid) {
            wp_send_json_error('Security check failed');
        }

        // Verifica i permessi
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Esegui l'aggiornamento
        $result = false;
        
        // Controlla se è il plugin stesso (Marrison Custom Updater)
        if ($slug === 'marrison-custom-updater') {
            $transient = get_site_transient('update_plugins');
            if (isset($transient->response[plugin_basename(__FILE__)])) {
                $update = $transient->response[plugin_basename(__FILE__)];
                $result = $this->perform_self_update($update->package);
            }
        } else {
            $result = $this->perform_update($slug);
        }

        if ($result) {
            wp_send_json_success('Plugin aggiornato con successo');
            
            // Aggiorna il conteggio delle notifiche
            $this->check_for_available_updates();
        } else {
            wp_send_json_error('Errore durante l\'aggiornamento del plugin');
        }
    }

    /* ===================== BULK UPDATE AJAX HANDLER ===================== */

    public function bulk_update_ajax() {
        // Verifica il nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        $plugins = isset($_POST['plugins']) ? array_map('sanitize_text_field', $_POST['plugins']) : [];
        
        if (!wp_verify_nonce($nonce, 'marrison_bulk_update')) {
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
            
            // Controlla se è il plugin stesso (Marrison Custom Updater)
            if ($slug === 'marrison-custom-updater') {
                $transient = get_site_transient('update_plugins');
                if (isset($transient->response[plugin_basename(__FILE__)])) {
                    $update = $transient->response[plugin_basename(__FILE__)];
                    $result = $this->perform_self_update($update->package);
                }
            } else {
                $result = $this->perform_update($slug);
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
        if ($hook !== 'toplevel_page_marrison-updater') {
            return;
        }
        
        // Assicurati che jQuery sia caricato
        wp_enqueue_script('jquery');
        
        // Aggiungi lo script per la barra di caricamento
        wp_add_inline_script('jquery', '
            var marrisonUpdater = {
                ajaxurl: "' . admin_url('admin-ajax.php') . '"
            };
        ');
    }

    public function add_admin_menu() {
        // Aggiungi menu principale con icona carina
        add_menu_page(
            'Marrison Updater',
            'MCU',
            'manage_options',
            'marrison-updater',
            [$this,'admin_page'],
            'dashicons-update', // Icona carina per aggiornamenti
            30 // Posizione nel menu (dopo Dashboard e Media)
        );

        // Sottomenu Aggiornamenti (default)
        add_submenu_page(
            'marrison-updater',
            'Aggiornamenti',
            'Aggiornamenti',
            'manage_options',
            'marrison-updater',
            [$this, 'admin_page']
        );

        // Sottomenu Impostazioni
        add_submenu_page(
            'marrison-updater',
            'Impostazioni',
            'Impostazioni',
            'manage_options',
            'marrison-updater-settings',
            [$this, 'settings_page']
        );

        // Sottomenu Backup
        add_submenu_page(
            'marrison-updater',
            'Backup',
            'Backup',
            'manage_options',
            'marrison-updater-backups',
            [$this, 'backup_page']
        );

        // Sottomenu Installer
        add_submenu_page(
            'marrison-updater',
            'Installer',
            'Installer',
            'manage_options',
            'marrison-updater-installer',
            [$this, 'installer_page']
        );
    }

    public function settings_page() {
        $settingsUpdated = $_GET['settings-updated'] ?? '';
        ?>
        <div class="wrap">
            <h1>Impostazioni Marrison Updater</h1>

            <?php if ($settingsUpdated === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Impostazioni salvate correttamente.</p></div>
            <?php elseif ($settingsUpdated === 'removed'): ?>
                <div class="notice notice-success is-dismissible"><p>URL del repository ripristinato ai valori predefiniti.</p></div>
            <?php endif; ?>

            <?php if (isset($_GET['cache_cleared'])): ?>
                <div class="notice notice-info"><p>Cache pulita ✓</p></div>
            <?php endif; ?>

            <?php if (isset($_GET['mcu_checked'])): ?>
                <div class="notice notice-success is-dismissible"><p>Controllo aggiornamenti MCU forzato con successo.</p></div>
            <?php endif; ?>

            <h2>Impostazioni Repository</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_save_repo_url'); ?>
                <input type="hidden" name="action" value="marrison_save_repo_url">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="marrison_repo_url">Indirizzo Repository</label></th>
                        <td>
                            <input type="url" id="marrison_repo_url" name="marrison_repo_url" value="<?php echo esc_attr(get_option('marrison_repo_url', $this->updates_url)); ?>" class="regular-text">
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
                <?php wp_nonce_field('marrison_force_check_mcu'); ?>
                <input type="hidden" name="action" value="marrison_force_check_mcu">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('admin.php?page=marrison-updater-settings&mcu_checked=1')); ?>">
                <button class="button button-secondary">Forza controllo aggiornamenti MCU</button>
                <p class="description">Usa questo pulsante se hai appena rilasciato una nuova versione su GitHub e non viene rilevata.</p>
            </form>
        </div>
        <?php
    }

    public function installer_page() {
        // Controllo permessi remoti
        $permissions = $this->check_remote_permissions();
        
        if (!$permissions['installer']) {
            ?>
            <div class="wrap">
                <h1>Installer - Repository Privato</h1>
                <div class="notice notice-error"><p>Non sei autorizzato a visualizzare questa pagina.</p></div>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('marrison_check_permissions'); ?>
                    <input type="hidden" name="action" value="marrison_check_permissions">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('admin.php?page=marrison-updater-installer')); ?>">
                    <button class="button button-secondary">Verifica permessi</button>
                </form>
            </div>
            <?php
            return;
        }

        $updates = $this->get_available_updates();
        $plugins = get_plugins();
        $installed_slugs = $_GET['installed'] ?? [];
        if (!is_array($installed_slugs)) $installed_slugs = [$installed_slugs];
        ?>
        <div class="wrap">
            <h1>Installer - Repository Privato</h1>

            <?php if (!empty($installed_slugs)): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo count($installed_slugs); ?> plugin installati con successo.</p>
                </div>
            <?php endif; ?>

            <!-- Barra di caricamento installazione -->
            <div id="marrison-install-progress" class="notice notice-info" style="display:none; padding: 15px; margin: 20px 0;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div class="spinner is-active" style="float:none; width:20px; height:20px; margin:0;"></div>
                    <div style="flex: 1;">
                        <div id="marrison-install-status" style="font-weight: 600; margin-bottom: 8px;">Installazione in corso...</div>
                        <div style="background: #f0f0f1; border-radius: 4px; height: 8px; overflow: hidden;">
                            <div id="marrison-install-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                        </div>
                        <div id="marrison-install-info" style="font-size: 12px; color: #646970; margin-top: 4px;">In attesa...</div>
                    </div>
                </div>
            </div>

            <form id="marrison-install-form" method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_bulk_install'); ?>
                <input type="hidden" name="action" value="marrison_bulk_install">

                <div class="tablenav top">
                    <div class="alignleft actions">
                        <label style="font-weight: 600;"><input type="checkbox" id="marrison-select-all"> Seleziona tutti</label>
                    </div>
                    <div class="alignleft actions">
                         <button type="submit" id="marrison-install-btn" class="button button-primary">Installa selezionati</button>
                    </div>
                </div>

                <div class="marrison-grid-container" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-top: 20px;">
                    <?php if (empty($updates)): ?>
                        <p>Nessun plugin disponibile nel repository.</p>
                    <?php else: ?>
                        <?php foreach ($updates as $u): 
                            if (!isset($u['slug'])) continue;
                            
                            $slug = $u['slug'];
                            $plugin_file = $this->find_plugin_file($slug);
                            $is_installed = !empty($plugin_file);
                            $is_active = $is_installed && is_plugin_active($plugin_file);
                            
                            // Disabilita se installato (sia attivo che inattivo)
                            $disabled = $is_installed;
                            $card_style = $disabled ? 'opacity: 0.6; background: #f6f7f7;' : 'background: #fff;';
                            $card_style .= ' border: 1px solid #c3c4c7; padding: 12px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); position: relative;';
                        ?>
                            <div class="marrison-plugin-card" style="<?php echo $card_style; ?>">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                    <h3 style="margin: 0; font-size: 1em; line-height: 1.3;"><?php echo esc_html($u['name']); ?></h3>
                                    <?php if (!$disabled): ?>
                                        <input type="checkbox" name="plugins[]" value="<?php echo esc_attr($slug); ?>" class="marrison-plugin-cb" style="transform: scale(1.1);">
                                    <?php else: ?>
                                        <input type="checkbox" disabled checked style="transform: scale(1.1);">
                                    <?php endif; ?>
                                </div>
                                
                                <p style="margin: 4px 0; font-size: 0.9em;"><strong>v</strong> <?php echo esc_html($u['version']); ?></p>
                                
                                <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #f0f0f1; font-size: 0.85em;">
                                    <?php if ($is_active): ?>
                                        <span class="dashicons dashicons-yes" style="color: #00a32a; font-size: 16px; width: 16px; height: 16px;"></span> <span style="color: #00a32a; font-weight: 600;">Attivo</span>
                                    <?php elseif ($is_installed): ?>
                                        <span class="dashicons dashicons-warning" style="color: #dba617; font-size: 16px; width: 16px; height: 16px;"></span> <span style="color: #dba617; font-weight: 600;">Installato (Inattivo)</span>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-download" style="color: #2271b1; font-size: 16px; width: 16px; height: 16px;"></span> <span style="color: #2271b1; font-weight: 600;">Disponibile</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </form>
            
            <script>
            jQuery(document).ready(function($) {
                // Seleziona tutto
                $('#marrison-select-all').on('change', function() {
                    $('.marrison-plugin-cb').prop('checked', $(this).is(':checked'));
                });

                // Gestione installazione AJAX
                $('#marrison-install-form').on('submit', function(e) {
                    var selected = $('.marrison-plugin-cb:checked');
                    if (selected.length === 0) {
                        alert('Seleziona almeno un plugin da installare.');
                        return false;
                    }

                    // Se confermato, procedi con AJAX
                    e.preventDefault();
                    
                    var plugins = [];
                    selected.each(function() {
                        plugins.push($(this).val());
                    });

                    var total = plugins.length;
                    var processed = 0;
                    var success = 0;

                    // Mostra barra di progresso
                    $('#marrison-install-progress').slideDown();
                    $('#marrison-install-btn').prop('disabled', true).text('Installazione in corso...');
                    $('.marrison-plugin-cb').prop('disabled', true);

                    function processNext() {
                        if (processed >= total) {
                            // Finito
                            $('#marrison-install-status').text('Completato!');
                            $('#marrison-install-info').text(success + ' su ' + total + ' plugin installati correttamente. Ricaricamento...');
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                            return;
                        }

                        var slug = plugins[processed];
                        var percent = Math.round((processed / total) * 100);
                        
                        $('#marrison-install-bar').css('width', percent + '%');
                        $('#marrison-install-info').text('Installazione di ' + slug + ' (' + (processed + 1) + '/' + total + ')...');

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'marrison_update_plugin_ajax', // Usiamo lo stesso handler dell'aggiornamento
                                slug: slug,
                                nonce: '<?php echo wp_create_nonce("marrison_bulk_update"); ?>' // Usa nonce bulk generico
                            },
                            success: function(response) {
                                if (response.success) {
                                    success++;
                                } else {
                                    console.error('Errore installazione ' + slug + ':', response);
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('Errore AJAX ' + slug + ':', error);
                            },
                            complete: function() {
                                processed++;
                                $('#marrison-install-bar').css('width', Math.round((processed / total) * 100) + '%');
                                processNext();
                            }
                        });
                    }

                    // Avvia processo
                    processNext();
                });
            });
            </script>
        </div>
        <?php
    }

    public function backup_page() {
        // Disabilita caricamento plugin esterni in questa pagina per evitare conflitti (es. JetEngine)
        // Nota: questo funziona solo se i plugin non sono già stati caricati, ma in admin_page di solito lo sono.
        // L'errore indica che JetEngine non trova una classe quando viene inizializzato.
        // Proviamo a catturare l'errore o a non istanziare classi problematiche.
        
        $restored = $_GET['restored'] ?? '';
        
        // Usa una funzione helper sicura per ottenere i plugin senza crash
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        ?>
        <div class="wrap">
            <h1>Backup Disponibili</h1>
            
            <!-- Barra di caricamento ripristino -->
            <div id="marrison-restore-progress" class="notice notice-warning" style="display:none; padding: 15px; margin: 20px 0;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div class="spinner is-active" style="float:none; width:20px; height:20px; margin:0;"></div>
                    <div style="flex: 1;">
                        <div id="marrison-restore-status" style="font-weight: 600; margin-bottom: 8px;">Ripristino in corso...</div>
                        <div style="background: #fff; border-radius: 4px; height: 8px; overflow: hidden; border:1px solid #ddd;">
                            <div id="marrison-restore-bar" style="background: #d63638; height: 100%; width: 100%;"></div>
                        </div>
                        <div id="marrison-restore-info" style="font-size: 12px; color: #646970; margin-top: 4px;">Attendere prego, non chiudere la pagina...</div>
                    </div>
                </div>
            </div>

            <?php if ($restored): ?>
                <div class="notice notice-success is-dismissible"><p>Plugin <?php echo esc_html($restored); ?> ripristinato con successo.</p></div>
            <?php endif; ?>

            <?php 
            // Cerca backup disponibili
            $backup_dir = WP_CONTENT_DIR . '/marrison-backups';
            $backups = [];
            if (is_dir($backup_dir)) {
                $files = glob($backup_dir . '/*-backup.zip');
                // Ordina per data (più recenti prima)
                usort($files, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });

                foreach ($files as $file) {
                    $filename = basename($file);
                    
                    // Parsa nome file per estrarre slug e versione
                    $slug = '';
                    $backup_version = 'N/A';
                    
                    if (preg_match('/^(.*)-v(.*)-backup\.zip$/', $filename, $matches)) {
                        $slug = $matches[1];
                        $backup_version = $matches[2];
                    } else {
                        $slug = str_replace('-backup.zip', '', $filename);
                    }
                    
                    $backups[] = [
                        'file' => $file,
                        'filename' => $filename,
                        'slug' => $slug,
                        'backup_version' => $backup_version,
                        'date' => date('d/m/Y H:i', filemtime($file)),
                        'size' => size_format(filesize($file))
                    ];
                }
            }

            if (!empty($backups)): ?>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th>Plugin (Slug)</th>
                            <th>Versione Backup</th>
                            <th>Versione Attuale</th>
                            <th>Data Backup</th>
                            <th>Dimensione</th>
                            <th>Azione</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $info): 
                             $plugin_name = $info['slug'];
                             $current_version = 'Non installato';
                             $version_class = '';
                             
                             // Cerca nome plugin se installato
                             $found_file = $this->find_plugin_file($info['slug']);
                             if ($found_file && isset($plugins[$found_file])) {
                                 $plugin_name = $plugins[$found_file]['Name'];
                                 $current_version = $plugins[$found_file]['Version'];
                                 
                                 if ($info['backup_version'] !== 'N/A' && $info['backup_version'] !== $current_version) {
                                     $version_class = 'color: #d63638; font-weight: bold;';
                                 }
                             }
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($plugin_name); ?></strong>
                                    <br><small><?php echo esc_html($info['slug']); ?></small>
                                </td>
                                <td>
                                    <span class="badge" style="background: #f0f0f1; padding: 2px 6px; border-radius: 4px;">
                                        <?php echo esc_html($info['backup_version']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="<?php echo $version_class; ?>">
                                        <?php echo esc_html($current_version); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($info['date']); ?></td>
                                <td><?php echo esc_html($info['size']); ?></td>
                                <td>
                                    <?php 
                                    // Usa il nome file completo per il nonce e l'azione
                                    $restore_nonce = wp_create_nonce('marrison_restore_' . $info['filename']); 
                                    ?>
                                    <button type="button" 
                                            class="button marrison-restore-btn" 
                                            data-filename="<?php echo esc_attr($info['filename']); ?>"
                                            data-nonce="<?php echo esc_attr($restore_nonce); ?>">
                                        Ripristina
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Nessun backup disponibile.</p>
            <?php endif; ?>
            
            <script>
            jQuery(document).ready(function($) {
                $('.marrison-restore-btn').on('click', function(e) {
                    e.preventDefault();
                    
                    if (!confirm('Sei sicuro di voler ripristinare questo backup? La versione corrente verrà sovrascritta.')) {
                        return;
                    }
                    
                    var btn = $(this);
                    var filename = btn.data('filename');
                    var nonce = btn.data('nonce');
                    
                    // Disabilita tutti i bottoni
                    $('.marrison-restore-btn').prop('disabled', true);
                    
                    // Mostra progress bar
                    $('#marrison-restore-progress').slideDown();
                    
                    // Animazione fake della barra
                    var percent = 0;
                    var interval = setInterval(function() {
                        percent += 5;
                        if (percent > 90) percent = 90; // Ferma al 90% finché non risponde
                        $('#marrison-restore-bar').css('width', percent + '%');
                    }, 500);
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'marrison_restore_plugin_ajax',
                            file: filename,
                            nonce: nonce
                        },
                        success: function(response) {
                            clearInterval(interval);
                            $('#marrison-restore-bar').css('width', '100%');
                            
                            if (response.success) {
                                $('#marrison-restore-status').text('Ripristino completato!');
                                $('#marrison-restore-info').text('Ricaricamento pagina...');
                                setTimeout(function() {
                                    window.location.href = 'admin.php?page=marrison-updater-backups&restored=' + response.data.slug;
                                }, 1000);
                            } else {
                                alert('Errore: ' + (response.data || 'Errore sconosciuto'));
                                $('#marrison-restore-progress').hide();
                                $('.marrison-restore-btn').prop('disabled', false);
                            }
                        },
                        error: function(xhr, status, error) {
                            clearInterval(interval);
                            alert('Errore di connessione: ' + error);
                            $('#marrison-restore-progress').hide();
                            $('.marrison-restore-btn').prop('disabled', false);
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    public function admin_page() {

        // Controllo permessi remoti
        $permissions = $this->check_remote_permissions();
        
        if (!$permissions['updater']) {
            ?>
            <div class="wrap">
                <h1>Marrison Updater</h1>
                <div class="notice notice-error"><p>Non sei autorizzato a visualizzare questa pagina.</p></div>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('marrison_check_permissions'); ?>
                    <input type="hidden" name="action" value="marrison_check_permissions">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('admin.php?page=marrison-updater')); ?>">
                    <button class="button button-secondary">Verifica permessi</button>
                </form>
            </div>
            <?php
            return;
        }

        $updates     = $this->get_available_updates();
        $plugins     = get_plugins();
        $updated     = $_GET['updated'] ?? '';
        $restored    = $_GET['restored'] ?? '';
        $bulkUpdated = $_GET['bulk_updated'] ?? [];
        if (!is_array($bulkUpdated)) $bulkUpdated = [$bulkUpdated];
        $settingsUpdated = $_GET['settings-updated'] ?? '';

        ?>
        <div class="wrap">
            <h1>Marrison Updater</h1>

            <!-- Barra di caricamento -->
            <div id="marrison-update-progress" class="notice notice-info" style="display:none; padding: 15px; margin: 20px 0;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div class="spinner is-active" style="float:none; width:20px; height:20px; margin:0;"></div>
                    <div style="flex: 1;">
                        <div id="marrison-update-status" style="font-weight: 600; margin-bottom: 8px;">Aggiornamento in corso...</div>
                        <div style="background: #f0f0f1; border-radius: 4px; height: 8px; overflow: hidden;">
                            <div id="marrison-update-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                        </div>
                        <div id="marrison-update-info" style="font-size: 12px; color: #646970; margin-top: 4px;"></div>
                    </div>
                </div>
            </div>

            <?php if ($settingsUpdated === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Impostazioni salvate correttamente.</p></div>
            <?php elseif ($settingsUpdated === 'removed'): ?>
                <div class="notice notice-success is-dismissible"><p>URL del repository ripristinato ai valori predefiniti.</p></div>
            <?php endif; ?>

            <?php if ($bulkUpdated): ?>
                <div class="notice notice-success"><p>Bulk update completato ✓</p></div>
            <?php endif; ?>

            <?php if ($restored): ?>
                <div class="notice notice-success is-dismissible"><p>Plugin <?php echo esc_html($restored); ?> ripristinato con successo.</p></div>
            <?php endif; ?>

            <?php if (isset($_GET['cache_cleared'])): ?>
                <div class="notice notice-info"><p>Cache pulita ✓</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_bulk_update'); ?>
                <input type="hidden" name="action" value="marrison_bulk_update">

                <h2 style="margin-top: 30px;">Plugin Repository Privato</h2>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <td id="cb" class="manage-column column-cb check-column"><label class="screen-reader-text" for="cb-select-all-1">Seleziona tutto</label><input id="cb-select-all-1" type="checkbox"></td>
                            <th>Plugin</th>
                            <th>Versione</th>
                            <th>Azione</th>
                        </tr>
                    </thead>
                    <tbody>

                    <?php 
                    $has_repo_updates = false;
                    foreach ($updates as $u):
                        foreach ($plugins as $file => $data) {
                            $slug = dirname($file);
                            if ($slug === '.' || $slug === '') $slug = basename($file, '.php');

                            if ($slug === $u['slug'] && version_compare($data['Version'], $u['version'], '<')):
                                $has_repo_updates = true;
                    ?>
                        <tr>
                            <td><input type="checkbox" name="plugins[]" value="<?php echo esc_attr($slug); ?>"></td>
                            <td><?php echo esc_html($u['name']); ?></td>
                            <td><?php echo esc_html($data['Version'] . ' → ' . $u['version']); ?></td>
                            <td>
                                <?php if ($updated === $slug || in_array($slug, $bulkUpdated, true)): ?>
                                    <strong style="color:green;">✓ Aggiornato</strong>
                                <?php else: ?>
                                    <?php 
                                    $is_self_update = ($slug === 'marrison-custom-updater');
                                    $nonce = $is_self_update ? wp_create_nonce('marrison_update_marrison-custom-updater') : wp_create_nonce('marrison_update_' . $slug);
                                    ?>
                                    <button class="button button-primary marrison-update-btn" 
                                            data-slug="<?php echo esc_attr($slug); ?>"
                                            data-nonce="<?php echo esc_attr($nonce); ?>">
                                        Aggiorna
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php
                            endif;
                        }
                    endforeach; 
                    
                    if (!$has_repo_updates): ?>
                        <tr><td colspan="4">Nessun aggiornamento disponibile dal repository privato.</td></tr>
                    <?php endif; ?>

                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="manage-column column-cb check-column"><label class="screen-reader-text" for="cb-select-all-2">Seleziona tutto</label><input id="cb-select-all-2" type="checkbox"></td>
                            <th>Plugin</th>
                            <th>Versione</th>
                            <th>Azione</th>
                        </tr>
                    </tfoot>
                </table>

                <p>
                    <button class="button button-secondary">Aggiorna selezionati (Repository Privato)</button>
                </p>

                <?php 
                    // Controlla se ci sono plugin con auto-update attivati che hanno aggiornamenti (ESCLUSI QUELLI PRIVATI)
                    $auto_update_plugins = (array) get_site_option('auto_update_plugins', []);
                    
                    // Ottieni aggiornamenti standard
                    $transient = get_site_transient('update_plugins');
                    
                    // Ottieni aggiornamenti privati per esclusione
                    $private_updates = $this->get_available_updates();
                    $private_slugs = [];
                    foreach ($private_updates as $u) {
                        $private_slugs[] = $u['slug'];
                    }
                    
                    $plugins_with_auto_update = [];
                    
                    if (!empty($transient->response)) {
                        foreach ($transient->response as $file => $data) {
                            $slug = dirname($file);
                            if ($slug === '.' || $slug === '') $slug = basename($file, '.php');
                            
                            // ESCLUDI i plugin del repository privato
                            if (in_array($slug, $private_slugs)) {
                                continue;
                            }
                            
                    // Se ha auto-update attivo (ORA: Includi tutti i plugin ufficiali)
                            // if (in_array($file, $auto_update_plugins)) {
                                $plugins_with_auto_update[$slug] = [
                                    'file' => $file,
                                    'new_version' => $data->new_version ?? '?',
                                    'name' => isset($plugins[$file]['Name']) ? $plugins[$file]['Name'] : $slug,
                                    'current_version' => isset($plugins[$file]['Version']) ? $plugins[$file]['Version'] : '?'
                                ];
                            // }
                        }
                    }
                ?>

                <hr style="margin-top: 30px;">
                
                <h2 style="margin-top: 30px;">Plugin Repository Ufficiale (WordPress)</h2>
                
                <?php if (!empty($plugins_with_auto_update)): ?>
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr>
                                <th>Plugin</th>
                                <th>Versione Attuale</th>
                                <th>Nuova Versione</th>
                                <th>Stato</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plugins_with_auto_update as $slug => $info): ?>
                                <tr>
                                    <td><?php echo esc_html($info['name']); ?></td>
                                    <td><?php echo esc_html($info['current_version']); ?></td>
                                    <td><?php echo esc_html($info['new_version']); ?></td>
                                    <td id="marrison-status-<?php echo esc_attr($slug); ?>"><span class="dashicons dashicons-clock" style="color: #dba617;"></span> In attesa</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <p style="margin-top: 15px;">
                        <?php $auto_update_nonce = wp_create_nonce('marrison_auto_update'); ?>
                        <button type="button" class="button button-primary marrison-auto-update-btn" 
                                data-nonce="<?php echo esc_attr($auto_update_nonce); ?>">
                            <span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>
                            Aggiorna tutti i plugin ufficiali
                        </button>
                    </p>
                <?php else: ?>
                    <p>Nessun plugin del repository ufficiale WordPress necessita di aggiornamento.</p>
                <?php endif; ?>

            </form>

            <hr style="margin-top: 30px;">
            
            <h2 style="margin-top: 30px;">Strumenti</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('marrison_clear_cache'); ?>
                <input type="hidden" name="action" value="marrison_clear_cache">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('admin.php?page=marrison-updater&cache_cleared=1')); ?>">
                <button class="button">Pulisci cache</button>
            </form>

        </div>
        <script>
            jQuery(document).ready(function($) {
                
                function updateProgressBar(percent, status, info) {
                    $('#marrison-update-bar').css('width', percent + '%');
                    $('#marrison-update-status').text(status);
                    if (info) {
                        $('#marrison-update-info').text(info);
                    }
                }

                function showProgressBar() {
                    $('#marrison-update-progress').show();
                    updateProgressBar(0, 'Preparazione aggiornamento...', '');
                }

                function hideProgressBar() {
                    setTimeout(function() {
                        $('#marrison-update-progress').fadeOut();
                    }, 2000);
                }

                // Gestione click sui pulsanti di aggiornamento singoli
                $('.marrison-update-btn').on('click', function(e) {
                    e.preventDefault();
                    
                    var $btn = $(this);
                    var slug = $btn.data('slug');
                    var nonce = $btn.data('nonce');
                    
                    // Disabilita il pulsante
                    $btn.prop('disabled', true).text('Aggiornamento...');
                    
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
                            updateProgressBar(progress, 'Installazione aggiornamento...', 'Copia file');
                        }
                    }, 300);
                    
                    // Esegui l'aggiornamento via AJAX
                    $.ajax({
                        url: marrisonUpdater.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'marrison_update_plugin_ajax',
                            slug: slug,
                            nonce: nonce
                        },
                        success: function(response) {
                            clearInterval(progressInterval);
                            
                            if (response.success) {
                                updateProgressBar(100, 'Aggiornamento completato!', 'Plugin aggiornato con successo');
                                $btn.replaceWith('<strong style="color:green;">✓ Aggiornato</strong>');
                                
                                // Ricarica la pagina dopo 2 secondi per mostrare lo stato aggiornato
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                updateProgressBar(0, 'Errore durante l\'aggiornamento', response.data || 'Si è verificato un errore');
                                $btn.prop('disabled', false).text('Aggiorna');
                            }
                            
                            hideProgressBar();
                        },
                        error: function() {
                            clearInterval(progressInterval);
                            updateProgressBar(0, 'Errore di connessione', 'Impossibile contattare il server');
                            $btn.prop('disabled', false).text('Aggiorna');
                            hideProgressBar();
                        }
                    });
                });
                
                // Gestione aggiornamento multiplo via AJAX - AGGIORNA UNO PER UNO
                $('form input[name="action"][value="marrison_bulk_update"]').closest('form').on('submit', function(e) {
                    e.preventDefault();
                    console.log('Form submit intercettato');
                    
                    var $checked = $('input[name="plugins[]"]:checked');
                    if ($checked.length === 0) {
                        alert('Seleziona almeno un plugin da aggiornare');
                        return;
                    }
                    
                    var plugins = [];
                    $checked.each(function() {
                        plugins.push($(this).val());
                    });
                    
                    console.log('Plugin da aggiornare:', plugins);
                    
                    // Mostra la barra di caricamento
                    showProgressBar();
                    updateProgressBar(0, 'Preparazione aggiornamento...', 'Plugin selezionati: ' + plugins.length);
                    
                    // Nonce bulk generico (accettato da update_plugin_ajax)
                    var bulkNonce = '<?php echo wp_create_nonce("marrison_bulk_update"); ?>';
                    
                    // Aggiorna i plugin uno per uno
                    var currentIndex = 0;
                    var successCount = 0;
                    var failedPlugins = [];
                    
                    function updateNextPlugin() {
                        if (currentIndex >= plugins.length) {
                            // Tutti gli aggiornamenti completati
                            console.log('Aggiornamenti completati. Successi: ' + successCount);
                            
                            if (successCount > 0) {
                                updateProgressBar(100, 'Aggiornamento completato!', 'Aggiornati ' + successCount + ' di ' + plugins.length + ' plugin');
                                
                                // Aggiorna lo stato dei pulsanti
                                plugins.forEach(function(slug) {
                                    $('button[data-slug="' + slug + '"]').replaceWith('<strong style="color:green;">✓ Aggiornato</strong>');
                                });
                                
                                // Ricarica dopo 2 secondi
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                updateProgressBar(0, 'Errore durante l\'aggiornamento', 'Nessun plugin è stato aggiornato');
                                hideProgressBar();
                            }
                            return;
                        }
                        
                        var slug = plugins[currentIndex];
                        var progressPercent = Math.round((currentIndex / plugins.length) * 100);
                        
                        // Aggiorna lo stato della barra
                        updateProgressBar(progressPercent, 'Aggiornamento in corso...', 'Aggiornamento ' + (currentIndex + 1) + ' di ' + plugins.length + ': ' + slug);
                        
                        console.log('Aggiornamento plugin: ' + slug);
                        
                        // Esegui l'aggiornamento via AJAX
                        $.ajax({
                            url: marrisonUpdater.ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'marrison_update_plugin_ajax',
                                slug: slug,
                                nonce: bulkNonce
                            },
                            success: function(response) {
                                console.log('Risposta AJAX per ' + slug + ':', response);
                                if (response.success) {
                                    successCount++;
                                } else {
                                    console.log('Errore per ' + slug + ':', response.data);
                                    failedPlugins.push(slug);
                                }
                                currentIndex++;
                                updateNextPlugin();
                            },
                            error: function(error) {
                                console.log('Errore AJAX per ' + slug + ':', error);
                                failedPlugins.push(slug);
                                currentIndex++;
                                updateNextPlugin();
                            }
                        });
                    }
                    
                    // Avvia l'aggiornamento dei plugin
                    updateNextPlugin();
                });
                
                // Gestione checkbox "Seleziona tutto" (mantenuta dalla versione originale)
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
                
                // Gestione aggiornamento automatico plugin
                $('.marrison-auto-update-btn').on('click', function(e) {
                    e.preventDefault();
                    
                    var $btn = $(this);
                    var nonce = $btn.data('nonce');
                    
                    // Conferma prima di procedere
                    if (!confirm('Sei sicuro di voler aggiornare tutti i plugin ufficiali disponibili?')) {
                        return;
                    }
                    
                    // Disabilita il pulsante
                    $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>Aggiornamento in corso...');
                    
                    // Mostra la barra di caricamento
                    showProgressBar();
                    
                    // Simula progresso durante la preparazione
                    var progress = 0;
                    var progressInterval = setInterval(function() {
                        progress += Math.random() * 8;
                        if (progress > 85) progress = 85;
                        updateProgressBar(progress, 'Ricerca aggiornamenti plugin ufficiali...', 'Analizzando i plugin');
                    }, 300);
                    
                    // Esegui l'aggiornamento via AJAX
                    $.ajax({
                        url: marrisonUpdater.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'marrison_auto_update_ajax',
                            nonce: nonce
                        },
                        success: function(response) {
                            clearInterval(progressInterval);
                            
                            if (response.success) {
                                updateProgressBar(100, 'Aggiornamento completato!', response.data.message);
                                
                                // Aggiorna i pulsanti per i plugin aggiornati
                                var updatedSlugs = response.data.results || {};
                                Object.keys(updatedSlugs).forEach(function(slug) {
                                    if (updatedSlugs[slug]) {
                                        // Aggiorna stato nella tabella plugin ufficiali
                                        $('#marrison-status-' + slug).html('<span class="dashicons dashicons-yes" style="color: green;"></span> <strong style="color:green;">Aggiornato</strong>');
                                        
                                        // Fallback per pulsanti se presenti
                                        $('button[data-slug="' + slug + '"]').replaceWith('<strong style="color:green;">✓ Aggiornato</strong>');
                                    }
                                });
                                
                                // Nascondi il pulsante auto-update se non ci sono più plugin con auto-update disponibili
                                if (response.data.success_count > 0) {
                                    $btn.fadeOut();
                                    updateProgressBar(100, 'Aggiornamento completato!', 'Ricaricamento pagina in corso...');
                                }
                                
                                // Ricarica la pagina dopo 2 secondi per mostrare lo stato aggiornato
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            } else {
                                updateProgressBar(0, 'Errore durante l\'aggiornamento', response.data || 'Si è verificato un errore');
                                $btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>Aggiorna tutti i plugin ufficiali');
                            }
                            
                            hideProgressBar();
                        },
                        error: function() {
                            clearInterval(progressInterval);
                            updateProgressBar(0, 'Errore di connessione', 'Impossibile contattare il server');
                            $btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>Aggiorna tutti i plugin ufficiali');
                            hideProgressBar();
                        }
                    });
                });
            });
        </script>
        <?php
    }
}

new Marrison_Custom_Updater;

/**
 * Fix definitivo GitHub updater:
 * - rinomina la cartella del plugin con suffisso versione (es. -1.9)
 * - forza il refresh della cache plugin per mostrare la versione corretta in WP
 */
add_action( 'upgrader_process_complete', function ( $upgrader, $hook_extra ) {

    // Agisce solo sui plugin
    if ( empty( $hook_extra['type'] ) || $hook_extra['type'] !== 'plugin' ) {
        return;
    }

    $plugins_dir = WP_PLUGIN_DIR;
    $expected    = $plugins_dir . '/marrison-custom-updater';

    // Cerca directory tipo: marrison-custom-updater-*
    foreach ( glob( $plugins_dir . '/marrison-custom-updater-*', GLOB_ONLYDIR ) as $dir ) {

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