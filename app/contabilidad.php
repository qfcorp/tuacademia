<?php
/*
 * (v18) CONTABILIDAD de ligas y escuelas: gastos (con o sin factura), facturas recibidas importadas del SRI
 * (archivos XML, reporte TXT de "comprobantes recibidos" o clave de acceso) que se eligen para incluir o no,
 * y reportes (resumen, gastos, compras con IVA) exportables a PDF y a Excel .xlsx real.
 *
 * El lector de comprobantes está adaptado del Sincronizador SRI del ERP de QF Producciones
 * (factura, liquidación de compra, notas de crédito y débito; bases 0 % / con IVA, IVA y total).
 * Solo se REGISTRAN facturas recibidas: TuAcademia no emite facturas electrónicas.
 */

/* ---------- Categorías de gasto ---------- */

/** Categorías de gasto (las de ligas y las de escuelas comparten claves). */
function categorias_gasto(string $tipo = 'liga'): array
{
    $comunes = ['cancha' => 'Alquiler de cancha', 'implementos' => 'Balones e implementos', 'uniformes' => 'Uniformes y trofeos',
        'hidratacion' => 'Hidratación y refrigerios', 'transporte' => 'Transporte y movilización', 'publicidad' => 'Publicidad y redes',
        'servicios' => 'Servicios básicos e internet', 'mantenimiento' => 'Mantenimiento', 'administracion' => 'Gastos administrativos'];
    if ($tipo === 'escuela') return ['profesores' => 'Pago a profesores'] + $comunes + ['otro' => 'Otros gastos'];
    return ['arbitros' => 'Pago a árbitros', 'premios' => 'Premios y trofeos'] + $comunes + ['otro' => 'Otros gastos'];
}

function nombre_categoria_gasto(string $k): string
{
    return (categorias_gasto('liga') + categorias_gasto('escuela'))[$k] ?? ($k !== '' ? ucfirst($k) : 'Otros gastos');
}

function tipo_contable(array $liga): string
{
    return es_escuela($liga) ? 'escuela' : 'liga';
}

/* ---------- Lectura de comprobantes del SRI ---------- */

function sri_nombre_tipo(string $cod): string
{
    return ['01' => 'factura', '03' => 'liquidacion', '04' => 'nota_credito', '05' => 'nota_debito', '06' => 'guia', '07' => 'retencion'][$cod] ?? 'otro';
}

function nombre_tipo_compra(string $t): string
{
    return ['factura' => 'Factura', 'liquidacion' => 'Liquidación de compra', 'nota_credito' => 'Nota de crédito', 'nota_debito' => 'Nota de débito', 'manual' => 'Factura física'][$t] ?? ucfirst($t);
}

/** Clave de acceso de 49 dígitos con su dígito verificador (módulo 11). */
function sri_clave_valida(string $clave): bool
{
    if (!preg_match('/^\d{49}$/', $clave)) return false;
    $suma = 0;
    $factor = 2;
    for ($i = 47; $i >= 0; $i--) {
        $suma += (int)$clave[$i] * $factor;
        $factor = $factor === 7 ? 2 : $factor + 1;
    }
    $dv = 11 - ($suma % 11);
    if ($dv === 11) $dv = 0;
    if ($dv === 10) $dv = 1;
    return (int)$clave[48] === $dv;
}

/** Lo que se lee directo de la clave de acceso: fecha, tipo, RUC del emisor y número. */
function sri_datos_clave(string $clave): array
{
    return [
        'fecha' => substr($clave, 4, 4) . '-' . substr($clave, 2, 2) . '-' . substr($clave, 0, 2),
        'tipo' => sri_nombre_tipo(substr($clave, 8, 2)),
        'ruc_emisor' => substr($clave, 10, 13),
        'ambiente' => substr($clave, 23, 1),
        'numero' => substr($clave, 24, 3) . '-' . substr($clave, 27, 3) . '-' . substr($clave, 30, 9),
    ];
}

/** Todas las claves de acceso válidas que haya en un texto (pegado o un TXT). */
function sri_extraer_claves(string $texto): array
{
    preg_match_all('/(?<!\d)(\d{49})(?!\d)/', $texto, $m);
    return array_values(array_filter(array_unique($m[1]), 'sri_clave_valida'));
}

function sri_fecha(string $t): string
{
    $t = trim($t);
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $t, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $t, $m)) return "$m[1]-$m[2]-$m[3]";
    return '';
}

function sri_num($v): float
{
    $s = trim((string)$v);
    if (preg_match('/^-?\d{1,3}(\.\d{3})+,\d+$/', $s)) $s = str_replace(['.', ','], ['', '.'], $s); // 1.234,56
    elseif (str_contains($s, ',') && !str_contains($s, '.')) $s = str_replace(',', '.', $s);            // 12,50
    else $s = str_replace(',', '', $s);                                                              // 1,234.56
    return round((float)preg_replace('/[^0-9.\-]/', '', $s), 2);
}

/**
 * Lee un comprobante electrónico del SRI (el XML "autorizado" con <autorizacion><comprobante><![CDATA[…]]>
 * o el comprobante solo). Devuelve ['ok' => true, ...datos] o ['ok' => false, 'error' => '...'].
 */
function sri_leer_comprobante(string $contenido): array
{
    $contenido = trim(preg_replace('/^\xEF\xBB\xBF/', '', $contenido));
    if ($contenido === '') return ['ok' => false, 'error' => 'El archivo está vacío.'];
    libxml_use_internal_errors(true);
    $original = $contenido; // se guarda completo (con la autorización del SRI si la trae)
    $xml = simplexml_load_string($contenido, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if ($xml === false) return ['ok' => false, 'error' => 'No es un XML válido.'];
    $clave_aut = '';
    $fecha_aut = '';
    // Envoltura de autorización (así lo entrega el SRI en línea y el web service)
    if (in_array($xml->getName(), ['autorizacion', 'RespuestaAutorizacionComprobante'], true) || isset($xml->comprobante)) {
        $aut = $xml->getName() === 'autorizacion' ? $xml : ($xml->xpath('//autorizacion')[0] ?? $xml);
        $clave_aut = trim((string)($aut->numeroAutorizacion ?? ''));
        $fecha_aut = trim((string)($aut->fechaAutorizacion ?? ''));
        $interno = trim((string)($aut->comprobante ?? ''));
        if ($interno === '') return ['ok' => false, 'error' => 'El XML no trae el comprobante.'];
        $xml = simplexml_load_string($interno, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        if ($xml === false) return ['ok' => false, 'error' => 'El comprobante dentro del XML no es válido.'];
    }
    $raiz = $xml->getName();
    $it = $xml->infoTributaria;
    if (!$it || !isset($it->ruc)) return ['ok' => false, 'error' => 'No es un comprobante electrónico del SRI (falta infoTributaria).'];
    $d = [
        'ok' => true, 'raiz' => $raiz, 'tipo' => sri_nombre_tipo(trim((string)$it->codDoc)),
        'clave_acceso' => preg_replace('/\D/', '', (string)$it->claveAcceso),
        'ruc_emisor' => preg_replace('/\D/', '', (string)$it->ruc),
        'razon_social' => trim((string)$it->razonSocial) ?: trim((string)$it->nombreComercial),
        'numero' => str_pad(trim((string)$it->estab), 3, '0', STR_PAD_LEFT) . '-' . str_pad(trim((string)$it->ptoEmi), 3, '0', STR_PAD_LEFT) . '-' . str_pad(trim((string)$it->secuencial), 9, '0', STR_PAD_LEFT),
        'fecha' => '', 'id_comprador' => '', 'base_0' => 0.0, 'base_iva' => 0.0, 'iva' => 0.0, 'total' => 0.0, 'subtotal' => 0.0,
        'detalle' => '', 'doc_modificado' => '', 'xml' => $original,
    ];
    if ($d['clave_acceso'] === '' && preg_match('/^\d{49}$/', $clave_aut)) $d['clave_acceso'] = $clave_aut;
    $sumar = function ($lista) use (&$d) {
        foreach ($lista as $imp) {
            if (trim((string)$imp->codigo) !== '2') continue; // 2 = IVA
            $cp = trim((string)$imp->codigoPorcentaje);
            $base = sri_num($imp->baseImponible);
            $valor = sri_num($imp->valor);
            if (in_array($cp, ['0', '6', '7'], true) || ($valor == 0.0 && $base > 0)) $d['base_0'] += $base;
            else { $d['base_iva'] += $base; $d['iva'] += $valor; }
        }
    };
    if ($raiz === 'factura') {
        $inf = $xml->infoFactura;
        $d['fecha'] = sri_fecha((string)$inf->fechaEmision);
        $d['id_comprador'] = preg_replace('/[^0-9A-Za-z]/', '', (string)$inf->identificacionComprador);
        $d['subtotal'] = sri_num($inf->totalSinImpuestos);
        $d['total'] = sri_num($inf->importeTotal);
        if (isset($inf->totalConImpuestos->totalImpuesto)) $sumar($inf->totalConImpuestos->totalImpuesto);
    } elseif ($raiz === 'liquidacionCompra') {
        $inf = $xml->infoLiquidacionCompra;
        $d['fecha'] = sri_fecha((string)$inf->fechaEmision);
        $d['subtotal'] = sri_num($inf->totalSinImpuestos);
        $d['total'] = sri_num($inf->importeTotal);
        if (isset($inf->totalConImpuestos->totalImpuesto)) $sumar($inf->totalConImpuestos->totalImpuesto);
    } elseif ($raiz === 'notaCredito') {
        $inf = $xml->infoNotaCredito;
        $d['fecha'] = sri_fecha((string)$inf->fechaEmision);
        $d['id_comprador'] = preg_replace('/[^0-9A-Za-z]/', '', (string)$inf->identificacionComprador);
        $d['subtotal'] = sri_num($inf->totalSinImpuestos);
        $d['total'] = sri_num($inf->valorModificacion);
        $d['doc_modificado'] = trim((string)$inf->numDocModificado);
        if (isset($inf->totalConImpuestos->totalImpuesto)) $sumar($inf->totalConImpuestos->totalImpuesto);
    } elseif ($raiz === 'notaDebito') {
        $inf = $xml->infoNotaDebito;
        $d['fecha'] = sri_fecha((string)$inf->fechaEmision);
        $d['id_comprador'] = preg_replace('/[^0-9A-Za-z]/', '', (string)$inf->identificacionComprador);
        $d['subtotal'] = sri_num($inf->totalSinImpuestos);
        $d['total'] = sri_num($inf->valorTotal);
        $d['doc_modificado'] = trim((string)$inf->numDocModificado);
        if (isset($inf->impuestos->impuesto)) $sumar($inf->impuestos->impuesto);
    } elseif ($raiz === 'comprobanteRetencion') {
        return ['ok' => false, 'error' => 'Es un comprobante de RETENCIÓN (no es un gasto): no se importa.', 'tipo' => 'retencion'];
    } else {
        return ['ok' => false, 'error' => 'Tipo de comprobante no reconocido (' . $raiz . ').'];
    }
    // Detalle: las primeras líneas de lo que se compró
    $items = [];
    foreach ($xml->xpath('//detalles/detalle') ?: [] as $det) {
        $txt = trim((string)$det->descripcion);
        if ($txt === '') $txt = trim((string)$det->motivo);
        if ($txt !== '') $items[] = (sri_num($det->cantidad) > 1 ? rtrim(rtrim(number_format(sri_num($det->cantidad), 2, '.', ''), '0'), '.') . ' × ' : '') . $txt;
    }
    foreach ($xml->xpath('//motivos/motivo') ?: [] as $mo) if (trim((string)$mo->razon) !== '') $items[] = trim((string)$mo->razon);
    $d['detalle'] = mb_substr(implode(' · ', array_slice($items, 0, 6)) . (count($items) > 6 ? ' · y ' . (count($items) - 6) . ' más' : ''), 0, 400);
    foreach (['base_0', 'base_iva', 'iva'] as $k) $d[$k] = round($d[$k], 2);
    if ($d['base_0'] == 0.0 && $d['base_iva'] == 0.0 && $d['subtotal'] > 0) {
        if ($d['total'] > $d['subtotal']) { $d['base_iva'] = $d['subtotal']; $d['iva'] = round($d['total'] - $d['subtotal'], 2); }
        else $d['base_0'] = $d['subtotal'];
    }
    if ($d['total'] == 0.0) $d['total'] = round($d['base_0'] + $d['base_iva'] + $d['iva'], 2);
    if ($d['fecha'] === '' && $d['clave_acceso'] !== '') $d['fecha'] = sri_datos_clave($d['clave_acceso'])['fecha'];
    return $d;
}

/**
 * Descarga del web service del SRI el comprobante autorizado de una clave de acceso.
 * Devuelve ['ok' => true, 'xml' => '<autorizacion>…'] o ['ok' => false, 'error' => '…'].
 */
function sri_descargar(string $clave): array
{
    if (!sri_clave_valida($clave)) return ['ok' => false, 'error' => 'Clave de acceso no válida.'];
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'El servidor no tiene cURL (instale php-curl).'];
    $url = substr($clave, 23, 1) === '1'
        ? 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline'
        : 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline';
    if (defined('SRI_WS_URL')) $url = SRI_WS_URL; // solo para pruebas
    $soap = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ec="http://ec.gob.sri.ws.autorizacion">'
        . '<soapenv:Header/><soapenv:Body><ec:autorizacionComprobante><claveAccesoComprobante>' . $clave . '</claveAccesoComprobante>'
        . '</ec:autorizacionComprobante></soapenv:Body></soapenv:Envelope>';
    $resp = false;
    $cod = 0;
    $err = '';
    for ($intento = 1; $intento <= 2; $intento++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $soap, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml;charset=UTF-8', 'SOAPAction: ""'], CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25]);
        $resp = curl_exec($ch);
        $cod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        unset($ch); // (v24) curl_close() ya no hace nada desde PHP 8 y PHP 8.5 lo marca como obsoleto
        if ($resp !== false && $cod === 200) break;
        if ($intento === 1) sleep(1);
    }
    if ($resp === false || $cod === 0) return ['ok' => false, 'error' => 'Sin conexión con el SRI' . ($err !== '' ? " ($err)" : '') . '. Intente más tarde.'];
    if ($cod !== 200) return ['ok' => false, 'error' => "El SRI respondió con código $cod. Intente más tarde."];
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$dom->loadXML((string)$resp, LIBXML_NONET)) return ['ok' => false, 'error' => 'La respuesta del SRI no es válida.'];
    $auts = $dom->getElementsByTagName('autorizacion');
    if ($auts->length === 0) return ['ok' => false, 'error' => 'El SRI no tiene registrado este comprobante.'];
    $el = null;
    foreach ($auts as $a) {
        $est = trim((string)($a->getElementsByTagName('estado')->item(0)?->textContent ?? ''));
        if ($est === 'AUTORIZADO') { $el = $a; break; }
        $el ??= $a;
    }
    $estado = trim((string)($el->getElementsByTagName('estado')->item(0)?->textContent ?? ''));
    if ($estado !== 'AUTORIZADO') return ['ok' => false, 'error' => 'El comprobante está ' . ($estado ?: 'sin estado') . ' en el SRI.'];
    $comp = trim((string)($el->getElementsByTagName('comprobante')->item(0)?->textContent ?? ''));
    if ($comp === '') return ['ok' => false, 'error' => 'El SRI no envió el comprobante.'];
    $num = trim((string)($el->getElementsByTagName('numeroAutorizacion')->item(0)?->textContent ?? ''));
    $fec = trim((string)($el->getElementsByTagName('fechaAutorizacion')->item(0)?->textContent ?? ''));
    $xml = '<?xml version="1.0" encoding="UTF-8"?><autorizacion><estado>AUTORIZADO</estado><numeroAutorizacion>' . htmlspecialchars($num, ENT_XML1)
        . '</numeroAutorizacion><fechaAutorizacion>' . htmlspecialchars($fec, ENT_XML1) . '</fechaAutorizacion><comprobante><![CDATA['
        . str_replace(']]>', ']]]]><![CDATA[>', $comp) . ']]></comprobante></autorizacion>';
    return ['ok' => true, 'xml' => $xml];
}

/* ---------- Guardar facturas recibidas ---------- */

/** RUC o cédula de la organización (para avisar si una factura está a nombre de otra persona). */
function ruc_organizacion(array $liga): string
{
    return preg_replace('/\D/', '', (string)($liga['ruc'] ?? ''));
}

/**
 * Guarda (o completa) una factura recibida. $d viene de sri_leer_comprobante() o del TXT.
 * Devuelve 'nueva' | 'completada' | 'repetida'.
 */
function guardar_compra(int $lid, array $d, string $origen, string $por): string
{
    $clave = preg_replace('/\D/', '', (string)($d['clave_acceso'] ?? ''));
    $ya = $clave !== '' ? fila('SELECT * FROM compras WHERE liga_id = ? AND clave_acceso = ?', [$lid, $clave]) : null;
    if (!$ya && $clave === '' && ($d['numero'] ?? '') !== '') {
        $ya = fila('SELECT * FROM compras WHERE liga_id = ? AND ruc_emisor = ? AND numero = ? AND tipo = ?', [$lid, $d['ruc_emisor'], $d['numero'], $d['tipo']]);
    }
    $completa = ($d['xml'] ?? '') !== '' ? 1 : 0;
    $campos = [
        'tipo' => $d['tipo'] ?? 'factura', 'ruc_emisor' => mb_substr((string)($d['ruc_emisor'] ?? ''), 0, 13), 'razon_social' => mb_substr((string)($d['razon_social'] ?? ''), 0, 200),
        'numero' => mb_substr((string)($d['numero'] ?? ''), 0, 20), 'fecha' => (string)($d['fecha'] ?? ''), 'id_comprador' => mb_substr((string)($d['id_comprador'] ?? ''), 0, 20),
        'base_0' => (float)($d['base_0'] ?? 0), 'base_iva' => (float)($d['base_iva'] ?? 0), 'iva' => (float)($d['iva'] ?? 0), 'total' => (float)($d['total'] ?? 0),
        'detalle' => (string)($d['detalle'] ?? ''), 'doc_modificado' => mb_substr((string)($d['doc_modificado'] ?? ''), 0, 20),
    ];
    if ($ya) {
        // Un TXT no pisa lo que ya vino del XML; el XML sí completa lo que vino del TXT
        if ((int)$ya['completa'] === 1 || !$completa) return 'repetida';
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
        q("UPDATE compras SET $sets, completa = 1, xml = ?, origen = ? WHERE id = ?", array_merge(array_values($campos), [$d['xml'], $ya['origen'] === 'txt' ? 'txt+xml' : $origen, $ya['id']]));
        if ($ya['movimiento_id']) {
            $signo = $campos['tipo'] === 'nota_credito' ? -1 : 1;
            q('UPDATE movimientos SET monto = ?, proveedor = ? WHERE id = ?', [$signo * $campos['total'], $campos['razon_social'], $ya['movimiento_id']]);
        }
        return 'completada';
    }
    q('INSERT INTO compras (liga_id, clave_acceso, ' . implode(', ', array_keys($campos)) . ', origen, completa, xml, registrado_por) VALUES (?, ?, ' . implode(', ', array_fill(0, count($campos), '?')) . ', ?, ?, ?, ?)',
        array_merge([$lid, $clave], array_values($campos), [$origen, $completa, (string)($d['xml'] ?? ''), $por]));
    return 'nueva';
}

/** Archivos subidos en un campo múltiple (name="xml[]"), incluidos los XML que vengan dentro de un .zip. */
function archivos_xml_subidos(string $campo): array
{
    $f = $_FILES[$campo] ?? null;
    if (!$f) return [];
    $n = is_array($f['name']) ? count($f['name']) : 1;
    $res = [];
    for ($i = 0; $i < $n; $i++) {
        $nom = is_array($f['name']) ? $f['name'][$i] : $f['name'];
        $tmp = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
        $err = is_array($f['error']) ? $f['error'][$i] : $f['error'];
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK || !is_file($tmp)) { $res[] = [$nom, null]; continue; }
        if (str_ends_with(strtolower($nom), '.zip') && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($tmp) === true) {
                for ($k = 0; $k < min($zip->numFiles, 500); $k++) {
                    $zn = $zip->getNameIndex($k);
                    if (str_ends_with(strtolower($zn), '.xml') && ($zip->statIndex($k)['size'] ?? 0) < 2 * 1024 * 1024) $res[] = [basename($zn), (string)$zip->getFromIndex($k)];
                }
                $zip->close();
            }
            continue;
        }
        $res[] = [$nom, filesize($tmp) < 2 * 1024 * 1024 ? (string)file_get_contents($tmp) : null];
    }
    return $res;
}

/** Importa varios XML. Devuelve conteos y los errores por archivo. */
function importar_xml_sri(array $liga, array $archivos, string $por): array
{
    $r = ['nueva' => 0, 'completada' => 0, 'repetida' => 0, 'errores' => []];
    foreach ($archivos as [$nom, $cont]) {
        if ($cont === null) { $r['errores'][] = "$nom: no se pudo leer (¿pesa más de 2 MB?)."; continue; }
        $d = sri_leer_comprobante($cont);
        if (!$d['ok']) { $r['errores'][] = "$nom: " . $d['error']; continue; }
        $r[guardar_compra((int)$liga['id'], $d, 'xml', $por)]++;
    }
    return $r;
}

/**
 * Importa el reporte TXT de "Comprobantes electrónicos recibidos" que se descarga de SRI en línea
 * (separado por tabulaciones; también acepta ; o ,). Se guía por los títulos de las columnas y,
 * si no los hay, reconoce la clave de acceso, el RUC, la fecha y el número por su forma.
 */
function importar_txt_sri(array $liga, string $contenido, string $por): array
{
    $r = ['nueva' => 0, 'completada' => 0, 'repetida' => 0, 'errores' => [], 'claves' => []];
    $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
    if (!mb_check_encoding($contenido, 'UTF-8')) $contenido = mb_convert_encoding($contenido, 'UTF-8', 'ISO-8859-1');
    $lineas = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $contenido))), fn($x) => $x !== ''));
    $mapa = [];
    foreach ($lineas as $idx => $linea) {
        $cols = explode("\t", $linea);
        if (count($cols) < 4) $cols = str_getcsv($linea, ';');
        if (count($cols) < 4) $cols = str_getcsv($linea, ',');
        $cols = array_map(fn($c) => trim($c, " \"'"), $cols);
        // ¿Fila de títulos?
        $norm = array_map(fn($c) => strtoupper(str_replace([' ', '-'], '_', slugify($c))), $cols);
        if (!preg_match('/\d{49}/', $linea) && (in_array('RUC_EMISOR', $norm, true) || in_array('CLAVE_ACCESO', $norm, true) || in_array('COMPROBANTE', $norm, true))) {
            $mapa = array_flip($norm);
            continue;
        }
        $val = fn(array $nombres) => (function () use ($nombres, $mapa, $cols) { foreach ($nombres as $n) if (isset($mapa[$n]) && ($cols[$mapa[$n]] ?? '') !== '') return $cols[$mapa[$n]]; return ''; })();
        $clave = preg_replace('/\D/', '', $val(['CLAVE_ACCESO', 'CLAVE_DE_ACCESO']));
        if (strlen($clave) !== 49) { preg_match('/(?<!\d)\d{49}(?!\d)/', $linea, $m); $clave = $m[0] ?? ''; }
        if ($clave === '' || !sri_clave_valida($clave)) { if (preg_match('/\d{10,}/', $linea)) $r['errores'][] = 'Línea ' . ($idx + 1) . ': sin clave de acceso válida.'; continue; }
        $dc = sri_datos_clave($clave);
        if (in_array($dc['tipo'], ['retencion', 'guia', 'otro'], true)) continue;
        $ruc = preg_replace('/\D/', '', $val(['RUC_EMISOR', 'RUC'])) ?: $dc['ruc_emisor'];
        $razon = $val(['RAZON_SOCIAL_EMISOR', 'RAZON_SOCIAL', 'NOMBRE_EMISOR']);
        if ($razon === '') foreach ($cols as $c) if (preg_match('/[A-Za-zÁÉÍÓÚÑáéíóúñ]{3,}/u', $c) && !preg_match('/^(factura|nota|liquid|comprobante|autorizad)/i', $c)) { $razon = $c; break; }
        $fecha = sri_fecha($val(['FECHA_EMISION', 'FECHA'])) ?: $dc['fecha'];
        $total = sri_num($val(['IMPORTE_TOTAL', 'VALOR_TOTAL', 'TOTAL']));
        if ($total == 0.0 && !$mapa) { $nums = array_values(array_filter($cols, fn($c) => preg_match('/^-?\d+([.,]\d{1,2})$/', $c))); $total = $nums ? sri_num(end($nums)) : 0.0; }
        $sin = sri_num($val(['VALOR_SIN_IMPUESTOS', 'SUBTOTAL', 'BASE_IMPONIBLE']));
        $iva = sri_num($val(['IVA', 'VALOR_IVA']));
        $d = ['clave_acceso' => $clave, 'tipo' => $dc['tipo'], 'ruc_emisor' => $ruc, 'razon_social' => mb_strtoupper($razon), 'numero' => $dc['numero'],
            'fecha' => $fecha, 'id_comprador' => preg_replace('/\D/', '', $val(['IDENTIFICACION_RECEPTOR', 'RUC_RECEPTOR'])),
            'base_0' => $iva > 0 ? 0.0 : ($sin ?: $total), 'base_iva' => $iva > 0 ? $sin : 0.0, 'iva' => $iva, 'total' => $total, 'xml' => ''];
        $r[guardar_compra((int)$liga['id'], $d, 'txt', $por)]++;
        $r['claves'][] = $clave;
    }
    if (!$r['nueva'] && !$r['repetida'] && !$r['completada'] && !$r['errores']) $r['errores'][] = 'El archivo no tiene comprobantes. Descargue de SRI en línea el reporte de "Comprobantes electrónicos recibidos" (archivo .txt).';
    return $r;
}

/** Descarga del SRI el XML de las facturas que llegaron solo del TXT (o de claves pegadas). Máximo $max por vez. */
function completar_desde_sri(array $liga, array $ids_o_claves, string $por, int $max = 15): array
{
    $r = ['nueva' => 0, 'completada' => 0, 'repetida' => 0, 'errores' => [], 'pendientes' => 0];
    $lista = array_slice($ids_o_claves, 0, $max);
    $r['pendientes'] = max(0, count($ids_o_claves) - $max);
    foreach ($lista as $x) {
        $clave = is_int($x) ? (string)valor('SELECT clave_acceso FROM compras WHERE id = ? AND liga_id = ?', [$x, $liga['id']]) : (string)$x;
        if (!sri_clave_valida($clave)) { $r['errores'][] = "Clave no válida: $clave"; continue; }
        $des = sri_descargar($clave);
        if (!$des['ok']) { $r['errores'][] = substr($clave, 0, 10) . '…: ' . $des['error']; if (str_starts_with($des['error'], 'Sin conexión')) break; continue; }
        $d = sri_leer_comprobante($des['xml']);
        if (!$d['ok']) { $r['errores'][] = substr($clave, 0, 10) . '…: ' . $d['error']; continue; }
        $d['clave_acceso'] = $clave;
        $r[guardar_compra((int)$liga['id'], $d, 'sri', $por)]++;
    }
    return $r;
}

/** Texto corto con el resultado de una importación. */
function texto_importacion(array $r): string
{
    $p = [];
    if ($r['nueva']) $p[] = $r['nueva'] . ' comprobante(s) nuevo(s) en la bandeja';
    if ($r['completada']) $p[] = $r['completada'] . ' completado(s) con el detalle del XML';
    if ($r['repetida']) $p[] = $r['repetida'] . ' ya estaba(n) registrado(s)';
    if (!empty($r['pendientes'])) $p[] = 'faltan ' . $r['pendientes'] . ': vuelva a tocar el botón para seguir';
    return $p ? implode(' · ', $p) . '.' : 'No se importó ningún comprobante.';
}

/* ---------- Incluir o no incluir como gasto ---------- */

/** Pasa una factura de la bandeja a gastos (crea el egreso). Las notas de crédito restan. */
function incluir_compra(array $liga, array $c, string $categoria, ?int $torneo_id, string $forma, string $por): int
{
    if ($c['estado'] === 'incluida' && $c['movimiento_id']) return (int)$c['movimiento_id'];
    $cats = categorias_gasto(tipo_contable($liga));
    $categoria = isset($cats[$categoria]) ? $categoria : 'otro';
    $signo = $c['tipo'] === 'nota_credito' ? -1 : 1;
    $desc = nombre_tipo_compra($c['tipo']) . ' ' . $c['numero'] . ($c['detalle'] !== '' ? ' · ' . mb_substr($c['detalle'], 0, 120) : '');
    q("INSERT INTO movimientos (liga_id, torneo_id, tipo, concepto, descripcion, monto, fecha, forma_pago, compra_id, proveedor, factura, registrado_por) VALUES (?,?, 'egreso', ?,?,?,?,?,?,?,?,?)", [
        $liga['id'], $torneo_id ?: null, $categoria, mb_substr($desc, 0, 200), $signo * (float)$c['total'], $c['fecha'] ?: date('Y-m-d'),
        array_key_exists($forma, formas_pago()) ? $forma : 'transferencia', $c['id'], $c['razon_social'], $c['numero'], $por]);
    $mid = (int)db()->lastInsertId();
    q("UPDATE compras SET estado = 'incluida', movimiento_id = ? WHERE id = ?", [$mid, $c['id']]);
    return $mid;
}

/** Devuelve una factura a la bandeja: anula su gasto si ya estaba incluida. */
function reabrir_compra(array $c): void
{
    if ($c['movimiento_id']) q('UPDATE movimientos SET anulado = 1 WHERE id = ?', [$c['movimiento_id']]);
    q("UPDATE compras SET estado = 'pendiente', movimiento_id = NULL WHERE id = ?", [$c['id']]);
}

/* ---------- Reportes ---------- */

/** Rango de fechas del filtro (por defecto, el año en curso hasta hoy). */
function rango_contable(): array
{
    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', get('desde')) ? get('desde') : date('Y') . '-01-01';
    $h = preg_match('/^\d{4}-\d{2}-\d{2}$/', get('hasta')) ? get('hasta') : date('Y-m-d');
    if ($h < $d) [$d, $h] = [$h, $d];
    return [$d, $h];
}

/**
 * Todos los números del período: ingresos por concepto, gastos por categoría, meses, detalle de gastos y compras.
 * Ligas: pagos de equipos y reservas (ingresos) y egresos de Finanzas (incluye pagos a árbitros).
 * Escuelas: mensualidades y cargos cobrados + ventas de contado de la tienda (ingresos); egresos + roles de profesores.
 */
function datos_contables(array $liga, string $desde, string $hasta, ?int $torneo_id = null): array
{
    $lid = (int)$liga['id'];
    $escuela = es_escuela($liga);
    $ingresos = [];
    $ing_rows = [];
    $gastos = [];
    $meses = [];
    $mes = function (string $f) use (&$meses) { $m = substr($f, 0, 7); $meses[$m] ??= ['ingresos' => 0.0, 'gastos' => 0.0]; return $m; };
    $ft = $torneo_id ? ' AND m.torneo_id = ' . (int)$torneo_id : '';
    if ($escuela) {
        foreach (filas("SELECT tipo, descripcion, (monto - descuento) AS total, substr(pagado_en, 1, 10) AS fecha FROM esc_cargos WHERE liga_id = ? AND estado = 'pagado' AND substr(pagado_en, 1, 10) BETWEEN ? AND ?", [$lid, $desde, $hasta]) as $x) {
            $k = function_exists('tipos_cargo_escuela') ? (tipos_cargo_escuela()[$x['tipo']] ?? ucfirst($x['tipo'])) : ucfirst($x['tipo']);
            $ingresos[$k] = ($ingresos[$k] ?? 0) + (float)$x['total'];
            $meses[$mes($x['fecha'])]['ingresos'] += (float)$x['total'];
            $ing_rows[] = ['fecha' => $x['fecha'], 'concepto' => $k, 'descripcion' => $x['descripcion'], 'monto' => (float)$x['total']];
        }
        if (tabla_existe('esc_ventas')) foreach (filas("SELECT total, fecha FROM esc_ventas WHERE liga_id = ? AND modo = 'contado' AND anulado = 0 AND substr(fecha, 1, 10) BETWEEN ? AND ?", [$lid, $desde, $hasta]) as $x) {
            $ingresos['Tienda (ventas de contado)'] = ($ingresos['Tienda (ventas de contado)'] ?? 0) + (float)$x['total'];
            $meses[$mes($x['fecha'])]['ingresos'] += (float)$x['total'];
            $ing_rows[] = ['fecha' => substr($x['fecha'], 0, 10), 'concepto' => 'Tienda', 'descripcion' => 'Venta de contado', 'monto' => (float)$x['total']];
        }
    } else {
        foreach (filas("SELECT m.*, t.nombre AS torneo FROM movimientos m LEFT JOIN torneos t ON t.id = m.torneo_id WHERE m.liga_id = ? AND m.tipo = 'pago' AND m.anulado = 0 AND m.fecha BETWEEN ? AND ?$ft", [$lid, $desde, $hasta]) as $x) {
            $k = conceptos_cargo()[$x['concepto']] ?? ucfirst($x['concepto']);
            $ingresos[$k] = ($ingresos[$k] ?? 0) + (float)$x['monto'];
            $meses[$mes($x['fecha'])]['ingresos'] += (float)$x['monto'];
            $ing_rows[] = ['fecha' => $x['fecha'], 'concepto' => $k, 'descripcion' => $x['descripcion'], 'monto' => (float)$x['monto']];
        }
    }
    $det = [];
    $pa = tabla_existe('pagos_arbitro') ? 'LEFT JOIN pagos_arbitro pa ON pa.movimiento_id = m.id LEFT JOIN arbitros ar ON ar.id = pa.arbitro_id' : '';
    foreach (filas("SELECT m.*, t.nombre AS torneo" . ($pa ? ', pa.id AS pago_arb, pa.factura_numero AS fact_arb, ar.nombre AS arbitro' : '') . " FROM movimientos m LEFT JOIN torneos t ON t.id = m.torneo_id $pa
                    WHERE m.liga_id = ? AND m.tipo = 'egreso' AND m.anulado = 0 AND m.fecha BETWEEN ? AND ?$ft ORDER BY m.fecha, m.id", [$lid, $desde, $hasta]) as $x) {
        $origen = $x['compra_id'] ? 'SRI' : (!empty($x['pago_arb']) ? 'Árbitros' : 'Manual');
        $det[] = ['fecha' => $x['fecha'], 'categoria' => $x['concepto'], 'descripcion' => $x['descripcion'], 'proveedor' => $x['proveedor'] !== '' ? $x['proveedor'] : (string)($x['arbitro'] ?? ''),
            'factura' => $x['factura'] !== '' ? $x['factura'] : ((string)($x['fact_arb'] ?? '') ?: (string)$x['comprobante']),
            'monto' => (float)$x['monto'], 'torneo' => $x['torneo'] ?? '', 'origen' => $origen, 'id' => (int)$x['id'], 'forma' => $x['forma_pago'], 'archivo' => $x['factura_archivo'] ?? '', 'compra_id' => $x['compra_id']];
    }
    if ($escuela && tabla_existe('esc_pagos_profesor')) {
        foreach (filas("SELECT pp.*, pr.nombre AS profesor FROM esc_pagos_profesor pp LEFT JOIN esc_profesores pr ON pr.id = pp.profesor_id WHERE pp.liga_id = ? AND pp.anulado = 0 AND pp.fecha BETWEEN ? AND ? ORDER BY pp.fecha", [$lid, $desde, $hasta]) as $x) {
            $det[] = ['fecha' => $x['fecha'], 'categoria' => 'profesores', 'descripcion' => 'Rol de pago · ' . ($x['profesor'] ?? 'Profesor') . (($x['periodo'] ?? '') !== '' ? ' (' . $x['periodo'] . ')' : ''),
                'proveedor' => $x['profesor'] ?? '', 'factura' => $x['factura_numero'] ?? '', 'monto' => (float)$x['monto'], 'torneo' => '', 'origen' => 'Rol de pago', 'id' => 0, 'forma' => $x['forma_pago'] ?? '', 'archivo' => '', 'compra_id' => null];
        }
        usort($det, fn($a, $b) => [$a['fecha'], $a['id']] <=> [$b['fecha'], $b['id']]);
    }
    foreach ($det as $g) {
        $gastos[$g['categoria']] = ($gastos[$g['categoria']] ?? 0) + $g['monto'];
        $meses[$mes($g['fecha'])]['gastos'] += $g['monto'];
    }
    arsort($ingresos);
    arsort($gastos);
    ksort($meses);
    $compras = filas("SELECT c.*, m.concepto AS categoria, t.nombre AS torneo FROM compras c LEFT JOIN movimientos m ON m.id = c.movimiento_id LEFT JOIN torneos t ON t.id = m.torneo_id
                      WHERE c.liga_id = ? AND c.estado = 'incluida' AND c.fecha BETWEEN ? AND ?" . ($torneo_id ? ' AND m.torneo_id = ' . (int)$torneo_id : '') . ' ORDER BY c.fecha, c.id', [$lid, $desde, $hasta]);
    $t_ing = array_sum($ingresos);
    $t_gas = array_sum($gastos);
    return ['ingresos' => $ingresos, 'ingresos_detalle' => $ing_rows, 'gastos' => $gastos, 'meses' => $meses, 'detalle' => $det, 'compras' => $compras,
        'total_ingresos' => round($t_ing, 2), 'total_gastos' => round($t_gas, 2), 'resultado' => round($t_ing - $t_gas, 2),
        'con_factura' => round(array_sum(array_map(fn($g) => $g['factura'] !== '' ? $g['monto'] : 0, $det)), 2),
        'iva_compras' => round(array_sum(array_map(fn($c) => ($c['tipo'] === 'nota_credito' ? -1 : 1) * (float)$c['iva'], $compras)), 2)];
}

function tabla_existe(string $t): bool
{
    static $cache = [];
    return $cache[$t] ??= (bool)valor("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$t]);
}

function nombre_mes_corto(string $ym): string
{
    $m = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    return ($m[(int)substr($ym, 5, 2)] ?? '') . ' ' . substr($ym, 0, 4);
}

/** Libro de Excel con el resumen, los gastos, los ingresos y las compras con IVA del período. */
function excel_contable(array $liga, array $D, string $desde, string $hasta, string $torneo = ''): string
{
    $tit = [$liga['nombre'] . ' · Contabilidad del ' . fecha_bonita($desde) . ' al ' . fecha_bonita($hasta) . ($torneo !== '' ? ' · ' . $torneo : '')];
    $res = [$tit, [], ['INGRESOS', 'Valor']];
    foreach ($D['ingresos'] as $k => $v) $res[] = [$k, (float)$v];
    $res[] = ['Total ingresos', (float)$D['total_ingresos']];
    $n1 = count($res) - 1;
    $res[] = [];
    $res[] = ['GASTOS', 'Valor'];
    $n2 = count($res) - 1;
    foreach ($D['gastos'] as $k => $v) $res[] = [nombre_categoria_gasto($k), (float)$v];
    $res[] = ['Total gastos', (float)$D['total_gastos']];
    $n3 = count($res) - 1;
    $res[] = [];
    $res[] = ['RESULTADO (ingresos − gastos)', (float)$D['resultado']];
    $n4 = count($res) - 1;
    $res[] = [];
    $res[] = ['Mes', 'Ingresos', 'Gastos', 'Resultado'];
    $n5 = count($res) - 1;
    foreach ($D['meses'] as $m => $x) $res[] = [nombre_mes_corto($m), (float)$x['ingresos'], (float)$x['gastos'], (float)($x['ingresos'] - $x['gastos'])];
    $gas = [['Fecha', 'Categoría', 'Descripción', 'Proveedor', 'N° factura', 'Origen', 'Campeonato', 'Forma de pago', 'Valor']];
    foreach ($D['detalle'] as $g) $gas[] = [$g['fecha'], nombre_categoria_gasto($g['categoria']), $g['descripcion'], $g['proveedor'], $g['factura'], $g['origen'], $g['torneo'], formas_pago()[$g['forma']] ?? $g['forma'], (float)$g['monto']];
    $gas[] = ['', '', '', '', '', '', '', 'TOTAL', (float)$D['total_gastos']];
    $ing = [['Fecha', 'Concepto', 'Descripción', 'Valor']];
    foreach ($D['ingresos_detalle'] as $x) $ing[] = [$x['fecha'], $x['concepto'], $x['descripcion'], (float)$x['monto']];
    $ing[] = ['', '', 'TOTAL', (float)$D['total_ingresos']];
    $com = [['Fecha', 'Tipo', 'RUC proveedor', 'Proveedor', 'N° comprobante', 'Clave de acceso', 'Base 0 %', 'Base con IVA', 'IVA', 'Total', 'Categoría']];
    $sum = [0.0, 0.0, 0.0, 0.0];
    foreach ($D['compras'] as $c) {
        $s = $c['tipo'] === 'nota_credito' ? -1 : 1;
        $fila = [(float)($s * $c['base_0']), (float)($s * $c['base_iva']), (float)($s * $c['iva']), (float)($s * $c['total'])];
        foreach ($fila as $i => $v) $sum[$i] += $v;
        $com[] = array_merge([$c['fecha'], nombre_tipo_compra($c['tipo']), $c['ruc_emisor'], $c['razon_social'], $c['numero'], $c['clave_acceso']], $fila, [nombre_categoria_gasto((string)$c['categoria'])]);
    }
    $com[] = array_merge(['', '', '', '', '', 'TOTAL'], array_map(fn($v) => (float)round($v, 2), $sum), ['']);
    return crear_xlsx_libro([
        ['nombre' => 'Resumen', 'filas' => $res, 'anchos' => [38, 16, 16, 16], 'negritas' => [0, $n1, $n2, $n3, $n4, $n5]],
        ['nombre' => 'Gastos', 'filas' => $gas, 'anchos' => [12, 24, 50, 30, 20, 12, 22, 16, 14], 'negritas' => [count($gas) - 1]],
        ['nombre' => 'Ingresos', 'filas' => $ing, 'anchos' => [12, 28, 50, 14], 'negritas' => [count($ing) - 1]],
        ['nombre' => 'Compras con IVA', 'filas' => $com, 'anchos' => [12, 18, 15, 36, 20, 52, 12, 14, 12, 12, 24], 'negritas' => [count($com) - 1]],
    ]);
}
