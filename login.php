<?php
require_once __DIR__ . '/auth_check.php';
header('Content-Type: application/json');

$pass = $_POST['password'] ?? '';

if (hash_equals(ADMIN_SECRET, $pass)) {
    $_SESSION['is_admin'] = true;
    echo json_encode(["status" => "success", "token" => ADMIN_SECRET]);
} else {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Credenciales incorrectas"]);
}