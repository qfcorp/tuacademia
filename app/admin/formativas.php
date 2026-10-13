<?php
/*
 * (v17) 🏆 CLUB — DIVISIONES FORMATIVAS (se activa en Parámetros de la escuela)
 * Partidos con convocatoria y minutos, maduración (pico de crecimiento), notas del cuerpo técnico, valoraciones,
 * seguimiento del colegio y enfermería (lesiones con fases de regreso).
 */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$por = usuario()['nombre'];
if (!formativas_activas($liga)) {
    cabecera_admin('Club');
    echo '<h1>🏆 Divisiones formativas</h1><div class="tarjeta">Esta sección está apagada. Actívela en <a href="admin.php?p=escuela_config#formativas">Parámetros → Divisiones formativas</a>.</div>';
    pie_admin();
    return;
}
$grupos = grupos_visibles($lid);
$ids_g = array_map(fn($g) => (int)$g['id'], $grupos);
$gid = (int)get('g');
if ($gid && !in_array($gid, $ids_g, true)) $gid = 0;
$ver = in_array(get('ver'), ['partidos', 'maduracion', 'staff', 'valoraciones', 'academico', 'enfermeria'], true) ? get('ver') : 'partidos';
$ed = puede('formativas', true);
$url = fn(array $x = []) => 'admin.php?' . http_build_query(array_merge(['p' => 'formativas', 'ver' => $ver, 'g' => $gid ?: null], $x));
$en_grupos = sql_en_grupo('a', $ids_g);
$filtro_g = $gid ? sql_en_grupo('a', $gid) : $en_grupos;

if (es_post()) {
    $accion = post('accion');
    if (!$ed) throw new RuntimeException('Sin permiso.');
    if ($accion === 'partido') {
        $g = grupo_de_escuela((int)post('grupo_id'), $lid);
        if (trim(post('rival')) === '') throw new RuntimeException('Escriba el rival.');
        $d = [$g['id'], date('Y-m-d', strtotime(post('fecha') ?: 'today')), mb_substr(post('hora'), 0, 5), mb_substr(trim(post('rival')), 0, 80),
              in_array(post('competencia'), competencias_partido(), true) ? post('competencia') : 'Amistoso', post('condicion') === 'visitante' ? 'visitante' : 'local',
              mb_substr(post('lugar'), 0, 120), entero(post('duracion'), 20, 120)];
        if ((int)post('id')) {
            fila('SELECT id FROM esc_partidos WHERE id = ? AND liga_id = ?', [(int)post('id'), $lid]) ?? throw new RuntimeException('Partido no encontrado.');
            q('UPDATE esc_partidos SET grupo_id = ?, fecha = ?, hora = ?, rival = ?, competencia = ?, condicion = ?, lugar = ?, duracion = ? WHERE id = ?', array_merge($d, [(int)post('id')]));
            $pid = (int)post('id');
        } else {
            q('INSERT INTO esc_partidos (grupo_id, fecha, hora, rival, competencia, condicion, lugar, duracion, liga_id, registrado_por) VALUES (?,?,?,?,?,?,?,?,?,?)', array_merge($d, [$lid, $por]));
            $pid = (int)db()->lastInsertId();
            // Convoca por defecto a todo el grupo
            foreach (alumnos_grupo((int)$g['id']) as $a) q('INSERT OR IGNORE INTO esc_partido_jugadores (partido_id, alumno_id, convocado) VALUES (?,?,1)', [$pid, $a['id']]);
        }
        flash('Partido guardado. Ahora registre el resultado y los minutos de cada jugador.');
        ir('admin.php?p=formativas&ver=partidos&partido=' . $pid);
    }
    if ($accion === 'ficha') {
        $p = fila('SELECT * FROM esc_partidos WHERE id = ? AND liga_id = ?', [(int)post('partido_id'), $lid]) ?? throw new RuntimeException('Partido no encontrado.');
        if ($p['grupo_id']) grupo_de_escuela((int)$p['grupo_id'], $lid);
        $gf = post('goles_favor') === '' ? null : entero(post('goles_favor'), 0, 300);
        $gc = post('goles_contra') === '' ? null : entero(post('goles_contra'), 0, 300);
        $pdo = db();
        $pdo->beginTransaction();
        q('UPDATE esc_partidos SET goles_favor = ?, goles_contra = ?, notas = ?, sets = ? WHERE id = ?', [$gf, $gc, mb_substr(post('notas'), 0, 1000), mb_substr(trim(post('sets')), 0, 80), $p['id']]);
        foreach ((array)($_POST['j'] ?? []) as $aid => $x) {
            if (!is_array($x) || !fila('SELECT id FROM esc_alumnos WHERE id = ? AND liga_id = ?', [(int)$aid, $lid])) continue;
            $conv = !empty($x['convocado']) ? 1 : 0;
            $cal = ($x['calificacion'] ?? '') === '' ? null : max(1, min(10, round((float)str_replace(',', '.', $x['calificacion']), 1)));
            q('INSERT INTO esc_partido_jugadores (partido_id, alumno_id, convocado, titular, minutos, goles, asistencias, amarillas, rojas, calificacion, nota, rebotes, faltas) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
               ON CONFLICT(partido_id, alumno_id) DO UPDATE SET convocado = excluded.convocado, titular = excluded.titular, minutos = excluded.minutos, goles = excluded.goles,
               asistencias = excluded.asistencias, amarillas = excluded.amarillas, rojas = excluded.rojas, calificacion = excluded.calificacion, nota = excluded.nota,
               rebotes = excluded.rebotes, faltas = excluded.faltas',
                [$p['id'], (int)$aid, $conv, $conv && !empty($x['titular']) ? 1 : 0, $conv ? entero($x['minutos'] ?? 0, 0, 150) : 0, $conv ? entero($x['goles'] ?? 0, 0, 99) : 0,
                 $conv ? entero($x['asistencias'] ?? 0, 0, 50) : 0, $conv ? entero($x['amarillas'] ?? 0, 0, 2) : 0, $conv ? entero($x['rojas'] ?? 0, 0, 1) : 0, $conv ? $cal : null, mb_substr((string)($x['nota'] ?? ''), 0, 200),
                 $conv ? entero($x['rebotes'] ?? 0, 0, 50) : 0, $conv ? entero($x['faltas'] ?? 0, 0, 10) : 0]);
        }
        $pdo->commit();
        flash('Partido guardado.');
        ir('admin.php?p=formativas&ver=partidos&partido=' . (int)$p['id']);
    }
    if ($accion === 'borrar_partido') {
        q('DELETE FROM esc_partidos WHERE id = ? AND liga_id = ?', [(int)post('partido_id'), $lid]);
        flash('Partido borrado.');
        ir('admin.php?p=formativas&ver=partidos');
    }
}

cabecera_admin('Club');
$tabs = ['partidos' => '🏟 Partidos y minutos', 'maduracion' => '🌱 Maduración', 'valoraciones' => '🧭 Valoraciones', 'staff' => '🗂 Cuerpo técnico', 'academico' => '🎒 Colegio', 'enfermeria' => '🩹 Enfermería'];
?>
<h1>🏆 Club · divisiones formativas</h1>
<nav class="pestanas"><?php foreach ($tabs as $k => $t): ?><a class="<?= $ver === $k ? 'activa' : '' ?>" href="admin.php?p=formativas&amp;ver=<?= $k ?><?= $gid ? '&amp;g=' . $gid : '' ?>"><?= e($t) ?></a><?php endforeach; ?></nav>
<?php if (!get('partido')): ?>
<form method="get" class="acciones" style="margin-bottom:10px"><input type="hidden" name="p" value="formativas"><input type="hidden" name="ver" value="<?= e($ver) ?>">
  <select name="g" onchange="this.form.submit()"><option value="">Todas las categorías</option><?= opciones_grupos($grupos, $gid ?: null, false) ?></select></form>
<?php endif; ?>
<?php
/* ------------------------------------------------------------ PARTIDOS */
if ($ver === 'partidos' && get('partido') !== ''):
    $p = fila('SELECT p.*, g.nombre AS grupo FROM esc_partidos p LEFT JOIN esc_grupos g ON g.id = p.grupo_id WHERE p.id = ? AND p.liga_id = ?', [(int)get('partido'), $lid]) ?? throw new RuntimeException('Partido no encontrado.');
    if ($p['grupo_id'] && !in_array((int)$p['grupo_id'], $ids_g, true)) throw new RuntimeException('Ese partido no es de sus grupos.');
    $pj = [];
    foreach (filas('SELECT * FROM esc_partido_jugadores WHERE partido_id = ?', [$p['id']]) as $x) $pj[(int)$x['alumno_id']] = $x;
    $al = $p['grupo_id'] ? alumnos_grupo((int)$p['grupo_id']) : [];
    foreach (array_keys($pj) as $aid) if (!in_array($aid, array_map(fn($a) => (int)$a['id'], $al), true) && ($x = fila('SELECT * FROM esc_alumnos WHERE id = ?', [$aid]))) $al[] = $x;
    $ET = etiquetas_partido(familia_deporte(deporte_grupo($p['grupo_id'] ? fila('SELECT * FROM esc_grupos WHERE id = ?', [$p['grupo_id']]) : null, $liga))); ?>
  <p><a href="admin.php?p=formativas&amp;ver=partidos&amp;g=<?= (int)$p['grupo_id'] ?>">← Partidos</a></p>
  <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="ficha"><input type="hidden" name="partido_id" value="<?= (int)$p['id'] ?>">
    <h2 style="margin-top:0"><?= e($p['grupo'] ?? '') ?> vs <?= e($p['rival']) ?> <small class="suave"><?= e($p['competencia']) ?> · <?= e(fecha_bonita($p['fecha'])) ?><?= $p['hora'] ? ' ' . e($p['hora']) : '' ?> · <?= $p['condicion'] === 'local' ? 'Local' : 'Visitante' ?><?= $p['lugar'] ? ' · ' . e($p['lugar']) : '' ?></small></h2>
    <div class="acciones" style="align-items:flex-end"><div><label><?= e($ET['marc']) ?> nuestros</label><input type="number" name="goles_favor" min="0" max="99" value="<?= e((string)($p['goles_favor'] ?? '')) ?>" style="max-width:90px;font-size:1.3rem"></div>
      <b style="font-size:1.3rem;padding-bottom:8px">–</b><div><label><?= e($ET['marc']) ?> del rival</label><input type="number" name="goles_contra" min="0" max="99" value="<?= e((string)($p['goles_contra'] ?? '')) ?>" style="max-width:90px;font-size:1.3rem"></div>
      <?php if ($ET['sets']): ?><div><label>Detalle de sets</label><input type="text" name="sets" maxlength="80" placeholder="Ej: 25-20, 22-25, 15-10" value="<?= e($p['sets'] ?? '') ?>"></div><?php endif; ?>
      <span class="suave" style="padding-bottom:10px">Duración: <?= (int)$p['duracion'] ?> min</span></div>
    <h3>Jugadores</h3>
    <div class="ficha-partido cab"><span style="text-align:left">Jugador (✓ convocado · T titular)</span><span>Min</span><span><?= e($ET['g']) ?></span><span><?= e($ET['a']) ?></span><span class="opc"><?= e($ET['c3']) ?></span><span class="opc"><?= e($ET['c4']) ?></span><span>Nota 1-10</span><span class="opc">Comentario</span></div>
    <?php foreach ($al as $a): $aid = (int)$a['id']; $x = ($pj[$aid] ?? []) + ['convocado' => 0, 'titular' => 0, 'minutos' => 0, 'goles' => 0, 'asistencias' => 0, 'amarillas' => 0, 'rojas' => 0, 'rebotes' => 0, 'faltas' => 0, 'calificacion' => null, 'nota' => '']; ?>
      <div class="ficha-partido"><span><label class="check" style="font-weight:400;display:inline"><input type="checkbox" name="j[<?= $aid ?>][convocado]" value="1" <?= (int)$x['convocado'] ? 'checked' : '' ?>> <?= e(nombre_alumno($a)) ?></label>
          <label class="check" style="font-weight:400;display:inline" title="Titular"><input type="checkbox" name="j[<?= $aid ?>][titular]" value="1" <?= (int)$x['titular'] ? 'checked' : '' ?>> T</label></span>
        <input type="number" name="j[<?= $aid ?>][minutos]" min="0" max="150" value="<?= (int)$x['minutos'] ?: '' ?>" placeholder="0">
        <input type="number" name="j[<?= $aid ?>][goles]" min="0" max="20" value="<?= (int)$x['goles'] ?: '' ?>" placeholder="0">
        <input type="number" name="j[<?= $aid ?>][asistencias]" min="0" max="20" value="<?= (int)$x['asistencias'] ?: '' ?>" placeholder="0">
        <?php if ($ET['tarjetas']): ?>
        <input class="opc" type="number" name="j[<?= $aid ?>][amarillas]" min="0" max="2" value="<?= (int)$x['amarillas'] ?: '' ?>" placeholder="0">
        <input class="opc" type="number" name="j[<?= $aid ?>][rojas]" min="0" max="1" value="<?= (int)$x['rojas'] ?: '' ?>" placeholder="0">
        <?php else: ?>
        <input class="opc" type="number" name="j[<?= $aid ?>][rebotes]" min="0" max="50" value="<?= (int)$x['rebotes'] ?: '' ?>" placeholder="0">
        <input class="opc" type="number" name="j[<?= $aid ?>][faltas]" min="0" max="10" value="<?= (int)$x['faltas'] ?: '' ?>" placeholder="0">
        <?php endif; ?>
        <input type="number" name="j[<?= $aid ?>][calificacion]" min="1" max="10" step="0.5" value="<?= $x['calificacion'] !== null ? e(fmt_num($x['calificacion'], 1)) : '' ?>">
        <input class="opc" type="text" name="j[<?= $aid ?>][nota]" maxlength="200" value="<?= e($x['nota']) ?>"></div>
    <?php endforeach; ?>
    <p><label>Informe del partido (lo ve solo el cuerpo técnico)</label><textarea name="notas" rows="3" maxlength="1000"><?= e($p['notas']) ?></textarea></p>
    <?php if ($ed): ?><div class="acciones"><button class="btn btn-grande">💾 Guardar partido</button>
      <button type="button" class="btn btn-sec btn-chico" onclick="document.querySelectorAll('.ficha-partido input[name$=&quot;[convocado]&quot;]:checked').forEach(function(c){var m=c.closest('.ficha-partido').querySelector('input[name$=&quot;[minutos]&quot;]');if(!m.value)m.value=<?= (int)$p['duracion'] ?>;})">Todos los convocados jugaron completo</button></div><?php endif; ?>
  </form>
  <?php if ($ed): ?>
  <details class="tarjeta"><summary>✏️ Editar datos del partido</summary>
    <?php $pe = $p; require APP_DIR . '/admin/formativas_form_partido.php'; ?>
    <form method="post" onsubmit="return confirm('¿Borrar el partido?')" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_partido"><input type="hidden" name="partido_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sec btn-chico">🗑 Borrar partido</button></form>
  </details>
  <?php endif; ?>

<?php elseif ($ver === 'partidos'):
    $ps = filas('SELECT p.*, g.nombre AS grupo, (SELECT COUNT(*) FROM esc_partido_jugadores pj WHERE pj.partido_id = p.id AND pj.minutos > 0) AS jugaron FROM esc_partidos p LEFT JOIN esc_grupos g ON g.id = p.grupo_id
                 WHERE p.liga_id = ? AND ' . ($gid ? 'p.grupo_id = ' . $gid : ($ids_g ? 'p.grupo_id IN (' . implode(',', $ids_g) . ')' : '0')) . ' ORDER BY p.fecha DESC, p.hora DESC LIMIT 80', [$lid]);
    $jug = array_filter($ps, fn($p) => $p['goles_favor'] !== null);
    $gan = count(array_filter($jug, fn($p) => $p['goles_favor'] > $p['goles_contra'])); $emp = count(array_filter($jug, fn($p) => $p['goles_favor'] == $p['goles_contra'])); $per = count($jug) - $gan - $emp; ?>
  <div class="cifras">
    <div class="cifra"><b><?= count($jug) ?></b>Partidos jugados</div>
    <div class="cifra"><b><?= $gan ?>-<?= $emp ?>-<?= $per ?></b>Ganados · empatados · perdidos</div>
    <div class="cifra"><b><?= array_sum(array_column($jug, 'goles_favor')) ?>:<?= array_sum(array_column($jug, 'goles_contra')) ?></b>A favor : en contra</div>
    <div class="cifra"><b><?= count(array_filter($ps, fn($p) => $p['goles_favor'] === null)) ?></b>Por jugar</div>
  </div>
  <?php if ($gid):
      $min = filas("SELECT a.id, a.nombres, a.apellidos, COALESCE(SUM(pj.minutos), 0) AS m, COALESCE(SUM(pj.goles), 0) AS gl, COUNT(CASE WHEN pj.minutos > 0 THEN 1 END) AS pj
                    FROM esc_alumnos a LEFT JOIN esc_partido_jugadores pj ON pj.alumno_id = a.id LEFT JOIN esc_partidos p ON p.id = pj.partido_id AND p.goles_favor IS NOT NULL
                    WHERE " . sql_en_grupo('a', $gid) . " AND a.estado = 'activo' GROUP BY a.id ORDER BY m DESC");
      $tot_min = array_sum(array_column($min, 'm')); $prom_min = $min ? $tot_min / count($min) : 0; ?>
  <div class="tarjeta"><h3 style="margin-top:0">⏱ Minutos jugados por cada jugador (reparto del tiempo de juego)</h3>
    <p class="suave" style="margin-top:0">En formativas todos deben jugar. La línea es el promedio del grupo: los que están muy abajo necesitan más minutos.</p>
    <?= svg_barras(array_map(fn($x) => [mb_substr($x['nombres'], 0, 8), (int)$x['m'], (string)(int)$x['m'], (int)$x['m'] < $prom_min * 0.5 ? '#d7263d' : ((int)$x['m'] < $prom_min * 0.8 ? '#e0a100' : '#1a9e5c')], $min), ['alto' => 190, 'linea' => $prom_min, 'linea_txt' => 'promedio ' . round($prom_min) . ' min', 'vacio' => 'Sin partidos todavía.']) ?></div>
  <?php endif; ?>
  <?php if ($ed && $grupos): ?>
  <details class="tarjeta"><summary><b>+ Nuevo partido</b></summary><?php $pe = ['grupo_id' => $gid ?: ($grupos[0]['id'] ?? 0)]; require APP_DIR . '/admin/formativas_form_partido.php'; ?></details>
  <?php endif; ?>
  <?php if (!$ps): ?><div class="tarjeta">No hay partidos registrados.</div><?php else: ?>
  <div class="tabla-scroll"><table><tr><th>Fecha</th><th>Categoría</th><th>Rival</th><th>Competencia</th><th>Resultado</th><th class="num">Jugaron</th><th></th></tr>
    <?php foreach ($ps as $p): $r = $p['goles_favor'] === null ? null : ($p['goles_favor'] <=> $p['goles_contra']); ?><tr>
      <td><?= e(fecha_corta($p['fecha'])) ?></td><td><?= e($p['grupo'] ?? '') ?></td><td><?= $p['condicion'] === 'local' ? '🏠' : '✈️' ?> <?= e($p['rival']) ?></td><td><?= e($p['competencia']) ?></td>
      <td><?= $r === null ? '<span class="estado-chip chip-pend">Por jugar</span>' : '<span class="semaforo ' . ($r > 0 ? 's-verde' : ($r < 0 ? 's-rojo' : 's-amarillo')) . '">' . (int)$p['goles_favor'] . ' - ' . (int)$p['goles_contra'] . '</span>' ?></td>
      <td class="num"><?= (int)$p['jugaron'] ?: '' ?></td><td><a class="btn btn-sec btn-chico" href="admin.php?p=formativas&amp;ver=partidos&amp;partido=<?= (int)$p['id'] ?>"><?= $r === null ? '📝 Registrar' : 'Ver' ?></a></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <?php $gol = filas("SELECT a.id, a.nombres, a.apellidos, g.nombre AS grupo, SUM(pj.goles) AS gl, SUM(pj.asistencias) AS asis FROM esc_partido_jugadores pj JOIN esc_alumnos a ON a.id = pj.alumno_id LEFT JOIN esc_grupos g ON g.id = a.grupo_id
                     WHERE $filtro_g AND pj.goles + pj.asistencias > 0 GROUP BY a.id ORDER BY gl DESC, asis DESC LIMIT 10");
  if ($gol): ?><div class="tarjeta"><h3 style="margin-top:0">⚽ Goleadores y asistidores</h3><table><?php foreach ($gol as $i => $x): ?><tr><td><?= $i + 1 ?></td><td><a href="admin.php?p=alumno&amp;id=<?= (int)$x['id'] ?>&amp;s=club"><?= e($x['nombres'] . ' ' . $x['apellidos']) ?></a> <small class="suave"><?= e($x['grupo']) ?></small></td><td class="num"><b><?= (int)$x['gl'] ?></b> goles</td><td class="num"><?= (int)$x['asis'] ?> asist.</td></tr><?php endforeach; ?></table></div><?php endif; ?>

<?php
/* ------------------------------------------------------------ MADURACIÓN */
elseif ($ver === 'maduracion'):
    $al = filas("SELECT a.*, g.nombre AS grupo FROM esc_alumnos a LEFT JOIN esc_grupos g ON g.id = a.grupo_id WHERE a.liga_id = ? AND a.estado = 'activo' AND $filtro_g ORDER BY a.fecha_nac DESC", [$lid]);
    $filas_m = [];
    foreach ($al as $a) {
        $m = medicion_maduracion((int)$a['id']);
        $filas_m[] = ['a' => $a, 'mad' => maduracion($a, $m), 'vel' => velocidad_crecimiento((int)$a['id']), 'talla' => ($t = valor('SELECT talla FROM esc_mediciones WHERE alumno_id = ? AND talla > 0 ORDER BY fecha DESC LIMIT 1', [$a['id']])) !== null ? (float)$t : null];
    }
    $con = array_values(array_filter($filas_m, fn($x) => $x['mad']));
    usort($con, fn($x, $y) => $y['mad']['offset'] <=> $x['mad']['offset']);
    $cuenta = ['pre' => 0, 'pico' => 0, 'post' => 0];
    foreach ($con as $x) $cuenta[$x['mad']['estado']]++; ?>
  <p class="suave">La maduración se calcula con la fórmula de Mirwald (talla, talla sentado, peso, edad y género). Dos chicos de la misma edad pueden estar hasta 4 años distintos en su desarrollo: esto ayuda a no descartar a los que maduran tarde y a cuidar la carga de los que están en pleno estirón.</p>
  <div class="cifras">
    <div class="cifra"><b><?= $cuenta['pre'] ?></b>Antes del estirón</div>
    <div class="cifra"><b style="color:#b07d00"><?= $cuenta['pico'] ?></b>En el pico (cuidar la carga)</div>
    <div class="cifra"><b><?= $cuenta['post'] ?></b>Después del estirón</div>
    <div class="cifra"><b><?= count($filas_m) - count($con) ?></b>Sin datos suficientes</div>
  </div>
  <?php if ($con): ?>
  <div class="tarjeta"><h3 style="margin-top:0">Años respecto al pico de crecimiento (negativo = todavía no llega)</h3>
    <?= svg_barras(array_map(fn($x) => [mb_substr($x['a']['nombres'], 0, 8), $x['mad']['offset'] + 4, fmt_num($x['mad']['offset'], 1), $x['mad']['estado'] === 'pico' ? '#e0a100' : ($x['mad']['estado'] === 'pre' ? '#8a9591' : '#1a9e5c')], $con), ['alto' => 190, 'max' => 7, 'linea' => 4, 'linea_txt' => 'pico']) ?></div>
  <?php endif; ?>
  <div class="tabla-scroll"><table><tr><th>Jugador</th><th>Edad</th><th class="num">Talla</th><th>Estado</th><th class="num">Años al pico</th><th class="num">Edad del pico</th><th class="num">cm/año</th></tr>
    <?php foreach ($filas_m as $x): $a = $x['a']; $m = $x['mad']; ?><tr><td><a href="admin.php?p=alumno&amp;id=<?= (int)$a['id'] ?>&amp;s=club"><?= e(nombre_alumno($a)) ?></a> <small class="suave"><?= e($a['grupo']) ?></small></td>
      <td><?= $a['fecha_nac'] ? edad($a['fecha_nac']) : '—' ?></td><td class="num"><?= $x['talla'] ? fmt_num($x['talla'], 1) : '—' ?></td>
      <td><?= $m ? '<span class="semaforo ' . e($m['clase']) . '">' . e($m['nombre']) . '</span>' : '<small class="suave">falta talla sentado</small>' ?></td>
      <td class="num"><?= $m ? fmt_num($m['offset'], 1) : '' ?></td><td class="num"><?= $m ? fmt_num($m['aphv'], 1) : '' ?></td>
      <td class="num"><?= $x['vel'] !== null ? ($x['vel'] >= 7 ? '<b style="color:#b07d00">' . fmt_num($x['vel'], 1) . '</b>' : fmt_num($x['vel'], 1)) : '—' ?></td></tr><?php endforeach; ?></table></div>
  <p class="suave">Las medidas se toman en <a href="admin.php?p=evaluaciones">Evaluaciones → Jornadas</a> (marque "tomar también peso y talla") o en la ficha de cada jugador.</p>

<?php
/* ------------------------------------------------------------ VALORACIONES */
elseif ($ver === 'valoraciones'):
    $vs = filas("SELECT v.*, a.nombres, a.apellidos, a.fecha_nac, g.nombre AS grupo FROM esc_valoraciones v JOIN esc_alumnos a ON a.id = v.alumno_id LEFT JOIN esc_grupos g ON g.id = a.grupo_id
                 WHERE a.liga_id = ? AND a.estado = 'activo' AND $filtro_g AND v.id = (SELECT v2.id FROM esc_valoraciones v2 WHERE v2.alumno_id = v.alumno_id ORDER BY v2.fecha DESC, v2.id DESC LIMIT 1)
                 ORDER BY v.potencial, a.apellidos", [$lid]);
    $sin = (int)valor("SELECT COUNT(*) FROM esc_alumnos a WHERE a.liga_id = ? AND a.estado = 'activo' AND $filtro_g AND NOT EXISTS (SELECT 1 FROM esc_valoraciones v WHERE v.alumno_id = a.id)", [$lid]); ?>
  <p class="suave">La última valoración de cada jugador. Se registra en la ficha del jugador → pestaña 🏆 Club.</p>
  <div class="cifras"><?php foreach (potenciales() as $k => [$l, $n, $c]): ?><div class="cifra"><b><?= count(array_filter($vs, fn($v) => $v['potencial'] === $k)) ?></b><span class="semaforo <?= $c ?>"><?= e($l) ?></span> <?= e($n) ?></div><?php endforeach; ?><div class="cifra"><b><?= $sin ?></b>Sin valorar</div></div>
  <?php if ($vs): ?><div class="tabla-scroll"><table><tr><th>Jugador</th><th>Posición</th><th>Potencial</th><th>Recomendación</th><th>Fecha</th></tr>
    <?php foreach ($vs as $v): [$l, $n, $c] = potenciales()[$v['potencial']] ?? ['?', '', '']; ?><tr><td><a href="admin.php?p=alumno&amp;id=<?= (int)$v['alumno_id'] ?>&amp;s=club"><?= e($v['nombres'] . ' ' . $v['apellidos']) ?></a> <small class="suave"><?= e($v['grupo']) ?> · <?= e(sub_de($v['fecha_nac'])) ?></small></td>
      <td><?= e($v['posicion']) ?></td><td><span class="semaforo <?= $c ?>"><?= e($l) ?></span></td><td><?= e(recomendaciones_valoracion()[$v['recomendacion']] ?? '') ?></td><td><small><?= e(fecha_corta($v['fecha'])) ?></small></td></tr><?php endforeach; ?></table></div>
  <?php else: ?><div class="tarjeta">Sin valoraciones todavía.</div><?php endif; ?>

<?php
/* ------------------------------------------------------------ NOTAS DEL CUERPO TÉCNICO */
elseif ($ver === 'staff'):
    $area_f = array_key_exists(get('area'), areas_staff()) ? get('area') : '';
    $u = usuario();
    $ns = filas("SELECT n.*, a.nombres, a.apellidos, g.nombre AS grupo FROM esc_staff_notas n JOIN esc_alumnos a ON a.id = n.alumno_id LEFT JOIN esc_grupos g ON g.id = a.grupo_id
                 WHERE a.liga_id = ? AND $filtro_g" . ($area_f ? ' AND n.area = ?' : '') . ' ORDER BY n.fecha DESC, n.id DESC LIMIT 80', $area_f ? [$lid, $area_f] : [$lid]);
    $ns = array_values(array_filter($ns, fn($n) => !(int)$n['privada'] || in_array(perfil_actual(), ['admin', 'super'], true) || $n['registrado_por'] === $u['nombre'] || ($u['area'] ?? '') === $n['area'])); ?>
  <nav class="pestanas"><a class="<?= $area_f === '' ? 'activa' : '' ?>" href="<?= e($url(['area' => null])) ?>">Todas las áreas</a><?php foreach (areas_staff() as $k => [$ic, $t]): ?><a class="<?= $area_f === $k ? 'activa' : '' ?>" href="<?= e($url(['area' => $k])) ?>"><?= $ic ?> <?= e($t) ?></a><?php endforeach; ?></nav>
  <p class="suave">Lo que anota cada área (preparador físico, psicólogo, nutricionista, médico…) en la ficha del jugador. Las notas 🔒 confidenciales solo las ven la dirección y su propia área.</p>
  <?php if (!$ns): ?><div class="tarjeta">Sin notas.</div><?php endif; ?>
  <div class="tarjeta"><?php foreach ($ns as $n): [$ic, $t] = areas_staff()[$n['area']] ?? ['📝', $n['area']]; ?>
    <div class="meta-fila"><b><?= $ic ?> <?= e($t) ?></b> · <a href="admin.php?p=alumno&amp;id=<?= (int)$n['alumno_id'] ?>&amp;s=club"><?= e($n['nombres'] . ' ' . $n['apellidos']) ?></a> <small class="suave"><?= e($n['grupo']) ?> · <?= e(fecha_corta($n['fecha'])) ?> · <?= e($n['registrado_por']) ?></small><?= (int)$n['privada'] ? ' <span class="etiqueta gris">🔒</span>' : '' ?>
      <div style="white-space:pre-wrap"><?= e($n['texto']) ?></div></div>
  <?php endforeach; ?></div>

<?php
/* ------------------------------------------------------------ COLEGIO */
elseif ($ver === 'academico'):
    $ac = filas("SELECT x.*, a.nombres, a.apellidos, a.colegio, g.nombre AS grupo FROM esc_academico x JOIN esc_alumnos a ON a.id = x.alumno_id LEFT JOIN esc_grupos g ON g.id = a.grupo_id
                 WHERE a.liga_id = ? AND a.estado = 'activo' AND $filtro_g AND x.id = (SELECT MAX(x2.id) FROM esc_academico x2 WHERE x2.alumno_id = x.alumno_id) ORDER BY x.promedio", [$lid]);
    $bajos = array_filter($ac, fn($x) => $x['promedio'] !== null && (float)$x['promedio'] < 7); ?>
  <p class="suave">Último periodo registrado de cada jugador. Se registra en la ficha del jugador → 🏆 Club → Rendimiento escolar.</p>
  <div class="cifras"><div class="cifra"><b><?= count($ac) ?></b>Jugadores con notas</div><div class="cifra"><b><?= $ac ? fmt_num(array_sum(array_map(fn($x) => (float)$x['promedio'], $ac)) / count($ac), 2) : '—' ?></b>Promedio del club</div>
    <div class="cifra"><b style="<?= $bajos ? 'color:var(--roja)' : '' ?>"><?= count($bajos) ?></b>Bajo 7 (necesitan apoyo)</div></div>
  <?php if ($ac): ?><div class="tabla-scroll"><table><tr><th>Jugador</th><th>Colegio</th><th>Periodo</th><th class="num">Promedio</th><th class="num">Asist.</th><th>Conducta</th><th>Materias bajas</th></tr>
    <?php foreach ($ac as $x): $pr = (float)$x['promedio']; ?><tr><td><a href="admin.php?p=alumno&amp;id=<?= (int)$x['alumno_id'] ?>&amp;s=club"><?= e($x['nombres'] . ' ' . $x['apellidos']) ?></a> <small class="suave"><?= e($x['grupo']) ?></small></td><td><small><?= e($x['colegio']) ?></small></td><td><?= e($x['periodo']) ?></td>
      <td class="num"><span class="semaforo <?= $x['promedio'] === null ? 's-gris' : ($pr >= 8 ? 's-verde' : ($pr >= 7 ? 's-amarillo' : 's-rojo')) ?>"><?= $x['promedio'] !== null ? fmt_num($pr, 2) : '—' ?></span></td>
      <td class="num"><?= $x['asistencia'] !== null ? fmt_num($x['asistencia'], 0) . '%' : '' ?></td><td><?= e($x['conducta']) ?></td><td><small><?= e($x['materias_bajas']) ?></small></td></tr><?php endforeach; ?></table></div>
  <?php else: ?><div class="tarjeta">Sin registros todavía.</div><?php endif; ?>

<?php
/* ------------------------------------------------------------ ENFERMERÍA */
else:
    $act = filas("SELECT l.*, a.nombres, a.apellidos, g.nombre AS grupo FROM esc_lesiones l JOIN esc_alumnos a ON a.id = l.alumno_id LEFT JOIN esc_grupos g ON g.id = a.grupo_id
                  WHERE a.liga_id = ? AND a.estado = 'activo' AND l.alta = '' AND $filtro_g ORDER BY l.retorno_estimado = '', l.retorno_estimado", [$lid]);
    $zonas = filas("SELECT l.zona, COUNT(*) AS n FROM esc_lesiones l JOIN esc_alumnos a ON a.id = l.alumno_id WHERE a.liga_id = ? AND $filtro_g AND l.fecha >= ? AND l.zona <> '' GROUP BY l.zona ORDER BY n DESC LIMIT 8", [$lid, date('Y-m-d', strtotime('-12 months'))]);
    $dias = (int)valor("SELECT COALESCE(SUM(l.dias_baja), 0) FROM esc_lesiones l JOIN esc_alumnos a ON a.id = l.alumno_id WHERE a.liga_id = ? AND $filtro_g AND l.fecha >= ?", [$lid, date('Y-m-d', strtotime('-12 months'))]); ?>
  <div class="cifras"><div class="cifra"><b style="<?= $act ? 'color:var(--roja)' : '' ?>"><?= count($act) ?></b>Lesionados ahora</div><div class="cifra"><b><?= array_sum(array_column($zonas, 'n')) ?></b>Lesiones en 12 meses</div><div class="cifra"><b><?= $dias ?></b>Días de baja en 12 meses</div></div>
  <?php if ($act): ?><div class="rejilla-2"><?php foreach ($act as $l): $fases = array_keys(fases_lesion()); $ix = array_search($l['fase'] ?: 'aguda', $fases, true); ?>
    <div class="tarjeta"><b><a href="admin.php?p=alumno&amp;id=<?= (int)$l['alumno_id'] ?>&amp;s=salud"><?= e($l['nombres'] . ' ' . $l['apellidos']) ?></a></b> <small class="suave"><?= e($l['grupo']) ?></small><br>
      <?= e(trim($l['zona'] . ' ' . $l['tipo'])) ?><?= $l['diagnostico'] ? ' · ' . e($l['diagnostico']) : '' ?> <small class="suave">desde <?= e(fecha_corta($l['fecha'])) ?></small>
      <div class="fase-lesion"><?php foreach ($fases as $i => $f): ?><span class="<?= $i < $ix ? 'hecha' : ($i === $ix ? 'actual' : '') ?>" title="<?= e(fases_lesion()[$f]) ?>"></span><?php endforeach; ?></div>
      <small><b><?= e(fases_lesion()[$l['fase'] ?: 'aguda'] ?? '') ?></b><?= $l['retorno_estimado'] ? ' · regreso estimado ' . e(fecha_corta($l['retorno_estimado'])) : '' ?></small></div>
  <?php endforeach; ?></div><?php else: ?><div class="tarjeta">✓ No hay jugadores lesionados.</div><?php endif; ?>
  <?php if ($zonas): ?><div class="tarjeta"><h3 style="margin-top:0">Zonas más lesionadas (12 meses)</h3><?= svg_barras(array_map(fn($z) => [mb_substr($z['zona'], 0, 12), (int)$z['n'], null, '#d7263d'], $zonas), ['alto' => 160]) ?></div><?php endif; ?>
<?php endif;
pie_admin();
