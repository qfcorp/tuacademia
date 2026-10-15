<?php
/*
 * (v28) LECTOR DE ENTRADAS PARA LA PUERTA: puerta.php?k=CLAVE (enlace secreto de cada venta, lo comparte la liga).
 *  - Escanea el QR con la cámara: VERDE = pasa · ROJO = no pasa (ya usada, anulada, de otro partido o falsa).
 *  - Con internet valida en el servidor (la primera lectura gana, aunque haya varias puertas).
 *  - Sin internet usa la lista descargada (solo huellas de los códigos) y sincroniza cuando vuelve la señal.
 */
require __DIR__ . '/../app/bootstrap.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$k = preg_match('/^[0-9a-f]{20}$/', get('k')) ? get('k') : '';
$v = $k !== '' ? fila('SELECT * FROM bol_ventas WHERE puerta_clave = ?', [$k]) : null;
$liga = $v ? fila('SELECT * FROM ligas WHERE id = ?', [$v['liga_id']]) : null;
cortar_liga_publica($liga, false, true); // (Anexo A) en la papelera → 404; pausada → «temporalmente no disponible»
$json = function (array $d): void { header('Content-Type: application/json'); header('Cache-Control: no-store'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
if (!$v || !$liga) {
    if (get('lista') !== '' || es_post()) $json(['ok' => false, 'error' => 'Enlace no válido o cambiado. Pida el enlace nuevo a la liga.']);
    cabecera_publica('Lector de entradas');
    echo '<div class="tarjeta login"><h1>Enlace no válido</h1><p>Este enlace del lector de entradas no existe o la liga lo cambió. Pida el enlace nuevo.</p></div>';
    pie_publico();
    exit;
}
contexto_auditoria(['usuario' => 'Puerta (lector de entradas)', 'rol' => 'puerta', 'liga_id' => (int)$liga['id'], 'origen' => 'puerta']);

if (get('lista') === '1') {
    $ts = [];
    foreach (filas('SELECT t.codigo, t.titular, t.placa, t.estado, l.nombre AS loc FROM bol_tickets t JOIN bol_localidades l ON l.id = t.localidad_id JOIN bol_ordenes o ON o.id = t.orden_id WHERE t.venta_id = ? AND o.estado = \'pagada\'', [$v['id']]) as $t) {
        $ts[] = ['h' => hash('sha256', $t['codigo']), 'l' => $t['loc'], 't' => $t['titular'], 'p' => $t['placa'], 'e' => $t['estado']];
    }
    $json(['ok' => true, 'venta' => $v['titulo'], 'n' => count($ts), 'tickets' => $ts, 'hora' => date('Y-m-d H:i:s')]);
}
if (es_post()) {
    $d = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (($d['a'] ?? '') === 'validar') {
        [$res, $txt] = validar_entrada($v, (string)($d['c'] ?? ''), mb_substr((string)($d['puerta'] ?? ''), 0, 40));
        $json(['ok' => true, 'r' => $res, 'txt' => $txt, 'dentro' => (int)valor("SELECT COUNT(*) FROM bol_tickets WHERE venta_id = ? AND estado = 'usado'", [$v['id']])]);
    }
    if (($d['a'] ?? '') === 'sync') {
        $out = [];
        foreach (array_slice((array)($d['lecturas'] ?? []), 0, 500) as $x) {
            $hora = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($x['hora'] ?? '')) ? $x['hora'] : '';
            [$res, $txt] = validar_entrada($v, (string)($x['c'] ?? ''), mb_substr((string)($x['puerta'] ?? ''), 0, 40), true, $hora);
            $out[] = ['c' => $x['c'] ?? '', 'r' => $res, 'txt' => $txt];
        }
        $json(['ok' => true, 'resultados' => $out]);
    }
    if (($d['a'] ?? '') === 'buscar') {
        $q = trim((string)($d['q'] ?? ''));
        $rs = mb_strlen($q) < 4 ? [] : filas("SELECT t.codigo, t.titular, t.placa, t.estado, l.nombre AS loc FROM bol_tickets t JOIN bol_localidades l ON l.id = t.localidad_id JOIN bol_ordenes o ON o.id = t.orden_id
            WHERE t.venta_id = ? AND o.estado = 'pagada' AND (o.cedula = ? OR t.placa = ? OR t.codigo = ? OR o.nombre LIKE ?) LIMIT 20", [$v['id'], $q, strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q)), $q, '%' . $q . '%']);
        $json(['ok' => true, 'tickets' => $rs]);
    }
    $json(['ok' => false, 'error' => 'Acción no válida.']);
}
cabecera_publica('Puerta · ' . $v['titulo'], $liga);
?>
<style>
  .puerta-top { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .puerta-res { border-radius: 16px; padding: 22px 14px; text-align: center; color: #fff; font-size: 1.5rem; font-weight: 800; margin: 12px 0; min-height: 110px; display: flex; flex-direction: column; justify-content: center; }
  .puerta-res small { font-size: 1rem; font-weight: 600; opacity: .95; margin-top: 6px; }
  .res-ok { background: #1a9e5c; } .res-mal { background: #d7263d; } .res-duda { background: #e0a100; } .res-espera { background: #46524e; }
  #p-video { width: 100%; max-height: 52vh; background: #000; border-radius: 12px; object-fit: cover; }
  .p-cont { display: flex; gap: 10px; flex-wrap: wrap; font-size: .9rem; }
</style>
<h1 style="margin-bottom:.2rem">📱 Puerta · <?= e($v['titulo']) ?></h1>
<p class="suave" style="margin-top:0"><?= e(fecha_bonita($v['fecha'])) ?> <?= e($v['hora']) ?> · <span id="p-red">…</span></p>
<div class="puerta-top">
  <label>Puerta: <input id="p-puerta" placeholder="Ej: Puerta 1" style="max-width:150px"></label>
  <button class="btn" id="p-cam" type="button" onclick="abrir()">📷 Escanear</button>
  <button class="btn btn-sec btn-chico" type="button" onclick="descargar(true)">⬇ Actualizar lista</button>
</div>
<div class="p-cont"><span>Lista: <b id="p-n">0</b> entradas</span><span>Ingresaron (este celular): <b id="p-ok">0</b></span><span>Sin sincronizar: <b id="p-cola">0</b></span><span>Dentro (todas las puertas): <b id="p-dentro">—</b></span></div>
<div id="p-res" class="puerta-res res-espera">Listo para escanear</div>
<video id="p-video" playsinline muted hidden></video><canvas id="p-lienzo" hidden></canvas>
<details class="tarjeta" style="margin-top:10px"><summary><b>🔎 Buscar por cédula, placa o nombre</b> (si el QR no se lee)</summary>
  <div class="acciones" style="margin-top:8px"><input id="p-q" placeholder="Cédula, placa o nombre"><button class="btn btn-sec" type="button" onclick="buscar()">Buscar</button></div>
  <div id="p-bus"></div></details>
<script src="assets/js/jsQR.js"></script>
<script>
var K = <?= json_encode($k) ?>, CLAVE = 'puerta_' + K, URL = 'puerta.php?k=' + K;
var lista = {}, usados = {}, cola = [], okn = 0, flujo = null, buscando = false, ultimo = '', ultimoT = 0, det = null;
function guardar() { try { localStorage.setItem(CLAVE, JSON.stringify({ lista: lista, usados: usados, cola: cola, okn: okn })); } catch (e) {} }
function cargar() { try { var d = JSON.parse(localStorage.getItem(CLAVE) || '{}'); lista = d.lista || {}; usados = d.usados || {}; cola = d.cola || []; okn = d.okn || 0; } catch (e) {} }
function pintar() { document.getElementById('p-n').textContent = Object.keys(lista).length; document.getElementById('p-ok').textContent = okn; document.getElementById('p-cola').textContent = cola.length; document.getElementById('p-red').textContent = navigator.onLine ? '🟢 con internet' : '🔴 sin internet (sigue funcionando)'; }
function mostrar(clase, txt, sub) { var r = document.getElementById('p-res'); r.className = 'puerta-res ' + clase; r.innerHTML = txt + (sub ? '<small>' + sub.replace(/</g, '&lt;') + '</small>' : ''); if (navigator.vibrate) navigator.vibrate(clase === 'res-ok' ? 120 : [250, 100, 250]); beep(clase === 'res-ok'); }
function beep(ok) { try { var c = new (window.AudioContext || window.webkitAudioContext)(), o = c.createOscillator(); o.frequency.value = ok ? 880 : 220; o.connect(c.destination); o.start(); setTimeout(function () { o.stop(); c.close(); }, ok ? 150 : 450); } catch (e) {} }
async function sha(t) { var b = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(t)); return Array.from(new Uint8Array(b)).map(function (x) { return x.toString(16).padStart(2, '0'); }).join(''); }
function codigo(txt) { var m = /(T[0-9a-f]{20})/.exec(txt || ''); return m ? m[1] : null; }
function puerta() { return document.getElementById('p-puerta').value || 'Puerta'; }
function hora() { var d = new Date(), p = function (n) { return String(n).padStart(2, '0'); }; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()); }
async function descargar(aviso) {
  try { var r = await (await fetch(URL + '&lista=1', { cache: 'no-store' })).json(); if (!r.ok) throw new Error(r.error);
    lista = {}; r.tickets.forEach(function (t) { lista[t.h] = t; if (t.e === 'usado') usados[t.h] = 1; }); guardar(); pintar(); if (aviso) mostrar('res-espera', 'Lista actualizada', r.n + ' entradas'); }
  catch (e) { if (aviso) mostrar('res-duda', 'Sin conexión', 'Se usa la lista guardada en este celular'); }
}
async function validar(txt) {
  var c = codigo(txt);
  if (!c) return mostrar('res-mal', '✗ NO ES UNA ENTRADA', 'Ese QR no es de ' + <?= json_encode(marca()) ?>);
  var h = await sha(c), t = lista[h];
  if (navigator.onLine) {
    try {
      var ctl = new AbortController(); setTimeout(function () { ctl.abort(); }, 4000);
      var r = await (await fetch(URL, { method: 'POST', body: JSON.stringify({ a: 'validar', c: c, puerta: puerta() }), headers: { 'Content-Type': 'application/json' }, signal: ctl.signal })).json();
      if (r.ok) { document.getElementById('p-dentro').textContent = r.dentro; if (r.r === 'ok') { usados[h] = 1; okn++; guardar(); pintar(); return mostrar('res-ok', '✓ PASA', r.txt); } usados[h] = 1; guardar(); return mostrar('res-mal', '✗ NO PASA', r.txt); }
    } catch (e) { /* sin respuesta: se valida con la lista guardada */ }
  }
  if (!t) return mostrar('res-duda', '? NO ESTÁ EN LA LISTA', 'Puede ser una compra nueva: verifíquela cuando haya internet o busque por cédula');
  if (t.e === 'anulado') return mostrar('res-mal', '✗ ANULADA', t.t);
  if (usados[h]) return mostrar('res-mal', '✗ YA SE USÓ', t.l + ' · ' + t.t);
  usados[h] = 1; okn++; cola.push({ c: c, puerta: puerta(), hora: hora() }); guardar(); pintar();
  mostrar('res-ok', '✓ PASA', t.l + (t.p ? ' · placa ' + t.p : '') + ' · ' + t.t + ' (sin internet)');
}
async function sincronizar() {
  if (!cola.length || !navigator.onLine) return;
  try { var r = await (await fetch(URL, { method: 'POST', body: JSON.stringify({ a: 'sync', lecturas: cola }), headers: { 'Content-Type': 'application/json' } })).json();
    if (r.ok) { var malos = r.resultados.filter(function (x) { return x.r !== 'ok'; }); cola = []; guardar(); pintar(); if (malos.length) mostrar('res-duda', '⚠ ' + malos.length + ' lectura(s) sin internet tenían problema', malos.map(function (x) { return x.txt; }).join(' · ')); } } catch (e) {}
}
async function buscar() {
  var q = document.getElementById('p-q').value, box = document.getElementById('p-bus');
  try { var r = await (await fetch(URL, { method: 'POST', body: JSON.stringify({ a: 'buscar', q: q }), headers: { 'Content-Type': 'application/json' } })).json();
    box.innerHTML = r.tickets.length ? '' : '<p class="suave">No se encontró.</p>';
    r.tickets.forEach(function (t) { var d = document.createElement('div'); d.className = 'fila-titulo'; d.style.margin = '6px 0';
      d.innerHTML = '<span><b>' + t.loc + '</b> · ' + t.titular + (t.placa ? ' · ' + t.placa : '') + ' · <i>' + t.estado + '</i></span>';
      if (t.estado === 'valido') { var b = document.createElement('button'); b.className = 'btn btn-chico'; b.textContent = 'Dejar pasar'; b.onclick = function () { validar(t.codigo); b.remove(); }; d.appendChild(b); }
      box.appendChild(d); });
  } catch (e) { box.innerHTML = '<p class="suave">La búsqueda necesita internet.</p>'; }
}
function abrir() {
  var v = document.getElementById('p-video');
  if (flujo) { cerrar(); return; }
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return mostrar('res-duda', 'Este celular no permite usar la cámara aquí', 'Use la búsqueda por cédula');
  navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
    flujo = s; v.srcObject = s; v.hidden = false; v.play(); buscando = true; document.getElementById('p-cam').textContent = '⏹ Detener cámara';
    if ('BarcodeDetector' in window) { try { det = new BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { det = null; } }
    leer();
  }).catch(function () { mostrar('res-duda', 'No se pudo abrir la cámara', 'Dé permiso a la cámara en el navegador'); });
}
function cerrar() { buscando = false; if (flujo) flujo.getTracks().forEach(function (t) { t.stop(); }); flujo = null; document.getElementById('p-video').hidden = true; document.getElementById('p-cam').textContent = '📷 Escanear'; }
function leido(txt) { var now = Date.now(); if (txt === ultimo && now - ultimoT < 3500) return; ultimo = txt; ultimoT = now; validar(txt); }
function leer() {
  if (!buscando) return;
  var v = document.getElementById('p-video');
  if (det) { det.detect(v).then(function (r) { if (r.length) leido(r[0].rawValue); setTimeout(leer, 200); }).catch(function () { det = null; setTimeout(leer, 200); }); return; }
  if (v.readyState === v.HAVE_ENOUGH_DATA) {
    var c = document.getElementById('p-lienzo'); c.width = v.videoWidth; c.height = v.videoHeight;
    var ctx = c.getContext('2d'); ctx.drawImage(v, 0, 0, c.width, c.height);
    var r = jsQR(ctx.getImageData(0, 0, c.width, c.height).data, c.width, c.height); if (r) leido(r.data);
  }
  requestAnimationFrame(leer);
}
try { document.getElementById('p-puerta').value = localStorage.getItem('puerta_nombre') || ''; } catch (e) {}
document.getElementById('p-puerta').addEventListener('change', function () { try { localStorage.setItem('puerta_nombre', this.value); } catch (e) {} });
cargar(); pintar(); descargar(false);
window.addEventListener('online', function () { pintar(); sincronizar(); descargar(false); }); window.addEventListener('offline', pintar);
setInterval(function () { sincronizar(); }, 8000); setInterval(function () { if (navigator.onLine) descargar(false); }, 60000);
</script>
<?php
pie_publico();
