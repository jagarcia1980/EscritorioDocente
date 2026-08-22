<?php
// ==========================================
//  CONFIGURACIÓN PARA AUTO-INSTALACIÓN
// ==========================================

// ent.pruebas
//$host = ''; 
//$db   = ''; 
//$user = ''; 
//$pass = '';

// ent.producción
$host = '127.0.0.1'; 
$db   = ''; 
$user = ''; 
$pass = '';


$apiKey = getenv('GOOGLE_API_KEY') ?: ''; 
$GEMINI_API_KEY  = $apiKey;
$charset = 'utf8mb4';
$CloudConvert_apiKey = ""; 
?>
