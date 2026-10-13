<?php
/* PROGRAMACIÓN de un campeonato: automática (con vista previa) o a mano, por jornada */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$t = torneo_de_liga((int)get('id'), $lid);
if (es_individual($t)) ir(url_admin_individual($t));
$tid = (int)$t['id'];
$s = get('s') === 'manual' ? 'manual' : 'auto';
$url = 'admin.php?p=programar&id=' . $tid;
$bloques = bloques_programables($tid);
$canchas = filas('SELECT * FROM canchas WHERE liga_id = ? AND activa = 1 ORDER BY orden, nombre', [$lid]);
$arbitros = filas('SELECT * FROM arbitros WHERE liga_id = ? AND activo = 1 ORDER BY nombre', [$lid]);

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'proponer') {
        $cn = array_values(array_filter(array_map('trim', (array)($_POST['canchas'] ?? [])), fn($c) => $c !== ''));
        foreach (array_filter(array_map('trim', explode(',', post('otras_canchas')))) as $c) {
            $c = mb_substr($c, 0, 60);
            $cn[] = $c;
            if (!fila('SELECT id FROM canchas WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $c])) q('INSERT INTO canchas (liga_id, nombre) VALUES (?, ?)', [$lid, $c]);
        }
        $o = [
            'bloques' => array_values(array_intersect((array)($_POST['bloques'] ?? []), array_keys($bloques))),
            'fecha' => post('fecha'), 'dias_entre' => entero(post('dias_entre'), 0, 60), 'canchas' => array_values(array_unique($cn)),
            'hora_desde' => hora_valida(post('hora_desde')) ?: '08:00', 'hora_hasta' => hora_valida(post('hora_hasta')) ?: '16:00',
            'duracion' => entero(post('duracion'), 20, 300), 'incluir_programados' => post('incluir_programados') === '1',
            'asignar_arbitros' => post('asignar_arbitros') === '1', 'arbitros' => array_map('intval', (array)($_POST['arbitros'] ?? [])),
            'evitar_repetir' => post('evitar_repetir') === '1',
        ];
        $prop = proponer_programacion($t, $o);
        $_SESSION['prog'][$tid] = ['o' => $o, 'filas' => array_map(fn($f) => ['id' => (int)$f['partido']['id'], 'fecha' => $f['fecha'], 'hora' => $f['hora'], 'cancha' => $f['cancha'],
            'arbitros' => array_map(fn($a) => $a[0], $f['arbitros'])], $prop['filas']), 'avisos' => $prop['avisos']];
        ir($url . '&ver=1');
    }
    if ($accion === 'guardar_propuesta') {
        $pr = $_SESSION['prog'][$tid] ?? null;
        if (!$pr) throw new RuntimeException('La propuesta ya no está disponible. Vuelva a calcularla.');
        $ids = [];
        $pdo = db();
        $pdo->beginTransaction();
        foreach ($pr['filas'] as $f) {
            if ($f['hora'] === '') continue;
            $p = fila('SELECT * FROM partidos WHERE id = ? AND torneo_id = ?', [$f['id'], $tid]);
            if (!$p || in_array($p['estado'], ['jugado', 'wo', 'en_vivo'], true)) continue;
            if ($pr['o']['asignar_arbitros']) {
                $a = $f['arbitros'] + ['arbitro_id' => null, 'asistente1_id' => null, 'asistente2_id' => null, 'cuarto_id' => null];
                q('UPDATE partidos SET fecha = ?, hora = ?, cancha = ?, arbitro_id = ?, asistente1_id = ?, asistente2_id = ?, cuarto_id = ? WHERE id = ?',
                    [$f['fecha'], $f['hora'], $f['cancha'], $a['arbitro_id'], $a['asistente1_id'], $a['asistente2_id'], $a['cuarto_id'], $f['id']]);
            } else {
                q('UPDATE partidos SET fecha = ?, hora = ?, cancha = ? WHERE id = ?', [$f['fecha'], $f['hora'], $f['cancha'], $f['id']]);
            }
            $ids[] = (int)$f['id'];
        }
        $pdo->commit();
        unset($_SESSION['prog'][$tid]);
        bitacora_evento('programacion', 'Programó automáticamente ' . count($ids) . ' partidos de ' . $t['nombre'], $lid, $tid);
        $n_tg = post('avisar') === '1' ? aviso_programacion($tid, $ids) : 0;
        flash('Programación guardada: ' . count($ids) . ' partidos.' . ($n_tg ? ' Se enviaron ' . $n_tg . ' avisos por Telegram.' : ''));
        ir('admin.php?p=torneo&id=' . $tid . '&s=fixture');
    }
    if ($accion === 'manual') {
        $ids = [];
        $datos = (array)($_POST['p'] ?? []);
        $avisos = [];
        $pdo = db();
        $pdo->beginTransaction();
        foreach ($datos as $pid => $d) {
            $p = fila('SELECT * FROM partidos WHERE id = ? AND torneo_id = ?', [(int)$pid, $tid]);
            if (!$p || !is_array($d)) continue;
            $fecha = ($d['fecha'] ?? '') !== '' && strtotime($d['fecha']) ? date('Y-m-d', strtotime($d['fecha'])) : '';
            $hora = hora_valida((string)($d['hora'] ?? ''));
            $roles = roles_arbitro(num_arbitros_partido($p));
            $arb = [];
            foreach (['arbitro_id', 'asistente1_id', 'asistente2_id', 'cuarto_id'] as $c) {
                $id = isset($roles[$c]) ? (int)($d[$c] ?? 0) : 0;
                $arb[$c] = $id && fila('SELECT id FROM arbitros WHERE id = ? AND liga_id = ?', [$id, $lid]) ? $id : null;
            }
            $asig = array_filter($arb);
            if (count($asig) !== count(array_unique($asig))) throw new RuntimeException('En un mismo partido un árbitro no puede tener dos funciones (' . valor('SELECT nombre FROM equipos WHERE id = ?', [$p['local_id']]) . ').');
            q('UPDATE partidos SET fecha = ?, hora = ?, cancha = ?, arbitro_id = ?, asistente1_id = ?, asistente2_id = ?, cuarto_id = ? WHERE id = ?',
                [$fecha, $hora, mb_substr((string)($d['cancha'] ?? ''), 0, 60), $arb['arbitro_id'], $arb['asistente1_id'], $arb['asistente2_id'], $arb['cuarto_id'], $p['id']]);
            $ids[] = (int)$p['id'];
        }
        $pdo->commit();
        // Revisión de choques (solo aviso)
        $dur = duracion_sugerida($t);
        $lista = $ids ? filas('SELECT p.*, el.nombre AS ln, ev.nombre AS vn FROM partidos p JOIN equipos el ON el.id = p.local_id JOIN equipos ev ON ev.id = p.visitante_id
                              JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ? AND p.fecha <> \'\' AND p.hora <> \'\' AND p.estado NOT IN (\'jugado\',\'wo\')', [$lid]) : [];
        foreach ($lista as $i => $a) {
            foreach ($lista as $k => $b) {
                if ($k <= $i || $a['fecha'] !== $b['fecha'] || abs(minutos_de_hora($a['hora']) - minutos_de_hora($b['hora'])) >= $dur) continue;
                if (!in_array((int)$a['id'], $ids, true) && !in_array((int)$b['id'], $ids, true)) continue;
                $ra = array_filter([$a['arbitro_id'], $a['asistente1_id'], $a['asistente2_id'], $a['cuarto_id']]);
                $rb = array_filter([$b['arbitro_id'], $b['asistente1_id'], $b['asistente2_id'], $b['cuarto_id']]);
                if (array_intersect($ra, $rb)) $avisos[] = 'Un mismo árbitro está en dos partidos a la vez: ' . $a['ln'] . ' vs ' . $a['vn'] . ' y ' . $b['ln'] . ' vs ' . $b['vn'] . ' (' . $a['fecha'] . ').';
                if ($a['cancha'] !== '' && $a['cancha'] === $b['cancha']) $avisos[] = 'Dos partidos en la misma cancha (' . $a['cancha'] . ') al mismo tiempo: ' . $a['hora'] . ' y ' . $b['hora'] . ' del ' . $a['fecha'] . '.';
                if (array_intersect([$a['local_id'], $a['visitante_id']], [$b['local_id'], $b['visitante_id']])) $avisos[] = 'Un equipo juega dos partidos a la vez el ' . $a['fecha'] . '.';
            }
        }
        bitacora_evento('programacion', 'Programó a mano ' . count($ids) . ' partidos de ' . $t['nombre'], $lid, $tid);
        $n_tg = post('avisar') === '1' ? aviso_programacion($tid, $ids) : 0;
        flash('Programación guardada.' . ($n_tg ? ' Se enviaron ' . $n_tg . ' avisos por Telegram.' : ''));
        foreach (array_unique($avisos) as $av) flash('⚠️ ' . $av, 'error');
        ir($url . '&s=manual&b=' . rawurlencode(post('bloque')));
    }
    ir($url);
}

cabecera_admin('Programación · ' . $t['nombre']);
?>
<p><a href="admin.php?p=torneo&amp;id=<?= $tid ?>&amp;s=fixture">← <?= e($t['nombre']) ?></a></p>
<h1>📅 Programación de partidos</h1>
<nav class="pestanas">
  <a href="<?= e($url) ?>" class="<?= $s === 'auto' ? 'activa' : '' ?>">⚙ Automática</a>
  <a href="<?= e($url . '&s=manual') ?>" class="<?= $s === 'manual' ? 'activa' : '' ?>">✍ A mano</a>
</nav>
<?php if (!$bloques): ?><div class="tarjeta">Primero genere el fixture del campeonato.</div><?php pie_admin(); return; endif; ?>

<?php if ($s === 'auto' && get('ver') === '1' && ($pr = $_SESSION['prog'][$tid] ?? null)):
    $nombres_arb = array_column($arbitros, 'nombre', 'id');
?>
  <div class="tarjeta">
    <h2 style="margin-top:0">Propuesta (todavía no se guardó)</h2>
    <?php foreach ($pr['avisos'] as $av): ?><div class="aviso aviso-error"><?= e($av) ?></div><?php endforeach; ?>
    <div class="tabla-scroll"><table>
      <tr><th>Fecha</th><th>Hora</th><th>Cancha</th><th>Partido</th><th>Árbitros</th></tr>
      <?php foreach ($pr['filas'] as $f): $p = partido_completo($f['id']); if (!$p) continue; ?>
        <tr class="<?= $f['hora'] === '' ? 'dif' : '' ?>"><td><?= e(fecha_bonita($f['fecha'])) ?></td><td><b><?= e($f['hora'] ?: '—') ?></b></td><td><?= e($f['cancha'] ?: '—') ?></td>
          <td><?= e($p['local_nombre'] . ' vs ' . $p['visitante_nombre']) ?> <small class="suave"><?= $p['fase'] === 'regular' ? 'J' . (int)$p['jornada'] : e(nombre_fase($p['fase'])) ?></small></td>
          <td style="white-space:normal"><?= $f['arbitros'] ? e(implode(', ', array_map(fn($id) => $nombres_arb[$id] ?? '?', $f['arbitros']))) : '<span class="suave">—</span>' ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <form method="post" style="margin-top:14px">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="guardar_propuesta">
      <?php if (tg_liga_activa($liga)): ?><label class="check" style="font-weight:400;margin-bottom:10px"><input type="checkbox" name="avisar" value="1" checked> Avisar por Telegram (canal de la liga, delegados y árbitros)</label><?php endif; ?>
      <div class="acciones"><button class="btn">✓ Guardar esta programación</button> <a class="btn btn-sec" href="<?= e($url) ?>">Cambiar datos</a></div>
    </form>
    <p class="ayuda">Después de guardar puede ajustar cualquier partido en la pestaña "A mano".</p>
  </div>
<?php elseif ($s === 'auto'):
    $o = $_SESSION['prog'][$tid]['o'] ?? [];
    $pend = array_filter($bloques, fn($b) => $b['sin_prog'] > 0 && $b['jugados'] < $b['n']);
    $sel = $o['bloques'] ?? array_slice(array_keys($pend), 0, 1);
    $sab = date('Y-m-d', strtotime('next saturday'));
?>
  <form method="post" class="tarjeta">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="proponer">
    <p class="suave" style="margin-top:0">El sistema reparte los partidos en las canchas y horarios que usted indique, sin que un equipo o un árbitro tenga dos partidos a la vez, alternando a los equipos entre horarios temprano y tarde y repartiendo los partidos entre los árbitros. Primero ve la propuesta y luego decide si la guarda.</p>
    <h3>1. ¿Qué va a programar?</h3>
    <div class="bloques">
      <?php foreach ($bloques as $b): ?>
        <label class="check" style="font-weight:400"><input type="checkbox" name="bloques[]" value="<?= e($b['clave']) ?>" <?= in_array($b['clave'], $sel, true) ? 'checked' : '' ?> <?= $b['jugados'] >= $b['n'] ? 'disabled' : '' ?>>
          <?= e($b['titulo']) ?> <small class="suave">(<?= $b['n'] ?> partidos<?= $b['sin_prog'] ? ', ' . $b['sin_prog'] . ' sin programar' : '' ?><?= $b['jugados'] ? ', ' . $b['jugados'] . ' jugados' : '' ?>)</small></label>
      <?php endforeach; ?>
    </div>
    <label class="check" style="font-weight:400;margin-top:8px"><input type="checkbox" name="incluir_programados" value="1" <?= !empty($o['incluir_programados']) ? 'checked' : '' ?>> Reprogramar también los partidos que ya tienen fecha y hora</label>
    <h3>2. ¿Cuándo y dónde?</h3>
    <div class="campos">
      <div><label>Fecha (de la primera jornada elegida)</label><input type="date" name="fecha" required value="<?= e($o['fecha'] ?? $sab) ?>"></div>
      <div><label>Días entre jornadas</label><input type="number" name="dias_entre" min="0" max="60" value="<?= (int)($o['dias_entre'] ?? 7) ?>"><div class="ayuda">Si elige varias jornadas: 7 = una por semana.</div></div>
      <div><label>Primer partido a las</label><input type="time" name="hora_desde" value="<?= e($o['hora_desde'] ?? '08:00') ?>"></div>
      <div><label>Último partido a las</label><input type="time" name="hora_hasta" value="<?= e($o['hora_hasta'] ?? '16:00') ?>"></div>
      <div><label>Minutos por turno</label><input type="number" name="duracion" min="20" max="300" value="<?= (int)($o['duracion'] ?? duracion_sugerida($t)) ?>"><div class="ayuda">Juego + descanso + margen. Sugerido: <?= duracion_sugerida($t) ?>.</div></div>
    </div>
    <label>Canchas</label>
    <div class="bloques">
      <?php foreach ($canchas as $c): ?><label class="check" style="font-weight:400"><input type="checkbox" name="canchas[]" value="<?= e($c['nombre']) ?>" <?= !$o || in_array($c['nombre'], $o['canchas'] ?? [], true) ? 'checked' : '' ?>> <?= e($c['nombre']) ?></label><?php endforeach; ?>
    </div>
    <p><input type="text" name="otras_canchas" maxlength="200" placeholder="<?= $canchas ? 'Otra cancha (opcional)' : 'Escriba las canchas separadas por coma: Cancha 1, Cancha 2' ?>"></p>
    <p class="ayuda">Las canchas se administran en <a href="admin.php?p=config#canchas">Parámetros</a>.</p>
    <h3>3. Árbitros</h3>
    <label class="check"><input type="checkbox" name="asignar_arbitros" value="1" <?= !$o || !empty($o['asignar_arbitros']) ? 'checked' : '' ?>> Asignar árbitros automáticamente (<?= (int)$t['num_arbitros'] ?> por partido<?= (int)$t['num_arbitros_final'] ? ', ' . (int)$t['num_arbitros_final'] . ' en eliminatorias' : '' ?>)</label>
    <?php if (!$arbitros): ?><p class="aviso aviso-info">No hay árbitros registrados. Agréguelos en <a href="admin.php?p=arbitros">Árbitros</a>.</p><?php else: ?>
    <p class="suave" style="margin:.4rem 0">Desmarque a los que no están disponibles en esa fecha:</p>
    <div class="bloques">
      <?php foreach ($arbitros as $a): ?><label class="check" style="font-weight:400"><input type="checkbox" name="arbitros[]" value="<?= (int)$a['id'] ?>" <?= !$o || in_array((int)$a['id'], $o['arbitros'] ?? [], true) ? 'checked' : '' ?>> <?= e($a['nombre']) ?><?= $a['categoria'] !== '' ? ' <small class="suave">(' . e($a['categoria']) . ')</small>' : '' ?></label><?php endforeach; ?>
    </div>
    <label class="check" style="font-weight:400;margin-top:8px"><input type="checkbox" name="evitar_repetir" value="1" <?= !$o || !empty($o['evitar_repetir']) ? 'checked' : '' ?>> Evitar que un árbitro pite al mismo equipo dos jornadas seguidas</label>
    <?php endif; ?>
    <p style="margin-top:16px"><button class="btn btn-grande">Ver propuesta</button></p>
  </form>

<?php else: /* manual */
    $b = get('b');
    if (!isset($bloques[$b])) {
        $pend = array_filter($bloques, fn($x) => $x['jugados'] < $x['n']);
        $b = array_key_first($pend) ?? array_key_first($bloques);
    }
    $ps = partidos_de_bloque($tid, $b);
    $nombres_canchas = array_column($canchas, 'nombre');
?>
  <form method="get" class="acciones" style="margin-bottom:12px"><input type="hidden" name="p" value="programar"><input type="hidden" name="id" value="<?= $tid ?>"><input type="hidden" name="s" value="manual">
    <label style="margin:0">Jornada / fase:</label>
    <select name="b" onchange="this.form.submit()" style="width:auto"><?php foreach ($bloques as $k => $x): ?><option value="<?= e($k) ?>" <?= $k === $b ? 'selected' : '' ?>><?= e($x['titulo']) ?><?= $x['sin_prog'] ? ' (' . $x['sin_prog'] . ' sin programar)' : '' ?></option><?php endforeach; ?></select>
  </form>
  <form method="post" class="tarjeta">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="manual"><input type="hidden" name="bloque" value="<?= e($b) ?>">
    <datalist id="lista-canchas"><?php foreach ($nombres_canchas as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
    <div class="tabla-scroll"><table class="tabla-prog">
      <tr><th>Partido</th><th>Fecha</th><th>Hora</th><th>Cancha</th><th>Árbitros</th></tr>
      <?php foreach ($ps as $p): $cerr = in_array($p['estado'], ['jugado', 'wo', 'en_vivo'], true); $pc = partido_completo((int)$p['id']); ?>
        <tr><td style="white-space:normal;min-width:150px"><b><?= e($p['local_nombre']) ?></b> vs <b><?= e($p['visitante_nombre']) ?></b><?= $cerr ? '<br><span class="etiqueta gris">' . e(nombre_estado_partido($p['estado'])) . '</span>' : '' ?></td>
          <?php if ($cerr): ?><td><?= e($p['fecha']) ?></td><td><?= e($p['hora']) ?></td><td><?= e($p['cancha']) ?></td><td><?= e(implode(', ', array_filter(array_map(fn($x) => $x[3], arbitros_del_partido($pc))))) ?></td>
          <?php else: ?>
          <td><input type="date" name="p[<?= (int)$p['id'] ?>][fecha]" value="<?= e($p['fecha']) ?>"></td>
          <td><input type="time" name="p[<?= (int)$p['id'] ?>][hora]" value="<?= e($p['hora']) ?>"></td>
          <td><input type="text" name="p[<?= (int)$p['id'] ?>][cancha]" list="lista-canchas" maxlength="60" value="<?= e($p['cancha']) ?>" style="min-width:110px"></td>
          <td style="min-width:200px"><?php foreach (roles_arbitro(num_arbitros_partido($p)) as $campo => $etq): ?>
            <select name="p[<?= (int)$p['id'] ?>][<?= $campo ?>]" title="<?= e($etq) ?>" style="margin-bottom:4px"><option value=""><?= e($etq) ?>: —</option><?php foreach ($arbitros as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$p[$campo] === (int)$a['id'] ? 'selected' : '' ?>><?= e($etq . ': ' . $a['nombre']) ?></option><?php endforeach; ?></select>
          <?php endforeach; ?></td>
          <?php endif; ?></tr>
      <?php endforeach; ?>
    </table></div>
    <?php if (tg_liga_activa($liga)): ?><label class="check" style="font-weight:400;margin:10px 0"><input type="checkbox" name="avisar" value="1"> Avisar por Telegram (canal, delegados y árbitros)</label><?php endif; ?>
    <button class="btn">Guardar programación</button>
    <p class="ayuda">Al guardar, el sistema le avisa si hay un árbitro, una cancha o un equipo con dos partidos a la vez.</p>
  </form>
<?php endif;
pie_admin();
