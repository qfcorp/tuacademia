<?php
/* (v10) Boletín oficial de la fecha (versión pública para compartir): /liga/campeonato/boletin?f=j3 */
completar_slugs_torneos($lid);
$t = get('t') !== '' ? fila('SELECT * FROM torneos WHERE id = ? AND liga_id = ?', [(int)get('t'), $lid]) : null;
if ($t && es_individual($t)) $t = null; // (v23) el boletín es de deportes de equipo
$t = $t ?? fila("SELECT * FROM torneos WHERE liga_id = ? AND " . sql_sin_carreras() . " ORDER BY CASE estado WHEN 'en_juego' THEN 0 ELSE 1 END, id DESC LIMIT 1", [$lid]);
if (!$t) ir($base);
doc_boletin($t, $liga, get('f'), url_torneo($liga, $t), true);
