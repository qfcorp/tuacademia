<?php
/*
 * Muestra una foto INTERNA de un partido solo a quien tiene permiso:
 * administradores de esa liga, el superadministrador, el árbitro del partido o el vocal con su enlace.
 */
require __DIR__ . '/../app/bootstrap.php';

// (v17) Foto de una clase de la escuela: la ve el personal de la escuela y, si es pública, la familia de un alumno que estuvo en esa clase.
if (get('c') !== '') {
    $fc = fila('SELECT f.*, g.liga_id FROM esc_sesion_fotos f JOIN esc_sesiones s ON s.id = f.sesion_id JOIN esc_grupos g ON g.id = s.grupo_id WHERE f.id = ?', [(int)get('c')]);
    $ok = false;
    if ($fc) {
        $u = usuario();
        if ($u && ($u['rol'] === 'super' || ($u['rol'] === 'liga' && (int)$u['liga_id'] === (int)$fc['liga_id']))) $ok = true;
        if (!$ok && !empty($_SESSION['familia']) && (int)$fc['publica'] === 1) {
            $fam = fila('SELECT * FROM esc_familias WHERE id = ?', [(int)$_SESSION['familia']]);
            $ok = $fam && (int)$fam['liga_id'] === (int)$fc['liga_id'] && (bool)valor('SELECT COUNT(*) FROM esc_asistencia x JOIN esc_alumnos a ON a.id = x.alumno_id WHERE x.sesion_id = ? AND a.representante_cedula = ? AND a.liga_id = ?', [$fc['sesion_id'], $fam['cedula'], $fam['liga_id']]);
        }
    }
    $ruta = $fc ? RAIZ . '/data/' . $fc['archivo'] : '';
    if (!$ok || !$fc || str_contains($fc['archivo'], '..') || !is_file($ruta)) { http_response_code(404); exit('No disponible'); }
    $tipos = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    header('Content-Type: ' . ($tipos[strtolower(pathinfo($ruta, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($ruta));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($ruta);
    exit;
}

$f = fila('SELECT f.*, p.arbitro_id, p.asistente1_id, p.asistente2_id, p.cuarto_id, t.liga_id FROM fotos_partido f
           JOIN partidos p ON p.id = f.partido_id JOIN torneos t ON t.id = p.torneo_id WHERE f.id = ?', [(int)get('id')]);
$ok = false;
if ($f) {
    $u = usuario();
    if ($u) {
        if ($u['rol'] === 'super') {
            $ok = true;
        } elseif ($u['rol'] === 'liga') {
            $ok = (int)$u['liga_id'] === (int)$f['liga_id'];
        } elseif ($u['rol'] === 'arbitro') {
            $ok = (bool)valor('SELECT COUNT(*) FROM arbitros WHERE usuario_id = ? AND id IN (?,?,?,?)', [$u['id'], (int)$f['arbitro_id'], (int)$f['asistente1_id'], (int)$f['asistente2_id'], (int)$f['cuarto_id']]);
        }
    }
    if (!$ok && in_array((int)$f['partido_id'], $_SESSION['vocal_partidos'] ?? [], true)) {
        $ok = true;
    }
}
$ruta = $f ? RAIZ . '/data/' . $f['archivo'] : '';
if (!$ok || !$f || str_contains($f['archivo'], '..') || !is_file($ruta)) {
    http_response_code(404);
    exit('No disponible');
}
$tipos = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
header('Content-Type: ' . ($tipos[strtolower(pathinfo($ruta, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($ruta));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($ruta);
