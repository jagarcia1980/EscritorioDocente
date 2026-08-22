<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    // Auto-creación de la tabla de progreso si no existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS book_progress (
        id INT AUTO_INCREMENT PRIMARY KEY,
        book_name VARCHAR(255) NOT NULL,
        user_id VARCHAR(50) NOT NULL,
        page INT DEFAULT 1,
        percentage FLOAT DEFAULT 0,
        flags LONGTEXT,
        drawings LONGTEXT,
        last_read INT NOT NULL,
        UNIQUE KEY unique_book_user (book_name, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    die(json_encode(['error' => 'Error de base de datos: ' . $e->getMessage()]));
}

$apiKey = $CloudConvert_apiKey ?? '';

$dir = __DIR__ . '/Libros';
if (!is_dir($dir)) mkdir($dir, 0777, true);

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $book = basename($_POST['book'] ?? $_GET['book'] ?? '');
    
    if ($_GET['action'] === 'upload' && isset($_FILES['file'])) {
        $fileName = basename($_FILES['file']['name']);
        $target = "$dir/" . $fileName;
        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['pdf', 'epub'])) die(json_encode(['error' => 'Formato no soportado']));
        
        if ($ext === 'epub') {
            set_time_limit(300);
            
            $ch = curl_init("https://api.cloudconvert.com/v2/jobs");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer $apiKey", "Content-Type: application/json"],
                CURLOPT_POSTFIELDS => json_encode(["tasks" => [
                    "import" => ["operation" => "import/upload"],
                    "convert" => ["operation" => "convert", "input" => "import", "input_format" => "epub", "output_format" => "pdf"],
                    "export" => ["operation" => "export/url", "input" => "convert"]
                ]])
            ]);
            $jobRes = curl_exec($ch);
            $job = json_decode($jobRes, true)['data'] ?? null;
            if (!$job) die(json_encode(['error' => 'Error API CloudConvert']));

            $uploadTask = array_values(array_filter($job['tasks'], fn($t) => $t['name'] === 'import'))[0] ?? null;
            $params = $uploadTask['result']['form']['parameters'] ?? [];
            $params['file'] = new CURLFile($_FILES['file']['tmp_name'], '', $fileName);

            $chUp = curl_init($uploadTask['result']['form']['url']);
            curl_setopt_array($chUp, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params]);
            curl_exec($chUp);

            do {
                sleep(3);
                $chStat = curl_init("https://api.cloudconvert.com/v2/jobs/" . $job['id']);
                curl_setopt_array($chStat, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $apiKey"]]);
                $jobStatus = json_decode(curl_exec($chStat), true)['data'];
                if ($jobStatus['status'] === 'error') die(json_encode(['error' => 'Error en conversión CloudConvert']));
            } while ($jobStatus['status'] !== 'finished');

            $exportTask = array_values(array_filter($jobStatus['tasks'], fn($t) => $t['name'] === 'export'))[0] ?? null;
            $exportUrl = $exportTask['result']['files'][0]['url'] ?? '';

            if ($exportUrl) {
                file_put_contents($target, file_get_contents($exportUrl), LOCK_EX);
            } else {
                die(json_encode(['error' => 'Fallo al exportar PDF']));
            }
        } else {
            move_uploaded_file($_FILES['file']['tmp_name'], $target);
        }
        die(json_encode(['success' => true]));
    }

    if (!$book || !file_exists("$dir/$book")) die(json_encode(['error' => 'Libro no encontrado']));
    $jsonFile = "$dir/$book.json";
    $data = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : ['tags' => [], 'shares' => 0];
    
    if ($_GET['action'] === 'save_tags') {
        $newTags = array_filter(array_map('trim', explode(',', $_POST['tags'])));
        $data['tags'] = array_unique(array_merge($data['tags'] ?? [], $newTags));
        file_put_contents($jsonFile, json_encode($data), LOCK_EX);
        die(json_encode(['success' => true]));
    }

    if ($_GET['action'] === 'remove_tag') {
        $tagToRemove = $_POST['tag'] ?? '';
        $data['tags'] = array_values(array_filter($data['tags'] ?? [], fn($t) => $t !== $tagToRemove));
        file_put_contents($jsonFile, json_encode($data), LOCK_EX);
        die(json_encode(['success' => true]));
    }

    if ($_GET['action'] === 'save_cover') {
        $data['cover'] = $_POST['cover'] ?? '';
        file_put_contents($jsonFile, json_encode($data), LOCK_EX);
        die(json_encode(['success' => true]));
    }

    if ($_GET['action'] === 'save') {
        $user = $_POST['userid'] ?? 'default';
        $page = is_numeric($_POST['page']) ? (int)$_POST['page'] : 1;
        $percentage = (float)($_POST['percentage'] ?? 0);
        $flags = json_encode($_POST['flags'] ?? []);
        $drawings = json_encode($_POST['drawings'] ?? []);
        $time = time();

        $stmt = $pdo->prepare("INSERT INTO book_progress (book_name, user_id, page, percentage, flags, drawings, last_read) 
                               VALUES (?, ?, ?, ?, ?, ?, ?) 
                               ON DUPLICATE KEY UPDATE 
                               page=VALUES(page), percentage=VALUES(percentage), flags=VALUES(flags), drawings=VALUES(drawings), last_read=VALUES(last_read)");
        $stmt->execute([$book, $user, $page, $percentage, $flags, $drawings, $time]);
        
        die(json_encode(['success' => true]));
    }
    
    if ($_GET['action'] === 'load') {
        $user = $_GET['userid'] ?? 'default';
        
        $stmt = $pdo->prepare("SELECT page, percentage, flags, drawings FROM book_progress WHERE book_name = ? AND user_id = ?");
        $stmt->execute([$book, $user]);
        $uData = $stmt->fetch();
        
        $response = [
            'page' => $uData ? (int)$uData['page'] : 1,
            'percentage' => $uData ? (float)$uData['percentage'] : 0,
            'flags' => $uData ? json_decode($uData['flags'], true) : [],
            'drawings' => $uData ? json_decode($uData['drawings'], true) : [],
            'hasCover' => !empty($data['cover'])
        ];
        
        die(json_encode($response));
    }

    if ($_GET['action'] === 'share') {
        $user = uniqid('u_');
        $currentUser = $_POST['userid'] ?? 'default';
        
        // Copiar progreso en BBDD
        $stmt = $pdo->prepare("SELECT * FROM book_progress WHERE book_name = ? AND user_id = ?");
        $stmt->execute([$book, $currentUser]);
        $uData = $stmt->fetch();
        
        $time = time();
        if ($uData) {
            $stmtIns = $pdo->prepare("INSERT INTO book_progress (book_name, user_id, page, percentage, flags, drawings, last_read) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->execute([$book, $user, $uData['page'], $uData['percentage'], $uData['flags'], $uData['drawings'], $time]);
        } else {
            $stmtIns = $pdo->prepare("INSERT INTO book_progress (book_name, user_id, last_read) VALUES (?, ?, ?)");
            $stmtIns->execute([$book, $user, $time]);
        }
        
        $data['shares'] = ($data['shares'] ?? 0) + 1;
        file_put_contents($jsonFile, json_encode($data), LOCK_EX);
        
        $url = "http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?bookid=" . urlencode($book) . "&userid=$user";
        $short = @file_get_contents("https://tinyurl.com/api-create.php?url=" . urlencode($url));
        die(json_encode(['url' => $short ?: $url]));
    }
}

$currentBook = $_GET['bookid'] ?? null;
$currentUser = $_GET['userid'] ?? 'default';
$search = strtolower($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'recent_read';
$isGuest = $currentUser !== 'default';

// Precargar todo el progreso del usuario actual para evitar queries en bucle
$stmt = $pdo->prepare("SELECT book_name, page, percentage, flags, last_read FROM book_progress WHERE user_id = ?");
$stmt->execute([$currentUser]);
$userProgress = [];
while ($row = $stmt->fetch()) {
    $userProgress[$row['book_name']] = $row;
}

$rawBooks = array_merge(glob("$dir/*.pdf"), glob("$dir/*.epub"));
$books = [];

foreach ($rawBooks as $path) {
    $file = basename($path);
    $jsonFile = "$dir/$file.json";
    $data = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : ['tags' => [], 'shares' => 0];
    
    $userData = $userProgress[$file] ?? null;
    if ($isGuest && !$userData) continue;

    $tags = $data['tags'] ?? [];
    if ($search) {
        $match = strpos(strtolower($file), $search) !== false;
        foreach ($tags as $t) if (strpos(strtolower($t), $search) !== false) $match = true;
        if (!$match) continue;
    }
    
    $books[] = [
        'file' => $file,
        'ext' => pathinfo($file, PATHINFO_EXTENSION),
        'last_read' => $userData['last_read'] ?? 0,
        'added' => filemtime($path),
        'size' => round(filesize($path) / 1048576, 1) . ' MB',
        'percentage' => round($userData['percentage'] ?? 0),
        'flags_count' => isset($userData['flags']) ? count(json_decode($userData['flags'], true) ?? []) : 0,
        'shares' => $data['shares'] ?? 0,
        'tags' => $tags,
        'cover' => $data['cover'] ?? null
    ];
}

usort($books, function($a, $b) use ($sort) {
    if ($sort === 'recent_added') return $b['added'] <=> $a['added'];
    if ($sort === 'title') return strnatcasecmp($a['file'], $b['file']);
    return $b['last_read'] <=> $a['last_read'];
});
?>
<!DOCTYPE html>
<html lang="es" class="light" data-palette="0">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lector Avanzado</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js"></script>
    <script>tailwind.config = { darkMode: 'class' }</script>
    <style>
        body { transition: background-color 0.3s, color 0.3s; }
        html.light[data-palette="0"] body { background-color: #f2efe9; color: #111827; }
        html.light[data-palette="1"] body { background-color: #ffffff; color: #1f2937; }
        html.light[data-palette="2"] body { background-color: #f0fdf4; color: #064e3b; }
        html.light[data-palette="3"] body { background-color: #fef3c7; color: #78350f; }
        html.light[data-palette="4"] body { background-color: #e0f2fe; color: #0c4a6e; }
        html.dark[data-palette="0"] body { background-color: #1f2937; color: #f3f4f6; }
        html.dark[data-palette="1"] body { background-color: #0f172a; color: #e2e8f0; }
        html.dark[data-palette="2"] body { background-color: #18181b; color: #d4d4d8; }
        html.dark[data-palette="3"] body { background-color: #172554; color: #dbeafe; }
        html.dark[data-palette="4"] body { background-color: #2a1610; color: #ffedd5; }
        .dark .bg-white { background-color: rgba(255,255,255,0.05) !important; border-color: rgba(255,255,255,0.1); }
        
        #viewer-wrapper { position: relative; display: inline-block; }
        canvas { display: block; }
        #drawCanvas { position: absolute; top: 0; left: 0; z-index: 10; mix-blend-mode: multiply; }
        .dark #drawCanvas { mix-blend-mode: screen; }
        .flag-marker { position: absolute; z-index: 20; color: #ef4444; font-size: 1.5rem; cursor: pointer; transform: translate(-50%, -100%); }
        input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
        input[type="color"]::-webkit-color-swatch { border: none; border-radius: 4px; }
        
        .pan-ready #drawCanvas { cursor: grab; }
        .pan-active #drawCanvas { cursor: grabbing !important; }
        .tool-active #drawCanvas { cursor: crosshair; touch-action: none; }
    </style>
</head>
<body class="min-h-screen font-sans">
    <div class="max-w-4xl mx-auto p-4">
        <header class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Mis Libros</h1>
            <div class="flex gap-2">
                <button id="paletteToggle" class="p-2 rounded-full hover:bg-gray-200 dark:hover:bg-gray-700 transition" title="Cambiar paleta">
                    <i class="fas fa-palette"></i>
                </button>
                <button id="themeToggle" class="p-2 rounded-full hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                    <i class="fas fa-moon"></i>
                </button>
            </div>
        </header>

        <?php if (!$currentBook): ?>
            <div class="bg-white p-2 rounded-xl flex items-center mb-6 shadow-sm border border-gray-200">
                <i class="fas fa-search text-gray-400 ml-3"></i>
                <form action="" method="GET" class="w-full flex items-center">
                    <input type="hidden" name="userid" value="<?= htmlspecialchars($currentUser) ?>">
                    <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Buscar título o etiqueta..." class="w-full bg-transparent border-none outline-none p-2 ml-2">
                    <?php if ($search): ?>
                        <a href="?userid=<?= urlencode($currentUser) ?>" class="p-2 text-gray-400 hover:text-red-500" title="Limpiar búsqueda"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="flex justify-between items-center mb-4 text-sm font-medium text-gray-600 dark:text-gray-300">
                <div class="flex items-center gap-2">
                    <?php if (!$isGuest): ?>
                    <form id="uploadForm" class="hidden"><input type="file" id="fileInput" accept=".pdf,.epub" onchange="uploadBook()"></form>
                    <button onclick="$('#fileInput').click()" class="bg-white px-3 py-1.5 border border-gray-300 rounded-lg shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700"><i class="fas fa-plus mr-1"></i> Subir</button>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-2">
                    <label>Ordenar por</label>
                    <select onchange="window.location.href='?sort='+this.value+'&q=<?= urlencode($search) ?>&userid=<?= urlencode($currentUser) ?>'" class="bg-transparent border-b border-gray-400 outline-none pb-1">
                        <option value="recent_read" <?= $sort === 'recent_read' ? 'selected' : '' ?>>Lectura reciente</option>
                        <option value="recent_added" <?= $sort === 'recent_added' ? 'selected' : '' ?>>Añadidos recientemente</option>
                        <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Título</option>
                    </select>
                </div>
            </div>

            <div class="space-y-4">
                <?php foreach ($books as $b): ?>
                    <div class="bg-white p-4 rounded-2xl shadow-sm border border-gray-100 flex flex-col hover:shadow-md transition">
                        <div class="flex justify-between items-start cursor-pointer" onclick="window.location.href='?bookid=<?= urlencode($b['file']) ?>&userid=<?= urlencode($currentUser) ?>'">
                            <div class="flex items-start gap-4 w-full">
                                <?php if ($b['cover']): ?>
                                    <img src="<?= htmlspecialchars($b['cover']) ?>" class="w-14 h-20 object-cover rounded shadow flex-shrink-0" alt="Portada">
                                <?php else: ?>
                                    <div class="w-14 h-20 bg-gray-200 rounded flex items-center justify-center flex-shrink-0">
                                        <i class="fas <?= $b['ext'] === 'pdf' ? 'fa-file-pdf text-teal-600' : 'fa-book text-red-500' ?> text-2xl"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="flex-1">
                                    <h2 class="font-semibold text-lg line-clamp-1 mb-1"><?= htmlspecialchars($b['file']) ?></h2>
                                    <div class="flex flex-wrap gap-3 text-xs text-gray-500 font-medium mb-2">
                                        <span><i class="fas fa-weight-hanging mr-1"></i><?= $b['size'] ?></span>
                                        <span class="<?= $b['percentage'] > 0 ? 'text-green-600' : '' ?>"><i class="fas fa-book-open mr-1"></i><?= $b['percentage'] ?>% completado</span>
                                        <span><i class="fas fa-share-nodes mr-1"></i><?= $b['shares'] ?> compartidos</span>
                                        <?php if($b['flags_count'] > 0): ?>
                                            <span class="text-blue-600"><i class="fas fa-bookmark mr-1"></i><?= $b['flags_count'] ?> marcas</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="flex flex-wrap gap-1 mt-1">
                                        <?php foreach ($b['tags'] as $tag): ?>
                                            <span class="tag-pill text-xs px-2 py-1 rounded-md text-white flex items-center gap-1" data-tag="<?= htmlspecialchars($tag) ?>">
                                                <?= htmlspecialchars($tag) ?>
                                                <?php if (!$isGuest): ?>
                                                <button class="hover:text-red-200 ml-1" onclick="event.stopPropagation(); removeTag('<?= urlencode($b['file']) ?>', '<?= htmlspecialchars($tag) ?>')"><i class="fas fa-times"></i></button>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <span class="text-xs font-bold px-2 py-1 rounded border <?= $b['ext'] === 'pdf' ? 'border-teal-500 text-teal-700' : 'border-red-400 text-red-600' ?> ml-2"><?= strtoupper($b['ext']) ?></span>
                        </div>
                        <?php if (!$isGuest): ?>
                        <input type="text" class="tag-input mt-3 text-xs border-b border-gray-200 bg-transparent outline-none text-gray-500 w-full" data-book="<?= htmlspecialchars($b['file']) ?>" placeholder="Añadir nuevas etiquetas separadas por coma y pulsar Enter...">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($books) && $isGuest): ?>
                    <p class="text-center text-gray-500 mt-10">No tienes libros compartidos disponibles.</p>
                <?php endif; ?>
            </div>

            <script>
                function getTagColor(str) {
                    let hash = 0;
                    for (let i = 0; i < str.length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash);
                    return `hsl(${Math.abs(hash % 360)}, 65%, 45%)`;
                }

                document.querySelectorAll('.tag-pill').forEach(el => el.style.backgroundColor = getTagColor(el.dataset.tag));

                function uploadBook() {
                    let file = $('#fileInput')[0].files[0];
                    if (!file) return;
                    
                    $('#fileInput').parent().after('<div class="fixed top-4 right-4 bg-blue-600 text-white px-4 py-2 rounded shadow z-50">Subiendo y convirtiendo... esto puede tardar un poco.</div>');
                    
                    let fd = new FormData(); fd.append('file', file);
                    $.ajax({ url: '?action=upload', type: 'POST', data: fd, processData: false, contentType: false, success: res => { if(res.success) location.reload(); else alert(res.error); }});
                }

                $('.tag-input').on('keypress', function(e) {
                    if(e.which == 13) {
                        e.preventDefault();
                        $.post('?action=save_tags', { book: $(this).data('book'), tags: $(this).val() }, () => location.reload());
                    }
                });

                window.removeTag = function(book, tag) {
                    $.post('?action=remove_tag', { book: decodeURIComponent(book), tag: tag }, () => location.reload());
                };
            </script>

        <?php else: ?>
            <div id="fullscreen-wrapper" class="flex flex-col gap-4 w-full transition-colors duration-300">
                <div class="flex flex-wrap justify-between items-center gap-2" id="toolbar">
                    <button onclick="window.location.href='?userid=<?= urlencode($currentUser) ?>'" class="text-gray-700 dark:text-gray-300 bg-white px-4 py-2 rounded-full shadow-sm text-sm"><i class="fas fa-arrow-left mr-2"></i> Inicio</button>
                    <div class="flex gap-2 items-center bg-white px-2 rounded-full shadow-sm">
                        <select id="toolSelect" class="bg-transparent text-sm py-2 px-1 outline-none text-gray-700 dark:text-gray-300 border-r border-gray-200 dark:border-gray-700">
                            <option value="none">Navegar</option>
                            <option value="flag">Poner Bandera</option>
                            <option value="highlight">Subrayador</option>
                            <option value="erase">Goma</option>
                        </select>
                        <input type="color" id="highlightColor" value="#fef08a" class="hidden h-6 w-6 ml-1 cursor-pointer bg-transparent border-0" title="Color de subrayado">
                        <button id="zoomOut" class="px-2 py-2 text-gray-600"><i class="fas fa-search-minus"></i></button>
                        <button id="zoomIn" class="px-2 py-2 text-gray-600 border-r border-gray-200 dark:border-gray-700"><i class="fas fa-search-plus"></i></button>
                        <button id="shareBtn" class="px-3 py-2 text-blue-600 text-sm font-medium"><i class="fas fa-share-alt mr-1"></i> Compartir</button>
                        <button id="fullscreenBtn" class="px-2 py-2 text-gray-600"><i class="fas fa-expand"></i></button>
                    </div>
                </div>

                <div id="reader-container" class="w-full min-h-[60vh] flex-1 bg-white rounded-xl shadow-inner overflow-auto relative border border-gray-200 flex justify-center p-4 pan-ready">
                    <div id="viewer-wrapper">
                        <div id="viewer" class="text-gray-500">Cargando lector...</div>
                    </div>
                </div>

                <div id="nav-bar" class="flex justify-between items-center bg-white p-3 rounded-full shadow-sm flex-shrink-0">
                    <button id="prevPage" class="px-4 py-1 text-gray-600"><i class="fas fa-chevron-left"></i></button>
                    <span id="pageInfo" class="text-sm font-medium">Pág. <span id="pageNum">1</span> <span id="percentInfo" class="text-xs text-gray-400 ml-2"></span></span>
                    <button id="nextPage" class="px-4 py-1 text-gray-600"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
            
            <ul id="flagsList" class="mt-4 space-y-2"></ul>

            <script>
                const bookFile = "<?= htmlspecialchars($currentBook) ?>";
                const userId = "<?= htmlspecialchars($currentUser) ?>";
                let currentPage = 1; let scale = 1.2; let pdfDoc = null;
                let flags = []; let drawings = {}; let currentPercentage = 0;
                let currentTool = 'none'; let isDrawing = false; let ctxDraw = null;

                $('#toolSelect').change(function() { 
                    currentTool = $(this).val(); 
                    if(currentTool === 'highlight') $('#highlightColor').removeClass('hidden');
                    else $('#highlightColor').addClass('hidden');

                    if(currentTool === 'none') {
                        $('#reader-container').addClass('pan-ready').removeClass('tool-active');
                    } else {
                        $('#reader-container').removeClass('pan-ready pan-active').addClass('tool-active');
                    }
                });

                function saveState() {
                    if(ctxDraw) drawings[currentPage] = document.getElementById('drawCanvas').toDataURL();
                    $.post('?action=save', { book: bookFile, userid: userId, page: currentPage, flags: flags, drawings: drawings, percentage: currentPercentage });
                    renderFlagsList();
                }

                function renderFlagsList() {
                    $('#flagsList').empty();
                    flags.forEach((f, i) => {
                        $('#flagsList').append(`<li class="text-sm flex justify-between bg-white border border-gray-200 p-3 rounded-lg shadow-sm">
                            <span class="cursor-pointer font-medium" onclick="goToPage('${f.page}')">Pág. ${f.page}: <span class="font-normal text-gray-600">${f.note}</span></span>
                            <button onclick="removeFlag(${i})" class="text-red-400 hover:text-red-600"><i class="fas fa-trash"></i></button>
                        </li>`);
                    });
                }

                window.removeFlag = function(idx) { flags.splice(idx, 1); renderPdfPage(currentPage); saveState(); }
                window.goToPage = function(p) { currentPage = parseInt(p); renderPdfPage(currentPage); }

                $('#shareBtn').click(() => {
                    $.post('?action=share', { book: bookFile, userid: userId }, (res) => {
                        navigator.clipboard.writeText(res.url).then(() => alert("URL copiada:\n" + res.url)).catch(() => prompt("Copia manualmente:", res.url));
                    });
                });

                $('#fullscreenBtn').click(() => { 
                    let el = document.getElementById('fullscreen-wrapper'); 
                    if (!document.fullscreenElement) el.requestFullscreen();
                    else document.exitFullscreen();
                });

                document.addEventListener('fullscreenchange', () => {
                    let isFs = !!document.fullscreenElement;
                    let wrapper = $('#fullscreen-wrapper');
                    
                    if (isFs) {
                        wrapper.css('background-color', getComputedStyle(document.body).backgroundColor);
                        wrapper.addClass('p-4 h-screen w-screen');
                        $('#reader-container').removeClass('min-h-[60vh]');
                    } else {
                        wrapper.css('background-color', '');
                        wrapper.removeClass('p-4 h-screen w-screen');
                        $('#reader-container').addClass('min-h-[60vh]');
                    }

                    if (pdfDoc) {
                        if (isFs) {
                            pdfDoc.getPage(currentPage).then(page => {
                                let vp = page.getViewport({ scale: 1.0 });
                                let availableHeight = window.innerHeight - $('#toolbar').outerHeight(true) - $('#nav-bar').outerHeight(true) - 48; 
                                scale = availableHeight / vp.height;
                                renderPdfPage(currentPage);
                            });
                        } else {
                            scale = 1.2;
                            renderPdfPage(currentPage);
                        }
                    }
                });

                $('#zoomIn').click(() => { scale += 0.2; renderPdfPage(currentPage); });
                $('#zoomOut').click(() => { if(scale > 0.4) scale -= 0.2; renderPdfPage(currentPage); });

                const container = document.getElementById('reader-container');
                let isPanning = false, startX, startY, sLeft, sTop;

                container.addEventListener('mousedown', (e) => {
                    if (currentTool !== 'none' || e.target.closest('.flag-marker')) return;
                    isPanning = true;
                    container.classList.add('pan-active');
                    startX = e.pageX - container.offsetLeft;
                    startY = e.pageY - container.offsetTop;
                    sLeft = container.scrollLeft;
                    sTop = container.scrollTop;
                });

                window.addEventListener('mouseup', () => {
                    if(isPanning) {
                        isPanning = false;
                        container.classList.remove('pan-active');
                    }
                });

                container.addEventListener('mousemove', (e) => {
                    if (!isPanning) return;
                    e.preventDefault(); 
                    const x = e.pageX - container.offsetLeft;
                    const y = e.pageY - container.offsetTop;
                    container.scrollLeft = sLeft - (x - startX);
                    container.scrollTop = sTop - (y - startY);
                });

                function generatePdfCover() {
                    pdfDoc.getPage(1).then(page => {
                        let canvas = document.createElement('canvas');
                        let vp = page.getViewport({ scale: 0.5 });
                        canvas.width = vp.width; canvas.height = vp.height;
                        page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise.then(() => {
                            $.post('?action=save_cover', { book: bookFile, cover: canvas.toDataURL('image/jpeg', 0.6) });
                        });
                    });
                }

                $.get(`?action=load&book=${encodeURIComponent(bookFile)}&userid=${userId}`, (data) => {
                    currentPage = parseInt(data.page) || 1;
                    flags = data.flags || []; drawings = data.drawings || {};
                    renderFlagsList();
                    $('#toolSelect').trigger('change');
                    
                    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';
                    pdfjsLib.getDocument(`Libros/${bookFile}`).promise.then(doc => { 
                        pdfDoc = doc; 
                        renderPdfPage(currentPage); 
                        if (!data.hasCover) generatePdfCover();
                    });
                });

                function renderPdfPage(num) {
                    pdfDoc.getPage(num).then(page => {
                        const vp = page.getViewport({ scale: scale });
                        $('#viewer').html(`<canvas id="pdfCanvas"></canvas><canvas id="drawCanvas"></canvas>`);
                        const canvas = document.getElementById('pdfCanvas'); const drawCanvas = document.getElementById('drawCanvas');
                        canvas.height = drawCanvas.height = vp.height; canvas.width = drawCanvas.width = vp.width;
                        page.render({ canvasContext: canvas.getContext('2d'), viewport: vp });
                        
                        currentPercentage = Math.round((num / pdfDoc.numPages) * 100);
                        $('#percentInfo').text(`(${currentPercentage}%)`);

                        ctxDraw = drawCanvas.getContext('2d');
                        if (drawings[num]) {
                            let img = new Image(); img.onload = () => ctxDraw.drawImage(img, 0, 0, drawCanvas.width, drawCanvas.height);
                            img.src = drawings[num];
                        }

                        $('.flag-marker').remove();
                        flags.filter(f => parseInt(f.page) === num).forEach(f => {
                            $('#viewer-wrapper').append(`<i class="fas fa-flag flag-marker" style="left:${f.x * scale}px; top:${f.y * scale}px" title="${f.note}"></i>`);
                        });

                        const startDraw = (e) => {
                            if (currentTool === 'none') return;
                            if (e.cancelable) e.preventDefault();
                            const rect = drawCanvas.getBoundingClientRect();
                            const ev = e.touches ? e.touches[0] : e;
                            const x = ev.clientX - rect.left;
                            const y = ev.clientY - rect.top;
                            
                            if (currentTool === 'flag') {
                                let note = prompt("Nota de la bandera:");
                                if(note) { flags.push({page: num, x: x/scale, y: y/scale, note: note}); saveState(); renderPdfPage(num); }
                                currentTool = 'none'; $('#toolSelect').val('none'); $('#toolSelect').trigger('change');
                            } else if (currentTool === 'highlight' || currentTool === 'erase') {
                                isDrawing = true; ctxDraw.beginPath(); ctxDraw.moveTo(x, y);
                                ctxDraw.globalCompositeOperation = currentTool === 'erase' ? 'destination-out' : 'source-over';
                                
                                let strokeColor = 'rgba(0,0,0,1)';
                                if (currentTool === 'highlight') {
                                    let hex = $('#highlightColor').val();
                                    let r = parseInt(hex.substring(1,3), 16);
                                    let g = parseInt(hex.substring(3,5), 16);
                                    let b = parseInt(hex.substring(5,7), 16);
                                    strokeColor = `rgba(${r}, ${g}, ${b}, 0.2)`;
                                }
                                ctxDraw.lineWidth = 20; ctxDraw.lineCap = 'round'; ctxDraw.strokeStyle = strokeColor;
                            }
                        };

                        const moveDraw = (e) => {
                            if (!isDrawing) return;
                            if (e.cancelable) e.preventDefault();
                            const rect = drawCanvas.getBoundingClientRect();
                            const ev = e.touches ? e.touches[0] : e;
                            ctxDraw.lineTo(ev.clientX - rect.left, ev.clientY - rect.top);
                            ctxDraw.stroke();
                        };

                        const endDraw = () => { if(isDrawing) { isDrawing = false; saveState(); } };

                        drawCanvas.addEventListener('mousedown', startDraw);
                        drawCanvas.addEventListener('touchstart', startDraw, { passive: false });
                        drawCanvas.addEventListener('mousemove', moveDraw);
                        drawCanvas.addEventListener('touchmove', moveDraw, { passive: false });
                        drawCanvas.addEventListener('mouseup', endDraw);
                        drawCanvas.addEventListener('touchend', endDraw);
                        drawCanvas.addEventListener('mouseleave', endDraw);
                        drawCanvas.addEventListener('touchcancel', endDraw);
                        
                        $('#pageNum').text(`${num} de ${pdfDoc.numPages}`);
                    });
                }

                $('#prevPage').click(() => { if (currentPage > 1) { currentPage--; renderPdfPage(currentPage); } });
                $('#nextPage').click(() => { if (currentPage < pdfDoc.numPages) { currentPage++; renderPdfPage(currentPage); } });
            </script>
        <?php endif; ?>
        
        <script>
            function applyTheme() {
                let isDark = localStorage.getItem('theme') === 'dark';
                let palette = localStorage.getItem('palette') || '0';
                if (isDark) document.documentElement.classList.add('dark'); else document.documentElement.classList.remove('dark');
                document.documentElement.dataset.palette = palette;
            }
            
            $('#themeToggle').click(() => {
                localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'light' : 'dark');
                applyTheme();
            });

            $('#paletteToggle').click(() => {
                let p = parseInt(localStorage.getItem('palette') || '0');
                localStorage.setItem('palette', (p + 1) % 5);
                applyTheme();
            });

            applyTheme();
        </script>
    </div>
    <footer class="bg-gray-800 text-gray-300 p-6 text-center mt-auto">
    <p>Esta aplicación no permite la descarga de ficheros, utilizala solo para compartir fragmentos anotados con tus alumnos y para preparar tus clases sobre tu propio material.</p>
  </footer>
</body>
</html>