<?php
// api_public.php
header('Content-Type: application/json');
$dir = './public/';
if (!file_exists($dir)) mkdir($dir, 0777, true);

$action = $_GET['action'] ?? '';

if ($action === 'upload') {
    if (!isset($_FILES['file'])) {
        echo json_encode(['status' => 'error', 'msg' => 'No se recibió ningún archivo.']);
        exit;
    }

    $file = $_FILES['file'];
    
    // Limite de tamaño: 30 MB (30 * 1024 * 1024 bytes)
    if ($file['size'] > 31457280) {
        echo json_encode(['status' => 'error', 'msg' => 'El archivo supera el límite de 30 MB.']);
        exit;
    }

    // Filtro de extensiones 
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $banned = ['iso', 'exe', 'cmd', 'sh', 'bat'];
    if (in_array($ext, $banned)) {
        echo json_encode(['status' => 'error', 'msg' => 'Extensión de archivo prohibida por seguridad.']);
        exit;
    }

    $name = preg_replace("/[^a-zA-Z0-9\._-]/", "_", basename($file['name']));
    $target = $dir . time() . '_' . $name;
    
    if (move_uploaded_file($file['tmp_name'], $target)) {
        echo json_encode(['status' => 'success', 'url' => $target, 'name' => $file['name']]);
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Error moviendo el archivo al servidor.']);
    }

} elseif ($action === 'save_json') {
    // Guarda el array de iconos de la carpeta pública
    $data = file_get_contents('php://input');
    file_put_contents($dir . 'public_items.json', $data);
    echo json_encode(['status' => 'success']);

} elseif ($action === 'get_json') {
    // Devuelve el estado actual de la carpeta
    if (file_exists($dir . 'public_items.json')) {
        echo file_get_contents($dir . 'public_items.json');
    } else {
        echo json_encode([]);
    }

} elseif ($action === 'shorten') {
    $url = $_GET['url'] ?? '';
    if (!empty($url)) {
        // Consultamos a TinyURL de servidor a servidor de manera infalible
        $short = @file_get_contents("https://tinyurl.com/api-create.php?url=" . urlencode($url));
        if ($short) {
            echo json_encode(['status' => 'success', 'short' => trim($short)]);
            exit;
        }
    }
    echo json_encode(['status' => 'error']);
    exit;
}
?>