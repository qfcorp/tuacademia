<?php
/* (v17) Evaluaciones → Jornadas de evaluación: el día de los tests el profesor abre la planilla de su grupo en el celular,
 * ve el valor anterior de cada alumno y si mejoró al momento; al cerrar la jornada los padres ven los resultados. */
$jid = (int)get('j');
$j = $jid ? jornada_de($jid, $lid) : null;
$ed = puede('evaluaciones', true);

if (!$j):
    $jornadas = filas('SELECT * FROM esc_jornadas WHERE liga_id = ? ORDER BY fecha DESC, id DESC LIMIT 40', [$lid]);
    if (perfil_actual() === 'profesor') $jornadas = array_values(array_filter($jornadas, fn($x) => grupos_jornada($x)));
    $mf = formativas_activas($liga); ?>
  <p class="suave">Una jornada de evaluación es el día (o la semana) en que se toman los tests a todos. Se crea una vez con los tests elegidos y cada profesor la llena desde el celular en el entrenamiento. Así todos los alumnos quedan medidos con la misma fecha y se comparan con la jornada anterior.</p>
  <?php if ($ed): ?>
  <details class="tarjeta" <?= !$jornadas ? 'open' : '' ?>><summary><b>+ Nueva jornada de evaluación</b></summary>
    <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="jornada_crear">
      <div class="campos">
        <div><label>Nombre</label><input type="text" name="nombre" required maxlength="100" placeholder="Ej: Evaluación inicial <?= date('Y') ?>" value="Evaluación de <?= e(mb_strtolower(nombre_mes(date('Y-m')))) ?>"></div>
        <div><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
        <div class="campo-ancho"><label>Tests que se toman</label><div class="dos-columnas"><?php foreach ($activos as $t): ?><label class="check" style="font-weight:400"><input type="checkbox" name="tests[]" value="<?= (int)$t['id'] ?>" checked> <?= e($t['nombre']) ?> <small class="suave"><?= e($t['unidad']) ?><?= ($t['deporte'] ?? '') !== '' ? ' · ' . e(texto_familia($t['deporte'])) : '' ?></small></label><?php endforeach; ?></div></div>
        <div class="campo-ancho"><label>Grupos (si no marca ninguno, son todos)</label><div class="dos-columnas"><?php foreach ($grupos as $g): ?><label class="check" style="font-weight:400"><input type="checkbox" name="grupos[]" value="<?= (int)$g['id'] ?>"> <?= icono_deporte_escuela(deporte_grupo($g, $liga)) ?> <?= e($g['nombre']) ?></label><?php endforeach; ?></div></div>
        <div class="campo-ancho"><label class="check"><input type="checkbox" name="medidas" value="1" checked> Tomar también peso y talla<?= $mf ? ' (y talla sentado para la maduración)' : '' ?></label></div>
        <div class="campo-ancho"><label>Indicaciones para los profesores (opcional)</label><input type="text" name="notas" maxlength="500" placeholder="Ej: calentar 10 minutos antes; 2 intentos por test, vale el mejor"></div>
      </div>
      <button class="btn">Crear jornada</button></form>
  </details>
  <?php endif; ?>
  <?php if (!$jornadas): ?><div class="tarjeta">Todavía no hay jornadas.</div><?php endif; ?>
  <div class="rejilla-2"><?php foreach ($jornadas as $x): [$ev, $tot] = progreso_jornada($x, grupos_jornada($x)); $pct = $tot ? (int)round(100 * $ev / $tot) : 0; ?>
    <a class="tarjeta" style="text-decoration:none;color:inherit;display:block" href="admin.php?p=evaluaciones&amp;j=<?= (int)$x['id'] ?>">
      <h3 style="margin-top:0">📋 <?= e($x['nombre']) ?> <?= $x['estado'] === 'cerrada' ? '<span class="estado-chip chip-ok">Cerrada</span>' : '<span class="estado-chip chip-pend">Abierta</span>' ?></h3>
      <p class="suave" style="margin:.2rem 0"><?= e(fecha_bonita($x['fecha'])) ?> · <?= count(tests_jornada($x)) ?> test(s)<?= (int)$x['medidas'] ? ' + medidas' : '' ?> · <?= $x['grupos'] === '' ? 'todos los grupos' : count(explode(',', $x['grupos'])) . ' grupo(s)' ?></p>
      <p style="margin:.3rem 0"><?= barra_avance($pct) ?> <b><?= $ev ?> de <?= $tot ?></b> alumnos evaluados</p></a>
  <?php endforeach; ?></div>
<?php
    return;
endif;

$tj = tests_jornada($j);
$gj = grupos_jornada($j);
$gsel = (int)get('g');
$mf = formativas_activas($liga);
?>
<p><a href="admin.php?p=evaluaciones">← Jornadas</a></p>
<div class="tarjeta">
  <h2 style="margin-top:0">📋 <?= e($j['nombre']) ?> <?= $j['estado'] === 'cerrada' ? '<span class="estado-chip chip-ok">Cerrada</span>' : '<span class="estado-chip chip-pend">Abierta</span>' ?></h2>
  <p class="suave" style="margin-top:0"><?= e(fecha_bonita($j['fecha'])) ?> · Tests: <?= e(implode(', ', array_column($tj, 'nombre'))) ?><?= (int)$j['medidas'] ? ' · Peso y talla' : '' ?></p>
  <?php if ($j['notas']): ?><p class="nota-profe">📌 <?= e($j['notas']) ?></p><?php endif; ?>
  <div class="rejilla-3"><?php foreach ($gj as $g): [$ev, $tot] = progreso_jornada($j, [$g]); ?>
    <div class="tarjeta" style="padding:10px 12px<?= $gsel === (int)$g['id'] ? ';border:2px solid var(--acento)' : '' ?>"><b><?= e($g['nombre']) ?></b><br>
      <?= barra_avance($tot ? (int)round(100 * $ev / $tot) : 0, $ev && $ev === $tot ? '#1a9e5c' : '') ?> <small><?= $ev ?>/<?= $tot ?></small>
      <?php if ($j['estado'] === 'abierta' && $ed): ?><br><a class="btn btn-chico" style="margin-top:6px" href="admin.php?p=evaluaciones&amp;j=<?= (int)$j['id'] ?>&amp;g=<?= (int)$g['id'] ?>#planilla">📱 Abrir planilla</a><?php endif; ?>
      <a class="btn btn-sec btn-chico" style="margin-top:6px" href="admin.php?p=evaluaciones&amp;j=<?= (int)$j['id'] ?>&amp;g=<?= (int)$g['id'] ?>&amp;res=1#resultados">📊 Resultados</a></div>
  <?php endforeach; ?></div>
  <?php if ($ed): ?><div class="acciones" style="margin-top:10px">
    <?php if ($j['estado'] === 'abierta'): ?>
      <form method="post" onsubmit="return confirm('¿Cerrar la jornada? Los padres verán los resultados en su portal.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="jornada_cerrar"><input type="hidden" name="jornada_id" value="<?= (int)$j['id'] ?>"><button class="btn">✅ Cerrar jornada y publicar a los padres</button></form>
      <form method="post" onsubmit="return confirm('¿Borrar la jornada?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="jornada_borrar"><input type="hidden" name="jornada_id" value="<?= (int)$j['id'] ?>"><button class="btn btn-sec btn-chico">🗑 Borrar</button></form>
    <?php else: ?>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="jornada_reabrir"><input type="hidden" name="jornada_id" value="<?= (int)$j['id'] ?>"><button class="btn btn-sec btn-chico">↩ Abrir otra vez para corregir</button></form>
    <?php endif; ?></div><?php endif; ?>
</div>

<?php
/* ---------- PLANILLA DEL GRUPO (celular) ---------- */
if ($gsel && !get('res') && $j['estado'] === 'abierta' && $ed):
    $g = grupo_de_escuela($gsel, $lid);
    $fam_g = familia_deporte(deporte_grupo($g, $liga)); // (v21) solo los tests que sirven para el deporte del grupo
    $tj_g = array_values(array_filter($tj, fn($t) => ($t['deporte'] ?? '') === '' || $t['deporte'] === $fam_g));
    if (!in_array($gsel, array_map(fn($x) => (int)$x['id'], $gj), true)) throw new RuntimeException('Ese grupo no está en esta jornada.');
    $al = alumnos_grupo($gsel);
    $ya = [];
    foreach (filas('SELECT alumno_id, test_id, valor FROM esc_resultados WHERE jornada_id = ?', [$j['id']]) as $x) $ya[(int)$x['alumno_id']][(int)$x['test_id']] = $x['valor'];
    $ym = [];
    foreach (filas('SELECT * FROM esc_mediciones WHERE jornada_id = ?', [$j['id']]) as $x) $ym[(int)$x['alumno_id']] = $x; ?>
  <h2 id="planilla">📱 Planilla · <?= e($g['nombre']) ?></h2>
  <p class="suave" style="margin-top:0">Escriba cada resultado: al lado aparece el valor anterior y si mejoró. Lo que escribe se guarda en el celular mientras tanto (si se va la señal no se pierde); al final toque <b>Guardar</b>.</p>
  <form method="post" id="form-planilla"><?= csrf_campo() ?><input type="hidden" name="accion" value="jornada_guardar"><input type="hidden" name="jornada_id" value="<?= (int)$j['id'] ?>"><input type="hidden" name="grupo_id" value="<?= $gsel ?>">
    <div class="progreso-j"><b id="pj-cuenta">0</b> de <?= count($al) ?> alumnos completos <button class="btn btn-chico" style="float:right">💾 Guardar</button></div>
    <div class="planilla-j">
    <?php foreach ($al as $a): $aid = (int)$a['id']; ?>
      <div class="pj-alumno"><div class="pj-cab"><?= foto_alumno($a) ?> <b><?= e(nombre_alumno($a)) ?></b> <small class="suave"><?= e(sub_de($a['fecha_nac'])) ?></small></div>
        <div class="pj-tests">
        <?php foreach ($tj_g as $t): $prev = valor_anterior($aid, (int)$t['id'], $j['fecha']); ?>
          <div class="pj-test"><label><?= e($t['nombre']) ?> (<?= e($t['unidad']) ?>)</label>
            <input type="number" step="0.01" inputmode="decimal" name="v[<?= $aid ?>][<?= (int)$t['id'] ?>]" value="<?= isset($ya[$aid][(int)$t['id']]) ? e(fmt_num($ya[$aid][(int)$t['id']])) : '' ?>" data-prev="<?= $prev !== null ? e((string)$prev) : '' ?>" data-mejor="<?= e($t['mejor']) ?>">
            <small class="suave"><?= $prev !== null ? 'antes: ' . e(fmt_num($prev)) : 'primera vez' ?></small></div>
        <?php endforeach; ?>
        <?php if ((int)$j['medidas']): $pm = fila('SELECT peso, talla FROM esc_mediciones WHERE alumno_id = ? AND fecha < ? ORDER BY fecha DESC LIMIT 1', [$aid, $j['fecha']]); ?>
          <div class="pj-test"><label>Peso (kg)</label><input type="number" step="0.1" inputmode="decimal" name="m[<?= $aid ?>][peso]" value="<?= isset($ym[$aid]) ? e(fmt_num($ym[$aid]['peso'], 1)) : '' ?>" class="pj-med"><small class="suave"><?= $pm && $pm['peso'] ? 'antes: ' . fmt_num($pm['peso'], 1) : '' ?></small></div>
          <div class="pj-test"><label>Talla (cm)</label><input type="number" step="0.1" inputmode="decimal" name="m[<?= $aid ?>][talla]" value="<?= isset($ym[$aid]) ? e(fmt_num($ym[$aid]['talla'], 1)) : '' ?>" class="pj-med"><small class="suave"><?= $pm && $pm['talla'] ? 'antes: ' . fmt_num($pm['talla'], 1) : '' ?></small></div>
          <?php if ($mf): ?><div class="pj-test"><label>Talla sentado (cm)</label><input type="number" step="0.1" inputmode="decimal" name="m[<?= $aid ?>][talla_sentado]" value="<?= isset($ym[$aid]) ? e(fmt_num($ym[$aid]['talla_sentado'], 1)) : '' ?>" class="pj-med"><small></small></div><?php endif; ?>
        <?php endif; ?>
        </div></div>
    <?php endforeach; ?>
    </div>
    <button class="btn btn-grande" style="margin-top:12px">💾 Guardar planilla</button>
  </form>
  <script>
  (function () {
    var f = document.getElementById('form-planilla'), clave = 'tv-jornada-<?= (int)$j['id'] ?>-<?= $gsel ?>';
    function fmt(n) { return Math.round(n * 100) / 100; }
    function evaluar(i) {
      var s = i.parentNode.querySelector('small'), p = i.dataset.prev;
      if (!s || p === undefined || p === '') return;
      if (i.value === '') { s.className = 'suave'; s.textContent = 'antes: ' + fmt(parseFloat(p)); return; }
      var d = parseFloat(i.value.replace(',', '.')) - parseFloat(p), mejor = i.dataset.mejor === 'menor' ? d < 0 : d > 0;
      if (Math.abs(d) < 1e-9) { s.className = 'suave'; s.textContent = '= igual que antes (' + fmt(parseFloat(p)) + ')'; return; }
      s.className = mejor ? 'sube' : 'baja';
      s.textContent = (mejor ? '▲ mejoró ' : '▼ bajó ') + fmt(Math.abs(d)) + ' (antes ' + fmt(parseFloat(p)) + ')';
    }
    function contar() {
      var n = 0;
      document.querySelectorAll('.pj-alumno').forEach(function (c) {
        var ins = c.querySelectorAll('input[data-mejor]'), ok = ins.length > 0;
        ins.forEach(function (i) { if (i.value === '') ok = false; });
        c.classList.toggle('listo', ok); if (ok) n++;
      });
      document.getElementById('pj-cuenta').textContent = n;
    }
    var borrador = null;
    try { borrador = JSON.parse(localStorage.getItem(clave) || 'null'); } catch (e) {}
    <?php if (get('guardado') === '1'): ?>try { localStorage.removeItem(clave); } catch (e) {} borrador = null;<?php endif; ?>
    f.querySelectorAll('input[type=number]').forEach(function (i) {
      if (borrador && i.value === '' && borrador[i.name]) i.value = borrador[i.name];
      if (i.dataset.mejor) evaluar(i);
      i.addEventListener('input', function () {
        if (i.dataset.mejor) evaluar(i);
        contar();
        var d = {}; f.querySelectorAll('input[type=number]').forEach(function (x) { if (x.value !== '') d[x.name] = x.value; });
        try { localStorage.setItem(clave, JSON.stringify(d)); } catch (e) {}
      });
    });
    contar();
  })();
  </script>
<?php
/* ---------- RESULTADOS ---------- */
else:
    $ver_g = $gsel ? array_values(array_filter($gj, fn($x) => (int)$x['id'] === $gsel)) : $gj; ?>
  <h2 id="resultados">📊 Resultados<?= $gsel && $ver_g ? ' · ' . e($ver_g[0]['nombre']) : '' ?></h2>
  <?php foreach ($ver_g as $g): $al = alumnos_grupo((int)$g['id']);
      $res = [];
      foreach (filas('SELECT r.* FROM esc_resultados r WHERE r.jornada_id = ? AND r.alumno_id IN (SELECT a.id FROM esc_alumnos a WHERE ' . sql_en_grupo('a', (int)$g['id']) . ')', [$j['id']]) as $x) $res[(int)$x['alumno_id']][(int)$x['test_id']] = (float)$x['valor'];
      if (!$res) { echo '<div class="tarjeta"><b>' . e($g['nombre']) . '</b>: todavía sin resultados.</div>'; continue; }
      $mejoraron = []; $con_prev = []; ?>
    <div class="tarjeta"><h3 style="margin-top:0"><?= e($g['nombre']) ?></h3>
      <div class="tabla-scroll"><table><tr><th>Alumno</th><?php foreach ($tj as $t): ?><th class="num"><?= e($t['nombre']) ?><br><small><?= e($t['unidad']) ?></small></th><?php endforeach; ?></tr>
      <?php foreach ($al as $a): $aid = (int)$a['id']; if (!isset($res[$aid])) continue; ?><tr><td><a href="admin.php?p=alumno&amp;id=<?= $aid ?>&amp;s=desarrollo"><?= e(nombre_alumno($a)) ?></a></td>
        <?php foreach ($tj as $t): $tid = (int)$t['id']; $v = $res[$aid][$tid] ?? null; ?><td class="num"><?php if ($v !== null):
            $prev = valor_anterior($aid, $tid, $j['fecha']); $mp = $prev !== null ? mejora_pct($prev, $v, $t['mejor']) : null;
            if ($mp !== null) { $con_prev[$tid] = ($con_prev[$tid] ?? 0) + 1; if ($mp > 0) $mejoraron[$tid] = ($mejoraron[$tid] ?? 0) + 1; } ?>
          <b><?= fmt_num($v) ?></b> <?= chip_semaforo(semaforo_test($t, $v, $a, $j['fecha']), false) ?><br><small><?= flecha_mejora($mp) ?></small><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
      </table></div>
      <?php if ($con_prev): ?><h4 style="margin-bottom:0">% de alumnos que mejoró respecto a su evaluación anterior</h4>
        <?= svg_barras(array_map(fn($t) => [mb_substr($t['nombre'], 0, 12), isset($con_prev[(int)$t['id']]) ? round(100 * ($mejoraron[(int)$t['id']] ?? 0) / $con_prev[(int)$t['id']]) : 0, isset($con_prev[(int)$t['id']]) ? round(100 * ($mejoraron[(int)$t['id']] ?? 0) / $con_prev[(int)$t['id']]) . '%' : '', '#1a9e5c'], $tj), ['max' => 100, 'alto' => 150]) ?>
      <?php endif; ?>
      <?php if ($j['estado'] === 'cerrada' && ($mm = mayor_mejora_grupo((int)$g['id'], $lid))): $am = fila('SELECT nombres, apellidos FROM esc_alumnos WHERE id = ?', [$mm['aid']]); ?>
        <p class="nota-profe">🚀 <b>Mayor mejora del grupo:</b> <?= e($am['nombres'] . ' ' . $am['apellidos']) ?> (+<?= fmt_num($mm['pct'], 1) ?>% en promedio)</p><?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($j['estado'] === 'cerrada' && $ed): ?>
  <h2 id="avisar">📲 Avisar a las familias</h2>
  <p class="suave">Toque cada botón para enviar el mensaje por WhatsApp. En su portal los padres ven los resultados con gráficos y la comparación con la evaluación anterior.</p>
  <div class="tarjeta"><?php foreach ($ver_g as $g): foreach (alumnos_grupo((int)$g['id']) as $a):
        $rs = filas('SELECT r.valor, r.test_id, t.mejor FROM esc_resultados r JOIN esc_tests t ON t.id = r.test_id WHERE r.alumno_id = ? AND r.jornada_id = ?', [$a['id'], $j['id']]);
        if (!$rs) continue;
        $mej = 0; $tot = 0;
        foreach ($rs as $x) { $pv = valor_anterior((int)$a['id'], (int)$x['test_id'], $j['fecha']); if ($pv !== null) { $tot++; if ((mejora_pct($pv, (float)$x['valor'], $x['mejor']) ?? 0) > 0) $mej++; } }
        $msg = mensaje_jornada_familia($a, $j, $liga, $mej, $tot); $tel = telefono_wa($a['representante_telefono']); ?>
      <div class="asis-fila"><span><b><?= e(nombre_alumno($a)) ?></b> <small class="suave"><?= e($a['representante_nombre']) ?><?= $tot ? ' · mejoró en ' . $mej . ' de ' . $tot : '' ?></small></span>
        <?php if ($tel): ?><a class="btn btn-sec btn-chico" target="_blank" href="https://wa.me/<?= e($tel) ?>?text=<?= rawurlencode($msg) ?>">📲 WhatsApp</a><?php else: ?><small class="suave">sin teléfono</small><?php endif; ?></div>
  <?php endforeach; endforeach; ?></div>
  <?php endif; ?>
<?php endif;
