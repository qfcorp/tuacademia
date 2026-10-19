<?php
/* (v12) FOLLETO DE UN PLAN: /planes/basico  (+ ?pdf=1 versión para imprimir o guardar en PDF) */
$pl = plan_por_slug(get('plan'));
if (!$pl) {
    http_response_code(404);
    cabecera_publica('Plan no encontrado');
    echo '<div class="tarjeta login"><h1>Plan no encontrado</h1><p><a href="index.php#planes">Ver todos los planes</a></p></div>';
    pie_publico();
    exit;
}
$ops = opciones_precio($pl);
$mp = modulos_plan($pl);
$tipo = $pl['tipo_cuenta'] === 'escuela' ? 'escuela' : 'liga';
$mods = modulos_sistema($pl['tipo_cuenta'] === 'ambos' ? '' : $pl['tipo_cuenta']);
$incluye = array_intersect_key($mods, array_flip($mp));
$no = array_diff_key($mods, array_flip($mp));
$extras = array_filter(array_map('trim', explode("\n", $pl['descripcion'])));
$wa = telefono_wa((string)ajuste('ventas_whatsapp'));
$url = url_plan($pl);
$siguiente = null;
if ($no) {
    foreach (filas("SELECT * FROM planes WHERE activo = 1 AND id <> ? AND precio_mensual > 0 AND tipo_cuenta IN ('ambos', ?) ORDER BY orden, precio_mensual", [$pl['id'], $tipo]) as $x) {
        if (!array_diff(array_keys($no), modulos_plan($x)) || count(modulos_plan($x)) > count($mp)) { $siguiente = $x; if (!array_diff(array_keys($no), modulos_plan($x))) break; }
    }
}

/** Contenido del folleto (se usa en la página y en el PDF). */
$contenido = function (bool $pdf) use ($pl, $ops, $incluye, $no, $extras, $tipo, $siguiente, $url) {
    ?>
  <div class="folleto">
    <div class="folleto-cab">
      <div><small><?= e(marca()) ?> · Plan</small><h1><?= e($pl['nombre']) ?></h1><?php if ($pl['eslogan'] !== ''): ?><p><?= e($pl['eslogan']) ?></p><?php endif; ?></div>
    </div>
    <h2>💲 Precio</h2>
    <div class="tabla-scroll"><table class="<?= $pdf ? 'pl' : '' ?>"><tr><th>Forma de pago</th><th class="num">Precio</th><th class="num">Equivale al mes</th><th>Beneficio</th></tr>
      <?php foreach ($ops as $o): ?><tr><td><?= e($o['nombre']) ?> (<?= $o['meses'] ?> mes<?= $o['meses'] === 1 ? '' : 'es' ?>)</td><td class="num"><b><?= dinero($o['precio']) ?></b></td><td class="num"><?= dinero($o['por_mes']) ?></td><td><?= $o['gratis'] ? texto_ahorro($o['gratis']) . '' : '—' ?><?= !empty($o['dominio']) ? ' + 🌐 dominio propio incluido*' : '' ?></td></tr><?php endforeach; ?>
    </table></div>
    <h2>📏 Capacidad</h2>
    <ul><?php foreach (textos_limites_plan($pl) as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ul>
    <h2>✅ Incluido siempre</h2>
    <ul class="dos-columnas"><?php foreach (funciones_base($tipo) as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ul>
    <h2>🧩 Módulos incluidos (<?= count($incluye) ?>)</h2>
    <?php foreach ($incluye as [$ico, $nom, $para, $desc]): ?>
      <div class="folleto-mod"><b><?= $ico ?> <?= e($nom) ?></b><?= $para !== 'ambos' ? ' <small class="suave">(' . ($para === 'liga' ? 'ligas' : 'escuelas') . ')</small>' : '' ?><br><?= e($desc) ?></div>
    <?php endforeach; ?>
    <?php if ($extras): ?><h2>➕ Además</h2><ul><?php foreach ($extras as $li): ?><li><?= e($li) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if (isset($incluye['pagos_tarjeta'])): ?>
      <h2>💳 Cobros con tarjeta</h2>
      <p>Sus <?= $tipo === 'escuela' ? 'familias' : 'equipos' ?> pagan con tarjeta de crédito o débito; el recibo sale solo y usted recibe la transferencia en su cuenta. Costo por transacción: <?= e(texto_tasas()) ?>. Usted elige si la comisión la asume su organización o el que paga.</p>
    <?php endif; ?>
    <?php if ($no): ?>
      <h2>No incluye</h2>
      <p class="suave"><?= e(implode(' · ', array_map(fn($m) => $m[1], $no))) ?>.<?= $siguiente ? ' Los tiene en el plan ' . e($siguiente['nombre']) . '.' : '' ?></p>
    <?php endif; ?>
    <?php if (combo_descuento() > 0): ?><h2>🤝 Combo liga + escuela</h2><p>Si también tiene una <?= $tipo === 'escuela' ? 'liga o campeonato' : 'escuela deportiva' ?>, contrate las dos con el mismo usuario y pague <b><?= e((string)(float)combo_descuento()) ?>% menos</b> en cada plan.</p><?php endif; ?>
    <?php if ((int)($pl['dominio_anual'] ?? 0) && nota_dominio() !== ''): ?><p class="suave" style="font-size:.85rem">* <?= e(nota_dominio()) ?></p><?php endif; ?>
    <p class="suave">Solicite su demo: le preparamos un acceso de prueba con todos los módulos. Precios en dólares. <?= e($url) ?></p>
  </div>
    <?php
};

if (get('pdf') === '1') {
    doc_inicio('Plan ' . $pl['nombre'] . ' · ' . marca(), $url);
    echo '<style>.folleto h1{font-size:30px;margin:0;color:#000}.folleto h2{margin-top:14px}.folleto-mod{border-left:3px solid #0b6e4f;padding:4px 8px;margin:6px 0}.dos-columnas{columns:2}.folleto-cab small{letter-spacing:.1em;text-transform:uppercase}</style>';
    $contenido(true);
    doc_fin();
    exit;
}

cabecera_publica('Plan ' . $pl['nombre'] . ' · ' . marca());
?>
<p><a href="<?= $tipo === 'escuela' ? 'escuelas' : 'index.php' ?>#planes">← Todos los planes</a> · <a href="comparar<?= $tipo === 'escuela' ? '?para=escuelas' : '' ?>">Comparar planes</a></p>
<div class="tarjeta">
  <?php $contenido(false); ?>
  <div class="acciones" style="margin-top:14px">
    <a class="btn btn-grande" href="<?= e(url_empezar($tipo === 'escuela' ? 'escuela' : '', (int)$pl['id'])) ?>">📝 Solicitar demo</a>
    <?php if ($wa && !en_app_play()): // (v38) en la app de Google Play el plan solo se contrata con Google ?><a class="btn btn-grande btn-wa" target="_blank" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola, quiero el plan ' . $pl['nombre'] . ' de ' . marca()) ?>">💬 Quiero este plan</a><?php endif; ?>
  </div>
  <div class="acciones" style="margin-top:10px">
    <a class="btn btn-sec btn-chico" target="_blank" href="<?= e($url) ?>?pdf=1">📄 Descargar PDF</a>
    <a class="btn btn-sec btn-chico" target="_blank" href="imagen.php?tipo=plan&amp;plan=<?= (int)$pl['id'] ?>">🖼 Imagen para WhatsApp</a>
    <a class="btn btn-sec btn-chico" target="_blank" href="https://wa.me/?text=<?= rawurlencode('Plan ' . $pl['nombre'] . ' de ' . marca() . ': ' . texto_precio_principal($pl) . "\n" . $url) ?>">📲 Compartir</a>
  </div>
</div>
<?php
pie_publico();
