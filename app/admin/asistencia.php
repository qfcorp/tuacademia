<?php
/* (v11) ASISTENCIA: el profesor marca quién vino, si él asistió y el plan de clase (qué se entrenó)
 * (v17) + ejercicios de la clase, duración, intensidad (RPE), nota para cada alumno, fotos y si lo ven los padres.
 *       Una clase planificada pasa a "dictada" al guardar la asistencia. */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$por = usuario()['nombre'];
$grupos = grupos_visibles($lid);
$gid = (int)get('g') ?: (int)($grupos[0]['id'] ?? 0);
if (get('g') !== '' && $gid) grupo_de_escuela($gid, $lid); // (v21) valida antes de dibujar la página
$fecha = get('fecha') !== '' && strtotime(get('fecha')) ? date('Y-m-d', strtotime(get('fecha'))) : date('Y-m-d');
$con_plan = entrenamientos_activos($liga);

if (es_post() && post('accion') === 'guardar') {
    $g = grupo_de_escuela((int)post('grupo_id'), $lid);
    $f = post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : date('Y-m-d');
    if ($f > date('Y-m-d')) throw new RuntimeException('No se puede tomar asistencia de un día que todavía no llega. Para preparar la clase use Entrenamientos.');
    $prof = (int)post('profesor_id') ?: ($g['profesor_id'] ? (int)$g['profesor_id'] : null);
    $fotos = subir_fotos_privadas('fotos'); // antes de la transacción (si una foto falla no se pierde nada)
    $pdo = db();
    $pdo->beginTransaction();
    q("INSERT INTO esc_sesiones (grupo_id, fecha, profesor_id, profesor_asistio, objetivo, contenido, observaciones, duracion, intensidad, rpe, visible_familia, estado, motivo, registrado_por)
       VALUES (?,?,?,?,?,?,?,?,?,?,?, 'dictada', '', ?)
       ON CONFLICT(grupo_id, fecha) DO UPDATE SET profesor_id = excluded.profesor_id, profesor_asistio = excluded.profesor_asistio, objetivo = excluded.objetivo,
       contenido = excluded.contenido, observaciones = excluded.observaciones, duracion = excluded.duracion, intensidad = excluded.intensidad, rpe = excluded.rpe,
       visible_familia = excluded.visible_familia, estado = 'dictada', motivo = '', registrado_por = excluded.registrado_por",
        [$g['id'], $f, $prof, post('profesor_asistio') === '1' ? 1 : 0, mb_substr(post('objetivo'), 0, 200), mb_substr(post('contenido'), 0, 2000), mb_substr(post('observaciones'), 0, 500),
         entero(post('duracion'), 0, 300), entero(post('intensidad'), 0, 10), entero(post('rpe'), 0, 10), post('visible_familia') === '1' ? 1 : 0, $por]);
    $sid = (int)valor('SELECT id FROM esc_sesiones WHERE grupo_id = ? AND fecha = ?', [$g['id'], $f]);
    if ($con_plan && isset($_POST['ej_id'])) {
        q('DELETE FROM esc_sesion_ejercicios WHERE sesion_id = ?', [$sid]);
        foreach (leer_ejercicios_post($lid) as $i => [$eid, $nom, $fase, $min, $notas]) {
            q('INSERT INTO esc_sesion_ejercicios (sesion_id, ejercicio_id, nombre, fase, minutos, notas, orden) VALUES (?,?,?,?,?,?,?)', [$sid, $eid, $nom, $fase, $min, $notas, $i]);
        }
    }
    $n = 0;
    foreach ((array)($_POST['est'] ?? []) as $aid => $est) {
        if (!array_key_exists((string)$est, estados_asistencia())) continue;
        if (!fila('SELECT id FROM esc_alumnos WHERE id = ? AND liga_id = ?', [(int)$aid, $lid])) continue;
        q('INSERT INTO esc_asistencia (sesion_id, alumno_id, estado) VALUES (?,?,?) ON CONFLICT(sesion_id, alumno_id) DO UPDATE SET estado = excluded.estado', [$sid, (int)$aid, $est]);
        $n++;
        // Nota del profesor para este alumno (la ven los padres en el diario si así se marca)
        $txt = mb_substr(trim((string)($_POST['nota'][$aid] ?? '')), 0, 300);
        $tipo = (string)($_POST['nota_tipo'][$aid] ?? 'nota');
        if (!array_key_exists($tipo, tipos_nota_clase())) $tipo = 'nota';
        if ($txt === '') {
            q('DELETE FROM esc_notas_clase WHERE sesion_id = ? AND alumno_id = ?', [$sid, (int)$aid]);
        } else {
            q('INSERT INTO esc_notas_clase (sesion_id, alumno_id, tipo, texto, visible_familia, registrado_por) VALUES (?,?,?,?,?,?)
               ON CONFLICT(sesion_id, alumno_id) DO UPDATE SET tipo = excluded.tipo, texto = excluded.texto, visible_familia = excluded.visible_familia, registrado_por = excluded.registrado_por',
                [$sid, (int)$aid, $tipo, $txt, isset($_POST['nota_privada'][$aid]) ? 0 : 1, $por]);
        }
    }
    foreach ($fotos as $ruta) q('INSERT INTO esc_sesion_fotos (sesion_id, archivo, descripcion, publica, subido_por) VALUES (?,?,?,?,?)', [$sid, $ruta, mb_substr(post('foto_desc'), 0, 150), post('fotos_publicas') === '1' ? 1 : 0, $por]);
    $pdo->commit();
    $vinieron = (int)valor("SELECT COUNT(*) FROM esc_asistencia WHERE sesion_id = ? AND estado IN ('P','T')", [$sid]);
    flash('Asistencia guardada: vinieron ' . $vinieron . ' de ' . $n . '.' . ($fotos ? ' Se subieron ' . count($fotos) . ' foto(s).' : ''));
    ir('admin.php?p=asistencia&g=' . (int)$g['id'] . '&fecha=' . $f);
}
if (es_post() && post('accion') === 'borrar_foto') {
    $ft = fila('SELECT f.*, s.grupo_id, s.fecha FROM esc_sesion_fotos f JOIN esc_sesiones s ON s.id = f.sesion_id WHERE f.id = ?', [(int)post('foto_id')]) ?? throw new RuntimeException('Foto no encontrada.');
    grupo_de_escuela((int)$ft['grupo_id'], $lid);
    q('DELETE FROM esc_sesion_fotos WHERE id = ?', [$ft['id']]);
    if (!str_contains($ft['archivo'], '..')) @unlink(RAIZ . '/data/' . $ft['archivo']);
    flash('Foto borrada.');
    ir('admin.php?p=asistencia&g=' . (int)$ft['grupo_id'] . '&fecha=' . $ft['fecha']);
}

cabecera_admin('Asistencia');
?>
<h1>📋 Asistencia y clases</h1>
<?php if (!$grupos): ?><div class="tarjeta">No tiene grupos asignados. <?= puede('alumnos', true) ? '<a href="admin.php?p=grupos">Cree los grupos</a>.' : '' ?></div><?php pie_admin(); return; endif; ?>
<form method="get" class="acciones" style="margin-bottom:10px"><input type="hidden" name="p" value="asistencia">
  <select name="g" onchange="this.form.submit()"><?= opciones_grupos($grupos, $gid, false) ?></select>
  <input type="date" name="fecha" value="<?= e($fecha) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()">
  <?php if ($con_plan): ?><a class="btn btn-sec btn-chico" href="admin.php?p=planificacion&amp;g=<?= $gid ?>&amp;mes=<?= e(substr($fecha, 0, 7)) ?>">📅 Planificación del mes</a><?php endif; ?></form>
<?php
$g = grupo_de_escuela($gid, $lid);
$ses = fila('SELECT * FROM esc_sesiones WHERE grupo_id = ? AND fecha = ?', [$gid, $fecha]);
$tomada = $ses && $ses['estado'] === 'dictada';
$marcas = [];
if ($ses) foreach (filas('SELECT * FROM esc_asistencia WHERE sesion_id = ?', [$ses['id']]) as $x) $marcas[(int)$x['alumno_id']] = $x['estado'];
$notas = [];
if ($ses) foreach (filas('SELECT * FROM esc_notas_clase WHERE sesion_id = ?', [$ses['id']]) as $x) $notas[(int)$x['alumno_id']] = $x;
$fotos = $ses ? filas('SELECT * FROM esc_sesion_fotos WHERE sesion_id = ? ORDER BY id', [$ses['id']]) : [];
$ejs = $ses ? ejercicios_sesion((int)$ses['id']) : [];
$alumnos = alumnos_grupo($gid);
$toca = in_array((string)date('N', strtotime($fecha)), explode(',', (string)$g['dias']), true);
$profes = filas('SELECT id, nombre FROM esc_profesores WHERE liga_id = ? AND activo = 1 ORDER BY nombre', [$lid]);
$lesion = [];
foreach (filas("SELECT alumno_id FROM esc_lesiones WHERE alta = '' AND alumno_id IN (SELECT a.id FROM esc_alumnos a WHERE " . sql_en_grupo('a', $gid) . ")") as $l) $lesion[(int)$l['alumno_id']] = true;
$ciclo = $con_plan ? ciclo_vigente($lid, $gid, $fecha) : null;
?>
<div class="tarjeta">
  <h2 style="margin-top:0"><?= e($g['nombre']) ?> · <?= e(fecha_bonita($fecha)) ?> <?= $ses ? chip_sesion($ses['estado']) : '' ?></h2>
  <p class="suave" style="margin-top:0"><?= e(texto_horario($g)) ?><?= !$toca ? ' · ⚠ este día no es de entrenamiento habitual' : '' ?><?= $tomada ? ' · ✓ ya se tomó (puede corregir)' : '' ?></p>
  <?php if ($ses && $ses['estado'] === 'suspendida'): ?><div class="aviso aviso-error">Esta clase se marcó como <b>suspendida</b><?= $ses['motivo'] !== '' ? ' (' . e($ses['motivo']) . ')' : '' ?>. Si sí se dictó, tome la asistencia y se corrige sola.</div><?php endif; ?>
  <?php if ($ciclo): ?><p style="margin:.2rem 0"><span class="cal-ciclo">🎯 Ciclo: <?= e($ciclo['nombre']) ?></span> <small class="suave"><?= e($ciclo['objetivo']) ?></small></p><?php endif; ?>
  <?php if ($ses && $ses['estado'] === 'planificada'): ?>
    <div class="aviso aviso-info">📝 <b>Clase planificada:</b> <?= e($ses['objetivo'] ?: 'sin objetivo') ?><?= $ejs ? ' · ' . count($ejs) . ' ejercicios, ' . array_sum(array_column($ejs, 'minutos')) . ' min' : '' ?>. Tome la asistencia y guarde: la clase pasa a <b>dictada</b>.</div>
  <?php endif; ?>
  <?php if (!$alumnos): ?><p>Este grupo no tiene alumnos activos.</p><?php else: ?>
  <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="grupo_id" value="<?= $gid ?>"><input type="hidden" name="fecha" value="<?= e($fecha) ?>">
    <div class="acciones" style="margin-bottom:8px"><button type="button" class="btn btn-sec btn-chico" onclick="document.querySelectorAll('.asis-P').forEach(function (r) { r.checked = true; })">Todos presentes</button>
      <span class="suave" style="font-size:.85rem">📝 = nota para ese alumno (la ven sus padres)</span></div>
    <?php foreach ($alumnos as $a): $aid = (int)$a['id']; $m = $marcas[$aid] ?? ($tomada ? 'A' : 'P'); $nt = $notas[$aid] ?? null; ?>
      <div class="asis-fila">
        <span class="eq-celda"><?= foto_alumno($a) ?> <?= e(nombre_alumno($a)) ?><?= isset($lesion[$aid]) ? ' <span class="etiqueta roja" title="Lesionado">🩹</span>' : '' ?><?= $a['alergias'] || $a['condiciones'] ? ' <span class="etiqueta amarilla" title="' . e(trim($a['alergias'] . ' ' . $a['condiciones'])) . '">🩺</span>' : '' ?></span>
        <span class="asis-botones"><?php foreach (estados_asistencia() as $k => $txt): ?><label class="asis-b asis-b-<?= $k ?>"><input type="radio" class="asis-<?= $k ?>" name="est[<?= $aid ?>]" value="<?= $k ?>" <?= $m === $k ? 'checked' : '' ?>><b title="<?= e($txt) ?>"><?= $k ?></b></label><?php endforeach; ?>
          <button type="button" class="btn-nota<?= $nt ? ' con-nota' : '' ?>" title="Nota para este alumno" onclick="this.closest('.asis-fila').querySelector('.nota-alumno').classList.toggle('abierta')">📝</button></span>
        <div class="nota-alumno<?= $nt ? ' abierta' : '' ?>">
          <select name="nota_tipo[<?= $aid ?>]"><?php foreach (tipos_nota_clase() as $k => [$ic, $tn]): ?><option value="<?= $k ?>" <?= ($nt['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= $ic ?> <?= e($tn) ?></option><?php endforeach; ?></select>
          <input type="text" name="nota[<?= $aid ?>]" maxlength="300" value="<?= e($nt['texto'] ?? '') ?>" placeholder="Ej: excelente control con la izquierda; practicar el pase largo en casa">
          <label class="check" style="font-weight:400"><input type="checkbox" name="nota_privada[<?= $aid ?>]" value="1" <?= $nt && !(int)$nt['visible_familia'] ? 'checked' : '' ?>> Solo para la escuela</label>
        </div>
      </div>
    <?php endforeach; ?>
    <p class="suave" style="font-size:.85rem">P presente · T tarde · J justificó · A faltó</p>

    <h3>🧑‍🏫 Profesor de la clase</h3>
    <div class="campos">
      <div><label>Profesor</label><select name="profesor_id"><?php foreach ($profes as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)($ses['profesor_id'] ?? $g['profesor_id']) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
      <div><label class="check" style="margin-top:26px"><input type="checkbox" name="profesor_asistio" value="1" <?= !$ses || $ses['profesor_asistio'] ? 'checked' : '' ?>> El profesor dio la clase</label></div>
    </div>

    <h3>📝 Qué se entrenó</h3>
    <div class="campos">
      <div class="campo-ancho"><label>Objetivo de la clase</label><input type="text" name="objetivo" maxlength="200" value="<?= e($ses['objetivo'] ?? '') ?>" placeholder="Ej: pase corto y control orientado"></div>
    </div>
    <?php if ($con_plan): ?>
      <label>Ejercicios</label>
      <?= html_editor_ejercicios($lid, $ejs, deporte_grupo($g)) ?>
    <?php endif; ?>
    <div class="campos" style="margin-top:10px">
      <div class="campo-ancho"><label><?= $con_plan ? 'Detalle o comentario de la clase (opcional)' : 'Qué se entrenó (calentamiento, ejercicios, juego)' ?></label><textarea name="contenido" rows="3" maxlength="2000"><?= e($ses['contenido'] ?? '') ?></textarea></div>
      <div><label>Duración (minutos)</label><input type="number" name="duracion" min="0" max="300" value="<?= (int)($ses['duracion'] ?? 0) ?: duracion_grupo($g) ?>"></div>
      <div><label>Intensidad planificada (1 a 10)</label><input type="number" name="intensidad" min="0" max="10" value="<?= (int)($ses['intensidad'] ?? 0) ?: '' ?>" placeholder="Ej: 6"></div>
      <div class="campo-ancho"><label>¿Qué tan dura fue la clase para el grupo? (esfuerzo percibido, 1 = muy suave, 10 = máximo)</label>
        <div class="rpe"><?php for ($i = 1; $i <= 10; $i++): ?><label><input type="radio" name="rpe" value="<?= $i ?>" <?= (int)($ses['rpe'] ?? 0) === $i ? 'checked' : '' ?>><b><?= $i ?></b></label><?php endfor; ?></div></div>
      <div class="campo-ancho"><label>Observaciones (solo para la escuela)</label><input type="text" name="observaciones" maxlength="500" value="<?= e($ses['observaciones'] ?? '') ?>"></div>
    </div>

    <h3>📷 Fotos de la clase</h3>
    <?php if ($fotos): ?><div class="fotos-clase" style="margin-bottom:8px"><?php foreach ($fotos as $ft): ?><a href="<?= e(url_foto_clase((int)$ft['id'])) ?>" target="_blank" title="<?= $ft['publica'] ? 'La ven los padres' : 'Solo la escuela' ?>"><img src="<?= e(url_foto_clase((int)$ft['id'])) ?>" alt=""></a><?php endforeach; ?></div><?php endif; ?>
    <div class="campos">
      <div><label>Subir fotos (puede elegir varias)</label><input type="file" name="fotos[]" accept="image/*" multiple></div>
      <div><label>Descripción (opcional)</label><input type="text" name="foto_desc" maxlength="150"></div>
      <div><label class="check" style="margin-top:26px"><input type="checkbox" name="fotos_publicas" value="1" checked> Los padres pueden ver las fotos</label></div>
    </div>
    <p><label class="check"><input type="checkbox" name="visible_familia" value="1" <?= !$ses || (int)$ses['visible_familia'] ? 'checked' : '' ?>> Mostrar esta clase a los padres en el <b>diario de entrenamientos</b></label></p>
    <button class="btn btn-grande">💾 Guardar asistencia</button>
  </form>
  <?php if ($fotos): ?><details style="margin-top:10px"><summary class="suave">Borrar fotos</summary><div class="acciones"><?php foreach ($fotos as $ft): ?>
    <form method="post" onsubmit="return confirm('¿Borrar esta foto?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_foto"><input type="hidden" name="foto_id" value="<?= (int)$ft['id'] ?>"><button class="btn btn-sec btn-chico">🗑 Foto <?= (int)$ft['id'] ?></button></form><?php endforeach; ?></div></details><?php endif; ?>
  <?php endif; ?>
</div>
<?php
// Historial del mes: cuadrícula alumnos × días (solo clases dictadas)
$mes = substr($fecha, 0, 7);
$sesiones = filas("SELECT * FROM esc_sesiones WHERE grupo_id = ? AND substr(fecha, 1, 7) = ? AND estado = 'dictada' ORDER BY fecha", [$gid, $mes]);
if ($sesiones):
    $m2 = [];
    foreach (filas('SELECT x.* FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id WHERE s.grupo_id = ? AND substr(s.fecha, 1, 7) = ?', [$gid, $mes]) as $x) $m2[$x['alumno_id']][$x['sesion_id']] = $x['estado'];
    $col = ['P' => 's-verde', 'T' => 's-amarillo', 'J' => 's-gris', 'A' => 's-rojo']; ?>
<h2>Historial de <?= e(nombre_mes($mes)) ?></h2>
<div class="tabla-scroll"><table class="tabla-asis">
  <tr><th>Alumno</th><?php foreach ($sesiones as $se): ?><th title="<?= e($se['objetivo']) ?>"><a href="admin.php?p=asistencia&amp;g=<?= $gid ?>&amp;fecha=<?= e($se['fecha']) ?>"><?= e(date('d', strtotime($se['fecha']))) ?></a><?= $se['profesor_asistio'] ? '' : '<br><small title="El profesor no dio la clase">✗</small>' ?></th><?php endforeach; ?><th class="num">%</th></tr>
  <?php foreach ($alumnos as $a): $t = 0; $v = 0; ?><tr><td><?= e(nombre_alumno($a)) ?></td>
    <?php foreach ($sesiones as $se): $e = $m2[$a['id']][$se['id']] ?? ''; if ($e !== '') { $t++; if ($e === 'P' || $e === 'T') $v++; } ?><td><?= $e !== '' ? '<span class="semaforo ' . $col[$e] . '">' . $e . '</span>' : '' ?></td><?php endforeach; ?>
    <td class="num"><?= $t ? round(100 * $v / $t) . '%' : '—' ?></td></tr><?php endforeach; ?>
</table></div>
<?php endif;
pie_admin();
