<?php
/*
 * (v8) CALENDARIO PARA EL CELULAR (.ics)
 *   calendario.php?l=slug&e=ID_EQUIPO   → partidos de un equipo
 *   calendario.php?l=slug&t=ID_TORNEO   → todos los partidos de un campeonato
 * Con webcal:// el celular se suscribe y el calendario se actualiza solo cuando cambia la programación.
 */
require __DIR__ . '/../app/bootstrap.php';

$liga = fila('SELECT * FROM ligas WHERE slug = ? AND activa = 1', [get('l')]);
if (!$liga) { http_response_code(404); exit('Liga no encontrada'); }
$nombre = $liga['nombre'];
if (get('e') !== '') {
    $eq = fila('SELECT * FROM equipos WHERE id = ? AND liga_id = ?', [(int)get('e'), $liga['id']]) ?? exit('Equipo no encontrado');
    $eid = (int)$eq['id'];
    $ps = filas("SELECT p.*, el.nombre AS local_nombre, ev.nombre AS visitante_nombre, t.nombre AS torneo FROM partidos p JOIN torneos t ON t.id = p.torneo_id
                 JOIN equipos el ON el.id = p.local_id JOIN equipos ev ON ev.id = p.visitante_id
                 WHERE t.liga_id = ? AND (p.local_id = ? OR p.visitante_id = ?) AND p.fecha <> '' ORDER BY p.fecha, p.hora", [$liga['id'], $eid, $eid]);
    $nombre = $eq['nombre'] . ' · ' . $liga['nombre'];
} else {
    $t = fila('SELECT * FROM torneos WHERE id = ? AND liga_id = ?', [(int)get('t'), $liga['id']]) ?? exit('Campeonato no encontrado');
    $ps = filas("SELECT p.*, el.nombre AS local_nombre, ev.nombre AS visitante_nombre, ? AS torneo FROM partidos p
                 JOIN equipos el ON el.id = p.local_id JOIN equipos ev ON ev.id = p.visitante_id
                 WHERE p.torneo_id = ? AND p.fecha <> '' ORDER BY p.fecha, p.hora", [$t['nombre'], $t['id']]);
    $nombre = $t['nombre'] . ' · ' . $liga['nombre'];
}
$host = preg_replace('/[^a-z0-9.-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'ligas'));
$lineas = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//QF Producciones//Ligas//ES', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
    'X-WR-CALNAME:' . ics_texto($nombre), 'X-WR-TIMEZONE:America/Guayaquil', 'REFRESH-INTERVAL;VALUE=DURATION:PT6H', 'X-PUBLISHED-TTL:PT6H',
    'BEGIN:VTIMEZONE', 'TZID:America/Guayaquil', 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:-0500', 'TZOFFSETTO:-0500', 'TZNAME:ECT', 'END:STANDARD', 'END:VTIMEZONE'];
foreach ($ps as $p) {
    $r = reglas_torneo((int)$p['torneo_id']);
    $titulo = $p['local_nombre'] . ' vs ' . $p['visitante_nombre'];
    if (partido_cerrado($p)) $titulo = $p['local_nombre'] . ' ' . (int)$p['goles_local'] . '-' . (int)$p['goles_visitante'] . ' ' . $p['visitante_nombre'];
    if ($p['estado'] === 'suspendido' || $p['estado'] === 'postergado') $titulo = '(' . nombre_estado_partido($p['estado']) . ') ' . $titulo;
    $lineas[] = 'BEGIN:VEVENT';
    $lineas[] = 'UID:partido-' . (int)$p['id'] . '@' . $host;
    $lineas[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
    if ($p['hora'] !== '') {
        $ini = strtotime($p['fecha'] . ' ' . $p['hora']);
        $lineas[] = 'DTSTART;TZID=America/Guayaquil:' . date('Ymd\THis', $ini);
        $lineas[] = 'DTEND;TZID=America/Guayaquil:' . date('Ymd\THis', $ini + duracion_sugerida($r) * 60);
    } else {
        $lineas[] = 'DTSTART;VALUE=DATE:' . date('Ymd', strtotime($p['fecha']));
        $lineas[] = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($p['fecha'] . ' +1 day'));
    }
    $lineas[] = 'SUMMARY:' . ics_texto(icono_deporte(deporte_de($r)) . ' ' . $titulo);
    if ($p['cancha'] !== '') $lineas[] = 'LOCATION:' . ics_texto($p['cancha']);
    $lineas[] = 'DESCRIPTION:' . ics_texto($p['torneo'] . ' · ' . ($p['fase'] === 'regular' ? 'Jornada ' . (int)$p['jornada'] : nombre_fase($p['fase'])) . "\n" . url_liga($liga));
    $lineas[] = 'URL:' . url_base() . 'index.php?l=' . rawurlencode($liga['slug']) . '&p=' . (int)$p['id'];
    if (!partido_cerrado($p) && $p['hora'] !== '') {
        $lineas[] = 'BEGIN:VALARM';
        $lineas[] = 'TRIGGER:-PT2H';
        $lineas[] = 'ACTION:DISPLAY';
        $lineas[] = 'DESCRIPTION:' . ics_texto('Partido en 2 horas: ' . $titulo);
        $lineas[] = 'END:VALARM';
    }
    $lineas[] = 'END:VEVENT';
}
$lineas[] = 'END:VCALENDAR';
// Las líneas largas se doblan a 75 caracteres (regla del formato iCalendar)
$salida = '';
foreach ($lineas as $l) {
    while (strlen($l) > 74) {
        $corte = 74;
        while ($corte > 0 && (ord($l[$corte]) & 0xC0) === 0x80) $corte--;
        $salida .= substr($l, 0, $corte) . "\r\n";
        $l = ' ' . substr($l, $corte);
    }
    $salida .= $l . "\r\n";
}
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="' . slugify($nombre) . '.ics"');
header('Cache-Control: public, max-age=900');
echo $salida;
