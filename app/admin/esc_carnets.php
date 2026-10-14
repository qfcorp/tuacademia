<?php
/* (v11) Carnets de los alumnos con QR (tamaño tarjeta, 10 por hoja A4) */
$liga = requiere_liga();
$lid = (int)$liga['id'];
if (get('alumno') !== '') {
    $lista = [alumno_de_escuela((int)get('alumno'), $lid)];
} else {
    $gid = (int)get('g');
    $lista = filas("SELECT a.*, g.nombre AS grupo FROM esc_alumnos a LEFT JOIN esc_grupos g ON g.id = a.grupo_id WHERE a.liga_id = ? AND a.estado = 'activo'" . ($gid ? ' AND ' . sql_en_grupo('a', $gid) : '') . ' ORDER BY g.nombre, a.apellidos', [$lid]);
}
doc_inicio('Carnets ' . $liga['nombre'], 'admin.php?p=alumnos');
?>
<style>
  .hoja-c { display: grid; grid-template-columns: repeat(2, 85.6mm); gap: 4mm; }
  .carnet-e { width: 85.6mm; height: 54mm; border: 1px solid #333; border-radius: 3mm; overflow: hidden; display: grid; grid-template-columns: 24mm 1fr 22mm; font-size: 2.6mm; break-inside: avoid; }
  .carnet-e .ce-cab { grid-column: 1 / -1; background: <?= e($liga['color']) ?>; color: #fff; padding: 1.2mm 2mm; font-weight: 700; display: flex; align-items: center; gap: 2mm; height: 9mm; }
  .carnet-e .ce-cab img { height: 7mm; width: 7mm; border-radius: 50%; background: #fff; object-fit: cover; }
  .carnet-e .ce-foto { padding: 1.5mm; } .carnet-e .ce-foto img, .carnet-e .ce-foto span { width: 21mm; height: 26mm; object-fit: cover; border-radius: 1.5mm; display: flex; align-items: center; justify-content: center; background: #ddd; font-size: 6mm; }
  .carnet-e .ce-datos { padding: 1.5mm 0; line-height: 1.35; } .carnet-e .ce-nombre { font-weight: 800; font-size: 3.2mm; }
  .carnet-e .ce-qr { padding: 1.5mm; } .carnet-e .ce-qr svg { width: 19mm; height: 19mm; }
  @media print { .barra-doc { display: none; } }
</style>
<p class="no-imprimir suave"><?= count($lista) ?> carnet(s). Imprima en A4, sin márgenes, y recorte.</p>
<div class="hoja-c">
<?php foreach ($lista as $a): ?>
  <div class="carnet-e">
    <div class="ce-cab"><?php if ($liga['logo']): ?><img src="<?= e($liga['logo']) ?>" alt=""><?php endif; ?><?= e($liga['nombre']) ?></div>
    <div class="ce-foto"><?= foto_alumno($a, 'x') ?></div>
    <div class="ce-datos"><div class="ce-nombre"><?= e(nombre_alumno($a)) ?></div><div><?= e(edad_o_categoria($a, $liga)) // (arreglos 14-oct) ?> · <?= e(implode(' ', array_map(fn($d) => icono_deporte_escuela($d), deportes_alumno($a, $liga)))) ?> <?= e($a['grupo'] ?? '') ?><?= ($gr_c = grado_actual((int)$a['id'])) ? ' · 🥋 ' . e($gr_c['grado']) : '' ?></div>
      <?php if ($a['cedula']): ?><div>C.I. <?= e($a['cedula']) ?></div><?php endif; ?><div>Nac.: <?= e($a['fecha_nac'] ? date('d/m/Y', strtotime($a['fecha_nac'])) : '') ?></div>
      <?php if ($a['tipo_sangre']): ?><div>Sangre: <b><?= e($a['tipo_sangre']) ?></b></div><?php endif; ?>
      <div>Emerg.: <?= e($a['emergencia_telefono'] ?: $a['representante_telefono']) ?></div><div>Año <?= date('Y') ?></div></div>
    <div class="ce-qr" data-url="<?= e(url_carnet_alumno($a)) ?>"></div>
  </div>
<?php endforeach; ?>
</div>
<script src="assets/js/qrcode.js"></script>
<script>document.querySelectorAll('.ce-qr').forEach(function (el) { var q = qrcode(0, 'M'); q.addData(el.getAttribute('data-url')); q.make(); el.innerHTML = q.createSvgTag({ cellSize: 2, margin: 0, scalable: true }); });</script>
<?php
doc_fin();
