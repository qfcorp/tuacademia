<?php
/*
 * (v9) PORTAL DEL VENDEDOR
 * El vendedor entra con su correo y contraseña (se los da QF Producciones) y ve:
 * su enlace y código, las ligas que trajo con su estado, y sus comisiones por cobrar y cobradas.
 */
require __DIR__ . '/../app/bootstrap.php';

$u = usuario();
if (!$u) ir('admin.php?p=login');
if ($u['rol'] !== 'vendedor') ir('admin.php');
csrf_verificar();
$v = fila('SELECT * FROM vendedores WHERE id = ?', [(int)$u['vendedor_id']]);
if (!$v) {
    http_response_code(403);
    exit('Su usuario no está vinculado a un vendedor.');
}

if (es_post() && post('accion') === 'clave') {
    try {
        if (!password_verify(post('actual'), $u['clave'])) throw new RuntimeException('La contraseña actual no es correcta.');
        if (strlen(post('nueva')) < 8) throw new RuntimeException('La contraseña nueva debe tener al menos 8 caracteres.');
        q('UPDATE usuarios SET clave = ? WHERE id = ?', [password_hash(post('nueva'), PASSWORD_DEFAULT), $u['id']]);
        bitacora_evento('usuarios', 'El vendedor cambió su contraseña', null, (int)$u['id']);
        flash('Contraseña cambiada.');
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    }
    ir('vendedor.php');
}

$r = resumen_vendedor((int)$v['id']);
$ligas = filas('SELECT l.*, p.nombre AS plan FROM ligas l LEFT JOIN planes p ON p.id = l.plan_id WHERE l.vendedor_id = ? ORDER BY l.creado DESC', [$v['id']]);
$coms = filas('SELECT c.*, l.nombre AS liga FROM comisiones c JOIN ligas l ON l.id = c.liga_id WHERE c.vendedor_id = ? AND c.estado <> \'anulada\' ORDER BY c.id DESC LIMIT 100', [$v['id']]);
$planes = filas('SELECT * FROM planes WHERE activo = 1 ORDER BY orden, precio_mensual');
$enlace = enlace_vendedor($v);
$texto_wa = '¡Hola! Te recomiendo ' . marca() . ': el sistema para manejar tu liga o escuela desde el celular (fixture, tabla, goleadores, vocalía en vivo, carnets con QR y finanzas). '
    . (prueba_automatica() ? 'Pruébalo gratis ' . (int)ajuste('dias_prueba', 30) . ' días aquí: ' : 'Pide tu demo aquí y te preparan un acceso de prueba: ') . $enlace;
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vendedor · <?= e(marca()) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="assets/estilo.css?v=330">
<?= cabecera_app() ?>
</head>
<body class="admin">
<header class="barra"><div class="barra-in">
  <a class="marca" href="vendedor.php">🤝 <?= e(marca()) ?> <small><?= e($v['nombre']) ?></small></a>
  <nav class="menu" style="display:flex;flex-direction:row;width:auto;padding:0;flex-wrap:wrap"><a href="vendedor.php">Mis ventas</a><a href="admin.php?p=salir">Salir</a></nav>
</div></header>
<main class="contenedor">
<?php foreach (tomar_flash() as [$tipo, $msg]): ?><div class="aviso aviso-<?= e($tipo) ?>"><?= e($msg) ?></div><?php endforeach; ?>

<div class="cifras">
  <div class="cifra"><b><?= $r['ligas'] ?></b>Ligas registradas</div>
  <div class="cifra"><b><?= $r['pagando'] ?></b>Ligas pagando</div>
  <div class="cifra"><b><?= dinero($r['pendiente']) ?></b>Por cobrar</div>
  <div class="cifra"><b><?= dinero($r['pagado']) ?></b>Ya cobrado</div>
</div>

<div class="tarjeta">
  <h2 style="margin-top:0">Su código: <code><?= e($v['codigo']) ?></code></h2>
  <p>Gana el <b><?= e((string)(float)$v['comision_pct']) ?>%</b> de lo que paga cada liga que traiga<?= (int)$v['comision_meses'] ? ', durante sus primeros <b>' . (int)$v['comision_meses'] . ' meses</b> pagados' : ', mientras siga pagando' ?>.</p>
  <p>Comparta este enlace. La liga o escuela que pida su demo con él (o que escriba su código) queda a su nombre:</p>
  <p><input type="text" readonly value="<?= e($enlace) ?>" onclick="this.select()" style="width:100%"></p>
  <div class="acciones">
    <a class="btn" target="_blank" href="https://wa.me/?text=<?= rawurlencode($texto_wa) ?>">📲 Compartir por WhatsApp</a>
    <button class="btn btn-sec" type="button" onclick="navigator.clipboard.writeText(<?= e(json_encode($enlace)) ?>).then(function () { alert('Enlace copiado'); })">📋 Copiar enlace</button>
    <a class="btn btn-sec" target="_blank" href="<?= e(url_base()) ?>">Ver la página de venta</a>
  </div>
  <?php if ($planes): ?>
  <h3>Planes que ofrece</h3>
  <div class="tabla-scroll"><table><tr><th>Plan</th><th class="num">Mensual</th><th class="num">Anual</th><th class="num">Usted gana (mes)</th></tr>
    <?php foreach ($planes as $pl): ?><tr><td><?= e($pl['nombre']) ?></td><td class="num"><?= dinero($pl['precio_mensual']) ?></td><td class="num"><?= dinero($pl['precio_anual']) ?></td><td class="num pts"><?= dinero(round((float)$pl['precio_mensual'] * (float)$v['comision_pct'] / 100, 2)) ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

<?php $mis_demos = filas('SELECT * FROM solicitudes_demo WHERE vendedor_id = ? ORDER BY id DESC LIMIT 30', [$v['id']]); if ($mis_demos): ?>
<h2>Solicitudes de demo</h2>
<div class="tabla-scroll"><table><tr><th>Fecha</th><th>Organización</th><th>Contacto</th><th>Estado</th></tr>
  <?php foreach ($mis_demos as $sd): ?><tr><td><?= e(substr($sd['creado'], 0, 10)) ?></td><td><?= $sd['tipo'] === 'escuela' ? '🎓' : '🏆' ?> <?= e($sd['organizacion']) ?></td><td><?= e($sd['contacto']) ?></td><td><?= e(estados_solicitud_demo()[$sd['estado']] ?? $sd['estado']) ?></td></tr><?php endforeach; ?>
</table></div>
<?php endif; ?>

<h2>Mis ligas</h2>
<?php if (!$ligas): ?><p class="suave">Todavía no tiene ligas. ¡Comparta su enlace!</p><?php else: ?>
<div class="tabla-scroll"><table><tr><th>Liga</th><th>Ciudad</th><th>Plan</th><th>Estado</th></tr>
  <?php foreach ($ligas as $l): $sus = estado_suscripcion($l); ?><tr><td><b><?= e($l['nombre']) ?></b><br><small><?= e(substr($l['creado'], 0, 10)) ?></small></td><td><?= e($l['ciudad']) ?></td><td><?= e($l['plan'] ?? '—') ?></td>
    <td><?= e($sus['texto']) ?><?php if ($sus['estado'] === 'prueba' || $sus['estado'] === 'gracia' || $sus['estado'] === 'vencida'): $wa = telefono_wa((string)$l['contacto_telefono']); if ($wa): ?><br><a class="btn btn-sec btn-chico" target="_blank" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola ' . $l['contacto_nombre'] . ', le saluda ' . $v['nombre'] . ' de ' . marca() . '. ¿Cómo le va con el sistema en ' . $l['nombre'] . '? Le ayudo a elegir su plan para seguir usándolo.') ?>">📲 Hacer seguimiento</a><?php endif; endif; ?></td></tr><?php endforeach; ?>
</table></div>
<?php endif; ?>

<h2>Mis comisiones</h2>
<?php if (!$coms): ?><p class="suave">Aparecen cuando una de sus ligas paga su suscripción.</p><?php else: ?>
<div class="tabla-scroll"><table><tr><th>Fecha</th><th>Liga</th><th class="num">Pagó</th><th class="num">Comisión</th><th>Estado</th></tr>
  <?php foreach ($coms as $c): ?><tr><td><?= e(substr($c['creado'], 0, 10)) ?></td><td><?= e($c['liga']) ?></td><td class="num"><?= dinero($c['monto_pago']) ?></td><td class="num pts"><?= dinero($c['comision']) ?></td>
    <td><?= $c['estado'] === 'pagada' ? '<span class="estado-chip chip-ok">Cobrada ' . e($c['pagada_en']) . '</span>' : '<span class="estado-chip chip-pend">Por cobrar</span>' ?></td></tr><?php endforeach; ?>
</table></div>
<?php endif; ?>

<details class="tarjeta" style="margin-top:16px"><summary>Cambiar mi contraseña</summary>
  <form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="clave">
    <p><label>Contraseña actual</label><input type="password" name="actual" required></p>
    <p><label>Contraseña nueva (mín. 8)</label><input type="password" name="nueva" required minlength="8"></p>
    <button class="btn btn-sec">Cambiar</button></form>
</details>
</main>
</body>
</html>
