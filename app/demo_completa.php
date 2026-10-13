<?php
/*
 * VERSIÓN 15 — DEMOS COMPLETAS PARA PRESENTAR A LOS CLIENTES
 * Liga de demostración: se entra con un clic como Administrador, Árbitro, Delegado, Vocal (vocalía en vivo) o Jugador (carnet digital).
 * Escuela de demostración: Dueño (administrador), Entrenador (profesor), Familia (portal de padres) y carnet del alumno.
 * Las dos se borran y se vuelven a crear solas cada madrugada. Con "demo_guardar" = 1 se puede probar y guardar
 * (vocalía, resultados, asistencia, cobros…) y al día siguiente todo vuelve a estar como nuevo.
 */

const DEMO_CLAVE = '1234'; // clave de los portales de jugador y familia en la demo (se muestra en la página "Ver demo")

function escuela_demo(): ?array
{
    return fila("SELECT * FROM ligas WHERE es_demo = 1 AND activa = 1 AND tipo = 'escuela' ORDER BY id DESC LIMIT 1");
}

/** ¿Se puede guardar en la demo? (se borra cada madrugada) */
function demo_guardar(): bool
{
    return (int)ajuste('demo_guardar', 1) === 1;
}

/** Cédula ecuatoriana válida (de Pichincha) para los datos de ejemplo. */
function cedula_demo(): string
{
    do {
        $d = '17' . random_int(0, 5) . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $s = 0;
        for ($i = 0; $i < 9; $i++) {
            $x = (int)$d[$i] * ($i % 2 === 0 ? 2 : 1);
            $s += $x > 9 ? $x - 9 : $x;
        }
        $c = $d . ((10 - $s % 10) % 10);
    } while (fila('SELECT id FROM jugadores WHERE cedula = ?', [$c]) || fila('SELECT id FROM esc_alumnos WHERE cedula = ? OR representante_cedula = ?', [$c, $c]));
    return $c;
}

/** Crea (o recrea) un usuario de la demo con una clave al azar: se entra con el botón, no con la clave. */
function usuario_demo(string $email, string $nombre, string $rol, ?int $liga_id, array $extra = []): int
{
    q('DELETE FROM usuarios WHERE email = ?', [$email]);
    $d = ['nombre' => $nombre, 'email' => $email, 'clave' => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT), 'rol' => $rol, 'liga_id' => $liga_id] + $extra;
    q('INSERT INTO usuarios (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_fill(0, count($d), '?')) . ')', array_values($d));
    return (int)db()->lastInsertId();
}

/* ============================================================
 * LIGA: portales de árbitro, delegado, vocal y jugador
 * ============================================================ */

/** Completa la liga de demostración con cédulas, accesos a todos los portales y un partido para la vocalía de hoy. */
function completar_demo_liga(int $lid): void
{
    sin_auditoria(function () use ($lid): void {
        // Cédulas válidas para todos (salen en la hoja de vocalía y en el carnet)
        foreach (filas('SELECT j.id FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ?', [$lid]) as $j) {
            q('UPDATE jugadores SET cedula = ? WHERE id = ?', [cedula_demo(), $j['id']]);
        }
        q("UPDATE usuarios SET perfil = 'admin' WHERE liga_id = ? AND rol = 'liga'", [$lid]);
        // Árbitro con su portal
        $arb = fila('SELECT * FROM arbitros WHERE liga_id = ? ORDER BY id LIMIT 1', [$lid]);
        if ($arb) q('UPDATE arbitros SET usuario_id = ? WHERE id = ?', [usuario_demo('arbitro@demo.tuacademia.tukanec.com', $arb['nombre'], 'arbitro', $lid), $arb['id']]);
        // Delegado del primer equipo
        $eq = fila('SELECT * FROM equipos WHERE liga_id = ? ORDER BY id LIMIT 1', [$lid]);
        if ($eq) usuario_demo('delegado@demo.tuacademia.tukanec.com', $eq['delegado'] ?: 'Delegado de la demo', 'delegado', $lid, ['equipo_id' => $eq['id']]);
        // Jugador con su portal (carnet digital)
        $jug = $eq ? fila("SELECT * FROM jugadores WHERE equipo_id = ? AND posicion <> 'Arquero' ORDER BY numero LIMIT 1 OFFSET 7", [$eq['id']]) : null;
        if ($jug) q('UPDATE jugadores SET portal_pin = ? WHERE id = ?', [password_hash(DEMO_CLAVE, PASSWORD_DEFAULT), $jug['id']]);
        // Un partido de HOY, listo para abrir la vocalía en vivo, con el árbitro de la demo
        $tc = fila("SELECT id FROM torneos WHERE liga_id = ? AND estado = 'en_juego' ORDER BY id DESC LIMIT 1", [$lid]);
        if ($tc) {
            $p = fila("SELECT * FROM partidos WHERE torneo_id = ? AND estado = 'programado' ORDER BY jornada, id LIMIT 1", [$tc['id']]);
            if ($p) {
                q("UPDATE partidos SET fecha = ?, hora = ?, vocal_token = ?, arbitro_id = ? WHERE id = ?", [date('Y-m-d'), date('H', strtotime('+1 hour')) . ':00', nuevo_token(), $arb['id'] ?? $p['arbitro_id'], $p['id']]);
                // Que el jugador de la demo juegue ese partido (así lo ve en "próximos partidos")
                if ($eq && $p['local_id'] != $eq['id'] && $p['visitante_id'] != $eq['id']) q('UPDATE partidos SET local_id = ? WHERE id = ? AND visitante_id <> ?', [$eq['id'], $p['id'], $eq['id']]);
            }
        }
    });
}

/** Accesos de la liga de demostración: [clave => [texto, descripción, emoji]]. */
function accesos_demo_liga(): array
{
    return [
        'admin' => ['Administrador de la liga', 'Equipos, campeonatos, fixture, resultados, disciplina, finanzas, redes y más.', '🖥'],
        'arbitro' => ['Árbitro', 'Sus partidos designados, el informe arbitral y sus honorarios.', '🟨'],
        'delegado' => ['Delegado de equipo', 'Su plantilla, inscripción de jugadores, pedidos, estado de cuenta y pagos.', '📋'],
        'vocal' => ['Vocal (vocalía en vivo)', 'Registrar presentes, goles y tarjetas desde el celular, con el reloj del partido.', '⏱'],
        'jugador' => ['Jugador', 'Su carnet digital con QR, sus partidos y sus estadísticas.', '🪪'],
        'federacion' => ['Federación', 'Varias ligas y escuelas bajo un mismo paraguas: resumen, partidos de la semana, sanciones federativas y campeonatos inter-ligas.', '🏛'],
    ];
}

/* ============================================================
 * ESCUELA DE DEMOSTRACIÓN
 * ============================================================ */

function accesos_demo_escuela(): array
{
    if (PRODUCTO === 'academia') return [ // (TuAcademia)
        'dueno' => ['Dueña de la academia', 'Cursos, alumnos, pensiones, Mensajes de hoy, niveles, recitales, clases particulares y profesores.', '🏫'],
        'profesor' => ['Profesora', 'Toma asistencia, plan de clase y evaluaciones de sus cursos.', '🧑‍🏫'],
        'familia' => ['Familia (padres)', 'Asistencia, pagos por DeUna, recitales, niveles y clases de sus hijas.', '👨‍👩‍👧'],
        'carnet' => ['Carnet del alumno', 'Lo que ve quien escanea el QR del carnet.', '🪪'],
    ];
    return [
        'dueno' => ['Dueño de la escuela', 'Alumnos, grupos, asistencia, pagos, profesores, evaluaciones, tienda y reportes.', '🏫'],
        'profesor' => ['Entrenador (profesor)', 'Toma asistencia, plan de clase, medidas, tests y evaluaciones de sus grupos.', '🧑‍🏫'],
        'familia' => ['Familia (padres)', 'Asistencia, pagos, evaluaciones, libreta y carnet de sus hijos.', '👨‍👩‍👧'],
        'carnet' => ['Carnet del alumno', 'Lo que ve quien escanea el QR del carnet.', '🪪'],
    ];
}

/** Borra la escuela de demostración anterior y crea una nueva, llena de datos. */
function crear_escuela_demo(): int
{
    return sin_auditoria(function (): int {
        q("DELETE FROM ligas WHERE es_demo = 1 AND tipo = 'escuela'");
        q("DELETE FROM usuarios WHERE email IN ('escuela@demo.tuacademia.tukanec.com', 'profesor@demo.tuacademia.tukanec.com', 'staff@demo.tuacademia.tukanec.com')");
        $r = crear_liga_completa([
            'nombre' => 'Escuela Demo ' . marca(), 'slug' => 'escuela-demo', 'ciudad' => 'Quito', 'color' => '#1565c0', 'modalidad' => 'F7',
            'tipo' => 'escuela', 'deporte_escuela' => 'futbol',
            'admin_nombre' => 'Dueño de la escuela', 'admin_email' => 'escuela@demo.tuacademia.tukanec.com', 'admin_clave' => bin2hex(random_bytes(12)),
        ]);
        $lid = (int)$r['liga_id'];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            q("UPDATE ligas SET es_demo = 1, vence = '', es_prueba = 0, color2 = '#f2b705', contacto_nombre = 'QF Producciones', contacto_telefono = '0990000000',
               esc_mensualidad = 30, esc_matricula = 25, esc_dia_vence = 5, esc_desc_hermano = 10, esc_inscripcion = 1,
               esc_texto_inscripcion = 'Entrenamientos de lunes a sábado. Traer uniforme, canilleras y agua.', datos_pago = 'Banco de ejemplo · Cuenta de ahorros 0000000000 · A nombre de Escuela Demo'
               WHERE id = ?", [$lid]);
            q("UPDATE usuarios SET perfil = 'admin' WHERE id = ?", [$r['usuario_id']]);
            $liga = fila('SELECT * FROM ligas WHERE id = ?', [$lid]);
            preparar_escuela($lid);
            $s1 = (int)valor('SELECT id FROM esc_sedes WHERE liga_id = ? ORDER BY id LIMIT 1', [$lid]);
            q("UPDATE esc_sedes SET nombre = 'Sede Centro', direccion = 'Parque La Carolina' WHERE id = ?", [$s1]);
            q("INSERT INTO esc_sedes (liga_id, nombre, direccion) VALUES (?, 'Sede Norte', 'Complejo deportivo Carcelén')", [$lid]);
            $s2 = (int)$pdo->lastInsertId();
            // Profesores (uno con acceso de entrenador)
            $prof = [];
            foreach ([['Andrea Ruiz', 'clase', 15, 'Formativas'], ['Carlos Mena', 'mensual', 350, 'Director técnico'], ['Diego Salazar', 'alumno', 6, 'Preparador físico']] as [$n, $tp, $v, $esp]) {
                q("INSERT INTO esc_profesores (liga_id, nombre, cedula, telefono, especialidad, tipo_pago, valor_pago) VALUES (?,?,?,?,?,?,?)", [$lid, $n, cedula_demo(), '099' . random_int(1000000, 9999999), $esp, $tp, $v]);
                $prof[] = (int)$pdo->lastInsertId();
            }
            $uprof = usuario_demo('profesor@demo.tuacademia.tukanec.com', 'Andrea Ruiz', 'liga', $lid, ['perfil' => 'profesor']);
            q('UPDATE esc_profesores SET usuario_id = ? WHERE id = ?', [$uprof, $prof[0]]);
            // Grupos
            $Y = (int)date('Y');
            $grupos = [
                ['Sub-7 mañana', 'sub', $Y - 7, $Y - 6, $prof[0], '1,3,5', '09:00', '10:00', $s1],
                ['Sub-9', 'sub', $Y - 9, $Y - 8, $prof[0], '2,4,6', '16:00', '17:30', $s1],
                ['Sub-11', 'sub', $Y - 11, $Y - 10, $prof[1], '1,3,5', '16:00', '17:30', $s1],
                ['Sub-13', 'sub', $Y - 13, $Y - 12, $prof[1], '2,4', '17:30', '19:00', $s2],
                ['Sub-15', 'sub', $Y - 15, $Y - 14, $prof[2], '1,3,5', '17:30', '19:00', $s2],
                ['Abierta sábados', 'abierta', null, null, $prof[2], '6', '08:00', '10:00', $s2],
            ];
            $gids = [];
            foreach ($grupos as [$n, $cat, $d, $h, $pr, $dias, $hi, $hf, $sede]) {
                q('INSERT INTO esc_grupos (liga_id, sede_id, nombre, categoria, anio_desde, anio_hasta, profesor_id, dias, hora_inicio, hora_fin, cancha, cupo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$lid, $sede, $n, $cat, $d, $h, $pr, $dias, $hi, $hf, 'Cancha ' . (count($gids) % 2 + 1), 20]);
                $gids[] = ['id' => (int)$pdo->lastInsertId(), 'd' => $d, 'h' => $h, 'dias' => $dias];
            }
            // Alumnos (10 por grupo), una familia con dos hijos para el portal de padres
            $nom = ['Mateo', 'Santiago', 'Sebastián', 'Nicolás', 'Emilio', 'Martín', 'Joaquín', 'Thiago', 'Samuel', 'Benjamín', 'Daniel', 'Gael', 'Valentina', 'Camila', 'Isabella', 'Emilia', 'Luciana', 'Sofía', 'Dylan', 'Matías', 'Lucas', 'Julián', 'Alejandro', 'Leonardo'];
            $ape = ['Pérez', 'Guamán', 'Quishpe', 'Morales', 'Chiriboga', 'Vera', 'Mendoza', 'Zambrano', 'Cedeño', 'Andrade', 'Paredes', 'Vaca', 'Salazar', 'Cevallos', 'Ortiz', 'Tapia', 'Herrera', 'Castillo', 'Ruiz', 'Proaño'];
            $madres = ['María', 'Patricia', 'Verónica', 'Gabriela', 'Diana', 'Carolina', 'Paola', 'Andrea', 'Lorena', 'Mónica'];
            $sangre = ['O+', 'O+', 'O+', 'A+', 'B+', 'O-', 'A-'];
            $fam_ced = cedula_demo();
            $alumnos = [];
            foreach ($gids as $gi => $g) {
                for ($k = 0; $k < 10; $k++) {
                    $anio = $g['d'] ? ($k < 8 ? (int)$g['d'] : (int)$g['h']) : random_int($Y - 30, $Y - 17); // (v17) casi todos del año de la categoría: así el semáforo compara con suficientes compañeros
                    $ap = $ape[random_int(0, count($ape) - 1)] . ' ' . $ape[random_int(0, count($ape) - 1)];
                    $esFam = ($gi === 1 && $k === 0) || ($gi === 3 && $k === 0);
                    if ($esFam) $ap = 'Andrade Proaño';
                    $rep = $esFam ? 'Patricia Proaño' : $madres[random_int(0, 9)] . ' ' . explode(' ', $ap)[1];
                    q("INSERT INTO esc_alumnos (liga_id, grupo_id, nombres, apellidos, cedula, fecha_nac, genero, posicion, pie, colegio, representante_nombre, representante_cedula, representante_telefono, representante_email, parentesco,
                       tipo_sangre, alergias, emergencia_nombre, emergencia_telefono, beca, fecha_ingreso, estado, consentimiento, consentimiento_por, foto_publica)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'Madre', ?,?,?,?,?,?, 'activo', ?, 'representante (demostración)', 0)", [
                        $lid, $g['id'], $esFam ? ($gi === 1 ? 'Emilio' : 'Martín') : $nom[random_int(0, count($nom) - 1)], $ap, cedula_demo(),
                        sprintf('%04d-%02d-%02d', $anio, random_int(1, 12), random_int(1, 28)), 'M', ['Arquero', 'Defensa', 'Mediocampista', 'Delantero'][random_int(0, 3)], random_int(0, 4) ? 'Derecho' : 'Zurdo',
                        'Unidad Educativa ' . ['San Gabriel', 'Benalcázar', 'Mejía', 'La Salle'][random_int(0, 3)], $rep, $esFam ? $fam_ced : cedula_demo(), '09' . random_int(80000000, 99999999),
                        $esFam ? 'patricia@ejemplo.com' : '', $sangre[random_int(0, 6)], random_int(0, 8) ? '' : 'Penicilina', $rep, '09' . random_int(80000000, 99999999),
                        ($gi === 2 && $k === 1) ? 50 : 0, date('Y-m-d', strtotime('-4 months')), date('Y-m-d H:i:s'),
                    ]);
                    $alumnos[] = ['id' => (int)$pdo->lastInsertId(), 'g' => $g['id'], 'fam' => $esFam];
                }
            }
            // Portal de la familia: clave 1234
            q('INSERT INTO esc_familias (liga_id, cedula, pin, ultimo) VALUES (?,?,?,?)', [$lid, $fam_ced, password_hash(DEMO_CLAVE, PASSWORD_DEFAULT), date('Y-m-d H:i')]);
            // (v17) Las clases, la asistencia y las evaluaciones las crea demo_v17_escuela() más abajo
            $pdo->commit(); // los cobros abren su propia transacción
            // Mensualidades de los últimos 3 meses: las anteriores casi todas pagadas; algunas vencidas
            $liga = fila('SELECT * FROM ligas WHERE id = ?', [$lid]);
            foreach ([2, 1, 0] as $m) generar_mensualidades($liga, date('Y-m', strtotime("first day of -$m month")));
            $cargos = filas("SELECT c.* FROM esc_cargos c WHERE c.liga_id = ? AND c.estado = 'pendiente' ORDER BY c.periodo, c.id", [$lid]);
            $hoy = date('Y-m-d');
            $por_recibo = [];
            foreach ($cargos as $c) {
                $vencido = $c['vence'] < $hoy;
                $pagar = $vencido ? random_int(1, 100) <= 88 : random_int(1, 100) <= 45;
                if ($pagar) $por_recibo[] = [(int)$c['id'], $vencido ? $c['vence'] : $hoy];
            }
            foreach ($por_recibo as [$cid, $f]) cobrar_cargos($liga, [$cid], random_int(0, 2) ? 'transferencia' : 'efectivo', 'Demostración', $f);
            // Matrícula y uniforme de algunos alumnos nuevos
            foreach (array_slice($alumnos, 0, 4) as $a) {
                $c1 = crear_cargo_escuela($liga, $a['id'], 'matricula', 'Matrícula', 25, date('Y-m-d', strtotime('-3 months')), 'Demostración');
                cobrar_cargos($liga, [$c1], 'efectivo', 'Demostración', date('Y-m-d', strtotime('-3 months')));
            }
            crear_cargo_escuela($liga, $alumnos[7]['id'], 'uniforme', 'Uniforme de juego', 35, date('Y-m-d', strtotime('+3 days')), 'Demostración');
            $pdo->beginTransaction();
            // Libreta del trimestre y una lesión en curso
            foreach ($alumnos as $a) if ($a['fam']) q("INSERT OR IGNORE INTO esc_libretas (alumno_id, periodo, comentario, registrado_por) VALUES (?,?,?, 'Carlos Mena')",
                [$a['id'], periodo_libreta(), 'Excelente trimestre: asistencia constante, buen compañerismo y gran avance técnico. ¡Felicitaciones!']);
            q("INSERT INTO esc_lesiones (alumno_id, fecha, zona, tipo, descripcion, dias_baja, registrado_por) VALUES (?,?, 'Tobillo derecho', 'Esguince leve', 'Torcedura en el juego reducido; hielo y reposo.', 10, 'Andrea Ruiz')",
                [$alumnos[15]['id'], date('Y-m-d', strtotime('-4 days'))]);
            // Tienda
            foreach ([['Uniforme de juego completo', 'Talla 10', 35, 18], ['Balón N° 4', '', 18, 9], ['Medias oficiales', 'Única', 6, 30], ['Canilleras', 'S', 8, 2]] as [$n, $t, $pr, $st]) {
                q('INSERT INTO esc_productos (liga_id, nombre, talla, precio, stock) VALUES (?,?,?,?,?)', [$lid, $n, $t, $pr, $st]);
            }
            $prod = (int)valor('SELECT id FROM esc_productos WHERE liga_id = ? ORDER BY id LIMIT 1', [$lid]);
            q("INSERT INTO esc_ventas (liga_id, producto_id, alumno_id, cantidad, precio, total, modo, forma_pago, fecha, registrado_por) VALUES (?,?,?,1,35,35,'contado','efectivo',?, 'Demostración')", [$lid, $prod, $alumnos[3]['id'], date('Y-m-d', strtotime('-9 days'))]);
            // Pago al entrenador del mes pasado
            q("INSERT INTO esc_pagos_profesor (liga_id, profesor_id, periodo, clases, alumnos, monto, fecha, forma_pago, nota, registrado_por) VALUES (?,?,?,?,?,350,?, 'transferencia', 'Sueldo', 'Demostración')",
                [$lid, $prof[1], date('Y-m', strtotime('first day of -1 month')), 20, 14, date('Y-m-05')]);
            // (v17) 5 meses de entrenamientos, jornadas de evaluación, metas y divisiones formativas
            demo_v17_escuela($lid, $gids, $alumnos, $prof);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $lid;
    });
}

/** Crea o recrea las dos demos (liga y escuela). */
function crear_demos(): void
{
    if (PRODUCTO === 'academia') { // (TuAcademia) solo la academia de demostración (sin liga, federación, entradas ni olimpiadas)
        crear_academia_demo();
        guardar_ajuste('demo_creada', date('Y-m-d H:i'));
        return;
    }
    $lid = crear_liga_demo();
    completar_demo_liga($lid);
    $eid = crear_escuela_demo();
    demo_v18($lid, $eid); // (v18) cumpleañeros, reglamento y contabilidad con facturas del SRI
    demo_multideporte($eid); // (v21) grupos de otros deportes: básquet, vóley, boxeo, natación, taekwondo y tenis
    demo_carreras($lid); // (v23) autocross y ciclismo
    demo_individuales($lid); // (v25) tenis, pádel, tenis de mesa, karate, boxeo y bolos
    crear_federacion_demo($lid, $eid); // (v28) federación con la liga y la escuela afiliadas
    crear_videos_demo($lid, $eid); // (v28) videos de ejemplo en el portal del jugador y de los padres
    crear_entradas_demo($lid); // (v28) venta de entradas con QR
    sin_auditoria(fn() => olimp_cargar_ejemplo(fila('SELECT * FROM ligas WHERE id = ?', [$lid]))); // (v37) olimpiadas interempresas
    guardar_ajuste('demo_creada', date('Y-m-d H:i'));
}

/**
 * Entra a un portal de la demo con un clic. Devuelve la dirección a la que hay que ir.
 * Solo funciona con los usuarios de las demos (nunca con cuentas reales).
 */
function entrar_demo(string $rol): string
{
    $liga = liga_demo();
    $esc = escuela_demo();
    $sesion = function (?array $u): void {
        if (!$u) throw new RuntimeException('La demostración no está lista. Vuelva a intentarlo en un momento.');
        session_regenerate_id(true);
        unset($_SESSION['2fa_uid'], $_SESSION['2fa_hora'], $_SESSION['liga_trabajo'], $_SESSION['jugador_portal'], $_SESSION['familia']);
        $_SESSION['uid'] = (int)$u['id'];
    };
    switch ($rol) {
        case 'admin':
            $sesion($liga ? fila("SELECT * FROM usuarios WHERE liga_id = ? AND rol = 'liga' AND activo = 1 AND email = 'demo@demo.tuacademia.tukanec.com'", [$liga['id']]) : null);
            return 'admin.php';
        case 'arbitro':
            $sesion($liga ? fila("SELECT * FROM usuarios WHERE email = 'arbitro@demo.tuacademia.tukanec.com' AND liga_id = ?", [$liga['id']]) : null);
            return 'arbitro.php';
        case 'delegado':
            $sesion($liga ? fila("SELECT * FROM usuarios WHERE email = 'delegado@demo.tuacademia.tukanec.com' AND liga_id = ?", [$liga['id']]) : null);
            return 'delegado.php';
        case 'vocal':
            $p = $liga ? fila("SELECT p.vocal_token FROM partidos p JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ? AND p.vocal_token <> '' AND p.estado IN ('programado','en_vivo') ORDER BY p.fecha, p.hora LIMIT 1", [$liga['id']]) : null;
            if (!$p) throw new RuntimeException('No hay partido de demostración para la vocalía.');
            return 'vocal.php?k=' . $p['vocal_token'];
        case 'jugador':
            $j = $liga ? fila("SELECT j.id FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ? AND j.portal_pin <> '' LIMIT 1", [$liga['id']]) : null;
            if (!$j) throw new RuntimeException('La demostración no está lista.');
            unset($_SESSION['uid']);
            session_regenerate_id(true);
            $_SESSION['jugador_portal'] = (int)$j['id'];
            return 'mi.php';
        case 'federacion': // (v28)
            $fd = fila('SELECT * FROM ligas WHERE es_demo = 1 AND es_federacion = 1 AND activa = 1 ORDER BY id DESC LIMIT 1');
            $sesion($fd ? fila("SELECT * FROM usuarios WHERE email = 'federacion@demo.tuacademia.tukanec.com' AND liga_id = ?", [$fd['id']]) : null);
            return 'admin.php?p=federacion';
        case 'dueno':
            $sesion($esc ? fila("SELECT * FROM usuarios WHERE email = 'escuela@demo.tuacademia.tukanec.com' AND liga_id = ?", [$esc['id']]) : null);
            return 'admin.php';
        case 'profesor':
            $sesion($esc ? fila("SELECT * FROM usuarios WHERE email = 'profesor@demo.tuacademia.tukanec.com' AND liga_id = ?", [$esc['id']]) : null);
            return 'admin.php';
        case 'familia':
            $f = $esc ? fila('SELECT id FROM esc_familias WHERE liga_id = ? LIMIT 1', [$esc['id']]) : null;
            if (!$f) throw new RuntimeException('La demostración no está lista.');
            unset($_SESSION['uid']);
            session_regenerate_id(true);
            $_SESSION['familia'] = (int)$f['id'];
            return 'familia.php';
        case 'carnet':
            $a = $esc ? fila("SELECT * FROM esc_alumnos WHERE liga_id = ? AND apellidos = 'Andrade Proaño' ORDER BY id LIMIT 1", [$esc['id']]) : null;
            if (!$a) throw new RuntimeException('La demostración no está lista.');
            return 'carnet.php?a=' . carnet_alumno($a);
    }
    throw new RuntimeException('Opción no válida.');
}
