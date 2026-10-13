<?php
/* (v22) Administración de un campeonato de CARRERAS (autocross, ciclismo): categorías, participantes, fechas, tiempos y clasificación */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$t = torneo_de_liga((int)get('id'), $lid);
$mod_req = modulo_de_torneo($t); // (v35) requisitos del módulo deportivo
$tid = (int)$t['id'];
if (!es_carrera($t)) ir('admin.php?p=torneo&id=' . $tid);
$T = textos_carrera($t);
$cfg = carrera_cfg($t);
$s = get('s', 'tiempos');
if (!in_array($s, ['tiempos', 'participantes', 'categorias', 'general', 'config'], true)) $s = 'tiempos';
$url = 'admin.php?p=carrera&id=' . $tid;
$num = fn(string $v) => (float)str_replace(',', '.', trim($v));
$ciclo = $t['modalidad'] === 'CICLO'; // (v34) ruta con reglas UCI
$mtb = $t['modalidad'] === 'MTB';
$fecha_nac = function (string $v): string { // (v34) fecha de nacimiento (para el mejor joven)
    $v = trim($v);
    if ($v === '') return '';
    $ts = strtotime($v);
    if (!$ts || (int)date('Y', $ts) < 1920 || $ts > time()) throw new RuntimeException('La fecha de nacimiento no es válida.');
    return date('Y-m-d', $ts);
};

if (es_post()) {
    $accion = post('accion');

    if ($accion === 'config') {
        if (post('nombre') === '') throw new RuntimeException('El nombre no puede quedar vacío.');
        $estado = in_array(post('estado'), ['inscripcion', 'en_juego', 'finalizado'], true) ? post('estado') : $t['estado'];
        $puntos = preg_split('/[\s,;]+/', trim(post('puntos')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($puntos as $p) if (!ctype_digit($p)) throw new RuntimeException('Los puntos por puesto se escriben como números separados por comas. Ej: 25,20,16,13,11,10,9,8,7,6,5,4,3,2,1');
        q('UPDATE torneos SET nombre = ?, temporada = ?, estado = ?, carrera_puntos = ?, carrera_mangas = ?, carrera_modo = ?, carrera_pen_cono = ?, carrera_descarte = ?, carrera_clasif = ? WHERE id = ?', [
            mb_substr(post('nombre'), 0, 100), mb_substr(post('temporada'), 0, 30), $estado, implode(',', $puntos) ?: '25,20,16,13,11,10,9,8,7,6,5,4,3,2,1',
            entero(post('mangas'), 1, 10), post('modo') === 'suma' ? 'suma' : 'mejor', max(0, round($num(post('pen_cono')), 2)),
            entero(post('descarte'), 0, 10), post('clasif') === 'tiempo' ? 'tiempo' : 'puntos', $tid]);
        if ($ciclo) q('UPDATE torneos SET ciclo_joven = ? WHERE id = ?', [entero(post('ciclo_joven'), 0, 40), $tid]);
        if (post('slug') !== $t['slug']) guardar_slug_torneo(array_merge($t, ['nombre' => post('nombre'), 'temporada' => post('temporada')]), post('slug'));
        flash('Reglas del campeonato guardadas. Las clasificaciones se recalcularon con las nuevas reglas.');
        ir($url . '&s=config');
    }

    if ($accion === 'eliminar') {
        if (post('confirmar') !== $t['nombre']) throw new RuntimeException('Escriba el nombre exacto del campeonato para eliminarlo.');
        q('DELETE FROM torneos WHERE id = ?', [$tid]);
        flash('Campeonato eliminado.');
        ir('admin.php?p=torneos');
    }

    // (v23) Catálogo de penalizaciones con nombre
    if ($accion === 'pen_guardar') {
        $id = (int)post('id');
        $nom = mb_substr(trim(post('nombre')), 0, 60);
        if ($nom === '') throw new RuntimeException('Escriba el nombre de la penalización.');
        $seg = round($num(post('segundos')), 3);
        if ($seg == 0) throw new RuntimeException('Escriba cuántos segundos suma (o resta, con signo menos, si es una bonificación).');
        if (abs($seg) > 3600) throw new RuntimeException('El valor no puede pasar de 3600 segundos.');
        if ($id) {
            fila('SELECT id FROM carrera_penalidades WHERE id = ? AND torneo_id = ?', [$id, $tid]) ?? throw new RuntimeException('No encontrada.');
            q('UPDATE carrera_penalidades SET nombre = ?, segundos = ?, orden = ?, activo = ? WHERE id = ?', [$nom, $seg, entero(post('orden'), 0, 999), post('activo') === '1' ? 1 : 0, $id]);
            flash('Penalización guardada. Las clasificaciones se recalcularon.');
        } else {
            $orden = (int)valor('SELECT COALESCE(MAX(orden), 0) + 1 FROM carrera_penalidades WHERE torneo_id = ?', [$tid]);
            q('INSERT INTO carrera_penalidades (torneo_id, nombre, segundos, orden) VALUES (?,?,?,?)', [$tid, $nom, $seg, $orden]);
            flash('Penalización agregada.');
        }
        ir($url . '&s=config#penalizaciones');
    }

    if ($accion === 'pen_borrar') {
        $id = (int)post('id');
        fila('SELECT id FROM carrera_penalidades WHERE id = ? AND torneo_id = ?', [$id, $tid]) ?? throw new RuntimeException('No encontrada.');
        $usos = (int)valor("SELECT COUNT(*) FROM carrera_resultados r JOIN carrera_fechas f ON f.id = r.fecha_id WHERE f.torneo_id = ? AND r.pens LIKE ?", [$tid, '%"' . $id . '"%']);
        if ($usos) throw new RuntimeException("Esta penalización ya se aplicó en $usos manga(s). En vez de eliminarla, desactívela (quite el visto de «Activa» y guarde).");
        q('DELETE FROM carrera_penalidades WHERE id = ?', [$id]);
        flash('Penalización eliminada.');
        ir($url . '&s=config#penalizaciones');
    }

    if ($accion === 'pen_base') {
        $n = carrera_cargar_penalidades_base($t);
        flash($n ? "Se cargaron $n penalizaciones típicas. Puede cambiar sus valores." : 'Las penalizaciones típicas ya estaban cargadas.');
        ir($url . '&s=config#penalizaciones');
    }

    if ($accion === 'cat_base') {
        $n = carrera_cargar_categorias_base($t);
        flash($n ? "Se cargaron $n categorías. Puede cambiarlas, borrarlas o agregar otras." : 'Las categorías de fábrica ya estaban cargadas.');
        ir($url . '&s=categorias');
    }

    if ($accion === 'cat_guardar') {
        $id = (int)post('id');
        $nombre = mb_substr(post('nombre'), 0, 60);
        if ($nombre === '') throw new RuntimeException('Escriba el nombre de la categoría.');
        $asp = in_array(post('aspiracion'), ['turbo', 'atmosferico'], true) ? post('aspiracion') : '';
        $mn = entero(post('cc_min'), 0, 20000);
        $mx = entero(post('cc_max'), 0, 20000);
        if ($mn > 0 && $mx > 0 && $mx < $mn) throw new RuntimeException('La cilindrada máxima no puede ser menor que la mínima.');
        if ($id > 0) {
            fila('SELECT id FROM carrera_categorias WHERE id = ? AND torneo_id = ?', [$id, $tid]) ?? throw new RuntimeException('Categoría no encontrada.');
            q('UPDATE carrera_categorias SET nombre = ?, orden = ?, cc_min = ?, cc_max = ?, aspiracion = ?, nota = ? WHERE id = ?', [$nombre, entero(post('orden'), 0, 999), $mn, $mx, $asp, mb_substr(post('nota'), 0, 120), $id]);
            flash('Categoría actualizada.');
        } else {
            $orden = (int)valor('SELECT COALESCE(MAX(orden), 0) FROM carrera_categorias WHERE torneo_id = ?', [$tid]) + 1;
            q('INSERT INTO carrera_categorias (torneo_id, nombre, orden, cc_min, cc_max, aspiracion, nota) VALUES (?,?,?,?,?,?,?)', [$tid, $nombre, $orden, $mn, $mx, $asp, mb_substr(post('nota'), 0, 120)]);
            flash('Categoría creada.');
        }
        ir($url . '&s=categorias');
    }

    if ($accion === 'cat_borrar') {
        q('DELETE FROM carrera_categorias WHERE id = ? AND torneo_id = ?', [(int)post('id'), $tid]);
        flash('Categoría eliminada. Sus ' . mb_strtolower($T['participantes']) . ' quedaron sin categoría.');
        ir($url . '&s=categorias');
    }

    if ($accion === 'part_guardar') {
        $id = (int)post('id');
        $nombre = mb_substr(post('nombre'), 0, 80);
        if ($nombre === '') throw new RuntimeException('Escriba el nombre del ' . mb_strtolower($T['participante']) . '.');
        $cc = entero(post('cc'), 0, 20000);
        $asp = in_array(post('aspiracion'), ['turbo', 'atmosferico'], true) ? post('aspiracion') : '';
        $numero = mb_substr(post('numero'), 0, 6);
        if ($numero !== '' && fila('SELECT id FROM carrera_participantes WHERE torneo_id = ? AND numero = ? AND id <> ? AND activo = 1', [$tid, $numero, $id])) throw new RuntimeException("El número $numero ya lo usa otro " . mb_strtolower($T['participante']) . '.');
        $cat = post('categoria_id');
        $aviso = '';
        if ($cat === 'auto') {
            $cat = carrera_categoria_auto($tid, $cc, $asp);
            if (!$cat) $aviso = ' No hay una categoría que corresponda a esa cilindrada y motor: quedó sin categoría (créela en Categorías).';
        } else {
            $cat = (int)$cat > 0 && fila('SELECT id FROM carrera_categorias WHERE id = ? AND torneo_id = ?', [(int)$cat, $tid]) ? (int)$cat : null;
        }
        $d = [$cat ?: null, $numero, $nombre, mb_substr(post('club'), 0, 60), mb_substr(post('ciudad'), 0, 40), mb_substr(post('vehiculo'), 0, 60), $cc, $asp, $fecha_nac(post('nacimiento'))];
        if ($id > 0) {
            fila('SELECT id FROM carrera_participantes WHERE id = ? AND torneo_id = ?', [$id, $tid]) ?? throw new RuntimeException('No encontrado.');
            q('UPDATE carrera_participantes SET categoria_id = ?, numero = ?, nombre = ?, club = ?, ciudad = ?, vehiculo = ?, cc = ?, aspiracion = ?, nacimiento = ? WHERE id = ?', array_merge($d, [$id]));
            guardar_requisitos($lid, $mod_req, 'carrera_participantes', $id); // (v35)
        } else {
            exigir_autorizacion(); // (v24) protección de datos
            q('INSERT INTO carrera_participantes (categoria_id, numero, nombre, club, ciudad, vehiculo, cc, aspiracion, nacimiento, torneo_id) VALUES (?,?,?,?,?,?,?,?,?,?)', array_merge($d, [$tid]));
            $nid = (int)db()->lastInsertId();
            registrar_autorizacion_tercero('participante_carrera', $lid, $nombre, 'la organización del campeonato ' . $t['nombre'], ['ref_tabla' => 'carrera_participantes', 'ref_id' => $nid]);
            guardar_requisitos($lid, $mod_req, 'carrera_participantes', $nid); // (v35)
        }
        flash(($id > 0 ? 'Datos guardados.' : $T['participante'] . ' inscrito.') . $aviso, $aviso ? 'error' : 'ok');
        ir($url . '&s=participantes');
    }

    if ($accion === 'part_auto') {
        $n = 0;
        foreach (filas('SELECT * FROM carrera_participantes WHERE torneo_id = ?', [$tid]) as $p) {
            $c = carrera_categoria_auto($tid, (int)$p['cc'], (string)$p['aspiracion']);
            if ($c && (int)$c !== (int)$p['categoria_id']) { q('UPDATE carrera_participantes SET categoria_id = ? WHERE id = ?', [$c, $p['id']]); $n++; }
        }
        flash($n ? "Se reasignó la categoría de $n " . mb_strtolower($T['participantes']) . ' según su cilindrada y motor.' : 'Todos ya estaban en la categoría que les corresponde.');
        ir($url . '&s=participantes');
    }

    if ($accion === 'part_borrar') {
        q('DELETE FROM carrera_participantes WHERE id = ? AND torneo_id = ?', [(int)post('id'), $tid]); borrar_requisitos('carrera_participantes', (int)post('id'));
        flash('Eliminado, junto con sus tiempos.');
        ir($url . '&s=participantes');
    }

    if ($accion === 'fecha_guardar') {
        $id = (int)post('id');
        $formato = $mtb && isset(mtb_formatos()[post('formato')]) ? post('formato') : '';
        $vueltas_total = $mtb ? entero(post('vueltas_total'), 0, 99) : 0;
        $f_txt = post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : '';
        if ($id > 0) {
            fila('SELECT id FROM carrera_fechas WHERE id = ? AND torneo_id = ?', [$id, $tid]) ?? throw new RuntimeException('No encontrada.');
            q('UPDATE carrera_fechas SET nombre = ?, fecha = ?, lugar = ?, mangas = ?, nota = ?, formato = ?, vueltas_total = ? WHERE id = ?', [mb_substr(post('nombre'), 0, 80), $f_txt, mb_substr(post('lugar'), 0, 80), entero(post('mangas'), 0, 10), mb_substr(post('nota'), 0, 200), $formato, $vueltas_total, $id]);
            flash('Guardado.');
            ir($url . '&s=tiempos&f=' . $id);
        }
        $n = (int)valor('SELECT COALESCE(MAX(num), 0) FROM carrera_fechas WHERE torneo_id = ?', [$tid]) + 1;
        q('INSERT INTO carrera_fechas (torneo_id, num, nombre, fecha, lugar, mangas, nota, formato, vueltas_total) VALUES (?,?,?,?,?,?,?,?,?)', [$tid, $n, mb_substr(post('nombre'), 0, 80), $f_txt, mb_substr(post('lugar'), 0, 80), entero(post('mangas'), 0, 10), mb_substr(post('nota'), 0, 200), $formato, $vueltas_total]);
        flash($T['fecha'] . " $n creada. Cargue los tiempos abajo.");
        ir($url . '&s=tiempos&f=' . (int)db()->lastInsertId());
    }

    // (v34) Configuración de las etapas de una carrera de ruta (tipo, km, bonificaciones, puntos, fuera de control)
    if ($accion === 'etapas_guardar' && $ciclo) {
        $f = fila('SELECT * FROM carrera_fechas WHERE id = ? AND torneo_id = ?', [(int)post('fecha_id'), $tid]) ?? throw new RuntimeException('No encontrada.');
        $nm = carrera_mangas_fecha($f, $cfg);
        $lista = function (string $v, string $que): string {
            $v = trim($v);
            if ($v !== '' && !preg_match('/^\d+([\s,;]+\d+)*$/', $v)) throw new RuntimeException("$que: escriba números separados por comas. Ej: 10,6,4");
            return implode(',', ciclo_lista_num($v));
        };
        for ($m = 1; $m <= $nm; $m++) {
            $tipo = (string)($_POST['tipo'][$m] ?? 'linea');
            if (!isset(ciclo_tipos_etapa()[$tipo])) $tipo = 'linea';
            q('INSERT INTO ciclo_etapas (fecha_id, manga, tipo, nombre, km, limite_pct, bonif_meta, puntos_meta, mismo_tiempo) VALUES (?,?,?,?,?,?,?,?,?)
               ON CONFLICT(fecha_id, manga) DO UPDATE SET tipo = excluded.tipo, nombre = excluded.nombre, km = excluded.km, limite_pct = excluded.limite_pct,
                 bonif_meta = excluded.bonif_meta, puntos_meta = excluded.puntos_meta, mismo_tiempo = excluded.mismo_tiempo', [
                $f['id'], $m, $tipo, mb_substr(trim((string)($_POST['nombre'][$m] ?? '')), 0, 80),
                max(0, min(400, round($num((string)($_POST['km'][$m] ?? '0')), 1))), max(0, min(100, round($num((string)($_POST['limite'][$m] ?? '0')), 1))),
                $lista((string)($_POST['bonif'][$m] ?? ''), 'Bonificación en meta'), $lista((string)($_POST['ptsm'][$m] ?? ''), 'Puntos en meta'),
                isset($_POST['mt'][$m]) ? 1 : 0]);
        }
        flash('Etapas guardadas. Las clasificaciones se recalcularon.');
        ir($url . '&s=tiempos&f=' . (int)$f['id']);
    }

    if ($accion === 'fecha_borrar') {
        q('DELETE FROM carrera_fechas WHERE id = ? AND torneo_id = ?', [(int)post('id'), $tid]);
        flash('Eliminada, junto con sus tiempos.');
        ir($url . '&s=tiempos');
    }

    if ($accion === 'tiempos_guardar') {
        $f = fila('SELECT * FROM carrera_fechas WHERE id = ? AND torneo_id = ?', [(int)post('fecha_id'), $tid]) ?? throw new RuntimeException('No encontrada.');
        $nm = carrera_mangas_fecha($f, $cfg);
        $estados = carrera_estados();
        $nombres = array_column(filas('SELECT id, nombre FROM carrera_participantes WHERE torneo_id = ?', [$tid]), 'nombre', 'id');
        $pens_ok = $cfg['pens'];
        $n = 0;
        db()->beginTransaction();
        try {
            foreach ($nombres as $pid => $nombre) {
                for ($m = 1; $m <= $nm; $m++) {
                    $tx = trim((string)($_POST['t'][$pid][$m] ?? ''));
                    $conos = entero($_POST['c'][$pid][$m] ?? 0, 0, 200);
                    $aj = round($num((string)($_POST['a'][$pid][$m] ?? '0')), 3);
                    $est = (string)($_POST['e'][$pid][$m] ?? 'ok');
                    if (!isset($estados[$est])) $est = 'ok';
                    // (v23) penalizaciones con nombre (cuántas veces) y motivo
                    $pens = [];
                    foreach ((array)($_POST['pn'][$pid][$m] ?? []) as $pen_id => $veces) {
                        $veces = entero($veces, 0, 99);
                        if ($veces > 0 && isset($pens_ok[(int)$pen_id])) $pens[(string)(int)$pen_id] = $veces;
                    }
                    $pens_json = $pens ? json_encode($pens) : '';
                    $motivo = mb_substr(trim((string)($_POST['mo'][$pid][$m] ?? '')), 0, 120);
                    // (v34) ruta: bonificación de sprints (s), puntos de sprints y de montaña · MTB XCO: vueltas
                    $bs = $ciclo ? max(0, min(600, round($num((string)($_POST['bs'][$pid][$m] ?? '0')), 1))) : 0;
                    $ps = $ciclo ? entero($_POST['ps'][$pid][$m] ?? 0, 0, 999) : 0;
                    $pm = $ciclo ? entero($_POST['pm'][$pid][$m] ?? 0, 0, 999) : 0;
                    $vu = $mtb && trim((string)($_POST['v'][$pid][$m] ?? '')) !== '' ? entero($_POST['v'][$pid][$m], 0, 99) : null;
                    $ms = carrera_parse_tiempo($tx);
                    if ($tx !== '' && $ms === null) throw new RuntimeException("Tiempo mal escrito para $nombre ($T[manga] $m): «" . $tx . '». Use 1:23.456 o 83.456');
                    if ($tx === '' && $est === 'ok' && $conos === 0 && $aj == 0 && !$pens && $motivo === '' && $bs == 0 && !$ps && !$pm && $vu === null) {
                        q('DELETE FROM carrera_resultados WHERE fecha_id = ? AND participante_id = ? AND manga = ?', [$f['id'], $pid, $m]);
                        continue;
                    }
                    if ($est === 'ok' && $ms === null) throw new RuntimeException("Falta el tiempo de $nombre ($T[manga] $m). Si no terminó, marque su estado (No terminó, Descalificado o No largó).");
                    q('INSERT INTO carrera_resultados (fecha_id, participante_id, manga, tiempo_ms, conos, ajuste_seg, estado, pens, motivo, bonif_seg, pts_sprint, pts_montana, vueltas) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                       ON CONFLICT(fecha_id, participante_id, manga) DO UPDATE SET tiempo_ms = excluded.tiempo_ms, conos = excluded.conos, ajuste_seg = excluded.ajuste_seg, estado = excluded.estado,
                         pens = excluded.pens, motivo = excluded.motivo, bonif_seg = excluded.bonif_seg, pts_sprint = excluded.pts_sprint, pts_montana = excluded.pts_montana, vueltas = excluded.vueltas',
                        [$f['id'], $pid, $m, $ms, $conos, $aj, $est, $pens_json, $motivo, $bs, $ps, $pm, $vu]);
                    $n++;
                }
            }
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            throw $ex;
        }
        if ($t['estado'] === 'inscripcion') q("UPDATE torneos SET estado = 'en_juego' WHERE id = ?", [$tid]);
        flash("Tiempos guardados ($n registros). La clasificación ya está actualizada.");
        ir($url . '&s=tiempos&f=' . (int)$f['id']);
    }

    if ($accion === 'ejemplo') {
        carrera_cargar_ejemplo($t);
        flash('Se cargó un ejemplo con categorías, ' . mb_strtolower($T['participantes']) . ' y ' . mb_strtolower($T['fechas']) . '. Bórrelos cuando ya no los necesite.');
        ir($ciclo || $mtb ? $url . '&s=tiempos' : $url . '&s=general');
    }
    ir($url);
}

$cats = carrera_categorias($tid);
$fechas = carrera_fechas($tid);
$n_part = (int)valor('SELECT COUNT(*) FROM carrera_participantes WHERE torneo_id = ?', [$tid]);
$estados_t = ['inscripcion' => 'Inscripción', 'en_juego' => 'En juego', 'finalizado' => 'Finalizado'];
$asp_txt = ['' => '—', 'atmosferico' => 'Atmosférico', 'turbo' => 'Turbo / sobrealimentado'];

cabecera_admin($t['nombre']);
?>
<p><a href="admin.php?p=torneos">← Campeonatos</a></p>
<div class="fila-titulo">
  <h1><?= icono_carrera($t) ?> <?= e($t['nombre']) ?> <small><?= e($t['temporada']) ?> · <?= e(nombre_modalidad($t['modalidad'])) ?> · <?= e($estados_t[$t['estado']] ?? $t['estado']) ?></small></h1>
  <span class="acciones"><?= html_botones_individual($t) ?></span>
</div>
<p class="suave" style="margin-top:6px"><?= e(count($cats)) ?> categorías · <?= $n_part ?> <?= e(mb_strtolower($T['participantes'])) ?> · <?= count($fechas) ?> <?= e(mb_strtolower($T['fechas'])) ?> ·
  <?php if ($ciclo): ?>reglas UCI: tiempo acumulado de las etapas − bonificaciones, mismo tiempo para el grupo, clasificaciones de puntos, montaña, joven y equipos ·
  <?= $cfg['clasif'] === 'tiempo' ? 'campeonato por tiempo total' : 'campeonato por puntos' ?></p>
  <?php elseif ($mtb): ?>cada carrera tiene su formato (XCO por vueltas, descenso, enduro) · <?= $cfg['clasif'] === 'tiempo' ? 'campeonato por tiempo total' : 'campeonato por puntos' ?></p>
  <?php else: ?>
  <?= $cfg['modo'] === 'suma' ? 'cuenta la suma de ' . $cfg['mangas'] . ' mangas' : ($cfg['mangas'] > 1 ? 'cuenta la mejor de ' . $cfg['mangas'] . ' mangas' : 'una sola manga') ?> ·
  cono = +<?= rtrim(rtrim(number_format($cfg['pen_cono'], 2, '.', ''), '0'), '.') ?> s · <?= $cfg['clasif'] === 'tiempo' ? 'general por tiempo total' : 'general por puntos' . ($cfg['descarte'] ? ' (se descartan las ' . $cfg['descarte'] . ' peores)' : '') ?></p>
  <?php endif; ?>
<nav class="pestanas">
  <?php foreach (['tiempos' => '1. ' . $T['fechas'] . ' y tiempos', 'participantes' => '2. ' . $T['participantes'], 'categorias' => 'Categorías', 'general' => 'Clasificación general', 'config' => 'Reglas y configuración'] as $k => $txt): ?>
    <a href="<?= e($url . '&s=' . $k) ?>" class="<?= $s === $k ? 'activa' : '' ?>"><?= e($txt) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($s === 'categorias'): ?>
  <div class="rejilla-2">
    <div class="tarjeta">
      <h2>Categorías (<?= count($cats) ?>)</h2>
      <?php if (!$cats): ?><p class="suave">Aún no hay categorías. Cargue las de fábrica o cree las suyas.</p><?php endif; ?>
      <?php foreach ($cats as $c): ?>
        <details style="margin-bottom:8px"><summary><b><?= e($c['nombre']) ?></b> <span class="suave"><?= e(carrera_texto_categoria($c)) ?></span></summary>
          <form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="cat_guardar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <div class="campos">
              <div><label>Nombre</label><input type="text" name="nombre" value="<?= e($c['nombre']) ?>" maxlength="60" required></div>
              <?php if ($T['con_cc']): ?>
                <div><label>Cilindrada desde (cc)</label><input type="number" name="cc_min" value="<?= (int)$c['cc_min'] ?>" min="0"></div>
                <div><label>Cilindrada hasta (cc)</label><input type="number" name="cc_max" value="<?= (int)$c['cc_max'] ?>" min="0"></div>
                <div><label>Motor</label><select name="aspiracion"><?php foreach ($asp_txt as $k => $x): ?><option value="<?= e($k) ?>" <?= $c['aspiracion'] === $k ? 'selected' : '' ?>><?= e($k === '' ? 'Cualquiera' : $x) ?></option><?php endforeach; ?></select></div>
              <?php endif; ?>
              <div><label>Nota</label><input type="text" name="nota" value="<?= e($c['nota']) ?>" maxlength="120"></div>
              <div><label>Orden</label><input type="number" name="orden" value="<?= (int)$c['orden'] ?>" min="0"></div>
            </div>
            <button class="btn btn-chico">Guardar</button>
          </form>
          <form method="post" onsubmit="return confirm('¿Eliminar la categoría?')" style="margin-top:6px"><?= csrf_campo() ?><input type="hidden" name="accion" value="cat_borrar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar categoría</button></form>
        </details>
      <?php endforeach; ?>
      <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="cat_base"><button class="btn btn-sec"><?= $T['con_cc'] ? '📋 Cargar categorías por cilindrada y motor' : '📋 Cargar categorías de ciclismo' ?></button></form>
      <?php if ($T['con_cc']): ?><p class="ayuda">Los pilotos con «categoría automática» entran a la primera que cumpla su cilindrada y su motor (atmosférico o turbo). Los turbo van a la categoría turbo aunque su cilindrada sea baja.</p><?php endif; ?>
    </div>
    <div class="tarjeta">
      <h2>Nueva categoría</h2>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="cat_guardar"><input type="hidden" name="id" value="0">
        <div class="campos">
          <div><label>Nombre *</label><input type="text" name="nombre" maxlength="60" required placeholder="<?= $T['con_cc'] ? 'Ej: 1.601 a 2.000 cc' : 'Ej: Máster A (30 a 39 años)' ?>"></div>
          <?php if ($T['con_cc']): ?>
            <div><label>Cilindrada desde (cc)</label><input type="number" name="cc_min" min="0" value="0"></div>
            <div><label>Cilindrada hasta (cc)</label><input type="number" name="cc_max" min="0" value="0"><div class="ayuda">0 = sin límite.</div></div>
            <div><label>Motor</label><select name="aspiracion"><option value="">Cualquiera</option><option value="atmosferico">Atmosférico</option><option value="turbo">Turbo / sobrealimentado</option></select></div>
          <?php endif; ?>
          <div><label>Nota</label><input type="text" name="nota" maxlength="120" placeholder="Ej: tracción trasera, 4x4, mujeres..."></div>
        </div>
        <button class="btn">Crear categoría</button>
      </form>
    </div>
  </div>

<?php elseif ($s === 'participantes'):
    $lista = filas('SELECT p.*, c.nombre AS categoria FROM carrera_participantes p LEFT JOIN carrera_categorias c ON c.id = p.categoria_id WHERE p.torneo_id = ? ORDER BY c.orden, CAST(p.numero AS INTEGER), p.nombre', [$tid]);
    $form_part = function (?array $p) use ($T, $cats, $asp_txt) { $p = $p ?? ['id' => 0, 'numero' => '', 'nombre' => '', 'club' => '', 'ciudad' => '', 'vehiculo' => '', 'cc' => '', 'aspiracion' => '', 'categoria_id' => 'auto', 'nacimiento' => '']; ?>
      <div class="campos">
        <div><label>Número</label><input type="text" name="numero" value="<?= e($p['numero']) ?>" maxlength="6"></div>
        <div><label>Nombre *</label><input type="text" name="nombre" value="<?= e($p['nombre']) ?>" maxlength="80" required></div>
        <div><label>Club / equipo</label><input type="text" name="club" value="<?= e($p['club']) ?>" maxlength="60"><?php if (!$T['con_cc']): ?><div class="ayuda">Escríbalo igual para todos los del mismo equipo (cuenta para la clasificación por equipos).</div><?php endif; ?></div>
        <div><label>Ciudad</label><input type="text" name="ciudad" value="<?= e($p['ciudad']) ?>" maxlength="40"></div>
        <div><label><?= e($T['vehiculo']) ?></label><input type="text" name="vehiculo" value="<?= e($p['vehiculo']) ?>" maxlength="60" placeholder="<?= $T['con_cc'] ? 'Marca y modelo' : 'Ruta, montaña...' ?>"></div>
        <?php if (!$T['con_cc']): ?><div><label>Fecha de nacimiento</label><input type="date" name="nacimiento" value="<?= e($p['nacimiento'] ?? '') ?>"><div class="ayuda">Para la clasificación del mejor joven (sub-23).</div></div><?php endif; ?>
        <?php if ($T['con_cc']): ?>
          <div><label>Cilindrada (cc)</label><input type="number" name="cc" value="<?= e($p['cc']) ?>" min="0" placeholder="Ej: 1600"></div>
          <div><label>Motor</label><select name="aspiracion"><?php foreach ($asp_txt as $k => $x): ?><option value="<?= e($k) ?>" <?= $p['aspiracion'] === $k ? 'selected' : '' ?>><?= e($k === '' ? 'No indicado' : $x) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div><label>Categoría</label><select name="categoria_id">
          <?php if ($T['con_cc']): ?><option value="auto" <?= $p['categoria_id'] === 'auto' ? 'selected' : '' ?>>Automática (por cilindrada y motor)</option><?php endif; ?>
          <option value="0">Sin categoría</option>
          <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$p['categoria_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
        </select></div>
      </div>
    <?php }; ?>
  <div class="tarjeta">
    <h2><?= e($T['participantes']) ?> (<?= count($lista) ?>)</h2>
    <?php if (!$lista): ?><p class="suave">Aún no hay <?= e(mb_strtolower($T['participantes'])) ?> inscritos.</p><?php endif; ?>
    <?php if ($lista): ?>
    <div class="tabla-scroll"><table>
      <tr><th>#</th><th>Nombre</th><th>Club</th><th><?= e($T['vehiculo']) ?></th><th>Categoría</th><th></th></tr>
      <?php foreach ($lista as $p): ?>
        <tr><td><?= e($p['numero']) ?></td><td><?= e($p['nombre']) ?> <?= html_estado_requisitos('carrera_participantes', (int)$p['id'], $mod_req) ?></td><td><?= e($p['club']) ?></td>
          <td><?= e($p['vehiculo']) ?><?= (int)$p['cc'] > 0 ? ' · ' . number_format((int)$p['cc'], 0, ',', '.') . ' cc' : '' ?><?= $p['aspiracion'] === 'turbo' ? ' · turbo' : '' ?></td>
          <td><?= $p['categoria'] ? e($p['categoria']) : '<span class="etiqueta roja">sin categoría</span>' ?></td>
          <td><details><summary>Editar</summary>
            <form method="post" enctype="multipart/form-data" style="min-width:260px"><?= csrf_campo() ?><input type="hidden" name="accion" value="part_guardar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><?php $form_part($p); ?><?= html_requisitos_form($mod_req, 'carrera_participantes', (int)$p['id']) ?><button class="btn btn-chico">Guardar</button></form>
            <form method="post" onsubmit="return confirm('¿Eliminar? Se borran también sus tiempos.')" style="margin-top:6px"><?= csrf_campo() ?><input type="hidden" name="accion" value="part_borrar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar</button></form>
          </details></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php if ($T['con_cc']): ?><form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="part_auto"><button class="btn btn-sec btn-chico">↻ Reasignar categorías por cilindrada y motor</button></form><?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="tarjeta" style="margin-top:16px">
    <h2>Inscribir <?= e(mb_strtolower($T['participante'])) ?></h2>
    <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="part_guardar"><input type="hidden" name="id" value="0"><?php $form_part(null); ?><?= html_requisitos_form($mod_req, 'carrera_participantes', 0) ?><?= html_autorizacion('tercero', 'la organización del campeonato') ?><button class="btn">Inscribir</button></form>
  </div>

<?php elseif ($s === 'general'):
    $g = carrera_general($t); ?>
  <?php if (!$g['cats']): ?>
    <div class="tarjeta"><p>Todavía no hay tiempos cargados. Cree una <?= e(mb_strtolower($T['fecha'])) ?> en la pestaña 1 y cargue los tiempos.</p>
      <?php if (!$n_part): ?><form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="ejemplo"><button class="btn btn-sec">🧪 Cargar un ejemplo para probar</button></form><?php endif; ?></div>
  <?php else: ?>
    <p class="suave"><?= $cfg['clasif'] === 'tiempo' ? 'Gana quien terminó más ' . e(mb_strtolower($T['fechas'])) . ' y tiene el menor tiempo total.' : 'Puntos por puesto en cada ' . e(mb_strtolower($T['fecha'])) . ': ' . e(implode(', ', array_slice($cfg['puntos'], 0, 10))) . (count($cfg['puntos']) > 10 ? '…' : '') . '. Empate: más primeros puestos, luego segundos, etc.' ?></p>
    <?php foreach ($g['cats'] as $c): ?>
      <div class="tarjeta" style="margin-bottom:14px"><h2><?= e($c['cat']['nombre']) ?></h2><?= carrera_html_tabla_general($c['filas'], $g['fechas'], $cfg, $T) ?></div>
    <?php endforeach; ?>
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
        <div><label><?= $ciclo ? 'Etapas por carrera (por omisión)' : ($mtb ? 'Bajadas / tramos por carrera (por omisión)' : 'Mangas por ' . e(mb_strtolower($T['fecha']))) ?></label><input type="number" name="mangas" value="<?= (int)$cfg['mangas'] ?>" min="1" max="10"><div class="ayuda"><?= $ciclo ? 'Carrera de un día: 1. Vuelta por etapas: el número de etapas. Cada carrera puede tener las suyas.' : ($mtb ? 'XCO: 1. Descenso: normalmente 2 bajadas. Enduro: los tramos. Cada carrera puede tener las suyas.' : 'Autocross: normalmente 2 o 3. Cada fecha puede tener las suyas.') ?></div></div>
        <?php if ($ciclo): ?>
          <input type="hidden" name="modo" value="suma"><input type="hidden" name="pen_cono" value="0">
          <div><label>Mejor joven: hasta cuántos años</label><input type="number" name="ciclo_joven" value="<?= (int)($t['ciclo_joven'] ?? 22) ?>" min="0" max="40"><div class="ayuda">UCI: sub-23 = hasta 22 años cumplidos en el año. 0 = sin clasificación del joven.</div></div>
        <?php elseif ($mtb): ?>
          <input type="hidden" name="modo" value="<?= e($cfg['modo']) ?>"><input type="hidden" name="pen_cono" value="0">
        <?php else: ?>
        <div><label>Cómo cuentan las mangas</label><select name="modo"><option value="mejor" <?= $cfg['modo'] === 'mejor' ? 'selected' : '' ?>>Vale la mejor manga</option><option value="suma" <?= $cfg['modo'] === 'suma' ? 'selected' : '' ?>>Vale la suma de todas las mangas</option></select></div>
        <div><label>Penalización por cono tumbado (segundos)</label><input type="text" name="pen_cono" value="<?= e(rtrim(rtrim(number_format($cfg['pen_cono'], 2, '.', ''), '0'), '.')) ?>" inputmode="decimal"><div class="ayuda">Se suma al tiempo por cada cono. Las demás penalizaciones (salida en falso, puerta, etc.) están en la lista de abajo.</div></div>
        <?php endif; ?>
        <div><label>Clasificación general</label><select name="clasif"><option value="puntos" <?= $cfg['clasif'] === 'puntos' ? 'selected' : '' ?>>Por puntos (puesto en cada <?= e(mb_strtolower($T['fecha'])) ?>)</option><option value="tiempo" <?= $cfg['clasif'] === 'tiempo' ? 'selected' : '' ?>>Por tiempo total acumulado</option></select></div>
        <div class="campo-ancho"><label>Puntos por puesto (1.° 2.° 3.° …)</label><input type="text" name="puntos" value="<?= e(implode(',', $cfg['puntos'])) ?>"><div class="ayuda">Separados por comas. Cada categoría puntúa aparte.</div></div>
        <div><label>Descartar las peores (fechas)</label><input type="number" name="descarte" value="<?= (int)$cfg['descarte'] ?>" min="0" max="10"><div class="ayuda">Solo se descarta cuando ya se corrieron más fechas que este número.</div></div>
      </div>
      <button class="btn">Guardar</button>
    </form>
  </div>
  <?php $pens_todas = carrera_penalidades($tid); ?>
  <div class="tarjeta" style="margin-top:16px" id="penalizaciones">
    <h2>Penalizaciones y bonificaciones</h2>
    <p class="ayuda">Al cargar los tiempos, en cada <?= e(mb_strtolower($T['manga'])) ?> se marca cuántas veces se aplicó cada una y el sistema suma (o resta) los segundos. Use un valor negativo para una bonificación (ej: meta volante −3). Si cambia un valor, las clasificaciones se recalculan.</p>
    <?php if (!$pens_todas): ?><p class="suave">No hay penalizaciones. Cargue las típicas o agregue las suyas.</p><?php endif; ?>
    <?php foreach ($pens_todas as $pn): ?>
      <form method="post" class="fila-pen"><?= csrf_campo() ?><input type="hidden" name="accion" value="pen_guardar"><input type="hidden" name="id" value="<?= (int)$pn['id'] ?>">
        <input type="text" name="nombre" value="<?= e($pn['nombre']) ?>" maxlength="60" required aria-label="Nombre">
        <input type="text" name="segundos" value="<?= e(rtrim(rtrim(number_format((float)$pn['segundos'], 3, '.', ''), '0'), '.')) ?>" inputmode="decimal" size="5" aria-label="Segundos"> <span class="suave">s</span>
        <input type="number" name="orden" value="<?= (int)$pn['orden'] ?>" min="0" style="width:60px" aria-label="Orden" title="Orden">
        <label style="display:inline"><input type="checkbox" name="activo" value="1" <?= (int)$pn['activo'] ? 'checked' : '' ?>> Activa</label>
        <button class="btn btn-chico">Guardar</button>
      </form>
      <form method="post" class="fila-pen-borrar" onsubmit="return confirm('¿Eliminar la penalización?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="pen_borrar"><input type="hidden" name="id" value="<?= (int)$pn['id'] ?>"><button class="btn btn-peligro btn-chico" title="Eliminar">✕</button></form>
    <?php endforeach; ?>
    <h3>Agregar</h3>
    <form method="post" class="fila-pen"><?= csrf_campo() ?><input type="hidden" name="accion" value="pen_guardar"><input type="hidden" name="id" value="0">
      <input type="text" name="nombre" maxlength="60" required placeholder="Ej: Salida en falso" aria-label="Nombre">
      <input type="text" name="segundos" inputmode="decimal" size="5" required placeholder="5" aria-label="Segundos"> <span class="suave">s</span>
      <button class="btn btn-chico">Agregar</button>
    </form>
    <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="pen_base"><button class="btn btn-sec btn-chico">📋 Cargar las penalizaciones típicas de <?= e(mb_strtolower(nombre_modalidad($t['modalidad']))) ?></button></form>
  </div>
  <style>.fila-pen{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:.35rem 0}.fila-pen input[type=text]{width:auto;flex:1 1 180px}.fila-pen input[size="5"]{flex:0 0 70px}.fila-pen-borrar{margin:-.2rem 0 .6rem}</style>

  <?= html_inscripcion_indiv_config($t) ?>
  <div class="tarjeta" style="margin-top:16px"><h2>Eliminar campeonato</h2>
    <form method="post" onsubmit="return confirm('¿Eliminar el campeonato con todos sus tiempos?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar">
      <div class="campos"><div><label>Escriba el nombre exacto para confirmar</label><input type="text" name="confirmar" autocomplete="off"></div></div>
      <button class="btn btn-peligro">Eliminar</button></form></div>

<?php else: /* tiempos */
    $fid = (int)get('f');
    $f = null;
    foreach ($fechas as $x) if ((int)$x['id'] === $fid) $f = $x;
    $f = $f ?? ($fechas ? end($fechas) : null);
    $lista_part = filas('SELECT p.*, c.nombre AS categoria FROM carrera_participantes p LEFT JOIN carrera_categorias c ON c.id = p.categoria_id WHERE p.torneo_id = ? ORDER BY c.orden, CAST(p.numero AS INTEGER), p.nombre', [$tid]); ?>
  <div class="rejilla-2">
    <div class="tarjeta">
      <h2><?= e($T['fechas']) ?></h2>
      <?php if (!$fechas): ?><p class="suave">Aún no hay <?= e(mb_strtolower($T['fechas'])) ?>. Cree la primera.</p><?php endif; ?>
      <?php foreach ($fechas as $x): ?>
        <p style="margin:.3rem 0"><a class="btn <?= $f && (int)$f['id'] === (int)$x['id'] ? '' : 'btn-sec' ?> btn-chico" href="<?= e($url . '&s=tiempos&f=' . (int)$x['id']) ?>"><?= e(carrera_nombre_fecha($x, $T)) ?></a>
          <span class="suave"><?= $x['fecha'] !== '' ? e(fecha_bonita($x['fecha'])) : '' ?> <?= e($x['lugar']) ?></span></p>
      <?php endforeach; ?>
    </div>
    <div class="tarjeta">
      <h2>Nueva <?= e(mb_strtolower($T['fecha'])) ?></h2>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="fecha_guardar"><input type="hidden" name="id" value="0">
        <div class="campos">
          <div><label>Nombre</label><input type="text" name="nombre" maxlength="80" placeholder="<?= $ciclo ? 'Ej: Vuelta a Pichincha' : ($mtb ? 'Ej: XCO · Parque Metropolitano' : 'Ej: Fecha 2 · Circuito Yahuarcocha') ?>"></div>
          <div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
          <div><label>Lugar</label><input type="text" name="lugar" maxlength="80"></div>
          <div><label><?= $ciclo ? 'Etapas' : 'Mangas' ?> (0 = las del campeonato)</label><input type="number" name="mangas" value="0" min="0" max="10"></div>
          <?php if ($mtb): ?>
            <div><label>Formato</label><select name="formato"><?php foreach (mtb_formatos() as $k => $x): ?><option value="<?= $k ?>"><?= e($x) ?></option><?php endforeach; ?></select></div>
            <div><label>Vueltas de la carrera (XCO)</label><input type="number" name="vueltas_total" value="0" min="0" max="99"><div class="ayuda">0 = se toma la mayor cantidad de vueltas que alguien completó.</div></div>
          <?php endif; ?>
        </div>
        <button class="btn">Crear</button>
      </form>
    </div>
  </div>

  <?php if ($f):
    $nm = carrera_mangas_fecha($f, $cfg);
    $prev = [];
    $pens_act = array_filter($cfg['pens'], fn($x) => (int)$x['activo'] === 1);
    foreach (filas('SELECT * FROM carrera_resultados WHERE fecha_id = ?', [$f['id']]) as $r) $prev[(int)$r['participante_id']][(int)$r['manga']] = $r; ?>
    <style>.pen-mas{display:inline-block;vertical-align:middle;position:relative}.pen-mas summary{list-style:none;cursor:pointer;padding:4px 7px;border:1px solid var(--borde,#ccc);border-radius:6px;font-size:.9em}
      .pen-mas summary::-webkit-details-marker{display:none}.pen-mas[data-con] summary{background:#fff3cd;border-color:#e0b000;color:#6b4e00}
      .tabla-tiempos td.tt-manga{white-space:nowrap}.tt-lbl{display:none}
      .tabla-tiempos td.tt-manga input,.tabla-tiempos td.tt-manga select{display:inline-block;vertical-align:middle;margin:2px 0}
      .tabla-tiempos td.tt-manga input[size="9"]{width:100px}.tabla-tiempos td.tt-manga input[placeholder="± seg"]{width:66px}
      .tabla-tiempos td.tt-manga input[type=number]{width:54px}.tabla-tiempos td.tt-manga select{width:auto;max-width:110px}
      .pen-caja{position:absolute;z-index:50;right:0;min-width:320px;background:var(--tarjeta,#fff);color:var(--texto,inherit);border:1px solid var(--borde,#ccc);border-radius:8px;padding:8px;box-shadow:0 6px 18px rgba(0,0,0,.18);white-space:normal;text-align:left}
      .pen-caja label{display:flex;gap:6px;align-items:center;margin:3px 0;font-weight:normal}.pen-caja input[type=number]{width:56px;flex:0 0 56px}.pen-caja input[type=text]{width:100%;margin-top:4px}
      @media (max-width:700px){
        .pen-caja{position:fixed;left:8px;right:8px;top:auto;bottom:76px;min-width:0;max-height:55vh;overflow:auto;z-index:1000}
        .tabla-tiempos,.tabla-tiempos tbody,.tabla-tiempos tr,.tabla-tiempos td{display:block;width:100%}
        .tabla-tiempos tr:first-child{display:none}
        .tabla-tiempos tr{border:1px solid var(--borde,#ddd);border-radius:10px;margin:0 0 10px;padding:8px 10px}
        .tabla-tiempos td{border:0;padding:3px 0}
        .tabla-tiempos td.tt-num,.tabla-tiempos td.tt-nom,.tabla-tiempos td.tt-cat{display:inline;width:auto;font-weight:600}
        .tabla-tiempos td.tt-num:not(:empty)::before{content:'#'}.tabla-tiempos td.tt-num{margin-right:6px}
        .tt-lbl{display:inline;font-size:.8rem;color:var(--suave,#666);margin-left:-2px}
        .tabla-tiempos td.tt-manga input[placeholder="± seg"]{flex:0 0 80px;width:80px}
        .tabla-tiempos td.tt-cat{font-weight:400;margin-left:6px}
        .tabla-tiempos td.tt-manga{white-space:normal;display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding-top:6px}
        .tabla-tiempos td.tt-manga::before{content:attr(data-manga);flex:0 0 100%;font-size:.8rem;color:var(--suave,#666);font-weight:600;text-transform:uppercase}
        .tabla-tiempos td.tt-manga input[type=text][size="9"]{flex:1 1 110px;min-width:0}
        .tabla-tiempos td.tt-manga select{flex:1 1 100px;width:auto}
      }</style>
    <?php if ($ciclo): ?>
      <details class="tarjeta" style="margin-top:16px"<?= (int)valor('SELECT COUNT(*) FROM ciclo_etapas WHERE fecha_id = ?', [$f['id']]) ? '' : ' open' ?>>
        <summary><b>🚴 Etapas de <?= e(carrera_nombre_fecha($f, $T)) ?></b> <span class="suave">· tipo, kilómetros, bonificaciones, puntos y fuera de control (reglas UCI)</span></summary>
        <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="etapas_guardar"><input type="hidden" name="fecha_id" value="<?= (int)$f['id'] ?>">
          <div class="tabla-scroll"><table>
            <tr><th>#</th><th>Nombre</th><th>Tipo</th><th>Km</th><th title="Fuera de control: % sobre el tiempo del ganador (0 = sin límite)">Límite %</th><th title="Segundos que se restan al 1.°, 2.°, 3.° en la meta">Bonif. meta (s)</th><th>Puntos en meta</th><th title="Los que llegan con menos de 1 s del de adelante reciben el mismo tiempo">Mismo tiempo</th></tr>
            <?php for ($m = 1; $m <= $nm; $m++): $et = ciclo_etapa($f, $m); ?>
              <tr><td><?= $m ?></td>
                <td><input type="text" name="nombre[<?= $m ?>]" value="<?= e($et['nombre']) ?>" maxlength="80" placeholder="Ej: Cayambe – Ibarra" style="min-width:150px"></td>
                <td><select name="tipo[<?= $m ?>]"><?php foreach (ciclo_tipos_etapa() as $k => $x): ?><option value="<?= $k ?>" <?= $et['tipo'] === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></td>
                <td><input type="text" name="km[<?= $m ?>]" value="<?= (float)$et['km'] ? e(rtrim(rtrim(number_format((float)$et['km'], 1, '.', ''), '0'), '.')) : '' ?>" inputmode="decimal" style="width:60px"></td>
                <td><input type="text" name="limite[<?= $m ?>]" value="<?= (float)$et['limite_pct'] ? e(rtrim(rtrim(number_format((float)$et['limite_pct'], 1, '.', ''), '0'), '.')) : '' ?>" inputmode="decimal" style="width:55px" placeholder="0"></td>
                <td><input type="text" name="bonif[<?= $m ?>]" value="<?= e($et['bonif_meta']) ?>" style="width:80px" placeholder="10,6,4"></td>
                <td><input type="text" name="ptsm[<?= $m ?>]" value="<?= e($et['puntos_meta']) ?>" style="min-width:150px"></td>
                <td style="text-align:center"><input type="checkbox" name="mt[<?= $m ?>]" value="1" <?= (int)$et['mismo_tiempo'] ? 'checked' : '' ?>></td></tr>
            <?php endfor; ?>
          </table></div>
          <p class="ayuda"><b>En línea:</b> llegada normal con bonificaciones (UCI: 10-6-4 s) y mismo tiempo para el grupo. <b>Contrarreloj individual:</b> sin bonificaciones. <b>Contrarreloj por equipos:</b> el tiempo del equipo se toma en su 3.er corredor. <b>Critérium:</b> gana quien suma más puntos. Límite %: los que llegan más allá de ese porcentaje del tiempo del ganador quedan fuera de control (OTL) y salen de la general (UCI usa entre 8 % y 20 % según la etapa).</p>
          <button class="btn btn-chico">Guardar etapas</button>
        </form>
      </details>
    <?php endif; ?>
    <div class="tarjeta" style="margin-top:16px">
      <div class="fila-titulo"><h2><?= e(carrera_nombre_fecha($f, $T)) ?> · cargar tiempos</h2>
        <details><summary class="btn btn-sec btn-chico">Datos de la <?= e(mb_strtolower($T['fecha'])) ?></summary>
          <form method="post" style="min-width:280px;margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="fecha_guardar"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <div class="campos">
              <div><label>Nombre</label><input type="text" name="nombre" value="<?= e($f['nombre']) ?>" maxlength="80"></div>
              <div><label>Fecha</label><input type="date" name="fecha" value="<?= e($f['fecha']) ?>"></div>
              <div><label>Lugar</label><input type="text" name="lugar" value="<?= e($f['lugar']) ?>" maxlength="80"></div>
              <div><label><?= $ciclo ? 'Etapas' : 'Mangas' ?> (0 = las del campeonato)</label><input type="number" name="mangas" value="<?= (int)$f['mangas'] ?>" min="0" max="10"></div>
              <?php if ($mtb): $fo = mtb_formato($t, $f); ?>
                <div><label>Formato</label><select name="formato"><?php foreach (mtb_formatos() as $k => $x): ?><option value="<?= $k ?>" <?= $fo === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></div>
                <div><label>Vueltas de la carrera (XCO)</label><input type="number" name="vueltas_total" value="<?= (int)$f['vueltas_total'] ?>" min="0" max="99"></div>
              <?php endif; ?>
              <div><label>Nota</label><input type="text" name="nota" value="<?= e($f['nota']) ?>" maxlength="200"></div>
            </div><button class="btn btn-chico">Guardar</button></form>
          <form method="post" onsubmit="return confirm('¿Eliminar esta fecha con todos sus tiempos?')" style="margin-top:6px"><?= csrf_campo() ?><input type="hidden" name="accion" value="fecha_borrar"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar fecha</button></form>
        </details></div>
      <?php if (!$lista_part): ?>
        <p>Primero inscriba <?= e(mb_strtolower($T['participantes'])) ?> en la pestaña 2.</p>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="ejemplo"><button class="btn btn-sec btn-chico">🧪 Cargar un ejemplo para probar</button></form>
      <?php else: ?>
      <p class="ayuda">Escriba el tiempo como <b>1:23.456</b> o <b>83.456</b>. <?= $cfg['pen_cono'] > 0 ? 'Cada cono suma ' . e(rtrim(rtrim(number_format($cfg['pen_cono'], 2, '.', ''), '0'), '.')) . ' s automáticamente. ' : '' ?>Toque <b>⚑</b> para marcar penalizaciones con nombre (salida en falso, puerta…) o escribir el motivo de un DNF/DSQ. En «± seg» puede poner otro ajuste en segundos (positivo suma, negativo resta). Deje vacío a quien no participó.<?php if ($ciclo): ?> <b>Ruta:</b> escriba el tiempo real de llegada de cada uno; el sistema aplica solo el mismo tiempo del grupo, las bonificaciones de meta, los puntos y el fuera de control según la configuración de cada etapa. En ⚑ cargue las bonificaciones y puntos de los sprints intermedios y los puntos de montaña.<?php elseif ($mtb && mtb_formato($t, $f) === 'xco'): ?> <b>XCO:</b> en «vueltas» ponga las vueltas que completó; si el comisario lo retiró por la regla del 80 % o fue doblado, ponga su tiempo y sus vueltas (queda clasificado detrás, con «−N vueltas»).<?php endif; ?></p>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="tiempos_guardar"><input type="hidden" name="fecha_id" value="<?= (int)$f['id'] ?>">
        <div class="tabla-scroll"><table class="tabla-tiempos">
          <tr><th>#</th><th><?= e($T['participante']) ?></th><th>Cat.</th>
            <?php for ($m = 1; $m <= $nm; $m++): ?><th><?= e($T['manga']) ?> <?= $m ?><br><small class="suave">tiempo · <?= $cfg['pen_cono'] > 0 ? 'conos · ' : '' ?><?= $mtb && mtb_formato($t, $f) === 'xco' ? 'vueltas · ' : '' ?>± seg · estado<?= $ciclo ? ' · ⚑ bonif./puntos' : '' ?></small></th><?php endfor; ?></tr>
          <?php foreach ($lista_part as $p): $pid = (int)$p['id']; ?>
            <tr><td class="tt-num"><?= e($p['numero']) ?></td><td class="tt-nom"><?= e($p['nombre']) ?></td><td class="tt-cat"><small><?= e($p['categoria'] ?? '') ?></small></td>
              <?php for ($m = 1; $m <= $nm; $m++): $r = $prev[$pid][$m] ?? null; ?>
                <td class="tt-manga" data-manga="<?= e($T['manga']) ?> <?= $m ?>">
                  <input type="text" name="t[<?= $pid ?>][<?= $m ?>]" value="<?= $r && $r['tiempo_ms'] !== null ? e(carrera_fmt_tiempo((int)$r['tiempo_ms'])) : '' ?>" size="9" inputmode="decimal" placeholder="0:00.000" aria-label="Tiempo">
                  <?php if ($cfg['pen_cono'] > 0): ?><input type="number" name="c[<?= $pid ?>][<?= $m ?>]" value="<?= $r ? (int)$r['conos'] : 0 ?>" min="0" style="width:52px" title="Conos tumbados" aria-label="Conos"><span class="tt-lbl">conos</span><?php endif; ?>
                  <?php if ($mtb && mtb_formato($t, $f) === 'xco'): ?><input type="number" name="v[<?= $pid ?>][<?= $m ?>]" value="<?= $r && $r['vueltas'] !== null ? (int)$r['vueltas'] : '' ?>" min="0" max="99" style="width:52px" title="Vueltas completadas" aria-label="Vueltas"><span class="tt-lbl">vueltas</span><?php endif; ?>
                  <input type="text" name="a[<?= $pid ?>][<?= $m ?>]" value="<?= $r && (float)$r['ajuste_seg'] != 0 ? e(rtrim(rtrim(number_format((float)$r['ajuste_seg'], 3, '.', ''), '0'), '.')) : '' ?>" size="4" inputmode="decimal" placeholder="± seg" aria-label="Penalización o bonificación en segundos">
                  <select name="e[<?= $pid ?>][<?= $m ?>]" aria-label="Estado"><?php foreach (carrera_estados() as $k => $x): ?><option value="<?= $k ?>" <?= ($r['estado'] ?? 'ok') === $k ? 'selected' : '' ?>><?= e($k === 'ok' ? 'Terminó' : strtoupper($k)) ?></option><?php endforeach; ?></select>
                  <?php $pr = $r ? carrera_pens_de($r) : []; $mo = (string)($r['motivo'] ?? ''); $n_pr = array_sum($pr) + ($r && $ciclo ? ((float)$r['bonif_seg'] > 0 ? 1 : 0) + ((int)$r['pts_sprint'] > 0 ? 1 : 0) + ((int)$r['pts_montana'] > 0 ? 1 : 0) : 0); ?>
                  <details class="pen-mas"<?= $n_pr || $mo !== '' ? ' data-con="1"' : '' ?>><summary title="Penalizaciones y motivo">⚑<?= $n_pr ? '<b>' . $n_pr . '</b>' : '' ?><?= $mo !== '' ? '✎' : '' ?></summary>
                    <div class="pen-caja">
                      <?php foreach ($pens_act as $pen_id => $pn): ?>
                        <label><input type="number" name="pn[<?= $pid ?>][<?= $m ?>][<?= $pen_id ?>]" value="<?= $pr[$pen_id] ?? '' ?>" min="0" max="99" placeholder="0"> <?= e($pn['nombre']) ?> <span class="suave">(<?= (float)$pn['segundos'] > 0 ? '+' : '' ?><?= e(rtrim(rtrim(number_format((float)$pn['segundos'], 3, '.', ''), '0'), '.')) ?> s)</span></label>
                      <?php endforeach; ?>
                      <?php foreach ($pr as $pen_id => $veces): if (isset($pens_act[$pen_id])) continue; ?>
                        <input type="hidden" name="pn[<?= $pid ?>][<?= $m ?>][<?= $pen_id ?>]" value="<?= (int)$veces ?>">
                      <?php endforeach; ?>
                      <?php if ($ciclo): ?>
                        <label><input type="text" name="bs[<?= $pid ?>][<?= $m ?>]" value="<?= $r && (float)$r['bonif_seg'] ? e(rtrim(rtrim(number_format((float)$r['bonif_seg'], 1, '.', ''), '0'), '.')) : '' ?>" inputmode="decimal" style="width:56px;flex:0 0 56px;margin:0" placeholder="0"> Bonificación en sprints intermedios (s)</label>
                        <label><input type="number" name="ps[<?= $pid ?>][<?= $m ?>]" value="<?= $r && (int)$r['pts_sprint'] ? (int)$r['pts_sprint'] : '' ?>" min="0" max="999" placeholder="0"> Puntos de sprints intermedios</label>
                        <label><input type="number" name="pm[<?= $pid ?>][<?= $m ?>]" value="<?= $r && (int)$r['pts_montana'] ? (int)$r['pts_montana'] : '' ?>" min="0" max="999" placeholder="0"> Puntos de montaña (premios de montaña)</label>
                      <?php endif; ?>
                      <input type="text" name="mo[<?= $pid ?>][<?= $m ?>]" value="<?= e($mo) ?>" maxlength="120" placeholder="Motivo o nota (ej: DSQ por peso bajo el mínimo)">
                      <button type="button" class="btn btn-chico" style="margin-top:6px" onclick="var d=this.closest('details');d.open=false;var n=0;d.querySelectorAll('input[type=number]').forEach(function(i){n+=parseInt(i.value||0,10)||0});var mo=d.querySelector('input[type=text]').value.trim();d.querySelector('summary').innerHTML='⚑'+(n?'<b>'+n+'</b>':'')+(mo?'✎':'');if(n||mo)d.setAttribute('data-con','1');else d.removeAttribute('data-con')">Listo</button>
                    </div>
                  </details>
                </td>
              <?php endfor; ?></tr>
          <?php endforeach; ?>
        </table></div>
        <button class="btn" style="margin-top:8px">Guardar tiempos</button>
      </form>
      <?php endif; ?>
    </div>

    <?php $cl = carrera_clasificacion_fecha($t, $f);
    if ($ciclo && ($html_ciclo = ciclo_html_carrera($t, $f)) !== ''): ?>
      <h2 style="margin-top:20px">Clasificaciones de <?= e(carrera_nombre_fecha($f, $T)) ?></h2>
      <?= $html_ciclo ?>
      <?php if (count($cl['cats']) > 1): ?><div class="tarjeta"><h3 style="margin-top:0">Absoluta (todas las categorías)</h3><?= carrera_html_tabla_fecha($cl['abs'], $t, $f, false) ?></div><?php endif; ?>
    <?php elseif ($cl['abs']): ?>
      <h2 style="margin-top:20px">Clasificación de <?= e(carrera_nombre_fecha($f, $T)) ?><?= $mtb ? ' <small class="suave">· ' . e(mtb_formatos()[mtb_formato($t, $f)]) . '</small>' : '' ?></h2>
      <?php foreach ($cl['cats'] as $c): ?>
        <div class="tarjeta" style="margin-bottom:14px"><h3 style="margin-top:0"><?= e($c['cat']['nombre']) ?></h3><?= carrera_html_tabla_fecha($c['filas'], $t, $f) ?></div>
      <?php endforeach; ?>
      <div class="tarjeta"><h3 style="margin-top:0">Absoluta (todas las categorías)</h3><?= carrera_html_tabla_fecha($cl['abs'], $t, $f, false) ?></div>
    <?php endif; ?>
  <?php endif; ?>
<?php endif;
pie_admin();
