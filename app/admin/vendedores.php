<?php
/* (v9) VENDEDORES CON COMISIÓN (solo superadministrador): quién vende, qué ligas trajo, cuánto se le debe y pagos */
if (!es_super()) ir('admin.php');
$yo = usuario();

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'guardar') {
        $id = (int)post('id');
        $v = $id ? fila('SELECT * FROM vendedores WHERE id = ?', [$id]) ?? throw new RuntimeException('Vendedor no encontrado.') : null;
        if (mb_strlen(post('nombre')) < 3) throw new RuntimeException('Escriba el nombre del vendedor.');
        $codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', post('codigo')));
        if ($codigo === '') $codigo = $v['codigo'] ?? codigo_vendedor_nuevo(post('nombre'));
        if (strlen($codigo) < 3 || strlen($codigo) > 15) throw new RuntimeException('El código debe tener de 3 a 15 letras o números.');
        if (fila('SELECT id FROM vendedores WHERE codigo = ? AND id <> ?', [$codigo, $id])) throw new RuntimeException('Ese código ya lo usa otro vendedor.');
        $d = [
            'nombre' => mb_substr(post('nombre'), 0, 80), 'cedula' => mb_substr(post('cedula'), 0, 20), 'telefono' => mb_substr(post('telefono'), 0, 30),
            'email' => mb_substr(mb_strtolower(post('email')), 0, 120), 'ciudad' => mb_substr(post('ciudad'), 0, 60), 'codigo' => $codigo,
            'comision_pct' => max(0, min(100, round((float)str_replace(',', '.', post('comision_pct')), 2))), 'comision_meses' => entero(post('comision_meses'), 0, 120),
            'datos_pago' => mb_substr(post('datos_pago'), 0, 300), 'notas' => mb_substr(post('notas'), 0, 300), 'activo' => post('activo') === '1' ? 1 : 0,
        ];
        if ($v) {
            q('UPDATE vendedores SET ' . implode(', ', array_map(fn($k) => "$k = :$k", array_keys($d))) . ' WHERE id = :id', $d + ['id' => $id]);
        } else {
            exigir_autorizacion(); // (v24)
            q('INSERT INTO vendedores (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($d))) . ')', $d);
            $id = (int)db()->lastInsertId();
            registrar_autorizacion_tercero('vendedor', null, $d['nombre'], 'la gestión de ventas y el pago de comisiones', ['ref_tabla' => 'vendedores', 'ref_id' => $id, 'documento' => $d['cedula']]);
        }
        flash('Vendedor guardado. Su código es ' . $codigo . '.');
        ir('admin.php?p=vendedores&ver=' . $id);
    }
    $v = fila('SELECT * FROM vendedores WHERE id = ?', [(int)post('id')]) ?? throw new RuntimeException('Vendedor no encontrado.');
    if ($accion === 'acceso') {
        // Usuario para que el vendedor vea sus ligas y comisiones en su celular
        $email = mb_strtolower(trim(post('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Escriba un correo válido.');
        if (strlen(post('clave')) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
        $u = fila("SELECT * FROM usuarios WHERE vendedor_id = ? AND rol = 'vendedor'", [$v['id']]);
        if (fila('SELECT id FROM usuarios WHERE lower(email) = ? AND id <> ?', [$email, $u['id'] ?? 0])) throw new RuntimeException('Ese correo ya tiene una cuenta.');
        if ($u) {
            q('UPDATE usuarios SET email = ?, clave = ?, activo = 1 WHERE id = ?', [$email, password_hash(post('clave'), PASSWORD_DEFAULT), $u['id']]);
            bitacora_evento('usuarios', 'Cambió el acceso del vendedor ' . $v['nombre'], null, (int)$u['id']);
        } else {
            q("INSERT INTO usuarios (nombre, email, clave, rol, vendedor_id) VALUES (?,?,?, 'vendedor', ?)", [$v['nombre'], $email, password_hash(post('clave'), PASSWORD_DEFAULT), $v['id']]);
        }
        $_SESSION['acceso_vendedor'] = [$email, post('clave')];
        flash('Acceso del vendedor listo. Envíele sus datos por WhatsApp.');
    } elseif ($accion === 'pagar') {
        $ids = array_map('intval', (array)($_POST['comisiones'] ?? []));
        if (!$ids) throw new RuntimeException('Marque las comisiones que va a pagar.');
        $in = implode(',', $ids);
        $total = (float)valor("SELECT COALESCE(SUM(comision), 0) FROM comisiones WHERE vendedor_id = ? AND estado = 'pendiente' AND id IN ($in)", [$v['id']]);
        $fecha = post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : date('Y-m-d');
        q("UPDATE comisiones SET estado = 'pagada', pagada_en = ?, forma_pago = ?, nota = ? WHERE vendedor_id = ? AND estado = 'pendiente' AND id IN ($in)",
            [$fecha, array_key_exists(post('forma_pago'), formas_pago()) ? post('forma_pago') : 'transferencia', mb_substr(post('nota'), 0, 200), $v['id']]);
        flash('Pago de comisiones registrado: ' . dinero($total) . '.');
        ir('admin.php?p=vendedores&ver=' . (int)$v['id'] . '&recibo=' . $fecha);
    } elseif ($accion === 'anular') {
        q("UPDATE comisiones SET estado = 'anulada' WHERE id = ? AND vendedor_id = ? AND estado = 'pendiente'", [(int)post('comision_id'), $v['id']]);
        flash('Comisión anulada.');
    }
    ir('admin.php?p=vendedores&ver=' . (int)$v['id']);
}

$ver = get('ver') !== '' ? fila('SELECT * FROM vendedores WHERE id = ?', [(int)get('ver')]) : null;

/* Comprobante de pago de comisiones (para que el vendedor lo firme) */
if ($ver && get('recibo') !== '') {
    $fecha = date('Y-m-d', strtotime(get('recibo')) ?: time());
    $cs = filas("SELECT c.*, l.nombre AS liga FROM comisiones c JOIN ligas l ON l.id = c.liga_id WHERE c.vendedor_id = ? AND c.estado = 'pagada' AND c.pagada_en = ? ORDER BY c.id", [$ver['id'], $fecha]);
    doc_inicio('Comisiones ' . $ver['nombre'], 'admin.php?p=vendedores&ver=' . (int)$ver['id']);
    ?>
  <div class="cab"><div><h1><?= e(marca()) ?> — Pago de comisiones</h1><div><?= e(ajuste('empresa')) ?></div></div></div>
  <div class="meta" style="grid-template-columns:1fr 1fr">
    <div><b>Vendedor:</b> <?= e($ver['nombre']) ?></div><div><b>C.I.:</b> <?= e($ver['cedula']) ?></div>
    <div><b>Código:</b> <?= e($ver['codigo']) ?></div><div><b>Fecha de pago:</b> <?= e($fecha) ?></div>
  </div>
  <table class="pl"><tr><th>Liga</th><th>Pago de la liga</th><th>Meses</th><th>%</th><th>Comisión</th></tr>
    <?php foreach ($cs as $c): ?><tr><td><?= e($c['liga']) ?></td><td><?= dinero($c['monto_pago']) ?></td><td><?= (int)$c['meses'] ?></td><td><?= e((string)(float)$c['porcentaje']) ?>%</td><td><?= dinero($c['comision']) ?></td></tr><?php endforeach; ?>
    <tr><td colspan="4" style="text-align:right"><b>TOTAL</b></td><td><b><?= dinero(array_sum(array_column($cs, 'comision'))) ?></b></td></tr>
  </table>
  <div class="firmas" style="grid-template-columns:1fr 1fr"><div>Recibí conforme: <?= e($ver['nombre']) ?></div><div><?= e(ajuste('empresa')) ?></div></div>
    <?php
    doc_fin();
    exit;
}

$vendedores = filas('SELECT v.* FROM vendedores v ORDER BY v.activo DESC, v.nombre');
cabecera_admin('Vendedores');
?>
<h1>🤝 Vendedores con comisión</h1>
<p class="suave">Personas en otras ciudades venden <?= e(marca()) ?> con su propio <b>código</b>. Cuando una liga se registra con su enlace o su código (o usted la asigna en Ligas → Editar), cada pago de suscripción que registre en <b>Ventas</b> le genera su comisión automáticamente, durante los meses que usted defina.</p>

<?php if ($vendedores): ?>
<div class="tabla-scroll"><table>
  <tr><th>Vendedor</th><th>Código</th><th>Comisión</th><th class="num">Ligas</th><th class="num">Pagando</th><th class="num">Por pagarle</th><th class="num">Pagado</th><th></th></tr>
  <?php foreach ($vendedores as $v): $r = resumen_vendedor((int)$v['id']); ?>
  <tr style="<?= $v['activo'] ? '' : 'opacity:.55' ?>"><td><b><?= e($v['nombre']) ?></b><?= $v['ciudad'] !== '' ? '<br><small>' . e($v['ciudad']) . '</small>' : '' ?></td><td><code><?= e($v['codigo']) ?></code></td>
    <td><?= e((string)(float)$v['comision_pct']) ?>% <small><?= (int)$v['comision_meses'] ? 'primeros ' . (int)$v['comision_meses'] . ' meses' : 'siempre' ?></small></td>
    <td class="num"><?= $r['ligas'] ?></td><td class="num"><?= $r['pagando'] ?></td><td class="num pts"><?= dinero($r['pendiente']) ?></td><td class="num"><?= dinero($r['pagado']) ?></td>
    <td><a class="btn btn-sec btn-chico" href="admin.php?p=vendedores&amp;ver=<?= (int)$v['id'] ?>">Ver</a></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>

<?php if ($ver):
    $ligas_v = filas("SELECT l.*, p.nombre AS plan FROM ligas l LEFT JOIN planes p ON p.id = l.plan_id WHERE l.vendedor_id = ? AND COALESCE(l.eliminada, '') = '' ORDER BY l.nombre", [$ver['id']]); // (Anexo A)
    $coms = filas('SELECT c.*, l.nombre AS liga FROM comisiones c JOIN ligas l ON l.id = c.liga_id WHERE c.vendedor_id = ? ORDER BY (c.estado = \'pendiente\') DESC, c.id DESC LIMIT 200', [$ver['id']]);
    $pend = array_filter($coms, fn($c) => $c['estado'] === 'pendiente');
    $acceso = fila("SELECT * FROM usuarios WHERE vendedor_id = ? AND rol = 'vendedor'", [$ver['id']]);
    $ac = $_SESSION['acceso_vendedor'] ?? null; unset($_SESSION['acceso_vendedor']);
    $enlace = enlace_vendedor($ver);
    $msg = 'Hola ' . $ver['nombre'] . ', este es tu enlace de ' . marca() . ' para registrar ligas con tu código ' . $ver['codigo'] . ":\n" . $enlace
        . ($ac ? "\n\nPara ver tus ligas y comisiones entra a " . url_base() . "vendedor.php\nUsuario: " . $ac[0] . "\nContraseña: " . $ac[1] : '');
?>
<div class="tarjeta" id="detalle" style="border:2px solid var(--acento);margin-top:16px">
  <h2 style="margin-top:0"><?= e($ver['nombre']) ?> · <code><?= e($ver['codigo']) ?></code></h2>
  <p>Enlace para registrar ligas con su código: <a href="<?= e($enlace) ?>" target="_blank"><?= e($enlace) ?></a></p>
  <div class="acciones">
    <?php $wa = telefono_wa($ver['telefono']); ?><a class="btn" target="_blank" href="https://wa.me/<?= e($wa ?: '') ?>?text=<?= rawurlencode($msg) ?>">📲 Enviar su enlace<?= $ac ? ' y su acceso' : '' ?> por WhatsApp</a>
  </div>
  <?php if ($ver['datos_pago'] !== ''): ?><p class="suave">Datos para pagarle: <?= e($ver['datos_pago']) ?></p><?php endif; ?>

  <h3>Ligas que trajo (<?= count($ligas_v) ?>)</h3>
  <?php if (!$ligas_v): ?><p class="suave">Todavía ninguna.</p><?php else: ?>
  <div class="tabla-scroll"><table><tr><th>Liga</th><th>Plan</th><th>Estado</th><th>Vence</th></tr>
    <?php foreach ($ligas_v as $l): $sus = estado_suscripcion($l); ?><tr><td><a href="admin.php?p=ligas&amp;editar=<?= (int)$l['id'] ?>"><?= e($l['nombre']) ?></a></td><td><?= e($l['plan'] ?? '—') ?></td><td><?= e($sus['texto']) ?></td><td><?= e($l['vence'] ?: '—') ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>

  <h3>Comisiones</h3>
  <?php if (!$coms): ?><p class="suave">Todavía no hay comisiones: se generan cuando usted registra en Ventas un pago de una de sus ligas.</p><?php else: ?>
  <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="pagar"><input type="hidden" name="id" value="<?= (int)$ver['id'] ?>">
    <div class="tabla-scroll"><table><tr><th></th><th>Fecha</th><th>Liga</th><th class="num">Pago de la liga</th><th class="num">Meses</th><th class="num">Comisión</th><th>Estado</th></tr>
      <?php foreach ($coms as $c): ?><tr style="<?= $c['estado'] === 'anulada' ? 'opacity:.5;text-decoration:line-through' : '' ?>">
        <td><?php if ($c['estado'] === 'pendiente'): ?><input type="checkbox" name="comisiones[]" value="<?= (int)$c['id'] ?>" checked><?php endif; ?></td>
        <td><?= e(substr($c['creado'], 0, 10)) ?></td><td><?= e($c['liga']) ?></td><td class="num"><?= dinero($c['monto_pago']) ?></td><td class="num"><?= (int)$c['meses'] ?></td><td class="num pts"><?= dinero($c['comision']) ?></td>
        <td><?= $c['estado'] === 'pagada' ? '<span class="estado-chip chip-ok">Pagada ' . e($c['pagada_en']) . '</span> <a href="admin.php?p=vendedores&amp;ver=' . (int)$ver['id'] . '&amp;recibo=' . e($c['pagada_en']) . '" target="_blank">🖨</a>' : ($c['estado'] === 'anulada' ? 'Anulada' : '<span class="estado-chip chip-pend">Por pagar</span> <button type="button" class="btn btn-sec btn-chico" onclick="if (confirm(\'¿Anular esta comisión?\')) document.getElementById(\'anular' . (int)$c['id'] . '\').submit()">Anular</button>') ?></td></tr><?php endforeach; ?>
    </table></div>
    <?php if ($pend): ?>
    <div class="campos" style="margin-top:10px">
      <div><label>Fecha del pago</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
      <div><label>Forma</label><select name="forma_pago"><?php foreach (formas_pago() as $k => $t): ?><option value="<?= $k ?>" <?= $k === 'transferencia' ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
      <div class="campo-ancho"><label>Nota</label><input type="text" name="nota" maxlength="200"></div>
    </div>
    <button class="btn" onclick="return confirm('¿Registrar el pago de las comisiones marcadas?')">💵 Pagar comisiones marcadas e imprimir comprobante</button>
    <?php endif; ?>
  </form>
  <?php foreach ($pend as $c): ?><form method="post" class="inline" style="display:none" id="anular<?= (int)$c['id'] ?>"><?= csrf_campo() ?><input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?= (int)$ver['id'] ?>"><input type="hidden" name="comision_id" value="<?= (int)$c['id'] ?>"></form><?php endforeach; ?>
  <?php endif; ?>

  <h3>Acceso del vendedor a su portal</h3>
  <p class="suave" style="margin-top:0">Con su usuario ve en su celular sus ligas, cuánto se le debe y lo que ya cobró (no ve nada más del sistema).</p>
  <form method="post" class="campos"><?= csrf_campo() ?><input type="hidden" name="accion" value="acceso"><input type="hidden" name="id" value="<?= (int)$ver['id'] ?>">
    <div><label>Correo (usuario)</label><input type="email" name="email" required value="<?= e($acceso['email'] ?? $ver['email']) ?>"></div>
    <div><label>Contraseña (mín. 8)</label><input type="text" name="clave" required minlength="8" value="<?= e(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10)) ?>"></div>
    <div><button class="btn btn-sec" style="margin-top:24px"><?= $acceso ? 'Cambiar acceso' : 'Crear acceso' ?></button></div>
  </form>
</div>
<?php endif; ?>

<?php $e = $ver ?? ['id' => 0, 'nombre' => '', 'cedula' => '', 'telefono' => '', 'email' => '', 'ciudad' => '', 'codigo' => '', 'comision_pct' => 20, 'comision_meses' => 12, 'datos_pago' => '', 'notas' => '', 'activo' => 1]; ?>
<details class="tarjeta" style="margin-top:16px" <?= $vendedores ? '' : 'open' ?>>
  <summary><b><?= $ver ? 'Editar datos de ' . e($ver['nombre']) : '+ Nuevo vendedor' ?></b></summary>
  <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
    <div class="campos">
      <div><label>Nombre *</label><input type="text" name="nombre" required maxlength="80" value="<?= e($e['nombre']) ?>"></div>
      <div><label>Cédula</label><input type="text" name="cedula" maxlength="20" value="<?= e($e['cedula']) ?>"></div>
      <div><label>Celular (WhatsApp)</label><input type="tel" name="telefono" maxlength="30" value="<?= e($e['telefono']) ?>"></div>
      <div><label>Correo</label><input type="email" name="email" value="<?= e($e['email']) ?>"></div>
      <div><label>Ciudad / zona</label><input type="text" name="ciudad" maxlength="60" value="<?= e($e['ciudad']) ?>"></div>
      <div><label>Código</label><input type="text" name="codigo" maxlength="15" value="<?= e($e['codigo']) ?>" placeholder="Se crea solo si lo deja vacío" style="text-transform:uppercase"></div>
      <div><label>Comisión (%)</label><input type="number" name="comision_pct" min="0" max="100" step="0.5" value="<?= e((string)(float)$e['comision_pct']) ?>"></div>
      <div><label>Durante los primeros … meses pagados</label><input type="number" name="comision_meses" min="0" max="120" value="<?= (int)$e['comision_meses'] ?>"><div class="ayuda">Ej: 12 = gana del primer año de cada liga. 0 = mientras la liga siga pagando.</div></div>
      <div class="campo-ancho"><label>Datos para pagarle (banco, cuenta)</label><input type="text" name="datos_pago" maxlength="300" value="<?= e($e['datos_pago']) ?>"></div>
      <div class="campo-ancho"><label>Notas</label><input type="text" name="notas" maxlength="300" value="<?= e($e['notas']) ?>"></div>
    </div>
    <label class="check"><input type="checkbox" name="activo" value="1" <?= $e['activo'] ? 'checked' : '' ?>> Activo (su código funciona)</label>
    <?php if (!(int)$e['id']): ?><?= html_autorizacion('tercero', 'la gestión de ventas y el pago de comisiones') ?><?php endif; ?>
    <button class="btn" style="margin-top:8px">Guardar vendedor</button>
  </form>
</details>
<?php
pie_admin();
