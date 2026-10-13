<?php
/* (v11) ALUMNOS: lista con filtros y semáforo de pagos, nuevo alumno, inscripciones en línea por aprobar */
$liga = requiere_liga();
$lid = (int)$liga['id'];
preparar_escuela($lid);
generar_mensualidades($liga);
$por = usuario()['nombre'];

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'nuevo') {
        verificar_alumnos_plan($liga); // (v12) alumnos según el plan
        $d = leer_alumno_post($lid);
        if (post('autorizacion') !== '1') throw new RuntimeException('Marque que tiene la autorización de datos del representante o del alumno.');
        if ($d['cedula'] !== '' && fila("SELECT id FROM esc_alumnos WHERE liga_id = ? AND cedula = ? AND estado <> 'retirado'", [$lid, $d['cedula']])) throw new RuntimeException('Ya hay un alumno con esa cédula.');
        if (!$d['grupo_id'] && ($gs = grupo_sugerido($lid, $d['fecha_nac']))) $d['grupo_id'] = (int)$gs['id'];
        $d += ['liga_id' => $lid, 'estado' => 'activo', 'origen' => 'panel', 'consentimiento' => date('Y-m-d H:i:s'), 'consentimiento_por' => es_menor($d['fecha_nac']) ? 'representante' : 'alumno'];
        if ($f = subir_imagen('foto', 'jugadores', 700)) $d['foto'] = $f;
        q('INSERT INTO esc_alumnos (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($d))) . ')', $d);
        $aid = (int)db()->lastInsertId();
        registrar_consentimiento_alumno($lid, $d, $aid, false, $liga['nombre']); // (v24)
        guardar_nivel_inicial($aid, $lid, $por); // (TuAcademia)
        if (post('cobrar_matricula') === '1' && (float)$liga['esc_matricula'] > 0) crear_cargo_escuela($liga, $aid, 'matricula', 'Matrícula ' . date('Y'), (float)$liga['esc_matricula'], date('Y-m-d'), $por);
        generar_mensualidades($liga);
        flash('Alumno inscrito.');
        ir('admin.php?p=alumno&id=' . $aid);
    }
    if ($accion === 'aprobar' || $accion === 'rechazar') {
        $a = alumno_de_escuela((int)post('id'), $lid);
        if ($a['estado'] !== 'pendiente') throw new RuntimeException('Esa inscripción ya fue revisada.');
        if ($accion === 'rechazar') {
            q("UPDATE esc_alumnos SET estado = 'rechazado', notas = ? WHERE id = ?", [mb_substr(trim($a['notas'] . ' Rechazado: ' . post('motivo')), 0, 300), $a['id']]);
            flash('Inscripción rechazada.');
        } else {
            $gid = (int)post('grupo_id');
            q("UPDATE esc_alumnos SET estado = 'activo', grupo_id = ?, fecha_ingreso = ? WHERE id = ?", [$gid ?: $a['grupo_id'], date('Y-m-d'), $a['id']]);
            if (post('cobrar_matricula') === '1' && (float)$liga['esc_matricula'] > 0) crear_cargo_escuela($liga, (int)$a['id'], 'matricula', 'Matrícula ' . date('Y'), (float)$liga['esc_matricula'], date('Y-m-d'), $por);
            generar_mensualidades($liga);
            flash('Alumno aprobado: ' . nombre_alumno($a) . '.');
        }
        ir('admin.php?p=alumnos&estado=pendiente');
    }
}

$estado = in_array(get('estado'), ['activo', 'pendiente', 'retirado'], true) ? get('estado') : 'activo';
$gid = (int)get('g');
$buscar = trim(get('q'));
$grupos = grupos_visibles($lid);
$ids_grupos = array_map(fn($g) => (int)$g['id'], $grupos);
$sql = 'SELECT a.*, g.nombre AS grupo FROM esc_alumnos a LEFT JOIN esc_grupos g ON g.id = a.grupo_id WHERE a.liga_id = ? AND a.estado = ?';
$par = [$lid, $estado];
if ($gid) $sql .= ' AND ' . sql_en_grupo('a', $gid);
if (perfil_actual() === 'profesor') $sql .= ' AND ' . sql_en_grupo('a', $ids_grupos);
if ($buscar !== '') { $sql .= " AND (a.nombres || ' ' || a.apellidos LIKE ? OR a.cedula LIKE ? OR a.representante_nombre LIKE ?)"; array_push($par, "%$buscar%", "%$buscar%", "%$buscar%"); }
$alumnos = filas($sql . ' ORDER BY a.apellidos, a.nombres LIMIT 500', $par);
$n_pend = (int)valor("SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ? AND estado = 'pendiente'", [$lid]);
$ed = puede('alumnos', true);
$ver_pagos = puede('cobros');
cabecera_admin('Alumnos');
?>
<div class="fila-titulo"><h1>👦 Alumnos</h1>
  <span class="acciones"><?php if ($ed): ?><a class="btn" href="#nuevo">+ Nuevo alumno</a><?php endif; ?> <a class="btn btn-sec" target="_blank" href="admin.php?p=esc_carnets<?= $gid ? '&amp;g=' . $gid : '' ?>">🪪 Carnets</a></span></div>
<nav class="pestanas">
  <a class="<?= $estado === 'activo' ? 'activa' : '' ?>" href="admin.php?p=alumnos">Activos</a>
  <a class="<?= $estado === 'pendiente' ? 'activa' : '' ?>" href="admin.php?p=alumnos&amp;estado=pendiente">Por aprobar<?= $n_pend ? ' (' . $n_pend . ')' : '' ?></a>
  <a class="<?= $estado === 'retirado' ? 'activa' : '' ?>" href="admin.php?p=alumnos&amp;estado=retirado">Retirados</a>
</nav>
<form method="get" class="acciones" style="margin-bottom:10px"><input type="hidden" name="p" value="alumnos"><input type="hidden" name="estado" value="<?= e($estado) ?>">
  <select name="g" onchange="this.form.submit()"><option value="">Todos los grupos</option><?= opciones_grupos($grupos, $gid, false) ?></select>
  <input type="search" name="q" value="<?= e($buscar) ?>" placeholder="Buscar nombre, cédula o representante"><button class="btn btn-sec btn-chico">Buscar</button></form>

<?php if ($estado === 'pendiente'): ?>
  <?php if (!$alumnos): ?><div class="tarjeta">No hay inscripciones por aprobar.</div><?php endif; ?>
  <?php foreach ($alumnos as $a): $sug = grupo_sugerido($lid, $a['fecha_nac']); ?>
    <div class="tarjeta">
      <div class="perfil"><?= foto_alumno($a, 'foto-grande') ?><div>
        <h3 style="margin:0"><?= e(nombre_alumno($a)) ?> <small class="suave"><?= e(sub_de($a['fecha_nac'])) ?> · <?= e($a['fecha_nac']) ?></small></h3>
        <p style="margin:.3rem 0">Representante: <b><?= e($a['representante_nombre']) ?></b> · C.I. <?= e($a['representante_cedula']) ?> · 📞 <?= e($a['representante_telefono']) ?> · ✉ <?= e($a['representante_email']) ?></p>
        <p style="margin:.3rem 0" class="suave">Se inscribió por internet el <?= e(substr($a['creado'], 0, 10)) ?> · autorización de datos: <?= $a['consentimiento'] ? '✓' : '✗' ?><?= $a['condiciones'] || $a['alergias'] ? ' · 🩺 ' . e(trim($a['alergias'] . ' ' . $a['condiciones'])) : '' ?></p>
      </div></div>
      <?php if ($ed): ?>
      <form method="post" class="acciones" style="margin-top:8px;align-items:flex-end"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <div><label>Grupo</label><select name="grupo_id"><?= opciones_grupos($grupos, (int)($a['grupo_id'] ?: ($sug['id'] ?? 0))) ?></select></div>
        <?php if ((float)$liga['esc_matricula'] > 0): ?><label class="check"><input type="checkbox" name="cobrar_matricula" value="1" checked> Cobrar matrícula (<?= dinero($liga['esc_matricula']) ?>)</label><?php endif; ?>
        <button class="btn" name="accion" value="aprobar">✓ Aprobar</button>
        <input type="text" name="motivo" placeholder="Motivo si rechaza" maxlength="200" style="max-width:220px"><button class="btn btn-peligro" name="accion" value="rechazar" onclick="return confirm('¿Rechazar la inscripción?')">✗ Rechazar</button>
      </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php else: ?>
  <p class="suave"><?= count($alumnos) ?> alumno(s)</p>
  <div class="tabla-scroll"><table>
    <tr><th>Alumno</th><th>Cat.</th><th>Grupo</th><th>Representante</th><th class="num">Asist.</th><?php if ($ver_pagos): ?><th>Pagos</th><?php endif; ?></tr>
    <?php foreach ($alumnos as $a): $as = asistencia_alumno((int)$a['id']); $ep = $ver_pagos ? estado_pagos((int)$a['id']) : null; ?>
    <tr><td><a class="eq-celda" href="admin.php?p=alumno&amp;id=<?= (int)$a['id'] ?>"><?= foto_alumno($a, 'foto') ?> <b><?= e(nombre_alumno($a)) ?></b></a></td>
      <td><?= e(sub_de($a['fecha_nac'])) ?></td><td><?= e($a['grupo'] ?? '—') ?></td><td><small><?= e($a['representante_nombre']) ?><br><?= e($a['representante_telefono']) ?></small></td>
      <td class="num"><?= $as['pct'] !== null ? $as['pct'] . '%' : '—' ?></td>
      <?php if ($ver_pagos): ?><td><?= chip_pago($ep['estado']) ?><?= $ep['deuda'] > 0 ? ' <small>' . dinero($ep['deuda']) . '</small>' : '' ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
  </table></div>
<?php endif; ?>

<?php if ($ed): ?>
<div class="tarjeta" id="nuevo" style="margin-top:16px">
  <h2 style="margin-top:0">+ Nuevo alumno</h2>
  <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="nuevo">
    <?php $a = []; require APP_DIR . '/admin/esc_form_alumno.php'; ?>
    <?php if ((float)$liga['esc_matricula'] > 0): ?><label class="check"><input type="checkbox" name="cobrar_matricula" value="1" checked> Cobrar matrícula (<?= dinero($liga['esc_matricula']) ?>)</label><?php endif; ?>
    <label class="check" style="font-weight:400"><input type="checkbox" name="autorizacion" value="1" required> Tengo la autorización del representante (o del alumno mayor de edad) para registrar sus datos, fotos y medidas, según la Ley de Protección de Datos Personales, incluido su almacenamiento en servidores fuera del Ecuador.</label>
    <button class="btn" style="margin-top:8px">Inscribir alumno</button>
  </form>
</div>
<?php endif;
pie_admin();
