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
                    $btn.replaceWith('<strong class="marrison-success-message">✓ Aggiornato</strong>');
                    
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
        // Nota: wp_create_nonce è PHP, qui dobbiamo passarlo in altro modo o usarne uno esistente.
        // Nel codice originale PHP era: var bulkNonce = '<?php echo wp_create_nonce("marrison_bulk_update"); ?>';
        // Dobbiamo passare questo valore dal PHP tramite wp_localize_script o simile.
        // Per ora usiamo marrisonUpdater.bulkNonce che dovremo aggiungere.
        var bulkNonce = marrisonUpdater.bulkNonce;
        
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
                        $('button[data-slug="' + slug + '"]').replaceWith('<strong class="marrison-success-message">✓ Aggiornato</strong>');
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
                            $('#marrison-status-' + slug).html('<span class="dashicons dashicons-yes" style="color: green;"></span> <strong class="marrison-success-message">Aggiornato</strong>');
                            
                            // Fallback per pulsanti se presenti
                            $('button[data-slug="' + slug + '"]').replaceWith('<strong class="marrison-success-message">✓ Aggiornato</strong>');
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
    
    // Gestione aggiornamento automatico TEMI
    $('.marrison-auto-update-themes-btn').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var nonce = $btn.data('nonce');
        
        // Conferma prima di procedere
        if (!confirm('Sei sicuro di voler aggiornare tutti i temi ufficiali disponibili?')) {
            return;
        }
        
        // Disabilita il pulsante
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>Aggiornamento temi in corso...');
        
        // Mostra la barra di caricamento
        showProgressBar();
        
        // Simula progresso durante la preparazione
        var progress = 0;
        var progressInterval = setInterval(function() {
            progress += Math.random() * 8;
            if (progress > 85) progress = 85;
            updateProgressBar(progress, 'Ricerca aggiornamenti temi ufficiali...', 'Analizzando i temi');
        }, 300);
        
        // Esegui l'aggiornamento via AJAX
        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_auto_update_themes_ajax',
                nonce: nonce
            },
            success: function(response) {
                clearInterval(progressInterval);
                
                if (response.success) {
                    updateProgressBar(100, 'Aggiornamento completato!', response.data.message);
                    
                    $btn.replaceWith('<strong class="marrison-success-message">✓ Temi Aggiornati</strong>');
                    
                    // Ricarica la pagina dopo 2 secondi per mostrare lo stato aggiornato
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    updateProgressBar(0, 'Errore durante l\'aggiornamento', response.data || 'Si è verificato un errore');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>Aggiorna tutti i temi ufficiali');
                }
                
                hideProgressBar();
            },
            error: function() {
                clearInterval(progressInterval);
                updateProgressBar(0, 'Errore di connessione', 'Impossibile contattare il server');
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>Aggiorna tutti i temi ufficiali');
                hideProgressBar();
            }
        });
    });

    // Gestione aggiornamento automatico TRADUZIONI
    $('.marrison-auto-update-translations-btn').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var nonce = $btn.data('nonce');
        
        // Conferma prima di procedere
        if (!confirm('Sei sicuro di voler aggiornare tutte le traduzioni disponibili?')) {
            return;
        }
        
        // Disabilita il pulsante
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-translation" style="vertical-align: middle; margin-right: 5px;"></span>Aggiornamento traduzioni in corso...');
        
        // Mostra la barra di caricamento
        showProgressBar();
        
        // Simula progresso
        var progress = 0;
        var progressInterval = setInterval(function() {
            progress += Math.random() * 8;
            if (progress > 85) progress = 85;
            updateProgressBar(progress, 'Scaricamento traduzioni...', 'Download pacchetti lingua');
        }, 300);
        
        // Esegui l'aggiornamento via AJAX
        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_auto_update_translations_ajax',
                nonce: nonce
            },
            success: function(response) {
                clearInterval(progressInterval);
                
                if (response.success) {
                    updateProgressBar(100, 'Aggiornamento completato!', response.data.message);
                    
                    $btn.replaceWith('<strong class="marrison-success-message">✓ Traduzioni Aggiornate</strong>');
                    
                    // Ricarica la pagina dopo 2 secondi
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    updateProgressBar(0, 'Errore durante l\'aggiornamento', response.data || 'Si è verificato un errore');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-translation" style="vertical-align: middle; margin-right: 5px;"></span>Aggiorna traduzioni');
                }
                
                hideProgressBar();
            },
            error: function() {
                clearInterval(progressInterval);
                updateProgressBar(0, 'Errore di connessione', 'Impossibile contattare il server');
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-translation" style="vertical-align: middle; margin-right: 5px;"></span>Aggiorna traduzioni');
                hideProgressBar();
            }
        });
    });
    
    // ===================== INSTALLER PAGE JS =====================

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
                url: marrisonUpdater.ajaxurl,
                type: 'POST',
                data: {
                    action: 'marrison_update_plugin_ajax', // Usiamo lo stesso handler dell'aggiornamento
                    slug: slug,
                    nonce: marrisonUpdater.bulkNonce,
                    activate: 'true'
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

    // Gestione click su "Disponibile" (Installazione singola)
    $(document).on('click', '.marrison-install-single-btn', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var slug = $btn.data('slug');
        var originalContent = $btn.html();
        
        // Evita doppi click
        if ($btn.hasClass('disabled')) return;
        $btn.addClass('disabled').css('opacity', '0.6');
        
        // Mostra stato loading
        $btn.html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> Installazione...');
        
        $.ajax({
            url: marrisonUpdater.ajaxurl,
            type: 'POST',
            data: {
                action: 'marrison_update_plugin_ajax',
                slug: slug,
                nonce: marrisonUpdater.bulkNonce,
                activate: 'true'
            },
            success: function(response) {
                if (response.success) {
                    $btn.html('<span class="dashicons dashicons-yes" style="color:green;"></span> Installato e Attivo');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    alert('Errore: ' + (response.data || 'Sconosciuto'));
                    $btn.html(originalContent).removeClass('disabled').css('opacity', '1');
                }
            },
            error: function(xhr, status, error) {
                alert('Errore di connessione: ' + error);
                $btn.html(originalContent).removeClass('disabled').css('opacity', '1');
            }
        });
    });

    // ===================== BACKUP PAGE JS =====================

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
            url: marrisonUpdater.ajaxurl,
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
