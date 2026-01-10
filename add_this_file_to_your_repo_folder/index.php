<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Percorso della cartella corrente
$directory = __DIR__;

// Array per memorizzare i plugin trovati
$plugins = [];

// Scansiona tutti i file ZIP nella cartella
$files = glob($directory . '/*.zip');

foreach ($files as $zip_file) {
    $zip = new ZipArchive();
    
    if ($zip->open($zip_file) === true) {
        // Cerca il file principale del plugin (che contiene il Plugin Name)
        $plugin_info = null;
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            
            // Cerca file .php nel primo livello (non in sottocartelle profonde)
            if (preg_match('/^[^\/]+\/[^\/]+\.php$/', $filename) && !strpos($filename, 'vendor/')) {
                $content = $zip->getFromIndex($i);
                
                // Verifica se contiene l'header del plugin
                if (preg_match('/Plugin Name:/i', $content)) {
                    $plugin_info = [
                        'content' => $content,
                        'file' => $filename
                    ];
                    break;
                }
            }
        }
        
        $zip->close();
        
        if ($plugin_info) {
            // Estrae le informazioni dall'header del plugin
            $name = '';
            $version = '';
            $author = '';
            $description = '';
            $requires = '5.0';
            $requires_php = '7.0';
            $tested = '6.4';
            
            if (preg_match('/Plugin Name:\s*(.+)/i', $plugin_info['content'], $matches)) {
                $name = trim($matches[1]);
            }
            
            if (preg_match('/Version:\s*(.+)/i', $plugin_info['content'], $matches)) {
                $version = trim($matches[1]);
            }
            
            if (preg_match('/Author:\s*(.+)/i', $plugin_info['content'], $matches)) {
                $author = trim($matches[1]);
            }
            
            if (preg_match('/Description:\s*(.+)/i', $plugin_info['content'], $matches)) {
                $description = trim($matches[1]);
            }
            
            if (preg_match('/Requires at least:\s*(.+)/i', $plugin_info['content'], $matches)) {
                $requires = trim($matches[1]);
            }
            
            if (preg_match('/Requires PHP:\s*(.+)/i', $plugin_info['content'], $matches)) {
                $requires_php = trim($matches[1]);
            }
            
            if (preg_match('/Tested up to:\s*(.+)/i', $plugin_info['content'], $matches)) {
                $tested = trim($matches[1]);
            }
            
            // Estrae lo slug dal nome del file ZIP
            $zip_filename = basename($zip_file, '.zip');
            
            // Rimuove la versione dal nome del file se presente
            // Es: jet-engine-3.5.1 -> jet-engine
            $slug = preg_replace('/-\d+(\.\d+)*(-custom)?$/', '', $zip_filename);
            
            // Se non riesce a estrarre lo slug, usa il nome della cartella interna
            if (empty($slug)) {
                $first_dir = dirname($plugin_info['file']);
                $slug = $first_dir;
            }
            
            // URL completo per il download
            $download_url = 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/' . basename($zip_file);
            
            $plugins[] = [
                'name' => $name ?: 'Unknown Plugin',
                'slug' => $slug,
                'version' => $version ?: '1.0.0',
                'author' => $author ?: 'Unknown',
                'description' => $description ?: 'Plugin personalizzato',
                'requires' => $requires,
                'requires_php' => $requires_php,
                'tested' => $tested,
                'download_url' => $download_url,
                'homepage' => '',
                'changelog' => '<h4>' . $version . '</h4><p>Versione personalizzata aggiornata</p>',
                'zip_file' => basename($zip_file),
                'file_size' => filesize($zip_file),
                'last_modified' => date('Y-m-d H:i:s', filemtime($zip_file))
            ];
        }
    }
}

// Ordina per nome
usort($plugins, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});

// Restituisce il JSON
echo json_encode($plugins, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
