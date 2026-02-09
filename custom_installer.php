<?php
/**
 * Plugin Name: Marrison Custom Installer
 * Plugin URI:  https://github.com/marrisonlab/marrison-custom-installer
 * Description: This plugin is used to install plugins from a personal repository.
 * Version: 2.1.7
 * Author: Angelo Marra
 * Author URI:  https://marrisonlab.com
 */

require_once __DIR__ . '/includes/traits/UpdateOperationsTrait.php';

class Marrison_Custom_Installer_Plugin {

    use Marrison_Installer_Update_Operations_Trait {
        check_for_updates as trait_check_for_updates;
        plugin_info as trait_plugin_info;
    }

    private $repo_url = '';
    protected $updates_url = ''; // Required by trait fallback
    private $cache_duration = 6 * HOUR_IN_SECONDS;

    public function __construct() {
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_marrison_install_plugin', [$this, 'install_plugin']);
        add_action('admin_post_marrison_bulk_install', [$this, 'bulk_install']);
        add_action('admin_post_marrison_clear_cache', [$this, 'clear_cache']);
        add_action('admin_post_marrison_save_repo_url', [$this, 'save_repo_url']);
        add_action('admin_post_marrison_download_repo_file', [$this, 'download_repo_file']);
        
        add_action('wp_ajax_marrison_install_plugin_ajax', [$this, 'install_plugin_ajax']);
        add_action('wp_ajax_marrison_bulk_install_ajax', [$this, 'bulk_install_ajax']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        add_filter('plugin_action_links', [$this, 'add_marrison_action_links'], 10, 2);
        add_filter('plugin_row_meta', [$this, 'add_plugin_row_meta'], 10, 2);
    }

    public function plugin_info($false, $action, $args) {
        if ($action !== 'plugin_information') return $false;
        
        // Try trait logic first
        $res = $this->trait_plugin_info($false, $action, $args);
        if ($res !== $false && is_object($res)) return $res;

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

    public function install_plugin() {
        $slug = sanitize_text_field($_GET['slug'] ?? '');
        check_admin_referer('marrison_install_' . $slug);

        $result = $this->perform_update($slug);

        if (is_wp_error($result)) {
            wp_die($result->get_error_message());
        }

        wp_redirect(admin_url('admin.php?page=marrison-installer&updated=' . $slug));
        exit;
    }

    public function bulk_install() {
        check_admin_referer('marrison_bulk_install');

        $updated = [];
        foreach ($_POST['plugins'] ?? [] as $slug) {
            $result = $this->perform_update(sanitize_text_field($slug));
            if ($result && !is_wp_error($result)) {
                $updated[] = $slug;
            }
        }

        $query = http_build_query(['bulk_updated' => $updated]);
        wp_redirect(admin_url('admin.php?page=marrison-installer&' . $query));
        exit;
    }

    public function clear_cache() {
        check_admin_referer('marrison_clear_cache');
        
        $this->delete_internal_cache();
        delete_transient('marrison_available_installs'); // Keep for backward compat if needed, but trait handles 'marrison_available_updates_v2'
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

        $this->delete_internal_cache();
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
            $result = $this->perform_update($slug);
        }

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } elseif ($result) {
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
                $result = $this->perform_update($slug);
            }
            
            if (is_wp_error($result)) {
                $results[$slug] = $result->get_error_message();
            } else {
                $results[$slug] = $result;
                if ($result) {
                    $success_count++;
                }
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
        wp_enqueue_style('mci-admin-style', plugin_dir_url(__FILE__) . 'assets/css/admin-style.css', [], '2.1.7');
        
        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                $(document).on("click", ".mci-plugin-grid a.mci-ajax-action", function(e) {
                    e.preventDefault();
                    var url = $(this).attr("href");
                    var parts = url.split("?");
                    if (parts.length < 2) return;
                    
                    var urlParams = new URLSearchParams(parts[1]);
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

                $("#mci-select-all").on("change", function() {
                    var checked = $(this).is(":checked");
                    $(".mci-bulk-checkbox").prop("checked", checked).trigger("change.mci");
                });

                $(document).on("change.mci", ".mci-bulk-checkbox", function() {
                    var $all = $(".mci-bulk-checkbox");
                    var $checked = $(".mci-bulk-checkbox:checked");
                    $("#mci-select-all").prop("checked", $all.length > 0 && $all.length === $checked.length);
                });

                $("#mci-plugin-search").on("input", function() {
                    var q = ($(this).val() || "").toString().toLowerCase().trim();
                    $("#the-list .mci-plugin-item").each(function() {
                        var $item = $(this);
                        var hay = ($item.data("search") || "").toString().toLowerCase();
                        var match = !q || hay.indexOf(q) !== -1;
                        $item.toggle(match);
                    });
                });

                function startProgress() {
                    $("#marrison-progress-container").slideDown();
                    $(".mci-dashboard-content, .mci-header-actions").css("opacity", "0.5").css("pointer-events", "none");
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
                    $(".mci-dashboard-content, .mci-header-actions").css("opacity", "1").css("pointer-events", "auto");
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
            'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4NCjwhLS0gR2VuZXJhdG9yOiBBZG9iZSBJbGx1c3RyYXRvciAyNS4wLjAsIFNWRyBFeHBvcnQgUGx1Zy1JbiAuIFNWRyBWZXJzaW9uOiA2LjAwIEJ1aWxkIDApICAtLT4NCjxzdmcgdmVyc2lvbj0iMS4xIiBpZD0iTGl2ZWxsb18xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB4PSIwcHgiIHk9IjBweCINCgkgdmlld0JveD0iMCAwIDEzMDAgMTMwMCIgc3R5bGU9ImVuYWJsZS1iYWNrZ3JvdW5kOm5ldyAwIDAgMTMwMCAxMzAwOyIgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+DQo8Zz4NCgk8cGF0aCBkPSJNNjUwLDExLjFDMjk3LjEsMTEuMSwxMS4xLDI5Ny4xLDExLjEsNjUwczI4Ni4xLDYzOC45LDYzOC45LDYzOC45czYzOC45LTI4Ni4xLDYzOC45LTYzOC45UzEwMDIuOSwxMS4xLDY1MCwxMS4xeg0KCQkgTTQ0OC43LDg1My4zbC0yNS44LTkzLjlIMjkzbC0yMy40LDkzLjlIMTQ0LjJMMjc0LDM4OS43aDE3MS45bDEzNS43LDQ2My41SDQ0OC43eiBNOTk2LjIsODUzLjNWNzAzLjJjMC03MS45LDEuNC0xNzAuMywzLjQtMjI1LjINCgkJaC00LjNjLTE3LjUsMTAwLjMtNTAuMiwyNTQuOS04MC4yLDM3NS4zSDgxMi40Yy0yMy4yLTExNC45LTU0LjktMjczLjEtNzEuMS0zNzUuMmgtNC41YzMuNiw1NC4yLDUuOSwxNDguOSw1LjksMjI4Ljh2MTQ2LjRINjI4LjYNCgkJVjM4OS43aDE4My45YzIxLDgzLjcsNDguNSwyMjcuMSw1Ny44LDI5NC42aDIuM2MxMC42LTcyLjcsNDIuOC0xOTkuMyw2Ny41LTI5NC42aDE3OC41djQ2My41SDk5Ni4yeiIvPg0KCTxwYXRoIGQ9Ik0zNTUuNCw0NzUuNWMtOS40LDUxLTI1LjIsMTE4LjItNDIuNSwxODQuM2g4OWMtMTguOC02OS43LTM0LjktMTM2LjYtNDQuNS0xODQuM0gzNTUuNHoiLz4NCjwvZz4NCjwvc3ZnPg0K',
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

            <?php
            if ($active_tab == 'general') {
                $this->render_general_tab();
            } else {
                $this->render_howto_tab();
            }
            ?>
        </div>
        <?php
    }

    private function render_general_tab() {
        ?>
        <div class="mci-card">
            <h3>Repository URL</h3>
            <p>Inserisci l'URL della cartella dove risiede il file <code>index.php</code> che elenca i plugin.</p>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="marrison_save_repo_url">
                <?php wp_nonce_field('marrison_save_repo_url'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="marrison_repo_url">URL Repository</label></th>
                        <td>
                            <input type="url" name="marrison_repo_url" id="marrison_repo_url" value="<?php echo esc_attr(get_option('marrison_repo_url')); ?>" class="regular-text" placeholder="https://example.com/repo/">
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="Salva Modifiche">
                    <input type="submit" name="marrison_remove_repo_url" class="button" value="Ripristina Default">
                </p>
            </form>
        </div>

        <div class="mci-card">
            <h3>Gestione Cache</h3>
            <p>Forza la pulizia della cache degli aggiornamenti.</p>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="marrison_clear_cache">
                <?php wp_nonce_field('marrison_clear_cache'); ?>
                <input type="submit" class="button button-secondary" value="Pulisci Cache">
            </form>
        </div>
        <?php
    }

    private function render_howto_tab() {
        ?>
        <div class="mci-card">
            <h3>Come configurare il repository</h3>
            <p>Per creare il tuo repository personale, devi caricare un file <code>index.php</code> nel tuo server.</p>
            <p>Scarica il file di esempio qui sotto e caricalo nella cartella del tuo repository.</p>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="marrison_download_repo_file">
                <?php wp_nonce_field('marrison_download_repo_file'); ?>
                <input type="submit" class="button button-primary" value="Scarica index.php di esempio">
            </form>
        </div>
        <?php
    }

    public function admin_page() {
        $installs = $this->get_available_updates();
        $installed_plugins = get_plugins();
        $active_plugins_option = get_option('active_plugins');
        ?>
        <div class="mci-wrap">
            <div class="mci-header">
                <h1><span class="dashicons dashicons-download"></span> Marrison Installer</h1>
                <div class="mci-header-actions">
                    <div class="mci-search">
                        <span class="dashicons dashicons-search"></span>
                        <input type="search" id="mci-plugin-search" placeholder="Cerca plugin..." aria-label="Cerca plugin">
                    </div>
                    <form id="marrison-bulk-form" method="post" style="display:inline;">
                        <?php wp_nonce_field('marrison_bulk_install'); ?>
                        <button type="submit" class="button button-primary mci-button">Installa Selezionati</button>
                    </form>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="marrison_force_check_mci">
                        <?php wp_nonce_field('marrison_force_check_mci'); ?>
                        <button type="submit" class="button button-secondary mci-button">
                            <span class="dashicons dashicons-update"></span> Ricarica Lista
                        </button>
                    </form>
                    <a href="<?php echo admin_url('admin.php?page=marrison-installer-settings'); ?>" class="button button-secondary mci-button">
                        <span class="dashicons dashicons-admin-settings"></span> Impostazioni
                    </a>
                </div>
            </div>

            <div id="marrison-progress-container" class="mci-progress-container" style="display:none;">
                <div class="mci-progress-bar-wrap">
                    <div id="marrison-progress-bar" class="mci-progress-bar" style="width: 0%;"></div>
                </div>
                <div class="mci-progress-info">
                    <span id="marrison-progress-status">Inizializzazione...</span>
                    <span id="marrison-progress-percent">0%</span>
                </div>
            </div>

            <?php if (isset($_GET['updated'])): ?>
                <div class="mci-notice mci-notice-success">
                    <span class="dashicons dashicons-yes-alt"></span> Plugin <strong><?php echo esc_html($_GET['updated']); ?></strong> installato con successo!
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['mci_checked'])): ?>
                <div class="mci-notice mci-notice-success">
                    <span class="dashicons dashicons-yes-alt"></span> Lista aggiornata.
                </div>
            <?php endif; ?>

            <div class="mci-dashboard-content">
                    <?php if (empty($installs)): ?>
                        <div class="mci-card mci-empty-state">
                            <span class="dashicons dashicons-info"></span>
                            <p>Nessun plugin disponibile nel repository.</p>
                            <a href="<?php echo admin_url('admin.php?page=marrison-installer-settings'); ?>">Controlla URL Repository</a>
                        </div>
                    <?php else: ?>
                        <div class="mci-select-all-wrap">
                             <label><input type="checkbox" id="mci-select-all" /> Seleziona tutto</label>
                        </div>
                        <div class="mci-plugin-grid" id="the-list">
                            <?php 
                            foreach ($installs as $plugin): 
                                $slug = $plugin['slug'];
                                $is_installed = false;
                                $is_active = false;
                                $current_version = '';
                                
                                foreach ($installed_plugins as $file => $data) {
                                    $dir = dirname($file);
                                    if ($dir === '.' || $dir === '') $dir = basename($file, '.php');
                                    
                                    if ($dir === $slug) {
                                        $is_installed = true;
                                        $current_version = $data['Version'];
                                        if (in_array($file, $active_plugins_option)) {
                                            $is_active = true;
                                        }
                                        break;
                                    }
                                }
                                
                                $status_class = 'mci-status-available';
                                if ($is_active) {
                                    $status_class = 'mci-status-active';
                                } elseif ($is_installed) {
                                    $status_class = 'mci-status-installed';
                                }
                            ?>
                                <div class="mci-plugin-item <?php echo $status_class; ?>" data-slug="<?php echo esc_attr($slug); ?>" data-search="<?php echo esc_attr(trim(($plugin['name'] ?? '') . ' ' . $slug)); ?>">
                                    <div class="mci-item-check">
                                        <input type="checkbox" name="plugins[]" value="<?php echo esc_attr($slug); ?>" class="mci-bulk-checkbox">
                                    </div>
                                    <div class="mci-item-content">
                                        <div class="mci-item-header">
                                            <strong><?php echo esc_html($plugin['name']); ?></strong>
                                        </div>
                                        <div class="mci-item-meta">
                                            v<?php echo esc_html($plugin['version']); ?>
                                            <?php if ($is_installed): ?>
                                                <span class="mci-dot">·</span> Inst: v<?php echo esc_html($current_version); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="mci-item-actions">
                                         <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=marrison_install_plugin&slug=' . $slug), 'marrison_install_' . $slug); ?>" class="button mci-button-sm mci-ajax-action">
                                            <?php echo $is_installed ? 'Reinstalla' : 'Installa'; ?>
                                         </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

new Marrison_Custom_Installer_Plugin();
