<?php
/*
 * (9 nov 2026) FACTURA ELECTRÓNICA CON TUFACTURA (módulo «facturacion»): APAGADO de fábrica, fuera de los planes («*» y la demo no
 * lo traen) y a $0 en este programa: se enciende desde el Panel Tukán Central cuando la cuenta compra TuFactura. El MISMO archivo
 * sirve para TuVocalía y TuAcademia. La pieza común está en app/TukanFactura.php (copia de tufactura/docs/enlace: no se cambia aquí).
 *  - Cada cuenta (liga, escuela o academia) se conecta con su propia clave de TuFactura: en las tablas de la pieza común el
 *    «negocio» es el id de la cuenta (ligas.id).
 *  - Qué se factura (origen «cobro», con una letra delante del número):
 *      m<id>  pago de un equipo o cobro de una reserva (movimientos tipo «pago»)            → consumidor final o la cédula de la reserva
 *      r<n>   recibo de la escuela/academia (esc_cargos pagados con ese número de recibo)   → la cédula del representante (o del alumno)
 *      b<id>  compra de entradas pagada (bol_ordenes, sin las cortesías)                     → la cédula y el correo del comprador
 *  - Con «automático» la factura sale sola al registrar el pago, al cobrar el recibo o al pagarse la compra de entradas.
 *  - La clave tf_ se guarda cifrada con TUKAN_FACTURA_LLAVE o, si no está, con data/tufactura.llave (se crea sola, fuera de public).
 */
require_once __DIR__ . '/TukanFactura.php';

final class FacturaTukan
{
    public const ORIGEN = 'cobro';

    public static function programa(): string { return marca(); }

    public static function enlace(): TukanFactura
    {
        static $t = null;
        if ($t) return $t;
        $llave = (string) getenv('TUKAN_FACTURA_LLAVE');
        if ($llave === '') {
            $f = RAIZ . '/data/tufactura.llave';
            if (!is_file($f)) { @file_put_contents($f, bin2hex(random_bytes(32))); @chmod($f, 0600); }
            $llave = trim((string) @file_get_contents($f)) ?: 'TukanFactura|' . RAIZ;
        }
        return $t = new TukanFactura(db(), $llave, self::programa());
    }

    public static function liga(int $ligaId): ?array { return fila('SELECT * FROM ligas WHERE id = ?', [$ligaId]); }

    public static function activo(?array $liga): bool { return $liga !== null && modulo_activo($liga, 'facturacion'); }

    /** Lo que se manda a TuFactura de un cobro ('m12', 'r34', 'b56'): [cliente, items, extra], o null. */
    public static function datos(int $ligaId, string $id): ?array
    {
        if (!preg_match('/^([mrb])(\d+)$/', $id, $x)) return null;
        [, $t, $n] = $x;
        $n = (int) $n;
        if ($t === 'm') {
            $m = fila("SELECT m.*, e.nombre AS equipo FROM movimientos m LEFT JOIN equipos e ON e.id = m.equipo_id WHERE m.id = ? AND m.liga_id = ? AND m.tipo = 'pago' AND m.anulado = 0", [$n, $ligaId]);
            if (!$m) return null;
            $cli = ['identificacion' => '', 'nombre' => ''];
            if (preg_match('/^res:(\d+)/', (string) $m['ref'], $r)) {   // cobro de una reserva: a nombre del cliente de la reserva
                $res = fila('SELECT * FROM reservas WHERE id = ? AND liga_id = ?', [(int) $r[1], $ligaId]);
                if ($res) $cli = ['identificacion' => (string) ($res['cliente_cedula'] ?? ''), 'nombre' => (string) $res['cliente_nombre'], 'telefono' => (string) $res['cliente_telefono'], 'correo' => (string) ($res['cliente_email'] ?? '')];
            }
            $desc = trim((string) $m['descripcion']) ?: ucfirst((string) $m['concepto']);
            return [$cli, [['descripcion' => $desc . ($m['equipo'] ? ' · ' . $m['equipo'] : ''), 'cantidad' => 1, 'precio' => (float) $m['monto']]],
                    ['forma' => (string) $m['forma_pago'], 'info' => ['Recibo' => 'N.º ' . (int) $m['recibo']] + ($m['equipo'] ? ['Equipo' => (string) $m['equipo']] : [])]];
        }
        if ($t === 'r') {
            $cs = filas("SELECT c.*, a.nombres, a.apellidos, a.cedula, a.direccion, a.representante_nombre, a.representante_cedula, a.representante_email, a.representante_telefono
                         FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE c.liga_id = ? AND c.recibo = ? AND c.estado = 'pagado' ORDER BY c.id", [$ligaId, $n]);
            if (!$cs) return null;
            $a = $cs[0];
            $repre = trim((string) $a['representante_cedula']) !== '';
            $cli = ['identificacion' => $repre ? (string) $a['representante_cedula'] : (string) $a['cedula'],
                    'nombre' => $repre ? (string) $a['representante_nombre'] : trim($a['nombres'] . ' ' . $a['apellidos']),
                    'correo' => (string) $a['representante_email'], 'telefono' => (string) $a['representante_telefono'], 'direccion' => (string) $a['direccion']];
            $items = [];
            foreach ($cs as $c) {
                $v = round((float) $c['monto'] - (float) $c['descuento'], 2);
                if ($v > 0) $items[] = ['descripcion' => (trim((string) $c['descripcion']) ?: ucfirst((string) $c['tipo'])) . (count(array_unique(array_column($cs, 'alumno_id'))) > 1 ? ' · ' . $c['nombres'] : ''), 'cantidad' => 1, 'precio' => $v];
            }
            if (!$items) return null;
            return [$cli, $items, ['forma' => (string) $a['forma_pago'], 'info' => ['Recibo' => 'N.º ' . $n, 'Alumno' => trim($a['nombres'] . ' ' . $a['apellidos'])]]];
        }
        $o = fila("SELECT o.*, v.titulo FROM bol_ordenes o JOIN bol_ventas v ON v.id = o.venta_id WHERE o.id = ? AND o.liga_id = ? AND o.estado = 'pagada' AND o.total > 0", [$n, $ligaId]);
        if (!$o) return null;
        $nEnt = max(1, (int) valor('SELECT COALESCE(SUM(cantidad), 0) FROM bol_items WHERE orden_id = ?', [$n]));
        $mun = (float) $o['imp_municipal'];
        $base = round((float) $o['total'] - (float) $o['iva'] - $mun, 2);
        $items = (float) $o['iva'] > 0
            ? [['descripcion' => 'Entradas: ' . $o['titulo'], 'cantidad' => $nEnt, 'precio_sin_iva' => round($base / $nEnt, 6), 'iva' => 15]]
            : [['descripcion' => 'Entradas: ' . $o['titulo'], 'cantidad' => $nEnt, 'precio' => round(((float) $o['total'] - $mun) / $nEnt, 6)]];
        if ($mun > 0) $items[] = ['descripcion' => 'Impuesto municipal a los espectáculos', 'cantidad' => 1, 'precio_sin_iva' => $mun, 'iva' => 0];
        $forma = ['tarjeta' => 'tarjeta', 'transferencia' => 'transferencia', 'efectivo' => 'efectivo'][(string) $o['metodo']] ?? 'otro';
        return [['identificacion' => (string) $o['cedula'], 'nombre' => (string) $o['nombre'], 'correo' => (string) $o['email'], 'telefono' => (string) $o['telefono']],
                $items, ['forma' => $forma, 'info' => ['Compra' => strtoupper(substr((string) $o['codigo'], 0, 8))]]];
    }

    /** Los últimos cobros de la cuenta: [id, fecha, cliente, total, detalle]. */
    public static function cobros(int $ligaId, int $limite = 30): array
    {
        $r = [];
        foreach (filas("SELECT m.id, m.fecha, m.monto, m.descripcion, m.concepto, m.recibo, e.nombre AS equipo FROM movimientos m LEFT JOIN equipos e ON e.id = m.equipo_id
                        WHERE m.liga_id = ? AND m.tipo = 'pago' AND m.anulado = 0 ORDER BY m.id DESC LIMIT $limite", [$ligaId]) as $m) {
            $r[] = ['m' . $m['id'], (string) $m['fecha'], (string) ($m['equipo'] ?: ''), (float) $m['monto'], 'Recibo N.º ' . (int) $m['recibo'] . ' · ' . (trim((string) $m['descripcion']) ?: $m['concepto'])];
        }
        foreach (filas("SELECT c.recibo, MAX(c.pagado_en) AS fecha, SUM(c.monto - c.descuento) AS total, MAX(a.nombres || ' ' || a.apellidos) AS alumno FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id
                        WHERE c.liga_id = ? AND c.estado = 'pagado' AND c.recibo IS NOT NULL GROUP BY c.recibo ORDER BY c.recibo DESC LIMIT $limite", [$ligaId]) as $c) {
            $r[] = ['r' . $c['recibo'], (string) $c['fecha'], (string) $c['alumno'], (float) $c['total'], 'Recibo N.º ' . (int) $c['recibo']];
        }
        foreach (filas("SELECT o.id, o.pagada_en, o.total, o.nombre, v.titulo FROM bol_ordenes o JOIN bol_ventas v ON v.id = o.venta_id
                        WHERE o.liga_id = ? AND o.estado = 'pagada' AND o.total > 0 ORDER BY o.id DESC LIMIT $limite", [$ligaId]) as $o) {
            $r[] = ['b' . $o['id'], (string) $o['pagada_en'], (string) $o['nombre'], (float) $o['total'], 'Entradas: ' . $o['titulo']];
        }
        usort($r, fn($a, $b) => strcmp($b[1], $a[1]));
        return array_slice($r, 0, $limite);
    }

    /** Al cobrar: si se eligió «automático», sale la factura sola. Nunca tumba el cobro. */
    public static function alCobrar(int $ligaId, string $id): void
    {
        try {
            if (!self::activo(self::liga($ligaId))) return;
            self::enlace()->alCobrar($ligaId, true, self::ORIGEN, $id, fn() => self::datos($ligaId, $id));
        } catch (Throwable $e) {
            error_log('TuFactura: ' . $e->getMessage());
        }
    }

    /** La pantalla del panel (admin.php?p=tufactura). */
    public static function pagina(array $liga): void
    {
        $lid = (int) $liga['id'];
        $tf = self::enlace();
        $app = function_exists('en_app_android') && en_app_android();
        if (!self::activo($liga)) {
            echo $tf->pantalla($lid, ['modulo' => false, 'programa' => self::programa(), 'sin_compras' => $app, 'pedir' => $app ? null : 'admin.php?p=mi_plan#modulos']);
            return;
        }
        if (get('pdf') !== '') {
            $pdf = $tf->pdf($lid, (int) get('pdf'));
            $d = $tf->documento($lid, (int) get('pdf'));
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="factura-' . preg_replace('/[^0-9-]/', '', (string) $d['numero']) . '.pdf"');
            echo $pdf;
            exit;
        }
        if (es_post()) {
            if ($r = $tf->atender($lid, $_POST, fn(string $id) => self::datos($lid, $id), self::ORIGEN)) flash($r[1], $r[0] === 'ok' ? 'ok' : 'error');
            ir('admin.php?p=tufactura');
        }
        echo $tf->pantalla($lid, ['modulo' => true, 'csrf' => csrf_campo(), 'accion' => 'admin.php?p=tufactura', 'es_admin' => puede('config', true) || puede('finanzas', true) || puede('cobros', true),
            'sin_compras' => $app, 'pdf' => fn(int $id) => 'admin.php?p=tufactura&pdf=' . $id, 'cobros' => self::cobros($lid), 'origen' => self::ORIGEN, 'programa' => self::programa()]);
    }
}
