<?php
// api_apps.php
header('Content-Type: application/json');
$dir = './Aplicaciones/';

// Creamos la carpeta automáticamente si no existe en el servidor
if (!file_exists($dir)) mkdir($dir, 0777, true);

$files = array_diff(scandir($dir), array('.', '..'));
$apps = [];

foreach ($files as $file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    // Filtramos únicamente archivos ejecutables web válidos
    if (in_array($ext, ['html', 'php'])) {
        $apps[] = $file;
    }
}

// Devolvemos la lista indexada de ficheros limpia
echo json_encode(array_values($apps));
?>