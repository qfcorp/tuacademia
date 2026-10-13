<?php
/*
 * (v27) SUBIR DATOS DESDE EXCEL: plantillas para cada tipo de dato, revisión y confirmación.
 * Solo en cuentas pagadas de los planes completos (no en la demostración ni en la prueba gratis).
 */
$liga = requiere_liga();
$lid = (int)$liga['id'];
if (($motivo = motivo_solo_pagadas($liga, 'carga_masiva')) !== '') {
    pantalla_solo_pagadas('📥 Subir datos desde Excel', $motivo);
    return;
}
$tipos = tipos_carga($liga);
$tipo = array_key_exists(get('tipo'), $tipos) ? get('tipo') : '';
if ($tipo === 'equipos') ir('admin.php?p=importar');
$torneos_ind = es_escuela($liga) ? [] : torneos_para_competidores($lid);
$torneo = null;
if ($tipo === 'competidores' && (int)get('t')) {
    $torneo = fila('SELECT * FROM torneos WHERE id = ? AND liga_id = ?', [(int)get('t'), $lid]);
    if (!$torneo || !es_individual($torneo)) throw new RuntimeException('Campeonato no encontrado.');
}

/* ---------- Plantillas ---------- */
if (get('plantilla') !== '' && $tipo !== '') {
    if ($tipo === 'competidores' && !$torneo) throw new RuntimeException('Elija primero el campeonato.');
    $bin = plantilla_carga($tipo, $liga, $torneo);
    $nombre = 'plantilla_' . $tipo . ($torneo ? '_' . slugify($torneo['nombre']) : '') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}
// Todas las plantillas juntas en un .zip
if (get('todas') === '1') {
    $tmp = tempnam(sys_get_temp_dir(), 'pla');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $i = 0;
    foreach ($tipos as $k => $x) {
        if ($k === 'competidores') continue;
        $i++;
        $bin = $k === 'equipos' ? crear_xlsx([array_column(columnas_importacion_base(), 0), array_column(columnas_importacion_base(), 2), array_column(columnas_importacion_base(), 3)], 'Jugadores', array_column(columnas_importacion_base(), 1)) : plantilla_carga($k, $liga);
        $zip->addFromString($i . '_plantilla_' . $k . '.xlsx', $bin);
    }
    foreach ($torneos_ind as $t) $zip->addFromString(++$i . '_competidores_' . slugify($t['nombre']) . '.xlsx', plantilla_carga('competidores', $liga, $t));
    $zip->addFromString('LEAME.txt', "Plantillas de " . marca() . " para " . $liga['nombre'] . "\r\n\r\n"
        . (es_escuela($liga) ? "Súbalas en este orden: 1 sedes, 2 profesores, 3 grupos, 4 alumnos.\r\n" : "Equipos y jugadores van juntos en la misma plantilla.\r\n")
        . "Cada archivo tiene una hoja 'Instrucciones'. Súbalos en Panel > Subir datos desde Excel.\r\n");
    $zip->close();
    $bin = (string)file_get_contents($tmp);
    @unlink($tmp);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="plantillas_' . slugify($liga['nombre']) . '.zip"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'revisar') {
        if ($tipo === '') throw new RuntimeException('Elija qué datos va a subir.');
        if ($tipo === 'competidores' && !$torneo) throw new RuntimeException('Elija el campeonato.');
        $f = $_FILES['archivo'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Elija el archivo de Excel.');
        if ($f['size'] > 5 * 1024 * 1024) throw new RuntimeException('El archivo pesa más de 5 MB.');
        $rev = revisar_carga($tipo, leer_hoja($f['tmp_name'], (string)$f['name']), $liga, $torneo);
        $_SESSION['carga'][$lid] = ['tipo' => $tipo, 'torneo_id' => $torneo ? (int)$torneo['id'] : 0, 'archivo' => mb_substr((string)$f['name'], 0, 80)] + $rev;
        ir('admin.php?p=cargas&tipo=' . $tipo . ($torneo ? '&t=' . (int)$torneo['id'] : '') . '&revisar=1');
    }
    if ($accion === 'guardar') {
        $c = $_SESSION['carga'][$lid] ?? throw new RuntimeException('Primero suba el archivo.');
        $t = $c['torneo_id'] ? fila('SELECT * FROM torneos WHERE id = ? AND liga_id = ?', [$c['torneo_id'], $lid]) : null;
        if ($tipos[$c['tipo']][3] && post('autorizacion') !== '1') throw new RuntimeException('Marque que tiene la autorización de uso de datos de cada persona del archivo.');
        if ($c['aviso'] !== '') throw new RuntimeException($c['aviso']);
        if ($c['tipo'] === 'alumnos') verificar_alumnos_plan($liga);
        $n = guardar_carga($c['tipo'], $c, $liga, $t);
        [$ico, $nom] = $tipos[$c['tipo']];
        bitacora_evento('importacion', 'Subió desde Excel (' . $c['archivo'] . '): ' . $n . ' registro(s) de ' . $nom . ($t ? ' · ' . $t['nombre'] : ''), $lid);
        unset($_SESSION['carga'][$lid]);
        flash('¡Listo! Se guardaron ' . $n . ' registro(s) de ' . mb_strtolower($nom) . '.');
        $destino = ['arbitros' => 'admin.php?p=arbitros', 'canchas' => 'admin.php?p=config#canchas', 'sedes' => 'admin.php?p=escuela_config', 'profesores' => 'admin.php?p=profesores',
            'grupos' => 'admin.php?p=grupos', 'alumnos' => 'admin.php?p=alumnos', 'competidores' => $t ? url_admin_individual($t) : 'admin.php?p=torneos'][$c['tipo']] ?? 'admin.php?p=cargas';
        ir($destino);
    }
    if ($accion === 'cancelar') {
        unset($_SESSION['carga'][$lid]);
        ir('admin.php?p=cargas' . ($tipo !== '' ? '&tipo=' . $tipo . ($torneo ? '&t=' . (int)$torneo['id'] : '') : ''));
    }
}

$rev = get('revisar') === '1' ? ($_SESSION['carga'][$lid] ?? null) : null;
if ($rev && $rev['tipo'] !== $tipo) $rev = null;
cabecera_admin('Subir datos desde Excel');
?>
<?php if ($tipo === ''): ?>
<div class="fila-titulo"><h1>📥 Subir datos desde Excel</h1>
  <span class="acciones"><a class="btn btn-sec" href="admin.php?p=cargas&amp;todas=1">⬇ Descargar todas las plantillas (.zip)</a> <a class="btn btn-sec" href="admin.php?p=mis_datos">📦 Descargar mis datos</a></span></div>
<p class="suave">Descargue la plantilla, llénela en Excel (un registro por fila) y súbala. Antes de guardar le mostramos la lista para revisar: lo que tenga errores no se guarda.
  <?= es_escuela($liga) ? '<b>Orden recomendado:</b> 1 sedes → 2 profesores → 3 grupos → 4 alumnos (cada uno usa lo anterior).' : '' ?></p>
<div class="rejilla" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
  <?php $i = 0; foreach ($tipos as $k => [$ico, $nom, $desc]): $i++; ?>
    <div class="tarjeta">
      <div style="font-size:2rem"><?= $ico ?></div>
      <h2 style="margin:.2rem 0"><?= es_escuela($liga) ? $i . '. ' : '' ?><?= e($nom) ?></h2>
      <p class="suave" style="min-height:3em"><?= e($desc) ?></p>
      <div class="acciones">
        <?php if ($k === 'equipos'): ?>
          <a class="btn" href="admin.php?p=importar">Abrir</a> <a class="btn btn-sec" href="admin.php?p=importar&amp;plantilla=1">⬇ Plantilla</a>
        <?php elseif ($k === 'competidores'): ?>
          <a class="btn" href="admin.php?p=cargas&amp;tipo=competidores">Abrir</a>
        <?php else: ?>
          <a class="btn" href="admin.php?p=cargas&amp;tipo=<?= $k ?>">Abrir</a> <a class="btn btn-sec" href="admin.php?p=cargas&amp;tipo=<?= $k ?>&amp;plantilla=1">⬇ Plantilla</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<p class="suave" style="font-size:.85rem">Las fotos no van en el Excel: se suben después en la ficha de cada persona. Máximo <?= CARGA_MAX_FILAS ?> filas por archivo. También acepta archivos CSV.</p>

<?php else: [$ico, $nom, $desc, $personal] = $tipos[$tipo]; ?>
<p><a href="admin.php?p=cargas">← Subir datos desde Excel</a></p>
<h1><?= $ico ?> Subir <?= e(mb_strtolower($nom)) ?> desde Excel</h1>

<?php if ($tipo === 'competidores' && !$torneo): ?>
  <div class="tarjeta">
    <h2 style="margin-top:0">¿A qué campeonato?</h2>
    <?php if (!$torneos_ind): ?>
      <p>No tiene campeonatos individuales abiertos. Créelo primero en <a href="admin.php?p=torneos">Campeonatos</a> (carreras, bolos, tenis, pádel, tenis de mesa, artes marciales, boxeo, natación o atletismo).</p>
    <?php else: ?>
      <div class="lista">
      <?php foreach ($torneos_ind as $t): ?>
        <a class="item" href="admin.php?p=cargas&amp;tipo=competidores&amp;t=<?= (int)$t['id'] ?>"><?= icono_individual($t) ?> <b><?= e($t['nombre'] . ' ' . $t['temporada']) ?></b> <small class="suave"><?= e(resumen_individual($t)) ?></small></a>
      <?php endforeach; ?>
      </div>
      <p class="suave">La plantilla trae las columnas de ese deporte (pareja, peso, grado, vehículo, pruebas…) y en la hoja «Instrucciones» la lista de categorías o pruebas del campeonato.</p>
    <?php endif; ?>
  </div>
<?php elseif (!$rev): $cols = columnas_carga($tipo, $liga, $torneo); $q = 'tipo=' . $tipo . ($torneo ? '&amp;t=' . (int)$torneo['id'] : ''); ?>
  <?php if ($torneo): ?><p>Campeonato: <b><?= icono_individual($torneo) ?> <?= e($torneo['nombre'] . ' ' . $torneo['temporada']) ?></b> · <a href="admin.php?p=cargas&amp;tipo=competidores">cambiar</a></p><?php endif; ?>
  <div class="tarjeta">
    <h2 style="margin-top:0">Paso 1 · Descargue la plantilla</h2>
    <p>Archivo de Excel con los títulos listos, dos filas de ejemplo (<b>bórrelas</b>) y una hoja «Instrucciones».</p>
    <a class="btn" href="admin.php?p=cargas&amp;<?= $q ?>&amp;plantilla=1">⬇ Descargar plantilla de Excel</a>
    <div class="tabla-scroll" style="margin-top:10px"><table>
      <tr><th>Columna</th><th>¿Obligatoria?</th><th>Cómo llenarla</th></tr>
      <?php foreach ($cols as $c): ?><tr><td><?= e(str_replace(' *', '', $c[0])) ?></td><td><?= $c[4] ? '<b>Sí</b>' : 'no' ?></td><td class="suave"><?= e($c[5]) ?></td></tr><?php endforeach; ?>
    </table></div>
  </div>
  <div class="tarjeta">
    <h2 style="margin-top:0">Paso 2 · Suba el archivo lleno</h2>
    <form method="post" enctype="multipart/form-data" action="admin.php?p=cargas&amp;<?= $q ?>">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="revisar">
      <div class="campos"><div><label>Archivo (.xlsx o .csv)</label><input type="file" name="archivo" accept=".xlsx,.csv" required></div></div>
      <button class="btn">Revisar el archivo</button>
      <p class="ayuda">Todavía no se guarda nada: primero verá la lista para revisar.</p>
    </form>
  </div>
<?php else: $cols = columnas_carga($tipo, $liga, $torneo); $mostrar = array_slice(array_keys($cols), 0, 5); ?>
  <div class="tarjeta">
    <h2 style="margin-top:0">Paso 3 · Revise y confirme</h2>
    <p>Archivo: <b><?= e($rev['archivo']) ?></b><?= $torneo ? ' · ' . e($torneo['nombre']) : '' ?> · <span class="estado-chip chip-ok"><?= (int)$rev['ok'] ?> listos</span>
      <?= $rev['omitidos'] ? '<span class="estado-chip">' . (int)$rev['omitidos'] . ' ya existen (se omiten)</span>' : '' ?>
      <?= $rev['errores'] ? '<span class="estado-chip chip-mal">' . (int)$rev['errores'] . ' con errores (no se guardan)</span>' : '' ?></p>
    <?php if ($rev['aviso'] !== ''): ?><div class="aviso aviso-error"><?= e($rev['aviso']) ?></div><?php endif; ?>
    <div class="tabla-scroll" style="max-height:520px"><table>
      <tr><th>Fila</th><?php foreach ($mostrar as $k): ?><th><?= e(str_replace(' *', '', $cols[$k][0])) ?></th><?php endforeach; ?><th>Estado</th></tr>
      <?php foreach ($rev['filas'] as $r): ?>
        <tr class="<?= $r['errores'] ? 'dif' : '' ?>"><td><?= (int)$r['n'] ?></td>
          <?php foreach ($mostrar as $k): $x = $r['d'][$k] ?? ''; ?><td><?= e(is_array($x) ? '' : (string)$x) ?></td><?php endforeach; ?>
          <td><?= $r['errores'] ? '✗ ' . e(implode(' ', $r['errores'])) : ($r['omitir'] ? '↷' : '✓') . ($r['notas'] ? ' <small>' . e(implode(' · ', $r['notas'])) . '</small>' : '') ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php if ($rev['ok'] > 0 && $rev['aviso'] === ''): ?>
    <form method="post" style="margin-top:12px" action="admin.php?p=cargas&amp;tipo=<?= $tipo ?><?= $torneo ? '&amp;t=' . (int)$torneo['id'] : '' ?>">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="guardar">
      <?php if ($personal): ?><label class="check"><input type="checkbox" name="autorizacion" value="1" required> Confirmo que <?= e($liga['nombre']) ?> tiene la <b>autorización</b> de cada persona del archivo (o de su representante, si es menor de edad) para el uso de sus datos, según la Ley Orgánica de Protección de Datos Personales, incluido su almacenamiento en servidores fuera del Ecuador.</label><?php endif; ?>
      <div class="acciones" style="margin-top:10px"><button class="btn btn-grande">✓ Guardar <?= (int)$rev['ok'] ?> registro(s)</button></div>
    </form>
    <?php endif; ?>
    <form method="post" style="margin-top:8px" action="admin.php?p=cargas&amp;tipo=<?= $tipo ?><?= $torneo ? '&amp;t=' . (int)$torneo['id'] : '' ?>"><?= csrf_campo() ?><input type="hidden" name="accion" value="cancelar"><button class="btn btn-sec">Cancelar y subir otro archivo</button></form>
    <p class="suave" style="font-size:.85rem">Para corregir las filas con error: arréglelas en su Excel y vuelva a subirlo. Lo que ya existe no se repite.</p>
  </div>
<?php endif; ?>
<?php endif;
pie_admin();
