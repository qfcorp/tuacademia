<?php
/*
 * Inicio de la APLICACIÓN instalada en el celular (PWA).
 * Muestra el acceso según quién es y las vocalías abiertas recientemente en este celular
 * (que funcionan aunque no haya internet).
 */
require __DIR__ . '/../app/bootstrap.php';
if (isset($_GET['origen'])) fijar_origen_app((string)$_GET['origen']); // (v38) play = app de Google Play, apk = APK directo, web = navegador
$u = usuario();
$destino = $u ? ['super' => 'admin.php', 'liga' => 'admin.php', 'arbitro' => 'arbitro.php', 'delegado' => 'delegado.php'][$u['rol']] ?? 'admin.php' : '';
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(marca()) ?></title>
<link rel="stylesheet" href="assets/estilo.css?v=330">
<?= cabecera_app() ?>
</head>
<body class="vocal-body">
<main class="contenedor" style="padding-top:24px">
  <div style="text-align:center;color:#fff">
    <img src="assets/icono-192.png" alt="" style="width:84px;height:84px;border-radius:20px">
    <h1 style="margin:.6rem 0 0"><?= e(marca()) ?></h1>
  </div>
  <div class="tarjeta" style="margin-top:18px">
    <?php if ($u): ?>
      <p style="margin-top:0">Hola, <b><?= e($u['nombre']) ?></b></p>
      <a class="btn btn-grande" href="<?= e($destino) ?>"><?= e(['super' => 'Ir al panel', 'liga' => 'Ir al panel de la liga', 'arbitro' => 'Mis partidos e informes', 'delegado' => 'Mi equipo'][$u['rol']] ?? 'Entrar') ?></a>
    <?php else: ?>
      <a class="btn btn-grande" href="admin.php?p=login">Ingresar (liga, árbitros y delegados)</a>
      <a class="btn btn-grande btn-sec" href="mi.php">🪪 Soy jugador: mi carnet</a>
      <a class="btn btn-grande btn-sec" href="index.php?v=ligas">Ver ligas y resultados</a>
    <?php endif; ?>
  </div>
  <div class="tarjeta" id="mis-vocalias" style="display:none">
    <h2 style="margin-top:0">📋 Mis vocalías</h2>
    <p class="suave" style="margin-top:0">Partidos que abrió como vocal en este celular. Funcionan aunque no haya internet.</p>
    <div id="lista-vocalias"></div>
  </div>
  <div class="tarjeta" id="mis-equipos" style="display:none">
    <h2 style="margin-top:0">🔔 Equipos que sigo</h2>
    <p class="suave" style="margin-top:0">Le llegan avisos de sus goles, resultados y horarios. Para seguir otro equipo, ábralo en la página de su liga y toque <b>Seguir a este equipo</b>.</p>
    <div id="lista-equipos"></div>
  </div>
  <?php if (app_origen() === 'web'): // (v38) dentro de las apps de Android no hace falta ?>
  <div class="tarjeta" id="instalar" style="display:none">
    <h2 style="margin-top:0">📲 Instalar en el celular</h2>
    <button class="btn btn-grande" id="btn-instalar">Instalar aplicación</button>
  </div>
  <?= html_descargar_app() ?>
  <?php endif; ?>
</main>
<script>
(function () {
  try {
    var rec = JSON.parse(localStorage.getItem('vocalias_recientes') || '[]');
    if (rec.length) {
      document.getElementById('mis-vocalias').style.display = '';
      document.getElementById('lista-vocalias').innerHTML = rec.map(function (x) {
        var n = 0; try { n = JSON.parse(localStorage.getItem('vocal_cola_' + x.k) || '[]').length; } catch (e) {}
        var d = document.createElement('div'); d.textContent = x.t;
        return '<a class="btn btn-sec btn-grande" style="text-align:left" href="vocal.php?k=' + encodeURIComponent(x.k) + '">⚽ ' + d.innerHTML + '<br><small>' + x.f + (n ? ' · ⏳ ' + n + ' sin enviar' : '') + '</small></a>';
      }).join('');
    }
  } catch (e) {}
  // (v10) Equipos que sigue este celular (notificaciones)
  if ('serviceWorker' in navigator && 'PushManager' in window) {
    navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
      if (!sub) return;
      var f = new FormData(); f.append('csrf', <?= json_encode(csrf_token()) ?>); f.append('accion', 'estado'); f.append('sub', JSON.stringify(sub));
      return fetch('push.php', { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
        if (!r || !r.ok || !r.equipos || !r.equipos.length) return;
        document.getElementById('mis-equipos').style.display = '';
        document.getElementById('lista-equipos').innerHTML = r.equipos.map(function (x) {
          var d = document.createElement('div'); d.textContent = x.nombre; var l = document.createElement('div'); l.textContent = x.liga;
          return '<a class="btn btn-sec btn-grande" style="text-align:left" href="' + x.url + '">⚽ ' + d.innerHTML + '<br><small>' + l.innerHTML + '</small></a>';
        }).join('');
      });
    }).catch(function () {});
  }
  var diferido = null, cajaInst = document.getElementById('instalar'), btnInst = document.getElementById('btn-instalar');
  if (cajaInst && btnInst) { // (v38) dentro de las apps de Android no existe
    window.addEventListener('beforeinstallprompt', function (ev) { ev.preventDefault(); diferido = ev; cajaInst.style.display = ''; });
    btnInst.addEventListener('click', function () { if (diferido) { diferido.prompt(); diferido = null; cajaInst.style.display = 'none'; } });
  }
})();
</script>
</body>
</html>
