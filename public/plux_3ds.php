<?php
/*
 * (v13) REGRESO DEL BANCO DESPUÉS DE LA VALIDACIÓN 3DS (Plux lo llama por GET con pti, pcc y ptk, sin autenticación).
 * Confirma con Plux y aplica el pago. El pago se reconoce por la sesión del navegador (o, si se perdió, por la IP y el
 * último pago que quedó esperando 3DS).
 */
require __DIR__ . '/../app/bootstrap.php';
define('VISTA_PUBLICA', true);

$pti = mb_substr(trim((string)get('pti')), 0, 500);
$pcc = mb_substr(trim((string)get('pcc')), 0, 500);
$ptk = mb_substr(trim((string)get('ptk')), 0, 2000);
$token = (string)($_SESSION['plux_token'] ?? '');
$pg = preg_match('/^[a-f0-9]{32}$/', $token) ? fila('SELECT * FROM pagos_linea WHERE token = ?', [$token]) : null;
if (!$pg) {
    $pg = fila("SELECT * FROM pagos_linea WHERE estado = 'pendiente' AND ip = ? AND respuesta LIKE 'Esperando validación 3DS%' AND creado >= datetime('now', 'localtime', '-2 days') ORDER BY id DESC LIMIT 1", [ip_cliente()]);
}

$pagina = function (bool $ok, string $titulo, string $texto): void {
    cabecera_publica($titulo);
    echo '<div class="tarjeta login"><h1>' . ($ok ? '✅ ' : '❌ ') . e($titulo) . '</h1><p>' . e($texto) . '</p><p><a class="btn" href="index.php">Ir al inicio</a></p></div>';
    pie_publico();
    exit;
};

if (demasiados_intentos('plux3ds', 30)) $pagina(false, 'Demasiados intentos', 'Espere unos minutos e intente de nuevo.');
anotar_intento('plux3ds');
if ($pti === '' || $pcc === '' || $ptk === '') {
    if ($pg) { flash('La respuesta del banco llegó incompleta. Intente de nuevo o use otra tarjeta.', 'error'); ir('pagar.php?t=' . $pg['token']); }
    $pagina(false, 'Pago no completado', 'La respuesta del banco llegó incompleta.');
}
if ($pg && $pg['estado'] === 'pagado') ir('pagar.php?t=' . $pg['token']);
if ($pg) contexto_auditoria(['usuario' => 'Pago en línea: ' . mb_substr($pg['pagador_nombre'], 0, 60), 'rol' => 'publico', 'liga_id' => (int)$pg['liga_id'], 'origen' => 'publico']);

$r = plux_llamar(PLUX_RUTA_3DS, ['pti' => $pti, 'pcc' => $pcc, 'ptk' => $ptk]);
if (!$pg) {
    // Aprobado por el banco pero no sabemos de qué pago: queda en la bitácora para que el superadministrador lo revise
    if ((int)($r['code'] ?? -1) === 0) {
        bitacora_evento('pagos', 'Plux aprobó un pago 3DS que no se pudo relacionar (revisar en Cobros): ' . mb_substr(json_encode($r, JSON_UNESCAPED_UNICODE), 0, 1500));
        $pagina(true, 'Pago aprobado por su banco', 'Su pago fue aprobado. Para que se aplique a su cuenta, envíe una captura de esta pantalla a la organización.');
    }
    $pagina(false, 'Pago no completado', 'El banco no aprobó la transacción.');
}
$res = plux_procesar_respuesta($pg, $r, (ajuste('plux_ambiente') === 'produccion' ? '' : '[SANDBOX] ') . 'Plux con validación 3DS');
unset($_SESSION['plux_token']);
if ($res['status'] !== 'success') {
    flash('El banco no aprobó la transacción' . (!empty($res['message']) ? ': ' . $res['message'] : '') . '. Puede intentar de nuevo o con otra tarjeta.', 'error');
}
ir('pagar.php?t=' . $pg['token']);
