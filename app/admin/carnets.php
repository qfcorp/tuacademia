<?php
/* Carnets imprimibles (tamaño tarjeta 85,6 × 54 mm, 10 por hoja A4) de un equipo o de un jugador */
$liga = requiere_liga();
$lid = (int)$liga['id'];

if (get('jugador') !== '') {
    $j = jugador_de_liga((int)get('jugador'), $lid);
    $lista = [fila('SELECT j.*, e.nombre AS equipo, e.escudo, e.color FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.id = ?', [$j['id']])];
    $volver = 'admin.php?p=jugador&id=' . (int)$j['id'];
} else {
    $eq = equipo_de_liga((int)get('equipo'), $lid);
    $lista = filas('SELECT j.*, e.nombre AS equipo, e.escudo, e.color FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.equipo_id = ? AND j.activo = 1 ORDER BY (j.numero IS NULL), j.numero, j.apellidos', [$eq['id']]);
    $volver = 'admin.php?p=equipo&id=' . (int)$eq['id'];
}
$anio = date('Y');
$t_activo = fila("SELECT nombre, temporada FROM torneos WHERE liga_id = ? AND estado <> 'finalizado' ORDER BY id DESC LIMIT 1", [$lid]);
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Carnets</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="assets/estilo.css?v=330">
<script src="assets/js/qrcode.js"></script>
<style>
  @page { size: A4; margin: 10mm; }
  body { background: #fff; color: #000; }
  * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .hoja { display: grid; grid-template-columns: repeat(2, 85.6mm); gap: 4mm 6mm; justify-content: center; }
  .carnet { width: 85.6mm; height: 54mm; border: .3mm solid #999; border-radius: 3mm; overflow: hidden; position: relative; background: #fff; page-break-inside: avoid; font-family: Arial, Helvetica, sans-serif; }
  .c-cab { background: <?= e($liga['color']) ?>; color: #fff; height: 11mm; display: flex; align-items: center; gap: 2mm; padding: 0 3mm; }
  .c-cab img, .c-cab .escudo-vacio { width: 8mm; height: 8mm; border-radius: 50%; background: #fff; object-fit: cover; font-size: 7pt; }
  .c-cab b { font-size: 8.5pt; line-height: 1.1; display: block; }
  .c-cab small { font-size: 6pt; color: rgba(255,255,255,.9); display: block; }
  .c-cuerpo { display: flex; gap: 2.5mm; padding: 2.5mm 3mm; }
  .c-foto { width: 22mm; height: 27mm; border-radius: 1.5mm; object-fit: cover; background: #ddd; flex: none; display: flex; align-items: center; justify-content: center; font-size: 14pt; font-weight: 800; color: #fff; }
  .c-datos { flex: 1; min-width: 0; font-size: 7pt; line-height: 1.35; }
  .c-nombre { font-size: 9pt; font-weight: 800; line-height: 1.1; margin-bottom: 1mm; text-transform: uppercase; }
  .c-num { position: absolute; right: 25mm; bottom: 3mm; font-size: 16pt; font-weight: 900; color: <?= e($liga['color']) ?>; }
  .c-qr { width: 20mm; height: 20mm; flex: none; align-self: flex-end; }
  .c-qr svg { width: 100%; height: 100%; }
  .c-pie { position: absolute; bottom: 1.2mm; left: 3mm; font-size: 5.5pt; color: #555; }
  .barra-doc { background: #f3f5f4; padding: 10px; border-radius: 8px; margin: 10px auto; max-width: 900px; }
  @media print { .barra-doc { display: none; } }
</style>
</head>
<body>
<div class="barra-doc">
  <button class="btn" onclick="window.print()">🖨 Imprimir / Guardar PDF</button> <a class="btn btn-sec" href="<?= e($volver) ?>">Volver</a>
  <p style="margin:8px 0 0;font-size:13px">Tamaño real de tarjeta (85,6 × 54 mm), 10 por hoja A4. Al imprimir elija <b>Escala 100%</b> (sin "ajustar a la página"). Puede imprimir en cartulina y plastificar, o llevar el PDF a una imprenta de carnets PVC.
  <?= !$lista ? '<br><b>Este equipo no tiene jugadores activos.</b>' : '' ?></p>
</div>
<?php $pat_c = patrocinadores_activos((int)$liga['id'], 'en_carnets')[0] ?? null; ?>
<div class="hoja">
<?php foreach ($lista as $j): ?>
  <div class="carnet">
    <div class="c-cab"><?= escudo($liga['logo'], $liga['nombre'], $liga['color'], 'x') ?><div><b><?= e($liga['nombre']) ?></b><small>CARNET DE JUGADOR · <?= e($t_activo['temporada'] ?? $anio) ?: $anio ?></small></div></div>
    <div class="c-cuerpo">
      <?php if ($j['foto']): ?><img class="c-foto" src="<?= e($j['foto']) ?>" alt=""><?php else: ?><div class="c-foto" style="background:#8a9591"><?= e(iniciales(nombre_jugador($j))) ?></div><?php endif; ?>
      <div class="c-datos">
        <div class="c-nombre"><?= e(nombre_jugador($j)) ?></div>
        <div><b>Equipo:</b> <?= e($j['equipo']) ?></div>
        <?php if ($j['cedula'] !== ''): ?><div><b>C.I.:</b> <?= e($j['cedula']) ?></div><?php endif; ?>
        <?php if ($j['fecha_nac'] !== ''): ?><div><b>Nac.:</b> <?= e($j['fecha_nac']) ?></div><?php endif; ?>
        <?php if ($j['posicion'] !== ''): ?><div><b>Posición:</b> <?= e($j['posicion']) ?></div><?php endif; ?>
      </div>
      <div class="c-qr" data-url="<?= e(url_carnet($j)) ?>"></div>
    </div>
    <?php if ($j['numero'] !== null): ?><div class="c-num"><?= (int)$j['numero'] ?></div><?php endif; ?>
    <div class="c-pie">Escanee el QR para verificar si está habilitado<?php if ($pat_c): ?> · <?= $pat_c['logo'] !== '' ? '<img src="' . e($pat_c['logo']) . '" alt="" style="height:3.2mm;vertical-align:middle">' : '' ?> <?= e($pat_c['nombre']) ?><?php endif; ?></div>
  </div>
<?php endforeach; ?>
</div>
<script>
document.querySelectorAll('.c-qr').forEach(function (el) {
  var q = qrcode(0, 'M');
  q.addData(el.getAttribute('data-url'));
  q.make();
  el.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
});
</script>
</body>
</html>
