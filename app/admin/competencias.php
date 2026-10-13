<?php
/*
 * (v21) 🥇 COMPETENCIAS DE LOS DEPORTES INDIVIDUALES (escuela multideporte)
 *  - 🥊 Combates (boxeo, artes marciales) y 🎾 partidos de tenis: rival, resultado, método, rounds o sets, peso; récord G-P-E.
 *  - 🥋 Cinturones (artes marciales): exámenes de grado.
 *  - 🏅 Pruebas (natación, atletismo): marca (tiempo o distancia), puesto y medalla; medallero y mejores marcas.
 * Tabla esc_competencias (tipo 'combate' o 'prueba') y esc_grados.
 */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$por = usuario()['nombre'];
$ed = puede('evaluaciones', true);
$grupos = grupos_visibles($lid);
$ids_g = array_map(fn($g) => (int)$g['id'], $grupos);
$gs_por_tipo = ['combate' => [], 'marcas' => [], 'grados' => []];
foreach ($grupos as $g) {
    $d = deporte_grupo($g, $liga);
    $t = tipo_club_deporte($d);
    if ($t === 'combate') $gs_por_tipo['combate'][] = $g;
    if ($t === 'marcas') $gs_por_tipo['marcas'][] = $g;
    if (familia_deporte($d) === 'artes_marciales') $gs_por_tipo['grados'][] = $g;
}
$tabs = [];
if ($gs_por_tipo['combate']) $tabs['combates'] = '🥊 Combates y partidos';
if ($gs_por_tipo['grados']) $tabs['cinturones'] = '🥋 Cinturones';
if ($gs_por_tipo['marcas']) $tabs['pruebas'] = '🏅 Pruebas y medallas';
$ver = array_key_exists(get('ver'), $tabs) ? get('ver') : (array_key_first($tabs) ?? '');
$gs_ver = $ver === 'combates' ? $gs_por_tipo['combate'] : ($ver === 'cinturones' ? $gs_por_tipo['grados'] : $gs_por_tipo['marcas']);
$gid = (int)get('g');
if ($gid && !in_array($gid, array_map(fn($g) => (int)$g['id'], $gs_ver), true)) $gid = 0;
$ids_ver = $gid ? [$gid] : array_map(fn($g) => (int)$g['id'], $gs_ver);
$alumnos = $ids_ver ? filas("SELECT a.* FROM esc_alumnos a WHERE a.liga_id = ? AND a.estado = 'activo' AND " . sql_en_grupo('a', $ids_ver) . ' ORDER BY a.apellidos, a.nombres', [$lid]) : [];
$ids_al = array_map(fn($a) => (int)$a['id'], $alumnos);
$alumno_ok = function (int $aid) use ($lid, $ids_g): array {
    $a = fila('SELECT * FROM esc_alumnos WHERE id = ? AND liga_id = ?', [$aid, $lid]) ?? throw new RuntimeException('Elija un alumno.');
    if (!array_intersect(ids_grupos_alumno($a), $ids_g)) throw new RuntimeException('Ese alumno no es de sus grupos.');
    return $a;
};
/** Grupo del alumno que corresponde a un tipo de competencia (para saber el deporte). */
$grupo_de = function (array $a, array $gs): ?array {
    $ids = ids_grupos_alumno($a);
    foreach ($gs as $g) if (in_array((int)$g['id'], $ids, true)) return $g;
    return null;
};

if (es_post()) {
    if (!$ed) throw new RuntimeException('Sin permiso.');
    $accion = post('accion');
    if ($accion === 'combate' || $accion === 'prueba') {
        $a = $alumno_ok((int)post('alumno_id'));
        $g = $grupo_de($a, $accion === 'combate' ? $gs_por_tipo['combate'] : $gs_por_tipo['marcas']);
        $dep = $g ? deporte_grupo($g, $liga) : '';
        $fecha = post('fecha') && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : date('Y-m-d');
        $d = ['liga_id' => $lid, 'alumno_id' => (int)$a['id'], 'grupo_id' => $g['id'] ?? null, 'deporte' => $dep, 'tipo' => $accion, 'fecha' => $fecha,
              'evento' => mb_substr(trim(post('evento')), 0, 120), 'lugar' => mb_substr(trim(post('lugar')), 0, 120), 'categoria' => mb_substr(trim(post('categoria')), 0, 40),
              'notas' => mb_substr(trim(post('notas')), 0, 300), 'registrado_por' => $por];
        if ($accion === 'combate') {
            if (trim(post('rival')) === '') throw new RuntimeException('Escriba el nombre del rival.');
            $d += ['rival' => mb_substr(trim(post('rival')), 0, 80), 'rival_club' => mb_substr(trim(post('rival_club')), 0, 80),
                   'peso' => post('peso') === '' ? null : round((float)str_replace(',', '.', post('peso')), 1), 'rounds' => entero(post('rounds') ?: 0, 0, 15),
                   'resultado' => array_key_exists(post('resultado'), nombres_resultado()) ? post('resultado') : 'sin_decision',
                   'metodo' => mb_substr(trim(post('metodo')), 0, 40), 'marca' => mb_substr(trim(post('marca')), 0, 60)];
        } else {
            if (trim(post('prueba')) === '') throw new RuntimeException('Escriba la prueba (ej: 50 m libre).');
            $unidad = in_array(post('unidad'), ['m', 'p'], true) ? post('unidad') : 's'; // (v26) p = puntaje (bolos)
            $valor = marca_a_valor(post('marca'));
            if ($valor === null) throw new RuntimeException('Escriba la marca así: 1:05.30 (tiempo), 4.85 (metros) o 548 (puntaje).');
            $puesto = post('puesto') === '' ? null : entero(post('puesto'), 1, 200);
            $d += ['prueba' => mb_substr(trim(post('prueba')), 0, 60), 'marca' => mb_substr(trim(post('marca')), 0, 20), 'valor' => $valor, 'unidad' => $unidad,
                   'puesto' => $puesto, 'medalla' => medalla_de_puesto($puesto)];
        }
        q('INSERT INTO esc_competencias (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_fill(0, count($d), '?')) . ')', array_values($d));
        flash($accion === 'combate' ? 'Registrado. El récord del alumno se actualizó.' : 'Marca registrada.' . ($d['medalla'] ? ' ¡Con medalla ' . icono_medalla($d['medalla']) . '!' : ''));
        ir('admin.php?p=competencias&ver=' . ($accion === 'combate' ? 'combates' : 'pruebas'));
    }
    if ($accion === 'grado') {
        $a = $alumno_ok((int)post('alumno_id'));
        // (v31) El grado se elige de la lista de cinturones de la escuela (o se escribe otro)
        $cint = (int)post('cinturon_id') ? fila('SELECT * FROM esc_cinturones WHERE id = ? AND liga_id = ?', [(int)post('cinturon_id'), $lid]) : null;
        $grado = trim(post('grado_otro')) !== '' ? trim(post('grado_otro')) : ($cint ? etiqueta_cinturon($cint) : trim(post('grado')));
        if ($grado === '') throw new RuntimeException('Elija el grado.');
        if (trim(post('grado_otro')) !== '') $cint = null;
        q('INSERT INTO esc_grados (alumno_id, fecha, grado, examinador, notas, registrado_por, cinturon_id) VALUES (?,?,?,?,?,?,?)', [
            (int)$a['id'], post('fecha') && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : date('Y-m-d'), mb_substr($grado, 0, 60),
            mb_substr(trim(post('examinador')), 0, 80), mb_substr(trim(post('notas')), 0, 300), $por, $cint['id'] ?? null]);
        flash('Grado registrado: ' . $grado . '. Ya sale en la ficha, el carnet y el portal de padres.');
        ir('admin.php?p=competencias&ver=cinturones');
    }
    if ($accion === 'borrar') {
        $x = fila('SELECT c.* FROM esc_competencias c WHERE c.id = ? AND c.liga_id = ?', [(int)post('id'), $lid]) ?? throw new RuntimeException('No encontrado.');
        $alumno_ok((int)$x['alumno_id']);
        q('DELETE FROM esc_competencias WHERE id = ?', [$x['id']]);
        flash('Borrado.');
        ir('admin.php?p=competencias&ver=' . ($x['tipo'] === 'combate' ? 'combates' : 'pruebas'));
    }
    if ($accion === 'borrar_grado') {
        $x = fila('SELECT gr.* FROM esc_grados gr JOIN esc_alumnos a ON a.id = gr.alumno_id WHERE gr.id = ? AND a.liga_id = ?', [(int)post('id'), $lid]) ?? throw new RuntimeException('No encontrado.');
        $alumno_ok((int)$x['alumno_id']);
        q('DELETE FROM esc_grados WHERE id = ?', [$x['id']]);
        flash('Grado borrado.');
        ir('admin.php?p=competencias&ver=cinturones');
    }
}

cabecera_admin('Competencias');
$opc_al = '<option value="">— elegir alumno —</option>';
foreach ($alumnos as $a) $opc_al .= '<option value="' . (int)$a['id'] . '">' . e(nombre_alumno($a)) . '</option>';
?>
<h1>🥇 Competencias</h1>
<?php if (!$tabs): ?>
  <div class="tarjeta">Aquí se registran los <b>combates</b> (boxeo, artes marciales), los <b>partidos de tenis</b>, los <b>cinturones</b> y las <b>pruebas con medallas</b> (natación, atletismo).
  Para usarlo, cree un grupo de uno de esos deportes en <a href="admin.php?p=grupos">Grupos y horarios</a> (campo <b>Deporte</b>).</div>
<?php pie_admin(); return; endif; ?>
<nav class="pestanas"><?php foreach ($tabs as $k => $t): ?><a class="<?= $ver === $k ? 'activa' : '' ?>" href="admin.php?p=competencias&amp;ver=<?= $k ?>"><?= e($t) ?></a><?php endforeach; ?></nav>
<form method="get" class="acciones" style="margin-bottom:10px"><input type="hidden" name="p" value="competencias"><input type="hidden" name="ver" value="<?= e($ver) ?>">
  <select name="g" onchange="this.form.submit()"><option value="">Todos los grupos</option><?= opciones_grupos($gs_ver, $gid ?: null, false) ?></select></form>

<?php if ($ver === 'combates'):
    $fam0 = familia_deporte(deporte_grupo($gs_ver[0], $liga));
    // (v31) Categorías de peso según la disciplina de los grupos (World Taekwondo, WKF, IJF o boxeo) y categoría sugerida por alumno
    $discs_c = [];
    foreach ($gs_ver as $g) { $dg = deporte_grupo($g, $liga); $discs_c[] = es_deporte_marcial($dg) ? disciplina_de($dg) : 'boxeo'; }
    $discs_c = array_values(array_unique($discs_c));
    $opc_pesos = [];
    foreach ($discs_c as $dc) {
        foreach (['M' => 'masculino', 'F' => 'femenino'] as $gk => $gn) {
            foreach ($dc === 'taekwondo' ? ['Infantil', 'Cadete', 'Junior', 'Adulto'] : ['Adulto'] as $ge) {
                foreach (categorias_peso_disciplina($dc === 'boxeo' ? 'boxeo' : $dc, $gk, $ge) as $c) {
                    $opc_pesos[] = ($dc === 'taekwondo' ? $ge . ' ' . $gn . ' ' : ($dc === 'boxeo' ? '' : ucfirst($gn) . ' ')) . ($dc === 'boxeo' ? ltrim($c, '-') : $c) . ' kg';
                }
            }
        }
    }
    $sug_peso = [];
    foreach ($alumnos as $a) {
        $pm = valor('SELECT peso FROM esc_mediciones WHERE alumno_id = ? AND peso IS NOT NULL ORDER BY fecha DESC, id DESC LIMIT 1', [$a['id']]);
        $da = disciplina_alumno($a, $liga);
        if ($pm && in_array($da, ['taekwondo', 'karate', 'judo'], true)) $sug_peso[(int)$a['id']] = categoria_marcial((float)$pm, $da, $a['genero'] === 'F' ? 'F' : 'M', $a['fecha_nac']);
    }
    $voc_c = count($discs_c) === 1 && $discs_c[0] !== 'boxeo' ? vocabulario_marcial($discs_c[0]) : null;
    $lista = $ids_al ? filas('SELECT c.*, a.nombres, a.apellidos FROM esc_competencias c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE c.tipo = \'combate\' AND c.alumno_id IN (' . implode(',', $ids_al) . ') ORDER BY c.fecha DESC, c.id DESC LIMIT 100') : [];
    $records = [];
    foreach ($alumnos as $a) { $r = record_alumno((int)$a['id']); if ($r['n']) $records[] = $a + ['r' => $r]; }
    usort($records, fn($x, $y) => [$y['r']['g'], -$y['r']['p']] <=> [$x['r']['g'], -$x['r']['p']]); ?>
  <div class="rejilla-2">
    <div class="tarjeta">
      <h2 style="margin-top:0">🏆 Récord de cada alumno</h2>
      <?php if (!$records): ?><p class="suave">Todavía no hay combates ni partidos registrados.</p><?php else: ?>
      <div class="tabla-scroll"><table><tr><th>Alumno</th><th class="num">G</th><th class="num">P</th><th class="num">E</th><th class="num">Total</th></tr>
        <?php foreach ($records as $x): ?><tr><td><a href="admin.php?p=alumno&amp;id=<?= (int)$x['id'] ?>&amp;s=club"><?= e(nombre_alumno($x)) ?></a></td><td class="num"><b><?= $x['r']['g'] ?></b></td><td class="num"><?= $x['r']['p'] ?></td><td class="num"><?= $x['r']['e'] ?></td><td class="num"><?= $x['r']['n'] ?></td></tr><?php endforeach; ?>
      </table></div><?php endif; ?>
    </div>
    <?php if ($ed): ?>
    <div class="tarjeta">
      <h2 style="margin-top:0">+ Registrar <?= $voc_c ? e(mb_strtolower($voc_c['combate'])) : 'combate o partido' ?></h2>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="combate">
        <div class="campos">
          <div><label>Alumno *</label><select name="alumno_id" required id="c-alumno"><?= $opc_al ?></select></div>
          <div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
          <div><label>Evento / torneo</label><input type="text" name="evento" maxlength="120" placeholder="Ej: Copa Pichincha juvenil"></div>
          <div><label>Lugar</label><input type="text" name="lugar" maxlength="120"></div>
          <div><label>Rival *</label><input type="text" name="rival" required maxlength="80"></div>
          <div><label>Club del rival</label><input type="text" name="rival_club" maxlength="80"></div>
          <div><label>Resultado</label><select name="resultado"><?php foreach (nombres_resultado() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
          <div><label>Método</label><input type="text" name="metodo" maxlength="40" list="metodos" placeholder="Puntos, KO, ippon, sets…"><datalist id="metodos"><?php foreach (array_unique(array_merge(metodos_combate('boxeo'), metodos_combate('artes_marciales'), metodos_combate('tenis'))) as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist></div>
          <div><label>Rounds (boxeo)</label><input type="number" name="rounds" min="0" max="15"></div>
          <div><label>Marcador (tenis)</label><input type="text" name="marca" maxlength="60" placeholder="Ej: 6-4, 3-6, 7-5"></div>
          <div><label>Categoría de peso</label><input type="text" name="categoria" id="c-cat" maxlength="40" list="pesos" placeholder="Ej: <?= in_array('taekwondo', $discs_c, true) ? 'Cadete masculino -45 kg' : '57 kg' ?>"><datalist id="pesos"><?php foreach (array_unique($opc_pesos) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
            <?php if ($sug_peso): ?><div class="ayuda">Al elegir el alumno se sugiere su categoría con su último peso medido.</div><script>(function () { var s = <?= json_encode($sug_peso, JSON_UNESCAPED_UNICODE) ?>; var al = document.getElementById('c-alumno'), ca = document.getElementById('c-cat'); al.addEventListener('change', function () { if (s[al.value] && (ca.value === '' || ca.dataset.auto === '1')) { ca.value = s[al.value]; ca.dataset.auto = '1'; } }); ca.addEventListener('input', function () { ca.dataset.auto = ''; }); })();</script><?php endif; ?></div>
          <div><label>Peso en el pesaje (kg)</label><input type="number" name="peso" min="10" max="200" step="0.1"><div class="ayuda">Se compara con su última medición.</div></div>
          <div class="campo-ancho"><label>Notas</label><input type="text" name="notas" maxlength="300"></div>
        </div>
        <button class="btn">💾 Guardar</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <h2>Últimos registros</h2>
  <?php if (!$lista): ?><p class="suave">Sin registros.</p><?php else: ?>
  <div class="tabla-scroll"><table><tr><th>Fecha</th><th>Alumno</th><th>Deporte</th><th>Rival</th><th>Resultado</th><th>Evento</th><th>Peso</th><th></th></tr>
    <?php foreach ($lista as $c): $med = fila('SELECT peso FROM esc_mediciones WHERE alumno_id = ? AND peso IS NOT NULL ORDER BY fecha DESC LIMIT 1', [$c['alumno_id']]); ?>
      <tr><td><?= e(fecha_corta($c['fecha'])) ?></td><td><?= e(nombre_alumno($c)) ?></td><td><?= $c['deporte'] ? chip_deporte($c['deporte']) : '' ?></td>
        <td><?= e($c['rival']) ?><?= $c['rival_club'] ? '<br><small class="suave">' . e($c['rival_club']) . '</small>' : '' ?></td>
        <td><?= e(nombres_resultado()[$c['resultado']] ?? '') ?><?= $c['metodo'] ? ' · ' . e($c['metodo']) : '' ?><?= $c['marca'] ? ' · ' . e($c['marca']) : '' ?><?= (int)$c['rounds'] ? ' · ' . (int)$c['rounds'] . ' rounds' : '' ?></td>
        <td><?= e($c['evento']) ?></td>
        <td><?= $c['peso'] ? e(fmt_num($c['peso'], 1)) . ' kg' . ($c['categoria'] ? '<br><small class="suave">' . e($c['categoria']) . '</small>' : '') : e($c['categoria']) ?><?= $c['peso'] && $med && abs((float)$med['peso'] - (float)$c['peso']) >= 1 ? '<br><small class="suave">Última medición: ' . e(fmt_num($med['peso'], 1)) . ' kg</small>' : '' ?></td>
        <td><?php if ($ed): ?><form method="post" class="inline" onsubmit="return confirm('¿Borrar?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sec btn-chico">✕</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></table></div>
  <?php endif; ?>

<?php elseif ($ver === 'cinturones'):
    // (v31) Cinturones de la lista de la escuela (por disciplina) con su dibujo; los exámenes completos están en Exámenes de grado
    $discs_g = array_values(array_unique(array_map(fn($g) => disciplina_de(deporte_grupo($g, $liga)), $gs_ver)));
    $lista = $ids_al ? filas('SELECT gr.*, a.nombres, a.apellidos FROM esc_grados gr JOIN esc_alumnos a ON a.id = gr.alumno_id WHERE gr.alumno_id IN (' . implode(',', $ids_al) . ') ORDER BY gr.fecha DESC, gr.id DESC LIMIT 100') : []; ?>
  <div class="rejilla-2">
    <div class="tarjeta">
      <h2 style="margin-top:0">🥋 Grado actual</h2>
      <p class="acciones"><a class="btn btn-chico" href="admin.php?p=examenes">🥋 Exámenes de grado</a> <a class="btn btn-sec btn-chico" href="admin.php?p=examenes&amp;v=cinturones">⚙ Lista de cinturones</a></p>
      <div class="tabla-scroll"><table><tr><th>Alumno</th><th>Grado</th><th>Desde</th></tr>
        <?php foreach ($alumnos as $a): [$gr, $act] = cinturon_alumno($a, $liga); ?><tr><td><a href="admin.php?p=alumno&amp;id=<?= (int)$a['id'] ?>"><?= e(nombre_alumno($a)) ?></a></td><td><?= $gr ? html_cinturon($act, $act ? '' : $gr['grado']) : '<span class="suave">sin registrar</span>' ?></td><td><small><?= $gr ? e(fecha_corta($gr['fecha'])) : '' ?></small></td></tr><?php endforeach; ?>
      </table></div>
    </div>
    <?php if ($ed): ?>
    <div class="tarjeta">
      <h2 style="margin-top:0">+ Registrar un grado</h2>
      <p class="ayuda">Para un grado que ya tenía el alumno o que rindió en otro lugar. Para tomar un examen en su escuela (con cobro, acta y certificados) use <a href="admin.php?p=examenes">Exámenes de grado</a>.</p>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="grado">
        <div class="campos">
          <div><label>Alumno *</label><select name="alumno_id" required><?= $opc_al ?></select></div>
          <div><label>Fecha del examen</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
          <div><label>Cinturón</label><select name="cinturon_id"><option value="">—</option><?php foreach ($discs_g as $dg): ?><optgroup label="<?= e(disciplinas_marciales()[$dg] ?? $dg) ?>"><?php foreach (cinturones_escuela($lid, $dg) as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e(etiqueta_cinturon($x)) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
          <div><label>…u otro grado</label><input type="text" name="grado_otro" maxlength="40" placeholder="Ej: Amarillo punta verde"></div>
          <div><label>Examinador</label><input type="text" name="examinador" maxlength="80"></div>
          <div><label>Notas</label><input type="text" name="notas" maxlength="300"></div>
        </div>
        <button class="btn">💾 Guardar grado</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <h2>Historial de grados</h2>
  <?php if (!$lista): ?><p class="suave">Todavía no hay grados registrados.</p><?php else: ?>
  <div class="tabla-scroll"><table><tr><th>Fecha</th><th>Alumno</th><th>Grado</th><th>Examinador</th><th></th></tr>
    <?php foreach ($lista as $x): ?><tr><td><?= e(fecha_corta($x['fecha'])) ?></td><td><?= e(nombre_alumno($x)) ?></td><td><b><?= e($x['grado']) ?></b></td><td><?= e($x['examinador']) ?></td>
      <td><?php if ($ed): ?><form method="post" class="inline" onsubmit="return confirm('¿Borrar?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_grado"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-chico">✕</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>

<?php else: /* pruebas */
    $fams = array_values(array_unique(array_map(fn($g) => familia_deporte(deporte_grupo($g, $liga)), $gs_ver)));
    $lista = $ids_al ? filas('SELECT c.*, a.nombres, a.apellidos, a.fecha_nac FROM esc_competencias c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE c.tipo = \'prueba\' AND c.alumno_id IN (' . implode(',', $ids_al) . ') ORDER BY c.fecha DESC, c.id DESC LIMIT 150') : [];
    $medallero = [];
    foreach ($lista as $c) if ($c['medalla']) { $k = (int)$c['alumno_id']; $medallero[$k] ??= ['n' => nombre_alumno($c), 'oro' => 0, 'plata' => 0, 'bronce' => 0]; $medallero[$k][$c['medalla']]++; }
    uasort($medallero, fn($x, $y) => [$y['oro'], $y['plata'], $y['bronce']] <=> [$x['oro'], $x['plata'], $x['bronce']]);
    $mejores = [];
    foreach ($lista as $c) {
        $k = $c['prueba'] . '|' . sub_de($c['fecha_nac']);
        $mejor = in_array($c['unidad'], ['m', 'p'], true) ? fn($a, $b) => $a > $b : fn($a, $b) => $a < $b;
        if (!isset($mejores[$k]) || $mejor((float)$c['valor'], (float)$mejores[$k]['valor'])) $mejores[$k] = $c;
    }
    ksort($mejores); ?>
  <div class="rejilla-2">
    <div class="tarjeta">
      <h2 style="margin-top:0">🥇 Medallero</h2>
      <?php if (!$medallero): ?><p class="suave">Todavía no hay medallas.</p><?php else: ?>
      <div class="tabla-scroll"><table><tr><th>Alumno</th><th class="num">🥇</th><th class="num">🥈</th><th class="num">🥉</th></tr>
        <?php foreach ($medallero as $m): ?><tr><td><?= e($m['n']) ?></td><td class="num"><b><?= $m['oro'] ?></b></td><td class="num"><?= $m['plata'] ?></td><td class="num"><?= $m['bronce'] ?></td></tr><?php endforeach; ?>
      </table></div><?php endif; ?>
    </div>
    <?php if ($ed): ?>
    <div class="tarjeta">
      <h2 style="margin-top:0">+ Registrar marca</h2>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="prueba">
        <div class="campos">
          <div><label>Alumno *</label><select name="alumno_id" required><?= $opc_al ?></select></div>
          <div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
          <div><label>Evento / torneo</label><input type="text" name="evento" maxlength="120" placeholder="Ej: Interescolar de natación"></div>
          <div><label>Prueba *</label><input type="text" name="prueba" required maxlength="60" list="pruebas" placeholder="Ej: 50 m libre"><datalist id="pruebas"><?php foreach ($fams as $f) foreach (pruebas_referencia($f) as $x): ?><option value="<?= e($x) ?>"><?php endforeach; ?></datalist></div>
          <div><label>Marca *</label><input type="text" name="marca" required maxlength="20" placeholder="1:05.30 o 4.85"><div class="ayuda">Tiempo: minutos:segundos.centésimas · Distancia: metros.</div></div>
          <div><label>Es</label><select name="unidad"><option value="s">⏱ Tiempo (gana el menor)</option><option value="m">📏 Distancia (gana el mayor)</option><option value="p">🎳 Puntaje (gana el mayor)</option></select></div>
          <div><label>Puesto</label><input type="number" name="puesto" min="1" max="200"><div class="ayuda">1, 2 o 3 = medalla automática.</div></div>
          <div><label>Categoría</label><input type="text" name="categoria" maxlength="40" placeholder="Ej: Sub-11 mujeres"></div>
          <div class="campo-ancho"><label>Notas</label><input type="text" name="notas" maxlength="300"></div>
        </div>
        <button class="btn">💾 Guardar marca</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <h2>⭐ Mejores marcas por prueba y categoría</h2>
  <?php if (!$mejores): ?><p class="suave">Todavía no hay marcas.</p><?php else: ?>
  <div class="tabla-scroll"><table><tr><th>Prueba</th><th>Categoría</th><th>Alumno</th><th>Marca</th><th>Fecha</th></tr>
    <?php foreach ($mejores as $k => $c): ?><tr><td><?= e($c['prueba']) ?></td><td><?= e(explode('|', $k)[1]) ?></td><td><?= e(nombre_alumno($c)) ?></td><td><b><?= e($c['marca']) ?></b><?= $c['unidad'] === 'm' ? ' m' : ($c['unidad'] === 'p' ? ' pts' : '') ?></td><td><?= e(fecha_corta($c['fecha'])) ?></td></tr><?php endforeach; ?>
  </table></div><?php endif; ?>
  <h2>Últimas marcas</h2>
  <?php if (!$lista): ?><p class="suave">Sin registros.</p><?php else: ?>
  <div class="tabla-scroll"><table><tr><th>Fecha</th><th>Alumno</th><th>Prueba</th><th>Marca</th><th>Puesto</th><th>Evento</th><th></th></tr>
    <?php foreach ($lista as $c): ?><tr><td><?= e(fecha_corta($c['fecha'])) ?></td><td><?= e(nombre_alumno($c)) ?></td><td><?= $c['deporte'] ? icono_deporte_escuela($c['deporte']) . ' ' : '' ?><?= e($c['prueba']) ?></td><td><b><?= e($c['marca']) ?></b><?= $c['unidad'] === 'm' ? ' m' : ($c['unidad'] === 'p' ? ' pts' : '') ?></td>
      <td><?= $c['puesto'] ? (int)$c['puesto'] . 'º ' . icono_medalla($c['medalla']) : '' ?></td><td><?= e($c['evento']) ?></td>
      <td><?php if ($ed): ?><form method="post" class="inline" onsubmit="return confirm('¿Borrar?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sec btn-chico">✕</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </table></div><?php endif; ?>
<?php endif; ?>
<?php
pie_admin();
