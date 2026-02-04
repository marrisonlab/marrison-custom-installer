=== Marrison Custom Installer ===
Contributors: Angelo Marra
Tags: installer, plugin-manager, custom-repository
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0
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
