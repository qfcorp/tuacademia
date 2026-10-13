<?php
/*
 * Página pública.
 *   /                         → página de venta (o la liga, si se entra por su dominio propio)
 *   /nombre-de-la-liga        → página de la liga (dirección corta)
 *   /index.php?l=nombre-liga  → página de la liga
 *   /index.php?v=ligas        → todas las ligas     /index.php?v=registro → prueba gratis
 *   /index.php?v=privacidad   → política de privacidad
 *   (v10) /nombre-liga/campeonato[/tabla|/fixture|/en-vivo|/boletin|/acumulada] → página del campeonato
 *   (v10) /demo               → liga de demostración
 */
require __DIR__ . '/../app/bootstrap.php';
define('VISTA_PUBLICA', true);

// (v9) Si llega con el enlace de un vendedor (?ref=CODIGO), se recuerda 60 días para su comisión
recordar_vendedor();

// (v7) Clic en un patrocinador: se cuenta y se abre su página
if (get('clic') !== '') {
    $s_clic = fila('SELECT * FROM patrocinadores WHERE id = ? AND activo = 1', [(int)get('clic')]);
    if ($s_clic && preg_match('#^https?://#i', $s_clic['enlace'])) {
        if (!es_robot()) contar_patrocinio((int)$s_clic['id'], preg_match('/^[a-z]{3,12}$/', get('lugar')) ? get('lugar') : 'pagina', 'clics');
        header('Location: ' . $s_clic['enlace'], true, 302);
        exit;
    }
    ir('index.php');
}

$ruta_url = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$ruta = trim($ruta_url, '/');
$patron_ruta = '#^[a-z0-9-]+(/[a-z0-9-]+){0,2}$#';
// /liga/ con barra final → sin barra (para que carguen bien estilos e imágenes)
if ($ruta !== '' && str_ends_with($ruta_url, '/') && preg_match($patron_ruta, $ruta)) {
    header('Location: /' . $ruta . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
    exit;
}
$liga_dom = liga_por_dominio();
$slug = get('l');
$v_global = get('v') === 'solicitar-demo' ? 'solicitar' : get('v'); // (v33) alias
$ruta_torneo = null; // (v10) [dirección del campeonato, parte: tabla / fixture / en-vivo ...]
if ($slug === '' && $ruta !== '' && $ruta !== 'index.php' && preg_match($patron_ruta, $ruta)) {
    $partes = explode('/', $ruta);
    if (count($partes) > 1) define('BASE_RAIZ', true); // estilos y enlaces desde la raíz del sitio
    if (count($partes) === 1 && in_array($ruta, ['ligas', 'registro', 'privacidad', 'derechos', 'precios', 'planes', 'demo', 'comparar', 'escuelas', 'solicitar-demo', 'nuestras-escuelas', 'manual'], true)) {
        $v_global = $ruta === 'precios' || $ruta === 'planes' ? 'inicio' : ($ruta === 'solicitar-demo' ? 'solicitar' : $ruta);
        if ($ruta === 'nuestras-escuelas') { $v_global = 'ligas'; $_GET['tipo'] = 'escuela'; }
    } elseif (count($partes) === 2 && $partes[0] === 'manual') {
        // (v27) Manuales: /manual/ligas y /manual/escuelas
        $v_global = 'manual';
        $_GET['m'] = $partes[1];
    } elseif (count($partes) === 2 && $partes[0] === 'planes') {
        // (v12) Folleto de cada plan: /planes/basico
        $v_global = 'plan';
        $_GET['plan'] = $partes[1];
    } elseif ($liga_dom && count($partes) <= 2 && fila('SELECT id FROM torneos WHERE liga_id = ? AND slug = ?', [$liga_dom['id'], $partes[0]])) {
        // Dominio propio de la liga: www.suliga.com/apertura-2026/tabla
        $slug = $liga_dom['slug'];
        $ruta_torneo = [$partes[0], $partes[1] ?? ''];
    } else {
        $slug = $partes[0];
        if (count($partes) > 1) $ruta_torneo = [$partes[1], $partes[2] ?? ''];
    }
}
if ($slug === '' && $liga_dom && !in_array($v_global, ['privacidad', 'derechos', 'demo', 'plan', 'comparar', 'solicitar', 'manual'], true)) {
    $slug = $liga_dom['slug'];
}

if ($slug === '') {
    if ($v_global === 'privacidad') {
        require APP_DIR . '/publico/privacidad.php';
    } elseif ($v_global === 'derechos') {
        // (v24) Protección de datos: ejercer mis derechos
        require APP_DIR . '/publico/derechos.php';
    } elseif ($v_global === 'demo') {
        require APP_DIR . '/publico/demo.php';
    } elseif ($v_global === 'registro') {
        require APP_DIR . '/publico/registro.php';
    } elseif ($v_global === 'solicitar') {
        // (v14) Solicitar una demo (reemplaza a la prueba gratis automática)
        require APP_DIR . '/publico/solicitar.php';
    } elseif ($v_global === 'ligas') {
        require APP_DIR . '/publico/portada.php';
    } elseif ($v_global === 'plan') {
        require APP_DIR . '/publico/plan.php';
    } elseif ($v_global === 'comparar') {
        require APP_DIR . '/publico/comparar.php';
    } elseif ($v_global === 'manual') {
        require APP_DIR . '/publico/manual.php'; // (v27) manuales en la web y en PDF
    } else {
        require APP_DIR . '/publico/landing.php';
    }
    exit;
}
$liga = fila('SELECT * FROM ligas WHERE slug = ? AND activa = 1', [$slug]);
if (!$liga) {
    http_response_code(404);
    cabecera_publica('Liga no encontrada');
    echo '<div class="tarjeta"><h1>Liga no encontrada</h1><p><a href="index.php?v=ligas">Ver todas las ligas</a></p></div>';
    pie_publico();
    exit;
}
$lid = (int)$liga['id'];
aplicar_idioma_liga($liga); // (v28) idioma por defecto de la página de la liga
$base = 'index.php?l=' . rawurlencode($liga['slug']);

// (v10) Dirección propia del campeonato: /liga/apertura-2026[/tabla|/fixture|/en-vivo|/boletin|/acumulada]
if ($ruta_torneo !== null) {
    completar_slugs_torneos($lid);
    $t_ruta = fila('SELECT * FROM torneos WHERE liga_id = ? AND slug = ?', [$lid, $ruta_torneo[0]]);
    $mapa = subrutas_torneo();
    if (!$t_ruta) {
        // Quizás es solo la pestaña de la liga: /liga/tabla
        if (isset($mapa[$ruta_torneo[0]]) && $ruta_torneo[1] === '') {
            $_GET['v'] = $_GET['v'] ?? $mapa[$ruta_torneo[0]];
        } else {
            header('Location: ' . url_liga($liga), true, 302);
            exit;
        }
    } else {
        if ($ruta_torneo[1] !== '' && !isset($mapa[$ruta_torneo[1]])) {
            header('Location: ' . url_torneo($liga, $t_ruta), true, 302);
            exit;
        }
        $_GET['t'] = (string)$t_ruta['id'];
        if (!isset($_GET['v'])) $_GET['v'] = $ruta_torneo[1] !== '' ? $mapa[$ruta_torneo[1]] : 'inicio';
    }
}

// (v26) Deportes individuales: aviso "en vivo" (ping) e inscripción en línea del competidor
if (get('t') !== '' && (get('ping') !== '' || (get('v') === 'inscribirse' && es_post()))) {
    $t_ind = fila('SELECT * FROM torneos WHERE id = ? AND liga_id = ?', [(int)get('t'), $lid]);
    if ($t_ind && es_individual($t_ind)) {
        if (get('ping') !== '') {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            echo json_encode(['f' => firma_individual($t_ind)]);
            exit;
        }
        try {
            procesar_inscripcion_indiv($liga, $t_ind, $base);
        } catch (RuntimeException $ex) {
            flash($ex->getMessage(), 'error');
            $_SESSION['insc_indiv_form'][(int)$t_ind['id']] = array_diff_key($_POST, ['csrf' => 1, 'acepto' => 1]);
            ir($base . '&t=' . (int)$t_ind['id'] . '&v=inscribirse');
        }
    }
}

if (get('v') === 'privacidad') {
    require APP_DIR . '/publico/privacidad.php';
} elseif (get('v') === 'derechos') {
    require APP_DIR . '/publico/derechos.php';
} elseif (get('v') === 'entradas' && entradas_activas($liga)) {
    require APP_DIR . '/publico/entradas.php'; // (v28) comprar entradas y ver mis entradas con QR
} elseif (get('o') !== '') {
    require APP_DIR . '/publico/olimpiada.php'; // (v37) olimpiadas: página del evento y portal de cada delegación
} elseif (es_federacion($liga) && (get('v') === 'federacion' || (get('v') === '' && get('t') === '' && get('e') === '' && get('j') === '' && get('p') === ''))) {
    require APP_DIR . '/publico/federacion.php'; // (v28) portada de la federación: sus ligas, sus campeonatos y los partidos de la semana
} elseif (es_escuela($liga)) {
    // (v11) Página pública de una escuela: grupos, horarios, sedes e inscripción en línea
    require APP_DIR . '/publico/escuela.php';
} elseif (get('v') === 'boletin') {
    require APP_DIR . '/publico/boletin.php';
} elseif (get('v') === 'fixture_pdf' || get('v') === 'esquema') {
    require APP_DIR . '/publico/fixture_doc.php'; // (v29) fixture completo y esquema para imprimir (plan Profesional)
} elseif (get('e') !== '') {
    require APP_DIR . '/publico/equipo.php';
} elseif (get('j') !== '') {
    require APP_DIR . '/publico/jugador.php';
} elseif (get('p') !== '') {
    require APP_DIR . '/publico/partido.php';
} else {
    require APP_DIR . '/publico/liga.php';
}
