<?php
/* (v17) Ficha del alumno → pestaña 🏆 Club (divisiones formativas): partidos y minutos, maduración, valoración del
 * cuerpo técnico, seguimiento escolar y notas por área. Se incluye desde alumno.php ($a, $aid, $liga, $lid, $url). */
$ed_club = puede('formativas', true);
$anio = date('Y-01-01');
$rp = resumen_partidos_alumno($aid, $anio);
$ult_p = filas('SELECT p.*, pj.titular, pj.minutos, pj.goles, pj.asistencias, pj.calificacion, pj.nota, pj.amarillas, pj.rojas FROM esc_partido_jugadores pj JOIN esc_partidos p ON p.id = pj.partido_id
                WHERE pj.alumno_id = ? AND pj.convocado = 1 AND p.goles_favor IS NOT NULL ORDER BY p.fecha DESC LIMIT 10', [$aid]);
// (v21) Etiquetas según el deporte del grupo principal (goles / puntos)
$dep_club = deporte_grupo($a['grupo_id'] ? fila('SELECT * FROM esc_grupos WHERE id = ?', [(int)$a['grupo_id']]) : null, $liga);
$et_club = etiquetas_partido(familia_deporte($dep_club));
$ic_club = icono_deporte_escuela($dep_club);
$med = medicion_maduracion($aid);
$mad = maduracion($a, $med);
$vel = velocidad_crecimiento($aid);
$ult_talla = valor('SELECT talla FROM esc_mediciones WHERE alumno_id = ? AND talla > 0 ORDER BY fecha DESC LIMIT 1', [$aid]);
$adulta = estatura_adulta($a, $ult_talla !== null ? (float)$ult_talla : null);
$vals = filas('SELECT * FROM esc_valoraciones WHERE alumno_id = ? ORDER BY fecha DESC, id DESC', [$aid]);
$acad = filas('SELECT * FROM esc_academico WHERE alumno_id = ? ORDER BY id DESC', [$aid]);
$u = usuario();
$ve_privada = fn($n) => !(int)$n['privada'] || in_array(perfil_actual(), ['admin', 'super'], true) || $n['registrado_por'] === $u['nombre'] || ($u['area'] ?? '') === $n['area'];
$area_f = array_key_exists(get('area'), areas_staff()) ? get('area') : '';
$notas = array_values(array_filter(filas('SELECT * FROM esc_staff_notas WHERE alumno_id = ?' . ($area_f ? ' AND area = ?' : '') . ' ORDER BY fecha DESC, id DESC LIMIT 60', $area_f ? [$aid, $area_f] : [$aid]), $ve_privada));
?>
<div class="cifras">
  <div class="cifra"><b><?= (int)$rp['pj'] ?></b>Partidos jugados <?= e(date('Y')) ?></div>
  <div class="cifra"><b><?= (int)$rp['tit'] ?></b>Como titular</div>
  <div class="cifra"><b><?= number_format((int)$rp['min'], 0, ',', '.') ?></b>Minutos jugados</div>
  <div class="cifra"><b><?= (int)$rp['goles'] ?> / <?= (int)$rp['asis'] ?></b><?= e($et_club['g'] === 'Pts' ? 'Puntos' : 'Goles') ?> / asistencias</div>
  <div class="cifra"><b><?= $rp['cal'] !== null ? fmt_num($rp['cal'], 1) : '—' ?></b>Calificación promedio (1–10)</div>
</div>
<div class="rejilla-2">
  <div class="tarjeta"><h3 style="margin-top:0"><?= $ic_club ?> Minutos en los últimos partidos</h3>
    <?= svg_barras(array_map(fn($p) => [date('d/m', strtotime($p['fecha'])), (int)$p['minutos'], (int)$p['minutos'] . "'", (int)$p['titular'] ? 'var(--acento)' : '#8a9591'], array_reverse($ult_p)), ['max' => max(70, ...array_map(fn($p) => (int)$p['duracion'], $ult_p ?: [['duracion' => 70]])), 'vacio' => 'Todavía no tiene partidos registrados.']) ?>
    <?php if ($ult_p): ?><?= leyenda_grafico([['nombre' => 'Titular', 'color' => 'var(--acento)'], ['nombre' => 'Suplente', 'color' => '#8a9591']]) ?><?php endif; ?>
    <p style="margin:.4rem 0 0"><a href="admin.php?p=formativas&amp;ver=partidos<?= $a['grupo_id'] ? '&amp;g=' . (int)$a['grupo_id'] : '' ?>">Ver todos los partidos de la categoría →</a></p></div>
  <div class="tarjeta"><h3 style="margin-top:0">📋 Últimos partidos</h3>
    <?php if (!$ult_p): ?><p class="suave">Sin partidos.</p><?php endif; ?>
    <table><?php foreach (array_slice($ult_p, 0, 6) as $p): ?><tr><td><?= e(fecha_corta($p['fecha'])) ?></td><td>vs <?= e($p['rival']) ?><br><small class="suave"><?= e($p['competencia']) ?> · <?= e(texto_resultado_partido($p)) ?></small></td>
      <td class="num"><?= (int)$p['minutos'] ?>'<?= (int)$p['titular'] ? ' <small>T</small>' : '' ?></td><td><?= $et_club['g'] === 'Pts' ? ((int)$p['goles'] ? (int)$p['goles'] . ' pts' : '') : str_repeat('⚽', min(5, (int)$p['goles'])) ?><?= (int)$p['asistencias'] ? ' 🅰×' . (int)$p['asistencias'] : '' ?><?= (int)$p['amarillas'] ? ' 🟨' : '' ?><?= (int)$p['rojas'] ? ' 🟥' : '' ?></td>
      <td class="num"><?= $p['calificacion'] !== null ? '<b>' . fmt_num($p['calificacion'], 1) . '</b>' : '' ?></td></tr><?php endforeach; ?></table></div>
</div>

<div class="tarjeta">
  <h3 style="margin-top:0">🌱 Maduración y crecimiento</h3>
  <?php if ($mad): $pos = max(2, min(98, 50 + $mad['offset'] / 3 * 50)); ?>
    <p style="margin:.2rem 0"><span class="semaforo <?= e($mad['clase']) ?>"><?= e($mad['nombre']) ?></span>
      <b><?= $mad['offset'] >= 0 ? 'Pasó el pico hace ' . fmt_num($mad['offset'], 1) . ' años' : 'Faltan ' . fmt_num(abs($mad['offset']), 1) . ' años para el pico' ?></b> · pico estimado a los <b><?= fmt_num($mad['aphv'], 1) ?> años</b>
      <small class="suave">(medición del <?= e(fecha_corta($mad['fecha'])) ?>)</small></p>
    <div class="maduracion-barra"><i style="left:<?= round($pos, 1) ?>%"></i><small style="left:16%">Antes del estirón</small><small style="left:50%">Pico de crecimiento</small><small style="left:84%">Después</small></div>
    <p class="nota-profe">💡 <?= e($mad['consejo']) ?></p>
  <?php else: ?>
    <p class="suave">Para estimar la maduración (pico de crecimiento) registre en <a href="<?= e($url) ?>&amp;s=medidas">Medidas</a> la <b>talla</b>, la <b>talla sentado</b> y el <b>peso</b>, y en Datos la <b>fecha de nacimiento</b> y el <b>género</b> (fórmula de Mirwald, para 8 a 18 años).</p>
  <?php endif; ?>
  <div class="cifras" style="margin:10px 0 0">
    <div class="cifra"><b><?= $vel !== null ? fmt_num($vel, 1) : '—' ?></b>cm por año (velocidad de crecimiento)<?= $vel !== null && $vel >= 7 ? '<br><span class="semaforo s-amarillo">crecimiento acelerado</span>' : '' ?></div>
    <div class="cifra"><b><?= $adulta ? fmt_num($adulta['pred'], 1) . ' cm' : '—' ?></b>Estatura adulta estimada<?= $adulta && $adulta['pct'] ? '<br><small>ya tiene el ' . fmt_num($adulta['pct'], 1) . '%</small>' : '' ?></div>
  </div>
  <?php if ($ed_club): ?>
  <form method="post" class="acciones" style="align-items:flex-end;margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="padres_talla">
    <div><label>Estatura del padre (cm)</label><input type="number" name="talla_padre" step="0.1" min="120" max="230" value="<?= e((string)($a['talla_padre'] ?? '')) ?>"></div>
    <div><label>Estatura de la madre (cm)</label><input type="number" name="talla_madre" step="0.1" min="120" max="230" value="<?= e((string)($a['talla_madre'] ?? '')) ?>"></div>
    <button class="btn btn-sec btn-chico">Guardar</button></form>
  <p class="suave" style="font-size:.8rem">Son estimaciones orientativas (±1 año en la maduración, ±8 cm en la estatura). Sirven para ajustar las cargas y no descartar a un chico que madura más tarde.</p>
  <?php endif; ?>
</div>

<div class="rejilla-2">
  <div class="tarjeta"><h3 style="margin-top:0">🧭 Valoración del cuerpo técnico</h3>
    <?php if ($v = $vals[0] ?? null): [$pl, $pn, $pc] = potenciales()[$v['potencial']] ?? ['B', '', 's-gris']; ?>
      <p style="margin:.2rem 0"><span class="semaforo <?= $pc ?>" style="font-size:1rem">Potencial <?= e($pl) ?></span> <?= e($pn) ?> · <small class="suave"><?= e(fecha_corta($v['fecha'])) ?> · <?= e($v['registrado_por']) ?></small></p>
      <p style="margin:.3rem 0"><b>Posición:</b> <?= e($v['posicion'] ?: '—') ?><?= $v['posicion2'] ? ' (también ' . e($v['posicion2']) . ')' : '' ?><?= $v['perfil'] ? ' · ' . e($v['perfil']) : '' ?></p>
      <?php if ($v['fortalezas']): ?><p style="margin:.3rem 0"><b>✅ Fortalezas:</b> <?= e($v['fortalezas']) ?></p><?php endif; ?>
      <?php if ($v['mejorar']): ?><p style="margin:.3rem 0"><b>🎯 A mejorar:</b> <?= e($v['mejorar']) ?></p><?php endif; ?>
      <p class="nota-profe"><b>Recomendación:</b> <?= e(recomendaciones_valoracion()[$v['recomendacion']] ?? $v['recomendacion']) ?></p>
      <?php if (count($vals) > 1): ?><details><summary class="suave" style="cursor:pointer">Valoraciones anteriores (<?= count($vals) - 1 ?>)</summary><ul><?php foreach (array_slice($vals, 1) as $x): ?><li><?= e(fecha_corta($x['fecha'])) ?> · potencial <?= e($x['potencial']) ?> · <?= e(recomendaciones_valoracion()[$x['recomendacion']] ?? '') ?></li><?php endforeach; ?></ul></details><?php endif; ?>
    <?php else: ?><p class="suave">Sin valoraciones todavía.</p><?php endif; ?>
    <?php if ($ed_club): ?>
    <details style="margin-top:8px"><summary class="btn btn-sec btn-chico">+ Nueva valoración</summary>
      <form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="valoracion">
        <div class="campos">
          <div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
          <div><label>Posición principal</label><input type="text" name="posicion" maxlength="40" value="<?= e($a['posicion']) ?>" placeholder="Ej: volante mixto"></div>
          <div><label>Otra posición</label><input type="text" name="posicion2" maxlength="40"></div>
          <div class="campo-ancho"><label>Perfil de jugador</label><input type="text" name="perfil" maxlength="200" placeholder="Ej: zurdo, rápido, buen primer toque, le cuesta el juego aéreo"></div>
          <div class="campo-ancho"><label>Fortalezas</label><textarea name="fortalezas" rows="2" maxlength="600"></textarea></div>
          <div class="campo-ancho"><label>Aspectos a mejorar</label><textarea name="mejorar" rows="2" maxlength="600"></textarea></div>
          <div><label>Potencial</label><select name="potencial"><?php foreach (potenciales() as $k => [$l, $n]): ?><option value="<?= $k ?>" <?= $k === 'B' ? 'selected' : '' ?>><?= e($l . ' · ' . $n) ?></option><?php endforeach; ?></select></div>
          <div><label>Recomendación</label><select name="recomendacion"><?php foreach (recomendaciones_valoracion() as $k => $t): ?><option value="<?= $k ?>" <?= $k === 'mantener' ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
        </div>
        <button class="btn btn-sec">Guardar valoración</button></form></details>
    <?php endif; ?>
  </div>
  <div class="tarjeta"><h3 style="margin-top:0">🎒 Rendimiento escolar</h3>
    <?php if ($acad): ?>
      <?= count($acad) >= 2 ? svg_barras(array_map(fn($x) => [mb_substr($x['periodo'], 0, 12), (float)$x['promedio'], fmt_num($x['promedio'], 1), (float)$x['promedio'] >= 8 ? '#1a9e5c' : ((float)$x['promedio'] >= 7 ? '#e0a100' : '#d7263d')], array_reverse(array_slice($acad, 0, 6))), ['max' => 10, 'alto' => 140, 'linea' => 7, 'linea_txt' => 'mínimo 7']) : '' ?>
      <table><tr><th>Periodo</th><th class="num">Promedio</th><th class="num">Asist.</th><th>Conducta</th></tr>
        <?php foreach ($acad as $x): ?><tr><td><?= e($x['periodo']) ?><?= $x['materias_bajas'] ? '<br><small class="baja">Bajo en: ' . e($x['materias_bajas']) . '</small>' : '' ?><?= $x['observacion'] ? '<br><small class="suave">' . e($x['observacion']) . '</small>' : '' ?></td>
          <td class="num"><b><?= $x['promedio'] !== null ? fmt_num($x['promedio'], 2) : '—' ?></b></td><td class="num"><?= $x['asistencia'] !== null ? fmt_num($x['asistencia'], 0) . '%' : '—' ?></td><td><?= e($x['conducta']) ?></td></tr><?php endforeach; ?></table>
    <?php else: ?><p class="suave">Registre las notas del colegio: el club también cuida que el jugador estudie.</p><?php endif; ?>
    <?php if ($ed_club): ?>
    <details style="margin-top:8px"><summary class="btn btn-sec btn-chico">+ Registrar periodo</summary>
      <form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="academico">
        <div class="campos">
          <div><label>Periodo</label><input type="text" name="periodo" maxlength="60" required placeholder="Ej: 1er quimestre 2026"></div>
          <div><label>Promedio (sobre 10)</label><input type="number" name="promedio" step="0.01" min="0" max="10"></div>
          <div><label>Asistencia al colegio (%)</label><input type="number" name="asistencia" step="1" min="0" max="100"></div>
          <div><label>Conducta</label><select name="conducta"><option value=""></option><?php foreach (['A', 'B', 'C', 'D', 'E'] as $c): ?><option><?= $c ?></option><?php endforeach; ?></select></div>
          <div class="campo-ancho"><label>Materias bajas</label><input type="text" name="materias_bajas" maxlength="200"></div>
          <div class="campo-ancho"><label>Observación</label><input type="text" name="observacion" maxlength="400"></div>
        </div>
        <button class="btn btn-sec">Guardar</button></form></details>
    <?php endif; ?>
  </div>
</div>

<div class="tarjeta">
  <h3 style="margin-top:0">🗂 Notas del cuerpo técnico</h3>
  <nav class="pestanas"><a class="<?= $area_f === '' ? 'activa' : '' ?>" href="<?= e($url) ?>&amp;s=club">Todas</a><?php foreach (areas_staff() as $k => [$ic, $t]): ?><a class="<?= $area_f === $k ? 'activa' : '' ?>" href="<?= e($url) ?>&amp;s=club&amp;area=<?= $k ?>"><?= $ic ?> <?= e($t) ?></a><?php endforeach; ?></nav>
  <?php if (!$notas): ?><p class="suave">Sin notas<?= $area_f ? ' de esta área' : '' ?>.</p><?php endif; ?>
  <?php foreach ($notas as $n): [$ic, $t] = areas_staff()[$n['area']] ?? ['📝', $n['area']]; ?>
    <div class="meta-fila"><b><?= $ic ?> <?= e($t) ?></b> · <?= e(fecha_corta($n['fecha'])) ?> · <small class="suave"><?= e($n['registrado_por']) ?></small><?= (int)$n['privada'] ? ' <span class="etiqueta gris" title="Solo la ven la dirección y su área">🔒 confidencial</span>' : '' ?>
      <div style="white-space:pre-wrap;margin-top:3px"><?= e($n['texto']) ?></div>
      <?php if ($ed_club && ($n['registrado_por'] === $u['nombre'] || in_array(perfil_actual(), ['admin', 'super'], true))): ?><form method="post" class="inline" onsubmit="return confirm('¿Borrar la nota?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_staff_nota"><input type="hidden" name="nota_id" value="<?= (int)$n['id'] ?>"><button class="btn btn-sec btn-chico">Borrar</button></form><?php endif; ?></div>
  <?php endforeach; ?>
  <?php if ($ed_club): ?>
  <form method="post" style="margin-top:12px"><?= csrf_campo() ?><input type="hidden" name="accion" value="staff_nota">
    <div class="campos">
      <div><label>Área</label><select name="area"><?php foreach (areas_staff() as $k => [$ic, $t]): ?><option value="<?= $k ?>" <?= ($u['area'] ?? '') === $k || $area_f === $k ? 'selected' : '' ?>><?= $ic ?> <?= e($t) ?></option><?php endforeach; ?></select></div>
      <div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
      <div><label class="check" style="margin-top:26px"><input type="checkbox" name="privada" value="1"> 🔒 Confidencial (solo dirección y su área)</label></div>
      <div class="campo-ancho"><label>Nota</label><textarea name="texto" rows="2" maxlength="1500" required placeholder="Ej: Preparación física: mejoró la resistencia; bajar carga de saltos por dolor de rodilla."></textarea></div>
    </div>
    <button class="btn btn-sec">+ Agregar nota</button></form>
  <?php endif; ?>
</div>
