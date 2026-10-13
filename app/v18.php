<?php
/*
 * (v18) Novedades para ligas y campeonatos:
 *  - Cumpleañeros del día y aniversarios de los equipos (página pública, Resumen e imagen para redes).
 *  - Sancionados con "Cumplido X de Y partidos" (lo calcula suspensiones() en logica.php).
 *  - Documentos de la liga (reglamento, bases, calendario…) en PDF o imagen, visibles en la página pública.
 *  - Buscador público de jugadores y equipos.
 * La contabilidad con importador del SRI está en contabilidad.php.
 */

/** Equipos "vigentes" de la liga: los que juegan un campeonato no finalizado (si no hay ninguno, todos). */
function equipos_vigentes(int $lid): array
{
    $ids = array_map('intval', array_column(filas("SELECT DISTINCT te.equipo_id FROM torneo_equipos te JOIN torneos t ON t.id = te.torneo_id WHERE t.liga_id = ? AND t.estado <> 'finalizado'", [$lid]), 'equipo_id'));
    if (!$ids) $ids = array_map('intval', array_column(filas('SELECT id FROM equipos WHERE liga_id = ?', [$lid]), 'id'));
    return $ids;
}

/** Días (mm-dd) desde $desde durante $dias días. */
function dias_mmdd(string $desde, int $dias): array
{
    $r = [];
    $ts = strtotime($desde);
    for ($i = 0; $i <= $dias; $i++) $r[] = date('m-d', strtotime("+$i day", $ts));
    return $r;
}

/**
 * Jugadores activos (de equipos vigentes) que cumplen años el día $fecha (o en los $dias siguientes).
 * El 29 de febrero se saluda el 28 en los años que no son bisiestos.
 */
function cumpleaneros_liga(int $lid, string $fecha = '', int $dias = 0): array
{
    $fecha = $fecha !== '' ? $fecha : date('Y-m-d');
    $eq = equipos_vigentes($lid);
    if (!$eq) return [];
    $mmdd = dias_mmdd($fecha, $dias);
    if (in_array('02-28', $mmdd, true) && !checkdate(2, 29, (int)substr($fecha, 0, 4))) $mmdd[] = '02-29';
    $in = implode(',', array_fill(0, count($mmdd), '?'));
    $rows = filas("SELECT j.*, e.nombre AS equipo, e.escudo, e.color FROM jugadores j JOIN equipos e ON e.id = j.equipo_id
                   WHERE e.id IN (" . implode(',', $eq) . ") AND j.activo = 1 AND COALESCE(j.anonimizado, 0) = 0 AND j.fecha_nac <> '' AND length(j.fecha_nac) >= 10
                   AND strftime('%m-%d', j.fecha_nac) IN ($in)", $mmdd);
    $ts = strtotime($fecha);
    foreach ($rows as &$r) {
        $md = substr($r['fecha_nac'], 5, 5);
        $anio = (int)date('Y', $ts);
        $dia = $md === '02-29' && !checkdate(2, 29, $anio) ? "$anio-02-28" : "$anio-$md";
        if (strtotime($dia) < $ts) $dia = ($anio + 1) . '-' . $md;
        $r['cumple_el'] = $dia;
        $r['cumple_anios'] = (int)substr($dia, 0, 4) - (int)substr($r['fecha_nac'], 0, 4);
        $r['faltan'] = (int)round((strtotime($dia) - $ts) / 86400);
    }
    unset($r);
    usort($rows, fn($a, $b) => [$a['faltan'], $a['equipo'], nombre_jugador($a)] <=> [$b['faltan'], $b['equipo'], nombre_jugador($b)]);
    return $rows;
}

/** Equipos que cumplen años de fundados en los próximos $dias días (con los años que cumplen). */
function aniversarios_equipos(int $lid, int $dias = 60, string $fecha = ''): array
{
    $fecha = $fecha !== '' ? $fecha : date('Y-m-d');
    $ts = strtotime($fecha);
    $res = [];
    foreach (filas("SELECT * FROM equipos WHERE liga_id = ? AND fundado <> '' AND length(fundado) >= 10", [$lid]) as $e) {
        $md = substr($e['fundado'], 5, 5);
        $anio = (int)date('Y', $ts);
        $dia = $md === '02-29' && !checkdate(2, 29, $anio) ? "$anio-02-28" : "$anio-$md";
        if (strtotime($dia) < $ts) $dia = ($anio + 1) . '-' . ($md === '02-29' && !checkdate(2, 29, $anio + 1) ? '02-28' : $md);
        $faltan = (int)round((strtotime($dia) - $ts) / 86400);
        if ($faltan > $dias) continue;
        $e['aniversario_el'] = $dia;
        $e['faltan'] = $faltan;
        $e['anios'] = (int)substr($dia, 0, 4) - (int)substr($e['fundado'], 0, 4);
        if ($e['anios'] < 1) continue;
        $res[] = $e;
    }
    usort($res, fn($a, $b) => [$a['faltan'], $a['nombre']] <=> [$b['faltan'], $b['nombre']]);
    return $res;
}

/** "5 de octubre de 1988" */
function fecha_larga(string $f): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $f, $m)) return $f;
    $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return (int)$m[3] . ' de ' . $meses[(int)$m[2]] . ' de ' . $m[1];
}

/** Bloque HTML de cumpleañeros y aniversarios (página pública y Resumen del panel). */
function html_cumples(array $liga, string $base, bool $panel = false): string
{
    $lid = (int)$liga['id'];
    $hoy = cumpleaneros_liga($lid);
    $semana = $hoy ? [] : array_values(array_filter(cumpleaneros_liga($lid, '', 6), fn($x) => $x['faltan'] > 0));
    $aniv = aniversarios_equipos($lid, 60);
    if (!$hoy && !$semana && !$aniv) return '';
    ob_start(); ?>
  <div class="rejilla-2 cumples">
    <?php if ($hoy || $semana): ?>
    <div class="tarjeta">
      <h2 style="margin-top:0">🎂 <?= $hoy ? 'Cumpleañeros de hoy' : 'Cumpleaños de esta semana' ?></h2>
      <?php foreach (array_slice($hoy ?: $semana, 0, 12) as $c): ?>
        <a class="eq-celda partido-link" style="margin:.35rem 0" href="<?= e($panel ? 'admin.php?p=jugador&id=' . (int)$c['id'] : $base . '&j=' . (int)$c['id']) ?>">
          <?= foto_jugador($c) ?> <span class="txt"><strong><?= e(nombre_jugador($c)) ?></strong><br><small><?= e($c['equipo']) ?> · <?= $c['faltan'] === 0 ? 'cumple ' . (int)$c['cumple_anios'] . ' años' : e(fecha_bonita($c['cumple_el'])) . ' · ' . (int)$c['cumple_anios'] . ' años' ?></small></span>
        </a>
      <?php endforeach; ?>
      <?php if ($hoy): ?><p class="suave" style="margin-bottom:0">De parte de todos los que conformamos <?= e($liga['nombre']) ?>, ¡feliz cumpleaños! 🎉</p><?php endif; ?>
      <?php if ($panel && $hoy && function_exists('imagettftext')): ?><p style="margin-bottom:0"><a class="btn btn-sec btn-chico" target="_blank" href="imagen.php?tipo=cumple&amp;l=<?= $lid ?>">🖼 Imagen para redes</a> <a class="btn btn-sec btn-chico" href="imagen.php?tipo=cumple&amp;l=<?= $lid ?>&amp;descargar=1">⬇ Descargar</a></p><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($aniv): ?>
    <div class="tarjeta">
      <h2 style="margin-top:0">🎉 Aniversario de equipos</h2>
      <?php foreach (array_slice($aniv, 0, 8) as $e): ?>
        <a class="eq-celda partido-link" style="margin:.35rem 0" href="<?= e($panel ? 'admin.php?p=equipo&id=' . (int)$e['id'] : $base . '&e=' . (int)$e['id']) ?>">
          <?= escudo($e['escudo'], $e['nombre'], $e['color']) ?> <span class="txt"><strong><?= e($e['nombre']) ?></strong><br><small><?= $e['faltan'] === 0 ? '¡Hoy cumple ' . (int)$e['anios'] . ' años!' : 'Cumple ' . (int)$e['anios'] . ' años · faltan ' . (int)$e['faltan'] . ' día' . ($e['faltan'] === 1 ? '' : 's') ?> · fundado el <?= e(fecha_larga($e['fundado'])) ?></small></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
<?php
    return (string)ob_get_clean();
}

/** Imagen para redes con los cumpleañeros del día. */
function img_cumpleanos(array $liga, array $lista, string $fecha)
{
    $img = lienzo($liga);
    $blanco = col($img, [255, 255, 255]);
    $y = cabecera_img($img, $liga, '¡Feliz cumpleaños!', fecha_larga($fecha));
    $lista = array_slice($lista, 0, 8);
    $n = count($lista);
    if ($n === 0) {
        texto($img, 'Hoy no hay cumpleañeros', 40, IMG_W / 2, $y + 300, $blanco, 'bold', 'centro');
    } elseif ($n <= 2) {
        $d = $n === 1 ? 420 : 300;
        foreach ($lista as $i => $c) {
            $cx = $n === 1 ? IMG_W / 2 : ($i === 0 ? 280 : IMG_W - 280);
            $cy = $y + 60 + intdiv($d, 2);
            foto_img($img, $c, (int)$cx, $cy, $d, $c['color']);
            escudo_img($img, $c['escudo'], $c['equipo'], $c['color'], (int)$cx + intdiv($d, 2) - 50, $cy + intdiv($d, 2) - 50, 100);
            $yy = $cy + intdiv($d, 2) + 80;
            texto($img, mb_strtoupper(nombre_jugador($c)), $n === 1 ? 54 : 36, (int)$cx, $yy, $blanco, 'bold', 'centro', $n === 1 ? 980 : 480);
            texto($img, $c['equipo'], $n === 1 ? 32 : 26, (int)$cx, $yy + 50, col($img, [220, 230, 226]), 'medium', 'centro', $n === 1 ? 980 : 480);
            $txt = (int)$c['cumple_anios'] . ' AÑOS';
            $w = ancho_texto($txt, 34) + 70;
            imagefilledrectangle($img, (int)$cx - intdiv($w, 2), $yy + 80, (int)$cx + intdiv($w, 2), $yy + 146, $blanco);
            texto($img, $txt, 34, (int)$cx, $yy + 128, col($img, [15, 22, 20]), 'bold', 'centro');
        }
    } else {
        $grande = $n <= 4;
        $alto = min($grande ? 180 : 118, intdiv(IMG_H - 190 - $y, $n));
        $d = min($grande ? 130 : 86, $alto - 26);
        $y += max(0, intdiv((IMG_H - 170 - $y) - $alto * $n, 2));
        foreach ($lista as $i => $c) {
            $yy = $y + $i * $alto;
            imagefilledrectangle($img, 40, $yy, IMG_W - 40, $yy + $alto - 14, col($img, [0, 0, 0], 60));
            $cy = $yy + intdiv($alto - 14, 2);
            foto_img($img, $c, 60 + intdiv($d, 2), $cy, $d, $c['color']);
            $xt = 60 + $d + 26;
            texto($img, nombre_jugador($c), $grande ? 40 : 30, $xt, $cy - ($grande ? 4 : 2), $blanco, 'bold', 'izq', 830 - $xt);
            texto($img, $c['equipo'], $grande ? 28 : 22, $xt, $cy + ($grande ? 40 : 32), col($img, [210, 220, 216]), 'medium', 'izq', 830 - $xt);
            imagefilledrectangle($img, 850, $cy - ($grande ? 40 : 32), 1020, $cy + ($grande ? 40 : 32), $blanco);
            texto($img, (int)$c['cumple_anios'] . ' años', $grande ? 36 : 30, 935, $cy + ($grande ? 13 : 11), col($img, [15, 22, 20]), 'bold', 'centro', 160);
        }
    }
    texto($img, '¡De parte de todos los que conformamos ' . $liga['nombre'] . '!', 26, IMG_W / 2, IMG_H - 150, $blanco, 'medium', 'centro', 1000);
    pie_img($img, $liga);
    return $img;
}

/* ---------- Documentos de la liga ---------- */

function tipos_documento(): array
{
    return ['reglamento' => 'Reglamento', 'bases' => 'Bases del campeonato', 'calendario' => 'Calendario / horarios', 'resolucion' => 'Resoluciones', 'otro' => 'Otro documento'];
}

/** Guarda un documento subido (PDF, JPG, PNG o WEBP de hasta 10 MB) en uploads/docs/<liga>/. Devuelve la ruta pública. */
function subir_documento(string $campo, int $lid): string
{
    $f = $_FILES[$campo] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Elija el archivo (PDF o imagen).');
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('El archivo no se pudo subir (¿pesa más de 10 MB?).');
    if ($f['size'] > 10 * 1024 * 1024) throw new RuntimeException('El archivo pesa más de 10 MB.');
    $mime = mime_content_type($f['tmp_name']) ?: '';
    $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? '';
    if ($ext === '') throw new RuntimeException('Solo se aceptan archivos PDF o imágenes (JPG, PNG).');
    $dir = UPLOADS_DIR . '/docs/' . $lid;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException('No se pudo crear la carpeta de documentos.');
    $nombre = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$nombre") && !rename($f['tmp_name'], "$dir/$nombre")) throw new RuntimeException('No se pudo guardar el archivo en el servidor.');
    @chmod("$dir/$nombre", 0644);
    return 'uploads/docs/' . $lid . '/' . $nombre;
}

/** Documentos públicos de la liga (los generales y los del campeonato indicado). */
function documentos_liga(int $lid, ?int $torneo_id = null, bool $solo_publicos = true): array
{
    return filas('SELECT d.*, t.nombre AS torneo FROM liga_documentos d LEFT JOIN torneos t ON t.id = d.torneo_id WHERE d.liga_id = ?'
        . ($solo_publicos ? ' AND d.publico = 1' : '')
        . ($torneo_id !== null ? ' AND (d.torneo_id IS NULL OR d.torneo_id = ' . (int)$torneo_id . ')' : '')
        . ' ORDER BY d.orden, d.id DESC', [$lid]);
}

function icono_documento(string $archivo): string
{
    return str_ends_with(strtolower($archivo), '.pdf') ? '📄' : '🖼';
}

/* ---------- Buscador público ---------- */

/** Busca jugadores (activos, no anonimizados) y equipos de la liga por nombre. Nunca por cédula. */
function buscar_en_liga(int $lid, string $q): array
{
    $q = trim(preg_replace('/\s+/', ' ', $q));
    if (mb_strlen($q) < 2) return ['jugadores' => [], 'equipos' => []];
    // Se compara sin tildes ni mayúsculas (SQLite no sabe pasar "Ñ" o "É" a minúsculas), por eso se filtra aquí
    $norm = fn(string $s) => ' ' . str_replace('-', ' ', slugify($s)) . ' ';
    $palabras = array_slice(array_filter(explode(' ', trim($norm($q)))), 0, 4);
    if (!$palabras) return ['jugadores' => [], 'equipos' => []];
    $coincide = function (string $texto) use ($palabras, $norm): bool {
        $t = $norm($texto);
        foreach ($palabras as $w) if (!str_contains($t, $w)) return false;
        return true;
    };
    $jug = array_values(array_filter(filas("SELECT j.*, e.nombre AS equipo, e.escudo, e.color FROM jugadores j JOIN equipos e ON e.id = j.equipo_id
                  WHERE e.liga_id = ? AND j.activo = 1 AND COALESCE(j.anonimizado, 0) = 0 ORDER BY j.apellidos, j.nombres", [$lid]),
        fn($j) => $coincide($j['nombres'] . ' ' . $j['apellidos'])));
    $eq = array_values(array_filter(filas('SELECT * FROM equipos WHERE liga_id = ? ORDER BY nombre', [$lid]), fn($e) => $coincide($e['nombre'])));
    return ['jugadores' => array_slice($jug, 0, 40), 'equipos' => array_slice($eq, 0, 20), 'mas' => count($jug) > 40];
}

/** (v18) Motivos de una suspensión con su avance: "Tarjeta roja (12 sep) · Cumplido 1 de 2". */
function html_motivos_suspension(array $x): string
{
    $det = $x['detalle'] ?? array_map(fn($m) => ['motivo' => $m, 'total' => 0, 'cumplidos' => 0], $x['motivos'] ?? []);
    return implode('<br>', array_map(fn($d) => e($d['motivo']) . ($d['total'] > 0 ? ' <span class="etiqueta' . ($d['cumplidos'] > 0 ? ' verde' : '') . '" style="white-space:nowrap">Cumplido ' . (int)$d['cumplidos'] . ' de ' . (int)$d['total'] . '</span>' : ''), $det));
}
