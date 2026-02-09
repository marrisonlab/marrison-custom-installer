=== Marrison Custom Installer ===
Contributors: Angelo Marra
Tags: installer, plugin-manager, custom-repository
Requires at least: 6.0
Tested up to: 6.9.1
Requires PHP: 7.4
Stable tag: 2.1.7
License: GPL-3.0+
License URI: https://www.gnu.org/licenses/gpl-3.0.txt

== Description ==

This plugin is used to install plugins from a personal repository.
It allows you to manage installations from a custom source.

GitHub Repository: https://github.com/marrisonlab/marrison-custom-installer

== Installation ==

1. Download the file marrison-custom-installer-stable.zip (or latest release).
2. Extract the folder if needed.
3. Upload the plugin to WordPress and activate it.

== Changelog ==

= 2.1.7 =
* UI improvements: Fixed button icon alignment
* UI improvements: Standardized search bar styling
* UI improvements: Fixed menu icon loading
* UI improvements: Status background colors instead of labels
* UI improvements: Grid layout for plugins

= 2.1.6 =
* Fix: Resa l'attivazione indipendente da Marrison Custom Updater (migrazione automatica del file principale).

= 2.1.5 =
* Fix: Migliorata la compatibilità con Marrison Custom Updater evitando conflitti di classi in attivazione.

= 2.1.0 =
* Fix: Risolto il bug della cartella versionata di GitHub che causava il rilevamento di plugin duplicati.
* Fix: Risolto un errore fatale che impediva l'attivazione del plugin in presenza di Marrison Custom Updater.
* Fix: Corretto il layout della pagina principale che appariva sformattato a causa di conflitti CSS.
* Miglioramento: Riorganizzata l'interfaccia utente per un layout più compatto e funzionale.
* Miglioramento: Aggiunta la pre-selezione automatica dei plugin che necessitano di un aggiornamento.

= 2.0 =
* Core: Implementazione di UpdateOperationsTrait per una gestione robusta degli aggiornamenti (condiviso con Marrison Custom Updater).
* Fix: Risolto problema di rinomina cartelle per le release GitHub (es. plugin-v1.0.0 viene correttamente rinominato in plugin).
* Feature: Aggiunta creazione automatica di backup prima di aggiornamenti o installazioni.
* Dev: Unificazione logica filesystem e gestione archivi zip.

= 1.9 =
* UI: Unificazione grafica con Marrison Custom Updater (stile moderno, card compatte).
* Feature: Aggiunta tab "Guida & Download" nelle impostazioni con download diretto del file index.php.
* Fix: Ripristinata barra di progresso per installazioni e aggiornamenti.
* Dev: Migrazione stili inline a file CSS dedicato (assets/css/admin-style.css).

= 1.8 =
* Fix: Risolto conflitto cache chiavi con Marrison Custom Updater.

= 1.7 =
* Layout: Grid view changed to 3 columns on desktop.
* Core: Removed default repository URL (requires manual setup).
* Fix: Prevented connection attempts when no repository URL is set.

= 1.6 =
* Initial release as Marrison Custom Installer.
* Removed private repo plugin update functionality (kept only install/manual update).
* Removed JSON authorization management.
