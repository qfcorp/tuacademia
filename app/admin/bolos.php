<?php
/* (v25) Administración de un campeonato de BOLOS: jugadores, categorías, jornadas con líneas, handicap y clasificaciones */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$t = torneo_de_liga((int)get('id'), $lid);
$mod_req = modulo_de_torneo($t); // (v35) requisitos del módulo deportivo
$tid = (int)$t['id'];
if (!es_bolos($t)) ir(es_individual($t) ? url_admin_individual($t) : 'admin.php?p=torneo&id=' . $tid);
$T = textos_bolos();
$cfg = bolos_cfg($t);
$s = get('s', 'jornadas');
if (!in_array($s, ['jornadas', 'participantes', 'categorias', 'general', 'config'], true)) $s = 'jornadas';
$url = 'admin.php?p=bolos&id=' . $tid;

if (es_post()) {
    $accion = post('accion');

    if ($accion === 'config') {
        if (post('nombre') === '') throw new RuntimeException('El nombre no puede quedar vacío.');
        $estado = in_array(post('estado'), ['inscripcion', 'en_juego', 'finalizado'], true) ? post('estado') : $t['estado'];
        $puntos = preg_split('/[\s,;]+/', trim(post('puntos')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($puntos as $p) if (!ctype_digit($p)) throw new RuntimeException('Los puntos por puesto se escriben como números separados por comas. Ej: 25,20,16,13,11');
        q('UPDATE torneos SET nombre = ?, temporada = ?, estado = ?, bolos_lineas = ?, bolos_hcp = ?, bolos_hcp_base = ?, bolos_hcp_pct = ?, bolos_clasif = ?, carrera_puntos = ? WHERE id = ?', [
            mb_substr(post('nombre'), 0, 100), mb_substr(post('temporada'), 0, 30), $estado, entero(post('lineas'), 1, 10),
            array_key_exists(post('hcp'), bolos_textos_hcp()) ? post('hcp') : 'no', entero(post('base'), 100, 300), entero(post('pct'), 0, 100),
            array_key_exists(post('clasif'), bolos_textos_clasif()) ? post('clasif') : 'total', implode(',', $puntos) ?: '25,20,16,13,11,10,9,8,7,6,5,4,3,2,1', $tid]);
        if (post('slug') !== $t['slug']) guardar_slug_torneo(array_merge($t, ['nombre' => post('nombre'), 'temporada' => post('temporada')]), post('slug'));
        flash('Reglas guardadas. Las clasificaciones se recalcularon.');
        ir($url . '&s=config');
    }

    if ($accion === 'eliminar') {
        if (post('confirmar') !== $t['nombre']) throw new RuntimeException('Escriba el nombre exacto del campeonato para eliminarlo.');
        q('DELETE FROM torneos WHERE id = ?', [$tid]);
        flash('Campeonato eliminado.');
        ir('admin.php?p=torneos');
    }

    if ($accion === 'cat_base') {
        $n = bolos_cargar_categorias_base($t);
        flash($n ? "Se cargaron $n categorías." : 'Las categorías de fábrica ya estaban cargadas.');
        ir($url . '&s=categorias');
    }

    if ($accion === 'cat_guardar') {
        $id = (int)post('id');
        $nombre = mb_substr(trim(post('nombre')), 0, 60);
        if ($nombre === '') throw new RuntimeException('Escriba el nombre de la categoría.');
        if ($id > 0) {
            fila('SELECT id FROM carrera_categorias WHERE id = ? AND torneo_id = ?', [$id, $tid]) ?? throw new RuntimeException('Categoría no encontrada.');
            q('UPDATE carrera_categorias SET nombre = ?, orden = ?, nota = ? WHERE id = ?', [$nombre, entero(post('orden'), 0, 999), mb_substr(post('nota'), 0, 120), $id]);
        } else {
            $orden = (int)valor('SELECT COALESCE(MAX(orden), 0) + 1 FROM carrera_categorias WHERE torneo_id = ?', [$tid]);
            q('INSERT INTO carrera_categorias (torneo_id, nombre, orden, nota) VALUES (?,?,?,?)', [$tid, $nombre, $orden, mb_substr(post('nota'), 0, 120)]);
        }
        flash('Categoría guardada.');
        ir($url . '&s=categorias');
    }

    if ($accion === 'cat_borrar') {
        q('DELETE FROM carrera_categorias WHERE id = ? AND torneo_id = ?', [(int)post('id'), $tid]);
        flash('Categoría eliminada. Sus jugadores quedaron sin categoría.');
        ir($url . '&s=categorias');
    }

    if ($accion === 'part_guardar') {
        $id = (int)post('id');
        $nombre = mb_substr(trim(post('nombre')), 0, 80);
        if ($nombre === '') throw new RuntimeException('Escriba el nombre del jugador.');
        $cat = (int)post('categoria_id') > 0 && fila('SELECT id FROM carrera_categorias WHERE id = ? AND torneo_id = ?', [(int)post('categoria_id'), $tid]) ? (int)post('categoria_id') : null;
        $numero = mb_substr(post('numero'), 0, 6);
        if ($numero !== '' && fila('SELECT id FROM carrera_participantes WHERE torneo_id = ? AND numero = ? AND id <> ? AND activo = 1', [$tid, $numero, $id])) throw new RuntimeException("El número $numero ya lo usa otro jugador.");
        $d = [$cat, $numero, $nombre, mb_substr(post('club'), 0, 60), mb_substr(post('ciudad'), 0, 40), entero(post('handicap'), 0, 200)];
        if ($id > 0) {
            fila('SELECT id FROM carrera_participantes WHERE id = ? AND torneo_id = ?', [$id, $tid]) ?? throw new RuntimeException('No encontrado.');
            q('UPDATE carrera_participantes SET categoria_id = ?, numero = ?, nombre = ?, club = ?, ciudad = ?, handicap = ? WHERE id = ?', array_merge($d, [$id]));
            guardar_requisitos($lid, $mod_req, 'carrera_participantes', $id); // (v35)
        } else {
            exigir_autorizacion(); // (v24) protección de datos
            q('INSERT INTO carrera_participantes (categoria_id, numero, nombre, club, ciudad, handicap, torneo_id) VALUES (?,?,?,?,?,?,?)', array_merge($d, [$tid]));
            $nid = (int)db()->lastInsertId();
            registrar_autorizacion_tercero('participante_bolos', $lid, $nombre, 'la organización del campeonato ' . $t['nombre'], ['ref_tabla' => 'carrera_participantes', 'ref_id' => $nid]);
            guardar_requisitos($lid, $mod_req, 'carrera_participantes', $nid); // (v35)
        }
        flash($id > 0 ? 'Datos guardados.' : 'Jugador inscrito.');
        ir($url . '&s=participantes');
    }

    if ($accion === 'part_borrar') {
        q('DELETE FROM carrera_participantes WHERE id = ? AND torneo_id = ?', [(int)post('id'), $tid]); borrar_requisitos('carrera_participantes', (int)post('id'));
        flash('Eliminado, junto con sus líneas.');
        ir($url . '&s=participantes');
    }

    if ($accion === 'fecha_guardar') {
        $id = (int)post('id');
        $f_txt = post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : '';
        $datos = [mb_substr(post('nombre'), 0, 80), $f_txt, mb_substr(post('lugar'), 0, 80), entero(post('mangas'), 0, 10), mb_substr(post('nota'), 0, 200)];
        if ($id > 0) {
            fila('SELECT id FROM carrera_fechas WHERE id = ? AND torneo_id = ?', [$id, $tid]) ?? throw new RuntimeException('No encontrada.');
            q('UPDATE carrera_fechas SET nombre = ?, fecha = ?, lugar = ?, mangas = ?, nota = ? WHERE id = ?', array_merge($datos, [$id]));
            flash('Guardado.');
            ir($url . '&s=jornadas&f=' . $id);
        }
        $n = (int)valor('SELECT COALESCE(MAX(num), 0) FROM carrera_fechas WHERE torneo_id = ?', [$tid]) + 1;
        q('INSERT INTO carrera_fechas (torneo_id, num, nombre, fecha, lugar, mangas, nota) VALUES (?,?,?,?,?,?,?)', array_merge([$tid, $n], $datos));
        flash("Jornada $n creada. Cargue los pinos abajo.");
        ir($url . '&s=jornadas&f=' . (int)db()->lastInsertId());
    }

    if ($accion === 'fecha_borrar') {
        q('DELETE FROM carrera_fechas WHERE id = ? AND torneo_id = ?', [(int)post('id'), $tid]);
        flash('Jornada eliminada, junto con sus líneas.');
        ir($url . '&s=jornadas');
    }

    if ($accion === 'pinos_guardar') {
        $f = fila('SELECT * FROM carrera_fechas WHERE id = ? AND torneo_id = ?', [(int)post('fecha_id'), $tid]) ?? throw new RuntimeException('No encontrada.');
        $nl = bolos_lineas_jornada($f, $cfg);
        $nombres = array_column(filas('SELECT id, nombre FROM carrera_participantes WHERE torneo_id = ?', [$tid]), 'nombre', 'id');
        $n = 0;
        db()->beginTransaction();
        try {
            foreach ($nombres as $pid => $nombre) {
                for ($m = 1; $m <= $nl; $m++) {
                    $tx = (string)($_POST['l'][$pid][$m] ?? '');
                    try {
                        $pin = bolos_parse_pinos($tx);
                    } catch (RuntimeException $ex) {
                        throw new RuntimeException("$nombre, línea $m: " . $ex->getMessage());
                    }
                    if ($pin === null) {
                        q('DELETE FROM carrera_resultados WHERE fecha_id = ? AND participante_id = ? AND manga = ?', [$f['id'], $pid, $m]);
                        continue;
                    }
                    q("INSERT INTO carrera_resultados (fecha_id, participante_id, manga, pinos, estado) VALUES (?,?,?,?, 'ok')
                       ON CONFLICT(fecha_id, participante_id, manga) DO UPDATE SET pinos = excluded.pinos, estado = 'ok'", [$f['id'], $pid, $m, $pin]);
                    $n++;
                }
            }
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            throw $ex;
        }
        if ($t['estado'] === 'inscripcion') q("UPDATE torneos SET estado = 'en_juego' WHERE id = ?", [$tid]);
        flash("Pinos guardados ($n líneas). La clasificación ya está actualizada.");
        ir($url . '&s=jornadas&f=' . (int)$f['id']);
    }

    if ($accion === 'ejemplo') {
        bolos_cargar_ejemplo($t);
        flash('Se cargó un ejemplo con 10 jugadores y 3 jornadas. Bórrelos cuando ya no los necesite.');
        ir($url . '&s=general');
    }
    ir($url);
}

$cats = carrera_categorias($tid);
$calc = bolos_calcular($t);
$fechas = $calc['fechas'];
$n_part = count($calc['part']);
$estados_t = ['inscripcion' => 'Inscripción', 'en_juego' => 'En juego', 'finalizado' => 'Finalizado'];

cabecera_admin($t['nombre']);
?>
<p><a href="admin.php?p=torneos">← Campeonatos</a></p>
<div class="fila-titulo">
  <h1>🎳 <?= e($t['nombre']) ?> <small><?= e($t['temporada']) ?> · Bolos · <?= e($estados_t[$t['estado']] ?? $t['estado']) ?></small></h1>
  <span class="acciones"><?= html_botones_individual($t) ?></span>
</div>
<p class="suave" style="margin-top:6px"><?= count($cats) ?> categorías · <?= $n_part ?> jugadores · <?= count($fechas) ?> jornadas · <?= $cfg['lineas'] ?> líneas por jornada · <?= e(bolos_textos_hcp()[$cfg['hcp']]) ?><?= $cfg['hcp'] === 'auto' ? ' (' . $cfg['pct'] . '% de ' . $cfg['base'] . ')' : '' ?> · <?= e(bolos_textos_clasif()[$cfg['clasif']]) ?></p>
<nav class="pestanas">
  <?php foreach (['jornadas' => '1. Jornadas y pinos', 'participantes' => '2. Jugadores', 'categorias' => 'Categorías', 'general' => 'Clasificación general', 'config' => 'Reglas y configuración'] as $k => $txt): ?>
    <a href="<?= e($url . '&s=' . $k) ?>" class="<?= $s === $k ? 'activa' : '' ?>"><?= e($txt) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($s === 'categorias'): ?>
  <div class="rejilla-2">
    <div class="tarjeta">
      <h2>Categorías (<?= count($cats) ?>)</h2>
      <?php if (!$cats): ?><p class="suave">Aún no hay categorías.</p><?php endif; ?>
      <?php foreach ($cats as $c): ?>
        <details style="margin-bottom:8px"><summary><b><?= e($c['nombre']) ?></b> <span class="suave"><?= e($c['nota']) ?></span></summary>
          <form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="cat_guardar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <div class="campos"><div><label>Nombre</label><input type="text" name="nombre" value="<?= e($c['nombre']) ?>" maxlength="60" required></div>
              <div><label>Nota</label><input type="text" name="nota" value="<?= e($c['nota']) ?>" maxlength="120"></div>
              <div><label>Orden</label><input type="number" name="orden" value="<?= (int)$c['orden'] ?>" min="0"></div></div>
            <button class="btn btn-chico">Guardar</button></form>
          <form method="post" onsubmit="return confirm('¿Eliminar la categoría?')" style="margin-top:6px"><?= csrf_campo() ?><input type="hidden" name="accion" value="cat_borrar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar categoría</button></form>
        </details>
      <?php endforeach; ?>
      <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="cat_base"><button class="btn btn-sec">📋 Cargar categorías típicas (varones, damas, juvenil, senior)</button></form>
    </div>
    <div class="tarjeta">
      <h2>Nueva categoría</h2>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="cat_guardar"><input type="hidden" name="id" value="0">
        <div class="campos"><div><label>Nombre *</label><input type="text" name="nombre" maxlength="60" required placeholder="Ej: Primera categoría"></div>
          <div><label>Nota</label><input type="text" name="nota" maxlength="120" placeholder="Ej: promedio mayor a 180"></div></div>
        <button class="btn">Crear categoría</button></form>
    </div>
  </div>

<?php elseif ($s === 'participantes'):
    $lista = filas('SELECT p.*, c.nombre AS categoria FROM carrera_participantes p LEFT JOIN carrera_categorias c ON c.id = p.categoria_id WHERE p.torneo_id = ? ORDER BY c.orden, p.nombre', [$tid]);
    $form_part = function (?array $p) use ($cats, $cfg) { $p = $p ?? ['id' => 0, 'numero' => '', 'nombre' => '', 'club' => '', 'ciudad' => '', 'handicap' => 0, 'categoria_id' => 0]; ?>
      <div class="campos">
        <div><label>Número</label><input type="text" name="numero" value="<?= e($p['numero']) ?>" maxlength="6"></div>
        <div><label>Nombre *</label><input type="text" name="nombre" value="<?= e($p['nombre']) ?>" maxlength="80" required></div>
        <div><label>Club / equipo</label><input type="text" name="club" value="<?= e($p['club']) ?>" maxlength="60"></div>
        <div><label>Ciudad</label><input type="text" name="ciudad" value="<?= e($p['ciudad']) ?>" maxlength="40"></div>
        <div><label>Handicap <?= $cfg['hcp'] === 'auto' ? 'inicial' : '' ?> (pinos por línea)</label><input type="number" name="handicap" value="<?= (int)$p['handicap'] ?>" min="0" max="200">
          <div class="ayuda"><?= $cfg['hcp'] === 'no' ? 'Este campeonato es sin handicap (scratch).' : ($cfg['hcp'] === 'auto' ? 'Se usa hasta que tenga 3 líneas; después se calcula solo.' : 'Se suma en cada línea.') ?></div></div>
        <div><label>Categoría</label><select name="categoria_id"><option value="0">Sin categoría</option>
          <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$p['categoria_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?></select></div>
      </div>
    <?php }; ?>
  <div class="tarjeta">
    <h2>Jugadores (<?= count($lista) ?>)</h2>
    <?php if (!$lista): ?><p class="suave">Aún no hay jugadores inscritos.</p>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="ejemplo"><button class="btn btn-sec btn-chico">🧪 Cargar un ejemplo para probar</button></form><?php endif; ?>
    <?php if ($lista): ?>
    <div class="tabla-scroll"><table>
      <tr><th>#</th><th>Nombre</th><th>Club</th><th class="num">Prom.</th><?php if ($cfg['hcp'] !== 'no'): ?><th class="num">Hcp</th><?php endif; ?><th>Categoría</th><th></th></tr>
      <?php foreach ($lista as $p): $g = $calc['general'][(int)$p['id']] ?? null; ?>
        <tr><td><?= e($p['numero']) ?></td><td><?= e($p['nombre']) ?> <?= html_estado_requisitos('carrera_participantes', (int)$p['id'], $mod_req) ?></td><td><?= e($p['club']) ?></td>
          <td class="num"><?= $g ? bolos_fmt_prom($g['promedio']) : '—' ?></td><?php if ($cfg['hcp'] !== 'no'): ?><td class="num"><?= $g ? (int)$g['hcp_actual'] : (int)$p['handicap'] ?></td><?php endif; ?>
          <td><?= $p['categoria'] ? e($p['categoria']) : '<span class="etiqueta roja">sin categoría</span>' ?></td>
          <td><details><summary>Editar</summary>
            <form method="post" enctype="multipart/form-data" style="min-width:260px"><?= csrf_campo() ?><input type="hidden" name="accion" value="part_guardar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><?php $form_part($p); ?><?= html_requisitos_form($mod_req, 'carrera_participantes', (int)$p['id']) ?><button class="btn btn-chico">Guardar</button></form>
            <form method="post" onsubmit="return confirm('¿Eliminar? Se borran también sus líneas.')" style="margin-top:6px"><?= csrf_campo() ?><input type="hidden" name="accion" value="part_borrar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar</button></form>
          </details></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>
  <div class="tarjeta" style="margin-top:16px">
    <h2>Inscribir jugador</h2>
    <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="part_guardar"><input type="hidden" name="id" value="0"><?php $form_part(null); ?><?= html_requisitos_form($mod_req, 'carrera_participantes', 0) ?><?= html_autorizacion('tercero', 'la organización del campeonato') ?><button class="btn">Inscribir</button></form>
  </div>

<?php elseif ($s === 'general'):
    $g = bolos_general($t, $calc); ?>
  <?php if (!$g['cats']): ?>
    <div class="tarjeta"><p>Todavía no hay pinos cargados. Cree una jornada en la pestaña 1 y cargue las líneas.</p>
      <?php if (!$n_part): ?><form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="ejemplo"><button class="btn btn-sec">🧪 Cargar un ejemplo para probar</button></form><?php endif; ?></div>
  <?php else: ?>
    <?= bolos_html_destacados(bolos_destacados($calc)) ?>
    <?php foreach ($g['cats'] as $c): ?>
      <div class="tarjeta" style="margin-bottom:14px"><h2><?= e($c['cat']['nombre']) ?></h2><?= bolos_html_tabla_general($c['filas'], $g['fechas'], $cfg) ?></div>
    <?php endforeach; ?>
    <div class="tarjeta"><h2>🏢 Por clubes</h2><?= bolos_html_clubes(bolos_clubes($calc)) ?></div>
  <?php endif; ?>

<?php elseif ($s === 'config'): ?>
  <div class="tarjeta">
    <h2>Reglas y configuración</h2>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="config">
      <div class="campos">
        <div><label>Nombre *</label><input type="text" name="nombre" value="<?= e($t['nombre']) ?>" maxlength="100" required></div>
        <div><label>Temporada</label><input type="text" name="temporada" value="<?= e($t['temporada']) ?>" maxlength="30"></div>
        <div><label>Estado</label><select name="estado"><?php foreach ($estados_t as $k => $x): ?><option value="<?= $k ?>" <?= $t['estado'] === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></div>
        <div><label>Dirección web (final)</label><input type="text" name="slug" value="<?= e($t['slug']) ?>" maxlength="60"></div>
        <div><label>Líneas por jornada</label><input type="number" name="lineas" value="<?= $cfg['lineas'] ?>" min="1" max="10"><div class="ayuda">Cada jornada puede tener otras.</div></div>
        <div><label>Handicap</label><select name="hcp"><?php foreach (bolos_textos_hcp() as $k => $x): ?><option value="<?= $k ?>" <?= $cfg['hcp'] === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></div>
        <div><label>Handicap automático: base</label><input type="number" name="base" value="<?= $cfg['base'] ?>" min="100" max="300"><div class="ayuda">Handicap por línea = (base − promedio) × porcentaje.</div></div>
        <div><label>… porcentaje</label><input type="number" name="pct" value="<?= $cfg['pct'] ?>" min="0" max="100"></div>
        <div><label>Clasificación general</label><select name="clasif"><?php foreach (bolos_textos_clasif() as $k => $x): ?><option value="<?= $k ?>" <?= $cfg['clasif'] === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></div>
        <div class="campo-ancho"><label>Puntos por puesto en cada jornada (si la general es por puntos)</label><input type="text" name="puntos" value="<?= e(implode(',', $cfg['puntos'])) ?>"></div>
      </div>
      <button class="btn">Guardar</button>
    </form>
  </div>
  <?= html_inscripcion_indiv_config($t) ?>
  <div class="tarjeta" style="margin-top:16px"><h2>Eliminar campeonato</h2>
    <form method="post" onsubmit="return confirm('¿Eliminar el campeonato con todas sus jornadas?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar">
      <div class="campos"><div><label>Escriba el nombre exacto para confirmar</label><input type="text" name="confirmar" autocomplete="off"></div></div>
      <button class="btn btn-peligro">Eliminar</button></form></div>

<?php else: /* jornadas */
    $fid = (int)get('f');
    $f = null;
    foreach ($fechas as $x) if ((int)$x['id'] === $fid) $f = $x;
    $f = $f ?? ($fechas ? end($fechas) : null);
    $lista_part = filas('SELECT p.*, c.nombre AS categoria FROM carrera_participantes p LEFT JOIN carrera_categorias c ON c.id = p.categoria_id WHERE p.torneo_id = ? AND p.activo = 1 ORDER BY c.orden, p.nombre', [$tid]); ?>
  <div class="rejilla-2">
    <div class="tarjeta">
      <h2>Jornadas</h2>
      <?php if (!$fechas): ?><p class="suave">Aún no hay jornadas. Cree la primera.</p><?php endif; ?>
      <?php foreach ($fechas as $x): ?>
        <p style="margin:.3rem 0"><a class="btn <?= $f && (int)$f['id'] === (int)$x['id'] ? '' : 'btn-sec' ?> btn-chico" href="<?= e($url . '&s=jornadas&f=' . (int)$x['id']) ?>"><?= e(carrera_nombre_fecha($x, $T)) ?></a>
          <span class="suave"><?= $x['fecha'] !== '' ? e(fecha_bonita($x['fecha'])) : '' ?> <?= e($x['lugar']) ?></span></p>
      <?php endforeach; ?>
    </div>
    <div class="tarjeta">
      <h2>Nueva jornada</h2>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="fecha_guardar"><input type="hidden" name="id" value="0">
        <div class="campos">
          <div><label>Nombre</label><input type="text" name="nombre" maxlength="80" placeholder="Ej: Jornada 4 · Bolera Quicentro"></div>
          <div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
          <div><label>Bolera</label><input type="text" name="lugar" maxlength="80"></div>
          <div><label>Líneas (0 = las del campeonato)</label><input type="number" name="mangas" value="0" min="0" max="10"></div>
        </div>
        <button class="btn">Crear</button>
      </form>
    </div>
  </div>

  <?php if ($f):
    $nl = bolos_lineas_jornada($f, $cfg);
    $prev = [];
    foreach (filas('SELECT * FROM carrera_resultados WHERE fecha_id = ? AND pinos IS NOT NULL', [$f['id']]) as $r) $prev[(int)$r['participante_id']][(int)$r['manga']] = (int)$r['pinos']; ?>
    <style>.tabla-pinos input{width:64px;text-align:center}.tabla-pinos td.pin-tot{font-weight:700}
      @media (max-width:700px){.tabla-pinos,.tabla-pinos tbody,.tabla-pinos tr,.tabla-pinos td{display:block;width:100%}.tabla-pinos tr:first-child{display:none}
        .tabla-pinos tr{border:1px solid var(--borde,#ddd);border-radius:10px;margin:0 0 10px;padding:8px 10px}.tabla-pinos td{border:0;padding:3px 0}
        .tabla-pinos td.pin-l{display:inline-block;width:auto;margin-right:6px}.tabla-pinos td.pin-l::before{content:attr(data-l);display:block;font-size:.75rem;color:var(--suave,#666)}
        .tabla-pinos td.pin-tot{display:inline-block;width:auto;margin-left:8px}}</style>
    <div class="tarjeta" style="margin-top:16px">
      <div class="fila-titulo"><h2><?= e(carrera_nombre_fecha($f, $T)) ?> · cargar pinos</h2>
        <details><summary class="btn btn-sec btn-chico">Datos de la jornada</summary>
          <form method="post" style="min-width:280px;margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="fecha_guardar"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <div class="campos">
              <div><label>Nombre</label><input type="text" name="nombre" value="<?= e($f['nombre']) ?>" maxlength="80"></div>
              <div><label>Fecha</label><input type="date" name="fecha" value="<?= e($f['fecha']) ?>"></div>
              <div><label>Bolera</label><input type="text" name="lugar" value="<?= e($f['lugar']) ?>" maxlength="80"></div>
              <div><label>Líneas (0 = las del campeonato)</label><input type="number" name="mangas" value="<?= (int)$f['mangas'] ?>" min="0" max="10"></div>
              <div><label>Nota</label><input type="text" name="nota" value="<?= e($f['nota']) ?>" maxlength="200"></div>
            </div><button class="btn btn-chico">Guardar</button></form>
          <form method="post" onsubmit="return confirm('¿Eliminar esta jornada con todas sus líneas?')" style="margin-top:6px"><?= csrf_campo() ?><input type="hidden" name="accion" value="fecha_borrar"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar jornada</button></form>
        </details></div>
      <?php if (!$lista_part): ?>
        <p>Primero inscriba jugadores en la pestaña 2.</p>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="ejemplo"><button class="btn btn-sec btn-chico">🧪 Cargar un ejemplo para probar</button></form>
      <?php else: ?>
      <p class="ayuda">Escriba los pinos de cada línea (0 a 300). Deje vacío a quien no jugó. <?= $cfg['hcp'] !== 'no' ? 'El handicap se suma solo.' : '' ?></p>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="pinos_guardar"><input type="hidden" name="fecha_id" value="<?= (int)$f['id'] ?>">
        <div class="tabla-scroll"><table class="tabla-pinos">
          <tr><th>Jugador</th><th>Cat.</th><?php for ($m = 1; $m <= $nl; $m++): ?><th class="num">Línea <?= $m ?></th><?php endfor; ?><th class="num">Pinos</th></tr>
          <?php foreach ($lista_part as $p): $pid = (int)$p['id']; $ls = $prev[$pid] ?? []; ?>
            <tr><td><b><?= e($p['nombre']) ?></b></td><td><small><?= e($p['categoria'] ?? '') ?></small></td>
              <?php for ($m = 1; $m <= $nl; $m++): ?><td class="pin-l" data-l="L<?= $m ?>"><input type="text" name="l[<?= $pid ?>][<?= $m ?>]" value="<?= isset($ls[$m]) ? (int)$ls[$m] : '' ?>" inputmode="numeric" maxlength="3" aria-label="Línea <?= $m ?>"></td><?php endfor; ?>
              <td class="num pin-tot"><?= $ls ? array_sum(array_filter($ls, fn($k) => $k <= $nl, ARRAY_FILTER_USE_KEY)) : '' ?></td></tr>
          <?php endforeach; ?>
        </table></div>
        <button class="btn" style="margin-top:8px">Guardar pinos</button>
      </form>
      <?php endif; ?>
    </div>

    <?php $cl = bolos_jornada($t, $f, $calc);
    if ($cl['n']): ?>
      <h2 style="margin-top:20px">Clasificación de <?= e(carrera_nombre_fecha($f, $T)) ?></h2>
      <?php foreach ($cl['cats'] as $c): ?>
        <div class="tarjeta" style="margin-bottom:14px"><h3 style="margin-top:0"><?= e($c['cat']['nombre']) ?></h3><?= bolos_html_tabla_jornada($c['filas'], $cl['lineas'], $cfg) ?></div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
<?php endif;
pie_admin();
