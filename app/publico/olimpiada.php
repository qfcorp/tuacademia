<?php
/*
 * (v37) Página pública de un evento de OLIMPIADAS (index.php?l=liga&o=ID) y PORTAL DE LA DELEGACIÓN (&k=token):
 *  - Público: medallero general, podios, programa, disciplinas y delegaciones.
 *  - Portal: el coordinador de la delegación registra a su gente (datos y documentos), la inscribe en las disciplinas y ve su estado.
 */
$ev = olimp_evento((int)get('o'), $lid);
$token = preg_match('/^[0-9a-f]{32}$/', get('k')) ? get('k') : '';
$del = $ev && $token !== '' ? fila('SELECT * FROM olimp_delegaciones WHERE evento_id = ? AND token = ?', [$ev['id'], $token]) : null;
$cfg = $ev ? olimp_cfg($ev) : null;
if (!$ev || ($token !== '' && !$del) || (!$del && !$cfg['publico']) || !olimp_activo($liga) && !$del) {
    http_response_code(404);
    cabecera_publica('No encontrado', $liga);
    echo '<div class="tarjeta"><h1>No encontrado</h1><p>' . ($token !== '' ? 'Este enlace ya no es válido. Pídale a la organización el enlace nuevo de su delegación.' : 'Este evento no existe o no es público.') . '</p><p><a href="index.php?l=' . e($liga['slug']) . '">Ir a ' . e($liga['nombre']) . '</a></p></div>';
    pie_publico();
    return;
}
$evid = (int)$ev['id'];
$bo = $base . '&o=' . $evid;

/* ============================================================
 * PORTAL DE LA DELEGACIÓN
 * ============================================================ */
if ($del) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: private, no-store');
    $bp = $bo . '&k=' . $token;
    $discs = olimp_disciplinas($evid);
    $mod_req = olimp_mod_req($ev);
    $puede = (int)$del['activo'] === 1 && olimp_activo($liga) && liga_puede_editar($liga);
    if (es_post()) {
        csrf_verificar();
        try {
            if (!$puede) throw new RuntimeException('Por ahora no se pueden hacer cambios. Comuníquese con la organización.');
            $accion = post('accion');
            $dep_mio = function () use ($evid, $del): array {
                return fila('SELECT * FROM olimp_deportistas WHERE id = ? AND evento_id = ? AND delegacion_id = ?', [(int)post('dep_id'), $evid, $del['id']]) ?? throw new RuntimeException('Deportista no encontrado.');
            };
            if ($accion === 'dep_guardar') {
                $depid = (int)post('dep_id');
                if ($depid > 0) $dep_mio();
                else {
                    exigir_autorizacion();
                    if (!$cfg['insc_abierta'] || ($cfg['insc_hasta'] !== '' && date('Y-m-d') > $cfg['insc_hasta'])) throw new RuntimeException('Las inscripciones de este evento ya cerraron.');
                }
                $nid = olimp_guardar_deportista($ev, (int)$del['id'], olimp_leer_deportista_post(), $depid);
                guardar_requisitos($lid, $mod_req, 'olimp_deportistas', $nid);
                if ($depid === 0) registrar_consentimiento('olimp_deportista', $lid, post('nombres') . ' ' . post('apellidos'), 'organizacion', ['ref_tabla' => 'olimp_deportistas', 'ref_id' => $nid, 'para' => 'su participación en ' . $ev['nombre'] . ' (inscrito por ' . $del['nombre'] . ')']);
                flash($depid ? 'Datos guardados.' : 'Registrado. Ahora inscríbalo en sus disciplinas.' . ($cfg['aprobacion'] === 'manual' ? ' La organización revisará sus documentos y lo aprobará.' : ''));
                ir($bp . '&dep=' . $nid . '#dep');
            }
            if ($accion === 'insc_agregar') {
                $dep = $dep_mio();
                $d = $discs[(int)post('disciplina_id')] ?? throw new RuntimeException('Elija la disciplina.');
                [, $av] = olimp_inscribir($ev, $dep, $d, olimp_leer_inscripcion_post());
                flash('Inscrito en ' . olimp_nombre_disciplina($d) . '.' . ($av ? ' ⚠ ' . implode(' · ', $av) : ''), $av ? 'error' : 'ok');
                ir($bp . '&dep=' . $dep['id'] . '#dep');
            }
            if ($accion === 'insc_quitar') {
                $i = fila('SELECT i.* FROM olimp_inscripciones i JOIN olimp_deportistas p ON p.id = i.deportista_id WHERE i.id = ? AND i.evento_id = ? AND p.delegacion_id = ?', [(int)post('insc_id'), $evid, $del['id']]) ?? throw new RuntimeException('Inscripción no encontrada.');
                if ($cfg['insc_hasta'] !== '' && date('Y-m-d') > $cfg['insc_hasta']) throw new RuntimeException('Las inscripciones ya cerraron: para quitarlo, pídaselo a la organización.');
                olimp_quitar_inscripcion((int)$i['id']);
                flash('Inscripción quitada.');
                ir($bp . '&dep=' . (int)$i['deportista_id'] . '#dep');
            }
            if ($accion === 'dep_borrar') {
                $dep = $dep_mio();
                if ($dep['estado'] === 'aprobado') throw new RuntimeException('Ya fue aprobado: para darlo de baja, pídaselo a la organización.');
                olimp_borrar_deportista($dep);
                flash('Eliminado.');
                ir($bp);
            }
            throw new RuntimeException('Acción no válida.');
        } catch (RuntimeException $ex) {
            flash($ex->getMessage(), 'error');
            ir($bp . ((int)post('dep_id') ? '&dep=' . (int)post('dep_id') . '#dep' : ''));
        }
    }
    $deps = olimp_deportistas($evid, (int)$del['id']);
    $dep = (int)get('dep') ? ($deps[(int)get('dep')] ?? null) : null;
    $nuevo = get('nuevo') === '1';
    $res = olimp_tabla($ev);
    $mi = null;
    foreach ($res['tabla'] as $x) if ((int)$x['del']['id'] === (int)$del['id']) $mi = $x;
    cabecera_publica($del['nombre'] . ' · ' . $ev['nombre'], $liga);
    echo olimp_css();
    foreach (tomar_flash() as [$tipo_f, $msg_f]) echo '<div class="aviso aviso-' . e($tipo_f) . '">' . e($msg_f) . '</div>'; ?>
  <div class="olimp-cab">
    <?= olimp_html_logo($del) ?>
    <div style="flex:1;min-width:220px"><h1 style="margin:0"><?= e($del['nombre']) ?></h1>
      <p class="suave" style="margin:2px 0">Portal de la delegación · <a href="<?= e($bo) ?>"><?= e($ev['nombre']) ?></a></p></div>
  </div>
  <div class="aviso aviso-info">🔒 Este enlace es solo para el coordinador de <?= e($del['nombre']) ?>: con él se registra a la gente de la delegación. No lo publique.
    <?= $cfg['insc_hasta'] !== '' ? ' Inscripciones hasta el <b>' . e(fecha_bonita($cfg['insc_hasta'])) . '</b>.' : '' ?><?= !$cfg['insc_abierta'] ? ' <b>Las inscripciones están cerradas.</b>' : '' ?></div>
  <?php if (!$puede): ?><div class="aviso aviso-error">Por ahora no se pueden hacer cambios. Comuníquese con la organización.</div><?php endif; ?>
  <?php if ($mi): ?><div class="olimp-kpis"><div><b><?= $mi['pos'] ?>.º</b>en la tabla</div><div><b><?= olimp_num($mi['puntos']) ?></b>puntos</div><div><b><?= $mi['oro'] ?> · <?= $mi['plata'] ?> · <?= $mi['bronce'] ?></b>🥇 🥈 🥉</div><div><b><?= count($deps) ?></b>deportistas</div></div><?php endif; ?>

  <?php if ($dep): $ins = olimp_inscripciones_dep((int)$dep['id']); $falta = requisitos_faltan('olimp_deportistas', (int)$dep['id'], $mod_req); $cr = olimp_cruces_deportista((int)$dep['id'], $discs); ?>
  <div class="tarjeta" id="dep">
    <p><a href="<?= e($bp) ?>">← Todos mis deportistas</a></p>
    <h2 style="margin-top:0"><?= e(olimp_nombre_dep($dep)) ?> <span class="etiqueta<?= $dep['estado'] === 'aprobado' ? '' : ($dep['estado'] === 'rechazado' ? ' roja' : ' gris') ?>"><?= e(olimp_estados_deportista()[$dep['estado']]) ?></span></h2>
    <?php if ($dep['estado'] === 'rechazado' && $dep['nota'] !== ''): ?><div class="aviso aviso-error">Motivo: <?= e($dep['nota']) ?></div><?php endif; ?>
    <?php if ($falta): ?><div class="aviso aviso-error">⚠ Falta: <?= e(implode(', ', $falta)) ?>. Complételo abajo.</div><?php endif; ?>
    <?php foreach ($cr as $c): ?><div class="aviso aviso-error">⏰ Cruce de horario: <?= e($c) ?></div><?php endforeach; ?>
    <h3>Disciplinas</h3>
    <?php if (!$ins): ?><p class="suave">Todavía no está inscrito en ninguna disciplina.</p><?php endif; ?>
    <ul>
      <?php foreach ($ins as $i): ?>
        <li><?= e(olimp_texto_inscripcion($i, $discs)) ?> <?= $i['ref_id'] !== null ? '<span class="etiqueta">✓ confirmado</span>' : '<span class="etiqueta gris">por aprobar</span>' ?>
          <?php if ($puede): ?><form method="post" style="display:inline" onsubmit="return confirm('¿Quitar esta inscripción?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="insc_quitar"><input type="hidden" name="insc_id" value="<?= (int)$i['id'] ?>"><input type="hidden" name="dep_id" value="<?= (int)$dep['id'] ?>"><button class="btn btn-sec btn-mini">quitar</button></form><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($puede && $dep['estado'] !== 'rechazado' && $discs && $cfg['insc_abierta']): ?>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="insc_agregar"><input type="hidden" name="dep_id" value="<?= (int)$dep['id'] ?>">
      <?= olimp_html_campos_inscripcion($discs, $dep) ?>
      <button class="btn btn-chico">+ Inscribir</button>
    </form>
    <?php endif; ?>
    <?php if ($puede): ?>
    <h3>Datos y documentos</h3>
    <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="dep_guardar"><input type="hidden" name="dep_id" value="<?= (int)$dep['id'] ?>">
      <?= olimp_html_campos_deportista($dep, $ev, null, true) ?>
      <button class="btn">Guardar</button>
    </form>
    <?php if ($dep['estado'] !== 'aprobado'): ?><form method="post" style="margin-top:8px" onsubmit="return confirm('¿Eliminar a esta persona?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="dep_borrar"><input type="hidden" name="dep_id" value="<?= (int)$dep['id'] ?>"><button class="btn btn-sec btn-mini">🗑 Eliminar</button></form><?php endif; ?>
    <?php endif; ?>
  </div>
  <?php elseif ($nuevo && $puede): ?>
  <div class="tarjeta" id="dep">
    <p><a href="<?= e($bp) ?>">← Todos mis deportistas</a></p>
    <h2 style="margin-top:0">+ Registrar deportista</h2>
    <form method="post" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="dep_guardar"><input type="hidden" name="dep_id" value="0">
      <?= olimp_html_campos_deportista(null, $ev, null, true) ?>
      <?= html_autorizacion('tercero', 'su participación en ' . $ev['nombre'], $base . '&v=privacidad') ?>
      <button class="btn btn-grande">Registrar</button>
    </form>
  </div>
  <?php else: ?>
  <div class="fila-titulo"><h2>👥 Mis deportistas (<?= count($deps) ?>)</h2><?php if ($puede && $cfg['insc_abierta']): ?><a class="btn" href="<?= e($bp . '&nuevo=1#dep') ?>">+ Registrar deportista</a><?php endif; ?></div>
  <?php if (!$deps): ?><p class="suave">Todavía no registró a nadie. Toque «+ Registrar deportista»: nombre, cédula, fecha de nacimiento y los documentos que pida la organización. Después lo inscribe en cada disciplina.</p><?php endif; ?>
  <?php if ($deps): ?><div class="olimp-lista-dep">
    <?php foreach ($deps as $x): $ins = olimp_inscripciones_dep((int)$x['id']); ?>
      <div><a href="<?= e($bp . '&dep=' . (int)$x['id'] . '#dep') ?>"><b><?= e(olimp_nombre_dep($x)) ?></b></a>
        <span class="etiqueta<?= $x['estado'] === 'aprobado' ? '' : ($x['estado'] === 'rechazado' ? ' roja' : ' gris') ?>"><?= e(olimp_estados_deportista()[$x['estado']]) ?></span> <?= html_estado_requisitos('olimp_deportistas', (int)$x['id'], $mod_req) ?><br>
        <small class="suave"><?= e(implode(' · ', array_map(fn($i) => olimp_texto_inscripcion($i, $discs), $ins))) ?: 'sin disciplinas' ?></small></div>
    <?php endforeach; ?>
  </div><?php endif; ?>
  <h2>📅 Disciplinas del evento</h2>
  <?= olimp_html_programa($discs) ?>
  <?php endif;
    pie_publico();
    return;
}

/* ============================================================
 * PÁGINA PÚBLICA DEL EVENTO
 * ============================================================ */
$res = olimp_tabla($ev);
$discs = $res['discs'];
$vistas = ['medallero' => '🥇 Medallero', 'resultados' => '🏅 Resultados', 'programa' => '📅 Programa', 'delegaciones' => '🏢 Delegaciones'];
$vo = isset($vistas[get('v')]) ? get('v') : 'medallero';
$url_disc = function (array $d) use ($liga, $base): string {
    $t = olimp_torneo($d);
    return $base . '&t=' . (int)$t['id'];
};
cabecera_publica($ev['nombre'] . ' · ' . $liga['nombre'], $liga);
echo olimp_css(); ?>
<div class="olimp-cab">
  <?= olimp_html_logo($ev) ?>
  <div style="flex:1;min-width:220px">
    <h1 style="margin:0"><?= e($ev['nombre']) ?></h1>
    <p class="suave" style="margin:2px 0"><?= $ev['fecha_ini'] !== '' ? '📅 ' . e(fecha_bonita($ev['fecha_ini'])) . ($ev['fecha_fin'] !== '' && $ev['fecha_fin'] !== $ev['fecha_ini'] ? ' al ' . e(fecha_bonita($ev['fecha_fin'])) : '') : '' ?><?= $ev['sede'] !== '' ? ' · 📍 ' . e($ev['sede']) : '' ?> · <?= count($discs) ?> disciplinas · <?= count($res['dels']) ?> delegaciones</p>
  </div>
</div>
<?php if ($ev['descripcion'] !== ''): ?><p><?= nl2br(e($ev['descripcion'])) ?></p><?php endif; ?>
<nav class="pestanas">
  <?php foreach ($vistas as $k => $t): ?><a href="<?= e($bo . '&v=' . $k) ?>" class="<?= $vo === $k ? 'activa' : '' ?>"><?= e($t) ?></a><?php endforeach; ?>
</nav>
<?php if ($vo === 'medallero'): ?>
  <?= olimp_html_tabla($res, $bo . '&v=delegaciones&d=') ?>
  <?php $term = array_values(array_filter($res['conjuntos'], fn($c) => $c['terminada'])); if ($term): ?>
    <h2>Últimos resultados</h2>
    <?= olimp_html_podios(['conjuntos' => array_slice(array_reverse($term), 0, 6)] + $res) ?>
  <?php endif; ?>
<?php elseif ($vo === 'resultados'): ?>
  <form method="get" class="acciones" style="margin:8px 0"><input type="hidden" name="l" value="<?= e($liga['slug']) ?>"><input type="hidden" name="o" value="<?= $evid ?>"><input type="hidden" name="v" value="resultados">
    <select name="d" onchange="this.form.submit()" aria-label="Disciplina"><option value="0">Todas las disciplinas</option><?php foreach ($discs as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)get('d') === (int)$d['id'] ? 'selected' : '' ?>><?= e(olimp_nombre_disciplina($d)) ?></option><?php endforeach; ?></select></form>
  <?= olimp_html_podios($res, (int)get('d') ?: null) ?>
<?php elseif ($vo === 'programa'): ?>
  <?= olimp_html_programa($discs, $url_disc) ?>
<?php else:
    $dsel = (int)get('d') ? ($res['dels'][(int)get('d')] ?? null) : null;
    if ($dsel): $fila = null; foreach ($res['tabla'] as $x) if ((int)$x['del']['id'] === (int)$dsel['id']) $fila = $x; ?>
  <p><a href="<?= e($bo . '&v=delegaciones') ?>">← Delegaciones</a></p>
  <div class="olimp-cab"><?= olimp_html_logo($dsel) ?><h2 style="margin:0"><?= e($dsel['nombre']) ?></h2></div>
  <?php if ($fila): ?><div class="olimp-kpis"><div><b><?= $fila['pos'] ?>.º</b>puesto</div><div><b><?= olimp_num($fila['puntos']) ?></b>puntos</div><div><b>🥇 <?= $fila['oro'] ?></b>oros</div><div><b>🥈 <?= $fila['plata'] ?></b>platas</div><div><b>🥉 <?= $fila['bronce'] ?></b>bronces</div></div><?php endif; ?>
  <?= olimp_html_podios($res, null, (int)$dsel['id']) ?>
    <?php else: ?>
  <div class="rejilla-2" style="grid-template-columns:repeat(auto-fill,minmax(240px,1fr))">
    <?php foreach ($res['tabla'] as $x): ?>
      <a class="tarjeta partido-link olimp-cab" style="margin:0;padding:12px;text-decoration:none;color:inherit" href="<?= e($bo . '&v=delegaciones&d=' . (int)$x['del']['id']) ?>"><?= olimp_html_logo($x['del']) ?><span><b><?= e($x['del']['nombre']) ?></b><br><small>🥇 <?= $x['oro'] ?> · 🥈 <?= $x['plata'] ?> · 🥉 <?= $x['bronce'] ?> · <?= olimp_num($x['puntos']) ?> pts</small></span></a>
    <?php endforeach; ?>
  </div>
    <?php endif;
endif;
pie_publico();
