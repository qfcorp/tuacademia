<?php
/* (v26) Administración de una competencia de NATACIÓN o ATLETISMO: programa de pruebas, competidores, marcas, medallero y puntos */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$t = torneo_de_liga((int)get('id'), $lid);
$mod_req = modulo_de_torneo($t); // (v35) requisitos del módulo deportivo
$tid = (int)$t['id'];
if (!es_pruebas($t)) ir(es_individual($t) ? url_admin_individual($t) : 'admin.php?p=torneo&id=' . $tid);
$T = textos_pruebas($t);
$cfg = pruebas_cfg($t);
$s = get('s', 'resultados');
if (!in_array($s, ['resultados', 'participantes', 'programa', 'medallero', 'config'], true)) $s = 'resultados';
$url = 'admin.php?p=pruebas&id=' . $tid;
$ciclo_p = es_ciclo_pista($t); // (v34) pista, BMX y freestyle: pruebas con formato
$pv = es_pruebas_v36($t); // (v36) atletismo (series, intentos, alturas, combinadas), ruta, pesas y triatlón
$formatos_t = $ciclo_p ? pista_formatos($t['modalidad']) : ($pv ? pv_formatos($t['modalidad']) : []);
$medidas = ['tiempo' => 'Tiempo (gana el menor)', 'metros' => 'Metros (gana el mayor)'] + ($ciclo_p ? ['puesto' => 'Puesto de llegada (scratch, eliminación)'] : []);
$evento = function (int $id) use ($tid): array {
    return fila('SELECT * FROM prueba_eventos WHERE id = ? AND torneo_id = ?', [$id, $tid]) ?? throw new RuntimeException('Prueba no encontrada.');
};

if (es_post()) {
    $accion = post('accion');

    if ($accion === 'config') {
        if (post('nombre') === '') throw new RuntimeException('El nombre no puede quedar vacío.');
        $estado = in_array(post('estado'), ['inscripcion', 'en_juego', 'finalizado'], true) ? post('estado') : $t['estado'];
        $puntos = preg_split('/[\s,;]+/', trim(post('puntos')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($puntos as $p) if (!ctype_digit($p)) throw new RuntimeException('Los puntos por puesto se escriben como números separados por comas. Ej: 9,7,6,5,4,3,2,1');
        q('UPDATE torneos SET nombre = ?, temporada = ?, estado = ?, carrera_puntos = ? WHERE id = ?', [mb_substr(post('nombre'), 0, 100), mb_substr(post('temporada'), 0, 30), $estado, implode(',', $puntos) ?: '9,7,6,5,4,3,2,1', $tid]);
        if (post('slug') !== $t['slug']) guardar_slug_torneo(array_merge($t, ['nombre' => post('nombre'), 'temporada' => post('temporada')]), post('slug'));
        flash('Reglas guardadas.');
        ir($url . '&s=config');
    }

    if ($accion === 'eliminar') {
        if (post('confirmar') !== $t['nombre']) throw new RuntimeException('Escriba el nombre exacto del campeonato para eliminarlo.');
        q('DELETE FROM torneos WHERE id = ?', [$tid]);
        flash('Campeonato eliminado.');
        ir('admin.php?p=torneos');
    }

    if ($accion === 'programa_generar') {
        $pr = array_values(array_filter((array)($_POST['pruebas'] ?? []), 'is_string'));
        $ra = array_values(array_filter((array)($_POST['ramas'] ?? []), 'is_string'));
        $ca = array_map('intval', (array)($_POST['cats'] ?? []));
        if (!$pr || !$ra || !$ca) throw new RuntimeException('Marque al menos una prueba, una rama y una categoría.');
        $f = post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : '';
        $n = pruebas_crear_programa($t, $pr, $ra, $ca, $f);
        flash($n ? "Se crearon $n pruebas en el programa." : 'Esas pruebas ya estaban en el programa.');
        ir($url . '&s=programa');
    }

    if ($accion === 'evento_guardar') {
        $id = (int)post('id');
        $nombre = mb_substr(trim(post('nombre')), 0, 60);
        if ($nombre === '') throw new RuntimeException('Escriba el nombre de la prueba (ej: 50 m libre).');
        $d = [$nombre, isset($medidas[post('medida')]) ? post('medida') : 'tiempo', array_key_exists(post('rama'), pruebas_ramas()) ? post('rama') : '',
            entero(post('edad_min'), 0, 99), entero(post('edad_max'), 0, 99), mb_substr(trim(post('categoria')), 0, 40),
            post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : '', preg_match('/^\d{2}:\d{2}$/', post('hora')) ? post('hora') : '', mb_substr(post('nota'), 0, 120)];
        $formato = isset($formatos_t[post('formato')]) ? post('formato') : (string)(array_key_first($formatos_t) ?? 'marca');
        if (!$formatos_t) $formato = 'marca';
        $config = $ciclo_p ? pista_cfg_post($formato) : ($pv ? pv_cfg_post($formato) : '');
        if ($id > 0) {
            $ev = $evento($id);
            if ($ev['medida'] !== $d[1] && prueba_con_marcas($id)) throw new RuntimeException('La prueba ya tiene marcas: no se puede cambiar cómo se mide.');
            if (($ev['formato'] ?: 'marca') !== $formato && prueba_con_marcas($id)) throw new RuntimeException('La prueba ya tiene resultados: no se puede cambiar el formato.');
            q('UPDATE prueba_eventos SET nombre = ?, medida = ?, rama = ?, edad_min = ?, edad_max = ?, categoria = ?, fecha = ?, hora = ?, nota = ?, num = ?, formato = ?, config = ? WHERE id = ?', array_merge($d, [entero(post('num'), 1, 999), $formato, $config, $id]));
            flash('Prueba guardada.');
        } else {
            $num = (int)valor('SELECT COALESCE(MAX(num), 0) + 1 FROM prueba_eventos WHERE torneo_id = ?', [$tid]);
            q('INSERT INTO prueba_eventos (nombre, medida, rama, edad_min, edad_max, categoria, fecha, hora, nota, num, formato, config, torneo_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($d, [$num, $formato, $config, $tid]));
            flash('Prueba ' . $num . ' agregada.');
        }
        ir($url . '&s=programa');
    }

    if ($accion === 'evento_borrar') {
        $ev = $evento((int)post('id'));
        if (prueba_con_marcas((int)$ev['id'])) throw new RuntimeException('La prueba ya tiene marcas: no se puede eliminar.');
        q('DELETE FROM prueba_eventos WHERE id = ?', [$ev['id']]);
        flash('Prueba eliminada.');
        ir($url . '&s=programa');
    }

    if ($accion === 'part_guardar') {
        $id = (int)post('id');
        $nombre = mb_substr(trim(post('nombre')), 0, 80);
        if ($nombre === '') throw new RuntimeException('Escriba el nombre.');
        $nac = post('nacimiento') !== '' && strtotime(post('nacimiento')) ? date('Y-m-d', strtotime(post('nacimiento'))) : '';
        $d = [mb_substr(post('numero'), 0, 6), $nombre, mb_substr(post('club'), 0, 60), mb_substr(post('ciudad'), 0, 40), array_key_exists(post('rama'), ['M' => 1, 'F' => 1]) ? post('rama') : '', $nac, post('activo', '1') === '1' ? 1 : 0];
        if ($id > 0) {
            fila('SELECT id FROM prueba_participantes WHERE id = ? AND torneo_id = ?', [$id, $tid]) ?? throw new RuntimeException('No encontrado.');
            q('UPDATE prueba_participantes SET numero = ?, nombre = ?, club = ?, ciudad = ?, rama = ?, nacimiento = ?, activo = ? WHERE id = ?', array_merge($d, [$id]));
        } else {
            exigir_autorizacion(); // (v24) protección de datos
            q('INSERT INTO prueba_participantes (numero, nombre, club, ciudad, rama, nacimiento, activo, torneo_id) VALUES (?,?,?,?,?,?,?,?)', array_merge($d, [$tid]));
            $id = (int)db()->lastInsertId();
            registrar_autorizacion_tercero('participante_pruebas', $lid, $nombre, 'la organización del campeonato ' . $t['nombre'], ['ref_tabla' => 'prueba_participantes', 'ref_id' => $id]);
        }
        guardar_requisitos($lid, $mod_req, 'prueba_participantes', $id); // (v35)
        // Pruebas en las que participa
        $p = fila('SELECT * FROM prueba_participantes WHERE id = ?', [$id]);
        $marcadas = array_map('intval', (array)($_POST['eventos'] ?? []));
        $avisos = [];
        foreach (pruebas_eventos($tid) as $ev) {
            $esta = (bool)fila('SELECT id FROM prueba_resultados WHERE evento_id = ? AND participante_id = ?', [$ev['id'], $id]);
            if (in_array((int)$ev['id'], $marcadas, true) && !$esta) {
                if ($m = prueba_no_puede($ev, $p, $t)) { $avisos[] = prueba_nombre($ev, false) . ' (' . $m . ')'; continue; }
                q('INSERT INTO prueba_resultados (evento_id, participante_id) VALUES (?, ?)', [$ev['id'], $id]);
            } elseif (!in_array((int)$ev['id'], $marcadas, true) && $esta) {
                $r = fila('SELECT * FROM prueba_resultados WHERE evento_id = ? AND participante_id = ?', [$ev['id'], $id]);
                if ($r['marca'] !== null || $r['estado'] !== 'ok') { $avisos[] = prueba_nombre($ev, false) . ' (ya tiene marca, no se quitó)'; continue; }
                q('DELETE FROM prueba_resultados WHERE id = ?', [$r['id']]);
            }
        }
        flash('Datos guardados.' . ($avisos ? ' No se inscribió en: ' . implode(' · ', $avisos) . '.' : ''), $avisos ? 'error' : 'ok');
        ir($url . '&s=participantes');
    }

    if ($accion === 'part_borrar') {
        $id = (int)post('id');
        if ((int)valor("SELECT COUNT(*) FROM prueba_resultados WHERE participante_id = ? AND (marca IS NOT NULL OR estado <> 'ok')", [$id])) throw new RuntimeException('Ya tiene marcas cargadas: márquelo como retirado en vez de eliminarlo.');
        q('DELETE FROM prueba_participantes WHERE id = ? AND torneo_id = ?', [$id, $tid]); borrar_requisitos('prueba_participantes', $id);
        flash('Eliminado.');
        ir($url . '&s=participantes');
    }

    if ($accion === 'inscribir_en') {
        $ev = $evento((int)post('evento_id'));
        $p = fila('SELECT * FROM prueba_participantes WHERE id = ? AND torneo_id = ?', [(int)post('participante_id'), $tid]) ?? throw new RuntimeException('Elija a quién inscribir.');
        prueba_inscribir($t, $ev, $p);
        flash($p['nombre'] . ' inscrito en la prueba.');
        ir($url . '&s=resultados&ev=' . (int)$ev['id']);
    }

    if ($accion === 'marcas_guardar') {
        $ev = $evento((int)post('evento_id'));
        $part = pruebas_participantes($tid);
        $n = 0;
        db()->beginTransaction();
        try {
            foreach (filas('SELECT * FROM prueba_resultados WHERE evento_id = ?', [$ev['id']]) as $r) {
                $pid = (int)$r['participante_id'];
                if (isset($_POST['quitar'][$pid])) {
                    if ($r['marca'] === null && $r['estado'] === 'ok') q('DELETE FROM prueba_resultados WHERE id = ?', [$r['id']]);
                    continue;
                }
                $est = (string)($_POST['est'][$pid] ?? 'ok');
                if (!isset(pruebas_estados()[$est])) $est = 'ok';
                try {
                    $marca = $est === 'ok' ? prueba_parse_marca($ev['medida'], (string)($_POST['marca'][$pid] ?? '')) : null;
                } catch (RuntimeException $ex) {
                    throw new RuntimeException(($part[$pid]['nombre'] ?? '') . ': ' . $ex->getMessage());
                }
                q('UPDATE prueba_resultados SET serie = ?, carril = ?, marca = ?, estado = ?, nota = ? WHERE id = ?', [entero($_POST['serie'][$pid] ?? 0, 0, 99), entero($_POST['carril'][$pid] ?? 0, 0, 99),
                    $marca, $est, mb_substr(trim((string)($_POST['nota'][$pid] ?? '')), 0, 120), $r['id']]);
                if (isset($_POST['viento'][$pid])) { // (v36) viento de cada uno
                    try { $vi = pv_leer_viento((string)$_POST['viento'][$pid]); } catch (RuntimeException $ex) { throw new RuntimeException(($part[$pid]['nombre'] ?? '') . ': ' . $ex->getMessage()); }
                    $dd = json_decode((string)$r['datos'], true) ?: [];
                    if ($vi === null) unset($dd['v']); else $dd['v'] = $vi;
                    q('UPDATE prueba_resultados SET datos = ? WHERE id = ?', [$dd ? json_encode($dd) : '', $r['id']]);
                }
                if ($marca !== null || $est !== 'ok') $n++;
            }
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            throw $ex;
        }
        if ($t['estado'] === 'inscripcion') q("UPDATE torneos SET estado = 'en_juego' WHERE id = ?", [$tid]);
        flash("Marcas guardadas ($n). Los puestos, medallas y puntos ya están actualizados.");
        ir($url . '&s=resultados&ev=' . (int)$ev['id']);
    }

    // (v34) cuadros, series, grillas de puntos y notas de jueces (ciclismo)
    if ($accion === 'pista' && ($ciclo_p || $pv)) {
        $ev = $evento((int)post('evento_id'));
        db()->beginTransaction();
        try {
            $msg = $pv ? pv_accion($t, $ev, post('sub')) : pista_accion($t, $ev, post('sub'));
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            throw $ex;
        }
        if ($t['estado'] === 'inscripcion') q("UPDATE torneos SET estado = 'en_juego' WHERE id = ?", [$tid]);
        flash($msg);
        ir($url . '&s=resultados&ev=' . (int)$ev['id']);
    }

    if ($accion === 'ejemplo') {
        pruebas_cargar_ejemplo($t);
        flash('Se cargó un ejemplo con pruebas, competidores y marcas. Bórrelos cuando ya no los necesite.');
        ir($url . '&s=medallero');
    }
    ir($url);
}

$t = fila('SELECT * FROM torneos WHERE id = ?', [$tid]);
$eventos = pruebas_eventos($tid);
$part = pruebas_participantes($tid);
$estados_t = ['inscripcion' => 'Inscripción', 'en_juego' => 'En juego', 'finalizado' => 'Finalizado'];
$n_marcas = (int)valor('SELECT COUNT(*) FROM prueba_resultados r JOIN prueba_eventos e ON e.id = r.evento_id WHERE e.torneo_id = ? AND r.marca IS NOT NULL', [$tid]);

cabecera_admin($t['nombre']);
?>
<p><a href="admin.php?p=torneos">← Campeonatos</a></p>
<div class="fila-titulo">
  <h1><?= icono_individual($t) ?> <?= e($t['nombre']) ?> <small><?= e($t['temporada']) ?> · <?= e(nombre_modalidad($t['modalidad'])) ?> · <?= e($estados_t[$t['estado']] ?? $t['estado']) ?></small></h1>
  <span class="acciones"><?= html_botones_individual($t) ?></span>
</div>
<p class="suave" style="margin-top:6px"><?= count($eventos) ?> pruebas · <?= count($part) ?> <?= e(mb_strtolower($T['participantes'])) ?> · <?= $n_marcas ?> marcas cargadas · puntos por puesto: <?= e(implode(', ', $cfg['puntos'])) ?></p>
<nav class="pestanas">
  <?php foreach (['resultados' => '1. Marcas y resultados', 'participantes' => '2. ' . $T['participantes'], 'programa' => 'Programa de pruebas', 'medallero' => '🏅 Medallero y puntos', 'config' => 'Reglas y configuración'] as $k => $txt): ?>
    <a href="<?= e($url . '&s=' . $k) ?>" class="<?= $s === $k ? 'activa' : '' ?>"><?= e($txt) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($s === 'programa'): ?>
  <div class="rejilla-2">
    <div class="tarjeta">
      <h2>Programa (<?= count($eventos) ?> pruebas)</h2>
      <?php if (!$eventos): ?><p class="suave">Aún no hay pruebas. Créelas con el formulario de la derecha.</p><?php endif; ?>
      <?php foreach ($eventos as $ev): $nr = (int)valor('SELECT COUNT(*) FROM prueba_resultados WHERE evento_id = ?', [$ev['id']]); ?>
        <details style="margin-bottom:6px"><summary><b><?= e(prueba_nombre($ev)) ?></b> <span class="suave"><?= $nr ?> inscritos<?= $ev['fecha'] !== '' ? ' · ' . e(fecha_corta($ev['fecha'])) : '' ?><?= $ev['hora'] !== '' ? ' ' . e($ev['hora']) : '' ?></span></summary>
          <form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="evento_guardar"><input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
            <div class="campos">
              <div><label>N.º</label><input type="number" name="num" value="<?= (int)$ev['num'] ?>" min="1"></div>
              <div><label>Prueba</label><input type="text" name="nombre" value="<?= e($ev['nombre']) ?>" maxlength="60" required></div>
              <div><label>Se mide en</label><select name="medida"><?php foreach ($medidas as $k => $x): ?><option value="<?= $k ?>" <?= $ev['medida'] === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></div>
              <div><label>Rama</label><select name="rama"><?php foreach (pruebas_ramas() as $k => $x): ?><option value="<?= $k ?>" <?= $ev['rama'] === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></div>
              <div><label>Categoría</label><input type="text" name="categoria" value="<?= e($ev['categoria']) ?>" maxlength="40"></div>
              <div><label>Edad desde</label><input type="number" name="edad_min" value="<?= (int)$ev['edad_min'] ?: '' ?>" min="0"></div>
              <div><label>Edad hasta</label><input type="number" name="edad_max" value="<?= (int)$ev['edad_max'] ?: '' ?>" min="0"></div>
              <div><label>Fecha</label><input type="date" name="fecha" value="<?= e($ev['fecha']) ?>"></div>
              <div><label>Hora</label><input type="time" name="hora" value="<?= e($ev['hora']) ?>"></div>
              <div><label>Nota</label><input type="text" name="nota" value="<?= e($ev['nota']) ?>" maxlength="120"></div>
              <?= $ciclo_p ? pista_html_cfg_campos($t, $ev) : ($pv ? pv_html_cfg_campos($t, $ev) : '') ?>
            </div>
            <button class="btn btn-chico">Guardar</button></form>
          <form method="post" onsubmit="return confirm('¿Eliminar la prueba?')" style="margin-top:6px"><?= csrf_campo() ?><input type="hidden" name="accion" value="evento_borrar"><input type="hidden" name="id" value="<?= (int)$ev['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar prueba</button></form>
        </details>
      <?php endforeach; ?>
    </div>
    <div>
      <div class="tarjeta">
        <h2>Armar el programa</h2>
        <p class="ayuda">Marque las pruebas, las ramas y las categorías: se crea una prueba por cada combinación (ej: 50 m libre · Damas · 11 y 12 años).</p>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="programa_generar">
          <label>Pruebas</label>
          <div class="campos" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr))">
            <?php foreach (pruebas_catalogo((string)$t['modalidad']) as [$pn, $med]): ?><label class="check" style="font-weight:400"><input type="checkbox" name="pruebas[]" value="<?= e($pn) ?>"> <?= e($pn) ?><?= $med === 'metros' ? ' <small class="suave">(m)</small>' : '' ?></label><?php endforeach; ?>
          </div>
          <label>Ramas</label>
          <p><?php foreach (['M' => 'Varones', 'F' => 'Damas', 'X' => 'Mixto'] as $k => $x): ?><label class="check" style="font-weight:400;display:inline-flex;margin-right:12px"><input type="checkbox" name="ramas[]" value="<?= $k ?>" <?= $k !== 'X' ? 'checked' : '' ?>> <?= e($x) ?></label><?php endforeach; ?></p>
          <label>Categorías de edad</label>
          <p><?php foreach (pruebas_categorias_base((string)$t['modalidad']) as $i => [$cn]): ?><label class="check" style="font-weight:400;display:inline-flex;margin-right:12px"><input type="checkbox" name="cats[]" value="<?= $i ?>"> <?= e($cn) ?></label><?php endforeach; ?></p>
          <div class="campos"><div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div></div>
          <button class="btn">Crear pruebas</button>
        </form>
      </div>
      <div class="tarjeta" style="margin-top:14px">
        <h2>Agregar una prueba a mano</h2>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="evento_guardar"><input type="hidden" name="id" value="0">
          <div class="campos">
            <div><label>Prueba *</label><input type="text" name="nombre" maxlength="60" required placeholder="<?= ['NATAC' => 'Ej: 50 m libre', 'PISTA' => 'Ej: Scratch 10 km', 'BMX' => 'Ej: BMX Racing', 'BMXFS' => 'Ej: Park'][$t['modalidad']] ?? 'Ej: 100 m planos' ?>"></div>
            <div><label>Se mide en</label><select name="medida"><?php foreach ($medidas as $k => $x): ?><option value="<?= $k ?>"><?= e($x) ?></option><?php endforeach; ?></select></div>
            <div><label>Rama</label><select name="rama"><?php foreach (pruebas_ramas() as $k => $x): ?><option value="<?= $k ?>"><?= e($x) ?></option><?php endforeach; ?></select></div>
            <div><label>Categoría</label><input type="text" name="categoria" maxlength="40" placeholder="Ej: 11 y 12 años"></div>
            <div><label>Edad desde</label><input type="number" name="edad_min" min="0"></div>
            <div><label>Edad hasta</label><input type="number" name="edad_max" min="0"></div>
            <div><label>Fecha</label><input type="date" name="fecha"></div>
            <div><label>Hora</label><input type="time" name="hora"></div>
            <?= $ciclo_p ? pista_html_cfg_campos($t, null) : ($pv ? pv_html_cfg_campos($t, null) : '') ?>
          </div>
          <button class="btn">Agregar prueba</button>
        </form>
      </div>
    </div>
  </div>

<?php elseif ($s === 'participantes'):
    $form_part = function (?array $p) use ($T, $eventos, $t) {
        $p = $p ?? ['id' => 0, 'numero' => '', 'nombre' => '', 'club' => '', 'ciudad' => '', 'rama' => '', 'nacimiento' => '', 'activo' => 1];
        $suyas = $p['id'] ? array_map('intval', array_column(filas('SELECT evento_id FROM prueba_resultados WHERE participante_id = ?', [$p['id']]), 'evento_id')) : []; ?>
      <div class="campos">
        <div><label>Número</label><input type="text" name="numero" value="<?= e($p['numero']) ?>" maxlength="6"></div>
        <div><label>Nombre *</label><input type="text" name="nombre" value="<?= e($p['nombre']) ?>" maxlength="80" required></div>
        <div><label>Club</label><input type="text" name="club" value="<?= e($p['club']) ?>" maxlength="60"></div>
        <div><label>Ciudad</label><input type="text" name="ciudad" value="<?= e($p['ciudad']) ?>" maxlength="40"></div>
        <div><label>Rama</label><select name="rama"><option value="">—</option><option value="M" <?= $p['rama'] === 'M' ? 'selected' : '' ?>>Varón</option><option value="F" <?= $p['rama'] === 'F' ? 'selected' : '' ?>>Dama</option></select></div>
        <div><label>Fecha de nacimiento</label><input type="date" name="nacimiento" value="<?= e($p['nacimiento']) ?>"></div>
        <?php if ($p['id']): ?><div><label>Estado</label><select name="activo"><option value="1" <?= (int)$p['activo'] ? 'selected' : '' ?>>Activo</option><option value="0" <?= !(int)$p['activo'] ? 'selected' : '' ?>>Retirado</option></select></div><?php endif; ?>
      </div>
      <?php if ($eventos): ?>
        <label>Pruebas en las que participa</label>
        <div class="campos" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr))">
          <?php foreach ($eventos as $ev): $no = $p['id'] ? prueba_no_puede($ev, $p, $t) : ''; ?>
            <label class="check" style="font-weight:400<?= $no !== '' && !in_array((int)$ev['id'], $suyas, true) ? ';opacity:.45' : '' ?>"><input type="checkbox" name="eventos[]" value="<?= (int)$ev['id'] ?>" <?= in_array((int)$ev['id'], $suyas, true) ? 'checked' : '' ?>> <?= e(prueba_nombre($ev)) ?></label>
          <?php endforeach; ?>
        </div>
        <p class="ayuda">Solo se inscribe en las pruebas de su rama y su edad (la que cumple en <?= e($t['temporada']) ?>).</p>
      <?php endif; ?>
    <?php }; ?>
  <div class="tarjeta">
    <h2><?= e($T['participantes']) ?> (<?= count($part) ?>)</h2>
    <?php if (!$part): ?><p class="suave">Aún no hay inscritos.</p>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="ejemplo"><button class="btn btn-sec btn-chico">🧪 Cargar un ejemplo para probar</button></form><?php endif; ?>
    <?php if ($part): ?>
    <div class="tabla-scroll"><table>
      <tr><th>#</th><th>Nombre</th><th>Club</th><th>Rama · edad</th><th class="num">Pruebas</th><th></th></tr>
      <?php foreach ($part as $p): $np = (int)valor('SELECT COUNT(*) FROM prueba_resultados WHERE participante_id = ?', [$p['id']]); $ed = prueba_edad((string)$p['nacimiento'], $t); ?>
        <tr<?= (int)$p['activo'] ? '' : ' style="opacity:.55"' ?>><td><?= e($p['numero']) ?></td><td><?= e($p['nombre']) ?> <?= html_estado_requisitos('prueba_participantes', (int)$p['id'], $mod_req) ?></td><td><?= e($p['club']) ?></td>
          <td><?= e(['M' => 'Varón', 'F' => 'Dama'][$p['rama']] ?? '—') ?><?= $ed !== null ? ' · ' . $ed . ' años' : '' ?></td><td class="num"><?= $np ?></td>
          <td><details><summary>Editar</summary>
            <form method="post" enctype="multipart/form-data" style="min-width:280px"><?= csrf_campo() ?><input type="hidden" name="accion" value="part_guardar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><?php $form_part($p); ?><?= html_requisitos_form($mod_req, 'prueba_participantes', (int)$p['id']) ?><button class="btn btn-chico">Guardar</button></form>
            <form method="post" onsubmit="return confirm('¿Eliminar?')" style="margin-top:6px"><?= csrf_campo() ?><input type="hidden" name="accion" value="part_borrar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar</button></form>
          </details></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>
  <div class="tarjeta" style="margin-top:16px">
    <h2>Inscribir <?= e(mb_strtolower($T['participante'])) ?></h2>
    <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="part_guardar"><input type="hidden" name="id" value="0"><?php $form_part(null); ?><?= html_requisitos_form($mod_req, 'prueba_participantes', 0) ?><?= html_autorizacion('tercero', 'la organización del campeonato') ?><button class="btn">Inscribir</button></form>
  </div>

<?php elseif ($s === 'medallero'):
    $med = pruebas_medallero($t); ?>
  <div class="tarjeta"><?= pruebas_html_medallero($med) ?></div>
  <div class="tarjeta" style="margin-top:14px"><h2>⭐ Ganadores y mejores marcas</h2><?= pruebas_html_mejores(pruebas_mejores($t)) ?></div>

<?php elseif ($s === 'config'): ?>
  <div class="tarjeta">
    <h2>Reglas y configuración</h2>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="config">
      <div class="campos">
        <div><label>Nombre *</label><input type="text" name="nombre" value="<?= e($t['nombre']) ?>" maxlength="100" required></div>
        <div><label>Temporada (año)</label><input type="text" name="temporada" value="<?= e($t['temporada']) ?>" maxlength="30"><div class="ayuda">La edad de cada competidor es la que cumple en este año.</div></div>
        <div><label>Estado</label><select name="estado"><?php foreach ($estados_t as $k => $x): ?><option value="<?= $k ?>" <?= $t['estado'] === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></div>
        <div><label>Dirección web (final)</label><input type="text" name="slug" value="<?= e($t['slug']) ?>" maxlength="60"></div>
        <div class="campo-ancho"><label>Puntos por puesto para la clasificación por clubes (1.° 2.° 3.° …)</label><input type="text" name="puntos" value="<?= e(implode(',', $cfg['puntos'])) ?>"><div class="ayuda">Separados por comas. Ej. natación: 9,7,6,5,4,3,2,1.</div></div>
      </div>
      <button class="btn">Guardar</button>
    </form>
  </div>
  <?= html_inscripcion_indiv_config($t) ?>
  <div class="tarjeta" style="margin-top:16px"><h2>Eliminar campeonato</h2>
    <form method="post" onsubmit="return confirm('¿Eliminar el campeonato con todas sus marcas?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar">
      <div class="campos"><div><label>Escriba el nombre exacto para confirmar</label><input type="text" name="confirmar" autocomplete="off"></div></div>
      <button class="btn btn-peligro">Eliminar</button></form></div>

<?php else: /* marcas y resultados */
    if (!$eventos): ?>
      <div class="tarjeta"><p>Primero arme el programa en la pestaña <a href="<?= e($url . '&s=programa') ?>">Programa de pruebas</a> e inscriba a los <?= e(mb_strtolower($T['participantes'])) ?>.</p>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="ejemplo"><button class="btn btn-sec btn-chico">🧪 Cargar un ejemplo para probar</button></form></div>
    <?php else:
    $evid = (int)get('ev');
    $ev = null;
    foreach ($eventos as $x) if ((int)$x['id'] === $evid) $ev = $x;
    if (!$ev) foreach ($eventos as $x) if (!prueba_con_marcas((int)$x['id']) && (int)valor('SELECT COUNT(*) FROM prueba_resultados WHERE evento_id = ?', [$x['id']])) { $ev = $x; break; }
    $ev = $ev ?? $eventos[0];
    $filas = prueba_resultados($ev, $part);
    $ya = array_map(fn($r) => (int)$r['participante_id'], $filas);
    $elegibles = array_filter($part, fn($p) => (int)$p['activo'] && !in_array((int)$p['id'], $ya, true) && prueba_no_puede($ev, $p, $t) === ''); ?>
    <form method="get" action="admin.php" style="margin-bottom:10px"><input type="hidden" name="p" value="pruebas"><input type="hidden" name="id" value="<?= $tid ?>">
      <select name="ev" onchange="this.form.submit()" aria-label="Prueba" style="max-width:100%">
        <?php foreach ($eventos as $x): $listo = prueba_con_marcas((int)$x['id']); ?><option value="<?= (int)$x['id'] ?>" <?= (int)$x['id'] === (int)$ev['id'] ? 'selected' : '' ?>><?= $listo ? '✓ ' : '' ?><?= e(prueba_nombre($x)) ?></option><?php endforeach; ?>
      </select></form>
    <div class="tarjeta">
      <h2 style="margin-top:0"><?= e(prueba_nombre($ev)) ?></h2>
      <?php $fo_ev = (string)($ev['formato'] ?: 'marca'); $con_tabla = in_array($fo_ev, $pv ? ['marca'] : ['marca', 'velocidad', 'persecucion'], true);
        $con_viento = $pv && $fo_ev === 'marca' && (int)(pv_cfg($ev)['viento'] ?? 0); ?>
      <p class="suave"><?= $ev['fecha'] !== '' ? '📅 ' . e(fecha_bonita($ev['fecha'])) : '' ?> <?= $ev['hora'] !== '' ? '🕒 ' . e($ev['hora']) : '' ?> · <?= $fo_ev !== 'marca' ? e($formatos_t[$fo_ev] ?? $fo_ev) . '.' : ($ev['medida'] === 'metros' ? 'Se mide en metros: gana el mayor.' : ($ev['medida'] === 'puesto' ? 'Por puesto de llegada: escriba en qué puesto llegó cada uno.' : 'Se mide en tiempo: gana el menor.')) ?></p>
      <?php if (!$filas && $fo_ev === 'ruta'): ?><p>Nadie inscrito en esta carrera todavía.</p><?= pv_html_admin($t, $ev, $part, $url) ?>
      <?php elseif (!$filas): ?><p>Nadie inscrito en esta prueba todavía.</p><?php elseif (!$con_tabla): ?>
        <?= $pv ? pv_html_admin($t, $ev, $part, $url) : pista_html_admin($t, $ev, $part, $url) ?>
      <?php else: ?>
      <?php if ($fo_ev !== 'marca'): ?><h3 style="margin-bottom:.3rem">Clasificatoria<?= $fo_ev === 'velocidad' ? ' (200 m lanzados)' : ' (tiempo)' ?></h3><?php endif; ?>
      <p class="ayuda">Escriba <?= $ev['medida'] === 'metros' ? 'la marca en metros, como <b>4,85</b>' : ($ev['medida'] === 'puesto' ? 'el <b>puesto</b> de llegada (1, 2, 3…)' : 'el tiempo como <b>1:05.30</b> o <b>28.47</b>') ?>. Si no compitió, cambie el estado (DNS, DNF o DQ). Los puestos se calculan solos.</p>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="marcas_guardar"><input type="hidden" name="evento_id" value="<?= (int)$ev['id'] ?>">
        <div class="tabla-scroll"><table class="tabla-marcas">
          <tr><th>Puesto</th><th><?= e($T['participante']) ?></th><th>Serie</th><th><?= e($T['carril']) ?></th><th><?= $ev['medida'] === 'metros' ? 'Metros' : ($ev['medida'] === 'puesto' ? 'Puesto' : 'Tiempo') ?></th><?php if ($con_viento): ?><th>Viento</th><?php endif; ?><th>Estado</th><th>Nota</th><th></th></tr>
          <?php foreach ($filas as $r): $pid = (int)$r['participante_id']; ?>
            <tr><td class="num"><b><?= $r['puesto'] ?? '' ?></b></td><td><?= e($r['p']['nombre']) ?><br><small class="suave"><?= e($r['p']['club']) ?></small></td>
              <td><input type="number" name="serie[<?= $pid ?>]" value="<?= (int)$r['serie'] ?: '' ?>" min="0" max="99" style="width:58px"></td>
              <td><input type="number" name="carril[<?= $pid ?>]" value="<?= (int)$r['carril'] ?: '' ?>" min="0" max="99" style="width:58px"></td>
              <td><input type="text" name="marca[<?= $pid ?>]" value="<?= e(prueba_fmt_marca($ev['medida'], $r['marca'])) ?>" inputmode="decimal" style="width:96px" placeholder="<?= $ev['medida'] === 'metros' ? '0,00' : ($ev['medida'] === 'puesto' ? '1' : '0:00.00') ?>"></td>
              <?php if ($con_viento): $vi = json_decode((string)$r['datos'], true)['v'] ?? ''; ?><td><input type="text" name="viento[<?= $pid ?>]" value="<?= e($vi !== '' ? pv_fmt_viento($vi) : '') ?>" style="width:60px" placeholder="+0,0"></td><?php endif; ?>
              <td><select name="est[<?= $pid ?>]"><?php foreach (pruebas_estados() as $k => $x): ?><option value="<?= $k ?>" <?= $r['estado'] === $k ? 'selected' : '' ?>><?= e($k === 'ok' ? 'Compitió' : strtoupper($k)) ?></option><?php endforeach; ?></select></td>
              <td><input type="text" name="nota[<?= $pid ?>]" value="<?= e($r['nota']) ?>" maxlength="120" style="min-width:120px"></td>
              <td><?php if ($r['marca'] === null && $r['estado'] === 'ok'): ?><label class="check" style="font-weight:400" title="Quitar de esta prueba"><input type="checkbox" name="quitar[<?= $pid ?>]" value="1"> quitar</label><?php endif; ?></td></tr>
          <?php endforeach; ?>
        </table></div>
        <button class="btn" style="margin-top:8px">💾 Guardar marcas</button>
      </form>
      <?php if ($fo_ev !== 'marca'): ?><div style="margin-top:14px"><?= $pv ? pv_html_admin($t, $ev, $part, $url) : pista_html_admin($t, $ev, $part, $url) ?></div><?php endif; ?>
      <?php endif; ?>
      <?php if ($elegibles): ?>
        <form method="post" style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap;align-items:center"><?= csrf_campo() ?><input type="hidden" name="accion" value="inscribir_en"><input type="hidden" name="evento_id" value="<?= (int)$ev['id'] ?>">
          <select name="participante_id"><option value="">+ Agregar a esta prueba…</option><?php foreach ($elegibles as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['nombre'] . ($p['club'] !== '' ? ' (' . $p['club'] . ')' : '')) ?></option><?php endforeach; ?></select>
          <button class="btn btn-sec btn-chico">Agregar</button></form>
      <?php endif; ?>
    </div>
    <?php if (array_filter($filas, fn($r) => $r['puesto'] !== null)): ?>
      <h2 style="margin-top:18px">Resultado</h2>
      <div class="tarjeta"><?= prueba_html_resultados($t, $ev, $filas) ?></div>
    <?php endif; ?>
    <?php if ($ciclo_p && ($det = pista_html_detalle($t, $ev, $part)) !== '' && ($ev['formato'] ?? '') !== 'jueces'): ?><div class="tarjeta" style="margin-top:14px"><?= $det ?></div><?php endif; ?>
    <?php if ($pv && ($det = pv_html_detalle($t, $ev, $part)) !== ''): ?><div class="tarjeta" style="margin-top:14px"><?= $det ?></div><?php endif; ?>
    <style>@media (max-width:700px){.tabla-marcas,.tabla-marcas tbody,.tabla-marcas tr,.tabla-marcas td{display:block;width:100%}.tabla-marcas tr:first-child{display:none}
      .tabla-marcas tr{border:1px solid var(--borde,#ddd);border-radius:10px;margin:0 0 10px;padding:8px 10px}.tabla-marcas td{border:0;padding:3px 0;display:inline-block;width:auto;margin-right:6px}
      .tabla-marcas td:nth-child(2){display:block}}</style>
    <?php endif;
endif;
pie_admin();
