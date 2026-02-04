<?php
trait Marrison_Update_Operations_Trait {
    private function get_available_updates() {
        $custom_repo_url = get_option('marrison_repo_url');
        $repo_url = !empty($custom_repo_url) ? trailingslashit($custom_repo_url) : $this->updates_url;
        if (empty($repo_url)) return [];
        $cached = get_transient('marrison_available_updates_v2');
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
        $updates = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($updates)) return [];
        $cleaned_updates = [];
        foreach ($updates as $u) {
            if (!isset($u['slug'])) continue;
            $u['slug'] = trim($u['slug']);
            if (isset($u['version'])) $u['version'] = trim($u['version']);
            if (isset($u['name'])) $u['name'] = trim($u['name']);
            if (isset($u['name']) && (strpos($u['name'], '$') !== false || strpos($u['name'], '/i\'') !== false)) continue;
            if (isset($u['version']) && strpos($u['version'], '$') !== false) continue;
            $cleaned_updates[] = $u;
        }
        $updates = $cleaned_updates;
        set_transient('marrison_available_updates_v2', $updates, $this->cache_duration);
        return $updates;
    }

    private function get_available_theme_updates() {
        $repo_url = get_option('marrison_themes_repo_url');
        if (empty($repo_url)) return [];
        $repo_url = trailingslashit($repo_url);
        $cached = get_transient('marrison_available_theme_updates');
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
        $response = wp_remote_get($repo_url . 'index.php', ['timeout' => 15]);
        if (is_wp_error($response)) return [];
        $updates = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($updates)) return [];
        $cleaned_updates = [];
        foreach ($updates as $u) {
            if (!isset($u['slug'])) continue;
            $u['slug'] = trim($u['slug']);
            if (isset($u['version'])) $u['version'] = trim($u['version']);
            if (isset($u['name'])) $u['name'] = trim($u['name']);
            if (isset($u['name']) && (strpos($u['name'], '$') !== false || strpos($u['name'], '/i\'') !== false)) continue;
            if (isset($u['version']) && strpos($u['version'], '$') !== false) continue;
            $cleaned_updates[] = $u;
        }
        $updates = $cleaned_updates;
        set_transient('marrison_available_theme_updates', $updates, $this->cache_duration);
        return $updates;
    }

    public function check_for_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $updates = $this->get_available_updates();

        foreach ($updates as $update) {
            $slug = $update['slug'];
            $plugin_file = $this->find_plugin_file($slug, $update['name'] ?? '');

            if ($plugin_file && isset($transient->checked[$plugin_file])) {
                $current_version = $transient->checked[$plugin_file];
                
                if (version_compare($current_version, $update['version'], '<')) {
                    $plugin_data = new stdClass();
                    $plugin_data->slug = $slug;
                    $plugin_data->plugin = $plugin_file;
                    $plugin_data->new_version = $update['version'];
                    $plugin_data->url = $update['info_url'] ?? '';
                    $plugin_data->package = $update['download_url'];
                    $plugin_data->icons = isset($update['icons']) ? (array)$update['icons'] : [];
                    $plugin_data->banners = isset($update['banners']) ? (array)$update['banners'] : [];
                    $plugin_data->banners_rtl = isset($update['banners_rtl']) ? (array)$update['banners_rtl'] : [];
                    
                    $transient->response[$plugin_file] = $plugin_data;
                }
            }
        }
        return $transient;
    }

    public function check_for_theme_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $updates = $this->get_available_theme_updates();

        foreach ($updates as $update) {
            $slug = $update['slug'];
            $theme = wp_get_theme($slug);

            if ($theme->exists()) {
                $current_version = $theme->get('Version');
                if (version_compare($current_version, $update['version'], '<')) {
                    $theme_data = [];
                    $theme_data['theme'] = $slug;
                    $theme_data['new_version'] = $update['version'];
                    $theme_data['url'] = $update['info_url'] ?? '';
                    $theme_data['package'] = $update['download_url'];
                    
                    $transient->response[$slug] = $theme_data;
                }
            }
        }
        return $transient;
    }

    public function plugin_info($res, $action, $args) {
        if ($action !== 'plugin_information') {
            return $res;
        }

        if (empty($args->slug)) {
            return $res;
        }

        $updates = $this->get_available_updates();
        foreach ($updates as $update) {
            if ($update['slug'] === $args->slug) {
                $res = new stdClass();
                $res->name = $update['name'];
                $res->slug = $update['slug'];
                $res->version = $update['version'];
                $res->tested = $update['tested'] ?? '';
                $res->requires = $update['requires'] ?? '';
                $res->author = $update['author'] ?? '';
                $res->author_profile = $update['author_profile'] ?? '';
                $res->download_link = $update['download_url'];
                $res->trunk = $update['download_url'];
                $res->requires_php = $update['requires_php'] ?? '';
                $res->last_updated = $update['last_updated'] ?? '';
                $res->sections = [
                    'description' => $update['description'] ?? 'No description provided.',
                    'installation' => $update['installation'] ?? 'No installation instructions provided.',
                    'changelog' => $update['changelog'] ?? 'No changelog provided.'
                ];
                $res->banners = isset($update['banners']) ? (array)$update['banners'] : [];
                return $res;
            }
        }

        return $res;
    }

    private function find_plugin_file($slug, $name = '') {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        
        // 1. Cerca per dirname (cartella dello slug)
        foreach ($all_plugins as $file => $data) {
            if (dirname($file) === $slug) {
                return $file;
            }
        }
        
        // 2. Cerca per nome esatto (se fornito)
        if (!empty($name)) {
            foreach ($all_plugins as $file => $data) {
                if ($data['Name'] === $name) {
                    return $file;
                }
            }
        }

        // 3. Fallback: cerca se il file inizia con lo slug
        foreach ($all_plugins as $file => $data) {
            if (strpos($file, $slug . '/') === 0 || $file === $slug . '.php') {
                return $file;
            }
        }

        return false;
    }
    
    public function delete_internal_cache() {
        delete_transient('marrison_available_updates_v2');
        delete_transient('marrison_available_theme_updates');
    }


    private function perform_update($slug) {
        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        if (!$wp_filesystem) return new WP_Error('fs_init_failed', __('Impossibile inizializzare il filesystem.', 'marrison-custom-installer'));
        foreach ($this->get_available_updates() as $update) {
            if ($update['slug'] !== $slug) continue;
            $zip = download_url($update['download_url']);
            if (is_wp_error($zip)) return $zip;
            $current_version = '';
            $plugin_file = $this->find_plugin_file($slug, $update['name'] ?? '');
            if ($plugin_file) {
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $all_plugins = get_plugins();
                if (isset($all_plugins[$plugin_file])) {
                    $current_version = $all_plugins[$plugin_file]['Version'];
                }
            }
            $this->create_backup($slug, $current_version, 'plugin', $plugin_file);
            $upgrade_dir = WP_CONTENT_DIR . '/upgrade/marrison-' . $slug;
            wp_mkdir_p($upgrade_dir);
            $unzip = unzip_file($zip, $upgrade_dir);
            unlink($zip);
            
            if (is_wp_error($unzip)) {
                return $unzip;
            }

            $dirs = glob($upgrade_dir . '/*', GLOB_ONLYDIR);
            if (empty($dirs)) {
                return new WP_Error('empty_archive', __('Archivio vuoto o non valido.', 'marrison-custom-installer'));
            } 
            $source = trailingslashit($dirs[0]);
            $dest_folder = $slug;
            if ($plugin_file) {
                $installed_dir = dirname($plugin_file);
                if ($installed_dir !== '.' && $installed_dir !== '') {
                    $dest_folder = $installed_dir;
                }
            }
            $dest = trailingslashit(WP_PLUGIN_DIR . '/' . $dest_folder);
            if ($wp_filesystem->is_dir($dest)) {
                $wp_filesystem->delete($dest, true);
            }
            $result = copy_dir($source, $dest);
            $wp_filesystem->delete($upgrade_dir, true);
            
            if (is_wp_error($result)) {
                return $result;
            }
            
            delete_site_transient('update_plugins');
            wp_clean_plugins_cache(true);
            return true;
        }
        return new WP_Error('update_not_found', __('Aggiornamento non trovato.', 'marrison-custom-installer'));
    }

    private function perform_self_update($download_url) {
        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        if (!$wp_filesystem) return new WP_Error('fs_error', 'Filesystem init failed');
        
        $zip = download_url($download_url);
        if (is_wp_error($zip)) return $zip;
        
        $upgrade_dir = WP_CONTENT_DIR . '/upgrade/marrison-custom-installer-temp';
        $wp_filesystem->delete($upgrade_dir, true); // Clean previous attempts
        wp_mkdir_p($upgrade_dir);
        
        $unzip = unzip_file($zip, $upgrade_dir);
        @unlink($zip);
        
        if (is_wp_error($unzip)) return $unzip;
        
        $dirs = glob($upgrade_dir . '/*', GLOB_ONLYDIR);
        if (empty($dirs)) {
             $wp_filesystem->delete($upgrade_dir, true);
             return new WP_Error('empty_zip', 'Zip archive is empty or invalid structure');
        }
        
        $source = trailingslashit($dirs[0]);
        $dest   = trailingslashit(WP_PLUGIN_DIR . '/marrison-custom-installer');
        
        // Strategy: Move current to backup, copy new, if fail restore backup
        $backup_dest = WP_PLUGIN_DIR . '/marrison-custom-installer-backup-' . time();
        $moved_backup = false;
        
        if ($wp_filesystem->is_dir($dest)) {
            // Try to move to backup
            if (!$wp_filesystem->move($dest, $backup_dest)) {
                // If move fails, try direct delete (fallback, risky but standard)
                // But better to fail safe if we can't backup
                // Let's try to proceed with delete if move failed? 
                // No, let's try copy_dir first to a temp dest? 
                // Actually, standard WP way is Maintenance mode + delete + copy.
                // But we want to avoid deactivation.
                // Let's try standard delete if move fails, but log it?
                // For now, let's assume move works or fail.
                $wp_filesystem->delete($upgrade_dir, true);
                return new WP_Error('backup_failed', 'Could not backup existing version');
            }
            $moved_backup = true;
        }
        
        $result = copy_dir($source, $dest);
        
        if (is_wp_error($result)) {
            // Restore backup
            if ($moved_backup) {
                $wp_filesystem->move($backup_dest, $dest);
            }
            $wp_filesystem->delete($upgrade_dir, true);
            return $result;
        }
        
        // Success
        if ($moved_backup) {
            $wp_filesystem->delete($backup_dest, true);
        }
        $wp_filesystem->delete($upgrade_dir, true);
        
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);
        
        return true;
    }

    private function get_backup_dir() {
        $dir = WP_CONTENT_DIR . '/marrison-backups';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/index.php', '<?php // Silence is golden');
            file_put_contents($dir . '/.htaccess', 'deny from all');
        }
        return $dir;
    }

    private function create_backup($slug, $version = '', $type = 'plugin', $known_file = '') {
        $source = '';
        if ($type === 'plugin') {
            $plugin_file = $known_file ? $known_file : $this->find_plugin_file($slug);
            if (!$plugin_file) return false;
            $plugin_dir = dirname($plugin_file);
            if ($plugin_dir === '.' || $plugin_dir === '') {
                return false;
            }
            $source = WP_PLUGIN_DIR . '/' . $plugin_dir;
        } else {
            $theme = wp_get_theme($slug);
            if (!$theme->exists()) return false;
            $source = get_theme_root() . '/' . $slug;
        }
        if (!is_dir($source)) return false;
        $backup_dir = $this->get_backup_dir();
        $pattern = $backup_dir . '/' . $type . '-' . $slug . '-*-backup.zip';
        foreach (glob($pattern) as $f) @unlink($f);
        if ($type === 'plugin') {
            foreach (glob($backup_dir . '/' . $slug . '-*-backup.zip') as $f) @unlink($f);
        }
        $date = date('Ymd');
        $time = date('His');
        $ver_str = $version ? $version : 'na';
        $filename = sprintf('%s-%s-v%s-%s-%s-backup.zip', $type, $slug, $ver_str, $date, $time);
        $zip_file = $backup_dir . '/' . $filename;
        if (file_exists($zip_file)) @unlink($zip_file);
        if (!class_exists('PclZip')) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }
        $archive = new PclZip($zip_file);
        $remove_path = ($type === 'theme') ? get_theme_root() : WP_PLUGIN_DIR;
        $v_list = $archive->create($source, PCLZIP_OPT_REMOVE_PATH, $remove_path);
        return ($v_list != 0);
    }

    private function perform_restore($filename) {
        try {
            $backup_dir = $this->get_backup_dir();
            $zip_file = $backup_dir . '/' . $filename;
            if (!file_exists($zip_file)) {
                return new WP_Error('not_found', 'Backup not found');
            }
            $type = 'plugin';
            $slug = '';
            if (strpos($filename, 'theme-') === 0) {
                $type = 'theme';
                $remaining = substr($filename, 6);
                if (preg_match('/^(.*?)-v.*-backup\.zip$/', $remaining, $matches)) {
                    $slug = $matches[1];
                } elseif (preg_match('/^(.*?)-backup\.zip$/', $remaining, $matches)) {
                    $slug = $matches[1];
                }
            } elseif (strpos($filename, 'plugin-') === 0) {
                $type = 'plugin';
                $remaining = substr($filename, 7);
                if (preg_match('/^(.*?)-v.*-backup\.zip$/', $remaining, $matches)) {
                    $slug = $matches[1];
                } elseif (preg_match('/^(.*?)-backup\.zip$/', $remaining, $matches)) {
                    $slug = $matches[1];
                }
            } else {
                if (preg_match('/^(.*)-v(.*)-backup\.zip$/', $filename, $matches)) {
                    $slug = $matches[1];
                } else {
                    $slug = str_replace('-backup.zip', '', $filename);
                }
            }
            if (empty($slug) || strpos($slug, '.') !== false || strpos($slug, '/') !== false || strpos($slug, '\\') !== false) {
                return new WP_Error('invalid_slug', 'Invalid slug derived from filename');
            }
            global $wp_filesystem;
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            if ( ! WP_Filesystem() ) {
                return new WP_Error('fs_error', 'Filesystem error - Could not initialize');
            }
            if (!$wp_filesystem) {
                return new WP_Error('fs_error', 'Filesystem error - Object is null');
            }
            $dest_root = ($type === 'theme') ? get_theme_root() : WP_PLUGIN_DIR;
            $dest = $dest_root . '/' . $slug;
            if (realpath($dest) === realpath($dest_root)) {
                return new WP_Error('invalid_dest', 'Destination invalid');
            }
            if ($wp_filesystem->is_dir($dest)) {
                $deleted = $wp_filesystem->delete($dest, true);
                if (!$deleted) {
                    $trash_dir = $dest_root . '/.' . $slug . '_trash_' . time();
                    if ($wp_filesystem->move($dest, $trash_dir)) {
                        $wp_filesystem->delete($trash_dir, true);
                    }
                }
            }
            $result = unzip_file($zip_file, $dest_root);
            if (is_wp_error($result)) {
                return $result;
            }
            if ($type === 'theme') {
                delete_site_transient('update_themes');
                wp_clean_themes_cache(true);
            } else {
                delete_site_transient('update_plugins');
                wp_clean_plugins_cache(true);
            }
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

    private function perform_theme_update($slug, $download_url) {
        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        if (!$wp_filesystem) return false;
        $theme = wp_get_theme($slug);
        $current_version = $theme->exists() ? $theme->get('Version') : '';
        $this->create_backup($slug, $current_version, 'theme');
        $zip = download_url($download_url);
        if (is_wp_error($zip)) return false;
        $upgrade_dir = WP_CONTENT_DIR . '/upgrade/marrison-theme-' . $slug;
        wp_mkdir_p($upgrade_dir);
        unzip_file($zip, $upgrade_dir);
        unlink($zip);
        $dirs = glob($upgrade_dir . '/*', GLOB_ONLYDIR);
        if (empty($dirs)) return false;
        $source = trailingslashit($dirs[0]);
        $dest = trailingslashit(get_theme_root() . '/' . $slug);
        if ($wp_filesystem->is_dir($dest)) {
            $wp_filesystem->delete($dest, true);
        }
        copy_dir($source, $dest);
        $wp_filesystem->delete($upgrade_dir, true);
        delete_site_transient('update_themes');
        wp_clean_themes_cache(true);
        return true;
    }
}
