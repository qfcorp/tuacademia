<?php
/*
 * (v7) Importar equipos y jugadores desde Excel (.xlsx) o CSV.
 * Paso 1: descargar la plantilla · Paso 2: subir el archivo y revisar · Paso 3: confirmar e importar.
 */
require_once APP_DIR . '/excel.php';
$liga = requiere_liga();
$lid = (int)$liga['id'];
// (v27) Solo en cuentas pagadas de los planes completos
if (($motivo = motivo_solo_pagadas($liga, 'carga_masiva')) !== '') {
    pantalla_solo_pagadas('📥 Importar equipos y jugadores', $motivo, 'admin.php?p=equipos');
    return;
}

/** Columnas de la plantilla (v27: viven en app/cargas.php para usarlas también en «todas las plantillas»). */
function columnas_importacion(): array
{
    return columnas_importacion_base();
}

/** Reconoce una columna aunque el título esté escrito un poco distinto. */
function clave_columna(string $titulo): ?string
{
    $t = slugify($titulo);
    $mapa = [
        'equipo' => ['equipo', 'club', 'nombre-del-equipo'], 'abreviatura' => ['abreviatura', 'abrev', 'sigla', 'siglas'],
        'delegado' => ['delegado', 'delegado-del-equipo', 'dirigente'], 'telefono' => ['telefono', 'telefono-del-delegado', 'celular', 'celular-del-delegado', 'whatsapp'],
        'nombres' => ['nombres', 'nombre', 'nombres-del-jugador', 'nombre-del-jugador', 'jugador'], 'apellidos' => ['apellidos', 'apellido', 'apellidos-del-jugador'],
        'cedula' => ['cedula', 'ci', 'c-i', 'documento', 'pasaporte', 'cedula-o-pasaporte', 'identificacion'],
        'numero' => ['numero', 'numero-de-camiseta', 'camiseta', 'dorsal', 'no', 'n'], 'posicion' => ['posicion', 'puesto'],
        'fecha_nac' => ['fecha-de-nacimiento', 'fecha-de-nacimiento-dia-mes-ano', 'nacimiento', 'fecha-nacimiento', 'f-nacimiento'],
        'foto_publica' => ['publicar-su-foto-si-no', 'foto-publica', 'publicar-foto'],
        'representante_nombre' => ['representante', 'representante-si-es-menor-de-18', 'representante-nombre', 'nombre-del-representante'],
        'representante_cedula' => ['cedula-del-representante', 'representante-cedula'],
        'representante_telefono' => ['telefono-del-representante', 'representante-telefono'],
    ];
    foreach ($mapa as $k => $opciones) if (in_array($t, $opciones, true)) return $k;
    return null;
}

/* ---------- Descargar plantilla ---------- */
if (get('plantilla') === '1') {
    $cols = columnas_importacion();
    $filas = [array_column($cols, 0), array_column($cols, 2), array_column($cols, 3)];
    $bin = crear_xlsx($filas, 'Jugadores', array_column($cols, 1));
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="plantilla_equipos_jugadores.xlsx"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

/**
 * Revisa las filas del archivo. Devuelve ['filas' => [...], 'equipos_nuevos' => [...], 'errores' => n, 'ok' => n].
 * Cada fila: ['n' => fila del Excel, 'd' => datos, 'equipo_id' => id existente o null, 'errores' => [...], 'notas' => [...]]
 */
function revisar_importacion(array $filas_excel, array $liga): array
{
    if (count($filas_excel) < 2) throw new RuntimeException('El archivo está vacío: debe tener la fila de títulos y al menos un jugador.');
    $titulos = array_shift($filas_excel);
    $pos = [];
    foreach ($titulos as $i => $t) {
        $k = clave_columna((string)$t);
        if ($k !== null && !isset($pos[$k])) $pos[$k] = $i;
    }
    if (!isset($pos['equipo'], $pos['nombres'])) {
        throw new RuntimeException('No se encontraron las columnas "Equipo" y "Nombres del jugador" en la primera fila. Use la plantilla que se descarga en esta página.');
    }
    $lid = (int)$liga['id'];
    $existentes = [];
    foreach (filas('SELECT id, nombre FROM equipos WHERE liga_id = ?', [$lid]) as $e) $existentes[mb_strtolower(trim($e['nombre']))] = (int)$e['id'];
    $out = [];
    $cedulas = [];
    $numeros = [];
    $conteo_equipo = [];
    $nuevos = [];
    foreach ($filas_excel as $idx => $f) {
        $val = fn(string $k) => isset($pos[$k]) ? trim((string)($f[$pos[$k]] ?? '')) : '';
        if (implode('', array_map('trim', $f)) === '') continue;
        $r = ['n' => $idx + 2, 'errores' => [], 'notas' => []];
        $equipo = mb_substr(preg_replace('/\s+/', ' ', $val('equipo')), 0, 80);
        $nombres = mb_substr($val('nombres'), 0, 60);
        $apellidos = mb_substr($val('apellidos'), 0, 60);
        if ($apellidos === '' && str_contains($nombres, ' ') && !isset($pos['apellidos'])) {
            // Si solo hay una columna con el nombre completo: la primera palabra es el nombre
            [$nombres, $apellidos] = [strtok($nombres, ' '), trim(substr($nombres, strpos($nombres, ' ')))];
        }
        $ced = strtoupper(preg_replace('/[\s.]+/', '', $val('cedula')));
        if (preg_match('/^\d{9}$/', $ced)) $ced = '0' . $ced; // Excel quita el cero de adelante
        $num = $val('numero');
        $fn = fecha_excel($val('fecha_nac'));
        $d = [
            'equipo' => $equipo, 'abreviatura' => mb_strtoupper(mb_substr($val('abreviatura'), 0, 4)), 'delegado' => mb_substr($val('delegado'), 0, 80),
            'telefono' => mb_substr($val('telefono'), 0, 30), 'nombres' => $nombres, 'apellidos' => $apellidos, 'cedula' => $ced,
            'numero' => $num === '' ? null : (int)$num, 'posicion' => mb_substr($val('posicion'), 0, 30), 'fecha_nac' => $fn ?? '',
            'foto_publica' => in_array(mb_strtolower($val('foto_publica')), ['no', 'n', '0', 'false'], true) ? 0 : 1,
            'representante_nombre' => mb_substr($val('representante_nombre'), 0, 80),
            'representante_cedula' => strtoupper(preg_replace('/[\s.]+/', '', $val('representante_cedula'))),
            'representante_telefono' => mb_substr($val('representante_telefono'), 0, 30),
        ];
        if (preg_match('/^\d{9}$/', $d['representante_cedula'])) $d['representante_cedula'] = '0' . $d['representante_cedula'];
        if (mb_strlen($equipo) < 2) $r['errores'][] = 'Falta el nombre del equipo.';
        if (mb_strlen($nombres) < 2) $r['errores'][] = 'Falta el nombre del jugador.';
        if ($fn === null) $r['errores'][] = 'Fecha de nacimiento no válida ("' . $val('fecha_nac') . '"). Escríbala así: 15/03/1995.';
        if ($num !== '' && (!ctype_digit($num) || (int)$num > 999)) $r['errores'][] = 'Número de camiseta no válido.';
        if ($ced !== '') {
            if ($err = error_documento($ced)) $r['errores'][] = $err;
            elseif (isset($cedulas[$ced])) $r['errores'][] = 'Cédula repetida en el archivo (fila ' . $cedulas[$ced] . ').';
            elseif ($err = cedula_ocupada($ced, $lid)) $r['errores'][] = $err;
            $cedulas[$ced] = $r['n'];
        } else {
            $r['notas'][] = 'sin cédula';
        }
        if ($fn !== null && $fn !== '' && es_menor($fn)) {
            if (mb_strlen($d['representante_nombre']) < 5) $r['errores'][] = 'Es menor de 18: falta el nombre de su representante.';
            elseif ($err = error_documento($d['representante_cedula'])) $r['errores'][] = 'Cédula del representante: ' . $err;
            elseif (strlen(preg_replace('/\D/', '', $d['representante_telefono'])) < 7) $r['errores'][] = 'Es menor de 18: falta el teléfono del representante.';
            else $r['notas'][] = 'menor (con representante)';
        } else {
            $d['representante_nombre'] = $d['representante_cedula'] = $d['representante_telefono'] = '';
        }
        $clave_eq = mb_strtolower($equipo);
        $eid = $existentes[$clave_eq] ?? null;
        $r['equipo_id'] = $eid;
        if (!$eid && $equipo !== '' && !isset($nuevos[$clave_eq])) $nuevos[$clave_eq] = $d;
        if ($d['numero'] !== null && $equipo !== '') {
            $kn = $clave_eq . '#' . $d['numero'];
            if (isset($numeros[$kn])) $r['errores'][] = 'El número ' . $d['numero'] . ' ya lo usa otro jugador del archivo en este equipo (fila ' . $numeros[$kn] . ').';
            elseif ($eid && fila('SELECT id FROM jugadores WHERE equipo_id = ? AND numero = ? AND activo = 1', [$eid, $d['numero']])) $r['errores'][] = 'El número ' . $d['numero'] . ' ya lo usa un jugador del equipo.';
            $numeros[$kn] = $r['n'];
        }
        if (!$r['errores'] && $eid) {
            try {
                $r['notas'] = array_merge($r['notas'], validar_categoria_jugador($eid, $d));
            } catch (RuntimeException $ex) {
                $r['errores'][] = $ex->getMessage();
            }
        }
        if (!$r['errores'] && $equipo !== '') {
            $conteo_equipo[$clave_eq] = ($conteo_equipo[$clave_eq] ?? 0) + 1;
            $max = $eid ? limites_plantilla_equipo($eid, $liga)[1] : (int)$liga['max_plantilla'];
            $ya = $eid ? (int)valor('SELECT COUNT(*) FROM jugadores WHERE equipo_id = ? AND activo = 1', [$eid]) : 0;
            if ($ya + $conteo_equipo[$clave_eq] > $max) {
                $r['errores'][] = 'El equipo pasaría de ' . $max . ' jugadores (máximo permitido).';
                $conteo_equipo[$clave_eq]--;
            }
        }
        $r['d'] = $d;
        $out[] = $r;
    }
    if (!$out) throw new RuntimeException('No se encontraron jugadores en el archivo.');
    $lim = limites_liga($liga);
    $err_lim = '';
    if (!es_super() && $lim['equipos'] > 0 && $lim['usados_equipos'] + count($nuevos) > $lim['equipos']) {
        $err_lim = 'Su liga permite ' . $lim['equipos'] . ' equipos y ya tiene ' . $lim['usados_equipos'] . ': el archivo trae ' . count($nuevos) . ' equipo(s) nuevo(s). Quite algunos o pida que amplíen su límite.';
    }
    return ['filas' => $out, 'equipos_nuevos' => array_values($nuevos), 'error_limite' => $err_lim,
        'ok' => count(array_filter($out, fn($r) => !$r['errores'])), 'errores' => count(array_filter($out, fn($r) => (bool)$r['errores']))];
}

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'revisar') {
        $f = $_FILES['archivo'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Elija el archivo de Excel.');
        if ($f['size'] > 5 * 1024 * 1024) throw new RuntimeException('El archivo pesa más de 5 MB.');
        $rev = revisar_importacion(leer_hoja($f['tmp_name'], (string)$f['name']), $liga);
        $_SESSION['importacion'][$lid] = ['archivo' => mb_substr((string)$f['name'], 0, 80), 'torneo_id' => (int)post('torneo_id')] + $rev;
        ir('admin.php?p=importar&revisar=1');
    }
    if ($accion === 'importar') {
        $imp = $_SESSION['importacion'][$lid] ?? throw new RuntimeException('Primero suba el archivo.');
        if (post('autorizacion') !== '1') throw new RuntimeException('Debe confirmar que tiene la autorización de uso de datos de cada jugador (o de su representante).');
        if ($imp['error_limite'] !== '') throw new RuntimeException($imp['error_limite']);
        $torneo = $imp['torneo_id'] ? fila('SELECT * FROM torneos WHERE id = ? AND liga_id = ?', [$imp['torneo_id'], $lid]) : null;
        $pdo = db();
        $pdo->beginTransaction();
        $ids_eq = [];
        $n_eq = 0;
        $n_j = 0;
        try {
            foreach (filas('SELECT id, nombre FROM equipos WHERE liga_id = ?', [$lid]) as $e) $ids_eq[mb_strtolower(trim($e['nombre']))] = (int)$e['id'];
            foreach ($imp['filas'] as $r) {
                if ($r['errores']) continue;
                $d = $r['d'];
                $k = mb_strtolower($d['equipo']);
                if (!isset($ids_eq[$k])) {
                    q('INSERT INTO equipos (liga_id, nombre, abreviatura, color, delegado, telefono) VALUES (?,?,?,?,?,?)', [
                        $lid, $d['equipo'], $d['abreviatura'] !== '' ? $d['abreviatura'] : mb_strtoupper(mb_substr(slugify($d['equipo']), 0, 3)),
                        sprintf('#%06x', random_int(0x202020, 0xcccccc)), $d['delegado'], $d['telefono'],
                    ]);
                    $ids_eq[$k] = (int)$pdo->lastInsertId();
                    $n_eq++;
                }
                $eid = $ids_eq[$k];
                if ($torneo) q('INSERT OR IGNORE INTO torneo_equipos (torneo_id, equipo_id) VALUES (?, ?)', [$torneo['id'], $eid]);
                if ($d['cedula'] !== '' && cedula_ocupada($d['cedula'], $lid) !== '') continue; // por si cambió algo entre la revisión y ahora
                $menor = $d['fecha_nac'] !== '' && es_menor($d['fecha_nac']);
                q('INSERT INTO jugadores (equipo_id, nombres, apellidos, cedula, numero, posicion, fecha_nac, consentimiento, consentimiento_por,
                     representante_nombre, representante_cedula, representante_telefono, foto_publica) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                    $eid, $d['nombres'], $d['apellidos'], $d['cedula'], $d['numero'], $d['posicion'], $d['fecha_nac'], date('Y-m-d H:i:s'),
                    $menor ? 'representante' : 'jugador', $d['representante_nombre'], $d['representante_cedula'], $d['representante_telefono'], $d['foto_publica'],
                ]);
                registrar_consentimiento_jugador('importacion', $lid, ['consentimiento_por' => $menor ? 'representante' : 'jugador'] + $d, 'jugadores', (int)$pdo->lastInsertId()); // (v24)
                $n_j++;
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        bitacora_evento('importacion', 'Importó desde Excel (' . $imp['archivo'] . '): ' . $n_eq . ' equipo(s) nuevo(s) y ' . $n_j . ' jugador(es)' . ($torneo ? ' · inscritos en ' . $torneo['nombre'] : ''), $lid);
        unset($_SESSION['importacion'][$lid]);
        flash('¡Importación lista! ' . $n_eq . ' equipo(s) nuevo(s) y ' . $n_j . ' jugador(es). Ahora suba las fotos desde cada equipo (o que lo haga cada delegado).');
        ir('admin.php?p=equipos');
    }
    if ($accion === 'cancelar') {
        unset($_SESSION['importacion'][$lid]);
        ir('admin.php?p=importar');
    }
}

$imp = get('revisar') === '1' ? ($_SESSION['importacion'][$lid] ?? null) : null;
$torneos = filas("SELECT * FROM torneos WHERE liga_id = ? AND estado <> 'finalizado' AND " . sql_sin_carreras() . " ORDER BY id DESC", [$lid]);
cabecera_admin('Importar desde Excel');
?>
<p><a href="admin.php?p=equipos">← Equipos</a> · <a href="admin.php?p=cargas">📥 Subir otros datos desde Excel</a></p>
<h1>📥 Importar equipos y jugadores desde Excel</h1>

<?php if (!$imp): ?>
<div class="tarjeta">
  <h2 style="margin-top:0">Paso 1 · Descargue la plantilla</h2>
  <p>Es un archivo de Excel con los títulos listos y dos jugadores de ejemplo. <b>Borre los ejemplos</b> y escriba un jugador por fila. El nombre del equipo se repite en cada jugador de ese equipo: si el equipo no existe, el sistema lo crea.</p>
  <a class="btn" href="admin.php?p=importar&amp;plantilla=1">⬇ Descargar plantilla de Excel</a>
  <ul class="suave" style="margin-bottom:0">
    <li>Obligatorios: <b>Equipo</b> y <b>Nombres del jugador</b>. La cédula se valida (10 números) o se acepta pasaporte.</li>
    <li>Fecha de nacimiento: día/mes/año (ej. 15/03/1995). Si es <b>menor de 18</b>, llene el representante.</li>
    <li>Las fotos no van en el Excel: se suben después en cada equipo, o las sube cada delegado.</li>
    <li>También puede subir un archivo <b>CSV</b>.</li>
  </ul>
</div>
<div class="tarjeta">
  <h2 style="margin-top:0">Paso 2 · Suba el archivo lleno</h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="revisar">
    <div class="campos">
      <div><label>Archivo (.xlsx o .csv)</label><input type="file" name="archivo" accept=".xlsx,.csv" required></div>
      <div><label>Inscribir los equipos en el campeonato (opcional)</label><select name="torneo_id"><option value="">— no inscribir —</option><?php foreach ($torneos as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['nombre'] . ' ' . $t['temporada']) ?></option><?php endforeach; ?></select></div>
    </div>
    <button class="btn">Revisar el archivo</button>
    <p class="ayuda">Todavía no se guarda nada: primero verá la lista para revisar.</p>
  </form>
</div>
<?php else: ?>
<div class="tarjeta">
  <h2 style="margin-top:0">Paso 3 · Revise y confirme</h2>
  <p>Archivo: <b><?= e($imp['archivo']) ?></b> · <span class="estado-chip chip-ok"><?= (int)$imp['ok'] ?> listos</span> <?= $imp['errores'] ? '<span class="estado-chip chip-mal">' . (int)$imp['errores'] . ' con errores (no se importan)</span>' : '' ?>
    · Equipos nuevos: <b><?= count($imp['equipos_nuevos']) ?></b><?= $imp['equipos_nuevos'] ? ' (' . e(implode(', ', array_column($imp['equipos_nuevos'], 'equipo'))) . ')' : '' ?></p>
  <?php if ($imp['error_limite'] !== ''): ?><div class="aviso aviso-error"><?= e($imp['error_limite']) ?></div><?php endif; ?>
  <div class="tabla-scroll" style="max-height:480px"><table>
    <tr><th>Fila</th><th>Equipo</th><th>Jugador</th><th>Cédula</th><th>N°</th><th>Nacimiento</th><th>Estado</th></tr>
    <?php foreach ($imp['filas'] as $r): $d = $r['d']; ?>
      <tr class="<?= $r['errores'] ? 'dif' : '' ?>"><td><?= (int)$r['n'] ?></td><td><?= e($d['equipo']) ?><?= !$r['equipo_id'] && $d['equipo'] !== '' ? ' <span class="etiqueta amarilla">nuevo</span>' : '' ?></td>
        <td><?= e(trim($d['nombres'] . ' ' . $d['apellidos'])) ?></td><td><?= e($d['cedula']) ?></td><td><?= $d['numero'] !== null ? (int)$d['numero'] : '' ?></td><td><?= e($d['fecha_nac']) ?></td>
        <td><?= $r['errores'] ? '✗ ' . e(implode(' ', $r['errores'])) : '✓' . ($r['notas'] ? ' <small>' . e(implode(' · ', $r['notas'])) . '</small>' : '') ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php if ($imp['ok'] > 0 && $imp['error_limite'] === ''): ?>
  <form method="post" style="margin-top:12px">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="importar">
    <label class="check"><input type="checkbox" name="autorizacion" value="1" required> Confirmo que la liga tiene la <b>autorización firmada</b> de cada jugador (o de su representante, si es menor de edad) para el uso de sus datos, según la Ley de Protección de Datos Personales, incluido su almacenamiento en servidores fuera del Ecuador.</label>
    <div class="acciones" style="margin-top:10px"><button class="btn btn-grande">✓ Importar <?= (int)$imp['ok'] ?> jugador(es)</button></div>
  </form>
  <?php endif; ?>
  <form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="cancelar"><button class="btn btn-sec">Cancelar y subir otro archivo</button></form>
  <p class="suave" style="font-size:.85rem">Para corregir las filas con error: arréglelas en su Excel y vuelva a subirlo (los jugadores que ya existen con la misma cédula no se repiten).</p>
</div>
<?php endif;
pie_admin();
