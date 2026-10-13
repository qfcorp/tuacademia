<?php
/*
 * VERSIÓN 9 — Funciones nuevas:
 *   1) Copiar un campeonato para la nueva temporada (mismas reglas y, si se quiere, los mismos equipos)
 *   2) Vendedores con comisión: venden TuVocalía con su código; ganan un % de los pagos de suscripción de sus ligas
 */

/* ============================================================
 * 1) COPIAR CAMPEONATO
 * ============================================================ */

/**
 * Crea un campeonato nuevo con las mismas reglas (juego, dinero, categoría, árbitros) de otro.
 * No copia partidos, resultados, sorteo, campeón ni fechas de inscripción. Devuelve el id nuevo.
 */
function copiar_campeonato(array $liga, int $tid, string $nombre, string $temporada, bool $con_equipos, bool $con_grupos): int
{
    $t = fila('SELECT * FROM torneos WHERE id = ? AND liga_id = ?', [$tid, $liga['id']]) ?? throw new RuntimeException('Campeonato no encontrado.');
    if (es_individual($t)) throw new RuntimeException('Los campeonatos de deportes individuales (carreras, duelos, bolos) todavía no se pueden copiar: cree uno nuevo desde «Campeonatos».');
    $nombre = trim($nombre);
    if (mb_strlen($nombre) < 3) throw new RuntimeException('Escriba el nombre del campeonato nuevo.');
    verificar_limite_plan($liga, 'campeonato');
    $nuevo = $t;
    foreach (['id', 'creado', 'fecha_sorteo', 'campeon_id', 'subcampeon_id', 'jugadores_hasta', 'equipos_hasta', 'slug'] as $c) unset($nuevo[$c]);
    $nuevo['nombre'] = mb_substr($nombre, 0, 80);
    $nuevo['temporada'] = mb_substr(trim($temporada), 0, 20);
    $nuevo['estado'] = 'inscripcion';
    $nuevo['edad_corte'] = ''; // la fecha de corte de edad se calcula con la temporada nueva
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO torneos (' . implode(', ', array_keys($nuevo)) . ') VALUES (' . implode(', ', array_fill(0, count($nuevo), '?')) . ')', array_values($nuevo));
        $id = (int)$pdo->lastInsertId();
        $n = 0;
        if ($con_equipos) {
            foreach (filas('SELECT te.* FROM torneo_equipos te JOIN equipos e ON e.id = te.equipo_id WHERE te.torneo_id = ? AND e.liga_id = ?', [$tid, $liga['id']]) as $te) {
                q('INSERT OR IGNORE INTO torneo_equipos (torneo_id, equipo_id, grupo) VALUES (?,?,?)', [$id, $te['equipo_id'], $con_grupos ? $te['grupo'] : '']);
                $n++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    bitacora_evento('copia', 'Creó el campeonato ' . $nombre . ' copiando ' . $t['nombre'] . ' ' . $t['temporada'] . ($con_equipos ? ' con ' . $n . ' equipos' : ' sin equipos'), (int)$liga['id'], $id);
    return $id;
}

/** Temporada sugerida para la copia: si la anterior tiene un año, el siguiente; si no, el año actual. */
function temporada_siguiente(string $temp): string
{
    if (preg_match('/(20\d\d)/', $temp, $m)) return str_replace($m[1], (string)((int)$m[1] + 1), $temp);
    return date('Y');
}

/* ============================================================
 * 2) VENDEDORES CON COMISIÓN
 * ============================================================ */

/** Código corto y único para el vendedor (ej: JUAN25). */
function codigo_vendedor_nuevo(string $nombre): string
{
    $base = strtoupper(substr(preg_replace('/[^a-z]/', '', slugify(strtok(trim($nombre), ' ') ?: 'VEND')), 0, 6)) ?: 'VEND';
    do {
        $c = $base . random_int(10, 99);
    } while (fila('SELECT id FROM vendedores WHERE codigo = ?', [$c]));
    return $c;
}

function vendedor_por_codigo(string $codigo): ?array
{
    $codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo));
    return $codigo === '' ? null : fila('SELECT * FROM vendedores WHERE codigo = ? AND activo = 1', [$codigo]);
}

/** Enlace de registro con el código del vendedor. */
function enlace_vendedor(array $v): string
{
    return url_base() . 'registro?ref=' . rawurlencode($v['codigo']);
}

/** Guarda el código del vendedor que trajo al visitante (galleta de 60 días). */
function recordar_vendedor(): void
{
    $ref = get('ref');
    if ($ref === '' || headers_sent()) return;
    $v = vendedor_por_codigo($ref);
    if ($v) setcookie('ref_vendedor', $v['codigo'], ['expires' => time() + 60 * 86400, 'path' => '/', 'samesite' => 'Lax']);
}

/** Vendedor del visitante actual (por la galleta o por el código escrito). */
function vendedor_del_visitante(string $escrito = ''): ?array
{
    if ($escrito !== '') return vendedor_por_codigo($escrito);
    $c = $_COOKIE['ref_vendedor'] ?? '';
    return is_string($c) && $c !== '' ? vendedor_por_codigo($c) : null;
}

/**
 * Calcula la comisión de un pago de suscripción (si la liga tiene vendedor).
 * La comisión cubre solo los primeros N meses pagados por la liga (comision_meses; 0 = siempre).
 * Si el pago cubre meses que ya pasaron ese límite, se paga la parte proporcional.
 */
function generar_comision(int $pago_id): ?int
{
    $p = fila('SELECT ps.*, l.vendedor_id FROM pagos_suscripcion ps JOIN ligas l ON l.id = ps.liga_id WHERE ps.id = ?', [$pago_id]);
    if (!$p || !$p['vendedor_id'] || (float)$p['monto'] <= 0) return null;
    $v = fila('SELECT * FROM vendedores WHERE id = ?', [$p['vendedor_id']]);
    if (!$v) return null;
    if (fila('SELECT id FROM comisiones WHERE pago_suscripcion_id = ?', [$pago_id])) return null;
    $meses = max(1, (int)$p['meses']);
    $elegibles = $meses;
    if ((int)$v['comision_meses'] > 0) {
        $ya = (int)valor('SELECT COALESCE(SUM(meses), 0) FROM comisiones WHERE vendedor_id = ? AND liga_id = ? AND estado <> \'anulada\'', [$v['id'], $p['liga_id']]);
        $elegibles = max(0, min($meses, (int)$v['comision_meses'] - $ya));
    }
    if ($elegibles <= 0) return null;
    $comision = round((float)$p['monto'] * $elegibles / $meses * (float)$v['comision_pct'] / 100, 2);
    if ($comision <= 0) return null;
    q('INSERT INTO comisiones (vendedor_id, liga_id, pago_suscripcion_id, monto_pago, meses, porcentaje, comision) VALUES (?,?,?,?,?,?,?)',
        [$v['id'], $p['liga_id'], $pago_id, $p['monto'], $elegibles, $v['comision_pct'], $comision]);
    return (int)db()->lastInsertId();
}

/** Resumen de un vendedor: ligas, comisiones pendientes y pagadas. */
function resumen_vendedor(int $vid): array
{
    return [
        'ligas' => (int)valor('SELECT COUNT(*) FROM ligas WHERE vendedor_id = ?', [$vid]),
        'pagando' => (int)valor("SELECT COUNT(*) FROM ligas WHERE vendedor_id = ? AND es_prueba = 0 AND vence >= ?", [$vid, date('Y-m-d')]),
        'pendiente' => (float)valor("SELECT COALESCE(SUM(comision), 0) FROM comisiones WHERE vendedor_id = ? AND estado = 'pendiente'", [$vid]),
        'pagado' => (float)valor("SELECT COALESCE(SUM(comision), 0) FROM comisiones WHERE vendedor_id = ? AND estado = 'pagada'", [$vid]),
    ];
}
