<?php
// Desactivar salida de errores para no romper el JSON
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';

$url_gemini   = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=";
$url_tailwind = "https://cdn.tailwindcss.com";
$url_fa       = "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css";
$url_fonts    = "https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700;900&display=swap";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS roscos (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        tema VARCHAR(255), 
        json_datos LONGTEXT, 
        fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS rosco_puntuaciones (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        rosco_id INT, 
        jugador VARCHAR(100), 
        aciertos INT, 
        fallos INT, 
        tiempo_restante INT, 
        fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
        FOREIGN KEY (rosco_id) REFERENCES roscos(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS quizzes (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        tema VARCHAR(255), 
        json_datos LONGTEXT, 
        fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS quiz_puntuaciones (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        quiz_id INT, 
        jugador VARCHAR(100), 
        puntuacion INT, 
        fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
        FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
    )");

} catch (Exception $e) { 
    die("Error crítico de Base de Datos: " . $e->getMessage()); 
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    if ($_POST['action'] === 'generar_rosco' || $_POST['action'] === 'generar_quiz') {
        $tema = trim($_POST['tema'] ?? 'Cultura General');
        $es_rosco = $_POST['action'] === 'generar_rosco';

        if ($es_rosco) {
            $prompt = "Actúa como un creador de concursos. Genera exactamente 27 palabras (de la A a la Z, incluyendo la Ñ) sobre: '$tema'. 
            Alterna de forma lógica entre palabras que empiezan por la letra y palabras que simplemente la contienen.
            Devuelve SOLO un JSON con esta estructura:
            [{\"letra\": \"A\", \"tipo\": \"empieza\", \"palabra\": \"ASTRONAUTA\", \"definicion\": \"Persona que viaja por el espacio.\"}, {\"letra\": \"X\", \"tipo\": \"contiene\", \"palabra\": \"EXTRATERRESTRE\", \"definicion\": \"Que es de fuera de la Tierra.\"}...]
            No incluyas markdown, solo texto puro.";
        } else {
            $prompt = "Actúa como un creador de cuestionarios tipo trivia. Genera 10 preguntas de opción múltiple sobre: '$tema'. 
            Devuelve SOLO un JSON con esta estructura exacta:
            [{\"pregunta\": \"¿Cuál es la capital de Francia?\", \"correcta\": \"París\", \"incorrectas\": [\"Lyon\", \"Marsella\", \"Burdeos\"], \"feedback\": \"París es la capital y ciudad más poblada de Francia.\"},...]
            No incluyas markdown, solo texto puro.";
        }

        $payload = [
            "contents" => [["parts" => [["text" => $prompt]]]],
            "generationConfig" => ["temperature" => 0.8, "responseMimeType" => "application/json"]
        ];

        $max_intentos = 2;
        $exito = false;
        $nuevo_id = 0;
        $error_msg = "Error desconocido.";

        for ($intento = 1; $intento <= $max_intentos; $intento++) {
            $ch = curl_init($url_gemini . $GEMINI_API_KEY);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            $response = curl_exec($ch);
            $err = curl_errno($ch);
            curl_close($ch);

            if ($err) { $error_msg = "Error de conexión cURL."; continue; }

            $responseData = json_decode($response, true);
            $textoFinal = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if ($textoFinal) {
                $jsonLimpio = trim(str_replace(['```json', '```'], '', $textoFinal));
                if (json_decode($jsonLimpio)) {
                    $tabla = $es_rosco ? 'roscos' : 'quizzes';
                    $stmt = $pdo->prepare("INSERT INTO $tabla (tema, json_datos) VALUES (?, ?)");
                    $stmt->execute([$tema, $jsonLimpio]);
                    $nuevo_id = $pdo->lastInsertId();
                    $exito = true;
                    break;
                } else {
                    $error_msg = "La IA no devolvió un JSON válido.";
                }
            } else { $error_msg = "Respuesta vacía de Gemini."; }
        }

        if ($exito) { echo json_encode(["status" => "ok", "id" => $nuevo_id, "tipo" => $es_rosco ? 'rosco' : 'quiz']); } 
        else { echo json_encode(["error" => $error_msg]); }
        exit;
    }

    if ($_POST['action'] === 'guardar_puntuacion') {
        $stmt = $pdo->prepare("INSERT INTO rosco_puntuaciones (rosco_id, jugador, aciertos, fallos, tiempo_restante) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$_POST['rosco_id'], $_POST['jugador'], $_POST['aciertos'], $_POST['fallos'], $_POST['tiempo']]);
        
        $stmtRank = $pdo->prepare("SELECT * FROM rosco_puntuaciones WHERE rosco_id = ? ORDER BY aciertos DESC, tiempo_restante DESC LIMIT 3");
        $stmtRank->execute([$_POST['rosco_id']]);
        echo json_encode(["status" => "ok", "ranking" => $stmtRank->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }

    if ($_POST['action'] === 'guardar_puntuacion_quiz') {
        $stmt = $pdo->prepare("INSERT INTO quiz_puntuaciones (quiz_id, jugador, puntuacion) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['quiz_id'], $_POST['jugador'], $_POST['puntuacion']]);
        
        $stmtRank = $pdo->prepare("SELECT * FROM quiz_puntuaciones WHERE quiz_id = ? ORDER BY puntuacion DESC LIMIT 3");
        $stmtRank->execute([$_POST['quiz_id']]);
        echo json_encode(["status" => "ok", "ranking" => $stmtRank->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }

    if ($_POST['action'] === 'cargar_lista') {
        $tabla = $_POST['tipo'] === 'rosco' ? 'roscos' : 'quizzes';
        $offset = max(0, (int)$_POST['offset']);
        $stmt = $pdo->query("SELECT id, tema FROM $tabla ORDER BY id DESC LIMIT 4 OFFSET $offset");
        echo json_encode(["status" => "ok", "datos" => $stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }
}

$rosco_id = $_GET['rosco'] ?? null;
$quiz_id = $_GET['quiz'] ?? null;
$ranking_json = "[]";
$datos_juego = null;

if ($rosco_id) {
    $stmt = $pdo->prepare("SELECT * FROM roscos WHERE id = ?"); $stmt->execute([$rosco_id]);
    $datos_juego = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$datos_juego) die("<div style='padding:50px;text-align:center;font-family:sans-serif;'><h2>El reto no existe.</h2><a href='?'>Volver</a></div>");
    $stmtRank = $pdo->prepare("SELECT * FROM rosco_puntuaciones WHERE rosco_id = ? ORDER BY aciertos DESC, tiempo_restante DESC LIMIT 3");
    $stmtRank->execute([$rosco_id]);
    $ranking_json = json_encode($stmtRank->fetchAll(PDO::FETCH_ASSOC));
} elseif ($quiz_id) {
    $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE id = ?"); $stmt->execute([$quiz_id]);
    $datos_juego = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$datos_juego) die("<div style='padding:50px;text-align:center;font-family:sans-serif;'><h2>El reto no existe.</h2><a href='?'>Volver</a></div>");
    $stmtRank = $pdo->prepare("SELECT * FROM quiz_puntuaciones WHERE quiz_id = ? ORDER BY puntuacion DESC LIMIT 3");
    $stmtRank->execute([$quiz_id]);
    $ranking_json = json_encode($stmtRank->fetchAll(PDO::FETCH_ASSOC));
} else {
    $ultimos_roscos = $pdo->query("SELECT id, tema FROM roscos ORDER BY id DESC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
    $ultimos_quizzes = $pdo->query("SELECT id, tema FROM quizzes ORDER BY id DESC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retos de clase</title>
    <script src="<?= $url_tailwind ?>"></script>
    <link rel="stylesheet" href="<?= $url_fa ?>">
    <style>
        @import url('<?= $url_fonts ?>');
        body { font-family: 'Montserrat', sans-serif; }
        
        .tema-claro { background-color: #f8fafc; color: #1e293b; }
        .tema-quiz {
            background-image: linear-gradient(rgba(15, 23, 42, 0.8), rgba(15, 23, 42, 0.9)), url('https://images.unsplash.com/photo-1557683316-973673baf926?q=80&w=1920&auto=format&fit=crop');
            background-size: cover; background-position: center; background-attachment: fixed; color: white;
        }
        
        #circle-container { position: relative; width: 100%; max-width: 500px; aspect-ratio: 1/1; margin: 0 auto; }
        .letter-node { 
            position: absolute; width: 44px; height: 44px; border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; 
            font-weight: 900; font-size: 20px; font-family: 'Arial', sans-serif; 
            border: 3px solid white; box-shadow: 0 4px 8px rgba(0,0,0,0.3), inset 0 -3px 4px rgba(0,0,0,0.2); 
            transition: all 0.3s; transform: translate(-50%, -50%); 
            color: white; text-shadow: 1px 1px 2px rgba(0,0,0,0.4); 
        }
        
        .letra-base { background: linear-gradient(180deg, #2563eb, #1d4ed8); border-color: white; }
        .letra-acierto { background: linear-gradient(180deg, #22c55e, #16a34a); border-color: white; }
        .letra-fallo { background: linear-gradient(180deg, #ef4444, #dc2626); border-color: white; }
        @keyframes heartbeat { 0%, 100% { transform: translate(-50%, -50%) scale(1.3); } 50% { transform: translate(-50%, -50%) scale(1.6); } }
        .letra-actual { background: linear-gradient(180deg, #fbbf24, #d97706); z-index: 10; border-color: white; box-shadow: 0 0 20px #f59e0b, inset 0 -3px 4px rgba(0,0,0,0.2); animation: heartbeat 0.8s infinite; }
        
        .quiz-btn { transition: transform 0.1s, filter 0.1s; }
        .quiz-btn:active { transform: scale(0.96); filter: brightness(0.8); }
        .bg-quiz-0 { background-color: #e21b3c; border-bottom: 4px solid #b0142e; }
        .bg-quiz-1 { background-color: #1368ce; border-bottom: 4px solid #0e4e9a; }
        .bg-quiz-2 { background-color: #d89e00; border-bottom: 4px solid #a67a00; }
        .bg-quiz-3 { background-color: #26890c; border-bottom: 4px solid #1c6609; }
        .timer-bar-container { width: 100%; background-color: #e2e8f0; height: 12px; border-radius: 6px; overflow: hidden; margin-bottom: 1rem; }
        .timer-bar-fill { height: 100%; background-color: #a855f7; transition: width 1s linear, background-color 0.3s; }
        
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-3px); } 75% { transform: translateX(3px); } }
        .timer-danger { animation: shake 0.5s infinite; color: #ef4444 !important; border-color: #ef4444 !important; }
        
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: rgba(0,0,0,0.05); border-radius: 10px;}
        ::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.2); border-radius: 10px;}
    </style>
</head>
<body class="flex flex-col <?= $quiz_id ? 'tema-quiz' : 'tema-claro' ?>">

<?php if (!$rosco_id && !$quiz_id): ?>
    <div class="container mx-auto p-8 max-w-6xl flex-1 flex flex-col">
        <header class="text-center mb-12 mt-4">
            <h1 class="text-5xl font-black text-slate-800 mb-2 uppercase tracking-tighter">Arena de Retos</h1>
            <p class="text-slate-500 font-medium">Crea y comparte desafíos educativos generados por IA.</p>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
            <div class="bg-white rounded-3xl p-8 shadow-xl border border-slate-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-2 bg-blue-600"></div>
                <h2 class="text-2xl font-black mb-2 text-blue-800 uppercase tracking-wide"><i class="fa-solid fa-circle-notch mr-2 text-blue-500"></i>Crear Rosco</h2>
                <p class="text-sm text-slate-500 mb-6">27 palabras. Letras de la A a la Z. Un clásico.</p>
                <input type="text" id="tema-rosco" placeholder="Tema del rosco..." class="w-full bg-slate-50 border-2 border-slate-200 rounded-xl p-4 text-slate-800 mb-4 font-bold focus:outline-none focus:border-blue-500 transition-colors">
                <button onclick="generarReto('generar_rosco', 'tema-rosco', this)" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-black py-4 rounded-xl shadow-md transition uppercase">Generar Rosco</button>
                
                <div class="flex justify-between items-center mt-8 mb-4 border-b border-slate-100 pb-2">
                    <h3 class="font-bold text-slate-400 text-sm uppercase">Últimos Roscos</h3>
                    <div class="flex space-x-2">
                        <button onclick="cambiarPagina('rosco', -4)" class="bg-slate-100 hover:bg-slate-200 text-slate-500 px-2 py-1 rounded transition"><i class="fa-solid fa-chevron-left"></i></button>
                        <button onclick="cambiarPagina('rosco', 4)" class="bg-slate-100 hover:bg-slate-200 text-slate-500 px-2 py-1 rounded transition"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="space-y-3" id="lista-rosco">
                    <?php foreach($ultimos_roscos as $r): ?>
                        <div class="bg-slate-50 border border-slate-100 rounded-xl p-3 flex justify-between items-center hover:shadow-md transition">
                            <span class="font-bold text-sm truncate w-1/2 text-slate-700"><?= htmlspecialchars($r['tema']) ?></span>
                            <div class="flex space-x-2">
                                <a href="?rosco=<?= $r['id'] ?>" class="bg-blue-100 text-blue-700 hover:bg-blue-200 px-3 py-1 text-xs font-bold rounded-lg transition">Jugar</a>
                                <button onclick="copiarLink('?rosco=<?= $r['id'] ?>')" class="bg-slate-200 text-slate-600 hover:bg-slate-300 px-2 py-1 rounded-lg text-xs transition"><i class="fa-solid fa-link"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-white rounded-3xl p-8 shadow-xl border border-slate-100 relative overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-2 bg-purple-600"></div>
                <h2 class="text-2xl font-black mb-2 text-purple-800 uppercase tracking-wide"><i class="fa-solid fa-bolt mr-2 text-purple-500"></i>Crear Quiz Trivia</h2>
                <p class="text-sm text-slate-500 mb-6">10 preguntas de opción múltiple con retroalimentación.</p>
                <input type="text" id="tema-quiz" placeholder="Tema del quiz..." class="w-full bg-slate-50 border-2 border-slate-200 rounded-xl p-4 text-slate-800 mb-4 font-bold focus:outline-none focus:border-purple-500 transition-colors">
                <button onclick="generarReto('generar_quiz', 'tema-quiz', this)" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-black py-4 rounded-xl shadow-md transition uppercase">Generar Quiz</button>
                
                <div class="flex justify-between items-center mt-8 mb-4 border-b border-slate-100 pb-2">
                    <h3 class="font-bold text-slate-400 text-sm uppercase">Últimos Quizzes</h3>
                    <div class="flex space-x-2">
                        <button onclick="cambiarPagina('quiz', -4)" class="bg-slate-100 hover:bg-slate-200 text-slate-500 px-2 py-1 rounded transition"><i class="fa-solid fa-chevron-left"></i></button>
                        <button onclick="cambiarPagina('quiz', 4)" class="bg-slate-100 hover:bg-slate-200 text-slate-500 px-2 py-1 rounded transition"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="space-y-3" id="lista-quiz">
                    <?php foreach($ultimos_quizzes as $q): ?>
                        <div class="bg-slate-50 border border-slate-100 rounded-xl p-3 flex justify-between items-center hover:shadow-md transition">
                            <span class="font-bold text-sm truncate w-1/2 text-slate-700"><?= htmlspecialchars($q['tema']) ?></span>
                            <div class="flex space-x-2">
                                <a href="?quiz=<?= $q['id'] ?>" class="bg-purple-100 text-purple-700 hover:bg-purple-200 px-3 py-1 text-xs font-bold rounded-lg transition">Jugar</a>
                                <button onclick="copiarLink('?quiz=<?= $q['id'] ?>')" class="bg-slate-200 text-slate-600 hover:bg-slate-300 px-2 py-1 rounded-lg text-xs transition"><i class="fa-solid fa-link"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
        function generarReto(accion, inputId, btn) {
            const tema = document.getElementById(inputId).value.trim();
            if(!tema) { alert("Escribe un tema."); return; }
            const originalText = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Procesando...';
            
            const fd = new URLSearchParams(); fd.append('action', accion); fd.append('tema', tema);
            fetch(window.location.pathname, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: fd.toString() })
            .then(r => r.json()).then(res => {
                if(res.status === 'ok') window.location.href = `?${res.tipo}=${res.id}`;
                else { alert(res.error); btn.disabled = false; btn.innerHTML = originalText; }
            }).catch(e => { alert("No se ha podido generar el reto. Quizás fuiste demasiado específico. Intenta introducir un tema más general."); btn.disabled = false; btn.innerHTML = originalText; });
        }
        function copiarLink(params) {
            navigator.clipboard.writeText(window.location.href.split('?')[0] + params).then(() => alert("Enlace copiado."));
        }

        let offsetListas = { rosco: 0, quiz: 0 };
        function cambiarPagina(tipo, delta) {
            let nuevoOffset = offsetListas[tipo] + delta;
            if(nuevoOffset < 0) return; 
            
            const fd = new URLSearchParams(); 
            fd.append('action', 'cargar_lista'); 
            fd.append('tipo', tipo); 
            fd.append('offset', nuevoOffset);
            
            fetch(window.location.pathname, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: fd.toString() })
            .then(r => r.json()).then(res => {
                if(res.status === 'ok' && res.datos.length > 0) {
                    offsetListas[tipo] = nuevoOffset;
                    let html = '';
                    let color = tipo === 'rosco' ? 'blue' : 'purple';
                    res.datos.forEach(item => {
                        let tSafe = item.tema.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                        html += `
                        <div class="bg-slate-50 border border-slate-100 rounded-xl p-3 flex justify-between items-center hover:shadow-md transition">
                            <span class="font-bold text-sm truncate w-1/2 text-slate-700">${tSafe}</span>
                            <div class="flex space-x-2">
                                <a href="?${tipo}=${item.id}" class="bg-${color}-100 text-${color}-700 hover:bg-${color}-200 px-3 py-1 text-xs font-bold rounded-lg transition">Jugar</a>
                                <button onclick="copiarLink('?${tipo}=${item.id}')" class="bg-slate-200 text-slate-600 hover:bg-slate-300 px-2 py-1 rounded-lg text-xs transition"><i class="fa-solid fa-link"></i></button>
                            </div>
                        </div>`;
                    });
                    document.getElementById('lista-' + tipo).innerHTML = html;
                }
            });
        }
    </script>

<?php elseif ($rosco_id): ?>
    <div class="w-full p-4 flex justify-between items-center bg-white shadow-sm border-b border-slate-200">
        <a href="?" class="text-slate-500 hover:text-blue-600 font-bold transition"><i class="fa-solid fa-arrow-left mr-2"></i> Salir</a>
        <button onclick="navigator.clipboard.writeText(window.location.href).then(()=>alert('Copiado'))" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-full text-sm font-bold transition">
            <i class="fa-solid fa-share-nodes mr-2"></i> Compartir
        </button>
    </div>

    <div class="w-full flex justify-center gap-4 mt-6 px-4">
        <div class="bg-cyan-500 text-white font-bold px-4 py-1.5 rounded-full shadow-sm text-sm">Correctas: <span id="ui-aciertos">0</span></div>
        <div class="bg-pink-500 text-white font-bold px-4 py-1.5 rounded-full shadow-sm text-sm">Incorrectas: <span id="ui-fallos">0</span></div>
        <div class="bg-slate-700 text-white font-bold px-4 py-1.5 rounded-full shadow-sm text-sm flex items-center gap-2">
            <i class="fa-regular fa-clock"></i> <span id="ui-timer">300</span>
        </div>
    </div>

    <div class="flex-1 flex flex-col md:flex-row max-w-[1200px] mx-auto w-full p-4 md:p-8 gap-12 items-center justify-center">
        
        <div class="w-full md:w-1/2 flex flex-col items-center">
            <h1 class="text-4xl font-black text-blue-900 mb-8 tracking-tighter">Pasapalabra</h1>
            <div id="circle-container" class="mb-4"></div>
        </div>

        <div class="w-full md:w-1/2 bg-white rounded-3xl p-8 shadow-xl border border-cyan-100 relative overflow-hidden max-w-[500px]">
            
            <div id="end-modal" class="hidden absolute inset-0 z-50 flex-col items-center justify-start p-8 text-center bg-white/95 backdrop-blur-md overflow-y-auto">
                <h2 class="text-3xl font-black text-blue-900 mb-2 uppercase mt-4">¡Fin del Juego!</h2>
                <p class="text-lg mb-6 text-slate-600 font-bold">Aciertos: <span id="final-aciertos" class="font-black text-green-500 text-2xl ml-2"></span></p>
                
                <div class="w-full bg-slate-50 p-5 rounded-2xl border border-slate-200 mb-6 shadow-inner transition-all duration-500" id="input-guardar-container">
                    <input type="text" id="player-name" placeholder="Tu Nombre o Iniciales" class="w-full bg-white border-2 border-slate-300 rounded-xl p-3 text-center font-bold text-slate-800 mb-4 uppercase focus:outline-none focus:border-blue-500" maxlength="15">
                    <button onclick="guardarPuntuacion()" id="btn-guardar" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-black py-3 rounded-xl transition shadow-md">Guardar Marca</button>
                </div>
                
                <button onclick="window.location.reload()" id="btn-reintentar" class="hidden w-full bg-slate-200 hover:bg-slate-300 text-slate-700 font-black py-3 rounded-xl transition shadow-md mb-6 uppercase">Intentarlo de nuevo</button>
                
                <div class="w-full text-left">
                    <div id="top3-wrapper" class="transition-all duration-700 p-3 rounded-xl border border-transparent">
                        <h3 class="text-slate-400 font-black text-sm mb-2 uppercase border-b border-slate-200 pb-2">Top 3 Histórico</h3>
                        <ul id="top3-list" class="text-sm text-slate-600 space-y-2 mb-4 font-medium"></ul>
                    </div>
                    
                    <h3 class="text-pink-500 font-black text-sm mb-2 uppercase border-b border-slate-200 pb-2 mt-4">Palabras Falladas</h3>
                    <div id="soluciones-lista" class="text-xs text-slate-500 overflow-y-auto max-h-[150px] pr-2 space-y-3 font-medium"></div>
                </div>
            </div>

            <div id="game-ui" class="text-center">
                <h2 class="text-2xl font-black text-blue-900 mb-2">Letra <span id="ui-letra">A</span></h2>
                <p class="text-sm font-bold text-pink-500 mb-6 uppercase"><span id="ui-tipo-letra">Empieza por la</span> <span id="ui-letra-sub">A</span></p>
                
                <div class="min-h-[100px] mb-8 flex flex-col justify-center">
                    <p class="text-lg text-slate-700 font-medium leading-relaxed" id="ui-definicion">Cargando definición...</p>
                </div>
                
                <div class="flex flex-col space-y-4">
                    <input type="text" id="answer-input" placeholder="Escribe tu respuesta aquí" class="w-full bg-white border-2 border-cyan-400 rounded-xl p-4 text-center text-lg font-bold text-slate-800 focus:outline-none focus:border-blue-600 uppercase transition-colors" autocomplete="off">
                    
                    <div class="flex space-x-3 mt-4">
                        <button onclick="comprobar('enviar')" class="flex-1 bg-blue-900 hover:bg-blue-800 text-white font-bold py-4 rounded-xl transition shadow-md">Comprobar</button>
                        <button onclick="comprobar('pasapalabra')" class="flex-1 bg-pink-500 hover:bg-pink-600 text-white font-bold py-4 rounded-xl transition shadow-md">Pasapalabra</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        const rankingData = <?= $ranking_json ?>; 
        let palabras = []; 
        try { palabras = <?= $datos_juego['json_datos'] ?: '[]' ?>; } catch(e) { console.error("Error crítico al leer el JSON del Rosco:", e); alert("Hubo un problema al cargar el texto de la IA."); }

        let estadoPalabras = new Array(palabras.length).fill(0), currentIndex = -1, aciertos = 0, fallos = 0, timer = 300, interval = null, playing = false;
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        
        function playSound(t) {
            if(!audioCtx) return; if(audioCtx.state === 'suspended') audioCtx.resume();
            const osc = audioCtx.createOscillator(), gain = audioCtx.createGain(); osc.connect(gain); gain.connect(audioCtx.destination); const now = audioCtx.currentTime;
            if (t === 'acierto') { osc.type = 'sine'; osc.frequency.setValueAtTime(400, now); osc.frequency.exponentialRampToValueAtTime(800, now+0.1); gain.gain.setValueAtTime(0.5, now); gain.gain.exponentialRampToValueAtTime(0.01, now+0.3); osc.start(now); osc.stop(now+0.3); } 
            else if (t === 'fallo') { osc.type = 'sawtooth'; osc.frequency.setValueAtTime(150, now); osc.frequency.exponentialRampToValueAtTime(100, now+0.2); gain.gain.setValueAtTime(0.5, now); gain.gain.exponentialRampToValueAtTime(0.01, now+0.3); osc.start(now); osc.stop(now+0.3); } 
            else if (t === 'tick_normal') { osc.type = 'sine'; osc.frequency.setValueAtTime(600, now); gain.gain.setValueAtTime(0.01, now); gain.gain.exponentialRampToValueAtTime(0.001, now+0.05); osc.start(now); osc.stop(now+0.05); }
            else if (t === 'tick_warning') { osc.type = 'square'; osc.frequency.setValueAtTime(800, now); gain.gain.setValueAtTime(0.03, now); gain.gain.exponentialRampToValueAtTime(0.001, now+0.05); osc.start(now); osc.stop(now+0.05); }
        }
        function norm(t) { return t.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim(); }
        
        function dibujarRosco() {
            const container = document.getElementById('circle-container'); container.innerHTML = '';
            for(let i=0; i<palabras.length; i++) {
                const angle = (i/palabras.length)*2*Math.PI - (Math.PI/2), x = 50 + Math.cos(angle)*45, y = 50 + Math.sin(angle)*45;
                const node = document.createElement('div'); node.id = 'nodo-'+i; node.className = 'letter-node letra-base'; node.textContent = palabras[i].letra; node.style.left = x+'%'; node.style.top = y+'%'; container.appendChild(node);
            }
        }
        function actualizarUI() {
            for(let i=0; i<palabras.length; i++) {
                let n = document.getElementById('nodo-'+i); if(!n) continue; n.className = 'letter-node'; 
                if (i === currentIndex) n.classList.add('letra-actual');
                else { if(estadoPalabras[i]===0) n.classList.add('letra-base'); if(estadoPalabras[i]===1) n.classList.add('letra-acierto'); if(estadoPalabras[i]===2) n.classList.add('letra-fallo'); }
            }
            document.getElementById('ui-aciertos').textContent = aciertos; document.getElementById('ui-fallos').textContent = fallos;
        }
        function buscarSig() {
            if (!estadoPalabras.includes(0)) return -1;
            let attempts = 0, next = currentIndex;
            while(attempts < palabras.length) { next++; if(next>=palabras.length) next=0; if(estadoPalabras[next]===0) return next; attempts++; } return -1;
        }
        function cargarLetra(idx) {
            if (idx === -1) { fin(); return; } currentIndex = idx;
            const p = palabras[idx];
            document.getElementById('ui-letra').textContent = p.letra;
            document.getElementById('ui-tipo-letra').textContent = p.tipo === 'contiene' ? 'Contiene la' : 'Empieza por la';
            document.getElementById('ui-letra-sub').textContent = p.letra;
            document.getElementById('ui-definicion').textContent = p.definicion;
            document.getElementById('answer-input').value = ''; document.getElementById('answer-input').focus(); actualizarUI();
        }
        function comprobar(acc) {
            if(!playing) return; if(audioCtx && audioCtx.state === 'suspended') audioCtx.resume(); 
            if(acc === 'pasapalabra') { cargarLetra(buscarSig()); return; }
            const val = document.getElementById('answer-input').value; if(!val) return;
            if(norm(val) === norm(palabras[currentIndex].palabra)) { estadoPalabras[currentIndex] = 1; aciertos++; playSound('acierto'); } 
            else { estadoPalabras[currentIndex] = 2; fallos++; playSound('fallo'); }
            cargarLetra(buscarSig());
        }
        function fin() {
            playing = false; clearInterval(interval); document.getElementById('answer-input').disabled = true; document.getElementById('ui-timer').classList.remove('timer-danger'); actualizarUI();
            let html = ''; for(let i=0; i<palabras.length; i++) { if(estadoPalabras[i]!==1) html += `<div class="border-b border-slate-100 pb-2"><span class="font-bold text-pink-500">${palabras[i].letra} - ${palabras[i].palabra.toUpperCase()}</span><br><span class="text-slate-500">${palabras[i].definicion}</span></div>`; }
            document.getElementById('soluciones-lista').innerHTML = html || '<p class="text-green-500 font-bold">¡Pleno!</p>';
            let top = ''; if(rankingData.length > 0) rankingData.slice(0,3).forEach((r, i) => top += `<li><span class="text-blue-500 font-bold">#${i+1}</span> ${r.jugador} - ${r.aciertos} Aciertos</li>`); else top = '<li>No hay marcas.</li>';
            document.getElementById('top3-list').innerHTML = top; document.getElementById('final-aciertos').textContent = aciertos;
            document.getElementById('end-modal').classList.remove('hidden'); document.getElementById('end-modal').classList.add('flex'); document.getElementById('player-name').focus();
        }
        function guardarPuntuacion() {
            let n = document.getElementById('player-name').value.trim() || "Anónimo"; const fd = new URLSearchParams();
            fd.append('action', 'guardar_puntuacion'); fd.append('rosco_id', <?= $rosco_id ?>); fd.append('jugador', n); fd.append('aciertos', aciertos); fd.append('fallos', fallos); fd.append('tiempo', timer);
            
            document.getElementById('btn-guardar').disabled = true;
            document.getElementById('btn-guardar').textContent = 'Guardando...';

            fetch(window.location.pathname, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: fd.toString() })
            .then(r => r.json())
            .then(res => {
                if(res.status === 'ok') {
                    let top = ''; let esTop1 = false;
                    res.ranking.forEach((r, i) => {
                        top += `<li><span class="text-blue-500 font-bold">#${i+1}</span> ${r.jugador} - ${r.aciertos} Aciertos</li>`;
                        if (i === 0 && r.jugador === n && parseInt(r.aciertos) === aciertos && parseInt(r.tiempo_restante) === timer) esTop1 = true;
                    });
                    document.getElementById('top3-list').innerHTML = top;
                    
                    if(esTop1) dispararConfeti();

                    document.getElementById('input-guardar-container').style.display = 'none';
                    document.getElementById('btn-reintentar').style.display = 'block';
                    
                    const top3Wrapper = document.getElementById('top3-wrapper');
                    top3Wrapper.classList.add('bg-yellow-100', 'border-yellow-300', 'scale-105', 'shadow-lg');
                    setTimeout(() => { top3Wrapper.classList.remove('bg-yellow-100', 'border-yellow-300', 'scale-105', 'shadow-lg'); }, 4000);
                }
            });
        }
        
        function dispararConfeti() {
            const colores = ['#fce18a', '#ff7171', '#9bdc28', '#38bdf8', '#2dd4bf'];
            for(let i=0; i<70; i++) {
                let confeti = document.createElement('div');
                confeti.style.position = 'fixed'; confeti.style.left = Math.random() * 100 + 'vw'; confeti.style.top = '-20px';
                confeti.style.width = Math.random() * 10 + 5 + 'px'; confeti.style.height = Math.random() * 10 + 5 + 'px';
                confeti.style.backgroundColor = colores[Math.floor(Math.random()*colores.length)]; confeti.style.zIndex = '99999';
                confeti.style.borderRadius = Math.random() > 0.5 ? '50%' : '0';
                document.body.appendChild(confeti);
                
                let duracion = Math.random() * 2 + 2;
                confeti.animate([
                    { transform: `translate3d(0,0,0) rotate(0deg)`, opacity: 1 },
                    { transform: `translate3d(${Math.random()*300 - 150}px, 100vh, 0) rotate(${Math.random()*720}deg)`, opacity: 0 }
                ], { duration: duracion * 1000, easing: 'cubic-bezier(.37,0,.63,1)' });
                setTimeout(() => confeti.remove(), duracion * 1000);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            if(palabras.length > 0) {
                dibujarRosco(); playing = true; cargarLetra(0);
                document.getElementById('answer-input').addEventListener('keypress', e => { if(e.key==='Enter') { e.preventDefault(); if(e.target.value.trim()==='') comprobar('pasapalabra'); else comprobar('enviar'); } });
                interval = setInterval(() => {
                    if(!playing) return; timer--; document.getElementById('ui-timer').textContent = timer;
                    if(timer > 20) {
                        playSound('tick_normal');
                    } else if(timer <= 20 && timer > 0) { 
                        playSound('tick_warning'); document.getElementById('ui-timer').classList.add('timer-danger'); 
                    }
                    if(timer<=0) fin();
                }, 1000);
            }
        });
    </script>

<?php elseif ($quiz_id): ?>
    <div class="w-full p-4 flex justify-between items-center bg-black/40 backdrop-blur-sm z-20 relative">
        <a href="?" class="text-gray-300 hover:text-white font-bold transition"><i class="fa-solid fa-arrow-left mr-2"></i> Salir</a>
        <button onclick="navigator.clipboard.writeText(window.location.href).then(()=>alert('Copiado'))" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-full text-sm font-bold shadow backdrop-blur-md transition">
            <i class="fa-solid fa-share-nodes mr-2"></i> Compartir
        </button>
    </div>

    <div class="flex-1 flex flex-col max-w-4xl mx-auto w-full p-4 md:p-8 items-center justify-center z-10 relative">
        <div class="w-full bg-white/95 backdrop-blur-xl rounded-[2rem] p-6 md:p-10 relative overflow-hidden shadow-2xl">
            
            <div id="end-modal-quiz" class="hidden absolute inset-0 z-50 flex-col items-center justify-center p-6 text-center bg-white/95 backdrop-blur-md overflow-y-auto">
                <h2 class="text-4xl font-black text-slate-800 mb-2 uppercase">¡Quiz Terminado!</h2>
                <p class="text-xl mb-6 text-slate-600 font-bold">Puntuación: <span id="final-score" class="font-black text-purple-600 text-3xl ml-2"></span></p>
                <div class="w-full max-w-sm bg-slate-50 p-5 rounded-2xl border border-slate-200 mb-6 shadow-inner" id="input-guardar-quiz-container">
                    <input type="text" id="quiz-player-name" placeholder="Tu Nombre" class="w-full bg-white border-2 border-slate-300 rounded-xl p-3 text-center font-bold text-slate-800 mb-4 uppercase focus:outline-none focus:border-purple-500" maxlength="15">
                    <button onclick="guardarPuntuacionQuiz()" id="btn-guardar-quiz" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-black py-3 rounded-xl transition shadow-lg">Guardar Marca</button>
                </div>
                <button onclick="window.location.reload()" id="btn-reintentar-quiz" class="hidden w-full max-w-sm bg-slate-200 hover:bg-slate-300 text-slate-700 font-black py-3 rounded-xl transition shadow-md mb-6 uppercase">Intentarlo de nuevo</button>
                <div class="w-full max-w-sm text-left">
                    <div id="top3-quiz-wrapper" class="transition-all duration-700 p-3 rounded-xl border border-transparent">
                        <h3 class="text-slate-400 font-black text-sm mb-2 uppercase border-b border-slate-200 pb-2">Top 3 Histórico</h3>
                        <ul id="top3-quiz-list" class="text-sm text-slate-600 space-y-2 font-medium"></ul>
                    </div>
                </div>
            </div>

            <div id="feedback-modal" class="hidden absolute inset-0 z-40 flex-col items-center justify-center p-6 text-center bg-slate-900/95 backdrop-blur-md">
                <div id="feedback-icon" class="text-7xl mb-4"></div>
                <h2 id="feedback-title" class="text-3xl font-black mb-4 uppercase"></h2>
                <div class="bg-black/50 p-6 rounded-2xl border border-white/10 max-w-lg w-full shadow-2xl">
                    <p class="text-sm text-gray-400 uppercase font-bold mb-1">La respuesta correcta era:</p>
                    <p id="feedback-correcta" class="text-2xl font-black text-white mb-4"></p>
                    <p class="text-sm text-gray-400 uppercase font-bold mb-1">Explicación:</p>
                    <p id="feedback-texto" class="text-md text-gray-200 italic leading-relaxed"></p>
                </div>
                <button onclick="siguientePregunta()" class="mt-8 bg-white text-slate-900 font-black py-4 px-12 rounded-full transition hover:bg-gray-200 shadow-xl text-lg hover:scale-105 transform">Siguiente <i class="fa-solid fa-arrow-right ml-2"></i></button>
            </div>

            <div id="quiz-ui">
                <div class="flex justify-between items-center mb-6">
                    <div class="text-xs font-bold text-slate-500 uppercase tracking-widest bg-slate-100 px-4 py-1.5 rounded-full border border-slate-200">Trivia: <?= htmlspecialchars($datos_juego['tema']) ?></div>
                    <div class="font-black text-xl text-slate-400 bg-slate-100 px-4 py-1 rounded-full"><span id="q-actual" class="text-slate-800">1</span> / <span id="q-total">10</span></div>
                </div>

                <div class="timer-bar-container"><div id="timer-bar" class="timer-bar-fill w-full"></div></div>
                <div class="text-center mb-8 bg-white p-8 rounded-3xl border border-slate-100 shadow-sm min-h-[160px] flex items-center justify-center">
                    <h2 id="q-pregunta" class="text-2xl md:text-3xl font-black text-slate-800 leading-tight">Cargando...</h2>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="opciones-grid">
                    <button class="quiz-btn bg-quiz-0 text-white font-bold text-lg md:text-xl p-6 rounded-2xl text-left flex items-center shadow-md">
                        <span class="w-10 h-10 rounded-lg bg-black/20 flex items-center justify-center mr-4 flex-shrink-0"><i class="fa-solid fa-play -rotate-90"></i></span> <span class="op-texto leading-tight">Opcion 1</span>
                    </button>
                    <button class="quiz-btn bg-quiz-1 text-white font-bold text-lg md:text-xl p-6 rounded-2xl text-left flex items-center shadow-md">
                        <span class="w-10 h-10 rounded-lg bg-black/20 flex items-center justify-center mr-4 flex-shrink-0"><i class="fa-solid fa-gem"></i></span> <span class="op-texto leading-tight">Opcion 2</span>
                    </button>
                    <button class="quiz-btn bg-quiz-2 text-white font-bold text-lg md:text-xl p-6 rounded-2xl text-left flex items-center shadow-md">
                        <span class="w-10 h-10 rounded-lg bg-black/20 flex items-center justify-center mr-4 flex-shrink-0"><i class="fa-solid fa-circle"></i></span> <span class="op-texto leading-tight">Opcion 3</span>
                    </button>
                    <button class="quiz-btn bg-quiz-3 text-white font-bold text-lg md:text-xl p-6 rounded-2xl text-left flex items-center shadow-md">
                        <span class="w-10 h-10 rounded-lg bg-black/20 flex items-center justify-center mr-4 flex-shrink-0"><i class="fa-solid fa-square"></i></span> <span class="op-texto leading-tight">Opcion 4</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <script>
        const rankingQuizData = <?= $ranking_json ?>;
        let preguntas = []; 
        try { preguntas = <?= $datos_juego['json_datos'] ?: '[]' ?>; } catch(e) { console.error("Error crítico al leer el JSON del Quiz:", e); alert("Hubo un problema al cargar el texto de la IA."); }

        let currentQ = 0, score = 0, timerQuiz = 15, intervalQuiz = null, canAnswer = false;
        const audioCtxQuiz = new (window.AudioContext || window.webkitAudioContext)();
        
        function playQSound(t) {
            if(!audioCtxQuiz) return; if(audioCtxQuiz.state === 'suspended') audioCtxQuiz.resume();
            const osc = audioCtxQuiz.createOscillator(), gain = audioCtxQuiz.createGain(), now = audioCtxQuiz.currentTime;
            osc.connect(gain); gain.connect(audioCtxQuiz.destination);
            if(t==='ok') { osc.type='sine'; osc.frequency.setValueAtTime(500, now); osc.frequency.exponentialRampToValueAtTime(1000, now+0.1); gain.gain.setValueAtTime(0.5, now); gain.gain.exponentialRampToValueAtTime(0.01, now+0.3); osc.start(now); osc.stop(now+0.3); }
            else if(t==='fail') { osc.type='sawtooth'; osc.frequency.setValueAtTime(200, now); osc.frequency.exponentialRampToValueAtTime(100, now+0.3); gain.gain.setValueAtTime(0.5, now); gain.gain.exponentialRampToValueAtTime(0.01, now+0.4); osc.start(now); osc.stop(now+0.4); }
        }

        function cargarPregunta() {
            if(currentQ >= preguntas.length) { finQuiz(); return; }
            const p = preguntas[currentQ];
            document.getElementById('q-actual').textContent = currentQ + 1;
            document.getElementById('q-total').textContent = preguntas.length;
            document.getElementById('q-pregunta').textContent = p.pregunta;
            
            let opciones = [...p.incorrectas, p.correcta];
            opciones.sort(() => Math.random() - 0.5); 
            
            const btns = document.querySelectorAll('.quiz-btn');
            btns.forEach((btn, idx) => {
                btn.querySelector('.op-texto').textContent = opciones[idx];
                btn.onclick = () => evaluar(opciones[idx] === p.correcta, p.correcta, p.feedback);
            });
            
            timerQuiz = 15;
            document.getElementById('timer-bar').style.width = '100%';
            document.getElementById('timer-bar').style.backgroundColor = '#a855f7';
            document.getElementById('feedback-modal').classList.add('hidden');
            document.getElementById('feedback-modal').classList.remove('flex');
            canAnswer = true;
            
            clearInterval(intervalQuiz);
            intervalQuiz = setInterval(() => {
                timerQuiz--;
                const pct = (timerQuiz / 15) * 100;
                const bar = document.getElementById('timer-bar');
                bar.style.width = pct + '%';
                if(timerQuiz <= 5) bar.style.backgroundColor = '#ef4444';
                if(timerQuiz <= 0) { evaluar(false, p.correcta, p.feedback); }
            }, 1000);
        }

        function evaluar(acierto, correcta, feedback) {
            if(!canAnswer) return; canAnswer = false; clearInterval(intervalQuiz);
            if(audioCtxQuiz && audioCtxQuiz.state === 'suspended') audioCtxQuiz.resume();
            
            const modal = document.getElementById('feedback-modal');
            const icon = document.getElementById('feedback-icon');
            const title = document.getElementById('feedback-title');
            
            if(acierto) {
                score += 100 + (timerQuiz * 10); playQSound('ok');
                icon.innerHTML = '<i class="fa-solid fa-check-circle text-green-500"></i>';
                title.textContent = '¡Correcto!'; title.className = 'text-5xl font-black mb-4 uppercase text-green-400 drop-shadow-md';
                setTimeout(siguientePregunta, 1500); 
            } else {
                playQSound('fail');
                icon.innerHTML = '<i class="fa-solid fa-times-circle text-red-500"></i>';
                title.textContent = timerQuiz <= 0 ? '¡Tiempo Agotado!' : '¡Incorrecto!';
                title.className = 'text-5xl font-black mb-4 uppercase text-red-500 drop-shadow-md';
                document.getElementById('feedback-correcta').textContent = correcta;
                document.getElementById('feedback-texto').textContent = feedback || "Sin explicación adicional.";
                modal.classList.remove('hidden'); modal.classList.add('flex'); 
            }
        }

        function siguientePregunta() { currentQ++; cargarPregunta(); }

        function finQuiz() {
            document.getElementById('final-score').textContent = score;
            let top = ''; if(rankingQuizData.length > 0) rankingQuizData.slice(0,3).forEach((r, i) => top += `<li class="border-b border-slate-100 pb-2"><span class="text-purple-500 font-bold">#${i+1}</span> ${r.jugador} <span class="float-right text-slate-800 font-bold">${r.puntuacion} pts</span></li>`); else top = '<li>No hay marcas.</li>';
            document.getElementById('top3-quiz-list').innerHTML = top;
            document.getElementById('end-modal-quiz').classList.remove('hidden'); document.getElementById('end-modal-quiz').classList.add('flex');
            document.getElementById('quiz-player-name').focus();
        }

        function guardarPuntuacionQuiz() {
            let n = document.getElementById('quiz-player-name').value.trim() || "Anónimo";
            const fd = new URLSearchParams(); fd.append('action', 'guardar_puntuacion_quiz'); fd.append('quiz_id', <?= $quiz_id ?>); fd.append('jugador', n); fd.append('puntuacion', score);
            
            document.getElementById('btn-guardar-quiz').disabled = true;
            document.getElementById('btn-guardar-quiz').textContent = 'Guardando...';

            fetch(window.location.pathname, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: fd.toString() })
            .then(r => r.json())
            .then(res => {
                if(res.status === 'ok') {
                    let top = ''; let esTop1 = false;
                    res.ranking.forEach((r, i) => {
                        top += `<li class="border-b border-slate-100 pb-2"><span class="text-purple-500 font-bold">#${i+1}</span> ${r.jugador} <span class="float-right text-slate-800 font-bold">${r.puntuacion} pts</span></li>`;
                        if (i === 0 && r.jugador === n && parseInt(r.puntuacion) === score) esTop1 = true;
                    });
                    document.getElementById('top3-quiz-list').innerHTML = top;
                    
                    if(esTop1) dispararConfeti();

                    document.getElementById('input-guardar-quiz-container').style.display = 'none';
                    document.getElementById('btn-reintentar-quiz').style.display = 'block';
                    
                    const top3Wrapper = document.getElementById('top3-quiz-wrapper');
                    top3Wrapper.classList.add('bg-yellow-100', 'border-yellow-300', 'scale-105', 'shadow-lg');
                    setTimeout(() => { top3Wrapper.classList.remove('bg-yellow-100', 'border-yellow-300', 'scale-105', 'shadow-lg'); }, 4000);
                }
            });
        }
        
        function dispararConfeti() {
            const colores = ['#fce18a', '#ff7171', '#9bdc28', '#38bdf8', '#2dd4bf', '#c084fc'];
            for(let i=0; i<70; i++) {
                let confeti = document.createElement('div');
                confeti.style.position = 'fixed'; confeti.style.left = Math.random() * 100 + 'vw'; confeti.style.top = '-20px';
                confeti.style.width = Math.random() * 10 + 5 + 'px'; confeti.style.height = Math.random() * 10 + 5 + 'px';
                confeti.style.backgroundColor = colores[Math.floor(Math.random()*colores.length)]; confeti.style.zIndex = '99999';
                confeti.style.borderRadius = Math.random() > 0.5 ? '50%' : '0';
                document.body.appendChild(confeti);
                
                let duracion = Math.random() * 2 + 2;
                confeti.animate([
                    { transform: `translate3d(0,0,0) rotate(0deg)`, opacity: 1 },
                    { transform: `translate3d(${Math.random()*300 - 150}px, 100vh, 0) rotate(${Math.random()*720}deg)`, opacity: 0 }
                ], { duration: duracion * 1000, easing: 'cubic-bezier(.37,0,.63,1)' });
                setTimeout(() => confeti.remove(), duracion * 1000);
            }
        }

        document.addEventListener('DOMContentLoaded', () => { if(preguntas.length > 0) cargarPregunta(); });
    </script>
<?php endif; ?>
</body>
</html>