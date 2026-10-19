<?php
/*
 * (v11) PORTAL DE LA FAMILIA (representante) de una escuela
 *   familia.php?c=CODIGO → crea su clave (la escuela le envía el código por WhatsApp)
 *   familia.php          → entra con su cédula y su clave; ve a todos sus hijos: carnet, asistencia, pagos (y sube el comprobante),
 *                          medidas, tests, evaluación técnica y libreta.
 *   (v17) familia.php?h=ID&v=diario|desarrollo|partidos → diario de entrenamientos (qué hizo en cada clase, nota del profesor y fotos),
 *                          desarrollo con gráficos (asistencia, minutos, tests contra su categoría, técnica, crecimiento, insignias y metas)
 *                          y partidos (si la escuela activó las divisiones formativas).
 */
require __DIR__ . '/../app/bootstrap.php';
define('VISTA_PUBLICA', true);
csrf_verificar();

$fam = !empty($_SESSION['familia']) ? fila('SELECT * FROM esc_familias WHERE id = ?', [(int)$_SESSION['familia']]) : null;
$liga = $fam ? fila('SELECT * FROM ligas WHERE id = ?', [$fam['liga_id']]) : null;
cortar_liga_publica($liga, false, true); // (Anexo A) en la papelera → 404; pausada → «temporalmente no disponible»
exigir_modulo_portal($liga, 'esc_familias'); // (v12) módulo del plan
if ($liga) contexto_auditoria(['usuario' => 'Familia C.I. ' . $fam['cedula'], 'rol' => 'familia', 'liga_id' => (int)$liga['id'], 'origen' => 'familia']);

if (es_post()) {
    $accion = post('accion');
    try {
        if ($accion === 'crear_clave') {
            $f = fila("SELECT * FROM esc_familias WHERE codigo = ? AND codigo <> ''", [strtoupper(post('c'))]) ?? throw new RuntimeException('El código ya se usó o no es válido. Pida uno nuevo a la ' . (es_academia() ? 'academia.' : 'escuela.'));
            if (!preg_match('/^\d{4,6}$/', post('pin')) || post('pin') !== post('pin2')) throw new RuntimeException('La clave debe tener de 4 a 6 números y repetirse igual.');
            q("UPDATE esc_familias SET pin = ?, codigo = '', ultimo = ? WHERE id = ?", [password_hash(post('pin'), PASSWORD_DEFAULT), date('Y-m-d H:i'), $f['id']]);
            session_regenerate_id(true);
            $_SESSION['familia'] = (int)$f['id'];
            flash('¡Listo! Desde ahora entra con su cédula y esta clave.');
            ir('familia.php');
        }
        if ($accion === 'ingresar') {
            if (demasiados_intentos('familia', 10, '-15 minutes')) throw new RuntimeException('Demasiados intentos. Espere 15 minutos.');
            $ced = strtoupper(preg_replace('/\s+/', '', post('cedula')));
            $ok = null;
            foreach (filas("SELECT * FROM esc_familias WHERE cedula = ? AND pin <> ''", [$ced]) as $f) if (password_verify(post('pin'), $f['pin'])) { $ok = $f; break; }
            if (!$ok) { anotar_intento('familia'); usleep(800000); throw new RuntimeException('Cédula o clave incorrecta. Si no tiene clave, pida a la ' . (es_academia() ? 'academia' : 'escuela') . ' su código de acceso.'); }
            session_regenerate_id(true);
            $_SESSION['familia'] = (int)$ok['id'];
            q('UPDATE esc_familias SET ultimo = ? WHERE id = ?', [date('Y-m-d H:i'), $ok['id']]);
            ir('familia.php');
        }
        if ($accion === 'salir') { unset($_SESSION['familia']); ir('familia.php'); }
        // (TuAcademia, 14-oct) Dejar de recibir los avisos por Telegram (se desconectan todos los Telegram de la familia y el enlace cambia)
        if ($accion === 'tg_desconectar' && $fam) {
            q('DELETE FROM acad_tg_chats WHERE familia_id = ?', [$fam['id']]);
            q("UPDATE esc_familias SET tg_codigo = '' WHERE id = ?", [$fam['id']]);
            flash('Listo: ya no recibirá avisos por Telegram. Puede volver a conectarlo cuando quiera.');
            ir('familia.php#telegram');
        }
        if ($accion === 'pagar_tarjeta' && $fam) {
            // (v12) La familia paga con tarjeta las mensualidades marcadas (TuAcademia cobra y transfiere a la escuela)
            if (!org_cobra_con_tarjeta($liga)) throw new RuntimeException((es_academia() ? 'La academia' : 'La escuela') . ' no recibe pagos con tarjeta por ahora.');
            $ids = array_map('intval', (array)($_POST['cargos'] ?? []));
            $cs = $ids ? filas("SELECT c.*, a.nombres, a.apellidos FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE c.id IN (" . implode(',', $ids) . ") AND a.liga_id = ? AND a.representante_cedula = ? AND c.estado = 'pendiente'", [$fam['liga_id'], $fam['cedula']]) : [];
            if (!$cs) throw new RuntimeException('Marque los valores que va a pagar.');
            $total = array_sum(array_map(fn($c) => (float)$c['monto'] - (float)$c['descuento'], $cs));
            $rep = fila('SELECT representante_nombre, representante_email, representante_telefono FROM esc_alumnos WHERE liga_id = ? AND representante_cedula = ? LIMIT 1', [$fam['liga_id'], $fam['cedula']]);
            $token = crear_pago_linea($liga, [
                'destino' => 'organizacion', 'concepto' => 'escuela', 'detalle' => implode(' · ', array_map(fn($c) => $c['descripcion'] . ' (' . $c['nombres'] . ')', $cs)), 'monto_base' => $total,
                'pagador_nombre' => (string)($rep['representante_nombre'] ?? ''), 'pagador_email' => (string)($rep['representante_email'] ?? ''), 'pagador_cedula' => $fam['cedula'],
                'pagador_telefono' => (string)($rep['representante_telefono'] ?? ''), 'datos' => ['cargos' => array_map('intval', array_column($cs, 'id')), 'volver' => 'familia.php'],
            ]);
            ir(url_pago($token));
        }
        // (TuAcademia) Un comprobante (DeUna o transferencia) para uno o varios valores
        if ($accion === 'comprobante_varios' && $fam) {
            acad_subir_comprobante_familia($liga, $fam, (array)($_POST['cargos'] ?? []), post('forma'), post('referencia'));
            flash(post('forma') === 'deuna' ? 'Recibimos su pago por DeUna. La academia lo revisará y le emitirá el recibo.' : 'Comprobante enviado. La academia lo revisará y le emitirá el recibo.');
            ir('familia.php#pagar');
        }
        // (TuAcademia) Pago con tarjeta por Payphone: se prepara la transacción y se muestra la cajita de Payphone
        if ($accion === 'payphone' && $fam) {
            $_SESSION['payphone_caja'] = acad_payphone_preparar($liga, $fam, (array)($_POST['cargos'] ?? []));
            ir('familia.php?pagar=tarjeta#pagar');
        }
        if ($accion === 'comprobante' && $fam) {
            $c = fila("SELECT c.* FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE c.id = ? AND a.liga_id = ? AND a.representante_cedula = ? AND c.estado = 'pendiente'", [(int)post('cargo_id'), $fam['liga_id'], $fam['cedula']])
                ?? throw new RuntimeException('Ese valor ya no está pendiente.');
            $ruta = subir_comprobante('foto');
            q("UPDATE esc_cargos SET comprobante = ?, comprobante_estado = 'pendiente', comprobante_forma = ?, comprobante_fecha = ? WHERE id = ?", [$ruta, post('forma') === 'deuna' ? 'deuna' : 'transferencia', date('Y-m-d H:i'), $c['id']]);
            aviso_admins($liga, '📤 <b>Comprobante de pago</b> de una familia: ' . e($c['descripcion']) . ' · ' . dinero($c['monto'] - $c['descuento']), 'escomp:' . (int)$c['id']);
            flash('Comprobante enviado. La ' . (es_academia() ? 'academia' : 'escuela') . ' lo revisará y le emitirá el recibo.');
            ir('familia.php#pagos');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
        ir('familia.php' . (post('c') !== '' ? '?c=' . rawurlencode(post('c')) : ''));
    }
}

// Libreta y recibo (solo de sus hijos)
if ($fam && get('libreta') !== '' && modulo_activo($liga, 'esc_evaluaciones')) { // (Anexo B) la libreta es de «Evaluaciones»
    $a = fila('SELECT a.*, g.nombre AS grupo FROM esc_alumnos a LEFT JOIN esc_grupos g ON g.id = a.grupo_id WHERE a.id = ? AND a.liga_id = ? AND a.representante_cedula = ?', [(int)get('libreta'), $fam['liga_id'], $fam['cedula']]);
    if ($a) { doc_libreta_alumno($a, $liga, preg_match('/^\d{4}-T[1-4]$/', get('per')) ? get('per') : periodo_libreta(), 'familia.php'); exit; }
}
if ($fam && get('recibo') !== '') {
    $propio = fila('SELECT c.id FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE c.recibo = ? AND c.liga_id = ? AND a.representante_cedula = ?', [(int)get('recibo'), $fam['liga_id'], $fam['cedula']]);
    if ($propio) { doc_recibo_escuela($liga, (int)get('recibo'), 'familia.php'); exit; }
}

cabecera_publica($liga ? 'Familia · ' . $liga['nombre'] : 'Portal de padres', $liga);
foreach (tomar_flash() as [$tipo, $msg]) echo '<div class="aviso aviso-' . e($tipo) . '">' . e($msg) . '</div>';

if (!$fam):
    $cod = strtoupper(get('c'));
    $fc = $cod !== '' ? fila("SELECT f.*, l.nombre AS escuela FROM esc_familias f JOIN ligas l ON l.id = f.liga_id WHERE f.codigo = ? AND f.codigo <> ''", [$cod]) : null; ?>
  <div class="tarjeta login">
    <?php if ($fc): ?>
      <h1>👨‍👩‍👦 <?= e($fc['escuela']) ?></h1>
      <p>Cree su clave de <b>4 a 6 números</b>. Después entrará con su cédula y esta clave.</p>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="crear_clave"><input type="hidden" name="c" value="<?= e($cod) ?>">
        <p><label>Clave</label><input type="password" name="pin" required pattern="\d{4,6}" inputmode="numeric" maxlength="6"></p>
        <p><label>Repita la clave</label><input type="password" name="pin2" required pattern="\d{4,6}" inputmode="numeric" maxlength="6"></p>
        <button class="btn btn-grande">Crear mi clave</button></form>
    <?php else: ?>
      <h1>👨‍👩‍👦 Portal de padres</h1>
      <p class="suave">Asistencia, pagos, evaluaciones y carnet de sus hijos.</p>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="ingresar">
        <p><label>Su cédula (representante)</label><input type="text" name="cedula" required inputmode="numeric" maxlength="20"></p>
        <p><label>Clave</label><input type="password" name="pin" required inputmode="numeric" maxlength="6"></p>
        <button class="btn btn-grande">Entrar</button></form>
      <p class="suave" style="font-size:.85rem">¿Primera vez? Pida a la <?= es_academia() ? 'academia' : 'escuela' ?> su código de acceso.</p>
    <?php endif; ?>
  </div>
<?php pie_publico(); exit; endif;

generar_mensualidades($liga);
$hijos = filas("SELECT a.*, g.nombre AS grupo, g.dias, g.hora_inicio, g.hora_fin, g.cancha FROM esc_alumnos a LEFT JOIN esc_grupos g ON g.id = a.grupo_id WHERE a.liga_id = ? AND a.representante_cedula = ? AND a.estado IN ('activo','pendiente') ORDER BY a.nombres", [$fam['liga_id'], $fam['cedula']]);
$habs = filas('SELECT * FROM esc_habilidades WHERE liga_id = ? AND activo = 1 ORDER BY orden, id', [$liga['id']]);
// (v12) Pago con tarjeta de todo lo pendiente de sus hijos
$pend_todos = org_cobra_con_tarjeta($liga) ? filas("SELECT c.*, a.nombres FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE a.liga_id = ? AND a.representante_cedula = ? AND c.estado = 'pendiente' ORDER BY c.vence", [$liga['id'], $fam['cedula']]) : [];
?>
<div class="fila-titulo"><h1 style="margin:.2rem 0">👨‍👩‍👦 <?= e($liga['nombre']) ?></h1>
  <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="salir"><button class="btn btn-sec btn-chico">Salir</button></form></div>
<?php if ($pend_todos): $tot_p = array_sum(array_map(fn($c) => (float)$c['monto'] - (float)$c['descuento'], $pend_todos)); ?>
  <div class="tarjeta" style="border:2px solid var(--acento)">
    <h2 style="margin-top:0">💳 Pagar con tarjeta</h2>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="pagar_tarjeta">
      <?php foreach ($pend_todos as $c): ?><label class="check" style="font-weight:400"><input type="checkbox" name="cargos[]" value="<?= (int)$c['id'] ?>" checked> <?= e($c['nombres']) ?> · <?= e($c['descripcion']) ?> · <b><?= dinero((float)$c['monto'] - (float)$c['descuento']) ?></b><?= $c['vence'] < date('Y-m-d') ? ' <span class="semaforo s-rojo">vencido</span>' : '' ?></label><?php endforeach; ?>
      <button class="btn btn-grande" style="margin-top:8px">Pagar con tarjeta</button></form>
    <?= aviso_comision_cliente($liga, $tot_p) ?>
  </div>
<?php endif; ?>
<?php if (!$hijos): ?><div class="tarjeta">No encontramos alumnos a su nombre. Comuníquese con la <?= es_academia() ? 'academia' : 'escuela' ?>.</div><?php endif; ?>
<?= html_pagar_familia($liga, $fam) // (TuAcademia) pagar por DeUna, transferencia o tarjeta (Payphone) ?>
<?= get('h') === '' ? acad_tg_tarjeta_familia($liga, $fam) : '' // (TuAcademia, 14-oct) conectar el Telegram de la familia ?>
<?php
// (v17) Vista de un hijo: diario de entrenamientos, desarrollo con gráficos o partidos
$hv = (int)get('h');
$vista = in_array(get('v'), ['diario', 'desarrollo', 'partidos'], true) ? get('v') : '';
if (($vista === 'diario' && !modulo_activo($liga, 'esc_entrenamientos')) || ($vista === 'desarrollo' && !modulo_activo($liga, 'esc_evaluaciones'))) $vista = ''; // (Anexo B)
$hijo = $hv && $vista ? (array_values(array_filter($hijos, fn($x) => (int)$x['id'] === $hv))[0] ?? null) : null;
$con_club = formativas_activas($liga);
$pest_hijo = function (array $a, string $v) use ($con_club): string {
    $t = ['diario' => '📖 Entrenamientos', 'desarrollo' => '📈 Desarrollo'] + ($con_club ? ['partidos' => '🏟 Partidos'] : []);
    $h = '<nav class="pestanas"><a href="familia.php">← Inicio</a>';
    foreach ($t as $k => $x) $h .= '<a class="' . ($v === $k ? 'activa' : '') . '" href="familia.php?h=' . (int)$a['id'] . '&amp;v=' . $k . '">' . $x . '</a>';
    return $h . '</nav>';
};
if ($hijo):
    $a = $hijo; $aid = (int)$a['id']; ?>
  <div class="perfil tarjeta"><?= foto_alumno($a, 'foto-grande') ?><div><h2 style="margin:0"><?= e(nombre_alumno($a)) ?></h2><p style="margin:.3rem 0"><?= e(edad_o_categoria($a, $liga)) ?><?= ($a['grupo'] ?? '') !== '' && !str_starts_with((string)$a['grupo'], sub_de($a['fecha_nac'])) ? ' · ' . e($a['grupo']) : '' ?><?= $a['grupo'] ? ' · ' . e(texto_horario($a)) : '' ?></p><?= html_deportes_alumno($a, $liga) ?></div></div>
  <?= $pest_hijo($a, $vista) ?>
  <?php if ($vista === 'diario'):
      $pag = max(0, (int)get('pag'));
      $items = diario_alumno($aid, 15, $pag * 15, true);
      $prox = proximas_clases_alumno($a, 3, true); ?>
    <?php if ($prox && !$pag): ?><h3>🗓 Próximas clases</h3><div class="rejilla-3"><?php foreach ($prox as $pc): $pe = ejercicios_sesion((int)$pc['id']); ?>
      <div class="tarjeta"><b style="text-transform:capitalize"><?= e(dia_corto($pc['fecha'])) ?></b> · <?= e($pc['objetivo'] ?: 'Entrenamiento') ?><br><small><?= e(icono_deporte_escuela(deporte_grupo($pc + ['liga_id' => $a['liga_id']])) . ' ' . ($pc['grupo'] ?? '')) ?></small><?php if ($pe): ?><br><small class="suave"><?= e(implode(' · ', array_column($pe, 'nombre'))) ?></small><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?>
    <h3>📖 Lo que entrenó <?= e($a['nombres']) ?></h3>
    <?= html_diario($items, true) ?>
    <div class="acciones" style="margin-top:10px"><?php if ($pag): ?><a class="btn btn-sec btn-chico" href="familia.php?h=<?= $aid ?>&amp;v=diario&amp;pag=<?= $pag - 1 ?>">← Más recientes</a><?php endif; ?>
      <?php if (count($items) === 15): ?><a class="btn btn-sec btn-chico" href="familia.php?h=<?= $aid ?>&amp;v=diario&amp;pag=<?= $pag + 1 ?>">Clases anteriores →</a><?php endif; ?></div>
  <?php elseif ($vista === 'desarrollo'): ?>
    <?php foreach (jornadas_nuevas_alumno($aid) as $jn): ?><div class="aviso aviso-info">🆕 <b>Nuevos resultados:</b> <?= e($jn['nombre']) ?> (<?= e(fecha_corta($jn['fecha'])) ?>).</div><?php endforeach; ?>
    <?= panel_desarrollo($a, $liga, true) ?><?= html_cinturon_alumno($a, $liga) ?><?= html_niveles_alumno($a, $liga) ?><?= html_competencias_alumno($a) ?>
    <p><a class="btn btn-sec" target="_blank" href="familia.php?libreta=<?= $aid ?>">📘 Informe completo para imprimir o guardar en PDF</a></p>
  <?php elseif ($vista === 'partidos' && $con_club):
      $rp = resumen_partidos_alumno($aid, date('Y-01-01'));
      $et_f = etiquetas_partido(familia_deporte(deporte_grupo($a['grupo_id'] ? fila('SELECT * FROM esc_grupos WHERE id = ?', [(int)$a['grupo_id']]) : null, $liga))); $pts_f = $et_f['g'] === 'Pts';
      $ps = filas('SELECT p.*, pj.titular, pj.minutos, pj.goles, pj.asistencias FROM esc_partido_jugadores pj JOIN esc_partidos p ON p.id = pj.partido_id WHERE pj.alumno_id = ? AND pj.convocado = 1 ORDER BY p.fecha DESC LIMIT 30', [$aid]); ?>
    <div class="cifras"><div class="cifra"><b><?= (int)$rp['pj'] ?></b>Partidos jugados <?= date('Y') ?></div><div class="cifra"><b><?= number_format((int)$rp['min'], 0, ',', '.') ?></b>Minutos</div><div class="cifra"><b><?= (int)$rp['tit'] ?></b>Titular</div><div class="cifra"><b><?= (int)$rp['goles'] ?></b><?= $pts_f ? 'Puntos' : 'Goles' ?></div><div class="cifra"><b><?= (int)$rp['asis'] ?></b>Asistencias</div></div>
    <?php $jug = array_reverse(array_values(array_filter($ps, fn($p) => $p['goles_favor'] !== null))); if ($jug): ?>
    <div class="tarjeta"><h3 style="margin-top:0">⏱ Minutos por partido</h3><?= svg_barras(array_map(fn($p) => [date('d/m', strtotime($p['fecha'])), (int)$p['minutos'], (int)$p['minutos'] . "'", (int)$p['titular'] ? 'var(--acento)' : '#8a9591'], array_slice($jug, -12)), ['max' => 70]) ?></div><?php endif; ?>
    <div class="tarjeta"><?php if (!$ps): ?><p class="suave">Todavía no hay partidos.</p><?php endif; ?><table><?php foreach ($ps as $p): ?><tr><td><?= e(fecha_corta($p['fecha'])) ?><?= $p['hora'] ? ' ' . e($p['hora']) : '' ?></td><td>vs <b><?= e($p['rival']) ?></b><br><small class="suave"><?= e($p['competencia']) ?> · <?= $p['condicion'] === 'local' ? 'Local' : 'Visitante' ?><?= $p['lugar'] ? ' · ' . e($p['lugar']) : '' ?></small></td>
      <td><?= e(texto_resultado_partido($p)) ?></td><td class="num"><?= $p['goles_favor'] !== null ? (int)$p['minutos'] . "'" . ((int)$p['titular'] ? ' (titular)' : '') : '<span class="estado-chip chip-pend">Convocado</span>' ?></td><td><?= $pts_f ? ((int)$p['goles'] ? (int)$p['goles'] . ' pts' : '') : str_repeat('⚽', min(5, (int)$p['goles'])) ?></td></tr><?php endforeach; ?></table></div>
  <?php endif; ?>
<?php pie_publico(); exit; endif; ?>
<?php if (modulo_activo($liga, 'videos')) foreach ($hijos as $a) echo html_videos_portal('alumno', (int)$a['id'], nombre_alumno($a)); // (v28) ?>
<?php foreach ($hijos as $a): $as = asistencia_alumno((int)$a['id']); $ep = estado_pagos((int)$a['id']);
    $pend = filas("SELECT * FROM esc_cargos WHERE alumno_id = ? AND estado = 'pendiente' ORDER BY vence", [$a['id']]);
    $pagados = filas("SELECT recibo, MAX(pagado_en) AS fecha, SUM(monto - descuento) AS total FROM esc_cargos WHERE alumno_id = ? AND estado = 'pagado' AND recibo IS NOT NULL GROUP BY recibo ORDER BY recibo DESC LIMIT 6", [$a['id']]);
    $ins = $a['estado'] === 'activo' ? insignias_alumno($a, $liga) : [];
    $ult = $a['estado'] === 'activo' ? diario_alumno((int)$a['id'], 1, 0, true) : [];
    $nuevas = modulo_activo($liga, 'esc_evaluaciones') ? jornadas_nuevas_alumno((int)$a['id']) : []; // (Anexo B) ?>
  <div class="tarjeta">
    <div class="perfil"><?= foto_alumno($a, 'foto-grande') ?><div>
      <h2 style="margin:0"><?= e(nombre_alumno($a)) ?> <?= $a['estado'] === 'pendiente' ? '<span class="estado-chip chip-pend">Inscripción en revisión</span>' : '' ?></h2>
      <p style="margin:.3rem 0"><?= e(edad_o_categoria($a, $liga)) ?><?= ($a['grupo'] ?? '') !== '' && !str_starts_with((string)$a['grupo'], sub_de($a['fecha_nac'])) ? ' · ' . e($a['grupo']) : '' ?><?= $a['grupo'] ? ' · ' . e(texto_horario($a)) : '' ?></p><?= html_deportes_alumno($a, $liga) ?>
      <p style="margin:.3rem 0">Asistencia (90 días): <b><?= $as['pct'] !== null ? $as['pct'] . '%' : '—' ?></b> · Pagos: <?= chip_pago($ep['estado']) ?><?= $ins ? ' · 🏅 ' . count($ins) . ' insignia(s)' : '' ?></p>
      <?php if ($a['estado'] === 'activo'): ?><div class="acciones">
        <?php if (modulo_activo($liga, 'esc_entrenamientos')): // (Anexo B) ?><a class="btn btn-chico" href="familia.php?h=<?= (int)$a['id'] ?>&amp;v=diario">📖 Entrenamientos</a><?php endif; ?>
        <?php if (modulo_activo($liga, 'esc_evaluaciones')): ?><a class="btn btn-chico" href="familia.php?h=<?= (int)$a['id'] ?>&amp;v=desarrollo">📈 Desarrollo<?= $nuevas ? ' 🆕' : '' ?></a><?php endif; ?>
        <?php if ($con_club): ?><a class="btn btn-chico" href="familia.php?h=<?= (int)$a['id'] ?>&amp;v=partidos">🏟 Partidos</a><?php endif; ?>
        <?php if (modulo_activo($liga, 'esc_evaluaciones')): ?><a class="btn btn-sec btn-chico" target="_blank" href="familia.php?libreta=<?= (int)$a['id'] ?>">📘 Informe</a><?php endif; ?><?php if (modulo_activo($liga, 'carnets')): ?><a class="btn btn-sec btn-chico" target="_blank" href="<?= e(url_carnet_alumno($a)) ?>">🪪 Carnet digital</a><?php endif; ?></div><?php endif; ?>
    </div></div>
    <?php foreach ($nuevas as $jn): ?><div class="aviso aviso-info" style="margin-top:10px">🆕 Ya están los resultados de <b><?= e($jn['nombre']) ?></b>. <a href="familia.php?h=<?= (int)$a['id'] ?>&amp;v=desarrollo">Ver con gráficos →</a></div><?php endforeach; ?>
    <?php if ($ins): ?><div style="margin-top:10px"><?= html_insignias(array_slice($ins, 0, 3)) ?></div><?php endif; ?>
    <?php if ($a['estado'] === 'activo'): ?><?= html_eventos_alumno($a) ?><?= html_particulares_familia($a) ?><?php endif; // (TuAcademia) ?>
    <?php if ($ult): ?><h3>📖 Última clase</h3><?= html_diario($ult, true) ?><?php endif; ?>

    <h3 id="pagos">💵 Pagos</h3>
    <?php if (!$pend): ?><p>✓ Al día. ¡Gracias!</p><?php endif; ?>
    <?php foreach ($pend as $c): $venc = $c['vence'] < date('Y-m-d'); ?>
      <div class="polla-fila"><b><?= e($c['descripcion']) ?></b> · <?= dinero($c['monto'] - $c['descuento']) ?> · vence <?= e(date('d/m/Y', strtotime($c['vence']))) ?> <?= $venc ? '<span class="semaforo s-rojo">vencido</span>' : '' ?>
        <?php if ($c['comprobante_estado'] === 'pendiente'): ?><span class="estado-chip chip-pend">Comprobante en revisión</span>
        <?php else: ?><?= $c['comprobante_estado'] === 'rechazado' ? '<span class="estado-chip chip-mal">Comprobante rechazado: envíe otro</span>' : '' ?>
          <form method="post" enctype="multipart/form-data" class="acciones" style="margin-top:6px"><?= csrf_campo() ?><input type="hidden" name="accion" value="comprobante"><input type="hidden" name="cargo_id" value="<?= (int)$c['id'] ?>">
            <?php if (acad_tiene_deuna($liga)): ?><select name="forma" style="max-width:150px"><option value="deuna">📱 DeUna</option><option value="transferencia">🏦 Transferencia</option></select><?php endif; ?>
            <input type="file" name="foto" accept="image/*" required><button class="btn btn-sec btn-chico">📤 Enviar comprobante</button></form>
        <?php endif; ?></div>
    <?php endforeach; ?>
    <?php if ($pend && $liga['datos_pago']): ?><div class="aviso aviso-info"><b>Datos para pagar:</b><br><?= nl2br(e($liga['datos_pago'])) ?></div><?php endif; ?>
    <?php if ($pagados): ?><p class="suave">Recibos: <?php foreach ($pagados as $r): ?><a target="_blank" href="familia.php?recibo=<?= (int)$r['recibo'] ?>">N° <?= str_pad((string)$r['recibo'], 6, '0', STR_PAD_LEFT) ?> (<?= dinero($r['total']) ?>)</a> · <?php endforeach; ?></p><?php endif; ?>
  </div>
<?php endforeach;
pie_publico();
