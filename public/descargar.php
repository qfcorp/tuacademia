<?php
/*
 * (v38) DESCARGAR LA APP PARA ANDROID (APK directo, com.tukan.tuacademia.directo).
 *  descargar.php        → página con las 3 formas de instalar y la ayuda para el APK
 *  descargar.php?apk=1  → baja el archivo tuacademia.apk
 * El APK usa los precios normales (los de la web). El de Google Play cobra con Google.
 */
define('VISTA_PUBLICA', true);
require __DIR__ . '/../app/bootstrap.php';
if (get('apk') === '1') {
    if (!apk_disponible()) {
        http_response_code(404);
        exit('La app para Android todavía no está disponible.');
    }
    $f = apk_archivo();
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="TuAcademia.apk"');
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: no-cache');
    readfile($f);
    exit;
}
cabecera_publica('Descargar la app — ' . marca());
?>
<main class="contenedor" style="max-width:720px">
  <h1>📲 <?= e(marca()) ?> en su celular</h1>
  <?= html_descargar_app() ?>
  <?php if (apk_disponible()): ?>
  <div class="tarjeta">
    <h2 style="margin-top:0">Cómo instalar el APK (Android)</h2>
    <ol>
      <li>Toque <b>⬇ Descargar la app para Android (APK)</b>.</li>
      <li>Cuando termine, toque la notificación <b>TuAcademia.apk</b> (o ábralo desde <b>Descargas</b>).</li>
      <li>Si el celular dice <b>«Por tu seguridad, no se permite instalar apps desconocidas»</b>: toque <b>Configuración</b> → active <b>Permitir de esta fuente</b> → vuelva atrás.</li>
      <li>Toque <b>Instalar</b> y después <b>Abrir</b>.</li>
      <li>Entre con su usuario y contraseña de siempre.</li>
    </ol>
    <p class="suave" style="margin:0">Necesita Android 7 o superior. Si Google Play Protect muestra un aviso, toque <b>Más detalles → Instalar de todos modos</b>: la app es nuestra y solo abre <?= e(marca()) ?>.</p>
  </div>
  <?php endif; ?>
  <p><a href="index.php">← Volver</a></p>
</main>
<?php
pie_publico();
