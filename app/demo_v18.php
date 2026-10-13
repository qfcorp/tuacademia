<?php
/*
 * VERSIÓN 18 — DATOS DE LAS DEMOS:
 *  Liga: fechas de fundación de los equipos (2 aniversarios próximos), 3 cumpleañeros hoy y 2 esta semana,
 *        reglamento en PDF, RUC, gastos del año (con y sin factura) y facturas del SRI (incluidas y por revisar).
 *  Escuela: RUC, gastos del año y facturas del SRI.
 * Los proveedores y los RUC son inventados.
 */

/** Clave de acceso de 49 dígitos con dígito verificador (para datos de ejemplo y pruebas). */
function sri_generar_clave(string $fecha, string $cod_doc, string $ruc, string $serie, int $secuencial): string
{
    $base = date('dmY', strtotime($fecha)) . $cod_doc . str_pad(substr($ruc, 0, 13), 13, '0') . '2' . str_replace('-', '', $serie)
        . str_pad((string)$secuencial, 9, '0', STR_PAD_LEFT) . str_pad((string)(($secuencial * 7919) % 100000000), 8, '0', STR_PAD_LEFT) . '1';
    $suma = 0;
    $factor = 2;
    for ($i = 47; $i >= 0; $i--) { $suma += (int)$base[$i] * $factor; $factor = $factor === 7 ? 2 : $factor + 1; }
    $dv = 11 - ($suma % 11);
    return $base . ($dv === 11 ? 0 : ($dv === 10 ? 1 : $dv));
}

/**
 * XML "autorizado" de una factura de ejemplo, igual al que entrega el SRI.
 * $items: [[descripción, cantidad, precio unitario, con IVA (bool)], ...]
 */
function sri_xml_ejemplo(string $fecha, string $ruc, string $razon, string $serie, int $sec, string $comprador_id, string $comprador, array $items, string $tipo = 'factura', string $doc_mod = ''): string
{
    $cod = $tipo === 'nota_credito' ? '04' : '01';
    $clave = sri_generar_clave($fecha, $cod, $ruc, $serie, $sec);
    [$est, $pto] = explode('-', $serie);
    $b0 = 0.0; $b15 = 0.0; $det = '';
    foreach ($items as [$d, $cant, $pu, $iva]) {
        $tot = round($cant * $pu, 2);
        if ($iva) $b15 += $tot; else $b0 += $tot;
        $det .= '<detalle><codigoPrincipal>' . substr(md5($d), 0, 6) . '</codigoPrincipal><descripcion>' . htmlspecialchars($d, ENT_XML1) . '</descripcion><cantidad>' . number_format($cant, 2, '.', '') . '</cantidad>'
            . '<precioUnitario>' . number_format($pu, 2, '.', '') . '</precioUnitario><descuento>0.00</descuento><precioTotalSinImpuesto>' . number_format($tot, 2, '.', '') . '</precioTotalSinImpuesto>'
            . '<impuestos><impuesto><codigo>2</codigo><codigoPorcentaje>' . ($iva ? '4' : '0') . '</codigoPorcentaje><tarifa>' . ($iva ? '15' : '0') . '</tarifa><baseImponible>' . number_format($tot, 2, '.', '') . '</baseImponible><valor>' . number_format($iva ? $tot * 0.15 : 0, 2, '.', '') . '</valor></impuesto></impuestos></detalle>';
    }
    $iva_v = round($b15 * 0.15, 2);
    $total = round($b0 + $b15 + $iva_v, 2);
    $imps = ($b0 > 0 ? '<totalImpuesto><codigo>2</codigo><codigoPorcentaje>0</codigoPorcentaje><baseImponible>' . number_format($b0, 2, '.', '') . '</baseImponible><valor>0.00</valor></totalImpuesto>' : '')
        . ($b15 > 0 ? '<totalImpuesto><codigo>2</codigo><codigoPorcentaje>4</codigoPorcentaje><baseImponible>' . number_format($b15, 2, '.', '') . '</baseImponible><valor>' . number_format($iva_v, 2, '.', '') . '</valor></totalImpuesto>' : '');
    $f = date('d/m/Y', strtotime($fecha));
    $it = '<infoTributaria><ambiente>2</ambiente><tipoEmision>1</tipoEmision><razonSocial>' . htmlspecialchars($razon, ENT_XML1) . '</razonSocial><ruc>' . $ruc . '</ruc><claveAcceso>' . $clave . '</claveAcceso>'
        . '<codDoc>' . $cod . '</codDoc><estab>' . $est . '</estab><ptoEmi>' . $pto . '</ptoEmi><secuencial>' . str_pad((string)$sec, 9, '0', STR_PAD_LEFT) . '</secuencial><dirMatriz>Quito</dirMatriz></infoTributaria>';
    if ($tipo === 'nota_credito') {
        $comp = '<notaCredito id="comprobante" version="1.1.0">' . $it . '<infoNotaCredito><fechaEmision>' . $f . '</fechaEmision><tipoIdentificacionComprador>04</tipoIdentificacionComprador><razonSocialComprador>' . htmlspecialchars($comprador, ENT_XML1)
            . '</razonSocialComprador><identificacionComprador>' . $comprador_id . '</identificacionComprador><codDocModificado>01</codDocModificado><numDocModificado>' . $doc_mod . '</numDocModificado><totalSinImpuestos>' . number_format($b0 + $b15, 2, '.', '')
            . '</totalSinImpuestos><valorModificacion>' . number_format($total, 2, '.', '') . '</valorModificacion><moneda>DOLAR</moneda><totalConImpuestos>' . $imps . '</totalConImpuestos><motivo>Devolución</motivo></infoNotaCredito><detalles>' . $det . '</detalles></notaCredito>';
    } else {
        $comp = '<factura id="comprobante" version="2.1.0">' . $it . '<infoFactura><fechaEmision>' . $f . '</fechaEmision><tipoIdentificacionComprador>04</tipoIdentificacionComprador><razonSocialComprador>' . htmlspecialchars($comprador, ENT_XML1)
            . '</razonSocialComprador><identificacionComprador>' . $comprador_id . '</identificacionComprador><totalSinImpuestos>' . number_format($b0 + $b15, 2, '.', '') . '</totalSinImpuestos><totalDescuento>0.00</totalDescuento>'
            . '<totalConImpuestos>' . $imps . '</totalConImpuestos><propina>0.00</propina><importeTotal>' . number_format($total, 2, '.', '') . '</importeTotal><moneda>DOLAR</moneda></infoFactura><detalles>' . $det . '</detalles></factura>';
    }
    return '<?xml version="1.0" encoding="UTF-8"?><autorizacion><estado>AUTORIZADO</estado><numeroAutorizacion>' . $clave . '</numeroAutorizacion><fechaAutorizacion>' . date('Y-m-d', strtotime($fecha)) . 'T10:15:00-05:00</fechaAutorizacion><ambiente>PRODUCCIÓN</ambiente><comprobante><![CDATA[<?xml version="1.0" encoding="UTF-8"?>' . $comp . ']]></comprobante></autorizacion>';
}

/** PDF muy simple (una página, texto) para el reglamento de ejemplo. */
function pdf_texto_simple(string $titulo, array $lineas): string
{
    $esc = fn(string $s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], mb_convert_encoding($s, 'Windows-1252', 'UTF-8'));
    $cont = "BT /F2 18 Tf 60 780 Td (" . $esc($titulo) . ") Tj ET\n";
    $y = 745;
    foreach ($lineas as $l) {
        $negrita = str_starts_with($l, '#');
        $cont .= 'BT /' . ($negrita ? 'F2 12' : 'F1 11') . ' Tf 60 ' . $y . ' Td (' . $esc(ltrim($l, '# ')) . ") Tj ET\n";
        $y -= $negrita ? 22 : 16;
    }
    $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        "<< /Length " . strlen($cont) . " >>\nstream\n" . $cont . "endstream"];
    $pdf = "%PDF-1.4\n";
    $pos = [];
    foreach ($objs as $i => $o) { $pos[] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n"; }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($pos as $p) $pdf .= sprintf("%010d 00000 n \n", $p);
    return $pdf . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
}

function demo_v18(int $lid, int $eid): void
{
    sin_auditoria(function () use ($lid, $eid) {
        mt_srand(1818);
        $hoy = date('Y-m-d');
        $anio = (int)date('Y');
        // ---------- Liga: fundación, cumpleaños y reglamento ----------
        $equipos = filas('SELECT id FROM equipos WHERE liga_id = ? ORDER BY id', [$lid]);
        foreach ($equipos as $i => $e) {
            $a = 1965 + mt_rand(0, 50);
            $f = $i === 0 ? date('Y-m-d', strtotime('+4 days')) : ($i === 1 ? date('Y-m-d', strtotime('+19 days')) : sprintf('%04d-%02d-%02d', $a, mt_rand(1, 12), mt_rand(1, 28)));
            q('UPDATE equipos SET fundado = ? WHERE id = ?', [substr_replace($f, (string)$a, 0, 4), $e['id']]);
        }
        $vig = equipos_vigentes($lid);
        if ($vig) {
            $jug = filas('SELECT id, fecha_nac FROM jugadores WHERE equipo_id IN (' . implode(',', $vig) . ') AND activo = 1 ORDER BY id', []);
            foreach ([3 => 0, 11 => 0, 25 => 0, 40 => 2, 57 => 5] as $k => $dias) {
                if (!isset($jug[$k])) continue;
                $y = $jug[$k]['fecha_nac'] !== '' ? (int)substr($jug[$k]['fecha_nac'], 0, 4) : $anio - 24;
                $md = date('m-d', strtotime("+$dias day"));
                if ($md === '02-29') $md = '02-28';
                q('UPDATE jugadores SET fecha_nac = ? WHERE id = ?', [sprintf('%04d-', $y) . $md, $jug[$k]['id']]);
            }
        }
        $dir = UPLOADS_DIR . '/docs/' . $lid;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $liga = fila('SELECT * FROM ligas WHERE id = ?', [$lid]);
        $pdf = pdf_texto_simple('Reglamento del campeonato ' . $anio, ['Liga: ' . $liga['nombre'], '', '# 1. De los equipos',
            'Cada equipo inscribe entre 12 y 25 jugadores con cédula y foto.', 'El delegado es el único que puede hablar con la mesa de control.', '',
            '# 2. De las tarjetas', '3 tarjetas amarillas acumuladas = 1 partido de suspensión.', 'Tarjeta roja directa = 1 partido de suspensión (la comisión puede aumentarlo).',
            'Doble amarilla en un mismo partido = roja.', '', '# 3. Del carnet', 'Sin carnet QR vigente el jugador no puede ingresar a la cancha.', '',
            '# 4. De los partidos', 'Tolerancia de 10 minutos. Pasado ese tiempo se declara W.O. (3 a 0).', 'Los resultados oficiales son los que registra el vocal en el sistema.', '',
            '# 5. De los pagos', 'La inscripción y los arbitrajes se pagan antes de cada fecha.', 'Las multas se cancelan antes del siguiente partido del equipo.', '',
            'Documento de ejemplo de la demostración de ' . marca() . '.']);
        file_put_contents("$dir/reglamento_demo.pdf", $pdf);
        q('DELETE FROM liga_documentos WHERE liga_id = ?', [$lid]);
        q("INSERT INTO liga_documentos (liga_id, titulo, tipo, archivo, publico, subido_por) VALUES (?, ?, 'reglamento', ?, 1, 'Demo')", [$lid, 'Reglamento ' . $anio, 'uploads/docs/' . $lid . '/reglamento_demo.pdf']);

        // ---------- Liga: cobros de inscripción y arbitrajes (para que la contabilidad tenga ingresos) ----------
        foreach (filas('SELECT id FROM torneos WHERE liga_id = ?', [$lid]) as $t) {
            $ini = (string)valor("SELECT MIN(fecha) FROM partidos WHERE torneo_id = ? AND fecha <> ''", [$t['id']]);
            q('UPDATE torneos SET cuota_inscripcion = 150, arbitraje_por_equipo = 20' . ($ini !== '' ? ", creado = date(?, '-10 day')" : '') . ' WHERE id = ? AND cuota_inscripcion = 0 AND arbitraje_por_equipo = 0',
                $ini !== '' ? [$ini, $t['id']] : [$t['id']]);
            reglas_torneo((int)$t['id'], true);
            sincronizar_cargos((int)$t['id']);
        }
        if (!valor("SELECT COUNT(*) FROM movimientos WHERE liga_id = ? AND tipo = 'pago'", [$lid])) {
            foreach (filas("SELECT * FROM movimientos WHERE liga_id = ? AND tipo = 'cargo' AND anulado = 0 ORDER BY fecha, id", [$lid]) as $i => $c) {
                if ($c['concepto'] === 'arbitraje' && $i % 7 === 0) continue; // algunos quedan debiendo
                $fp = min($hoy, date('Y-m-d', strtotime($c['fecha'] . ' +' . ($i % 4) . ' day')));
                registrar_pago($lid, $c['torneo_id'] ? (int)$c['torneo_id'] : null, (int)$c['equipo_id'], (float)$c['monto'], $c['concepto'], 'Pago: ' . $c['descripcion'], $i % 3 ? 'efectivo' : 'transferencia', '', $fp, 'Demo');
            }
        }

        // ---------- Contabilidad ----------
        foreach ([[$lid, 'liga'], [$eid, 'escuela']] as [$id, $tipo]) {
            if (!$id) continue;
            $org = fila('SELECT * FROM ligas WHERE id = ?', [$id]);
            $ruc = $tipo === 'liga' ? '1792345678001' : '1793456789001';
            q('UPDATE ligas SET ruc = ? WHERE id = ?', [$ruc, $id]);
            q('DELETE FROM compras WHERE liga_id = ?', [$id]);
            q("DELETE FROM movimientos WHERE liga_id = ? AND tipo = 'egreso' AND (compra_id IS NOT NULL OR registrado_por = 'Demo v18')", [$id]);
            $torneo = $tipo === 'liga' ? (int)valor("SELECT id FROM torneos WHERE liga_id = ? ORDER BY (estado = 'en_juego') DESC, id DESC LIMIT 1", [$id]) : 0;
            $gastos = $tipo === 'liga'
                ? [['cancha', 'Alquiler de la cancha principal (mes)', 'Complejo Deportivo Los Pinos', 180.0, '001-002-000004512'], ['hidratacion', 'Agua y bebidas para árbitros y vocales', '', 32.5, ''],
                   ['premios', 'Medallas para la fecha inaugural', 'Trofeos La Copa', 95.0, '002-001-000000871'], ['transporte', 'Movilización de la mesa de control', '', 20.0, ''],
                   ['publicidad', 'Lona con el logo de la liga y los patrocinadores', 'Imprenta Rápida Norte', 60.0, '001-001-000002209'], ['administracion', 'Copias de carnets y planillas', '', 12.75, '']]
                : [['cancha', 'Alquiler de la cancha sintética (mes)', 'Complejo Deportivo Los Pinos', 220.0, '001-002-000004513'], ['implementos', 'Conos, platillos y escalera de coordinación', '', 38.0, ''],
                   ['hidratacion', 'Agua para los entrenamientos', '', 18.5, ''], ['servicios', 'Plan de internet del celular de la escuela', '', 25.0, ''],
                   ['mantenimiento', 'Arreglo de los arcos pequeños', '', 45.0, ''], ['publicidad', 'Publicidad en redes (inscripciones)', '', 30.0, '']];
            for ($m = $tipo === 'liga' ? 3 : 5; $m >= 0; $m--) {
                $base = strtotime(date('Y-m-01') . " -$m month");
                foreach ($gastos as $k => [$cat, $desc, $prov, $monto, $fac]) {
                    if ($m > 0 && $k >= 4 && $m % 2 === 1) continue;
                    $dia = min((int)date('t', $base), 3 + $k * 4);
                    $f = date('Y-m-', $base) . str_pad((string)$dia, 2, '0', STR_PAD_LEFT);
                    if ($f > $hoy) continue;
                    q("INSERT INTO movimientos (liga_id, torneo_id, tipo, concepto, descripcion, monto, fecha, forma_pago, proveedor, factura, registrado_por) VALUES (?,?, 'egreso', ?,?,?,?,?,?,?, 'Demo v18')",
                        [$id, $torneo ?: null, $cat, $desc, round($monto * (1 + mt_rand(-8, 8) / 100), 2), $f, $fac !== '' ? 'transferencia' : 'efectivo', $prov, $fac !== '' ? substr_replace($fac, str_pad((string)(mt_rand(100, 9999)), 9, '0', STR_PAD_LEFT), 8) : '']);
                }
            }
            $prov = [['1790123456001', 'DISTRIBUIDORA DEPORTIVA EL GOLAZO S.A.', '001-001'], ['1791122334001', 'TROFEOS Y MEDALLAS LA COPA CIA. LTDA.', '002-001'],
                ['1790987654001', 'AGUA PURA DEL VALLE CIA. LTDA.', '001-003'], ['1792233445001', 'IMPRENTA RAPIDA NORTE', '001-001'], ['1793344556001', 'TRANSPORTES UNIDOS DEL SUR', '003-001']];
            $facturas = $tipo === 'liga'
                ? [[0, 40, [['Balón de fútbol N° 5 profesional', 6, 28.5, true], ['Inflador con manómetro', 2, 9.9, true]], 'implementos', true],
                   [1, 33, [['Trofeo campeón 60 cm', 1, 85, true], ['Medallas doradas', 25, 3.2, true]], 'premios', true],
                   [2, 21, [['Botellón de agua 20 L', 12, 2.75, false]], 'hidratacion', true],
                   [3, 12, [['Planillas de vocalía impresas (block 100)', 4, 7.5, true], ['Credenciales PVC', 60, 0.9, true]], 'administracion', false],
                   [4, 6, [['Bus para la delegación (ida y vuelta)', 1, 140, true]], 'transporte', false],
                   [0, 3, [['Camiseta de árbitro', 3, 22, true]], '', false]]
                : [[0, 45, [['Balón de fútbol N° 4', 15, 19.9, true], ['Red para arco pequeño', 2, 35, true]], 'implementos', true],
                   [0, 25, [['Uniforme completo niño (camiseta, pantaloneta, medias)', 20, 17.5, true]], 'uniformes', true],
                   [2, 14, [['Botellón de agua 20 L', 8, 2.75, false]], 'hidratacion', false],
                   [3, 4, [['Volantes de inscripción', 500, 0.06, true]], '', false]];
            foreach ($facturas as $n => [$pi, $dias, $items, $cat, $incluir]) {
                [$pruc, $pnom, $serie] = $prov[$pi];
                $xml = sri_xml_ejemplo(date('Y-m-d', strtotime("-$dias day")), $pruc, $pnom, $serie, 1200 + $n * 37 + ($tipo === 'escuela' ? 500 : 0), $ruc, $org['nombre'], $items);
                $d = sri_leer_comprobante($xml);
                guardar_compra($id, $d, 'xml', 'Demo');
                if ($incluir) incluir_compra($org, fila('SELECT * FROM compras WHERE liga_id = ? AND clave_acceso = ?', [$id, $d['clave_acceso']]), $cat, $torneo ?: null, 'transferencia', 'Demo');
            }
            // Una factura a nombre de otra persona (se muestra con ⚠) y una nota de crédito por revisar
            $d = sri_leer_comprobante(sri_xml_ejemplo(date('Y-m-d', strtotime('-2 day')), '1790123456001', 'DISTRIBUIDORA DEPORTIVA EL GOLAZO S.A.', '001-001', 1777 + ($tipo === 'escuela' ? 500 : 0), '1712345678', 'JUAN PEREZ', [['Zapatos de fútbol talla 42', 1, 64.9, true]]));
            guardar_compra($id, $d, 'xml', 'Demo');
            $d = sri_leer_comprobante(sri_xml_ejemplo(date('Y-m-d', strtotime('-1 day')), '1790123456001', 'DISTRIBUIDORA DEPORTIVA EL GOLAZO S.A.', '001-001', 90 + ($tipo === 'escuela' ? 500 : 0), $ruc, $org['nombre'], [['Devolución de un balón defectuoso', 1, 28.5, true]], 'nota_credito', '001-001-000001200'));
            guardar_compra($id, $d, 'xml', 'Demo');
            // Una que llegó solo del reporte TXT (falta completar desde el SRI)
            $cl = sri_generar_clave(date('Y-m-d', strtotime('-9 day')), '01', '1794455667001', '001-001', 3301 + ($tipo === 'escuela' ? 500 : 0));
            guardar_compra($id, ['clave_acceso' => $cl, 'tipo' => 'factura', 'ruc_emisor' => '1794455667001', 'razon_social' => 'FERRETERIA EL MARTILLO', 'numero' => sri_datos_clave($cl)['numero'],
                'fecha' => sri_datos_clave($cl)['fecha'], 'id_comprador' => $ruc, 'base_0' => 0.0, 'base_iva' => 0.0, 'iva' => 0.0, 'total' => 47.15, 'xml' => ''], 'txt', 'Demo');
        }
    });
}
