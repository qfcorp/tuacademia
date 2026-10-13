<?php
/* (v29) Fixture completo y esquema del campeonato para imprimir, versión pública: ?l=liga&t=ID&v=fixture_pdf|esquema
 * Solo si la liga tiene el plan Profesional (no la demo ni la demo de 7 días). */
$t = get('t') !== '' ? fila('SELECT * FROM torneos WHERE id = ? AND liga_id = ?', [(int)get('t'), $lid]) : null;
if (!$t || es_individual($t) || !fixture_pro_activo($liga, true)) ir($t ? $base . '&t=' . (int)$t['id'] . '&v=fixture' : $base);
$volver = $base . '&t=' . (int)$t['id'] . '&v=fixture';
if (get('v') === 'esquema') {
    doc_esquema($t, $liga, $volver);
} else {
    doc_fixture($t, $liga, $volver, $base . '&t=' . (int)$t['id'] . '&v=fixture_pdf' . (get('blanco') === '1' ? '&blanco=1' : ''), get('blanco') !== '1');
}
