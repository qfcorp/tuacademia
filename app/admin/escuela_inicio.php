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
atender_ocultar_pasos($liga); // (10 nov 2026) «Ocultar» de Primeros pasos
$n_vencidos = (int)valor("SELECT COUNT(DISTINCT c.alumno_id) FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE c.liga_id = ? AND c.estado = 'pendiente' AND c.vence < ? AND a.estado = 'activo'", [$lid, $hoy]);
$con_entrenamientos = entrenamientos_activos($liga);
if ($con_entrenamientos) { // (v17) planificación de la próxima semana
    $fechas_sem = 0; $plan_sem = 0;
    foreach ($grupos as $g) { $fs = fechas_entrenamiento($g, $hoy, date('Y-m-d', strtotime('+6 days'))); $fechas_sem += count($fs);
        if ($fs) $plan_sem += (int)valor("SELECT COUNT(*) FROM esc_sesiones WHERE grupo_id = ? AND fecha BETWEEN ? AND ? AND estado IN ('planificada','dictada') AND (objetivo <> '' OR id IN (SELECT sesion_id FROM esc_sesion_ejercicios))", [$g['id'], $hoy, date('Y-m-d', strtotime('+6 days'))]); }
}
cabecera_admin(es_academia() ? 'Resumen de la academia' : 'Resumen de la escuela'); // (arreglos 14-oct)
// (10 nov 2026) Identidad gráfica común de Tukán: saludo, aviso, primeros pasos, cifras, tarjetas del día y accesos rápidos
echo tk_saludo(es_academia() ? 'Su academia de un vistazo: quién tiene clase hoy, pagos y lo que falta por hacer' : 'Su escuela de un vistazo: quién entrena hoy, pagos y lo que falta por hacer');
echo tk_tira_inicio($liga); // demo (días que quedan, manual, «Ver Mi plan») o la novedad sin ver
echo html_primeros_pasos($liga); // (v27) guía con barra de avance
?>
<div class="tk-cifras">
  <?= tk_cifra('Alumnos activos', (string)$activos, $pendientes ? $pendientes . ' inscripción(es) por aprobar' : count($grupos) . ' grupo(s)', 'programa') ?>
  <?= tk_cifra('Asistencia (30 días)', $asist['t'] ? round(100 * (int)$asist['v'] / (int)$asist['t']) . '%' : '—', 'Presentes y atrasos de los últimos 30 días') ?>
  <?php if ($con_entrenamientos): ?>
  <?= tk_cifra('Clases planificadas', $plan_sem . '/' . $fechas_sem, '<a href="admin.php?p=planificacion">Clases de la semana planificadas</a>', 'aviso') ?>
  <?php endif; ?>
  <?php if (puede('cobros')): ?>
  <?= tk_cifra('Cobrado este mes', '<span class="tk-dinero">' . dinero($cobrado_mes) . '</span>', 'Mensualidades y tienda de contado', 'ok') ?>
  <?= tk_cifra('Por cobrar', '<span class="tk-dinero">' . dinero($por_cobrar) . '</span>', $n_vencidos ? $n_vencidos . ' alumno(s) con pagos vencidos' : 'Nadie con pagos vencidos', 'mal') ?>
  <?php endif; ?>
</div>

<?php if ($pendientes && puede('alumnos')): ?><div class="tk-aviso aviso">📝 Hay <b><?= $pendientes ?></b> inscripción(es) en línea por aprobar. <a href="admin.php?p=alumnos&amp;estado=pendiente">Revisar</a></div><?php endif; ?>

<div class="tk-columnas">
  <section class="tk-tarjeta"><?= tk_cabeza('🏃 Hoy entrenan', 'Grupos con clase hoy y su asistencia', puede('alumnos') ? 'admin.php?p=grupos' : (puede('clases') ? 'admin.php?p=asistencia' : ''), puede('alumnos') ? 'Ver grupos y horarios' : 'Ver asistencia') ?>
    <?php if (!$hoy_grupos): ?>
      <div class="tk-vacio"><b><?= $grupos ? 'Hoy no hay grupos con entrenamiento.' : 'Todavía no tiene grupos.' ?></b><?= $grupos ? 'Los días de clase aparecen aquí con el botón para tomar asistencia.' : 'Cree sus grupos con sus días y horarios para ver aquí quién entrena.' ?><?php if (!$grupos && puede('alumnos', true)): ?><br><br><a class="tk-boton" href="admin.php?p=grupos">＋ Crear el primero</a><?php endif; ?></div>
    <?php endif; ?>
    <?php foreach ($hoy_grupos as $g): $tomada = fila("SELECT id FROM esc_sesiones WHERE grupo_id = ? AND fecha = ? AND estado = 'dictada'", [$g['id'], $hoy]);
        $plan_hoy = fila('SELECT * FROM esc_sesiones WHERE grupo_id = ? AND fecha = ?', [$g['id'], $hoy]); ?>
      <div class="tk-grupo-hoy">
        <b><?= e($g['nombre']) ?></b> <small><?= e(texto_horario($g)) ?><?= $g['sede'] ? ' · ' . e($g['sede']) : '' ?> · <?= (int)$g['n_alumnos'] ?> alumnos · <?= e($g['profesor'] ?? 'sin profesor') ?></small>
        <?php if ($plan_hoy && $plan_hoy['objetivo'] !== ''): ?><br><small>📝 <?= e($plan_hoy['objetivo']) ?> <?= chip_sesion($plan_hoy['estado']) ?></small><?php elseif ($con_entrenamientos && puede('clases', true)): ?><br><small><a href="admin.php?p=planificacion&amp;g=<?= (int)$g['id'] ?>&amp;fecha=<?= $hoy ?>">+ Planificar la clase de hoy</a></small><?php endif; ?>
        <?php if (puede('clases', true)): ?><div style="margin-top:6px"><a class="tk-boton chico <?= $tomada ? 'secundario' : '' ?>" href="admin.php?p=asistencia&amp;g=<?= (int)$g['id'] ?>&amp;fecha=<?= $hoy ?>"><?= $tomada ? '✓ Asistencia tomada (ver)' : '📋 Tomar asistencia' ?></a></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
  <?php if (puede('cobros')): ?>
  <section class="tk-tarjeta mal"><?= tk_cabeza('🔴 Pagos vencidos', 'Alumnos con mensualidades o cargos sin pagar', 'admin.php?p=cobros', 'Ver todos los pagos', 'peligro') ?>
    <?php if (!$vencidos): ?><div class="tk-vacio"><b>Nadie tiene pagos vencidos. ✓</b>Cuando un pago pase su fecha, aparece aquí con el botón para recordarlo por WhatsApp.</div><?php else: ?>
    <div class="tk-tabla-caja"><table class="tk-tabla tarjetas"><thead><tr><th>Alumno</th><th class="num">Debe</th><th>Desde</th><th>Acción</th></tr></thead><tbody>
      <?php foreach (array_slice($vencidos, 0, 6) as $v): $wa = telefono_wa($v['representante_telefono']); ?><tr><td data-titulo="Alumno"><a href="admin.php?p=alumno&amp;id=<?= (int)$v['id'] ?>&amp;s=pagos"><?= e(nombre_alumno($v)) ?></a></td><td data-titulo="Debe" class="num tk-dinero mal"><?= dinero($v['total']) ?></td><td data-titulo="Desde"><?= e(date('d/m', strtotime($v['desde']))) ?></td>
        <td data-titulo="Acción"><?php if ($wa): ?><a class="tk-boton ok chico" target="_blank" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola ' . $v['representante_nombre'] . ', le saludamos de ' . $liga['nombre'] . '. Le recordamos que la cuenta de ' . $v['nombres'] . ' tiene ' . dinero($v['total']) . ' pendiente(s).' . ($liga['datos_pago'] ? "\n\nDatos para pagar: " . $liga['datos_pago'] : '') . "\n\n¡Gracias!") ?>">📲 Recordar</a><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if ($n_vencidos > 6): ?><p class="tk-linea-dato"><a href="admin.php?p=cobros">… y <?= $n_vencidos - 6 ?> alumno(s) más en Pagos</a></p><?php endif; ?>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>

<?php if ($cumple || $lesionados): ?>
<div class="tk-columnas">
  <?php if ($cumple): ?><section class="tk-tarjeta info"><?= tk_cabeza('🎂 Cumpleaños esta semana', 'Alumnos que cumplen años en los próximos 7 días') ?>
    <?php foreach ($cumple as $c): ?><p class="tk-linea-dato"><a href="admin.php?p=alumno&amp;id=<?= (int)$c['id'] ?>"><?= e(nombre_alumno($c)) ?></a> · <?= e(date('d/m', strtotime($c['fecha_nac']))) ?> (cumple <?= (int)date('Y') - (int)substr($c['fecha_nac'], 0, 4) ?>)</p><?php endforeach; ?>
  </section><?php endif; ?>
  <?php if ($lesionados): ?><section class="tk-tarjeta aviso"><?= tk_cabeza('🩹 Lesionados', 'Alumnos que todavía no tienen el alta') ?>
    <?php foreach ($lesionados as $l): ?><p class="tk-linea-dato"><a href="admin.php?p=alumno&amp;id=<?= (int)$l['alumno_id'] ?>&amp;s=salud"><?= e(nombre_alumno($l)) ?></a> · <?= e($l['zona'] ?: $l['tipo']) ?> · desde <?= e(date('d/m', strtotime($l['fecha']))) ?><?= $l['dias_baja'] ? ' · ' . (int)$l['dias_baja'] . ' días' : '' ?></p><?php endforeach; ?>
  </section><?php endif; ?>
</div>
<?php endif; ?>

<?= html_accesos_rapidos() // (10 nov 2026) 3 a 6 botones a lo más usado ?>
<?php
pie_admin();
