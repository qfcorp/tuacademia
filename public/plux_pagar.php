<?php
/*
 * (v13) PAGO CON TARJETA POR PLUX — recibe del navegador la tarjeta YA CIFRADA (AES + RSA) y la envía a Plux.
 * Responde JSON: success · otp_required · 3ds_required · failed. No guarda ningún dato de la tarjeta.
 */
require __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$fin = function (array $r): void { echo json_encode($r, JSON_UNESCAPED_UNICODE); exit; };

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $fin(['status' => 'failed', 'message' => 'Método no permitido.']);
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in) || !is_string($in['csrf'] ?? null) || !hash_equals(csrf_token(), $in['csrf'])) {
    $fin(['status' => 'failed', 'message' => 'La página venció. Recárguela e intente de nuevo.']);
}
$pg = preg_match('/^[a-f0-9]{32}$/', get('t')) ? fila('SELECT * FROM pagos_linea WHERE token = ?', [get('t')]) : null;
if (!$pg) $fin(['status' => 'failed', 'message' => 'Pago no encontrado.']);
if ($pg['estado'] === 'pagado') $fin(['status' => 'success']);
if ($pg['estado'] !== 'pendiente') $fin(['status' => 'failed', 'message' => 'Este pago ya no se puede completar. Vuelva a generarlo.']);
if ($pg['destino'] === 'organizacion' && !modulo_activo(fila('SELECT * FROM ligas WHERE id = ?', [$pg['liga_id']]), 'pagos_tarjeta')) $fin(['status' => 'failed', 'message' => 'El pago con tarjeta no está disponible en este momento.']); // (Anexo B)
if (pasarela_modo() !== 'plux' || !plux_listo() || !plux_configurado()) $fin(['status' => 'failed', 'message' => 'El pago con tarjeta no está disponible en este momento.']);
if (demasiados_intentos('plux', 20)) $fin(['status' => 'failed', 'message' => 'Demasiados intentos de pago seguidos. Espere una hora o comuníquese con la organización.']);
// (v24) Autorización de uso de datos del que paga (se registra una sola vez por pago)
if (!isset($in['paramsOtp']) && ($in['acepta_datos'] ?? false) !== true) $fin(['status' => 'failed', 'message' => 'Marque la casilla de autorización de uso de sus datos para procesar el pago.']);
anotar_intento('plux');
contexto_auditoria(['usuario' => 'Pago en línea: ' . mb_substr($pg['pagador_nombre'], 0, 60), 'rol' => 'publico', 'liga_id' => (int)$pg['liga_id'], 'origen' => 'publico']);

$clave = (string)($in['simetricKeyCifrada'] ?? '');
if (!preg_match('#^[A-Za-z0-9+/=]{40,1200}$#', $clave)) $fin(['status' => 'failed', 'message' => 'No se pudo preparar el pago. Recargue la página e intente de nuevo.']);
try {
    $body = plux_cuerpo_pago($pg, $in);
} catch (RuntimeException $e) {
    $fin(['status' => 'failed', 'message' => $e->getMessage()]);
}
// Datos del que paga (para el reporte), si no los teníamos
q('UPDATE pagos_linea SET pagador_cedula = CASE WHEN pagador_cedula = \'\' THEN ? ELSE pagador_cedula END, pagador_email = CASE WHEN pagador_email = \'\' THEN ? ELSE pagador_email END WHERE id = ?',
    [mb_substr($body['buyer']['documentNumber'], 0, 20), mb_substr($body['buyer']['email'], 0, 120), $pg['id']]);
if (!isset($in['paramsOtp']) && !valor("SELECT id FROM consentimientos WHERE ref_tabla = 'pagos_linea' AND ref_id = ?", [$pg['id']])) {
    registrar_consentimiento('pago', $pg['destino'] === 'tuvocalia' ? null : (int)$pg['liga_id'], trim($body['buyer']['firstName'] . ' ' . $body['buyer']['lastName']), 'titular',
        ['ref_tabla' => 'pagos_linea', 'ref_id' => (int)$pg['id'], 'documento' => (string)$body['buyer']['documentNumber'], 'texto' => texto_autorizacion_pago()]);
}
$_SESSION['plux_token'] = $pg['token'];
$r = plux_llamar(PLUX_RUTA_PAGO, $body, $clave);
unset($body);
$origen = (ajuste('plux_ambiente') === 'produccion' ? '' : '[SANDBOX] ') . 'Plux' . (isset($in['paramsOtp']) ? ' con código OTP' : '');
$fin(plux_procesar_respuesta($pg, $r, $origen));
