<?php
require_once __DIR__ . '/auth_check.php';
check_admin_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['json_data'])) {
    $dir = './uploads/';
    if (!file_exists($dir)) { mkdir($dir, 0777, true); }
    file_put_contents($dir . 'admin_desktop.json', $_POST['json_data']);
    echo "Configuración Admin guardada";
}
?>