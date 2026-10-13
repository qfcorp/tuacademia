<?php
/*
 * (TuAcademia) 🎹 CLASES PARTICULARES CON PAQUETES DE CLASES
 *  admin.php?p=particulares                → clases de hoy (horario 1 a 1) para marcar asistencia, saldos y vender paquetes
 *  admin.php?p=particulares&a=ID           → un alumno: sus paquetes, su horario 1 a 1 y sus clases
 *  admin.php?p=particulares&v=paquetes     → paquetes que vende la academia (4, 8, 12 clases…)
 */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$por = usuario()['nombre'];
if (!es_escuela($liga)) ir('admin.php');
preparar_paquetes($lid);
$ed = puede('clases', true);
$prof_yo = perfil_actual() === 'profesor' ? profesor_del_usuario(usuario()) : null;
$profes = filas('SELECT * FROM esc_profesores WHERE liga_id = ? AND activo = 1 ORDER BY nombre', [$lid]);
$fecha = get('fecha') !== '' && strtotime(get('fecha')) ? date('Y-m-d', strtotime(get('fecha'))) : date('Y-m-d');
$aid = (int)get('a');
$alumno = $aid ? (fila("SELECT * FROM esc_alumnos WHERE id = ? AND liga_id = ?", [$aid, $lid]) ?? throw new RuntimeException('Alumno no encontrado.')) : null;
$vista = $alumno ? 'alumno' : (get('v') === 'paquetes' ? 'paquetes' : 'hoy');
$volver = $alumno ? 'admin.php?p=particulares&a=' . $aid : 'admin.php?p=particulares' . ($vista === 'paquetes' ? '&v=paquetes' : '&fecha=' . $fecha);
$alumnos = filas("SELECT * FROM esc_alumnos WHERE liga_id = ? AND estado = 'activo' ORDER BY apellidos, nombres", [$lid]);
$opc_al = '';
foreach ($alumnos as $a) $opc_al .= '<option value="' . (int)$a['id'] . '"' . ($alumno && (int)$alumno['id'] === (int)$a['id'] ? ' selected' : '') . '>' . e(nombre_alumno($a)) . ($a['instrumento'] !== '' ? ' · ' . e($a['instrumento']) : '') . '</option>';
$opc_prof = '<option value="">—</option>';
foreach ($profes as $p) $opc_prof .= '<option value="' . (int)$p['id'] . '"' . ($prof_yo && (int)$prof_yo['id'] === (int)$p['id'] ? ' selected' : '') . '>' . e($p['nombre']) . '</option>';

if (es_post()) {
    $acc = post('accion');
    $vender = in_array($acc, ['vender', 'paquete_guardar', 'paquete_anular'], true);
    if ($vender ? !(puede('cobros', true) || puede('alumnos', true)) : !$ed) throw new RuntimeException('Su perfil no puede hacer este cambio.');
    if ($acc === 'marcar') {
        $f = post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : date('Y-m-d');
        if ($f > date('Y-m-d')) throw new RuntimeException('No se puede marcar una clase que todavía no llega.');
        $r = marcar_clase_particular($liga, (int)post('alumno_id'), post('estado'), $f, hora_valida(post('hora')), (int)post('profesor_id') ?: null, post('tema'), $por);
        $al = fila('SELECT nombres FROM esc_alumnos WHERE id = ?', [(int)post('alumno_id')]);
        $txt = strip_tags(estados_clase_particular()[post('estado')][0]) . ': ' . ($al['nombres'] ?? '') . '.';
        if ($r['descontado']) $txt .= ' Se descontó 1 clase del paquete: ' . ($r['saldo'] === 1 ? 'le queda 1.' : 'le quedan ' . $r['saldo'] . '.') . ($r['saldo'] === 0 ? ' ¡Se terminó el paquete! Ofrézcale uno nuevo.' : '');
        elseif ($r['sin_paquete']) $txt .= ' ⚠ No tiene un paquete con clases: véndale uno para que se descuente.';
        flash($txt, $r['sin_paquete'] ? 'error' : 'ok');
    } elseif ($acc === 'borrar_clase') {
        borrar_clase_particular($lid, (int)post('id'));
        flash('Clase borrada (si se había descontado, volvió al paquete).');
    } elseif ($acc === 'vender') {
        $pa = vender_paquete($liga, (int)post('alumno_id'), (int)post('paquete_id'), (int)post('profesor_id') ?: null, $por, post('precio') === '' ? null : (float)str_replace(',', '.', post('precio')));
        $p = fila('SELECT * FROM acad_paquetes_alumno WHERE id = ?', [$pa]);
        flash('Paquete vendido: ' . $p['nombre'] . ' (' . (int)$p['clases_total'] . ' clases).' . ((float)$p['precio'] > 0 ? ' Se cargó ' . dinero($p['precio']) . ' en Pagos.' : ''));
        $volver = 'admin.php?p=particulares&a=' . (int)post('alumno_id');
    } elseif ($acc === 'horario') {
        $al = (int)post('alumno_id');
        if (!fila('SELECT id FROM esc_alumnos WHERE id = ? AND liga_id = ?', [$al, $lid])) throw new RuntimeException('Elija el alumno.');
        $dia = entero(post('dia'), 1, 7);
        $hi = hora_valida(post('hora_inicio'));
        if ($hi === '') throw new RuntimeException('Escriba la hora de la clase.');
        $pid = (int)post('profesor_id') ?: null;
        if ($pid && ($choque = fila('SELECT h.*, a.nombres FROM acad_horario h JOIN esc_alumnos a ON a.id = h.alumno_id WHERE h.liga_id = ? AND h.profesor_id = ? AND h.dia = ? AND h.hora_inicio = ? AND h.activo = 1', [$lid, $pid, $dia, $hi]))) {
            throw new RuntimeException('Ese profesor ya tiene clase ese día a esa hora con ' . $choque['nombres'] . '.');
        }
        q('INSERT INTO acad_horario (liga_id, alumno_id, profesor_id, dia, hora_inicio, hora_fin, lugar, disciplina) VALUES (?,?,?,?,?,?,?,?)', [$lid, $al, $pid, $dia, $hi, hora_valida(post('hora_fin')), mb_substr(trim(post('lugar')), 0, 80), isset(catalogo_deportes()[post('disciplina')]) ? post('disciplina') : '']);
        flash('Horario agregado: ' . dias_semana()[$dia] . ' ' . $hi . '.');
        $volver = 'admin.php?p=particulares&a=' . $al;
    } elseif ($acc === 'quitar_horario') {
        q('UPDATE acad_horario SET activo = 0 WHERE id = ? AND liga_id = ?', [(int)post('id'), $lid]);
        flash('Horario quitado.');
    } elseif ($acc === 'paquete_guardar') {
        $d = [mb_substr(trim(post('nombre')), 0, 80), entero(post('clases'), 1, 100), max(0, round((float)str_replace(',', '.', post('precio')), 2)), entero(post('vigencia_dias'), 0, 730), entero(post('minutos'), 0, 240),
            isset(catalogo_deportes()[post('disciplina')]) ? post('disciplina') : '', post('activo') === '1' ? 1 : 0];
        if ($d[0] === '') throw new RuntimeException('Escriba el nombre del paquete (ej: Paquete de 8 clases).');
        if ((int)post('id')) q('UPDATE acad_paquetes SET nombre = ?, clases = ?, precio = ?, vigencia_dias = ?, minutos = ?, disciplina = ?, activo = ? WHERE id = ? AND liga_id = ?', array_merge($d, [(int)post('id'), $lid]));
        else q('INSERT INTO acad_paquetes (nombre, clases, precio, vigencia_dias, minutos, disciplina, activo, liga_id) VALUES (?,?,?,?,?,?,?,?)', array_merge($d, [$lid]));
        flash('Paquete guardado.');
    } elseif ($acc === 'paquete_anular') {
        $p = fila('SELECT * FROM acad_paquetes_alumno WHERE id = ? AND liga_id = ?', [(int)post('id'), $lid]) ?? throw new RuntimeException('No encontrado.');
        if ($p['cargo_id'] && fila("SELECT id FROM esc_cargos WHERE id = ? AND estado = 'pagado'", [$p['cargo_id']])) throw new RuntimeException('Ese paquete ya está pagado: no se puede anular.');
        if ($p['cargo_id']) q("UPDATE esc_cargos SET estado = 'anulado' WHERE id = ? AND estado = 'pendiente'", [$p['cargo_id']]);
        q("UPDATE acad_paquetes_alumno SET estado = 'anulado' WHERE id = ?", [$p['id']]);
        flash('Paquete anulado.');
    }
    ir($volver);
}

cabecera_admin('Clases particulares');
?>
<h1>🎹 Clases particulares</h1>
<nav class="pestanas">
  <a class="<?= $vista === 'hoy' ? 'activa' : '' ?>" href="admin.php?p=particulares">📅 Clases del día</a>
  <a class="<?= $vista === 'alumno' ? 'activa' : '' ?>" href="admin.php?p=particulares&amp;a=<?= (int)($alumno['id'] ?? ($alumnos[0]['id'] ?? 0)) ?>">👤 Por alumno</a>
  <a class="<?= $vista === 'paquetes' ? 'activa' : '' ?>" href="admin.php?p=particulares&amp;v=paquetes">📦 Paquetes</a>
</nav>

<?php if ($vista === 'hoy'):
    $hor = horario_particular_del_dia($lid, $fecha, $prof_yo ? (int)$prof_yo['id'] : null);
    $hechas = filas('SELECT c.*, a.nombres, a.apellidos FROM acad_clases c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE c.liga_id = ? AND c.fecha = ? ORDER BY c.hora', [$lid, $fecha]);
    $bajos = filas("SELECT p.*, a.nombres, a.apellidos, a.representante_telefono, a.representante_nombre FROM acad_paquetes_alumno p JOIN esc_alumnos a ON a.id = p.alumno_id WHERE p.liga_id = ? AND p.estado = 'activo' AND (p.clases_total - p.clases_usadas) <= 1 ORDER BY (p.clases_total - p.clases_usadas)", [$lid]); ?>
  <form method="get" class="acciones" style="margin-bottom:8px"><input type="hidden" name="p" value="particulares"><input type="date" name="fecha" value="<?= e($fecha) ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()"> <b><?= e(acad_fecha_texto($fecha)) ?></b></form>
  <div class="tarjeta">
    <h2 style="margin-top:0">🗓 Horario 1 a 1 de este día</h2>
    <?php if (!$hor): ?><p class="suave">No hay clases particulares en el horario de este día. Arme el horario de cada alumno en la pestaña <b>Por alumno</b>.</p><?php else: ?>
    <div class="tabla-scroll"><table><tr><th>Hora</th><th>Alumno</th><th>Profesor</th><th>Saldo</th><th>Asistencia</th></tr>
      <?php foreach ($hor as $h): $pq = paquete_vigente((int)$h['alumno_id']); $s = $pq ? (int)$pq['clases_total'] - (int)$pq['clases_usadas'] : 0; ?>
        <tr><td><b><?= e($h['hora_inicio']) ?></b><?= $h['hora_fin'] ? '<br><small class="suave">a ' . e($h['hora_fin']) . '</small>' : '' ?></td>
          <td><a href="admin.php?p=particulares&amp;a=<?= (int)$h['alumno_id'] ?>"><?= e(nombre_alumno($h)) ?></a><?= $h['instrumento'] ? '<br><small class="suave">🎵 ' . e($h['instrumento']) . '</small>' : '' ?></td>
          <td><?= e($h['profesor'] ?? '—') ?><?= $h['lugar'] ? '<br><small class="suave">' . e($h['lugar']) . '</small>' : '' ?></td>
          <td><?= $pq ? '<b>' . $s . '</b> clase' . ($s === 1 ? '' : 's') : '<span class="etiqueta roja">sin paquete</span>' ?></td>
          <td><?php if ($h['marcada']): ?><span class="chip-estado"><?= e(strip_tags(estados_clase_particular()[$h['estado_marcado']][0] ?? '')) ?></span>
            <?php elseif ($ed): ?><div class="acciones"><?php foreach (['asistio' => '✅ Vino', 'falto' => '❌ Faltó', 'aviso' => '📞 Avisó'] as $k => $t): ?>
              <form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="marcar"><input type="hidden" name="alumno_id" value="<?= (int)$h['alumno_id'] ?>"><input type="hidden" name="fecha" value="<?= e($fecha) ?>"><input type="hidden" name="hora" value="<?= e($h['hora_inicio']) ?>"><input type="hidden" name="profesor_id" value="<?= (int)$h['profesor_id'] ?>"><input type="hidden" name="estado" value="<?= $k ?>"><button class="btn <?= $k === 'asistio' ? '' : 'btn-sec' ?> btn-chico"><?= $t ?></button></form>
            <?php endforeach; ?></div><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </table></div><?php endif; ?>
  </div>
  <?php if ($ed): ?>
  <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="marcar">
    <h3 style="margin-top:0">+ Marcar una clase fuera del horario</h3>
    <div class="campos">
      <div><label>Alumno</label><select name="alumno_id" required><option value="">—</option><?= $opc_al ?></select></div>
      <div><label>Fecha</label><input type="date" name="fecha" value="<?= e($fecha) ?>" max="<?= date('Y-m-d') ?>"></div>
      <div><label>Hora</label><input type="time" name="hora" value="<?= date('H:00') ?>"></div>
      <div><label>Profesor</label><select name="profesor_id"><?= $opc_prof ?></select></div>
      <div><label>¿Qué pasó?</label><select name="estado"><?php foreach (estados_clase_particular() as $k => [$t]): ?><option value="<?= $k ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
      <div class="campo-ancho"><label>Qué se trabajó (opcional)</label><input type="text" name="tema" maxlength="200" placeholder="Ej: escala de sol mayor y primera parte de Para Elisa"></div>
    </div>
    <button class="btn btn-sec">Guardar</button>
  </form>
  <?php endif; ?>
  <?php if ($hechas): ?>
  <h2>Clases marcadas este día</h2>
  <div class="tabla-scroll"><table><tr><th>Hora</th><th>Alumno</th><th>Qué pasó</th><th>Descontó</th><th></th></tr>
    <?php foreach ($hechas as $c): [$t, $cl] = estados_clase_particular()[$c['estado']] ?? ['', '']; ?><tr><td><?= e($c['hora']) ?></td><td><?= e(nombre_alumno($c)) ?><?= $c['tema'] ? '<br><small class="suave">' . e($c['tema']) . '</small>' : '' ?></td><td><span class="chip-estado <?= $cl ?>"><?= e($t) ?></span></td><td><?= (int)$c['descuenta'] ? '−1 clase' : '—' ?></td>
      <td><?php if ($ed): ?><form method="post" class="inline" onsubmit="return confirm('¿Borrar esta clase? Si se descontó, vuelve al paquete.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_clase"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sec btn-chico">✕</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <?php if ($bajos): ?>
  <h2>⚠ Paquetes por terminar</h2>
  <div class="tabla-scroll"><table><tr><th>Alumno</th><th>Paquete</th><th>Le quedan</th><th></th></tr>
    <?php foreach ($bajos as $b): $s = (int)$b['clases_total'] - (int)$b['clases_usadas']; $wa = telefono_wa($b['representante_telefono']); ?><tr><td><a href="admin.php?p=particulares&amp;a=<?= (int)$b['alumno_id'] ?>"><?= e(nombre_alumno($b)) ?></a></td><td><?= e($b['nombre']) ?></td><td><b><?= $s ?></b></td>
      <td><?php if ($wa): ?><a class="btn btn-sec btn-chico" target="_blank" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola ' . trim(explode(' ', $b['representante_nombre'])[0] ?? '') . ', le saludamos de ' . $liga['nombre'] . '. A ' . $b['nombres'] . ' le ' . ($s === 1 ? 'queda 1 clase' : 'quedan ' . $s . ' clases') . ' de su ' . mb_strtolower($b['nombre']) . '. ¿Le reservamos el siguiente paquete? ¡Gracias!') ?>">💬 Avisar</a><?php endif; ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>

<?php elseif ($vista === 'alumno'):
    if (!$alumno): ?><div class="tarjeta">Todavía no hay alumnos activos.</div><?php pie_admin(); return; endif;
    $pqs = filas('SELECT p.*, pr.nombre AS profesor, c.estado AS pago FROM acad_paquetes_alumno p LEFT JOIN esc_profesores pr ON pr.id = p.profesor_id LEFT JOIN esc_cargos c ON c.id = p.cargo_id WHERE p.alumno_id = ? ORDER BY p.id DESC', [$aid]);
    $hor = filas('SELECT h.*, pr.nombre AS profesor FROM acad_horario h LEFT JOIN esc_profesores pr ON pr.id = h.profesor_id WHERE h.alumno_id = ? AND h.activo = 1 ORDER BY h.dia, h.hora_inicio', [$aid]);
    $clases = filas('SELECT c.*, pr.nombre AS profesor FROM acad_clases c LEFT JOIN esc_profesores pr ON pr.id = c.profesor_id WHERE c.alumno_id = ? ORDER BY c.fecha DESC, c.hora DESC LIMIT 40', [$aid]);
    $paquetes = filas('SELECT * FROM acad_paquetes WHERE liga_id = ? AND activo = 1 ORDER BY clases', [$lid]); ?>
  <form method="get" class="acciones" style="margin-bottom:8px"><input type="hidden" name="p" value="particulares"><select name="a" onchange="this.form.submit()"><?= $opc_al ?></select></form>
  <div class="cifras">
    <div class="cifra"><b><?= saldo_clases($aid) ?></b>Clases disponibles</div>
    <div class="cifra"><b><?= count($hor) ?></b>Clases por semana</div>
    <div class="cifra"><b><?= (int)valor("SELECT COUNT(*) FROM acad_clases WHERE alumno_id = ? AND estado = 'asistio' AND fecha >= ?", [$aid, date('Y-m-01')]) ?></b>Clases este mes</div>
  </div>
  <div class="rejilla-2">
    <div class="tarjeta">
      <h2 style="margin-top:0">📦 Paquetes</h2>
      <?php if (!$pqs): ?><p class="suave">Todavía no tiene paquetes.</p><?php endif; ?>
      <?php foreach ($pqs as $p): $s = (int)$p['clases_total'] - (int)$p['clases_usadas']; ?>
        <div style="border-bottom:1px solid var(--borde,#e3e8e6);padding:6px 0">
          <b><?= e($p['nombre']) ?></b> · <?= (int)$p['clases_usadas'] ?>/<?= (int)$p['clases_total'] ?> usadas · <?= $p['estado'] === 'activo' ? '<b>' . ($s === 1 ? 'queda 1' : 'quedan ' . $s) . '</b>' : e($p['estado']) ?>
          <br><small class="suave"><?= dinero($p['precio']) ?> · <?= $p['pago'] === 'pagado' ? '✅ pagado' : ($p['cargo_id'] ? '⏳ por pagar' : 'sin cobro') ?><?= $p['vence'] !== '' ? ' · vence ' . e(fecha_corta($p['vence'])) : '' ?><?= $p['profesor'] ? ' · ' . e($p['profesor']) : '' ?></small>
          <?php if ($p['estado'] === 'activo' && (puede('cobros', true) || puede('alumnos', true))): ?><form method="post" class="inline" onsubmit="return confirm('¿Anular este paquete?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="paquete_anular"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sec btn-chico">Anular</button></form><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (puede('cobros', true) || puede('alumnos', true)): ?>
      <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="vender"><input type="hidden" name="alumno_id" value="<?= $aid ?>">
        <h3>+ Vender un paquete</h3>
        <div class="campos">
          <div><label>Paquete</label><select name="paquete_id" required><?php foreach ($paquetes as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['nombre']) ?> · <?= dinero($p['precio']) ?></option><?php endforeach; ?></select></div>
          <div><label>Profesor</label><select name="profesor_id"><?= $opc_prof ?></select></div>
          <div><label>Precio especial ($)</label><input type="number" name="precio" min="0" step="0.01" placeholder="el del paquete"></div>
        </div>
        <button class="btn">Vender y cargar el cobro</button>
      </form>
      <?php endif; ?>
    </div>
    <div class="tarjeta">
      <h2 style="margin-top:0">🗓 Horario 1 a 1</h2>
      <?php if (!$hor): ?><p class="suave">Sin horario fijo.</p><?php endif; ?>
      <?php foreach ($hor as $h): ?><p style="margin:.3rem 0">📅 <b><?= e(dias_semana()[(int)$h['dia']] ?? '') ?> <?= e($h['hora_inicio']) ?></b><?= $h['hora_fin'] ? '–' . e($h['hora_fin']) : '' ?> · <?= e($h['profesor'] ?? 'sin profesor') ?><?= $h['lugar'] ? ' · ' . e($h['lugar']) : '' ?>
        <?php if ($ed): ?><form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="quitar_horario"><input type="hidden" name="id" value="<?= (int)$h['id'] ?>"><button class="btn btn-sec btn-chico">✕</button></form><?php endif; ?></p><?php endforeach; ?>
      <?php if ($ed): ?>
      <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="horario"><input type="hidden" name="alumno_id" value="<?= $aid ?>">
        <div class="campos">
          <div><label>Día</label><select name="dia"><?php foreach (dias_semana() as $k => $d): ?><option value="<?= $k ?>"><?= e($d) ?></option><?php endforeach; ?></select></div>
          <div><label>Desde</label><input type="time" name="hora_inicio" value="16:00" required></div>
          <div><label>Hasta</label><input type="time" name="hora_fin" value="16:45"></div>
          <div><label>Profesor</label><select name="profesor_id"><?= $opc_prof ?></select></div>
          <div><label>Aula</label><input type="text" name="lugar" maxlength="80" placeholder="Ej: Aula 2"></div>
        </div>
        <button class="btn btn-sec">+ Agregar al horario</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <h2>Clases</h2>
  <?php if ($ed): ?>
  <form method="post" class="tarjeta acciones" style="align-items:flex-end"><?= csrf_campo() ?><input type="hidden" name="accion" value="marcar"><input type="hidden" name="alumno_id" value="<?= $aid ?>">
    <div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
    <div><label>Hora</label><input type="time" name="hora" value="<?= e($hor[0]['hora_inicio'] ?? date('H:00')) ?>"></div>
    <div><label>¿Qué pasó?</label><select name="estado"><?php foreach (estados_clase_particular() as $k => [$t]): ?><option value="<?= $k ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div><label>Profesor</label><select name="profesor_id"><?= $opc_prof ?></select></div>
    <div style="flex:1;min-width:200px"><label>Qué se trabajó</label><input type="text" name="tema" maxlength="200"></div>
    <button class="btn">Marcar</button>
  </form>
  <?php endif; ?>
  <?php if (!$clases): ?><p class="suave">Todavía no hay clases marcadas.</p><?php else: ?>
  <div class="tabla-scroll"><table><tr><th>Fecha</th><th>Qué pasó</th><th>Profesor</th><th>Descontó</th><th></th></tr>
    <?php foreach ($clases as $c): [$t, $cl] = estados_clase_particular()[$c['estado']] ?? ['', '']; ?><tr><td><?= e(fecha_corta($c['fecha'])) ?> <?= e($c['hora']) ?></td><td><span class="chip-estado <?= $cl ?>"><?= e($t) ?></span><?= $c['tema'] ? '<br><small class="suave">' . e($c['tema']) . '</small>' : '' ?></td><td><?= e($c['profesor'] ?? '') ?></td><td><?= (int)$c['descuenta'] ? '−1' : '—' ?></td>
      <td><?php if ($ed): ?><form method="post" class="inline" onsubmit="return confirm('¿Borrar esta clase?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_clase"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sec btn-chico">✕</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </table></div><?php endif; ?>

<?php else: /* paquetes */
    $lista = filas('SELECT p.*, (SELECT COUNT(*) FROM acad_paquetes_alumno x WHERE x.paquete_id = p.id) AS vendidos FROM acad_paquetes p WHERE p.liga_id = ? ORDER BY p.activo DESC, p.clases', [$lid]); ?>
  <p class="suave">Los paquetes que vende la academia. Al venderlo a un alumno se carga el cobro en Pagos; cada clase que se marca (asistió, o faltó sin avisar si así lo decide en Parámetros) baja 1 del saldo.</p>
  <?php foreach (array_merge($lista, [['id' => 0, 'nombre' => '', 'clases' => 8, 'precio' => 0, 'vigencia_dias' => 60, 'minutos' => 45, 'disciplina' => '', 'activo' => 1, 'vendidos' => 0]]) as $p): ?>
  <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="paquete_guardar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
    <?php if (!$p['id']): ?><h3 style="margin-top:0">+ Nuevo paquete</h3><?php else: ?><p class="suave" style="margin:0 0 6px">Vendidos: <?= (int)$p['vendidos'] ?></p><?php endif; ?>
    <div class="campos">
      <div class="campo-ancho"><label>Nombre</label><input type="text" name="nombre" maxlength="80" value="<?= e($p['nombre']) ?>" placeholder="Ej: Paquete de 8 clases de piano"></div>
      <div><label>Clases</label><input type="number" name="clases" min="1" max="100" value="<?= (int)$p['clases'] ?>"></div>
      <div><label>Precio ($)</label><input type="number" name="precio" min="0" step="0.01" value="<?= e((string)(float)$p['precio']) ?>"></div>
      <div><label>Vence en (días)</label><input type="number" name="vigencia_dias" min="0" max="730" value="<?= (int)$p['vigencia_dias'] ?>"><div class="ayuda">0 = no vence.</div></div>
      <div><label>Minutos por clase</label><input type="number" name="minutos" min="0" max="240" value="<?= (int)$p['minutos'] ?>"></div>
      <div><label>Disciplina</label><select name="disciplina"><option value="">Cualquiera</option><?= opciones_disciplinas((string)$p['disciplina']) ?></select></div>
      <div><label class="check" style="margin-top:26px"><input type="checkbox" name="activo" value="1" <?= (int)$p['activo'] ? 'checked' : '' ?>> Se vende</label></div>
    </div>
    <?php if ($ed || puede('cobros', true)): ?><button class="btn btn-sec btn-chico">Guardar</button><?php endif; ?>
  </form>
  <?php endforeach; ?>
<?php endif;
pie_admin();
