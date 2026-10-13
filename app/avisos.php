<?php
/*
 * VERSIÓN 6 — AVISOS AUTOMÁTICOS POR TELEGRAM (opcional, cada liga decide si lo usa)
 *
 * - Un solo bot para todo el sistema (lo configura el superadministrador en Ajustes).
 * - Cada liga vincula su canal o grupo público (resultados, programación, goles en vivo).
 * - Delegados, árbitros y administradores vinculan su Telegram personal para avisos privados.
 * - Los mensajes se guardan en una cola y los envía la tarea automática (app/tareas.php, cada minuto),
 *   así una falla de internet nunca frena el sistema. Cada aviso tiene una "clave" para no repetirse.
 */

function tg_activo(): bool
{
    return (string)ajuste('tg_token') !== '';
}

/** Opciones de avisos de una liga (con valores por defecto). */
function tg_opciones(array $liga): array
{
    $def = ['activo' => 0, 'resultados' => 1, 'programacion' => 1, 'inicio' => 0, 'goles' => 0, 'fotos' => 1, 'delegados' => 1, 'arbitros' => 1, 'admins' => 1];
    $o = json_decode((string)($liga['tg_opciones'] ?? ''), true);
    return array_merge($def, is_array($o) ? array_intersect_key($o, $def) : []);
}

function tg_liga_activa(array $liga): bool
{
    return tg_activo() && (int)tg_opciones($liga)['activo'] === 1 && modulo_activo($liga, 'avisos'); // (v12) según el plan
}

/** Pone un mensaje en la cola. Si ya existe uno con la misma clave, no se repite. */
function tg_encolar(?int $liga_id, string $chat, string $texto, string $foto = '', ?string $clave = null): void
{
    if ($chat === '' || $texto === '') return;
    try {
        q('INSERT OR IGNORE INTO telegram_cola (liga_id, chat_id, texto, foto_url, clave) VALUES (?,?,?,?,?)', [$liga_id, $chat, mb_substr($texto, 0, 3900), $foto, $clave]);
    } catch (Throwable) {
        // los avisos nunca deben impedir el trabajo
    }
}

/** Llama a la API de Telegram. Devuelve la respuesta decodificada o ['ok' => false, 'description' => ...]. */
function tg_api(string $metodo, array $params = [], int $espera = 20): array
{
    $token = (string)ajuste('tg_token');
    if ($token === '') return ['ok' => false, 'description' => 'Falta el token del bot'];
    $url = 'https://api.telegram.org/bot' . $token . '/' . $metodo;
    $cuerpo = json_encode($params);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $espera, CURLOPT_CONNECTTIMEOUT => 10]);
        $r = curl_exec($ch);
        $err = curl_error($ch);
        unset($ch); // (v24) curl_close() ya no hace nada desde PHP 8 y PHP 8.5 lo marca como obsoleto
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $cuerpo, 'timeout' => $espera, 'ignore_errors' => true]]);
        $r = @file_get_contents($url, false, $ctx);
        $err = $r === false ? 'sin conexión con Telegram' : '';
    }
    if ($r === false || $r === null || $r === '') return ['ok' => false, 'description' => $err ?: 'sin respuesta'];
    $d = json_decode((string)$r, true);
    return is_array($d) ? $d : ['ok' => false, 'description' => 'respuesta no válida'];
}

/** Envía los mensajes pendientes de la cola (lo llama la tarea automática). */
function tg_procesar_cola(int $max = 40): int
{
    if (!tg_activo()) return 0;
    $enviados = 0;
    foreach (filas("SELECT * FROM telegram_cola WHERE enviado = '' AND intentos < 5 ORDER BY id LIMIT $max") as $m) {
        $r = ['ok' => false];
        if ($m['foto_url'] !== '') {
            $r = tg_api('sendPhoto', ['chat_id' => $m['chat_id'], 'photo' => $m['foto_url'], 'caption' => mb_substr($m['texto'], 0, 1000), 'parse_mode' => 'HTML']);
        }
        if (!($r['ok'] ?? false)) {
            $r = tg_api('sendMessage', ['chat_id' => $m['chat_id'], 'text' => $m['texto'], 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
        }
        if ($r['ok'] ?? false) {
            q("UPDATE telegram_cola SET enviado = datetime('now'), intentos = intentos + 1, error = '' WHERE id = ?", [$m['id']]);
            $enviados++;
        } else {
            q('UPDATE telegram_cola SET intentos = intentos + 1, error = ? WHERE id = ?', [mb_substr((string)($r['description'] ?? 'error'), 0, 300), $m['id']]);
        }
        usleep(60000); // Telegram permite ~30 mensajes por segundo
    }
    q("DELETE FROM telegram_cola WHERE enviado <> '' AND enviado < datetime('now', '-30 days')");
    return $enviados;
}

/**
 * Lee los mensajes que le escriben al bot (vinculación):
 *   /start CODIGO     → vincula el Telegram personal de un usuario (delegado, árbitro, administrador)
 *   /vincular CODIGO  → escrito dentro de un grupo o canal: lo vincula como canal público de la liga
 */
function tg_leer_mensajes(): int
{
    if (!tg_activo()) return 0;
    $offset = (int)ajuste('tg_offset', 0);
    $r = tg_api('getUpdates', ['offset' => $offset, 'timeout' => 0, 'allowed_updates' => ['message', 'channel_post']], 15);
    if (!($r['ok'] ?? false)) return 0;
    $n = 0;
    foreach ($r['result'] ?? [] as $up) {
        $offset = max($offset, (int)$up['update_id'] + 1);
        $msg = $up['message'] ?? $up['channel_post'] ?? null;
        if (!$msg || !isset($msg['text'], $msg['chat']['id'])) continue;
        $chat = (string)$msg['chat']['id'];
        $texto = trim((string)$msg['text']);
        $n++;
        if (preg_match('/^\/start(?:@\w+)?\s+([a-f0-9]{16})$/i', $texto, $m)) {
            $u = fila('SELECT * FROM usuarios WHERE tg_codigo = ? AND activo = 1', [strtolower($m[1])]);
            if ($u) {
                q("UPDATE usuarios SET tg_chat = ?, tg_codigo = '' WHERE id = ?", [$chat, $u['id']]);
                tg_encolar($u['liga_id'] ? (int)$u['liga_id'] : null, $chat, '✅ Listo, ' . e($u['nombre']) . '. Desde ahora recibirá aquí los avisos de ' . e(marca()) . '.');
            } else {
                tg_encolar(null, $chat, 'Ese código no es válido o ya se usó. Genere uno nuevo desde el sistema.');
            }
        } elseif (preg_match('/^\/vincular(?:@\w+)?\s+([a-f0-9]{8})$/i', $texto, $m)) {
            $l = fila('SELECT * FROM ligas WHERE tg_codigo = ?', [strtolower($m[1])]);
            if ($l) {
                $nombre = (string)($msg['chat']['title'] ?? $msg['chat']['username'] ?? 'chat');
                q("UPDATE ligas SET tg_chat = ?, tg_chat_nombre = ?, tg_codigo = '' WHERE id = ?", [$chat, mb_substr($nombre, 0, 80), $l['id']]);
                tg_encolar((int)$l['id'], $chat, '✅ Este ' . (($msg['chat']['type'] ?? '') === 'channel' ? 'canal' : 'grupo') . ' quedó vinculado a <b>' . e($l['nombre']) . '</b>. Aquí se publicarán los resultados y la programación.');
            } else {
                tg_encolar(null, $chat, 'Ese código no es válido o ya se usó. Genere uno nuevo en el panel de la liga (Parámetros → Telegram).');
            }
        } elseif (preg_match('/^\/start/i', $texto) && ($msg['chat']['type'] ?? '') === 'private') {
            tg_encolar(null, $chat, 'Hola 👋 Soy el bot de avisos de ' . e(marca()) . '. Para recibir avisos, entre al sistema y presione "Conectar mi Telegram".');
        }
    }
    guardar_ajuste('tg_offset', $offset);
    return $n;
}

/** Enlace para que un usuario vincule su Telegram personal. */
function tg_enlace_usuario(array $u): string
{
    $bot = (string)ajuste('tg_usuario');
    if ($bot === '') return '';
    $cod = $u['tg_codigo'] ?? '';
    if ($cod === '') {
        $cod = bin2hex(random_bytes(8));
        q('UPDATE usuarios SET tg_codigo = ? WHERE id = ?', [$cod, $u['id']]);
    }
    return 'https://t.me/' . rawurlencode(ltrim($bot, '@')) . '?start=' . $cod;
}

/** Tarjeta "Conectar mi Telegram" para cualquier usuario (panel, árbitro, delegado). */
function tg_tarjeta_usuario(array $u, string $accion_url): string
{
    if (!tg_activo()) return '';
    ob_start(); ?>
    <div class="tarjeta" id="telegram">
      <h2 style="margin-top:0">📲 Avisos por Telegram</h2>
      <?php if ($u['tg_chat'] !== ''): ?>
        <p>✅ Su Telegram está conectado. Aquí le llegan los avisos del sistema.</p>
        <form method="post" action="<?= e($accion_url) ?>"><?= csrf_campo() ?><input type="hidden" name="accion" value="tg_desconectar"><button class="btn btn-sec btn-chico">Desconectar</button></form>
      <?php else: ?>
        <p class="suave">Reciba en su celular: su programación, suspensiones, respuestas de la liga y más. Necesita tener instalada la aplicación Telegram.</p>
        <a class="btn" target="_blank" rel="noopener" href="<?= e(tg_enlace_usuario($u)) ?>">Conectar mi Telegram</a>
        <p class="ayuda">Se abre Telegram: presione <b>INICIAR</b> (o <b>START</b>). En menos de un minuto queda conectado.</p>
      <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

/* ---------- Destinatarios ---------- */

function tg_chats_admins(int $liga_id): array
{
    return array_column(filas("SELECT tg_chat FROM usuarios WHERE liga_id = ? AND rol = 'liga' AND activo = 1 AND tg_chat <> ''", [$liga_id]), 'tg_chat');
}

function tg_chats_delegados(int $equipo_id): array
{
    return array_column(filas("SELECT tg_chat FROM usuarios WHERE equipo_id = ? AND rol = 'delegado' AND activo = 1 AND tg_chat <> ''", [$equipo_id]), 'tg_chat');
}

function tg_chat_arbitro(int $arbitro_id): string
{
    return (string)valor("SELECT u.tg_chat FROM arbitros a JOIN usuarios u ON u.id = a.usuario_id WHERE a.id = ? AND u.activo = 1", [$arbitro_id]);
}

/* ---------- Avisos del día a día ---------- */

function aviso_admins(array $liga, string $texto, string $clave): void
{
    if (!tg_liga_activa($liga) || !tg_opciones($liga)['admins']) return;
    foreach (tg_chats_admins((int)$liga['id']) as $c) tg_encolar((int)$liga['id'], $c, $texto, '', $clave . ':' . $c);
}

function aviso_delegados(array $liga, int $equipo_id, string $texto, string $clave): void
{
    if (!tg_liga_activa($liga) || !tg_opciones($liga)['delegados']) return;
    foreach (tg_chats_delegados($equipo_id) as $c) tg_encolar((int)$liga['id'], $c, $texto, '', $clave . ':' . $c);
}

/** Texto corto de un partido: "Local 2 - 1 Visitante". */
function tg_linea_partido(array $p, bool $con_marcador = true): string
{
    if ($con_marcador && $p['goles_local'] !== null) {
        $pen = $p['penales_local'] !== null ? ' (pen ' . (int)$p['penales_local'] . '-' . (int)$p['penales_visitante'] . ')' : '';
        return e($p['local_nombre']) . ' <b>' . (int)$p['goles_local'] . ' - ' . (int)$p['goles_visitante'] . '</b> ' . e($p['visitante_nombre']) . $pen;
    }
    return e($p['local_nombre']) . ' vs ' . e($p['visitante_nombre']);
}

/** Resultado final de un partido al canal de la liga (+ suspensiones a los delegados). */
function aviso_resultado(int $pid): void
{
    $p = partido_completo($pid);
    if (!$p || !partido_cerrado($p)) return;
    push_aviso_resultado($p); // (v10) a los que siguen a estos equipos
    // (v8) Si es un partido de la llave, el ganador pasa solo a la siguiente ronda
    if ($p['llave_pos'] !== null) avanzar_llaves((int)$p['torneo_id']);
    $liga = fila('SELECT * FROM ligas WHERE id = ?', [$p['liga_id']]);
    if (!$liga || !tg_liga_activa($liga)) return;
    $o = tg_opciones($liga);
    if ($o['resultados'] && $liga['tg_chat'] !== '') {
        $goles = filas("SELECT ev.*, j.nombres, j.apellidos FROM eventos ev JOIN jugadores j ON j.id = ev.jugador_id WHERE ev.partido_id = ? AND ev.tipo IN ('gol','penal','autogol') ORDER BY ev.minuto, ev.id", [$pid]);
        $txt = '🏁 <b>FINAL</b>' . ($p['estado'] === 'wo' ? ' (W.O.)' : '') . "\n" . tg_linea_partido($p) . "\n<i>" . e($p['torneo']) . ' · ' . ($p['fase'] === 'regular' ? 'Jornada ' . (int)$p['jornada'] : e(nombre_fase($p['fase']))) . '</i>';
        if ($goles) {
            $txt .= "\n";
            foreach ($goles as $g) $txt .= "\n⚽ " . e(nombre_jugador($g)) . ($g['minuto'] !== null ? ' ' . (int)$g['minuto'] . "'" : '') . ($g['tipo'] === 'penal' ? ' (penal)' : ($g['tipo'] === 'autogol' ? ' (autogol)' : '')) . ' — ' . e((int)$g['equipo_id'] === (int)$p['local_id'] ? $p['local_nombre'] : $p['visitante_nombre']);
        }
        if (($ds = detalle_sets($pid)) !== '') $txt .= "\n🏐 Sets: " . e($ds);
        $txt .= "\n\n🔗 " . url_liga($liga);
        $foto = $o['fotos'] ? url_base() . 'imagen.php?tipo=resultado&p=' . $pid . '&v=' . substr(md5($p['goles_local'] . '-' . $p['goles_visitante'] . count($goles)), 0, 6) : '';
        tg_encolar((int)$liga['id'], $liga['tg_chat'], $txt, $foto, 'res:' . $pid . ':' . $p['goles_local'] . '-' . $p['goles_visitante'] . ':' . $p['estado']);
    }
    aviso_suspensiones((int)$p['torneo_id'], [(int)$p['local_id'], (int)$p['visitante_id']]);
}

/** Aviso de inicio de partido (canal). */
function aviso_inicio(int $pid): void
{
    $p = partido_completo($pid);
    if (!$p) return;
    push_aviso_inicio($p);
    $liga = fila('SELECT * FROM ligas WHERE id = ?', [$p['liga_id']]);
    if (!$liga || !tg_liga_activa($liga) || !tg_opciones($liga)['inicio'] || $liga['tg_chat'] === '') return;
    tg_encolar((int)$liga['id'], $liga['tg_chat'], '🟢 <b>¡Empezó el partido!</b>' . "\n" . tg_linea_partido($p, false) . ($p['cancha'] !== '' ? "\n📍 " . e($p['cancha']) : '') . "\n\n🔴 En vivo: " . url_liga($liga), '', 'ini:' . $pid);
}

/** Gol en vivo (canal). */
function aviso_gol(int $pid, int $evento_id): void
{
    $p = partido_completo($pid);
    if (!$p) return;
    push_aviso_gol($p, $evento_id);
    $liga = fila('SELECT * FROM ligas WHERE id = ?', [$p['liga_id']]);
    if (!$liga || !tg_liga_activa($liga) || !tg_opciones($liga)['goles'] || $liga['tg_chat'] === '') return;
    $ev = fila('SELECT ev.*, j.nombres, j.apellidos FROM eventos ev JOIN jugadores j ON j.id = ev.jugador_id WHERE ev.id = ?', [$evento_id]);
    if (!$ev || !in_array($ev['tipo'], ['gol', 'penal', 'autogol'], true)) return;
    $eq = (int)$ev['equipo_id'] === (int)$p['local_id'] ? $p['local_nombre'] : $p['visitante_nombre'];
    tg_encolar((int)$liga['id'], $liga['tg_chat'], '⚽ <b>¡GOL' . ($ev['tipo'] === 'autogol' ? ' (autogol)' : '') . '!</b> ' . e(nombre_jugador($ev)) . ' (' . e($eq) . ')' . ($ev['minuto'] !== null ? ' ' . (int)$ev['minuto'] . "'" : '') . "\n" . tg_linea_partido($p), '', 'gol:' . $evento_id);
}

/** Programación de una jornada: al canal, a los delegados (su partido) y a los árbitros (su designación). */
function aviso_programacion(int $torneo_id, array $partido_ids): int
{
    $t = reglas_torneo($torneo_id, true);
    $liga = $t ? fila('SELECT * FROM ligas WHERE id = ?', [$t['liga_id']]) : null;
    if ($liga && $partido_ids) push_aviso_programacion($partido_ids);
    if (!$liga || !tg_liga_activa($liga) || !$partido_ids) return 0;
    $o = tg_opciones($liga);
    $ps = [];
    foreach ($partido_ids as $id) if ($p = partido_completo((int)$id)) $ps[] = $p;
    usort($ps, fn($a, $b) => strcmp(clave_orden_partido($a), clave_orden_partido($b)));
    $n = 0;
    $linea = fn($p) => ($p['fecha'] !== '' ? fecha_bonita($p['fecha']) . ' ' : '') . ($p['hora'] !== '' ? $p['hora'] . ' · ' : '') . tg_linea_partido($p, false) . ($p['cancha'] !== '' ? ' · 📍' . e($p['cancha']) : '');
    if ($o['programacion'] && $liga['tg_chat'] !== '') {
        $txt = '📅 <b>Programación · ' . e($t['nombre']) . '</b>';
        $fase = null;
        foreach ($ps as $p) {
            $f = $p['fase'] === 'regular' ? 'Jornada ' . (int)$p['jornada'] : nombre_fase($p['fase']);
            if ($f !== $fase) { $txt .= "\n\n<b>" . e($f) . '</b>'; $fase = $f; }
            $txt .= "\n• " . $linea($p);
        }
        $txt .= "\n\n🔗 " . url_liga($liga);
        tg_encolar((int)$liga['id'], $liga['tg_chat'], $txt, '', 'prog:' . $torneo_id . ':' . md5($txt));
        $n++;
    }
    foreach ($ps as $p) {
        $firma = md5($p['fecha'] . $p['hora'] . $p['cancha']);
        if ($o['delegados']) {
            foreach ([(int)$p['local_id'], (int)$p['visitante_id']] as $eid) {
                foreach (tg_chats_delegados($eid) as $c) {
                    tg_encolar((int)$liga['id'], $c, '📅 <b>Su próximo partido</b>' . "\n" . $linea($p) . "\n<i>" . e($t['nombre']) . '</i>', '', 'progd:' . $p['id'] . ':' . $c . ':' . $firma);
                    $n++;
                }
            }
        }
        if ($o['arbitros']) {
            foreach (arbitros_del_partido($p) as [$campo, $etq, $aid, $nom]) {
                if (!$aid || ($c = tg_chat_arbitro($aid)) === '') continue;
                tg_encolar((int)$liga['id'], $c, '🟨 <b>Designación arbitral</b>' . "\n" . e($etq) . "\n" . $linea($p) . "\n<i>" . e($liga['nombre']) . ' · ' . e($t['nombre']) . '</i>', '', 'proga:' . $p['id'] . ':' . $aid . ':' . $firma);
                $n++;
            }
        }
    }
    return $n;
}

/** Avisa a los delegados de los jugadores que quedaron suspendidos (una sola vez por suspensión). */
function aviso_suspensiones(int $torneo_id, array $equipo_ids): void
{
    $t = reglas_torneo($torneo_id);
    $liga = $t ? fila('SELECT * FROM ligas WHERE id = ?', [$t['liga_id']]) : null;
    if (!$liga || !tg_liga_activa($liga) || !tg_opciones($liga)['delegados']) return;
    foreach (suspensiones($torneo_id) as $jid => $s) {
        $eid = (int)($s['jugador']['equipo_id'] ?? 0);
        if (!in_array($eid, $equipo_ids, true)) continue;
        aviso_delegados($liga, $eid, '⛔ <b>Jugador suspendido</b>' . "\n" . e(nombre_jugador($s['jugador'])) . ' no puede jugar su próximo(s) ' . (int)$s['pendientes'] . ' partido(s).' . "\nMotivo: " . e(implode('; ', $s['motivos'])) . "\n<i>" . e($t['nombre']) . '</i>',
            'susp:' . $torneo_id . ':' . $jid . ':' . md5(implode('|', $s['motivos'])));
    }
}

/** Avisos de una vez al día: suscripciones por vencer (a la liga y al superadministrador). */
function avisos_diarios(): void
{
    $super = array_column(filas("SELECT tg_chat FROM usuarios WHERE rol = 'super' AND activo = 1 AND tg_chat <> ''"), 'tg_chat');
    foreach (filas("SELECT * FROM ligas WHERE vence <> '' AND activa = 1 AND es_demo = 0") as $l) {
        $s = estado_suscripcion($l);
        // (v10) también 5 días antes, con el valor y los datos para pagar
        if (!in_array($s['dias'], [7, 5, 3, 1, 0, -1], true)) continue;
        $txt = ($s['dias'] >= 0 ? '⏰ <b>' . ($l['es_prueba'] ? 'Su demo' : 'Su suscripción') . ' de ' . e(marca()) . ' vence ' . ($s['dias'] === 0 ? 'HOY' : 'en ' . $s['dias'] . ' día(s)') . '</b>' : '⚠️ <b>Su suscripción venció ayer</b>')
            . "\nLiga: " . e($l['nombre']) . ' · vence el ' . e($l['vence']) . texto_tg_renovacion($l)
            . "\n\nPara renovar comuníquese con " . e(ajuste('empresa')) . (ajuste('ventas_whatsapp') !== '' ? ' al ' . e(ajuste('ventas_whatsapp')) : '') . ' y envíe su comprobante.';
        foreach (tg_chats_admins((int)$l['id']) as $c) tg_encolar((int)$l['id'], $c, $txt, '', 'vence:' . $l['id'] . ':' . date('Y-m-d') . ':' . $c);
        foreach ($super as $c) tg_encolar(null, $c, '💳 ' . e($l['nombre']) . ': ' . e($s['texto']) . ($l['contacto_telefono'] ? "\n📞 " . e($l['contacto_telefono']) : ''), '', 'vence_s:' . $l['id'] . ':' . date('Y-m-d') . ':' . $c);
        // (v33) correo al contacto de la cuenta (5 días antes, 1 día antes, el día y el día después); las demos reciben el suyo en avisos_demos()
        if (empty($l['es_prueba']) && in_array($s['dias'], [5, 1, 0, -1], true)) { try { correo_vencimiento($l, $s); } catch (Throwable $ex) { error_log('correo vencimiento: ' . $ex->getMessage()); } }
    }
}
