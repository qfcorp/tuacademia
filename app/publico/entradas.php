<?php
/*
 * (v28) ENTRADAS en la página pública de la liga:
 *   ?v=entradas            → partidos y eventos con entradas a la venta
 *   ?v=entradas&venta=ID   → comprar (tarjeta o transferencia con comprobante)
 *   ?v=entradas&o=CÓDIGO   → mis entradas con su QR (para mostrar en la puerta o imprimir)
 */
$base_e = 'index.php?l=' . rawurlencode($liga['slug']) . '&v=entradas';
$v = (int)get('venta') ? venta_entradas((int)get('venta'), $lid) : null;
$con_tarjeta = (int)$liga['entradas_tarjeta'] === 1 && org_cobra_con_tarjeta($liga);
$con_transf = (int)$liga['entradas_transferencia'] === 1;

/* ---------- Comprar ---------- */
if ($v && es_post() && post('accion') === 'comprar') {
    csrf_verificar();
    if (post('sitio') !== '') exit;
    contexto_auditoria(['usuario' => 'Público (entradas): ' . mb_substr(post('nombre'), 0, 60), 'rol' => 'publico', 'liga_id' => $lid, 'origen' => 'publico']);
    try {
        if (!venta_abierta($v)) throw new RuntimeException('La venta de entradas de este partido ya está cerrada.');
        if (post('acepto') !== '1') throw new RuntimeException('Marque la autorización de uso de sus datos.');
        if (demasiados_intentos('entradas', 8, '-1 hour')) throw new RuntimeException('Demasiadas compras seguidas desde esta conexión. Escriba a la liga.');
        $nombre = trim(post('nombre'));
        $ced = strtoupper(preg_replace('/[\s.\-]+/', '', post('cedula')));
        if (mb_strlen($nombre) < 5) throw new RuntimeException('Escriba su nombre completo.');
        if ($ced === '' || ($err = error_documento($ced))) throw new RuntimeException('Cédula o pasaporte: ' . ($err ?? 'escríbalo.'));
        if (strlen(preg_replace('/\D/', '', post('telefono'))) < 7) throw new RuntimeException('Escriba su celular (ahí le enviamos las entradas).');
        if (post('email') !== '' && !filter_var(post('email'), FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo no es válido.');
        $metodo = post('metodo') === 'tarjeta' && $con_tarjeta ? 'tarjeta' : 'transferencia';
        if ($metodo === 'transferencia' && !$con_transf) throw new RuntimeException('Elija cómo pagar.');
        [$items, $total] = leer_pedido_entradas($v, array_map('intval', (array)($_POST['cant'] ?? [])), post('placas'));
        $comp = '';
        if ($metodo === 'transferencia') {
            if ($total > 0) {
                $comp = subir_fotos_privadas('comprobante', 1600)[0] ?? '';
                if ($comp === '') throw new RuntimeException('Suba la foto del comprobante de la transferencia.');
            }
        }
        $o = crear_orden_entradas($liga, $v, $items, $total, ['nombre' => $nombre, 'cedula' => $ced, 'telefono' => post('telefono'), 'email' => post('email')], 'web', $total > 0 ? $metodo : 'cortesia', $comp);
        if ($total <= 0 && $o['estado'] !== 'pagada') pagar_orden_entradas($o, 'Sin costo');
        registrar_consentimiento('entradas', $lid, $nombre, 'titular', ['ref_tabla' => 'bol_ordenes', 'ref_id' => (int)$o['id'], 'documento' => $ced,
            'texto' => 'Autorizo a ' . $liga['nombre'] . ' a usar mi nombre, cédula, celular y correo solo para emitir y controlar mis entradas, según la política de privacidad (datos guardados en servidores fuera del Ecuador).']);
        anotar_intento('entradas');
        if ($metodo === 'transferencia' && $o['estado'] === 'por_verificar') {
            aviso_admins($liga, '🎟 <b>Compra de entradas por revisar</b>' . "\n" . e($nombre) . ' · ' . dinero($total) . ' · ' . e($v['titulo']) . "\n\nRevisar: " . url_base() . 'admin.php?p=entradas&v=' . (int)$v['id'], 'bol:' . $o['id']);
        }
        if ($metodo === 'tarjeta' && $o['estado'] === 'pendiente') {
            $token = crear_pago_linea($liga, ['destino' => 'organizacion', 'concepto' => 'entradas', 'detalle' => 'Entradas · ' . $v['titulo'], 'monto_base' => $total,
                'pagador_nombre' => $nombre, 'pagador_email' => post('email'), 'pagador_cedula' => $ced, 'pagador_telefono' => post('telefono'),
                'datos' => ['orden_id' => (int)$o['id'], 'volver' => 'index.php?l=' . $liga['slug'] . '&v=entradas&o=' . $o['codigo']]]);
            q('UPDATE bol_ordenes SET pago_token = ? WHERE id = ?', [$token, $o['id']]);
            ir(url_pago($token));
        }
        ir($base_e . '&o=' . $o['codigo']);
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
        $_SESSION['entradas_form'] = array_diff_key($_POST, ['csrf' => 1, 'acepto' => 1]);
        ir($base_e . '&venta=' . (int)$v['id']);
    }
}

cabecera_publica('Entradas · ' . $liga['nombre'], $liga);
foreach (tomar_flash() as [$tipo_f, $msg_f]) echo '<div class="aviso aviso-' . e($tipo_f) . '">' . e($msg_f) . '</div>';

/* ---------- Mis entradas ---------- */
if (preg_match('/^[0-9a-f]{20}$/', get('o'))):
    $o = fila('SELECT * FROM bol_ordenes WHERE codigo = ? AND liga_id = ?', [get('o'), $lid]);
    $vv = $o ? venta_entradas((int)$o['venta_id']) : null;
    if (!$o || !$vv): ?>
      <div class="tarjeta"><h1>Compra no encontrada</h1><p>Revise el enlace que le enviaron.</p></div>
    <?php else:
      $tks = filas('SELECT t.*, l.nombre AS loc, l.tipo FROM bol_tickets t JOIN bol_localidades l ON l.id = t.localidad_id WHERE t.orden_id = ? ORDER BY t.id', [$o['id']]); ?>
      <h1 style="margin-bottom:.2rem">🎟 Sus entradas</h1>
      <p><b><?= e($vv['titulo']) ?></b><br><span class="suave"><?= e(fecha_bonita($vv['fecha'])) ?> <?= e($vv['hora']) ?><?= $vv['lugar'] !== '' ? ' · ' . e($vv['lugar']) : '' ?> · A nombre de <?= e($o['nombre']) ?></span></p>
      <?php if ($o['estado'] === 'por_verificar'): ?>
        <div class="aviso aviso-info">⏳ Recibimos su comprobante. La liga lo revisa y aquí mismo aparecerán sus entradas con el QR. Guarde este enlace.</div>
        <button class="btn btn-sec" onclick="location.reload()">🔄 Revisar otra vez</button>
      <?php elseif ($o['estado'] === 'pendiente'): ?>
        <div class="aviso aviso-info">El pago con tarjeta no se completó. <?php if ($o['pago_token'] !== ''): ?><a class="btn btn-chico" href="<?= e(url_pago($o['pago_token'])) ?>">Pagar ahora</a><?php endif; ?></div>
      <?php elseif (in_array($o['estado'], ['rechazada', 'anulada', 'expirada'], true)): ?>
        <div class="aviso aviso-error">Esta compra está <b><?= e(mb_strtolower(estados_orden_entrada()[$o['estado']])) ?></b>.<?= $o['nota'] !== '' ? ' ' . e($o['nota']) : '' ?> Si cree que es un error, escriba a la liga.</div>
      <?php else: ?>
        <p class="suave">Muestre cada QR en la puerta (en el celular o impreso). <b>Cada entrada sirve una sola vez.</b> No comparta la foto del QR: la primera persona que entra con él lo usa.</p>
        <div class="entradas-qr">
        <?php foreach ($tks as $k => $t): ?>
          <div class="entrada-qr tarjeta <?= $t['estado'] !== 'valido' ? 'entrada-usada' : '' ?>">
            <div class="entrada-cab"><b><?= e($liga['nombre']) ?></b><small>Entrada <?= $k + 1 ?> de <?= count($tks) ?></small></div>
            <div class="qr-entrada" data-url="<?= e(url_qr_entrada($t)) ?>"></div>
            <div style="font-size:1.15rem;font-weight:800"><?= $t['tipo'] === 'parqueo' ? '🚗 ' : '🎟 ' ?><?= e($t['loc']) ?></div>
            <?= $t['placa'] !== '' ? '<div>Placa <b>' . e($t['placa']) . '</b></div>' : '' ?>
            <div class="suave" style="font-size:.85rem"><?= e($vv['titulo']) ?> · <?= e(fecha_bonita($vv['fecha'])) ?> <?= e($vv['hora']) ?></div>
            <div class="suave" style="font-size:.75rem"><?= e($t['codigo']) ?></div>
            <?php if ($t['estado'] === 'usado'): ?><div class="estado-chip">Ya ingresó · <?= e(substr($t['usado_en'], 11, 5)) ?></div><?php elseif ($t['estado'] === 'anulado'): ?><div class="estado-chip chip-mal">Anulada</div><?php endif; ?>
          </div>
        <?php endforeach; ?>
        </div>
        <p class="no-imprimir"><button class="btn btn-sec" onclick="window.print()">🖨 Imprimir o guardar en PDF</button></p>
        <script src="assets/js/qrcode.js"></script>
        <script>document.querySelectorAll('.qr-entrada').forEach(function (el) { var q = qrcode(0, 'M'); q.addData(el.getAttribute('data-url')); q.make(); el.innerHTML = q.createSvgTag({ cellSize: 5, margin: 2, scalable: true }); });</script>
      <?php endif; ?>
    <?php endif;

/* ---------- Comprar en una venta ---------- */
elseif ($v && venta_abierta($v)):
    $locs = localidades_venta((int)$v['id']);
    $f = $_SESSION['entradas_form'] ?? [];
    unset($_SESSION['entradas_form']);
    $hay_parqueo = (bool)array_filter($locs, fn($l) => $l['tipo'] === 'parqueo'); ?>
  <p><a href="<?= e($base_e) ?>">← Entradas</a></p>
  <h1 style="margin-bottom:.2rem">🎟 <?= e($v['titulo']) ?></h1>
  <p class="suave"><?= e(fecha_bonita($v['fecha'])) ?> <?= e($v['hora']) ?><?= $v['lugar'] !== '' ? ' · 📍 ' . e($v['lugar']) : '' ?></p>
  <?php if ($v['descripcion'] !== ''): ?><div class="aviso aviso-info"><?= nl2br(e($v['descripcion'])) ?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="tarjeta" id="form-entradas">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="comprar"><input type="text" name="sitio" value="" style="display:none" tabindex="-1" autocomplete="off">
    <h2 style="margin-top:0">1 · ¿Cuántas?</h2>
    <?php foreach ($locs as $l): $libres = (int)$l['cupo'] > 0 ? max(0, (int)$l['cupo'] - ocupadas_localidad((int)$l['id'])) : null; ?>
      <div class="fila-titulo" style="margin:6px 0"><span><b><?= $l['tipo'] === 'parqueo' ? '🚗 ' : '' ?><?= e($l['nombre']) ?></b> · <?= (float)$l['precio'] > 0 ? dinero($l['precio']) : 'Sin costo' ?>
          <?= $libres !== null ? '<small class="suave"> · ' . ($libres ? 'quedan ' . $libres : '<b style="color:var(--roja)">AGOTADO</b>') . '</small>' : '' ?></span>
        <input type="number" name="cant[<?= (int)$l['id'] ?>]" data-precio="<?= (float)$l['precio'] ?>" min="0" max="<?= $libres === null ? (int)$v['max_por_orden'] : min($libres, (int)$v['max_por_orden']) ?>" value="<?= (int)($f['cant'][$l['id']] ?? 0) ?>" style="max-width:90px" <?= $libres === 0 ? 'disabled' : '' ?> oninput="totalEntradas()"></div>
    <?php endforeach; ?>
    <?php if ($hay_parqueo): ?><label>Placas de los vehículos (si compra parqueadero)</label><input name="placas" value="<?= e($f['placas'] ?? '') ?>" placeholder="Ej: PBA-1234, PCD-5678" style="text-transform:uppercase"><?php endif; ?>
    <p style="font-size:1.2rem">Total: <b id="total-ent">$0.00</b><?= (float)$liga['entradas_iva'] > 0 ? ' <small class="suave">(incluye IVA)</small>' : '' ?></p>

    <h2>2 · Sus datos</h2>
    <div class="campos">
      <div><label>Nombre completo</label><input name="nombre" required maxlength="100" value="<?= e($f['nombre'] ?? '') ?>"></div>
      <div><label>Cédula o pasaporte</label><input name="cedula" required maxlength="20" value="<?= e($f['cedula'] ?? '') ?>"></div>
      <div><label>Celular (WhatsApp)</label><input name="telefono" required inputmode="tel" maxlength="20" value="<?= e($f['telefono'] ?? '') ?>"></div>
      <div><label>Correo (opcional)</label><input type="email" name="email" maxlength="120" value="<?= e($f['email'] ?? '') ?>"></div>
    </div>

    <h2>3 · ¿Cómo paga?</h2>
    <?php if ($con_tarjeta): ?><label class="check"><input type="radio" name="metodo" value="tarjeta" <?= ($f['metodo'] ?? 'tarjeta') === 'tarjeta' || !$con_transf ? 'checked' : '' ?> onchange="verComp()"> 💳 Tarjeta de crédito o débito (sus entradas llegan al instante)</label><?php endif; ?>
    <?php if ($con_transf): ?><label class="check"><input type="radio" name="metodo" value="transferencia" <?= !$con_tarjeta || ($f['metodo'] ?? '') === 'transferencia' ? 'checked' : '' ?> onchange="verComp()"> 🏦 Transferencia o depósito (suba el comprobante; la liga lo revisa)</label>
      <div id="caja-comp" class="aviso aviso-info"><?= $liga['datos_pago'] !== '' ? '<b>Datos para la transferencia:</b><br>' . nl2br(e($liga['datos_pago'])) . '<br><br>' : '' ?>
        <label>Foto del comprobante</label><input type="file" name="comprobante" accept="image/*"></div><?php endif; ?>
    <?php if ($liga['entradas_condiciones'] !== ''): ?><p class="suave" style="font-size:.85rem"><?= nl2br(e($liga['entradas_condiciones'])) ?></p><?php endif; ?>
    <?= html_autorizacion('titular', 'emitir y controlar mis entradas (nombre, cédula, celular y correo)', 'index.php?l=' . rawurlencode($liga['slug']) . '&v=privacidad', 'acepto') ?>
    <button class="btn btn-grande" style="margin-top:10px">🎟 Comprar</button>
  </form>
  <script>
  function totalEntradas() { var t = 0; document.querySelectorAll('#form-entradas input[data-precio]').forEach(function (i) { t += (parseInt(i.value, 10) || 0) * parseFloat(i.getAttribute('data-precio')); }); document.getElementById('total-ent').textContent = '$' + t.toFixed(2); }
  function verComp() { var c = document.getElementById('caja-comp'), r = document.querySelector('input[name=metodo]:checked'); if (c) c.style.display = r && r.value === 'transferencia' ? '' : 'none'; }
  totalEntradas(); verComp();
  </script>

<?php
/* ---------- Lista de ventas abiertas ---------- */
else:
    $ventas = ventas_abiertas($lid); ?>
  <h1>🎟 Entradas · <?= e($liga['nombre']) ?></h1>
  <?php if (!$ventas): ?><div class="tarjeta vacio">No hay entradas a la venta en este momento.</div><?php endif; ?>
  <div class="rejilla" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
  <?php foreach ($ventas as $x): $min = (float)valor('SELECT MIN(precio) FROM bol_localidades WHERE venta_id = ? AND tipo = \'entrada\'', [$x['id']]); ?>
    <a class="tarjeta" style="text-decoration:none;color:inherit" href="<?= e($base_e . '&venta=' . (int)$x['id']) ?>">
      <b style="font-size:1.1rem">🎟 <?= e($x['titulo']) ?></b><br><span class="suave"><?= e(fecha_bonita($x['fecha'])) ?> <?= e($x['hora']) ?><?= $x['lugar'] !== '' ? ' · ' . e($x['lugar']) : '' ?></span>
      <p style="margin:.4rem 0 0"><?= $min > 0 ? 'Desde <b>' . dinero($min) . '</b>' : '<b>Sin costo</b>' ?> · <span class="btn btn-chico">Comprar</span></p></a>
  <?php endforeach; ?>
  </div>
<?php endif;
pie_publico();
