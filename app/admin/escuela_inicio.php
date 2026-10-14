<?php
/* (v11) RESUMEN DE LA ESCUELA (en TuAcademia: de la academia) */
$liga = requiere_liga();
$lid = (int)$liga['id'];
preparar_escuela($lid);
generar_mensualidades($liga);
$hoy = date('Y-m-d');
$activos = (int)valor("SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ? AND estado = 'activo'", [$lid]);
$pendientes = (int)valor("SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ? AND estado = 'pendiente'", [$lid]);
$mes = date('Y-m');
$cobrado_mes = (float)valor("SELECT COALESCE(SUM(monto - descuento), 0) FROM esc_cargos WHERE liga_id = ? AND estado = 'pagado' AND substr(pagado_en, 1, 7) = ?", [$lid, $mes])
    + (float)valor("SELECT COALESCE(SUM(total), 0) FROM esc_ventas WHERE liga_id = ? AND modo = 'contado' AND anulado = 0 AND substr(fecha, 1, 7) = ?", [$lid, $mes]);
$por_cobrar = (float)valor("SELECT COALESCE(SUM(monto - descuento), 0) FROM esc_cargos WHERE liga_id = ? AND estado = 'pendiente'", [$lid]);
$vencidos = filas("SELECT a.id, a.nombres, a.apellidos, a.representante_telefono, a.representante_nombre, COUNT(c.id) AS n, SUM(c.monto - c.descuento) AS total, MIN(c.vence) AS desde
                   FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE c.liga_id = ? AND c.estado = 'pendiente' AND c.vence < ? AND a.estado = 'activo'
                   GROUP BY a.id ORDER BY total DESC LIMIT 15", [$lid, $hoy]);
$asist = fila("SELECT COUNT(*) AS t, SUM(CASE WHEN x.estado IN ('P','T') THEN 1 ELSE 0 END) AS v FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id JOIN esc_grupos g ON g.id = s.grupo_id WHERE g.liga_id = ? AND s.fecha >= ?", [$lid, date('Y-m-d', strtotime('-30 days'))]);
$grupos = grupos_visibles($lid);
$dia = (int)date('N');
$hoy_grupos = array_filter($grupos, fn($g) => in_array((string)$dia, explode(',', (string)$g['dias']), true));
$cumple = filas("SELECT id, nombres, apellidos, fecha_nac FROM esc_alumnos WHERE liga_id = ? AND estado = 'activo' AND fecha_nac <> '' AND strftime('%m-%d', fecha_nac) BETWEEN ? AND ? ORDER BY strftime('%m-%d', fecha_nac)",
    [$lid, date('m-d'), date('m-d', strtotime('+7 days'))]);
$lesionados = filas("SELECT l.*, a.nombres, a.apellidos FROM esc_lesiones l JOIN esc_alumnos a ON a.id = l.alumno_id WHERE a.liga_id = ? AND l.alta = '' ORDER BY l.fecha DESC LIMIT 10", [$lid]);
cabecera_admin(es_academia() ? 'Resumen de la academia' : 'Resumen de la escuela'); // (arreglos 14-oct)
?>
<h1>🎓 <?= e($liga['nombre']) ?></h1>
<div class="cifras">
  <div class="cifra"><b><?= $activos ?></b>Alumnos activos</div>
  <div class="cifra"><b><?= $asist['t'] ? round(100 * (int)$asist['v'] / (int)$asist['t']) . '%' : '—' ?></b>Asistencia (30 días)</div>
  <?php if (entrenamientos_activos($liga)): // (v17) planificación de la próxima semana
      $fechas_sem = 0; $plan_sem = 0;
      foreach ($grupos as $g) { $fs = fechas_entrenamiento($g, $hoy, date('Y-m-d', strtotime('+6 days'))); $fechas_sem += count($fs);
          if ($fs) $plan_sem += (int)valor("SELECT COUNT(*) FROM esc_sesiones WHERE grupo_id = ? AND fecha BETWEEN ? AND ? AND estado IN ('planificada','dictada') AND (objetivo <> '' OR id IN (SELECT sesion_id FROM esc_sesion_ejercicios))", [$g['id'], $hoy, date('Y-m-d', strtotime('+6 days'))]); } ?>
  <div class="cifra"><b><?= $plan_sem ?>/<?= $fechas_sem ?></b><a href="admin.php?p=planificacion">Clases de la semana planificadas</a></div>
  <?php endif; ?>
  <?php if (puede('cobros')): ?>
  <div class="cifra"><b><?= dinero($cobrado_mes) ?></b>Cobrado este mes</div>
  <div class="cifra"><b><?= dinero($por_cobrar) ?></b>Por cobrar</div>
  <?php endif; ?>
</div>
<?= html_botones_inicio() // (v19) botones grandes con ícono ?>

<?= html_banner_novedades() // (v27) ?>
<?= html_primeros_pasos($liga) // (v27) guía con barra de avance ?>

<?php if ($pendientes && puede('alumnos')): ?><div class="aviso aviso-info">📝 Hay <b><?= $pendientes ?></b> inscripción(es) en línea por aprobar. <a href="admin.php?p=alumnos&amp;estado=pendiente">Revisar</a></div><?php endif; ?>

<div class="rejilla-2">
  <div>
    <h2>Hoy entrenan</h2>
    <?php if (!$hoy_grupos): ?><p class="suave">Hoy no hay grupos con entrenamiento.</p><?php endif; ?>
    <?php foreach ($hoy_grupos as $g): $tomada = fila("SELECT id FROM esc_sesiones WHERE grupo_id = ? AND fecha = ? AND estado = 'dictada'", [$g['id'], $hoy]);
        $plan_hoy = fila('SELECT * FROM esc_sesiones WHERE grupo_id = ? AND fecha = ?', [$g['id'], $hoy]); ?>
      <div class="tarjeta" style="padding:10px 12px;margin-bottom:8px">
        <b><?= e($g['nombre']) ?></b> <small class="suave"><?= e(texto_horario($g)) ?><?= $g['sede'] ? ' · ' . e($g['sede']) : '' ?> · <?= (int)$g['n_alumnos'] ?> alumnos · <?= e($g['profesor'] ?? 'sin profesor') ?></small>
        <?php if ($plan_hoy && $plan_hoy['objetivo'] !== ''): ?><br><small>📝 <?= e($plan_hoy['objetivo']) ?> <?= chip_sesion($plan_hoy['estado']) ?></small><?php elseif (entrenamientos_activos($liga) && puede('clases', true)): ?><br><small><a href="admin.php?p=planificacion&amp;g=<?= (int)$g['id'] ?>&amp;fecha=<?= $hoy ?>">+ Planificar la clase de hoy</a></small><?php endif; ?>
        <?php if (puede('clases', true)): ?><div style="margin-top:6px"><a class="btn btn-chico <?= $tomada ? 'btn-sec' : '' ?>" href="admin.php?p=asistencia&amp;g=<?= (int)$g['id'] ?>&amp;fecha=<?= $hoy ?>"><?= $tomada ? '✓ Asistencia tomada (ver)' : '📋 Tomar asistencia' ?></a></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if ($cumple): ?><h2>🎂 Cumpleaños esta semana</h2>
      <?php foreach ($cumple as $c): ?><p style="margin:.2rem 0"><a href="admin.php?p=alumno&amp;id=<?= (int)$c['id'] ?>"><?= e(nombre_alumno($c)) ?></a> · <?= e(date('d/m', strtotime($c['fecha_nac']))) ?> (cumple <?= (int)date('Y') - (int)substr($c['fecha_nac'], 0, 4) ?>)</p><?php endforeach; ?>
    <?php endif; ?>
  </div>
  <div>
    <?php if (puede('cobros')): ?>
    <h2>🔴 Pagos vencidos</h2>
    <?php if (!$vencidos): ?><p class="suave">Nadie tiene pagos vencidos. ✓</p><?php else: ?>
    <div class="tabla-scroll"><table><tr><th>Alumno</th><th class="num">Debe</th><th>Desde</th><th></th></tr>
      <?php foreach ($vencidos as $v): $wa = telefono_wa($v['representante_telefono']); ?><tr><td><a href="admin.php?p=alumno&amp;id=<?= (int)$v['id'] ?>&amp;s=pagos"><?= e(nombre_alumno($v)) ?></a></td><td class="num pts"><?= dinero($v['total']) ?></td><td><?= e(date('d/m', strtotime($v['desde']))) ?></td>
        <td><?php if ($wa): ?><a class="btn btn-sec btn-chico" target="_blank" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola ' . $v['representante_nombre'] . ', le saludamos de ' . $liga['nombre'] . '. Le recordamos que la cuenta de ' . $v['nombres'] . ' tiene ' . dinero($v['total']) . ' pendiente(s).' . ($liga['datos_pago'] ? "\n\nDatos para pagar: " . $liga['datos_pago'] : '') . "\n\n¡Gracias!") ?>">📲</a><?php endif; ?></td></tr><?php endforeach; ?>
    </table></div>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($lesionados): ?><h2>🩹 Lesionados</h2>
      <?php foreach ($lesionados as $l): ?><p style="margin:.2rem 0"><a href="admin.php?p=alumno&amp;id=<?= (int)$l['alumno_id'] ?>&amp;s=salud"><?= e(nombre_alumno($l)) ?></a> · <?= e($l['zona'] ?: $l['tipo']) ?> · desde <?= e(date('d/m', strtotime($l['fecha']))) ?><?= $l['dias_baja'] ? ' · ' . (int)$l['dias_baja'] . ' días' : '' ?></p><?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php
pie_admin();
