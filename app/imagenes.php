<?php
/*
 * GENERADOR DE IMÁGENES PARA REDES SOCIALES (1080 × 1350, formato Instagram/Facebook)
 * Resultado de un partido, resultados de la jornada, tabla, goleadores, figura de la fecha y programación.
 */

const IMG_W = 1080;
const IMG_H = 1350;

function fuente(string $peso = 'bold'): string
{
    return APP_DIR . '/fuentes/Poppins-' . ['bold' => 'Bold', 'medium' => 'Medium', 'regular' => 'Regular'][$peso] . '.ttf';
}

function hex_rgb(string $hex): array
{
    $hex = ltrim(color_valido($hex, '#0b6e4f'), '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function col($img, array $rgb, int $alpha = 0)
{
    return imagecolorallocatealpha($img, $rgb[0], $rgb[1], $rgb[2], $alpha);
}

/** Lienzo con degradado del color de la liga hacia negro y franjas diagonales suaves. */
function lienzo(array $liga)
{
    $img = imagecreatetruecolor(IMG_W, IMG_H);
    imagealphablending($img, true);
    [$r, $g, $b] = hex_rgb($liga['color']);
    for ($y = 0; $y < IMG_H; $y++) {
        $f = $y / IMG_H;
        $k = 0.62 - 0.5 * $f; // más color arriba, más oscuro abajo
        $c = imagecolorallocate($img, (int)(12 + $r * $k), (int)(16 + $g * $k), (int)(15 + $b * $k));
        imageline($img, 0, $y, IMG_W, $y, $c);
    }
    $franja = imagecolorallocatealpha($img, 255, 255, 255, 122);
    for ($x = -IMG_H; $x < IMG_W; $x += 90) {
        imagefilledpolygon($img, [$x, 0, $x + 30, 0, $x + 30 + IMG_H, IMG_H, $x + IMG_H, IMG_H], $franja);
    }
    return $img;
}

/** Ancho de un texto. */
function ancho_texto(string $t, int $size, string $peso = 'bold'): int
{
    $b = imagettfbbox($size, 0, fuente($peso), $t);
    return (int)abs($b[2] - $b[0]);
}

/** Escribe texto; reduce la letra si no cabe en $max px. $alin: izq | centro | der. */
function texto($img, string $t, int $size, int $x, int $y, $color, string $peso = 'bold', string $alin = 'izq', int $max = 0): void
{
    $t = trim($t);
    if ($t === '') return;
    while ($max > 0 && $size > 12 && ancho_texto($t, $size, $peso) > $max) $size--;
    if ($max > 0 && ancho_texto($t, $size, $peso) > $max) {
        while (mb_strlen($t) > 3 && ancho_texto($t . '…', $size, $peso) > $max) $t = mb_substr($t, 0, -1);
        $t .= '…';
    }
    $w = ancho_texto($t, $size, $peso);
    $xx = $alin === 'centro' ? $x - intdiv($w, 2) : ($alin === 'der' ? $x - $w : $x);
    imagettftext($img, $size, 0, $xx, $y, $color, fuente($peso), $t);
}

function cargar_imagen(?string $ruta)
{
    if (!$ruta || str_contains($ruta, '..')) return null;
    $abs = PUBLIC_DIR . '/' . $ruta;
    if (!is_file($abs)) return null;
    $info = @getimagesize($abs);
    if (!$info) return null;
    return match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($abs),
        IMAGETYPE_PNG => @imagecreatefrompng($abs),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($abs) : null,
        IMAGETYPE_GIF => @imagecreatefromgif($abs),
        default => null,
    } ?: null;
}

/** Pega una imagen recortada en círculo (centro cx, cy y diámetro d), con borde. */
function circulo($img, $src, int $cx, int $cy, int $d, ?array $borde = [255, 255, 255], int $grosor = 6, bool $contener = false): void
{
    if ($borde) {
        imagefilledellipse($img, $cx, $cy, $d + $grosor * 2, $d + $grosor * 2, col($img, $borde));
    }
    $sq = imagecreatetruecolor($d, $d);
    imagefill($sq, 0, 0, imagecolorallocate($sq, 255, 255, 255));
    $w = imagesx($src);
    $h = imagesy($src);
    if ($contener) { // escudos: se ve completo dentro del círculo
        $esc = min(($d * 0.78) / $w, ($d * 0.78) / $h);
        $nw = (int)($w * $esc);
        $nh = (int)($h * $esc);
        imagecopyresampled($sq, $src, intdiv($d - $nw, 2), intdiv($d - $nh, 2), 0, 0, $nw, $nh, $w, $h);
    } else { // fotos: recorte centrado (un poco hacia arriba para la cara)
        $lado = min($w, $h);
        imagecopyresampled($sq, $src, 0, 0, intdiv($w - $lado, 2), (int)max(0, ($h - $lado) * 0.25), $d, $d, $lado, $lado);
    }
    $r2 = ($d / 2) ** 2;
    $x0 = $cx - intdiv($d, 2);
    $y0 = $cy - intdiv($d, 2);
    for ($y = 0; $y < $d; $y++) {
        $dy = ($y + 0.5 - $d / 2) ** 2;
        for ($x = 0; $x < $d; $x++) {
            if (($x + 0.5 - $d / 2) ** 2 + $dy <= $r2) {
                imagesetpixel($img, $x0 + $x, $y0 + $y, imagecolorat($sq, $x, $y));
            }
        }
    }
    unset($sq); // (v24) imagedestroy() ya no hace nada desde PHP 8 y PHP 8.5 lo marca como obsoleto
}

/** Escudo del equipo o círculo con iniciales. */
function escudo_img($img, ?string $ruta, string $nombre, string $color, int $cx, int $cy, int $d): void
{
    $src = cargar_imagen($ruta);
    if ($src) {
        circulo($img, $src, $cx, $cy, $d, [255, 255, 255], max(3, intdiv($d, 30)), true);
        return;
    }
    imagefilledellipse($img, $cx, $cy, $d + 8, $d + 8, col($img, [255, 255, 255]));
    imagefilledellipse($img, $cx, $cy, $d, $d, col($img, hex_rgb($color)));
    texto($img, iniciales($nombre), (int)($d * 0.3), $cx, $cy + (int)($d * 0.11), col($img, [255, 255, 255]), 'bold', 'centro');
}

function foto_img($img, array $j, int $cx, int $cy, int $d, string $color): void
{
    $src = foto_permitida($j) ? cargar_imagen($j['foto'] ?? '') : null;
    if ($src) {
        circulo($img, $src, $cx, $cy, $d, [255, 255, 255], max(4, intdiv($d, 40)));
        return;
    }
    imagefilledellipse($img, $cx, $cy, $d + 10, $d + 10, col($img, [255, 255, 255]));
    imagefilledellipse($img, $cx, $cy, $d, $d, col($img, [110, 120, 117]));
    texto($img, iniciales(nombre_jugador($j)), (int)($d * 0.3), $cx, $cy + (int)($d * 0.11), col($img, [255, 255, 255]), 'bold', 'centro');
}

/** Encabezado: logo + nombre de la liga + título grande. Devuelve la Y donde empieza el contenido. */
function cabecera_img($img, array $liga, string $titulo, string $sub): int
{
    $blanco = col($img, [255, 255, 255]);
    escudo_img($img, $liga['logo'], $liga['nombre'], $liga['color'], 110, 110, 120);
    texto($img, mb_strtoupper($liga['nombre']), 34, 200, 100, $blanco, 'bold', 'izq', 820);
    texto($img, $sub, 24, 200, 142, col($img, [220, 230, 226]), 'medium', 'izq', 820);
    // Título en franja
    imagefilledrectangle($img, 0, 205, IMG_W, 300, col($img, [0, 0, 0], 50));
    imagefilledrectangle($img, 0, 205, 14, 300, col($img, hex_rgb($liga['color'])));
    texto($img, mb_strtoupper($titulo), 54, IMG_W / 2, 273, $blanco, 'bold', 'centro', 1000);
    return 340;
}

/** Pie: patrocinador + dirección web. */
function pie_img($img, array $liga): void
{
    $blanco = col($img, [255, 255, 255]);
    imagefilledrectangle($img, 0, IMG_H - 120, IMG_W, IMG_H, col($img, [0, 0, 0], 40));
    $x = 50;
    // (v7) Patrocinador: el marcado "en imágenes" en Patrocinadores o, si no hay, el principal de Parámetros
    $pp = patrocinador_principal($liga, 'en_imagenes');
    $liga['patrocinador_logo'] = $pp['logo'];
    $liga['patrocinador_nombre'] = $pp['nombre'];
    $pat = cargar_imagen($liga['patrocinador_logo'] ?? '');
    if ($pat || ($liga['patrocinador_nombre'] ?? '') !== '') {
        texto($img, 'PATROCINA', 15, $x, IMG_H - 94, col($img, [200, 210, 206]), 'medium');
        if ($pat) {
            $h = 52;
            $w = (int)(imagesx($pat) * $h / imagesy($pat));
            if ($w > 200) { $w = 200; $h = (int)(imagesy($pat) * $w / imagesx($pat)); }
            $yc = IMG_H - 50;
            imagefilledrectangle($img, $x - 6, $yc - intdiv($h, 2) - 6, $x + $w + 6, $yc + intdiv($h, 2) + 6, $blanco);
            imagecopyresampled($img, $pat, $x, $yc - intdiv($h, 2), 0, 0, $w, $h, imagesx($pat), imagesy($pat));
            $x += $w + 26;
        }
        if (($liga['patrocinador_nombre'] ?? '') !== '' && 610 - $x >= 110) texto($img, $liga['patrocinador_nombre'], 28, $x, IMG_H - 40, $blanco, 'bold', 'izq', 610 - $x);
    }
    $host = preg_replace('#^https?://#', '', rtrim(url_base(), '/'));
    texto($img, $host, 20, IMG_W - 50, IMG_H - 70, $blanco, 'medium', 'der', 400);
    texto($img, 'Sistema de ligas · QF Producciones', 16, IMG_W - 50, IMG_H - 40, col($img, [190, 200, 196]), 'regular', 'der', 400);
}

function fila_equipos_resultado($img, array $p, int $y, int $alto, bool $mostrar_hora = false): void
{
    $blanco = col($img, [255, 255, 255]);
    imagefilledrectangle($img, 40, $y, IMG_W - 40, $y + $alto - 14, col($img, [0, 0, 0], 60));
    $cy = $y + intdiv($alto - 14, 2);
    $d = min(80, $alto - 34);
    escudo_img($img, $p['local_escudo'], $p['local_nombre'], $p['local_color'], 100, $cy, $d);
    escudo_img($img, $p['visitante_escudo'], $p['visitante_nombre'], $p['visitante_color'], IMG_W - 100, $cy, $d);
    $fs = $alto > 110 ? 30 : 24;
    $cx_l = 100 + intdiv($d, 2) + 22;
    texto($img, $p['local_nombre'], $fs, $cx_l, $cy + 11, $blanco, 'bold', 'izq', 420 - $cx_l);
    texto($img, $p['visitante_nombre'], $fs, IMG_W - $cx_l, $cy + 11, $blanco, 'bold', 'der', 420 - $cx_l);
    if ($mostrar_hora || !partido_cerrado($p)) {
        $t1 = $p['hora'] !== '' ? $p['hora'] : 'vs';
        texto($img, $t1, 34, IMG_W / 2, $cy + 2, $blanco, 'bold', 'centro');
        $info = trim(($p['fecha'] ? fecha_bonita($p['fecha']) : '') . ($p['cancha'] ? ' · ' . $p['cancha'] : ''));
        texto($img, $info, 16, IMG_W / 2, $cy + 30, col($img, [210, 220, 216]), 'medium', 'centro', 200);
    } else {
        imagefilledrectangle($img, IMG_W / 2 - 80, $cy - 34, IMG_W / 2 + 80, $cy + 34, $blanco);
        texto($img, (int)$p['goles_local'] . ' - ' . (int)$p['goles_visitante'], 40, IMG_W / 2, $cy + 16, col($img, [15, 22, 20]), 'bold', 'centro');
        if ($p['penales_local'] !== null) texto($img, '(pen ' . (int)$p['penales_local'] . '-' . (int)$p['penales_visitante'] . ')', 16, IMG_W / 2, $cy + 58, $blanco, 'medium', 'centro');
        if ($p['estado'] === 'wo') texto($img, 'W.O.', 16, IMG_W / 2, $cy + 58, $blanco, 'bold', 'centro');
    }
}

/* ============ Las 6 imágenes ============ */

function img_resultado(array $liga, array $p)
{
    $img = lienzo($liga);
    $blanco = col($img, [255, 255, 255]);
    $titulo = $p['estado'] === 'en_vivo' ? 'EN VIVO' : (partido_cerrado($p) ? 'RESULTADO FINAL' : 'PRÓXIMO PARTIDO');
    $y = cabecera_img($img, $liga, $titulo, $p['torneo'] . ' · ' . ($p['fase'] === 'regular' ? 'Jornada ' . (int)$p['jornada'] : nombre_fase($p['fase'])));
    escudo_img($img, $p['local_escudo'], $p['local_nombre'], $p['local_color'], 270, $y + 180, 250);
    escudo_img($img, $p['visitante_escudo'], $p['visitante_nombre'], $p['visitante_color'], IMG_W - 270, $y + 180, 250);
    texto($img, mb_strtoupper($p['local_nombre']), 32, 270, $y + 370, $blanco, 'bold', 'centro', 440);
    texto($img, mb_strtoupper($p['visitante_nombre']), 32, IMG_W - 270, $y + 370, $blanco, 'bold', 'centro', 440);
    if (partido_cerrado($p) || $p['estado'] === 'en_vivo') {
        texto($img, (int)$p['goles_local'] . ' - ' . (int)$p['goles_visitante'], 130, IMG_W / 2, $y + 540, $blanco, 'bold', 'centro');
        if ($p['penales_local'] !== null) texto($img, 'Penales ' . (int)$p['penales_local'] . ' - ' . (int)$p['penales_visitante'], 30, IMG_W / 2, $y + 600, $blanco, 'medium', 'centro');
    } else {
        texto($img, 'VS', 110, IMG_W / 2, $y + 530, $blanco, 'bold', 'centro');
        texto($img, trim(fecha_bonita($p['fecha']) . ($p['hora'] ? ' · ' . $p['hora'] : '')), 34, IMG_W / 2, $y + 600, $blanco, 'medium', 'centro');
    }
    // Goleadores (en básquet: los que más puntos hicieron; en ecuavóley: el detalle de los sets)
    $dep = deporte_de(reglas_torneo((int)$p['torneo_id']) ?? []);
    $col = [(int)$p['local_id'] => [], (int)$p['visitante_id'] => []];
    $ev = $dep === 'futbol' || $dep === 'indor' ? filas("SELECT ev.*, j.nombres, j.apellidos FROM eventos ev JOIN jugadores j ON j.id = ev.jugador_id WHERE ev.partido_id = ? AND ev.tipo IN ('gol','penal','autogol') ORDER BY (ev.minuto IS NULL), ev.minuto, ev.id", [$p['id']]) : [];
    if ($dep === 'basquet') {
        foreach (filas("SELECT ev.equipo_id, j.nombres, j.apellidos, SUM(" . sql_valor_evento('ev') . ") AS pts FROM eventos ev JOIN jugadores j ON j.id = ev.jugador_id
                        WHERE ev.partido_id = ? AND ev.tipo IN ('p1','p2','p3') GROUP BY ev.jugador_id ORDER BY pts DESC", [$p['id']]) as $x) {
            if (count($col[(int)$x['equipo_id']] ?? []) < 3) $col[(int)$x['equipo_id']][] = trim(mb_substr($x['nombres'], 0, 1) . '. ' . $x['apellidos']) . ' · ' . (int)$x['pts'] . ' pts';
        }
    }
    if ($dep === 'ecuavoley' && ($ds = detalle_sets((int)$p['id'])) !== '') {
        texto($img, 'Sets: ' . $ds, 34, IMG_W / 2, $y + 690, $blanco, 'bold', 'centro', 980);
    }
    foreach ($ev as $x) {
        $lado = $x['tipo'] === 'autogol' ? ((int)$x['equipo_id'] === (int)$p['local_id'] ? (int)$p['visitante_id'] : (int)$p['local_id']) : (int)$x['equipo_id'];
        $n = trim(mb_substr($x['nombres'], 0, 1) . '. ' . $x['apellidos']);
        $col[$lado][] = '⚽ ' . $n . ($x['minuto'] !== null ? ' ' . (int)$x['minuto'] . "'" : '') . ($x['tipo'] === 'penal' ? ' (p)' : ($x['tipo'] === 'autogol' ? ' (a.g.)' : ''));
    }
    $yy = $y + 660;
    foreach ([[(int)$p['local_id'], 270], [(int)$p['visitante_id'], IMG_W - 270]] as [$eid, $cx]) {
        foreach (array_slice($col[$eid], 0, 6) as $i => $linea) {
            if ($dep !== 'ecuavoley') texto($img, str_replace('⚽ ', '', $linea), 24, $cx, $yy + $i * 38, $blanco, 'medium', 'centro', 460);
        }
    }
    $info = trim(($p['fecha'] ? fecha_bonita($p['fecha']) : '') . ($p['cancha'] ? ' · ' . $p['cancha'] : ''));
    if (partido_cerrado($p) && $info !== '') texto($img, $info, 24, IMG_W / 2, IMG_H - 150, col($img, [220, 230, 226]), 'medium', 'centro');
    pie_img($img, $liga);
    return $img;
}

function img_lista_partidos(array $liga, array $t, array $partidos, string $titulo, string $sub, bool $programacion)
{
    $img = lienzo($liga);
    $y = cabecera_img($img, $liga, $titulo, $sub);
    $disp = IMG_H - 150 - $y;
    $n = max(1, count($partidos));
    $alto = (int)min(190, max(70, $disp / $n));
    $y += max(0, intdiv($disp - $alto * min($n, (int)floor($disp / 70)), 2));
    foreach (array_slice($partidos, 0, (int)floor($disp / 70)) as $i => $p) {
        fila_equipos_resultado($img, $p, $y + $i * $alto, $alto, $programacion);
    }
    if (!$partidos) texto($img, 'Sin partidos', 40, IMG_W / 2, $y + 200, col($img, [255, 255, 255]), 'bold', 'centro');
    pie_img($img, $liga);
    return $img;
}

function img_tabla(array $liga, array $t, string $grupo)
{
    $img = lienzo($liga);
    $blanco = col($img, [255, 255, 255]);
    $tablas = tabla_posiciones((int)$t['id']);
    $filas_t = $tablas[$grupo] ?? (reset($tablas) ?: []);
    $y = cabecera_img($img, $liga, 'Tabla de posiciones', $t['nombre'] . ($grupo !== '' ? ' · Grupo ' . $grupo : ''));
    $disp = IMG_H - 150 - $y - 50;
    $n = max(1, count($filas_t));
    $alto = (int)min(110, max(44, $disp / $n));
    $y += max(0, intdiv($disp - $alto * min($n, (int)floor($disp / 44)), 2) - 20);
    $fs = $alto >= 90 ? 32 : ($alto >= 60 ? 26 : 20);
    $gris = col($img, [200, 210, 206]);
    texto($img, 'PJ', 20, 700, $y + 26, $gris, 'bold', 'centro');
    texto($img, 'DG', 20, 820, $y + 26, $gris, 'bold', 'centro');
    texto($img, 'PTS', 20, 960, $y + 26, $gris, 'bold', 'centro');
    $y += 44;
    foreach (array_slice($filas_t, 0, (int)floor($disp / 44)) as $i => $f) {
        $yy = $y + $i * $alto;
        imagefilledrectangle($img, 40, $yy, IMG_W - 40, $yy + $alto - 6, col($img, [0, 0, 0], $i % 2 ? 80 : 60));
        $cy = $yy + intdiv($alto - 6, 2);
        if ($i < 1) imagefilledrectangle($img, 40, $yy, 50, $yy + $alto - 6, col($img, hex_rgb($liga['color'])));
        texto($img, (string)($i + 1), $fs, 90, $cy + intdiv($fs, 2) - 2, $blanco, 'bold', 'centro');
        $de = min(70, $alto - 20);
        escudo_img($img, $f['escudo'], $f['nombre'], $f['color'], 170, $cy, $de);
        texto($img, $f['nombre'], $fs, 170 + intdiv($de, 2) + 22, $cy + intdiv($fs, 2) - 2, $blanco, 'bold', 'izq', 640 - (170 + intdiv($de, 2) + 22));
        texto($img, (string)$f['pj'], $fs, 700, $cy + intdiv($fs, 2) - 2, $blanco, 'medium', 'centro');
        texto($img, ($f['dg'] > 0 ? '+' : '') . $f['dg'], $fs, 820, $cy + intdiv($fs, 2) - 2, $blanco, 'medium', 'centro');
        imagefilledrectangle($img, 915, $yy + 6, 1005, $yy + $alto - 12, $blanco);
        texto($img, (string)$f['pts'], $fs, 960, $cy + intdiv($fs, 2) - 2, col($img, [15, 22, 20]), 'bold', 'centro');
    }
    pie_img($img, $liga);
    return $img;
}

function img_goleadores(array $liga, array $t)
{
    $img = lienzo($liga);
    $blanco = col($img, [255, 255, 255]);
    $gol = goleadores((int)$t['id'], 8);
    $T = textos_deporte($t);
    $y = cabecera_img($img, $liga, 'Tabla de ' . mb_strtolower($T['goleadores'] ?: 'goleadores'), $t['nombre'] . ' ' . $t['temporada']);
    $alto = 106;
    $y += max(0, intdiv((IMG_H - 150 - $y) - $alto * count($gol), 2));
    foreach ($gol as $i => $g) {
        $yy = $y + $i * $alto;
        imagefilledrectangle($img, 40, $yy, IMG_W - 40, $yy + $alto - 12, col($img, [0, 0, 0], 60));
        $cy = $yy + intdiv($alto - 12, 2);
        texto($img, (string)($i + 1), 34, 85, $cy + 14, $blanco, 'bold', 'centro');
        foto_img($img, $g, 180, $cy, 76, $g['color']);
        texto($img, nombre_jugador($g), 30, 250, $cy - 2, $blanco, 'bold', 'izq', 560);
        texto($img, $g['equipo'], 22, 250, $cy + 32, col($img, [210, 220, 216]), 'medium', 'izq', 560);
        imagefilledrectangle($img, 880, $yy + 12, 1020, $yy + $alto - 24, $blanco);
        texto($img, (string)(int)$g['goles'], 40, 950, $cy + 16, col($img, [15, 22, 20]), 'bold', 'centro');
    }
    if (!$gol) texto($img, 'Aún no hay ' . mb_strtolower($T['goles']), 40, IMG_W / 2, $y + 200, $blanco, 'bold', 'centro');
    pie_img($img, $liga);
    return $img;
}

function img_figura(array $liga, array $t, array $j, int $jornada, string $motivo)
{
    $img = lienzo($liga);
    $blanco = col($img, [255, 255, 255]);
    $y = cabecera_img($img, $liga, 'Figura de la fecha', $t['nombre'] . ' · Jornada ' . $jornada);
    foto_img($img, $j, IMG_W / 2, $y + 270, 460, $j['color']);
    escudo_img($img, $j['escudo'], $j['equipo'], $j['color'], IMG_W / 2 + 190, $y + 450, 120);
    texto($img, mb_strtoupper(nombre_jugador($j)), 58, IMG_W / 2, $y + 620, $blanco, 'bold', 'centro', 980);
    texto($img, $j['equipo'] . ($j['numero'] !== null ? ' · #' . (int)$j['numero'] : ''), 32, IMG_W / 2, $y + 675, col($img, [220, 230, 226]), 'medium', 'centro', 980);
    if ($motivo !== '') {
        $w = min(980, ancho_texto($motivo, 34) + 80);
        imagefilledrectangle($img, IMG_W / 2 - intdiv($w, 2), $y + 715, IMG_W / 2 + intdiv($w, 2), $y + 785, $blanco);
        texto($img, $motivo, 34, IMG_W / 2, $y + 764, col($img, [15, 22, 20]), 'bold', 'centro', 940);
    }
    pie_img($img, $liga);
    return $img;
}

/** Figura de la fecha: la elegida por la liga o, si no hay, el que más goles hizo en la jornada. */
function figura_de_jornada(int $torneo_id, int $jornada): ?array
{
    $f = fila('SELECT f.*, j.*, e.nombre AS equipo, e.escudo, e.color FROM figuras f JOIN jugadores j ON j.id = f.jugador_id JOIN equipos e ON e.id = j.equipo_id WHERE f.torneo_id = ? AND f.jornada = ?', [$torneo_id, $jornada]);
    if ($f) return $f + ['motivo_auto' => $f['motivo']];
    $g = fila("SELECT j.*, e.nombre AS equipo, e.escudo, e.color, SUM(" . sql_valor_evento('ev') . ") AS goles FROM eventos ev JOIN partidos p ON p.id = ev.partido_id
               JOIN jugadores j ON j.id = ev.jugador_id JOIN equipos e ON e.id = ev.equipo_id
               WHERE p.torneo_id = ? AND p.fase = 'regular' AND p.jornada = ? AND p.estado = 'jugado' AND ev.tipo IN ('gol','penal','p1','p2','p3')
               GROUP BY j.id ORDER BY goles DESC, MIN(ev.id) LIMIT 1", [$torneo_id, $jornada]);
    if (!$g) return null;
    $basq = deporte_de(reglas_torneo($torneo_id) ?? []) === 'basquet';
    return $g + ['motivo_auto' => (int)$g['goles'] . ($basq ? ' PUNTOS' : ((int)$g['goles'] === 1 ? ' GOL' : ' GOLES')) . ' EN LA FECHA'];
}

/* ============ (v7) Cromo del jugador ============ */

/** Pega una imagen recortada para llenar un rectángulo (como una foto de álbum). */
function foto_rectangulo($img, $src, int $x, int $y, int $w, int $h): void
{
    $sw = imagesx($src);
    $sh = imagesy($src);
    $esc = max($w / $sw, $h / $sh);
    $cw = (int)($w / $esc);
    $ch = (int)($h / $esc);
    imagecopyresampled($img, $src, $x, $y, intdiv($sw - $cw, 2), (int)max(0, ($sh - $ch) * 0.2), $w, $h, $cw, $ch);
}

/** Cromo del jugador: foto grande, número, equipo y sus números en la liga (para compartir en redes). */
function img_cromo(array $liga, array $j, array $st)
{
    $img = lienzo($liga);
    $blanco = col($img, [255, 255, 255]);
    $oscuro = col($img, [15, 22, 20]);
    $T = textos_deporte($st['deporte']);
    // Marco del cromo
    imagefilledrectangle($img, 60, 60, IMG_W - 60, IMG_H - 150, $blanco);
    imagefilledrectangle($img, 78, 78, IMG_W - 78, IMG_H - 168, col($img, hex_rgb($j['color'])));
    // Foto (o iniciales)
    $fx = 100; $fy = 100; $fw = IMG_W - 200; $fh = 700;
    $src = foto_permitida($j) ? cargar_imagen($j['foto'] ?? '') : null;
    if ($src) {
        foto_rectangulo($img, $src, $fx, $fy, $fw, $fh);
    } else {
        imagefilledrectangle($img, $fx, $fy, $fx + $fw, $fy + $fh, col($img, [60, 70, 67]));
        texto($img, iniciales(nombre_jugador($j)), 220, IMG_W / 2, $fy + 470, col($img, [200, 210, 206]), 'bold', 'centro');
    }
    // Degradado sobre la parte de abajo de la foto para leer el nombre
    for ($i = 0; $i < 220; $i++) {
        imageline($img, $fx, $fy + $fh - 220 + $i, $fx + $fw, $fy + $fh - 220 + $i, col($img, [0, 0, 0], (int)(127 - $i * 0.5)));
    }
    // Número y logo de la liga
    if ($j['numero'] !== null) {
        imagefilledellipse($img, $fx + 95, $fy + 95, 150, 150, $blanco);
        texto($img, (string)(int)$j['numero'], 70, $fx + 95, $fy + 122, $oscuro, 'bold', 'centro');
    }
    escudo_img($img, $liga['logo'], $liga['nombre'], $liga['color'], $fx + $fw - 80, $fy + 80, 110);
    texto($img, mb_strtoupper(nombre_jugador($j)), 56, IMG_W / 2, $fy + $fh - 70, $blanco, 'bold', 'centro', $fw - 40);
    texto($img, mb_strtoupper(trim(($j['posicion'] ?? '') !== '' ? $j['posicion'] : '')), 26, IMG_W / 2, $fy + $fh - 25, col($img, [230, 236, 233]), 'medium', 'centro', $fw - 40);
    // Equipo
    $ye = $fy + $fh + 70;
    escudo_img($img, $j['escudo'], $j['equipo'], $j['color'], 170, $ye, 90);
    texto($img, $j['equipo'], 40, 240, $ye + 14, $blanco, 'bold', 'izq', IMG_W - 340);
    // Números
    $datos = [['PJ', $st['pj']]];
    if ($T['con_goleadores']) $datos[] = [$T['deporte'] === 'basquet' ? 'PUNTOS' : 'GOLES', $st['goles']];
    if ($T['deporte'] === 'basquet') $datos[] = ['TRIPLES', $st['triples']];
    $datos[] = [$T['deporte'] === 'basquet' ? 'TÉCNICAS' : 'AMARILLAS', $st['amarillas']];
    $datos[] = ['TÍTULOS', $st['titulos']];
    $ancho = intdiv(IMG_W - 200, count($datos));
    $yb = $ye + 60;
    foreach ($datos as $i => [$et, $n]) {
        $cx = 100 + $ancho * $i + intdiv($ancho, 2);
        imagefilledrectangle($img, $cx - intdiv($ancho, 2) + 8, $yb, $cx + intdiv($ancho, 2) - 8, $yb + 130, col($img, [0, 0, 0], 70));
        texto($img, (string)(int)$n, 54, $cx, $yb + 72, $blanco, 'bold', 'centro');
        texto($img, $et, 18, $cx, $yb + 110, col($img, [220, 230, 226]), 'medium', 'centro', $ancho - 20);
    }
    texto($img, mb_strtoupper($liga['nombre']), 20, IMG_W / 2, IMG_H - 180, col($img, [240, 244, 242]), 'medium', 'centro', 900);
    pie_img($img, $liga);
    return $img;
}

/** Números del jugador para el cromo (en toda la liga). */
function numeros_cromo(array $j, array $liga): array
{
    $st = estadisticas_jugador((int)$j['id']);
    $pj = (int)valor("SELECT COUNT(*) FROM alineaciones a JOIN partidos p ON p.id = a.partido_id WHERE a.jugador_id = ? AND p.estado IN ('jugado','wo')", [$j['id']]);
    $titulos = (int)valor("SELECT COUNT(*) FROM torneos t WHERE t.campeon_id IS NOT NULL AND EXISTS (SELECT 1 FROM alineaciones a JOIN partidos p ON p.id = a.partido_id WHERE a.jugador_id = ? AND a.equipo_id = t.campeon_id AND p.torneo_id = t.id)", [$j['id']]);
    $dep = deporte_de(fila("SELECT t.modalidad FROM torneo_equipos te JOIN torneos t ON t.id = te.torneo_id WHERE te.equipo_id = ? ORDER BY t.id DESC LIMIT 1", [$j['equipo_id']]) ?? $liga);
    return ['pj' => $pj, 'goles' => (int)($st['goles'] ?? 0), 'triples' => (int)($st['triples'] ?? 0), 'amarillas' => (int)($st['amarillas'] ?? 0), 'titulos' => $titulos, 'deporte' => $dep];
}

/* ============================================================
 * (v12) IMAGEN DE UN PLAN (para enviar por WhatsApp y publicar en redes)
 * ============================================================ */
function img_plan(array $pl)
{
    $fake = ['color' => '#0b6e4f', 'nombre' => marca(), 'logo' => ''];
    $img = lienzo($fake);
    $blanco = col($img, [255, 255, 255]);
    $suave = col($img, [215, 230, 224]);
    $amarillo = col($img, [255, 213, 74]);
    $verde = col($img, [120, 230, 160]);
    $gris = col($img, [150, 165, 160]);
    escudo_img($img, 'assets/icono-192.png', marca(), '#0b6e4f', 105, 105, 110);
    texto($img, mb_strtoupper(marca()), 34, 190, 98, $blanco, 'bold', 'izq', 840);
    texto($img, 'Sistema para ligas y escuelas deportivas', 22, 190, 138, $suave, 'medium', 'izq', 840);
    // Nombre del plan
    imagefilledrectangle($img, 0, 190, IMG_W, 330, col($img, [0, 0, 0], 55));
    texto($img, 'PLAN ' . mb_strtoupper($pl['nombre']), 64, IMG_W / 2, 272, $blanco, 'bold', 'centro', 1000);
    if ($pl['eslogan'] !== '') texto($img, $pl['eslogan'], 24, IMG_W / 2, 315, $suave, 'medium', 'centro', 1000);
    // Precio principal y demás períodos
    $ops = opciones_precio($pl);
    $p0 = reset($ops);
    $y = 448;
    if ($p0) {
        $precio = dinero($p0['precio']);
        $w = ancho_texto($precio, 96);
        texto($img, $precio, 96, IMG_W / 2 - 90, $y, $amarillo, 'bold', 'centro');
        texto($img, $p0['texto'], 32, IMG_W / 2 - 90 + intdiv($w, 2) + 18, $y - 8, $blanco, 'medium');
        $otros = array_slice($ops, 1);
        if ($otros) texto($img, implode('   ·   ', array_map(fn($o) => dinero($o['precio']) . ' ' . $o['texto'] . ($o['gratis'] ? ' (' . texto_ahorro($o['gratis']) . ')' : ''), $otros)), 24, IMG_W / 2, $y + 52, $blanco, 'medium', 'centro', 1000);
    }
    // Límites
    $y = 556;
    texto($img, implode('  ·  ', array_map(fn($t) => trim(str_replace('🌐', '', $t)), textos_limites_plan($pl))), 24, IMG_W / 2, $y, $suave, 'medium', 'centro', 1000);
    // Módulos (dos columnas)
    $y = 610;
    texto($img, 'INCLUYE', 22, 70, $y, $amarillo, 'bold');
    $mods = modulos_sistema($pl['tipo_cuenta'] === 'ambos' ? '' : $pl['tipo_cuenta']);
    $mp = modulos_plan($pl);
    $base = $pl['tipo_cuenta'] === 'escuela' ? ['Alumnos, grupos y asistencia', 'Mensualidades y recibos'] : ['Fixture y sorteo automático', 'Tabla, goleadores y tarjetas', 'Página pública de la liga'];
    $lineas = array_merge(array_map(fn($f) => [true, $f], $base), array_map(fn($k) => [in_array($k, $mp, true), $mods[$k][1]], array_keys($mods)));
    // Un solo tamaño de letra para todas las líneas (el que haga caber la más larga)
    $tam = 26;
    while ($tam > 16 && max(array_map(fn($l) => ancho_texto($l[1], $tam, 'medium'), $lineas)) > 440) $tam--;
    $col_n = (int)ceil(count($lineas) / 2);
    $alto = min(46, (int)floor(560 / max(1, $col_n)));
    foreach ($lineas as $i => [$si, $txt]) {
        $x = $i < $col_n ? 70 : 560;
        $yy = $y + 50 + ($i % $col_n) * $alto;
        if ($si) {
            imagefilledellipse($img, $x + 12, $yy - 9, 22, 22, $verde);
            imagesetthickness($img, 3);
            imageline($img, $x + 6, $yy - 9, $x + 11, $yy - 4, col($img, [10, 60, 40]));
            imageline($img, $x + 11, $yy - 4, $x + 19, $yy - 15, col($img, [10, 60, 40]));
            imagesetthickness($img, 1);
        } else {
            imagefilledrectangle($img, $x + 4, $yy - 11, $x + 20, $yy - 7, $gris);
        }
        texto($img, $txt, $tam, $x + 34, $yy, $si ? $blanco : $gris, 'medium', 'izq', 440);
    }
    // Pie
    imagefilledrectangle($img, 0, IMG_H - 130, IMG_W, IMG_H, col($img, [0, 0, 0], 40));
    texto($img, 'Solicite su demo', 34, 60, IMG_H - 70, $amarillo, 'bold', 'izq', 560);
    $host = preg_replace('#^https?://#', '', rtrim(url_base(), '/'));
    texto($img, $host, 26, IMG_W - 60, IMG_H - 78, $blanco, 'bold', 'der', 420);
    if (($wa = (string)ajuste('ventas_whatsapp')) !== '') texto($img, 'WhatsApp ' . $wa, 22, IMG_W - 60, IMG_H - 40, $suave, 'medium', 'der', 420);
    return $img;
}

/**
 * (v26) Imagen de un deporte individual (podio, clasificación, medallero, resultados de una prueba o de una fecha).
 * $d = ['titulo', 'sub', 'filas' => [[pos | 'oro' | 'plata' | 'bronce' | '✓', nombre, detalle, valor]]]
 */
function img_lista_indiv(array $liga, array $d)
{
    $img = lienzo($liga);
    $blanco = col($img, [255, 255, 255]);
    $gris = col($img, [210, 220, 216]);
    $oscuro = col($img, [15, 22, 20]);
    $med = ['oro' => [212, 175, 55], 'plata' => [192, 192, 200], 'bronce' => [205, 127, 50]];
    $num_med = ['oro' => '1', 'plata' => '2', 'bronce' => '3'];
    $filas = array_slice($d['filas'], 0, 10);
    $y = cabecera_img($img, $liga, $d['titulo'], $d['sub']);
    $disp = IMG_H - 150 - $y - 20;
    $n = max(1, count($filas));
    $alto = (int)min(150, max(80, intdiv($disp, $n)));
    $y += max(0, intdiv($disp - $alto * $n, 2));
    $grande = $alto >= 120;
    foreach ($filas as $i => [$pos, $nombre, $det, $valor]) {
        $yy = $y + $i * $alto;
        $es_med = isset($med[(string)$pos]);
        imagefilledrectangle($img, 40, $yy, IMG_W - 40, $yy + $alto - 12, col($img, [0, 0, 0], $es_med ? 45 : ($i % 2 ? 80 : 62)));
        $cy = $yy + intdiv($alto - 12, 2);
        if ($es_med) {
            imagefilledrectangle($img, 40, $yy, 52, $yy + $alto - 12, col($img, $med[$pos]));
            $dm = min(78, $alto - 30);
            imagefilledellipse($img, 112, $cy, $dm, $dm, col($img, $med[$pos]));
            imageellipse($img, 112, $cy, $dm, $dm, $blanco);
            texto($img, $num_med[$pos], (int)($dm * 0.45), 112, $cy + (int)($dm * 0.22), $oscuro, 'bold', 'centro');
        } elseif ($pos === '✓') {
            imagefilledellipse($img, 112, $cy, 56, 56, col($img, hex_rgb($liga['color'])));
            texto($img, 'G', 26, 112, $cy + 12, $blanco, 'bold', 'centro');
        } else {
            texto($img, (string)$pos, $grande ? 38 : 32, 112, $cy + ($grande ? 16 : 13), $blanco, 'bold', 'centro');
        }
        $tx = 175;
        $ancho_valor = $valor !== '' ? min(340, max(150, ancho_texto((string)$valor, $grande ? 34 : 28) + 40)) : 0;
        $max = IMG_W - 60 - $ancho_valor - $tx - 20;
        if ($det !== '') {
            texto($img, $nombre, $grande ? 34 : 28, $tx, $cy - 2, $blanco, 'bold', 'izq', $max);
            texto($img, $det, $grande ? 22 : 19, $tx, $cy + ($grande ? 34 : 28), $gris, 'medium', 'izq', $max);
        } else {
            texto($img, $nombre, $grande ? 34 : 28, $tx, $cy + 12, $blanco, 'bold', 'izq', $max);
        }
        if ($valor !== '') {
            $x2 = IMG_W - 56;
            imagefilledrectangle($img, $x2 - $ancho_valor, $yy + 14, $x2, $yy + $alto - 26, $blanco);
            texto($img, (string)$valor, $grande ? 34 : 28, $x2 - intdiv($ancho_valor, 2), $cy + ($grande ? 13 : 11), $oscuro, 'bold', 'centro', $ancho_valor - 16);
        }
    }
    if (!$filas) texto($img, 'Todavía no hay resultados', 40, IMG_W / 2, $y + 200, $blanco, 'bold', 'centro');
    pie_img($img, $liga);
    return $img;
}
