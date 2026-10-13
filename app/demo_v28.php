<?php
/*
 * (v28) Datos de la demostración para lo nuevo de la versión 28:
 *  - Federación Deportiva Demo, con la liga y la escuela de la demo afiliadas, un campeonato federativo y una sanción federativa.
 */

/** Crea la federación de la demo (se borra y se vuelve a crear con las demás demos). */
function crear_federacion_demo(int $lid, int $eid): ?int
{
    return sin_auditoria(function () use ($lid, $eid): ?int {
        q("DELETE FROM usuarios WHERE email = 'federacion@demo.tuvocalia.com'");
        q('DELETE FROM ligas WHERE es_demo = 1 AND es_federacion = 1');
        $r = crear_liga_completa([
            'nombre' => 'Federación Deportiva Demo', 'slug' => 'federacion-demo', 'ciudad' => 'Quito', 'color' => '#6a1b9a', 'modalidad' => 'F7',
            'admin_nombre' => 'Presidente de la federación', 'admin_email' => 'federacion@demo.tuvocalia.com', 'admin_clave' => bin2hex(random_bytes(12)),
        ]);
        $fid = (int)$r['liga_id'];
        q("UPDATE ligas SET es_demo = 1, es_federacion = 1, vence = '', es_prueba = 0, color2 = '#f2b705', contacto_nombre = 'QF Producciones', contacto_telefono = '0990000000' WHERE id = ?", [$fid]);
        q("UPDATE usuarios SET perfil = 'admin' WHERE id = ?", [$r['usuario_id']]);
        q("UPDATE ligas SET federacion_id = ?, federacion_estado = 'afiliada' WHERE id IN (?, ?)", [$fid, $lid, $eid]);
        $f = fila('SELECT * FROM ligas WHERE id = ?', [$fid]);
        codigo_federacion($f);
        // Campeonato federativo con 4 equipos traídos de la liga demo
        $reglas = presets_modalidad()['F7'];
        $t = array_merge(['liga_id' => $fid, 'nombre' => 'Copa Federación', 'temporada' => date('Y'), 'estado' => 'sorteado'], $reglas, ['modalidad' => 'F7']);
        q('INSERT INTO torneos (' . implode(', ', array_keys($t)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($t))) . ')', $t);
        $tid = (int)db()->lastInsertId();
        foreach (filas('SELECT id FROM equipos WHERE liga_id = ? ORDER BY id LIMIT 4', [$lid]) as $e) {
            try { traer_equipo_a_federacion($f, (int)$e['id'], $tid); } catch (Throwable $ex) { error_log('fed demo: ' . $ex->getMessage()); }
        }
        q('UPDATE torneos SET fecha_sorteo = ? WHERE id = ?', [date('Y-m-d H:i:s'), $tid]);
        try {
            generar_fixture($tid, date('Y-m-d', strtotime('next saturday')), 7, '15:00', 'Estadio Federativo');
        } catch (Throwable $ex) {
            error_log('fed demo fixture: ' . $ex->getMessage());
        }
        // Una sanción federativa vigente sobre un jugador de la liga demo
        $j = fila("SELECT j.* FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ? AND j.cedula <> '' AND j.activo = 1 ORDER BY j.id DESC LIMIT 1", [$lid]);
        if ($j) q('INSERT INTO fed_sanciones (federacion_id, cedula, nombre, motivo, desde, hasta, registrado_por) VALUES (?,?,?,?,?,?,?)',
            [$fid, $j['cedula'], trim($j['nombres'] . ' ' . $j['apellidos']), 'Agresión al árbitro (resolución 07-' . date('Y') . ')', date('Y-m-d', strtotime('-3 days')), date('Y-m-d', strtotime('+30 days')), 'Comisión de disciplina']);
        return $fid;
    });
}

/** Videos de ejemplo para el portal del jugador y el de los padres (en el mismo servidor, se renuevan cada noche). */
function crear_videos_demo(int $lid, int $eid): void
{
    sin_auditoria(function () use ($lid, $eid): void {
        $origen = APP_DIR . '/demo_video.mp4';
        if (!is_file($origen)) return;
        foreach (filas("SELECT * FROM videos WHERE liga_id NOT IN (SELECT id FROM ligas)") as $v) q('DELETE FROM videos WHERE id = ?', [$v['id']]);
        @mkdir(videos_dir_local(), 0775, true);
        $j = fila("SELECT j.id FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ? AND j.portal_pin <> '' LIMIT 1", [$lid]);
        $f = fila('SELECT * FROM esc_familias WHERE liga_id = ? LIMIT 1', [$eid]);
        $a = $f ? fila("SELECT id FROM esc_alumnos WHERE liga_id = ? AND representante_cedula = ? AND estado = 'activo' ORDER BY id LIMIT 1", [$eid, $f['cedula']]) : null;
        $crear = function (int $liga, string $tipo, int $pid, string $titulo, string $nota, int $dias_antes) use ($origen) {
            $archivo = bin2hex(random_bytes(12)) . '.mp4';
            copy($origen, videos_dir_local() . '/' . $archivo);
            $listo = date('Y-m-d H:i:s', strtotime('-' . $dias_antes . ' days'));
            q("INSERT INTO videos (liga_id, tipo, persona_id, titulo, nota, archivo, servidor, bytes, duracion, estado, subido_por, listo_en, expira) VALUES (?,?,?,?,?,?, 'local', ?, 8, 'listo', 'Demo', ?, ?)",
                [$liga, $tipo, $pid, $titulo, $nota, $archivo, filesize($origen), $listo, date('Y-m-d H:i:s', strtotime($listo . ' +' . VIDEO_DIAS . ' days'))]);
        };
        if ($j) {
            $crear($lid, 'jugador', (int)$j['id'], 'Tu gol de la fecha 3', '¡Golazo! Descárgalo y compártelo en tus redes.', 0);
            $crear($lid, 'jugador', (int)$j['id'], 'Jugada del partido anterior', '', 6);
        }
        if ($a) $crear($eid, 'alumno', (int)$a['id'], 'Técnica de pase · entrenamiento del sábado', 'Mire cómo mejoró su técnica. ¡Felicitaciones!', 1);
    });
}

/** Venta de entradas de ejemplo en la liga demo: localidades, parqueadero, compras pagadas, una por revisar y algunas ya usadas. */
function crear_entradas_demo(int $lid): void
{
    sin_auditoria(function () use ($lid): void {
        q("UPDATE ligas SET entradas_activas = 1, entradas_transferencia = 1, entradas_parqueo = 1, entradas_iva = 15, entradas_condiciones = 'No hay devoluciones. Niños menores de 5 años no pagan.' WHERE id = ?", [$lid]);
        $liga = fila('SELECT * FROM ligas WHERE id = ?', [$lid]);
        $p = fila("SELECT p.*, el.nombre AS l, ev.nombre AS vv, t.nombre AS torneo FROM partidos p JOIN torneos t ON t.id = p.torneo_id LEFT JOIN equipos el ON el.id = p.local_id LEFT JOIN equipos ev ON ev.id = p.visitante_id
                   WHERE t.liga_id = ? AND p.estado = 'programado' AND p.fecha >= ? AND t.modalidad NOT IN ('" . implode("','", array_keys(modalidades_individuales())) . "') ORDER BY p.fecha, p.hora LIMIT 1", [$lid, date('Y-m-d')]);
        $fecha = $p['fecha'] ?? date('Y-m-d', strtotime('next sunday'));
        q("INSERT INTO bol_ventas (liga_id, partido_id, titulo, fecha, hora, lugar, descripcion, estado, venta_hasta, max_por_orden, puerta_clave) VALUES (?,?,?,?,?,?,?, 'abierta', ?, 10, ?)", [
            $lid, $p['id'] ?? null, $p ? $p['l'] . ' vs ' . $p['vv'] . ' · ' . $p['torneo'] : 'Gran final', $fecha, $p['hora'] ?? '15:00', 'Estadio de la liga',
            'Puertas abren 1 hora antes. Presente el QR en el celular o impreso.', $fecha . ' 23:59', bin2hex(random_bytes(10))]);
        $vid = (int)db()->lastInsertId();
        $locs = [];
        foreach ([['General', 'entrada', 2, 300], ['Tribuna', 'entrada', 4, 120], ['Palco', 'entrada', 8, 20], ['Parqueadero', 'parqueo', 1, 40]] as $k => [$n, $t, $pr, $c]) {
            q('INSERT INTO bol_localidades (venta_id, nombre, tipo, precio, cupo, orden) VALUES (?,?,?,?,?,?)', [$vid, $n, $t, $pr, $c, $k + 1]);
            $locs[$n] = (int)db()->lastInsertId();
        }
        $v = fila('SELECT * FROM bol_ventas WHERE id = ?', [$vid]);
        // Comprobante de transferencia de ejemplo (imagen sencilla)
        $comp = '';
        if (function_exists('imagecreatetruecolor')) {
            @mkdir(RAIZ . '/data/fotos/demo', 0775, true);
            $im = imagecreatetruecolor(520, 300);
            imagefill($im, 0, 0, imagecolorallocate($im, 245, 247, 250));
            $az = imagecolorallocate($im, 21, 101, 192);
            $ng = imagecolorallocate($im, 40, 40, 40);
            imagefilledrectangle($im, 0, 0, 520, 56, $az);
            imagestring($im, 5, 16, 20, 'BANCO DE EJEMPLO - Transferencia exitosa', imagecolorallocate($im, 255, 255, 255));
            foreach (['Ordenante: Sofia Ortiz', 'Beneficiario: Liga Demo', 'Monto: USD 8.00', 'Fecha: ' . date('d/m/Y H:i'), 'Comprobante N. 004512873'] as $k => $l) imagestring($im, 5, 24, 80 + $k * 38, $l, $ng);
            imagejpeg($im, RAIZ . '/data/fotos/demo/comprobante_entradas.jpg', 80);
            imagedestroy($im);
            $comp = 'fotos/demo/comprobante_entradas.jpg';
        }
        $compradores = [['Andrea Zambrano', '1712345675', '0991112233', ['General' => 2]], ['Luis Cevallos', '1715975320', '0982223344', ['Tribuna' => 3, 'Parqueadero' => 1], 'PBA1234'],
            ['María Guamán', '1703456127', '0973334455', ['Palco' => 2]], ['Pedro Vera', '1720345675', '0964445566', ['General' => 4]], ['Sofía Ortiz', '1720356789', '0955556677', ['Tribuna' => 2]]];
        foreach ($compradores as $i => $c) {
            $items = [];
            $total = 0;
            foreach ($c[3] as $n => $q) {
                $pr = (float)valor('SELECT precio FROM bol_localidades WHERE id = ?', [$locs[$n]]);
                $items[] = ['localidad_id' => $locs[$n], 'cantidad' => $q, 'precio' => $pr, 'placas' => $n === 'Parqueadero' ? ($c[4] ?? '') : ''];
                $total += $pr * $q;
            }
            $o = crear_orden_entradas($liga, $v, $items, $total, ['nombre' => $c[0], 'cedula' => $c[1], 'telefono' => $c[2]], $i === 4 ? 'web' : ($i === 3 ? 'taquilla' : 'web'), $i === 3 ? 'efectivo' : 'transferencia', $i === 4 ? $comp : '');
            if ($o['estado'] === 'por_verificar' && $i < 3) pagar_orden_entradas($o, 'Demo');
        }
        // Algunas ya ingresaron
        foreach (filas("SELECT id FROM bol_tickets WHERE venta_id = ? AND estado = 'valido' ORDER BY id LIMIT 4", [$vid]) as $k => $t) {
            q("UPDATE bol_tickets SET estado = 'usado', usado_en = ?, puerta = ? WHERE id = ?", [date('Y-m-d H:i:s', strtotime('-' . (10 + $k * 3) . ' minutes')), $k % 2 ? 'Puerta 2' : 'Puerta 1', $t['id']]);
        }
        q('INSERT INTO bol_escaneos (venta_id, ticket_id, codigo, resultado, puerta) SELECT venta_id, id, codigo, \'ok\', puerta FROM bol_tickets WHERE venta_id = ? AND estado = \'usado\'', [$vid]);
    });
}
