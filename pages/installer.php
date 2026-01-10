<?php
defined('ABSPATH') || exit;

$updates = $this->get_available_updates();
$plugins = get_plugins();
$installed_slugs = $_GET['installed'] ?? [];
if (!is_array($installed_slugs)) $installed_slugs = [$installed_slugs];

// Controlla aggiornamenti temi
$themes_transient = get_site_transient('update_themes');
$themes_count = isset($themes_transient->response) ? count($themes_transient->response) : 0;

// Controlla aggiornamenti traduzioni
$core_transient = get_site_transient('update_core');
$translations_count = 0;
if (isset($core_transient->translations) && is_array($core_transient->translations)) {
    $translations_count = count($core_transient->translations);
}
?>
<div class="wrap">
    <h1>Installer - Repository Privato</h1>

    <?php if ($themes_count > 0 || $translations_count > 0): ?>
        <div class="marrison-updates-toolbar" style="margin-bottom: 20px; background: #fff; padding: 15px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2 style="margin-top: 0; font-size: 1.1em; margin-bottom: 10px;">Aggiornamenti Ufficiali Disponibili</h2>
            
            <?php if ($themes_count > 0): ?>
                <?php $theme_nonce = wp_create_nonce('marrison_auto_update_themes'); ?>
                <button class="button button-primary marrison-auto-update-themes-btn" data-nonce="<?php echo esc_attr($theme_nonce); ?>" style="margin-right: 10px;">
                    <span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>
                    Aggiorna <?php echo $themes_count; ?> Temi
                </button>
            <?php endif; ?>

            <?php if ($translations_count > 0): ?>
                <?php $trans_nonce = wp_create_nonce('marrison_auto_update_translations'); ?>
                <button class="button button-primary marrison-auto-update-translations-btn" data-nonce="<?php echo esc_attr($trans_nonce); ?>">
                    <span class="dashicons dashicons-translation" style="vertical-align: middle; margin-right: 5px;"></span>
                    Aggiorna <?php echo $translations_count; ?> Traduzioni
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($installed_slugs)): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo count($installed_slugs); ?> plugin installati con successo.</p>
        </div>
    <?php endif; ?>

    <!-- Barra di caricamento installazione -->
    <div id="marrison-install-progress" class="notice notice-info marrison-progress-box">
        <div class="marrison-progress-container">
            <div class="spinner is-active marrison-spinner"></div>
            <div style="flex: 1;">
                <div id="marrison-install-status" class="marrison-progress-status">Installazione in corso...</div>
                <div class="marrison-progress-bar-bg">
                    <div id="marrison-install-bar" class="marrison-progress-bar-fill"></div>
                </div>
                <div id="marrison-install-info" class="marrison-progress-info">In attesa...</div>
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

        <div class="marrison-grid-container">
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
                    $card_class = 'marrison-plugin-card' . ($disabled ? ' disabled' : '');
                ?>
                    <div class="<?php echo $card_class; ?>">
                        <div class="marrison-card-header">
                            <h3 class="marrison-card-title"><?php echo esc_html($u['name']); ?></h3>
                            <?php if (!$disabled): ?>
                                <input type="checkbox" name="plugins[]" value="<?php echo esc_attr($slug); ?>" class="marrison-plugin-cb">
                            <?php else: ?>
                                <input type="checkbox" disabled checked class="marrison-plugin-cb">
                            <?php endif; ?>
                        </div>
                        
                        <p class="marrison-card-version"><strong>v</strong> <?php echo esc_html($u['version']); ?></p>
                        
                        <div class="marrison-card-footer">
                            <?php if ($is_active): ?>
                                <span class="dashicons dashicons-yes marrison-icon-success"></span> <span class="marrison-text-success">Attivo</span>
                            <?php elseif ($is_installed): ?>
                                <span class="dashicons dashicons-warning marrison-icon-warning"></span> <span class="marrison-text-warning">Installato (Inattivo)</span>
                            <?php else: ?>
                                <a href="#" class="marrison-install-single-btn" data-slug="<?php echo esc_attr($slug); ?>" style="text-decoration:none;">
                                    <span class="dashicons dashicons-download marrison-icon-download"></span> <span class="marrison-text-download">Disponibile</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </form>
    
</div>
