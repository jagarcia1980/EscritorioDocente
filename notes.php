<?php
require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS pizarra_compartida (id_pizarra VARCHAR(50) PRIMARY KEY, id_interviniente VARCHAR(50), ip_interviniente VARCHAR(45), ultimo_acceso INT)");
} catch(PDOException $e) { 
    die(json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos.'])); 
}

$uploadDir = 'Notas/';
if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); }

$secretSalt = "MiClaveSecreta_Pizarra_2026_"; 

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] == 'list_classes') {
        $files = glob($uploadDir . '*.json');
        $classes = [];
        foreach($files as $file) {
            $id = basename($file, '.json');
            $classes[] = [
                'id' => $id,
                'date' => date('d/m/Y H:i', filemtime($file)),
                'timestamp' => filemtime($file)
            ];
        }
        usort($classes, function($a, $b) { return $b['timestamp'] - $a['timestamp']; });
        echo json_encode($classes);
        exit;
    }

    $actionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
    $actionV = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['v'] ?? '');
    $targetId = '';

    if (!empty($actionId)) {
        $targetId = $actionId; 
    } elseif (!empty($actionV)) {
        foreach(glob($uploadDir . '*.json') as $file) {
            $id = basename($file, '.json');
            if (md5($secretSalt . $id) === $actionV) {
                $targetId = $id; break;
            }
        }
    }

    if(empty($targetId)) { echo json_encode(['status' => 'error', 'message' => 'Clase no encontrada']); exit; }

    $pdfFile = $uploadDir . $targetId . ".pdf";
    $jsonFile = $uploadDir . $targetId . ".json";

    if ($_GET['action'] == 'upload_pdf' && isset($_FILES['pdf'])) {
        if(move_uploaded_file($_FILES['pdf']['tmp_name'], $pdfFile)) echo json_encode(['status' => 'success']);
        else echo json_encode(['status' => 'error']);
        exit;
    }

    if ($_GET['action'] == 'upload_custom_img' && isset($_FILES['image'])) {
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $imgName = $targetId . "_img_" . uniqid() . "." . $ext;
        $dest = $uploadDir . $imgName;
        if(move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
            echo json_encode(['status' => 'success', 'url' => $dest]);
        } else {
            echo json_encode(['status' => 'error']);
        }
        exit;
    }

    if ($_GET['action'] == 'save_notes') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['payload'])) {
            echo json_encode(['status' => 'error']);
            exit;
        }
        
        $notas = $data['payload']; 
        $interviniente = $data['interviniente'] ?? 'desconocido';
        $ip = $_SERVER['REMOTE_ADDR'];
        $tiempoActual = time();
        $forzarDocente = ($interviniente === 'docente');

        $stmt = $pdo->prepare("SELECT id_interviniente, ultimo_acceso FROM pizarra_compartida WHERE id_pizarra = ?");
        $stmt->execute([$targetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $puedeEscribir = false;
        if (!$row) {
            $stmt = $pdo->prepare("INSERT INTO pizarra_compartida (id_pizarra, id_interviniente, ip_interviniente, ultimo_acceso) VALUES (?, ?, ?, ?)");
            $stmt->execute([$targetId, $interviniente, $ip, $tiempoActual]);
            $puedeEscribir = true;
        } else {
            $tiempoTranscurrido = $tiempoActual - $row['ultimo_acceso'];
            if ($forzarDocente || $row['id_interviniente'] === $interviniente || $tiempoTranscurrido >= 15) {
                $stmt = $pdo->prepare("UPDATE pizarra_compartida SET id_interviniente = ?, ip_interviniente = ?, ultimo_acceso = ? WHERE id_pizarra = ?");
                $stmt->execute([$interviniente, $ip, $tiempoActual, $targetId]);
                $puedeEscribir = true;
            }
        }

        if ($puedeEscribir) {
            file_put_contents($jsonFile, $notas, LOCK_EX);
            echo json_encode(['status' => 'success', 'hash' => md5($notas)]);
        } else {
            echo json_encode(['status' => 'locked']);
        }
        exit;
    }

    if ($_GET['action'] == 'load_data') {
        $pdfUrl = file_exists($pdfFile) ? $pdfFile : null;
        $payload = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile)) : new stdClass();
        $dataHash = file_exists($jsonFile) ? md5_file($jsonFile) : '';
        
        $stmt = $pdo->prepare("SELECT id_interviniente, ip_interviniente, ultimo_acceso FROM pizarra_compartida WHERE id_pizarra = ?");
        $stmt->execute([$targetId]);
        $lockData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['pdfUrl' => $pdfUrl, 'payload' => $payload, 'hash' => $dataHash, 'lock' => $lockData]);
        exit;
    }
}

$claseId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
$viewHash = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['v'] ?? '');
$role = 'teacher';

if (!empty($viewHash)) {
    $found = false;
    foreach(glob($uploadDir . '*.json') as $file) {
        $id = basename($file, '.json');
        if (md5($secretSalt . $id) === $viewHash) {
            $claseId = $id; 
            $role = 'student';
            $found = true;
            break;
        }
    }
    if (!$found) die("<div style='font-family:sans-serif;text-align:center;margin-top:50px;'><h2>Esta clase no existe o el enlace ha caducado.</h2></div>");
} 
elseif (empty($claseId)) {
    $nuevoId = substr(md5(uniqid(mt_rand(), true)), 0, 8);
    header("Location: ?id=" . $nuevoId);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pizarra - <?php echo ($role === 'student') ? 'Clase en Vivo' : $claseId; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js"></script>
    <style>
        #pizarra-wrapper { background: #2d3748; min-height: calc(100vh - 80px); overflow: auto; padding: 30px 20px; display: flex; justify-content: center; }
        .canvas-container { box-shadow: 0 10px 40px rgba(0,0,0,0.6); }
        .tool-active { background-color: #3b82f6 !important; color: white !important; }
        .toggle-checkbox-red:checked { right: 0; border-color: #EF4444; }
        .toggle-checkbox-red:checked + .toggle-label { background-color: #EF4444; }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #4B5563; border-radius: 4px; }
    </style>
</head>
<body class="bg-gray-900 text-white font-sans overflow-hidden">

    <div id="modal-clases" class="fixed inset-0 bg-black/70 hidden flex items-center justify-center z-[100] backdrop-blur-sm transition-opacity">
        <div class="bg-gray-800 border border-gray-700 rounded-xl shadow-2xl w-full max-w-md p-6 flex flex-col max-h-[80vh]">
            <div class="flex justify-between items-center mb-4 border-b border-gray-700 pb-3">
                <h2 class="text-xl font-bold text-white"><i class="fas fa-folder-open text-blue-400 mr-2"></i> Mis Pizarras</h2>
                <button onclick="cerrarModalClases()" class="text-gray-400 hover:text-white transition"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div id="lista-clases" class="flex-1 overflow-y-auto custom-scroll pr-2 flex flex-col gap-2"></div>
        </div>
    </div>

    <nav class="h-[80px] bg-gray-800 border-b border-gray-700 flex items-center justify-between px-4 shadow-2xl z-50 relative">
        <div class="flex items-center gap-3">
            <?php if($role !== 'student'): ?>
                <button onclick="abrirModalClases()" class="bg-gray-700 hover:bg-gray-600 border border-gray-600 p-2 rounded-lg text-blue-400 transition" title="Ver clases anteriores">
                    <i class="fas fa-folder-open text-lg"></i>
                </button>

                <div class="flex flex-col bg-gray-900 p-1 px-2 rounded border border-gray-700">
                    <span class="text-[9px] text-gray-500 uppercase font-bold tracking-widest">ID Clase</span>
                    <input type="text" value="<?php echo $claseId; ?>" onchange="cambiarClase(this.value)" class="bg-transparent text-blue-400 focus:outline-none font-bold text-sm w-24">
                </div>
                
                <input type="file" id="pdf-upload" accept="application/pdf" class="hidden">
                <button onclick="document.getElementById('pdf-upload').click()" class="bg-blue-600 hover:bg-blue-700 p-2 px-4 rounded-lg text-sm transition flex items-center font-medium shadow">
                    <i class="fas fa-file-upload mr-2"></i> Subir PDF
                </button>
            <?php else: ?>
                <div class="bg-gray-900 px-4 py-2 rounded-lg border border-gray-700">
                    <span class="text-xs text-gray-400 uppercase font-bold tracking-widest">Pizarra:</span>
                    <span class="ml-2 text-blue-400 font-bold">En Vivo</span>
                </div>
            <?php endif; ?>

            <div id="page-controls" class="hidden flex items-center bg-gray-700 rounded-lg ml-2">
                <button onclick="changePage(-1)" class="p-2 hover:bg-gray-600 rounded-l-lg"><i class="fas fa-chevron-left"></i></button>
                <span id="page-info" class="text-xs font-mono w-12 text-center">0/0</span>
                <button onclick="changePage(1)" class="p-2 hover:bg-gray-600 rounded-r-lg"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>

        <div id="toolbar" class="flex items-center gap-1 bg-gray-700 p-1.5 rounded-xl border border-gray-600 shadow-inner transition-all duration-300" style="display: <?php echo ($role === 'student') ? 'none' : 'flex'; ?>;">
            <button onclick="setTool('draw')" class="btn-t p-2 w-10 h-10 rounded-lg hover:bg-gray-600 tool-active" id="t-draw"><i class="fas fa-pencil-alt"></i></button>
            <button onclick="setTool('highlight')" class="btn-t p-2 w-10 h-10 rounded-lg hover:bg-gray-600" id="t-highlight" title="Subrayar Libre"><i class="fas fa-highlighter"></i></button>
            
            <input type="file" id="img-upload" accept="image/*" class="hidden" onchange="handleImgUpload(this)">
            <button onclick="document.getElementById('img-upload').click()" class="btn-t p-2 w-10 h-10 rounded-lg hover:bg-gray-600" title="Insertar Imagen"><i class="fas fa-image"></i></button>

            <button onclick="addText()" class="p-2 w-10 h-10 rounded-lg hover:bg-gray-600"><i class="fas fa-font"></i></button>
            <button onclick="addArrow('right')" class="p-2 w-10 h-10 rounded-lg hover:bg-gray-600" title="Flecha Derecha"><i class="fas fa-long-arrow-alt-right"></i></button>
            <button onclick="addArrow('left')" class="p-2 w-10 h-10 rounded-lg hover:bg-gray-600" title="Flecha Izquierda"><i class="fas fa-long-arrow-alt-left"></i></button>
            <button onclick="setTool('select')" class="btn-t p-2 w-10 h-10 rounded-lg hover:bg-gray-600" id="t-select"><i class="fas fa-mouse-pointer"></i></button>
            
            <div class="w-[1px] h-8 bg-gray-600 mx-2"></div>
            <div class="flex flex-col gap-1 items-center mr-2">
                <input type="color" id="colorPicker" value="#FFFF00" class="w-8 h-6 rounded cursor-pointer border-none p-0">
                <input type="range" id="sizeSlider" min="1" max="20" value="3" class="w-16 h-1 bg-gray-500 rounded outline-none appearance-none cursor-pointer">
            </div>
            <button onclick="deleteSelected()" class="p-2 w-10 h-10 text-red-400 hover:bg-red-900/40 rounded-lg"><i class="fas fa-trash"></i></button>
        </div>

        <div class="flex items-center gap-2">
            <?php if($role !== 'student'): ?>
                <div class="flex items-center gap-2 border-r border-gray-600 pr-4 mr-2">
                    <span class="text-xs text-yellow-500 font-bold uppercase"><i class="fas fa-lock-open mr-1"></i> Alumnos</span>
                    <div class="relative inline-block w-10 align-middle select-none">
                        <input type="checkbox" id="allowStudentsToggle" onchange="autoSave()" class="toggle-checkbox-red absolute block w-5 h-5 rounded-full bg-white border-4 border-gray-600 appearance-none cursor-pointer z-10"/>
                        <label for="allowStudentsToggle" class="toggle-label block overflow-hidden h-5 rounded-full bg-gray-600 cursor-pointer"></label>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button onclick="copyTeacherLink()" class="bg-blue-600 hover:bg-blue-500 p-2 px-3 rounded-lg text-xs transition font-bold shadow flex items-center" title="Copiar tu enlace de acceso total">
                        <i class="fas fa-key mr-2"></i> ENLACE PROFE
                    </button>
                    <button onclick="copyShareLink()" class="bg-gray-600 hover:bg-gray-500 p-2 px-3 rounded-lg text-xs transition font-bold shadow flex items-center" title="Copiar enlace seguro para alumnos">
                        <i class="fas fa-share-nodes mr-2"></i> LINK ALUMNOS
                    </button>
                </div>
            <?php else: ?>
                <div class="flex items-center gap-2 bg-blue-900/40 px-3 py-1.5 rounded-full border border-blue-800">
                    <span class="relative flex h-3 w-3"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span><span class="relative inline-flex rounded-full h-3 w-3 bg-blue-500"></span></span>
                    <span class="text-xs text-blue-300 font-bold uppercase tracking-wide">Recibiendo en Vivo</span>
                </div>
            <?php endif; ?>
            <span id="save-status" class="text-xs font-bold w-16 text-right <?php echo ($role === 'student') ? 'hidden' : 'text-green-400'; ?>"></span>
        </div>
    </nav>

    <div id="pizarra-wrapper">
        <canvas id="pizarra"></canvas>
    </div>

<script>
    const pdfjsLib = window['pdfjs-dist/build/pdf'];
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';

    let canvas = new fabric.Canvas('pizarra', { width: 800, height: 600, backgroundColor: '#ffffff' });
    let pdfDoc = null, pageNum = 1, annotations = {};
    let currentPdfUrl = null; 
    
    const userRole = "<?php echo $role; ?>";
    const isStudent = (userRole === 'student');
    const queryParam = "<?php echo ($role === 'student') ? 'v='.$viewHash : 'id='.$claseId; ?>";
    const studentLinkHash = "<?php echo md5($secretSalt . $claseId); ?>";
    const miToken = isStudent ? 'alumno_' + Math.random().toString(36).substr(2, 9) : 'docente';
    
    let lastServerHash = '', isDrawing = false, currentMode = 'draw', studentCanDraw = false, isRendering = false;

    const colorPicker = document.getElementById('colorPicker');
    const sizeSlider = document.getElementById('sizeSlider');
    const toolbar = document.getElementById('toolbar');
    const allowStudentsToggle = document.getElementById('allowStudentsToggle');
    const saveStatus = document.getElementById('save-status');

    function hexToRgba(hex, alpha) {
        let r = parseInt(hex.slice(1, 3), 16), g = parseInt(hex.slice(3, 5), 16), b = parseInt(hex.slice(5, 7), 16);
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    async function abrirModalClases() {
        try {
            const r = await fetch('?action=list_classes'); 
            const clases = await r.json();
            const lista = document.getElementById('lista-clases');
            if(clases.length === 0) lista.innerHTML = `<div class="text-center text-gray-500 py-6">No hay clases.</div>`;
            else lista.innerHTML = clases.map(c => `<div onclick="cambiarClase('${c.id}')" class="flex justify-between items-center bg-gray-700/50 hover:bg-gray-600 p-3 rounded-lg cursor-pointer border border-transparent hover:border-blue-500 transition-all group"><span class="font-bold text-gray-200 group-hover:text-blue-400"><i class="fas fa-file-pdf mr-2 text-gray-500"></i>${c.id}</span><span class="text-xs text-gray-400 bg-gray-800 px-2 py-1 rounded">${c.date}</span></div>`).join('');
            document.getElementById('modal-clases').classList.remove('hidden');
        } catch (e) { alert("Error"); }
    }
    
    function cerrarModalClases() { 
        document.getElementById('modal-clases').classList.add('hidden'); 
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
        
        const activeObj = canvas.getActiveObject();
        if (activeObj && activeObj.type === 'i-text') { 
            activeObj.set({ fill: colorPicker.value, fontSize: parseInt(sizeSlider.value) * 6 }); 
            canvas.renderAll(); 
            autoSave(); 
        }
    }

    colorPicker.addEventListener('input', updateBrush); 
    sizeSlider.addEventListener('input', updateBrush);

    function setTool(mode) {
        currentMode = mode; updateBrush();
        document.querySelectorAll('.btn-t').forEach(b => b.classList.remove('tool-active'));
        if(document.getElementById('t-' + mode)) document.getElementById('t-' + mode).classList.add('tool-active');
    }

    async function handleImgUpload(input) {
        if (!input.files || !input.files[0]) return;
        const formData = new FormData();
        formData.append('image', input.files[0]);
        saveStatus.innerText = "Subiendo...";
        try {
            const r = await fetch(`?action=upload_custom_img&${queryParam}`, { method: 'POST', body: formData });
            const res = await r.json();
            if (res.status === 'success') {
                fabric.Image.fromURL(res.url, function(img) {
                    if(img.width > 300) img.scaleToWidth(300);
                    img.set({ left: 100, top: 100 });
                    const objects = canvas.getObjects();
                    const lastImgIndex = objects.reduce((lastIdx, obj, idx) => {
                        return (obj.type === 'image') ? idx : lastIdx;
                    }, -1);
                    canvas.insertAt(img, lastImgIndex + 1);
                    canvas.setActiveObject(img);
                    setTool('select'); 
                    autoSave();
                    saveStatus.innerText = "Hecho";
                    setTimeout(() => { if(!isStudent) saveStatus.innerText = ""; }, 2000);
                });
            }
        } catch (e) { alert("Error al subir imagen"); }
        input.value = ""; 
    }

    function addText() { setTool('select'); const tSize = parseInt(sizeSlider.value) * 6; const t = new fabric.IText('Escribe...', { left: 100, top: 100, fontSize: tSize < 12 ? 14 : tSize, fill: colorPicker.value }); canvas.add(t); canvas.setActiveObject(t); autoSave(); }
    function addArrow(dir) { setTool('select'); const c = colorPicker.value; const w = parseInt(sizeSlider.value); const line = new fabric.Line([50, 50, 200, 50], { stroke: c, strokeWidth: w }); let triConfig = dir === 'right' ? { left: 200, top: 50, angle: 90 } : { left: 50, top: 50, angle: -90 }; const tri = new fabric.Triangle({ left: triConfig.left, top: triConfig.top, angle: triConfig.angle, width: w*4+5, height: w*4+5, fill: c, originX: 'center', originY: 'center' }); canvas.add(new fabric.Group([line, tri], { left: 100, top: 100 })); autoSave(); }
    function deleteSelected() { canvas.getActiveObjects().forEach(obj => canvas.remove(obj)); canvas.discardActiveObject().renderAll(); autoSave(); }

    function applyPermissions(canDraw) {
        if (isStudent) {
            studentCanDraw = canDraw;
            if (!canDraw) { toolbar.style.display = 'none'; canvas.isDrawingMode = false; canvas.selection = false; canvas.forEachObject(o => o.selectable = false); } 
            else { toolbar.style.display = 'flex'; canvas.selection = true; canvas.forEachObject(o => o.selectable = true); updateBrush(); }
        }
        canvas.renderAll();
    }

    let saveTimeout;
    function autoSave() {
        if(isStudent && !studentCanDraw) return;
        if(isRendering) return;
        clearTimeout(saveTimeout);
        if(!isStudent) saveStatus.innerText = "Guardando...";
        
        saveTimeout = setTimeout(async () => {
            annotations[pageNum] = canvas.toJSON();
            const allowDraw = isStudent ? studentCanDraw : allowStudentsToggle.checked;
            const payloadString = JSON.stringify({ notes: annotations, settings: { allowStudents: allowDraw, currentPage: pageNum } });
            const dataToSend = { interviniente: miToken, payload: payloadString };
            
            try {
                const r = await fetch(`?action=save_notes&${queryParam}`, { method: 'POST', body: JSON.stringify(dataToSend) });
                const res = await r.json();
                if(res.status === 'success') {
                    if(!isStudent) saveStatus.innerText = "Hecho";
                    lastServerHash = res.hash; 
                    saveTimeout = null;
                    setTimeout(() => { if(!isStudent) saveStatus.innerText = ""; }, 2000);
                }
            } catch (e) {}
        }, 800);
    }

    canvas.on('object:added', autoSave); 
    canvas.on('object:modified', autoSave); 
    canvas.on('object:removed', autoSave);
    canvas.on('mouse:down', () => isDrawing = true); 
    canvas.on('mouse:up', () => isDrawing = false);

    async function checkUpdatesSilent() {
        if(isDrawing) return; 
        try {
            const r = await fetch(`?action=load_data&${queryParam}&t=${new Date().getTime()}`);
            const data = await r.json();
            
            if (data.pdfUrl && data.pdfUrl !== currentPdfUrl) {
                currentPdfUrl = data.pdfUrl;
                pdfDoc = await pdfjsLib.getDocument(currentPdfUrl + "?t=" + new Date().getTime()).promise;
                document.getElementById('page-controls').classList.remove('hidden');
                renderPage(pageNum);
            }
            
            const payloadData = data.payload || {};
            const dbAllowDraw = payloadData.settings ? payloadData.settings.allowStudents : false;
            const dbPage = payloadData.settings && payloadData.settings.currentPage ? payloadData.settings.currentPage : null;
            
            const lockData = data.lock;
            let canIWrite = false;
            let whoIsEditing = "";
            let isLockedByOther = false;
            
            if (lockData) {
                const lockAge = (Date.now() / 1000) - lockData.ultimo_acceso;
                const isMyTurn = lockData.id_interviniente === miToken;
                
                canIWrite = dbAllowDraw && (isMyTurn || lockAge >= 15 || !isStudent);
                
                if (!isMyTurn && lockData.id_interviniente !== 'docente') {
                    if (lockAge < 15) whoIsEditing = `Editando desde ${lockData.id_interviniente}`;
                    if (lockAge < 2.5) isLockedByOther = true;
                }
            } else {
                canIWrite = dbAllowDraw || !isStudent;
            }

            let editIndicator = document.getElementById('edit-indicator');
            if(!editIndicator) {
                editIndicator = document.createElement('div');
                editIndicator.id = 'edit-indicator';
                editIndicator.className = 'absolute top-20 left-1/2 transform -translate-x-1/2 bg-red-600 text-white px-4 py-1 rounded shadow-lg z-50 text-sm font-bold transition-opacity duration-300';
                document.body.appendChild(editIndicator);
            }
            
            if(whoIsEditing) {
                editIndicator.innerText = whoIsEditing;
                editIndicator.style.opacity = '1';
            } else {
                editIndicator.style.opacity = '0';
            }
            
            if (isStudent) { if(studentCanDraw !== canIWrite) applyPermissions(canIWrite); } 
            else { if(allowStudentsToggle && !saveTimeout) allowStudentsToggle.checked = dbAllowDraw; }
            
            if (data.hash && data.hash !== lastServerHash) {
                if (isLockedByOther) return; 
                
                lastServerHash = data.hash;
                annotations = payloadData.notes || {};
                isRendering = true;
                
                if (isStudent && dbPage && dbPage !== pageNum) { 
                    pageNum = dbPage; 
                    renderPage(pageNum); 
                } else {
                    if (annotations[pageNum]) {
                        canvas.loadFromJSON(annotations[pageNum], () => { 
                            if(pdfDoc) renderPageBackgroundOnly(pageNum); 
                            else canvas.setBackgroundColor('#ffffff', canvas.renderAll.bind(canvas)); 
                            applyPermissions(isStudent ? studentCanDraw : true); 
                            isRendering = false; 
                        });
                    } else { 
                        canvas.clear(); 
                        if(pdfDoc) renderPageBackgroundOnly(pageNum); 
                        else canvas.setBackgroundColor('#ffffff', canvas.renderAll.bind(canvas)); 
                        applyPermissions(isStudent ? studentCanDraw : true); 
                        isRendering = false; 
                    }
                }
            }
        } catch (e) {}
    }
    setInterval(checkUpdatesSilent, 2000);

    if(!isStudent) {
        document.getElementById('pdf-upload').addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = async function() {
                const typedarray = new Uint8Array(this.result);
                pdfDoc = await pdfjsLib.getDocument(typedarray).promise;
                pageNum = 1; document.getElementById('page-controls').classList.remove('hidden'); renderPage(pageNum); autoSave();
            };
            reader.readAsArrayBuffer(file);
            const formData = new FormData(); formData.append('pdf', file);
            try { await fetch(`?action=upload_pdf&${queryParam}`, { method: 'POST', body: formData }); } catch (e) {}
        });
    }

    async function loadDataFull() {
        try {
            const r = await fetch(`?action=load_data&${queryParam}&t=${new Date().getTime()}`);
            const data = await r.json();
            
            lastServerHash = data.hash;
            const payloadData = data.payload || {};
            annotations = payloadData.notes || {};
            const dbAllowDraw = payloadData.settings ? payloadData.settings.allowStudents : false;
            const dbPage = payloadData.settings && payloadData.settings.currentPage ? payloadData.settings.currentPage : 1;
            
            const lockData = data.lock;
            let canIWrite = false;
            
            if (lockData) {
                const lockAge = (Date.now() / 1000) - lockData.ultimo_acceso;
                const isMyTurn = lockData.id_interviniente === miToken;
                canIWrite = dbAllowDraw && (isMyTurn || lockAge >= 15 || !isStudent);
            } else {
                canIWrite = dbAllowDraw || !isStudent;
            }
            
            if(isStudent) { pageNum = dbPage; applyPermissions(canIWrite); } 
            else if(allowStudentsToggle) allowStudentsToggle.checked = dbAllowDraw;
            
            if (data.pdfUrl) {
                currentPdfUrl = data.pdfUrl;
                pdfDoc = await pdfjsLib.getDocument(data.pdfUrl + "?t=" + new Date().getTime()).promise;
                document.getElementById('page-controls').classList.remove('hidden');
                renderPage(pageNum);
            } else { 
                if (annotations[pageNum]) {
                    canvas.loadFromJSON(annotations[pageNum], () => { applyPermissions(isStudent ? studentCanDraw : true); }); 
                } else {
                    applyPermissions(isStudent ? studentCanDraw : true); 
                }
            }
        } catch (e) {}
    }

    async function renderPage(num) {
        isRendering = true;
        if (!pdfDoc) {
            if (annotations[num]) {
                canvas.loadFromJSON(annotations[num], () => { 
                    canvas.setBackgroundColor('#ffffff', canvas.renderAll.bind(canvas)); 
                    applyPermissions(isStudent ? studentCanDraw : true); 
                    isRendering = false; 
                });
            } else { 
                canvas.clear(); 
                canvas.setBackgroundColor('#ffffff', canvas.renderAll.bind(canvas)); 
                applyPermissions(isStudent ? studentCanDraw : true); 
                isRendering = false; 
            }
            return;
        }
        
        const page = await pdfDoc.getPage(num); const viewport = page.getViewport({ scale: 1.5 });
        canvas.setHeight(viewport.height); canvas.setWidth(viewport.width);
        const tempCanvas = document.createElement('canvas'); tempCanvas.height = viewport.height; tempCanvas.width = viewport.width;
        await page.render({ canvasContext: tempCanvas.getContext('2d'), viewport: viewport }).promise;
        
        fabric.Image.fromURL(tempCanvas.toDataURL(), function(img) {
            if (annotations[num]) {
                canvas.loadFromJSON(annotations[num], () => { 
                    canvas.setBackgroundImage(img, canvas.renderAll.bind(canvas)); 
                    applyPermissions(isStudent ? studentCanDraw : true); 
                    isRendering = false; 
                });
            } else { 
                canvas.clear(); 
                canvas.setBackgroundImage(img, canvas.renderAll.bind(canvas)); 
                applyPermissions(isStudent ? studentCanDraw : true); 
                isRendering = false; 
            }
        });
        document.getElementById('page-info').innerText = `${num}/${pdfDoc.numPages}`;
    }

    async function renderPageBackgroundOnly(num) {
        if (!pdfDoc) { canvas.setBackgroundColor('#ffffff', canvas.renderAll.bind(canvas)); return; }
        const page = await pdfDoc.getPage(num); const viewport = page.getViewport({ scale: 1.5 });
        const tempCanvas = document.createElement('canvas'); tempCanvas.height = viewport.height; tempCanvas.width = viewport.width;
        await page.render({ canvasContext: tempCanvas.getContext('2d'), viewport: viewport }).promise;
        fabric.Image.fromURL(tempCanvas.toDataURL(), (img) => canvas.setBackgroundImage(img, canvas.renderAll.bind(canvas)));
    }

    function changePage(d) {
        if (!pdfDoc) return;
        let n = pageNum + d;
        if (n > 0 && n <= pdfDoc.numPages) { 
            if(!isStudent || studentCanDraw) annotations[pageNum] = canvas.toJSON();
            pageNum = n; renderPage(n); if (!isStudent) autoSave(); 
        }
    }

    function cambiarClase(nuevo) { 
        window.location.href = "?id=" + nuevo.replace(/[^a-zA-Z0-9_-]/g, ''); 
    }
    
    function copyTeacherLink() {
        const url = window.location.href;
        navigator.clipboard.writeText(url);
        alert("Enlace de PROFESOR copiado.\n\nÚsalo para volver a entrar con permisos de edición. ¡No lo envíes a los alumnos!");
    }

    function copyShareLink() {
        const url = window.location.origin + window.location.pathname + "?v=" + studentLinkHash;
        navigator.clipboard.writeText(url);
        alert("Enlace para ALUMNOS copiado.\n\nEste enlace es solo de lectura (espectador) a menos que les abras el candado.");
    }

    setTool('draw'); loadDataFull();
</script>
</body>
</html>