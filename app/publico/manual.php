<?php
/*
 * (v27) Manuales de TuAcademia en la web: /manual, /manual/ligas y /manual/escuelas.
 * El contenido está en app/manuales/*.html (secciones con anclas #s1, #s2… para el botón de ayuda del panel)
 * y las imágenes en public/assets/manual/. El PDF se descarga de assets/manual/Manual-TuAcademia-*.pdf.
 */
$manuales = [
    'ligas' => ['Manual de ligas y campeonatos', '🏆', 'Fútbol, indor, vóley, ecuavóley, básquet, balonmano, béisbol y deportes individuales: carreras, tenis, pádel, squash, tenis de mesa, artes marciales, boxeo, ajedrez, e-sports, bolos, natación, atletismo, carreras de ruta, pesas y triatlón; y olimpiadas multideportivas.', 'Manual-TuAcademia-Ligas.pdf'],
    'escuelas' => ['Manual de escuelas deportivas', '🎓', 'Grupos, alumnos, asistencia, entrenamientos, pagos, profesores, evaluaciones, club, tienda y escuela multideporte.', 'Manual-TuAcademia-Escuelas.pdf'],
];
$m = get('m');
// (v30) Manuales traducidos: app/manuales/<manual>.<idioma>.html y assets/manual/Manual-TuAcademia-<Manual>.<idioma>.pdf
$idm = function_exists('idioma_actual') ? idioma_actual() : 'es';
$pdf_idioma = function (string $f) use ($idm): string {
    if ($idm === 'es') return $f;
    $t = preg_replace('/\.pdf$/', '.' . $idm . '.pdf', $f);
    return is_file(RAIZ . '/public/assets/manual/' . $t) ? $t : $f;
};
$pdf_url = fn(string $f): string => 'assets/manual/' . $pdf_idioma($f);
$pdf_hay = fn(string $f): bool => is_file(RAIZ . '/public/assets/manual/' . $pdf_idioma($f));

if (!isset($manuales[$m])) {
    cabecera_publica('Manuales de ' . marca());
    ?>
    <h1 style="margin-bottom:.2rem">📘 Manuales de <?= e(marca()) ?></h1>
    <p class="suave">Paso a paso y con imágenes de la demostración. Se leen aquí o se descargan en PDF.</p>
    <div class="rejilla" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))">
      <?php foreach ($manuales as $k => [$tit, $ico, $desc, $pdf]): ?>
        <div class="tarjeta">
          <div style="font-size:2.4rem"><?= $ico ?></div>
          <h2 style="margin:.3rem 0"><?= e($tit) ?></h2>
          <p class="suave"><?= e($desc) ?></p>
          <a class="btn" href="manual/<?= $k ?>">Leer el manual</a>
          <?php if ($pdf_hay($pdf)): ?> <a class="btn btn-sec" href="<?= e($pdf_url($pdf)) ?>" download>⬇ Descargar PDF</a><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="aviso aviso-info" style="margin-top:16px">🎁 ¿Todavía no usa <?= e(marca()) ?>? <a href="solicitar-demo"><b>Pida su demo gratis de <?= (int)ajuste('dias_demo', 7) ?> días</b></a>.</div>
    <?php
    pie_publico();
    return;
}

[$titulo, $ico, $desc, $pdf] = $manuales[$m];
$archivo_manual = APP_DIR . '/manuales/' . $m . '.html';
$traducido = false;
if ($idm !== 'es' && is_file(APP_DIR . '/manuales/' . $m . '.' . $idm . '.html')) {
    $archivo_manual = APP_DIR . '/manuales/' . $m . '.' . $idm . '.html';
    $traducido = true;
}
$html = (string)@file_get_contents($archivo_manual);
$html = str_replace('7 días', (int)ajuste('dias_demo', 7) . ' días', $html);
preg_match_all('#<h2 id="(s\d+)">(.*?)</h2>#s', $html, $sec, PREG_SET_ORDER);
$impresion = get('imprimir') === '1';
$host_manual = preg_replace('#^https?://#', '', rtrim(url_base(), '/'));
if (preg_match('#^(127\.|localhost)#', $host_manual)) $host_manual = 'tuacademia.tukanec.com'; // el PDF se arma en la computadora de desarrollo
cabecera_publica($titulo . ' · ' . marca());
?>
<style>
  .manual { display: grid; grid-template-columns: 270px minmax(0, 1fr); gap: 28px; align-items: start; }
  .manual-indice { position: sticky; top: 12px; max-height: calc(100vh - 24px); overflow: auto; background: var(--tarjeta, #fff); border-radius: 12px; padding: 12px 14px; box-shadow: 0 1px 4px rgba(0,0,0,.08); font-size: .88rem; }
  .manual-indice a { display: block; padding: 4px 0; color: inherit; text-decoration: none; border-bottom: 1px solid rgba(127,127,127,.12); }
  .manual-indice a:hover { color: var(--acento); }
  .manual-texto { background: var(--tarjeta, #fff); border-radius: 14px; padding: 22px 28px; box-shadow: 0 1px 4px rgba(0,0,0,.08); line-height: 1.6; }
  .manual-texto h2 { margin-top: 2.2rem; padding-top: .6rem; border-top: 3px solid var(--acento); scroll-margin-top: 12px; }
  .manual-texto h2:first-of-type { margin-top: .4rem; }
  .manual-texto h3 { margin-top: 1.4rem; }
  .manual-texto figure { margin: 14px 0; }
  .manual-texto figure img { max-width: 100%; height: auto; border: 1px solid #d5ddd9; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.08); display: block; }
  .manual-texto figcaption { font-size: .8rem; color: #6b7572; margin-top: 4px; }
  .manual-texto table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: .92rem; }
  .manual-texto th, .manual-texto td { border: 1px solid #d5ddd9; padding: 6px 8px; vertical-align: top; text-align: left; white-space: normal; }
  .manual-texto th { background: #eef3f1; }
  .manual-texto li p { margin: .2rem 0; }
  .manual-texto .nota, .manual-texto .nota-demo { background: #eef6ff; border-left: 4px solid #2f6fd6; padding: 10px 14px; border-radius: 8px; margin: 12px 0; }
  .manual-texto .nota-demo { background: #fff7e6; border-left-color: #f0a500; }
  .manual-cab { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; margin-bottom: 14px; }
  .portada-pdf { display: none; }
  @media (max-width: 900px) { .manual { grid-template-columns: 1fr; } .manual-indice { position: static; max-height: none; } .manual-texto { padding: 16px; } }
  @media print {
    header.portada, footer.pie, .manual-indice, .manual-cab, .demo-cinta { display: none !important; }
    .manual { display: block; }
    .manual-texto { box-shadow: none; padding: 0; border-radius: 0; font-size: 11pt; }
    .manual-texto h2 { break-before: page; border-top: 0; border-bottom: 3px solid #0b6e4f; }
    .manual-texto figure, .manual-texto table, .manual-texto ol { break-inside: avoid; }
    .manual-texto figure img { max-height: 21cm; width: auto; box-shadow: none; }
    .portada-pdf { display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; height: 25cm; break-after: page; }
    .portada-pdf h1 { font-size: 30pt; margin: .4em 0; color: #0b6e4f; }
    .portada-pdf .indice-pdf { text-align: left; columns: 2; font-size: 10pt; margin-top: 1.2cm; }
    body { background: #fff !important; }
  }
</style>
<div class="portada-pdf">
  <img src="assets/icono-192.png" alt="" style="width:120px">
  <h1><?= e($titulo) ?></h1>
  <p style="font-size:14pt"><?= e(marca()) ?> · QF Producciones · <?= e($host_manual) ?>/manual/<?= e($m) ?></p>
  <p style="font-size:11pt;color:#555"><?= e($desc) ?><br>Versión: <?= date('d/m/Y', filemtime($archivo_manual)) ?></p>
  <?= $traducido ? '<!--notr-->' : '' ?><div class="indice-pdf"><?php foreach ($sec as $s): ?><div><?= strip_tags($s[2]) ?></div><?php endforeach; ?></div><?= $traducido ? '<!--/notr-->' : '' ?>
</div>
<div class="manual-cab">
  <div><a href="manual">← Manuales</a>
    <h1 style="margin:.2rem 0"><?= $ico ?> <?= e($titulo) ?></h1>
    <p class="suave" style="margin:0"><?= e($desc) ?></p></div>
  <div class="acciones">
    <?php if ($pdf_hay($pdf)): ?><a class="btn" href="<?= e($pdf_url($pdf)) ?>" download>⬇ Descargar PDF</a><?php endif; ?>
    <button class="btn btn-sec" type="button" onclick="window.print()">🖨 Imprimir</button>
  </div>
</div>
<div class="manual">
  <nav class="manual-indice" aria-label="Índice">
    <b>Índice</b>
    <?= $traducido ? '<!--notr-->' : '' ?><?php foreach ($sec as $s): ?><a href="manual/<?= e($m) ?>#<?= $s[1] ?>"><?= strip_tags($s[2]) ?></a><?php endforeach; ?><?= $traducido ? '<!--/notr-->' : '' ?>
  </nav>
  <article class="manual-texto"><?= $traducido ? '<!--notr-->' . $html . '<!--/notr-->' : $html ?></article>
</div>
<?php if ($impresion): ?><script>window.addEventListener('load', function () { document.querySelectorAll('img[loading="lazy"]').forEach(function (i) { i.loading = 'eager'; }); });</script><?php endif; ?>
<?php
pie_publico();
