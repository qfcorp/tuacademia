<?php
/*
 * (v23) DEMO DE CARRERAS: agrega a la liga de demostración un campeonato de autocross (por puntos)
 * y uno de ciclismo (por tiempo total), cada uno con 3 fechas, penalizaciones, un DNF y un DSQ con motivo.
 * La liga sigue mostrando primero su campeonato de fútbol.
 */
function demo_carreras(int $lid): void
{
    sin_auditoria(function () use ($lid) {
        foreach ([['AUTOX', 'Campeonato de Autocross', 'puntos', 2], ['CICLO', 'Vuelta Ciclística', 'tiempo', 1], ['MTB', 'Copa de MTB', 'puntos', 1]] as [$mod, $nom, $clasif, $mangas]) {
            if (valor('SELECT id FROM torneos WHERE liga_id = ? AND modalidad = ?', [$lid, $mod])) continue;
            q("INSERT INTO torneos (liga_id, nombre, temporada, modalidad, formato, num_grupos, vueltas, estado, carrera_mangas, carrera_modo, carrera_clasif, carrera_descarte)
               VALUES (?,?,?,?, 'liga', 1, 1, 'en_juego', ?, 'mejor', ?, 0)", [$lid, $nom, date('Y'), $mod, $mangas, $clasif]);
            $tid = (int)db()->lastInsertId();
            $t = fila('SELECT * FROM torneos WHERE id = ?', [$tid]);
            guardar_slug_torneo($t, '');
            carrera_cargar_ejemplo(fila('SELECT * FROM torneos WHERE id = ?', [$tid]));
        }
    });
}

/**
 * (v25) DEMO DE DUELOS Y BOLOS: tenis, pádel, tenis de mesa, artes marciales, boxeo y bolos en la liga de demostración,
 * cada uno con su ejemplo (llave con resultados, todos contra todos, medallero; bolos con 3 jornadas).
 */
function demo_individuales(int $lid): void
{
    sin_auditoria(function () use ($lid) {
        $lista = [
            ['TENIS', 'Abierto de Tenis', ['duelo_sets' => 3, 'duelo_bronce' => 'no']],
            ['PADEL', 'Torneo de Pádel', ['duelo_sets' => 3, 'duelo_bronce' => 'no']],
            ['TMESA', 'Copa de Tenis de Mesa', ['duelo_sets' => 5, 'duelo_bronce' => 'partido']],
            ['MARC', 'Campeonato de Karate', ['duelo_bronce' => 'doble']],
            ['BOXEO', 'Guantes de Oro (boxeo)', ['duelo_rounds' => 3, 'duelo_bronce' => 'doble']],
            ['BOLOS', 'Liga de Bolos', ['bolos_lineas' => 3, 'bolos_hcp' => 'auto', 'bolos_clasif' => 'total']],
            ['NATAC', 'Festival de Natación', ['carrera_puntos' => '9,7,6,5,4,3,2,1']], // (v26)
            ['ATLET', 'Juegos de Atletismo', ['carrera_puntos' => '9,7,6,5,4,3,2,1']],
            ['PISTA', 'Campeonato de Pista', ['carrera_puntos' => '9,7,6,5,4,3,2,1']], // (v34)
            ['BMX', 'Copa BMX Racing', ['carrera_puntos' => '9,7,6,5,4,3,2,1']],
            ['BMXFS', 'BMX Freestyle Park', ['carrera_puntos' => '9,7,6,5,4,3,2,1']],
            // (v36)
            ['SQUASH', 'Abierto de Squash', duelo_reglas_fabrica('SQUASH')],
            ['AJED', 'Torneo de Ajedrez', duelo_reglas_fabrica('AJED')],
            ['ESPOR', 'Copa eFootball', duelo_reglas_fabrica('ESPOR')],
            ['RUTA', '10K de la Liga', ['carrera_puntos' => '9,7,6,5,4,3,2,1']],
            ['PESAS', 'Campeonato de Pesas', ['carrera_puntos' => '9,7,6,5,4,3,2,1']],
            ['TRIAT', 'Triatlón Sprint', ['carrera_puntos' => '9,7,6,5,4,3,2,1']],
        ];
        foreach ($lista as [$mod, $nom, $extra]) {
            if (valor('SELECT id FROM torneos WHERE liga_id = ? AND modalidad = ?', [$lid, $mod])) continue;
            $d = array_merge(['liga_id' => $lid, 'nombre' => $nom, 'temporada' => date('Y'), 'modalidad' => $mod, 'formato' => 'liga', 'num_grupos' => 1, 'vueltas' => 1, 'estado' => 'en_juego'], $extra);
            q('INSERT INTO torneos (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_fill(0, count($d), '?')) . ')', array_values($d));
            $tid = (int)db()->lastInsertId();
            guardar_slug_torneo(fila('SELECT * FROM torneos WHERE id = ?', [$tid]), '');
            $t = fila('SELECT * FROM torneos WHERE id = ?', [$tid]);
            if ($mod === 'BOLOS') bolos_cargar_ejemplo($t);
            elseif (es_pruebas($t)) pruebas_cargar_ejemplo($t);
            else duelo_cargar_ejemplo($t);
        }
        demo_inscripciones_indiv($lid);
    });
}

/** (v26) Inscripción en línea abierta en dos campeonatos de la demo, con solicitudes por aprobar. */
function demo_inscripciones_indiv(int $lid): void
{
    $hasta = date('Y-m-d', strtotime('+20 days'));
    foreach ([['NATAC', 10], ['TENIS', 15]] as [$mod, $valor]) {
        $t = fila('SELECT * FROM torneos WHERE liga_id = ? AND modalidad = ?', [$lid, $mod]);
        if (!$t || (int)valor('SELECT COUNT(*) FROM inscripciones_indiv WHERE torneo_id = ?', [$t['id']])) continue;
        q("UPDATE torneos SET insc_indiv_hasta = ?, insc_indiv_valor = ?, insc_indiv_info = ? WHERE id = ?", [$hasta, $valor,
            'Transferencia a la cuenta de ahorros de la liga o pago en efectivo en la mesa de control el día de la competencia.', $t['id']]);
        $anio = (int)date('Y');
        if ($mod === 'NATAC') {
            $evs = array_values(array_filter(pruebas_eventos((int)$t['id']), fn($e) => $e['rama'] === 'F' && $e['categoria'] === '11 y 12 años'));
            $lista = [['Renata Espinoza', 'F', ($anio - 12) . '-04-10', 'Club Delfines', array_slice(array_column($evs, 'id'), -2), date('Y-m-d', strtotime('-2 days')) . ' 18:40:00', ''],
                      ['Lucía Montalvo', 'F', ($anio - 11) . '-08-22', 'Tiburones UIO', array_slice(array_column($evs, 'id'), -1), date('Y-m-d', strtotime('-1 day')) . ' 09:15:00', date('Y-m-d', strtotime('-1 day')) . ' 09:18:00']];
            foreach ($lista as $i => [$nom, $rama, $nac, $club, $ev, $creado, $pagado]) {
                q('INSERT INTO inscripciones_indiv (torneo_id, liga_id, nombre, cedula, telefono, email, club, ciudad, rama, nacimiento, extra, pagado, creado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$t['id'], $lid, $nom, ['1720345675', '1720356789'][$i], '09' . (91234567 + $i), '', $club, 'Quito', $rama, $nac, json_encode(['eventos' => $ev]), $pagado, $creado]);
            }
        } else {
            q('INSERT INTO inscripciones_indiv (torneo_id, liga_id, nombre, cedula, telefono, club, ciudad, rama, nacimiento, extra, creado) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$t['id'], $lid, 'Esteban Villacís', '1719283747', '0987766554', 'Club de Tenis Cumbayá', 'Quito', 'M', ($anio - 24) . '-02-03', '[]', date('Y-m-d', strtotime('-1 day')) . ' 20:05:00']);
        }
    }
}
