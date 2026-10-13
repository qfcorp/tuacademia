<?php
/*
 * Plantillas visuales (encabezado y pie) del panel y de la página pública.
 */

function cabecera_admin(string $titulo): void
{
    $u = usuario();
    $liga = liga_trabajo();
    acad_vocabulario_activar($liga); // (TuAcademia) palabras de la academia (disciplina, clase, salón)
    $p = get('p', 'inicio');
    $activo = fn(array $ps) => in_array($p, $ps, true) ? ' class="activo"' : '';
    ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · Panel <?= e(marca()) ?></title>
<link rel="stylesheet" href="assets/estilo.css?v=330">
<meta name="robots" content="noindex">
<?= cabecera_app() ?>
</head>
<body class="admin" style="--acento: <?= e($liga['color'] ?? '#0b6e4f') ?>; --acento2: <?= e(($liga['color2'] ?? '') !== '' ? $liga['color2'] : ($liga['color'] ?? '#0b6e4f')) ?>">
<header class="barra">
  <div class="barra-in">
    <a class="marca" href="admin.php"><?= es_academia() ? '🎓' : '⚽' ?> <?= e(marca()) ?> <small>Panel</small></a>
    <?= tc_enlace_volver() // (Tukán Central) «← Panel Tukán» si la sesión vino del panel central ?>
    <?= html_selector_idioma('idioma-panel') // (v28) ?>
    <?php if ($u): ?>
    <button class="menu-btn" type="button" onclick="document.body.classList.toggle('menu-abierto')" aria-label="Menú">☰</button>
    <nav class="menu"><?= html_menu_panel() // (v19) menú agrupado: app/menu.php ?></nav>
    <?php endif; ?>
  </div>
  <?php if ($liga): ?>
  <div class="subbarra"><div class="barra-in">
    <?= escudo($liga['logo'], $liga['nombre'], $liga['color'], 'mini') ?>
    <strong><?= e($liga['nombre']) ?></strong> · <?= es_escuela($liga) ? (count($deps_l = deportes_de_escuela($liga)) > 1 ? '🎓 ' . (es_academia() ? 'Academia' : 'Escuela multideporte') . ' (' . e(mb_strtolower(texto_deportes($deps_l))) . ')' : '🎓 ' . (es_academia() ? 'Academia' : 'Escuela') . ' de ' . e(mb_strtolower(deportes_escuela()[$liga['deporte_escuela']] ?? 'deporte'))) : e(nombre_modalidad($liga['modalidad'])) ?>
 · <a href="<?= e(url_liga($liga)) ?>" target="_blank">ver página pública ↗</a>
    <?php if (es_super()): ?> · <a href="admin.php?p=ligas&amp;salir_liga=1"><?= es_academia() ? 'cambiar de academia' : 'cambiar de liga o escuela' ?></a><?php else: ?> · <span class="suave">Perfil: <?= e(nombre_perfil(perfil_actual())) ?></span><?php endif; ?>
    <?php // (v16) combo liga + escuela
    if (!es_super() && ($cbo = combo_de($liga)) && (usuario()['perfil'] ?? 'admin') === 'admin'): ?> · <a class="btn btn-chico btn-sec-blanco" href="admin.php?combo=<?= (int)$cbo['id'] ?>">🔁 Ir a <?= ($cbo['tipo'] ?? 'liga') === 'escuela' ? '🎓' : '🏆' ?> <?= e($cbo['nombre']) ?></a><?php elseif (es_super() && ($cbo = combo_de($liga))): ?> · <span class="suave">combo con <?= e($cbo['nombre']) ?></span><?php endif; ?>
  </div></div>
  <?php endif; ?>
</header>
<?php if ($u && !es_super() && !empty($liga['es_demo'])): ?>
<div class="demo-cinta">👀 Está en la <b>demostración</b> de <?= e(marca()) ?> <?= demo_guardar() ? '(puede probar y guardar; todo se borra cada madrugada)' : '(solo mirar, no se guardan cambios)' ?>. <a href="index.php?v=demo">← Entrar a otro panel</a> · <a href="<?= e(url_empezar()) ?>"><?= prueba_automatica() ? 'Cree su liga gratis' : 'Solicite su demo' ?> →</a></div>
<?php endif; ?>
<main class="contenedor">
<?php if ($liga && $u && get('p') !== 'login' && toca_aviso_vencimiento($liga)): ?>
  <?= html_aviso_vencimiento($liga) ?>
<?php elseif ($liga && $u && get('p') !== 'login' && empty($liga['es_demo'])):
    $sus = estado_suscripcion($liga);
    if (in_array($sus['estado'], ['prueba', 'gracia', 'vencida'], true) && ($sus['estado'] !== 'prueba' || $sus['dias'] <= 10 || get('p', 'inicio') === 'inicio')):
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
</main>
<footer class="pie"><?= e(marca()) ?> · <?= e(ajuste('empresa', 'Tukán Software')) ?> · <a href="manual" target="_blank">Manuales</a></footer>
<?= usuario() ? html_barra_abajo() . script_menu_panel() . html_boton_ayuda() : '' ?>
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
<link rel="stylesheet" href="assets/estilo.css?v=330">
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
