<?php
/*
 * (v27) EMBUDO DE DEMOS (solo superadministrador): solicitudes nuevas → demos activas (con su uso) → demos vencidas.
 * Desde aquí se da seguimiento por WhatsApp, se alarga la demo o se pasa a plan pagado.
 */
if (!es_super()) ir('admin.php');

if (es_post()) {
    $l = fila('SELECT * FROM ligas WHERE id = ? AND es_demo = 0', [(int)post('id')]) ?? throw new RuntimeException('Cuenta no encontrada.');
    if (post('accion') === 'alargar') {
        // (Tukán Central v2) misma función que el panel central: 1 a 90 días y NO pone en demo a una cuenta que ya paga
        $hasta = extender_prueba_cuenta($l, entero(post('dias'), 1, 90));
        flash('Demo de ' . $l['nombre'] . ' alargada hasta el ' . $hasta . '.');
    }
    ir('admin.php?p=embudo');
}

$hoy = date('Y-m-d');
$solicitudes = filas("SELECT * FROM solicitudes_demo WHERE estado = 'nueva' ORDER BY id DESC LIMIT 100");
$activas = filas("SELECT * FROM ligas WHERE es_prueba = 1 AND es_demo = 0 AND COALESCE(eliminada, '') = '' AND vence >= ? ORDER BY vence", [$hoy]); // (Anexo A) sin la papelera
$vencidas = filas("SELECT * FROM ligas WHERE es_prueba = 1 AND es_demo = 0 AND COALESCE(eliminada, '') = '' AND vence <> '' AND vence < ? AND vence >= ? ORDER BY vence DESC", [$hoy, date('Y-m-d', strtotime('-60 days'))]);
$convertidas = (int)valor("SELECT COUNT(DISTINCT liga_id) FROM pagos_suscripcion WHERE fecha >= ?", [date('Y-m-d', strtotime('-30 days'))]);
$n_sol30 = (int)valor('SELECT COUNT(*) FROM solicitudes_demo WHERE creado >= ?', [date('Y-m-d', strtotime('-30 days'))]);

$fila_cuenta = function (array $l, bool $vencida) {
    $s = estado_suscripcion($l);
    $uso = uso_cuenta($l);
    $wa = telefono_wa((string)$l['contacto_telefono']);
    $msg = mensaje_seguimiento_demo($l, $vencida ? -1 : (int)$s['dias']);
    ?>
    <tr>
      <td><b><?= e($l['nombre']) ?></b><br><small class="suave"><?= ($l['tipo'] ?? 'liga') === 'escuela' ? '🎓 escuela' : '🏆 liga' ?> · <?= e($l['ciudad']) ?></small></td>
      <td><?= e($l['contacto_nombre']) ?><br><small><?= e($l['contacto_telefono']) ?></small></td>
      <td><?= $vencida ? 'venció ' . e($l['vence']) : ((int)$s['dias'] === 0 ? '<b style="color:var(--roja)">vence hoy</b>' : 'quedan ' . (int)$s['dias'] . ' d') ?></td>
      <td><?= e($uso['texto']) ?><br><small class="suave">primeros pasos <?= e($uso['avance']) ?></small></td>
      <td><?= $uso['ultimo'] !== '' ? e(fecha_bonita($uso['ultimo'])) . ' ' . e(substr($uso['ultimo'], 11, 5)) : '<span class="etiqueta roja">nunca entró</span>' ?></td>
      <td class="acciones" style="white-space:nowrap">
        <?php if ($wa): ?><a class="btn btn-chico" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode($msg) ?>">💬 WhatsApp</a><?php endif; ?>
        <form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="alargar"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="dias" value="7"><button class="btn btn-sec btn-chico">+7 días</button></form>
        <a class="btn btn-sec btn-chico" href="admin.php?p=suscripciones&amp;liga=<?= (int)$l['id'] ?>">💵 Pasar a pagado</a>
        <a class="btn btn-sec btn-chico" href="admin.php?p=ligas&amp;entrar=<?= (int)$l['id'] ?>">Entrar</a>
      </td>
    </tr>
    <?php
};
cabecera_admin('Embudo de demos');
?>
<h1>📈 Embudo de demos</h1>
<div class="rejilla" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
  <div class="tarjeta dato"><small>Solicitudes (30 días)</small><b><?= $n_sol30 ?></b></div>
  <div class="tarjeta dato"><small>Sin atender</small><b><?= count($solicitudes) ?></b></div>
  <div class="tarjeta dato"><small>Demos activas</small><b><?= count($activas) ?></b></div>
  <div class="tarjeta dato"><small>Demos vencidas (60 días)</small><b><?= count($vencidas) ?></b></div>
  <div class="tarjeta dato"><small>Cuentas que pagaron (30 días)</small><b><?= $convertidas ?></b></div>
</div>
<?php $ea = estado_avisos_super(); if (!$ea['ok']): ?>
  <div class="aviso aviso-error">⚠️ Los avisos por Telegram de nuevas solicitudes y demos por vencer <b>no le están llegando</b>: <?= e(implode(' ', $ea['problemas'])) ?> <a href="admin.php?p=soporte#avisos">Ver detalle</a></div>
<?php endif; ?>
<p class="suave">El sistema le avisa por Telegram cuando alguien pide una demo, cuando a una demo le quedan 2 días (día 5) y el día que vence (día 7), con el mensaje de WhatsApp listo para enviar.</p>

<div class="tarjeta">
  <h2 style="margin-top:0">1 · Solicitudes sin atender (<?= count($solicitudes) ?>)</h2>
  <?php if (!$solicitudes): ?><p class="suave">No hay solicitudes nuevas.</p><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>Fecha</th><th>Organización</th><th>Contacto</th><th>Tipo</th><th></th></tr>
    <?php foreach ($solicitudes as $s): $wa = telefono_wa((string)$s['telefono']); ?>
      <tr><td><?= e(fecha_bonita($s['creado'])) ?></td><td><b><?= e($s['organizacion']) ?></b><br><small class="suave"><?= e($s['ciudad']) ?> · <?= e($s['tamano']) ?></small></td>
        <td><?= e($s['contacto']) ?><br><small><?= e($s['telefono']) ?> · <?= e($s['email']) ?></small></td><td><?= e($s['tipo']) ?></td>
        <td class="acciones" style="white-space:nowrap"><?php if ($wa): ?><a class="btn btn-chico" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola ' . $s['contacto'] . ', le saluda ' . ajuste('empresa') . ' de ' . marca() . '. Recibimos su solicitud de demo para ' . $s['organizacion'] . '. ¿Le parece si se la activo ahora y le explico en 10 minutos cómo funciona?') ?>">💬 WhatsApp</a><?php endif; ?>
          <a class="btn btn-sec btn-chico" href="admin.php?p=demos">Atender</a></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

<div class="tarjeta">
  <h2 style="margin-top:0">2 · Demos activas (<?= count($activas) ?>)</h2>
  <?php if (!$activas): ?><p class="suave">No hay demos activas.</p><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>Cuenta</th><th>Contacto</th><th>Demo</th><th>Lo que cargó</th><th>Último ingreso</th><th></th></tr>
    <?php foreach ($activas as $l) $fila_cuenta($l, false); ?>
  </table></div>
  <?php endif; ?>
</div>

<div class="tarjeta">
  <h2 style="margin-top:0">3 · Demos vencidas sin pagar (<?= count($vencidas) ?>)</h2>
  <?php if (!$vencidas): ?><p class="suave">No hay demos vencidas en los últimos 60 días.</p><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>Cuenta</th><th>Contacto</th><th>Demo</th><th>Lo que cargó</th><th>Último ingreso</th><th></th></tr>
    <?php foreach ($vencidas as $l) $fila_cuenta($l, true); ?>
  </table></div>
  <?php endif; ?>
</div>
<?php $refs = filas('SELECT r.*, a.nombre AS recomienda, b.nombre AS recomendada, b.es_prueba FROM referidos r JOIN ligas a ON a.id = r.liga_id JOIN ligas b ON b.id = r.referido_id ORDER BY r.id DESC LIMIT 100'); ?>
<div class="tarjeta">
  <h2 style="margin-top:0">🤝 Recomendaciones (referidos) · premio: <?= meses_premio_referido() ?> mes(es)</h2>
  <?php if (!$refs): ?><p class="suave">Todavía no hay cuentas recomendadas. Cada liga y escuela tiene su enlace en «Recomiende y gane».</p><?php else: ?>
  <div class="tabla-scroll"><table><tr><th>Recomendada</th><th>Por</th><th>Estado</th></tr>
    <?php foreach ($refs as $r): ?><tr><td><?= e($r['recomendada']) ?></td><td><?= e($r['recomienda']) ?></td><td><?= $r['estado'] === 'premiado' ? '🎁 pagó · premio ' . (int)$r['meses'] . ' mes(es)' : ($r['es_prueba'] ? 'en demo' : e($r['estado'])) ?></td></tr><?php endforeach; ?>
  </table></div><?php endif; ?>
</div>
<?php
pie_admin();
