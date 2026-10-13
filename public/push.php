<?php
/*
 * (v10) "SEGUIR A MI EQUIPO": guarda o quita la suscripción del celular a las notificaciones de un equipo.
 * La llama el botón "🔔 Seguir a este equipo" (página pública del equipo) y la aplicación (app.php).
 */
require __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$responder = function (array $r, int $cod = 200) {
    http_response_code($cod);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
};
if (!es_post()) $responder(['ok' => false, 'error' => 'Solo POST'], 405);
$t = $_POST['csrf'] ?? '';
if (!is_string($t) || !hash_equals(csrf_token(), $t)) $responder(['ok' => false, 'error' => 'La página venció. Recárguela e intente de nuevo.'], 400);
if (!push_disponible()) $responder(['ok' => false, 'error' => 'Las notificaciones no están disponibles en este servidor.']);
contexto_auditoria(['usuario' => 'Público (notificaciones)', 'rol' => 'publico', 'origen' => 'publico']);

$sub = json_decode((string)($_POST['sub'] ?? ''), true);
$endpoint = is_array($sub) ? (string)($sub['endpoint'] ?? '') : '';
$p256dh = is_array($sub) ? (string)($sub['keys']['p256dh'] ?? '') : '';
$auth = is_array($sub) ? (string)($sub['keys']['auth'] ?? '') : '';
if (!push_endpoint_valido($endpoint)) $responder(['ok' => false, 'error' => 'Este navegador usa un servicio de notificaciones no admitido.']);
$accion = post('accion');
$fila_sub = fila('SELECT * FROM push_suscripciones WHERE endpoint = ?', [$endpoint]);

if ($accion === 'estado') {
    $sigue = [];
    if ($fila_sub) {
        $sigue = filas('SELECT e.id, e.nombre, l.slug, l.nombre AS liga FROM push_seguimientos ps JOIN equipos e ON e.id = ps.equipo_id JOIN ligas l ON l.id = e.liga_id WHERE ps.suscripcion_id = ? ORDER BY e.nombre', [$fila_sub['id']]);
    }
    $eq = (int)post('equipo_id');
    $responder(['ok' => true, 'siguiendo' => $eq ? in_array($eq, array_map('intval', array_column($sigue, 'id')), true) : false,
        'equipos' => array_map(fn($x) => ['id' => (int)$x['id'], 'nombre' => $x['nombre'], 'liga' => $x['liga'], 'url' => 'index.php?l=' . rawurlencode($x['slug']) . '&e=' . (int)$x['id']], $sigue)]);
}

$eq = fila('SELECT e.*, l.nombre AS liga FROM equipos e JOIN ligas l ON l.id = e.liga_id WHERE e.id = ? AND l.activa = 1', [(int)post('equipo_id')]);
if (!$eq) $responder(['ok' => false, 'error' => 'Equipo no encontrado.']);

if ($accion === 'seguir') {
    if (strlen(b64u_dec($p256dh)) !== 65 || strlen(b64u_dec($auth)) < 16) $responder(['ok' => false, 'error' => 'Suscripción no válida. Recargue la página.']);
    if (demasiados_intentos('push', 60, '-1 hour')) $responder(['ok' => false, 'error' => 'Demasiados intentos. Espere un rato.']);
    anotar_intento('push');
    q('INSERT INTO push_suscripciones (endpoint, p256dh, auth, ip) VALUES (?,?,?,?) ON CONFLICT(endpoint) DO UPDATE SET p256dh = excluded.p256dh, auth = excluded.auth, fallos = 0',
        [$endpoint, $p256dh, $auth, ip_cliente()]);
    $sid = (int)valor('SELECT id FROM push_suscripciones WHERE endpoint = ?', [$endpoint]);
    if ((int)valor('SELECT COUNT(*) FROM push_seguimientos WHERE suscripcion_id = ?', [$sid]) >= 30) $responder(['ok' => false, 'error' => 'Ya sigue a 30 equipos en este celular. Deje de seguir alguno.']);
    q('INSERT OR IGNORE INTO push_seguimientos (suscripcion_id, equipo_id) VALUES (?, ?)', [$sid, $eq['id']]);
    // Mensaje de prueba para que el hincha vea que funciona
    q('INSERT OR IGNORE INTO push_cola (suscripcion_id, titulo, cuerpo, url, clave) VALUES (?,?,?,?,?)', [$sid, '✅ Ahora sigue a ' . mb_substr($eq['nombre'], 0, 60),
        'Le avisaremos los goles, resultados y horarios de ' . $eq['nombre'] . ' (' . $eq['liga'] . ').', 'index.php?l=' . rawurlencode((string)valor('SELECT slug FROM ligas WHERE id = ?', [$eq['liga_id']])) . '&e=' . (int)$eq['id'],
        'bienvenida:' . $sid . ':' . $eq['id'] . ':' . date('YmdH')]);
    push_envio_al_terminar();
    $responder(['ok' => true, 'siguiendo' => true]);
}
if ($accion === 'dejar') {
    if ($fila_sub) {
        q('DELETE FROM push_seguimientos WHERE suscripcion_id = ? AND equipo_id = ?', [$fila_sub['id'], $eq['id']]);
        if ((int)valor('SELECT COUNT(*) FROM push_seguimientos WHERE suscripcion_id = ?', [$fila_sub['id']]) === 0) q('DELETE FROM push_suscripciones WHERE id = ?', [$fila_sub['id']]);
    }
    $responder(['ok' => true, 'siguiendo' => false]);
}
$responder(['ok' => false, 'error' => 'Acción no válida.'], 400);
