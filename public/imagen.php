<?php
/*
 * Imagen para redes sociales (JPG 1080×1350).
 * imagen.php?tipo=resultado&p=ID
 * imagen.php?tipo=jornada|programacion&t=ID&j=N
 * imagen.php?tipo=tabla&t=ID[&g=A]
 * imagen.php?tipo=goleadores&t=ID
 * imagen.php?tipo=figura&t=ID&j=N
 * imagen.php?tipo=cromo&j=ID_JUGADOR   (v7: cromo del jugador)
 * imagen.php?tipo=plan&plan=ID          (v12: plan de venta para WhatsApp)
 * imagen.php?tipo=cumple&l=ID_LIGA      (v18: cumpleañeros del día)
 * imagen.php?tipo=indiv&t=ID&que=podio|tabla|medallero|resultados|prueba|general|clubes|fecha&c=ID  (v26: deportes individuales)
 * Agregue &descargar=1 para bajar el archivo.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/imagenes.php';
// Las fotos de los jugadores solo salen si están autorizadas para el público (protección de datos)
define('VISTA_PUBLICA', true);
define('SIN_PRECIO_PLAY', true); // (v38) las imágenes para compartir llevan siempre los precios de la web

function no_disponible(): never
{
    http_response_code(404);
    exit('Imagen no disponible');
}

if (!function_exists('imagettftext')) {
    http_response_code(500);
    exit('El servidor no tiene GD con fuentes (instale php-gd).');
}

$tipo = get('tipo');
$liga = null;
$t = null;
$nombre = 'imagen';
$datos_firma = [];

if ($tipo === 'cromo') {
    $j = fila('SELECT j.*, e.nombre AS equipo, e.escudo, e.color, e.liga_id FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.id = ? AND j.anonimizado = 0', [(int)get('j')]) ?? no_disponible();
    $liga = fila('SELECT * FROM ligas WHERE id = ? AND activa = 1', [$j['liga_id']]) ?? no_disponible();
    $num_cromo = numeros_cromo($j, $liga);
    $datos_firma = [$j, $num_cromo, patrocinador_principal($liga)];
    $nombre = 'cromo_' . slugify(nombre_jugador($j));
} elseif ($tipo === 'plan') {
    // (v12) Imagen de un plan para WhatsApp y redes
    $pl = fila('SELECT * FROM planes WHERE id = ?', [(int)get('plan')]) ?? no_disponible();
    $liga = ['id' => 0, 'color' => '#0b6e4f', 'nombre' => marca(), 'logo' => ''];
    $datos_firma = [$pl, modulos_sistema(), ajuste('ventas_whatsapp'), ajuste('dias_prueba'), ajuste('comision_credito'), 'solo_demo'];
    $nombre = 'plan_' . slugify($pl['nombre']);
} elseif ($tipo === 'cumple') {
    // (v18) Cumpleañeros del día: imagen.php?tipo=cumple&l=ID_LIGA[&f=AAAA-MM-DD]
    $liga = fila('SELECT * FROM ligas WHERE id = ? AND activa = 1', [(int)get('l')]) ?? no_disponible();
    $f_cumple = preg_match('/^\d{4}-\d{2}-\d{2}$/', get('f')) ? get('f') : date('Y-m-d');
    $lista_cumple = cumpleaneros_liga((int)$liga['id'], $f_cumple);
    $datos_firma = [$lista_cumple, $f_cumple];
    $nombre = 'cumpleaneros_' . $f_cumple;
} elseif ($tipo === 'resultado') {
    $p = partido_completo((int)get('p')) ?? no_disponible();
    $liga = fila('SELECT * FROM ligas WHERE id = ? AND activa = 1', [$p['liga_id']]) ?? no_disponible();
    $datos_firma = [$p, filas('SELECT * FROM eventos WHERE partido_id = ?', [$p['id']]), sets_partido((int)$p['id']), patrocinador_principal($liga)];
    $nombre = 'resultado_' . slugify($p['local_nombre'] . '-vs-' . $p['visitante_nombre']);
} else {
    $t = fila('SELECT * FROM torneos WHERE id = ?', [(int)get('t')]) ?? no_disponible();
    $liga = fila('SELECT * FROM ligas WHERE id = ? AND activa = 1', [$t['liga_id']]) ?? no_disponible();
    $jornada = (int)get('j');
    if ($tipo === 'indiv') {
        // (v26) Deportes individuales: podio, clasificación, medallero, resultado de una prueba o de una fecha
        if (!es_individual($t)) no_disponible();
        $datos_indiv = datos_imagen_individual($t, get('que'), (int)get('c')) ?? no_disponible();
        $datos_firma = $datos_indiv;
        $nombre = slugify($datos_indiv['titulo'] . ' ' . $datos_indiv['sub']);
    } elseif ($tipo === 'jornada' || $tipo === 'programacion') {
        $ps = partidos_torneo((int)$t['id'], 'AND p.fase = \'regular\' AND p.jornada = ' . $jornada);
        $datos_firma = $ps;
        $nombre = ($tipo === 'jornada' ? 'resultados' : 'programacion') . '_jornada_' . $jornada;
    } elseif ($tipo === 'tabla') {
        $datos_firma = [tabla_posiciones((int)$t['id']), get('g')];
        $nombre = 'tabla_' . slugify($t['nombre']);
    } elseif ($tipo === 'goleadores') {
        $datos_firma = goleadores((int)$t['id'], 8);
        $nombre = 'goleadores_' . slugify($t['nombre']);
    } elseif ($tipo === 'figura') {
        $fig = figura_de_jornada((int)$t['id'], $jornada) ?? no_disponible();
        $datos_firma = $fig;
        $nombre = 'figura_jornada_' . $jornada;
    } else {
        no_disponible();
    }
}

// (Anexo B) las imágenes de una cuenta son del módulo de redes (el cromo, del de carnets); la del plan es de la página de venta
if ($tipo !== 'plan' && !empty($liga['id'])) exigir_modulo_corto($liga, $tipo === 'cromo' ? 'carnets' : 'redes');
// Caché: solo se vuelve a dibujar si cambiaron los datos
$firma = md5(json_encode([$tipo, $_GET['g'] ?? '', $liga, $t, $datos_firma, patrocinador_principal($liga), url_base(), filemtime(APP_DIR . '/imagenes.php'), filemtime(APP_DIR . '/v18.php'), filemtime(APP_DIR . '/individuales_extra.php')]));
$dir_cache = RAIZ . '/data/cache';
if (!is_dir($dir_cache)) @mkdir($dir_cache, 0770, true);
$archivo = $dir_cache . '/img_' . $firma . '.jpg';

if (!is_file($archivo)) {
    $img = match ($tipo) {
        'plan' => img_plan($pl),
        'indiv' => img_lista_indiv($liga, $datos_indiv),
        'cumple' => img_cumpleanos($liga, $lista_cumple, $f_cumple),
        'resultado' => img_resultado($liga, $p),
        'cromo' => img_cromo($liga, $j, $num_cromo),
        'jornada' => img_lista_partidos($liga, $t, $ps, 'Resultados · Jornada ' . $jornada, $t['nombre'] . ' ' . $t['temporada'], false),
        'programacion' => img_lista_partidos($liga, $t, $ps, 'Programación · Jornada ' . $jornada, $t['nombre'] . ' ' . $t['temporada'], true),
        'tabla' => img_tabla($liga, $t, get('g')),
        'goleadores' => img_goleadores($liga, $t),
        'figura' => img_figura($liga, $t, $fig, $jornada, mb_strtoupper($fig['motivo_auto'])),
    };
    imagejpeg($img, $archivo, 90);
    unset($img); // (v24) imagedestroy() ya no hace nada desde PHP 8 y PHP 8.5 lo marca como obsoleto
    // Limpieza: borra imágenes en caché de más de 15 días
    if (random_int(1, 50) === 1) {
        foreach (glob($dir_cache . '/img_*.jpg') ?: [] as $f) {
            if (filemtime($f) < time() - 15 * 86400) @unlink($f);
        }
    }
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($archivo));
header('Cache-Control: public, max-age=60');
if (get('descargar') === '1') {
    header('Content-Disposition: attachment; filename="' . $nombre . '.jpg"');
}
readfile($archivo);
