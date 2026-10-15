<?php
/*
 * (Tukán Central v2, Anexo A) PAUSAR Y BORRAR CUENTAS DE CLIENTES (con papelera). El mismo archivo sirve para TuVocalía y TuAcademia.
 * Lo usan el conector (app/tukan_central.php: pausar, reactivar, borrar_cliente, restaurar_cliente), el panel del
 * superadministrador (Ligas → Papelera) y la tarea automática (app/tareas.php: borrado definitivo a los 30 días).
 *
 *  PAUSA: la cuenta queda suspendida (activa = 0) con un TIPO y un MOTIVO (ligas.suspendida_tipo / suspendida_motivo).
 *    - Los usuarios de la cuenta ven «Tu cuenta está pausada» con el motivo (con abuso o revisión, un texto general) y el
 *      WhatsApp de Tukán (pantalla_cuenta_pausada()); sus clientes finales (página pública, portal de familias, pagos…)
 *      ven «temporalmente no disponible» (cortar_liga_publica()). reactivar_cuenta() la quita.
 *  PAPELERA: borrar_cuenta() guarda una COPIA COMPLETA de la cuenta (todas sus filas de todas las tablas, sacadas del esquema:
 *    tablas con liga_id o cliente_id y, siguiendo las claves foráneas, todas las que cuelgan de ellas) en
 *    data/borrados/<id>_<AAAAMMDD-HHMMSS>.json.gz (fuera de la carpeta pública) y la marca eliminada (ligas.eliminada):
 *    no puede entrar nadie, no sale en listas ni cifras, su página pública da 404 y no se le mandan avisos.
 *    restaurar_cuenta() la devuelve tal cual (los datos nunca salieron de la base). Pasados 30 días papelera_purgar() (tarea
 *    automática diaria) borra sus filas de verdad; la copia se guarda 1 año y luego también se borra.
 */

const PAPELERA_DIAS = 30;          // días en la papelera antes del borrado definitivo
const PAPELERA_COPIA_DIAS = 365;   // días que se guarda la copia (.json.gz)
const PAPELERA_DIR = 'data/borrados';

/** Tipos de pausa (Anexo A.1). */
function tipos_pausa(): array
{
    return ['falta_pago' => 'Falta de pago', 'pedido_cliente' => 'A pedido del cliente', 'revision' => 'En revisión', 'abuso' => 'Uso indebido', 'otro' => 'Otro motivo'];
}

/** ¿La cuenta está en la papelera? */
function liga_eliminada(?array $l): bool
{
    return $l !== null && (string)($l['eliminada'] ?? '') !== '';
}

/** ¿La cuenta está pausada (suspendida con tipo y motivo)? */
function liga_pausada(?array $l): bool
{
    return $l !== null && !liga_eliminada($l) && (int)($l['activa'] ?? 1) !== 1 && (string)($l['suspendida_tipo'] ?? '') !== '';
}

/** Condición SQL: sin las cuentas de la papelera ($a = alias de la tabla ligas, o '' sin alias). */
function sql_sin_papelera(string $a = 'l'): string
{
    return " AND COALESCE(" . ($a !== '' ? $a . '.' : '') . "eliminada, '') = ''";
}

/** ¿La cuenta con ese id está en la papelera? (consulta rápida, para las colas de avisos y los correos) */
function liga_id_eliminada(?int $id): bool
{
    static $memo = [];
    if (!$id) return false;
    if (!array_key_exists($id, $memo)) {
        try {
            $memo[$id] = (string)valor('SELECT eliminada FROM ligas WHERE id = ?', [$id]) !== '';
        } catch (Throwable) {
            $memo[$id] = false;
        }
    }
    return $memo[$id];
}

/** Lo que ve el cliente como motivo (abuso y revisión: un texto general). */
function texto_motivo_pausa(array $l): string
{
    $tipo = (string)($l['suspendida_tipo'] ?? '');
    if (in_array($tipo, ['abuso', 'revision'], true)) return 'Estamos revisando su cuenta. Escríbanos para más información.';
    $m = trim((string)($l['suspendida_motivo'] ?? ''));
    return $m !== '' ? $m : (tipos_pausa()[$tipo] ?? 'Comuníquese con nosotros.');
}

/** WhatsApp de Tukán (el de ventas del programa): [número para wa.me, número como se escribió]. */
function whatsapp_tukan(): array
{
    $n = trim((string)ajuste('ventas_whatsapp'));
    return [telefono_wa($n), $n];
}

/** Página sencilla (sin el menú del programa) para pausas, «no disponible» y 404. */
function pagina_sencilla(int $http, string $titulo, string $cuerpo_html, array $cabeceras = []): never
{
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        http_response_code($http);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        foreach ($cabeceras as $k => $v) header($k . ': ' . $v);
    }
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . e($titulo) . ' · ' . e(marca()) . '</title></head>'
        . '<body style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f4f6f5;margin:0;padding:40px 16px;color:#1d2a26">'
        . '<main style="max-width:480px;margin:0 auto;background:#fff;border-radius:14px;padding:26px;box-shadow:0 2px 10px rgba(0,0,0,.08)">'
        . $cuerpo_html . '</main></body></html>';
    exit;
}

/** «Tu cuenta está pausada»: la ven los usuarios de la cuenta (administrador, vocal, árbitro, delegado…). */
function pantalla_cuenta_pausada(array $l): never
{
    [$wa, $num] = whatsapp_tukan();
    $empresa = (string)ajuste('empresa');
    $texto = 'Hola, mi cuenta ' . $l['nombre'] . ' de ' . marca() . ' está pausada. Quiero más información.';
    $html = '<h1 style="font-size:1.35rem;margin-top:0" id="cuenta-pausada">⏸️ Tu cuenta está pausada</h1>'
        . '<p><b>' . e((string)$l['nombre']) . '</b> no está disponible por ahora. Tus datos siguen guardados.</p>'
        . '<p style="background:#fff6e5;border-left:4px solid #e0a100;padding:10px 12px;border-radius:6px"><b>Motivo:</b> ' . e(texto_motivo_pausa($l)) . '</p>'
        . '<p>Para reactivarla comunícate con ' . e($empresa !== '' ? $empresa : marca()) . '.</p>'
        . ($wa !== '' ? '<p><a href="https://wa.me/' . e($wa) . '?text=' . rawurlencode($texto) . '" style="display:inline-block;background:#1f9d55;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">WhatsApp ' . e($num) . '</a></p>' : '')
        . '<p style="margin-bottom:0"><a href="admin.php?p=salir" style="color:#555">Cerrar sesión</a></p>';
    pagina_sencilla(403, 'Tu cuenta está pausada', $html);
}

/** «Temporalmente no disponible»: la ven los clientes finales (página pública, familias, pagos…) de una cuenta pausada o suspendida. */
function pagina_no_disponible(array $l): never
{
    $html = '<h1 style="font-size:1.3rem;margin-top:0" id="no-disponible">' . e((string)$l['nombre']) . '</h1>'
        . '<p>Esta página está <b>temporalmente no disponible</b>. Vuelva a intentarlo más tarde.</p>';
    pagina_sencilla(503, 'Temporalmente no disponible', $html, ['Retry-After' => '86400']);
}

/** 404 de una cuenta que está en la papelera (como si no existiera). */
function pagina_no_encontrada(): never
{
    pagina_sencilla(404, 'No encontrado', '<h1 style="font-size:1.3rem;margin-top:0">Página no encontrada</h1><p>Esta dirección no existe.</p>');
}

/**
 * Para las páginas públicas y los portales: cuenta en la papelera → 404; pausada o suspendida (no demo) → «temporalmente no
 * disponible» (o, con $personal, la pantalla «Tu cuenta está pausada» para el vocal, árbitro o delegado de la cuenta).
 * $solo_pausa: las páginas que antes funcionaban con la cuenta suspendida solo se cortan si está PAUSADA (Anexo A) o eliminada.
 */
function cortar_liga_publica(?array $l, bool $personal = false, bool $solo_pausa = false): void
{
    if (!$l) return;
    if (liga_eliminada($l)) pagina_no_encontrada();
    if ((int)($l['activa'] ?? 1) === 1 || !empty($l['es_demo'])) return;
    if ($solo_pausa && !liga_pausada($l)) return;
    if ($personal && liga_pausada($l)) pantalla_cuenta_pausada($l);
    pagina_no_disponible($l);
}

/* ============================================================
 * PAUSAR / REACTIVAR
 * ============================================================ */

/**
 * Pausa la cuenta (Anexo A.1): la suspende (activa = 0) y guarda el tipo y el motivo. Si ya estaba pausada con lo mismo, false.
 * Si estaba suspendida (sin tipo) o pausada por otra cosa, cambia el tipo y el motivo.
 */
function pausar_cuenta(array $l, string $tipo, string $motivo): bool
{
    if (!isset(tipos_pausa()[$tipo])) throw new RuntimeException('Tipo de pausa no válido.');
    $motivo = mb_substr(trim($motivo), 0, 300);
    if (liga_suspendida($l) && (string)($l['suspendida_tipo'] ?? '') === $tipo && (string)$l['suspendida_motivo'] === $motivo) return false;
    q("UPDATE ligas SET activa = 0, suspendida_tipo = ?, suspendida_motivo = ?, suspendida_en = CASE WHEN suspendida_en = '' OR activa = 1 THEN ? ELSE suspendida_en END WHERE id = ?",
        [$tipo, $motivo, date('Y-m-d H:i:s'), $l['id']]);
    bitacora_evento('suscripcion', 'Pausó la cuenta ' . $l['nombre'] . ' (' . tipos_pausa()[$tipo] . '): ' . $motivo, (int)$l['id']);
    return true;
}

/* ============================================================
 * PAPELERA: qué filas son de una cuenta (sacado del esquema)
 * ============================================================ */

/**
 * Plan de la papelera, sacado del esquema de la base (no de una lista a mano):
 *  - «ligas» (la fila de la cuenta);
 *  - toda tabla con columna liga_id, o cliente_id sin clave foránea (el «cliente» del Panel Tukán es la cuenta);
 *  - toda tabla con una clave foránea (declarada) hacia una de las anteriores, y así sucesivamente;
 *  - columnas «<algo>_id» sin clave foránea declarada que apuntan por el nombre a una tabla de la cuenta
 *    (partido_id → partidos, venta_id → bol_ventas, ticket_id → bol_tickets…).
 * Devuelve [tabla => ['directo' => [condiciones], 'enlaces' => [[columna, tabla_padre, columna_padre]], 'columnas' => [...]]].
 */
function papelera_plan(): array
{
    $pdo = db();
    $tablas = [];
    foreach ($pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_KEY_PAIR) as $t => $sql) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $t) || $t === 'ligas_borradas') continue;
        $cols = array_column($pdo->query('PRAGMA table_info("' . $t . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $fks = [];
        foreach ($pdo->query('PRAGMA foreign_key_list("' . $t . '")')->fetchAll(PDO::FETCH_ASSOC) as $f) $fks[(int)$f['id']][] = $f;
        $tablas[$t] = ['cols' => $cols, 'fks' => $fks, 'sin_rowid' => stripos((string)$sql, 'WITHOUT ROWID') !== false];
    }
    // enlaces hijo → padre: claves foráneas declaradas (de una sola columna) y columnas «x_id» que nombran una tabla
    $enlaces = [];
    foreach ($tablas as $t => $x) {
        $con_fk = [];
        foreach ($x['fks'] as $grupo) {
            if (count($grupo) !== 1 || !isset($tablas[$grupo[0]['table']])) continue;
            $f = $grupo[0];
            $to = (string)($f['to'] ?? '') !== '' ? (string)$f['to'] : 'rowid';
            $enlaces[$t][] = [(string)$f['from'], (string)$f['table'], $to];
            $con_fk[$f['from']] = true;
        }
        foreach ($x['cols'] as $c) {
            if (isset($con_fk[$c]) || in_array($c, ['liga_id', 'cliente_id'], true) || !preg_match('/^([a-z]+)_id$/', $c, $m)) continue;
            foreach ([$m[1] . 's', $m[1] . 'es'] as $plural) {
                $padre = isset($tablas[$plural]) ? $plural : null;
                if ($padre === null) {
                    $cands = array_values(array_filter(array_keys($tablas), fn($n) => str_ends_with($n, '_' . $plural)));
                    $padre = count($cands) === 1 ? $cands[0] : null;
                }
                if ($padre !== null && $padre !== $t && in_array('id', $tablas[$padre]['cols'], true)) {
                    $enlaces[$t][] = [$c, $padre, 'id'];
                    break;
                }
            }
        }
    }
    // tablas de la cuenta: punto fijo
    $plan = ['ligas' => ['directo' => ['"id" = :L'], 'enlaces' => [], 'columnas' => $tablas['ligas']['cols'] ?? [], 'sin_rowid' => false]];
    foreach ($tablas as $t => $x) {
        if ($t === 'ligas') continue;
        $dir = [];
        if (in_array('liga_id', $x['cols'], true)) $dir[] = '"liga_id" = :L';
        $fk_cliente = false;
        foreach ($x['fks'] as $g) foreach ($g as $f) if ($f['from'] === 'cliente_id') $fk_cliente = true;
        if (!$fk_cliente && in_array('cliente_id', $x['cols'], true)) $dir[] = 'CAST("cliente_id" AS TEXT) = \':L\'';
        if ($dir) $plan[$t] = ['directo' => $dir, 'enlaces' => [], 'columnas' => $x['cols'], 'sin_rowid' => $x['sin_rowid']];
    }
    do {
        $cambio = false;
        foreach ($enlaces as $t => $lista) {
            foreach ($lista as $e) {
                if ($t === 'ligas' || !isset($plan[$e[1]])) continue;
                if (!isset($plan[$t])) {
                    $plan[$t] = ['directo' => [], 'enlaces' => [], 'columnas' => $tablas[$t]['cols'], 'sin_rowid' => $tablas[$t]['sin_rowid']];
                    $cambio = true;
                }
                if (!in_array($e, $plan[$t]['enlaces'], true)) {
                    $plan[$t]['enlaces'][] = $e;
                    $cambio = true;
                }
            }
        }
    } while ($cambio);
    ksort($plan);
    return $plan;
}

/**
 * Marca en la tabla temporal _papelera (tabla, rowid) todas las filas de la cuenta. Devuelve [tabla => cantidad].
 * Se hace por pasadas: cada tabla toma sus filas directas y las que cuelgan de filas ya marcadas, hasta que no cambia nada.
 */
function papelera_marcar(int $lid, array $plan): array
{
    $pdo = db();
    $pdo->exec('CREATE TEMP TABLE IF NOT EXISTS _papelera (t TEXT NOT NULL, rid INTEGER NOT NULL, PRIMARY KEY (t, rid))');
    $pdo->exec('DELETE FROM _papelera');
    $L = (string)$lid;
    for ($pasada = 0; $pasada < 30; $pasada++) {
        $antes = (int)$pdo->query('SELECT COUNT(*) FROM _papelera')->fetchColumn();
        foreach ($plan as $t => $x) {
            if ($x['sin_rowid']) continue; // (no hay tablas así en el programa; si apareciera una, no se toca)
            $cond = array_map(fn($c) => str_replace(':L', $L, $c), $x['directo']);
            foreach ($x['enlaces'] as [$col, $padre, $pcol]) {
                $cond[] = '"' . $col . '" IN (SELECT p."' . $pcol . '" FROM "' . $padre . '" p WHERE p.rowid IN (SELECT rid FROM _papelera WHERE t = ' . $pdo->quote($padre) . '))';
            }
            if (!$cond) continue;
            $pdo->exec('INSERT OR IGNORE INTO _papelera (t, rid) SELECT ' . $pdo->quote($t) . ', rowid FROM "' . $t . '" WHERE ' . implode(' OR ', $cond));
        }
        if ((int)$pdo->query('SELECT COUNT(*) FROM _papelera')->fetchColumn() === $antes) break;
    }
    return array_map('intval', array_column($pdo->query('SELECT t, COUNT(*) AS n FROM _papelera GROUP BY t ORDER BY t')->fetchAll(PDO::FETCH_ASSOC), 'n', 't'));
}

/** Valor de una celda para el JSON (lo que no es UTF-8 válido va en base64). */
function papelera_valor(mixed $v): mixed
{
    if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) return ['_b64' => base64_encode($v)];
    return $v;
}

/**
 * Guarda la copia completa de la cuenta en data/borrados/<id>_<AAAAMMDD-HHMMSS>.json.gz (gzip, JSON).
 * Formato: {"formato": "tukan-papelera-1", "producto", "cuenta": {id, nombre, slug}, "creado", "motivo", "por",
 *           "conteo": {tabla: n}, "tablas": {tabla: {"columnas": [...], "filas": [[...], ...]}}}.
 * Devuelve ['ruta' => 'data/borrados/…', 'conteo' => [...], 'filas' => total].
 */
function papelera_exportar(array $l, string $motivo, string $por, ?string $momento = null): array
{
    $pdo = db();
    $plan = papelera_plan();
    $conteo = papelera_marcar((int)$l['id'], $plan);
    $dir = RAIZ . '/' . PAPELERA_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('No se pudo crear la carpeta de copias (' . PAPELERA_DIR . ').');
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    $ts = $momento !== null ? strtotime($momento) : time();
    $nombre = (int)$l['id'] . '_' . date('Ymd-His', $ts) . '.json.gz';
    $n = 1;
    while (is_file($dir . '/' . $nombre)) $nombre = (int)$l['id'] . '_' . date('Ymd-His', $ts) . '-' . (++$n) . '.json.gz';
    $tmp = $dir . '/.' . $nombre . '.tmp';
    $gz = gzopen($tmp, 'wb6');
    if ($gz === false) throw new RuntimeException('No se pudo escribir la copia de la cuenta.');
    $jf = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
    $cab = ['formato' => 'tukan-papelera-1', 'producto' => function_exists('tc_producto') ? tc_producto()[0] : marca(), 'cuenta' => ['id' => (int)$l['id'], 'nombre' => (string)$l['nombre'], 'slug' => (string)($l['slug'] ?? '')],
            'creado' => date('c', $ts), 'motivo' => $motivo, 'por' => $por, 'conteo' => (object)$conteo];
    $total = 0;
    try {
        gzwrite($gz, substr(json_encode($cab, $jf), 0, -1) . ',"tablas":{'); // sin la última «}»: siguen las tablas
        $primera = true;
        foreach ($conteo as $t => $cant) {
            if (!isset($plan[$t])) continue;
            $cols = $plan[$t]['columnas'];
            gzwrite($gz, ($primera ? '' : ',') . json_encode($t) . ':{"columnas":' . json_encode($cols, $jf) . ',"filas":[');
            $primera = false;
            $st = $pdo->query('SELECT ' . implode(', ', array_map(fn($c) => 'x."' . $c . '"', $cols)) . ' FROM "' . $t . '" x WHERE x.rowid IN (SELECT rid FROM _papelera WHERE t = ' . $pdo->quote($t) . ') ORDER BY x.rowid');
            $i = 0;
            while ($f = $st->fetch(PDO::FETCH_NUM)) {
                $linea = json_encode(array_map('papelera_valor', $f), $jf);
                if ($linea === false) throw new RuntimeException('No se pudo guardar una fila de ' . $t . '.');
                gzwrite($gz, ($i++ ? ',' : '') . $linea);
                $total++;
            }
            gzwrite($gz, ']}');
        }
        gzwrite($gz, '}}');
        gzclose($gz);
    } catch (Throwable $e) {
        @gzclose($gz);
        @unlink($tmp);
        throw $e;
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $dir . '/' . $nombre)) { @unlink($tmp); throw new RuntimeException('No se pudo guardar la copia de la cuenta.'); }
    return ['ruta' => PAPELERA_DIR . '/' . $nombre, 'conteo' => $conteo, 'filas' => $total];
}

/** Lee una copia (para revisarla o restaurarla a mano). */
function papelera_leer_copia(string $ruta): ?array
{
    $abs = str_starts_with($ruta, '/') ? $ruta : RAIZ . '/' . $ruta;
    if (!is_file($abs)) return null;
    $d = json_decode((string)gzdecode((string)file_get_contents($abs)), true);
    return is_array($d) ? $d : null;
}

/**
 * Pasa la cuenta a la PAPELERA: copia completa en data/borrados/ y borrado lógico. Los avisos de Telegram que estaban
 * por salir para esa cuenta ya no salen. Devuelve ['copia', 'borrar_definitivo_el', 'filas', 'conteo', 'ya_estaba'].
 */
function borrar_cuenta(array $l, string $motivo, string $por): array
{
    if (!empty($l['es_demo'])) throw new RuntimeException('La demostración del sistema no se borra desde aquí.');
    if (liga_eliminada($l)) {
        return ['copia' => (string)$l['eliminada_copia'], 'borrar_definitivo_el' => (string)$l['eliminada_hasta'], 'filas' => null, 'conteo' => null, 'ya_estaba' => true];
    }
    $motivo = mb_substr(trim($motivo), 0, 300);
    $ahora = date('Y-m-d H:i:s');
    $hasta = date('Y-m-d', strtotime(date('Y-m-d') . ' +' . PAPELERA_DIAS . ' days'));
    $x = papelera_exportar($l, $motivo, $por, $ahora);
    try {
        q("UPDATE ligas SET eliminada = ?, eliminada_motivo = ?, eliminada_por = ?, eliminada_hasta = ?, eliminada_copia = ?, eliminada_activa = activa, activa = 0 WHERE id = ?",
            [$ahora, $motivo, mb_substr($por, 0, 200), $hasta, $x['ruta'], $l['id']]);
        q("UPDATE telegram_cola SET intentos = 5, error = 'Cuenta en la papelera' WHERE liga_id = ? AND enviado = ''", [$l['id']]);
        q('INSERT INTO ligas_borradas (cuenta_id, nombre, motivo, por, eliminada, borrar_el, copia, filas, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$l['id'], $l['nombre'], $motivo, mb_substr($por, 0, 200), $ahora, $hasta, $x['ruta'], $x['filas'], 'papelera']);
        bitacora_evento('suscripcion', 'Pasó la cuenta ' . $l['nombre'] . ' a la papelera (se borra de verdad el ' . $hasta . '; copia ' . $x['ruta'] . ', ' . $x['filas'] . ' filas). Motivo: ' . $motivo, (int)$l['id']);
    } catch (Throwable $e) {
        @unlink(RAIZ . '/' . $x['ruta']);
        throw $e;
    }
    return ['copia' => $x['ruta'], 'borrar_definitivo_el' => $hasta, 'filas' => $x['filas'], 'conteo' => $x['conteo'], 'ya_estaba' => false];
}

/** Saca la cuenta de la papelera, tal cual estaba (también su suspensión o pausa). false si no estaba en la papelera. */
function restaurar_cuenta(array $l, string $por): bool
{
    if (!liga_eliminada($l)) return false;
    q("UPDATE ligas SET activa = eliminada_activa, eliminada = '', eliminada_motivo = '', eliminada_por = '', eliminada_hasta = '', eliminada_copia = '', eliminada_activa = 1 WHERE id = ?", [$l['id']]);
    q("UPDATE ligas_borradas SET estado = 'restaurada', terminado = ? WHERE cuenta_id = ? AND estado = 'papelera'", [date('Y-m-d H:i:s'), $l['id']]);
    bitacora_evento('suscripcion', 'Restauró la cuenta ' . $l['nombre'] . ' desde la papelera (' . mb_substr($por, 0, 120) . ')', (int)$l['id']);
    return true;
}

/**
 * Borrado DEFINITIVO de una cuenta de la papelera: borra todas sus filas (las mismas de la copia) sin copiarlas en la
 * bitácora, quita las referencias que otras cuentas tenían a ella (combo, federación, recomendada por) y anota el borrado.
 * La bitácora de auditoría NO se borra (el programa la protege con un disparador): sus líneas van en la copia y se quedan.
 * Si la copia no existe (alguien la borró), se vuelve a hacer antes de borrar. Debe llamarse SIN una transacción abierta.
 */
function papelera_purgar_cuenta(array $l): int
{
    $pdo = db();
    if (!liga_eliminada($l)) throw new RuntimeException('Esa cuenta no está en la papelera.');
    if ($pdo->inTransaction()) throw new LogicException('El borrado definitivo no se hace dentro de una transacción.');
    $lid = (int)$l['id'];
    $copia = (string)$l['eliminada_copia'];
    if ($copia === '' || !is_file(RAIZ . '/' . $copia)) {
        $copia = papelera_exportar($l, (string)$l['eliminada_motivo'], 'Tarea automática (copia rehecha antes del borrado definitivo)')['ruta'];
    }
    return sin_auditoria(function () use ($pdo, $lid, $l, $copia) {
        $conteo = papelera_marcar($lid, papelera_plan());
        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            // tablas protegidas contra el borrado (la BITÁCORA de auditoría: un disparador BEFORE DELETE con RAISE(ABORT)) se quedan
            // como están: van en la copia, pero no se borran (así lo quiere el programa: la bitácora nunca se borra)
            $protegidas = [];
            foreach ($pdo->query("SELECT tbl_name, sql FROM sqlite_master WHERE type = 'trigger'")->fetchAll(PDO::FETCH_ASSOC) as $tr) {
                if (preg_match('/BEFORE\s+DELETE\b.*RAISE\s*\(\s*ABORT/is', (string)$tr['sql'])) $protegidas[$tr['tbl_name']] = true;
            }
            $pdo->beginTransaction();
            $total = 0;
            foreach (array_keys($conteo) as $t) {
                if ($t === 'ligas' || isset($protegidas[$t])) continue;
                $total += $pdo->exec('DELETE FROM "' . $t . '" WHERE rowid IN (SELECT rid FROM _papelera WHERE t = ' . $pdo->quote($t) . ')');
            }
            // otras cuentas que la nombraban (combo, federación, recomendada por): quedan sin ese dato
            $cols = array_column($pdo->query('PRAGMA table_info(ligas)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            foreach (['combo_id', 'federacion_id', 'referido_por'] as $c) {
                if (in_array($c, $cols, true)) $pdo->exec('UPDATE ligas SET "' . $c . '" = NULL WHERE "' . $c . '" = ' . $lid);
            }
            $total += $pdo->exec('DELETE FROM ligas WHERE id = ' . $lid);
            $pdo->prepare("UPDATE ligas_borradas SET estado = 'borrada', terminado = ?, copia = ? WHERE cuenta_id = ? AND estado = 'papelera'")->execute([date('Y-m-d H:i:s'), $copia, $lid]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('DELETE FROM _papelera');
        }
        bitacora_evento('suscripcion', 'Borrado definitivo de la cuenta ' . $l['nombre'] . ' (id ' . $lid . ', ' . $total . ' filas), 30 días después de pasar a la papelera. Copia guardada 1 año: ' . $copia, null);
        return $total;
    });
}

/**
 * Tarea automática (una vez al día, app/tareas.php): borra de verdad las cuentas que cumplieron 30 días en la papelera y
 * borra las copias de más de 1 año. $hoy (AAAA-MM-DD) solo para las pruebas. Devuelve ['cuentas' => [id => filas], 'copias' => n].
 */
function papelera_purgar(?string $hoy = null): array
{
    $hoy ??= date('Y-m-d');
    $r = ['cuentas' => [], 'copias' => 0];
    foreach (filas("SELECT * FROM ligas WHERE COALESCE(eliminada, '') <> '' AND eliminada_hasta <> '' AND eliminada_hasta <= ? ORDER BY id", [$hoy]) as $l) {
        try {
            $r['cuentas'][(int)$l['id']] = papelera_purgar_cuenta($l);
        } catch (Throwable $ex) {
            error_log('papelera (cuenta ' . $l['id'] . '): ' . $ex->getMessage());
        }
    }
    $limite = date('Ymd', strtotime($hoy . ' -' . PAPELERA_COPIA_DIAS . ' days'));
    foreach (glob(RAIZ . '/' . PAPELERA_DIR . '/*.json.gz') ?: [] as $f) {
        if (preg_match('/^\d+_(\d{8})-\d{6}/', basename($f), $m) && $m[1] < $limite && @unlink($f)) $r['copias']++;
    }
    return $r;
}
