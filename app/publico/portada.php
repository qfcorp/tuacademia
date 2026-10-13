<?php
/* (v20) Directorio público: /ligas (todas las ligas) y /nuestras-escuelas (todas las escuelas) */
$tipo_dir = get('tipo') === 'escuela' ? 'escuela' : 'liga';
$es_dir = $tipo_dir === 'escuela';
$lista = clientes_directorio($tipo_dir);
cabecera_publica(($es_dir ? (es_academia() ? 'Academias' : 'Escuelas deportivas') : 'Ligas y campeonatos') . ' · ' . marca());
?>
<div class="dir-cab">
  <div>
    <h1><?= $es_dir ? '🎓 ' . (es_academia() ? 'Academias' : 'Escuelas') . ' que usan ' . e(marca()) : '🏆 Ligas que usan ' . e(marca()) ?></h1>
    <p class="suave"><?= $es_dir ? (es_academia() ? 'Entre a la página de cada academia: cursos, horarios e inscripción en línea.' : 'Entre a la página de cada escuela: grupos, horarios e inscripción en línea.') : 'Entre a la página de cada liga: resultados en vivo, tabla, fixture y goleadores.' ?></p>
  </div>
  <?php if (!es_academia()): ?><div class="dir-tabs"><a class="<?= $es_dir ? '' : 'activa' ?>" href="ligas">🏆 Ligas</a><a class="<?= $es_dir ? 'activa' : '' ?>" href="nuestras-escuelas">🎓 Escuelas</a></div><?php endif; ?>
</div>
<?php if ($lista): ?>
  <?php if (count($lista) > 6): ?>
  <input type="search" id="dir-buscar" class="dir-buscar" placeholder="🔎 Buscar por nombre o ciudad" oninput="var t=this.value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');document.querySelectorAll('.dir-item').forEach(function(a){a.hidden=a.dataset.t.indexOf(t)<0})">
  <?php endif; ?>
  <div class="dir-rejilla">
  <?php foreach ($lista as $c): ?>
    <a class="tarjeta dir-item" href="index.php?l=<?= e($c['slug']) ?>" data-t="<?= e(mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $c['nombre'] . ' ' . $c['ciudad']) ?: $c['nombre'], 'UTF-8')) ?>">
      <?= escudo($c['logo'], $c['nombre'], $c['color'], 'escudo-grande') ?>
      <div>
        <strong><?= e($c['nombre']) ?></strong>
        <small><?= e(detalle_cliente($c)) ?></small>
        <small class="dir-num"><?= $es_dir
            ? (int)$c['n_grupos'] . ' grupo' . ((int)$c['n_grupos'] === 1 ? '' : 's') . ' · ' . (int)$c['n_alumnos'] . ' alumno' . ((int)$c['n_alumnos'] === 1 ? '' : 's')
            : (int)$c['n_equipos'] . ' equipo' . ((int)$c['n_equipos'] === 1 ? '' : 's') . ((int)$c['n_torneos'] > 0 ? ' · ' . (int)$c['n_torneos'] . ' campeonato' . ((int)$c['n_torneos'] === 1 ? '' : 's') . ' en curso' : '') ?></small>
      </div>
      <span class="dir-ir"><?= $es_dir ? (es_academia() ? 'Ver academia' : 'Ver escuela') : 'Ver liga' ?> →</span>
    </a>
  <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="tarjeta dir-vacio">
    <span class="dir-vacio-ico"><?= $es_dir ? '🎓' : '🏆' ?></span>
    <h2><?= $es_dir ? (es_academia() ? 'Muy pronto verá aquí a las academias que confían en nosotros' : 'Muy pronto verá aquí a las escuelas que confían en nosotros') : 'Muy pronto verá aquí a las ligas que confían en nosotros' ?></h2>
    <p class="suave"><?= $es_dir ? (es_academia() ? '¿Tiene una academia? Puede ser la primera en aparecer.' : '¿Tiene una escuela deportiva? Puede ser la primera en aparecer.') : '¿Organiza una liga o un campeonato? Puede ser la primera en aparecer.' ?></p>
    <a class="btn btn-grande" style="width:auto;display:inline-block;padding-inline:28px" href="<?= e(url_empezar($es_dir ? 'escuela' : '')) ?>"><?= e(texto_empezar()) ?></a>
  </div>
<?php endif; ?>
<p style="text-align:center;margin-top:30px"><a href="admin.php?p=login">Ingresar</a> · <a href="<?= $es_dir && !es_academia() ? 'escuelas' : 'index.php' ?>">Conozca <?= e(marca()) ?><?= $es_dir && !es_academia() ? ' para escuelas' : '' ?></a></p>
<?php
pie_publico();
