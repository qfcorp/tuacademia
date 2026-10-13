<?php
/*
 * (v21) DEMO MULTIDEPORTE de la escuela: grupos de básquet, vóley, boxeo, natación, taekwondo y tenis,
 * con sus profesores, alumnos, clases con ejercicios de su deporte, combates, cinturones, medallas,
 * un partido de básquet y dos hermanos que hacen un segundo deporte (pagan dos mensualidades).
 * Todos los nombres y datos son inventados.
 */
function demo_multideporte(int $eid): void
{
    if (!$eid) return;
    sin_auditoria(function () use ($eid) {
        mt_srand(2121);
        $pdo = db();
        $Y = (int)date('Y');
        $hoy = date('Y-m-d');
        $pdo->beginTransaction();
        try {
            q('UPDATE ligas SET esc_desc_segundo = 10 WHERE id = ?', [$eid]);
            $s1 = (int)valor('SELECT id FROM esc_sedes WHERE liga_id = ? ORDER BY id LIMIT 1', [$eid]);
            $s2 = (int)valor('SELECT id FROM esc_sedes WHERE liga_id = ? ORDER BY id DESC LIMIT 1', [$eid]) ?: $s1;
            $prof = [];
            foreach ([['Luis Toapanta', 'Boxeo'], ['Gabriela Chávez', 'Natación'], ['Jorge Almeida', 'Taekwondo'], ['Fernando Ayala', 'Tenis'], ['Ricardo Pazmiño', 'Básquet y vóley']] as [$n, $esp]) {
                q("INSERT INTO esc_profesores (liga_id, nombre, cedula, telefono, especialidad, tipo_pago, valor_pago) VALUES (?,?,?,?,?, 'clase', 15)", [$eid, $n, cedula_demo(), '099' . mt_rand(1000000, 9999999), $esp]);
                $prof[$esp] = (int)$pdo->lastInsertId();
            }
            // [nombre, deporte, desde, hasta (años de nacimiento), profesor, días, inicio, fin, lugar, mensualidad (null = la general)]
            $grupos = [
                ['Básquet Sub-13', 'basquet', $Y - 13, $Y - 11, $prof['Básquet y vóley'], '2,4', '16:00', '17:30', 'Coliseo', null],
                ['Vóley Sub-15', 'voley', $Y - 15, $Y - 13, $prof['Básquet y vóley'], '1,3', '17:30', '19:00', 'Coliseo', null],
                ['Boxeo formativo', 'boxeo', $Y - 17, $Y - 13, $prof['Boxeo'], '1,3,5', '18:00', '19:30', 'Gimnasio', 35.0],
                ['Natación', 'natacion', $Y - 13, $Y - 7, $prof['Natación'], '2,4,6', '15:00', '16:00', 'Piscina', 35.0],
                ['Taekwondo', 'taekwondo', $Y - 14, $Y - 6, $prof['Taekwondo'], '2,4', '17:00', '18:00', 'Dojang', null], // (v31)
                ['Tenis Sub-11', 'tenis', $Y - 11, $Y - 8, $prof['Tenis'], '1,3,5', '15:30', '16:30', 'Cancha de tenis', 40.0],
            ];
            $nom = ['Mateo', 'Santiago', 'Sebastián', 'Nicolás', 'Emilio', 'Joaquín', 'Thiago', 'Samuel', 'Daniel', 'Gael', 'Valentina', 'Camila', 'Isabella', 'Emilia', 'Luciana', 'Sofía', 'Dylan', 'Matías', 'Lucas', 'Renata', 'Doménica', 'Ariana'];
            $ape = ['Pérez', 'Guamán', 'Quishpe', 'Morales', 'Chiriboga', 'Vera', 'Mendoza', 'Zambrano', 'Cedeño', 'Paredes', 'Vaca', 'Cevallos', 'Ortiz', 'Tapia', 'Herrera', 'Castillo', 'Jácome', 'Lema'];
            $G = [];
            foreach ($grupos as [$n, $dep, $d, $h, $pr, $dias, $hi, $hf, $lugar, $mens]) {
                q("INSERT INTO esc_grupos (liga_id, sede_id, nombre, categoria, anio_desde, anio_hasta, profesor_id, dias, hora_inicio, hora_fin, cancha, cupo, deporte, mensualidad) VALUES (?,?,?, 'sub', ?,?,?,?,?,?,?, 16, ?, ?)",
                    [$eid, $dep === 'basquet' || $dep === 'voley' || $dep === 'boxeo' ? $s2 : $s1, $n, $d, $h, $pr, $dias, $hi, $hf, $lugar, $dep, $mens]);
                $gid = (int)$pdo->lastInsertId();
                preparar_deporte($eid, $dep);
                $al = [];
                for ($k = 0; $k < 7; $k++) {
                    $fem = in_array($dep, ['voley', 'natacion', 'tenis'], true) ? $k % 2 === 1 : $k === 5;
                    $n1 = $fem ? $nom[10 + mt_rand(0, 5)] : $nom[mt_rand(0, 9)];
                    if ($fem && $n1 === 'Emilia') $n1 = 'Renata';
                    $ap = $ape[mt_rand(0, count($ape) - 1)] . ' ' . $ape[mt_rand(0, count($ape) - 1)];
                    q("INSERT INTO esc_alumnos (liga_id, grupo_id, nombres, apellidos, cedula, fecha_nac, genero, colegio, representante_nombre, representante_cedula, representante_telefono, parentesco, tipo_sangre, emergencia_nombre, emergencia_telefono, fecha_ingreso, estado, consentimiento, consentimiento_por, foto_publica)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?, 'Madre', 'O+', ?,?,?, 'activo', ?, 'representante (demostración)', 0)", [
                        $eid, $gid, $n1, $ap, cedula_demo(), sprintf('%04d-%02d-%02d', mt_rand($d, $h), mt_rand(1, 12), mt_rand(1, 28)), $fem ? 'F' : 'M',
                        'Unidad Educativa ' . ['San Gabriel', 'Benalcázar', 'Mejía', 'La Salle'][mt_rand(0, 3)], 'María ' . explode(' ', $ap)[0], cedula_demo(), '09' . mt_rand(80000000, 99999999),
                        'María ' . explode(' ', $ap)[0], '09' . mt_rand(80000000, 99999999), date('Y-m-d', strtotime('-3 months')), date('Y-m-d H:i:s')]);
                    $al[] = ['id' => (int)$pdo->lastInsertId(), 'n' => $n1 . ' ' . $ap, 'g' => $fem ? 'F' : 'M'];
                }
                $G[$dep] = ['id' => $gid, 'al' => $al, 'prof' => $pr, 'dias' => array_map('intval', explode(',', $dias)), 'nombre' => $n];
            }

            // Los hermanos Andrade Proaño hacen un segundo deporte: Martín natación y Emilio taekwondo (dos mensualidades; v31)
            $desde = date('Y-m-01', strtotime('first day of -2 month'));
            foreach ([['Martín', 'natacion'], ['Emilio', 'taekwondo']] as [$n, $dep]) {
                $aid = (int)valor("SELECT id FROM esc_alumnos WHERE liga_id = ? AND nombres = ? AND apellidos = 'Andrade Proaño'", [$eid, $n]);
                if (!$aid) continue;
                q('INSERT OR IGNORE INTO esc_alumno_grupos (alumno_id, grupo_id, desde) VALUES (?,?,?)', [$aid, $G[$dep]['id'], $desde]);
                $G[$dep]['al'][] = ['id' => $aid, 'n' => $n . ' Andrade Proaño', 'g' => 'M', 'extra' => true];
            }

            // Clases de las últimas 6 semanas con ejercicios de su deporte y asistencia
            foreach ($G as $dep => $g) {
                $ejs = filas('SELECT id, nombre, tipo, duracion FROM esc_ejercicios WHERE liga_id = ? AND activo = 1 AND ' . sql_deporte([$dep]) . ' ORDER BY deporte DESC, id', [$eid]);
                $obj = ['basquet' => ['Bote con las dos manos', 'Tiro en bandeja', 'Defensa individual'], 'voley' => ['Recepción de antebrazo', 'Saque de abajo', 'Colocación'],
                        'boxeo' => ['Guardia y desplazamientos', 'Jab y recto', 'Esquivas y contragolpe'], 'natacion' => ['Técnica de crol', 'Virajes', 'Salida desde el bloque'],
                        'taekwondo' => ['Kibon: posiciones y patadas básicas', 'Poomsae Taegeuk Il Jang', 'Kyorugi (combate) controlado'], 'tenis' => ['Derecha cruzada', 'Revés a dos manos', 'Saque y devolución']][$dep];
                for ($d = 42; $d >= 1; $d--) {
                    $f = date('Y-m-d', strtotime("-$d day"));
                    if (!in_array((int)date('N', strtotime($f)), $g['dias'], true)) continue;
                    q("INSERT INTO esc_sesiones (grupo_id, fecha, profesor_id, profesor_asistio, objetivo, contenido, duracion, intensidad, visible_familia, estado, registrado_por) VALUES (?,?,?,1,?, '', 75, ?, 1, 'dictada', 'Demostración')",
                        [$g['id'], $f, $g['prof'], $obj[intdiv($d, 7) % 3], mt_rand(5, 8)]);
                    $sid = (int)$pdo->lastInsertId();
                    $sel = array_slice($ejs, ($d % 3), 4);
                    foreach ($sel as $i => $x) q("INSERT INTO esc_sesion_ejercicios (sesion_id, ejercicio_id, nombre, fase, minutos, notas, orden) VALUES (?,?,?,?,?, '', ?)",
                        [$sid, $x['id'], $x['nombre'], $i === 0 ? 'inicial' : ($i === count($sel) - 1 ? 'final' : 'principal'), (int)$x['duracion'] ?: 15, $i]);
                    foreach ($g['al'] as $a) q('INSERT OR IGNORE INTO esc_asistencia (sesion_id, alumno_id, estado) VALUES (?,?,?)', [$sid, $a['id'], mt_rand(1, 100) <= 90 ? 'P' : (mt_rand(0, 1) ? 'A' : 'J')]);
                }
            }

            $ins = fn(array $c) => q('INSERT INTO esc_competencias (liga_id, alumno_id, grupo_id, deporte, tipo, fecha, evento, lugar, rival, rival_club, categoria, peso, rounds, resultado, metodo, prueba, marca, valor, unidad, puesto, medalla, notas, registrado_por)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, \'Demostración\')', [$eid, $c['a'], $c['g'], $c['dep'], $c['tipo'], $c['fecha'], $c['evento'] ?? '', $c['lugar'] ?? '', $c['rival'] ?? '', $c['club'] ?? '',
                $c['cat'] ?? '', $c['peso'] ?? null, $c['rounds'] ?? 0, $c['res'] ?? '', $c['met'] ?? '', $c['prueba'] ?? '', $c['marca'] ?? '', $c['valor'] ?? null, $c['unidad'] ?? '', $c['puesto'] ?? null, $c['medalla'] ?? '', $c['notas'] ?? '']);
            $rivales = ['Kevin Loor', 'Bryan Tituaña', 'Jostin Caicedo', 'Alexis Pilco', 'Jhon Cuero', 'Anthony Lema', 'Steven Ñacato', 'Josué Villacís'];
            $clubes = ['Club Pichincha', 'Federación Deportiva de Pichincha', 'Club Los Tigres', 'Academia Sur', 'Liga Barrial Chillogallo'];

            // Boxeo: combates con peso, categoría, rounds y método
            foreach ($G['boxeo']['al'] as $i => $a) {
                $peso = 48 + mt_rand(0, 220) / 10;
                for ($c = 0; $c < 1 + ($i % 3); $c++) {
                    $res = $i === 0 ? 'gano' : ['gano', 'gano', 'perdio', 'empate'][mt_rand(0, 3)];
                    $ins(['a' => $a['id'], 'g' => $G['boxeo']['id'], 'dep' => 'boxeo', 'tipo' => 'combate', 'fecha' => date('Y-m-d', strtotime('-' . (10 + $c * 25 + $i) . ' day')),
                        'evento' => ['Torneo Guantes de Oro', 'Interclubes de Quito', 'Copa Juvenil Pichincha'][$c], 'lugar' => 'Coliseo Julio César Hidalgo', 'rival' => $rivales[($i + $c) % 8], 'club' => $clubes[($i + $c) % 5],
                        'cat' => categoria_para_peso($peso, $a['g']), 'peso' => round($peso, 1), 'rounds' => 3, 'res' => $res, 'met' => $res === 'gano' ? ['Puntos', 'Decisión unánime', 'TKO / RSC'][mt_rand(0, 2)] : ($res === 'empate' ? 'Puntos' : 'Decisión dividida')]);
                }
            }
            // (v31) Taekwondo: cinturones de la lista de la escuela, poomsae de cada alumno, un examen cerrado con certificados,
            // otro abierto con inscritos y algunos combates (kyorugi) con su categoría de World Taekwondo
            $tkd = $G['taekwondo'];
            $cin = cinturones_escuela($eid, 'taekwondo');
            $maestro = 'Sabonim Jorge Almeida, 4.º Dan';
            q("INSERT INTO esc_examenes (liga_id, disciplina, fecha, hora, lugar, examinador, valor, estado, notas, registrado_por) VALUES (?, 'taekwondo', ?, '09:00', 'Dojang de la escuela', ?, 15, 'cerrado', 'Traer el dobok limpio.', 'Demostración')",
                [$eid, date('Y-m-d', strtotime('-40 day')), $maestro]);
            $ex1 = (int)$pdo->lastInsertId();
            q("INSERT INTO esc_examenes (liga_id, disciplina, fecha, hora, lugar, examinador, valor, estado, notas, registrado_por) VALUES (?, 'taekwondo', ?, '09:00', 'Dojang de la escuela', ?, 15, 'abierto', 'Traer el dobok limpio y el carnet.', 'Demostración')",
                [$eid, date('Y-m-d', strtotime('+12 day')), $maestro]);
            $ex2 = (int)$pdo->lastInsertId();
            foreach ($tkd['al'] as $i => $a) {
                $hasta = 1 + ($i % 4);                    // cinturón actual: posición 1..4 de la lista (blanco punta amarilla … verde)
                for ($k = 0; $k <= $hasta; $k++) {
                    $c = $cin[$k];
                    $del_examen = $k === $hasta && $i % 2 === 0; // los pares subieron en el examen de hace 40 días
                    $f = $del_examen ? date('Y-m-d', strtotime('-40 day')) : date('Y-m-d', strtotime('-' . (($hasta - $k) * 120 + 60 + $i) . ' day'));
                    if ($del_examen) {
                        $ant = $cin[$k - 1];
                        q("INSERT INTO esc_examen_inscritos (examen_id, alumno_id, grado_actual, grado_aspira, cinturon_id, forma, forma_ok, nota, resultado, certificado) VALUES (?,?,?,?,?,?,1,?, 'aprobado', ?)",
                            [$ex1, $a['id'], etiqueta_cinturon($ant), etiqueta_cinturon($c), $c['id'], $c['forma'], (string)(8 + $i % 3), nuevo_codigo_certificado()]);
                    }
                    q("INSERT INTO esc_grados (alumno_id, fecha, grado, examinador, notas, registrado_por, cinturon_id, examen_id) VALUES (?,?,?,?,?, 'Demostración', ?, ?)",
                        [$a['id'], $f, etiqueta_cinturon($c), $maestro, $del_examen ? 'Examen de grado del ' . fecha_corta($f) : '', $c['id'], $del_examen ? $ex1 : null]);
                    if ($c['forma'] !== '' && $k >= 1) q("INSERT OR IGNORE INTO esc_formas_alumno (alumno_id, forma, estado, fecha, registrado_por) VALUES (?,?, 'domina', ?, 'Demostración')", [$a['id'], $c['forma'], $f]);
                }
                // Poomsae del próximo cinturón: la mitad lo está aprendiendo y dos ya lo dominan
                $sig = $cin[$hasta + 1];
                if ($sig['forma'] !== '' && $i % 3 !== 2) q("INSERT OR IGNORE INTO esc_formas_alumno (alumno_id, forma, estado, fecha, registrado_por) VALUES (?,?,?,?, 'Demostración')",
                    [$a['id'], $sig['forma'], $i < 2 ? 'domina' : 'aprendiendo', date('Y-m-d', strtotime('-' . (5 + $i) . ' day'))]);
                // Examen abierto: inscritos los 4 primeros, con el próximo cinturón
                if ($i < 4) q("INSERT INTO esc_examen_inscritos (examen_id, alumno_id, grado_actual, grado_aspira, cinturon_id, forma, forma_ok) VALUES (?,?,?,?,?,?,?)",
                    [$ex2, $a['id'], etiqueta_cinturon($cin[$hasta]), etiqueta_cinturon($sig), $sig['id'], $sig['forma'], $i < 2 ? 1 : 0]);
                // Una medición de peso (para sugerir la categoría) y combates de kyorugi
                $peso = 26 + $i * 4 + mt_rand(0, 30) / 10;
                $nac = valor('SELECT fecha_nac FROM esc_alumnos WHERE id = ?', [$a['id']]);
                q("INSERT INTO esc_mediciones (alumno_id, fecha, peso, talla, registrado_por) VALUES (?,?,?,?, 'Demostración')", [$a['id'], date('Y-m-d', strtotime('-20 day')), round($peso, 1), 128 + $i * 5]);
                if ($i < 4) $ins(['a' => $a['id'], 'g' => $tkd['id'], 'dep' => 'taekwondo', 'tipo' => 'combate', 'fecha' => date('Y-m-d', strtotime('-' . (15 + $i) . ' day')),
                    'evento' => 'Open de Taekwondo Quito', 'lugar' => 'Coliseo de la Concentración Deportiva', 'rival' => $rivales[$i + 2], 'club' => $clubes[$i % 5],
                    'cat' => categoria_marcial(round($peso, 1), 'taekwondo', $a['g'], $nac), 'peso' => round($peso, 1), 'rounds' => 3,
                    'res' => $i % 3 ? 'gano' : 'perdio', 'met' => $i % 3 ? 'Puntos' : 'Superioridad']);
            }
            // Natación: pruebas con tiempo, puesto y medalla (Martín gana el oro en 50 m libre)
            foreach ($G['natacion']['al'] as $i => $a) {
                foreach ([['50 m libre', 38], ['25 m espalda', 22], ['50 m pecho', 50]] as $j => [$pr, $base]) {
                    if ($j === 2 && $i % 2) continue;
                    $seg = $base + mt_rand(0, 900) / 100 + ($i === 0 ? 2 : 0);
                    $puesto = !empty($a['extra']) && $j === 0 ? 1 : 1 + (($i + $j) % 6);
                    if (!empty($a['extra']) && $j === 0) $seg = $base - 1.2;
                    $marca = $seg >= 60 ? sprintf('%d:%05.2f', intdiv((int)$seg, 60), fmod($seg, 60)) : sprintf('%.2f', $seg);
                    foreach ([[40, $seg + 1.4], [8, $seg]] as [$dias, $s]) {
                        $m = $s >= 60 ? sprintf('%d:%05.2f', intdiv((int)$s, 60), fmod($s, 60)) : sprintf('%.2f', $s);
                        $p = $dias === 8 ? $puesto : min(8, $puesto + 1);
                        $ins(['a' => $a['id'], 'g' => $G['natacion']['id'], 'dep' => 'natacion', 'tipo' => 'prueba', 'fecha' => date('Y-m-d', strtotime("-$dias day")),
                            'evento' => $dias === 8 ? 'Campeonato Provincial Infantil' : 'Festival de Natación Interescuelas', 'lugar' => 'Piscina Olímpica Batán', 'prueba' => $pr,
                            'marca' => $m, 'valor' => marca_a_valor($m), 'unidad' => 's', 'puesto' => $p, 'medalla' => medalla_de_puesto($p)]);
                    }
                }
            }
            // Tenis: partidos individuales por sets
            foreach ($G['tenis']['al'] as $i => $a) {
                foreach ([0, 1] as $c) {
                    $gano = ($i + $c) % 3 !== 2 || !empty($a['extra']);
                    $ins(['a' => $a['id'], 'g' => $G['tenis']['id'], 'dep' => 'tenis', 'tipo' => 'combate', 'fecha' => date('Y-m-d', strtotime('-' . (6 + $c * 20 + $i) . ' day')),
                        'evento' => $c ? 'Circuito Infantil Sub-12' : 'Torneo Interescuelas de Tenis', 'lugar' => 'Club de Tenis Quito', 'rival' => $rivales[($i + $c + 3) % 8], 'club' => $clubes[($i + 1) % 5],
                        'cat' => 'Sub-12', 'res' => $gano ? 'gano' : 'perdio', 'met' => 'Sets', 'marca' => $gano ? '6-3, 6-4' : '4-6, 5-7']);
                }
            }
            // Básquet: un partido jugado con puntos, rebotes y faltas
            $jb = $G['basquet']['al'];
            q("INSERT INTO esc_partidos (liga_id, grupo_id, fecha, hora, rival, competencia, condicion, lugar, goles_favor, goles_contra, duracion, notas, registrado_por) VALUES (?,?,?, '10:00', 'Colegio Benalcázar', 'Liga Intercolegial Sub-13', 'local', 'Coliseo', 48, 41, 40, 'Buen trabajo defensivo en el último cuarto.', 'Ricardo Pazmiño')",
                [$eid, $G['basquet']['id'], date('Y-m-d', strtotime('-5 day'))]);
            $pid = (int)$pdo->lastInsertId();
            $pts = [16, 11, 8, 6, 4, 3, 0];
            foreach ($jb as $i => $a) q('INSERT INTO esc_partido_jugadores (partido_id, alumno_id, convocado, titular, minutos, goles, asistencias, rebotes, faltas, calificacion) VALUES (?,?,1,?,?,?,?,?,?,?)',
                [$pid, $a['id'], $i < 5 ? 1 : 0, $i < 5 ? 28 + mt_rand(0, 10) : 8 + mt_rand(0, 6), $pts[$i] ?? 0, mt_rand(0, 4), mt_rand(1, 9), mt_rand(0, 3), 6 + mt_rand(0, 30) / 10]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        // Mensualidades de los meses que faltan (los nuevos grupos y los segundos deportes)
        $liga = fila('SELECT * FROM ligas WHERE id = ?', [$eid]);
        foreach ([2, 1, 0] as $m) generar_mensualidades($liga, date('Y-m', strtotime("first day of -$m month")));
        foreach (filas("SELECT id, vence FROM esc_cargos WHERE liga_id = ? AND estado = 'pendiente' AND vence < ? AND (grupo_id IS NOT NULL OR alumno_id IN (SELECT id FROM esc_alumnos WHERE liga_id = ? AND grupo_id IN (SELECT id FROM esc_grupos WHERE deporte <> '')))", [$eid, date('Y-m-01'), $eid]) as $c) {
            cobrar_cargos($liga, [(int)$c['id']], 'transferencia', 'Demostración', $c['vence']);
        }
    });
}
