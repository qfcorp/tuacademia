<?php
/* (v11) EVALUACIONES: medidas y tests para todo un grupo a la vez, ranking por categoría y configuración de tests y habilidades
 * (v17) + Jornadas de evaluación (planilla para el celular con el valor anterior y el semáforo), récords de la escuela
 *       y valores de referencia por edad (semáforo). */
$liga = requiere_liga();
$lid = (int)$liga['id'];
preparar_escuela($lid);
$por = usuario()['nombre'];
$grupos = grupos_visibles($lid);
$ver = in_array(get('ver'), ['jornadas', 'tests', 'medidas', 'ranking', 'records', 'config'], true) ? get('ver') : 'jornadas';
$gid = (int)get('g') ?: (int)($grupos[0]['id'] ?? 0);
$fecha = get('fecha') !== '' && strtotime(get('fecha')) ? date('Y-m-d', strtotime(get('fecha'))) : date('Y-m-d');
$tests = filas('SELECT * FROM esc_tests WHERE liga_id = ? ORDER BY activo DESC, orden, nombre', [$lid]);
$activos = array_values(array_filter($tests, fn($t) => $t['activo']));
$tid = (int)get('t') ?: (int)($activos[0]['id'] ?? 0);
$n = fn($v) => $v === '' || $v === null ? null : round((float)str_replace(',', '.', (string)$v), 2);

if (es_post()) {
    $accion = post('accion');
    $f = post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : date('Y-m-d');
    if (in_array($accion, ['jornada_crear', 'jornada_guardar', 'jornada_cerrar', 'jornada_reabrir', 'jornada_borrar'], true)) {
        require APP_DIR . '/admin/evaluaciones_jornadas_post.php';
    }
    if ($accion === 'baremo' || $accion === 'borrar_baremo') {
        if (!puede('evaluaciones', true)) throw new RuntimeException('Sin permiso.');
        if ($accion === 'borrar_baremo') {
            q('DELETE FROM esc_baremos WHERE id = ? AND liga_id = ?', [(int)post('id'), $lid]);
        } else {
            $t = fila('SELECT * FROM esc_tests WHERE id = ? AND liga_id = ?', [(int)post('test_id'), $lid]) ?? throw new RuntimeException('Elija el test.');
            $am = $n(post('amarillo')); $ve = $n(post('verde'));
            if ($am === null || $ve === null) throw new RuntimeException('Escriba los dos valores (amarillo y verde).');
            if ($t['mejor'] === 'menor' ? $ve > $am : $ve < $am) throw new RuntimeException($t['mejor'] === 'menor' ? 'En este test gana el menor: el valor verde debe ser MENOR que el amarillo.' : 'En este test gana el mayor: el valor verde debe ser MAYOR que el amarillo.');
            q('INSERT INTO esc_baremos (liga_id, test_id, edad_desde, edad_hasta, genero, amarillo, verde) VALUES (?,?,?,?,?,?,?)',
                [$lid, $t['id'], entero(post('edad_desde'), 3, 25), entero(post('edad_hasta'), 3, 25), in_array(post('genero'), ['M', 'F'], true) ? post('genero') : '', $am, $ve]);
        }
        flash('Valores de referencia guardados.');
        ir('admin.php?p=evaluaciones&ver=config#baremos');
    }
    if ($accion === 'tests') {
        $g = grupo_de_escuela((int)post('grupo_id'), $lid);
        $t = fila('SELECT * FROM esc_tests WHERE id = ? AND liga_id = ?', [(int)post('test_id'), $lid]) ?? throw new RuntimeException('Elija el test.');
        $c = 0;
        foreach ((array)($_POST['v'] ?? []) as $aid => $val) {
            if ($n($val) === null || !alumno_en_grupo((int)$aid, (int)$g['id'])) continue;
            q('INSERT INTO esc_resultados (alumno_id, test_id, fecha, valor, registrado_por) VALUES (?,?,?,?,?) ON CONFLICT(alumno_id, test_id, fecha) DO UPDATE SET valor = excluded.valor', [(int)$aid, $t['id'], $f, $n($val), $por]);
            $c++;
        }
        flash($c . ' resultado(s) de "' . $t['nombre'] . '" guardado(s).');
        ir('admin.php?p=evaluaciones&ver=tests&g=' . (int)$g['id'] . '&t=' . (int)$t['id'] . '&fecha=' . $f);
    }
    if ($accion === 'medidas') {
        $g = grupo_de_escuela((int)post('grupo_id'), $lid);
        $c = 0;
        foreach ((array)($_POST['m'] ?? []) as $aid => $vals) {
            if (!is_array($vals) || !alumno_en_grupo((int)$aid, (int)$g['id'])) continue;
            $peso = $n($vals['peso'] ?? ''); $talla = $n($vals['talla'] ?? ''); $cint = $n($vals['cintura'] ?? '');
            if ($peso === null && $talla === null && $cint === null) continue;
            q('INSERT INTO esc_mediciones (alumno_id, fecha, peso, talla, cintura, registrado_por) VALUES (?,?,?,?,?,?)', [(int)$aid, $f, $peso, $talla, $cint, $por]);
            $c++;
        }
        flash('Medidas guardadas de ' . $c . ' alumno(s).');
        ir('admin.php?p=evaluaciones&ver=medidas&g=' . (int)$g['id']);
    }
    if (!puede('config', true) && !puede('evaluaciones', true)) throw new RuntimeException('Sin permiso.');
    if ($accion === 'test_guardar') {
        if (mb_strlen(post('nombre')) < 2) throw new RuntimeException('Escriba el nombre del test.');
        $d = [mb_substr(post('nombre'), 0, 80), mb_substr(post('unidad'), 0, 12), post('mejor') === 'menor' ? 'menor' : 'mayor', post('tipo') === 'tecnico' ? 'tecnico' : 'fisico', mb_substr(post('descripcion'), 0, 200), post('activo') === '1' ? 1 : 0, array_key_exists(post('deporte'), familias_opciones()) ? post('deporte') : ''];
        if ((int)post('id')) q('UPDATE esc_tests SET nombre = ?, unidad = ?, mejor = ?, tipo = ?, descripcion = ?, activo = ?, deporte = ? WHERE id = ? AND liga_id = ?', array_merge($d, [(int)post('id'), $lid]));
        else q('INSERT INTO esc_tests (nombre, unidad, mejor, tipo, descripcion, activo, deporte, liga_id, orden) VALUES (?,?,?,?,?,?,?,?,99)', array_merge($d, [$lid]));
        flash('Test guardado.');
    } elseif ($accion === 'hab_guardar') {
        $dep_h = array_key_exists(post('deporte'), familias_opciones()) ? post('deporte') : '';
        if ((int)post('id')) q('UPDATE esc_habilidades SET nombre = ?, activo = ?, deporte = ? WHERE id = ? AND liga_id = ?', [mb_substr(post('nombre'), 0, 40), post('activo') === '1' ? 1 : 0, $dep_h, (int)post('id'), $lid]);
        elseif (mb_strlen(post('nombre')) >= 2) q('INSERT INTO esc_habilidades (liga_id, nombre, orden, deporte) VALUES (?,?,99,?)', [$lid, mb_substr(post('nombre'), 0, 40), $dep_h]);
        flash('Habilidad guardada.');
    }
    ir('admin.php?p=evaluaciones&ver=config');
}

cabecera_admin('Evaluaciones');
?>
<h1>⏱ Evaluaciones</h1>
<nav class="pestanas">
  <a class="<?= $ver === 'jornadas' ? 'activa' : '' ?>" href="admin.php?p=evaluaciones">📋 Jornadas de evaluación</a>
  <a class="<?= $ver === 'tests' ? 'activa' : '' ?>" href="admin.php?p=evaluaciones&amp;ver=tests">Tests del grupo</a>
  <a class="<?= $ver === 'medidas' ? 'activa' : '' ?>" href="admin.php?p=evaluaciones&amp;ver=medidas">Medidas del grupo</a>
  <a class="<?= $ver === 'ranking' ? 'activa' : '' ?>" href="admin.php?p=evaluaciones&amp;ver=ranking">🏅 Ranking</a>
  <a class="<?= $ver === 'records' ? 'activa' : '' ?>" href="admin.php?p=evaluaciones&amp;ver=records">🏆 Récords</a>
  <a class="<?= $ver === 'config' ? 'activa' : '' ?>" href="admin.php?p=evaluaciones&amp;ver=config">Tests, habilidades y semáforo</a>
</nav>
<?php if (!$grupos && $ver !== 'config'): ?><div class="tarjeta">No hay grupos.</div><?php pie_admin(); return; endif; ?>

<?php if ($ver === 'jornadas'): require APP_DIR . '/admin/evaluaciones_jornadas.php'; ?>

<?php elseif ($ver === 'records'): $recs = records_escuela($lid);
    $mis = array_map(fn($g) => (int)$g['id'], $grupos); ?>
  <p class="suave">La mejor marca de la escuela en cada test y en cada categoría (según el año de nacimiento en la fecha del test). Se actualiza sola con cada resultado.</p>
  <?php if (!$recs): ?><div class="tarjeta">Todavía no hay resultados.</div><?php endif; ?>
  <div class="rejilla-2">
  <?php foreach ($recs as $r): $t = $r['test']; $x = $r['abs']; ?>
    <div class="tarjeta"><h3 style="margin-top:0">🏆 <?= e($t['nombre']) ?> <small class="suave"><?= e($t['unidad']) ?> · gana el <?= $t['mejor'] === 'menor' ? 'menor' : 'mayor' ?></small></h3>
      <p style="margin:.2rem 0 .6rem">Récord absoluto: <b style="font-size:1.25rem;color:var(--acento)"><?= fmt_num($x['valor']) ?> <?= e($t['unidad']) ?></b> · <a href="admin.php?p=alumno&amp;id=<?= (int)$x['aid'] ?>&amp;s=desarrollo"><?= e($x['nombres'] . ' ' . $x['apellidos']) ?></a> <small class="suave">(<?= e(fecha_corta($x['fecha'])) ?>)</small></p>
      <table><tr><th>Categoría</th><th>Alumno</th><th class="num">Marca</th><th>Fecha</th></tr>
        <?php foreach ($r['cats'] as $sub => $y): ?><tr><td><b><?= e($sub) ?></b></td><td><a href="admin.php?p=alumno&amp;id=<?= (int)$y['aid'] ?>&amp;s=desarrollo"><?= e($y['nombres'] . ' ' . $y['apellidos']) ?></a><?= $y['estado'] !== 'activo' ? ' <small class="suave">(exalumno)</small>' : '' ?></td><td class="num pts"><?= fmt_num($y['valor']) ?></td><td><small><?= e(fecha_corta($y['fecha'])) ?></small></td></tr><?php endforeach; ?></table></div>
  <?php endforeach; ?>
  </div>

<?php elseif ($ver === 'tests'): $g = $gid ? grupo_de_escuela($gid, $lid) : null;
    $activos_g = $g ? tests_deportes($lid, [deporte_grupo($g, $liga)]) : $activos; // (v21) solo los tests de su deporte
    if ($tid && !in_array($tid, array_map(fn($x) => (int)$x['id'], $activos_g), true)) $tid = (int)($activos_g[0]['id'] ?? 0);
    $t = $tid ? fila('SELECT * FROM esc_tests WHERE id = ?', [$tid]) : null; ?>
  <form method="get" class="acciones" style="margin-bottom:10px"><input type="hidden" name="p" value="evaluaciones"><input type="hidden" name="ver" value="tests">
    <select name="g" onchange="this.form.submit()"><?= opciones_grupos($grupos, $gid, false) ?></select>
    <select name="t" onchange="this.form.submit()"><?php foreach ($activos_g as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $tid === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['nombre']) ?></option><?php endforeach; ?></select>
    <input type="date" name="fecha" value="<?= e($fecha) ?>" onchange="this.form.submit()"></form>
  <?php if ($g && $t): $prev = []; foreach (filas('SELECT alumno_id, valor FROM esc_resultados WHERE test_id = ? AND fecha = ?', [$t['id'], $fecha]) as $x) $prev[(int)$x['alumno_id']] = $x['valor']; ?>
  <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="tests"><input type="hidden" name="grupo_id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="test_id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="fecha" value="<?= e($fecha) ?>">
    <h2 style="margin-top:0"><?= e($t['nombre']) ?> <small class="suave">(<?= e($t['unidad']) ?> · gana el <?= $t['mejor'] === 'menor' ? 'menor' : 'mayor' ?>)</small></h2>
    <?= $t['descripcion'] ? '<p class="suave">' . e($t['descripcion']) . '</p>' : '' ?>
    <?php foreach (alumnos_grupo((int)$g['id']) as $a): $ult = fila('SELECT valor, fecha FROM esc_resultados WHERE alumno_id = ? AND test_id = ? AND fecha < ? ORDER BY fecha DESC LIMIT 1', [$a['id'], $t['id'], $fecha]); ?>
      <div class="asis-fila"><span class="eq-celda"><?= foto_alumno($a) ?> <?= e(nombre_alumno($a)) ?> <small class="suave"><?= $ult ? 'antes: ' . e((string)(float)$ult['valor']) : '' ?></small></span>
        <input type="number" step="0.01" inputmode="decimal" name="v[<?= (int)$a['id'] ?>]" value="<?= isset($prev[(int)$a['id']]) ? e((string)(float)$prev[(int)$a['id']]) : '' ?>" style="max-width:110px"></div>
    <?php endforeach; ?>
    <button class="btn btn-grande" style="margin-top:10px">💾 Guardar resultados</button>
  </form>
  <?php endif; ?>

<?php elseif ($ver === 'medidas'): $g = $gid ? grupo_de_escuela($gid, $lid) : null; ?>
  <form method="get" class="acciones" style="margin-bottom:10px"><input type="hidden" name="p" value="evaluaciones"><input type="hidden" name="ver" value="medidas">
    <select name="g" onchange="this.form.submit()"><?= opciones_grupos($grupos, $gid, false) ?></select></form>
  <?php if ($g): ?>
  <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="medidas"><input type="hidden" name="grupo_id" value="<?= (int)$g['id'] ?>">
    <p><label>Fecha de la medición</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>" style="max-width:180px"></p>
    <div class="tabla-scroll"><table><tr><th>Alumno</th><th>Última</th><th>Peso (kg)</th><th>Talla (cm)</th><th>Cintura (cm)</th></tr>
    <?php foreach (alumnos_grupo((int)$g['id']) as $a): $u = fila('SELECT * FROM esc_mediciones WHERE alumno_id = ? ORDER BY fecha DESC LIMIT 1', [$a['id']]); ?>
      <tr><td><?= e(nombre_alumno($a)) ?></td><td><small><?= $u ? e(date('d/m/y', strtotime($u['fecha']))) . ' · ' . e((string)(float)$u['peso']) . ' kg · ' . e((string)(float)$u['talla']) . ' cm' : '—' ?></small></td>
        <td><input type="number" step="0.1" min="0" inputmode="decimal" name="m[<?= (int)$a['id'] ?>][peso]" style="max-width:90px"></td>
        <td><input type="number" step="0.1" min="0" inputmode="decimal" name="m[<?= (int)$a['id'] ?>][talla]" style="max-width:90px"></td>
        <td><input type="number" step="0.1" min="0" inputmode="decimal" name="m[<?= (int)$a['id'] ?>][cintura]" style="max-width:90px"></td></tr>
    <?php endforeach; ?></table></div>
    <p class="suave">Las demás medidas (cadera, brazo, muslo, % grasa, envergadura) se registran en la ficha de cada alumno.</p>
    <button class="btn btn-grande">💾 Guardar medidas</button>
  </form>
  <?php endif; ?>

<?php elseif ($ver === 'ranking'): $t = $tid ? fila('SELECT * FROM esc_tests WHERE id = ?', [$tid]) : null; $gsel = (int)get('g'); ?>
  <form method="get" class="acciones" style="margin-bottom:10px"><input type="hidden" name="p" value="evaluaciones"><input type="hidden" name="ver" value="ranking">
    <select name="t" onchange="this.form.submit()"><?php foreach ($activos as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $tid === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['nombre'] . (($x['deporte'] ?? '') !== '' ? ' · ' . texto_familia($x['deporte']) : '')) ?></option><?php endforeach; ?></select>
    <select name="g" onchange="this.form.submit()"><option value="">Toda la escuela</option><?= opciones_grupos($grupos, $gsel, false) ?></select></form>
  <?php if ($t): $rk = ranking_test($t, $lid, $gsel ?: null);
      if (perfil_actual() === 'profesor') { $mis = array_map(fn($g) => (int)$g['id'], $grupos); $rk = array_values(array_filter($rk, fn($r) => in_array((int)$r['grupo_id'], $mis, true))); }
      $por_cat = [];
      foreach ($rk as $r) $por_cat[sub_de($r['fecha_nac'])][] = $r;
      ksort($por_cat, SORT_NATURAL); ?>
    <?php if (!$rk): ?><p class="suave">Todavía no hay resultados de este test.</p><?php endif; ?>
    <div class="rejilla-2">
    <?php foreach ($por_cat as $cat => $filas_r): ?>
      <div class="tarjeta"><h3 style="margin-top:0"><?= e($cat) ?> · <?= e($t['nombre']) ?></h3><table>
        <?php foreach (array_slice($filas_r, 0, 10) as $i => $r): ?><tr><td><?= ['🥇', '🥈', '🥉'][$i] ?? $i + 1 ?></td><td><a href="admin.php?p=alumno&amp;id=<?= (int)$r['id'] ?>&amp;s=tests"><?= e(nombre_alumno($r)) ?></a></td><td class="num pts"><?= e(rtrim(rtrim(number_format((float)$r['valor'], 2, '.', ''), '0'), '.')) ?> <?= e($t['unidad']) ?></td></tr><?php endforeach; ?>
      </table></div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php else: $habs = filas('SELECT * FROM esc_habilidades WHERE liga_id = ? ORDER BY activo DESC, orden, id', [$lid]); $edc = puede('evaluaciones', true); ?>
  <h2>Tests de rendimiento</h2>
  <p class="suave">Cree los tests que usa su escuela. "Gana el menor" sirve para tiempos (ej: 30 metros); "gana el mayor" para saltos, repeticiones o metros.</p>
  <?php foreach ($tests as $t): ?>
    <form method="post" class="acciones tarjeta" style="padding:8px 10px;margin-bottom:6px;align-items:flex-end;<?= $t['activo'] ? '' : 'opacity:.6' ?>"><?= csrf_campo() ?><input type="hidden" name="accion" value="test_guardar"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
      <div><label>Test</label><input type="text" name="nombre" value="<?= e($t['nombre']) ?>" maxlength="80"></div>
      <div><label>Unidad</label><input type="text" name="unidad" value="<?= e($t['unidad']) ?>" maxlength="12" style="max-width:70px"></div>
      <div><label>Gana</label><select name="mejor"><option value="mayor" <?= $t['mejor'] === 'mayor' ? 'selected' : '' ?>>el mayor</option><option value="menor" <?= $t['mejor'] === 'menor' ? 'selected' : '' ?>>el menor</option></select></div>
      <div><label>Tipo</label><select name="tipo"><option value="fisico" <?= $t['tipo'] === 'fisico' ? 'selected' : '' ?>>Físico</option><option value="tecnico" <?= $t['tipo'] === 'tecnico' ? 'selected' : '' ?>>Técnico</option></select></div>
      <div><label>Cómo se hace</label><input type="text" name="descripcion" value="<?= e($t['descripcion']) ?>" maxlength="200"></div>
<div><label>Deporte</label><select name="deporte"><?php foreach (familias_opciones() as $fk => $fv): ?><option value="<?= e($fk) ?>" <?= ($t['deporte'] ?? '') === $fk ? 'selected' : '' ?>><?= e($fv) ?></option><?php endforeach; ?></select></div>
      <label class="check"><input type="checkbox" name="activo" value="1" <?= $t['activo'] ? 'checked' : '' ?>> Activo</label>
      <?php if ($edc): ?><button class="btn btn-sec btn-chico">Guardar</button><?php endif; ?>
    </form>
  <?php endforeach; ?>
  <?php if ($edc): ?>
  <form method="post" class="acciones tarjeta" style="align-items:flex-end"><?= csrf_campo() ?><input type="hidden" name="accion" value="test_guardar"><input type="hidden" name="id" value="0"><input type="hidden" name="activo" value="1">
    <div><label>Nuevo test</label><input type="text" name="nombre" maxlength="80" placeholder="Ej: Agilidad Illinois"></div>
    <div><label>Unidad</label><input type="text" name="unidad" maxlength="12" style="max-width:70px" placeholder="s"></div>
    <div><label>Gana</label><select name="mejor"><option value="mayor">el mayor</option><option value="menor">el menor</option></select></div>
    <div><label>Tipo</label><select name="tipo"><option value="fisico">Físico</option><option value="tecnico">Técnico</option></select></div>
    <div><label>Cómo se hace</label><input type="text" name="descripcion" maxlength="200"></div>
    <div><label>Deporte</label><select name="deporte"><?php foreach (familias_opciones() as $fk => $fv): ?><option value="<?= e($fk) ?>"><?= e($fv) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-sec">+ Agregar</button></form>
  <?php endif; ?>
  <h2>Habilidades de la evaluación técnica (1 a 5)</h2>
  <?php foreach ($habs as $h): ?>
    <form method="post" class="acciones" style="margin-bottom:4px;<?= $h['activo'] ? '' : 'opacity:.6' ?>"><?= csrf_campo() ?><input type="hidden" name="accion" value="hab_guardar"><input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
      <input type="text" name="nombre" value="<?= e($h['nombre']) ?>" maxlength="40"><select name="deporte"><?php foreach (familias_opciones() as $fk => $fv): ?><option value="<?= e($fk) ?>" <?= ($h['deporte'] ?? '') === $fk ? 'selected' : '' ?>><?= e($fv) ?></option><?php endforeach; ?></select><label class="check"><input type="checkbox" name="activo" value="1" <?= $h['activo'] ? 'checked' : '' ?>> Activa</label><?php if ($edc): ?><button class="btn btn-sec btn-chico">Guardar</button><?php endif; ?></form>
  <?php endforeach; ?>
  <?php if ($edc): ?><form method="post" class="acciones"><?= csrf_campo() ?><input type="hidden" name="accion" value="hab_guardar"><input type="hidden" name="id" value="0"><input type="text" name="nombre" maxlength="40" placeholder="Nueva habilidad"><select name="deporte"><?php foreach (familias_opciones() as $fk => $fv): ?><option value="<?= e($fk) ?>"><?= e($fv) ?></option><?php endforeach; ?></select><button class="btn btn-sec btn-chico">+ Agregar</button></form><?php endif; ?>
  <?php $bars = filas('SELECT b.*, t.nombre, t.unidad, t.mejor FROM esc_baremos b JOIN esc_tests t ON t.id = b.test_id WHERE b.liga_id = ? ORDER BY t.orden, t.nombre, b.edad_desde', [$lid]); ?>
  <h2 id="baremos">🚦 Semáforo por edad (valores de referencia)</h2>
  <p class="suave">El semáforo de cada resultado sale solo: <b>verde</b> si el alumno está en el tercio superior de su categoría en la escuela, <b>amarillo</b> en el medio y <b>rojo</b> abajo (hacen falta al menos 5 alumnos evaluados de la categoría). Si prefiere usar sus propios valores, escríbalos aquí y se usan primero.</p>
  <?php if ($bars): ?><div class="tabla-scroll"><table><tr><th>Test</th><th>Edad</th><th>Género</th><th class="num">🟡 Amarillo desde</th><th class="num">🟢 Verde desde</th><th></th></tr>
    <?php foreach ($bars as $b): ?><tr><td><?= e($b['nombre']) ?> <small class="suave"><?= e($b['unidad']) ?></small></td><td><?= (int)$b['edad_desde'] ?> a <?= (int)$b['edad_hasta'] ?> años</td><td><?= ['M' => 'Hombres', 'F' => 'Mujeres'][$b['genero']] ?? 'Todos' ?></td><td class="num"><?= fmt_num($b['amarillo']) ?></td><td class="num"><?= fmt_num($b['verde']) ?></td>
      <td><?php if ($edc): ?><form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_baremo"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn-sec btn-chico">×</button></form><?php endif; ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
  <?php if ($edc && $activos): ?>
  <form method="post" class="acciones tarjeta" style="align-items:flex-end"><?= csrf_campo() ?><input type="hidden" name="accion" value="baremo">
    <div><label>Test</label><select name="test_id"><?php foreach ($activos as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['nombre'] . ' (' . $t['unidad'] . ', gana el ' . $t['mejor'] . ')') ?></option><?php endforeach; ?></select></div>
    <div><label>Edad desde</label><input type="number" name="edad_desde" min="3" max="25" value="10" style="max-width:80px"></div>
    <div><label>hasta</label><input type="number" name="edad_hasta" min="3" max="25" value="11" style="max-width:80px"></div>
    <div><label>Género</label><select name="genero"><option value="">Todos</option><option value="M">Hombres</option><option value="F">Mujeres</option></select></div>
    <div><label>🟡 Amarillo desde</label><input type="number" name="amarillo" step="0.01" style="max-width:100px"></div>
    <div><label>🟢 Verde desde</label><input type="number" name="verde" step="0.01" style="max-width:100px"></div>
    <button class="btn btn-sec">+ Agregar</button></form>
  <p class="ayuda">Ejemplo, sprint de 30 m (gana el menor), 10 a 11 años: amarillo desde 5,9 s y verde desde 5,4 s → 5,3 s es verde, 5,7 s es amarillo y 6,1 s es rojo.</p>
  <?php endif; ?>
<?php endif;
pie_admin();
