<?php
/* (v17) Evaluaciones → Jornadas: acciones (crear, guardar la planilla, cerrar, reabrir, borrar). Se incluye desde evaluaciones.php. */
if (!puede('evaluaciones', true)) throw new RuntimeException('Su perfil no puede registrar evaluaciones.');

if ($accion === 'jornada_crear') {
    if (trim(post('nombre')) === '') throw new RuntimeException('Escriba el nombre de la jornada (ej: Evaluación de mitad de año).');
    $ids_t = array_values(array_filter(array_map('intval', (array)($_POST['tests'] ?? [])), fn($id) => (bool)fila('SELECT id FROM esc_tests WHERE id = ? AND liga_id = ?', [$id, $lid])));
    if (!$ids_t && post('medidas') !== '1') throw new RuntimeException('Elija al menos un test o marque las medidas.');
    $ids_g = array_values(array_filter(array_map('intval', (array)($_POST['grupos'] ?? [])), fn($id) => in_array($id, array_map(fn($g) => (int)$g['id'], $grupos), true)));
    if (!$ids_g && perfil_actual() === 'profesor') $ids_g = array_map(fn($g) => (int)$g['id'], $grupos);
    q('INSERT INTO esc_jornadas (liga_id, nombre, fecha, tests, grupos, medidas, notas, registrado_por) VALUES (?,?,?,?,?,?,?,?)',
        [$lid, mb_substr(trim(post('nombre')), 0, 100), $f, implode(',', $ids_t), implode(',', $ids_g), post('medidas') === '1' ? 1 : 0, mb_substr(post('notas'), 0, 500), $por]);
    $jid = (int)db()->lastInsertId();
    flash('Jornada creada. Abra la planilla de cada grupo y registre los resultados desde el celular, en el entrenamiento.');
    ir('admin.php?p=evaluaciones&j=' . $jid);
}

$j = jornada_de((int)post('jornada_id'), $lid);

if ($accion === 'jornada_guardar') {
    if ($j['estado'] === 'cerrada') throw new RuntimeException('La jornada está cerrada. Ábrala otra vez para corregir.');
    $g = grupo_de_escuela((int)post('grupo_id'), $lid);
    $tests_ok = array_map(fn($t) => (int)$t['id'], tests_jornada($j));
    $pdo = db();
    $pdo->beginTransaction();
    $c = 0; $m = 0;
    foreach ((array)($_POST['v'] ?? []) as $aid => $vals) {
        if (!is_array($vals) || !alumno_en_grupo((int)$aid, (int)$g['id'])) continue;
        foreach ($vals as $tid => $val) {
            if (!in_array((int)$tid, $tests_ok, true)) continue;
            if ($n($val) === null) {
                q('DELETE FROM esc_resultados WHERE alumno_id = ? AND test_id = ? AND jornada_id = ?', [(int)$aid, (int)$tid, $j['id']]);
                continue;
            }
            q('INSERT INTO esc_resultados (alumno_id, test_id, fecha, valor, registrado_por, jornada_id) VALUES (?,?,?,?,?,?)
               ON CONFLICT(alumno_id, test_id, fecha) DO UPDATE SET valor = excluded.valor, jornada_id = excluded.jornada_id, registrado_por = excluded.registrado_por',
                [(int)$aid, (int)$tid, $j['fecha'], $n($val), $por, $j['id']]);
            $c++;
        }
    }
    if ((int)$j['medidas']) {
        foreach ((array)($_POST['m'] ?? []) as $aid => $vals) {
            if (!is_array($vals) || !alumno_en_grupo((int)$aid, (int)$g['id'])) continue;
            $peso = $n($vals['peso'] ?? ''); $talla = $n($vals['talla'] ?? ''); $ts = $n($vals['talla_sentado'] ?? '');
            $ya = fila('SELECT id FROM esc_mediciones WHERE alumno_id = ? AND jornada_id = ?', [(int)$aid, $j['id']]);
            if ($peso === null && $talla === null && $ts === null) { if ($ya) q('DELETE FROM esc_mediciones WHERE id = ?', [$ya['id']]); continue; }
            if ($ya) q('UPDATE esc_mediciones SET peso = ?, talla = ?, talla_sentado = ?, fecha = ?, registrado_por = ? WHERE id = ?', [$peso, $talla, $ts, $j['fecha'], $por, $ya['id']]);
            else q('INSERT INTO esc_mediciones (alumno_id, fecha, peso, talla, talla_sentado, jornada_id, registrado_por) VALUES (?,?,?,?,?,?,?)', [(int)$aid, $j['fecha'], $peso, $talla, $ts, $j['id'], $por]);
            $m++;
        }
    }
    $pdo->commit();
    flash('Planilla de ' . $g['nombre'] . ' guardada: ' . $c . ' resultado(s)' . ($m ? ' y medidas de ' . $m . ' alumno(s)' : '') . '.');
    ir('admin.php?p=evaluaciones&j=' . (int)$j['id'] . '&g=' . (int)$g['id'] . '&guardado=1');
}
if ($accion === 'jornada_cerrar') {
    q("UPDATE esc_jornadas SET estado = 'cerrada', cerrada_en = ? WHERE id = ?", [date('Y-m-d H:i:s'), $j['id']]);
    [$ev, $tot] = progreso_jornada($j, grupos_jornada($j));
    aviso_admins($liga, '📋 <b>Jornada de evaluación cerrada:</b> ' . e($j['nombre']) . ' · ' . $ev . ' de ' . $tot . ' alumnos evaluados. Los padres ya ven los resultados en su portal.', 'jornada:' . (int)$j['id']);
    flash('Jornada cerrada. Los padres ya ven los resultados con gráficos. Abajo tiene la lista para avisarles por WhatsApp.');
    ir('admin.php?p=evaluaciones&j=' . (int)$j['id'] . '#avisar');
}
if ($accion === 'jornada_reabrir') {
    q("UPDATE esc_jornadas SET estado = 'abierta' WHERE id = ?", [$j['id']]);
    flash('Jornada abierta otra vez: puede corregir resultados.');
    ir('admin.php?p=evaluaciones&j=' . (int)$j['id']);
}
if ($accion === 'jornada_borrar') {
    if (valor('SELECT COUNT(*) FROM esc_resultados WHERE jornada_id = ?', [$j['id']])) throw new RuntimeException('La jornada ya tiene resultados: no se puede borrar.');
    q('DELETE FROM esc_jornadas WHERE id = ?', [$j['id']]);
    flash('Jornada borrada.');
    ir('admin.php?p=evaluaciones');
}
