<?php
// Desactivar salida de errores para no romper el JSON del backend
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

} catch (Exception $e) { 
    die("Error crítico de Base de Datos: " . $e->getMessage()); 
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    if ($_POST['action'] === 'generar_rosco') {
        $tema = trim($_POST['tema'] ?? 'Cultura General');

        $prompt = "Actúa como un creador de concursos estilo Pasapalabra. Genera exactamente 27 palabras (una para cada letra del alfabeto español, de la A a la Z, incluyendo la Ñ) estrictamente relacionadas con el tema: '$tema'. 
        Para cada letra, proporciona la palabra correcta y una definición clara. 
        Devuelve SOLO un JSON con esta estructura exacta y nada más:
        [
            {\"letra\": \"A\", \"palabra\": \"ASTRONAUTA\", \"definicion\": \"Persona que viaja por el espacio exterior.\"},
            {\"letra\": \"B\", \"palabra\": \"BOSQUE\", \"definicion\": \"Sitio poblado de árboles y matas.\"}
        ]
        Asegúrate de incluir la letra Ñ. No incluyas markdown (```json), solo el texto puro.";

        $payload = [
            "contents" => [["parts" => [["text" => $prompt]]]],
            "generationConfig" => ["temperature" => 0.8, "responseMimeType" => "application/json"]
        ];

        // MEJORA 1: Sistema de reintentos
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

            if ($err) {
                $error_msg = "Error de conexión cURL.";
                continue; // Falla, intenta de nuevo
            }

            $responseData = json_decode($response, true);
            $textoFinal = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if ($textoFinal) {
                $jsonLimpio = trim(str_replace(['```json', '```'], '', $textoFinal));
                
                if (json_decode($jsonLimpio)) {
                    $stmt = $pdo->prepare("INSERT INTO roscos (tema, json_datos) VALUES (?, ?)");
                    $stmt->execute([$tema, $jsonLimpio]);
                    $nuevo_id = $pdo->lastInsertId();
                    $exito = true;
                    break; // Éxito, salir del bucle
                } else {
                    $error_msg = "La IA no devolvió un JSON válido.";
                }
            } else {
                $error_msg = "Respuesta vacía de Gemini.";
            }
        }

        if ($exito) {
            echo json_encode(["status" => "ok", "id" => $nuevo_id]);
        } else {
            echo json_encode(["error" => $error_msg . " (Tras $max_intentos intentos)"]);
        }
        exit;
    }

    if ($_POST['action'] === 'guardar_puntuacion') {
        $rosco_id = $_POST['rosco_id'];
        $jugador = $_POST['jugador'];
        $aciertos = $_POST['aciertos'];
        $fallos = $_POST['fallos'];
        $tiempo = $_POST['tiempo'];

        $stmt = $pdo->prepare("INSERT INTO rosco_puntuaciones (rosco_id, jugador, aciertos, fallos, tiempo_restante) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$rosco_id, $jugador, $aciertos, $fallos, $tiempo]);
        
        echo json_encode(["status" => "ok"]);
        exit;
    }
}





$rosco_id_jugando = $_GET['id'] ?? null;
$ranking_json = "[]";

if ($rosco_id_jugando) {
    $stmt = $pdo->prepare("SELECT * FROM roscos WHERE id = ?");
    $stmt->execute([$rosco_id_jugando]);
    $rosco_actual = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$rosco_actual) die("<div style='padding:50px;text-align:center;font-family:sans-serif;'><h2>El rosco no existe.</h2><a href='rosco.php'>Volver al inicio</a></div>");
    
    $stmtRank = $pdo->prepare("SELECT * FROM rosco_puntuaciones WHERE rosco_id = ? ORDER BY aciertos DESC, tiempo_restante DESC LIMIT 5");
    $stmtRank->execute([$rosco_id_jugando]);
    $ranking = $stmtRank->fetchAll(PDO::FETCH_ASSOC);
    $ranking_json = json_encode($ranking);
} else {
    $ultimos_roscos = $pdo->query("SELECT * FROM roscos ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pasapalabra | El Rosco de Palabras</title>
    
    <script src="<?= $url_tailwind ?>"></script>
    <link rel="stylesheet" href="<?= $url_fa ?>">
    
    <style>
        @import url('<?= $url_fonts ?>');
        body { font-family: 'Montserrat', sans-serif; background: radial-gradient(circle at center, #1e3a8a 0%, #0f172a 100%); color: white; min-height: 100vh; overflow-x: hidden; }
        
        #circle-container { position: relative; width: 100%; max-width: 500px; aspect-ratio: 1/1; margin: 0 auto; }
        .letter-node { position: absolute; width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 18px; border: 3px solid white; box-shadow: 0 4px 6px rgba(0,0,0,0.5); transition: all 0.3s; transform: translate(-50%, -50%); color: white; }
        
        .letra-base { background: linear-gradient(135deg, #3b82f6, #1d4ed8); } 
        .letra-acierto { background: linear-gradient(135deg, #22c55e, #15803d); } 
        .letra-fallo { background: linear-gradient(135deg, #ef4444, #b91c1c); } 
        
        @keyframes heartbeat { 0%, 100% { transform: translate(-50%, -50%) scale(1.3); } 50% { transform: translate(-50%, -50%) scale(1.6); } }
        .letra-actual { background: linear-gradient(135deg, #fbbf24, #d97706); z-index: 10; border-color: #fff; box-shadow: 0 0 20px #f59e0b; color: black; animation: heartbeat 0.8s infinite; } 
        
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-3px); } 75% { transform: translateX(3px); } }
        .timer-danger { animation: shake 0.5s infinite; color: #ef4444 !important; border-color: #ef4444 !important; box-shadow: 0 0 15px rgba(239, 68, 68, 0.5) !important; }

        .glass-panel { background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); }
        
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: rgba(0,0,0,0.2); border-radius: 10px;}
        ::-webkit-scrollbar-thumb { background: rgba(6, 182, 212, 0.5); border-radius: 10px;}
    </style>
</head>
<body class="flex flex-col">

<?php if (!$rosco_id_jugando): ?>
    <div class="container mx-auto p-8 max-w-5xl flex-1 flex flex-col">
        <header class="text-center mb-12 mt-8">
            <h1 class="text-5xl font-black text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-cyan-300 mb-2 uppercase tracking-tighter shadow-sm">EL ROSCO <i class="fa-solid fa-circle-notch"></i></h1>
            <p class="text-blue-200 font-medium">Adivina la palabra. Demuestra lo que sabes.</p>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="col-span-1 glass-panel rounded-2xl p-6 h-fit shadow-2xl">
                <h2 class="text-xl font-bold mb-4 text-cyan-400"><i class="fa-solid fa-wand-magic-sparkles mr-2"></i>Crear Nuevo Rosco</h2>
                <p class="text-sm text-gray-300 mb-6">Dinos un tema y la IA generará 27 definiciones automáticamente.</p>
                
                <input type="text" id="tema-input" placeholder="Ej: Países, Harry Potter..." class="w-full bg-blue-900/50 border border-blue-500 rounded-lg p-3 text-white placeholder-blue-300 focus:outline-none focus:ring-2 focus:ring-cyan-400 mb-4 font-bold transition-all">
                
                <button id="btn-generar" onclick="generarRosco()" class="w-full bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white font-bold py-3 rounded-lg shadow-lg transition-transform transform hover:scale-105 uppercase tracking-widest disabled:opacity-50 disabled:cursor-not-allowed">
                    Generar Rosco
                </button>
            </div>

            <div class="col-span-1 md:col-span-2">
                <h2 class="text-xl font-bold mb-4 text-white"><i class="fa-solid fa-clock-rotate-left mr-2"></i>Últimos Roscos Creados</h2>
                <?php if (empty($ultimos_roscos)): ?>
                    <div class="glass-panel rounded-xl p-8 text-center text-gray-400 border-dashed">Aún no hay roscos. ¡Sé el primero en crear uno!</div>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <?php foreach($ultimos_roscos as $r): ?>
                            <div class="glass-panel rounded-xl p-5 hover:bg-white/10 transition group border-l-4 border-cyan-500">
                                <h3 class="font-bold text-lg text-white mb-1 uppercase truncate" title="<?= htmlspecialchars($r['tema']) ?>"><?= htmlspecialchars($r['tema']) ?></h3>
                                <p class="text-xs text-blue-300 mb-4"><i class="fa-regular fa-calendar mr-1"></i> <?= date('d/m/Y', strtotime($r['fecha'])) ?></p>
                                <div class="flex space-x-2">
                                    <a href="?id=<?= $r['id'] ?>" class="flex-1 text-center bg-cyan-600 hover:bg-cyan-500 text-white py-2 rounded font-bold text-sm transition shadow">Jugar <i class="fa-solid fa-play ml-1"></i></a>
                                    <button onclick="copiarLink('<?= $r['id'] ?>')" class="bg-blue-800 hover:bg-blue-700 text-white px-3 py-2 rounded transition shadow"><i class="fa-solid fa-link"></i></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        function generarRosco() {
            const temaInput = document.getElementById('tema-input');
            const tema = temaInput.value.trim();
            const btn = document.getElementById('btn-generar');
            if(!tema) { alert("¡Escribe un tema primero!"); temaInput.focus(); return; }
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Procesando...';
            const formData = new URLSearchParams(); formData.append('action', 'generar_rosco'); formData.append('tema', tema);
            fetch(window.location.pathname, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData.toString() })
            .then(r => r.json()).then(res => {
                if(res.status === 'ok') window.location.href = '?id=' + res.id;
                else { alert("Aviso: " + res.error); btn.disabled = false; btn.innerHTML = 'Generar Rosco'; }
            }).catch(e => { alert("Error crítico de conexión."); btn.disabled = false; btn.innerHTML = 'Generar Rosco'; });
        }
        function copiarLink(id) {
            navigator.clipboard.writeText(window.location.href.split('?')[0] + '?id=' + id).then(() => alert("¡Enlace copiado!"));
        }
    </script>

<?php else: ?>
    <div class="w-full p-4 flex justify-between items-center bg-black/20">
        <a href="?" class="text-blue-300 hover:text-cyan-400 font-bold transition"><i class="fa-solid fa-arrow-left mr-2"></i> Volver a inicio</a>
        <button onclick="copiarLinkJuego()" class="bg-blue-800/80 hover:bg-blue-700 text-white px-4 py-2 rounded-full text-sm font-bold shadow transition">
            <i class="fa-solid fa-share-nodes mr-2"></i> Compartir Rosco
        </button>
    </div>

    <div class="flex-1 flex flex-col md:flex-row max-w-[1400px] mx-auto w-full p-4 md:p-8 gap-12 items-center justify-center mt-2">
        
        <div class="w-full md:w-1/2 flex flex-col items-center">
            <div class="text-center mb-8 w-full flex justify-between px-8 text-3xl font-black max-w-[400px]">
                <div class="text-green-400 drop-shadow-md"><i class="fa-solid fa-check mr-1"></i><span id="ui-aciertos">0</span></div>
                <div class="text-cyan-400 border-2 border-cyan-400 rounded-full px-6 py-1 shadow-[0_0_10px_rgba(6,182,212,0.3)] transition-colors duration-300" id="ui-timer">100</div>
                <div class="text-red-500 drop-shadow-md"><span id="ui-fallos">0</span><i class="fa-solid fa-xmark ml-1"></i></div>
            </div>
            
            <div id="circle-container" class="mb-4">
                </div>
        </div>

        <div class="w-full md:w-1/2 glass-panel rounded-3xl p-8 relative overflow-hidden shadow-2xl max-w-[550px]">
            
            <div id="end-modal" class="hidden absolute inset-0 z-50 flex-col items-center justify-start p-6 text-center bg-slate-900/95 backdrop-blur-md overflow-y-auto">
                <h2 class="text-3xl font-black text-cyan-400 mb-1 uppercase drop-shadow-lg mt-4">¡Tiempo / Fin!</h2>
                <p class="text-lg mb-4 text-white">Aciertos conseguidos: <span id="final-aciertos" class="font-black text-green-400 text-2xl ml-2"></span></p>
                
                <div class="w-full bg-black/40 p-4 rounded-xl border border-white/10 mb-4">
                    <input type="text" id="player-name" placeholder="Tu Nombre o Iniciales" class="w-full bg-slate-800 border-2 border-blue-500 rounded-lg p-2 text-center font-bold text-white mb-3 uppercase focus:outline-none focus:border-cyan-400" maxlength="15">
                    <button onclick="guardarPuntuacion()" class="w-full bg-gradient-to-r from-cyan-400 to-blue-500 hover:from-cyan-300 hover:to-blue-400 text-slate-900 font-black py-2 rounded-lg transition transform shadow-[0_0_15px_rgba(6,182,212,0.5)]">
                        Guardar mi Marca
                    </button>
                </div>

                <div class="w-full bg-yellow-500/10 border border-yellow-500/30 p-3 rounded-xl mb-4">
                    <h3 class="text-yellow-400 font-black text-sm mb-2 uppercase"><i class="fa-solid fa-crown mr-1"></i> Top Histórico de este Rosco</h3>
                    <ul id="top3-list" class="text-left text-xs text-gray-200 space-y-1">
                        </ul>
                </div>

                <div class="w-full flex-1 min-h-[150px]">
                    <h3 class="text-cyan-400 font-black text-sm mb-2 uppercase text-left border-b border-white/20 pb-1">Palabras Falladas / Sin Responder</h3>
                    <div id="soluciones-lista" class="text-left text-xs text-gray-300 overflow-y-auto max-h-[150px] pr-2 space-y-2">
                        </div>
                </div>
            </div>

            <div id="game-ui">
                <div class="text-xs font-bold text-blue-300 uppercase tracking-widest mb-1 drop-shadow">Tema del Rosco</div>
                <h2 class="text-2xl font-black text-white mb-6 uppercase border-b border-white/20 pb-2 drop-shadow-md truncate" title="<?= htmlspecialchars($rosco_actual['tema']) ?>"><?= htmlspecialchars($rosco_actual['tema']) ?></h2>

                <div class="min-h-[160px] mb-6 flex flex-col justify-center bg-black/20 p-6 rounded-2xl border border-white/5 shadow-inner">
                    <div class="text-[5rem] leading-none font-black text-cyan-400 mb-3 drop-shadow-[0_0_8px_rgba(6,182,212,0.5)]" id="ui-letra">A</div>
                    <p class="text-lg text-gray-200 leading-relaxed font-medium" id="ui-definicion">Cargando definición...</p>
                </div>

                <div class="flex flex-col space-y-4">
                    <input type="text" id="answer-input" placeholder="Escribe aquí la palabra..." class="w-full bg-slate-800/80 border-2 border-blue-400 rounded-xl p-4 text-center text-xl font-bold text-white focus:outline-none focus:border-cyan-300 uppercase shadow-inner transition-colors" autocomplete="off">
                    
                    <div class="flex space-x-3">
                        <button onclick="comprobar('pasapalabra')" class="flex-1 bg-gradient-to-r from-indigo-600 to-blue-800 hover:from-indigo-500 hover:to-blue-700 text-white font-bold py-4 rounded-xl shadow-lg transition transform hover:-translate-y-1">
                            Pasapalabra
                        </button>
                        <button onclick="comprobar('enviar')" class="flex-1 bg-gradient-to-r from-cyan-500 to-blue-500 hover:from-cyan-400 hover:to-blue-400 text-slate-900 font-black py-4 rounded-xl shadow-lg transition transform hover:-translate-y-1">
                            Enviar <i class="fa-solid fa-paper-plane ml-1"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if(!empty($ranking)): ?>
    <div class="max-w-6xl mx-auto w-full px-8 pb-12 mt-8">
        <h3 class="text-lg font-black text-blue-300 uppercase tracking-widest mb-4 drop-shadow"><i class="fa-solid fa-trophy text-yellow-400 mr-2"></i> Ranking Global</h3>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <?php foreach($ranking as $idx => $rank): 
                $bgClass = $idx === 0 ? 'bg-yellow-500/20 border-yellow-500 shadow-[0_0_15px_rgba(234,179,8,0.2)]' : 'bg-white/5 border-white/10';
            ?>
            <div class="<?= $bgClass ?> border rounded-xl p-4 text-center transition hover:bg-white/10">
                <p class="font-black text-white truncate text-lg"><?= htmlspecialchars($rank['jugador']) ?></p>
                <div class="text-md font-bold text-green-400 drop-shadow-sm mt-1"><?= $rank['aciertos'] ?> Aciertos</div>
                <div class="text-xs text-gray-400 mt-1 font-medium"><?= $rank['tiempo_restante'] ?>s sobrantes</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <script>
        const rawJson = `<?= $rosco_actual['json_datos'] ?>`;
        const rankingData = <?= $ranking_json ?>; // Pasamos el ranking de PHP a JS para el Top 3
        let palabras = [];
        try { palabras = JSON.parse(rawJson); } catch(e) { alert("Error crítico en el JSON."); }

        let estadoPalabras = new Array(palabras.length).fill(0); 
        let currentIndex = -1;
        let aciertos = 0, fallos = 0, timer = 100, interval = null, playing = false;

        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        function playSound(type) {
            if(!audioCtx) return;
            if(audioCtx.state === 'suspended') audioCtx.resume();
            
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            const now = audioCtx.currentTime;

            if (type === 'acierto') {
                osc.type = 'sine';
                osc.frequency.setValueAtTime(400, now);
                osc.frequency.exponentialRampToValueAtTime(800, now + 0.1);
                gain.gain.setValueAtTime(0.5, now);
                gain.gain.exponentialRampToValueAtTime(0.01, now + 0.3);
                osc.start(now); osc.stop(now + 0.3);
            } else if (type === 'fallo') {
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(150, now);
                osc.frequency.exponentialRampToValueAtTime(100, now + 0.2);
                gain.gain.setValueAtTime(0.5, now);
                gain.gain.exponentialRampToValueAtTime(0.01, now + 0.3);
                osc.start(now); osc.stop(now + 0.3);
            } else if (type === 'tick') {
                osc.type = 'square';
                osc.frequency.setValueAtTime(800, now);
                gain.gain.setValueAtTime(0.02, now); // Muy sutil
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.05);
                osc.start(now); osc.stop(now + 0.05);
            }
        }

        function normalizarTexto(t) { return t.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim(); }

        function dibujarRosco() {
            const container = document.getElementById('circle-container');
            container.innerHTML = '';
            const total = palabras.length;
            
            for(let i = 0; i < total; i++) {
                // Posicionamiento mediante porcentajes (Responsividad total)
                const angle = (i / total) * 2 * Math.PI - (Math.PI / 2);
                const radiusPercent = 43; // 43% desde el centro, deja margen en los bordes
                const x = 50 + Math.cos(angle) * radiusPercent; 
                const y = 50 + Math.sin(angle) * radiusPercent;

                const node = document.createElement('div');
                node.id = 'nodo-' + i;
                node.className = 'letter-node letra-base';
                node.textContent = palabras[i].letra;
                node.style.left = x + '%';
                node.style.top = y + '%';
                container.appendChild(node);
            }
        }

        function actualizarUI_Rosco() {
            for(let i = 0; i < palabras.length; i++) {
                let nodo = document.getElementById('nodo-' + i);
                if (!nodo) continue;
                nodo.className = 'letter-node'; 
                
                if (i === currentIndex) nodo.classList.add('letra-actual');
                else {
                    if (estadoPalabras[i] === 0) nodo.classList.add('letra-base');
                    if (estadoPalabras[i] === 1) nodo.classList.add('letra-acierto');
                    if (estadoPalabras[i] === 2) nodo.classList.add('letra-fallo');
                }
            }
            document.getElementById('ui-aciertos').textContent = aciertos;
            document.getElementById('ui-fallos').textContent = fallos;
        }

        function buscarSiguientePendiente() {
            if (!estadoPalabras.includes(0)) return -1;
            let attempts = 0, nextIndex = currentIndex;
            while(attempts < palabras.length) {
                nextIndex++;
                if (nextIndex >= palabras.length) nextIndex = 0;
                if (estadoPalabras[nextIndex] === 0) return nextIndex;
                attempts++;
            }
            return -1;
        }

        function cargarLetra(index) {
            if (index === -1) { finalizarJuego(); return; }
            currentIndex = index;
            const p = palabras[currentIndex];
            document.getElementById('ui-letra').textContent = p.letra;
            document.getElementById('ui-definicion').textContent = p.definicion;
            
            const input = document.getElementById('answer-input');
            input.value = ''; input.focus();
            actualizarUI_Rosco();
        }

        function comprobar(accion) {
            if(!playing) return;
            // Al primer clic en el juego nos aseguramos de que el contexto de audio despierta
            if(audioCtx && audioCtx.state === 'suspended') audioCtx.resume(); 

            if(accion === 'pasapalabra') {
                cargarLetra(buscarSiguientePendiente());
                return;
            }

            const inputVal = document.getElementById('answer-input').value;
            if(!inputVal) return;

            const correcta = normalizarTexto(palabras[currentIndex].palabra);
            const user = normalizarTexto(inputVal);

            if(user === correcta) {
                estadoPalabras[currentIndex] = 1; aciertos++;
                playSound('acierto');
            } else {
                estadoPalabras[currentIndex] = 2; fallos++;
                playSound('fallo');
            }
            cargarLetra(buscarSiguientePendiente());
        }

        function finalizarJuego() {
            playing = false;
            clearInterval(interval);
            document.getElementById('answer-input').disabled = true;
            document.getElementById('ui-timer').classList.remove('timer-danger'); // Detener temblor si lo había
            actualizarUI_Rosco();

            let htmlFallos = '';
            for(let i=0; i<palabras.length; i++) {
                if(estadoPalabras[i] !== 1) { // 0 o 2
                    htmlFallos += `<div class="border-b border-white/10 pb-2"><span class="font-bold text-cyan-400 text-sm">${palabras[i].letra} - ${palabras[i].palabra.toUpperCase()}</span><br><span class="text-gray-400 italic">${palabras[i].definicion}</span></div>`;
                }
            }
            document.getElementById('soluciones-lista').innerHTML = htmlFallos || '<p class="text-green-400 font-bold">¡Pleno! No has fallado ninguna.</p>';

            // Preparar Top 3
            let htmlTop3 = '';
            if(rankingData.length > 0) {
                rankingData.slice(0,3).forEach((r, idx) => {
                    htmlTop3 += `<li><span class="font-bold text-yellow-400">#${idx+1}</span> ${r.jugador} - ${r.aciertos} Aciertos (${r.tiempo_restante}s)</li>`;
                });
            } else { htmlTop3 = '<li>No hay marcas aún. ¡Sé el primero!</li>'; }
            document.getElementById('top3-list').innerHTML = htmlTop3;

            document.getElementById('final-aciertos').textContent = aciertos;
            document.getElementById('end-modal').classList.remove('hidden');
            document.getElementById('end-modal').classList.add('flex');
            document.getElementById('player-name').focus();
        }

        function guardarPuntuacion() {
            let nombre = document.getElementById('player-name').value.trim() || "Anónimo";
            const formData = new URLSearchParams();
            formData.append('action', 'guardar_puntuacion'); formData.append('rosco_id', <?= $rosco_id_jugando ?>);
            formData.append('jugador', nombre); formData.append('aciertos', aciertos); formData.append('fallos', fallos); formData.append('tiempo', timer);

            fetch(window.location.pathname, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: formData.toString() })
            .then(() => window.location.reload());
        }

        function copiarLinkJuego() {
            navigator.clipboard.writeText(window.location.href).then(() => alert("¡Enlace del rosco copiado al portapapeles!"));
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (palabras.length > 0) {
                dibujarRosco();
                playing = true;
                cargarLetra(0);
                
                document.getElementById('answer-input').addEventListener('keypress', function(e) {
                    if(e.key === 'Enter') {
                        e.preventDefault();
                        if(this.value.trim() === '') comprobar('pasapalabra'); else comprobar('enviar');
                    }
                });

                interval = setInterval(function() {
                    if(!playing) return;
                    timer--;
                    
                    const uiTimer = document.getElementById('ui-timer');
                    uiTimer.textContent = timer;
                    
                    // Efectos de tensión
                    if(timer <= 30 && timer > 0) {
                        playSound('tick');
                        if (!uiTimer.classList.contains('timer-danger')) uiTimer.classList.add('timer-danger');
                    }
                    
                    if(timer <= 0) finalizarJuego();
                }, 1000);
            }
        });
    </script>
<?php endif; ?>

</body>
</html>