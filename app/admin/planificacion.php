<?php
/*
 * (v17) ENTRENAMIENTOS: el profesor planifica sus clases por adelantado (calendario del mes), arma cada clase con
 * ejercicios de la biblioteca de la escuela y organiza el año en ciclos (ej: "Pase y control", 4 semanas).
 * Al tomar la asistencia, la clase planificada pasa a "dictada" y los padres la ven en el diario de entrenamientos.
 */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$por = usuario()['nombre'];
if (!entrenamientos_activos($liga) && !es_super()) throw new RuntimeException('El plan de la escuela no incluye la planificación de entrenamientos.');
preparar_ejercicios($lid);
$grupos = grupos_visibles($lid);
$gid = (int)get('g') ?: (int)($grupos[0]['id'] ?? 0);
$ver = in_array(get('ver'), ['calendario', 'ejercicios', 'ciclos'], true) ? get('ver') : 'calendario';
if (get('g') !== '' && $gid) grupo_de_escuela($gid, $lid); // (v21) valida antes de dibujar la página (un grupo ajeno muestra el aviso)
$mes = preg_match('/^\d{4}-\d{2}$/', get('mes')) ? get('mes') : date('Y-m');
$fecha = get('fecha') !== '' && strtotime(get('fecha')) ? date('Y-m-d', strtotime(get('fecha'))) : '';
$editar = puede('clases', true);
$volver = fn($g, $f = '', $m = '') => 'admin.php?p=planificacion&g=' . (int)$g . ($f ? '&fecha=' . $f : '') . ($m ? '&mes=' . $m : '');

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'plan') {
        $g = grupo_de_escuela((int)post('grupo_id'), $lid);
        $f = date('Y-m-d', strtotime(post('fecha') ?: 'today'));
        $ciclo = (int)post('ciclo_id') ? fila('SELECT id FROM esc_ciclos WHERE id = ? AND liga_id = ?', [(int)post('ciclo_id'), $lid]) : null;
        $pdo = db();
        $pdo->beginTransaction();
        $sid = guardar_plan_sesion($g, $f, [
            'objetivo' => mb_substr(post('objetivo'), 0, 200), 'contenido' => mb_substr(post('contenido'), 0, 2000), 'observaciones' => mb_substr(post('observaciones'), 0, 500),
            'duracion' => entero(post('duracion'), 0, 300), 'intensidad' => entero(post('intensidad'), 0, 10), 'visible_familia' => post('visible_familia') === '1' ? 1 : 0,
            'ciclo_id' => $ciclo ? (int)$ciclo['id'] : null,
        ], leer_ejercicios_post($lid), $por);
        // Repetir el mismo plan cada semana (mismo día) hasta una fecha
        $rep = 0;
        if (post('repetir_hasta') !== '' && strtotime(post('repetir_hasta'))) {
            $hasta = min(date('Y-m-d', strtotime(post('repetir_hasta'))), date('Y-m-d', strtotime($f . ' +16 weeks')));
            $ses = fila('SELECT * FROM esc_sesiones WHERE id = ?', [$sid]);
            for ($t = strtotime($f . ' +7 days'); date('Y-m-d', $t) <= $hasta; $t = strtotime('+7 days', $t)) {
                $d = date('Y-m-d', $t);
                $ya = fila('SELECT estado FROM esc_sesiones WHERE grupo_id = ? AND fecha = ?', [$g['id'], $d]);
                if ($ya && $ya['estado'] !== 'planificada') continue;
                copiar_plan_sesion($ses, $g, $d, $por);
                $rep++;
            }
        }
        $pdo->commit();
        flash('Plan de la clase guardado.' . ($rep ? ' Se repitió en ' . $rep . ' semana(s) más.' : ''));
        ir($volver($g['id'], $f));
    }
    if ($accion === 'copiar') {
        $ses = fila('SELECT s.* FROM esc_sesiones s JOIN esc_grupos g ON g.id = s.grupo_id WHERE s.id = ? AND g.liga_id = ?', [(int)post('sesion_id'), $lid]) ?? throw new RuntimeException('Clase no encontrada.');
        $n = 0;
        foreach ((array)($_POST['grupos'] ?? []) as $gd) {
            $g2 = grupo_de_escuela((int)$gd, $lid);
            $f2 = post('fecha_destino') !== '' && strtotime(post('fecha_destino')) ? date('Y-m-d', strtotime(post('fecha_destino'))) : $ses['fecha'];
            $ya = fila('SELECT estado FROM esc_sesiones WHERE grupo_id = ? AND fecha = ?', [$g2['id'], $f2]);
            if ($ya && $ya['estado'] !== 'planificada') continue;
            if ((int)$g2['id'] === (int)$ses['grupo_id'] && $f2 === $ses['fecha']) continue;
            copiar_plan_sesion($ses, $g2, $f2, $por);
            $n++;
        }
        flash($n ? 'Plan copiado a ' . $n . ' clase(s).' : 'No se copió: elija otro grupo u otra fecha (las clases ya dictadas no se tocan).', $n ? 'ok' : 'error');
        ir($volver($ses['grupo_id'], $ses['fecha']));
    }
    if ($accion === 'suspender' || $accion === 'reactivar' || $accion === 'borrar_plan') {
        $g = grupo_de_escuela((int)post('grupo_id'), $lid);
        $f = date('Y-m-d', strtotime(post('fecha')));
        $ses = fila('SELECT * FROM esc_sesiones WHERE grupo_id = ? AND fecha = ?', [$g['id'], $f]);
        if ($accion === 'suspender') {
            $motivo = mb_substr(post('motivo'), 0, 150) ?: 'Sin motivo';
            if ($ses && $ses['estado'] === 'dictada') throw new RuntimeException('Esa clase ya tiene asistencia: no se puede suspender.');
            if ($ses) q("UPDATE esc_sesiones SET estado = 'suspendida', motivo = ? WHERE id = ?", [$motivo, $ses['id']]);
            else q("INSERT INTO esc_sesiones (grupo_id, fecha, profesor_id, estado, motivo, registrado_por) VALUES (?,?,?, 'suspendida', ?, ?)", [$g['id'], $f, $g['profesor_id'] ?: null, $motivo, $por]);
            flash('Clase marcada como suspendida (' . $motivo . '). No cuenta para la asistencia ni para el pago del profesor.');
        } elseif ($accion === 'reactivar' && $ses) {
            q("UPDATE esc_sesiones SET estado = 'planificada', motivo = '' WHERE id = ? AND estado = 'suspendida'", [$ses['id']]);
            flash('La clase vuelve a estar planificada.');
        } elseif ($accion === 'borrar_plan' && $ses) {
            if ($ses['estado'] === 'dictada') throw new RuntimeException('Esa clase ya tiene asistencia: no se puede borrar desde aquí.');
            q('DELETE FROM esc_sesiones WHERE id = ?', [$ses['id']]);
            flash('Plan borrado.');
        }
        ir($volver($g['id'], $accion === 'borrar_plan' ? '' : $f, substr($f, 0, 7)));
    }
    if ($accion === 'ejercicio') {
        $id = (int)post('id');
        if ($id) fila('SELECT id FROM esc_ejercicios WHERE id = ? AND liga_id = ?', [$id, $lid]) ?? throw new RuntimeException('Ejercicio no encontrado.');
        $tipo = array_key_exists(post('tipo'), tipos_ejercicio()) ? post('tipo') : 'tecnico';
        if (trim(post('nombre')) === '') throw new RuntimeException('Escriba el nombre del ejercicio.');
        $video = trim(post('video'));
        if ($video !== '' && !preg_match('#^https?://#i', $video)) throw new RuntimeException('El enlace del video debe empezar con https://');
        $img = subir_imagen('imagen', 'ejercicios', 1200);
        $dep = array_key_exists(post('deporte'), familias_opciones()) ? post('deporte') : '';
        $d = [mb_substr(trim(post('nombre')), 0, 120), $tipo, mb_substr(post('objetivo'), 0, 200), mb_substr(post('descripcion'), 0, 2000), entero(post('duracion'), 0, 240),
              mb_substr(post('jugadores'), 0, 80), mb_substr(post('materiales'), 0, 200), mb_substr(post('edades'), 0, 60), mb_substr($video, 0, 300), $dep];
        if ($id) {
            q('UPDATE esc_ejercicios SET nombre = ?, tipo = ?, objetivo = ?, descripcion = ?, duracion = ?, jugadores = ?, materiales = ?, edades = ?, video = ?, deporte = ?' . ($img ? ', imagen = ?' : '') . ' WHERE id = ?', array_merge($d, $img ? [$img] : [], [$id]));
        } else {
            q('INSERT INTO esc_ejercicios (liga_id, nombre, tipo, objetivo, descripcion, duracion, jugadores, materiales, edades, video, deporte, imagen, registrado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge([$lid], $d, [$img ?? '', $por]));
        }
        flash('Ejercicio guardado en la biblioteca.');
        ir('admin.php?p=planificacion&ver=ejercicios');
    }
    if ($accion === 'archivar_ejercicio') {
        q('UPDATE esc_ejercicios SET activo = 1 - activo WHERE id = ? AND liga_id = ?', [(int)post('id'), $lid]);
        flash('Listo.');
        ir('admin.php?p=planificacion&ver=ejercicios');
    }
    if ($accion === 'ciclo') {
        $id = (int)post('id');
        if ($id) fila('SELECT id FROM esc_ciclos WHERE id = ? AND liga_id = ?', [$id, $lid]) ?? throw new RuntimeException('Ciclo no encontrado.');
        $g = (int)post('grupo_id') ? grupo_de_escuela((int)post('grupo_id'), $lid) : null;
        if (!$g && perfil_actual() === 'profesor') throw new RuntimeException('Elija uno de sus grupos.');
        if (trim(post('nombre')) === '' || !strtotime(post('desde')) || !strtotime(post('hasta')) || post('hasta') < post('desde')) throw new RuntimeException('Revise el nombre y las fechas del ciclo.');
        $d = [$g ? (int)$g['id'] : null, mb_substr(trim(post('nombre')), 0, 100), date('Y-m-d', strtotime(post('desde'))), date('Y-m-d', strtotime(post('hasta'))), mb_substr(post('objetivo'), 0, 300), mb_substr(post('contenidos'), 0, 1500)];
        if ($id) q('UPDATE esc_ciclos SET grupo_id = ?, nombre = ?, desde = ?, hasta = ?, objetivo = ?, contenidos = ? WHERE id = ?', array_merge($d, [$id]));
        else q('INSERT INTO esc_ciclos (liga_id, grupo_id, nombre, desde, hasta, objetivo, contenidos, registrado_por) VALUES (?,?,?,?,?,?,?,?)', array_merge([$lid], $d, [$por]));
        flash('Ciclo guardado.');
        ir('admin.php?p=planificacion&ver=ciclos');
    }
    if ($accion === 'borrar_ciclo') {
        q('UPDATE esc_sesiones SET ciclo_id = NULL WHERE ciclo_id = ?', [(int)post('id')]);
        q('DELETE FROM esc_ciclos WHERE id = ? AND liga_id = ?', [(int)post('id'), $lid]);
        flash('Ciclo borrado.');
        ir('admin.php?p=planificacion&ver=ciclos');
    }
}

cabecera_admin('Entrenamientos');
?>
<h1>📅 Entrenamientos</h1>
<nav class="pestanas">
  <a class="<?= $ver === 'calendario' ? 'activa' : '' ?>" href="admin.php?p=planificacion&amp;g=<?= $gid ?>">🗓 Planificación</a>
  <a class="<?= $ver === 'ejercicios' ? 'activa' : '' ?>" href="admin.php?p=planificacion&amp;ver=ejercicios">📚 Biblioteca de ejercicios</a>
  <a class="<?= $ver === 'ciclos' ? 'activa' : '' ?>" href="admin.php?p=planificacion&amp;ver=ciclos">🎯 Ciclos de trabajo</a>
</nav>
<?php
/* ------------------------------------------------------------ BIBLIOTECA */
if ($ver === 'ejercicios'):
    $tipo_f = array_key_exists(get('tipo'), tipos_ejercicio()) ? get('tipo') : '';
    $archivados = get('archivados') === '1';
    // (v21) Filtro por deporte: solo se muestra si la escuela tiene grupos de varios deportes
    $fams_esc = familias_de(deportes_de_escuela($liga));
    $dep_f = in_array(get('deporte'), $fams_esc, true) ? get('deporte') : '';
    $lista = filas('SELECT e.*, (SELECT COUNT(*) FROM esc_sesion_ejercicios se WHERE se.ejercicio_id = e.id) AS usos FROM esc_ejercicios e WHERE e.liga_id = ? AND e.activo = ?' . ($tipo_f ? ' AND e.tipo = ?' : '') . ($dep_f ? ' AND ' . sql_deporte([$dep_f], 'e.deporte') : '') . ' ORDER BY e.tipo, e.nombre', $tipo_f ? [$lid, $archivados ? 0 : 1, $tipo_f] : [$lid, $archivados ? 0 : 1]);
    $tipos_b = tipos_ejercicio(count($fams_esc) === 1 ? $fams_esc[0] : ($dep_f ?: ''));
    $ed = get('id') !== '' ? fila('SELECT * FROM esc_ejercicios WHERE id = ? AND liga_id = ?', [(int)get('id'), $lid]) : null; ?>
  <p class="suave">Los ejercicios de la escuela. El profesor los elige al planificar cada clase y los padres ven en el diario qué ejercicios hizo su hijo.</p>
  <form method="get" class="acciones"><input type="hidden" name="p" value="planificacion"><input type="hidden" name="ver" value="ejercicios">
    <?php if (count($fams_esc) > 1): ?><select name="deporte" onchange="this.form.submit()"><option value="">Todos los deportes</option><?php foreach ($fams_esc as $f): ?><option value="<?= e($f) ?>" <?= $dep_f === $f ? 'selected' : '' ?>><?= e(texto_familia($f)) ?></option><?php endforeach; ?></select><?php endif; ?>
    <select name="tipo" onchange="this.form.submit()"><option value="">Todos los tipos</option><?php foreach ($tipos_b as $k => [$ic, $t]): ?><option value="<?= $k ?>" <?= $tipo_f === $k ? 'selected' : '' ?>><?= $ic ?> <?= e($t) ?></option><?php endforeach; ?></select>
    <label class="check"><input type="checkbox" name="archivados" value="1" <?= $archivados ? 'checked' : '' ?> onchange="this.form.submit()"> Ver archivados</label></form>
  <?php if ($editar): ?>
  <details class="tarjeta" <?= $ed || get('nuevo') ? 'open' : '' ?>><summary><b><?= $ed ? '✏️ Editar: ' . e($ed['nombre']) : '+ Nuevo ejercicio' ?></b></summary>
    <form method="post" enctype="multipart/form-data" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="ejercicio"><input type="hidden" name="id" value="<?= (int)($ed['id'] ?? 0) ?>">
      <div class="campos">
        <div><label>Nombre</label><input type="text" name="nombre" required maxlength="120" value="<?= e($ed['nombre'] ?? '') ?>"></div>
        <div><label>Deporte</label><select name="deporte"><?php foreach (familias_opciones() as $k => $t): ?><option value="<?= e($k) ?>" <?= ($ed['deporte'] ?? ($dep_f ?: (count($fams_esc) === 1 ? $fams_esc[0] : ''))) === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div><label>Tipo</label><select name="tipo"><?php foreach ($tipos_b as $k => [$ic, $t]): ?><option value="<?= $k ?>" <?= ($ed['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= $ic ?> <?= e($t) ?></option><?php endforeach; ?></select></div>
        <div><label>Minutos</label><input type="number" name="duracion" min="0" max="240" value="<?= (int)($ed['duracion'] ?? 10) ?>"></div>
        <div class="campo-ancho"><label>Objetivo</label><input type="text" name="objetivo" maxlength="200" value="<?= e($ed['objetivo'] ?? '') ?>" placeholder="Ej: pase y control orientado bajo presión"></div>
        <div class="campo-ancho"><label>Cómo se hace</label><textarea name="descripcion" rows="3" maxlength="2000"><?= e($ed['descripcion'] ?? '') ?></textarea></div>
        <div><label>Jugadores</label><input type="text" name="jugadores" maxlength="80" value="<?= e($ed['jugadores'] ?? '') ?>" placeholder="Ej: grupos de 5"></div>
        <div><label>Materiales</label><input type="text" name="materiales" maxlength="200" value="<?= e($ed['materiales'] ?? '') ?>"></div>
        <div><label>Edades</label><input type="text" name="edades" maxlength="60" value="<?= e($ed['edades'] ?? '') ?>" placeholder="Ej: Sub-8 en adelante"></div>
        <div><label>Video (enlace de YouTube, opcional)</label><input type="url" name="video" maxlength="300" value="<?= e($ed['video'] ?? '') ?>" placeholder="https://"></div>
        <div><label>Gráfico o foto (opcional)</label><input type="file" name="imagen" accept="image/*"></div>
      </div>
      <button class="btn">💾 Guardar ejercicio</button> <?php if ($ed): ?><a class="btn btn-sec" href="admin.php?p=planificacion&amp;ver=ejercicios">Cancelar</a><?php endif; ?>
    </form>
  </details>
  <?php endif; ?>
  <?php foreach ($tipos_b as $k => [$ic, $tn]): $de = array_filter($lista, fn($x) => $x['tipo'] === $k); if (!$de) continue; ?>
    <h2><?= $ic ?> <?= e($tn) ?> <small class="suave">(<?= count($de) ?>)</small></h2>
    <div class="rejilla"><?php foreach ($de as $x): ?>
      <div class="ejercicio">
        <?php if ($x['imagen']): ?><img src="<?= e($x['imagen']) ?>" alt="" style="width:100%;border-radius:8px;margin-bottom:6px"><?php endif; ?>
        <h4><?= e($x['nombre']) ?><?= count($fams_esc) > 1 && $x['deporte'] !== '' ? ' <small class="suave">' . e(texto_familia($x['deporte'])) . '</small>' : '' ?></h4>
        <small class="suave">⏱ <?= (int)$x['duracion'] ?> min<?= $x['jugadores'] ? ' · 👥 ' . e($x['jugadores']) : '' ?><?= $x['edades'] ? ' · ' . e($x['edades']) : '' ?> · usado <?= (int)$x['usos'] ?> vez/veces</small>
        <?php if ($x['objetivo']): ?><p><b>Objetivo:</b> <?= e($x['objetivo']) ?></p><?php endif; ?>
        <?php if ($x['descripcion']): ?><p><?= e($x['descripcion']) ?></p><?php endif; ?>
        <?php if ($x['materiales']): ?><p class="suave">🧰 <?= e($x['materiales']) ?></p><?php endif; ?>
        <div class="acciones"><?php if ($x['video']): ?><a class="btn btn-sec btn-chico" href="<?= e($x['video']) ?>" target="_blank" rel="noopener">▶ Video</a><?php endif; ?>
          <?php if ($editar): ?><a class="btn btn-sec btn-chico" href="admin.php?p=planificacion&amp;ver=ejercicios&amp;id=<?= (int)$x['id'] ?>">✏️ Editar</a>
          <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="archivar_ejercicio"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-chico"><?= $archivados ? '↩ Reactivar' : '🗄 Archivar' ?></button></form><?php endif; ?></div>
      </div>
    <?php endforeach; ?></div>
  <?php endforeach; ?>
  <?php if (!$lista): ?><div class="tarjeta">No hay ejercicios<?= $archivados ? ' archivados' : '' ?>.</div><?php endif; ?>
<?php
/* ------------------------------------------------------------ CICLOS */
elseif ($ver === 'ciclos'):
    $ids_g = array_map(fn($g) => (int)$g['id'], $grupos);
    $ciclos = filas('SELECT c.*, g.nombre AS grupo, (SELECT COUNT(*) FROM esc_sesiones s WHERE s.ciclo_id = c.id) AS clases FROM esc_ciclos c LEFT JOIN esc_grupos g ON g.id = c.grupo_id WHERE c.liga_id = ? ORDER BY c.desde DESC', [$lid]);
    if (perfil_actual() === 'profesor') $ciclos = array_values(array_filter($ciclos, fn($c) => !$c['grupo_id'] || in_array((int)$c['grupo_id'], $ids_g, true)));
    $ed = get('id') !== '' ? fila('SELECT * FROM esc_ciclos WHERE id = ? AND liga_id = ?', [(int)get('id'), $lid]) : null; ?>
  <p class="suave">Un ciclo es un bloque de semanas con un tema (ej: "Pase y control", "Juego de posición", "Pretemporada"). Así se ve que el trabajo tiene un plan y una secuencia, no clases sueltas.</p>
  <?php if ($editar): ?>
  <details class="tarjeta" <?= $ed ? 'open' : '' ?>><summary><b><?= $ed ? '✏️ Editar ciclo' : '+ Nuevo ciclo' ?></b></summary>
    <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="ciclo"><input type="hidden" name="id" value="<?= (int)($ed['id'] ?? 0) ?>">
      <div class="campos">
        <div><label>Nombre</label><input type="text" name="nombre" required maxlength="100" value="<?= e($ed['nombre'] ?? '') ?>" placeholder="Ej: Pase y control"></div>
        <div><label>Grupo</label><select name="grupo_id"><?= perfil_actual() === 'profesor' ? '' : '<option value="">Toda la escuela</option>' ?><?= opciones_grupos($grupos, $ed['grupo_id'] ?? null, false) ?></select></div>
        <div><label>Desde</label><input type="date" name="desde" required value="<?= e($ed['desde'] ?? date('Y-m-d')) ?>"></div>
        <div><label>Hasta</label><input type="date" name="hasta" required value="<?= e($ed['hasta'] ?? date('Y-m-d', strtotime('+4 weeks'))) ?>"></div>
        <div class="campo-ancho"><label>Objetivo</label><input type="text" name="objetivo" maxlength="300" value="<?= e($ed['objetivo'] ?? '') ?>"></div>
        <div class="campo-ancho"><label>Contenidos</label><textarea name="contenidos" rows="3" maxlength="1500"><?= e($ed['contenidos'] ?? '') ?></textarea></div>
      </div>
      <button class="btn">💾 Guardar ciclo</button></form>
  </details>
  <?php endif; ?>
  <?php if (!$ciclos): ?><div class="tarjeta">Todavía no hay ciclos.</div><?php endif; ?>
  <div class="rejilla-2"><?php foreach ($ciclos as $c): $act = $c['desde'] <= date('Y-m-d') && $c['hasta'] >= date('Y-m-d');
      $tot = max(1, (strtotime($c['hasta']) - strtotime($c['desde'])) / 86400); $av = (int)round(100 * max(0, min($tot, (time() - strtotime($c['desde'])) / 86400)) / $tot); ?>
    <div class="tarjeta"<?= $act ? ' style="border:2px solid var(--acento)"' : '' ?>>
      <h3 style="margin-top:0">🎯 <?= e($c['nombre']) ?> <?= $act ? '<span class="estado-chip chip-ok">En curso</span>' : ($c['hasta'] < date('Y-m-d') ? '<span class="estado-chip">Terminado</span>' : '<span class="estado-chip chip-pend">Próximo</span>') ?></h3>
      <p class="suave" style="margin:.2rem 0"><?= e($c['grupo'] ?? 'Toda la escuela') ?> · <?= e(fecha_corta($c['desde'])) ?> al <?= e(fecha_corta($c['hasta'])) ?> · <?= (int)$c['clases'] ?> clase(s)</p>
      <p style="margin:.3rem 0"><?= barra_avance($av) ?> <small><?= $av ?>%</small></p>
      <?php if ($c['objetivo']): ?><p><b>Objetivo:</b> <?= e($c['objetivo']) ?></p><?php endif; ?>
      <?php if ($c['contenidos']): ?><p class="suave" style="white-space:pre-wrap"><?= e($c['contenidos']) ?></p><?php endif; ?>
      <?php if ($editar): ?><div class="acciones"><a class="btn btn-sec btn-chico" href="admin.php?p=planificacion&amp;ver=ciclos&amp;id=<?= (int)$c['id'] ?>">✏️ Editar</a>
        <form method="post" onsubmit="return confirm('¿Borrar el ciclo? Las clases no se borran.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_ciclo"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sec btn-chico">🗑 Borrar</button></form></div><?php endif; ?>
    </div>
  <?php endforeach; ?></div>
<?php
/* ------------------------------------------------------------ PLAN DE UNA CLASE */
elseif ($fecha !== '' && $gid):
    $g = grupo_de_escuela($gid, $lid);
    $ses = fila('SELECT * FROM esc_sesiones WHERE grupo_id = ? AND fecha = ?', [$gid, $fecha]);
    $ejs = $ses ? ejercicios_sesion((int)$ses['id']) : [];
    $ciclos = filas('SELECT * FROM esc_ciclos WHERE liga_id = ? AND (grupo_id = ? OR grupo_id IS NULL) ORDER BY desde DESC LIMIT 30', [$lid, $gid]);
    $cv = ciclo_vigente($lid, $gid, $fecha);
    $estado = $ses['estado'] ?? 'nueva'; ?>
  <p><a href="<?= e($volver($gid, '', substr($fecha, 0, 7))) ?>">← Volver al calendario de <?= e(nombre_mes(substr($fecha, 0, 7))) ?></a></p>
  <div class="tarjeta">
    <h2 style="margin-top:0"><?= e($g['nombre']) ?> · <?= e(fecha_bonita($fecha)) ?> <?= $ses ? chip_sesion($ses['estado']) : '<span class="estado-chip">Sin plan</span>' ?></h2>
    <p class="suave" style="margin-top:0"><?= e(texto_horario($g)) ?><?= $ses && $ses['estado'] === 'suspendida' ? ' · Motivo: ' . e($ses['motivo']) : '' ?></p>
    <?php if ($estado === 'dictada'):
        $asis = fila("SELECT COUNT(*) AS t, SUM(CASE WHEN estado IN ('P','T') THEN 1 ELSE 0 END) AS v FROM esc_asistencia WHERE sesion_id = ?", [$ses['id']]); ?>
      <div class="aviso aviso-info">✓ Esta clase ya se dictó: vinieron <b><?= (int)$asis['v'] ?> de <?= (int)$asis['t'] ?></b>. Para corregir algo use <a href="admin.php?p=asistencia&amp;g=<?= $gid ?>&amp;fecha=<?= e($fecha) ?>">Asistencia</a>.</div>
      <p><b>Objetivo:</b> <?= e($ses['objetivo'] ?: '—') ?> · <?= minutos_clase(array_merge($ses, ['hora_inicio' => $g['hora_inicio'], 'hora_fin' => $g['hora_fin']])) ?> min<?= (int)$ses['rpe'] ? ' · esfuerzo percibido ' . (int)$ses['rpe'] . '/10' : '' ?></p>
      <?php if ($ejs): ?><ol><?php foreach ($ejs as $x): ?><li><?= e((tipos_ejercicio()[$x['tipo'] ?? '']['0'] ?? '') . ' ' . $x['nombre']) ?> <small class="suave"><?= e(fases_clase()[$x['fase']] ?? '') ?><?= $x['minutos'] ? ' · ' . (int)$x['minutos'] . ' min' : '' ?><?= $x['notas'] ? ' · ' . e($x['notas']) : '' ?></small></li><?php endforeach; ?></ol><?php endif; ?>
    <?php elseif ($editar): ?>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="plan"><input type="hidden" name="grupo_id" value="<?= $gid ?>"><input type="hidden" name="fecha" value="<?= e($fecha) ?>">
        <div class="campos">
          <div class="campo-ancho"><label>Objetivo de la clase</label><input type="text" name="objetivo" maxlength="200" value="<?= e($ses['objetivo'] ?? '') ?>" placeholder="Ej: pase corto y control orientado"></div>
          <div><label>Ciclo</label><select name="ciclo_id"><option value="">— ninguno —</option><?php foreach ($ciclos as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($ses['ciclo_id'] ?? ($cv['id'] ?? 0)) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?> (<?= e(fecha_corta($c['desde'])) ?>)</option><?php endforeach; ?></select></div>
          <div><label>Duración (min)</label><input type="number" name="duracion" min="0" max="300" value="<?= (int)($ses['duracion'] ?? 0) ?: duracion_grupo($g) ?>"></div>
          <div><label>Intensidad (1 a 10)</label><input type="number" name="intensidad" min="0" max="10" value="<?= (int)($ses['intensidad'] ?? 0) ?: '' ?>" placeholder="Ej: 6"></div>
        </div>
        <label>Ejercicios de la clase</label>
        <?= html_editor_ejercicios($lid, $ejs, deporte_grupo($g)) ?>
        <div class="campos" style="margin-top:10px">
          <div class="campo-ancho"><label>Indicaciones (opcional)</label><textarea name="contenido" rows="2" maxlength="2000"><?= e($ses['contenido'] ?? '') ?></textarea></div>
          <div class="campo-ancho"><label>Notas internas (no las ven los padres)</label><input type="text" name="observaciones" maxlength="500" value="<?= e($ses['observaciones'] ?? '') ?>"></div>
          <div><label>Repetir cada semana hasta (opcional)</label><input type="date" name="repetir_hasta" min="<?= e(date('Y-m-d', strtotime($fecha . ' +7 days'))) ?>"></div>
          <div><label class="check" style="margin-top:26px"><input type="checkbox" name="visible_familia" value="1" <?= !$ses || (int)$ses['visible_familia'] ? 'checked' : '' ?>> Los padres ven el plan</label></div>
        </div>
        <div class="acciones"><button class="btn btn-grande">💾 Guardar plan</button>
          <?php if ($fecha <= date('Y-m-d')): ?><a class="btn btn-sec" href="admin.php?p=asistencia&amp;g=<?= $gid ?>&amp;fecha=<?= e($fecha) ?>">✅ Tomar asistencia</a><?php endif; ?></div>
      </form>
    <?php else: ?><p>No tiene permiso para cambiar el plan.</p><?php endif; ?>
  </div>
  <?php if ($editar && $estado !== 'dictada'): ?>
  <div class="rejilla-2">
    <?php if ($ses && $estado === 'planificada'): ?>
    <div class="tarjeta"><h3 style="margin-top:0">📋 Copiar este plan</h3>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="copiar"><input type="hidden" name="sesion_id" value="<?= (int)$ses['id'] ?>">
        <label>A los grupos</label><?php foreach ($grupos as $g2): ?><label class="check" style="font-weight:400"><input type="checkbox" name="grupos[]" value="<?= (int)$g2['id'] ?>" <?= (int)$g2['id'] === $gid ? '' : '' ?>> <?= e($g2['nombre']) ?></label><?php endforeach; ?>
        <p><label>Fecha (vacío = la misma)</label><input type="date" name="fecha_destino"></p>
        <button class="btn btn-sec">Copiar</button></form></div>
    <?php endif; ?>
    <div class="tarjeta"><h3 style="margin-top:0"><?= $estado === 'suspendida' ? '↩ Reactivar la clase' : '✗ Suspender la clase' ?></h3>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="grupo_id" value="<?= $gid ?>"><input type="hidden" name="fecha" value="<?= e($fecha) ?>">
        <?php if ($estado === 'suspendida'): ?><input type="hidden" name="accion" value="reactivar"><button class="btn btn-sec">Reactivar</button>
        <?php else: ?><input type="hidden" name="accion" value="suspender"><p><label>Motivo</label><input type="text" name="motivo" maxlength="150" placeholder="Ej: lluvia, feriado, cancha ocupada"></p><button class="btn btn-sec">Suspender</button><?php endif; ?></form>
      <?php if ($ses): ?><form method="post" style="margin-top:10px" onsubmit="return confirm('¿Borrar el plan de esta clase?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_plan"><input type="hidden" name="grupo_id" value="<?= $gid ?>"><input type="hidden" name="fecha" value="<?= e($fecha) ?>"><button class="btn btn-sec btn-chico">🗑 Borrar plan</button></form><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
<?php
/* ------------------------------------------------------------ CALENDARIO DEL MES */
else:
    if (!$grupos): ?><div class="tarjeta">No tiene grupos asignados.</div><?php pie_admin(); return; endif;
    $g = grupo_de_escuela($gid, $lid);
    $ini = $mes . '-01';
    $fin = date('Y-m-t', strtotime($ini));
    $ses_mes = [];
    foreach (filas('SELECT s.*, (SELECT COUNT(*) FROM esc_sesion_ejercicios se WHERE se.sesion_id = s.id) AS n_ej, (SELECT COALESCE(SUM(minutos), 0) FROM esc_sesion_ejercicios se WHERE se.sesion_id = s.id) AS min_ej,
                    (SELECT COUNT(*) FROM esc_asistencia x WHERE x.sesion_id = s.id AND x.estado IN (\'P\',\'T\')) AS vinieron FROM esc_sesiones s WHERE s.grupo_id = ? AND s.fecha BETWEEN ? AND ?', [$gid, $ini, $fin]) as $s) $ses_mes[$s['fecha']] = $s;
    $fechas = fechas_entrenamiento($g, $ini, $fin);
    $plan = count(array_filter($ses_mes, fn($s) => $s['estado'] === 'planificada'));
    $dict = count(array_filter($ses_mes, fn($s) => $s['estado'] === 'dictada'));
    $susp = count(array_filter($ses_mes, fn($s) => $s['estado'] === 'suspendida'));
    $sin = count(array_filter($fechas, fn($f) => !isset($ses_mes[$f])));
    // Distribución del tiempo por tipo de ejercicio en el mes
    $dist = [];
    foreach (filas("SELECT COALESCE(e.tipo, 'otro') AS tipo, SUM(se.minutos) AS m FROM esc_sesion_ejercicios se JOIN esc_sesiones s ON s.id = se.sesion_id LEFT JOIN esc_ejercicios e ON e.id = se.ejercicio_id
                    WHERE s.grupo_id = ? AND s.fecha BETWEEN ? AND ? AND s.estado <> 'suspendida' GROUP BY 1", [$gid, $ini, $fin]) as $x) $dist[$x['tipo']] = (int)$x['m'];
    $colores = ['calentamiento' => '#e0a100', 'tecnico' => '#0b6e4f', 'tactico' => '#2563eb', 'fisico' => '#d7263d', 'juego' => '#7c3aed', 'arquero' => '#0891b2', 'calma' => '#8a9591', 'otro' => '#b0b8b4'];
    $ant = date('Y-m', strtotime($ini . ' -1 month')); $sig = date('Y-m', strtotime($ini . ' +1 month')); ?>
  <form method="get" class="acciones" style="margin-bottom:10px"><input type="hidden" name="p" value="planificacion"><input type="hidden" name="mes" value="<?= e($mes) ?>">
    <select name="g" onchange="this.form.submit()"><?= opciones_grupos($grupos, $gid, false) ?></select>
    <a class="btn btn-sec btn-chico" href="<?= e($volver($gid, '', $ant)) ?>">◀</a><b style="min-width:150px;text-align:center"><?= e(nombre_mes($mes)) ?></b><a class="btn btn-sec btn-chico" href="<?= e($volver($gid, '', $sig)) ?>">▶</a>
    <?php if ($mes !== date('Y-m')): ?><a class="btn btn-sec btn-chico" href="<?= e($volver($gid)) ?>">Hoy</a><?php endif; ?></form>
  <div class="cifras">
    <div class="cifra"><b><?= count($fechas) ?></b>Clases del mes (según horario)</div>
    <div class="cifra"><b><?= $dict ?></b>Dictadas</div>
    <div class="cifra"><b><?= $plan ?></b>Planificadas por dictar</div>
    <div class="cifra"><b style="<?= $sin ? 'color:var(--roja)' : '' ?>"><?= $sin ?></b>Sin plan todavía</div>
    <?php if ($susp): ?><div class="cifra"><b><?= $susp ?></b>Suspendidas</div><?php endif; ?>
  </div>
  <?php $cv = ciclo_vigente($lid, $gid, max($ini, min($fin, date('Y-m-d')))); if ($cv): ?><p><span class="cal-ciclo">🎯 Ciclo: <?= e($cv['nombre']) ?></span> <small class="suave"><?= e(fecha_corta($cv['desde'])) ?> al <?= e(fecha_corta($cv['hasta'])) ?> · <?= e($cv['objetivo']) ?></small></p><?php endif; ?>
  <div class="calendario">
    <?php foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $d): ?><div class="cal-cab"><?= $d ?></div><?php endforeach; ?>
    <?php $t0 = strtotime('monday this week', strtotime($ini)); $t1 = strtotime('sunday this week', strtotime($fin));
    for ($t = $t0; $t <= $t1; $t = strtotime('+1 day', $t)): $f = date('Y-m-d', $t); $en = substr($f, 0, 7) === $mes; $s = $ses_mes[$f] ?? null; $toca = in_array($f, $fechas, true); ?>
      <div class="cal-dia<?= $en ? '' : ' fuera' ?><?= $f === date('Y-m-d') ? ' hoy' : '' ?><?= !$s && !$toca ? ' sin-clase' : '' ?>">
        <span class="cal-num"><?= (int)date('j', $t) ?> <small class="suave" style="font-weight:400"><?= e(dia_corto($f)) ?></small></span>
        <?php if ($en && $s): ?>
          <a class="cal-ses <?= e($s['estado']) ?>" href="<?= e($volver($gid, $f)) ?>"><b><?= e(mb_substr($s['objetivo'] ?: ($s['estado'] === 'suspendida' ? $s['motivo'] : 'Clase'), 0, 40)) ?></b><br>
            <small><?= $s['estado'] === 'dictada' ? '✓ ' . (int)$s['vinieron'] . ' vinieron' : ($s['estado'] === 'planificada' ? '📝 ' . (int)$s['n_ej'] . ' ejerc. · ' . (int)$s['min_ej'] . ' min' : '✗ suspendida') ?></small></a>
        <?php elseif ($en && $toca && $editar): ?>
          <a class="cal-ses vacia" href="<?= e($volver($gid, $f)) ?>">+ Planificar</a>
        <?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>
  <div class="rejilla-2" style="margin-top:16px">
    <div class="tarjeta"><h3 style="margin-top:0">⏱ En qué se usó el tiempo este mes</h3>
      <?php if ($dist): arsort($dist); $tot = array_sum($dist); ?>
        <?= svg_barras(array_map(fn($k, $v) => [['calentamiento' => 'Calent.', 'tecnico' => 'Técnico', 'tactico' => 'Táctico', 'fisico' => 'Físico', 'juego' => 'Juego', 'arquero' => 'Arqueros', 'calma' => 'Calma'][$k] ?? 'Otro', $v, round(100 * $v / max(1, $tot)) . '%', $colores[$k] ?? '#999'], array_keys($dist), $dist)) ?>
        <p class="suave" style="font-size:.85rem;margin:0">Total planificado: <?= fmt_num($tot / 60, 1) ?> horas en ejercicios.</p>
      <?php else: ?><p class="suave">Cuando las clases tengan ejercicios, aquí verá cuánto tiempo fue técnico, táctico, físico o juego.</p><?php endif; ?></div>
    <div class="tarjeta"><h3 style="margin-top:0">📊 Asistencia de las clases del mes</h3>
      <?php $dd = array_values(array_filter($ses_mes, fn($s) => $s['estado'] === 'dictada')); usort($dd, fn($a, $b) => strcmp($a['fecha'], $b['fecha'])); $na = max(1, count(alumnos_grupo($gid))); ?>
      <?= svg_barras(array_map(fn($s) => [date('d', strtotime($s['fecha'])), round(100 * (int)$s['vinieron'] / $na), (int)$s['vinieron'] . '', (int)$s['vinieron'] / $na >= .8 ? '#1a9e5c' : ((int)$s['vinieron'] / $na >= .6 ? '#e0a100' : '#d7263d')], $dd), ['max' => 100, 'vacio' => 'Todavía no hay clases dictadas este mes.']) ?>
      <p class="suave" style="font-size:.85rem;margin:0">Número de alumnos que vinieron a cada clase (de <?= $na ?>).</p></div>
  </div>
<?php endif;
pie_admin();
