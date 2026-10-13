<?php
/* (v11) PAGOS DE LA ESCUELA: semáforo de mensualidades, cobros, cargos por grupo, comprobantes de las familias y caja */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$por = usuario()['nombre'];
generar_mensualidades($liga);

// Foto del comprobante subido por la familia (privada)
if (get('foto') !== '') {
    $c = fila('SELECT * FROM esc_cargos WHERE id = ? AND liga_id = ?', [(int)get('foto'), $lid]);
    $abs = $c && $c['comprobante'] !== '' ? RAIZ . '/data/' . $c['comprobante'] : '';
    if (!$c || !is_file($abs) || str_contains($c['comprobante'], '..')) { http_response_code(404); exit('No encontrado'); }
    header('Content-Type: ' . (mime_content_type($abs) ?: 'image/jpeg'));
    readfile($abs);
    exit;
}

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'masivo') {
        $monto = round((float)str_replace(',', '.', post('monto')), 2);
        if ($monto <= 0 || mb_strlen(post('descripcion')) < 3) throw new RuntimeException('Escriba la descripción y el valor.');
        $gid = (int)post('grupo_id');
        $alumnos = $gid ? alumnos_grupo((int)grupo_de_escuela($gid, $lid)['id']) : filas("SELECT * FROM esc_alumnos WHERE liga_id = ? AND estado = 'activo'", [$lid]);
        foreach ($alumnos as $a) crear_cargo_escuela($liga, (int)$a['id'], post('tipo'), post('descripcion'), $monto, post('vence'), $por);
        flash('Cargo "' . post('descripcion') . '" agregado a ' . count($alumnos) . ' alumno(s).');
    } elseif ($accion === 'aprobar_comp') {
        $c = fila("SELECT * FROM esc_cargos WHERE id = ? AND liga_id = ? AND estado = 'pendiente'", [(int)post('id'), $lid]) ?? throw new RuntimeException('Cargo no encontrado.');
        $r = cobrar_cargos($liga, [$c['id']], 'transferencia', $por);
        flash('Comprobante aprobado. Recibo N° ' . str_pad((string)$r, 6, '0', STR_PAD_LEFT) . '.');
    } elseif ($accion === 'rechazar_comp') {
        q("UPDATE esc_cargos SET comprobante_estado = 'rechazado' WHERE id = ? AND liga_id = ?", [(int)post('id'), $lid]);
        flash('Comprobante rechazado. La familia lo verá en su portal.');
    } elseif ($accion === 'gasto') {
        $monto = round((float)str_replace(',', '.', post('monto')), 2);
        if ($monto <= 0) throw new RuntimeException('Escriba el valor del gasto.');
        q("INSERT INTO movimientos (liga_id, tipo, concepto, descripcion, monto, fecha, forma_pago, registrado_por) VALUES (?, 'egreso', ?, ?, ?, ?, ?, ?)", [
            $lid, array_key_exists(post('concepto'), conceptos_egreso()) ? post('concepto') : 'otro', mb_substr(post('descripcion'), 0, 200), $monto,
            post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : date('Y-m-d'), array_key_exists(post('forma_pago'), formas_pago()) ? post('forma_pago') : 'efectivo', $por,
        ]);
        flash('Gasto registrado.');
    } elseif ($accion === 'generar') {
        $n = generar_mensualidades($liga, preg_match('/^\d{4}-\d{2}$/', post('periodo')) ? post('periodo') : date('Y-m'));
        flash($n ? $n . ' mensualidad(es) creada(s).' : 'Todas las mensualidades de ese mes ya estaban creadas.');
    }
    ir('admin.php?p=cobros&ver=' . rawurlencode(get('ver', 'estado')));
}

$ver = in_array(get('ver'), ['estado', 'comprobantes', 'cargos', 'caja'], true) ? get('ver') : 'estado';
$n_comp = (int)valor("SELECT COUNT(*) FROM esc_cargos WHERE liga_id = ? AND estado = 'pendiente' AND comprobante_estado = 'pendiente'", [$lid]);
$grupos = grupos_visibles($lid);
$ed = puede('cobros', true);
cabecera_admin('Pagos de la escuela');
?>
<div class="fila-titulo"><h1>💵 Pagos</h1><?php if (modulo_menu($liga, 'pagos_tarjeta')): ?><a class="btn btn-sec btn-chico" href="admin.php?p=pagos_linea">💳 Cobros con tarjeta</a><?php endif; ?></div>
<nav class="pestanas">
  <a class="<?= $ver === 'estado' ? 'activa' : '' ?>" href="admin.php?p=cobros">Estado de pagos</a>
  <a class="<?= $ver === 'comprobantes' ? 'activa' : '' ?>" href="admin.php?p=cobros&amp;ver=comprobantes">📤 Comprobantes<?= $n_comp ? ' (' . $n_comp . ')' : '' ?></a>
  <a class="<?= $ver === 'cargos' ? 'activa' : '' ?>" href="admin.php?p=cobros&amp;ver=cargos">+ Cargos a un grupo</a>
  <a class="<?= $ver === 'caja' ? 'activa' : '' ?>" href="admin.php?p=cobros&amp;ver=caja">Caja del mes</a>
</nav>

<?php if ($ver === 'estado'):
    $filtro = in_array(get('f'), ['vencido', 'por_vencer', 'al_dia'], true) ? get('f') : '';
    $gid = (int)get('g');
    $alumnos = filas("SELECT a.*, g.nombre AS grupo FROM esc_alumnos a LEFT JOIN esc_grupos g ON g.id = a.grupo_id WHERE a.liga_id = ? AND a.estado = 'activo'" . ($gid ? ' AND ' . sql_en_grupo('a', $gid) : '') . ' ORDER BY a.apellidos, a.nombres', [$lid]);
    $filas_e = [];
    $cuenta = ['al_dia' => 0, 'por_vencer' => 0, 'vencido' => 0];
    foreach ($alumnos as $a) { $ep = estado_pagos((int)$a['id']); $cuenta[$ep['estado']]++; if (!$filtro || $ep['estado'] === $filtro) $filas_e[] = [$a, $ep]; } ?>
  <div class="cifras">
    <a class="cifra" href="admin.php?p=cobros&amp;f=al_dia"><b style="color:#1a9e5c"><?= $cuenta['al_dia'] ?></b>Al día</a>
    <a class="cifra" href="admin.php?p=cobros&amp;f=por_vencer"><b style="color:#b7791f"><?= $cuenta['por_vencer'] ?></b>Por vencer (5 días)</a>
    <a class="cifra" href="admin.php?p=cobros&amp;f=vencido"><b style="color:#d7263d"><?= $cuenta['vencido'] ?></b>Vencidos</a>
  </div>
  <form method="get" class="acciones" style="margin:10px 0"><input type="hidden" name="p" value="cobros"><input type="hidden" name="f" value="<?= e($filtro) ?>">
    <select name="g" onchange="this.form.submit()"><option value="">Todos los grupos</option><?= opciones_grupos($grupos, $gid, false) ?></select>
    <?php if ($filtro): ?><a class="btn btn-sec btn-chico" href="admin.php?p=cobros">Ver todos</a><?php endif; ?></form>
  <div class="tabla-scroll"><table><tr><th>Alumno</th><th>Grupo</th><th>Estado</th><th class="num">Debe</th><th>Próximo vence</th><th></th></tr>
    <?php foreach ($filas_e as [$a, $ep]): $wa = telefono_wa($a['representante_telefono']); ?><tr>
      <td><a href="admin.php?p=alumno&amp;id=<?= (int)$a['id'] ?>&amp;s=pagos"><?= e(nombre_alumno($a)) ?></a></td><td><?= e($a['grupo'] ?? '—') ?></td><td><?= chip_pago($ep['estado']) ?></td>
      <td class="num <?= $ep['deuda'] > 0 ? 'pts' : '' ?>"><?= dinero($ep['deuda']) ?></td><td><?= $ep['proximo'] ? e(date('d/m/Y', strtotime($ep['proximo']))) : '—' ?></td>
      <td class="acciones"><a class="btn btn-sec btn-chico" href="admin.php?p=alumno&amp;id=<?= (int)$a['id'] ?>&amp;s=pagos">Cobrar</a>
        <?php if ($wa && $ep['deuda'] > 0): ?><a class="btn btn-sec btn-chico" target="_blank" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola ' . $a['representante_nombre'] . ', le saludamos de ' . $liga['nombre'] . '. La cuenta de ' . $a['nombres'] . ' tiene ' . dinero($ep['deuda']) . ' pendiente(s)' . ($ep['proximo'] ? ', con vencimiento ' . date('d/m', strtotime($ep['proximo'])) : '') . '.' . ($liga['datos_pago'] ? "\n\nDatos para pagar: " . $liga['datos_pago'] : '') . "\n\nPuede enviar el comprobante desde su portal: " . url_base() . 'familia.php') ?>">📲</a><?php endif; ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php if ($ed): ?>
  <form method="post" class="acciones" style="margin-top:12px"><?= csrf_campo() ?><input type="hidden" name="accion" value="generar">
    <input type="month" name="periodo" value="<?= date('Y-m') ?>"><button class="btn btn-sec btn-chico">Crear mensualidades de ese mes</button>
    <small class="suave">Se crean solas cada mes; use esto solo para adelantar el mes siguiente.</small></form>
  <?php endif; ?>

<?php elseif ($ver === 'comprobantes'):
    $cs = filas("SELECT c.*, a.nombres, a.apellidos, a.representante_nombre FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE c.liga_id = ? AND c.comprobante <> '' ORDER BY (c.comprobante_estado = 'pendiente') DESC, c.id DESC LIMIT 100", [$lid]); ?>
  <p class="suave">Las familias suben aquí la foto de su transferencia desde su portal. Revise que el dinero llegó y apruébelo: se emite el recibo.</p>
  <?php if (!$cs): ?><div class="tarjeta">Todavía no hay comprobantes.</div><?php endif; ?>
  <?php foreach ($cs as $c): ?>
    <div class="tarjeta comprobante"><a target="_blank" href="admin.php?p=cobros&amp;foto=<?= (int)$c['id'] ?>"><img src="admin.php?p=cobros&amp;foto=<?= (int)$c['id'] ?>" alt="Comprobante" loading="lazy"></a>
      <div><h3 style="margin:0"><?= e(nombre_alumno($c)) ?> · <?= dinero($c['monto'] - $c['descuento']) ?> <?= ['pendiente' => '<span class="estado-chip chip-pend">Por revisar</span>', 'aprobado' => '<span class="estado-chip chip-ok">Aprobado</span>', 'rechazado' => '<span class="estado-chip chip-mal">Rechazado</span>'][$c['comprobante_estado']] ?? '' ?></h3>
        <p class="suave" style="margin:.3rem 0"><?= e($c['descripcion']) ?> · envió <?= e($c['representante_nombre']) ?></p>
        <?php if ($c['comprobante_estado'] === 'pendiente' && $c['estado'] === 'pendiente' && $ed): ?>
          <div class="acciones">
            <form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="aprobar_comp"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn">✓ Aprobar y emitir recibo</button></form>
            <form class="inline" method="post" onsubmit="return confirm('¿Rechazar el comprobante?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="rechazar_comp"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sec">Rechazar</button></form>
          </div>
        <?php elseif ($c['recibo']): ?><a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=esc_recibo&amp;n=<?= (int)$c['recibo'] ?>">🖨 Recibo</a><?php endif; ?>
      </div></div>
  <?php endforeach; ?>

<?php elseif ($ver === 'cargos'): ?>
  <?php if ($ed): ?>
  <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="masivo">
    <h2 style="margin-top:0">Cargo para un grupo o para todos</h2>
    <p class="suave" style="margin-top:0">Ej: uniforme, inscripción a un torneo, viaje, seguro. Queda en la cuenta de cada alumno, aparte de la mensualidad.</p>
    <div class="campos">
      <div><label>A quién</label><select name="grupo_id"><option value="">Todos los alumnos activos</option><?= opciones_grupos($grupos, null, false) ?></select></div>
      <div><label>Tipo</label><select name="tipo"><?php foreach (tipos_cargo_escuela() as $k => $v): if ($k === 'mensualidad') continue; ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div><label>Descripción *</label><input type="text" name="descripcion" required maxlength="150" placeholder="Ej: Torneo Copa Verano"></div>
      <div><label>Valor por alumno ($) *</label><input type="number" name="monto" min="0.01" step="0.01" required></div>
      <div><label>Vence</label><input type="date" name="vence" value="<?= date('Y-m-d', strtotime('+7 days')) ?>"></div>
    </div>
    <button class="btn" onclick="return confirm('¿Agregar el cargo?')">Agregar cargo</button>
  </form>
  <?php endif; ?>

<?php else:
    $mes = preg_match('/^\d{4}-\d{2}$/', get('mes')) ? get('mes') : date('Y-m');
    $ing = filas("SELECT tipo, COUNT(*) AS n, SUM(monto - descuento) AS total FROM esc_cargos WHERE liga_id = ? AND estado = 'pagado' AND substr(pagado_en, 1, 7) = ? GROUP BY tipo", [$lid, $mes]);
    $ventas = (float)valor("SELECT COALESCE(SUM(total), 0) FROM esc_ventas WHERE liga_id = ? AND modo = 'contado' AND anulado = 0 AND substr(fecha, 1, 7) = ?", [$lid, $mes]);
    $prof = (float)valor('SELECT COALESCE(SUM(monto), 0) FROM esc_pagos_profesor WHERE liga_id = ? AND anulado = 0 AND substr(fecha, 1, 7) = ?', [$lid, $mes]);
    $gastos = filas("SELECT * FROM movimientos WHERE liga_id = ? AND tipo = 'egreso' AND anulado = 0 AND substr(fecha, 1, 7) = ? ORDER BY fecha", [$lid, $mes]);
    $t_ing = array_sum(array_map(fn($x) => (float)$x['total'], $ing)) + $ventas;
    $t_egr = $prof + array_sum(array_map(fn($x) => (float)$x['monto'], $gastos)); ?>
  <form method="get" class="acciones" style="margin-bottom:10px"><input type="hidden" name="p" value="cobros"><input type="hidden" name="ver" value="caja"><input type="month" name="mes" value="<?= e($mes) ?>" onchange="this.form.submit()"></form>
  <div class="cifras"><div class="cifra"><b><?= dinero($t_ing) ?></b>Ingresos</div><div class="cifra"><b><?= dinero($t_egr) ?></b>Egresos</div><div class="cifra"><b><?= dinero($t_ing - $t_egr) ?></b>Resultado</div></div>
  <div class="rejilla-2">
    <div><h2>Ingresos</h2><table><?php foreach ($ing as $x): ?><tr><td><?= e(tipos_cargo_escuela()[$x['tipo']] ?? $x['tipo']) ?> (<?= (int)$x['n'] ?>)</td><td class="num"><?= dinero($x['total']) ?></td></tr><?php endforeach; ?>
      <tr><td>Tienda (contado)</td><td class="num"><?= dinero($ventas) ?></td></tr></table></div>
    <div><h2>Egresos</h2><table><tr><td>Pagos a profesores</td><td class="num"><?= dinero($prof) ?></td></tr>
      <?php foreach ($gastos as $g): ?><tr><td><?= e(conceptos_egreso()[$g['concepto']] ?? $g['concepto']) ?><?= $g['descripcion'] ? ' · ' . e($g['descripcion']) : '' ?> <small><?= e($g['fecha']) ?></small></td><td class="num"><?= dinero($g['monto']) ?></td></tr><?php endforeach; ?></table></div>
  </div>
  <?php if ($ed): ?>
  <form method="post" class="tarjeta" style="margin-top:12px"><?= csrf_campo() ?><input type="hidden" name="accion" value="gasto">
    <h3 style="margin-top:0">+ Registrar gasto</h3>
    <?php if (modulo_menu($liga, 'contabilidad')): ?><p class="ayuda" style="margin-top:0">Para gastos con factura electrónica, reportes del año y Excel para su contador use <a href="admin.php?p=contabilidad">📒 Contabilidad</a>.</p><?php endif; ?>
    <div class="campos"><div><label>Concepto</label><select name="concepto"><?php foreach (categorias_gasto('escuela') as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div><label>Descripción</label><input type="text" name="descripcion" maxlength="200"></div><div><label>Valor ($)</label><input type="number" name="monto" min="0.01" step="0.01" required></div>
      <div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div><div><label>Forma</label><select name="forma_pago"><?php foreach (formas_pago() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div></div>
    <button class="btn btn-sec">Guardar gasto</button>
  </form>
  <?php endif; ?>
<?php endif;
pie_admin();
