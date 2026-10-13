<?php
/* (v7) Patrocinadores: espacios vendibles y reporte de vistas y clics para mostrarle al patrocinador */
$liga = requiere_liga();
$lid = (int)$liga['id'];

$desde = get('desde') !== '' && strtotime(get('desde')) ? date('Y-m-d', strtotime(get('desde'))) : date('Y-m-01');
$hasta = get('hasta') !== '' && strtotime(get('hasta')) ? date('Y-m-d', strtotime(get('hasta'))) : date('Y-m-d');

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'guardar') {
        $id = (int)post('id');
        $actual = $id ? fila('SELECT * FROM patrocinadores WHERE id = ? AND liga_id = ?', [$id, $lid]) : null;
        if ($id && !$actual) throw new RuntimeException('Patrocinador no encontrado.');
        if (mb_strlen(post('nombre')) < 2) throw new RuntimeException('Escriba el nombre del patrocinador.');
        $enlace = post('enlace');
        if ($enlace !== '' && !preg_match('#^https?://#i', $enlace)) $enlace = 'https://' . $enlace;
        if ($enlace !== '' && !filter_var($enlace, FILTER_VALIDATE_URL)) throw new RuntimeException('La dirección web no es válida. Ejemplo: https://www.empresa.com');
        $fecha = fn(string $k) => post($k) !== '' && strtotime(post($k)) ? date('Y-m-d', strtotime(post($k))) : '';
        $d = [
            'nombre' => mb_substr(post('nombre'), 0, 80), 'enlace' => mb_substr($enlace, 0, 300), 'texto' => mb_substr(post('texto'), 0, 120),
            'contacto' => mb_substr(post('contacto'), 0, 120), 'desde' => $fecha('desde'), 'hasta' => $fecha('hasta'),
            'valor' => max(0, round((float)str_replace(',', '.', post('valor')), 2)), 'activo' => post('activo') === '1' ? 1 : 0, 'orden' => entero(post('orden'), 0, 99),
        ];
        foreach (array_keys(lugares_patrocinio()) as $l) $d[$l] = post($l) === '1' ? 1 : 0;
        if ($logo = subir_imagen('logo', 'patrocinadores', 600)) { if ($actual) borrar_imagen($actual['logo']); $d['logo'] = $logo; }
        if ($ban = subir_imagen('banner', 'patrocinadores', 1400)) { if ($actual) borrar_imagen($actual['banner']); $d['banner'] = $ban; }
        if ($actual && post('quitar_banner') === '1') { borrar_imagen($actual['banner']); $d['banner'] = ''; }
        if ($actual) {
            $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($d)));
            q("UPDATE patrocinadores SET $sets WHERE id = :id", $d + ['id' => $id]);
            flash('Patrocinador guardado.');
        } else {
            $d['liga_id'] = $lid;
            q('INSERT INTO patrocinadores (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($d))) . ')', $d);
            flash('Patrocinador agregado. Ya aparece en los lugares que marcó.');
        }
        ir('admin.php?p=patrocinadores');
    }
    if ($accion === 'eliminar') {
        $x = fila('SELECT * FROM patrocinadores WHERE id = ? AND liga_id = ?', [(int)post('id'), $lid]) ?? throw new RuntimeException('Patrocinador no encontrado.');
        borrar_imagen($x['logo']);
        borrar_imagen($x['banner']);
        q('DELETE FROM patrocinadores WHERE id = ?', [$x['id']]);
        flash('Patrocinador eliminado.');
        ir('admin.php?p=patrocinadores');
    }
}

/* Reporte imprimible para entregar al patrocinador */
if (get('reporte') !== '') {
    $x = fila('SELECT * FROM patrocinadores WHERE id = ? AND liga_id = ?', [(int)get('reporte'), $lid]) ?? throw new RuntimeException('Patrocinador no encontrado.');
    $r = reporte_patrocinio((int)$x['id'], $desde, $hasta);
    doc_inicio('Reporte ' . $x['nombre'], 'admin.php?p=patrocinadores');
    ?>
  <div class="cab"><?= escudo($liga['logo'], $liga['nombre'], $liga['color'], 'escudo-grande') ?><div><h1><?= e($liga['nombre']) ?> — Reporte de patrocinio</h1><div><?= e(url_liga($liga)) ?></div></div></div>
  <h2 style="margin-top:16px"><?= e($x['nombre']) ?><?= $x['logo'] !== '' ? ' <img src="' . e($x['logo']) . '" alt="" style="height:40px;vertical-align:middle;margin-left:8px">' : '' ?></h2>
  <p>Período: <b><?= e(date('d/m/Y', strtotime($desde))) ?></b> al <b><?= e(date('d/m/Y', strtotime($hasta))) ?></b></p>
  <table class="pl">
    <tr><th>Veces que se mostró su marca</th><th>Clics hacia su página</th><th>Partidos "presentados por"</th><th>Votos en sus votaciones</th></tr>
    <tr style="font-size:22px;text-align:center"><td><b><?= number_format((int)$r['total']['vistas'], 0, ',', '.') ?></b></td><td><b><?= number_format((int)$r['total']['clics'], 0, ',', '.') ?></b></td><td><b><?= (int)$r['partidos'] ?></b></td><td><b><?= (int)$r['votos'] ?></b></td></tr>
  </table>
  <?php if ($r['por_lugar']): ?>
  <h2>Por lugar</h2>
  <table class="pl"><tr><th>Lugar</th><th>Vistas</th><th>Clics</th></tr>
    <?php foreach ($r['por_lugar'] as $l): ?><tr><td><?= e(nombre_lugar_patrocinio($l['lugar'])) ?></td><td><?= (int)$l['vistas'] ?></td><td><?= (int)$l['clics'] ?></td></tr><?php endforeach; ?>
  </table>
  <?php endif; ?>
  <?php if ($r['por_dia']): $max = max(array_map(fn($d) => (int)$d['vistas'], $r['por_dia'])) ?: 1; ?>
  <h2>Día por día</h2>
  <table class="pl"><tr><th style="width:110px">Fecha</th><th>Vistas</th><th style="width:70px">Clics</th></tr>
    <?php foreach ($r['por_dia'] as $d): ?><tr><td><?= e(date('d/m/Y', strtotime($d['fecha']))) ?></td><td><div style="display:flex;align-items:center;gap:6px"><div style="height:12px;background:<?= e($liga['color']) ?>;width:<?= max(1, (int)round(300 * (int)$d['vistas'] / $max)) ?>px"></div><?= (int)$d['vistas'] ?></div></td><td><?= (int)$d['clics'] ?></td></tr><?php endforeach; ?>
  </table>
  <?php endif; ?>
  <p style="margin-top:16px;font-size:12px;color:#555">Dónde aparece su marca: <?= e(implode(' · ', array_map(fn($k) => lugares_patrocinio()[$k], array_filter(array_keys(lugares_patrocinio()), fn($k) => (int)$x[$k] === 1)))) ?: '—' ?>.
    Las vistas se cuentan cada vez que una persona abre una página donde aparece su marca (no se cuentan buscadores ni robots). Página pública: <?= e(url_liga($liga)) ?></p>
    <?php
    doc_fin();
    exit;
}

$lista = filas('SELECT * FROM patrocinadores WHERE liga_id = ? ORDER BY activo DESC, orden, nombre', [$lid]);
$editar = get('editar') !== '' ? fila('SELECT * FROM patrocinadores WHERE id = ? AND liga_id = ?', [(int)get('editar'), $lid]) : null;
$v = $editar ?? ['id' => 0, 'nombre' => '', 'enlace' => '', 'texto' => '', 'contacto' => '', 'desde' => date('Y-m-d'), 'hasta' => '', 'valor' => 0, 'activo' => 1, 'orden' => 0,
    'logo' => '', 'banner' => '', 'en_pagina' => 1, 'en_partidos' => 1, 'en_imagenes' => 0, 'en_carnets' => 0];
$hoy = date('Y-m-d');
cabecera_admin('Patrocinadores');
subnav_publico('patrocinadores');
?>
<h1>🤝 Patrocinadores</h1>
<p class="suave">Venda espacios a empresas del barrio: su banner sale en la página pública de la liga, en los partidos ("Partido presentado por…"), en las imágenes para redes y en los carnets. El sistema cuenta cuántas veces se mostró su marca y cuántos clics recibió: imprima el <b>reporte</b> y entrégueselo al patrocinador para renovar el contrato.</p>

<?php if ($lista): ?>
<form method="get" class="acciones" style="margin-bottom:10px"><input type="hidden" name="p" value="patrocinadores">
  <label>Período del reporte: <input type="date" name="desde" value="<?= e($desde) ?>"></label> <label>al <input type="date" name="hasta" value="<?= e($hasta) ?>"></label>
  <button class="btn btn-sec btn-chico">Ver</button></form>
<div class="tabla-scroll"><table>
  <tr><th>Patrocinador</th><th>Dónde sale</th><th>Vigencia</th><th class="num">Vistas</th><th class="num">Clics</th><th></th></tr>
  <?php foreach ($lista as $x): $r = reporte_patrocinio((int)$x['id'], $desde, $hasta);
      $vigente = $x['activo'] && ($x['desde'] === '' || $x['desde'] <= $hoy) && ($x['hasta'] === '' || $x['hasta'] >= $hoy); ?>
  <tr>
    <td><div class="eq-celda"><?= $x['logo'] !== '' ? '<img src="' . e($x['logo']) . '" alt="" class="mini">' : '' ?> <b><?= e($x['nombre']) ?></b></div><?= $x['valor'] > 0 ? '<small class="suave">' . dinero($x['valor']) . '</small>' : '' ?></td>
    <td><small><?= e(implode(', ', array_map(fn($k) => ['en_pagina' => 'página', 'en_partidos' => 'partidos', 'en_imagenes' => 'imágenes', 'en_carnets' => 'carnets'][$k], array_filter(array_keys(lugares_patrocinio()), fn($k) => (int)$x[$k] === 1)))) ?: '—' ?></small></td>
    <td><?= $vigente ? '<span class="estado-chip chip-ok">Vigente</span>' : '<span class="estado-chip">No vigente</span>' ?><br><small><?= $x['desde'] !== '' ? e($x['desde']) : '…' ?> → <?= $x['hasta'] !== '' ? e($x['hasta']) : 'sin fin' ?></small></td>
    <td class="num"><?= number_format((int)$r['total']['vistas'], 0, ',', '.') ?></td><td class="num"><?= (int)$r['total']['clics'] ?></td>
    <td class="acciones">
      <a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=patrocinadores&amp;reporte=<?= (int)$x['id'] ?>&amp;desde=<?= e($desde) ?>&amp;hasta=<?= e($hasta) ?>">🖨 Reporte</a>
      <?php if (puede('comunicacion', true)): ?>
      <a class="btn btn-sec btn-chico" href="admin.php?p=patrocinadores&amp;editar=<?= (int)$x['id'] ?>#formulario">Editar</a>
      <form class="inline" method="post" onsubmit="return confirm('¿Eliminar este patrocinador y su reporte?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar</button></form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>

<?php if (puede('comunicacion', true)): ?>
<div class="tarjeta" id="formulario" style="margin-top:20px">
  <h2 style="margin-top:0"><?= $editar ? 'Editar: ' . e($editar['nombre']) : '+ Nuevo patrocinador' ?></h2>
  <form method="post" enctype="multipart/form-data" action="admin.php?p=patrocinadores">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
    <div class="campos">
      <div><label>Nombre de la empresa *</label><input type="text" name="nombre" required maxlength="80" value="<?= e($v['nombre']) ?>"></div>
      <div><label>Página web, Facebook o WhatsApp</label><input type="text" name="enlace" maxlength="300" value="<?= e($v['enlace']) ?>" placeholder="https://..."><div class="ayuda">Para WhatsApp: https://wa.me/5939XXXXXXXX</div></div>
      <div><label>Frase corta (opcional)</label><input type="text" name="texto" maxlength="120" value="<?= e($v['texto']) ?>" placeholder="Ej: Las mejores ferreterías del norte"></div>
      <div><label>Logo</label><input type="file" name="logo" accept="image/*"><?= $v['logo'] !== '' ? '<div class="ayuda"><img src="' . e($v['logo']) . '" alt="" class="mini"> ya tiene logo</div>' : '' ?></div>
      <div><label>Banner horizontal (opcional)</label><input type="file" name="banner" accept="image/*"><div class="ayuda">Imagen ancha (ej: 1200 × 200). Si no hay, se muestra el logo con el nombre.<?= $v['banner'] !== '' ? ' <label style="display:inline;font-weight:400"><input type="checkbox" name="quitar_banner" value="1"> quitar el banner actual</label>' : '' ?></div></div>
      <div><label>Desde</label><input type="date" name="desde" value="<?= e($v['desde']) ?>"></div>
      <div><label>Hasta</label><input type="date" name="hasta" value="<?= e($v['hasta']) ?>"><div class="ayuda">Vacío = sin fecha de fin.</div></div>
      <div><label>Valor del contrato ($)</label><input type="number" name="valor" min="0" step="0.01" value="<?= e(number_format((float)$v['valor'], 2, '.', '')) ?>"><div class="ayuda">Solo para su control (cóbrelo en Finanzas).</div></div>
      <div><label>Contacto del patrocinador</label><input type="text" name="contacto" maxlength="120" value="<?= e($v['contacto']) ?>" placeholder="Nombre y teléfono"></div>
      <div><label>Orden</label><input type="number" name="orden" min="0" max="99" value="<?= (int)$v['orden'] ?>"></div>
    </div>
    <h3>¿Dónde aparece?</h3>
    <?php foreach (lugares_patrocinio() as $k => $txt): ?><label class="check" style="font-weight:400"><input type="checkbox" name="<?= $k ?>" value="1" <?= (int)$v[$k] === 1 ? 'checked' : '' ?>> <?= e($txt) ?></label><?php endforeach; ?>
    <p class="ayuda">Si varios patrocinadores salen en la página, se van turnando en cada visita. En imágenes y carnets sale el primero de la lista (use "Orden").</p>
    <label class="check"><input type="checkbox" name="activo" value="1" <?= (int)$v['activo'] === 1 ? 'checked' : '' ?>> Activo</label>
    <div class="acciones" style="margin-top:10px"><button class="btn">Guardar patrocinador</button><?php if ($editar): ?><a class="btn btn-sec" href="admin.php?p=patrocinadores">Cancelar</a><?php endif; ?></div>
  </form>
</div>
<?php endif;
pie_admin();
