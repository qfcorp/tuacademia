<?php
/*
 * (v8) WIDGET PARA INCRUSTAR en la página web o el Facebook de la liga (se actualiza solo).
 *   widget.php?l=slug&w=tabla|resultados|proximos|goleadores|vivo[&t=ID][&tema=oscuro][&n=5]
 * Se pone con un <iframe>. El código listo se copia en el panel: Redes y público → Para su web.
 */
require __DIR__ . '/../app/bootstrap.php';
define('VISTA_PUBLICA', true);

$liga = fila('SELECT * FROM ligas WHERE slug = ? AND activa = 1', [get('l')]);
if (!$liga) { http_response_code(404); exit('Liga no encontrada'); }
exigir_modulo_corto($liga, 'publico'); // (Anexo B) el widget es de «Patrocinadores y público»
$lid = (int)$liga['id'];
$w = in_array(get('w'), ['tabla', 'resultados', 'proximos', 'goleadores', 'vivo'], true) ? get('w') : 'tabla';
$t = get('t') !== '' ? fila('SELECT * FROM torneos WHERE id = ? AND liga_id = ?', [(int)get('t'), $lid]) : null;
if ($t && es_individual($t)) $t = null; // (v23) los widgets son de deportes de equipo
$t = $t ?? fila("SELECT * FROM torneos WHERE liga_id = ? AND " . sql_sin_carreras() . " ORDER BY CASE estado WHEN 'en_juego' THEN 0 WHEN 'sorteado' THEN 1 WHEN 'inscripcion' THEN 2 ELSE 3 END, id DESC LIMIT 1", [$lid]);
$n = entero(get('n', '6'), 1, 30);
$oscuro = get('tema') === 'oscuro';
$base = url_liga($liga);
$enlace = url_base() . 'index.php?l=' . rawurlencode($liga['slug']);
$T = textos_deporte($t ?? $liga);
$refresco = $w === 'vivo' ? 20 : 120;
header('Cache-Control: public, max-age=30');
$color = color_valido($liga['color']);

$fila_partido = function (array $p) use ($enlace): string {
    $cerr = partido_cerrado($p) || $p['estado'] === 'en_vivo';
    $centro = $cerr ? (int)$p['goles_local'] . ' - ' . (int)$p['goles_visitante'] : ($p['hora'] !== '' ? e($p['hora']) : 'vs');
    return '<a class="w-part" target="_blank" href="' . e($enlace . '&p=' . (int)$p['id']) . '"><span class="w-eq">' . e($p['local_nombre']) . '</span><b class="' . ($p['estado'] === 'en_vivo' ? 'w-vivo' : '') . '">' . $centro . '</b><span class="w-eq der">' . e($p['visitante_nombre']) . '</span>'
        . '<small>' . ($p['estado'] === 'en_vivo' ? '● EN VIVO' : e(fecha_bonita($p['fecha']) . ($p['cancha'] !== '' ? ' · ' . $p['cancha'] : ''))) . '</small></a>';
};
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($liga['nombre']) ?></title><meta http-equiv="refresh" content="<?= $refresco ?>">
<style>
  :root { --c: <?= e($color) ?>; --fondo: <?= $oscuro ? '#0f1614' : '#ffffff' ?>; --texto: <?= $oscuro ? '#f2f5f4' : '#15201c' ?>; --suave: <?= $oscuro ? '#9fb0aa' : '#6b7572' ?>; --linea: <?= $oscuro ? '#23302c' : '#e8eeeb' ?>; }
  * { box-sizing: border-box; } body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: var(--fondo); color: var(--texto); font-size: 14px; }
  .w-cab { background: var(--c); color: #fff; padding: 8px 12px; display: flex; align-items: center; gap: 8px; font-weight: 700; }
  .w-cab img { width: 26px; height: 26px; border-radius: 50%; background: #fff; object-fit: cover; }
  .w-cab small { font-weight: 400; opacity: .9; margin-left: auto; }
  table { width: 100%; border-collapse: collapse; } th, td { padding: 6px 8px; border-bottom: 1px solid var(--linea); text-align: left; } th { color: var(--suave); font-size: 11px; text-transform: uppercase; }
  td.n, th.n { text-align: center; width: 34px; } td.pts { font-weight: 800; color: var(--c); }
  .w-part { display: grid; grid-template-columns: 1fr auto 1fr; gap: 6px; align-items: center; padding: 8px 10px; border-bottom: 1px solid var(--linea); color: inherit; text-decoration: none; }
  .w-part b { background: var(--linea); border-radius: 6px; padding: 2px 8px; min-width: 52px; text-align: center; } .w-part b.w-vivo { background: #d7263d; color: #fff; }
  .w-part .der { text-align: right; } .w-part small { grid-column: 1 / -1; text-align: center; color: var(--suave); font-size: 11px; }
  .w-eq { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
  .w-pie { padding: 6px 10px; font-size: 11px; color: var(--suave); display: flex; justify-content: space-between; } .w-pie a { color: var(--c); text-decoration: none; font-weight: 700; }
  .w-vacio { padding: 16px; color: var(--suave); text-align: center; }
</style></head><body>
<div class="w-cab"><?php if ($liga['logo']): ?><img src="<?= e(url_base() . $liga['logo']) ?>" alt=""><?php endif; ?><?= e($liga['nombre']) ?><small><?= e(['tabla' => 'Posiciones', 'resultados' => 'Resultados', 'proximos' => 'Próximos partidos', 'goleadores' => $T['goleadores'] ?: 'Goleadores', 'vivo' => 'En vivo'][$w]) ?></small></div>
<?php
if (!$t) {
    echo '<div class="w-vacio">Próximamente</div>';
} elseif ($w === 'tabla') {
    foreach (tabla_posiciones((int)$t['id']) as $g => $filas_t) {
        if ($g !== '') echo '<div style="padding:6px 10px;font-weight:700">Grupo ' . e($g) . '</div>';
        echo '<table><tr><th class="n">#</th><th>Equipo</th><th class="n">PJ</th><th class="n">' . e($T['dg']) . '</th><th class="n">Pts</th></tr>';
        foreach (array_slice($filas_t, 0, max($n, 20)) as $i => $f) echo '<tr><td class="n">' . ($i + 1) . '</td><td>' . e($f['nombre']) . '</td><td class="n">' . $f['pj'] . '</td><td class="n">' . ($f['dg'] > 0 ? '+' : '') . $f['dg'] . '</td><td class="n pts">' . $f['pts'] . '</td></tr>';
        echo '</table>';
    }
} elseif ($w === 'goleadores') {
    $gol = goleadores((int)$t['id'], $n);
    if (!$gol) echo '<div class="w-vacio">Aún no hay datos.</div>';
    else {
        echo '<table><tr><th class="n">#</th><th>Jugador</th><th>Equipo</th><th class="n">' . e($T['goles']) . '</th></tr>';
        foreach ($gol as $i => $g) echo '<tr><td class="n">' . ($i + 1) . '</td><td>' . e(nombre_jugador($g)) . '</td><td>' . e($g['equipo']) . '</td><td class="n pts">' . (int)$g['goles'] . '</td></tr>';
        echo '</table>';
    }
} else {
    $filtro = match ($w) {
        'resultados' => "AND p.estado IN ('jugado','wo')",
        'proximos' => "AND p.estado IN ('programado','postergado')",
        default => "AND p.estado = 'en_vivo'",
    };
    $ps = $w === 'vivo'
        ? filas("SELECT p.*, el.nombre AS local_nombre, ev.nombre AS visitante_nombre FROM partidos p JOIN torneos t ON t.id = p.torneo_id JOIN equipos el ON el.id = p.local_id JOIN equipos ev ON ev.id = p.visitante_id WHERE t.liga_id = ? AND p.estado = 'en_vivo' ORDER BY p.hora", [$lid])
        : partidos_torneo((int)$t['id'], $filtro);
    if ($w === 'resultados') $ps = array_reverse($ps);
    $ps = array_slice($ps, 0, $n);
    if (!$ps) echo '<div class="w-vacio">' . ($w === 'vivo' ? 'No hay partidos en vivo ahora.' : 'No hay partidos.') . '</div>';
    foreach ($ps as $p) echo $fila_partido($p);
}
?>
<div class="w-pie"><span><?= $t ? e($t['nombre']) : '' ?></span><a target="_blank" href="<?= e($base) ?>">Ver todo ›</a></div>
</body></html>
