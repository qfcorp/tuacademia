<?php
/* (v8) Para su web: código para incrustar la tabla, resultados, próximos partidos, goleadores o partidos en vivo */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$torneos = filas("SELECT * FROM torneos WHERE liga_id = ? AND " . sql_sin_carreras() . " ORDER BY (estado = 'finalizado'), id DESC", [$lid]);
$tipos = ['tabla' => 'Tabla de posiciones', 'resultados' => 'Últimos resultados', 'proximos' => 'Próximos partidos', 'goleadores' => 'Goleadores / anotadores', 'vivo' => 'Partidos en vivo'];
$w = array_key_exists(get('w'), $tipos) ? get('w') : 'tabla';
$tid = (int)get('t');
$tema = get('tema') === 'oscuro' ? 'oscuro' : 'claro';
$n = entero(get('n', '6'), 1, 30);
$alto = entero(get('alto', '420'), 200, 1200);
$src = url_base() . 'widget.php?l=' . rawurlencode($liga['slug']) . '&w=' . $w . ($tid ? '&t=' . $tid : '') . '&n=' . $n . ($tema === 'oscuro' ? '&tema=oscuro' : '');
$codigo = '<iframe src="' . $src . '" width="100%" height="' . $alto . '" style="border:0;border-radius:12px;max-width:520px" loading="lazy" title="' . e($liga['nombre']) . '"></iframe>';
cabecera_admin('Para su web');
subnav_publico('widgets');
?>
<h1>🧩 Para su página web y redes</h1>
<p class="suave">Copie este código y péguelo en la página web de la liga (WordPress, Wix, Google Sites, Blogger…) como "HTML" o "Insertar código". Se actualiza solo: nadie tiene que volver a subir la tabla.</p>
<form method="get" class="tarjeta">
  <input type="hidden" name="p" value="widgets">
  <div class="campos">
    <div><label>¿Qué mostrar?</label><select name="w" onchange="this.form.submit()"><?php foreach ($tipos as $k => $v): ?><option value="<?= $k ?>" <?= $w === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Campeonato</label><select name="t" onchange="this.form.submit()"><option value="">El que está en curso</option><?php foreach ($torneos as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $tid === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['nombre'] . ' ' . $t['temporada']) ?></option><?php endforeach; ?></select></div>
    <div><label>Cantidad</label><input type="number" name="n" min="1" max="30" value="<?= $n ?>" onchange="this.form.submit()"></div>
    <div><label>Colores</label><select name="tema" onchange="this.form.submit()"><option value="claro">Fondo blanco</option><option value="oscuro" <?= $tema === 'oscuro' ? 'selected' : '' ?>>Fondo oscuro</option></select></div>
    <div><label>Alto (px)</label><input type="number" name="alto" min="200" max="1200" step="20" value="<?= $alto ?>" onchange="this.form.submit()"></div>
  </div>
</form>
<div class="rejilla-2">
  <div class="tarjeta">
    <h2 style="margin-top:0">1. Copie este código</h2>
    <textarea readonly rows="5" style="width:100%;font-family:monospace;font-size:.85rem" onclick="this.select()" id="cod"><?= e($codigo) ?></textarea>
    <button class="btn" type="button" onclick="navigator.clipboard.writeText(document.getElementById('cod').value).then(function () { alert('Código copiado'); })">📋 Copiar código</button>
    <p class="suave">O comparta este enlace directo: <a href="<?= e($src) ?>" target="_blank">abrir</a></p>
    <h3>📅 Calendario para el celular</h3>
    <p class="suave" style="margin-top:0">Con este enlace, cualquiera agrega todos los partidos del campeonato al calendario de su celular (y se actualiza solo). Cada equipo tiene el suyo en su página pública.</p>
    <?php $tc = $tid ?: (int)($torneos[0]['id'] ?? 0); if ($tc): ?><a class="btn btn-sec btn-chico" href="<?= e(enlace_calendario($liga, 't', $tc)) ?>">📅 Calendario del campeonato</a><?php endif; ?>
  </div>
  <div class="tarjeta">
    <h2 style="margin-top:0">2. Así se verá</h2>
    <?= $codigo ?>
  </div>
</div>
<?php
pie_admin();
