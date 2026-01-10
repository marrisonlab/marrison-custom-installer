<?php
defined('ABSPATH') || exit;

$updates     = $this->get_available_updates();
$plugins     = get_plugins();
$updated     = $_GET['updated'] ?? '';
$restored    = $_GET['restored'] ?? '';
$bulkUpdated = $_GET['bulk_updated'] ?? [];
if (!is_array($bulkUpdated)) $bulkUpdated = [$bulkUpdated];
$settingsUpdated = $_GET['settings-updated'] ?? '';

?>
<?php
?>
<div class="wrap">
    <h1>Plugin Updater</h1>
    
    <div class="marrison-card">

    <!-- Barra di caricamento -->
    <div id="marrison-update-progress" class="notice notice-info marrison-progress-box">
        <div class="marrison-progress-container">
            <div class="spinner is-active marrison-spinner"></div>
            <div style="flex: 1;">
                <div id="marrison-update-status" class="marrison-progress-status">Aggiornamento in corso...</div>
                <div class="marrison-progress-bar-bg">
                    <div id="marrison-update-bar" class="marrison-progress-bar-fill"></div>
                </div>
                <div id="marrison-update-info" class="marrison-progress-info"></div>
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
                            <strong class="marrison-success-message">✓ Aggiornato</strong>
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

