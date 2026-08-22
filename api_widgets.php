<?php
// api_widgets.php
header('Content-Type: application/json');
$dir = './widgets/';

if (!file_exists($dir)) mkdir($dir, 0777, true);

$files = array_diff(scandir($dir), array('.', '..'));
$widgets = [];

foreach ($files as $file) {
    $info = pathinfo($file);
    $ext = strtolower($info['extension'] ?? '');
    
    if (in_array($ext, ['html', 'php'])) {
        $baseName = $info['filename'];
        $preview = file_exists($dir . $baseName . '.png') ? $dir . $baseName . '.png' : null;
        
        $widgets[] = [
            'id' => preg_replace('/[^a-zA-Z0-9_-]/', '', $baseName),
            'name' => ucfirst(str_replace(['_', '-'], ' ', $baseName)),
            'file' => $file,
            'preview' => $preview
        ];
    }
}

echo json_encode(array_values($widgets));
?>