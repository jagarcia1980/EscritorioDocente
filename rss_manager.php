<?php
require_once __DIR__ . '/config.php';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// ==========================================
// CREACIÓN DE LA TABLA (Auto-instalación)
// ==========================================
$pdo->exec("CREATE TABLE IF NOT EXISTS custom_rss_notices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    link VARCHAR(255),
    image_url VARCHAR(255),
    pub_date DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// ==========================================
// GENERADOR DE FEED RSS 
// ==========================================
if (isset($_GET['feed']) && $_GET['feed'] === 'rss') {
    header("Content-Type: application/rss+xml; charset=utf-8");
    $stmt = $pdo->query("SELECT * FROM custom_rss_notices ORDER BY pub_date DESC");
    $items = $stmt->fetchAll();
    
    $rss_url = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?feed=rss";
    $site_url = "http://" . $_SERVER['HTTP_HOST'];

    echo "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n";
    echo "<rss version=\"2.0\">\n";
    echo "<channel>\n";
    echo "  <title>Avisos del Sitio</title>\n";
    echo "  <link>$site_url</link>\n";
    echo "  <description>Canal oficial de noticias y avisos del sistema.</description>\n";
    
    foreach ($items as $item) {
        // Formatear fecha a RFC 822 (Estándar RSS)
        $date = date('r', strtotime($item['pub_date']));
        echo "  <item>\n";
        echo "    <title><![CDATA[" . htmlspecialchars($item['title']) . "]]></title>\n";
        echo "    <link>" . htmlspecialchars($item['link']) . "</link>\n";
        
        $desc = $item['description'];
        if (!empty($item['image_url'])) {
            $desc = "<img src='" . htmlspecialchars($item['image_url']) . "' alt='image' /><br/>" . $desc;
        }
        echo "    <description><![CDATA[" . $desc . "]]></description>\n";
        echo "    <pubDate>$date</pubDate>\n";
        echo "    <guid isPermaLink=\"false\">" . $item['id'] . "</guid>\n";
        echo "  </item>\n";
    }
    
    echo "</channel>\n";
    echo "</rss>";
    exit;
}

// ==========================================
// PROCESAMIENTO DEL FORMULARIO 
// ==========================================

function remote_image_exists($url) {
    if (empty($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $ch = curl_init($url);
    // CURLOPT_NOBODY hace que sea una petición HEAD (muy rápida, no descarga la imagen)
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Máximo 5 segundos de espera
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Seguir redirecciones si las hay
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Evitar problemas con certificados SSL locales
    
    curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Retorna true solo si el servidor responde con un código 200 (OK)
    return $statusCode === 200;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
if ($action === 'save') {
        $id = $_POST['id'] ?? null;
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $link = trim($_POST['link']);
        $image_url = trim($_POST['image_url']);
        
        // --- VALIDACIÓN DE IMAGEN ---
        if (!empty($image_url)) {
            // Si la imagen NO existe, forzamos que sea NULL
            if (!remote_image_exists($image_url)) {
                $image_url = null;
                $message_extra = " (Aviso: La URL de la imagen no era válida o no existe, se guardó sin imagen).";
            } else {
                $message_extra = "";
            }
        } else {
            $image_url = null;
            $message_extra = "";
        }
        
        if ($title && $description) {
            if (empty($id)) {
                // INSERTAR
                $stmt = $pdo->prepare("INSERT INTO custom_rss_notices (title, description, link, image_url) VALUES (?, ?, ?, ?)");
                $stmt->execute([$title, $description, $link, $image_url]);
                $message = "Aviso publicado correctamente.";
            } else {
                // ACTUALIZAR
                $stmt = $pdo->prepare("UPDATE custom_rss_notices SET title=?, description=?, link=?, image_url=? WHERE id=?");
                $stmt->execute([$title, $description, $link, $image_url, $id]);
                $message = "Aviso actualizado correctamente.";
            }
        } else {
            $message = "Error: Título y descripción son obligatorios.";
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? null;
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM custom_rss_notices WHERE id=?");
            $stmt->execute([$id]);
            $message = "Aviso eliminado.";
        }
    }
    
    // Evitar reenvío de formulario al recargar
    header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($message));
    exit;
}


// Recoger mensajes de la URL
$msg = $_GET['msg'] ?? '';

// Obtener todas las noticias para mostrarlas
$stmt = $pdo->query("SELECT * FROM custom_rss_notices ORDER BY pub_date DESC");
$notices = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor de Avisos RSS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { primary: '#3b82f6', secondary: '#1e40af' }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 text-gray-800 font-sans antialiased p-6">

    <div class="max-w-5xl mx-auto">
        <div class="flex justify-between items-center mb-8 bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Gestor de Avisos RSS</h1>
                <p class="text-sm text-gray-500 mt-1">Crea y administra los avisos que se incluirán en tu feed.</p>
            </div>
            <div class="flex flex-col items-end">
                <a href="?feed=rss" target="_blank" class="flex items-center gap-2 bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M5 3a1 1 0 000 2c5.523 0 10 4.477 10 10a1 1 0 102 0C17 8.373 11.627 3 5 3z"/><path d="M4 9a1 1 0 011-1 7 7 0 017 7 1 1 0 11-2 0 5 5 0 00-5-5 1 1 0 01-1-1zM3 15a2 2 0 114 0 2 2 0 01-4 0z"/></svg>
                    Ver Feed RSS
                </a>
                <span class="text-xs text-gray-400 mt-2">URL: <?php echo "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?feed=rss"; ?></span>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="mb-6 p-4 rounded-lg bg-green-50 text-green-700 border border-green-200" id="alert-box">
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="md:col-span-1">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 sticky top-6">
                    <h2 class="text-lg font-bold mb-4 border-b pb-2" id="form-title">Crear Nuevo Aviso</h2>
                    
                    <form method="POST" id="notice-form" class="space-y-4">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" id="input-id" value="">
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Título *</label>
                            <input type="text" name="title" id="input-title" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-all">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Descripción *</label>
                            <textarea name="description" id="input-description" rows="4" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-all"></textarea>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Enlace (Opcional)</label>
                            <input type="url" name="link" id="input-link" placeholder="https://..." class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-all">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">URL de Imagen (Opcional)</label>
                            <input type="url" name="image_url" id="input-image" placeholder="https://.../imagen.jpg" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition-all">
                        </div>

                        <div class="flex gap-2 pt-2">
                            <button type="submit" class="flex-1 bg-primary hover:bg-secondary text-white font-medium py-2 px-4 rounded-lg transition-colors">Guardar Aviso</button>
                            <button type="button" id="btn-cancel" class="hidden bg-gray-200 hover:bg-gray-300 text-gray-700 font-medium py-2 px-4 rounded-lg transition-colors">Cancelar</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="md:col-span-2 space-y-4">
                <h2 class="text-xl font-bold mb-4 text-gray-800">Avisos Recientes</h2>
                
                <?php if (empty($notices)): ?>
                    <div class="text-center p-12 bg-white rounded-xl border border-dashed border-gray-300 text-gray-500">
                        No hay avisos creados. ¡Crea el primero!
                    </div>
                <?php endif; ?>

                <?php foreach ($notices as $notice): ?>
                    <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100 flex flex-col sm:flex-row gap-5 transition-all hover:shadow-md">
                        
                        
                        <?php $img_src = !empty($notice['image_url']) ? htmlspecialchars($notice['image_url']) : 'noimage.jpg'; ?>
                        <img src="<?php echo $img_src; ?>" alt="Portada" class="w-full sm:w-32 h-32 object-cover rounded-lg">
                        
                        
                        <div class="flex-1">
                            <div class="flex justify-between items-start">
                                <h3 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($notice['title']); ?></h3>
                                <span class="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded-full"><?php echo date('d M, Y H:i', strtotime($notice['pub_date'])); ?></span>
                            </div>
                            
                            <p class="text-gray-600 mt-2 text-sm line-clamp-2"><?php echo htmlspecialchars($notice['description']); ?></p>
                            
                            <?php if ($notice['link']): ?>
                                <a href="<?php echo htmlspecialchars($notice['link']); ?>" target="_blank" class="inline-block mt-2 text-sm text-primary hover:underline">Ver enlace asociado &rarr;</a>
                            <?php endif; ?>
                            
                            <div class="flex gap-3 mt-4 pt-4 border-t border-gray-50">
                                <button type="button" 
                                    class="btn-edit text-sm text-blue-600 hover:text-blue-800 font-medium flex items-center gap-1"
                                    data-id="<?php echo $notice['id']; ?>"
                                    data-title="<?php echo htmlspecialchars($notice['title']); ?>"
                                    data-description="<?php echo htmlspecialchars($notice['description']); ?>"
                                    data-link="<?php echo htmlspecialchars($notice['link']); ?>"
                                    data-image="<?php echo htmlspecialchars($notice['image_url']); ?>">
                                    Editar
                                </button>
                                
                                <form method="POST" onsubmit="return confirm('¿Seguro que quieres borrar este aviso?');" class="inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $notice['id']; ?>">
                                    <button type="submit" class="text-sm text-red-600 hover:text-red-800 font-medium flex items-center gap-1">
                                        Borrar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // Ocultar la alerta de éxito después de 3 segundos
            setTimeout(function() {
                $('#alert-box').fadeOut('slow');
            }, 3000);

            // Funcionalidad del botón Editar
            $('.btn-edit').click(function() {
                var btn = $(this);
                $('#input-id').val(btn.data('id'));
                $('#input-title').val(btn.data('title'));
                $('#input-description').val(btn.data('description'));
                $('#input-link').val(btn.data('link'));
                $('#input-image').val(btn.data('image'));
                
                $('#form-title').text('Editar Aviso');
                $('#btn-cancel').removeClass('hidden');
                
                // Hacer scroll suave hacia arriba en móviles
                $('html, body').animate({ scrollTop: 0 }, 'fast');
                $('#input-title').focus();
            });

            // Funcionalidad del botón Cancelar
            $('#btn-cancel').click(function() {
                $('#notice-form')[0].reset();
                $('#input-id').val('');
                $('#form-title').text('Crear Nuevo Aviso');
                $(this).addClass('hidden');
            });
        });
    </script>
</body>
</html>