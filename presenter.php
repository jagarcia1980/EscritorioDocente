<?php
require_once __DIR__ . '/config.php';
$apiKey = $CloudConvert_apiKey;

$uploadDir = 'Presentaciones/';
if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); }

$secretSalt = "MiClaveSecreta_Presentador_2026_"; 

// ==========================================
// MOTOR AJAX
// ==========================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] == 'list_classes') {
        $files = glob($uploadDir . '*.json');
        $classes = [];
        foreach($files as $file) {
            $id = basename($file, '.json');
            $data = json_decode(file_get_contents($file), true);
            $nombre = isset($data['settings']['originalName']) ? $data['settings']['originalName'] : 'Presentación';
            $classes[] = ['id' => $id, 'name' => $nombre, 'date' => date('d/m/Y H:i', filemtime($file)), 'timestamp' => filemtime($file)];
        }
        usort($classes, function($a, $b) { return $b['timestamp'] - $a['timestamp']; });
        echo json_encode($classes);
        exit;
    }

    $actionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
    $actionV = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['v'] ?? '');
    $targetId = '';

    if (!empty($actionId)) { $targetId = $actionId; } 
    elseif (!empty($actionV)) {
        foreach(glob($uploadDir . '*.json') as $file) {
            $id = basename($file, '.json');
            if (md5($secretSalt . $id) === $actionV) { $targetId = $id; break; }
        }
    }

    if(empty($targetId) && $_GET['action'] !== 'upload_presentation') { 
        echo json_encode(['status' => 'error', 'message' => 'Presentación no encontrada']); exit; 
    }

    $pdfFile = $uploadDir . $targetId . ".pdf";
    $jsonFile = $uploadDir . $targetId . ".json";

    if ($_GET['action'] == 'upload_presentation' && isset($_FILES['file'])) {
        $fileName = $_FILES['file']['name'];
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $fileTmpPath = $_FILES['file']['tmp_name'];
        
        $fileHash = md5_file($fileTmpPath);
        $cachedPdf = $uploadDir . "_cache_" . $fileHash . ".pdf";
        
        $pdfDestino = $uploadDir . $targetId . ".pdf";

        if (file_exists($cachedPdf)) {
            copy($cachedPdf, $pdfDestino);
            file_put_contents($uploadDir . $targetId . ".json", json_encode(['notes' => [], 'settings' => ['currentPage' => 1, 'originalName' => $baseName]]), LOCK_EX);
            echo json_encode(['status' => 'success', 'id' => $targetId, 'pdfUrl' => $pdfDestino, 'cached' => true]);
            exit;
        }

        $ch = curl_init('https://api.cloudconvert.com/v2/jobs');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json']);
        
        $jobData = [
            'tasks' => [
                'import-my-file' => ['operation' => 'import/upload'],
                'convert-my-file' => ['operation' => 'convert', 'input' => 'import-my-file', 'output_format' => 'pdf'],
                'export-my-file' => ['operation' => 'export/url', 'input' => 'convert-my-file']
            ]
        ];
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jobData));
        $jobResponse = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if(!isset($jobResponse['data'])) { echo json_encode(['status' => 'error', 'message' => 'Error al conectar con CloudConvert']); exit; }

        $uploadTask = array_filter($jobResponse['data']['tasks'], function($t) { return $t['name'] === 'import-my-file'; });
        $uploadTask = reset($uploadTask);
        $uploadUrl = $uploadTask['result']['form']['url'];
        $uploadParams = $uploadTask['result']['form']['parameters'];
        $uploadParams['file'] = new CURLFile($fileTmpPath, $_FILES['file']['type'], $fileName);
        
        $chUpload = curl_init($uploadUrl);
        curl_setopt($chUpload, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chUpload, CURLOPT_POST, true);
        curl_setopt($chUpload, CURLOPT_POSTFIELDS, $uploadParams);
        curl_exec($chUpload);
        curl_close($chUpload);

        $jobId = $jobResponse['data']['id'];
        $status = 'processing';
        $downloadUrl = '';
        
        while ($status === 'processing' || $status === 'waiting') {
            sleep(2); 
            $chStatus = curl_init('https://api.cloudconvert.com/v2/jobs/' . $jobId);
            curl_setopt($chStatus, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chStatus, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $apiKey]);
            $statusResponse = json_decode(curl_exec($chStatus), true);
            curl_close($chStatus);
            
            $status = $statusResponse['data']['status'];
            if ($status === 'finished') {
                $exportTask = array_filter($statusResponse['data']['tasks'], function($t) { return $t['name'] === 'export-my-file'; });
                $exportTask = reset($exportTask);
                $downloadUrl = $exportTask['result']['files'][0]['url'];
            } elseif ($status === 'error') {
                echo json_encode(['status' => 'error', 'message' => 'Fallo en la conversión']); exit;
            }
        }

        if (!empty($downloadUrl)) {
            $pdfContent = file_get_contents($downloadUrl);
            file_put_contents($pdfDestino, $pdfContent, LOCK_EX);
            copy($pdfDestino, $cachedPdf); 
            file_put_contents($uploadDir . $targetId . ".json", json_encode(['notes' => [], 'settings' => ['currentPage' => 1, 'originalName' => $baseName]]), LOCK_EX);
            echo json_encode(['status' => 'success', 'id' => $targetId, 'pdfUrl' => $pdfDestino]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No se pudo obtener el PDF']);
        }
        exit;
    }

    if ($_GET['action'] == 'save_notes') {
        $data = file_get_contents('php://input');
        file_put_contents($jsonFile, $data, LOCK_EX);
        echo json_encode(['status' => 'success', 'hash' => md5($data)]);
        exit;
    }

    if ($_GET['action'] == 'load_data') {
        $pdfUrl = file_exists($pdfFile) ? $pdfFile : null;
        $payload = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile)) : new stdClass();
        $dataHash = file_exists($jsonFile) ? md5_file($jsonFile) : '';
        echo json_encode(['pdfUrl' => $pdfUrl, 'payload' => $payload, 'hash' => $dataHash]);
        exit;
    }
}

// ==========================================
// ENRUTAMIENTO
// ==========================================
$claseId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
$viewHash = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['v'] ?? '');
$role = 'teacher';
$isProjectorMode = isset($_GET['projector']) && $_GET['projector'] == '1';

if (!empty($viewHash)) {
    $found = false;
    foreach(glob($uploadDir . '*.json') as $file) {
        $id = basename($file, '.json');
        if (md5($secretSalt . $id) === $viewHash) { $claseId = $id; $role = 'student'; $found = true; break; }
    }
    if (!$found) die("<div style='font-family:sans-serif;text-align:center;margin-top:50px;'><h2>Esta presentación no existe o el enlace ha caducado.</h2></div>");
} 
elseif (empty($claseId)) {
    $nuevoId = substr(md5(uniqid(mt_rand(), true)), 0, 8);
    file_put_contents($uploadDir . $nuevoId . ".json", json_encode(['notes' => [], 'settings' => ['currentPage' => 1, 'originalName' => 'Presentación']]), LOCK_EX);
    header("Location: ?id=" . $nuevoId);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Presentador - <?php echo ($role === 'student' && !$isProjectorMode) ? 'Modo Alumno' : (($isProjectorMode) ? 'Proyector' : $claseId); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/localforage/1.10.0/localforage.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
        #pizarra-wrapper { background: #1a202c; height: calc(100vh - 80px); padding: 20px; display: flex; justify-content: center; align-items: center; overflow: hidden; }
        .canvas-container { box-shadow: 0 10px 40px rgba(0,0,0,0.8); }
        .tool-active { background-color: #3b82f6 !important; color: white !important; }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #4B5563; border-radius: 4px; }
    </style>
</head>
<body class="bg-gray-900 text-white font-sans overflow-hidden">

    <div id="modal-clases" class="fixed inset-0 bg-black/70 hidden flex items-center justify-center z-[100] backdrop-blur-sm transition-opacity">
        <div class="bg-gray-800 border border-gray-700 rounded-xl shadow-2xl w-full max-w-md p-6 flex flex-col max-h-[80vh]">
            <div class="flex justify-between items-center mb-4 border-b border-gray-700 pb-3">
                <h2 class="text-xl font-bold text-white"><i class="fas fa-folder-open text-blue-400 mr-2"></i> Mis Presentaciones</h2>
                <button onclick="cerrarModalClases()" class="text-gray-400 hover:text-white transition"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div id="lista-clases" class="flex-1 overflow-y-auto custom-scroll pr-2 flex flex-col gap-2"></div>
        </div>
    </div>

    <nav id="main-nav" class="h-[80px] bg-gray-800 border-b border-gray-700 flex items-center justify-between px-4 shadow-2xl z-50 relative">
        <div class="flex items-center gap-3">
            <?php if($role !== 'student'): ?>
                <button onclick="abrirModalClases()" class="bg-gray-700 hover:bg-gray-600 border border-gray-600 p-2 rounded-lg text-blue-400 transition" title="Ver presentaciones anteriores">
                    <i class="fas fa-folder-open text-lg"></i>
                </button>
            
                <div class="flex flex-col bg-gray-900 p-1 px-2 rounded border border-gray-700">
                    <span class="text-[9px] text-gray-500 uppercase font-bold tracking-widest">ID Pres.</span>
                    <input type="text" value="<?php echo $claseId; ?>" readonly class="bg-transparent text-blue-400 focus:outline-none font-bold text-sm w-24">
                </div>
                
                <input type="file" id="ppt-upload" accept=".ppt,.pptx,.key,.odp" class="hidden">
                <button onclick="document.getElementById('ppt-upload').click()" id="btn-upload" class="bg-blue-600 hover:bg-blue-700 p-2 px-4 rounded-lg text-sm transition flex items-center font-bold shadow">
                    <i class="fas fa-file-upload mr-2"></i> Subir Presentación
                </button>

                <button onclick="abrirProyector()" class="bg-purple-600 hover:bg-purple-700 p-2 px-4 rounded-lg text-sm transition flex items-center font-bold shadow ml-2">
                    <i class="fas fa-desktop mr-2"></i> Proyectar
                </button>
            <?php else: ?>
                <div id="student-status" class="bg-gray-900 px-4 py-2 rounded-lg border border-gray-700 flex items-center gap-2 cursor-pointer" onclick="toggleSync()">
                    <span class="relative flex h-3 w-3" id="sync-ping"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span><span class="relative inline-flex rounded-full h-3 w-3 bg-blue-500"></span></span>
                    <span id="sync-text" class="text-xs text-blue-400 font-bold uppercase tracking-widest">Sincronizado</span>
                </div>
                <button onclick="descargarPDF()" id="btn-download" class="bg-green-600 hover:bg-green-700 p-2 px-3 rounded-lg text-xs transition font-bold shadow flex items-center ml-2">
                    <i class="fas fa-file-pdf mr-2"></i> EXPORTAR
                </button>
            <?php endif; ?>

            <div id="page-controls" class="hidden flex items-center bg-gray-700 rounded-lg ml-2">
                <button onclick="changePage(-1)" class="p-2 hover:bg-gray-600 rounded-l-lg"><i class="fas fa-chevron-left"></i></button>
                <span id="page-info" class="text-xs font-mono w-12 text-center">0/0</span>
                <button onclick="changePage(1)" class="p-2 hover:bg-gray-600 rounded-r-lg"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>

        <div id="toolbar" class="flex items-center gap-1 bg-gray-700 p-1.5 rounded-xl border border-gray-600 shadow-inner" style="display: <?php echo ($isProjectorMode) ? 'none' : 'flex'; ?>;">
            <button onclick="setTool('draw')" class="btn-t p-2 w-10 h-10 rounded-lg hover:bg-gray-600 tool-active" id="t-draw"><i class="fas fa-pencil-alt"></i></button>
            <button onclick="setTool('highlight')" class="btn-t p-2 w-10 h-10 rounded-lg hover:bg-gray-600" id="t-highlight"><i class="fas fa-highlighter"></i></button>
            
            <?php if($role === 'student'): ?>
                <button onclick="addText()" class="p-2 w-10 h-10 rounded-lg hover:bg-gray-600"><i class="fas fa-font"></i></button>
            <?php endif; ?>
            
            <div class="w-[1px] h-8 bg-gray-600 mx-2"></div>
            
            <?php if($role !== 'student'): ?>
                <div class="flex gap-0.5 bg-gray-800 p-1 rounded-lg border border-gray-600 mr-2">
                    <button id="btn-arrow-225" onclick="addVolatileArrow(225)" class="btn-arrow p-1 w-8 h-8 hover:bg-gray-600 rounded text-yellow-400"><i class="fas fa-arrow-right" style="transform: rotate(225deg);"></i></button>
                    <button id="btn-arrow-180" onclick="addVolatileArrow(180)" class="btn-arrow p-1 w-8 h-8 hover:bg-gray-600 rounded text-yellow-400"><i class="fas fa-arrow-right" style="transform: rotate(180deg);"></i></button>
                    <button id="btn-arrow-135" onclick="addVolatileArrow(135)" class="btn-arrow p-1 w-8 h-8 hover:bg-gray-600 rounded text-yellow-400"><i class="fas fa-arrow-right" style="transform: rotate(135deg);"></i></button>
                    <button id="btn-arrow-315" onclick="addVolatileArrow(315)" class="btn-arrow p-1 w-8 h-8 hover:bg-gray-600 rounded text-yellow-400"><i class="fas fa-arrow-right" style="transform: rotate(315deg);"></i></button>
                    <button id="btn-arrow-0"   onclick="addVolatileArrow(0)"   class="btn-arrow p-1 w-8 h-8 hover:bg-gray-600 rounded text-yellow-400"><i class="fas fa-arrow-right"></i></button>
                    <button id="btn-arrow-45"  onclick="addVolatileArrow(45)"  class="btn-arrow p-1 w-8 h-8 hover:bg-gray-600 rounded text-yellow-400"><i class="fas fa-arrow-right" style="transform: rotate(45deg);"></i></button>
                </div>
            <?php endif; ?>

            <div class="flex flex-col gap-1 items-center mr-2">
                <input type="color" id="colorPicker" value="<?php echo ($role === 'student') ? '#3b82f6' : '#EF4444'; ?>" class="w-8 h-6 rounded cursor-pointer border-none p-0">
                <input type="range" id="sizeSlider" min="1" max="20" value="4" class="w-16 h-1 bg-gray-500 rounded outline-none">
            </div>
            
            <?php if($role === 'student'): ?>
                <button onclick="deleteSelected()" class="p-2 w-10 h-10 text-red-400 hover:bg-red-900/40 rounded-lg"><i class="fas fa-trash"></i></button>
            <?php endif; ?>
        </div>

        <div class="flex items-center gap-2">
            <?php if($role !== 'student'): ?>
                <button onclick="copyTeacherLink()" class="bg-blue-600 hover:bg-blue-500 p-2 px-3 rounded-lg text-xs transition font-bold shadow flex items-center">
                    <i class="fas fa-key mr-2"></i> ENLACE PROFE
                </button>
                <button onclick="copyShareLink()" class="bg-gray-600 hover:bg-gray-500 p-2 px-3 rounded-lg text-xs transition font-bold shadow flex items-center">
                    <i class="fas fa-share-nodes mr-2"></i> LINK ALUMNOS
                </button>
            <?php endif; ?>
            <span id="save-status" class="text-xs font-bold w-24 text-right <?php echo ($role === 'student') ? 'text-blue-400' : 'text-green-400'; ?>"></span>
        </div>
    </nav>

    <div id="pizarra-wrapper">
        <canvas id="pizarra"></canvas>
    </div>

<script>
    const pdfjsLib = window['pdfjs-dist/build/pdf'];
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';

    let canvas = new fabric.Canvas('pizarra', { backgroundColor: '#ffffff' });
    let pdfDoc = null, pageNum = 1, annotations = {};
    let currentPdfUrl = null, baseViewport = null; 
    let originalName = 'Presentación';
    
    const userRole = "<?php echo $role; ?>";
    const isStudent = (userRole === 'student');
    const isProjector = <?php echo $isProjectorMode ? 'true' : 'false'; ?>;
    const queryParam = "<?php echo ($role === 'student') ? 'v='.$viewHash : 'id='.$claseId; ?>";
    const studentLinkHash = "<?php echo md5($secretSalt . $claseId); ?>";
    
    let lastServerHash = '', isDrawing = false, currentMode = 'draw';
    let isSynced = isStudent ? true : false;
    const localStoreKey = `presentacion_notas_${studentLinkHash}`;
    let pendingArrowAngle = 0;

    const colorPicker = document.getElementById('colorPicker');
    const sizeSlider = document.getElementById('sizeSlider');
    const saveStatus = document.getElementById('save-status');

    if (isProjector) {
        document.getElementById('main-nav').style.display = 'none';
        document.getElementById('pizarra-wrapper').style.height = '100vh';
        document.getElementById('pizarra-wrapper').style.padding = '0';
        canvas.selection = false; canvas.forEachObject(o => o.selectable = false);
    }

    if (isStudent) localforage.config({ name: 'PresentacionesApp', storeName: 'notas_alumnos' });

    function hexToRgba(hex, alpha) {
        let r = parseInt(hex.slice(1, 3), 16), g = parseInt(hex.slice(3, 5), 16), b = parseInt(hex.slice(5, 7), 16);
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    function updateBrush() {
        if(currentMode === 'draw' || currentMode === 'highlight') {
            canvas.isDrawingMode = true;
            if (currentMode === 'highlight') { 
                canvas.freeDrawingBrush.color = hexToRgba(colorPicker.value, 0.4); 
                canvas.freeDrawingBrush.width = parseInt(sizeSlider.value) * 8; 
            } else { 
                canvas.freeDrawingBrush.color = colorPicker.value; 
                canvas.freeDrawingBrush.width = parseInt(sizeSlider.value); 
            }
        } else canvas.isDrawingMode = false;
    }

    function setTool(mode) {
        currentMode = mode; updateBrush();
        document.querySelectorAll('.btn-t, .btn-arrow').forEach(b => b.classList.remove('tool-active'));
        if(document.getElementById('t-' + mode)) document.getElementById('t-' + mode).classList.add('tool-active');
    }

    colorPicker.addEventListener('input', updateBrush); sizeSlider.addEventListener('input', updateBrush);

    if(isStudent) {
        function addText() { setTool('select'); const tSize = parseInt(sizeSlider.value) * 6; const t = new fabric.IText('Escribe...', { left: 100, top: 100, fontSize: tSize < 12 ? 14 : tSize, fill: colorPicker.value }); canvas.add(t); canvas.setActiveObject(t); autoSaveStudent(); }
        function deleteSelected() { canvas.getActiveObjects().forEach(obj => canvas.remove(obj)); canvas.discardActiveObject().renderAll(); autoSaveStudent(); }
    }

    if (!isStudent && !isProjector) {
        window.addVolatileArrow = function(angle) {
            currentMode = 'arrow';
            pendingArrowAngle = angle;
            canvas.isDrawingMode = false;
            document.querySelectorAll('.btn-t, .btn-arrow').forEach(b => b.classList.remove('tool-active'));
            document.getElementById('btn-arrow-' + angle).classList.add('tool-active');
        };

        canvas.on('path:created', function(e) {
            let path = e.path;
            setTimeout(() => {
                path.animate('opacity', 0, {
                    duration: 1000, onChange: canvas.renderAll.bind(canvas),
                    onComplete: () => { canvas.remove(path); autoSave(); }
                });
            }, 3000); 
        });
    }

    canvas.on('mouse:down', (options) => { 
        if (isStudent && isSynced && !isProjector) toggleSync(); 
        isDrawing = true; 
        
        if (currentMode === 'arrow' && !isStudent && !isProjector) {
            const pointer = canvas.getPointer(options.e);
            const color = colorPicker.value;
            const w = parseInt(sizeSlider.value) + 2; 

            const arrowPath = new fabric.Path('M -40 0 L 40 0 M 40 0 L 20 -15 M 40 0 L 20 15', {
                left: pointer.x, top: pointer.y, 
                originX: 'center', originY: 'center',
                angle: pendingArrowAngle, 
                stroke: color, strokeWidth: w,
                strokeLineCap: 'round', strokeLineJoin: 'round', fill: '',
                selectable: false, evented: false
            });

            canvas.add(arrowPath);
            autoSave(); 

            setTimeout(() => {
                arrowPath.animate('opacity', 0, {
                    duration: 1000, onChange: canvas.renderAll.bind(canvas),
                    onComplete: () => { canvas.remove(arrowPath); autoSave(); }
                });
            }, 3000);
        }
    });

    canvas.on('mouse:up', () => isDrawing = false);
    canvas.on('object:added', () => { if(isStudent) autoSaveStudent(); else autoSave(); });
    canvas.on('object:modified', () => { if(isStudent) autoSaveStudent(); else autoSave(); });
    canvas.on('object:removed', () => { if(isStudent) autoSaveStudent(); else autoSave(); });

    async function autoSaveStudent() {
        if (!isStudent || isSynced || isProjector) return;
        let misNotas = await localforage.getItem(localStoreKey) || {};
        misNotas[pageNum] = canvas.toJSON();
        await localforage.setItem(localStoreKey, misNotas);
        saveStatus.innerText = "Guardado local"; setTimeout(() => { saveStatus.innerText = ""; }, 1500);
    }

    let saveTimeout;
    function autoSave() {
        if(isStudent || isProjector) return;
        clearTimeout(saveTimeout); saveStatus.innerText = "Guardando...";
        saveTimeout = setTimeout(async () => {
            annotations[pageNum] = canvas.toJSON();
            const payload = { notes: annotations, settings: { currentPage: pageNum, originalName: originalName } };
            try {
                const r = await fetch(`?action=save_notes&${queryParam}`, { method: 'POST', body: JSON.stringify(payload) });
                const res = await r.json();
                if(res.status === 'success') { lastServerHash = res.hash; saveStatus.innerText = "Hecho"; setTimeout(() => { saveStatus.innerText = ""; }, 2000); }
            } catch (e) {}
        }, 800);
    }

    async function toggleSync() {
        if (!isStudent || isProjector) return;
        isSynced = !isSynced;
        const statusEl = document.getElementById('student-status'), syncText = document.getElementById('sync-text'), ping = document.getElementById('sync-ping');
        if (isSynced) {
            statusEl.classList.replace('bg-gray-700', 'bg-gray-900'); syncText.innerText = "Sincronizado"; syncText.classList.replace('text-gray-300', 'text-blue-400');
            ping.style.display = 'flex'; checkUpdatesSilent(); 
        } else {
            statusEl.classList.replace('bg-gray-900', 'bg-gray-700'); syncText.innerText = "Modo Libre"; syncText.classList.replace('text-blue-400', 'text-gray-300');
            ping.style.display = 'none'; renderPage(pageNum);
        }
    }

    async function checkUpdatesSilent() {
        if(isDrawing || (isStudent && !isSynced && !isProjector)) return; 
        try {
            const r = await fetch(`?action=load_data&${queryParam}&t=${new Date().getTime()}`);
            const data = await r.json();
            
            if (data.pdfUrl && data.pdfUrl !== currentPdfUrl) {
                currentPdfUrl = data.pdfUrl;
                pdfDoc = await pdfjsLib.getDocument(currentPdfUrl + "?t=" + new Date().getTime()).promise;
                document.getElementById('page-controls').classList.remove('hidden'); renderPage(pageNum);
            } else if (data.hash && data.hash !== lastServerHash) {
                lastServerHash = data.hash;
                const payloadData = data.payload || {}; annotations = payloadData.notes || {};
                const dbPage = payloadData.settings ? payloadData.settings.currentPage : null;
                if (payloadData.settings && payloadData.settings.originalName) originalName = payloadData.settings.originalName;
                
                if ((isStudent && isSynced) || isProjector) { 
                    if (dbPage && dbPage !== pageNum) { 
                        pageNum = dbPage; renderPage(pageNum); 
                    } else {
                        if (annotations[pageNum]) {
                            canvas.loadFromJSON(annotations[pageNum], () => canvas.renderAll());
                        } else {
                            canvas.getObjects().forEach(o => canvas.remove(o)); canvas.renderAll();
                        }
                    }
                }
            }
        } catch (e) {}
    }
    setInterval(checkUpdatesSilent, 2000);

    async function renderPage(num) {
        if (!pdfDoc) {
            const wrapper = document.getElementById('pizarra-wrapper');
            canvas.setDimensions({ width: wrapper.clientWidth * 0.75, height: wrapper.clientHeight * 0.75 });
            canvas.setZoom(1);
            if (annotations[num] && (!isStudent || isSynced)) canvas.loadFromJSON(annotations[num], () => canvas.setBackgroundColor('#ffffff', canvas.renderAll.bind(canvas)));
            else if (isStudent && !isSynced) cargarNotasLocales(num);
            else { canvas.clear(); canvas.setBackgroundColor('#ffffff', canvas.renderAll.bind(canvas)); }
            return;
        }
        
        const page = await pdfDoc.getPage(num); 
        baseViewport = page.getViewport({ scale: 1.5 }); 
        
        const tempCanvas = document.createElement('canvas'); 
        tempCanvas.height = baseViewport.height; 
        tempCanvas.width = baseViewport.width;
        await page.render({ canvasContext: tempCanvas.getContext('2d'), viewport: baseViewport }).promise;
        
        fabric.Image.fromURL(tempCanvas.toDataURL(), async function(img) {
            
            const applySettings = () => {
                const wrapper = document.getElementById('pizarra-wrapper');
                let zoom = Math.min((wrapper.clientWidth - 40) / baseViewport.width, (wrapper.clientHeight - 40) / baseViewport.height);
                if (isProjector) zoom = Math.min(wrapper.clientWidth / baseViewport.width, wrapper.clientHeight / baseViewport.height);
                
                canvas.setDimensions({ width: baseViewport.width * zoom, height: baseViewport.height * zoom });
                canvas.setZoom(zoom);
                canvas.setBackgroundImage(img, canvas.renderAll.bind(canvas));
            };

            if ((!isStudent || isSynced) && annotations[num]) {
                canvas.loadFromJSON(annotations[num], applySettings);
            } else if (isStudent && !isSynced) {
                let misNotas = await localforage.getItem(localStoreKey) || {};
                if (misNotas[num]) canvas.loadFromJSON(misNotas[num], applySettings);
                else { canvas.getObjects().forEach(o => canvas.remove(o)); applySettings(); }
            } else {
                canvas.getObjects().forEach(o => canvas.remove(o));
                applySettings();
            }
        });
        document.getElementById('page-info').innerText = `${num}/${pdfDoc.numPages}`;
    }

    async function renderPageBackgroundOnly(num) {
        if (!pdfDoc) { 
            const wrapper = document.getElementById('pizarra-wrapper');
            canvas.setDimensions({ width: wrapper.clientWidth * 0.75, height: wrapper.clientHeight * 0.75 });
            canvas.setZoom(1);
            canvas.setBackgroundColor('#ffffff', canvas.renderAll.bind(canvas)); 
            return; 
        }
        const page = await pdfDoc.getPage(num); 
        const wrapper = document.getElementById('pizarra-wrapper');
        let zoom = Math.min((wrapper.clientWidth - 40) / baseViewport.width, (wrapper.clientHeight - 40) / baseViewport.height);
        if (isProjector) zoom = Math.min(wrapper.clientWidth / baseViewport.width, wrapper.clientHeight / baseViewport.height);
        const viewport = page.getViewport({ scale: zoom });
        const tempCanvas = document.createElement('canvas'); 
        tempCanvas.height = viewport.height; tempCanvas.width = viewport.width;
        await page.render({ canvasContext: tempCanvas.getContext('2d'), viewport: viewport }).promise;
        fabric.Image.fromURL(tempCanvas.toDataURL(), (img) => canvas.setBackgroundImage(img, canvas.renderAll.bind(canvas)));
    }

    window.addEventListener('resize', () => { 
        if (baseViewport && pdfDoc) {
            const wrapper = document.getElementById('pizarra-wrapper');
            let zoom = Math.min((wrapper.clientWidth - 40) / baseViewport.width, (wrapper.clientHeight - 40) / baseViewport.height);
            if (isProjector) zoom = Math.min(wrapper.clientWidth / baseViewport.width, wrapper.clientHeight / baseViewport.height);
            canvas.setDimensions({ width: baseViewport.width * zoom, height: baseViewport.height * zoom });
            canvas.setZoom(zoom);
        } else if (!pdfDoc) {
            const wrapper = document.getElementById('pizarra-wrapper');
            canvas.setDimensions({ width: wrapper.clientWidth * 0.75, height: wrapper.clientHeight * 0.75 });
        }
    });

    function changePage(d) {
        if (!pdfDoc) return;
        let n = pageNum + d;
        if (n > 0 && n <= pdfDoc.numPages) { 
            if(isStudent && isSynced) toggleSync(); 
            pageNum = n; renderPage(n); if (!isStudent && !isProjector) autoSave(); 
        }
    }

    if (!isStudent && !isProjector) {
        document.getElementById('ppt-upload').addEventListener('change', async (e) => {
            const file = e.target.files[0]; if (!file) return;
            const btn = document.getElementById('btn-upload');
            btn.innerHTML = `<i class="fas fa-spinner fa-spin mr-2"></i> Procesando...`; btn.disabled = true;

            const formData = new FormData(); formData.append('file', file);
            try { 
                const r = await fetch(`?action=upload_presentation&${queryParam}`, { method: 'POST', body: formData }); 
                const res = await r.json();
                if(res.status === 'success') {
                    btn.innerHTML = res.cached ? `<i class="fas fa-bolt mr-2"></i> ¡En Caché!` : `<i class="fas fa-check mr-2"></i> ¡Convertido!`;
                    pdfDoc = await pdfjsLib.getDocument(res.pdfUrl + "?t=" + new Date().getTime()).promise;
                    pageNum = 1; document.getElementById('page-controls').classList.remove('hidden'); 
                    originalName = file.name.replace(/\.[^/.]+$/, "");
                    renderPage(pageNum); autoSave();
                    setTimeout(() => { btn.innerHTML = `<i class="fas fa-file-upload mr-2"></i> Subir Presentación`; }, 3000);
                } else { alert("Error: " + res.message); btn.innerHTML = `<i class="fas fa-file-upload mr-2"></i> Subir Presentación`; }
            } catch (e) { alert("Error de red."); }
            btn.disabled = false;
        });
    }

    async function abrirProyector() {
        if (!('getScreenDetails' in window)) { alert('Actualiza tu navegador.'); return; }
        try {
            const screenDetails = await window.getScreenDetails();
            if (screenDetails.screens.length > 1) {
                const projectorScreen = screenDetails.screens.find(s => s !== screenDetails.currentScreen) || screenDetails.screens[1];
                const features = `left=${projectorScreen.left},top=${projectorScreen.top},width=${projectorScreen.width},height=${projectorScreen.height},fullscreen=yes,menubar=no,toolbar=no,location=no,status=no`;
                const url = window.location.origin + window.location.pathname + "?v=" + studentLinkHash + "&projector=1";
                window.open(url, 'ProyectorClase', features);
            } else alert('No se ha detectado monitor.');
        } catch (error) { alert('Permiso denegado.'); }
    }

    function copyShareLink() {
        navigator.clipboard.writeText(window.location.origin + window.location.pathname + "?v=" + studentLinkHash);
        alert("Enlace para ALUMNOS copiado al portapapeles.");
    }

    async function abrirModalClases() {
        try {
            const r = await fetch('?action=list_classes'); 
            const clases = await r.json();
            const lista = document.getElementById('lista-clases');
            if(clases.length === 0) lista.innerHTML = `<div class="text-center text-gray-500 py-6">No hay presentaciones guardadas.</div>`;
            else lista.innerHTML = clases.map(c => `<div onclick="cambiarClase('${c.id}')" class="flex justify-between items-center bg-gray-700/50 hover:bg-gray-600 p-3 rounded-lg cursor-pointer border border-transparent hover:border-blue-500 transition-all group"><span class="font-bold text-gray-200 group-hover:text-blue-400 truncate"><i class="fas fa-file-pdf mr-2 text-gray-500"></i>${c.name} <span class="text-xs text-gray-500 font-normal">(${c.id})</span></span><span class="text-xs text-gray-400 bg-gray-800 px-2 py-1 rounded shrink-0">${c.date}</span></div>`).join('');
            document.getElementById('modal-clases').classList.remove('hidden');
        } catch (e) { alert("Error al cargar el historial."); }
    }

    function cerrarModalClases() { document.getElementById('modal-clases').classList.add('hidden'); }
    function cambiarClase(nuevo) { window.location.href = "?id=" + nuevo.replace(/[^a-zA-Z0-9_-]/g, ''); }

    function copyTeacherLink() {
        navigator.clipboard.writeText(window.location.href);
        alert("Enlace de PROFESOR copiado al portapapeles.\n\nGuárdalo para volver a entrar y editar. ¡No se lo pases a los alumnos!");
    }

    async function descargarPDF() {
        if (!pdfDoc) { alert("No hay presentación cargada."); return; }
        const btn = document.getElementById('btn-download'); const originalHtml = btn.innerHTML;
        btn.innerHTML = `<i class="fas fa-spinner fa-spin mr-2"></i> Generando...`; btn.disabled = true;

        try {
            const { jsPDF } = window.jspdf; const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
            const pageWidth = 210, pageHeight = 297, margin = 10;
            const maxImgWidth = pageWidth - (margin * 2), maxImgHeight = (pageHeight / 2) - (margin * 1.5); 
            
            let misNotas = await localforage.getItem(localStoreKey) || {};
            const tempCanvas = document.createElement('canvas'), tempCtx = tempCanvas.getContext('2d');
            const fabricTemp = new fabric.StaticCanvas(null);

            for (let i = 1; i <= pdfDoc.numPages; i++) {
                const page = await pdfDoc.getPage(i); const viewport = page.getViewport({ scale: 1.5 }); 
                tempCanvas.width = viewport.width; tempCanvas.height = viewport.height;
                fabricTemp.setWidth(viewport.width); fabricTemp.setHeight(viewport.height);
                
                await page.render({ canvasContext: tempCtx, viewport: viewport }).promise;
                
                await new Promise((resolve) => {
                    fabric.Image.fromURL(tempCanvas.toDataURL(), (img) => { fabricTemp.setBackgroundImage(img, fabricTemp.renderAll.bind(fabricTemp)); resolve(); });
                });

                if (misNotas[i]) await new Promise((resolve) => { fabricTemp.loadFromJSON(misNotas[i], () => { fabricTemp.renderAll(); resolve(); }); });
                else { fabricTemp.getObjects().forEach(obj => fabricTemp.remove(obj)); fabricTemp.renderAll(); }

                const imgData = fabricTemp.toDataURL({ format: 'jpeg', quality: 0.8 });
                const imgProps = doc.getImageProperties(imgData); const ratio = imgProps.width / imgProps.height;
                let renderWidth = maxImgWidth, renderHeight = renderWidth / ratio;
                
                if (renderHeight > maxImgHeight) { renderHeight = maxImgHeight; renderWidth = renderHeight * ratio; }
                const x = (pageWidth - renderWidth) / 2, isTopHalf = (i % 2 !== 0), y = isTopHalf ? margin : (pageHeight / 2) + (margin / 2);
                
                doc.addImage(imgData, 'JPEG', x, y, renderWidth, renderHeight);
                doc.setDrawColor(200, 200, 200); doc.rect(x, y, renderWidth, renderHeight);
                if (!isTopHalf && i < pdfDoc.numPages) doc.addPage();
            }
            doc.save(`Apuntes_Presentacion.pdf`);
        } catch (error) { alert("Error al generar el PDF."); } finally { btn.innerHTML = originalHtml; btn.disabled = false; }
    }

    async function loadDataFull() {
        try {
            const r = await fetch(`?action=load_data&${queryParam}&t=${new Date().getTime()}`);
            const data = await r.json();
            lastServerHash = data.hash; const payloadData = data.payload || {}; annotations = payloadData.notes || {};
            const dbPage = payloadData.settings && payloadData.settings.currentPage ? payloadData.settings.currentPage : 1;
            if (payloadData.settings && payloadData.settings.originalName) originalName = payloadData.settings.originalName;
            
            if((isStudent && isSynced) || isProjector) pageNum = dbPage;
            if (data.pdfUrl) {
                currentPdfUrl = data.pdfUrl; pdfDoc = await pdfjsLib.getDocument(data.pdfUrl + "?t=" + new Date().getTime()).promise;
                document.getElementById('page-controls').classList.remove('hidden'); renderPage(pageNum);
            } else if (annotations[pageNum]) canvas.loadFromJSON(annotations[pageNum], () => renderPage(pageNum));
            else renderPage(pageNum);
        } catch (e) {}
    }

    setTool('draw'); loadDataFull();
</script>
</body>
</html>