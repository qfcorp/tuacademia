<?php
/* (v25) Administración de un campeonato de DUELOS (tenis, pádel, tenis de mesa, artes marciales, boxeo):
   categorías, inscritos, sorteo de llaves o todos contra todos, resultados y medallero */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$t = torneo_de_liga((int)get('id'), $lid);
$mod_req = modulo_de_torneo($t); // (v35) requisitos del módulo deportivo
$tid = (int)$t['id'];
if (!es_duelo($t)) ir(es_individual($t) ? url_admin_individual($t) : 'admin.php?p=torneo&id=' . $tid);
$T = textos_duelo($t);
$cfg = duelo_cfg($t);
$s = get('s', 'resultados');
if (!in_array($s, ['resultados', 'participantes', 'categorias', 'medallero', 'ranking', 'config'], true)) $s = 'resultados';
$url = 'admin.php?p=duelo&id=' . $tid;
$num = fn(string $v) => (float)str_replace(',', '.', trim($v));
$categoria = function (int $id) use ($tid): array {
    return fila('SELECT * FROM duelo_categorias WHERE id = ? AND torneo_id = ?', [$id, $tid]) ?? throw new RuntimeException('Categoría no encontrada.');
};

if (es_post()) {
    $accion = post('accion');

    if ($accion === 'config') {
        if (post('nombre') === '') throw new RuntimeException('El nombre no puede quedar vacío.');
        $estado = in_array(post('estado'), ['inscripcion', 'en_juego', 'finalizado'], true) ? post('estado') : $t['estado'];
        q('UPDATE torneos SET nombre = ?, temporada = ?, estado = ?, duelo_sets = ?, duelo_rounds = ?, duelo_pts_gana = ?, duelo_pts_empate = ?, duelo_pts_pierde = ?, duelo_bronce = ?,
            duelo_game_pts = ?, duelo_game_dif = ?, duelo_factor = ?, duelo_rank_pts = ? WHERE id = ?', [
            mb_substr(post('nombre'), 0, 100), mb_substr(post('temporada'), 0, 30), $estado,
            in_array((int)post('sets'), [1, 3, 5, 7], true) ? (int)post('sets') : 3, entero(post('rounds'), 1, 15),
            entero(post('pts_gana', (string)$cfg['pts_gana']), 0, 10), entero(post('pts_empate', (string)$cfg['pts_empate']), 0, 10), entero(post('pts_pierde', (string)$cfg['pts_pierde']), 0, 10),
            in_array(post('bronce'), ['doble', 'partido', 'no'], true) ? post('bronce') : 'no',
            // (v36) games a 11 / 15 (squash, tenis de mesa), punto de oro y ranking del circuito
            in_array((int)post('game_pts', (string)$cfg['game_pts']), [0, 7, 9, 11, 15, 21], true) ? (int)post('game_pts', (string)$cfg['game_pts']) : 0, post('game_dif', (string)$cfg['game_dif']) === '1' ? 1 : 2,
            entero(post('factor', (string)$cfg['factor']), 0, 200), implode(',', duelo_rank_pts_leer(post('rank_pts', implode(',', $cfg['rank_pts'])))), $tid]);
        if (post('slug') !== $t['slug']) guardar_slug_torneo(array_merge($t, ['nombre' => post('nombre'), 'temporada' => post('temporada')]), post('slug'));
        flash('Reglas guardadas.' . (post('bronce') !== $cfg['bronce'] ? ' El cambio del tercer puesto se aplica a las llaves que se sorteen desde ahora.' : ''));
        ir($url . '&s=config');
    }

    if ($accion === 'eliminar') {
        if (post('confirmar') !== $t['nombre']) throw new RuntimeException('Escriba el nombre exacto del campeonato para eliminarlo.');
        q('DELETE FROM torneos WHERE id = ?', [$tid]);
        flash('Campeonato eliminado.');
        ir('admin.php?p=torneos');
    }

    if ($accion === 'cat_base') {
        $n = duelo_cargar_categorias_base($t);
        flash($n ? "Se cargaron $n categorías. Puede cambiarlas, borrarlas o agregar otras." : 'Las categorías de fábrica ya estaban cargadas.');
        ir($url . '&s=categorias');
    }

    if ($accion === 'cat_guardar') {
        $id = (int)post('id');
        $nombre = mb_substr(trim(post('nombre')), 0, 60);
        if ($nombre === '') throw new RuntimeException('Escriba el nombre de la categoría.');
        $d = [$nombre, array_key_exists(post('formato'), duelo_formatos()) ? post('formato') : 'llave', array_key_exists(post('rama'), duelo_ramas()) ? post('rama') : '',
            max(0, round($num(post('peso_min')), 1)), max(0, round($num(post('peso_max')), 1)), entero(post('edad_min'), 0, 99), entero(post('edad_max'), 0, 99), mb_substr(post('nota'), 0, 120),
            // (v36) grupos (tamaño y cuántos pasan), escalera (cuántos puestos arriba se puede desafiar) y rondas del suizo (0 = automático)
            entero(post('grupo_tam', '3'), 2, 8), entero(post('grupo_pasan', '2'), 1, 8), entero(post('escalera_salto', '3'), 1, 20), entero(post('rondas', '0'), 0, 15)];
        if ($d[3] > 0 && $d[4] > 0 && $d[4] <= $d[3]) throw new RuntimeException('El peso máximo debe ser mayor que el mínimo.');
        if ($d[5] > 0 && $d[6] > 0 && $d[6] < $d[5]) throw new RuntimeException('La edad máxima no puede ser menor que la mínima.');
        if ($id > 0) {
            $c = $categoria($id);
            if ($c['formato'] !== $d[1] && duelo_combates($id)) throw new RuntimeException('Para cambiar el formato, primero borre el sorteo de la categoría.');
            if ($c['formato'] === 'grupos' && duelo_combates($id) && ((int)$c['grupo_tam'] !== $d[8])) throw new RuntimeException('Los grupos ya están sorteados: para cambiar su tamaño, primero borre el sorteo.');
            q('UPDATE duelo_categorias SET nombre = ?, formato = ?, rama = ?, peso_min = ?, peso_max = ?, edad_min = ?, edad_max = ?, nota = ?, grupo_tam = ?, grupo_pasan = ?, escalera_salto = ?, rondas = ?, orden = ? WHERE id = ?', array_merge($d, [entero(post('orden'), 0, 999), $id]));
            flash('Categoría actualizada.');
        } else {
            $orden = (int)valor('SELECT COALESCE(MAX(orden), 0) + 1 FROM duelo_categorias WHERE torneo_id = ?', [$tid]);
            q('INSERT INTO duelo_categorias (nombre, formato, rama, peso_min, peso_max, edad_min, edad_max, nota, grupo_tam, grupo_pasan, escalera_salto, rondas, orden, torneo_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($d, [$orden, $tid]));
            flash('Categoría creada.');
        }
        ir($url . '&s=categorias');
    }

    if ($accion === 'cat_borrar') {
        $c = $categoria((int)post('id'));
        if (duelo_categoria_con_resultados((int)$c['id'])) throw new RuntimeException('La categoría ya tiene resultados: no se puede eliminar.');
        q('DELETE FROM duelo_categorias WHERE id = ?', [$c['id']]);
        flash('Categoría eliminada. Sus ' . mb_strtolower($T['participantes']) . ' quedaron sin categoría.');
        ir($url . '&s=categorias');
    }

    if ($accion === 'part_guardar') {
        $id = (int)post('id');
        $nombre = mb_substr(trim(post('nombre')), 0, 80);
        if ($nombre === '') throw new RuntimeException('Escriba el nombre.');
        $rama = array_key_exists(post('rama'), duelo_ramas()) ? post('rama') : '';
        $peso = trim(post('peso')) !== '' ? round($num(post('peso')), 1) : null;
        if ($peso !== null && ($peso <= 0 || $peso > 250)) throw new RuntimeException('Escriba el peso en kilos (ej: 63,5).');
        $nac = post('nacimiento') !== '' && strtotime(post('nacimiento')) ? date('Y-m-d', strtotime(post('nacimiento'))) : '';
        $cat = post('categoria_id');
        $aviso = '';
        if ($cat === 'auto') {
            $cat = duelo_categoria_auto($tid, $rama, $peso, $nac);
            if (!$cat) $aviso = ' No hay una categoría que corresponda a su rama, peso y edad: quedó sin categoría (elíjala a mano o cree la categoría).';
        } else {
            $cat = (int)$cat > 0 && fila('SELECT id FROM duelo_categorias WHERE id = ? AND torneo_id = ?', [(int)$cat, $tid]) ? (int)$cat : null;
        }
        $d = [$cat ?: null, $nombre, $T['dobles'] ? mb_substr(trim(post('pareja')), 0, 80) : '', mb_substr(post('club'), 0, 60), mb_substr(post('ciudad'), 0, 40), $rama, $nac, $peso,
            mb_substr(post('grado'), 0, 40), entero(post('siembra'), 0, 64), post('activo', '1') === '1' ? 1 : 0];
        if ($id > 0) {
            $p = fila('SELECT * FROM duelo_participantes WHERE id = ? AND torneo_id = ?', [$id, $tid]) ?? throw new RuntimeException('No encontrado.');
            if ((int)($p['categoria_id'] ?? 0) !== (int)($cat ?? 0) && $p['categoria_id'] && fila('SELECT id FROM duelo_combates WHERE categoria_id = ? AND (a_id = ? OR b_id = ?)', [$p['categoria_id'], $id, $id])) {
                throw new RuntimeException('Ya está en el sorteo de su categoría. Para cambiarlo de categoría, primero borre el sorteo de esa categoría.');
            }
            q('UPDATE duelo_participantes SET categoria_id = ?, nombre = ?, pareja = ?, club = ?, ciudad = ?, rama = ?, nacimiento = ?, peso = ?, grado = ?, siembra = ?, activo = ? WHERE id = ?', array_merge($d, [$id]));
            guardar_requisitos($lid, $mod_req, 'duelo_participantes', $id); // (v35)
        } else {
            exigir_autorizacion(); // (v24) protección de datos
            q('INSERT INTO duelo_participantes (categoria_id, nombre, pareja, club, ciudad, rama, nacimiento, peso, grado, siembra, activo, torneo_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($d, [$tid]));
            $nid = (int)db()->lastInsertId();
            registrar_autorizacion_tercero('participante_duelo', $lid, duelo_nombre(['nombre' => $d[1], 'pareja' => $d[2]]), 'la organización del campeonato ' . $t['nombre'], ['ref_tabla' => 'duelo_participantes', 'ref_id' => $nid]);
            guardar_requisitos($lid, $mod_req, 'duelo_participantes', $nid); // (v35)
            if ($cat && duelo_combates((int)$cat)) $aviso .= ' Ojo: esa categoría ya está sorteada; para que entre a la llave hay que volver a sortearla.';
        }
        flash(($id > 0 ? 'Datos guardados.' : $T['participante'] . ' inscrito.') . $aviso, $aviso && !$cat ? 'error' : 'ok');
        ir($url . '&s=participantes');
    }

    if ($accion === 'part_auto') {
        $n = 0;
        $con_sorteo = array_map('intval', array_column(filas('SELECT DISTINCT categoria_id FROM duelo_combates WHERE torneo_id = ?', [$tid]), 'categoria_id'));
        foreach (filas('SELECT * FROM duelo_participantes WHERE torneo_id = ?', [$tid]) as $p) {
            if ($p['categoria_id'] && in_array((int)$p['categoria_id'], $con_sorteo, true)) continue;
            $c = duelo_categoria_auto($tid, (string)$p['rama'], $p['peso'] !== null ? (float)$p['peso'] : null, (string)$p['nacimiento']);
            if ($c && (int)$c !== (int)$p['categoria_id'] && !in_array((int)$c, $con_sorteo, true)) { q('UPDATE duelo_participantes SET categoria_id = ? WHERE id = ?', [$c, $p['id']]); $n++; }
        }
        flash($n ? "Se reasignó la categoría de $n " . mb_strtolower($T['participantes']) . ' (las categorías ya sorteadas no se tocan).' : 'Todos ya estaban en la categoría que les corresponde.');
        ir($url . '&s=participantes');
    }

    if ($accion === 'part_borrar') {
        $id = (int)post('id');
        if (fila('SELECT id FROM duelo_combates WHERE torneo_id = ? AND (a_id = ? OR b_id = ?)', [$tid, $id, $id])) throw new RuntimeException('Ya está en un sorteo. Primero borre el sorteo de su categoría (o márquelo como retirado y cargue W.O. en sus combates).');
        q('DELETE FROM duelo_participantes WHERE id = ? AND torneo_id = ?', [$id, $tid]); borrar_requisitos('duelo_participantes', $id);
        flash('Eliminado.');
        ir($url . '&s=participantes');
    }

    if ($accion === 'sortear') {
        $c = $categoria((int)post('categoria_id'));
        $n = duelo_sortear($t, $c);
        if ($t['estado'] === 'inscripcion') q("UPDATE torneos SET estado = 'en_juego' WHERE id = ?", [$tid]);
        flash(match ($c['formato']) {
            'escalera' => 'Escalera armada: los cabezas de serie arriba y el resto al azar. Ya se pueden cargar desafíos.',
            'suizo' => 'Ronda 1 emparejada: ' . $n . ' ' . mb_strtolower($T['combates']) . '. Cuando cargue todos los resultados, empareje la ronda siguiente.',
            'grupos' => 'Grupos sorteados: ' . $n . ' ' . mb_strtolower($T['combates']) . '. La llave final se arma sola al terminar los grupos.',
            'liga' => 'Sorteo hecho: ' . $n . ' ' . mb_strtolower($T['combates']) . '.',
            default => 'Sorteo hecho: ' . $n . ' ' . mb_strtolower($T['combates']) . ' (incluye las rondas siguientes y los pases directos).',
        });
        ir($url . '&s=resultados&cat=' . (int)$c['id']);
    }

    if ($accion === 'sorteo_borrar') {
        $c = $categoria((int)post('categoria_id'));
        if (duelo_categoria_con_resultados((int)$c['id']) && post('confirmar') !== 'BORRAR') throw new RuntimeException('Esta categoría ya tiene resultados. Para borrar el sorteo con sus resultados, escriba BORRAR en la casilla.');
        q('DELETE FROM duelo_combates WHERE categoria_id = ?', [$c['id']]);
        if ($c['formato'] === 'escalera') q('UPDATE duelo_participantes SET siembra = 0 WHERE categoria_id = ?', [$c['id']]);
        flash('Sorteo borrado. Puede corregir los inscritos y volver a sortear.');
        ir($url . '&s=resultados&cat=' . (int)$c['id']);
    }

    // (v36) suizo: emparejar la ronda siguiente o borrar la última
    if ($accion === 'suizo_ronda' || $accion === 'suizo_borrar') {
        $c = $categoria((int)post('categoria_id'));
        if ($c['formato'] !== 'suizo') throw new RuntimeException('Esta categoría no es de sistema suizo.');
        if ($accion === 'suizo_ronda') {
            $n = duelo_suizo_emparejar($t, $c);
            flash('Ronda emparejada (' . $n . ' ' . mb_strtolower($T['combates']) . ').');
        } else {
            $max = (int)valor('SELECT COALESCE(MAX(ronda), 0) FROM duelo_combates WHERE categoria_id = ?', [$c['id']]);
            if ((int)valor("SELECT COUNT(*) FROM duelo_combates WHERE categoria_id = ? AND ronda = ? AND estado = 'jugado'", [$c['id'], $max]) && post('confirmar') !== 'BORRAR') throw new RuntimeException('La última ronda tiene resultados: escriba BORRAR para borrarla con sus resultados.');
            $r = duelo_suizo_borrar_ronda($c);
            flash($r ? 'Se borró la ronda ' . $r . '.' : 'No había rondas.');
        }
        ir($url . '&s=resultados&cat=' . (int)$c['id']);
    }

    // (v36) escalera: nuevo desafío
    if ($accion === 'desafio') {
        $c = $categoria((int)post('categoria_id'));
        if ($c['formato'] !== 'escalera') throw new RuntimeException('Esta categoría no es una escalera.');
        $nid = duelo_escalera_desafio($t, $c, (int)post('retador'), (int)post('defensor'), post('fecha'), post('hora'));
        flash('Desafío creado. Cargue el resultado cuando se juegue.');
        ir($url . '&s=resultados&cat=' . (int)$c['id'] . '&c=' . $nid . '#combate');
    }

    // (v36) grupos: armar de nuevo la llave final (por si cambió algo)
    if ($accion === 'grupos_llave') {
        $c = $categoria((int)post('categoria_id'));
        $n = duelo_grupos_armar_llave($t, $c);
        flash('Llave final armada (' . $n . ' ' . mb_strtolower($T['combates']) . ').');
        ir($url . '&s=resultados&cat=' . (int)$c['id']);
    }

    if ($accion === 'desafio_borrar') {
        $cb = fila("SELECT * FROM duelo_combates WHERE id = ? AND torneo_id = ? AND estado = 'pendiente'", [(int)post('combate_id'), $tid]) ?? throw new RuntimeException('Solo se puede borrar un desafío pendiente.');
        q('DELETE FROM duelo_combates WHERE id = ?', [$cb['id']]);
        flash('Desafío borrado.');
        ir($url . '&s=resultados&cat=' . (int)$cb['categoria_id']);
    }

    if ($accion === 'resultado' || $accion === 'resultado_borrar') {
        $cb = fila('SELECT * FROM duelo_combates WHERE id = ? AND torneo_id = ?', [(int)post('combate_id'), $tid]) ?? throw new RuntimeException('No encontrado.');
        if ($accion === 'resultado_borrar') {
            duelo_borrar_resultado($t, $cb);
            flash('Resultado borrado.');
        } else {
            $r = duelo_guardar_resultado($t, $cb, $_POST);
            $fmt_cb = (string)valor('SELECT formato FROM duelo_categorias WHERE id = ?', [$cb['categoria_id']]);
            flash($r === 'programado' ? 'Programación guardada.' : 'Resultado guardado. ' . match (true) {
                $fmt_cb === 'escalera' => 'La escalera ya está actualizada.',
                $fmt_cb === 'grupos' && (int)$cb['bloque'] > 0 => duelo_grupos_terminados((int)$cb['categoria_id']) ? 'Terminaron los grupos: la llave final ya está armada.' : 'La tabla del grupo ya está actualizada.',
                duelo_es_llave($fmt_cb) => 'La llave ya está actualizada.',
                default => 'La tabla ya está actualizada.',
            });
        }
        ir($url . '&s=resultados&cat=' . (int)$cb['categoria_id']);
    }

    if ($accion === 'ejemplo') {
        duelo_cargar_ejemplo($t);
        flash('Se cargó un ejemplo con ' . mb_strtolower($T['participantes']) . ', una llave y un todos contra todos. Bórrelos cuando ya no los necesite.');
        ir($url . '&s=resultados');
    }
    ir($url);
}

$t = fila('SELECT * FROM torneos WHERE id = ?', [$tid]);
$cats = duelo_categorias($tid);
$part = duelo_participantes($tid);
$n_por_cat = [];
foreach ($part as $p) if ((int)$p['activo']) $n_por_cat[(int)($p['categoria_id'] ?? 0)] = ($n_por_cat[(int)($p['categoria_id'] ?? 0)] ?? 0) + 1;
$estados_t = ['inscripcion' => 'Inscripción', 'en_juego' => 'En juego', 'finalizado' => 'Finalizado'];
$txt_formato = match ($cfg['tipo']) {
    'sets' => 'al mejor de ' . $cfg['sets'] . ($cfg['game_pts'] > 0 ? ' games a ' . $cfg['game_pts'] . ($cfg['game_dif'] === 1 ? ' (punto de oro)' : ' (diferencia de 2)') : ' sets'),
    'rounds' => $cfg['rounds'] . ' rounds',
    'ajedrez' => 'ganar 1 · tablas ½ · perder 0',
    'goles' => 'por goles (penales en la llave)',
    default => 'por puntos',
};
$txt_bronce = ['doble' => 'dos bronces (los semifinalistas)', 'partido' => 'partido por el tercer puesto', 'no' => 'sin tercer puesto'][$cfg['bronce']];

cabecera_admin($t['nombre']);
?>
<p><a href="admin.php?p=torneos">← Campeonatos</a></p>
<div class="fila-titulo">
  <h1><?= icono_duelo($t) ?> <?= e($t['nombre']) ?> <small><?= e($t['temporada']) ?> · <?= e(nombre_modalidad($t['modalidad'])) ?> · <?= e($estados_t[$t['estado']] ?? $t['estado']) ?></small></h1>
  <span class="acciones"><?= html_botones_individual($t) ?></span>
</div>
<p class="suave" style="margin-top:6px"><?= count($cats) ?> categorías · <?= count($part) ?> <?= e(mb_strtolower($T['participantes'])) ?> · <?= e($txt_formato) ?> · <?= e($txt_bronce) ?></p>
<nav class="pestanas">
  <?php foreach (['resultados' => '1. Sorteo y resultados', 'participantes' => '2. ' . $T['participantes'], 'categorias' => 'Categorías', 'medallero' => '🏅 Medallero', 'ranking' => '🏆 Ranking', 'config' => 'Reglas y configuración'] as $k => $txt): ?>
    <a href="<?= e($url . '&s=' . $k) ?>" class="<?= $s === $k ? 'activa' : '' ?>"><?= e($txt) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($s === 'categorias'):
    $form_cat = function (?array $c) { $c = $c ?? ['id' => 0, 'nombre' => '', 'formato' => 'llave', 'rama' => '', 'peso_min' => 0, 'peso_max' => 0, 'edad_min' => 0, 'edad_max' => 0, 'nota' => '', 'orden' => 0, 'grupo_tam' => 3, 'grupo_pasan' => 2, 'escalera_salto' => 3, 'rondas' => 0];
        $kg = fn($x) => (float)$x > 0 ? rtrim(rtrim(number_format((float)$x, 1, ',', ''), '0'), ',') : ''; ?>
      <div class="campos">
        <div><label>Nombre *</label><input type="text" name="nombre" value="<?= e($c['nombre']) ?>" maxlength="60" required placeholder="Ej: Varones −71 kg"></div>
        <div><label>Formato</label><select name="formato"><?php foreach (duelo_formatos() as $k => $x): ?><option value="<?= $k ?>" <?= $c['formato'] === $k ? 'selected' : '' ?> title="<?= e(duelo_formato_ayuda($k)) ?>"><?= e($x) ?></option><?php endforeach; ?></select>
          <div class="ayuda"><?= e(duelo_formato_ayuda($c['formato'])) ?></div></div>
        <div><label>Grupos: jugadores por grupo</label><input type="number" name="grupo_tam" value="<?= (int)$c['grupo_tam'] ?>" min="2" max="8"><div class="ayuda">Solo «Grupos y luego llave». Squash: 3 (máximo 5).</div></div>
        <div><label>Grupos: pasan a la llave</label><input type="number" name="grupo_pasan" value="<?= (int)$c['grupo_pasan'] ?>" min="1" max="8"><div class="ayuda">Los primeros de cada grupo.</div></div>
        <div><label>Suizo: rondas</label><input type="number" name="rondas" value="<?= (int)$c['rondas'] ?: '' ?>" min="0" max="15" placeholder="Automático"><div class="ayuda">Vacío = según los inscritos (8 → 4 rondas).</div></div>
        <div><label>Escalera: desafiar hasta</label><input type="number" name="escalera_salto" value="<?= (int)$c['escalera_salto'] ?>" min="1" max="20"><div class="ayuda">Puestos más arriba.</div></div>
        <div><label>Rama</label><select name="rama"><?php foreach (duelo_ramas() as $k => $x): ?><option value="<?= e($k) ?>" <?= $c['rama'] === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></div>
        <div><label>Peso desde (kg, sin incluir)</label><input type="text" name="peso_min" value="<?= e($kg($c['peso_min'])) ?>" inputmode="decimal" placeholder="—"></div>
        <div><label>Peso hasta (kg, incluido)</label><input type="text" name="peso_max" value="<?= e($kg($c['peso_max'])) ?>" inputmode="decimal" placeholder="—"></div>
        <div><label>Edad desde</label><input type="number" name="edad_min" value="<?= (int)$c['edad_min'] ?: '' ?>" min="0" max="99" placeholder="—"></div>
        <div><label>Edad hasta</label><input type="number" name="edad_max" value="<?= (int)$c['edad_max'] ?: '' ?>" min="0" max="99" placeholder="—"></div>
        <div><label>Nota</label><input type="text" name="nota" value="<?= e($c['nota']) ?>" maxlength="120" placeholder="Ej: cinturón verde a marrón"></div>
        <?php if ($c['id']): ?><div><label>Orden</label><input type="number" name="orden" value="<?= (int)$c['orden'] ?>" min="0"></div><?php endif; ?>
      </div>
    <?php }; ?>
  <div class="rejilla-2">
    <div class="tarjeta">
      <h2>Categorías (<?= count($cats) ?>)</h2>
      <?php if (!$cats): ?><p class="suave">Aún no hay categorías. Cargue las de fábrica o cree las suyas.</p><?php endif; ?>
      <?php foreach ($cats as $c): ?>
        <details style="margin-bottom:8px"><summary><b><?= e($c['nombre']) ?></b> <span class="suave"><?= e(duelo_formatos()[$c['formato']]) ?><?= duelo_texto_categoria($c) !== '' ? ' · ' . e(duelo_texto_categoria($c)) : '' ?> · <?= (int)($n_por_cat[(int)$c['id']] ?? 0) ?> inscritos</span></summary>
          <form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="cat_guardar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><?php $form_cat($c); ?><button class="btn btn-chico">Guardar</button></form>
          <form method="post" onsubmit="return confirm('¿Eliminar la categoría?')" style="margin-top:6px"><?= csrf_campo() ?><input type="hidden" name="accion" value="cat_borrar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar categoría</button></form>
        </details>
      <?php endforeach; ?>
      <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="cat_base"><button class="btn btn-sec">📋 Cargar categorías típicas de <?= e(mb_strtolower(nombre_modalidad($t['modalidad']))) ?></button></form>
      <p class="ayuda">Con «categoría automática» cada inscrito entra a la primera categoría que cumpla su rama, su peso y su edad (en el orden de esta lista).</p>
    </div>
    <div class="tarjeta">
      <h2>Nueva categoría</h2>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="cat_guardar"><input type="hidden" name="id" value="0"><?php $form_cat(null); ?><button class="btn">Crear categoría</button></form>
    </div>
  </div>

<?php elseif ($s === 'participantes'):
    $nombres_cat = array_column($cats, 'nombre', 'id');
    $lista = $part;
    uasort($lista, fn($a, $b) => [array_search((int)($a['categoria_id'] ?? 0), array_map('intval', array_column($cats, 'id'))) === false ? 999 : array_search((int)($a['categoria_id'] ?? 0), array_map('intval', array_column($cats, 'id'))), (int)$a['siembra'] ?: 99, $a['nombre']]
        <=> [array_search((int)($b['categoria_id'] ?? 0), array_map('intval', array_column($cats, 'id'))) === false ? 999 : array_search((int)($b['categoria_id'] ?? 0), array_map('intval', array_column($cats, 'id'))), (int)$b['siembra'] ?: 99, $b['nombre']]);
    $form_part = function (?array $p) use ($T, $cats, $t) { $p = $p ?? ['id' => 0, 'nombre' => '', 'pareja' => '', 'club' => '', 'ciudad' => '', 'rama' => '', 'nacimiento' => '', 'peso' => null, 'grado' => '', 'siembra' => 0, 'activo' => 1, 'categoria_id' => 'auto']; ?>
      <div class="campos">
        <div><label><?= $T['dobles'] ? 'Jugador 1 *' : 'Nombre *' ?></label><input type="text" name="nombre" value="<?= e($p['nombre']) ?>" maxlength="80" required></div>
        <?php if ($T['dobles']): ?><div><label>Jugador 2 <?= $t['modalidad'] === 'PADEL' ? '*' : '(solo en dobles)' ?></label><input type="text" name="pareja" value="<?= e($p['pareja']) ?>" maxlength="80" <?= $t['modalidad'] === 'PADEL' ? 'required' : '' ?>></div><?php endif; ?>
        <div><label>Club / academia</label><input type="text" name="club" value="<?= e($p['club']) ?>" maxlength="60"></div>
        <div><label>Ciudad</label><input type="text" name="ciudad" value="<?= e($p['ciudad']) ?>" maxlength="40"></div>
        <div><label>Rama</label><select name="rama"><?php foreach (['' => '—', 'M' => 'Varón', 'F' => 'Dama', 'X' => 'Mixto (pareja)'] as $k => $x): ?><option value="<?= e($k) ?>" <?= $p['rama'] === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></div>
        <div><label>Fecha de nacimiento</label><input type="date" name="nacimiento" value="<?= e($p['nacimiento']) ?>"></div>
        <?php if ($t['modalidad'] === 'AJED'): ?>
          <div><label>ELO / rating (opcional)</label><input type="text" name="grado" value="<?= e($p['grado']) ?>" maxlength="40" inputmode="numeric" placeholder="Ej: 1850"><div class="ayuda">Ordena la primera ronda del suizo.</div></div>
        <?php endif; ?>
        <?php if (in_array($t['modalidad'], ['MARC', 'BOXEO'], true)): ?>
          <div><label>Peso (kg)</label><input type="text" name="peso" value="<?= $p['peso'] !== null ? e(rtrim(rtrim(number_format((float)$p['peso'], 1, ',', ''), '0'), ',')) : '' ?>" inputmode="decimal" placeholder="Ej: 63,5"></div>
          <div><label><?= $t['modalidad'] === 'MARC' ? 'Cinturón / grado' : 'Récord (G-P-E)' ?></label><input type="text" name="grado" value="<?= e($p['grado']) ?>" maxlength="40" <?= $t['modalidad'] === 'MARC' ? 'list="grados-duelo"' : 'placeholder="Ej: 5-1-0"' ?>></div>
        <?php endif; ?>
        <div><label>Cabeza de serie</label><input type="number" name="siembra" value="<?= (int)$p['siembra'] ?: '' ?>" min="0" max="64" placeholder="—"><div class="ayuda">1 = el mejor. Los cabezas de serie no se cruzan al inicio.</div></div>
        <div><label>Categoría</label><select name="categoria_id">
          <option value="auto" <?= $p['categoria_id'] === 'auto' ? 'selected' : '' ?>>Automática (por rama, peso y edad)</option>
          <option value="0">Sin categoría</option>
          <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$p['categoria_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
        </select></div>
        <?php if ($p['id']): ?><div><label>Estado</label><select name="activo"><option value="1" <?= (int)$p['activo'] ? 'selected' : '' ?>>Activo</option><option value="0" <?= !(int)$p['activo'] ? 'selected' : '' ?>>Retirado</option></select></div><?php endif; ?>
      </div>
    <?php }; ?>
  <?php if ($t['modalidad'] === 'MARC'): ?><datalist id="grados-duelo"><?php foreach (grados_referencia() as $g): ?><option value="<?= e($g) ?>"><?php endforeach; ?></datalist><?php endif; ?>
  <div class="tarjeta">
    <h2><?= e($T['participantes']) ?> (<?= count($lista) ?>)</h2>
    <?php if (!$lista): ?><p class="suave">Aún no hay inscritos.</p>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="ejemplo"><button class="btn btn-sec btn-chico">🧪 Cargar un ejemplo para probar</button></form><?php endif; ?>
    <?php if ($lista): ?>
    <div class="tabla-scroll"><table>
      <tr><th><?= e($T['participante']) ?></th><th>Club</th><th>Datos</th><th>Categoría</th><th></th></tr>
      <?php foreach ($lista as $p): $edad = duelo_edad((string)$p['nacimiento']); ?>
        <tr<?= (int)$p['activo'] ? '' : ' style="opacity:.55"' ?>><td><?= e(duelo_nombre($p)) ?><?= (int)$p['siembra'] ? ' <span class="etiqueta">[' . (int)$p['siembra'] . ']</span>' : '' ?><?= (int)$p['activo'] ? '' : ' <span class="etiqueta">retirado</span>' ?> <?= html_estado_requisitos('duelo_participantes', (int)$p['id'], $mod_req) ?></td>
          <td><?= e($p['club']) ?></td>
          <td><small><?= e(trim(implode(' · ', array_filter([duelo_ramas()[$p['rama']] ?? '', $edad !== null ? $edad . ' años' : '', $p['peso'] !== null ? rtrim(rtrim(number_format((float)$p['peso'], 1, ',', ''), '0'), ',') . ' kg' : '', $p['grado']])))) ?></small></td>
          <td><?= $p['categoria_id'] && isset($nombres_cat[$p['categoria_id']]) ? e($nombres_cat[$p['categoria_id']]) : '<span class="etiqueta roja">sin categoría</span>' ?></td>
          <td><details><summary>Editar</summary>
            <form method="post" enctype="multipart/form-data" style="min-width:260px"><?= csrf_campo() ?><input type="hidden" name="accion" value="part_guardar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><?php $form_part($p); ?><?= html_requisitos_form($mod_req, 'duelo_participantes', (int)$p['id']) ?><button class="btn btn-chico">Guardar</button></form>
            <form method="post" onsubmit="return confirm('¿Eliminar?')" style="margin-top:6px"><?= csrf_campo() ?><input type="hidden" name="accion" value="part_borrar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar</button></form>
          </details></td></tr>
      <?php endforeach; ?>
    </table></div>
    <form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="part_auto"><button class="btn btn-sec btn-chico">↻ Reasignar categorías automáticamente</button></form>
    <?php endif; ?>
  </div>
  <div class="tarjeta" style="margin-top:16px">
    <h2>Inscribir <?= e(mb_strtolower($T['participante'])) ?></h2>
    <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="part_guardar"><input type="hidden" name="id" value="0"><?php $form_part(null); ?><?= html_requisitos_form($mod_req, 'duelo_participantes', 0) ?><?= html_autorizacion('tercero', 'la organización del campeonato') ?><button class="btn">Inscribir</button></form>
  </div>

<?php elseif ($s === 'medallero'): ?>
  <?= duelo_css() ?>
  <div class="tarjeta"><?= duelo_html_medallero($t, duelo_medallero($t)) ?></div>

<?php elseif ($s === 'ranking'): ?>
  <div class="tarjeta">
    <h2>🏆 Ranking del circuito de <?= e(mb_strtolower(nombre_modalidad($t['modalidad']))) ?></h2>
    <p class="ayuda">Suma los puntos de todos los campeonatos de <?= e(mb_strtolower(nombre_modalidad($t['modalidad']))) ?> de su cuenta, por categoría (mismo nombre de categoría). Cada campeonato da puntos según el puesto final, multiplicados por su factor (por ejemplo: nacional 100 %, regional 50 %). Se cambian en «Reglas y configuración».</p>
    <?= duelo_html_ranking(duelo_ranking($lid, (string)$t['modalidad'])) ?>
  </div>

<?php elseif ($s === 'config'): ?>
  <div class="tarjeta">
    <h2>Reglas y configuración</h2>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="config">
      <div class="campos">
        <div><label>Nombre *</label><input type="text" name="nombre" value="<?= e($t['nombre']) ?>" maxlength="100" required></div>
        <div><label>Temporada</label><input type="text" name="temporada" value="<?= e($t['temporada']) ?>" maxlength="30"></div>
        <div><label>Estado</label><select name="estado"><?php foreach ($estados_t as $k => $x): ?><option value="<?= $k ?>" <?= $t['estado'] === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></div>
        <div><label>Dirección web (final)</label><input type="text" name="slug" value="<?= e($t['slug']) ?>" maxlength="60"></div>
        <?php if ($cfg['tipo'] === 'sets'): ?>
          <div><label>Partidos al mejor de</label><select name="sets"><?php foreach ([1, 3, 5, 7] as $n): ?><option value="<?= $n ?>" <?= $cfg['sets'] === $n ? 'selected' : '' ?>><?= $n ?> <?= $cfg['game_pts'] > 0 ? 'game' : 'set' ?><?= $n > 1 ? 's' : '' ?></option><?php endforeach; ?></select><div class="ayuda">Tenis y pádel: 3 sets. Tenis de mesa y squash: 5 games (dobles de squash: 3).</div></div>
          <div><label>Puntos de cada game</label><select name="game_pts"><?php foreach ([0 => 'Libre (tenis, pádel: 6-4, 7-6…)', 11 => 'A 11 (squash, tenis de mesa)', 15 => 'A 15', 21 => 'A 21', 9 => 'A 9', 7 => 'A 7'] as $k => $x): ?><option value="<?= $k ?>" <?= $cfg['game_pts'] === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select><div class="ayuda">Con un número, el sistema revisa que cada game esté bien terminado.</div></div>
          <div><label>Empate al final del game (ej. 10-10)</label><select name="game_dif"><option value="2" <?= $cfg['game_dif'] === 2 ? 'selected' : '' ?>>Se gana con 2 de diferencia (12-10, 13-11…)</option><option value="1" <?= $cfg['game_dif'] === 1 ? 'selected' : '' ?>>Punto de oro: el siguiente punto gana (11-10)</option></select><div class="ayuda">Squash individual: 2 de diferencia. Dobles de squash (WSF): punto de oro.</div></div>
        <?php else: ?><input type="hidden" name="sets" value="<?= $cfg['sets'] ?>"><?php endif; ?>
        <?php if ($cfg['tipo'] === 'rounds'): ?>
          <div><label>Rounds por pelea</label><input type="number" name="rounds" value="<?= $cfg['rounds'] ?>" min="1" max="15"></div>
        <?php else: ?><input type="hidden" name="rounds" value="<?= $cfg['rounds'] ?>"><?php endif; ?>
        <div><label>Tercer puesto (llaves)</label><select name="bronce">
          <?php foreach (['doble' => 'Dos bronces: los dos semifinalistas', 'partido' => 'Se juega un partido por el tercer puesto', 'no' => 'Sin tercer puesto'] as $k => $x): ?><option value="<?= $k ?>" <?= $cfg['bronce'] === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?>
        </select><div class="ayuda">Artes marciales y boxeo: normalmente dos bronces.</div></div>
        <?php if ($cfg['tipo'] !== 'ajedrez'): ?>
        <div><label>Todos contra todos: puntos por ganar</label><input type="number" name="pts_gana" value="<?= $cfg['pts_gana'] ?>" min="0" max="10"></div>
        <div><label>… por empatar</label><input type="number" name="pts_empate" value="<?= $cfg['pts_empate'] ?>" min="0" max="10"><div class="ayuda"><?= $cfg['empate'] ? 'Solo en todos contra todos, grupos y suizo.' : 'En raqueta no hay empates.' ?></div></div>
        <div><label>… por perder</label><input type="number" name="pts_pierde" value="<?= $cfg['pts_pierde'] ?>" min="0" max="10"></div>
        <?php endif; ?>
        <div><label>Ranking: factor de este campeonato (%)</label><input type="number" name="factor" value="<?= $cfg['factor'] ?>" min="0" max="200"><div class="ayuda">100 = nacional, 50 = regional, 0 = no cuenta para el ranking.</div></div>
        <div class="campo-ancho"><label>Ranking: puntos por puesto (<?= e(implode(' · ', duelo_rank_tramos())) ?>)</label><input type="text" name="rank_pts" value="<?= e(implode(', ', $cfg['rank_pts'])) ?>" maxlength="80"></div>
      </div>
      <button class="btn">Guardar</button>
    </form>
  </div>
  <?= html_inscripcion_indiv_config($t) ?>
  <div class="tarjeta" style="margin-top:16px"><h2>Eliminar campeonato</h2>
    <form method="post" onsubmit="return confirm('¿Eliminar el campeonato con todos sus resultados?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar">
      <div class="campos"><div><label>Escriba el nombre exacto para confirmar</label><input type="text" name="confirmar" autocomplete="off"></div></div>
      <button class="btn btn-peligro">Eliminar</button></form></div>

<?php else: /* sorteo y resultados */
    echo duelo_css();
    if (!$cats): ?>
      <div class="tarjeta"><p>Primero cree las categorías (pestaña <a href="<?= e($url . '&s=categorias') ?>">Categorías</a>) e inscriba a los <?= e(mb_strtolower($T['participantes'])) ?>.</p>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="ejemplo"><button class="btn btn-sec btn-chico">🧪 Cargar un ejemplo para probar</button></form></div>
    <?php else:
    $cid = (int)get('cat');
    if (!$cid && get('c') !== '') $cid = (int)valor('SELECT categoria_id FROM duelo_combates WHERE id = ? AND torneo_id = ?', [(int)get('c'), $tid]); // abrir la categoría del combate
    $cat = null;
    foreach ($cats as $c) if ((int)$c['id'] === $cid) $cat = $c;
    if (!$cat) foreach ($cats as $c) if (duelo_combates((int)$c['id']) || ($n_por_cat[(int)$c['id']] ?? 0) >= 2) { $cat = $c; break; }
    $cat = $cat ?? $cats[0];
    $combs = duelo_combates((int)$cat['id']);
    $part_cat = duelo_participantes($tid, (int)$cat['id']);
    $sel = null;
    if (get('c') !== '') foreach ($combs as $x) if ((int)$x['id'] === (int)get('c')) $sel = $x;
    $enlace = fn($c) => in_array($c['estado'], ['bye', 'vacio'], true) ? '' : $url . '&s=resultados&cat=' . (int)$cat['id'] . '&c=' . (int)$c['id'] . '#combate';
    ?>
    <p><?php foreach ($cats as $c): $nc = (int)($n_por_cat[(int)$c['id']] ?? 0); $sorteada = (int)valor('SELECT COUNT(*) FROM duelo_combates WHERE categoria_id = ?', [$c['id']]) > 0; ?>
      <a class="btn <?= (int)$c['id'] === (int)$cat['id'] ? '' : 'btn-sec' ?> btn-chico" href="<?= e($url . '&s=resultados&cat=' . (int)$c['id']) ?>"><?= e($c['nombre']) ?> <small>(<?= $nc ?><?= $sorteada ? ' ✓' : '' ?>)</small></a>
    <?php endforeach; ?></p>

    <?php if ($sel):
        $pa = $sel['a_id'] ? ($part_cat[(int)$sel['a_id']] ?? null) : null;
        $pb = $sel['b_id'] ? ($part_cat[(int)$sel['b_id']] ?? null) : null;
        $gan_sel = $sel['ganador_id'] === null ? ($sel['estado'] === 'jugado' ? 'empate' : '') : ((int)$sel['ganador_id'] === (int)$sel['a_id'] ? 'a' : 'b');
        $fmt = (string)$cat['formato'];
        $con_empate = in_array($fmt, ['liga', 'grupos', 'suizo'], true) && !($fmt === 'grupos' && (int)$sel['bloque'] === 0) && $cfg['empate'];
        $aj = $cfg['tipo'] === 'ajedrez';
        $nom_a = $aj ? '♔ ' . duelo_nombre($pa) . ' (blancas)' : duelo_nombre($pa);
        $nom_b = $aj ? '♚ ' . duelo_nombre($pb) . ' (negras)' : duelo_nombre($pb); ?>
      <div class="tarjeta" id="combate" style="border:2px solid var(--acento)">
        <div class="fila-titulo"><h2 style="margin:0"><?= e($T['combate']) ?>: <?= e($pa ? duelo_nombre($pa) : 'por definir') ?> vs <?= e($pb ? duelo_nombre($pb) : 'por definir') ?></h2>
          <a class="btn btn-sec btn-chico" href="<?= e($url . '&s=resultados&cat=' . (int)$cat['id']) ?>">Cerrar</a></div>
        <p class="suave"><?= e($cat['nombre']) ?> · <?= e(duelo_instancia($cat, $sel, $combs)) ?><?= $fmt === 'escalera' && $pa && $pb ? ' · ' . e(duelo_nombre($pa)) . ' (puesto ' . (int)$pa['siembra'] . ') desafía a ' . e(duelo_nombre($pb)) . ' (puesto ' . (int)$pb['siembra'] . ')' : '' ?></p>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="resultado"><input type="hidden" name="combate_id" value="<?= (int)$sel['id'] ?>">
          <div class="campos">
            <div><label>Fecha</label><input type="date" name="fecha" value="<?= e($sel['fecha']) ?>"></div>
            <div><label>Hora</label><input type="time" name="hora" value="<?= e($sel['hora']) ?>"></div>
            <div><label><?= e($T['lugar']) ?></label><input type="text" name="lugar" value="<?= e($sel['lugar']) ?>" maxlength="40"></div>
          </div>
          <?php if ($pa && $pb): ?>
          <h3>Resultado</h3>
          <div class="campos">
            <?php if ($cfg['tipo'] === 'sets'): ?>
              <div class="campo-ancho"><label>Marcador (<?= $cfg['game_pts'] > 0 ? 'puntos' : 'games' ?> de <?= e(duelo_nombre($pa)) ?> primero)</label><input type="text" name="marcador" value="<?= e($sel['marcador']) ?>" placeholder="<?= e(duelo_ejemplo_marcador($t)) ?>" autocomplete="off">
                <div class="ayuda"><?= $cfg['game_pts'] > 0 ? 'Cada game separado por un espacio. Se juega a ' . $cfg['game_pts'] . ($cfg['game_dif'] === 1 ? ' con punto de oro.' : ' con 2 de diferencia (12-10, 15-13…).') : 'Cada set separado por un espacio. Tie-break entre paréntesis.' ?> Con «Sets» el ganador sale solo del marcador.</div></div>
            <?php elseif ($cfg['tipo'] === 'puntos' || $cfg['tipo'] === 'goles'): $u = $cfg['tipo'] === 'goles' ? 'Goles' : 'Puntos'; ?>
              <div><label><?= $u ?> de <?= e(duelo_nombre($pa)) ?></label><input type="text" name="pts_a" value="<?= $sel['pts_a'] !== null ? e(duelo_num((float)$sel['pts_a'])) : '' ?>" inputmode="decimal"></div>
              <div><label><?= $u ?> de <?= e(duelo_nombre($pb)) ?></label><input type="text" name="pts_b" value="<?= $sel['pts_b'] !== null ? e(duelo_num((float)$sel['pts_b'])) : '' ?>" inputmode="decimal"></div>
              <?php if ($cfg['tipo'] === 'goles'): ?><div><label>Penales (si hubo)</label><input type="text" name="marcador" value="<?= e($sel['metodo'] === 'Penales' ? $sel['marcador'] : '') ?>" maxlength="10" placeholder="Ej: 4-3"><div class="ayuda">Solo con «Penales».</div></div><?php endif; ?>
            <?php elseif ($cfg['tipo'] === 'rounds'): ?>
              <div><label>Terminó en el round</label><input type="number" name="round_fin" value="<?= (int)$sel['round_fin'] ?: '' ?>" min="1" max="<?= $cfg['rounds'] ?>" placeholder="Solo KO / TKO"></div>
              <div class="campo-ancho"><label>Tarjetas de los jueces (opcional)</label><input type="text" name="marcador" value="<?= e($sel['marcador']) ?>" maxlength="80" placeholder="Ej: 29-28 30-27 28-29"></div>
            <?php endif; ?>
            <div><label>Cómo ganó</label><select name="metodo"><?php foreach (duelo_metodos($t) as $mt): ?><option <?= $sel['metodo'] === $mt ? 'selected' : '' ?>><?= e($mt) ?></option><?php endforeach; ?></select></div>
            <div><label><?= $aj ? 'Resultado' : 'Ganador' ?></label><select name="ganador">
              <option value=""><?= $aj ? '— Elija —' : ($cfg['tipo'] === 'rounds' ? '— Elija —' : 'Según el marcador') ?></option>
              <option value="a" <?= $gan_sel === 'a' ? 'selected' : '' ?>><?= $aj ? '1-0 · gana ' : '' ?><?= e($nom_a) ?></option>
              <option value="b" <?= $gan_sel === 'b' ? 'selected' : '' ?>><?= $aj ? '0-1 · gana ' : '' ?><?= e($nom_b) ?></option>
              <?php if ($con_empate): ?><option value="empate" <?= $gan_sel === 'empate' ? 'selected' : '' ?>><?= $aj ? '½-½ · Tablas' : 'Empate' ?></option><?php endif; ?>
            </select></div>
            <div class="campo-ancho"><label>Nota</label><input type="text" name="nota" value="<?= e($sel['nota']) ?>" maxlength="120" placeholder="Ej: se retiró por lesión en el 2.º set"></div>
          </div>
          <?php else: ?><p class="aviso aviso-info">Todavía falta definir a uno de los rivales: puede guardar la fecha, la hora y el lugar.</p><?php endif; ?>
          <button class="btn">💾 Guardar</button>
        </form>
        <?php if ($sel['estado'] === 'jugado'): ?>
          <form method="post" style="margin-top:8px" onsubmit="return confirm('¿Borrar el resultado?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="resultado_borrar"><input type="hidden" name="combate_id" value="<?= (int)$sel['id'] ?>"><button class="btn btn-peligro btn-chico">Borrar resultado</button></form>
        <?php elseif ($fmt === 'escalera'): ?>
          <form method="post" style="margin-top:8px" onsubmit="return confirm('¿Borrar el desafío?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="desafio_borrar"><input type="hidden" name="combate_id" value="<?= (int)$sel['id'] ?>"><button class="btn btn-peligro btn-chico">Borrar desafío</button></form>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="tarjeta" style="margin-top:12px">
      <div class="fila-titulo"><h2 style="margin:0"><?= e($cat['nombre']) ?> <small class="suave"><?= e(duelo_formatos()[$cat['formato']] ?? $cat['formato']) ?><?= duelo_texto_categoria($cat) !== '' ? ' · ' . e(duelo_texto_categoria($cat)) : '' ?></small></h2></div>
      <?php $fmt = (string)$cat['formato'];
      $nc = (int)($n_por_cat[(int)$cat['id']] ?? 0);
      $armada = $combs || ($fmt === 'escalera' && (int)valor('SELECT COUNT(*) FROM duelo_participantes WHERE categoria_id = ? AND activo = 1 AND siembra > 0', [$cat['id']]) > 0);
      if (!$armada): ?>
        <p><?= $nc ?> inscrito<?= $nc === 1 ? '' : 's' ?> activos. <?= $nc < 2 ? 'Se necesitan al menos 2 para sortear.' : e(duelo_formato_ayuda($fmt)) ?></p>
        <?php if (duelo_es_llave($fmt)): ?><p class="ayuda">Al sortear, los cabezas de serie quedan separados, se reparten los pases directos y se evita que dos del mismo club se crucen al inicio.</p><?php endif; ?>
        <?php if ($nc >= 2): ?><form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="sortear"><input type="hidden" name="categoria_id" value="<?= (int)$cat['id'] ?>"><button class="btn">🎲 <?= e(match ($fmt) { 'llave', 'consolacion', 'monrad' => 'Sortear la llave', 'grupos' => 'Sortear los grupos', 'suizo' => 'Emparejar la ronda 1', 'escalera' => 'Armar la escalera', default => 'Sortear el todos contra todos' }) ?></button></form><?php endif; ?>
        <?php if ($nc < 2): ?><p><a class="btn btn-sec btn-chico" href="<?= e($url . '&s=participantes') ?>">Inscribir <?= e(mb_strtolower($T['participantes'])) ?></a></p><?php endif; ?>
      <?php else: ?>
        <?php if (duelo_es_llave($fmt)): ?><p class="ayuda">Toque un <?= e(mb_strtolower($T['combate'])) ?> para cargar su resultado o programarlo.<?= $fmt === 'grupos' ? ' Al terminar los grupos se arma sola la llave final.' : ' El ganador pasa solo a la ronda siguiente.' ?></p><?php endif; ?>
        <?php if ($fmt === 'escalera'):
            $esc = duelo_escalera($tid, (int)$cat['id']); ?>
          <form method="post" class="tarjeta" style="background:var(--fondo-suave,#f7f7f7);margin-bottom:12px"><?= csrf_campo() ?><input type="hidden" name="accion" value="desafio"><input type="hidden" name="categoria_id" value="<?= (int)$cat['id'] ?>">
            <h3 style="margin-top:0">⚔️ Nuevo desafío</h3>
            <div class="campos">
              <div><label>Retador (el de más abajo)</label><select name="retador" required><option value="">— Elija —</option><?php foreach ($esc as $p): if ((int)$p['siembra'] === 1) continue; ?><option value="<?= (int)$p['id'] ?>"><?= (int)$p['siembra'] ?>. <?= e(duelo_nombre($p)) ?></option><?php endforeach; ?></select></div>
              <div><label>Desafiado</label><select name="defensor" required><option value="">— Elija —</option><?php foreach ($esc as $p): ?><option value="<?= (int)$p['id'] ?>"><?= (int)$p['siembra'] ?>. <?= e(duelo_nombre($p)) ?></option><?php endforeach; ?></select></div>
              <div><label>Fecha</label><input type="date" name="fecha"></div>
              <div><label>Hora</label><input type="time" name="hora"></div>
            </div>
            <button class="btn btn-chico">Crear desafío</button>
          </form>
        <?php endif; ?>
        <?php if ($fmt === 'suizo'):
            $n_act = count(array_filter($part_cat, fn($p) => (int)$p['activo'] === 1));
            $r_max = $combs ? max(array_map(fn($c) => (int)$c['ronda'], $combs)) : 0;
            $pend = count(array_filter($combs, fn($c) => (int)$c['ronda'] === $r_max && $c['estado'] === 'pendiente'));
            $total_r = duelo_suizo_rondas($cat, $n_act); ?>
          <p>
            <?php if ($r_max < $total_r && !$pend): ?><form method="post" style="display:inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="suizo_ronda"><input type="hidden" name="categoria_id" value="<?= (int)$cat['id'] ?>"><button class="btn">🎲 Emparejar la ronda <?= $r_max + 1 ?> de <?= $total_r ?></button></form>
            <?php elseif ($pend): ?><span class="etiqueta">Faltan <?= $pend ?> resultado<?= $pend === 1 ? '' : 's' ?> de la ronda <?= $r_max ?></span>
            <?php else: ?><span class="etiqueta">✓ Se jugaron las <?= $total_r ?> rondas</span><?php endif; ?>
          </p>
        <?php endif; ?>
        <?= duelo_html_categoria($t, $cat, $part_cat, $enlace) ?>
        <?php if ($fmt === 'grupos' && duelo_grupos_terminados((int)$cat['id']) && !(int)valor("SELECT COUNT(*) FROM duelo_combates WHERE categoria_id = ? AND bloque = 0 AND estado = 'jugado'", [$cat['id']])): ?>
          <form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="grupos_llave"><input type="hidden" name="categoria_id" value="<?= (int)$cat['id'] ?>"><button class="btn btn-sec btn-chico">↻ Volver a armar la llave final</button></form>
        <?php endif; ?>
        <?php if ($fmt === 'suizo' && $combs): ?>
          <details style="margin-top:14px"><summary class="suave">Borrar la última ronda</summary>
            <form method="post" style="margin-top:8px" onsubmit="return confirm('¿Borrar la última ronda?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="suizo_borrar"><input type="hidden" name="categoria_id" value="<?= (int)$cat['id'] ?>">
              <p class="ayuda">Si ya tiene resultados, escriba <b>BORRAR</b>.</p><input type="text" name="confirmar" autocomplete="off" style="max-width:160px">
              <button class="btn btn-peligro btn-chico">Borrar ronda</button></form></details>
        <?php endif; ?>
        <details style="margin-top:14px"><summary class="suave">Borrar el sorteo de esta categoría</summary>
          <form method="post" style="margin-top:8px" onsubmit="return confirm('¿Borrar el sorteo?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="sorteo_borrar"><input type="hidden" name="categoria_id" value="<?= (int)$cat['id'] ?>">
            <?php if (duelo_categoria_con_resultados((int)$cat['id'])): ?><p class="ayuda">Tiene resultados: escriba <b>BORRAR</b> para borrarlos también.</p><input type="text" name="confirmar" autocomplete="off" style="max-width:160px"><?php endif; ?>
            <button class="btn btn-peligro btn-chico">Borrar sorteo</button></form></details>
      <?php endif; ?>
    </div>
    <?php endif;
endif;
pie_admin();
