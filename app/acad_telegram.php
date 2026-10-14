<?php
/*
 * (TuAcademia, arreglos del 14-oct-2026) AVISOS POR TELEGRAM A LOS PADRES
 *
 * Usa la misma infraestructura de Telegram que ya tenía el sistema (app/avisos.php):
 *   un solo bot para todo el sistema (lo pone el superadministrador en Ajustes → Bot de Telegram),
 *   la cola telegram_cola (la envía la tarea automática cada minuto) y la lectura de /start con getUpdates.
 *
 * - En el PORTAL DE PADRES, la familia toca «Conectar Telegram» → se abre el bot con /start famXXXXXXXXXXXXXXXX → queda conectada.
 *   El mismo enlace sirve para papá y mamá (hasta 4 Telegram por familia). Se desconecta desde el portal.
 * - AVISOS AUTOMÁTICOS (la academia activa o desactiva cada uno en «Avisos a padres»):
 *     por_vencer  pensión (o cualquier valor) que vence en los próximos N días (N configurable, 3 por defecto)
 *     vencida     el día después de vencer, si sigue sin pagar
 *     pago        pago recibido (con el número de recibo): efectivo, comprobante aprobado, DeUna, Payphone o tarjeta
 *     falta       el alumno faltó a su clase (asistencia «Faltó» o clase particular «Faltó sin avisar»), solo el mismo día o el siguiente
 *     comunicado  mensaje que escribe la academia para todas las familias o para un curso
 * - SIN REPETIR: cada aviso tiene una clave única en acad_tg_avisos (por valor, por recibo, por clase y alumno, por comunicado y familia).
 * - SI TELEGRAM FALLA NO SE ROMPE NADA: todo va en try/catch, el mensaje queda en la cola y la tarea automática lo reintenta (hasta 5 veces).
 */

/** Tipos de aviso: clave => [nombre, explicación]. */
function acad_tg_tipos(): array
{
    return [
        'por_vencer' => ['Pensión por vencer', 'Unos días antes de que venza una pensión u otro valor.'],
        'vencida' => ['Pensión vencida', 'Al día siguiente de vencer, si todavía no está pagada.'],
        'pago' => ['Pago recibido', 'Cuando la academia registra un pago o aprueba un comprobante (con el número de recibo).'],
        'falta' => ['Falta a clase', 'Cuando el profesor marca «Faltó» al tomar asistencia (también en clases particulares).'],
        'comunicado' => ['Comunicados', 'Los mensajes que la academia escribe para todas las familias o para un curso.'],
    ];
}

/** Opciones de la academia (con valores por defecto: todo encendido y 3 días antes). */
function acad_tg_opciones(?array $liga): array
{
    $def = ['activo' => 1, 'dias' => 3] + array_fill_keys(array_keys(acad_tg_tipos()), 1);
    $o = json_decode((string)($liga['acad_tg'] ?? ''), true);
    $o = array_merge($def, is_array($o) ? array_intersect_key($o, $def) : []);
    foreach ($o as $k => $v) $o[$k] = (int)$v;
    $o['dias'] = max(0, min(15, $o['dias']));
    return $o;
}

/** ¿La academia puede usar los avisos? (bot configurado, plan con «Avisos por Telegram» y la academia no los apagó). */
function acad_tg_disponible(?array $liga): bool
{
    try {
        return $liga && es_escuela($liga) && tg_activo() && modulo_activo($liga, 'avisos') && (int)acad_tg_opciones($liga)['activo'] === 1;
    } catch (Throwable) {
        return false;
    }
}

function acad_tg_tipo_activo(?array $liga, string $tipo): bool
{
    return acad_tg_disponible($liga) && (int)(acad_tg_opciones($liga)[$tipo] ?? 0) === 1;
}

/** Telegram conectados de una familia. */
function acad_tg_chats_familia(int $fam_id): array
{
    return array_column(filas('SELECT chat_id FROM acad_tg_chats WHERE familia_id = ? ORDER BY id', [$fam_id]), 'chat_id');
}

/** Familia (portal de padres) de un alumno, o null si el representante nunca entró al portal. */
function acad_tg_familia_de_alumno(int $aid): ?array
{
    return fila("SELECT f.* FROM esc_alumnos a JOIN esc_familias f ON f.liga_id = a.liga_id AND f.cedula = a.representante_cedula WHERE a.id = ? AND a.representante_cedula <> ''", [$aid]);
}

/** Enlace para conectar el Telegram de la familia (el código no se borra al usarlo: sirve para papá y mamá). */
function acad_tg_enlace_familia(array $fam): string
{
    $bot = ltrim((string)ajuste('tg_usuario'), '@');
    if ($bot === '') return '';
    $cod = (string)($fam['tg_codigo'] ?? '');
    if (!preg_match('/^[a-f0-9]{16}$/', $cod)) {
        $cod = bin2hex(random_bytes(8));
        q('UPDATE esc_familias SET tg_codigo = ? WHERE id = ?', [$cod, $fam['id']]);
    }
    return 'https://t.me/' . rawurlencode($bot) . '?start=fam' . $cod;
}

/**
 * Lo llama tg_leer_mensajes() cuando alguien escribe /start famCODIGO al bot.
 * Devuelve true si el código era de una familia (conectada o no).
 */
function acad_tg_vincular_familia(string $chat, string $codigo, array $msg): bool
{
    $f = fila("SELECT f.*, l.nombre AS academia FROM esc_familias f JOIN ligas l ON l.id = f.liga_id WHERE f.tg_codigo = ? AND f.tg_codigo <> ''", [strtolower($codigo)]);
    if (!$f) {
        tg_encolar(null, $chat, 'Ese enlace ya no es válido. Entre al portal de padres y toque otra vez «Conectar Telegram».');
        return false;
    }
    if (($msg['chat']['type'] ?? 'private') !== 'private') {
        tg_encolar(null, $chat, 'Para recibir los avisos de su familia, abra el enlace desde su chat personal con el bot (no desde un grupo).');
        return false;
    }
    $ya = (int)valor('SELECT COUNT(*) FROM acad_tg_chats WHERE familia_id = ?', [$f['id']]);
    $existe = (bool)fila('SELECT id FROM acad_tg_chats WHERE familia_id = ? AND chat_id = ?', [$f['id'], $chat]);
    if (!$existe && $ya >= 4) {
        tg_encolar((int)$f['liga_id'], $chat, 'Esta familia ya tiene 4 Telegram conectados. Desconecte uno desde el portal de padres y vuelva a intentarlo.');
        return false;
    }
    $nombre = trim((string)($msg['from']['first_name'] ?? $msg['chat']['first_name'] ?? '') . ' ' . (string)($msg['from']['last_name'] ?? $msg['chat']['last_name'] ?? ''));
    q('INSERT INTO acad_tg_chats (familia_id, chat_id, nombre) VALUES (?,?,?) ON CONFLICT(familia_id, chat_id) DO UPDATE SET nombre = excluded.nombre', [$f['id'], $chat, mb_substr($nombre, 0, 80)]);
    $liga = fila('SELECT * FROM ligas WHERE id = ?', [$f['liga_id']]);
    $tipos = array_filter(acad_tg_tipos(), fn($k) => (int)acad_tg_opciones($liga)[$k] === 1, ARRAY_FILTER_USE_KEY);
    tg_encolar((int)$f['liga_id'], $chat, '✅ Listo. Desde ahora recibirá aquí los avisos de <b>' . e($f['academia']) . '</b>'
        . ($tipos ? ': ' . e(mb_strtolower(implode(', ', array_column($tipos, 0)))) . '.' : '.') . "\n\nPara dejar de recibirlos, entre al portal de padres → Avisos por Telegram → Desconectar.");
    return true;
}

/**
 * Pone en la cola un aviso para una familia, una sola vez por clave. Nunca lanza errores.
 * $claves: una o varias claves únicas (por ejemplo una por cada valor avisado). Si TODAS ya se avisaron, no se manda nada.
 * Devuelve cuántos mensajes se pusieron en la cola (uno por Telegram conectado).
 */
function acad_tg_avisar(array $liga, int $fam_id, string $tipo, array $claves, string $texto): int
{
    try {
        if (!acad_tg_tipo_activo($liga, $tipo) || $texto === '' || !$claves) return 0;
        $chats = acad_tg_chats_familia($fam_id);
        if (!$chats) return 0;
        $lid = (int)$liga['id'];
        $nuevas = 0;
        foreach (array_values(array_unique($claves)) as $i => $k) {
            $st = q('INSERT OR IGNORE INTO acad_tg_avisos (liga_id, familia_id, tipo, clave, texto, chats, creado) VALUES (?,?,?,?,?,?,?)',
                [$lid, $fam_id, $tipo, $lid . ':' . $k, $i === 0 ? mb_substr($texto, 0, 2000) : '', count($chats), date('Y-m-d H:i:s')]);
            $nuevas += $st->rowCount();
        }
        if (!$nuevas) return 0;
        $pie = "\n\n<i>" . e($liga['nombre']) . ' · ' . e(marca()) . '</i>';
        $id = substr(sha1($lid . ':' . implode('|', $claves)), 0, 16);
        foreach ($chats as $c) tg_encolar($lid, $c, $texto . $pie, '', 'acadtg:' . $id . ':' . $c);
        return count($chats);
    } catch (Throwable $ex) {
        error_log('avisos padres: ' . $ex->getMessage());
        return 0;
    }
}

/** Valores pendientes de una lista con el nombre del alumno, agrupados por familia (solo familias con Telegram conectado). */
function acad_tg_cargos_por_familia(int $lid, string $donde, array $params): array
{
    $cs = filas("SELECT c.*, a.nombres, f.id AS fam_id FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id
                 JOIN esc_familias f ON f.liga_id = a.liga_id AND f.cedula = a.representante_cedula
                 WHERE c.liga_id = ? AND a.representante_cedula <> '' AND EXISTS (SELECT 1 FROM acad_tg_chats t WHERE t.familia_id = f.id) AND $donde
                 ORDER BY c.vence, a.nombres", array_merge([$lid], $params));
    $r = [];
    foreach ($cs as $c) $r[(int)$c['fam_id']][] = $c;
    return $r;
}

/** Línea de un valor: "• Pensión de octubre · Emilia: $40,00 (vence el 05/10)". */
function acad_tg_linea_cargo(array $c, string $hoy): string
{
    $v = (string)$c['vence'];
    $cuando = $v === '' ? '' : ($v < $hoy ? ' (venció el ' . date('d/m', strtotime($v)) . ')' : ($v === $hoy ? ' (vence hoy)' : ' (vence el ' . date('d/m', strtotime($v)) . ')'));
    return '• ' . e($c['descripcion']) . ' · ' . e($c['nombres']) . ': <b>' . e(dinero((float)$c['monto'] - (float)$c['descuento'])) . '</b>' . $cuando;
}

function acad_tg_como_pagar(array $liga): string
{
    $f = [];
    if (trim((string)($liga['deuna_celular'] ?? '')) !== '') $f[] = 'DeUna al ' . e(trim($liga['deuna_celular']));
    if (trim((string)($liga['datos_pago'] ?? '')) !== '') $f[] = 'transferencia (' . e(preg_replace('/\s+/', ' ', trim($liga['datos_pago']))) . ')';
    return ($f ? "\nPuede pagar por " . implode(' o por ', $f) . '.' : '') . "\nSuba el comprobante en su portal: " . url_base() . 'familia.php';
}

/**
 * Avisos diarios de pensiones (lo llama la tarea automática una vez al día; también se puede llamar a mano: no repite).
 * Devuelve ['por_vencer' => familias avisadas, 'vencida' => familias avisadas].
 */
function acad_tg_avisos_pensiones(array $liga, ?string $hoy = null): array
{
    $hoy ??= date('Y-m-d');
    $r = ['por_vencer' => 0, 'vencida' => 0];
    try {
        if (!acad_tg_disponible($liga)) return $r;
        $lid = (int)$liga['id'];
        $o = acad_tg_opciones($liga);
        $base = "c.estado = 'pendiente' AND a.estado = 'activo' AND (c.monto - c.descuento) > 0 AND COALESCE(c.comprobante_estado, '') <> 'pendiente' AND c.vence <> ''";
        if ($o['por_vencer']) {
            $hasta = date('Y-m-d', strtotime($hoy . ' +' . $o['dias'] . ' days'));
            foreach (acad_tg_cargos_por_familia($lid, "$base AND c.vence BETWEEN ? AND ?", [$hoy, $hasta]) as $fid => $cs) {
                $cs = array_values(array_filter($cs, fn($c) => !fila('SELECT id FROM acad_tg_avisos WHERE clave = ?', [$lid . ':porvencer:' . $c['id']])));
                if (!$cs) continue;
                $t = '🔔 <b>Recordatorio de pago</b>' . "\n" . (count($cs) === 1 ? 'Le recordamos este valor:' : 'Le recordamos estos valores:') . "\n"
                    . implode("\n", array_map(fn($c) => acad_tg_linea_cargo($c, $hoy), $cs)) . "\n" . acad_tg_como_pagar($liga);
                if (acad_tg_avisar($liga, $fid, 'por_vencer', array_map(fn($c) => 'porvencer:' . $c['id'], $cs), $t)) $r['por_vencer']++;
            }
        }
        if ($o['vencida']) {
            // Solo lo que venció en los últimos 30 días (al activar los avisos no se manda una lista de deudas viejas)
            $desde = date('Y-m-d', strtotime($hoy . ' -30 days'));
            foreach (acad_tg_cargos_por_familia($lid, "$base AND c.vence < ? AND c.vence >= ?", [$hoy, $desde]) as $fid => $cs) {
                $cs = array_values(array_filter($cs, fn($c) => !fila('SELECT id FROM acad_tg_avisos WHERE clave = ?', [$lid . ':vencida:' . $c['id']])));
                if (!$cs) continue;
                $t = '⚠️ <b>Pago vencido</b>' . "\n" . (count($cs) === 1 ? 'Este valor ya venció y sigue pendiente:' : 'Estos valores ya vencieron y siguen pendientes:') . "\n"
                    . implode("\n", array_map(fn($c) => acad_tg_linea_cargo($c, $hoy), $cs)) . "\n" . acad_tg_como_pagar($liga)
                    . "\n\nSi ya pagó, no tome en cuenta este mensaje.";
                if (acad_tg_avisar($liga, $fid, 'vencida', array_map(fn($c) => 'vencida:' . $c['id'], $cs), $t)) $r['vencida']++;
            }
        }
    } catch (Throwable $ex) {
        error_log('avisos padres (pensiones): ' . $ex->getMessage());
    }
    return $r;
}

/** Pago recibido: lo llama cobrar_cargos() después de guardar el pago (un aviso por recibo y familia). */
function acad_tg_pago_recibido(array $liga, int $recibo, array $ids): void
{
    try {
        if (!acad_tg_tipo_activo($liga, 'pago') || !$ids) return;
        $lid = (int)$liga['id'];
        $in = implode(',', array_map('intval', $ids));
        foreach (acad_tg_cargos_por_familia($lid, "c.id IN ($in) AND c.estado = 'pagado'", []) as $fid => $cs) {
            $tot = array_sum(array_map(fn($c) => (float)$c['monto'] - (float)$c['descuento'], $cs));
            $forma = formas_pago()[$cs[0]['forma_pago']] ?? $cs[0]['forma_pago'];
            $t = '✅ <b>Pago recibido</b>' . "\n" . implode("\n", array_map(fn($c) => '• ' . e($c['descripcion']) . ' · ' . e($c['nombres']) . ': ' . e(dinero((float)$c['monto'] - (float)$c['descuento'])), $cs))
                . "\nTotal: <b>" . e(dinero($tot)) . '</b>' . ($forma !== '' ? ' (' . e(mb_strtolower((string)$forma)) . ')' : '')
                . "\nRecibo N° " . str_pad((string)$recibo, 6, '0', STR_PAD_LEFT) . '. Lo puede ver en su portal: ' . url_base() . 'familia.php' . "\n\n¡Muchas gracias!";
            acad_tg_avisar($liga, $fid, 'pago', ['pago:' . $recibo . ':' . $fid], $t);
        }
    } catch (Throwable $ex) {
        error_log('avisos padres (pago): ' . $ex->getMessage());
    }
}

/** Faltas de una clase de curso (asistencia «Faltó»). Solo si la clase es de hoy o de ayer. */
function acad_tg_faltas_sesion(array $liga, int $sid): int
{
    $n = 0;
    try {
        if (!acad_tg_tipo_activo($liga, 'falta')) return 0;
        $s = fila('SELECT s.*, g.nombre AS curso, g.hora_inicio FROM esc_sesiones s JOIN esc_grupos g ON g.id = s.grupo_id WHERE s.id = ? AND g.liga_id = ?', [$sid, (int)$liga['id']]);
        if (!$s || $s['fecha'] < date('Y-m-d', strtotime('-1 day')) || $s['fecha'] > date('Y-m-d')) return 0;
        foreach (filas("SELECT a.id, a.nombres FROM esc_asistencia x JOIN esc_alumnos a ON a.id = x.alumno_id WHERE x.sesion_id = ? AND x.estado = 'A'", [$sid]) as $a) {
            $f = acad_tg_familia_de_alumno((int)$a['id']);
            if (!$f) continue;
            $cuando = $s['fecha'] === date('Y-m-d') ? 'hoy' : 'ayer';
            $t = '📋 <b>Falta a clase</b>' . "\n" . e($a['nombres']) . ' no vino ' . $cuando . ' a la clase de <b>' . e($s['curso']) . '</b>'
                . ($s['hora_inicio'] ? ' (' . e($s['hora_inicio']) . ')' : '') . '.' . "\nSi fue por un motivo de salud u otro, avísenos para justificar la falta.";
            if (acad_tg_avisar($liga, (int)$f['id'], 'falta', ['falta:' . $sid . ':' . $a['id']], $t)) $n++;
        }
    } catch (Throwable $ex) {
        error_log('avisos padres (falta): ' . $ex->getMessage());
    }
    return $n;
}

/** Falta a una clase particular («Faltó sin avisar»). */
function acad_tg_falta_particular(array $liga, int $aid, string $fecha, string $hora, int $clase_id): void
{
    try {
        if (!acad_tg_tipo_activo($liga, 'falta') || $fecha < date('Y-m-d', strtotime('-1 day')) || $fecha > date('Y-m-d')) return;
        $a = fila('SELECT id, nombres FROM esc_alumnos WHERE id = ? AND liga_id = ?', [$aid, (int)$liga['id']]);
        $f = $a ? acad_tg_familia_de_alumno($aid) : null;
        if (!$f) return;
        $t = '📋 <b>Falta a clase particular</b>' . "\n" . e($a['nombres']) . ' no vino ' . ($fecha === date('Y-m-d') ? 'hoy' : 'ayer') . ' a su clase particular' . ($hora !== '' ? ' de las ' . e($hora) : '') . '.'
            . "\nSi no puede venir, avise antes a la academia para no perder la clase.";
        acad_tg_avisar($liga, (int)$f['id'], 'falta', ['faltap:' . $clase_id], $t);
    } catch (Throwable $ex) {
        error_log('avisos padres (falta particular): ' . $ex->getMessage());
    }
}

/**
 * Comunicado de la academia para todas las familias con Telegram (o solo las de un curso).
 * $token evita mandarlo dos veces si se toca dos veces el botón. Devuelve cuántas familias lo recibirán.
 */
function acad_tg_comunicado(array $liga, string $titulo, string $texto, int $grupo_id, string $token): int
{
    if (!acad_tg_disponible($liga)) throw new RuntimeException('Los avisos por Telegram no están disponibles en su academia.');
    if (!acad_tg_tipo_activo($liga, 'comunicado')) throw new RuntimeException('Los comunicados están apagados. Actívelos arriba en «Qué avisos se envían».');
    $texto = trim($texto);
    if (mb_strlen($texto) < 5) throw new RuntimeException('Escriba el comunicado.');
    $lid = (int)$liga['id'];
    $where = "a.liga_id = ? AND a.estado = 'activo' AND a.representante_cedula <> ''";
    $p = [$lid];
    if ($grupo_id) { grupo_de_escuela($grupo_id, $lid); $where .= ' AND ' . sql_en_grupo('a', $grupo_id); }
    $fams = array_column(filas("SELECT DISTINCT f.id FROM esc_alumnos a JOIN esc_familias f ON f.liga_id = a.liga_id AND f.cedula = a.representante_cedula
                                 WHERE $where AND EXISTS (SELECT 1 FROM acad_tg_chats t WHERE t.familia_id = f.id)", $p), 'id');
    $token = preg_replace('/[^a-f0-9]/', '', $token) ?: bin2hex(random_bytes(6));
    $t = '📣 <b>' . e(trim($titulo) !== '' ? mb_substr(trim($titulo), 0, 100) : 'Comunicado') . '</b>' . "\n" . e(mb_substr($texto, 0, 1500));
    $n = 0;
    foreach ($fams as $fid) if (acad_tg_avisar($liga, (int)$fid, 'comunicado', ['com:' . $token . ':' . $fid], $t)) $n++;
    return $n;
}

/** Cifras para la pantalla «Avisos a padres». */
function acad_tg_cifras(int $lid): array
{
    $fam = (int)valor("SELECT COUNT(DISTINCT a.representante_cedula) FROM esc_alumnos a WHERE a.liga_id = ? AND a.estado = 'activo' AND a.representante_cedula <> ''", [$lid]);
    $con = (int)valor("SELECT COUNT(DISTINCT f.id) FROM esc_familias f JOIN acad_tg_chats t ON t.familia_id = f.id WHERE f.liga_id = ?
                       AND EXISTS (SELECT 1 FROM esc_alumnos a WHERE a.liga_id = f.liga_id AND a.representante_cedula = f.cedula AND a.estado = 'activo')", [$lid]);
    $mes = (int)valor("SELECT COUNT(*) FROM acad_tg_avisos WHERE liga_id = ? AND texto <> '' AND creado >= ?", [$lid, date('Y-m-01')]);
    return ['familias' => $fam, 'conectadas' => $con, 'avisos_mes' => $mes];
}

/** Tarjeta del portal de padres: conectar / desconectar Telegram. */
function acad_tg_tarjeta_familia(?array $liga, ?array $fam): string
{
    if (!$liga || !$fam || !acad_tg_disponible($liga)) return '';
    $chats = filas('SELECT * FROM acad_tg_chats WHERE familia_id = ? ORDER BY id', [$fam['id']]);
    $o = acad_tg_opciones($liga);
    $tipos = array_filter(acad_tg_tipos(), fn($k) => (int)$o[$k] === 1, ARRAY_FILTER_USE_KEY);
    $enlace = acad_tg_enlace_familia($fam);
    ob_start(); ?>
    <div class="tarjeta" id="telegram">
      <h2 style="margin-top:0">📲 Avisos por Telegram</h2>
      <?php if ($chats): ?>
        <p>✅ Conectado<?= count($chats) > 1 ? 's' : '' ?>: <b><?= e(implode(', ', array_map(fn($c) => $c['nombre'] !== '' ? $c['nombre'] : 'Telegram', $chats))) ?></b>. Aquí le llegan los avisos de la academia.</p>
      <?php else: ?>
        <p class="suave" style="margin-top:0">Reciba en su celular, sin entrar al portal, los avisos de la academia. Necesita tener instalada la aplicación <b>Telegram</b>.</p>
      <?php endif; ?>
      <?php if ($tipos): ?><p style="margin:.3rem 0">Le avisamos de: <?= e(mb_strtolower(implode(' · ', array_column($tipos, 0)))) ?>.</p><?php endif; ?>
      <div class="acciones">
        <?php if ($enlace !== ''): ?><a class="btn<?= $chats ? ' btn-sec btn-chico' : '' ?>" target="_blank" rel="noopener" href="<?= e($enlace) ?>"><?= $chats ? '+ Conectar otro Telegram (papá o mamá)' : 'Conectar Telegram' ?></a><?php endif; ?>
        <?php if ($chats): ?><form method="post" class="inline" onsubmit="return confirm('¿Dejar de recibir los avisos por Telegram?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="tg_desconectar"><button class="btn btn-sec btn-chico">Desconectar</button></form><?php endif; ?>
      </div>
      <?php if (!$chats): ?><p class="ayuda">Se abre Telegram: toque <b>INICIAR</b> (o <b>START</b>). En menos de un minuto queda conectado. El mismo botón sirve para conectar el Telegram de papá y de mamá.</p><?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}
