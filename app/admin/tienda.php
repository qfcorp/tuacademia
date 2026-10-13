<?php
/* (v11) TIENDA E INVENTARIO: uniformes, balones y otros productos, con stock; venta al contado o cargada a la cuenta del alumno */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$por = usuario()['nombre'];

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'producto') {
        if (mb_strlen(post('nombre')) < 2) throw new RuntimeException('Escriba el nombre del producto.');
        $d = [mb_substr(post('nombre'), 0, 80), mb_substr(post('talla'), 0, 20), max(0, round((float)str_replace(',', '.', post('precio')), 2)), entero(post('stock'), 0, 99999), post('activo') === '1' ? 1 : 0];
        if ((int)post('id')) q('UPDATE esc_productos SET nombre = ?, talla = ?, precio = ?, stock = ?, activo = ? WHERE id = ? AND liga_id = ?', array_merge($d, [(int)post('id'), $lid]));
        else q('INSERT INTO esc_productos (nombre, talla, precio, stock, activo, liga_id) VALUES (?,?,?,?,?,?)', array_merge($d, [$lid]));
        flash('Producto guardado.');
    } elseif ($accion === 'vender') {
        $p = fila('SELECT * FROM esc_productos WHERE id = ? AND liga_id = ? AND activo = 1', [(int)post('producto_id'), $lid]) ?? throw new RuntimeException('Elija el producto.');
        $cant = entero(post('cantidad'), 1, 999);
        if ($p['stock'] < $cant) throw new RuntimeException('Solo quedan ' . (int)$p['stock'] . ' en stock.');
        $precio = post('precio') !== '' ? max(0, round((float)str_replace(',', '.', post('precio')), 2)) : (float)$p['precio'];
        $aid = (int)post('alumno_id');
        $alumno = $aid ? fila('SELECT * FROM esc_alumnos WHERE id = ? AND liga_id = ?', [$aid, $lid]) : null;
        $modo = post('modo') === 'cuenta' && $alumno ? 'cuenta' : 'contado';
        $pdo = db();
        $pdo->beginTransaction();
        $cargo = null;
        if ($modo === 'cuenta') $cargo = crear_cargo_escuela($liga, $aid, 'tienda', $p['nombre'] . ($p['talla'] ? ' (' . $p['talla'] . ')' : '') . ' × ' . $cant, $precio * $cant, date('Y-m-d', strtotime('+7 days')), $por);
        q('INSERT INTO esc_ventas (liga_id, producto_id, alumno_id, cantidad, precio, total, modo, forma_pago, cargo_id, fecha, registrado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$lid, $p['id'], $alumno['id'] ?? null, $cant, $precio, round($precio * $cant, 2), $modo, array_key_exists(post('forma_pago'), formas_pago()) ? post('forma_pago') : 'efectivo', $cargo, date('Y-m-d'), $por]);
        q('UPDATE esc_productos SET stock = stock - ? WHERE id = ?', [$cant, $p['id']]);
        $pdo->commit();
        flash('Venta registrada' . ($modo === 'cuenta' ? ' y cargada a la cuenta de ' . nombre_alumno($alumno) : '') . '. Quedan ' . ((int)$p['stock'] - $cant) . '.');
    } elseif ($accion === 'anular') {
        $v = fila('SELECT * FROM esc_ventas WHERE id = ? AND liga_id = ? AND anulado = 0', [(int)post('id'), $lid]) ?? throw new RuntimeException('Venta no encontrada.');
        if ($v['cargo_id'] && valor("SELECT estado FROM esc_cargos WHERE id = ?", [$v['cargo_id']]) === 'pagado') throw new RuntimeException('Esa venta ya fue pagada en la cuenta del alumno.');
        q('UPDATE esc_ventas SET anulado = 1 WHERE id = ?', [$v['id']]);
        if ($v['producto_id']) q('UPDATE esc_productos SET stock = stock + ? WHERE id = ?', [$v['cantidad'], $v['producto_id']]);
        if ($v['cargo_id']) q("UPDATE esc_cargos SET estado = 'anulado' WHERE id = ?", [$v['cargo_id']]);
        flash('Venta anulada y stock devuelto.');
    }
    ir('admin.php?p=tienda');
}

$productos = filas('SELECT * FROM esc_productos WHERE liga_id = ? ORDER BY activo DESC, nombre, talla', [$lid]);
$ventas = filas('SELECT v.*, p.nombre AS producto, p.talla, a.nombres, a.apellidos FROM esc_ventas v LEFT JOIN esc_productos p ON p.id = v.producto_id LEFT JOIN esc_alumnos a ON a.id = v.alumno_id WHERE v.liga_id = ? ORDER BY v.id DESC LIMIT 50', [$lid]);
$alumnos = filas("SELECT id, nombres, apellidos FROM esc_alumnos WHERE liga_id = ? AND estado = 'activo' ORDER BY apellidos, nombres", [$lid]);
$ed = puede('tienda', true);
cabecera_admin('Tienda');
?>
<h1>🛍 Tienda e inventario</h1>
<div class="tabla-scroll"><table><tr><th>Producto</th><th>Talla</th><th class="num">Precio</th><th class="num">Stock</th><th></th></tr>
  <?php foreach ($productos as $p): ?><tr style="<?= $p['activo'] ? '' : 'opacity:.5' ?>"><td><b><?= e($p['nombre']) ?></b></td><td><?= e($p['talla']) ?></td><td class="num"><?= dinero($p['precio']) ?></td>
    <td class="num"><?= (int)$p['stock'] <= 3 ? '<span class="semaforo s-rojo">' . (int)$p['stock'] . '</span>' : (int)$p['stock'] ?></td>
    <td><?php if ($ed): ?><details><summary>Editar</summary><form method="post" class="acciones" style="margin-top:6px"><?= csrf_campo() ?><input type="hidden" name="accion" value="producto"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <input type="text" name="nombre" value="<?= e($p['nombre']) ?>" maxlength="80"><input type="text" name="talla" value="<?= e($p['talla']) ?>" maxlength="20" style="max-width:70px">
      <input type="number" name="precio" step="0.01" min="0" value="<?= e(number_format((float)$p['precio'], 2, '.', '')) ?>" style="max-width:90px"><input type="number" name="stock" min="0" value="<?= (int)$p['stock'] ?>" style="max-width:80px">
      <label class="check"><input type="checkbox" name="activo" value="1" <?= $p['activo'] ? 'checked' : '' ?>> Activo</label><button class="btn btn-sec btn-chico">Guardar</button></form></details><?php endif; ?></td></tr><?php endforeach; ?>
</table></div>
<?php if ($ed): ?>
<form method="post" class="acciones tarjeta" style="margin-top:10px;align-items:flex-end"><?= csrf_campo() ?><input type="hidden" name="accion" value="producto"><input type="hidden" name="id" value="0"><input type="hidden" name="activo" value="1">
  <div><label>Nuevo producto</label><input type="text" name="nombre" maxlength="80" placeholder="Ej: Uniforme completo"></div>
  <div><label>Talla</label><input type="text" name="talla" maxlength="20" style="max-width:70px"></div>
  <div><label>Precio ($)</label><input type="number" name="precio" step="0.01" min="0" style="max-width:90px"></div>
  <div><label>Stock</label><input type="number" name="stock" min="0" value="0" style="max-width:80px"></div>
  <button class="btn btn-sec">+ Agregar</button></form>

<?php if ($productos): ?>
<form method="post" class="tarjeta" style="margin-top:16px"><?= csrf_campo() ?><input type="hidden" name="accion" value="vender">
  <h2 style="margin-top:0">Vender</h2>
  <div class="campos">
    <div><label>Producto</label><select name="producto_id"><?php foreach ($productos as $p): if (!$p['activo']) continue; ?><option value="<?= (int)$p['id'] ?>"><?= e($p['nombre'] . ($p['talla'] ? ' · ' . $p['talla'] : '') . ' · ' . dinero($p['precio']) . ' · quedan ' . $p['stock']) ?></option><?php endforeach; ?></select></div>
    <div><label>Cantidad</label><input type="number" name="cantidad" min="1" value="1"></div>
    <div><label>Precio (vacío = el del producto)</label><input type="number" name="precio" step="0.01" min="0"></div>
    <div><label>Alumno (opcional)</label><select name="alumno_id"><option value="">— venta suelta —</option><?php foreach ($alumnos as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e(nombre_alumno($a)) ?></option><?php endforeach; ?></select></div>
    <div><label>Cómo paga</label><select name="modo"><option value="contado">Paga ahora</option><option value="cuenta">Cargar a la cuenta del alumno</option></select></div>
    <div><label>Forma de pago</label><select name="forma_pago"><?php foreach (formas_pago() as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
  </div>
  <button class="btn">Registrar venta</button>
</form>
<?php endif; endif; ?>

<?php if ($ventas): ?>
<h2>Últimas ventas</h2>
<div class="tabla-scroll"><table><tr><th>Fecha</th><th>Producto</th><th>Alumno</th><th class="num">Total</th><th>Pago</th><th></th></tr>
  <?php foreach ($ventas as $v): ?><tr style="<?= $v['anulado'] ? 'opacity:.5;text-decoration:line-through' : '' ?>"><td><?= e($v['fecha']) ?></td><td><?= e(($v['producto'] ?? '—') . ($v['talla'] ? ' · ' . $v['talla'] : '')) ?> × <?= (int)$v['cantidad'] ?></td><td><?= $v['nombres'] ? e(nombre_alumno($v)) : '—' ?></td><td class="num"><?= dinero($v['total']) ?></td>
    <td><?= $v['modo'] === 'cuenta' ? 'A la cuenta' : e(formas_pago()[$v['forma_pago']] ?? '') ?></td>
    <td><?php if (!$v['anulado'] && $ed): ?><form class="inline" method="post" onsubmit="return confirm('¿Anular la venta?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button class="btn btn-sec btn-chico">Anular</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</table></div>
<?php endif;
pie_admin();
