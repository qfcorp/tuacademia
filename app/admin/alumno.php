<?php
/* (v11) FICHA DEL ALUMNO: datos, salud y lesiones, pagos, asistencia, medidas, tests, evaluación técnica, libreta, carnet y portal de la familia */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$aid = (int)get('id');
$a = alumno_de_escuela($aid, $lid);
if (perfil_actual() === 'profesor') { // (v19) el profesor ve al alumno si está en alguno de sus grupos (principal o de otro deporte)
    $ve = false;
    foreach (ids_grupos_alumno($a) as $gx) { try { grupo_de_escuela($gx, $lid); $ve = true; break; } catch (RuntimeException) {} }
    if (!$ve) throw new RuntimeException('Ese alumno no es de sus grupos.');
}
$s = get('s', 'datos');
$por = usuario()['nombre'];
$url = 'admin.php?p=alumno&id=' . $aid;
// (v35) requisitos del módulo deportivo del grupo principal del alumno
$mod_req = modulo_de_deporte_escuela(deporte_grupo($a['grupo_id'] ? fila('SELECT * FROM esc_grupos WHERE id = ?', [$a['grupo_id']]) : null, $liga));
$fecha_post = fn() => post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : date('Y-m-d');
$num = fn(string $k) => post($k) === '' ? null : round((float)str_replace(',', '.', post($k)), 2);

if (es_post()) {
    $accion = post('accion');
    $puede_alumnos = puede('alumnos', true);
    $puede_eval = puede('evaluaciones', true);
    if ($accion === 'datos' && $puede_alumnos) {
        $grupos = grupos_visibles($lid);
        $d = leer_alumno_post($lid);
        if ($f = subir_imagen('foto', 'jugadores', 700)) { borrar_imagen($a['foto']); $d['foto'] = $f; }
        q('UPDATE esc_alumnos SET ' . implode(', ', array_map(fn($k) => "$k = :$k", array_keys($d))) . ' WHERE id = :id', $d + ['id' => $aid]);
        if (!empty($d['grupo_id'])) q('DELETE FROM esc_alumno_grupos WHERE alumno_id = ? AND grupo_id = ?', [$aid, $d['grupo_id']]); // (v19)
        guardar_requisitos($lid, $mod_req, 'esc_alumnos', $aid); // (v35)
        flash('Datos guardados.');
    } elseif ($accion === 'grupo_extra' && $puede_alumnos) {
        // (v19) Otro deporte / otro grupo además del principal (paga también la mensualidad de ese grupo)
        $gx = grupo_de_escuela((int)post('grupo_id'), $lid);
        if ((int)$gx['id'] === (int)$a['grupo_id']) throw new RuntimeException('Ese ya es su grupo principal.');
        q('INSERT OR IGNORE INTO esc_alumno_grupos (alumno_id, grupo_id, desde) VALUES (?,?,?)', [$aid, $gx['id'], date('Y-m-d')]);
        preparar_deporte($lid, deporte_grupo($gx, $liga));
        flash(nombre_alumno($a) . ' también entrena en ' . $gx['nombre'] . ' (' . nombre_deporte_corto(deporte_grupo($gx, $liga)) . '). Desde el próximo mes se genera también la mensualidad de ese grupo.');
    } elseif ($accion === 'quitar_grupo_extra' && $puede_alumnos) {
        q('DELETE FROM esc_alumno_grupos WHERE alumno_id = ? AND grupo_id = ?', [$aid, (int)post('grupo_id')]);
        flash('Listo: ya no está en ese grupo.');
    } elseif ($accion === 'estado' && $puede_alumnos) {
        $nuevo = post('estado') === 'retirado' ? 'retirado' : 'activo';
        q('UPDATE esc_alumnos SET estado = ? WHERE id = ?', [$nuevo, $aid]);
        if ($nuevo === 'retirado') q("UPDATE esc_cargos SET estado = 'anulado' WHERE alumno_id = ? AND estado = 'pendiente' AND tipo = 'mensualidad' AND vence > ?", [$aid, date('Y-m-d')]);
        flash($nuevo === 'retirado' ? 'Alumno retirado (ya no se le generan mensualidades).' : 'Alumno activo otra vez.');
    } elseif ($accion === 'carnet' && $puede_alumnos) {
        q("UPDATE esc_alumnos SET carnet_token = '' WHERE id = ?", [$aid]);
        bitacora_evento('escuela', 'Anuló el carnet QR de ' . nombre_alumno($a), $lid, $aid);
        flash('Carnet anulado: imprima uno nuevo.');
    } elseif ($accion === 'familia' && $puede_alumnos) {
        if ($a['representante_cedula'] === '') throw new RuntimeException('Primero escriba la cédula del representante.');
        $_SESSION['msg_familia'] = codigo_familia($lid, $a['representante_cedula']);
        bitacora_evento('familia', 'Generó el acceso al portal de la familia de ' . nombre_alumno($a), $lid, $aid);
        ir($url . '&s=datos#familia');
    } elseif ($accion === 'lesion' && ($puede_alumnos || $puede_eval)) {
        $dias = entero(post('dias_baja'), 0, 365);
        q("INSERT INTO esc_lesiones (alumno_id, fecha, zona, tipo, descripcion, dias_baja, diagnostico, fase, retorno_estimado, profesional, registrado_por) VALUES (?,?,?,?,?,?,?, 'aguda', ?,?,?)",
            [$aid, $fecha_post(), mb_substr(post('zona'), 0, 60), mb_substr(post('tipo'), 0, 60), mb_substr(post('descripcion'), 0, 300), $dias, mb_substr(post('diagnostico'), 0, 200),
             $dias ? date('Y-m-d', strtotime($fecha_post() . " +$dias days")) : '', mb_substr(post('profesional'), 0, 100), $por]);
        flash('Lesión registrada.');
    } elseif ($accion === 'alta' && ($puede_alumnos || $puede_eval)) {
        q("UPDATE esc_lesiones SET alta = ?, fase = 'alta' WHERE id = ? AND alumno_id = ?", [date('Y-m-d'), (int)post('lesion_id'), $aid]);
        flash('Alta médica registrada.');
    } elseif ($accion === 'lesion_seg') {
        // (v17) Seguimiento de la lesión por fases: reposo → tratamiento → readaptación → vuelta al grupo → alta
        $l = fila('SELECT * FROM esc_lesiones WHERE id = ? AND alumno_id = ?', [(int)post('lesion_id'), $aid]) ?? throw new RuntimeException('Lesión no encontrada.');
        $fase = array_key_exists(post('fase'), fases_lesion()) ? post('fase') : $l['fase'];
        q('INSERT INTO esc_lesion_seguimiento (lesion_id, fecha, fase, texto, registrado_por) VALUES (?,?,?,?,?)', [$l['id'], $fecha_post(), $fase, mb_substr(post('texto'), 0, 400), $por]);
        q('UPDATE esc_lesiones SET fase = ?, retorno_estimado = COALESCE(NULLIF(?, \'\'), retorno_estimado), alta = ? WHERE id = ?',
            [$fase, post('retorno_estimado') !== '' && strtotime(post('retorno_estimado')) ? date('Y-m-d', strtotime(post('retorno_estimado'))) : '', $fase === 'alta' ? ($l['alta'] ?: $fecha_post()) : '', $l['id']]);
        flash('Seguimiento guardado' . ($fase === 'alta' ? ': alta médica.' : '.'));
    } elseif ($accion === 'meta' && $puede_eval) {
        if (trim(post('texto')) === '') throw new RuntimeException('Escriba la meta.');
        q('INSERT INTO esc_metas (alumno_id, area, texto, fecha_objetivo, visible_familia, registrado_por) VALUES (?,?,?,?,?,?)',
            [$aid, array_key_exists(post('area'), areas_meta()) ? post('area') : 'tecnica', mb_substr(trim(post('texto')), 0, 200),
             post('fecha_objetivo') !== '' && strtotime(post('fecha_objetivo')) ? date('Y-m-d', strtotime(post('fecha_objetivo'))) : '', post('visible_familia') === '1' ? 1 : 0, $por]);
        flash('Meta agregada.');
    } elseif ($accion === 'meta_avance' && $puede_eval) {
        $m = fila('SELECT * FROM esc_metas WHERE id = ? AND alumno_id = ?', [(int)post('meta_id'), $aid]) ?? throw new RuntimeException('Meta no encontrada.');
        if (post('borrar') === '1') {
            q('DELETE FROM esc_metas WHERE id = ?', [$m['id']]);
            flash('Meta borrada.');
        } else {
            $av = entero(post('avance'), 0, 100);
            $est = array_key_exists(post('estado'), estados_meta()) ? post('estado') : 'en_curso';
            if ($av === 100 && $est === 'en_curso') $est = 'lograda';
            if ($est === 'lograda') $av = 100;
            q('UPDATE esc_metas SET avance = ?, estado = ?, comentario = ?, actualizado = ? WHERE id = ?', [$av, $est, mb_substr(post('comentario'), 0, 300), date('Y-m-d H:i:s'), $m['id']]);
            flash($est === 'lograda' ? '🎖 ¡Meta lograda! Aparece como insignia en el portal de los padres.' : 'Avance guardado.');
        }
    } elseif ($accion === 'padres_talla') {
        $t = fn($k) => post($k) === '' ? null : max(120, min(230, round((float)str_replace(',', '.', post($k)), 1)));
        q('UPDATE esc_alumnos SET talla_padre = ?, talla_madre = ? WHERE id = ?', [$t('talla_padre'), $t('talla_madre'), $aid]);
        flash('Estatura de los padres guardada.');
    } elseif ($accion === 'staff_nota' && formativas_activas($liga)) {
        if (trim(post('texto')) === '') throw new RuntimeException('Escriba la nota.');
        $area = array_key_exists(post('area'), areas_staff()) ? post('area') : 'entrenador';
        q('INSERT INTO esc_staff_notas (alumno_id, area, fecha, texto, privada, registrado_por) VALUES (?,?,?,?,?,?)', [$aid, $area, $fecha_post(), mb_substr(trim(post('texto')), 0, 1500), post('privada') === '1' ? 1 : 0, $por]);
        flash('Nota del cuerpo técnico guardada.');
    } elseif ($accion === 'borrar_staff_nota' && formativas_activas($liga)) {
        q('DELETE FROM esc_staff_notas WHERE id = ? AND alumno_id = ? AND (registrado_por = ? OR ?)', [(int)post('nota_id'), $aid, $por, in_array(perfil_actual(), ['admin', 'super'], true) ? 1 : 0]);
        flash('Nota borrada.');
    } elseif ($accion === 'valoracion' && formativas_activas($liga)) {
        q('INSERT INTO esc_valoraciones (alumno_id, fecha, posicion, posicion2, perfil, fortalezas, mejorar, potencial, recomendacion, registrado_por) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$aid, $fecha_post(), mb_substr(post('posicion'), 0, 40), mb_substr(post('posicion2'), 0, 40), mb_substr(post('perfil'), 0, 200), mb_substr(post('fortalezas'), 0, 600), mb_substr(post('mejorar'), 0, 600),
             array_key_exists(post('potencial'), potenciales()) ? post('potencial') : 'B', array_key_exists(post('recomendacion'), recomendaciones_valoracion()) ? post('recomendacion') : 'mantener', $por]);
        if (post('posicion') !== '') q('UPDATE esc_alumnos SET posicion = ? WHERE id = ?', [mb_substr(post('posicion'), 0, 40), $aid]);
        flash('Valoración guardada.');
    } elseif ($accion === 'academico' && formativas_activas($liga)) {
        if (trim(post('periodo')) === '') throw new RuntimeException('Escriba el periodo (ej: 1er quimestre 2026).');
        $n = fn($k, $max) => post($k) === '' ? null : max(0, min($max, round((float)str_replace(',', '.', post($k)), 2)));
        q('INSERT INTO esc_academico (alumno_id, periodo, promedio, asistencia, conducta, materias_bajas, observacion, registrado_por) VALUES (?,?,?,?,?,?,?,?)',
            [$aid, mb_substr(trim(post('periodo')), 0, 60), $n('promedio', 10), $n('asistencia', 100), mb_substr(post('conducta'), 0, 20), mb_substr(post('materias_bajas'), 0, 200), mb_substr(post('observacion'), 0, 400), $por]);
        flash('Rendimiento escolar guardado.');
    } elseif ($accion === 'medida' && $puede_eval) {
        $vals = [];
        foreach (array_keys(campos_medicion()) as $k) $vals[$k] = $num($k);
        $vals['talla_sentado'] = $num('talla_sentado'); // (v17) para calcular la maduración
        if (!array_filter($vals, fn($x) => $x !== null)) throw new RuntimeException('Escriba al menos una medida.');
        q('INSERT INTO esc_mediciones (alumno_id, fecha, ' . implode(', ', array_keys($vals)) . ', nota, registrado_por) VALUES (?, ?, ' . implode(', ', array_fill(0, count($vals), '?')) . ', ?, ?)',
            array_merge([$aid, $fecha_post()], array_values($vals), [mb_substr(post('nota'), 0, 200), $por]));
        flash('Medidas guardadas.');
    } elseif ($accion === 'borrar_medida' && $puede_eval) {
        q('DELETE FROM esc_mediciones WHERE id = ? AND alumno_id = ?', [(int)post('mid'), $aid]);
    } elseif ($accion === 'test' && $puede_eval) {
        $t = fila('SELECT * FROM esc_tests WHERE id = ? AND liga_id = ?', [(int)post('test_id'), $lid]) ?? throw new RuntimeException('Elija el test.');
        if (post('valor') === '') throw new RuntimeException('Escriba el resultado.');
        q('INSERT INTO esc_resultados (alumno_id, test_id, fecha, valor, nota, registrado_por) VALUES (?,?,?,?,?,?) ON CONFLICT(alumno_id, test_id, fecha) DO UPDATE SET valor = excluded.valor, nota = excluded.nota',
            [$aid, $t['id'], $fecha_post(), (float)str_replace(',', '.', post('valor')), mb_substr(post('nota'), 0, 200), $por]);
        flash('Resultado guardado.');
    } elseif ($accion === 'tecnica' && $puede_eval) {
        $vals = [];
        foreach ((array)($_POST['h'] ?? []) as $hid => $val) if ((int)$val >= 1 && (int)$val <= 5) $vals[(int)$hid] = (int)$val;
        if (!$vals) throw new RuntimeException('Califique al menos una habilidad.');
        q('INSERT INTO esc_eval_tecnica (alumno_id, fecha, valores, comentario, registrado_por) VALUES (?,?,?,?,?)', [$aid, $fecha_post(), json_encode($vals), mb_substr(post('comentario'), 0, 500), $por]);
        flash('Evaluación técnica guardada.');
    } elseif ($accion === 'libreta' && $puede_eval) {
        $per = preg_match('/^\d{4}-T[1-4]$/', post('periodo')) ? post('periodo') : periodo_libreta();
        q('INSERT INTO esc_libretas (alumno_id, periodo, comentario, registrado_por) VALUES (?,?,?,?) ON CONFLICT(alumno_id, periodo) DO UPDATE SET comentario = excluded.comentario, registrado_por = excluded.registrado_por, actualizado = CURRENT_TIMESTAMP',
            [$aid, $per, mb_substr(post('comentario'), 0, 1500), $por]);
        flash('Comentario de la libreta guardado.');
    } elseif ($accion === 'cargo' && puede('cobros', true)) {
        crear_cargo_escuela($liga, $aid, post('tipo'), post('descripcion') ?: tipos_cargo_escuela()[post('tipo')] ?? 'Cargo', round((float)str_replace(',', '.', post('monto')), 2), post('vence'), $por);
        flash('Cargo agregado.');
    } elseif ($accion === 'cobrar' && puede('cobros', true)) {
        $r = cobrar_cargos($liga, (array)($_POST['cargos'] ?? []), post('forma_pago'), $por);
        flash('Pago registrado. Recibo N° ' . str_pad((string)$r, 6, '0', STR_PAD_LEFT) . '.');
        ir('admin.php?p=esc_recibo&n=' . $r);
    } elseif ($accion === 'anular_cargo' && puede('cobros', true)) {
        q("UPDATE esc_cargos SET estado = 'anulado' WHERE id = ? AND alumno_id = ? AND estado = 'pendiente'", [(int)post('cargo_id'), $aid]);
        flash('Cargo anulado.');
    } else {
        throw new RuntimeException('Su perfil no puede hacer ese cambio.');
    }
    ir($url . '&s=' . $s);
}

/* LIBRETA DEL ALUMNO (46) — documento imprimible del trimestre */
if (get('libreta') !== '') {
    $per = preg_match('/^\d{4}-T[1-4]$/', get('libreta')) ? get('libreta') : periodo_libreta();
    doc_libreta_alumno($a, $liga, $per, $url . '&s=libreta');
    exit;
}

$grupos = grupos_visibles($lid);
$as = asistencia_alumno($aid);
cabecera_admin(nombre_alumno($a));
?>
<p><a href="admin.php?p=alumnos<?= $a['grupo_id'] ? '&amp;g=' . (int)$a['grupo_id'] : '' ?>">← Alumnos</a></p>
<div class="perfil tarjeta">
  <?= foto_alumno($a, 'foto-grande') ?>
  <div>
    <h1 style="margin:0"><?= e(nombre_alumno($a)) ?> <?= $a['estado'] !== 'activo' ? '<span class="etiqueta gris">' . e($a['estado']) . '</span>' : '' ?></h1>
    <p style="margin:.3rem 0"><b><?= e(sub_de($a['fecha_nac'])) ?></b> · <?= $a['fecha_nac'] ? edad($a['fecha_nac']) . ' años · ' : '' ?><?= e($a['grupo'] ?? 'sin grupo') ?><?= $a['posicion'] ? ' · ' . e($a['posicion']) : '' ?> · <?= implode(' ', array_map('chip_deporte', deportes_alumno($a, $liga))) ?><?= ($gr_a = grado_actual($aid)) ? ' <span class="chip-deporte">🥋 ' . e($gr_a['grado']) . '</span>' : '' ?></p>
    <?php if ($otros = texto_otros_grupos($a, $liga)): ?><p class="suave" style="margin:.2rem 0"><?= e($otros) ?></p><?php endif; ?>
    <p style="margin:.3rem 0">Asistencia (90 días): <b><?= $as['pct'] !== null ? $as['pct'] . '%' : '—' ?></b><?php if (puede('cobros')): $ep = estado_pagos($aid); ?> · Pagos: <?= chip_pago($ep['estado']) ?><?= $ep['deuda'] > 0 ? ' ' . dinero($ep['deuda']) : '' ?><?php endif; ?>
      <?= $a['alergias'] || $a['condiciones'] ? ' · <span class="etiqueta roja">🩺 ' . e(trim($a['alergias'] . ' ' . $a['condiciones'])) . '</span>' : '' ?></p>
    <div class="acciones"><a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=esc_carnets&amp;alumno=<?= $aid ?>">🪪 Carnet</a><?php if (modulo_menu($liga, 'videos')): ?><a class="btn btn-sec btn-chico" href="admin.php?p=videos&amp;id=<?= $aid ?>#subir">🎬 Subir video</a><?php endif; ?><a class="btn btn-sec btn-chico" target="_blank" href="<?= e($url) ?>&amp;libreta=<?= periodo_libreta() ?>">📘 Informe de desarrollo</a></div>
  </div>
</div>
<?php pestanas_alumno($aid, $s); ?>

<?php if ($s === 'datos'): ?>
  <?php $cf = $_SESSION['msg_familia'] ?? null; unset($_SESSION['msg_familia']); ?>
  <div class="tarjeta" id="familia">
    <h2 style="margin-top:0">👨‍👩‍👦 Portal de la familia</h2>
    <p class="suave" style="margin-top:0">El representante ve en su celular la asistencia, los pagos, las evaluaciones, la libreta y el carnet de sus hijos, y puede subir el comprobante de pago.</p>
    <?php if ($cf): $msg = mensaje_familia($a, $liga, $cf); ?><div class="aviso aviso-info"><pre class="mensaje"><?= e($msg) ?></pre><a class="btn btn-chico" target="_blank" href="https://wa.me/<?= e(telefono_wa($a['representante_telefono']) ?: '') ?>?text=<?= rawurlencode($msg) ?>">📲 Enviar por WhatsApp</a></div><?php endif; ?>
    <?php if (puede('alumnos', true)): ?><form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="familia"><button class="btn btn-sec btn-chico">🔑 Generar acceso para el representante</button></form><?php endif; ?>
  </div>
  <?php $gal = grupos_alumno($aid); $extras = array_values(array_filter($gal, fn($g) => !(int)$g['principal'])); $ids_gal = array_map(fn($g) => (int)$g['id'], $gal);
  $libres = array_values(array_filter(grupos_visibles($lid), fn($g) => !in_array((int)$g['id'], $ids_gal, true))); ?>
  <div class="tarjeta" id="deportes">
    <h2 style="margin-top:0">🏅 Deportes y grupos</h2>
    <p style="margin-top:0">Grupo principal: <b><?= e($a['grupo'] ?? 'sin grupo') ?></b><?= $a['grupo_id'] ? ' · ' . chip_deporte(deporte_grupo(fila('SELECT * FROM esc_grupos WHERE id = ?', [$a['grupo_id']]), $liga)) : '' ?> <small class="suave">(se cambia abajo en los datos)</small></p>
    <?php foreach ($extras as $gx): ?><div class="acciones" style="margin:.3rem 0"><span>También en <b><?= e($gx['nombre']) ?></b> <?= chip_deporte(deporte_grupo($gx, $liga)) ?> <small class="suave"><?= e(texto_horario($gx)) ?><?= $gx['mensualidad'] !== null ? ' · ' . dinero($gx['mensualidad']) . '/mes' : '' ?></small></span>
      <?php if (puede('alumnos', true)): ?><form class="inline" method="post" onsubmit="return confirm('¿Quitarlo de este grupo?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="quitar_grupo_extra"><input type="hidden" name="grupo_id" value="<?= (int)$gx['id'] ?>"><button class="btn btn-sec btn-chico">Quitar</button></form><?php endif; ?></div><?php endforeach; ?>
    <?php if (puede('alumnos', true) && $libres && $a['grupo_id']): ?>
    <form method="post" class="acciones" style="margin-top:8px;align-items:flex-end"><?= csrf_campo() ?><input type="hidden" name="accion" value="grupo_extra">
      <div><label>Agregar otro deporte o grupo</label><select name="grupo_id"><?php foreach ($libres as $gl): ?><option value="<?= (int)$gl['id'] ?>"><?= icono_deporte_escuela(deporte_grupo($gl, $liga)) ?> <?= e($gl['nombre']) ?></option><?php endforeach; ?></select></div>
      <button class="btn btn-sec btn-chico">+ Agregar</button>
    </form>
    <p class="ayuda" style="margin-bottom:0">Entrena y se evalúa también en ese grupo, y cada mes se genera la mensualidad de ese grupo<?= (float)($liga['esc_desc_segundo'] ?? 0) > 0 ? ' con ' . fmt_num((float)$liga['esc_desc_segundo']) . ' % de descuento' : '' ?>.</p>
    <?php endif; ?>
  </div>
  <?php if (puede('alumnos', true)): ?>
  <div class="tarjeta">
    <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="datos">
      <?php require APP_DIR . '/admin/esc_form_alumno.php'; ?>
      <?= html_requisitos_form($mod_req, 'esc_alumnos', $aid) ?>
      <button class="btn">Guardar</button>
    </form>
    <div class="acciones" style="margin-top:10px">
      <form class="inline" method="post" onsubmit="return confirm('¿Seguro?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="estado" value="<?= $a['estado'] === 'activo' ? 'retirado' : 'activo' ?>"><button class="btn btn-sec btn-chico"><?= $a['estado'] === 'activo' ? 'Retirar de la escuela' : 'Volver a activar' ?></button></form>
      <form class="inline" method="post" onsubmit="return confirm('¿Anular el carnet actual?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="carnet"><button class="btn btn-sec btn-chico">Anular carnet QR</button></form>
    </div>
  </div>
  <?php endif; ?>

<?php elseif ($s === 'desarrollo'): // (v17)
    $metas = filas("SELECT * FROM esc_metas WHERE alumno_id = ? ORDER BY estado = 'lograda', estado = 'pausada', actualizado DESC", [$aid]); ?>
  <div class="acciones" style="margin-bottom:10px"><a class="btn btn-sec btn-chico" target="_blank" href="<?= e($url) ?>&amp;libreta=<?= periodo_libreta() ?>">🖨 Informe para imprimir o PDF</a>
    <span class="suave">Esto mismo ven los padres en su portal (sin las notas internas).</span></div>
  <?= panel_desarrollo($a, $liga) ?><?= html_cinturon_alumno($a, $liga) ?><?= html_competencias_alumno($a) ?>
  <div class="tarjeta" id="metas">
    <h3 style="margin-top:0">🎯 Metas personales</h3>
    <p class="suave" style="margin-top:0">Metas concretas para el alumno (ej: "Pasar con la pierna izquierda", "Bajar el sprint de 30 m a 5,2 s"). Al cumplirlas gana una insignia.</p>
    <?= html_metas($metas, puede('evaluaciones', true), $url . '&s=desarrollo') ?>
    <?php if (puede('evaluaciones', true)): ?>
    <form method="post" style="margin-top:12px"><?= csrf_campo() ?><input type="hidden" name="accion" value="meta">
      <div class="campos">
        <div class="campo-ancho"><label>Nueva meta</label><input type="text" name="texto" maxlength="200" required placeholder="Ej: Controlar orientado con la pierna menos hábil"></div>
        <div><label>Área</label><select name="area"><?php foreach (areas_meta() as $k => [$ic, $t]): ?><option value="<?= $k ?>"><?= $ic ?> <?= e($t) ?></option><?php endforeach; ?></select></div>
        <div><label>Para cuándo (opcional)</label><input type="date" name="fecha_objetivo" value="<?= date('Y-m-d', strtotime('+3 months')) ?>"></div>
        <div><label class="check" style="margin-top:26px"><input type="checkbox" name="visible_familia" value="1" checked> La ven los padres</label></div>
      </div>
      <button class="btn btn-sec">+ Agregar meta</button></form>
    <?php endif; ?>
  </div>

<?php elseif ($s === 'club' && formativas_activas($liga)): // (v17) Divisiones formativas
    require APP_DIR . '/admin/alumno_club.php'; ?>

<?php elseif ($s === 'salud'): $les = filas('SELECT * FROM esc_lesiones WHERE alumno_id = ? ORDER BY fecha DESC', [$aid]); ?>
  <div class="tarjeta">
    <h2 style="margin-top:0">🩺 Ficha médica</h2>
    <div class="meta" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr))">
      <div><b>Tipo de sangre:</b> <?= e($a['tipo_sangre'] ?: '—') ?></div><div><b>Alergias:</b> <?= e($a['alergias'] ?: '—') ?></div>
      <div><b>Condiciones:</b> <?= e($a['condiciones'] ?: '—') ?></div><div><b>Medicamentos:</b> <?= e($a['medicamentos'] ?: '—') ?></div>
      <div><b>Seguro:</b> <?= e($a['seguro'] ?: '—') ?></div><div><b>Emergencia:</b> <?= e($a['emergencia_nombre'] ?: $a['representante_nombre']) ?> · <?php $te = $a['emergencia_telefono'] ?: $a['representante_telefono']; ?><a href="tel:<?= e($te) ?>"><?= e($te) ?></a></div>
    </div>
    <p class="suave">Se edita en la pestaña Datos → Ficha médica.</p>
  </div>
  <h2>🩹 Lesiones</h2>
  <?php if (!$les): ?><p class="suave">Sin lesiones registradas.</p><?php endif; ?>
  <?php $fases = array_keys(fases_lesion()); $pued_l = puede('alumnos', true) || puede('evaluaciones', true) || puede('formativas', true);
  foreach ($les as $l): $fa = $l['alta'] ? 'alta' : ($l['fase'] ?: 'aguda'); $ix = array_search($fa, $fases, true);
      $seg = filas('SELECT * FROM esc_lesion_seguimiento WHERE lesion_id = ? ORDER BY fecha, id', [$l['id']]); ?>
    <div class="tarjeta" style="padding:10px 12px"><b><?= e(date('d/m/Y', strtotime($l['fecha']))) ?></b> · <?= e($l['zona']) ?> <?= e($l['tipo']) ?> <?= $l['dias_baja'] ? '· ' . (int)$l['dias_baja'] . ' días de baja' : '' ?>
      <?= $l['alta'] ? '<span class="estado-chip chip-ok">Alta ' . e(date('d/m', strtotime($l['alta']))) . '</span>' : '<span class="estado-chip chip-mal">' . e(fases_lesion()[$fa] ?? 'En recuperación') . '</span>' ?>
      <?= $l['diagnostico'] ? '<br><b>Diagnóstico:</b> ' . e($l['diagnostico']) : '' ?><?= $l['profesional'] ? ' · ' . e($l['profesional']) : '' ?>
      <?= !$l['alta'] && $l['retorno_estimado'] ? '<br><b>Regreso estimado:</b> ' . e(fecha_corta($l['retorno_estimado'])) . ($l['retorno_estimado'] < date('Y-m-d') ? ' <span class="semaforo s-amarillo">ya pasó la fecha</span>' : ' (faltan ' . (int)ceil((strtotime($l['retorno_estimado']) - time()) / 86400) . ' días)') : '' ?>
      <?= $l['descripcion'] ? '<br><small>' . e($l['descripcion']) . '</small>' : '' ?>
      <div class="fase-lesion" title="<?= e(fases_lesion()[$fa] ?? '') ?>"><?php foreach ($fases as $i => $f): ?><span class="<?= $i < $ix || $fa === 'alta' ? 'hecha' : ($i === $ix ? 'actual' : '') ?>" title="<?= e(fases_lesion()[$f]) ?>"></span><?php endforeach; ?></div>
      <?php if ($seg): ?><ul style="margin:.3rem 0;padding-left:1.1rem;font-size:.9rem"><?php foreach ($seg as $x): ?><li><b><?= e(fecha_corta($x['fecha'])) ?></b> · <?= e(fases_lesion()[$x['fase']] ?? '') ?><?= $x['texto'] ? ': ' . e($x['texto']) : '' ?> <small class="suave">— <?= e($x['registrado_por']) ?></small></li><?php endforeach; ?></ul><?php endif; ?>
      <?php if (!$l['alta'] && $pued_l): ?>
        <details><summary class="suave" style="cursor:pointer">+ Anotar avance</summary>
          <form method="post" class="acciones" style="align-items:flex-end;margin-top:6px"><?= csrf_campo() ?><input type="hidden" name="accion" value="lesion_seg"><input type="hidden" name="lesion_id" value="<?= (int)$l['id'] ?>">
            <div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
            <div><label>Fase</label><select name="fase"><?php foreach (fases_lesion() as $k => $t): ?><option value="<?= $k ?>" <?= $k === $fa ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
            <div><label>Regreso estimado</label><input type="date" name="retorno_estimado" value="<?= e($l['retorno_estimado']) ?>"></div>
            <div style="flex:1;min-width:200px"><label>Detalle</label><input type="text" name="texto" maxlength="400" placeholder="Ej: ya trota sin dolor; empieza trabajo con balón"></div>
            <button class="btn btn-sec btn-chico">Guardar</button></form></details>
      <?php endif; ?></div><?php endforeach; ?>
  <?php if (puede('alumnos', true) || puede('evaluaciones', true)): ?>
  <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="lesion">
    <h3 style="margin-top:0">+ Registrar lesión</h3>
    <div class="campos"><div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div><div><label>Zona</label><input type="text" name="zona" maxlength="60" placeholder="Tobillo derecho"></div>
      <div><label>Tipo</label><input type="text" name="tipo" maxlength="60" placeholder="Esguince"></div><div><label>Días de baja (aprox.)</label><input type="number" name="dias_baja" min="0" max="365" value="0"></div>
      <div><label>Diagnóstico (opcional)</label><input type="text" name="diagnostico" maxlength="200" placeholder="Ej: esguince grado I"></div><div><label>Profesional que lo atiende</label><input type="text" name="profesional" maxlength="100" placeholder="Ej: Dr. Pérez / fisioterapeuta"></div>
      <div class="campo-ancho"><label>Descripción / tratamiento</label><input type="text" name="descripcion" maxlength="300"></div></div>
    <button class="btn btn-sec">Guardar</button>
  </form>
  <?php endif; ?>

<?php elseif ($s === 'pagos' && puede('cobros')):
    $cargos = filas("SELECT * FROM esc_cargos WHERE alumno_id = ? AND estado <> 'anulado' ORDER BY (estado = 'pendiente') DESC, vence DESC LIMIT 60", [$aid]);
    $pend = array_filter($cargos, fn($c) => $c['estado'] === 'pendiente'); ?>
  <?php if ($pend && puede('cobros', true)): ?>
  <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="cobrar">
    <h2 style="margin-top:0">Pendiente</h2>
    <?php foreach ($pend as $c): $venc = $c['vence'] < date('Y-m-d'); ?><label class="check" style="font-weight:400"><input type="checkbox" name="cargos[]" value="<?= (int)$c['id'] ?>" checked> <?= e($c['descripcion']) ?> · <b><?= dinero($c['monto'] - $c['descuento']) ?></b><?= $c['descuento'] > 0 ? ' <small>(desc. ' . dinero($c['descuento']) . ')</small>' : '' ?> · vence <?= e(date('d/m', strtotime($c['vence']))) ?> <?= $venc ? '<span class="semaforo s-rojo">vencido</span>' : '' ?><?= $c['comprobante_estado'] === 'pendiente' ? ' <a target="_blank" href="admin.php?p=cobros&amp;foto=' . (int)$c['id'] . '">📎 comprobante</a>' : '' ?></label><?php endforeach; ?>
    <div class="acciones" style="margin-top:8px"><select name="forma_pago"><?php foreach (formas_pago() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select><button class="btn">💵 Cobrar y emitir recibo</button></div>
  </form>
  <?php endif; ?>
  <h2>Historial</h2>
  <div class="tabla-scroll"><table><tr><th>Concepto</th><th>Vence</th><th class="num">Valor</th><th>Estado</th><th></th></tr>
    <?php foreach ($cargos as $c): ?><tr><td><?= e($c['descripcion']) ?></td><td><?= e($c['vence']) ?></td><td class="num"><?= dinero($c['monto'] - $c['descuento']) ?></td>
      <td><?= $c['estado'] === 'pagado' ? '<span class="estado-chip chip-ok">Pagado ' . e($c['pagado_en']) . '</span>' : ($c['vence'] < date('Y-m-d') ? '<span class="semaforo s-rojo">Vencido</span>' : '<span class="semaforo s-amarillo">Pendiente</span>') ?></td>
      <td><?php if ($c['recibo']): ?><a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=esc_recibo&amp;n=<?= (int)$c['recibo'] ?>">🖨 Recibo</a><?php elseif (puede('cobros', true)): ?><form class="inline" method="post" onsubmit="return confirm('¿Anular este cargo?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="anular_cargo"><input type="hidden" name="cargo_id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sec btn-chico">Anular</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php if (puede('cobros', true)): ?>
  <form method="post" class="tarjeta" style="margin-top:12px"><?= csrf_campo() ?><input type="hidden" name="accion" value="cargo">
    <h3 style="margin-top:0">+ Otro cargo</h3>
    <div class="campos"><div><label>Tipo</label><select name="tipo"><?php foreach (tipos_cargo_escuela() as $k => $v): if ($k === 'mensualidad') continue; ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div><label>Descripción</label><input type="text" name="descripcion" maxlength="150"></div><div><label>Valor ($)</label><input type="number" name="monto" min="0.01" step="0.01" required></div><div><label>Vence</label><input type="date" name="vence" value="<?= date('Y-m-d') ?>"></div></div>
    <button class="btn btn-sec">Agregar</button>
  </form>
  <?php endif; ?>

<?php elseif ($s === 'asistencia'):
    $hist = filas('SELECT s.fecha, x.estado, g.nombre AS grupo FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id JOIN esc_grupos g ON g.id = s.grupo_id WHERE x.alumno_id = ? ORDER BY s.fecha DESC LIMIT 120', [$aid]);
    $colores = ['P' => 's-verde', 'T' => 's-amarillo', 'J' => 's-gris', 'A' => 's-rojo']; ?>
  <p>Últimos 90 días: vino a <b><?= $as['vino'] ?></b> de <b><?= $as['total'] ?></b> entrenamientos (<?= $as['pct'] ?? 0 ?>%).</p>
  <?php if (!$hist): ?><p class="suave">Todavía no hay asistencia registrada.</p><?php endif; ?>
  <div class="calendario-asis"><?php foreach ($hist as $h): ?><span class="semaforo <?= $colores[$h['estado']] ?? '' ?>" title="<?= e($h['grupo']) ?>"><?= e(date('d/m', strtotime($h['fecha']))) ?> <?= e(estados_asistencia()[$h['estado']] ?? $h['estado']) ?></span><?php endforeach; ?></div>
  <?php $prox = proximas_clases_alumno($a, 3, false); if ($prox): ?>
  <h2>🗓 Próximas clases planificadas</h2>
  <div class="rejilla-3"><?php foreach ($prox as $pc): $pe = ejercicios_sesion((int)$pc['id']); ?><div class="tarjeta"><b><?= e(dia_corto($pc['fecha'])) ?></b> · <?= e($pc['objetivo'] ?: 'Entrenamiento') ?><br><small class="suave"><?= $pe ? e(implode(' · ', array_column($pe, 'nombre'))) : 'Sin ejercicios todavía' ?></small></div><?php endforeach; ?></div>
  <?php endif; ?>
  <h2>📖 Diario de entrenamientos</h2>
  <p class="suave" style="margin-top:0">Lo que entrenó en cada clase, la nota del profesor y las fotos. Los padres lo ven igual en su portal.</p>
  <?= html_diario(diario_alumno($aid, 12), false) ?>

<?php elseif ($s === 'medidas'): $med = filas('SELECT * FROM esc_mediciones WHERE alumno_id = ? ORDER BY fecha', [$aid]); ?>
  <?php if (count($med) >= 2): ?><div class="rejilla-2">
    <div class="tarjeta"><h3 style="margin-top:0">Peso (kg)</h3><?= svg_linea(array_map(fn($m) => [$m['fecha'], $m['peso']], $med), 'kg') ?></div>
    <div class="tarjeta"><h3 style="margin-top:0">Talla (cm)</h3><?= svg_linea(array_map(fn($m) => [$m['fecha'], $m['talla']], $med), 'cm') ?></div>
  </div><?php endif; ?>
  <div class="tabla-scroll"><table><tr><th>Fecha</th><?php foreach (campos_medicion() as [$n, $u]): ?><th class="num"><?= e($n) ?><br><small><?= e($u) ?></small></th><?php endforeach; ?><th class="num">IMC</th><th></th></tr>
    <?php foreach (array_reverse($med) as $m): ?><tr><td><?= e(date('d/m/Y', strtotime($m['fecha']))) ?></td><?php foreach (array_keys(campos_medicion()) as $k): ?><td class="num"><?= $m[$k] !== null ? e(rtrim(rtrim(number_format((float)$m[$k], 1, '.', ''), '0'), '.')) : '' ?></td><?php endforeach; ?>
      <td class="num pts"><?= imc($m['peso'] !== null ? (float)$m['peso'] : null, $m['talla'] !== null ? (float)$m['talla'] : null) ?? '' ?></td>
      <td><?php if (puede('evaluaciones', true)): ?><form class="inline" method="post" onsubmit="return confirm('¿Borrar?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_medida"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><button class="btn btn-sec btn-chico">×</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php if (puede('evaluaciones', true)): ?>
  <form method="post" class="tarjeta" style="margin-top:12px"><?= csrf_campo() ?><input type="hidden" name="accion" value="medida">
    <h3 style="margin-top:0">+ Nueva medición</h3>
    <div class="campos"><div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
      <?php foreach (campos_medicion() as $k => [$n, $u]): ?><div><label><?= e($n) ?> (<?= e($u) ?>)</label><input type="number" name="<?= $k ?>" step="0.1" min="0" inputmode="decimal"></div><?php endforeach; ?>
      <?php if (formativas_activas($liga)): ?><div><label>Talla sentado (cm) <small title="Sentado en un banco, espalda recta: desde el banco hasta la coronilla. Sirve para calcular la maduración.">ⓘ</small></label><input type="number" name="talla_sentado" step="0.1" min="0" inputmode="decimal"></div><?php endif; ?>
      <div class="campo-ancho"><label>Nota</label><input type="text" name="nota" maxlength="200"></div></div>
    <button class="btn btn-sec">Guardar</button>
  </form>
  <?php endif; ?>

<?php elseif ($s === 'tests'): $tests = filas('SELECT * FROM esc_tests WHERE liga_id = ? AND activo = 1 AND ' . sql_deporte(deportes_alumno($a, $liga)) . ' ORDER BY orden, nombre', [$lid]); ?>
  <div class="rejilla-2">
  <?php foreach ($tests as $t): $res = filas('SELECT * FROM esc_resultados WHERE alumno_id = ? AND test_id = ? ORDER BY fecha', [$aid, $t['id']]); if (!$res) continue;
      $vals = array_map(fn($r) => (float)$r['valor'], $res); $mejor = $t['mejor'] === 'menor' ? min($vals) : max($vals); ?>
    <div class="tarjeta"><h3 style="margin-top:0"><?= e($t['nombre']) ?> <small class="suave"><?= e($t['unidad']) ?> · mejor: <?= e(rtrim(rtrim(number_format($mejor, 2, '.', ''), '0'), '.')) ?></small></h3>
      <?= count($res) >= 2 ? svg_linea(array_map(fn($r) => [$r['fecha'], $r['valor']], $res)) : '<p>' . e(date('d/m/Y', strtotime($res[0]['fecha']))) . ': <b>' . e((string)(float)$res[0]['valor']) . ' ' . e($t['unidad']) . '</b></p>' ?></div>
  <?php endforeach; ?>
  </div>
  <?php if (puede('evaluaciones', true) && $tests): ?>
  <form method="post" class="tarjeta" style="margin-top:12px"><?= csrf_campo() ?><input type="hidden" name="accion" value="test">
    <h3 style="margin-top:0">+ Registrar resultado</h3>
    <div class="campos"><div><label>Test</label><select name="test_id"><?php foreach ($tests as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['nombre'] . ' (' . $t['unidad'] . ')') ?></option><?php endforeach; ?></select></div>
      <div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div><div><label>Resultado</label><input type="number" name="valor" step="0.01" required inputmode="decimal"></div><div><label>Nota</label><input type="text" name="nota" maxlength="200"></div></div>
    <button class="btn btn-sec">Guardar</button>
    <p class="ayuda">Para todo un grupo a la vez, use Evaluaciones → Tests.</p>
  </form>
  <?php endif; ?>

<?php elseif ($s === 'tecnica'):
    // (v19) Una evaluación técnica por deporte (si el alumno practica varios, se elige arriba)
    $deps = deportes_alumno($a, $liga);
    $dep = in_array(get('dep'), $deps, true) ? get('dep') : $deps[0];
    $habs = habilidades_deporte($lid, $dep);
    $ids_h = array_map(fn($h) => (int)$h['id'], $habs);
    $evals = array_values(array_filter(filas('SELECT * FROM esc_eval_tecnica WHERE alumno_id = ? ORDER BY fecha DESC, id DESC LIMIT 40', [$aid]),
        fn($ev) => (bool)array_intersect(array_map('intval', array_keys((array)json_decode($ev['valores'], true))), $ids_h)));
    $ult = $evals[0] ?? null; $ant = $evals[1] ?? null;
    if (count($deps) > 1): ?><nav class="pestanas"><?php foreach ($deps as $dx): ?><a class="<?= $dx === $dep ? 'activa' : '' ?>" href="<?= e($url) ?>&amp;s=tecnica&amp;dep=<?= $dx ?>"><?= icono_deporte_escuela($dx) ?> <?= e(nombre_deporte_corto($dx)) ?></a><?php endforeach; ?></nav><?php endif;
    $uv = $ult ? json_decode($ult['valores'], true) : []; $av = $ant ? json_decode($ant['valores'], true) : null; ?>
  <?php if ($ult): ?><div class="tarjeta" style="text-align:center"><h3 style="margin-top:0">Evaluación del <?= e(date('d/m/Y', strtotime($ult['fecha']))) ?><?= $ant ? ' <small class="suave">(línea punteada: ' . e(date('d/m/Y', strtotime($ant['fecha']))) . ')</small>' : '' ?></h3>
    <?= svg_radar(array_column($habs, 'nombre'), array_map(fn($h) => (float)($uv[$h['id']] ?? 0), $habs), 5, $av ? array_map(fn($h) => (float)($av[$h['id']] ?? 0), $habs) : null) ?>
    <?= $ult['comentario'] ? '<p>' . e($ult['comentario']) . '</p>' : '' ?></div><?php endif; ?>
  <?php if (puede('evaluaciones', true) && $habs): ?>
  <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="tecnica">
    <h3 style="margin-top:0">+ Nueva evaluación técnica (1 = por mejorar · 5 = excelente)</h3>
    <p><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>" style="max-width:180px"></p>
    <?php foreach ($habs as $h): ?><div class="eval-fila"><span><?= e($h['nombre']) ?></span><span><?php for ($i = 1; $i <= 5; $i++): ?><label class="eval-num"><input type="radio" name="h[<?= (int)$h['id'] ?>]" value="<?= $i ?>" <?= (int)($uv[$h['id']] ?? 0) === $i ? 'checked' : '' ?>><b><?= $i ?></b></label><?php endfor; ?></span></div><?php endforeach; ?>
    <p><label>Comentario del profesor</label><textarea name="comentario" rows="2" maxlength="500"></textarea></p>
    <button class="btn btn-sec">Guardar evaluación</button>
  </form>
  <?php endif; ?>

<?php elseif ($s === 'libreta'): $per = preg_match('/^\d{4}-T[1-4]$/', get('periodo')) ? get('periodo') : periodo_libreta();
    $lb = fila('SELECT * FROM esc_libretas WHERE alumno_id = ? AND periodo = ?', [$aid, $per]); ?>
  <form method="get" class="acciones"><input type="hidden" name="p" value="alumno"><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="s" value="libreta">
    <select name="periodo" onchange="this.form.submit()"><?php for ($i = 0; $i < 6; $i++): $pp = periodo_libreta(date('Y-m-d', strtotime("-" . (3 * $i) . " months"))); ?><option value="<?= $pp ?>" <?= $pp === $per ? 'selected' : '' ?>><?= e(nombre_periodo_libreta($pp)) ?></option><?php endfor; ?></select>
    <a class="btn" target="_blank" href="<?= e($url) ?>&amp;libreta=<?= e($per) ?>">🖨 Ver / imprimir el informe</a></form>
  <p class="suave">El informe de desarrollo reúne la asistencia, los gráficos de tests (comparados con su categoría), la evaluación técnica, las insignias, las metas y el comentario del profesor del trimestre. La familia también lo ve en su portal y lo puede guardar en PDF.</p>
  <?php if (puede('evaluaciones', true)): ?>
  <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="libreta"><input type="hidden" name="periodo" value="<?= e($per) ?>">
    <label>Comentario del profesor (<?= e(nombre_periodo_libreta($per)) ?>)</label><textarea name="comentario" rows="4" maxlength="1500"><?= e($lb['comentario'] ?? '') ?></textarea>
    <button class="btn btn-sec" style="margin-top:8px">Guardar comentario</button>
  </form>
  <?php endif; ?>
<?php endif;
pie_admin();
