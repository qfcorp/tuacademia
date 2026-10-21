<?php
/* (v12) MI PLAN: la liga o escuela ve su plan, sus módulos y límites, y renueva o cambia de plan (con tarjeta si está activo). */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$yo = usuario();
$tipo = ($liga['tipo'] ?? 'liga') === 'escuela' ? 'escuela' : 'liga';
// (Anexo B) Pedir un módulo: crea un pedido «cambio_modulos» que revisa Tukán (Panel Tukán Central o Ligas → Editar)
if (es_post() && post('accion') === 'pedir_modulo') {
    if (!puede('config', true)) throw new RuntimeException('Solo el administrador puede pedir módulos.');
    crear_pedido_modulos($liga, $yo['nombre'] . ' <' . $yo['email'] . '>', [post('modulo')], [], post('mensaje'));
    flash('¡Listo! Recibimos su pedido. Le avisamos apenas quede encendido (normalmente el mismo día).');
    ir('admin.php?p=mi_plan#modulos');
}
// (20 oct 2026) Pedir cotización de un plan, deporte o disciplina: ticket «Pagos, plan o factura» (llega al súper y al Panel Tukán Central)
if (es_post() && post('accion') === 'pedir_cotizacion') {
    if (!puede('config', true)) throw new RuntimeException('Solo el administrador puede pedir cotizaciones.');
    pedir_cotizacion($liga, (string)post('que'), (string)post('mensaje'));
    flash('¡Listo! Recibimos su pedido de cotización. Le respondemos en ❓ Soporte y por WhatsApp (normalmente el mismo día).');
    ir('admin.php?p=mi_plan');
}
// (20 oct 2026) el cliente solo renueva SU plan con su valor; otro plan (o contratar desde la demo) se cotiza
$ver_precios = ver_precios_software();
$plan_propio = empty($liga['es_prueba']) ? plan_de_liga($liga) : null;
if (!$ver_precios && es_post() && in_array(post('accion'), ['comprobante', 'pagar'], true)) {
    $pid_post = post('accion') === 'pagar' ? (int)post('plan_id') : (int)explode(':', post('plan_meses') . ':0')[0];
    if (!$plan_propio || $pid_post !== (int)$plan_propio['id']) throw new RuntimeException('Para contratar o cambiar de plan, pida su cotización aquí mismo en Mi plan.');
}

$play = en_app_play(); // (v38) dentro de la app de Google Play el plan solo se paga con Google
if ($play && es_post() && in_array(post('accion'), ['comprobante', 'pagar'], true)) throw new RuntimeException('En la app de Google Play el plan se paga con Google Play.');
if (es_post() && post('accion') === 'comprobante') { // (v33) contratar / renovar por transferencia
    if (!puede('config', true)) throw new RuntimeException('Solo el administrador puede contratar o renovar el plan.');
    [$pid, $ms] = array_map('intval', explode(':', post('plan_meses') . ':0'));
    crear_solicitud_pago($liga, $yo, $pid, $ms, post('forma_pago'), post('nota'), isset($_POST['deportes']) ? leer_deportes_post() : null); // (v35) con los deportes elegidos
    flash('¡Recibimos su comprobante! Lo revisamos y le avisamos por correo y Telegram apenas quede activa su cuenta (normalmente el mismo día).');
    ir('admin.php?p=mi_plan#contratar');
}
if (es_post() && post('accion') === 'pagar') {
    if (!pasarela_activa()) throw new RuntimeException('El pago con tarjeta todavía no está disponible. Use la transferencia y envíe el comprobante por WhatsApp.');
    $pl = fila("SELECT * FROM planes WHERE id = ? AND activo = 1 AND tipo_cuenta IN ('ambos', ?)", [(int)post('plan_id'), $tipo]) ?? throw new RuntimeException('Elija un plan.');
    $meses = (int)post('meses');
    $op = null;
    foreach (opciones_precio($pl, $liga) as $o) if ($o['meses'] === $meses) $op = $o;
    if (!$op) throw new RuntimeException('Elija por cuánto tiempo va a pagar.');
    $token = crear_pago_linea($liga, [
        'destino' => 'tuvocalia', 'concepto' => 'suscripcion', 'detalle' => 'Plan ' . $pl['nombre'] . ' · ' . $op['nombre'] . ' (' . $meses . ' mes' . ($meses === 1 ? '' : 'es') . ')' . ($op['combo'] ? ' · combo ' . (float)combo_descuento() . '%' : '') . ' · ' . $liga['nombre'],
        'plan_id' => (int)$pl['id'], 'meses' => $meses, 'monto_base' => $op['precio'],
        'pagador_nombre' => $yo['nombre'], 'pagador_email' => $yo['email'], 'pagador_telefono' => (string)$liga['contacto_telefono'],
        'datos' => ['volver' => 'admin.php?p=mi_plan'],
    ]);
    ir(url_pago($token));
}

$sus = estado_suscripcion($liga);
$plan = plan_de_liga($liga);
$lim = limites_liga($liga);
$mods = modulos_liga($liga);
$planes = filas("SELECT * FROM planes WHERE activo = 1 AND tipo_cuenta IN ('ambos', ?) ORDER BY orden, precio_mensual", [$tipo]);
$wa = telefono_wa((string)ajuste('ventas_whatsapp'));
$pagos = filas('SELECT ps.*, p.nombre AS plan FROM pagos_suscripcion ps LEFT JOIN planes p ON p.id = ps.plan_id WHERE ps.liga_id = ? ORDER BY ps.id DESC LIMIT 12', [$lid]);
cabecera_admin('Mi plan');
?>
<h1>⭐ Mi plan</h1>
<div class="rejilla-2">
  <div class="tarjeta">
    <h2 style="margin-top:0"><?= $plan ? e($plan['nombre']) : ($sus['estado'] === 'prueba' ? 'Demo' : 'Sin plan') ?></h2>
    <p><?= e($sus['texto']) ?></p>
    <?php if ($sus['estado'] === 'prueba'): ?><p class="aviso aviso-info">Durante la demo tiene <b>todos los módulos</b>, menos los exclusivos de las cuentas pagadas (fixture para imprimir y esquema del campeonato, subida desde Excel y descarga de datos). Al contratar, queda con los del plan que elija.</p><?php endif; ?>
    <ul>
      <?php if (!es_academia() || !es_escuela($liga)): // (arreglos 14-oct) las academias no tienen campeonatos ?><li><?= e(texto_limite_campeonatos($liga)) ?></li><?php endif; ?>
      <?php if ($tipo === 'liga'): ?><li>Equipos: <?= (int)$lim['usados_equipos'] ?><?= $lim['equipos'] ? ' de ' . (int)$lim['equipos'] : ' (sin límite)' ?><?= $plan && (int)$plan['max_equipos_torneo'] ? ' · hasta ' . (int)$plan['max_equipos_torneo'] . ' por torneo' : '' ?></li>
      <?php else: ?><li>Alumnos: <?= (int)valor("SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ? AND estado IN ('activo','pendiente')", [$lid]) ?><?= $plan && (int)$plan['max_alumnos'] ? ' de ' . (int)$plan['max_alumnos'] : ' (sin límite)' ?></li><?php endif; ?>
    </ul>
  </div>
  <div class="tarjeta">
    <h2 style="margin-top:0">🧩 Módulos</h2>
    <?php $mc = modulos_cliente($liga); ?>
    <p style="margin-top:0">Tiene <b><?= count($mods) ?></b> de <?= count(catalogo_modulos($tipo)) ?> módulos encendidos.</p>
    <?php if ($ver_precios || $plan_propio): ?><p>Su mensual: <b><?= dinero($mc['mensual']) ?> al mes</b><?= $mc['precio_acordado'] !== null ? ' (precio acordado)' : '' ?>.</p>
    <?php else: ?><p>El valor de su plan se lo cotizamos a su medida.</p><?php endif; ?>
    <p><a class="btn btn-sec btn-chico" href="#modulos">Ver mis módulos y agregar más</a></p>
  </div>
</div>
<?= html_mi_plan_modulos($liga, puede('config', true)) // (Anexo B) ?>

<div class="tarjeta" style="margin-top:14px" id="deportes">
  <h2 style="margin-top:0">🧩 Deportes de su cuenta</h2>
  <p style="margin-top:0">Tiene: <b><?= e(texto_modulos(deportes_liga($liga))) ?></b><?= !empty($liga['es_demo']) ? '' : ($ver_precios ? ' · ' . e(texto_precio_modulos(deportes_cobrados($liga))) : '') ?></p>
  <p class="suave">Todos sus deportes quedan en esta misma cuenta: un solo panel, una sola página pública, los mismos usuarios y las mismas finanzas. <?= $play ? 'Para agregar un deporte, use <a href="#play-caja">Deportes adicionales</a> más abajo.' : ($ver_precios ? 'Para agregar o quitar un deporte, márquelo en <a href="#contratar">Contratar o renovar</a> al enviar su comprobante.' : 'Agregar otro deporte o disciplina es <b>por cotización</b>: ' . html_boton_cotizacion('deportes')) ?></p>
</div>
<?= html_modulos_bloqueados($liga) ?>

<?php // (v16) Combo liga + escuela (en la app de Google Play no aplica: Google cobra el precio de su tienda)
$combo = $play ? null : combo_de($liga);
if ($play):
elseif ($combo): $pl_c = plan_de_liga($combo); ?>
<div class="tarjeta combo-caja">
  <h2 style="margin-top:0">🤝 Combo <?= $tipo === 'escuela' ? 'escuela + liga' : 'liga + escuela' ?><?= $ver_precios ? ': ' . e((string)(float)combo_descuento()) . '% de descuento' : '' ?></h2>
  <p>Esta cuenta está en combo con <b><?= e($combo['nombre']) ?></b> (<?= ($combo['tipo'] ?? 'liga') === 'escuela' ? 'escuela' : 'liga' ?><?= $pl_c ? ', plan ' . e($pl_c['nombre']) : '' ?> · <?= e(estado_suscripcion($combo)['texto']) ?>). <?= $ver_precios ? 'Los precios de abajo ya tienen el descuento; la otra cuenta también lo tiene en su propio plan.' : 'El valor de su plan ya tiene el precio especial del combo.' ?></p>
  <?php if (!es_super()): ?><p><a class="btn btn-sec" href="admin.php?combo=<?= (int)$combo['id'] ?>">🔁 Ir a <?= e($combo['nombre']) ?></a></p><?php endif; ?>
</div>
<?php elseif (combo_descuento() > 0): ?>
<div class="tarjeta combo-caja">
  <h2 style="margin-top:0">🤝 ¿También tiene una <?= $tipo === 'escuela' ? 'liga o campeonato' : 'escuela deportiva' ?>?</h2>
  <p>Con el <b>combo liga + escuela</b> maneja las dos con este mismo usuario <?= $ver_precios ? 'y paga <b>' . e((string)(float)combo_descuento()) . '% menos</b> en cada plan.' : 'con un precio especial en los dos planes (por cotización).' ?><?= $wa ? ' <a target="_blank" href="https://wa.me/' . e($wa) . '?text=' . rawurlencode('Hola, soy de ' . $liga['nombre'] . '. Quiero el combo liga + escuela de ' . marca() . '.') . '">Pídalo por WhatsApp</a>.' : '' ?></p>
</div>
<?php endif; ?>
<?php if (nota_dominio() !== '' && array_filter($planes, fn($p) => (int)($p['dominio_anual'] ?? 0))): ?><p class="suave" style="font-size:.85rem">* <?= e(nota_dominio()) ?></p><?php endif; ?>

<h2>Renovar o cambiar de plan</h2>
<?php if ($play): // (v38) app de Google Play ?>
<?= html_mi_plan_play($liga, $planes) ?>
<?php else: ?>
<?php if (!pasarela_activa()): ?>
  <div class="aviso aviso-info"><?= $ver_precios ? 'Elija su plan, pague por transferencia y <a href="#contratar"><b>suba el comprobante aquí mismo</b></a>: lo activamos el mismo día.' : ($plan_propio ? 'Para renovar su plan, pague por transferencia y <a href="#contratar"><b>suba el comprobante aquí mismo</b></a>: lo activamos el mismo día. Otro plan se cotiza a su medida.' : 'Su plan se cotiza a su medida: <a href="#contratar"><b>pida su cotización</b></a> y le respondemos el mismo día.') ?><?= $wa ? ' ¿Dudas? <a target="_blank" href="https://wa.me/' . e($wa) . '?text=' . rawurlencode('Hola, soy de ' . $liga['nombre'] . '. Tengo una consulta sobre mi plan de ' . marca() . '.') . '">Escríbanos por WhatsApp</a>.' : '' ?></div>
<?php endif; ?>
<div class="planes">
  <?php foreach ($planes as $pl): $ops = opciones_precio($pl, $liga); if (!$ops) continue;
    $es_propio = $plan_propio && (int)$plan_propio['id'] === (int)$pl['id']; ?>
    <div class="plan<?= $plan && (int)$plan['id'] === (int)$pl['id'] ? ' plan-destacado' : '' ?>">
      <?php if ($plan && (int)$plan['id'] === (int)$pl['id']): ?><span class="plan-cinta">Su plan</span><?php endif; ?>
      <h3><?= e($pl['nombre']) ?></h3>
      <p class="suave" style="margin:0 0 6px"><?= e($pl['eslogan']) ?></p>
      <ul><?php foreach (textos_limites_plan($pl, $tipo) as $t): ?><li><?= e($t) ?></li><?php endforeach; ?><li><?= count(array_intersect(modulos_plan($pl), array_keys(modulos_sistema($tipo)))) ?> de <?= count(modulos_sistema($tipo)) ?> módulos · <a target="_blank" href="<?= e(url_plan($pl)) ?>">ver detalle</a></li></ul>
      <?php if (!$ver_precios && !$es_propio): // (20 oct 2026) un plan que no tiene: por cotización ?>
        <p><b>Por cotización</b></p><p><?= html_boton_cotizacion('plan:' . (int)$pl['id']) ?></p>
      <?php elseif (pasarela_activa()): ?>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="pagar"><input type="hidden" name="plan_id" value="<?= (int)$pl['id'] ?>">
          <select name="meses" style="width:100%;margin-bottom:8px"><?php foreach ($ops as $o): ?><option value="<?= $o['meses'] ?>"><?= e($o['nombre']) ?>: <?= dinero($o['precio']) ?><?= $o['combo'] ? ' (antes ' . dinero($o['precio_normal']) . ')' : '' ?><?= $o['gratis'] ? ' (' . texto_ahorro($o['gratis']) . ')' : '' ?><?= $o['dominio'] ? ' + dominio incluido*' : '' ?></option><?php endforeach; ?></select>
          <button class="btn btn-grande">💳 Pagar con tarjeta</button></form>
      <?php else: ?>
        <p><?php foreach ($ops as $o): ?><?= $o['combo'] ? '<s class="suave">' . dinero($o['precio_normal']) . '</s> ' : '' ?><b><?= dinero($o['precio']) ?></b> <?= e($o['texto']) ?><?= $o['gratis'] ? ' <small class="suave">(' . texto_ahorro($o['gratis']) . ')</small>' : '' ?><?= $o['dominio'] ? ' <small><b>+ dominio incluido*</b></small>' : '' ?><br><?php endforeach; ?></p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?= html_contratar_transferencia($liga, $planes) // (v33) ?>
<?php endif; // fin de la parte que no va en la app de Google Play ?>
<?php if ($pagos): ?>
<h2>Mis pagos</h2>
<div class="tabla-scroll"><table><tr><th>Fecha</th><th>Plan</th><th class="num">Meses</th><th>Vigencia</th><th class="num">Valor</th><th>Forma</th></tr>
  <?php foreach ($pagos as $x): ?><tr><td><?= e($x['fecha']) ?></td><td><?= e($x['plan'] ?? '—') ?></td><td class="num"><?= (int)$x['meses'] ?></td><td><?= e($x['desde'] . ' → ' . $x['hasta']) ?></td><td class="num"><?= dinero($x['monto']) ?></td><td><?= e(nombre_forma_pago($x['forma_pago'])) ?></td></tr><?php endforeach; ?>
</table></div>
<?php endif;
pie_admin();
