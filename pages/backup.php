<?php
defined('ABSPATH') || exit;

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
    <div id="marrison-restore-progress" class="notice notice-warning marrison-progress-box">
        <div class="marrison-progress-container">
            <div class="spinner is-active marrison-spinner"></div>
            <div style="flex: 1;">
                <div id="marrison-restore-status" class="marrison-progress-status">Ripristino in corso...</div>
                <div class="marrison-progress-bar-bg marrison-progress-bar-bg-red">
                    <div id="marrison-restore-bar" class="marrison-progress-bar-fill marrison-progress-bar-fill-red" style="width: 100%;"></div>
                </div>
                <div id="marrison-restore-info" class="marrison-progress-info">Attendere prego, non chiudere la pagina...</div>
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
                             $version_class = 'marrison-version-warning';
                         }
                     }
                ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($plugin_name); ?></strong>
                            <br><small><?php echo esc_html($info['slug']); ?></small>
                        </td>
                        <td>
                            <span class="marrison-badge">
                                <?php echo esc_html($info['backup_version']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="<?php echo $version_class; ?>">
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
    
</div>
