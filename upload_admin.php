<?php
header('Content-Type: application/json');
require_once __DIR__ . '/auth_check.php';
check_admin_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $dir = './uploads/';
    if (!file_exists($dir)) { mkdir($dir, 0777, true); }
    
    $fileName = time() . "_" . basename($_FILES['file']['name']);
    $targetPath = $dir . $fileName;

    if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
        echo json_encode(["status" => "success", "url" => $targetPath, "name" => $_FILES['file']['name']]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error"]);
    }
}
?>