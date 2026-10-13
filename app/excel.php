<?php
/*
 * VERSIÓN 7 — Leer y crear archivos de Excel (.xlsx) y CSV sin librerías externas.
 * El .xlsx es un archivo ZIP con XML adentro; se usa la extensión zip de PHP (php-zip, la instala el instalador).
 */

/** Lee la primera hoja de un .xlsx (o un .csv). Devuelve filas: [[celda, celda, ...], ...] (todo como texto). */
function leer_hoja(string $ruta, string $nombre_original): array
{
    $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') return leer_csv($ruta);
    if ($ext !== 'xlsx') throw new RuntimeException('Suba el archivo en formato Excel (.xlsx) o CSV. Si tiene un .xls antiguo, ábralo en Excel y use "Guardar como → Libro de Excel (.xlsx)".');
    if (!class_exists('ZipArchive')) throw new RuntimeException('El servidor no puede leer archivos .xlsx (falta php-zip). Guarde el archivo como CSV y súbalo de nuevo.');
    $zip = new ZipArchive();
    if ($zip->open($ruta) !== true) throw new RuntimeException('El archivo no es un Excel válido.');
    // Textos compartidos
    $compartidos = [];
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml !== false) {
        $sx = @simplexml_load_string($xml);
        if ($sx) {
            foreach ($sx->si as $si) {
                if (isset($si->t)) $compartidos[] = (string)$si->t;
                else { $t = ''; foreach ($si->r as $r) $t .= (string)$r->t; $compartidos[] = $t; }
            }
        }
    }
    // Primera hoja (según el libro)
    $hoja = 'xl/worksheets/sheet1.xml';
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $libro = $zip->getFromName('xl/workbook.xml');
    if ($rels !== false && $libro !== false) {
        $lx = @simplexml_load_string($libro);
        $rx = @simplexml_load_string($rels);
        if ($lx && $rx && isset($lx->sheets->sheet[0])) {
            $rid = (string)$lx->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($rx->Relationship as $r) {
                if ((string)$r['Id'] === $rid) {
                    $destino = ltrim((string)$r['Target'], '/');
                    $hoja = str_starts_with($destino, 'xl/') ? $destino : 'xl/' . $destino;
                }
            }
        }
    }
    $xml = $zip->getFromName($hoja);
    $zip->close();
    if ($xml === false) throw new RuntimeException('No se encontró la hoja de cálculo dentro del archivo.');
    $sx = @simplexml_load_string($xml);
    if (!$sx) throw new RuntimeException('No se pudo leer la hoja de cálculo.');
    $filas = [];
    foreach ($sx->sheetData->row as $row) {
        $f = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = $ref !== '' ? columna_a_numero(preg_replace('/\d+/', '', $ref)) : count($f);
            $tipo = (string)$c['t'];
            if ($tipo === 's') $v = $compartidos[(int)$c->v] ?? '';
            elseif ($tipo === 'inlineStr') $v = (string)($c->is->t ?? '');
            elseif ($tipo === 'b') $v = ((string)$c->v) === '1' ? 'SI' : 'NO';
            else $v = (string)$c->v;
            $f[$col] = trim($v);
        }
        if (!$f) continue;
        $max = max(array_keys($f));
        $completa = [];
        for ($i = 0; $i <= $max; $i++) $completa[] = $f[$i] ?? '';
        $filas[] = $completa;
    }
    return $filas;
}

function columna_a_numero(string $letras): int
{
    $n = 0;
    foreach (str_split(strtoupper($letras)) as $l) $n = $n * 26 + (ord($l) - 64);
    return max(0, $n - 1);
}

function leer_csv(string $ruta): array
{
    $txt = (string)file_get_contents($ruta);
    if (str_starts_with($txt, "\xEF\xBB\xBF")) $txt = substr($txt, 3);
    if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
    $primera = strtok($txt, "\n");
    $sep = substr_count((string)$primera, ';') >= substr_count((string)$primera, ',') ? ';' : ',';
    $filas = [];
    $h = fopen('php://memory', 'r+');
    fwrite($h, $txt);
    rewind($h);
    while (($f = fgetcsv($h, 0, $sep, '"', '')) !== false) {
        if ($f === [null]) continue;
        $filas[] = array_map(fn($x) => trim((string)$x), $f);
    }
    fclose($h);
    return $filas;
}

/** Convierte una fecha de Excel (número de serie, 15/03/2010, 2010-03-15) a AAAA-MM-DD. '' si está vacía, null si no se entiende. */
function fecha_excel(string $v): ?string
{
    $v = trim($v);
    if ($v === '') return '';
    if (preg_match('/^\d{4,5}(\.\d+)?$/', $v) && (float)$v > 3000 && (float)$v < 80000) {
        return date('Y-m-d', (int)round(((float)$v - 25569) * 86400));
    }
    if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $v, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    if (preg_match('#^(\d{4})[/.-](\d{1,2})[/.-](\d{1,2})#', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    return null;
}

/** Crea un .xlsx sencillo con una hoja. $filas = [[texto, texto...], ...]; la primera fila va en negrita. */
function crear_xlsx(array $filas, string $nombre_hoja = 'Hoja1', array $anchos = []): string
{
    return crear_xlsx_libro([['nombre' => $nombre_hoja, 'filas' => $filas, 'anchos' => $anchos]]);
}

/**
 * (v18) Libro de Excel real (.xlsx) con una o varias hojas. Los números se guardan como NÚMEROS (se pueden sumar y
 * graficar en Excel): int = entero, float = valor con 2 decimales. La primera fila de cada hoja va en negrita.
 * Cada hoja: ['nombre' => 'Gastos', 'filas' => [[...], ...], 'anchos' => [12, 40, ...], 'negritas' => [índices de fila]].
 */
function crear_xlsx_libro(array $hojas): string
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('El servidor no puede crear archivos .xlsx (falta php-zip).');
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $letra = function (int $i): string { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; };
    $hojas = array_values($hojas) ?: [['nombre' => 'Hoja1', 'filas' => []]];
    $usados = [];
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $ct = '';
    $wb = '';
    $rels = '';
    foreach ($hojas as $n => $h) {
        $num = $n + 1;
        $neg = array_flip($h['negritas'] ?? []);
        $filas_xml = '';
        foreach (array_values($h['filas'] ?? []) as $r => $fila) {
            $filas_xml .= '<row r="' . ($r + 1) . '">';
            $b = $r === 0 || isset($neg[$r]);
            foreach (array_values($fila) as $c => $v) {
                $ref = $letra($c) . ($r + 1);
                if ($v === null || $v === '') continue;
                if (is_int($v)) {
                    $filas_xml .= '<c r="' . $ref . '"' . ($b ? ' s="1"' : '') . '><v>' . $v . '</v></c>';
                } elseif (is_float($v)) {
                    $filas_xml .= '<c r="' . $ref . '" s="' . ($b ? 3 : 2) . '"><v>' . round($v, 2) . '</v></c>';
                } else {
                    $filas_xml .= '<c r="' . $ref . '" t="inlineStr"' . ($b ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
                }
            }
            $filas_xml .= '</row>';
        }
        $cols = '';
        if (!empty($h['anchos'])) {
            $cols = '<cols>';
            foreach (array_values($h['anchos']) as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
            $cols .= '</cols>';
        }
        $vista = '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        $zip->addFromString("xl/worksheets/sheet$num.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $vista . $cols . '<sheetData>' . $filas_xml . '</sheetData></worksheet>');
        // Nombre de hoja: máx. 31 caracteres, sin : \ / ? * [ ] y sin repetir
        $nom = mb_substr(trim(preg_replace('#[:\\\\/?*\[\]]#', ' ', (string)($h['nombre'] ?? 'Hoja' . $num))) ?: 'Hoja' . $num, 0, 31);
        while (isset($usados[mb_strtolower($nom)])) $nom = mb_substr($nom, 0, 28) . ' ' . $num;
        $usados[mb_strtolower($nom)] = true;
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $num . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wb .= '<sheet name="' . $x($nom) . '" sheetId="' . $num . '" r:id="rId' . $num . '"/>';
        $rels .= '<Relationship Id="rId' . $num . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $num . '.xml"/>';
    }
    $n_estilos = count($hojas) + 1;
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $ct . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wb . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '<Relationship Id="rId' . $n_estilos . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    // Estilos: 0 normal · 1 negrita · 2 moneda (#,##0.00) · 3 moneda en negrita
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="4"><xf/><xf fontId="1" applyFont="1"/><xf numFmtId="164" applyNumberFormat="1"/><xf numFmtId="164" fontId="1" applyNumberFormat="1" applyFont="1"/></cellXfs></styleSheet>');
    $zip->close();
    $bin = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}
