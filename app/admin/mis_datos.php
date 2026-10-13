<?php
/*
 * (v27) DESCARGAR TODOS MIS DATOS: un Excel con todo lo de la liga o escuela (una hoja por tema).
 * Solo en cuentas pagadas (no en la demostración ni en la prueba gratis).
 */
$liga = requiere_liga();
$lid = (int)$liga['id'];
if (($motivo = motivo_solo_pagadas($liga)) !== '') {
    pantalla_solo_pagadas('📦 Descargar todos mis datos', $motivo);
    return;
}
if (get('descargar') === '1') {
    @set_time_limit(120);
    $bin = libro_mis_datos($liga);
    bitacora_evento('exportacion', 'Descargó todos los datos de la cuenta en Excel', $lid);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="datos_' . slugify($liga['nombre']) . '_' . date('Y-m-d') . '.xlsx"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}
cabecera_admin('Descargar mis datos');
?>
<h1>📦 Descargar todos mis datos</h1>
<div class="tarjeta" style="max-width:760px">
  <p>Un archivo de Excel con <b>todos los datos de <?= e($liga['nombre']) ?></b>, una hoja por tema:</p>
  <ul>
    <?php if (es_escuela($liga)): ?>
      <li>Cuenta, sedes, profesores y grupos</li><li>Alumnos (con representante y ficha médica)</li><li>Cobros (mensualidades, matrículas y otros)</li><li>Asistencia</li>
    <?php else: ?>
      <li>Cuenta, equipos y jugadores</li><li>Campeonatos, partidos, goles y tarjetas</li><li>Competidores de campeonatos individuales</li><li>Árbitros, finanzas y canchas</li>
    <?php endif; ?>
  </ul>
  <div class="aviso aviso-info">🔒 El archivo tiene <b>datos personales</b> (cédulas, teléfonos<?= es_escuela($liga) ? ', fichas médicas' : '' ?>). Guárdelo en un lugar seguro y no lo comparta. La descarga queda registrada en la bitácora.</div>
  <a class="btn btn-grande" href="admin.php?p=mis_datos&amp;descargar=1">⬇ Descargar Excel</a>
  <p class="suave" style="font-size:.85rem;margin-bottom:0">Las fotos y los comprobantes no van en el Excel. Para subir datos desde Excel use <a href="admin.php?p=cargas">Subir datos desde Excel</a>.</p>
</div>
<?php
pie_admin();
