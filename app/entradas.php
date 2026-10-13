<?php
/*
 * (v28) VENTA DE ENTRADAS CON QR (módulo "entradas", se activa con una casilla en Parámetros de la liga)
 *
 *  - La liga abre la venta de un partido (o de un evento) con sus LOCALIDADES (General, Tribuna, Palco…) y, si quiere,
 *    PARQUEADERO (pide la placa). Cada localidad tiene precio y cupo.
 *  - El público compra en la página de la liga: paga con TARJETA (si la liga la activó) o por TRANSFERENCIA (sube el
 *    comprobante y la liga lo aprueba). También hay venta en TAQUILLA (efectivo) y CORTESÍAS.
 *  - Cada entrada tiene un código único con QR. En la puerta, el personal abre el LECTOR (puerta.php, enlace secreto de la
 *    venta) en su celular: verde = pasa, rojo = no pasa (ya usada, anulada o falsa). Funciona sin internet y sincroniza.
 *  - Reportes: vendidas, por canal, ingresaron, recaudado (con IVA e impuesto municipal incluidos si la liga los marca).
 */

const ENTRADA_MINUTOS_RESERVA = 20;

function entradas_activas(?array $liga): bool
{
    return $liga && (int)($liga['entradas_activas'] ?? 0) === 1 && modulo_activo($liga, 'entradas');
}

function estados_orden_entrada(): array
{
    return ['pendiente' => 'Esperando el pago', 'por_verificar' => 'Comprobante por revisar', 'pagada' => 'Pagada', 'rechazada' => 'Rechazada', 'anulada' => 'Anulada', 'expirada' => 'No se completó'];
}

function chip_orden_entrada(string $e): string
{
    $c = ['pagada' => 'chip-ok', 'por_verificar' => 'chip-pend', 'pendiente' => 'chip-pend', 'rechazada' => 'chip-mal', 'anulada' => 'chip-mal'][$e] ?? '';
    return '<span class="estado-chip ' . $c . '">' . e(estados_orden_entrada()[$e] ?? $e) . '</span>';
}

function venta_entradas(int $id, ?int $lid = null): ?array
{
    return fila('SELECT * FROM bol_ventas WHERE id = ?' . ($lid ? ' AND liga_id = ' . (int)$lid : ''), [$id]);
}

function localidades_venta(int $vid): array
{
    return filas('SELECT * FROM bol_localidades WHERE venta_id = ? ORDER BY tipo, orden, id', [$vid]);
}

/** Cuántas entradas de una localidad están ocupadas (pagadas, por revisar o reservadas mientras pagan). */
function ocupadas_localidad(int $loc_id): int
{
    return (int)valor("SELECT COALESCE(SUM(i.cantidad), 0) FROM bol_items i JOIN bol_ordenes o ON o.id = i.orden_id
                       WHERE i.localidad_id = ? AND (o.estado IN ('pagada','por_verificar') OR (o.estado = 'pendiente' AND o.expira_en > ?))", [$loc_id, date('Y-m-d H:i:s')]);
}

/** ¿La venta está abierta al público ahora? */
function venta_abierta(array $v): bool
{
    return $v['estado'] === 'abierta' && ($v['venta_hasta'] === '' || $v['venta_hasta'] >= date('Y-m-d H:i'));
}

/** Ventas abiertas de una liga (para su página pública). */
function ventas_abiertas(int $lid): array
{
    return array_values(array_filter(filas("SELECT * FROM bol_ventas WHERE liga_id = ? AND estado = 'abierta' ORDER BY fecha, hora", [$lid]), 'venta_abierta'));
}

/** Desglose de impuestos INCLUIDOS en el precio (si la liga los marcó). */
function impuestos_incluidos(array $liga, float $total): array
{
    $iva = (float)($liga['entradas_iva'] ?? 0);
    $mun = (float)($liga['entradas_municipal'] ?? 0);
    $base = $total / (1 + ($iva + $mun) / 100);
    return ['base' => round($base, 2), 'iva' => round($base * $iva / 100, 2), 'municipal' => round($base * $mun / 100, 2)];
}

/** Lee el pedido (cantidades por localidad y placas) y valida el cupo. Devuelve [items, total]. */
function leer_pedido_entradas(array $v, array $cant, string $placas_txt, bool $taquilla = false): array
{
    $items = [];
    $total = 0.0;
    $n = 0;
    $placas = array_values(array_filter(array_map(fn($p) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $p)), preg_split('/[,;\s]+/', $placas_txt))));
    $usadas = 0;
    foreach (localidades_venta((int)$v['id']) as $l) {
        $c = max(0, min(50, (int)($cant[$l['id']] ?? 0)));
        if (!$c) continue;
        $libres = (int)$l['cupo'] - ocupadas_localidad((int)$l['id']);
        if ((int)$l['cupo'] > 0 && $c > $libres) throw new RuntimeException('En ' . $l['nombre'] . ' ' . ($libres > 0 ? 'solo quedan ' . $libres : 'ya no quedan entradas') . '.');
        $pl = '';
        if ($l['tipo'] === 'parqueo') {
            $mias = array_slice($placas, $usadas, $c);
            if (count($mias) < $c) throw new RuntimeException('Escriba la placa de cada vehículo para el parqueadero (ej: PBA-1234).');
            foreach ($mias as $p) if (!preg_match('/^[A-Z]{2,3}[0-9]{3,4}[A-Z]?$/', $p)) throw new RuntimeException('Placa no válida: ' . $p . ' (escríbala así: PBA-1234).');
            $usadas += $c;
            $pl = implode(',', $mias);
        }
        $items[] = ['localidad_id' => (int)$l['id'], 'cantidad' => $c, 'precio' => (float)$l['precio'], 'placas' => $pl, 'nombre' => $l['nombre'], 'tipo' => $l['tipo']];
        $total += $c * (float)$l['precio'];
        $n += $c;
    }
    if (!$items) throw new RuntimeException('Elija cuántas entradas quiere.');
    if (!$taquilla && (int)$v['max_por_orden'] > 0 && $n > (int)$v['max_por_orden']) throw new RuntimeException('Máximo ' . (int)$v['max_por_orden'] . ' entradas por compra.');
    return [$items, round($total, 2)];
}

/** Crea la orden (y sus líneas). $canal: web | taquilla | cortesia. $metodo: tarjeta | transferencia | efectivo | cortesia. */
function crear_orden_entradas(array $liga, array $v, array $items, float $total, array $comprador, string $canal, string $metodo, string $comprobante = ''): array
{
    $codigo = bin2hex(random_bytes(10));
    $estado = in_array($canal, ['taquilla', 'cortesia'], true) ? 'pagada' : ($metodo === 'transferencia' ? 'por_verificar' : 'pendiente');
    $imp = impuestos_incluidos($liga, $total);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Revisión final del cupo dentro de la transacción
        foreach ($items as $it) {
            $l = fila('SELECT * FROM bol_localidades WHERE id = ?', [$it['localidad_id']]);
            if ((int)$l['cupo'] > 0 && ocupadas_localidad((int)$l['id']) + $it['cantidad'] > (int)$l['cupo']) throw new RuntimeException('Se acaban de agotar las entradas de ' . $l['nombre'] . '.');
        }
        q('INSERT INTO bol_ordenes (venta_id, liga_id, codigo, nombre, cedula, telefono, email, canal, metodo, estado, total, iva, imp_municipal, comprobante, expira_en, pagada_en, revisado_por)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $v['id'], $liga['id'], $codigo, mb_substr(trim($comprador['nombre'] ?? ''), 0, 100), mb_substr(trim($comprador['cedula'] ?? ''), 0, 20), mb_substr(trim($comprador['telefono'] ?? ''), 0, 30),
            mb_substr(mb_strtolower(trim($comprador['email'] ?? '')), 0, 120), $canal, $metodo, $estado, $canal === 'cortesia' ? 0 : $total, $imp['iva'], $imp['municipal'], $comprobante,
            $estado === 'pendiente' ? date('Y-m-d H:i:s', strtotime('+' . ENTRADA_MINUTOS_RESERVA . ' minutes')) : '', $estado === 'pagada' ? date('Y-m-d H:i:s') : '',
            $estado === 'pagada' ? mb_substr((string)(usuario()['nombre'] ?? ''), 0, 80) : '']);
        $oid = (int)$pdo->lastInsertId();
        foreach ($items as $it) q('INSERT INTO bol_items (orden_id, localidad_id, cantidad, precio, placas) VALUES (?,?,?,?,?)', [$oid, $it['localidad_id'], $it['cantidad'], $canal === 'cortesia' ? 0 : $it['precio'], $it['placas']]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    $o = fila('SELECT * FROM bol_ordenes WHERE id = ?', [$oid]);
    if ($estado === 'pagada') emitir_entradas($o);
    return fila('SELECT * FROM bol_ordenes WHERE id = ?', [$oid]);
}

/** Crea los boletos (uno por entrada) de una orden pagada. No duplica. */
function emitir_entradas(array $o): void
{
    if ((int)valor('SELECT COUNT(*) FROM bol_tickets WHERE orden_id = ?', [$o['id']]) > 0) return;
    foreach (filas('SELECT * FROM bol_items WHERE orden_id = ?', [$o['id']]) as $it) {
        $placas = $it['placas'] !== '' ? explode(',', $it['placas']) : [];
        for ($k = 0; $k < (int)$it['cantidad']; $k++) {
            q("INSERT INTO bol_tickets (orden_id, venta_id, localidad_id, codigo, titular, placa, estado) VALUES (?,?,?,?,?,?, 'valido')",
                [$o['id'], $o['venta_id'], $it['localidad_id'], 'T' . bin2hex(random_bytes(10)), $o['nombre'], $placas[$k] ?? '']);
        }
    }
}

/** Marca la orden como pagada y emite los boletos (pago con tarjeta aprobado o transferencia aprobada). */
function pagar_orden_entradas(array $o, string $por): void
{
    if ($o['estado'] === 'pagada') return;
    q("UPDATE bol_ordenes SET estado = 'pagada', pagada_en = ?, revisado_por = ? WHERE id = ?", [date('Y-m-d H:i:s'), mb_substr($por, 0, 80), $o['id']]);
    emitir_entradas(fila('SELECT * FROM bol_ordenes WHERE id = ?', [$o['id']]));
}

/** Enlace del comprador a sus entradas (con los QR). */
function url_mis_entradas(array $liga, array $o): string
{
    return url_base() . 'index.php?l=' . rawurlencode($liga['slug']) . '&v=entradas&o=' . $o['codigo'];
}

/** Lo que va dentro del QR de cada entrada. */
function url_qr_entrada(array $t): string
{
    return url_base() . 'e.php?c=' . $t['codigo'];
}

/** Valida una entrada en la puerta (atómico: solo la primera lectura la deja pasar). Devuelve [resultado, texto, ticket]. */
function validar_entrada(array $v, string $codigo, string $puerta, bool $offline = false, string $hora = ''): array
{
    $codigo = preg_match('/(T[0-9a-f]{20})/', $codigo, $m) ? $m[1] : '';
    $t = $codigo !== '' ? fila('SELECT t.*, l.nombre AS localidad, l.tipo FROM bol_tickets t JOIN bol_localidades l ON l.id = t.localidad_id WHERE t.codigo = ?', [$codigo]) : null;
    $res = 'invalida';
    $txt = 'Código no válido: no es una entrada de ' . marca() . '.';
    if ($t && (int)$t['venta_id'] !== (int)$v['id']) {
        $res = 'otra_venta';
        $txt = 'Esta entrada es de OTRO partido o evento.';
    } elseif ($t && $t['estado'] === 'anulado') {
        $res = 'anulada';
        $txt = 'Entrada ANULADA.';
    } elseif ($t) {
        $ok = q("UPDATE bol_tickets SET estado = 'usado', usado_en = ?, puerta = ? WHERE id = ? AND estado = 'valido'", [$hora ?: date('Y-m-d H:i:s'), mb_substr($puerta, 0, 40), $t['id']])->rowCount() === 1;
        if ($ok) {
            $res = 'ok';
            $txt = $t['localidad'] . ($t['placa'] !== '' ? ' · placa ' . $t['placa'] : '') . ' · ' . $t['titular'];
        } else {
            $res = 'usada';
            $u = fila('SELECT usado_en, puerta FROM bol_tickets WHERE id = ?', [$t['id']]);
            $txt = 'YA SE USÓ: ' . substr((string)$u['usado_en'], 11, 5) . ($u['puerta'] !== '' ? ' en ' . $u['puerta'] : '');
        }
    }
    q('INSERT INTO bol_escaneos (venta_id, ticket_id, codigo, resultado, puerta, offline) VALUES (?,?,?,?,?,?)', [$v['id'], $t['id'] ?? null, mb_substr($codigo, 0, 40), $res, mb_substr($puerta, 0, 40), $offline ? 1 : 0]);
    return [$res, $txt, $t];
}

/** Resumen de una venta para el panel y los reportes. */
function resumen_venta(array $v): array
{
    $vid = (int)$v['id'];
    $locs = [];
    foreach (localidades_venta($vid) as $l) {
        $vend = (int)valor("SELECT COUNT(*) FROM bol_tickets t JOIN bol_ordenes o ON o.id = t.orden_id WHERE t.localidad_id = ? AND o.estado = 'pagada' AND t.estado <> 'anulado'", [$l['id']]);
        $ing = (int)valor("SELECT COUNT(*) FROM bol_tickets WHERE localidad_id = ? AND estado = 'usado'", [$l['id']]);
        $locs[] = $l + ['vendidas' => $vend, 'ingresaron' => $ing, 'libres' => (int)$l['cupo'] > 0 ? max(0, (int)$l['cupo'] - ocupadas_localidad((int)$l['id'])) : null];
    }
    $por_metodo = [];
    foreach (filas("SELECT metodo, COUNT(*) AS n, COALESCE(SUM(total), 0) AS t FROM bol_ordenes WHERE venta_id = ? AND estado = 'pagada' GROUP BY metodo", [$vid]) as $r) $por_metodo[$r['metodo']] = $r;
    $tot = fila("SELECT COALESCE(SUM(total), 0) AS t, COALESCE(SUM(iva), 0) AS iva, COALESCE(SUM(imp_municipal), 0) AS mun FROM bol_ordenes WHERE venta_id = ? AND estado = 'pagada'", [$vid]);
    return ['localidades' => $locs, 'por_metodo' => $por_metodo, 'total' => (float)$tot['t'], 'iva' => (float)$tot['iva'], 'municipal' => (float)$tot['mun'],
        'por_revisar' => (int)valor("SELECT COUNT(*) FROM bol_ordenes WHERE venta_id = ? AND estado = 'por_verificar'", [$vid]),
        'rechazos' => (int)valor("SELECT COUNT(*) FROM bol_escaneos WHERE venta_id = ? AND resultado <> 'ok'", [$vid])];
}

/** Tarea (cada minuto): libera los cupos de las compras con tarjeta que no se completaron. */
function expirar_ordenes_entradas(): void
{
    q("UPDATE bol_ordenes SET estado = 'expirada' WHERE estado = 'pendiente' AND expira_en <> '' AND expira_en < ?", [date('Y-m-d H:i:s')]);
}

function nombre_metodo_entrada(string $m): string
{
    return ['tarjeta' => '💳 Tarjeta', 'transferencia' => '🏦 Transferencia', 'efectivo' => '💵 Taquilla (efectivo)', 'cortesia' => '🎁 Cortesía'][$m] ?? $m;
}
