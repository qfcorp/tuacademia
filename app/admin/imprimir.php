<?php
/* Documentos imprimibles desde el panel: vocalía llena o informe del árbitro */
$liga = requiere_liga();
// (v8) Informe de fin de temporada de un campeonato
if (get('doc') === 'temporada') {
    $t = torneo_de_liga((int)get('id'), (int)$liga['id']);
    doc_temporada($t, $liga, 'admin.php?p=torneo&id=' . (int)$t['id'] . '&s=tabla');
    return;
}
// (v29) Fixture completo y esquema del campeonato: exclusivos del plan Profesional
if (get('doc') === 'fixture' || get('doc') === 'esquema') {
    $t = torneo_de_liga((int)get('id'), (int)$liga['id']);
    $volver = 'admin.php?p=torneo&id=' . (int)$t['id'] . '&s=fixture#esquema';
    if (($motivo = fixture_pro_motivo($liga)) !== '') {
        pantalla_bloqueo_fixture_pro($liga, $motivo, $volver);
        return;
    }
    if (es_individual($t)) throw new RuntimeException('El fixture y el esquema son para campeonatos de equipos.');
    if (get('doc') === 'esquema') doc_esquema($t, $liga, $volver);
    else doc_fixture($t, $liga, $volver, 'admin.php?p=imprimir&doc=fixture&id=' . (int)$t['id'] . (get('blanco') === '1' ? '&blanco=1' : ''), get('blanco') !== '1');
    return;
}
// (v10) Boletín oficial de la fecha
if (get('doc') === 'boletin') {
    $t = torneo_de_liga((int)get('id'), (int)$liga['id']);
    doc_boletin($t, $liga, get('f'), 'admin.php?p=torneo&id=' . (int)$t['id'] . '&s=tabla');
    return;
}
// (v15) Hojas de vocalía en el formato de las ligas: una por partido o todas las de una fecha
if (get('doc') === 'hojas') {
    $t = torneo_de_liga((int)get('t'), (int)$liga['id']);
    $pids = array_column(filas('SELECT id FROM partidos WHERE torneo_id = ? AND jornada = ? ORDER BY fecha, hora, id', [$t['id'], (int)get('j')]), 'id');
    if (!$pids) throw new RuntimeException('Esa fecha no tiene partidos.');
    if (get('vacia') !== '1') exigir_modulo_panel($liga, 'vocalia'); // (Anexo B) la hoja llena es de la vocalía en vivo; la vacía es base
    doc_hojas_vocalia($pids, $liga, 'admin.php?p=torneo&id=' . (int)$t['id'] . '&s=fixture', get('vacia') !== '1');
    return;
}
if (get('doc') === 'hoja') {
    $ph = partido_de_liga((int)get('id'), (int)$liga['id']);
    if (get('vacia') !== '1') exigir_modulo_panel($liga, 'vocalia'); // (Anexo B)
    doc_hojas_vocalia([(int)$ph['id']], $liga, 'admin.php?p=partido&id=' . (int)$ph['id'], get('vacia') !== '1');
    return;
}
$p0 = partido_de_liga((int)get('id'), (int)$liga['id']);
$p = partido_completo((int)$p0['id']);
$volver = 'admin.php?p=partido&id=' . (int)$p['id'];
exigir_modulo_panel($liga, get('doc') === 'informe' ? 'arbitros' : 'vocalia'); // (Anexo B) informe del árbitro / vocalía llena
if (get('doc') === 'informe') {
    doc_informe($p, $liga, $volver . '&s=arbitro');
} else {
    doc_vocalia($p, $liga, $volver . '&s=vocalia');
}
