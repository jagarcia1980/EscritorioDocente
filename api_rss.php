<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$url = isset($_GET['url']) ? $_GET['url'] : '';

if (empty($url)) {
    echo json_encode(['status' => 'error', 'message' => 'No se proporcionó URL']);
    exit;
}

// cURL para simular ser un navegador y evitar bloqueos
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
$xmlContent = curl_exec($ch);
curl_close($ch);

if (!$xmlContent) {
    echo json_encode(['status' => 'error', 'message' => 'No se pudo descargar el feed']);
    exit;
}

// XML a un objeto
$xml = @simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOCDATA);

if (!$xml) {
    echo json_encode(['status' => 'error', 'message' => 'XML inválido o malformado']);
    exit;
}

$data = [
    'status' => 'ok',
    'feed' => ['title' => (string)($xml->channel->title ?? 'Aviso del sistema')],
    'items' => []
];

if (isset($xml->channel->item)) {
    foreach ($xml->channel->item as $item) {
        $data['items'][] = [
            'title' => (string)$item->title,
            'link' => (string)$item->link,
            'pubDate' => (string)$item->pubDate,
            'description' => (string)$item->description
        ];
    }
}

echo json_encode($data);
?>