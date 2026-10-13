<?php
/*
 * (v18) CONTABILIDAD de la liga o de la escuela.
 * Pestañas: Resumen · Gastos · Facturas del SRI (bandeja) · Reporte para el contador.
 * Exporta a Excel (.xlsx real, con números) y a PDF (página para imprimir o guardar como PDF).
 */
require_once APP_DIR . '/excel.php';
$liga = requiere_liga();
$lid = (int)$liga['id'];
$por = usuario()['nombre'];
$escuela = es_escuela($liga);
$tipo_c = tipo_contable($liga);
$cats = categorias_gasto($tipo_c);
$torneos = $escuela ? [] : filas('SELECT id, nombre, temporada FROM torneos WHERE liga_id = ? ORDER BY id DESC', [$lid]);
$ver = in_array(get('ver'), ['resumen', 'gastos', 'sri', 'contador'], true) ? get('ver') : 'resumen';
[$desde, $hasta] = rango_contable();
$tid = null;
if (!$escuela && (int)get('t') > 0) foreach ($torneos as $x) if ((int)$x['id'] === (int)get('t')) $tid = (int)$x['id'];
$nombre_t = '';
foreach ($torneos as $x) if ((int)$x['id'] === $tid) $nombre_t = $x['nombre'] . ' ' . $x['temporada'];
$qs = '&desde=' . $desde . '&hasta=' . $hasta . ($tid ? '&t=' . $tid : '');
$url = 'admin.php?p=contabilidad';

// Archivo de la factura de un gasto (privado)
if (get('archivo') !== '') {
    $m = fila("SELECT * FROM movimientos WHERE id = ? AND liga_id = ? AND tipo = 'egreso'", [(int)get('archivo'), $lid]);
    servir_factura($m['factura_archivo'] ?? '');
}
// XML de una factura recibida
if (get('xml') !== '') {
    $c = fila('SELECT * FROM compras WHERE id = ? AND liga_id = ?', [(int)get('xml'), $lid]);
    if (!$c || $c['xml'] === '') { http_response_code(404); exit('No encontrado'); }
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($c['clave_acceso'] ?: 'comprobante_' . $c['id']) . '.xml"');
    echo $c['xml'];
    exit;
}

// Exportar
if (in_array(get('exportar'), ['xlsx', 'pdf'], true)) {
    $D = datos_contables($liga, $desde, $hasta, $tid);
    if (get('exportar') === 'xlsx') {
        $bin = excel_contable($liga, $D, $desde, $hasta, $nombre_t);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="contabilidad_' . $liga['slug'] . '_' . $desde . '_' . $hasta . '.xlsx"');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    }
    $rep = in_array(get('rep'), ['resumen', 'gastos', 'contador'], true) ? get('rep') : 'resumen';
    $titulos = ['resumen' => 'Resumen contable', 'gastos' => 'Detalle de gastos', 'contador' => 'Compras con IVA (para el contador)'];
    doc_inicio($titulos[$rep] . ' · ' . $liga['nombre'], $url . '&ver=' . ($rep === 'contador' ? 'contador' : $rep) . $qs);
    doc_cabecera_financiera($liga, $titulos[$rep]);
    echo '<p><b>Período:</b> ' . e(fecha_bonita($desde)) . ' al ' . e(fecha_bonita($hasta)) . ($nombre_t !== '' ? ' · <b>Campeonato:</b> ' . e($nombre_t) : '') . ($liga['ruc'] ?? '' ? ' · <b>RUC:</b> ' . e($liga['ruc']) : '') . '</p>';
    if ($rep === 'resumen') { ?>
      <table class="pl"><tr><th>Ingresos</th><th>Gastos</th><th>Resultado</th><th>IVA pagado en compras</th></tr>
        <tr><td style="font-size:16px"><b><?= dinero($D['total_ingresos']) ?></b></td><td style="font-size:16px"><b><?= dinero($D['total_gastos']) ?></b></td><td style="font-size:16px"><b><?= dinero($D['resultado']) ?></b></td><td><?= dinero($D['iva_compras']) ?></td></tr></table>
      <h2>Ingresos por concepto</h2>
      <table class="pl"><tr><th>Concepto</th><th style="width:120px">Valor</th></tr>
        <?php foreach ($D['ingresos'] as $k => $v): ?><tr><td><?= e($k) ?></td><td style="text-align:right"><?= dinero($v) ?></td></tr><?php endforeach; ?>
        <tr><th style="text-align:right">Total</th><th style="text-align:right"><?= dinero($D['total_ingresos']) ?></th></tr></table>
      <h2>Gastos por categoría</h2>
      <table class="pl"><tr><th>Categoría</th><th style="width:120px">Valor</th></tr>
        <?php foreach ($D['gastos'] as $k => $v): ?><tr><td><?= e(nombre_categoria_gasto($k)) ?></td><td style="text-align:right"><?= dinero($v) ?></td></tr><?php endforeach; ?>
        <tr><th style="text-align:right">Total</th><th style="text-align:right"><?= dinero($D['total_gastos']) ?></th></tr></table>
      <h2>Por mes</h2>
      <table class="pl"><tr><th>Mes</th><th>Ingresos</th><th>Gastos</th><th>Resultado</th></tr>
        <?php foreach ($D['meses'] as $m => $x): ?><tr><td><?= e(nombre_mes_corto($m)) ?></td><td style="text-align:right"><?= dinero($x['ingresos']) ?></td><td style="text-align:right"><?= dinero($x['gastos']) ?></td><td style="text-align:right"><?= dinero($x['ingresos'] - $x['gastos']) ?></td></tr><?php endforeach; ?></table>
    <?php } elseif ($rep === 'gastos') { ?>
      <table class="pl"><tr><th>Fecha</th><th>Categoría</th><th>Descripción</th><th>Proveedor</th><th>N° factura</th><th>Valor</th></tr>
        <?php foreach ($D['detalle'] as $g): ?><tr><td><?= e($g['fecha']) ?></td><td><?= e(nombre_categoria_gasto($g['categoria'])) ?></td><td><?= e($g['descripcion']) ?><?= $g['torneo'] !== '' ? ' <small>(' . e($g['torneo']) . ')</small>' : '' ?></td><td><?= e($g['proveedor']) ?></td><td><?= e($g['factura'] ?: 'Sin factura') ?></td><td style="text-align:right"><?= dinero($g['monto']) ?></td></tr><?php endforeach; ?>
        <tr><th colspan="5" style="text-align:right">Total</th><th style="text-align:right"><?= dinero($D['total_gastos']) ?></th></tr></table>
    <?php } else { $sum = [0, 0, 0, 0]; ?>
      <table class="pl"><tr><th>Fecha</th><th>RUC</th><th>Proveedor</th><th>Comprobante</th><th>Base 0 %</th><th>Base IVA</th><th>IVA</th><th>Total</th></tr>
        <?php foreach ($D['compras'] as $c): $s = $c['tipo'] === 'nota_credito' ? -1 : 1; $sum[0] += $s * $c['base_0']; $sum[1] += $s * $c['base_iva']; $sum[2] += $s * $c['iva']; $sum[3] += $s * $c['total']; ?>
          <tr><td><?= e($c['fecha']) ?></td><td><?= e($c['ruc_emisor']) ?></td><td><?= e($c['razon_social']) ?></td><td><?= e(nombre_tipo_compra($c['tipo'])) ?> <?= e($c['numero']) ?></td>
          <td style="text-align:right"><?= dinero($s * $c['base_0']) ?></td><td style="text-align:right"><?= dinero($s * $c['base_iva']) ?></td><td style="text-align:right"><?= dinero($s * $c['iva']) ?></td><td style="text-align:right"><?= dinero($s * $c['total']) ?></td></tr>
        <?php endforeach; ?>
        <tr><th colspan="4" style="text-align:right">Totales</th><?php foreach ($sum as $v): ?><th style="text-align:right"><?= dinero($v) ?></th><?php endforeach; ?></tr></table>
      <p class="pie-doc">Comprobantes electrónicos recibidos e incluidos como gasto. Este reporte ayuda a su contador; no reemplaza las declaraciones al SRI.</p>
    <?php }
    echo '<p class="pie-doc">Generado el ' . date('d/m/Y H:i') . ' por ' . e($por) . ' · ' . e(marca()) . '</p>';
    doc_fin();
    exit;
}

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'gasto') {
        $monto = round((float)str_replace(',', '.', post('monto')), 2);
        if ($monto <= 0) throw new RuntimeException('Escriba el valor del gasto.');
        if (mb_strlen(trim(post('descripcion'))) < 3) throw new RuntimeException('Escriba en qué se gastó.');
        $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('fecha')) ? post('fecha') : date('Y-m-d');
        $t_g = !$escuela && (int)post('torneo_id') ? (int)(fila('SELECT id FROM torneos WHERE id = ? AND liga_id = ?', [(int)post('torneo_id'), $lid])['id'] ?? 0) : 0;
        $arch = subir_factura('archivo');
        q("INSERT INTO movimientos (liga_id, torneo_id, tipo, concepto, descripcion, monto, fecha, forma_pago, proveedor, factura, factura_archivo, registrado_por) VALUES (?,?, 'egreso', ?,?,?,?,?,?,?,?,?)", [
            $lid, $t_g ?: null, isset($cats[post('categoria')]) ? post('categoria') : 'otro', mb_substr(trim(post('descripcion')), 0, 200), $monto, $f,
            array_key_exists(post('forma_pago'), formas_pago()) ? post('forma_pago') : 'efectivo', mb_substr(trim(post('proveedor')), 0, 120),
            post('con_factura') === '1' ? limpiar_numero_factura(post('factura')) : '', post('con_factura') === '1' ? $arch : '', $por]);
        if ($arch !== '' && post('con_factura') !== '1') borrar_factura($arch);
        flash('Gasto registrado: ' . dinero($monto) . '.');
        ir($url . '&ver=gastos' . $qs);
    }
    if ($accion === 'anular_gasto') {
        $m = fila("SELECT * FROM movimientos WHERE id = ? AND liga_id = ? AND tipo = 'egreso' AND anulado = 0", [(int)post('id'), $lid]) ?? throw new RuntimeException('Gasto no encontrado.');
        if (tabla_existe('pagos_arbitro') && fila('SELECT id FROM pagos_arbitro WHERE movimiento_id = ? AND anulado = 0', [$m['id']])) throw new RuntimeException('Es un pago a árbitros: anúlelo en Finanzas → Pago a árbitros.');
        if ($m['compra_id'] && ($c = fila('SELECT * FROM compras WHERE id = ? AND liga_id = ?', [$m['compra_id'], $lid]))) {
            reabrir_compra($c);
            flash('Gasto anulado. La factura volvió a la bandeja del SRI por si quiere incluirla de nuevo.');
        } else {
            q('UPDATE movimientos SET anulado = 1 WHERE id = ?', [$m['id']]);
            flash('Gasto anulado (queda en la bitácora).');
        }
        ir($url . '&ver=gastos' . $qs);
    }
    if ($accion === 'ruc') {
        $ruc = preg_replace('/\D/', '', post('ruc'));
        if ($ruc !== '' && !in_array(strlen($ruc), [10, 13], true)) throw new RuntimeException('El RUC tiene 13 dígitos (o la cédula 10).');
        q('UPDATE ligas SET ruc = ? WHERE id = ?', [$ruc, $lid]);
        flash($ruc !== '' ? 'RUC guardado: se avisará si llega una factura a nombre de otra persona.' : 'RUC borrado.');
        ir($url . '&ver=sri');
    }
    if ($accion === 'importar_xml') {
        $arch = archivos_xml_subidos('xml');
        if (!$arch) throw new RuntimeException('Elija uno o varios archivos XML (o un .zip con los XML).');
        $r = importar_xml_sri($liga, $arch, $por);
        flash(texto_importacion($r) . ($r['errores'] ? ' Con problemas: ' . implode(' | ', array_slice($r['errores'], 0, 5)) : ''), $r['errores'] && !$r['nueva'] && !$r['completada'] ? 'error' : 'ok');
        ir($url . '&ver=sri');
    }
    if ($accion === 'importar_txt') {
        $f = $_FILES['txt'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Elija el archivo .txt que descargó del SRI.');
        if ($f['size'] > 5 * 1024 * 1024) throw new RuntimeException('El archivo pesa más de 5 MB.');
        $r = importar_txt_sri($liga, (string)file_get_contents($f['tmp_name']), $por);
        $n_inc = (int)valor("SELECT COUNT(*) FROM compras WHERE liga_id = ? AND completa = 0 AND estado = 'pendiente'", [$lid]);
        flash(texto_importacion($r) . ($n_inc ? ' Para ver el IVA y el detalle de cada una toque "Completar desde el SRI".' : '') . ($r['errores'] ? ' Avisos: ' . implode(' | ', array_slice($r['errores'], 0, 3)) : ''), $r['errores'] && !$r['nueva'] && !$r['repetida'] ? 'error' : 'ok');
        ir($url . '&ver=sri');
    }
    if ($accion === 'claves') {
        $claves = sri_extraer_claves(post('claves'));
        if (!$claves) throw new RuntimeException('No se encontró ninguna clave de acceso válida (son 49 dígitos).');
        $r = completar_desde_sri($liga, $claves, $por);
        flash(texto_importacion($r) . ($r['errores'] ? ' Con problemas: ' . implode(' | ', array_slice($r['errores'], 0, 4)) : ''), $r['errores'] && !$r['nueva'] && !$r['completada'] ? 'error' : 'ok');
        ir($url . '&ver=sri');
    }
    if ($accion === 'bandeja') {
        $ids = array_values(array_unique(array_map('intval', (array)($_POST['ids'] ?? []))));
        if (!$ids) throw new RuntimeException('Marque al menos una factura.');
        $sel = filas('SELECT * FROM compras WHERE liga_id = ? AND id IN (' . implode(',', $ids) . ')', [$lid]);
        $que = post('que');
        $n = 0;
        if ($que === 'incluir') {
            if (!isset($cats[post('categoria')])) throw new RuntimeException('Elija la categoría del gasto antes de incluir las facturas.');
            $t_g = !$escuela && (int)post('torneo_id') ? (int)(fila('SELECT id FROM torneos WHERE id = ? AND liga_id = ?', [(int)post('torneo_id'), $lid])['id'] ?? 0) : 0;
            foreach ($sel as $c) if ($c['estado'] !== 'incluida') { incluir_compra($liga, $c, post('categoria'), $t_g ?: null, post('forma_pago'), $por); $n++; }
            flash($n . ' factura(s) incluida(s) como gasto en "' . nombre_categoria_gasto(isset($cats[post('categoria')]) ? post('categoria') : 'otro') . '".');
        } elseif ($que === 'excluir') {
            foreach ($sel as $c) if ($c['estado'] === 'pendiente') { q("UPDATE compras SET estado = 'excluida' WHERE id = ?", [$c['id']]); $n++; }
            flash($n . ' factura(s) marcada(s) como "No incluir". Puede devolverlas a la bandeja cuando quiera.');
        } elseif ($que === 'reabrir') {
            foreach ($sel as $c) if ($c['estado'] !== 'pendiente') { reabrir_compra($c); $n++; }
            flash($n . ' factura(s) devuelta(s) a la bandeja' . ($n ? ' (si estaban incluidas, su gasto quedó anulado)' : '') . '.');
        } elseif ($que === 'completar') {
            $r = completar_desde_sri($liga, array_map(fn($c) => (int)$c['id'], array_filter($sel, fn($c) => (int)$c['completa'] === 0)), $por);
            flash(texto_importacion($r) . ($r['errores'] ? ' Con problemas: ' . implode(' | ', array_slice($r['errores'], 0, 4)) : ''), $r['errores'] && !$r['completada'] ? 'error' : 'ok');
        } elseif ($que === 'quitar') {
            foreach ($sel as $c) if ($c['estado'] !== 'incluida') { q('DELETE FROM compras WHERE id = ?', [$c['id']]); $n++; }
            flash($n . ' comprobante(s) quitado(s) de la bandeja.');
        }
        ir($url . '&ver=sri&estado=' . rawurlencode(post('estado_vuelta') ?: 'pendiente'));
    }
    ir($url);
}

$n_pend = (int)valor("SELECT COUNT(*) FROM compras WHERE liga_id = ? AND estado = 'pendiente'", [$lid]);
cabecera_admin('Contabilidad');
$form_filtro = function (string $v) use ($desde, $hasta, $tid, $torneos, $escuela, $qs, $url): void { ?>
  <form method="get" class="acciones filtro-cont" style="margin:10px 0">
    <input type="hidden" name="p" value="contabilidad"><input type="hidden" name="ver" value="<?= e($v) ?>">
    <label>Desde <input type="date" name="desde" value="<?= e($desde) ?>"></label>
    <label>Hasta <input type="date" name="hasta" value="<?= e($hasta) ?>"></label>
    <?php if (!$escuela && $torneos): ?><label>Campeonato <select name="t"><option value="">Todos</option><?php foreach ($torneos as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)$x['id'] === $tid ? 'selected' : '' ?>><?= e($x['nombre'] . ' ' . $x['temporada']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <button class="btn btn-sec btn-chico">Ver</button>
    <span style="flex:1"></span>
    <a class="btn btn-chico" href="<?= e($url . '&exportar=xlsx' . $qs) ?>">⬇ Excel</a>
    <a class="btn btn-sec btn-chico" target="_blank" href="<?= e($url . '&exportar=pdf&rep=' . $v . $qs) ?>">🖨 PDF</a>
  </form>
  <p class="suave" style="margin-top:-4px;font-size:.85rem">Atajos:
    <a href="<?= e($url . '&ver=' . $v . '&desde=' . date('Y-m-01') . '&hasta=' . date('Y-m-d')) ?>">este mes</a> ·
    <a href="<?= e($url . '&ver=' . $v . '&desde=' . date('Y-m-01', strtotime('first day of last month')) . '&hasta=' . date('Y-m-t', strtotime('first day of last month'))) ?>">mes pasado</a> ·
    <a href="<?= e($url . '&ver=' . $v . '&desde=' . date('Y') . '-01-01&hasta=' . date('Y-m-d')) ?>">este año</a> ·
    <a href="<?= e($url . '&ver=' . $v . '&desde=' . (date('Y') - 1) . '-01-01&hasta=' . (date('Y') - 1) . '-12-31') ?>">año pasado</a></p>
<?php };
?>
<div class="fila-titulo"><h1>📒 Contabilidad</h1><?php if (!$escuela): ?><a class="btn btn-sec btn-chico" href="admin.php?p=finanzas">💵 Ir a Finanzas</a><?php else: ?><a class="btn btn-sec btn-chico" href="admin.php?p=cobros">💵 Ir a Pagos</a><?php endif; ?></div>
<nav class="pestanas">
  <a class="<?= $ver === 'resumen' ? 'activa' : '' ?>" href="<?= e($url . $qs) ?>">📊 Resumen</a>
  <a class="<?= $ver === 'gastos' ? 'activa' : '' ?>" href="<?= e($url . '&ver=gastos' . $qs) ?>">🧾 Gastos</a>
  <a class="<?= $ver === 'sri' ? 'activa' : '' ?>" href="<?= e($url . '&ver=sri') ?>">📥 Facturas del SRI<?= $n_pend ? ' <span class="etiqueta roja">' . $n_pend . '</span>' : '' ?></a>
  <a class="<?= $ver === 'contador' ? 'activa' : '' ?>" href="<?= e($url . '&ver=contador' . $qs) ?>">📑 Para el contador</a>
</nav>

<?php if ($ver === 'resumen'):
    $D = datos_contables($liga, $desde, $hasta, $tid);
    $max_mes = max([1, ...array_values(array_map(fn($x) => max($x['ingresos'], $x['gastos']), $D['meses']))]);
    $max_cat = max([1, ...array_values($D['gastos'])]);
    $form_filtro('resumen'); ?>
  <div class="cifras">
    <div class="cifra"><b><?= dinero($D['total_ingresos']) ?></b>Ingresos</div>
    <div class="cifra"><b><?= dinero($D['total_gastos']) ?></b>Gastos</div>
    <div class="cifra"><b style="color:<?= $D['resultado'] < 0 ? '#b3261e' : 'inherit' ?>"><?= dinero($D['resultado']) ?></b>Resultado</div>
    <div class="cifra"><b><?= dinero($D['iva_compras']) ?></b>IVA pagado en compras</div>
  </div>
  <?php if ($n_pend): ?><div class="aviso aviso-info">Tiene <b><?= $n_pend ?></b> factura(s) del SRI por revisar. <a href="<?= e($url . '&ver=sri') ?>">Elegir cuáles incluir →</a></div><?php endif; ?>
  <div class="rejilla-2">
    <div class="tarjeta">
      <h2 style="margin-top:0">Ingresos por concepto</h2>
      <?php if (!$D['ingresos']): ?><p class="suave">No hay ingresos en este período.</p><?php endif; ?>
      <table><?php foreach ($D['ingresos'] as $k => $v): ?><tr><td><?= e($k) ?></td><td class="num"><?= dinero($v) ?></td></tr><?php endforeach; ?>
        <?php if ($D['ingresos']): ?><tr><th>Total</th><th class="num"><?= dinero($D['total_ingresos']) ?></th></tr><?php endif; ?></table>
      <p class="suave" style="font-size:.8rem;margin-bottom:0"><?= $escuela ? 'Mensualidades y cargos cobrados (por la fecha de pago) y ventas de contado de la tienda.' : 'Pagos de los equipos y reservas de cancha registrados en Finanzas.' ?></p>
    </div>
    <div class="tarjeta">
      <h2 style="margin-top:0">Gastos por categoría</h2>
      <?php if (!$D['gastos']): ?><p class="suave">No hay gastos en este período. <a href="<?= e($url . '&ver=gastos') ?>">Registrar un gasto</a></p><?php endif; ?>
      <?php foreach ($D['gastos'] as $k => $v): ?>
        <div style="margin:.35rem 0"><div style="display:flex;justify-content:space-between;gap:8px"><span><?= e(nombre_categoria_gasto($k)) ?></span><b><?= dinero($v) ?></b></div>
          <div class="barra-cont"><span style="width:<?= max(1, round(100 * max(0, $v) / $max_cat)) ?>%"></span></div></div>
      <?php endforeach; ?>
      <?php if ($D['gastos']): ?><p style="margin-bottom:0"><b>Total: <?= dinero($D['total_gastos']) ?></b> · con factura <?= dinero($D['con_factura']) ?> · sin factura <?= dinero($D['total_gastos'] - $D['con_factura']) ?></p><?php endif; ?>
    </div>
  </div>
  <?php if ($D['meses']): ?>
  <div class="tarjeta">
    <h2 style="margin-top:0">Mes a mes</h2>
    <div class="meses-cont">
      <?php foreach ($D['meses'] as $m => $x): ?>
        <div class="mes-cont" title="<?= e(nombre_mes_corto($m)) ?>: ingresos <?= dinero($x['ingresos']) ?>, gastos <?= dinero($x['gastos']) ?>">
          <div class="barras"><span class="ing" style="height:<?= round(100 * $x['ingresos'] / $max_mes) ?>%"></span><span class="gas" style="height:<?= round(100 * $x['gastos'] / $max_mes) ?>%"></span></div>
          <small><?= e(nombre_mes_corto($m)) ?></small>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="suave" style="font-size:.85rem"><span class="punto ing"></span> Ingresos &nbsp; <span class="punto gas"></span> Gastos</p>
    <div class="tabla-scroll"><table>
      <tr><th>Mes</th><th class="num">Ingresos</th><th class="num">Gastos</th><th class="num">Resultado</th></tr>
      <?php foreach ($D['meses'] as $m => $x): $res = $x['ingresos'] - $x['gastos']; ?><tr><td><?= e(nombre_mes_corto($m)) ?></td><td class="num"><?= dinero($x['ingresos']) ?></td><td class="num"><?= dinero($x['gastos']) ?></td><td class="num" style="color:<?= $res < 0 ? '#b3261e' : 'inherit' ?>"><b><?= dinero($res) ?></b></td></tr><?php endforeach; ?>
    </table></div>
  </div>
  <?php endif; ?>

<?php elseif ($ver === 'gastos'):
    $D = datos_contables($liga, $desde, $hasta, $tid);
    $fc = get('cat');
    $ff = get('fac');
    $lista = array_values(array_filter($D['detalle'], fn($g) => ($fc === '' || $g['categoria'] === $fc) && ($ff === '' || ($ff === 'si' ? $g['factura'] !== '' : $g['factura'] === ''))));
    $form_filtro('gastos'); ?>
  <details class="tarjeta" <?= !$D['detalle'] ? 'open' : '' ?>>
    <summary><b>➕ Registrar un gasto</b> <span class="suave">(con o sin factura)</span></summary>
    <form method="post" enctype="multipart/form-data" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="gasto">
      <div class="campos">
        <div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
        <div><label>Categoría</label><select name="categoria"><?php foreach ($cats as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div><label>¿En qué se gastó?</label><input type="text" name="descripcion" maxlength="200" required placeholder="<?= $escuela ? 'Ej: 10 conos y 5 balones' : 'Ej: Alquiler de la cancha, fecha 5' ?>"></div>
        <div><label>Valor ($)</label><input type="text" name="monto" inputmode="decimal" required placeholder="0.00"></div>
        <div><label>Proveedor <small>(opcional)</small></label><input type="text" name="proveedor" maxlength="120"></div>
        <div><label>Forma de pago</label><select name="forma_pago"><?php foreach (formas_pago() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
        <?php if (!$escuela && $torneos): ?><div><label>Campeonato <small>(opcional)</small></label><select name="torneo_id"><option value="0">General de la liga</option><?php foreach ($torneos as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)$x['id'] === $tid ? 'selected' : '' ?>><?= e($x['nombre'] . ' ' . $x['temporada']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      </div>
      <label class="check"><input type="checkbox" name="con_factura" value="1" onchange="document.getElementById('cf-datos').style.display = this.checked ? '' : 'none'"> Tiene factura física (o nota de venta)</label>
      <div class="campos" id="cf-datos" style="display:none">
        <div><label>N° de factura</label><input type="text" name="factura" maxlength="40" placeholder="001-001-000000123"></div>
        <div><label>Foto o PDF de la factura <small>(opcional)</small></label><input type="file" name="archivo" accept="image/*,application/pdf"></div>
      </div>
      <p class="ayuda">¿La factura es electrónica? Mejor impórtela en <a href="<?= e($url . '&ver=sri') ?>">Facturas del SRI</a>: así queda con su IVA y su clave de acceso.</p>
      <button class="btn">Guardar gasto</button>
    </form>
  </details>
  <form method="get" class="acciones" style="margin:10px 0"><input type="hidden" name="p" value="contabilidad"><input type="hidden" name="ver" value="gastos"><input type="hidden" name="desde" value="<?= e($desde) ?>"><input type="hidden" name="hasta" value="<?= e($hasta) ?>"><?php if ($tid): ?><input type="hidden" name="t" value="<?= $tid ?>"><?php endif; ?>
    <select name="cat" onchange="this.form.submit()" aria-label="Categoría"><option value="">Todas las categorías</option><?php foreach ($cats as $k => $v): ?><option value="<?= $k ?>" <?= $fc === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
    <select name="fac" onchange="this.form.submit()" aria-label="Factura"><option value="">Con y sin factura</option><option value="si" <?= $ff === 'si' ? 'selected' : '' ?>>Solo con factura</option><option value="no" <?= $ff === 'no' ? 'selected' : '' ?>>Solo sin factura</option></select>
  </form>
  <?php if (!$lista): ?><p class="suave">No hay gastos con estos filtros.</p><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>Fecha</th><th>Categoría</th><th>Descripción</th><th>Proveedor</th><th>Factura</th><th class="num">Valor</th><th></th></tr>
    <?php foreach ($lista as $g): ?><tr>
      <td><?= e(fecha_bonita($g['fecha'])) ?></td><td><?= e(nombre_categoria_gasto($g['categoria'])) ?></td>
      <td style="white-space:normal"><?= e($g['descripcion']) ?><?= $g['torneo'] !== '' ? '<br><small class="suave">' . e($g['torneo']) . '</small>' : '' ?></td>
      <td><?= e($g['proveedor']) ?></td>
      <td><?php if ($g['factura'] !== ''): ?><?= e($g['factura']) ?><?php if ($g['archivo'] !== ''): ?> <a href="<?= e($url . '&archivo=' . $g['id']) ?>" target="_blank" title="Ver la factura">📎</a><?php endif; ?><?= $g['origen'] === 'SRI' ? ' <span class="etiqueta verde">SRI</span>' : '' ?><?php else: ?><span class="suave">Sin factura</span><?php endif; ?></td>
      <td class="num"><b><?= dinero($g['monto']) ?></b></td>
      <td class="acciones"><?php if ($g['id'] && $g['origen'] !== 'Árbitros' && puede_pagina('contabilidad', true)): ?><form class="inline" method="post" onsubmit="return confirm('¿Anular este gasto?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="anular_gasto"><input type="hidden" name="id" value="<?= $g['id'] ?>"><button class="btn btn-sec btn-chico">Anular</button></form><?php elseif ($g['origen'] !== 'Manual' && $g['origen'] !== 'SRI'): ?><small class="suave"><?= e($g['origen']) ?></small><?php endif; ?></td>
    </tr><?php endforeach; ?>
    <tr><th colspan="5" class="num">Total</th><th class="num"><?= dinero(array_sum(array_column($lista, 'monto'))) ?></th><th></th></tr>
  </table></div>
  <?php endif; ?>
  <p class="suave" style="font-size:.85rem">Aquí salen todos los gastos: los que registra en esta pantalla, los de <?= $escuela ? '<a href="admin.php?p=cobros&amp;ver=caja">Caja del mes</a>, los roles de pago de los profesores' : '<a href="admin.php?p=finanzas&amp;ver=egresos">Finanzas → Gastos</a>, los pagos a árbitros' ?> y las facturas del SRI que incluyó.</p>

<?php elseif ($ver === 'sri'):
    $estado = in_array(get('estado'), ['pendiente', 'incluida', 'excluida'], true) ? get('estado') : 'pendiente';
    $comp = filas('SELECT c.*, m.concepto AS categoria FROM compras c LEFT JOIN movimientos m ON m.id = c.movimiento_id WHERE c.liga_id = ? AND c.estado = ? ORDER BY c.fecha DESC, c.id DESC LIMIT 500', [$lid, $estado]);
    $cuentas = [];
    foreach (filas('SELECT estado, COUNT(*) AS n FROM compras WHERE liga_id = ? GROUP BY estado', [$lid]) as $x) $cuentas[$x['estado']] = (int)$x['n'];
    $ruc_org = ruc_organizacion($liga);
    $n_incompletas = count(array_filter($comp, fn($c) => (int)$c['completa'] === 0)); ?>
  <p class="suave">Traiga aquí las facturas electrónicas que la <?= $escuela ? 'escuela' : 'liga' ?> recibió de sus proveedores y elija cuáles son gastos de la organización. Las que marque <b>Incluir</b> pasan a Gastos con su IVA; las demás quedan como <b>No incluir</b> (por ejemplo, compras personales).</p>
  <div class="tarjeta" id="ruc">
    <form method="post" class="acciones" style="align-items:flex-end"><?= csrf_campo() ?><input type="hidden" name="accion" value="ruc">
      <div><label>RUC o cédula de la <?= $escuela ? 'escuela' : 'liga' ?> (a nombre de quién se factura)</label><input type="text" name="ruc" inputmode="numeric" maxlength="13" value="<?= e($liga['ruc'] ?? '') ?>" placeholder="1790000000001"></div>
      <button class="btn btn-sec btn-chico">Guardar</button>
    </form>
    <?php if ($ruc_org === ''): ?><p class="ayuda" style="margin-bottom:0">Si lo escribe, el sistema le avisa ⚠ cuando una factura esté a nombre de otra persona.</p><?php endif; ?>
  </div>
  <div class="rejilla-3 importar-sri">
    <form class="tarjeta" method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="importar_xml">
      <h3 style="margin-top:0">1. Archivos XML</h3>
      <p class="suave">Los XML que le llegan al correo con cada factura. Puede elegir varios a la vez o un <b>.zip</b>.</p>
      <input type="file" name="xml[]" accept=".xml,.zip,text/xml,application/zip" multiple required>
      <button class="btn" style="margin-top:8px">⬆ Subir XML</button>
    </form>
    <form class="tarjeta" method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="importar_txt">
      <h3 style="margin-top:0">2. Reporte TXT del SRI</h3>
      <p class="suave">En <b>SRI en línea</b> → Facturación electrónica → <b>Comprobantes electrónicos recibidos</b> → elija el mes → Consultar → descargue el listado (.txt).</p>
      <input type="file" name="txt" accept=".txt,.csv,text/plain" required>
      <button class="btn" style="margin-top:8px">⬆ Subir TXT</button>
    </form>
    <form class="tarjeta" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="claves">
      <h3 style="margin-top:0">3. Claves de acceso</h3>
      <p class="suave">Pegue una o varias claves de acceso (49 dígitos); el sistema baja cada factura del SRI.</p>
      <textarea name="claves" rows="3" required placeholder="2909202601179..." style="width:100%"></textarea>
      <button class="btn" style="margin-top:8px">🔎 Buscar en el SRI</button>
    </form>
  </div>

  <nav class="pestanas" style="margin-top:14px">
    <?php foreach (['pendiente' => '📥 Por revisar', 'incluida' => '✅ Incluidas', 'excluida' => '🚫 No incluidas'] as $k => $v): ?><a class="<?= $estado === $k ? 'activa' : '' ?>" href="<?= e($url . '&ver=sri&estado=' . $k) ?>"><?= $v ?> (<?= $cuentas[$k] ?? 0 ?>)</a><?php endforeach; ?>
  </nav>
  <?php if (!$comp): ?><p class="suave"><?= $estado === 'pendiente' ? 'No hay facturas por revisar. Súbalas con una de las 3 opciones de arriba.' : 'No hay facturas aquí.' ?></p><?php else: ?>
  <form method="post" id="form-bandeja"><?= csrf_campo() ?><input type="hidden" name="accion" value="bandeja"><input type="hidden" name="estado_vuelta" value="<?= e($estado) ?>">
    <div class="tabla-scroll"><table class="bandeja-sri">
      <tr><th><input type="checkbox" onclick="document.querySelectorAll('.sel-c').forEach(c => c.checked = this.checked)" aria-label="Marcar todas"></th><th>Fecha</th><th>Proveedor</th><th>Comprobante</th><th>Detalle</th><th class="num">Base 0 %</th><th class="num">Base IVA</th><th class="num">IVA</th><th class="num">Total</th><?php if ($estado === 'incluida'): ?><th>Categoría</th><?php endif; ?></tr>
      <?php foreach ($comp as $c): $otro = $ruc_org !== '' && $c['id_comprador'] !== '' && $c['id_comprador'] !== $ruc_org && substr($c['id_comprador'], 0, 10) !== substr($ruc_org, 0, 10); $s = $c['tipo'] === 'nota_credito' ? -1 : 1; ?>
      <tr>
        <td><input type="checkbox" class="sel-c" name="ids[]" value="<?= (int)$c['id'] ?>" aria-label="Marcar"></td>
        <td><?= e(fecha_bonita($c['fecha'])) ?></td>
        <td style="white-space:normal"><b><?= e($c['razon_social'] ?: 'Proveedor') ?></b><br><small class="suave">RUC <?= e($c['ruc_emisor']) ?></small><span class="solo-cel"><br><b><?= dinero($s * $c['total']) ?></b><?= $otro ? ' ⚠' : '' ?></span></td>
        <td><?= e(nombre_tipo_compra($c['tipo'])) ?><br><small><?= e($c['numero']) ?></small>
          <?php if ($c['xml'] !== ''): ?><br><a href="<?= e($url . '&xml=' . (int)$c['id']) ?>"><small>⬇ XML</small></a><?php endif; ?></td>
        <td style="white-space:normal;max-width:280px"><small><?= e($c['detalle']) ?></small>
          <?php if ((int)$c['completa'] === 0): ?><br><span class="etiqueta">Solo del TXT: falta el detalle</span><?php endif; ?>
          <?php if ($otro): ?><br><span class="etiqueta roja" title="Comprador: <?= e($c['id_comprador']) ?>">⚠ A nombre de otra persona (<?= e($c['id_comprador']) ?>)</span><?php endif; ?>
          <?php if ($c['doc_modificado'] !== ''): ?><br><small class="suave">Modifica la factura <?= e($c['doc_modificado']) ?></small><?php endif; ?></td>
        <td class="num"><?= dinero($s * $c['base_0']) ?></td><td class="num"><?= dinero($s * $c['base_iva']) ?></td><td class="num"><?= dinero($s * $c['iva']) ?></td><td class="num"><b><?= dinero($s * $c['total']) ?></b></td>
        <?php if ($estado === 'incluida'): ?><td><?= e(nombre_categoria_gasto((string)$c['categoria'])) ?></td><?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <?php if (puede_pagina('contabilidad', true)): ?>
    <div class="tarjeta acciones-bandeja">
      <b>Con las marcadas:</b>
      <?php if ($estado === 'pendiente'): ?>
        <div class="acciones" style="align-items:flex-end;flex-wrap:wrap">
          <div><label>Categoría</label><select name="categoria"><option value="">— Elija la categoría —</option><?php foreach ($cats as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
          <?php if (!$escuela && $torneos): ?><div><label>Campeonato</label><select name="torneo_id"><option value="0">General de la liga</option><?php foreach ($torneos as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['nombre'] . ' ' . $x['temporada']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
          <div><label>Forma de pago</label><select name="forma_pago"><?php foreach (formas_pago() as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'transferencia' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
          <button class="btn" name="que" value="incluir">✅ Incluir como gasto</button>
          <button class="btn btn-sec" name="que" value="excluir">🚫 No incluir</button>
          <?php if ($n_incompletas): ?><button class="btn btn-sec" name="que" value="completar" title="Baja del SRI el XML de las que llegaron solo del TXT">🔄 Completar desde el SRI</button><?php endif; ?>
          <button class="btn btn-sec" name="que" value="quitar" onclick="return confirm('¿Quitar de la bandeja? (no se borra nada del SRI)')">🗑 Quitar</button>
        </div>
      <?php else: ?>
        <button class="btn btn-sec" name="que" value="reabrir" onclick="return confirm('<?= $estado === 'incluida' ? '¿Devolver a la bandeja? Su gasto quedará anulado.' : '¿Devolver a la bandeja?' ?>')">↩ Devolver a la bandeja</button>
        <?php if ($estado === 'excluida'): ?><button class="btn btn-sec" name="que" value="quitar" onclick="return confirm('¿Quitar definitivamente?')">🗑 Quitar</button><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </form>
  <?php endif; ?>
  <p class="suave" style="font-size:.85rem">Los comprobantes de <b>retención</b> no se importan (no son gastos). Si el mismo comprobante se sube dos veces, el sistema lo reconoce por su clave de acceso y no lo repite. TuVocalía solo registra las facturas que usted recibe; no emite facturas electrónicas.</p>

<?php else: // Para el contador
    $D = datos_contables($liga, $desde, $hasta, $tid);
    $form_filtro('contador');
    $sum = [0.0, 0.0, 0.0, 0.0]; ?>
  <p class="suave">Las facturas electrónicas <b>incluidas</b> como gasto en el período, con sus bases e IVA. Descargue el <b>Excel</b> (trae además el resumen, los gastos y los ingresos en hojas separadas) y envíeselo a su contador.</p>
  <?php if (!$D['compras']): ?><p class="suave">No hay facturas incluidas en este período.</p><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>Fecha</th><th>RUC</th><th>Proveedor</th><th>Comprobante</th><th class="num">Base 0 %</th><th class="num">Base IVA</th><th class="num">IVA</th><th class="num">Total</th></tr>
    <?php foreach ($D['compras'] as $c): $s = $c['tipo'] === 'nota_credito' ? -1 : 1; $f = [$s * $c['base_0'], $s * $c['base_iva'], $s * $c['iva'], $s * $c['total']]; foreach ($f as $i => $v) $sum[$i] += $v; ?>
      <tr><td><?= e(fecha_bonita($c['fecha'])) ?></td><td><?= e($c['ruc_emisor']) ?></td><td style="white-space:normal"><?= e($c['razon_social']) ?></td><td><?= e(nombre_tipo_compra($c['tipo'])) ?><br><small><?= e($c['numero']) ?></small></td>
        <?php foreach ($f as $v): ?><td class="num"><?= dinero($v) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    <tr><th colspan="4" class="num">Totales</th><?php foreach ($sum as $v): ?><th class="num"><?= dinero($v) ?></th><?php endforeach; ?></tr>
  </table></div>
  <?php endif; ?>
  <p class="suave" style="font-size:.85rem">Gastos sin factura electrónica en el período: <b><?= dinero(array_sum(array_map(fn($g) => $g['origen'] !== 'SRI' ? $g['monto'] : 0, $D['detalle']))) ?></b> (están en la hoja "Gastos" del Excel). Este reporte ayuda a su contador; no reemplaza las declaraciones al SRI.</p>
<?php endif; ?>
<?php
pie_admin();
