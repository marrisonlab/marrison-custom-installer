<?php
defined('ABSPATH') || exit;

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
                    <input type="url" id="marrison_repo_url" name="marrison_repo_url" value="<?php echo esc_attr(get_option('marrison_repo_url', 'https://marrisonlab.com/wp-repo/')); ?>" class="regular-text">
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

    <h3>Strumenti Sviluppatore</h3>
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <?php wp_nonce_field('marrison_force_check_mcu'); ?>
        <input type="hidden" name="action" value="marrison_force_check_mcu">
        <input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('admin.php?page=marrison-updater-settings&mcu_checked=1')); ?>">
        <button class="button button-secondary">Forza controllo aggiornamenti MCU</button>
        <p class="description">Usa questo pulsante se hai appena rilasciato una nuova versione su GitHub e non viene rilevata.</p>
    </form>
</div>
