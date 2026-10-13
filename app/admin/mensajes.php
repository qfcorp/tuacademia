<?php
/*
 * (TuAcademia) 📲 MENSAJES DE HOY — recordatorio de pensiones por WhatsApp «con un toque».
 * Lista de las familias con pensiones (u otros valores) que vencen en los próximos días o ya vencieron, con el mensaje listo.
 * «WhatsApp» abre el chat con el mensaje escrito; «Marcar como enviado» lo anota para que no se repita hoy.
 * No hay envío automático a los padres (hace falta WhatsApp oficial pagado): lo envía la persona de la academia desde su celular.
 */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$por = usuario()['nombre'];
if (!es_escuela($liga)) ir('admin.php');
generar_mensualidades($liga);
$ed = puede('cobros', true);

if (es_post()) {
    if (!$ed) throw new RuntimeException('Su perfil no puede marcar mensajes.');
    $acc = post('accion');
    if ($acc === 'enviado') {
        acad_marcar_enviado($liga, post('clave'), $por);
        if (post('ajax') === '1') { header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit; }
        flash('Marcado como enviado.');
    } elseif ($acc === 'deshacer') {
        q("DELETE FROM acad_mensajes WHERE liga_id = ? AND clave = ? AND fecha = ? AND tipo = 'pension'", [$lid, post('clave'), date('Y-m-d')]);
        flash('Listo: vuelve a la lista de pendientes.');
    } elseif ($acc === 'dias') {
        q('UPDATE ligas SET acad_dias_aviso = ? WHERE id = ?', [entero(post('dias'), 0, 15), $lid]);
        flash('Desde ahora se avisa ' . entero(post('dias'), 0, 15) . ' día(s) antes del vencimiento.');
    }
    ir('admin.php?p=mensajes');
}

$fams = acad_mensajes_del_dia($liga);
$pend = array_filter($fams, fn($f) => !$f['enviado']);
$hechos = array_filter($fams, fn($f) => (bool)$f['enviado']);
$sin_tel = array_filter($pend, fn($f) => $f['wa'] === '');
$hist = filas("SELECT fecha, COUNT(*) AS n FROM acad_mensajes WHERE liga_id = ? AND tipo = 'pension' AND fecha >= ? GROUP BY fecha ORDER BY fecha DESC LIMIT 7", [$lid, date('Y-m-d', strtotime('-30 days'))]);
cabecera_admin('Mensajes de hoy');
?>
<div class="fila-titulo"><h1>📲 Mensajes de hoy</h1><span class="suave"><?= e(acad_fecha_texto(date('Y-m-d'))) ?></span></div>
<p class="suave" style="margin-top:0">Pensiones y valores que vencen en los próximos <b><?= acad_dias_aviso($liga) ?></b> día(s) o que ya vencieron. Toque <b>WhatsApp</b>: se abre el chat con el mensaje escrito; envíelo y toque <b>Marcar como enviado</b>. Un mensaje por familia (si tiene varios hijos, van juntos).</p>

<div class="cifras">
  <div class="cifra"><b style="color:#d7263d"><?= count($pend) ?></b>Por enviar hoy</div>
  <div class="cifra"><b style="color:#1a9e5c"><?= count($hechos) ?></b>Enviados hoy</div>
  <div class="cifra"><b><?= dinero(array_sum(array_column($fams, 'total'))) ?></b>Por cobrar en la lista</div>
</div>

<?php if (!$fams): ?>
  <div class="tarjeta" style="text-align:center"><h2 style="margin:.2rem 0">✅ Hoy no hay que recordarle nada a nadie</h2><p class="suave">No hay pensiones por vencer en los próximos <?= acad_dias_aviso($liga) ?> días ni pensiones vencidas.</p></div>
<?php endif; ?>

<?php if ($pend): ?><h2>Por enviar (<?= count($pend) ?>)</h2><?php endif; ?>
<?php foreach ($pend as $f): $wa_url = $f['wa'] !== '' ? 'https://wa.me/' . $f['wa'] . '?text=' . rawurlencode($f['texto']) : ''; ?>
  <div class="tarjeta msj-familia" id="m-<?= e(md5($f['clave'])) ?>">
    <div class="fila-titulo"><h3 style="margin:0"><?= e($f['representante']) ?> <small class="suave">· <?= e(implode(', ', $f['alumnos'])) ?></small></h3>
      <span><?= $f['vencido'] ? '<span class="semaforo s-rojo">Vencido</span>' : '<span class="semaforo s-amarillo">Por vencer</span>' ?> <b><?= dinero($f['total']) ?></b></span></div>
    <?php if ($f['ultimo'] !== ''): ?><p class="suave" style="margin:.2rem 0">Último recordatorio: <?= e(fecha_corta($f['ultimo'])) ?></p><?php endif; ?>
    <details><summary class="suave">Ver el mensaje</summary><pre class="mensaje"><?= e($f['texto']) ?></pre></details>
    <div class="acciones" style="margin-top:8px">
      <?php if ($wa_url): ?><a class="btn btn-wa" target="_blank" rel="noopener" href="<?= e($wa_url) ?>" data-marcar="<?= e($f['clave']) ?>">💬 WhatsApp</a>
      <?php else: ?><span class="etiqueta roja">Sin celular del representante</span> <a class="btn btn-sec btn-chico" href="admin.php?p=alumno&amp;id=<?= (int)array_key_first($f['alumnos']) ?>&amp;s=datos">Agregar celular</a><?php endif; ?>
      <?php if ($ed): ?><form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="enviado"><input type="hidden" name="clave" value="<?= e($f['clave']) ?>"><button class="btn btn-sec">✓ Marcar como enviado</button></form><?php endif; ?>
      <a class="btn btn-sec btn-chico" href="admin.php?p=alumno&amp;id=<?= (int)array_key_first($f['alumnos']) ?>&amp;s=pagos">Cobrar</a>
    </div>
  </div>
<?php endforeach; ?>

<?php if ($hechos): ?>
<h2>Enviados hoy (<?= count($hechos) ?>)</h2>
<div class="tabla-scroll"><table><tr><th>Familia</th><th>Alumnos</th><th class="num">Valor</th><th>Envió</th><th></th></tr>
  <?php foreach ($hechos as $f): ?><tr><td><?= e($f['representante']) ?></td><td><?= e(implode(', ', $f['alumnos'])) ?></td><td class="num"><?= dinero($f['total']) ?></td><td><small><?= e($f['enviado']['enviado_por']) ?> · <?= e(substr($f['enviado']['creado'], 11, 5)) ?></small></td>
    <td><?php if ($ed): ?><form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="deshacer"><input type="hidden" name="clave" value="<?= e($f['clave']) ?>"><button class="btn btn-sec btn-chico">Deshacer</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</table></div>
<?php endif; ?>

<div class="rejilla-2" style="margin-top:16px">
  <?php if ($ed): ?>
  <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="dias">
    <h3 style="margin-top:0">⚙ ¿Con cuántos días de anticipación?</h3>
    <div class="acciones"><input type="number" name="dias" min="0" max="15" value="<?= acad_dias_aviso($liga) ?>" style="max-width:90px"> <span>días antes del vencimiento</span> <button class="btn btn-sec btn-chico">Guardar</button></div>
    <p class="ayuda">Las vencidas salen siempre. El mensaje incluye su DeUna y sus datos de transferencia (se ponen en <a href="admin.php?p=escuela_config#cobros-linea">Parámetros</a>).</p>
  </form>
  <?php endif; ?>
  <div class="tarjeta">
    <h3 style="margin-top:0">📅 Últimos días</h3>
    <?php if (!$hist): ?><p class="suave">Todavía no se han marcado mensajes.</p><?php else: ?><ul style="margin:0"><?php foreach ($hist as $x): ?><li><?= e(fecha_corta($x['fecha'])) ?>: <?= (int)$x['n'] ?> mensaje(s)</li><?php endforeach; ?></ul><?php endif; ?>
  </div>
</div>
<script>
// Al tocar «WhatsApp» se marca como enviado sin recargar (si la persona vuelve a la página, ya no aparece como pendiente)
document.querySelectorAll('a[data-marcar]').forEach(function (a) {
  a.addEventListener('click', function () {
    var f = new FormData(); f.append('csrf', <?= json_encode(csrf_token()) ?>); f.append('accion', 'enviado'); f.append('clave', a.getAttribute('data-marcar')); f.append('ajax', '1');
    fetch('admin.php?p=mensajes', { method: 'POST', body: f, credentials: 'same-origin' }).then(function () { var t = a.closest('.msj-familia'); if (t) { t.style.opacity = '.5'; a.textContent = '✓ Enviado'; } }).catch(function () {});
  });
});
</script>
<?php
pie_admin();
