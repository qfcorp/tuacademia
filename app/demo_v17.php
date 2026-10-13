<?php
/*
 * VERSIÓN 17 — DATOS DE LA ESCUELA DEMO (para presentar a los clientes):
 * 5 meses de clases con ejercicios, notas del profesor y fotos; ciclos de trabajo; clases planificadas de las próximas
 * 2 semanas; 3 jornadas de evaluación cerradas y una abierta; medidas con talla sentado; evaluaciones técnicas; metas;
 * valores de referencia; y todo lo de divisiones formativas (partidos, minutos, cuerpo técnico, valoraciones, colegio,
 * maduración y lesiones con fases).
 */

/** Talla promedio (cm) de un niño según su edad. */
function demo_talla_edad(float $edad): float
{
    $t = [5 => 110, 6 => 116, 7 => 122, 8 => 128, 9 => 133, 10 => 139, 11 => 144, 12 => 150, 13 => 157, 14 => 164, 15 => 170, 16 => 173, 17 => 175, 18 => 176];
    $e = max(5, min(17.99, $edad));
    $a = (int)floor($e);
    return $t[$a] + ($t[$a + 1] - $t[$a]) * ($e - $a);
}

/** Valor típico de un test para una edad (el mismo modelo de la demo anterior). */
function demo_base_test(string $nombre, float $edad): float
{
    return match ($nombre) {
        'Velocidad 30 metros' => 7.4 - $edad * 0.15, 'Salto horizontal' => 90 + $edad * 9, 'Salto vertical' => 15 + $edad * 2, 'Abdominales 1 minuto' => 12 + $edad * 2,
        'Flexibilidad (sit and reach)' => 22, 'Resistencia Yo-Yo' => 200 + $edad * 60, 'Conducción en slalom' => 16 - $edad * 0.3, default => 8 + $edad * 3,
    };
}

function demo_v17_escuela(int $lid, array $gids, array $alumnos, array $prof): void
{
    $Y = (int)date('Y');
    $hoy = date('Y-m-d');
    $r = fn(array $x) => $x[random_int(0, count($x) - 1)];
    q('UPDATE ligas SET esc_formativas = 1 WHERE id = ?', [$lid]);

    // Fotos de ejemplo de las clases (ilustraciones incluidas en el sistema)
    $fotos = [];
    $dir = RAIZ . '/data/fotos/demo';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    foreach (glob(APP_DIR . '/demo/fotos_clase/*.jpg') ?: [] as $f) {
        $dest = $dir . '/' . basename($f);
        if (!is_file($dest)) @copy($f, $dest);
        if (is_file($dest)) $fotos[] = 'fotos/demo/' . basename($f);
    }

    // Usuario del cuerpo técnico (psicóloga)
    usuario_demo('staff@demo.tuacademia.tukanec.com', 'Lcda. Paula Vera', 'liga', $lid, ['perfil' => 'staff', 'area' => 'psicologia']);

    // Datos de cada alumno: talento, constancia, fecha de nacimiento
    $info = [];
    foreach ($alumnos as $a) {
        $al = fila('SELECT * FROM esc_alumnos WHERE id = ?', [$a['id']]);
        $info[$a['id']] = $al + ['tal' => $a['fam'] ? 0.07 + random_int(0, 3) / 100 : random_int(-9, 9) / 100, 'prog' => $a['fam'] ? 1.4 : random_int(5, 15) / 10,
            'asis' => $a['fam'] ? 97 : $r([96, 92, 90, 88, 85, 82, 78, 72]), 'fam' => $a['fam'], 'g' => $a['g']];
    }
    $grupo = [];
    foreach ($gids as $i => $g) $grupo[$g['id']] = fila('SELECT * FROM esc_grupos WHERE id = ?', [$g['id']]) + ['i' => $i];
    $evaluados = [$gids[1]['id'], $gids[2]['id'], $gids[3]['id'], $gids[4]['id']]; // Sub-9 a Sub-15
    $club = [$gids[2]['id'], $gids[3]['id'], $gids[4]['id']];                     // Sub-11 a Sub-15 compiten

    /* ---------- A) Ejercicios, ciclos y clases ---------- */
    preparar_ejercicios($lid);
    q("INSERT INTO esc_ejercicios (liga_id, nombre, tipo, objetivo, descripcion, duracion, jugadores, materiales, edades, video, registrado_por) VALUES (?, 'Rueda de pases con desmarque', 'tecnico', 'Pase tenso y desmarque de apoyo', 'Cuatro filas en cuadrado; pase y sigo con desmarque antes de recibir. Dos balones a la vez para más intensidad.', 12, '12 a 16', 'Balones y 4 conos', 'Sub-10 en adelante', 'https://www.youtube.com/results?search_query=rueda+de+pases+futbol', 'Carlos Mena')", [$lid]);
    $bib = [];
    foreach (filas('SELECT id, nombre, tipo, duracion FROM esc_ejercicios WHERE liga_id = ?', [$lid]) as $e) $bib[$e['tipo']][] = $e;
    $temas = [
        ['Conducción y regate', 'Dominar la conducción con ambos perfiles y el 1 vs 1', ['tecnico', 'fisico'], ['Conducción con cambios de dirección', 'Regate y definición en 1 vs 1', 'Conducción a velocidad con la pierna menos hábil']],
        ['Pase y control orientado', 'Pasar tenso y controlar hacia el espacio libre', ['tecnico', 'tactico'], ['Pase corto y control orientado', 'Pase tenso con la pierna menos hábil', 'Recepción orientada bajo presión']],
        ['Definición', 'Rematar con precisión en pocos toques', ['tecnico', 'juego'], ['Remate tras control orientado', 'Definición frente al arquero', 'Remate de primera tras pase atrás']],
        ['Juego de posición', 'Ocupar espacios y encontrar al compañero libre', ['tactico', 'juego'], ['Posesión con comodines', 'Amplitud y profundidad en ataque', 'Salida limpia desde el arco']],
        ['Transiciones y presión', 'Reaccionar rápido al perder y al recuperar el balón', ['tactico', 'fisico'], ['Presión tras pérdida en 5 segundos', 'Contraataque en superioridad', 'Repliegue y basculación']],
        ['Velocidad y coordinación', 'Mejorar la reacción, la coordinación y la velocidad', ['fisico', 'tecnico'], ['Velocidad de reacción', 'Coordinación con escalera y vallas', 'Aceleraciones con balón']],
    ];
    $ini = strtotime('-150 days');
    $ciclos = [];
    for ($k = 0, $t = strtotime('monday this week', $ini); $t <= strtotime('+20 days'); $k++, $t = strtotime('+4 weeks', $t)) {
        $tm = $temas[$k % count($temas)];
        q("INSERT INTO esc_ciclos (liga_id, grupo_id, nombre, desde, hasta, objetivo, contenidos, registrado_por) VALUES (?, NULL, ?, ?, ?, ?, ?, 'Carlos Mena')",
            [$lid, $tm[0], date('Y-m-d', $t), date('Y-m-d', strtotime('+27 days', $t)), $tm[1], "Semana 1: " . $tm[3][0] . "\nSemana 2: " . $tm[3][1] . "\nSemana 3: " . $tm[3][2] . "\nSemana 4: juego aplicado y evaluación del ciclo"]);
        $ciclos[] = ['id' => (int)db()->lastInsertId(), 'desde' => date('Y-m-d', $t), 'hasta' => date('Y-m-d', strtotime('+27 days', $t)), 'tema' => $tm];
    }
    $ciclo_de = function (string $f) use ($ciclos) { foreach ($ciclos as $c) if ($f >= $c['desde'] && $f <= $c['hasta']) return $c; return $ciclos[0]; };
    $notas = [
        'bien' => ['Excelente actitud y concentración toda la clase.', 'Muy buen control con la pierna izquierda, ¡se nota la práctica!', 'Ayudó a sus compañeros y lideró su equipo en el juego.', 'Gran esfuerzo en el trabajo físico.', 'Mejoró mucho la conducción a velocidad.', 'Definió muy bien frente al arco.'],
        'mejorar' => ['Levantar la cabeza antes de pasar.', 'Practicar en casa toques con la pierna menos hábil (5 minutos al día).', 'Llegar puntual para no perder el calentamiento.', 'Hidratarse bien: traer su botella de agua.', 'Mantener la posición cuando el equipo defiende.'],
        'nota' => ['Hoy trabajó con el grupo de arqueros.', 'Salió 10 minutos antes por una molestia leve; se recuperó bien.', 'Fue capitán en el partido de cierre.'],
    ];
    $pg = array_column(filas('SELECT id, profesor_id FROM esc_grupos WHERE liga_id = ?', [$lid]), 'profesor_id', 'id');
    $n_nom = ['Andrea Ruiz', 'Carlos Mena', 'Diego Salazar'];
    $nom_prof = array_combine($prof, $n_nom);
    $mes_pasado = date('Y-m', strtotime('first day of last month'));
    $plan = function (array $g, string $f, string $estado) use ($bib, $r, $ciclo_de, $lid) {
        $c = $ciclo_de($f);
        [$tipo1, $tipo2] = $c['tema'][2];
        $dur = duracion_grupo($g);
        $ej = [];
        $ej[] = [$r($bib['calentamiento']), 'calentamiento'];
        $ej[] = [$r($bib[$tipo1]), 'principal'];
        $ej[] = [$r($bib[$tipo2] ?? $bib['tecnico']), 'principal'];
        $ej[] = [$r($bib['juego']), 'final'];
        if ($dur >= 90) $ej[] = [$r($bib['calma']), 'final'];
        $tot = array_sum(array_map(fn($x) => (int)$x[0]['duracion'], $ej));
        $filas_e = array_map(fn($x) => [(int)$x[0]['id'], $x[0]['nombre'], $x[1], max(5, (int)round((int)$x[0]['duracion'] * $dur / max(1, $tot) / 5) * 5), ''], $ej);
        return [$c, $filas_e, $dur];
    };
    $ins_ej = function (int $sid, array $filas_e) { foreach ($filas_e as $i => [$eid, $nom, $fase, $min, $nt]) q('INSERT INTO esc_sesion_ejercicios (sesion_id, ejercicio_id, nombre, fase, minutos, notas, orden) VALUES (?,?,?,?,?,?,?)', [$sid, $eid, $nom, $fase, $min, $nt, $i]); };

    for ($d = 150; $d >= -13; $d--) {
        $f = date('Y-m-d', strtotime(($d >= 0 ? '-' : '+') . abs($d) . ' days'));
        $dow = (string)date('N', strtotime($f));
        foreach ($gids as $g) {
            if (!in_array($dow, explode(',', $g['dias']), true)) continue;
            $gr = $grupo[$g['id']];
            [$c, $filas_e, $dur] = $plan($gr, $f, $d > 0 ? 'dictada' : 'planificada');
            $obj = $r($c['tema'][3]);
            if ($d <= 0) { // hoy y las próximas 2 semanas: planificadas
                q("INSERT INTO esc_sesiones (grupo_id, fecha, profesor_id, profesor_asistio, objetivo, contenido, duracion, intensidad, visible_familia, ciclo_id, estado, registrado_por) VALUES (?,?,?,1,?,?,?,?,1,?, 'planificada', ?)",
                    [$g['id'], $f, $pg[$g['id']], $obj, 'Traer canilleras y agua.', $dur, random_int(5, 7), $c['id'], $nom_prof[$pg[$g['id']]] ?? 'Demostración']);
                $ins_ej((int)db()->lastInsertId(), $filas_e);
                continue;
            }
            if (random_int(1, 60) === 1) { // alguna clase suspendida por lluvia
                q("INSERT INTO esc_sesiones (grupo_id, fecha, profesor_id, estado, motivo, registrado_por) VALUES (?,?,?, 'suspendida', 'Lluvia fuerte', 'Demostración')", [$g['id'], $f, $pg[$g['id']]]);
                continue;
            }
            $int = random_int(5, 8);
            q("INSERT INTO esc_sesiones (grupo_id, fecha, profesor_id, profesor_asistio, objetivo, contenido, observaciones, duracion, intensidad, rpe, visible_familia, ciclo_id, estado, registrado_por) VALUES (?,?,?,?,?,?,?,?,?,?,1,?, 'dictada', ?)",
                [$g['id'], $f, $pg[$g['id']], random_int(1, 25) === 1 ? 0 : 1, $obj, '', '', $dur, $int, max(1, min(10, $int + random_int(-1, 1))), $c['id'], $nom_prof[$pg[$g['id']]] ?? 'Demostración']);
            $sid = (int)db()->lastInsertId();
            $ins_ej($sid, $filas_e);
            $famg = false;
            foreach ($alumnos as $a) {
                if ($a['g'] !== $g['id']) continue;
                $in = $info[$a['id']];
                $x = random_int(1, 100);
                $est = $x <= $in['asis'] ? ($x <= $in['asis'] - 6 ? 'P' : 'T') : (random_int(0, 1) ? 'J' : 'A');
                if ($in['fam'] && substr($f, 0, 7) === $mes_pasado) $est = 'P'; // asistencia perfecta el mes pasado
                q('INSERT INTO esc_asistencia (sesion_id, alumno_id, estado) VALUES (?,?,?)', [$sid, $a['id'], $est]);
                if ($in['fam']) $famg = true;
                if (in_array($est, ['P', 'T'], true) && random_int(1, 100) <= ($in['fam'] ? 35 : 8)) {
                    $tp = $r(['bien', 'bien', 'bien', 'mejorar', 'mejorar', 'nota']);
                    q('INSERT INTO esc_notas_clase (sesion_id, alumno_id, tipo, texto, visible_familia, registrado_por) VALUES (?,?,?,?,1,?)', [$sid, $a['id'], $tp, $r($notas[$tp]), $nom_prof[$pg[$g['id']]] ?? '']);
                }
            }
            if ($fotos && $d <= 45 && random_int(1, 100) <= ($famg ? 55 : 12)) {
                foreach (array_slice(array_values(array_unique([$r($fotos), $r($fotos)])), 0, random_int(1, 2)) as $ft)
                    q("INSERT INTO esc_sesion_fotos (sesion_id, archivo, descripcion, publica, subido_por) VALUES (?,?, '', 1, 'Demostración')", [$sid, $ft]);
            }
        }
    }

    /* ---------- B y C) Jornadas, medidas, tests, técnica, valores de referencia y metas ---------- */
    $tests = filas('SELECT * FROM esc_tests WHERE liga_id = ? ORDER BY orden', [$lid]);
    $hab = array_column(filas('SELECT id FROM esc_habilidades WHERE liga_id = ? ORDER BY orden', [$lid]), 'id');
    $jds = [[140, 'Evaluación inicial'], [75, 'Evaluación de mitad de año'], [8, 'Evaluación de ' . mb_strtolower(nombre_mes(date('Y-m', strtotime('-8 days'))))]];
    $grupos_ev = implode(',', $evaluados);
    $ids_t = implode(',', array_column($tests, 'id'));
    foreach ($jds as $k => [$dias, $nombre]) {
        $fj = date('Y-m-d', strtotime("-$dias days"));
        q("INSERT INTO esc_jornadas (liga_id, nombre, fecha, tests, grupos, medidas, estado, cerrada_en, notas, registrado_por) VALUES (?,?,?,?,?,1,'cerrada',?,?, 'Carlos Mena')",
            [$lid, $nombre, $fj, $ids_t, $grupos_ev, date('Y-m-d H:i:s', strtotime($fj . ' +2 days 18:00')), 'Calentar 10 minutos; 2 intentos por test y vale el mejor.']);
        $jid = (int)db()->lastInsertId();
        foreach ($alumnos as $a) {
            if (!in_array($a['g'], $evaluados, true)) continue;
            $in = $info[$a['id']];
            $edad = (strtotime($fj) - strtotime($in['fecha_nac'])) / (365.25 * 86400);
            // Medidas: crecimiento más rápido cerca del estirón
            $talla = demo_talla_edad($edad) * (1 + $in['tal'] * 0.25) + ($in['id'] % 5 - 2);
            $ratio = $edad < 11 ? 0.535 : ($edad < 13.5 ? 0.525 : 0.518);
            $peso = round(($talla / 100) ** 2 * (16 + ($in['id'] % 7) * 0.6 + max(0, $edad - 10) * 0.35), 1);
            q("INSERT INTO esc_mediciones (alumno_id, fecha, peso, talla, talla_sentado, cintura, grasa, jornada_id, registrado_por) VALUES (?,?,?,?,?,?,?,?, 'Diego Salazar')",
                [$a['id'], $fj, $peso, round($talla, 1), round($talla * $ratio + random_int(-8, 8) / 10, 1), round(48 + $edad * 1.5 + random_int(-2, 2), 1), round(13 + random_int(0, 50) / 10, 1), $jid]);
            foreach ($tests as $t) {
                $base = demo_base_test($t['nombre'], $edad);
                $mej = ($t['mejor'] === 'menor' ? -0.022 : 0.04) * $k * $in['prog'];
                $v = $base * ($t['mejor'] === 'menor' ? 1 - $in['tal'] : 1 + $in['tal']) * (1 + $mej) * (1 + random_int(-4, 4) / 100);
                q("INSERT OR IGNORE INTO esc_resultados (alumno_id, test_id, fecha, valor, registrado_por, jornada_id) VALUES (?,?,?,?,?,?)", [$a['id'], $t['id'], $fj, round($v, $t['unidad'] === 's' ? 2 : 0), $nom_prof[$pg[$a['g']]] ?? 'Demostración', $jid]);
            }
            $vals = [];
            foreach ($hab as $h) $vals[$h] = max(1, min(5, 2 + (int)round($in['tal'] * 12) + ($k >= 1 && random_int(0, 2) ? 1 : 0) + ($k === 2 && ($in['fam'] || random_int(0, 3) === 0) ? 1 : 0) - (random_int(0, 4) === 0 ? 1 : 0)));
            $com = [['Buena actitud. Debe mejorar el pase con la pierna menos hábil.', 'Llegó con buena base técnica; trabajar la toma de decisiones.', 'Muy entusiasta; necesita más concentración en los ejercicios.'],
                    ['Avanza bien: mejor control orientado y más confianza con el balón.', 'Mejoró la conducción; seguir con el perfil débil.', 'Buen trimestre; le falta intensidad en la presión.'],
                    ['Mejoró mucho en el control y la toma de decisiones. Seguir trabajando el perfil débil.', 'Gran evolución técnica; ya es referente de su grupo.', 'Constante y comprometido; se nota el trabajo en casa.']][$k];
            q("INSERT INTO esc_eval_tecnica (alumno_id, fecha, valores, comentario, registrado_por) VALUES (?,?,?,?, 'Carlos Mena')", [$a['id'], $fj, json_encode($vals), $in['fam'] ? $com[2 - ($k === 2 ? 0 : 1)] : $r($com)]);
        }
    }
    // Jornada abierta para mostrar la planilla del celular
    q("INSERT INTO esc_jornadas (liga_id, nombre, fecha, tests, grupos, medidas, estado, notas, registrado_por) VALUES (?, 'Control de velocidad y salto', ?, ?, ?, 0, 'abierta', 'Solo velocidad y salto horizontal; 2 intentos.', 'Carlos Mena')",
        [$lid, $hoy, implode(',', array_column(array_filter($tests, fn($t) => in_array($t['nombre'], ['Velocidad 30 metros', 'Salto horizontal'], true)), 'id')), $gids[2]['id']]);
    // Valores de referencia (semáforo) para dos tests
    foreach ($tests as $t) {
        if (!in_array($t['nombre'], ['Velocidad 30 metros', 'Salto horizontal'], true)) continue;
        foreach ([[8, 9], [10, 11], [12, 13], [14, 15]] as [$e1, $e2]) {
            $b = demo_base_test($t['nombre'], $e1 + 0.5) * ($t['mejor'] === 'menor' ? 0.98 : 1.04);
            [$am, $ve] = $t['mejor'] === 'menor' ? [round($b * 1.04, 2), round($b * 0.965, 2)] : [round($b * 0.95, 0), round($b * 1.04, 0)];
            q("INSERT INTO esc_baremos (liga_id, test_id, edad_desde, edad_hasta, genero, amarillo, verde) VALUES (?,?,?,?, '', ?, ?)", [$lid, $t['id'], $e1, $e2, $am, $ve]);
        }
    }
    // Metas personales
    $metas = [
        ['tecnica', 'Pasar y controlar con la pierna menos hábil'], ['tecnica', 'Llegar a 50 toques seguidos de dominio'], ['fisica', 'Bajar el sprint de 30 m en 0,2 segundos'],
        ['tactica', 'Mantener la posición cuando el equipo defiende'], ['actitud', 'Llegar puntual a todas las clases del mes'], ['escolar', 'Mantener el promedio sobre 8 en el colegio'],
        ['salud', 'Traer fruta y agua a cada entrenamiento'], ['tecnica', 'Rematar con el empeine sin mirar el balón'],
    ];
    foreach ($alumnos as $a) {
        if (!in_array($a['g'], $evaluados, true)) continue;
        $n = $a['fam'] ? 3 : (random_int(0, 1) ? random_int(1, 2) : 0);
        $usadas = [];
        for ($i = 0; $i < $n; $i++) {
            do { $m = $r($metas); } while (in_array($m[1], $usadas, true));
            $usadas[] = $m[1];
            $est = $i === 0 && ($a['fam'] || random_int(0, 2) === 0) ? 'lograda' : 'en_curso';
            $av = $est === 'lograda' ? 100 : $r([20, 30, 40, 50, 60, 70, 80]);
            q("INSERT INTO esc_metas (alumno_id, area, texto, fecha_objetivo, avance, estado, comentario, visible_familia, registrado_por, creado, actualizado) VALUES (?,?,?,?,?,?,?,1,?,?,?)",
                [$a['id'], $m[0], $m[1], date('Y-m-d', strtotime('+' . random_int(20, 90) . ' days')), $av, $est, $est === 'lograda' ? '¡Lo logró! Felicitaciones.' : $r(['Va muy bien.', 'Constancia: practicar en casa.', '']),
                 $nom_prof[$pg[$a['g']]] ?? 'Carlos Mena', date('Y-m-d H:i:s', strtotime('-100 days')), date('Y-m-d H:i:s', strtotime('-' . random_int(3, 40) . ' days'))]);
        }
    }
    foreach ($alumnos as $a) if (in_array($a['g'], $evaluados, true) && !$a['fam'] && random_int(0, 2) === 0) q("INSERT OR IGNORE INTO esc_libretas (alumno_id, periodo, comentario, registrado_por) VALUES (?,?,?, 'Carlos Mena')",
        [$a['id'], periodo_libreta(), $r(['Buen trimestre, con avances claros en la técnica. Seguir con la misma constancia.', 'Muy comprometido y buen compañero. Debe mejorar la puntualidad.', 'Progresa bien; el próximo trimestre trabajaremos la toma de decisiones.'])]);

    /* ---------- D) Divisiones formativas ---------- */
    $rivales = ['Academia Alfaro Moreno', 'Club Deportivo Norte', 'Escuela Juvenil Sur', 'Talentos del Valle', 'Club Atlético Los Chillos', 'Academia Real Quito', 'Fútbol Base Cumbayá', 'Club Estrellas'];
    foreach ($club as $ci => $gidc) {
        $gr = $grupo[$gidc];
        $dur = [60, 70, 80][$ci];
        $jug = array_values(array_filter($alumnos, fn($a) => $a['g'] === $gidc));
        $ult_dom = strtotime('last sunday');
        for ($s = 11; $s >= -2; $s--) {
            $fp = date('Y-m-d', strtotime(($s >= 0 ? '-' : '+') . abs($s) * 7 . ' days', $ult_dom));
            $jugado = $fp < $hoy;
            $gf = $jugado ? $r([0, 1, 1, 2, 2, 3, 4]) : null;
            $gc = $jugado ? $r([0, 0, 1, 1, 2, 3]) : null;
            q("INSERT INTO esc_partidos (liga_id, grupo_id, fecha, hora, rival, competencia, condicion, lugar, goles_favor, goles_contra, duracion, notas, registrado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'Carlos Mena')",
                [$lid, $gidc, $fp, ['09:00', '10:30', '12:00'][$ci], $r($rivales), $s % 4 === 3 ? 'Amistoso' : 'Liga', $s % 2 ? 'local' : 'visitante', $s % 2 ? 'Cancha 1 · Sede Norte' : 'Cancha del rival',
                 $gf, $gc, $dur, $jugado ? $r(['Buen primer tiempo; nos costó sostener la presión en el segundo.', 'Partido muy parejo; destacó el orden defensivo.', 'Gran circulación de balón, faltó definir.']) : '']);
            $pid = (int)db()->lastInsertId();
            shuffle($jug);
            $goles = (int)$gf;
            foreach ($jug as $i => $a) {
                if (!$jugado) { q('INSERT INTO esc_partido_jugadores (partido_id, alumno_id, convocado) VALUES (?,?,1)', [$pid, $a['id']]); continue; }
                $tit = $i < 7 ? 1 : 0;
                $min = $tit ? ($i < 5 ? $dur : $dur - random_int(10, 25)) : ($i < 9 ? random_int(10, 30) : 0);
                $pos = $info[$a['id']]['posicion'];
                $g = 0;
                if ($goles > 0 && $min > 0 && random_int(1, 100) <= ($pos === 'Delantero' ? 55 : ($pos === 'Mediocampista' ? 30 : 10)) + ($a['fam'] ? 20 : 0)) { $g = 1; $goles--; }
                q('INSERT INTO esc_partido_jugadores (partido_id, alumno_id, convocado, titular, minutos, goles, asistencias, amarillas, calificacion) VALUES (?,?,1,?,?,?,?,?,?)',
                    [$pid, $a['id'], $tit, $min, $g, $min > 0 && random_int(1, 8) === 1 ? 1 : 0, $min > 0 && random_int(1, 25) === 1 ? 1 : 0, $min > 0 ? $r([5.5, 6, 6, 6.5, 6.5, 7, 7, 7.5, 8, 8.5]) : null]);
            }
        }
        // Cuerpo técnico, valoraciones, colegio y estatura de los padres
        foreach ($jug as $a) {
            $in = $info[$a['id']];
            q('UPDATE esc_alumnos SET talla_padre = ?, talla_madre = ? WHERE id = ?', [random_int(164, 182), random_int(152, 168), $a['id']]);
            $pot = $in['tal'] > 0.05 ? 'A' : ($in['tal'] > -0.04 ? 'B' : 'C');
            q("INSERT INTO esc_valoraciones (alumno_id, fecha, posicion, posicion2, perfil, fortalezas, mejorar, potencial, recomendacion, registrado_por) VALUES (?,?,?,?,?,?,?,?,?, 'Carlos Mena')",
                [$a['id'], date('Y-m-d', strtotime('-' . random_int(10, 30) . ' days')), $in['posicion'], $r(['', 'Extremo', 'Lateral', 'Volante']),
                 $r(['Rápido, buen primer toque, perfil derecho.', 'Zurdo, buena visión de juego, le cuesta el juego aéreo.', 'Fuerte en el 1 vs 1, buena lectura defensiva.', 'Técnico, pausado, buen pase largo.']),
                 $r(['Conducción y regate; mentalidad competitiva.', 'Lectura del juego y pase entre líneas.', 'Velocidad y presión tras pérdida.', 'Liderazgo y comunicación con sus compañeros.']),
                 $r(['Perfil débil y juego aéreo.', 'Toma de decisiones bajo presión.', 'Resistencia en el segundo tiempo.', 'Concentración en las jugadas a balón parado.']),
                 $pot, $pot === 'A' ? $r(['promover', 'mantener']) : ($pot === 'B' ? $r(['mantener', 'reforzar']) : $r(['reforzar', 'observar']))]);
            foreach ([['2do quimestre ' . ($Y - 1) . '-' . $Y, 60], ['1er quimestre ' . $Y . '-' . ($Y + 1), 5]] as [$per, $hace]) {
                $pr = round(min(10, max(6, 8 + $in['tal'] * 8 + random_int(-12, 10) / 10)), 2);
                q("INSERT INTO esc_academico (alumno_id, periodo, promedio, asistencia, conducta, materias_bajas, observacion, registrado_por, creado) VALUES (?,?,?,?,?,?,?, 'Tutor académico', ?)",
                    [$a['id'], $per, $pr, random_int(88, 100), $pr >= 8 ? 'A' : 'B', $pr < 7.2 ? $r(['Matemáticas', 'Física', 'Inglés']) : '', $pr < 7.2 ? 'Refuerzo académico los martes antes del entrenamiento.' : '', date('Y-m-d H:i:s', strtotime("-$hace days"))]);
            }
            $ns = [['preparador', 'Buena respuesta al trabajo de fuerza con el propio cuerpo. Aumentar progresivamente.', 0], ['preparador', 'En pleno estirón: bajar la carga de saltos y trabajar movilidad.', 0],
                   ['nutricion', 'Desayuna poco antes de entrenar: se recomendó fruta y avena. Hidratación adecuada.', 0], ['psicologia', 'Se frustra cuando pierde el balón; trabajamos rutinas de respiración y diálogo interno positivo.', 1],
                   ['medico', 'Control médico anual: apto para la práctica deportiva.', 0], ['psicologia', 'Muy buena motivación; conversar con la familia sobre la presión por los resultados.', 1]];
            foreach ((array)array_rand($ns, random_int(1, 3)) as $ix) {
                [$ar, $tx, $pv] = $ns[$ix];
                q('INSERT INTO esc_staff_notas (alumno_id, area, fecha, texto, privada, registrado_por) VALUES (?,?,?,?,?,?)', [$a['id'], $ar, date('Y-m-d', strtotime('-' . random_int(2, 90) . ' days')), $tx, $pv,
                    ['preparador' => 'Diego Salazar', 'nutricion' => 'Nutricionista del club', 'psicologia' => 'Lcda. Paula Vera', 'medico' => 'Dr. Luis Ortega'][$ar]]);
            }
        }
    }
    // Lesiones con fases (una en readaptación y una ya dada de alta)
    $j15 = array_values(array_filter($alumnos, fn($a) => $a['g'] === $gids[4]['id']));
    $j13 = array_values(array_filter($alumnos, fn($a) => $a['g'] === $gids[3]['id'] && !$a['fam']));
    q("INSERT INTO esc_lesiones (alumno_id, fecha, zona, tipo, descripcion, dias_baja, diagnostico, fase, retorno_estimado, profesional, registrado_por) VALUES (?,?, 'Muslo izquierdo', 'Distensión', 'En un sprint del partido.', 21, 'Distensión del isquiotibial grado I', 'readaptacion', ?, 'Fisioterapeuta del club', 'Diego Salazar')",
        [$j15[1]['id'], date('Y-m-d', strtotime('-16 days')), date('Y-m-d', strtotime('+5 days'))]);
    $l1 = (int)db()->lastInsertId();
    foreach ([[-16, 'aguda', 'Reposo, hielo y compresión.'], [-11, 'tratamiento', 'Fisioterapia 3 veces por semana.'], [-4, 'readaptacion', 'Ya trota sin dolor; empieza trabajo con balón.']] as [$dd, $fa, $tx])
        q("INSERT INTO esc_lesion_seguimiento (lesion_id, fecha, fase, texto, registrado_por) VALUES (?,?,?,?, 'Diego Salazar')", [$l1, date('Y-m-d', strtotime("$dd days")), $fa, $tx]);
    q("INSERT INTO esc_lesiones (alumno_id, fecha, zona, tipo, descripcion, dias_baja, diagnostico, fase, alta, profesional, registrado_por) VALUES (?,?, 'Rodilla derecha', 'Sobrecarga', 'Dolor al saltar.', 14, 'Enfermedad de Osgood-Schlatter (crecimiento)', 'alta', ?, 'Dr. Luis Ortega', 'Diego Salazar')",
        [$j13[2]['id'], date('Y-m-d', strtotime('-70 days')), date('Y-m-d', strtotime('-50 days'))]);
}
