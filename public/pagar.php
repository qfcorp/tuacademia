<?php
/*
 * (v12) PAGO CON TARJETA: pagar.php?t=CÓDIGO
 * Lo abren: la liga o escuela (su suscripción a TuAcademia), el delegado (estado de cuenta del equipo) y la familia (mensualidades).
 * Modo "prueba": solo el superadministrador puede aprobar o rechazar el pago simulado. Modo "plux": formulario de Plux.
 */
require __DIR__ . '/../app/bootstrap.php';
define('VISTA_PUBLICA', true);
csrf_verificar();

$pg = preg_match('/^[a-f0-9]{32}$/', get('t')) ? fila('SELECT * FROM pagos_linea WHERE token = ?', [get('t')]) : null;
if (!$pg) {
    http_response_code(404);
    cabecera_publica('Pago no encontrado');
    echo '<div class="tarjeta login"><h1>Pago no encontrado</h1><p>El enlace de pago no es válido o ya no existe.</p></div>';
    pie_publico();
    exit;
}
$liga = fila('SELECT * FROM ligas WHERE id = ?', [$pg['liga_id']]);
$datos = json_decode($pg['datos'], true) ?: [];
$volver = (string)($datos['volver'] ?? '');
if (!preg_match('#^(admin|delegado|familia|index)\.php(\?[A-Za-z0-9_=&%.-]*)?$#', $volver)) $volver = '';
contexto_auditoria(['usuario' => 'Pago en línea: ' . mb_substr($pg['pagador_nombre'], 0, 60), 'rol' => 'publico', 'liga_id' => (int)$pg['liga_id'], 'origen' => 'publico']);

// Modo prueba: el superadministrador simula la respuesta de la tarjeta
if (es_post() && in_array(post('accion'), ['prueba_aprobar', 'prueba_rechazar'], true)) {
    if (pasarela_modo() !== 'prueba' || !es_super()) {
        flash('Solo el superadministrador puede completar pagos de prueba.', 'error');
    } elseif ($pg['estado'] !== 'pendiente') {
        flash('Ese pago ya fue procesado.', 'error');
    } elseif (post('accion') === 'prueba_aprobar') {
        confirmar_pago_linea($pg, post('tipo') === 'debito' ? 'debito' : 'credito', 'PRUEBA-' . $pg['id'], 'Pago simulado (modo prueba) por ' . usuario()['email']);
        bitacora_evento('pagos', 'Aprobó un pago de PRUEBA N° ' . $pg['id'] . ' por ' . dinero($pg['total']), (int)$pg['liga_id'], (int)$pg['id']);
    } else {
        rechazar_pago_linea($pg, 'Rechazado (modo prueba)');
    }
    ir('pagar.php?t=' . $pg['token']);
}
$pg = fila('SELECT * FROM pagos_linea WHERE id = ?', [$pg['id']]);
$datos = json_decode($pg['datos'], true) ?: [];
$a_tuvocalia = $pg['destino'] === 'tuvocalia';
cabecera_publica('Pago con tarjeta · ' . ($a_tuvocalia ? marca() : $liga['nombre']), $a_tuvocalia ? null : $liga);
foreach (tomar_flash() as [$tipo_f, $msg_f]) echo '<div class="aviso aviso-' . e($tipo_f) . '">' . e($msg_f) . '</div>';
?>
<div class="tarjeta pago-caja">
  <h1 style="margin-top:0">💳 Pago con tarjeta</h1>
  <p class="suave" style="margin-top:0">Pago a <b><?= e($a_tuvocalia ? marca() . ' (' . ajuste('empresa') . ')' : $liga['nombre']) ?></b><?= $a_tuvocalia ? '' : ' · cobrado por ' . e(marca()) ?> · N° <?= (int)$pg['id'] ?></p>
  <p><b><?= e(nombre_concepto_pago($pg['concepto'])) ?>:</b> <?= e($pg['detalle']) ?></p>
  <table>
    <tr><td>Valor</td><td class="num"><?= dinero($pg['monto_base']) ?></td></tr>
    <?php if ((float)$pg['recargo'] > 0): ?><tr><td>Costo de la transacción con tarjeta</td><td class="num"><?= dinero($pg['recargo']) ?></td></tr><?php endif; ?>
    <tr><th>Total a pagar</th><th class="num" style="font-size:1.3rem"><?= dinero($pg['total']) ?></th></tr>
  </table>

  <?php if ($pg['estado'] === 'pagado'): ?>
    <div class="aviso aviso-ok" style="margin-top:12px;font-size:1.1rem">✅ <b>Pago aprobado</b> el <?= e(substr($pg['pagado_en'], 0, 16)) ?>. <?= e(str_starts_with($pg['aplicado'], 'ERROR') ? 'La organización revisará su pago.' : $pg['aplicado']) ?></div>
    <p class="acciones">
      <?php if ($pg['concepto'] === 'equipo' && !empty($datos['movimiento_id'])): ?><a class="btn btn-sec" target="_blank" href="delegado.php?recibo=<?= (int)$datos['movimiento_id'] ?>">🧾 Ver recibo</a><?php endif; ?>
      <?php if ($pg['concepto'] === 'escuela' && !empty($datos['recibo'])): ?><a class="btn btn-sec" target="_blank" href="familia.php?recibo=<?= (int)$datos['recibo'] ?>">🧾 Ver recibo</a><?php endif; ?>
      <?php if ($volver): ?><a class="btn" href="<?= e($volver) ?>">Volver</a><?php endif; ?>
    </p>
  <?php elseif ($pg['estado'] === 'rechazado'): ?>
    <div class="aviso aviso-error" style="margin-top:12px">❌ El pago no fue aprobado. Revise los datos de su tarjeta o intente con otra.</div>
    <?php if ($volver): ?><p><a class="btn" href="<?= e($volver) ?>">Volver e intentar de nuevo</a></p><?php endif; ?>
  <?php elseif (pasarela_modo() === 'prueba'): ?>
    <?php if (es_super()): ?>
      <div class="aviso aviso-info" style="margin-top:12px"><b>MODO PRUEBA</b> — no se cobra nada. Simule la respuesta de la tarjeta:</div>
      <form method="post" class="acciones"><?= csrf_campo() ?>
        <select name="tipo"><option value="credito">Tarjeta de crédito</option><option value="debito">Tarjeta de débito</option></select>
        <button class="btn" name="accion" value="prueba_aprobar">✅ Aprobar (prueba)</button>
        <button class="btn btn-peligro" name="accion" value="prueba_rechazar">❌ Rechazar (prueba)</button></form>
    <?php else: ?>
      <div class="aviso aviso-info" style="margin-top:12px">El pago con tarjeta está en pruebas y todavía no se puede completar. Por ahora pague por transferencia y envíe su comprobante.</div>
    <?php endif; ?>
  <?php elseif (pasarela_modo() === 'plux'): ?>
    <?= plux_html_pago($pg) ?>
  <?php else: ?>
    <div class="aviso aviso-info" style="margin-top:12px">El pago con tarjeta no está disponible en este momento.</div>
  <?php endif; ?>
  <p class="ayuda" style="margin-top:14px">🔒 Los datos de su tarjeta los procesa Plux (pasarela autorizada en Ecuador); <?= e(marca()) ?> no los guarda.<?= $a_tuvocalia ? '' : ' ' . e(marca()) . ' recibe el pago y lo transfiere a ' . e($liga['nombre']) . '.' ?></p>
</div>
<?php
pie_publico();
