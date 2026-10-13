<?php
/*
 * (TuAcademia) RESPUESTA DE PAYPHONE — a esta dirección vuelve el padre después de pagar con tarjeta:
 *   payphone.php?id=NNN&clientTransactionId=TA12-abcd1234
 * Se le pregunta a Payphone si el pago fue aprobado (y por cuánto). Si coincide, se cobran los valores y sale el recibo.
 * En el panel de Payphone (Desarrolladores → su aplicación) la «URL de respuesta» debe ser: https://SU-DOMINIO/payphone.php
 */
require __DIR__ . '/../app/bootstrap.php';
define('VISTA_PUBLICA', true);

$id = (int)get('id');
$tx = get('clientTransactionId');
$estado = 'error';
$recibo = null;
$liga = null;
try {
    if (!$id || $tx === '') throw new RuntimeException('Faltan datos del pago.');
    [$estado, $recibo, $lid] = acad_payphone_confirmar($id, $tx);
    $liga = fila('SELECT * FROM ligas WHERE id = ?', [$lid]);
} catch (RuntimeException $e) {
    $estado = 'error';
}
unset($_SESSION['payphone_caja']);
cabecera_publica('Pago con tarjeta', $liga);
?>
<div class="tarjeta login" style="text-align:center">
  <?php if ($estado === 'aprobado'): ?>
    <div class="estado-grande" style="background:#1a9e5c">✓ PAGO APROBADO</div>
    <p>¡Gracias! Su pago se registró<?= $recibo ? ' con el recibo <b>N° ' . str_pad((string)$recibo, 6, '0', STR_PAD_LEFT) . '</b>' : '' ?>.</p>
  <?php elseif ($estado === 'cancelado'): ?>
    <div class="estado-grande" style="background:#6b7572">PAGO CANCELADO</div>
    <p>No se cobró nada. Puede intentarlo otra vez o pagar por DeUna o transferencia.</p>
  <?php elseif ($estado === 'no_aprobado'): ?>
    <div class="estado-grande" style="background:#d7263d">✗ PAGO NO APROBADO</div>
    <p>La tarjeta no aprobó el pago. Puede intentarlo con otra tarjeta o pagar por DeUna o transferencia.</p>
  <?php else: ?>
    <div class="estado-grande" style="background:#d7263d">No pudimos confirmar el pago</div>
    <p>Si le debitaron, escriba a la academia con la captura de su pago.</p>
  <?php endif; ?>
  <p><a class="btn btn-grande" href="familia.php#pagar">← Volver al portal de padres</a></p>
</div>
<?php
pie_publico();
