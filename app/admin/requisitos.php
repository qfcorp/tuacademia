<?php
/* (v35) REQUISITOS Y DOCUMENTOS: quién debe datos o documentos obligatorios del módulo deportivo de su campeonato o grupo */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$solo = get('ver') === 'todos' ? 'todos' : 'faltan';
$personas = []; // [modulo, tabla, id, nombre, donde, enlace]
$mod_eq = [];
if (!es_escuela($liga)) {
    foreach (filas('SELECT j.id, j.nombres, j.apellidos, j.equipo_id, e.nombre AS equipo FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ? AND j.activo = 1 AND j.anonimizado = 0 ORDER BY e.nombre, j.apellidos', [$lid]) as $j) {
        $mod_eq[$j['equipo_id']] ??= modulo_de_equipo((int)$j['equipo_id'], $liga);
        $personas[] = [$mod_eq[$j['equipo_id']], 'jugadores', (int)$j['id'], trim($j['nombres'] . ' ' . $j['apellidos']), $j['equipo'], 'admin.php?p=jugador&id=' . (int)$j['id']];
    }
    $tabs = ['carrera_participantes' => 'carrera', 'duelo_participantes' => 'duelo', 'prueba_participantes' => 'pruebas'];
    foreach ($tabs as $tabla => $pag) {
        foreach (filas("SELECT p.id, p.nombre, t.id AS tid, t.nombre AS torneo, t.modalidad FROM $tabla p JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ? AND p.activo = 1 AND t.estado <> 'finalizado' ORDER BY t.nombre, p.nombre", [$lid]) as $p) {
            $pg = $tabla === 'carrera_participantes' && $p['modalidad'] === 'BOLOS' ? 'bolos' : $pag;
            $personas[] = [modulo_de_torneo($p), $tabla, (int)$p['id'], $p['nombre'], $p['torneo'], 'admin.php?p=' . $pg . '&id=' . (int)$p['tid'] . '&s=participantes'];
        }
    }
} else {
    foreach (filas("SELECT a.id, a.nombres, a.apellidos, g.nombre AS grupo, g.deporte, g.liga_id FROM esc_alumnos a LEFT JOIN esc_grupos g ON g.id = a.grupo_id WHERE a.liga_id = ? AND a.estado = 'activo' ORDER BY g.nombre, a.apellidos", [$lid]) as $a) {
        $personas[] = [modulo_de_deporte_escuela(deporte_grupo($a['grupo'] !== null ? ['deporte' => $a['deporte'], 'liga_id' => $lid] : null, $liga)), 'esc_alumnos', (int)$a['id'], trim($a['nombres'] . ' ' . $a['apellidos']), $a['grupo'] ?? 'Sin grupo', 'admin.php?p=alumno&id=' . (int)$a['id']];
    }
}
$por_mod = [];
$total_faltan = 0;
foreach ($personas as [$mod, $tabla, $id, $nombre, $donde, $enlace]) {
    $req = requisitos_modulo($mod);
    if (!$req['campos'] && !$req['docs']) continue;
    $f = requisitos_faltan($tabla, $id, $mod);
    if ($f) $total_faltan++;
    if ($solo === 'faltan' && !$f) continue;
    $por_mod[$mod][] = ['nombre' => $nombre, 'donde' => $donde, 'enlace' => $enlace, 'faltan' => $f];
}
cabecera_admin('Requisitos y documentos');
?>
<h1>📋 Requisitos y documentos</h1>
<p class="suave" style="margin-top:0">Cada deporte pide sus propios datos y documentos (los define <?= e(ajuste('empresa', 'QF Producciones')) ?> para cada módulo). Aquí ve quién todavía debe algo. Toque el nombre para completar su ficha.</p>
<p><a class="btn btn-chico <?= $solo === 'faltan' ? '' : 'btn-sec' ?>" href="admin.php?p=requisitos">⚠ Solo los que deben (<?= $total_faltan ?>)</a> <a class="btn btn-chico <?= $solo === 'todos' ? '' : 'btn-sec' ?>" href="admin.php?p=requisitos&amp;ver=todos">Todos</a></p>
<?php foreach (deportes_liga($liga) as $mod): if ($mod === 'olimpiadas') continue; /* (v37) cada evento tiene los suyos (Olimpiadas → Deportistas) */ $req = requisitos_modulo($mod); if (!$req['campos'] && !$req['docs']) continue; $l = $por_mod[$mod] ?? []; ?>
  <div class="tarjeta" style="margin-bottom:14px">
    <h2 style="margin-top:0"><?= e(nombre_modulo_deporte($mod)) ?> <small class="suave"><?= count($l) ?></small></h2>
    <p class="suave" style="margin-top:0">Obligatorio: <?= e(implode(', ', array_merge(array_map(fn($c) => requisitos_campos_catalogo()[$c][0], array_keys(array_filter($req['campos'], fn($v) => $v === 2))), array_column(array_filter($req['docs'], fn($d) => $d['obligatorio']), 'nombre'))) ?: 'nada (solo se piden)') ?></p>
    <?php if (!$l): ?><p>✅ <?= $solo === 'faltan' ? 'Nadie debe nada.' : 'Sin personas todavía.' ?></p><?php else: ?>
    <div class="tabla-scroll"><table><tr><th>Nombre</th><th><?= es_escuela($liga) ? 'Grupo' : 'Equipo / campeonato' ?></th><th>Le falta</th></tr>
      <?php foreach (array_slice($l, 0, 500) as $x): ?><tr><td><a href="<?= e($x['enlace']) ?>"><?= e($x['nombre']) ?></a></td><td><?= e($x['donde']) ?></td><td style="white-space:normal"><?= $x['faltan'] ? '<span class="etiqueta roja">⚠</span> ' . e(implode(', ', $x['faltan'])) : '✅ completo' ?></td></tr><?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>
<?php endforeach;
pie_admin();
