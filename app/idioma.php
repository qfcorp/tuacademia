<?php
/*
 * (v28) IDIOMAS: español (base), inglés, francés, portugués e italiano.
 *
 *  - Selector 🌐 en la barra de arriba (panel y páginas públicas). Se recuerda en una cookie y, si el usuario entró
 *    al panel, en su cuenta. Cada liga o escuela elige el idioma por defecto de su página pública (Parámetros).
 *  - La traducción se hace al final, sobre la página ya armada: se traduce cada texto visible usando los diccionarios
 *    de app/idiomas/xx.php (texto en español => traducción). Los nombres de equipos, jugadores, etc. no se tocan.
 *    Los números se respetan: "Quedan 5 días" usa la entrada "Quedan {n} días".
 *  - Lo que todavía no tiene traducción se queda en español (no se rompe nada).
 */

function idiomas(): array
{
    return ['es' => ['Español', '🇪🇸'], 'en' => ['English', '🇬🇧'], 'fr' => ['Français', '🇫🇷'], 'pt' => ['Português', '🇧🇷'], 'it' => ['Italiano', '🇮🇹']];
}

/** Idioma de esta página: ?lang= (y se recuerda) › cookie › usuario › idioma de la liga › español. */
function idioma_actual(bool $refrescar = false): string
{
    $i = &$GLOBALS['__idioma'];
    if ($i !== null && !$refrescar) return $i;
    $ok = array_keys(idiomas());
    $q = (string)($_GET['lang'] ?? '');
    if (in_array($q, $ok, true)) {
        if (PHP_SAPI !== 'cli' && !headers_sent()) setcookie('idioma', $q, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
        $_COOKIE['idioma'] = $q;
        if (function_exists('usuario') && ($u = usuario()) && !es_usuario_demo($u) && ($u['idioma'] ?? 'es') !== $q) q('UPDATE usuarios SET idioma = ? WHERE id = ?', [$q, $u['id']]);
        return $i = $q;
    }
    $c = (string)($_COOKIE['idioma'] ?? '');
    if (in_array($c, $ok, true)) return $i = $c;
    if (!empty($_SESSION['uid']) && function_exists('usuario') && ($u = usuario()) && !es_usuario_demo($u) && in_array($u['idioma'] ?? '', $ok, true)) return $i = $u['idioma'];
    if (!empty($GLOBALS['__idioma_liga']) && in_array($GLOBALS['__idioma_liga'], $ok, true)) return $i = $GLOBALS['__idioma_liga'];
    return $i = 'es';
}

/** Idioma por defecto de la liga para su página pública (si el visitante no eligió otro). */
function aplicar_idioma_liga(?array $liga): void
{
    if (!$liga) return;
    $GLOBALS['__idioma_liga'] = (string)($liga['idioma'] ?? 'es');
    idioma_actual(true);
    iniciar_traduccion();
}

/** Diccionario del idioma (texto en español => traducción). */
function diccionario(string $idioma): array
{
    static $d = [];
    if (!isset($d[$idioma])) {
        $f = APP_DIR . '/idiomas/' . $idioma . '.php';
        $d[$idioma] = is_file($f) ? (array)(include $f) : [];
    }
    return $d[$idioma];
}

/** Traduce un texto suelto (sin HTML). Si no hay traducción, lo devuelve igual. */
function tr(string $texto, ?string $idioma = null): string
{
    $idioma = $idioma ?? idioma_actual();
    if ($idioma === 'es') return $texto;
    $dic = diccionario($idioma);
    if (!$dic) return $texto;
    if (!preg_match('/^(\s*)(.*?)(\s*)$/su', $texto, $m)) return $texto;
    [$_, $ini, $t, $fin] = $m;
    if ($t === '' || !preg_match('/\p{L}{2}/u', $t)) return $texto;
    $t1 = preg_replace('/\s+/u', ' ', $t);
    $r = tr_buscar($t1, $dic);
    if ($r === null && !empty(CFG['idioma_faltantes'])) idioma_anotar_faltante($t1); // (v30) solo en la copia de desarrollo
    return $r === null ? $texto : $ini . $r . $fin;
}

/** (v30) Solo para desarrollo (config 'idioma_faltantes' => archivo): anota los textos que no tienen traducción. */
function idioma_anotar_faltante(string $t, bool $guardar = false): void
{
    static $lista = [];
    if ($t !== '') $lista[$t] = true;
    if ($guardar && $lista) {
        @file_put_contents(CFG['idioma_faltantes'], implode("\n", array_keys($lista)) . "\n", FILE_APPEND | LOCK_EX);
        $lista = [];
    }
}

function tr_buscar(string $t, array $dic, int $nivel = 0): ?string
{
    if (isset($dic[$t])) return $dic[$t];
    // Con números: "Quedan 5 días" → "Quedan {n} días"
    if (preg_match_all('/\d+(?:[.,]\d+)*/', $t, $nums)) {
        $k = preg_replace('/\d+(?:[.,]\d+)*/', '{n}', $t);
        if (isset($dic[$k])) {
            $i = 0;
            return preg_replace_callback('/\{n\}/', function () use (&$i, $nums) { return $nums[0][$i++] ?? ''; }, $dic[$k]);
        }
    }
    if ($nivel > 2) return null;
    // Con íconos o símbolos adelante: "📋 Tomar asistencia" → se traduce "Tomar asistencia"
    if (preg_match('/^([^\p{L}\p{N}¿¡«"(]+)(\p{L}.*)$/u', $t, $m) && $m[1] !== '') {
        $x = tr_buscar($m[2], $dic, $nivel + 1);
        if ($x !== null) return $m[1] . $x;
    }
    // Con dos puntos al final: "Estado:" → "Estado"
    if (str_ends_with($t, ':') && mb_strlen($t) > 2) {
        $x = tr_buscar(rtrim(mb_substr($t, 0, -1)), $dic, $nivel + 1);
        if ($x !== null) return $x . ':';
    }
    // Plantillas con {s} (texto variable): "Resumen de {s}"
    foreach (plantillas_idioma($dic) as [$re, $dest]) {
        if (preg_match($re, $t, $m)) {
            $i = 1;
            return preg_replace_callback('/\{s\}/', function () use (&$i, $m) { return $m[$i++] ?? ''; }, $dest);
        }
    }
    // Partes separadas con " · "
    if (str_contains($t, ' · ')) {
        $partes = explode(' · ', $t);
        $alguna = false;
        foreach ($partes as $j => $p) {
            $x = tr_buscar(trim($p), $dic, $nivel + 1);
            if ($x !== null) { $partes[$j] = $x; $alguna = true; }
        }
        return $alguna ? implode(' · ', $partes) : null;
    }
    // "Etiqueta: valor" → se traduce la etiqueta
    if (preg_match('/^(.{1,40}?:)\s(.+)$/u', $t, $m)) {
        $x = tr_buscar($m[1], $dic, $nivel + 1);
        if ($x !== null) return $x . ' ' . (tr_buscar($m[2], $dic, $nivel + 1) ?? $m[2]);
    }
    return null;
}

/** Entradas del diccionario con {s} convertidas en expresiones regulares (se calculan una vez). */
function plantillas_idioma(array $dic): array
{
    static $cache = [];
    $k = spl_object_id((object)[]) && isset($dic['__id']) ? $dic['__id'] : md5((string)count($dic) . (string)array_key_first($dic));
    if (isset($cache[$k])) return $cache[$k];
    $out = [];
    foreach ($dic as $es => $tr) {
        if (!str_contains($es, '{s}')) continue;
        $re = '/^' . str_replace('\\{s\\}', '(.+?)', preg_quote($es, '/')) . '$/u';
        $out[] = [$re, $tr];
    }
    return $cache[$k] = $out;
}

/** Traduce el HTML de la página completa (textos visibles y algunos atributos). */
function traducir_html(string $html, string $idioma): string
{
    if ($idioma === 'es' || $html === '' || !diccionario($idioma)) return $html;
    // No se tocan scripts, estilos, áreas de texto, SVG ni código
    // (v30) tampoco lo que está entre <!--notr--> y <!--/notr--> (por ejemplo, un manual que ya está en ese idioma)
    $partes = preg_split('#(<(script|style|textarea|svg|code|pre)\b.*?</\2>|<!--notr-->.*?<!--/notr-->)#si', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    for ($i = 0, $n = count($partes); $i < $n; $i++) {
        $p = $partes[$i];
        if (str_starts_with($p, '<!--notr-->')) {
            $out .= $p;
            continue;
        }
        if (preg_match('#^<(script|style|textarea|svg|code|pre)\b#i', $p)) {
            $out .= $p;
            $i++; // el nombre de la etiqueta capturado
            continue;
        }
        // Textos entre etiquetas
        $p = preg_replace_callback('/>([^<>]+)</u', function ($m) use ($idioma) {
            $raw = $m[1];
            if (!preg_match('/\p{L}{2}/u', $raw)) return $m[0];
            $txt = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $tr = tr($txt, $idioma);
            return $tr === $txt ? $m[0] : '>' . htmlspecialchars($tr, ENT_NOQUOTES, 'UTF-8') . '<';
        }, $p);
        // Atributos visibles
        $p = preg_replace_callback('/\b(placeholder|title|aria-label|alt|data-confirmar)="([^"]*)"/u', function ($m) use ($idioma) {
            $txt = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $tr = tr($txt, $idioma);
            return $tr === $txt ? $m[0] : $m[1] . '="' . htmlspecialchars($tr, ENT_QUOTES, 'UTF-8') . '"';
        }, $p);
        $p = preg_replace_callback('/<input\b[^>]*\btype="(?:submit|button)"[^>]*>/iu', function ($m) use ($idioma) {
            return preg_replace_callback('/\bvalue="([^"]*)"/u', function ($v) use ($idioma) {
                $txt = html_entity_decode($v[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return 'value="' . htmlspecialchars(tr($txt, $idioma), ENT_QUOTES, 'UTF-8') . '"';
            }, $m[0]);
        }, $p);
        $out .= $p;
    }
    if (!empty(CFG['idioma_faltantes'])) idioma_anotar_faltante('', true); // (v30) solo en desarrollo
    return preg_replace('/<html lang="es"/', '<html lang="' . $idioma . '"', $out, 1);
}

/** Empieza a capturar la página para traducirla al final (solo si el idioma no es español). */
function iniciar_traduccion(): void
{
    if (PHP_SAPI === 'cli' || !empty($GLOBALS['__traduciendo'])) return;
    $idioma = idioma_actual();
    if ($idioma === 'es') return;
    $GLOBALS['__traduciendo'] = true;
    ob_start(function (string $buf) use ($idioma) {
        foreach (headers_list() as $h) {
            if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'text/html') === false) return $buf;
            if (stripos($h, 'Content-Disposition:') === 0) return $buf;
        }
        return traducir_html($buf, $idioma);
    });
}

/** Selector de idioma (🌐) para la barra de arriba. */
function html_selector_idioma(string $clase = ''): string
{
    $act = idioma_actual();
    $q = $_GET;
    $h = '<details class="selector-idioma ' . e($clase) . '"><summary title="Idioma / Language">🌐 ' . strtoupper($act) . '</summary><div class="selector-idioma-menu">';
    foreach (idiomas() as $k => [$nom, $flag]) {
        $q['lang'] = $k;
        $h .= '<a href="' . e(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php')) . '?' . http_build_query($q)) . '"' . ($k === $act ? ' class="activo"' : '') . ' lang="' . $k . '">' . $flag . ' ' . e($nom) . '</a>';
    }
    return $h . '</div></details>';
}

/** Guarda el idioma de la página pública de la liga o escuela (Parámetros). */
function guardar_idioma_liga(array $liga): void
{
    $l = (string)post('idioma');
    if (!isset(idiomas()[$l])) $l = 'es';
    q('UPDATE ligas SET idioma = ? WHERE id = ?', [$l, $liga['id']]);
    flash('Idioma de la página pública: ' . idiomas()[$l][0] . '. Cada visitante puede cambiarlo con el botón 🌐.');
}

/** Tarjeta "Idioma de la página pública" para Parámetros. */
function html_tarjeta_idioma_liga(array $liga): string
{
    $act = (string)($liga['idioma'] ?? 'es');
    $h = '<div class="tarjeta" id="idioma"><h2 style="margin-top:0">🌐 Idioma de la página pública</h2>'
        . '<p class="suave">El idioma con el que se abre su página para el público. Cada visitante (y cada usuario del panel) puede cambiarlo con el botón 🌐 de arriba.</p>'
        . '<form method="post" class="acciones">' . csrf_campo() . '<input type="hidden" name="accion" value="idioma_liga"><select name="idioma">';
    foreach (idiomas() as $k => [$n, $f]) $h .= '<option value="' . $k . '"' . ($k === $act ? ' selected' : '') . '>' . $f . ' ' . e($n) . '</option>';
    return $h . '</select><button class="btn btn-sec btn-chico">Guardar</button></form></div>';
}
