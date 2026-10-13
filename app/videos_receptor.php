<?php
/*
 * (v28) RECEPTOR DE VIDEOS — sin dependencias del resto del sistema.
 *
 * El mismo archivo sirve en dos lugares:
 *   1. En el servidor de TuAcademia (public/video.php), cuando los videos se guardan en el mismo servidor.
 *   2. En la LAPTOP (disco grande), copiado como /var/www/tuacademia-videos/index.php con su config.php,
 *      publicado por el túnel de Cloudflare (ej: https://videos.tukanec.com).
 *
 * Todo pedido va firmado con HMAC-SHA256 (clave compartida) y con hora de vencimiento, así nadie puede subir,
 * ver ni borrar sin un enlace generado por TuAcademia.
 *
 *   a=subir  → recibe un pedazo (8 MB) del video: f, n (número), exp, sig("subir|f|exp")      cuerpo: bytes del pedazo
 *   a=fin    → une los pedazos, mide la duración (ffprobe si existe) y devuelve la firma "listo"  (f, total, exp, sig)
 *   a=ver    → entrega el video (con Range para verlo en el celular); d=1 lo descarga         (f, exp, sig("ver|f|exp"))
 *   a=borrar → borra el video                                                               (f, exp, sig("borrar|f|exp"))
 *   php index.php limpiar → (tarea diaria) borra los videos de más de N días
 */

const VR_PEDAZO = 8 * 1024 * 1024;

function vr_firma(string $clave, string $datos): string
{
    return hash_hmac('sha256', $datos, $clave);
}

function vr_nombre_valido(string $f): bool
{
    return (bool)preg_match('/^[a-z0-9]{20,40}\.(mp4|mov|webm|m4v)$/', $f);
}

function vr_json(array $d, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($d);
    exit;
}

/** Duración en segundos con ffprobe (null si no hay ffprobe). */
function vr_duracion(string $ruta): ?float
{
    if (!function_exists('shell_exec')) return null;
    $bin = trim((string)@shell_exec('command -v ffprobe 2>/dev/null'));
    if ($bin === '') return null;
    $s = trim((string)@shell_exec(escapeshellarg($bin) . ' -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($ruta) . ' 2>/dev/null'));
    return is_numeric($s) ? (float)$s : null;
}

/** Borra los videos con más de $dias días (y pedazos abandonados de más de 1 día). Devuelve cuántos borró. */
function vr_limpiar(string $dir, int $dias): int
{
    $n = 0;
    foreach (glob(rtrim($dir, '/') . '/*.{mp4,mov,webm,m4v}', GLOB_BRACE) ?: [] as $f) {
        if (filemtime($f) < time() - $dias * 86400 && @unlink($f)) $n++;
    }
    foreach (glob(rtrim($dir, '/') . '/tmp/*') ?: [] as $f) {
        if (filemtime($f) < time() - 86400) @unlink($f);
    }
    return $n;
}

/**
 * Atiende el pedido. $cfg: dir (carpeta), clave, max_mb (tamaño máximo), max_seg (duración máxima), dias (días que se guarda).
 */
function vr_atender(array $cfg): void
{
    $dir = rtrim($cfg['dir'], '/');
    $clave = (string)$cfg['clave'];
    if ($clave === '') vr_json(['ok' => false, 'error' => 'Receptor sin clave configurada.'], 500);
    // Los envíos vienen del navegador desde otra dirección (tuacademia.tukanec.com): se permite porque todo va firmado
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
    $a = (string)($_GET['a'] ?? '');
    $f = (string)($_GET['f'] ?? '');
    $exp = (int)($_GET['exp'] ?? 0);
    $sig = (string)($_GET['sig'] ?? '');
    if ($a === '' && $f === '') vr_json(['ok' => true, 'servicio' => 'videos', 'libre_gb' => round(@disk_free_space($dir) / 1073741824, 1)]);
    if (!vr_nombre_valido($f)) vr_json(['ok' => false, 'error' => 'Archivo no válido.'], 400);
    if ($exp < time()) vr_json(['ok' => false, 'error' => 'El enlace venció. Recargue la página.'], 403);
    if (!hash_equals(vr_firma($clave, $a . '|' . $f . '|' . $exp), $sig)) vr_json(['ok' => false, 'error' => 'Firma no válida.'], 403);
    if (!is_dir($dir . '/tmp')) @mkdir($dir . '/tmp', 0775, true);
    $ruta = $dir . '/' . $f;

    if ($a === 'subir') {
        $n = (int)($_GET['n'] ?? -1);
        $max_pedazos = (int)ceil(($cfg['max_mb'] * 1024 * 1024) / VR_PEDAZO);
        if ($n < 0 || $n >= $max_pedazos) vr_json(['ok' => false, 'error' => 'El video es demasiado pesado (máximo ' . (int)$cfg['max_mb'] . ' MB).'], 400);
        $datos = file_get_contents('php://input');
        if ($datos === false || $datos === '' || strlen($datos) > VR_PEDAZO) vr_json(['ok' => false, 'error' => 'Pedazo vacío o muy grande.'], 400);
        if (@file_put_contents($dir . '/tmp/' . $f . '.' . sprintf('%04d', $n), $datos) === false) vr_json(['ok' => false, 'error' => 'No se pudo guardar (¿disco lleno?).'], 500);
        vr_json(['ok' => true, 'n' => $n]);
    }
    if ($a === 'fin') {
        $total = (int)($_GET['total'] ?? 0);
        if ($total < 1) vr_json(['ok' => false, 'error' => 'Faltan pedazos.'], 400);
        $sal = @fopen($ruta . '.uniendo', 'wb');
        if (!$sal) vr_json(['ok' => false, 'error' => 'No se pudo escribir el video.'], 500);
        for ($i = 0; $i < $total; $i++) {
            $p = $dir . '/tmp/' . $f . '.' . sprintf('%04d', $i);
            if (!is_file($p)) { fclose($sal); @unlink($ruta . '.uniendo'); vr_json(['ok' => false, 'error' => 'Falta el pedazo ' . ($i + 1) . ' de ' . $total . '. Vuelva a subirlo.'], 400); }
            $in = fopen($p, 'rb');
            stream_copy_to_stream($in, $sal);
            fclose($in);
            @unlink($p);
        }
        fclose($sal);
        rename($ruta . '.uniendo', $ruta);
        $bytes = filesize($ruta);
        $dur = vr_duracion($ruta);
        if ($dur !== null && $dur > (float)$cfg['max_seg'] + 5) {
            @unlink($ruta);
            vr_json(['ok' => false, 'error' => 'El video dura ' . (int)round($dur) . ' segundos. El máximo es ' . (int)$cfg['max_seg'] . ' segundos (' . round($cfg['max_seg'] / 60, 1) . ' min).'], 400);
        }
        $d = $dur === null ? '' : (string)round($dur, 1);
        vr_json(['ok' => true, 'bytes' => $bytes, 'duracion' => $d, 'firma' => vr_firma($clave, 'listo|' . $f . '|' . $bytes . '|' . $d)]);
    }
    if ($a === 'borrar') {
        @unlink($ruta);
        vr_json(['ok' => true]);
    }
    if ($a === 'ver') {
        if (!is_file($ruta)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Este video ya no está disponible (se borra a los ' . (int)$cfg['dias'] . ' días).'); }
        $tam = filesize($ruta);
        $ext = pathinfo($ruta, PATHINFO_EXTENSION);
        header('Content-Type: ' . (['mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm'][$ext] ?? 'application/octet-stream'));
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=600');
        header('X-Content-Type-Options: nosniff');
        if (($_GET['d'] ?? '') === '1') {
            $nom = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string)($_GET['nombre'] ?? 'video'));
            header('Content-Disposition: attachment; filename="' . substr($nom, 0, 60) . '.' . $ext . '"');
        }
        $ini = 0;
        $fin = $tam - 1;
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
            if ($m[1] !== '') $ini = (int)$m[1];
            if ($m[2] !== '') $fin = min((int)$m[2], $tam - 1);
            if ($m[1] === '' && $m[2] !== '') { $ini = max(0, $tam - (int)$m[2]); $fin = $tam - 1; }
            if ($ini > $fin || $ini >= $tam) { http_response_code(416); header('Content-Range: bytes */' . $tam); exit; }
            http_response_code(206);
            header('Content-Range: bytes ' . $ini . '-' . $fin . '/' . $tam);
        }
        header('Content-Length: ' . ($fin - $ini + 1));
        $h = fopen($ruta, 'rb');
        fseek($h, $ini);
        $falta = $fin - $ini + 1;
        while ($falta > 0 && !feof($h)) {
            $b = fread($h, min(1048576, $falta));
            echo $b;
            $falta -= strlen($b);
            @flush();
        }
        fclose($h);
        exit;
    }
    vr_json(['ok' => false, 'error' => 'Acción no válida.'], 400);
}
