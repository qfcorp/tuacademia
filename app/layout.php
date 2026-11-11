<?php
/*
 * Plantillas visuales (encabezado y pie) del panel y de la página pública.
 */

/** (10 nov 2026) Identidad gráfica común de Tukán: la ÚNICA línea que cambia por programa es su color (kit tukan-ui.css). */
function tk_color_programa(): string
{
    return '<style>:root{ --tk-color:#7b3fb0; --tk-color-claro:#c9a2ef; --tk-color-texto:#ffffff; }</style>';
}

/** Iniciales del programa para el círculo de la barra (TuAcademia → TA) cuando el negocio no subió su logo. */
function tk_iniciales_programa(): string
{
    preg_match_all('/\p{Lu}/u', marca(), $m);
    $ini = implode('', array_slice($m[0], 0, 2));
    return $ini !== '' ? $ini : mb_strtoupper(mb_substr(marca(), 0, 2));
}

/** Qué es el negocio (lo que decía la franja de antes): «Fútbol 7», «Escuela de fútbol», «Academia de ballet»… */
function tk_tipo_negocio(array $liga): string
{
    return es_escuela($liga) ? (count($deps_l = deportes_de_escuela($liga)) > 1 ? (es_academia() ? 'Academia' : 'Escuela multideporte') . ' (' . mb_strtolower(texto_deportes($deps_l)) . ')' : (es_academia() ? 'Academia' : 'Escuela') . ' de ' . mb_strtolower(deportes_escuela()[$liga['deporte_escuela']] ?? 'deporte')) : (es_federacion($liga) ? 'Federación' : nombre_modalidad($liga['modalidad']));
}

/** Línea chica de la barra: «Programa · tipo de negocio · Plan». */
function tk_linea_plan(?array $liga): string
{
    if (!$liga) return es_super() ? marca() . ' · Superadministrador' : (usuario() ? marca() : 'Ingreso al panel');
    $base = marca() . ' · ' . tk_tipo_negocio($liga);
    if (!empty($liga['es_demo'])) return $base . ' · Demostración';
    if (cuenta_en_demo($liga)) return $base . ' · Demo';
    $plan = plan_de_liga($liga);
    if (!$plan) return $base;
    $n = trim((string)$plan['nombre']);
    return $base . ' · ' . (preg_match('/^plan\b/i', $n) ? $n : 'Plan ' . $n);
}

function cabecera_admin(string $titulo): void
{
    $u = usuario();
    $liga = liga_trabajo();
    $p = get('p', 'inicio');
    acad_vocabulario_activar($liga); // (TuAcademia) palabras de la academia (disciplina, clase, salón)
    $nombre_neg = $liga ? (string)$liga['nombre'] : marca();
    ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · Panel <?= e(marca()) ?></title>
<link rel="stylesheet" href="assets/tukan-ui.css?v=1">
<link rel="stylesheet" href="assets/estilo.css?v=332">
<?= tk_color_programa() ?>
<meta name="robots" content="noindex">
<?= cabecera_app() ?>
</head>
<body class="tk admin<?= $u ? '' : ' tk-sin-menu' ?>">
<header class="tk-arriba">
  <div class="tk-arriba-izq">
    <?php if ($u): ?><button class="tk-boton-menu" type="button" aria-label="Abrir el menú" aria-expanded="false">☰</button><?php endif; ?>
    <a class="tk-marca" href="admin.php"><?= $liga && ($liga['logo'] ?? '') !== '' ? '<img src="' . e($liga['logo']) . '" alt="">' : '<span class="tk-logo">' . e(tk_iniciales_programa()) . '</span>' ?><span><b><?= e($nombre_neg) ?></b><small><?= e(tk_linea_plan($liga)) ?></small></span></a>
  </div>
  <div class="tk-usuario">
    <?= tc_enlace_volver() // (Tukán Central) «← Panel Tukán» si la sesión vino del panel central ?>
    <?= html_selector_idioma('idioma-panel') // (v28) ?>
    <?php if ($u): ?>
    <span class="tk-nombre">👤 <?= e((string)$u['nombre']) ?></span><span class="tk-rol"><?= e(es_super() ? 'Súper' : nombre_perfil(perfil_actual())) ?></span><a class="tk-salir" href="admin.php?p=salir">Salir</a>
    <?php endif; ?>
  </div>
</header>
<div class="tk-cuerpo">
<?php if ($u): ?>
  <nav class="tk-menu" aria-label="Menú principal"><?= html_menu_panel() // menú agrupado: app/menu.php ?></nav>
  <div class="tk-fondo-menu"></div>
<?php endif; ?>
<main class="tk-contenido contenedor">
<?php if ($u && !es_super() && !empty($liga['es_demo'])): ?>
<div class="demo-cinta">👀 Está en la <b>demostración</b> de <?= e(marca()) ?> <?= demo_guardar() ? '(puede probar y guardar; todo se borra cada madrugada)' : '(solo mirar, no se guardan cambios)' ?>. <a href="index.php?v=demo">← Entrar a otro panel</a> · <a href="<?= e(url_empezar()) ?>">Solicite su demo →</a></div>
<?php endif; ?>
<?php if ($liga && $u && get('p') !== 'login' && empty($liga['es_demo']) && liga_suspendida($liga)): // (Tukán Central v2) cuenta suspendida por Tukán ?>
  <div class="aviso aviso-error aviso-suspendida" id="cuenta-suspendida">⛔ <b><?= e(texto_suspension($liga)) ?></b><?= es_super() && ($liga['suspendida_motivo'] ?? '') !== '' ? ' Motivo: ' . e($liga['suspendida_motivo']) : '' ?></div>
<?php endif; ?>
<?php if ($liga && $u && get('p') !== 'login' && toca_aviso_vencimiento($liga)): ?>
  <?= html_aviso_vencimiento($liga) ?>
<?php elseif ($liga && $u && get('p') !== 'login' && empty($liga['es_demo'])):
    $sus = estado_suscripcion($liga);
    // (10 nov 2026) En el Inicio, la demo se avisa en la tira de arriba del dashboard (con «Ver Mi plan»): aquí no se repite
    if (in_array($sus['estado'], ['prueba', 'gracia', 'vencida'], true) && ($sus['estado'] !== 'prueba' || ($sus['dias'] <= 10 && $p !== 'inicio'))):
        $wa = telefono_wa((string)ajuste('ventas_whatsapp')); ?>
  <div class="aviso <?= $sus['estado'] === 'prueba' ? 'aviso-info' : 'aviso-error' ?> aviso-suscripcion">
    <?= $sus['estado'] === 'prueba' ? '🎁' : '⚠️' ?> <?= e($sus['texto']) ?>
    <?php if (puede('config')): ?> · <a href="admin.php?p=mi_plan#contratar"><b><?= $sus['estado'] === 'prueba' || !empty($liga['es_prueba']) ? '💵 Contratar mi plan' : '💵 Renovar ahora' ?></b></a><?php endif; // (v33) ?>
    <?php if ($wa): ?> · <a href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola, quiero ' . ($sus['estado'] === 'prueba' ? 'contratar' : 'renovar') . ' ' . marca() . ' para la liga ' . $liga['nombre']) ?>" target="_blank">WhatsApp</a><?php endif; ?>
  </div>
<?php endif; endif; ?>
<?php foreach (tomar_flash() as [$tipo, $msg]): ?>
  <div class="aviso aviso-<?= e($tipo) ?>"><?= e($msg) ?></div>
<?php endforeach; ?>
<?php // (7 nov 2026) El administrador acepta los términos y la privacidad de Tukán (app/TukanLegal.php); se acepta en Mi plan
if ($liga && $u && !es_super() && empty($liga['es_demo']) && get('p') !== 'login' && puede('config', true)) {
    require_once __DIR__ . '/TukanLegal.php';
    if (!TukanLegal::aceptado(db(), (string)$liga['id'])) echo TukanLegal::aviso(csrf_campo(), 'admin.php?p=mi_plan', 'index.php?v=privacidad', true);
} ?>
<?php // (v37) campeonato que es disciplina de un evento de olimpiadas: enlace de vuelta al evento
if ($liga && $u && in_array($p, ['torneo', 'carrera', 'duelo', 'bolos', 'pruebas', 'partido'], true) && function_exists('olimp_evento_de_torneo')):
    $tid_o = $p === 'partido' ? (int)valor('SELECT torneo_id FROM partidos WHERE id = ?', [(int)get('id')]) : (int)get('id');
    if ($tid_o && ($ev_o = olimp_evento_de_torneo($tid_o)) && (int)$ev_o['liga_id'] === (int)$liga['id']): ?>
  <p class="no-imprimir" style="margin:0 0 6px"><a href="admin.php?p=olimpiadas&amp;id=<?= (int)$ev_o['id'] ?>&amp;s=disciplinas">🏅 ← <?= e($ev_o['nombre']) ?></a></p>
<?php endif; endif; ?>
<?php
}

/** Etiquetas para que el sistema se pueda instalar como aplicación en el celular (PWA). */
function cabecera_app(): string
{
    return '<link rel="manifest" href="manifest.php">
<meta name="theme-color" content="#0f1614">
<link rel="icon" href="assets/icono-192.png">
<link rel="apple-touch-icon" href="assets/icono-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<script>if (\'serviceWorker\' in navigator) { window.addEventListener(\'load\', function () { navigator.serviceWorker.register(\'sw.js\').catch(function () {}); }); }</script>' . script_origen_app();
}

/**
 * (v38) Las apps de Android comparten las cookies con Chrome: si alguien abrió la app de Google Play y después entra a la web
 * en Chrome, la cookie tv_app=play quedaría puesta. Este guardián la vuelve a "web" cuando la página NO está dentro de una app.
 */
function script_origen_app(): string
{
    return "<script>(function(){try{if(/^android-app:\\/\\//.test(document.referrer))sessionStorage.setItem('tv_twa','1');"
        . "var c=document.cookie.match(/(?:^|; )tv_app=(play|apk)/),app=sessionStorage.getItem('tv_twa')==='1'||(window.matchMedia&&(matchMedia('(display-mode: standalone)').matches||matchMedia('(display-mode: fullscreen)').matches));"
        . "if(c&&!app){document.cookie='tv_app=web; path=/; max-age=34560000; samesite=lax'+(location.protocol==='https:'?'; secure':'');"
        . "if(/[?&]origen=(play|apk)/.test(location.search))location.replace(location.href.replace(/([?&])origen=(play|apk)/,'$1origen=web'));else location.reload();}}catch(e){}})();</script>";
}

function pie_admin(): void
{
    ?>
<footer class="pie"><?= e(marca()) ?> · <?= e(ajuste('empresa', 'Tukán Software')) ?> · <a href="manual" target="_blank">Manuales</a> · <a href="https://tukansoftware.com/terminos.html" target="_blank" rel="noopener">Términos</a> · <a href="index.php?v=privacidad" target="_blank">Privacidad</a></footer>
</main>
</div>
<?= usuario() ? html_barra_abajo() . html_boton_ayuda() : '' ?>
<script>/* (10 nov 2026) Los formularios con confirmación, los que abren otra pestaña y los de búsqueda (GET, también las descargas) no quedan en «Procesando…» */
document.querySelectorAll('form[onsubmit], form[target], form:not([method]), form[method="get"], form[method="GET"]').forEach(function (f) { f.setAttribute('data-sin-ocupado', ''); });</script>
<script src="assets/tukan-ui.js?v=1"></script>
</body>
</html>
<?php
}

function cabecera_publica(string $titulo, ?array $liga = null): void
{
    $GLOBALS['liga_publica'] = $liga;
    acad_vocabulario_activar($liga); // (TuAcademia)
    ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?></title>
<?= etiqueta_base() ?>
<?php if (!$liga): // (10 nov 2026) sitio del programa (solicitar demo, registro): kit común con el color del programa; la página de la liga no lo usa ?><link rel="stylesheet" href="assets/tukan-ui.css?v=1"><?php endif; ?>
<link rel="stylesheet" href="assets/estilo.css?v=332">
<?= !$liga ? tk_color_programa() : '' ?>
<meta property="og:title" content="<?= e($titulo) ?>">
<?= html_metas_compartir($titulo, $liga) // (v33) descripción, imagen y dirección para buscadores y WhatsApp ?>
<?= cabecera_app() ?>
</head>
<body class="publico" style="--acento: <?= e($liga['color'] ?? '#0b6e4f') ?>; --acento2: <?= e(($liga['color2'] ?? '') !== '' ? $liga['color2'] : ($liga['color'] ?? '#0b6e4f')) ?>">
<header class="portada">
  <div class="barra-in">
    <?php if ($liga): ?>
      <a class="portada-marca" href="index.php?l=<?= e($liga['slug']) ?>">
        <?= escudo($liga['logo'], $liga['nombre'], $liga['color'], 'logo-liga') ?>
        <span><strong><?= e($liga['nombre']) ?></strong><small><?= e(es_federacion($liga) ? '🏛 Federación' : (es_escuela($liga) ? (count($dpl = deportes_de_escuela($liga)) > 1 ? (PRODUCTO === 'academia' ? 'Academia: ' : 'Escuela deportiva: ') . texto_deportes($dpl) : (PRODUCTO === 'academia' ? 'Academia de ' : 'Escuela de ') . mb_strtolower(nombre_deporte_corto($dpl[0]))) : nombre_modalidad($liga['modalidad']))) ?><?= $liga['ciudad'] !== '' ? ' · ' . e($liga['ciudad']) : '' ?><?= ($fdl = federacion_de($liga)) ? ' · 🏛 ' . e($fdl['nombre']) : '' // (v28) ?></small></span>
      </a>
      <?= html_selector_idioma('idioma-pub') // (v28) ?>
    <?php else: ?>
      <a class="portada-marca" href="index.php"><img class="logo-liga" src="assets/icono-192.png" alt=""><span><strong><?= e(marca()) ?></strong><small><?= PRODUCTO === 'academia' ? 'Academias de baile, música y deportes' : 'Ligas y escuelas deportivas' ?></small></span></a>
      <?= html_menu_clientes() ?>
      <?= html_selector_idioma('idioma-pub idioma-der') // (v28) ?>
      <a class="btn btn-sec-blanco btn-chico" href="admin.php?p=login">Ingresar</a>
    <?php endif; ?>
  </div>
</header>
<main class="contenedor">
<?php
    if ($liga && !defined('SIN_BANNER')) echo banner_patrocinador($liga, 'pagina');
}

function pie_publico(): void
{
    ?>
</main>
<?php $lp = $GLOBALS['liga_publica'] ?? null; $propio = liga_por_dominio(); ?>
<footer class="pie"><?= es_academia() ? e(marca()) : 'Estadísticas en vivo' ?><?= $propio ? '' : (es_academia() ? ' · <a href="nuestras-escuelas">Academias</a> · <a href="index.php?v=manual">Manuales</a>' : ' · <a href="index.php?v=ligas">Ligas</a> · <a href="index.php?v=ligas&amp;tipo=escuela">Escuelas</a> · <a href="index.php?v=manual">Manuales</a>') ?> · <a href="<?= $lp ? 'index.php?l=' . e($lp['slug']) . '&amp;v=privacidad' : 'index.php?v=privacidad' ?>">Privacidad</a> · <a href="<?= $lp ? 'index.php?l=' . e($lp['slug']) . '&amp;v=derechos' : 'index.php?v=derechos' ?>">Mis datos</a> · <a href="admin.php?p=login">Ingresar</a> · Powered by <?= $propio ? e(ajuste('empresa')) : '<a href="index.php">' . e(marca()) . '</a>' ?></footer>
</body>
</html>
<?php
}

/** Fila de partido reutilizable (público y admin). */
function tarjeta_partido(array $p, string $enlace = ''): string
{
    $cerrado = partido_cerrado($p);
    $vivo = null;
    if ($p['estado'] === 'en_vivo') {
        $vivo = minuto_vivo($p);
    }
    if ($cerrado || $p['estado'] === 'en_vivo') {
        $centro = '<span class="marcador' . ($p['estado'] === 'en_vivo' ? ' marcador-vivo' : '') . '">' . (int)$p['goles_local'] . ' - ' . (int)$p['goles_visitante'] . '</span>';
        if ($p['estado'] === 'en_vivo') {
            $centro .= '<small class="en-vivo">● EN VIVO' . ($vivo ? ' · ' . e($vivo['txt']) : '') . '</small>';
        }
    } else {
        $centro = '<span class="hora">' . ($p['hora'] !== '' ? e($p['hora']) : 'vs') . '</span>';
    }
    if ($p['penales_local'] !== null && $p['penales_visitante'] !== null) {
        $centro .= '<small class="pen">(pen ' . (int)$p['penales_local'] . '-' . (int)$p['penales_visitante'] . ')</small>';
    }
    $estado = '';
    if ($p['estado'] === 'wo') $estado = '<span class="etiqueta roja">W.O.</span>';
    elseif (in_array($p['estado'], ['suspendido', 'postergado'], true)) $estado = '<span class="etiqueta gris">' . e(nombre_estado_partido($p['estado'])) . '</span>';
    $info = [];
    $info[] = fecha_bonita($p['fecha']);
    if ($p['cancha'] !== '') $info[] = e($p['cancha']);
    if ($p['grupo'] !== '') $info[] = 'Grupo ' . e($p['grupo']);
    $html = '<div class="partido">'
        . '<div class="eq eq-l"><span class="nom">' . e($p['local_nombre']) . '</span>' . escudo($p['local_escudo'], $p['local_nombre'], $p['local_color']) . '</div>'
        . '<div class="centro">' . $centro . '</div>'
        . '<div class="eq eq-v">' . escudo($p['visitante_escudo'], $p['visitante_nombre'], $p['visitante_color']) . '<span class="nom">' . e($p['visitante_nombre']) . '</span></div>'
        . '<div class="info">' . implode(' · ', $info) . ' ' . $estado . '</div>'
        . '</div>';
    if ($enlace !== '') {
        $html = '<a class="partido-link" href="' . e($enlace) . '">' . $html . '</a>';
    }
    return $html;
}

/** Script que recarga la zona #zona-vivo cada 15 s mientras haya partidos en vivo. */
function script_refresco(): string
{
    return <<<'JS'
<script>
(function () {
  var z = document.getElementById('zona-vivo');
  if (!z) return;
  function vivo(el) { return !!el.querySelector('[data-estado="en_vivo"]'); }
  if (!vivo(z)) return;
  var t = setInterval(function () {
    fetch(z.getAttribute('data-url'), { cache: 'no-store' }).then(function (r) { return r.ok ? r.text() : null; }).then(function (h) {
      if (h === null) return;
      z.innerHTML = h;
      if (!vivo(z)) clearInterval(t);
    }).catch(function () {});
  }, 15000);
})();
</script>
JS;
}
