<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    if (isset($_GET['arreglar'])) {
        $pdo->exec("DROP TABLE IF EXISTS publicaciones"); $pdo->exec("DROP TABLE IF EXISTS modulos"); $pdo->exec("DROP TABLE IF EXISTS tableros"); $pdo->exec("DROP TABLE IF EXISTS config");
        die("<div style='padding:30px; font-family:sans-serif; text-align:center;'><h2 style='color:#2563eb;'>¡Sistema Reseteado!</h2><a href='padlet.php'>Ir al Panel</a></div>");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS tableros (id INT AUTO_INCREMENT PRIMARY KEY, titulo VARCHAR(255), fondo_tipo ENUM('color', 'url') DEFAULT 'color', fondo_valor VARCHAR(500), fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS modulos (id INT AUTO_INCREMENT PRIMARY KEY, tablero_id INT, titulo VARCHAR(255) NOT NULL, FOREIGN KEY (tablero_id) REFERENCES tableros(id) ON DELETE CASCADE)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS publicaciones (id INT AUTO_INCREMENT PRIMARY KEY, modulo_id INT, contenido TEXT, tipo ENUM('texto', 'enlace', 'archivo', 'imagen', 'youtube', 'audio', 'video') DEFAULT 'texto', url_media VARCHAR(500), meta_data TEXT, archivo_nombre VARCHAR(255), autor VARCHAR(100) DEFAULT 'Anónimo', reacciones TEXT, fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (modulo_id) REFERENCES modulos(id) ON DELETE CASCADE)");

    // PARCHES SILENCIOSOS
    try { $pdo->exec("ALTER TABLE publicaciones ADD COLUMN meta_data TEXT"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE publicaciones ADD COLUMN archivo_nombre VARCHAR(255)"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE publicaciones ADD COLUMN autor VARCHAR(100) DEFAULT 'Anónimo'"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE publicaciones ADD COLUMN reacciones TEXT"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE publicaciones MODIFY COLUMN tipo ENUM('texto', 'enlace', 'archivo', 'imagen', 'youtube', 'audio', 'video') DEFAULT 'texto'"); } catch(Exception $e) {}

} catch (Exception $e) { die("Error crítico de Base de Datos: " . $e->getMessage()); }

// ==========================================
//  FUNCIONES Y RUTAS
// ==========================================
function getUrlMetadata($url) {
    $html = @file_get_contents($url); if(!$html) return null;
    $meta = ['titulo' => 'Enlace externo', 'imagen' => '', 'dominio' => parse_url($url, PHP_URL_HOST)];
    if(preg_match('/<title>(.*?)<\/title>/is', $html, $matches)) $meta['titulo'] = trim($matches[1]);
    if(preg_match('/<meta property="og:image" content="(.*?)"/is', $html, $matches)) $meta['imagen'] = trim($matches[1]);
    return json_encode($meta);
}

$upload_dir = __DIR__ . '/uploads/';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' || isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$base_url = rtrim($protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']), '/') . '/';

$tablero_id = $_GET['edit'] ?? $_GET['board'] ?? null;
$is_admin = isset($_GET['edit']); 

// ==========================================
//  LÓGICA DE LA API (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'crear_tablero') {
        try {
            $titulo = $_POST['titulo']; $plantilla_id = $_POST['plantilla_id'] ?? null;
            if ($plantilla_id) {
                $stmt = $pdo->prepare("SELECT * FROM tableros WHERE id = ?"); $stmt->execute([$plantilla_id]); $orig = $stmt->fetch();
                $pdo->prepare("INSERT INTO tableros (titulo, fondo_tipo, fondo_valor) VALUES (?, ?, ?)")->execute([$titulo, $orig['fondo_tipo'], $orig['fondo_valor']]);
                $nuevo_id = $pdo->lastInsertId();
                $modulos = $pdo->prepare("SELECT * FROM modulos WHERE tablero_id = ?"); $modulos->execute([$plantilla_id]);
                foreach($modulos->fetchAll() as $mod) $pdo->prepare("INSERT INTO modulos (tablero_id, titulo) VALUES (?, ?)")->execute([$nuevo_id, $mod['titulo']]);
            } else {
                $pdo->prepare("INSERT INTO tableros (titulo, fondo_tipo, fondo_valor) VALUES (?, 'color', '#1e293b')")->execute([$titulo]);
                $nuevo_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO modulos (tablero_id, titulo) VALUES (?, 'Recursos')")->execute([$nuevo_id]);
            }
            echo json_encode(['status' => 'ok', 'id' => $nuevo_id]);
        } catch (Exception $e) { http_response_code(500); echo "Error BD: " . $e->getMessage(); } exit;
    }

    if ($_POST['action'] === 'eliminar_tablero' && $is_admin) {
        $pdo->prepare("DELETE FROM tableros WHERE id = ?")->execute([$_POST['tablero_id']]); echo json_encode(['status' => 'ok']); exit;
    }

    if ($_POST['action'] === 'guardar_config' && $is_admin) {
        $pdo->prepare("UPDATE tableros SET titulo = ?, fondo_tipo = ?, fondo_valor = ? WHERE id = ?")->execute([$_POST['titulo'], $_POST['fondo_tipo'], $_POST['fondo_valor'], $_POST['tablero_id']]); echo json_encode(['status' => 'ok']); exit;
    }

    if ($_POST['action'] === 'nueva_seccion' && $is_admin) {
        $pdo->prepare("INSERT INTO modulos (tablero_id, titulo) VALUES (?, ?)")->execute([$_POST['tablero_id'], $_POST['titulo']]); echo json_encode(['status' => 'ok']); exit;
    }
    
    if ($_POST['action'] === 'eliminar_seccion' && $is_admin) {
        $pdo->prepare("DELETE FROM modulos WHERE id = ?")->execute([$_POST['id']]); echo json_encode(['status' => 'ok']); exit;
    }

    if ($_POST['action'] === 'eliminar_publicacion' && $is_admin) {
        $stmt = $pdo->prepare("SELECT url_media, tipo FROM publicaciones WHERE id = ?"); $stmt->execute([$_POST['id']]); $post = $stmt->fetch();
        if ($post && in_array($post['tipo'], ['archivo', 'imagen', 'audio', 'video']) && strpos($post['url_media'], 'uploads/') === 0) {
            $archivo_fisico = __DIR__ . '/' . $post['url_media']; if (file_exists($archivo_fisico)) @unlink($archivo_fisico);
        }
        $pdo->prepare("DELETE FROM publicaciones WHERE id = ?")->execute([$_POST['id']]); echo json_encode(['status' => 'ok']); exit;
    }

    if ($_POST['action'] === 'reaccionar') {
        try {
            $stmt = $pdo->prepare("SELECT reacciones FROM publicaciones WHERE id = ?");
            $stmt->execute([$_POST['post_id']]);
            $post = $stmt->fetch();
            $reacciones = json_decode($post['reacciones'] ?: '{}', true);
            $tipo_r = $_POST['tipo_reaccion'];
            
            $reacciones[$tipo_r] = ($reacciones[$tipo_r] ?? 0) + 1;
            $pdo->prepare("UPDATE publicaciones SET reacciones = ? WHERE id = ?")->execute([json_encode($reacciones), $_POST['post_id']]);
            echo json_encode(['status' => 'ok', 'count' => $reacciones[$tipo_r]]);
        } catch (Exception $e) { http_response_code(500); echo "Error"; }
        exit;
    }

    if ($_POST['action'] === 'publicar') {
        $modulo_id = $_POST['modulo_id']; $contenido = trim($_POST['contenido']); $tipo = 'texto'; $url_media = ''; $meta_data = ''; $archivo_nombre = '';
        $autor = $_POST['autor'] ?? 'Anónimo';
        if ($is_admin) { $autor = 'Profesor 👨‍🏫'; }

        if (!empty($_POST['url_media'])) {
            $url_media = $_POST['url_media'];
            if (preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/\s]{11})%i', $url_media, $match)) {
                $tipo = 'youtube';
                $url_media = $match[1];
            } 
            elseif (preg_match('/\.(jpeg|jpg|gif|png|webp)(\?.*)?$/i', $url_media)) { 
                $tipo = 'imagen'; 
            } 
            else { 
                $tipo = 'enlace'; $meta_data = getUrlMetadata($url_media); 
            }
        }

        // Subida de archivos
        $archivo_subido = false;
        $archivo_obj = $_FILES['archivo_doc']['error'] === UPLOAD_ERR_OK ? $_FILES['archivo_doc'] : ($_FILES['archivo_media']['error'] === UPLOAD_ERR_OK ? $_FILES['archivo_media'] : null);

        if ($archivo_obj) {
            $max_bytes = 25 * 1024 * 1024; // 25 MB
            if ($archivo_obj['size'] <= $max_bytes) {
                $allowed = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','odt','ods','ppt','pptx','mp3','ogg','aac','wav','mp4','webm','ogv','mov'];
                $ext = strtolower(pathinfo($archivo_obj['name'], PATHINFO_EXTENSION));
                
                if (in_array($ext, $allowed)) {
                    $nombre_real = basename($archivo_obj['name']);
                    $nombre_seguro = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "_", $nombre_real);
                    if (move_uploaded_file($archivo_obj['tmp_name'], $upload_dir . $nombre_seguro)) {
                        $url_media = 'uploads/' . $nombre_seguro; 
                        $archivo_nombre = $nombre_real;
                        $archivo_subido = true;

                        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) { $tipo = 'imagen'; }
                        elseif (in_array($ext, ['mp3','ogg','aac','wav'])) { $tipo = 'audio'; }
                        elseif (in_array($ext, ['mp4','webm','ogv','mov'])) { $tipo = 'video'; }
                        else { $tipo = 'archivo'; }
                    }
                }
            }
        }

        if (empty($contenido) && empty($url_media) && !$archivo_subido) {
            header("Location: " . $_SERVER['REQUEST_URI']); 
            exit;
        }

        $pdo->prepare("INSERT INTO publicaciones (modulo_id, contenido, tipo, url_media, meta_data, archivo_nombre, autor, reacciones) VALUES (?, ?, ?, ?, ?, ?, ?, '{}')")->execute([$modulo_id, $contenido, $tipo, $url_media, $meta_data, $archivo_nombre, $autor]);
        header("Location: " . $_SERVER['REQUEST_URI']); exit; 
    }
}

// ==========================================
// VISTA 1: DASHBOARD (PANEL DE PROFESOR)
// ==========================================
if (!$tablero_id) {
    $ultimos_tableros = $pdo->query("SELECT * FROM tableros ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Murales Docentes</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>body { background-color: #f3f4f6; font-family: system-ui, sans-serif; }</style>
</head>
<body class="p-8">
    <header class="mb-10 flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-800"><i class="fas fa-layer-group text-blue-600 mr-2"></i>Mis Murales Docentes</h1>
            <p class="text-gray-500 mt-2">Gestiona tus clases o crea plantillas reutilizables</p>
        </div>
        <button onclick="crearTablero()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-bold shadow-lg transition">
            <i class="fas fa-plus mr-2"></i>Mural en Blanco
        </button>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-6">
        <?php foreach($ultimos_tableros as $t): 
            $bg_style = ($t['fondo_tipo'] == 'color') ? "background-color: " . $t['fondo_valor'] . ";" : "background-image: url('" . htmlspecialchars($t['fondo_valor']) . "'); background-size: cover; background-position: center;";
        ?>
        <div class="bg-white rounded-2xl shadow-sm border overflow-hidden hover:shadow-xl transition group relative flex flex-col h-64">
            <a href="padlet.php?edit=<?= $t['id'] ?>" class="flex-1">
                <div class="h-32 w-full" style="<?= $bg_style ?>"></div>
                <div class="p-4">
                    <h3 class="font-bold text-lg text-gray-800 line-clamp-1"><?= htmlspecialchars($t['titulo']) ?></h3>
                    <p class="text-xs text-gray-400 mt-1"><i class="far fa-clock"></i> <?= date('d/m/Y', strtotime($t['fecha'])) ?></p>
                </div>
            </a>
            <div class="absolute bottom-4 right-4 flex space-x-2 opacity-0 group-hover:opacity-100 transition">
                <button onclick="clonarTablero(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['titulo'])) ?>')" class="bg-gray-100 hover:bg-gray-200 text-gray-700 w-8 h-8 rounded-full shadow" title="Usar como Plantilla"><i class="far fa-copy"></i></button>
                <button onclick="eliminarTablero(<?= $t['id'] ?>)" class="bg-red-100 hover:bg-red-200 text-red-600 w-8 h-8 rounded-full shadow" title="Eliminar Muro"><i class="fas fa-trash"></i></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <script>
        function crearTablero() {
            let t = prompt("Título para tu nuevo mural:");
            if(t) $.post('padlet.php', { action: 'crear_tablero', titulo: t }, (res) => window.location.href = 'padlet.php?edit=' + JSON.parse(res).id );
        }
        function clonarTablero(id, nombre) {
            let t = prompt(`Crear una copia de "${nombre}":\n\nNuevo título:`, nombre + " (Copia)");
            if(t) $.post('padlet.php', { action: 'crear_tablero', titulo: t, plantilla_id: id }, (res) => window.location.href = 'padlet.php?edit=' + JSON.parse(res).id );
        }
        function eliminarTablero(id) {
            if(confirm("¿Borrar este mural entero de forma permanente?")) {
                $.post('padlet.php?edit=1', { action: 'eliminar_tablero', tablero_id: id }, () => location.reload()); 
            }
        }
    </script>
</body>
</html>
<?php exit; } 

// ==========================================
// VISTA 2: EL MURAL (ALUMNO O PROFESOR)
// ==========================================
$stmt = $pdo->prepare("SELECT * FROM tableros WHERE id = ?");
$stmt->execute([$tablero_id]);
$tablero = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tablero) die("El muro que buscas no existe. <a href='padlet.php'>Volver al inicio</a>");

$stmt = $pdo->prepare("SELECT * FROM modulos WHERE tablero_id = ? ORDER BY id ASC");
$stmt->execute([$tablero_id]);
$modulos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$bg_css = ($tablero['fondo_tipo'] == 'color') ? "background-color: " . $tablero['fondo_valor'] . ";" : "background-image: url('" . htmlspecialchars($tablero['fondo_valor']) . "'); background-size: cover; background-position: center; background-attachment: fixed;";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($tablero['titulo']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { <?= $bg_css ?> font-family: 'Segoe UI', system-ui, sans-serif; }
        .glass-header { background: rgba(0,0,0,0.6); backdrop-filter: blur(16px); }
        .glass-column { background: rgba(240, 240, 245, 0.90); backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.4); }
        .card { border-radius: 12px; background: white; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); transition: all 0.2s ease; word-wrap: break-word;}
        .card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }
        .column { min-width: 340px; width: 340px; height: calc(100vh - 130px); }
        .tab-btn.active { border-bottom: 2px solid #2563eb; color: #2563eb; font-weight: bold; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.3); border-radius: 10px; }
    </style>
</head>
<body class="p-0 m-0 overflow-hidden text-gray-800">

    <header class="glass-header px-6 py-3 flex justify-between items-center text-white shadow-md">
        <div class="flex items-center gap-4">
            <?php if ($is_admin): ?>
                <a href="padlet.php" class="text-white/50 hover:text-white mr-2" title="Volver a mis murales"><i class="fas fa-home"></i></a>
            <?php endif; ?>
            
            <div>
                <h1 class="text-2xl font-bold tracking-tight"><?= htmlspecialchars($tablero['titulo']) ?></h1>
                <?php if ($is_admin): ?>
                    <span class="text-[10px] bg-red-500 text-white px-2 py-0.5 rounded uppercase font-bold tracking-widest">Profesor</span>
                <?php else: ?>
                    <span class="text-[10px] bg-green-500 text-white px-2 py-0.5 rounded uppercase font-bold tracking-widest">Alumno: <span id="nombre-alumno-ui"></span></span>
                <?php endif; ?>
            </div>
            
            <?php if ($is_admin): ?>
            <button onclick="$('#configModal').fadeIn()" class="text-white/70 hover:text-white bg-white/20 p-2 rounded-full transition ml-2" title="Diseño">
                <i class="fas fa-cog"></i>
            </button>
            <?php endif; ?>
        </div>
        <div class="flex gap-3">
            <?php if ($is_admin): ?>
            <button onclick="nuevaSeccion()" class="bg-white/10 hover:bg-white/20 px-4 py-2 rounded-lg font-semibold text-sm transition">
                <i class="fas fa-plus mr-2"></i>Añadir Columna
            </button>
            <button onclick="compartirTablon()" class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg font-semibold text-sm transition shadow-lg shadow-blue-500/30">
                <i class="fas fa-share mr-2"></i>Compartir con Alumnos
            </button>
            <?php endif; ?>
        </div>
    </header>

    <div class="flex space-x-6 overflow-x-auto p-6 items-start h-full pb-20">
        <?php foreach ($modulos as $m): ?>
            <div class="column flex flex-col glass-column rounded-2xl p-3 relative group flex-shrink-0">
                <div class="flex justify-between items-center mb-3 px-2">
                    <h2 class="font-bold text-gray-800 text-sm uppercase tracking-wide"><?= htmlspecialchars($m['titulo']) ?></h2>
                    <?php if ($is_admin): ?>
                    <button onclick="eliminarSeccion(<?= $m['id'] ?>)" class="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-500 transition">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>

                <div class="space-y-4 overflow-y-auto pr-1 flex-1">
                    <?php
                    $stmt = $pdo->prepare("SELECT * FROM publicaciones WHERE modulo_id = ? ORDER BY id DESC");
                    $stmt->execute([$m['id']]);
                    while ($p = $stmt->fetch()):
                        $meta = $p['meta_data'] ? json_decode($p['meta_data'], true) : null;
                        $reacciones = json_decode($p['reacciones'] ?: '{}', true);
                    ?>
                        <div class="card overflow-hidden relative group flex flex-col">
                            
                            <?php if ($is_admin): ?>
                            <button onclick="eliminarPublicacion(<?= $p['id'] ?>)" class="absolute top-2 right-2 bg-red-500/80 hover:bg-red-600 text-white w-7 h-7 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition z-10 shadow-sm" title="Eliminar">
                                <i class="fas fa-times text-sm"></i>
                            </button>
                            <?php endif; ?>

                            <div class="flex-1">
                                <?php if ($p['tipo'] === 'imagen' && $p['url_media']): ?>
                                    <a href="<?= htmlspecialchars($p['url_media']) ?>" target="_blank"><img src="<?= htmlspecialchars($p['url_media']) ?>" class="w-full max-h-48 object-cover border-b"></a>
                                
                                <?php elseif ($p['tipo'] === 'youtube'): ?>
                                    <div class="w-full border-b" style="aspect-ratio: 16/9;">
                                        <iframe src="https://www.youtube.com/embed/<?= htmlspecialchars($p['url_media']) ?>" frameborder="0" allowfullscreen class="w-full h-full"></iframe>
                                    </div>

                                <?php elseif ($p['tipo'] === 'audio' && $p['url_media']): 
                                    $ext_audio = strtolower(pathinfo($p['url_media'], PATHINFO_EXTENSION));
                                    $mime_audio = ($ext_audio === 'mp3') ? 'audio/mpeg' : (($ext_audio === 'wav') ? 'audio/wav' : 'audio/' . $ext_audio);
                                    $src_audio = (strpos($p['url_media'], 'http') === 0) ? $p['url_media'] : $base_url . $p['url_media'];
                                ?>
                                    <div class="p-3 bg-gray-50 border-b">
                                        <p class="text-xs font-bold text-gray-600 mb-2 truncate"><i class="fas fa-music text-purple-600 mr-1"></i> <?= htmlspecialchars($p['archivo_nombre']) ?></p>
                                        <audio controls preload="metadata" class="w-full h-10">
                                            <source src="<?= htmlspecialchars($src_audio) ?>" type="<?= $mime_audio ?>">
                                            Tu navegador no soporta el reproductor de audio.
                                        </audio>
                                    </div>

                                <!-- RENDERIZADO VÍDEO HTML5 CORREGIDO -->
                                <?php elseif ($p['tipo'] === 'video' && $p['url_media']): 
                                    $ext_video = strtolower(pathinfo($p['url_media'], PATHINFO_EXTENSION));
                                    $mime_video = ($ext_video === 'mov' || $ext_video === 'mp4') ? 'video/mp4' : 'video/' . $ext_video;
                                    $src_video = (strpos($p['url_media'], 'http') === 0) ? $p['url_media'] : $base_url . $p['url_media'];
                                ?>
                                    <div class="w-full border-b bg-black flex items-center justify-center">
                                        <video controls preload="metadata" playsinline class="w-full max-h-48 object-contain" src="<?= htmlspecialchars($src_video) ?>">
                                            <source src="<?= htmlspecialchars($src_video) ?>" type="<?= $mime_video ?>">
                                            Tu navegador no soporta la reproducción de vídeos.
                                        </video>
                                    </div>

                                <?php elseif ($p['tipo'] === 'enlace' && $meta): ?>
                                    <a href="<?= htmlspecialchars($p['url_media']) ?>" target="_blank" class="block border-b hover:bg-gray-50 transition">
                                        <?php if(!empty($meta['imagen'])): ?><img src="<?= htmlspecialchars($meta['imagen']) ?>" class="w-full h-32 object-cover"><?php endif; ?>
                                        <div class="p-3 bg-gray-50">
                                            <p class="text-xs text-gray-500 uppercase truncate"><?= htmlspecialchars($meta['dominio']) ?></p>
                                            <p class="font-bold text-sm leading-tight mt-1 text-gray-800 line-clamp-2"><?= htmlspecialchars($meta['titulo']) ?></p>
                                        </div>
                                    </a>

                                <?php elseif ($p['tipo'] === 'archivo'): 
                                    $ext = strtolower(pathinfo($p['url_media'], PATHINFO_EXTENSION));
                                    $url_absoluta = $base_url . $p['url_media'];
                                    
                                    $color_icono = 'bg-gray-500'; $fa_icono = 'fa-file-alt';
                                    if (in_array($ext, ['pdf'])) { $color_icono = 'bg-red-500'; $fa_icono = 'fa-file-pdf'; }
                                    elseif (in_array($ext, ['doc', 'docx', 'odt'])) { $color_icono = 'bg-blue-600'; $fa_icono = 'fa-file-word'; }
                                    elseif (in_array($ext, ['xls', 'xlsx', 'ods'])) { $color_icono = 'bg-green-600'; $fa_icono = 'fa-file-excel'; }
                                    elseif (in_array($ext, ['ppt', 'pptx'])) { $color_icono = 'bg-orange-500'; $fa_icono = 'fa-file-powerpoint'; }

                                    if (in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'ppt', 'pptx'])) {
                                        $link_destino = "https://docs.google.com/viewer?url=" . urlencode($url_absoluta); $icono_accion = '<i class="fas fa-external-link-alt"></i> Abrir en Docs';
                                    } else {
                                        $link_destino = htmlspecialchars($p['url_media']); $icono_accion = '<i class="fas fa-download"></i> Descargar';
                                    }
                                ?>
                                    <a href="<?= $link_destino ?>" target="_blank" class="flex items-center p-3 bg-gray-50 hover:bg-gray-100 border-b transition">
                                        <div class="<?= $color_icono ?> text-white p-2 rounded-lg text-lg w-10 h-10 flex items-center justify-center"><i class="fas <?= $fa_icono ?>"></i></div>
                                        <div class="ml-3 overflow-hidden">
                                            <p class="text-sm font-bold text-gray-800 truncate"><?= htmlspecialchars($p['archivo_nombre']) ?></p>
                                            <p class="text-[10px] text-gray-500 mt-1 uppercase font-bold"><?= $icono_accion ?></p>
                                        </div>
                                    </a>
                                <?php endif; ?>

                                <?php if($p['contenido']): ?>
                                    <div class="p-4"><p class="text-sm text-gray-700 leading-relaxed"><?= nl2br(strip_tags($p['contenido'], '<b><strong><i><em><u><br><a>')) ?></p></div>
                                <?php endif; ?>
                            </div>

                            <div class="px-4 py-2 bg-gray-50 border-t flex justify-between items-center text-[10px] font-bold text-gray-500">
                                <div class="flex space-x-3 text-sm">
                                    <button onclick="reaccionar(<?= $p['id'] ?>, 'like')" class="hover:text-blue-500 transition"><i class="fas fa-thumbs-up"></i> <span id="react-like-<?= $p['id'] ?>"><?= $reacciones['like'] ?? 0 ?></span></button>
                                    <button onclick="reaccionar(<?= $p['id'] ?>, 'heart')" class="hover:text-red-500 transition"><i class="fas fa-heart"></i> <span id="react-heart-<?= $p['id'] ?>"><?= $reacciones['heart'] ?? 0 ?></span></button>
                                    <button onclick="reaccionar(<?= $p['id'] ?>, 'laugh')" class="hover:text-yellow-500 transition"><i class="fas fa-laugh-squint"></i> <span id="react-laugh-<?= $p['id'] ?>"><?= $reacciones['laugh'] ?? 0 ?></span></button>
                                </div>

                                <?php 
                                    $is_profesor = strpos($p['autor'], 'Profesor') !== false;
                                    $color_autor = $is_profesor ? 'text-red-500' : 'text-blue-600';
                                ?>
                                <div class="text-right ml-2 flex-shrink-0">
                                    <span class="truncate block <?= $color_autor ?> uppercase"><i class="fas fa-user-circle mr-1"></i> <?= htmlspecialchars($p['autor']) ?></span>
                                    <span class="text-[9px] font-normal"><i class="far fa-clock mr-1"></i> <?= date('d M', strtotime($p['fecha'])) ?></span>
                                </div>
                            </div>

                        </div>
                    <?php endwhile; ?>
                    
                    <button onclick="abrirPublicar(<?= $m['id'] ?>)" class="w-full py-3 bg-white/50 border border-dashed border-gray-400 rounded-xl text-gray-500 hover:bg-white hover:text-black transition flex items-center justify-center font-semibold text-sm">
                        <i class="fas fa-plus mr-2"></i> Añadir publicación
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($is_admin): ?>
    <div id="configModal" class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-md overflow-hidden shadow-2xl">
            <div class="p-5 border-b flex justify-between items-center bg-gray-50">
                <h3 class="font-bold text-lg"><i class="fas fa-paint-roller mr-2"></i>Diseño del Tablón</h3>
                <button onclick="$('#configModal').fadeOut()" class="text-gray-400 hover:text-black"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-5 space-y-4">
                <div><label class="block text-sm font-bold mb-1">Título</label><input type="text" id="conf_titulo" value="<?= htmlspecialchars($tablero['titulo']) ?>" class="w-full border rounded-lg p-2"></div>
                <div>
                    <label class="block text-sm font-bold mb-2">Fondo</label>
                    <div class="grid grid-cols-2 gap-2 mb-2">
                        <button onclick="setFondoTipo('color')" id="btn_tipo_color" class="py-2 border rounded-lg text-sm bg-gray-100">Color Sólido</button>
                        <button onclick="setFondoTipo('url')" id="btn_tipo_url" class="py-2 border rounded-lg text-sm">Imagen (URL)</button>
                    </div>
                    <input type="hidden" id="conf_fondo_tipo" value="<?= $tablero['fondo_tipo'] ?>">
                    <div id="div_fondo_color" class="hidden"><input type="color" id="conf_fondo_color" class="w-full h-10 rounded cursor-pointer border-none p-0"></div>
                    <div id="div_fondo_url" class="hidden"><input type="url" id="conf_fondo_url" placeholder="https://images.unsplash.com/..." class="w-full border rounded-lg p-2 text-sm"></div>
                </div>
                <button id="btn_guardar_config" onclick="guardarConfig()" class="w-full bg-black text-white font-bold py-3 rounded-lg mt-4">Guardar</button>
            </div>
        </div>
    </div>
    
    <div id="shareModal" class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl p-6 max-w-sm w-full text-center shadow-2xl relative">
            <button onclick="$('#shareModal').fadeOut()" class="absolute top-4 right-4 text-gray-400 hover:text-black"><i class="fas fa-times"></i></button>
            <div class="w-16 h-16 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl"><i class="fas fa-users"></i></div>
            <h3 class="text-lg font-bold mb-2">Enlace para Alumnos</h3>
            <p class="text-xs text-gray-500 mb-4">Comparte este enlace para que suban sus trabajos. No podrán borrar nada.</p>
            
            <div class="flex items-center space-x-2 mb-4">
                <input type="text" id="shareUrl" readonly class="flex-1 bg-gray-100 p-3 rounded text-xs text-center border font-mono font-bold outline-none">
                <button id="btnCopiar" onclick="copiarEnlace()" class="bg-gray-200 text-black px-4 py-3 rounded font-bold hover:bg-gray-300 transition" title="Copiar al portapapeles">
                    <i class="fas fa-copy"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="pubModal" class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl">
            <div class="p-4 border-b flex justify-between items-center">
                <h3 class="font-bold text-lg">Añadir al muro</h3>
                <button onclick="$('#pubModal').fadeOut()" class="text-gray-400 hover:text-black"><i class="fas fa-times"></i></button>
            </div>
            <form action="" method="POST" enctype="multipart/form-data" class="p-5" id="formSubida">
                <input type="hidden" name="action" value="publicar">
                <input type="hidden" name="modulo_id" id="modal_modulo_id">
                <input type="hidden" name="autor" id="modal_autor">

                <div class="flex border-b mb-4">
                    <button type="button" onclick="switchTab('texto')" id="tab_texto" class="tab-btn active flex-1 py-2 text-xs font-bold"><i class="fas fa-font mr-1"></i> Texto/Enlace</button>
                    <button type="button" onclick="switchTab('archivo')" id="tab_archivo" class="tab-btn flex-1 py-2 text-xs text-gray-500"><i class="fas fa-file-alt mr-1"></i> Doc / Imagen</button>
                    <button type="button" onclick="switchTab('media')" id="tab_media" class="tab-btn flex-1 py-2 text-xs text-gray-500"><i class="fas fa-video mr-1"></i> Vídeo / Audio</button>
                </div>

                <div id="panel_texto" class="space-y-3"><input type="url" name="url_media" placeholder="Pega un enlace o link de YouTube..." class="w-full border bg-gray-50 p-3 rounded-lg outline-none text-sm"></div>
                
                <div id="panel_archivo" class="hidden space-y-3">
                    <div class="border-2 border-dashed border-gray-300 rounded-xl p-6 text-center bg-gray-50">
                        <input type="file" name="archivo_doc" id="inputArchivoDoc" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700">
                        <p class="font-bold text-[9px] text-gray-400 mt-2">(doc, docx, gif, jpg, jpeg, odt, ods, pdf, png, ppt, pptx, xls, xlsx, webp)</p>
                    </div>
                </div>

                <div id="panel_media" class="hidden space-y-3">
                    <div class="border-2 border-dashed border-purple-200 rounded-xl p-6 text-center bg-purple-50">
                        <i class="fas fa-photo-video text-purple-400 text-2xl mb-2"></i>
                        <input type="file" name="archivo_media" id="inputArchivoMedia" accept="audio/*,video/*" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-purple-100 file:text-purple-700">
                        <p class="font-bold text-[10px] text-purple-600 mt-2">Soporta: MP3, WAV, OGG, MP4, WEBM (Máx 25 MB)</p>
                    </div>
                </div>

                <textarea name="contenido" placeholder="Escribe un comentario... Puedes usar <b>negrita</b> o <i>cursiva</i>" class="w-full border bg-gray-50 p-3 rounded-lg mt-3 h-24 outline-none text-sm"></textarea>
                <div class="mt-4"><button type="submit" id="btnSubir" class="w-full bg-blue-600 text-white py-3 rounded-xl font-bold">Publicar</button></div>
            </form>
        </div>
    </div>

    <script>
        const pokemonList = ["Bulbasaur", "Ivysaur", "Venusaur", "Charmander", "Charizard", "Squirtle", "Blastoise", "Caterpie", "Butterfree", "Weedle", "Pidgey", "Rattata", "Spearow", "Ekans", "Pikachu", "Raichu", "Sandshrew", "Nidoran", "Clefairy", "Vulpix", "Jigglypuff", "Zubat", "Oddish", "Paras", "Venonat", "Diglett", "Meowth", "Psyduck", "Mankey", "Growlithe", "Poliwag", "Abra", "Machop", "Bellsprout", "Tentacool", "Geodude", "Ponyta", "Slowpoke", "Magnemite", "Doduo", "Seel", "Grimer", "Shellder", "Gastly", "Gengar", "Onix", "Drowzee", "Krabby", "Voltorb", "Exeggcute", "Cubone", "Hitmonlee", "Hitmonchan", "Koffing", "Rhyhorn", "Chansey", "Tangela", "Horsea", "Goldeen", "Staryu", "Scyther", "Jynx", "Electabuzz", "Magmar", "Pinsir", "Tauros", "Magikarp", "Gyarados", "Lapras", "Ditto", "Eevee", "Snorlax", "Articuno", "Zapdos", "Moltres", "Dratini", "Mewtwo", "Mew"];
        let myPokemon = localStorage.getItem('padlet_alumno_id');
        if (!myPokemon) {
            myPokemon = pokemonList[Math.floor(Math.random() * pokemonList.length)] + " #" + (Math.floor(Math.random() * 99) + 1);
            localStorage.setItem('padlet_alumno_id', myPokemon);
        }

        <?php if (!$is_admin): ?>
            $('#nombre-alumno-ui').text(myPokemon);
        <?php endif; ?>

        $('#formSubida').on('submit', function(e) { 
            const maxBytes = 25 * 1024 * 1024;
            const docFile = $('#inputArchivoDoc')[0].files[0];
            const mediaFile = $('#inputArchivoMedia')[0].files[0];

            if ((docFile && docFile.size > maxBytes) || (mediaFile && mediaFile.size > maxBytes)) {
                e.preventDefault();
                alert("El archivo seleccionado supera el límite de 25 MB.");
                return false;
            }

            $('#modal_autor').val(myPokemon);
            $('#btnSubir').text('Publicando...').prop('disabled', true); 
        });

        function reaccionar(postId, tipo) {
            $.post(window.location.href, { action: 'reaccionar', post_id: postId, tipo_reaccion: tipo }, function(res) {
                let data = JSON.parse(res);
                if(data.status === 'ok') {
                    let iconSpan = $('#react-' + tipo + '-' + postId);
                    iconSpan.text(data.count).parent().addClass('scale-125').delay(200).queue(function(next){ $(this).removeClass('scale-125'); next(); });
                }
            });
        }

        // FUNCIONES DE PROFESOR
        <?php if ($is_admin): ?>
        function initConfigUI() {
            let tipo = $('#conf_fondo_tipo').val(); let valor = '<?= htmlspecialchars($tablero['fondo_valor'] ?? '', ENT_QUOTES) ?>';
            setFondoTipo(tipo);
            if(tipo === 'color') $('#conf_fondo_color').val(valor); if(tipo === 'url') $('#conf_fondo_url').val(valor);
        }
        function setFondoTipo(tipo) {
            $('#conf_fondo_tipo').val(tipo);
            if(tipo === 'color') { $('#btn_tipo_color').addClass('bg-gray-200 font-bold'); $('#btn_tipo_url').removeClass('bg-gray-200 font-bold'); $('#div_fondo_color').removeClass('hidden'); $('#div_fondo_url').addClass('hidden'); } 
            else { $('#btn_tipo_url').addClass('bg-gray-200 font-bold'); $('#btn_tipo_color').removeClass('bg-gray-200 font-bold'); $('#div_fondo_url').removeClass('hidden'); $('#div_fondo_color').addClass('hidden'); }
        }
        function guardarConfig() {
            let tipo = $('#conf_fondo_tipo').val(); let valor = tipo === 'color' ? $('#conf_fondo_color').val() : $('#conf_fondo_url').val();
            $.post(window.location.href, { action: 'guardar_config', tablero_id: <?= $tablero_id ?>, titulo: $('#conf_titulo').val(), fondo_tipo: tipo, fondo_valor: valor }).done(() => location.reload());
        }
        function nuevaSeccion() {
            let t = prompt("Nombre de la nueva columna:");
            if(t) $.post(window.location.href, { action: 'nueva_seccion', tablero_id: <?= $tablero_id ?>, titulo: t }).done(() => location.reload());
        }
        function eliminarSeccion(id) {
            if(confirm("¿Eliminar columna y todo su contenido?")) $.post(window.location.href, { action: 'eliminar_seccion', id: id }).done(() => location.reload());
        }
        function eliminarPublicacion(id) {
            if(confirm("¿Seguro que quieres borrar esta publicación?")) $.post(window.location.href, { action: 'eliminar_publicacion', id: id }).done(() => location.reload());
        }
        function compartirTablon() {
            let basePath = window.location.href.split('?')[0];
            $('#shareUrl').val(basePath + '?board=<?= $tablero_id ?>');
            $('#shareModal').fadeIn();
        }
        function copiarEnlace() {
            let copyText = document.getElementById("shareUrl");
            copyText.select();
            copyText.setSelectionRange(0, 99999); 
            navigator.clipboard.writeText(copyText.value);
            $('#btnCopiar').html('<i class="fas fa-check"></i>').removeClass('bg-gray-200 text-black').addClass('bg-green-500 text-white');
            setTimeout(() => $('#btnCopiar').html('<i class="fas fa-copy"></i>').removeClass('bg-green-500 text-white').addClass('bg-gray-200 text-black'), 2000);
        }
        $(document).ready(() => initConfigUI());
        <?php endif; ?>

        function abrirPublicar(id) { $('#modal_modulo_id').val(id); $('#pubModal').fadeIn(); }
        function switchTab(tab) {
            $('.tab-btn').removeClass('active text-blue-600 font-bold').addClass('text-gray-500'); 
            $('#tab_'+tab).addClass('active text-blue-600 font-bold').removeClass('text-gray-500');
            
            $('#panel_texto, #panel_archivo, #panel_media').hide();
            if(tab === 'texto') { $('#panel_texto').show(); $('#inputArchivoDoc, #inputArchivoMedia').val(''); } 
            else if(tab === 'archivo') { $('#panel_archivo').show(); $('input[name="url_media"], #inputArchivoMedia').val(''); }
            else if(tab === 'media') { $('#panel_media').show(); $('input[name="url_media"], #inputArchivoDoc').val(''); }
        }
    </script>
</body>
</html>