<?php
/*
 * VERSIÓN 7 — Funciones para el público y para vender:
 *   1) Patrocinadores con espacios vendibles y reporte de vistas y clics.
 *   2) Votación del público (jugador del partido, gol de la fecha, libre).
 *   3) Polla de pronósticos de la liga (gratis, sin dinero, con premios de patrocinadores).
 */

/* ============================================================
 * 1) PATROCINADORES
 * ============================================================ */

/** Lugares donde puede aparecer un patrocinador: columna => texto. */
function lugares_patrocinio(): array
{
    return [
        'en_pagina' => 'Banner en la página pública de la liga',
        'en_partidos' => '"Partido presentado por…" (el que se elija en cada partido)',
        'en_imagenes' => 'Logo en las imágenes para redes sociales',
        'en_carnets' => 'Logo en los carnets impresos',
    ];
}

/** Patrocinadores vigentes de una liga para un lugar ('en_pagina', 'en_partidos', 'en_imagenes', 'en_carnets'). */
function patrocinadores_activos(int $liga_id, string $lugar = ''): array
{
    static $con = [];
    $con[$liga_id] ??= modulo_encendido(fila('SELECT * FROM ligas WHERE id = ?', [$liga_id]), 'publico'); // (Anexo B) banners de «Patrocinadores y público»
    if (!$con[$liga_id]) return [];
    $hoy = date('Y-m-d');
    $col = array_key_exists($lugar, lugares_patrocinio()) ? " AND $lugar = 1" : '';
    return filas("SELECT * FROM patrocinadores WHERE liga_id = ? AND activo = 1 AND (desde = '' OR desde <= ?) AND (hasta = '' OR hasta >= ?)$col ORDER BY orden, id", [$liga_id, $hoy, $hoy]);
}

/** ¿La visita parece de un robot (buscadores)? Esas no se cuentan como vistas. */
function es_robot(): bool
{
    return (bool)preg_match('/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|preview|curl|wget|python/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

/** Suma una vista (o un clic) al reporte del patrocinador. */
function contar_patrocinio(int $id, string $lugar, string $campo = 'vistas'): void
{
    if ($campo === 'vistas' && (PHP_SAPI === 'cli' || es_robot())) return;
    $campo = $campo === 'clics' ? 'clics' : 'vistas';
    try {
        q("INSERT INTO patrocinio_vistas (patrocinador_id, fecha, lugar, $campo) VALUES (?, ?, ?, 1)
           ON CONFLICT(patrocinador_id, fecha, lugar) DO UPDATE SET $campo = $campo + 1", [$id, date('Y-m-d'), $lugar]);
    } catch (Throwable) {
        // el conteo nunca debe romper la página
    }
}

/** Enlace que cuenta el clic y lleva a la página del patrocinador. */
function enlace_patrocinio(array $s, string $lugar): string
{
    return 'index.php?clic=' . (int)$s['id'] . '&amp;lugar=' . rawurlencode($lugar);
}

/** Banner rotativo de la página pública (uno al azar entre los vigentes). */
function banner_patrocinador(array $liga, string $lugar = 'pagina'): string
{
    if (!defined('VISTA_PUBLICA')) return '';
    $lista = patrocinadores_activos((int)$liga['id'], 'en_pagina');
    if (!$lista) return '';
    $s = $lista[array_rand($lista)];
    contar_patrocinio((int)$s['id'], $lugar);
    $img = $s['banner'] !== '' ? $s['banner'] : $s['logo'];
    $cuerpo = '<span class="patro-etq">Patrocina</span>'
        . ($img !== '' ? '<img src="' . e($img) . '" alt="' . e($s['nombre']) . '" class="' . ($s['banner'] !== '' ? 'patro-banner' : 'patro-logo') . '">' : '')
        . ($s['banner'] === '' ? '<span class="patro-txt"><b>' . e($s['nombre']) . '</b>' . ($s['texto'] !== '' ? '<br><small>' . e($s['texto']) . '</small>' : '') . '</span>' : '');
    $h = $s['enlace'] !== '' ? '<a class="patrocinio" href="' . enlace_patrocinio($s, $lugar) . '" target="_blank" rel="noopener sponsored">' . $cuerpo . '</a>'
                             : '<div class="patrocinio">' . $cuerpo . '</div>';
    return '<div class="contenedor-patro">' . $h . '</div>';
}

/** "Partido presentado por…" de un partido (o vacío). */
function presentado_por(array $p): string
{
    if (empty($p['patrocinador_id'])) return '';
    $s = fila("SELECT * FROM patrocinadores WHERE id = ? AND activo = 1", [$p['patrocinador_id']]);
    if (!$s) return '';
    if (defined('VISTA_PUBLICA')) contar_patrocinio((int)$s['id'], 'partido');
    $cuerpo = '<small>Partido presentado por</small> ' . ($s['logo'] !== '' ? '<img src="' . e($s['logo']) . '" alt="" class="patro-logo">' : '') . '<b>' . e($s['nombre']) . '</b>';
    return $s['enlace'] !== '' ? '<a class="presentado" href="' . enlace_patrocinio($s, 'partido') . '" target="_blank" rel="noopener sponsored">' . $cuerpo . '</a>'
                               : '<div class="presentado">' . $cuerpo . '</div>';
}

/**
 * Patrocinador para imágenes de redes o carnets: el primero marcado para ese lugar;
 * si no hay ninguno, el "patrocinador principal" de Parámetros (como en la versión 6).
 */
function patrocinador_principal(array $liga, string $lugar = 'en_imagenes'): array
{
    $l = patrocinadores_activos((int)$liga['id'], $lugar);
    if ($l) return ['logo' => $l[0]['logo'], 'nombre' => $l[0]['nombre'], 'id' => (int)$l[0]['id']];
    return ['logo' => (string)($liga['patrocinador_logo'] ?? ''), 'nombre' => (string)($liga['patrocinador_nombre'] ?? ''), 'id' => 0];
}

/** Reporte de vistas y clics de un patrocinador entre dos fechas: ['total' => [...], 'por_lugar' => [...], 'por_dia' => [...]]. */
function reporte_patrocinio(int $id, string $desde, string $hasta): array
{
    $total = fila('SELECT COALESCE(SUM(vistas),0) AS vistas, COALESCE(SUM(clics),0) AS clics FROM patrocinio_vistas WHERE patrocinador_id = ? AND fecha BETWEEN ? AND ?', [$id, $desde, $hasta]);
    $por_lugar = filas('SELECT lugar, SUM(vistas) AS vistas, SUM(clics) AS clics FROM patrocinio_vistas WHERE patrocinador_id = ? AND fecha BETWEEN ? AND ? GROUP BY lugar ORDER BY vistas DESC', [$id, $desde, $hasta]);
    $por_dia = filas('SELECT fecha, SUM(vistas) AS vistas, SUM(clics) AS clics FROM patrocinio_vistas WHERE patrocinador_id = ? AND fecha BETWEEN ? AND ? GROUP BY fecha ORDER BY fecha', [$id, $desde, $hasta]);
    $votos = (int)valor('SELECT COUNT(*) FROM votos vo JOIN votaciones v ON v.id = vo.votacion_id WHERE v.patrocinador_id = ? AND substr(vo.creado, 1, 10) BETWEEN ? AND ?', [$id, $desde, $hasta]);
    $partidos = (int)valor("SELECT COUNT(*) FROM partidos WHERE patrocinador_id = ? AND fecha BETWEEN ? AND ?", [$id, $desde, $hasta]);
    return ['total' => $total, 'por_lugar' => $por_lugar, 'por_dia' => $por_dia, 'votos' => $votos, 'partidos' => $partidos];
}

function nombre_lugar_patrocinio(string $l): string
{
    return ['pagina' => 'Página de la liga', 'partido' => 'Página de partidos', 'equipo' => 'Página de equipos', 'jugador' => 'Página de jugadores', 'votacion' => 'Votaciones', 'polla' => 'Polla'][$l] ?? $l;
}

/* ============================================================
 * 2) VOTACIÓN DEL PÚBLICO
 * ============================================================ */

function tipos_votacion(): array
{
    return ['partido' => 'Jugador del partido', 'gol' => 'Gol (o jugada) de la fecha', 'libre' => 'Votación libre (usted escribe las opciones)'];
}

/** ¿Está abierta para votar? */
function votacion_abierta(array $v): bool
{
    if ($v['estado'] !== 'abierta') return false;
    return $v['cierra'] === '' || strtotime($v['cierra']) > time();
}

/** Crea una votación y sus opciones. Devuelve el id. */
function crear_votacion(array $liga, array $d): int
{
    $tipo = array_key_exists($d['tipo'] ?? '', tipos_votacion()) ? $d['tipo'] : 'partido';
    $opciones = [];
    $partido_id = null;
    $torneo_id = !empty($d['torneo_id']) ? (int)$d['torneo_id'] : null;
    $jornada = (int)($d['jornada'] ?? 0);
    $titulo = trim((string)($d['titulo'] ?? ''));
    if ($tipo === 'partido') {
        $p = partido_completo((int)($d['partido_id'] ?? 0));
        if (!$p || (int)$p['liga_id'] !== (int)$liga['id']) throw new RuntimeException('Elija el partido.');
        $partido_id = (int)$p['id'];
        $torneo_id = (int)$p['torneo_id'];
        $jugadores = filas('SELECT j.*, e.nombre AS equipo FROM alineaciones a JOIN jugadores j ON j.id = a.jugador_id JOIN equipos e ON e.id = j.equipo_id WHERE a.partido_id = ? AND j.anonimizado = 0 ORDER BY e.nombre, j.apellidos', [$partido_id]);
        if (!$jugadores) {
            $jugadores = filas('SELECT j.*, e.nombre AS equipo FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.equipo_id IN (?,?) AND j.activo = 1 AND j.anonimizado = 0 ORDER BY e.nombre, j.apellidos', [$p['local_id'], $p['visitante_id']]);
        }
        foreach ($jugadores as $j) $opciones[] = ['jugador_id' => (int)$j['id'], 'evento_id' => null, 'texto' => nombre_jugador($j), 'detalle' => $j['equipo']];
        if ($titulo === '') $titulo = 'Jugador del partido: ' . $p['local_nombre'] . ' vs ' . $p['visitante_nombre'];
    } elseif ($tipo === 'gol') {
        $t = fila('SELECT * FROM torneos WHERE id = ? AND liga_id = ?', [(int)$torneo_id, $liga['id']]) ?? throw new RuntimeException('Elija el campeonato.');
        if (es_individual($t)) throw new RuntimeException('Los campeonatos de deportes individuales no tienen goles para votar.');
        $tipos = deporte_de($t) === 'basquet' ? "'p3'" : "'gol','penal'";
        $ev = filas("SELECT ev.*, j.nombres, j.apellidos, e.nombre AS equipo, el.nombre AS local, evv.nombre AS visitante, p.local_id
                     FROM eventos ev JOIN partidos p ON p.id = ev.partido_id JOIN jugadores j ON j.id = ev.jugador_id JOIN equipos e ON e.id = ev.equipo_id
                     JOIN equipos el ON el.id = p.local_id JOIN equipos evv ON evv.id = p.visitante_id
                     WHERE p.torneo_id = ? AND p.jornada = ? AND p.fase = 'regular' AND ev.tipo IN ($tipos) AND j.anonimizado = 0 ORDER BY p.id, ev.minuto, ev.id", [$t['id'], $jornada]);
        foreach ($ev as $x) {
            $rival = (int)$x['equipo_id'] === (int)$x['local_id'] ? $x['visitante'] : $x['local'];
            $opciones[] = ['jugador_id' => (int)$x['jugador_id'], 'evento_id' => (int)$x['id'], 'texto' => nombre_jugador($x),
                'detalle' => $x['equipo'] . ' vs ' . $rival . ($x['minuto'] !== null ? ' · ' . (int)$x['minuto'] . "'" : '') . ($x['nota'] !== '' ? ' · ' . $x['nota'] : '')];
        }
        if (!$opciones) throw new RuntimeException('En esa jornada todavía no hay ' . (deporte_de($t) === 'basquet' ? 'triples' : 'goles') . ' registrados.');
        if ($titulo === '') $titulo = (deporte_de($t) === 'basquet' ? 'Triple' : 'Gol') . ' de la fecha · Jornada ' . $jornada;
    } else {
        foreach (preg_split('/\r?\n/', (string)($d['opciones'] ?? '')) as $linea) {
            $linea = trim($linea);
            if ($linea !== '') $opciones[] = ['jugador_id' => null, 'evento_id' => null, 'texto' => mb_substr($linea, 0, 100), 'detalle' => ''];
        }
        if ($titulo === '') throw new RuntimeException('Escriba la pregunta de la votación.');
    }
    if (count($opciones) < 2) throw new RuntimeException('Una votación necesita al menos 2 opciones.');
    $cierra = '';
    if (!empty($d['cierra']) && strtotime((string)$d['cierra'])) $cierra = date('Y-m-d H:i', strtotime((string)$d['cierra']));
    $pat = !empty($d['patrocinador_id']) && fila('SELECT id FROM patrocinadores WHERE id = ? AND liga_id = ?', [(int)$d['patrocinador_id'], $liga['id']]) ? (int)$d['patrocinador_id'] : null;
    q('INSERT INTO votaciones (liga_id, torneo_id, tipo, partido_id, jornada, titulo, premio, patrocinador_id, cierra) VALUES (?,?,?,?,?,?,?,?,?)', [
        $liga['id'], $torneo_id, $tipo, $partido_id, $jornada, mb_substr($titulo, 0, 150), mb_substr((string)($d['premio'] ?? ''), 0, 200), $pat, $cierra,
    ]);
    $vid = (int)db()->lastInsertId();
    foreach (array_slice($opciones, 0, 60) as $i => $o) {
        q('INSERT INTO votacion_opciones (votacion_id, jugador_id, evento_id, texto, detalle, orden) VALUES (?,?,?,?,?,?)', [$vid, $o['jugador_id'], $o['evento_id'], $o['texto'], mb_substr($o['detalle'], 0, 150), $i]);
    }
    return $vid;
}

/** Resultados: opciones con votos y porcentaje, ordenadas de más a menos votos. */
function resultados_votacion(int $vid): array
{
    $ops = filas('SELECT o.*, j.foto, j.foto_publica, j.nombres, j.apellidos, (SELECT COUNT(*) FROM votos v WHERE v.opcion_id = o.id) AS votos
                  FROM votacion_opciones o LEFT JOIN jugadores j ON j.id = o.jugador_id WHERE o.votacion_id = ? ORDER BY votos DESC, o.orden', [$vid]);
    $total = array_sum(array_map(fn($o) => (int)$o['votos'], $ops));
    foreach ($ops as &$o) $o['pct'] = $total ? round(100 * (int)$o['votos'] / $total) : 0;
    unset($o);
    return ['opciones' => $ops, 'total' => $total];
}

/** Identificador anónimo del votante (galleta del navegador de 1 año). */
function huella_votante(): string
{
    $c = $_COOKIE['ligas_votante'] ?? '';
    if (!is_string($c) || !preg_match('/^[a-f0-9]{32}$/', $c)) {
        $c = bin2hex(random_bytes(16));
        if (!headers_sent()) setcookie('ligas_votante', $c, ['expires' => time() + 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['ligas_votante'] = $c;
    }
    return hash('sha256', $c);
}

/** ¿Ya votó este navegador? Devuelve la opción votada o null. */
function voto_de(int $vid): ?int
{
    $c = $_COOKIE['ligas_votante'] ?? '';
    if (!is_string($c) || !preg_match('/^[a-f0-9]{32}$/', $c)) return null;
    $r = valor('SELECT opcion_id FROM votos WHERE votacion_id = ? AND huella = ?', [$vid, hash('sha256', $c)]);
    return $r !== false ? (int)$r : null;
}

/** Registra un voto. Un voto por navegador y máximo 5 por conexión (así una familia con el mismo wifi puede votar). */
function votar(array $v, int $opcion_id): void
{
    if (!votacion_abierta($v)) throw new RuntimeException('Esta votación ya está cerrada.');
    if (!fila('SELECT id FROM votacion_opciones WHERE id = ? AND votacion_id = ?', [$opcion_id, $v['id']])) throw new RuntimeException('Elija una opción.');
    $h = huella_votante();
    if (fila('SELECT id FROM votos WHERE votacion_id = ? AND huella = ?', [$v['id'], $h])) throw new RuntimeException('Usted ya votó en esta votación. ¡Gracias!');
    if ((int)valor('SELECT COUNT(*) FROM votos WHERE votacion_id = ? AND ip = ?', [$v['id'], ip_cliente()]) >= 5) {
        throw new RuntimeException('Desde esta conexión ya se votó varias veces. Para evitar trampas, no se aceptan más votos desde aquí.');
    }
    q('INSERT INTO votos (votacion_id, opcion_id, huella, ip) VALUES (?,?,?,?)', [$v['id'], $opcion_id, $h, ip_cliente()]);
}

/* ============================================================
 * 3) POLLA DE PRONÓSTICOS (gratis, sin dinero)
 * ============================================================ */

/** Teléfono solo con números (09xxxxxxxx). */
function telefono_limpio(string $t): string
{
    $t = preg_replace('/\D+/', '', $t);
    if (str_starts_with($t, '593') && strlen($t) === 12) $t = '0' . substr($t, 3);
    return $t;
}

/** Participante conectado en esta liga (por la galleta del navegador) o null. */
function polla_participante(array $liga): ?array
{
    $tk = $_COOKIE['polla_' . (int)$liga['id']] ?? '';
    if (!is_string($tk) || !preg_match('/^[a-f0-9]{32}$/', $tk)) return null;
    return fila('SELECT * FROM polla_participantes WHERE liga_id = ? AND token = ? AND activo = 1', [$liga['id'], hash('sha256', $tk)]);
}

function polla_iniciar_sesion(array $liga, int $participante_id): void
{
    $tk = bin2hex(random_bytes(16));
    q('UPDATE polla_participantes SET token = ? WHERE id = ?', [hash('sha256', $tk), $participante_id]);
    setcookie('polla_' . (int)$liga['id'], $tk, ['expires' => time() + 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}

function polla_registrar(array $liga, string $nombre, string $telefono, string $pin): int
{
    $nombre = trim($nombre);
    $tel = telefono_limpio($telefono);
    if (mb_strlen($nombre) < 3) throw new RuntimeException('Escriba su nombre (o un apodo).');
    if (strlen($tel) < 9 || strlen($tel) > 13) throw new RuntimeException('Escriba su número de celular.');
    if (!preg_match('/^\d{4}$/', $pin)) throw new RuntimeException('La clave debe ser de 4 números.');
    if (fila('SELECT id FROM polla_participantes WHERE liga_id = ? AND telefono = ?', [$liga['id'], $tel])) throw new RuntimeException('Ese celular ya está registrado en la polla. Use "Ya estoy registrado" con su clave de 4 números.');
    if (demasiados_intentos('polla_reg', 5, '-1 hour')) throw new RuntimeException('Demasiados registros seguidos desde esta conexión. Intente más tarde.');
    anotar_intento('polla_reg');
    q('INSERT INTO polla_participantes (liga_id, nombre, telefono, pin, ip) VALUES (?,?,?,?,?)', [$liga['id'], mb_substr($nombre, 0, 40), $tel, password_hash($pin, PASSWORD_DEFAULT), ip_cliente()]);
    return (int)db()->lastInsertId();
}

function polla_ingresar(array $liga, string $telefono, string $pin): int
{
    if (demasiados_intentos('polla_login', 10, '-15 minutes')) throw new RuntimeException('Demasiados intentos. Espere 15 minutos.');
    $p = fila('SELECT * FROM polla_participantes WHERE liga_id = ? AND telefono = ? AND activo = 1', [$liga['id'], telefono_limpio($telefono)]);
    if (!$p || !password_verify($pin, $p['pin'])) {
        anotar_intento('polla_login');
        throw new RuntimeException('Celular o clave incorrectos.');
    }
    return (int)$p['id'];
}

/** ¿Todavía se puede pronosticar este partido? (hasta que empiece) */
function polla_abierto(array $p): bool
{
    if ($p['estado'] !== 'programado') return false;
    if ($p['fecha'] === '') return true;
    if ($p['hora'] !== '') return strtotime($p['fecha'] . ' ' . $p['hora']) > time();
    return $p['fecha'] >= date('Y-m-d');
}

/** Partidos para pronosticar (próximos 21 días o sin fecha) de los campeonatos en curso de la liga. */
function polla_partidos(array $liga): array
{
    return filas("SELECT p.*, el.nombre AS local_nombre, el.escudo AS local_escudo, el.color AS local_color,
                         ev.nombre AS visitante_nombre, ev.escudo AS visitante_escudo, ev.color AS visitante_color, t.nombre AS torneo
                  FROM partidos p JOIN torneos t ON t.id = p.torneo_id JOIN equipos el ON el.id = p.local_id JOIN equipos ev ON ev.id = p.visitante_id
                  WHERE t.liga_id = ? AND t.estado <> 'finalizado' AND p.estado = 'programado' AND (p.fecha = '' OR p.fecha BETWEEN ? AND ?)
                  ORDER BY (p.fecha = ''), p.fecha, p.hora, p.id LIMIT 40", [$liga['id'], date('Y-m-d'), date('Y-m-d', strtotime('+21 days'))]);
}

/** Puntos de un pronóstico: exacto o solo el ganador/empate. */
function polla_puntos(array $liga, int $pl, int $pv, int $gl, int $gv): int
{
    if ($pl === $gl && $pv === $gv) return (int)$liga['polla_exacto'];
    return ($pl <=> $pv) === ($gl <=> $gv) ? (int)$liga['polla_resultado'] : 0;
}

/** Tabla de la polla: [['id','nombre','puntos','exactos','aciertos','jugados'], ...] */
function polla_ranking(array $liga, int $limite = 100): array
{
    $rows = filas("SELECT pp.id, pp.nombre, pr.goles_local AS pl, pr.goles_visitante AS pv, p.goles_local AS gl, p.goles_visitante AS gv
                   FROM polla_participantes pp
                   JOIN polla_pronosticos pr ON pr.participante_id = pp.id
                   JOIN partidos p ON p.id = pr.partido_id
                   WHERE pp.liga_id = ? AND pp.activo = 1 AND p.estado IN ('jugado','wo') AND p.goles_local IS NOT NULL", [$liga['id']]);
    $t = [];
    foreach (filas('SELECT id, nombre FROM polla_participantes WHERE liga_id = ? AND activo = 1', [$liga['id']]) as $pp) {
        $t[(int)$pp['id']] = ['id' => (int)$pp['id'], 'nombre' => $pp['nombre'], 'puntos' => 0, 'exactos' => 0, 'aciertos' => 0, 'jugados' => 0];
    }
    foreach ($rows as $r) {
        $pts = polla_puntos($liga, (int)$r['pl'], (int)$r['pv'], (int)$r['gl'], (int)$r['gv']);
        $x = &$t[(int)$r['id']];
        $x['jugados']++;
        $x['puntos'] += $pts;
        if ((int)$r['pl'] === (int)$r['gl'] && (int)$r['pv'] === (int)$r['gv']) $x['exactos']++;
        elseif ($pts > 0) $x['aciertos']++;
        unset($x);
    }
    $t = array_values(array_filter($t, fn($x) => $x['jugados'] > 0));
    usort($t, fn($a, $b) => [$b['puntos'], $b['exactos'], $a['jugados'], $a['nombre']] <=> [$a['puntos'], $a['exactos'], $b['jugados'], $b['nombre']]);
    return array_slice($t, 0, $limite);
}
