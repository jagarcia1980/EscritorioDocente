<?php
require_once 'config.php';


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('ADMIN_SECRET', $admin_secret);

function check_admin_auth() {
    if (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
        return true;
    }

    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
        if (hash_equals(ADMIN_SECRET, $matches[1])) {
            return true;
        }
    }

    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Acceso denegado: No no no, no has dicho la palabra mágica"]);
    exit;
}