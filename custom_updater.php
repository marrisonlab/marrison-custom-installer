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
        
        // Hook per AJAX
        add_action('wp_ajax_marrison_update_plugin_ajax', [$this, 'update_plugin_ajax']);
        add_action('wp_ajax_marrison_bulk_update_ajax', [$this, 'bulk_update_ajax']);
        add_action('wp_ajax_marrison_auto_update_ajax', [$this, 'auto_update_ajax']);
        add_action('wp_ajax_marrison_auto_update_themes_ajax', [$this, 'auto_update_themes_ajax']);
        add_action('wp_ajax_marrison_auto_update_translations_ajax', [$this, 'auto_update_translations_ajax']);
        add_action('wp_ajax_marrison_restore_plugin_ajax', [$this, 'restore_plugin_ajax']);
        
        // Aggiungi script e stili per la pagina admin
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Hook per aggiungere link al plugin Marrison Updater nella pagina dei plugin
        add_filter('plugin_action_links', [$this, 'add_marrison_action_links'], 10, 2);
        add_filter('plugin_row_meta', [$this, 'add_plugin_row_meta'], 10, 2);
        
        // Hook per aggiungere notifiche al menu
        add_action('admin_menu', [$this, 'add_menu_notification_badge'], 999);
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

    /* ===================== PERMISSIONS CHECK (REMOVED) ===================== */

    /* ===================== AUTO UPDATE AJAX HANDLER ===================== */

    public function auto_update_ajax() {
        // Verifica il nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        
        if (!wp_verify_nonce($nonce, 'marrison_auto_update')) {
            wp_die('Security check failed');
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

    /* ===================== AUTO UPDATE THEMES AJAX HANDLER ===================== */

    public function auto_update_themes_ajax() {
        // Verifica il nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        
        if (!wp_verify_nonce($nonce, 'marrison_auto_update_themes')) {
            wp_die('Security check failed');
        }

        // Forza il controllo degli aggiornamenti Temi
        wp_update_themes();
        $transient = get_site_transient('update_themes');
        
        if (empty($transient->response)) {
            wp_send_json_error('Nessun aggiornamento temi disponibile');
        }

        $themes_to_update = array_keys($transient->response);

        if (empty($themes_to_update)) {
             wp_send_json_error('Tutti i temi sono già aggiornati');
        }

        // Carica librerie necessarie
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        
        // Disabilita output buffering per evitare problemi con AJAX
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);
        
        // Esegui aggiornamento
        $results = [];
        $success_count = 0;
        
        foreach ($themes_to_update as $theme_slug) {
            $result = $upgrader->upgrade($theme_slug);
            $results[$theme_slug] = !is_wp_error($result) && $result;
            if ($results[$theme_slug]) {
                $success_count++;
            }
        }

        if ($success_count > 0) {
            wp_send_json_success([
                'message' => sprintf('%d temi aggiornati con successo', $success_count),
                'results' => $results,
                'success_count' => $success_count,
                'total_count' => count($themes_to_update)
            ]);
        } else {
            wp_send_json_error('Impossibile aggiornare i temi. Verifica i permessi di scrittura.');
        }
    }

    /* ===================== AUTO UPDATE TRANSLATIONS AJAX HANDLER ===================== */

    public function auto_update_translations_ajax() {
        // Verifica il nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        
        if (!wp_verify_nonce($nonce, 'marrison_auto_update_translations')) {
            wp_die('Security check failed');
        }

        // Carica librerie necessarie
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        
        // Disabilita output buffering
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Language_Pack_Upgrader($skin);
        
        // Ottieni aggiornamenti traduzioni
        wp_version_check();
        $translations = wp_get_translation_updates();
        
        if (empty($translations)) {
             wp_send_json_error('Nessun aggiornamento traduzioni disponibile');
        }

        // Esegui aggiornamento
        $results = $upgrader->bulk_upgrade($translations);
        
        // Verifica risultati
        // bulk_upgrade ritorna array di risultati o false/WP_Error
        if (is_wp_error($results)) {
            wp_send_json_error($results->get_error_message());
        }

        // Conta successi
        $success_count = 0;
        if (is_array($results)) {
            foreach ($results as $res) {
                if ($res && !is_wp_error($res)) {
                    $success_count++;
                }
            }
        }

        if ($success_count > 0) {
            wp_send_json_success([
                'message' => sprintf('%d traduzioni aggiornate con successo', $success_count),
                'success_count' => $success_count
            ]);
        } else {
             // Se results è vuoto o nessun successo, ma non errore critico
             wp_send_json_error('Impossibile aggiornare le traduzioni o aggiornamento parziale.');
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
        $activate = isset($_POST['activate']) && $_POST['activate'] === 'true';
        
        // Controlla nonce specifico per il plugin o nonce bulk generico
        $nonce_valid = wp_verify_nonce($nonce, 'marrison_update_' . $slug) || 
                       wp_verify_nonce($nonce, 'marrison_bulk_update') ||
                       wp_verify_nonce($nonce, 'marrison_update_marrison-custom-updater');
        
        if (!$nonce_valid) {
            wp_send_json_error('Security check failed');
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
            $message = 'Plugin aggiornato con successo';
            
            // Se richiesto, attiva il plugin
            if ($activate && $slug !== 'marrison-custom-updater') {
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                
                // Pulisce la cache per trovare il nuovo plugin
                wp_clean_plugins_cache(true);
                
                $plugin_file = $this->find_plugin_file($slug);
                
                if ($plugin_file) {
                    $activation = activate_plugin($plugin_file);
                    if (is_wp_error($activation)) {
                        $message .= ' ma errore attivazione: ' . $activation->get_error_message();
                    } else {
                        $message .= ' e attivato';
                    }
                }
            }
            
            wp_send_json_success($message);
            
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
        // Enqueue menu badge styles on all admin pages
        wp_enqueue_style('marrison-menu-badge', plugin_dir_url(__FILE__) . 'assets/css/menu-badge.css', [], '1.0');
        
        // Add inline style for badge visibility based on update count
        $count = get_option('marrison_available_updates_count', 0);
        $display = $count > 0 ? 'block' : 'none';
        wp_add_inline_style('marrison-menu-badge', "#adminmenu .wp-submenu a[href='admin.php?page=marrison-updater']:after { display: {$display}; }");

        // Carica gli script e stili solo sulla nostra pagina
        if (strpos($hook, 'marrison-updater') === false) {
            return;
        }
        
        // Enqueue admin styles
        wp_enqueue_style('marrison-admin-style', plugin_dir_url(__FILE__) . 'assets/css/admin-style.css', [], '1.0');
        
        // Enqueue admin script
        wp_enqueue_script('marrison-admin-script', plugin_dir_url(__FILE__) . 'assets/js/admin-script.js', ['jquery'], '1.0', true);
        
        // Localize script for AJAX
        wp_localize_script('marrison-admin-script', 'marrisonUpdater', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'bulkNonce' => wp_create_nonce('marrison_bulk_update')
        ]);
    }

    public function add_admin_menu() {
        // Aggiungi menu principale con icona carina
        add_menu_page(
            'Marrison Updater',
            'MCU',
            'read',
            'marrison-updater',
            [$this,'admin_page'],
            'data:image/svg+xml;base64,' . base64_encode(file_get_contents(plugin_dir_path(__FILE__) . 'assets/images/icon.svg')),
            30 // Posizione nel menu (dopo Dashboard e Media)
        );

        // Sottomenu Aggiornamenti (default)
        add_submenu_page(
            'marrison-updater',
            'Aggiornamenti',
            'Aggiornamenti',
            'read',
            'marrison-updater',
            [$this, 'admin_page']
        );

        // Sottomenu Impostazioni
        add_submenu_page(
            'marrison-updater',
            'Impostazioni',
            'Impostazioni',
            'read',
            'marrison-updater-settings',
            [$this, 'settings_page']
        );

        // Sottomenu Backup
        add_submenu_page(
            'marrison-updater',
            'Backup',
            'Backup',
            'read',
            'marrison-updater-backups',
            [$this, 'backup_page']
        );

        // Sottomenu Installer
        add_submenu_page(
            'marrison-updater',
            'Installer',
            'Installer',
            'read',
            'marrison-updater-installer',
            [$this, 'installer_page']
        );
    }

    public function settings_page() {
        include plugin_dir_path(__FILE__) . 'pages/settings.php';
    }

    public function installer_page() {
        include plugin_dir_path(__FILE__) . 'pages/installer.php';
    }

    public function backup_page() {
        include plugin_dir_path(__FILE__) . 'pages/backup.php';
    }

    public function admin_page() {
        include plugin_dir_path(__FILE__) . 'pages/main.php';
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