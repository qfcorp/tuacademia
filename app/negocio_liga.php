<?php
/*
 * (v28) INDICADORES DEL NEGOCIO (para cada liga y escuela) y PROGRAMA DE REFERIDOS ("Recomiende y gane").
 */

/* ============================================================
 * REFERIDOS
 * ============================================================ */

/** Meses gratis que gana quien recomienda cuando la cuenta recomendada paga por primera vez. */
function meses_premio_referido(): int
{
    return max(0, (int)ajuste('referidos_meses', 1));
}

/** Código para recomendar (se crea la primera vez). */
function codigo_referido(array $l): string
{
    if (($l['ref_codigo'] ?? '') !== '') return $l['ref_codigo'];
    do {
        $c = 'R' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    } while (valor('SELECT id FROM ligas WHERE ref_codigo = ?', [$c]));
    q('UPDATE ligas SET ref_codigo = ? WHERE id = ?', [$c, $l['id']]);
    return $c;
}

/** Enlace para recomendar a otra liga o escuela. */
function enlace_referido(array $l, string $tipo = ''): string
{
    return url_base() . url_empezar($tipo) . '&rec=' . codigo_referido($l);
}

/** Cuenta que recomienda al visitante (por ?rec= o lo que quedó en la sesión). */
function recomendador_visitante(): ?array
{
    $c = strtoupper(trim((string)($_GET['rec'] ?? '')));
    if ($c !== '' && preg_match('/^R[0-9A-F]{5}$/', $c)) $_SESSION['recomienda'] = $c;
    $c = (string)($_SESSION['recomienda'] ?? '');
    return $c !== '' ? fila('SELECT * FROM ligas WHERE ref_codigo = ? AND es_demo = 0 AND activa = 1', [$c]) : null;
}

/** Anota que una cuenta nueva llegó recomendada por otra. */
function anotar_referido(int $nueva_id, int $por_id): void
{
    if ($nueva_id === $por_id || !$por_id) return;
    q('UPDATE ligas SET referido_por = ? WHERE id = ? AND referido_por IS NULL', [$por_id, $nueva_id]);
    q("INSERT OR IGNORE INTO referidos (liga_id, referido_id, estado) VALUES (?, ?, 'pendiente')", [$por_id, $nueva_id]);
    $por = fila('SELECT * FROM ligas WHERE id = ?', [$por_id]);
    $nueva = fila('SELECT * FROM ligas WHERE id = ?', [$nueva_id]);
    if ($por && $nueva) aviso_admins($por, '🤝 <b>¡Gracias por recomendarnos!</b>' . "\n" . e($nueva['nombre']) . ' empezó su demo con su enlace. Cuando contrate, usted gana ' . meses_premio_referido() . ' mes(es) de descuento.', 'ref1:' . $nueva_id);
}

/** Premia a quien recomendó cuando la cuenta recomendada paga por primera vez. */
function premiar_referido(array $l): void
{
    $r = fila("SELECT * FROM referidos WHERE referido_id = ? AND estado = 'pendiente'", [$l['id']]);
    if (!$r) return;
    $por = fila('SELECT * FROM ligas WHERE id = ? AND activa = 1', [$r['liga_id']]);
    $meses = meses_premio_referido();
    if (!$por || $meses < 1) {
        q("UPDATE referidos SET estado = 'sin_premio', premiado_en = ? WHERE id = ?", [date('Y-m-d H:i:s'), $r['id']]);
        return;
    }
    $base = $por['vence'] !== '' && $por['vence'] >= date('Y-m-d') ? $por['vence'] : date('Y-m-d');
    $hasta = $por['vence'] === '' && !$por['es_prueba'] ? '' : date('Y-m-d', strtotime($base . ' +' . $meses . ' months'));
    if ($hasta !== '') q('UPDATE ligas SET vence = ? WHERE id = ?', [$hasta, $por['id']]);
    q("UPDATE referidos SET estado = 'premiado', meses = ?, premiado_en = ? WHERE id = ?", [$meses, date('Y-m-d H:i:s'), $r['id']]);
    bitacora_evento('ventas', 'Premio por referido: ' . $meses . ' mes(es) de descuento por recomendar a ' . $l['nombre'] . ($hasta !== '' ? ' (ahora vence el ' . $hasta . ')' : ''), (int)$por['id']);
    aviso_admins($por, '🎁 <b>¡Ganó ' . $meses . ' mes(es) de descuento!</b>' . "\n" . e($l['nombre']) . ' contrató ' . e(marca()) . ' con su recomendación.' . ($hasta !== '' ? "\nSu cuenta ahora vence el <b>" . $hasta . '</b>.' : ''), 'ref2:' . $l['id']);
    aviso_super('🤝 <b>Referido que pagó</b>' . "\n" . e($l['nombre']) . ' (recomendada por ' . e($por['nombre']) . '). Premio: ' . $meses . ' mes(es) a ' . e($por['nombre']) . '.', 'ref3:' . $l['id']);
}

/* ============================================================
 * INDICADORES DEL NEGOCIO
 * ============================================================ */

/** Últimos $n meses como 'AAAA-MM' (del más viejo al actual). */
function meses_atras(int $n): array
{
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) $out[] = date('Y-m', strtotime(date('Y-m-01') . ' -' . $i . ' months'));
    return $out;
}

function mes_corto(string $ym): string
{
    $m = ['01' => 'ene', '02' => 'feb', '03' => 'mar', '04' => 'abr', '05' => 'may', '06' => 'jun', '07' => 'jul', '08' => 'ago', '09' => 'sep', '10' => 'oct', '11' => 'nov', '12' => 'dic'];
    return ($m[substr($ym, 5, 2)] ?? '') . ' ' . substr($ym, 2, 2);
}

/** Variación porcentual (null si no hay base). */
function variacion(float $antes, float $ahora): ?int
{
    return $antes > 0 ? (int)round(($ahora - $antes) * 100 / $antes) : null;
}

/** Indicadores de una ESCUELA. */
function indicadores_escuela(array $liga): array
{
    $lid = (int)$liga['id'];
    $meses = meses_atras(12);
    $ingresos = $altas = $bajas = [];
    foreach ($meses as $m) {
        $ingresos[$m] = (float)valor("SELECT COALESCE(SUM(monto - descuento), 0) FROM esc_cargos WHERE liga_id = ? AND estado = 'pagado' AND substr(pagado_en, 1, 7) = ?", [$lid, $m])
            + (float)valor("SELECT COALESCE(SUM(total), 0) FROM esc_ventas WHERE liga_id = ? AND modo = 'contado' AND anulado = 0 AND substr(fecha, 1, 7) = ?", [$lid, $m]);
        $altas[$m] = (int)valor("SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ? AND substr(COALESCE(NULLIF(fecha_ingreso, ''), creado), 1, 7) = ?", [$lid, $m]);
        $bajas[$m] = (int)valor("SELECT COUNT(*) FROM bitacora WHERE liga_id = ? AND tabla = 'esc_alumnos' AND operacion = 'modificar' AND despues LIKE '%\"estado\":\"retirado\"%' AND antes NOT LIKE '%\"estado\":\"retirado\"%' AND substr(fecha, 1, 7) = ?", [$lid, $m]);
    }
    $activos = (int)valor("SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ? AND estado = 'activo'", [$lid]);
    $retirados = (int)valor("SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ? AND estado = 'retirado'", [$lid]);
    $hoy = date('Y-m-d');
    $vencido = (float)valor("SELECT COALESCE(SUM(c.monto - c.descuento), 0) FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE c.liga_id = ? AND c.estado = 'pendiente' AND c.vence < ? AND a.estado = 'activo'", [$lid, $hoy]);
    $morosos = (int)valor("SELECT COUNT(DISTINCT c.alumno_id) FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE c.liga_id = ? AND c.estado = 'pendiente' AND c.vence < ? AND a.estado = 'activo'", [$lid, $hoy]);
    $mes = date('Y-m');
    $esperado = (float)valor("SELECT COALESCE(SUM(monto - descuento), 0) FROM esc_cargos WHERE liga_id = ? AND tipo = 'mensualidad' AND periodo = ?", [$lid, $mes]);
    $cobrado_mes = (float)valor("SELECT COALESCE(SUM(monto - descuento), 0) FROM esc_cargos WHERE liga_id = ? AND tipo = 'mensualidad' AND periodo = ? AND estado = 'pagado'", [$lid, $mes]);
    $asist = fila("SELECT COUNT(*) AS t, SUM(CASE WHEN x.estado IN ('P','T') THEN 1 ELSE 0 END) AS v FROM esc_asistencia x JOIN esc_sesiones s ON s.id = x.sesion_id JOIN esc_grupos g ON g.id = s.grupo_id WHERE g.liga_id = ? AND s.fecha >= ?", [$lid, date('Y-m-d', strtotime('-30 days'))]);
    $grupos = filas("SELECT g.id, g.nombre, g.cupo, (SELECT COUNT(*) FROM esc_alumnos a WHERE a.grupo_id = g.id AND a.estado = 'activo') AS n FROM esc_grupos g WHERE g.liga_id = ? AND g.activo = 1 ORDER BY g.nombre", [$lid]);
    $m_act = end($meses);
    $m_ant = $meses[count($meses) - 2];
    return [
        'tarjetas' => [
            ['Alumnos activos', $activos, ''],
            ['Ingresos de ' . mes_corto($m_act), dinero($ingresos[$m_act]), variacion($ingresos[$m_ant], $ingresos[$m_act])],
            ['Ingreso promedio por alumno (mes anterior)', $activos ? dinero($ingresos[$m_ant] / max(1, $activos)) : '—', null],
            ['Mensualidades del mes cobradas', $esperado > 0 ? round($cobrado_mes * 100 / $esperado) . '%' : '—', null],
            ['Deuda vencida', dinero($vencido) . ' · ' . $morosos . ' alumno(s)', null],
            ['Asistencia (30 días)', $asist['t'] ? round(100 * (int)$asist['v'] / (int)$asist['t']) . '%' : '—', null],
            ['Retiro histórico', ($activos + $retirados) ? round($retirados * 100 / ($activos + $retirados)) . '%' : '—', null],
        ],
        'series' => ['Ingresos ($)' => $ingresos, 'Alumnos nuevos' => $altas, 'Alumnos retirados' => $bajas],
        'ocupacion' => array_map(fn($g) => [$g['nombre'], (int)$g['n'], (int)$g['cupo']], $grupos),
    ];
}

/** Indicadores de una LIGA. */
function indicadores_liga(array $liga): array
{
    $lid = (int)$liga['id'];
    $meses = meses_atras(12);
    $ingresos = $egresos = $partidos = [];
    foreach ($meses as $m) {
        $ingresos[$m] = (float)valor("SELECT COALESCE(SUM(monto), 0) FROM movimientos WHERE liga_id = ? AND tipo = 'pago' AND anulado = 0 AND substr(fecha, 1, 7) = ?", [$lid, $m]);
        $egresos[$m] = (float)valor("SELECT COALESCE(SUM(monto), 0) FROM movimientos WHERE liga_id = ? AND tipo = 'egreso' AND anulado = 0 AND substr(fecha, 1, 7) = ?", [$lid, $m]);
        $partidos[$m] = (int)valor("SELECT COUNT(*) FROM partidos p JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ? AND p.estado IN ('jugado','wo') AND substr(p.fecha, 1, 7) = ?", [$lid, $m]);
    }
    $m_act = end($meses);
    $m_ant = $meses[count($meses) - 2];
    $equipos = (int)valor('SELECT COUNT(*) FROM equipos WHERE liga_id = ?', [$lid]);
    $jugadores = (int)valor('SELECT COUNT(*) FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE e.liga_id = ? AND j.activo = 1', [$lid]);
    $jugados = (int)valor("SELECT COUNT(*) FROM partidos p JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ? AND p.estado IN ('jugado','wo') AND p.fecha >= ?", [$lid, date('Y-m-d', strtotime('-90 days'))]);
    $con_vocal = (int)valor("SELECT COUNT(*) FROM partidos p JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ? AND p.estado IN ('jugado','wo') AND p.fecha >= ? AND p.vocal_cerrada = 1", [$lid, date('Y-m-d', strtotime('-90 days'))]);
    $wo = (int)valor("SELECT COUNT(*) FROM partidos p JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ? AND p.estado = 'wo' AND p.fecha >= ?", [$lid, date('Y-m-d', strtotime('-90 days'))]);
    // Por cobrar: saldo de cada equipo (cargos menos pagos) si la liga usa finanzas
    $por_cobrar = 0.0;
    $deudores = [];
    if (function_exists('saldo_equipo')) {
        foreach (filas('SELECT id, nombre FROM equipos WHERE liga_id = ?', [$lid]) as $e) {
            $s = (float)saldo_equipo((int)$e['id']);
            if ($s > 0.009) { $por_cobrar += $s; $deudores[] = [$e['nombre'], $s]; }
        }
        usort($deudores, fn($a, $b) => $b[1] <=> $a[1]);
    }
    $reservas = (float)valor("SELECT COALESCE(SUM(precio), 0) FROM reservas WHERE liga_id = ? AND estado IN ('confirmada','pagada') AND substr(fecha, 1, 7) = ?", [$lid, $m_act]);
    return [
        'tarjetas' => [
            ['Equipos', $equipos, ''],
            ['Jugadores activos', $jugadores, ''],
            ['Ingresos de ' . mes_corto($m_act), dinero($ingresos[$m_act]), variacion($ingresos[$m_ant], $ingresos[$m_act])],
            ['Resultado de ' . mes_corto($m_act) . ' (ingresos − gastos)', dinero($ingresos[$m_act] - $egresos[$m_act]), null],
            ['Por cobrar a los equipos', $deudores || $por_cobrar ? dinero($por_cobrar) . ' · ' . count($deudores) . ' equipo(s)' : '—', null],
            ['Partidos con vocalía en el celular (90 días)', $jugados ? round($con_vocal * 100 / $jugados) . '%' : '—', null],
            ['Partidos por W.O. (90 días)', $jugados ? $wo . ' (' . round($wo * 100 / $jugados) . '%)' : '—', null],
            ['Reservas de cancha de ' . mes_corto($m_act), $reservas > 0 ? dinero($reservas) : '—', null],
        ],
        'series' => ['Ingresos ($)' => $ingresos, 'Gastos ($)' => $egresos, 'Partidos jugados' => $partidos],
        'deudores' => array_slice($deudores, 0, 10),
    ];
}

/** Gráfico de barras sencillo en HTML (sin librerías). */
function html_barras(array $serie, bool $dinero = false): string
{
    $max = max(1, max(array_map('floatval', $serie)));
    $h = '<div class="barras">';
    foreach ($serie as $m => $v) {
        $pct = round((float)$v * 100 / $max);
        $h .= '<div class="barra-col" title="' . e(mes_corto($m) . ': ' . ($dinero ? dinero($v) : $v)) . '"><small>' . ($v > 0 ? e($dinero ? '$' . number_format((float)$v, 0, '.', ',') : (string)$v) : '') . '</small><span style="height:' . max(2, $pct) . '%"></span><em>' . e(mes_corto($m)) . '</em></div>';
    }
    return $h . '</div>';
}
