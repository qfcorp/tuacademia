<?php
/*
 * APP DE iPHONE (App Store) — SIN PRECIOS NI PAGOS DEL SOFTWARE (13 nov 2026)
 * (Mismo archivo en TuVocalía y TuAcademia.)
 *
 * Regla de Apple 3.1.1: dentro de la app de iPhone NO se puede ver ningún precio de lo que vende Tukán (planes, módulos,
 * suscripciones), ni botones, enlaces o formularios para pagar, contratar, cotizar, mejorar o cancelar, ni textos que digan
 * dónde pagar («paga en la web», WhatsApp para contratar…). Lo que la liga, escuela o academia cobra a SUS jugadores, alumnos
 * o familias (mensualidades, inscripciones, entradas con QR…) es del negocio del cliente y SÍ se sigue viendo.
 *
 * Cómo se reconoce la app de iPhone:
 *  - El User-Agent trae «TukanIOS» (ej. «Mozilla/5.0 (iPhone…) … TukanIOS/1.0.0»), o
 *  - la cookie canal=ios, que deja la dirección con la que abre la app: TuVocalía /app.php?app=ios, TuAcademia /app.php?origen=ios.
 *    ?app=web u ?origen=web la quita.
 * En el iPhone, <html> lleva class="tukan-ios" (se agrega sola a todas las páginas HTML: ver ios_marcar_html()).
 */

const TUKAN_IOS_COOKIE = 'canal';

/** La llama el arranque: ?app=ios / ?origen=ios deja la cookie canal=ios; ?app=web / ?origen=web la quita. */
function fijar_canal_ios(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    $q = (string)($_GET['app'] ?? ($_GET['origen'] ?? ''));
    if ($q !== 'ios' && !($q === 'web' && isset($_COOKIE[TUKAN_IOS_COOKIE]))) return;
    $https = !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $ios = $q === 'ios';
    setcookie(TUKAN_IOS_COOKIE, $ios ? 'ios' : '', ['expires' => $ios ? time() + 400 * 86400 : time() - 3600, 'path' => '/', 'samesite' => 'Lax', 'secure' => $https, 'httponly' => false]);
    if ($ios) $_COOKIE[TUKAN_IOS_COOKIE] = 'ios';
    else unset($_COOKIE[TUKAN_IOS_COOKIE]);
}

/** ¿La página se está viendo dentro de la app de iPhone? (User-Agent con «TukanIOS» o cookie canal=ios) */
function en_app_ios(): bool
{
    if (!empty($GLOBALS['__forzar_app_ios'])) return true; // pruebas
    if (PHP_SAPI === 'cli') return false;
    if (str_contains((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'TukanIOS')) return true;
    return (string)($_COOKIE[TUKAN_IOS_COOKIE] ?? '') === 'ios';
}

/** Para los POST que cobran, contratan, cotizan, cambian o cancelan el plan del software: en el iPhone no se hacen (mensaje neutro). */
function exigir_fuera_de_ios(): void
{
    if (en_app_ios()) throw new RuntimeException('Esta acción no está disponible en la app.');
}

/** El texto de siempre, o el neutro en el iPhone (sin «cambie de plan», «agréguelo», «pídalo»…). */
function texto_app_ios(string $web, string $ios): string
{
    return en_app_ios() ? $ios : $web;
}

/** Estado del plan en palabras neutras para el iPhone (sin precios, sin «renueve», sin decir dónde pagar). */
function texto_estado_ios(array $liga, array $sus): string
{
    $plan = function_exists('plan_de_liga') ? plan_de_liga($liga) : null;
    $nombre = $plan ? (string)$plan['nombre'] : '';
    $vence = (string)($liga['vence'] ?? '');
    $prueba = !empty($liga['es_prueba']);
    switch ($sus['estado'] ?? '') {
        case 'sin_limite':
            return $nombre !== '' ? 'Tu plan: ' . $nombre . '. Activo.' : 'Tu cuenta está activa.';
        case 'prueba':
            return 'Tu periodo de prueba: activo hasta el ' . $vence . '.';
        case 'activa':
            return 'Tu plan: ' . ($nombre !== '' ? $nombre : 'activo') . '. Activo hasta el ' . $vence . '.';
        default: // gracia o vencida
            if ($prueba) return 'Tu periodo de prueba terminó.';
            return 'Tu plan' . ($nombre !== '' ? ' ' . $nombre : '') . ' venció el ' . $vence . '.';
    }
}

/**
 * Mi plan en el iPhone: solo información neutra (plan, vigencia, límites y módulos encendidos). Sin precios, sin planes para
 * contratar, sin cotizar, sin pagos ni historial de pagos, sin enlaces ni textos de dónde pagar.
 */
function html_mi_plan_ios(array $liga): string
{
    $tipo = ($liga['tipo'] ?? 'liga') === 'escuela' ? 'escuela' : 'liga';
    $sus = estado_suscripcion($liga);
    $plan = function_exists('plan_de_liga') ? plan_de_liga($liga) : null;
    $lim = function_exists('limites_liga') ? limites_liga($liga) : [];
    $cat = function_exists('catalogo_modulos') && function_exists('tipo_modulos') ? catalogo_modulos(tipo_modulos($liga)) : [];
    $act = function_exists('modulos_liga') ? modulos_liga($liga) : [];
    $titulo = $plan ? (string)$plan['nombre'] : (!empty($liga['es_prueba']) ? 'Periodo de prueba' : 'Tu plan');
    $h = '<h1>⭐ Mi plan</h1><div class="rejilla-2" id="mi-plan-ios"><div class="tarjeta">'
        . '<h2 style="margin-top:0">' . e($titulo) . '</h2>'
        . '<p id="estado-plan-ios">' . e(texto_estado_ios($liga, $sus)) . '</p><ul>';
    if (function_exists('texto_limite_campeonatos') && $tipo === 'liga') $h .= '<li>' . e(texto_limite_campeonatos($liga)) . '</li>';
    if ($tipo === 'liga' && isset($lim['usados_equipos'])) {
        $h .= '<li>Equipos: ' . (int)$lim['usados_equipos'] . (!empty($lim['equipos']) ? ' de ' . (int)$lim['equipos'] : ' (sin límite)') . '</li>';
    } elseif ($tipo === 'escuela') {
        $n = (int)valor("SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ? AND estado IN ('activo','pendiente')", [$liga['id']]);
        $h .= '<li>Alumnos: ' . $n . ($plan && (int)($plan['max_alumnos'] ?? 0) ? ' de ' . (int)$plan['max_alumnos'] : ' (sin límite)') . '</li>';
    }
    if (function_exists('deportes_liga') && function_exists('texto_modulos')) {
        $d = texto_modulos(deportes_liga($liga));
        if ($d !== '') $h .= '<li>' . (function_exists('es_academia') && es_academia() ? 'Disciplinas' : 'Deportes') . ': ' . e($d) . '</li>';
    }
    $h .= '</ul></div>';
    if ($cat) {
        $h .= '<div class="tarjeta"><h2 style="margin-top:0">🧩 Módulos encendidos</h2>'
            . '<p class="suave" style="margin-top:0">Tiene <b>' . count(array_intersect(array_keys($cat), $act)) . '</b> de ' . count($cat) . ' módulos.</p><ul class="lista-modulos-app">';
        foreach ($cat as $k => $x) if (in_array($k, $act, true)) $h .= '<li>' . ($x['icono'] ?? '') . ' ' . e((string)$x['nombre']) . '</li>';
        $h .= '</ul></div>';
    }
    return $h . '</div>';
}

/**
 * Manual dentro del iPhone: quita las secciones del plan del software (Mi plan, renovar, contratar, cancelar, precios, cómo se consigue un
 * módulo) y los párrafos o viñetas que hablan de cotizar, pedir módulos o pagar el plan. Lo demás del manual queda igual.
 */
function ios_limpiar_manual(string $html): string
{
    if ($html === '' || !en_app_ios()) return $html;
    $titulo = '/Mi plan\b|cancelar (su|mi) plan|Precios|Renovar|Contratar|Cómo se consigue|cotizaci|My plan|Mon forfait|Il mio piano|Meu plano|cancel (your|my) plan|Prices|Prix|Prezzi|Preços|Renew|Renouvel|Rinnov|request a quote|devis|preventiv|orçamento/iu';
    $sin = function (string $h, string $etq) use ($titulo): string {
        $partes = preg_split('/(?=<' . $etq . '\b)/i', $h) ?: [$h];
        $out = '';
        foreach ($partes as $i => $pz) {
            if ($i > 0 && preg_match('#^<' . $etq . '\b[^>]*>(.*?)</' . $etq . '>#is', $pz, $m) && preg_match($titulo, strip_tags($m[1]))) continue;
            $out .= $pz;
        }
        return $out;
    };
    // capítulos <section …><h1>…</h1>…</section> (manual de TuAcademia)
    $html = preg_replace_callback('#<section\b[^>]*>\s*<h1\b[^>]*>(.*?)</h1>.*?</section>#su', fn($m) => preg_match($titulo, strip_tags($m[1])) ? '' : $m[0], $html) ?? $html;
    $html = $sin($html, 'h2');
    $html = implode('', array_map(fn($pz) => $sin($pz, 'h3'), preg_split('/(?=<h2\b)/i', $html) ?: [$html]));
    $malo = '/Mi plan\b|My plan|Mon forfait|Il mio piano|Meu plano|Solicitar demo|Request a demo|cotizaci|request a quote|devis|preventiv|orçamento|Solicitar este módulo|Pedir cotización|Precios:|más IVA|Google Play|Contratar mi plan|Renovar ahora|Ver planes y pagar|Pagar con tarjeta/iu';
    return preg_replace_callback('#<(li|p|tr)\b[^>]*>.*?</\1>#su', fn($m) => preg_match($malo, $m[0]) ? '' : $m[0], $html) ?? $html;
}

/**
 * En el iPhone, pone class="tukan-ios" en la etiqueta <html> de todas las páginas HTML (con un búfer de salida que solo mira
 * el primer pedazo: las descargas grandes, los JSON y las imágenes pasan sin tocar).
 */
function ios_marcar_html(): void
{
    if (PHP_SAPI === 'cli' || !en_app_ios() || !empty($GLOBALS['__ios_marcando'])) return;
    $GLOBALS['__ios_marcando'] = true;
    $hecho = false;
    ob_start(function (string $buf) use (&$hecho): string {
        if ($hecho || $buf === '') return $buf;
        foreach (headers_list() as $hd) {
            if (stripos($hd, 'content-type:') === 0 && stripos($hd, 'text/html') === false) { $hecho = true; return $buf; }
        }
        $p = stripos($buf, '<html');
        if ($p === false) { if (strlen($buf) > 2048) $hecho = true; return $buf; }
        $hecho = true;
        $fin = strpos($buf, '>', $p);
        if ($fin === false) return $buf;
        $tag = substr($buf, $p, $fin - $p);
        if (preg_match('/\sclass\s*=\s*"([^"]*)"/i', $tag, $m)) {
            if (preg_match('/(^|\s)tukan-ios(\s|$)/', $m[1])) return $buf;
            $nuevo = str_replace($m[0], ' class="' . trim($m[1] . ' tukan-ios') . '"', $tag);
        } else {
            $nuevo = $tag . ' class="tukan-ios"';
        }
        return substr($buf, 0, $p) . $nuevo . substr($buf, $fin);
    }, 8192);
}

fijar_canal_ios();
ios_marcar_html();
