<?php
/* Administración de un campeonato: equipos, sorteo, fixture, tabla, disciplina */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$t = torneo_de_liga((int)get('id'), $lid);
$tid = (int)$t['id'];
if (es_individual($t)) ir(url_admin_individual($t)); // (v22/v25) carreras, duelos y bolos tienen su propio panel
$s = get('s', 'equipos');
if (!in_array($s, ['equipos', 'fixture', 'tabla', 'disciplina', 'datos'], true)) $s = 'equipos';
$url = 'admin.php?p=torneo&id=' . $tid;

if (es_post()) {
    $accion = post('accion');
    $jugados = (int)valor("SELECT COUNT(*) FROM partidos WHERE torneo_id = ? AND estado IN ('jugado','wo')", [$tid]);

    if ($accion === 'datos') {
        $formato = post('formato') === 'grupos' ? 'grupos' : 'liga';
        $estado = in_array(post('estado'), ['inscripcion', 'sorteado', 'en_juego', 'finalizado'], true) ? post('estado') : $t['estado'];
        if (post('nombre') === '') throw new RuntimeException('El nombre no puede quedar vacío.');
        $datos = array_merge([
            'nombre' => mb_substr(post('nombre'), 0, 100), 'temporada' => mb_substr(post('temporada'), 0, 30), 'formato' => $formato,
            'num_grupos' => $formato === 'grupos' ? entero(post('num_grupos'), 2, 16) : 1, 'vueltas' => entero(post('vueltas'), 1, 2), 'estado' => $estado,
            'jugadores_hasta' => post('jugadores_hasta') !== '' && strtotime(post('jugadores_hasta')) ? date('Y-m-d', strtotime(post('jugadores_hasta'))) : '',
            'equipos_hasta' => post('equipos_hasta') !== '' && strtotime(post('equipos_hasta')) ? date('Y-m-d', strtotime(post('equipos_hasta'))) : '',
        ], leer_reglas_post($t), leer_categoria_post());
        $ins = array_map('intval', array_column(filas('SELECT equipo_id FROM torneo_equipos WHERE torneo_id = ?', [$tid]), 'equipo_id'));
        $datos['campeon_id'] = in_array((int)post('campeon_id'), $ins, true) ? (int)post('campeon_id') : null;
        $datos['subcampeon_id'] = in_array((int)post('subcampeon_id'), $ins, true) ? (int)post('subcampeon_id') : null;
        if ($estado === 'finalizado' && !$datos['campeon_id']) {
            [$datos['campeon_id'], $sub] = campeon_sugerido($tid);
            $datos['subcampeon_id'] = $datos['subcampeon_id'] ?: $sub;
        }
        $datos['id'] = $tid;
        q('UPDATE torneos SET ' . implode(', ', array_map(fn($k) => "$k = :$k", array_keys(array_diff_key($datos, ['id' => 1])))) . ' WHERE id = :id', $datos);
        // (v10) Dirección web propia del campeonato
        if (post('slug') !== $t['slug']) guardar_slug_torneo(array_merge($t, ['nombre' => $datos['nombre'], 'temporada' => $datos['temporada']]), post('slug'));
        flash('Configuración y reglas del campeonato guardadas. Tabla, suspensiones y planillas ya usan las nuevas reglas.');
        ir($url . '&s=datos');
    }

    if ($accion === 'eliminar') {
        if (post('confirmar') !== $t['nombre']) throw new RuntimeException('Escriba el nombre exacto del campeonato para eliminarlo.');
        q('DELETE FROM torneos WHERE id = ?', [$tid]);
        flash('Campeonato eliminado.');
        ir('admin.php?p=torneos');
    }

    if ($accion === 'inscripcion') {
        $marcados = array_map('intval', (array)($_POST['equipos'] ?? []));
        $validos = array_map('intval', array_column(filas('SELECT id FROM equipos WHERE liga_id = ?', [$lid]), 'id'));
        $marcados = array_values(array_intersect($marcados, $validos));
        $actuales = array_map('intval', array_column(filas('SELECT equipo_id FROM torneo_equipos WHERE torneo_id = ?', [$tid]), 'equipo_id'));
        $quitar = array_diff($actuales, $marcados);
        foreach ($quitar as $eq) {
            if ((int)valor('SELECT COUNT(*) FROM partidos WHERE torneo_id = ? AND (local_id = ? OR visitante_id = ?)', [$tid, $eq, $eq]) > 0) {
                throw new RuntimeException('No se puede retirar a ' . valor('SELECT nombre FROM equipos WHERE id = ?', [$eq]) . ' porque ya tiene partidos en el fixture. Regenere el fixture o borre sus partidos primero.');
            }
        }
        foreach ($quitar as $eq) q('DELETE FROM torneo_equipos WHERE torneo_id = ? AND equipo_id = ?', [$tid, $eq]);
        $nuevos = array_diff($marcados, $actuales);
        if ($nuevos) verificar_equipos_torneo($liga, $tid, count($marcados)); // (v12) equipos por torneo según el plan
        foreach ($nuevos as $eq) q('INSERT INTO torneo_equipos (torneo_id, equipo_id) VALUES (?,?)', [$tid, $eq]);
        flash('Inscripción actualizada: ' . count($marcados) . ' equipos.');
        if (torneo_con_categoria($t)) {
            $prob = array_filter(resumen_categoria_torneo($t), fn($r) => $r['excede'] || $r['fuera'] || $r['sin_fecha']);
            if ($prob) flash('Revise la categoría: ' . count($prob) . ' equipo(s) tienen jugadores fuera de edad, sin fecha de nacimiento o más pasados de los permitidos (vea el detalle abajo).', 'error');
        }
        ir($url);
    }

    if ($accion === 'grupos_manual') {
        $letras = letras_grupos(max(1, (int)$t['num_grupos']));
        foreach ((array)($_POST['grupo'] ?? []) as $eq => $g) {
            $g = in_array($g, $letras, true) ? $g : '';
            q('UPDATE torneo_equipos SET grupo = ? WHERE torneo_id = ? AND equipo_id = ?', [$g, $tid, (int)$eq]);
        }
        flash('Grupos guardados. Si ya había fixture, vuelva a generarlo.');
        ir($url);
    }

    if ($accion === 'sorteo') {
        if ($jugados > 0) throw new RuntimeException('No se puede sortear: el campeonato ya tiene partidos jugados.');
        if ((int)valor('SELECT COUNT(*) FROM torneo_equipos WHERE torneo_id = ?', [$tid]) < 2) throw new RuntimeException('Inscriba al menos 2 equipos antes del sorteo.');
        realizar_sorteo($tid);
        $n = 0;
        if (post('con_fixture') === '1') {
            $n = generar_fixture($tid, post('fecha_inicio'), entero(post('dias'), 1, 60), post('hora'), mb_substr(post('cancha'), 0, 60));
        }
        flash('¡Sorteo realizado!' . ($n ? " Se generaron $n partidos." : ''));
        ir($url . ($n ? '&s=fixture' : ''));
    }

    if ($accion === 'fixture') {
        $n = generar_fixture($tid, post('fecha_inicio'), entero(post('dias'), 1, 60), post('hora'), mb_substr(post('cancha'), 0, 60));
        flash("Fixture generado: $n partidos.");
        ir($url . '&s=fixture');
    }

    if ($accion === 'llaves') {
        $n = crear_llaves($tid, entero(post('por_grupo'), 1, 16), post('tercer') === '1', post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : '',
            hora_valida(post('hora')), mb_substr(post('cancha'), 0, 60));
        bitacora_evento('llaves', 'Generó las llaves eliminatorias de ' . $t['nombre'] . ' (' . $n . ' partidos en la primera ronda)', $lid, $tid);
        flash('¡Llaves creadas! ' . $n . ' partidos en la primera ronda. Cuando se jueguen, los ganadores pasan solos a la siguiente ronda.');
        ir($url . '&s=fixture#llaves');
    }
    // (v29) Fase final prevista para el esquema del campeonato (plan Profesional)
    if ($accion === 'plan_final') {
        if (($motivo = fixture_pro_motivo($liga)) !== '') throw new RuntimeException($motivo);
        if (tiene_llaves($tid)) throw new RuntimeException('Las llaves ya están creadas: el esquema ya muestra la llave real.');
        $ng = max(1, count(array_filter(array_keys(grupos_esquema($t)['grupos']), fn($l) => $l !== '?')));
        $pg = entero(post('por_grupo'), 0, 16);
        if ($pg > 0 && !isset(opciones_clasifican($ng)[$pg])) throw new RuntimeException('Con ' . $ng . ' grupo(s) no se puede armar una llave con ' . $pg . ' clasificado(s) por grupo: deben entrar 2, 4, 8 o 16 equipos.');
        q('UPDATE torneos SET llaves_por_grupo = ?, llaves_tercer = ? WHERE id = ?', [$pg, post('tercer') === '1' ? 1 : 0, $tid]);
        bitacora_evento('llaves', 'Definió la fase final prevista de ' . $t['nombre'] . ': ' . ($pg ? $pg * $ng . ' equipos a la llave' : 'sin fase final'), $lid, $tid);
        flash($pg ? 'Fase final guardada: el esquema ya muestra la llave con ' . ($pg * $ng) . ' equipos.' : 'Listo: el campeonato queda sin fase final (gana el primero de la tabla).');
        ir($url . '&s=fixture#esquema');
    }
    if ($accion === 'borrar_llaves') {
        $n = borrar_llaves($tid);
        bitacora_evento('llaves', 'Borró las llaves eliminatorias de ' . $t['nombre'], $lid, $tid);
        flash('Se borraron ' . $n . ' partidos de las llaves.');
        ir($url . '&s=fixture#llaves');
    }

    if ($accion === 'partido_manual') {
        $l = (int)post('local_id');
        $v = (int)post('visitante_id');
        if ($l === $v) throw new RuntimeException('Elija dos equipos distintos.');
        equipo_de_liga($l, $lid);
        equipo_de_liga($v, $lid);
        $fase = in_array(post('fase'), fases(), true) ? post('fase') : 'regular';
        q('INSERT INTO partidos (torneo_id, fase, grupo, jornada, local_id, visitante_id, num_arbitros, fecha, hora, cancha) VALUES (?,?,?,?,?,?,?,?,?,?)', [
            $tid, $fase, mb_substr(post('grupo'), 0, 3), entero(post('jornada'), 0, 200), $l, $v, entero(post('num_arbitros'), 0, 4) ?: null,
            post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : '',
            preg_match('/^\d{2}:\d{2}$/', post('hora')) ? post('hora') : '', mb_substr(post('cancha'), 0, 60),
        ]);
        flash('Partido agregado.');
        ir($url . '&s=fixture');
    }

    if ($accion === 'sancion') {
        $j = jugador_de_liga((int)post('jugador_id'), $lid);
        $desde = post('desde') !== '' && strtotime(post('desde')) ? date('Y-m-d', strtotime(post('desde'))) : date('Y-m-d');
        q('INSERT INTO sanciones (torneo_id, jugador_id, partidos, motivo, desde, multa) VALUES (?,?,?,?,?,?)', [$tid, $j['id'], entero(post('partidos'), 0, 99), mb_substr(post('motivo'), 0, 200), $desde, max(0, round((float)str_replace(',', '.', post('multa')), 2))]);
        flash('Sanción registrada para ' . nombre_jugador($j) . '.');
        ir($url . '&s=disciplina');
    }

    if ($accion === 'sancion_equipo') {
        $eq = equipo_de_liga((int)post('equipo_id'), $lid);
        $pts = entero(post('puntos'), 0, 99);
        $multa = max(0, round((float)str_replace(',', '.', post('multa')), 2));
        if ($pts === 0 && $multa <= 0) throw new RuntimeException('Indique los puntos a restar o el valor de la multa.');
        $fecha = post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : date('Y-m-d');
        q('INSERT INTO sanciones_equipo (torneo_id, equipo_id, puntos, multa, motivo, fecha) VALUES (?,?,?,?,?,?)', [$tid, $eq['id'], $pts, $multa, mb_substr(post('motivo'), 0, 200), $fecha]);
        flash('Sanción registrada a ' . $eq['nombre'] . ($pts ? " (−$pts puntos en la tabla)" : '') . ($multa > 0 ? ' · multa ' . dinero($multa) : '') . '.');
        ir($url . '&s=disciplina');
    }

    if ($accion === 'quitar_sancion_equipo') {
        q('DELETE FROM sanciones_equipo WHERE id = ? AND torneo_id = ?', [(int)post('id'), $tid]);
        flash('Sanción del equipo eliminada.');
        ir($url . '&s=disciplina');
    }

    if ($accion === 'pagar_multa') {
        $es_eq = post('clase') === 'equipo';
        $sx = $es_eq
            ? fila('SELECT s.*, s.equipo_id AS eq FROM sanciones_equipo s WHERE s.id = ? AND s.torneo_id = ?', [(int)post('id'), $tid])
            : fila('SELECT s.*, j.equipo_id AS eq, j.nombres, j.apellidos FROM sanciones s JOIN jugadores j ON j.id = s.jugador_id WHERE s.id = ? AND s.torneo_id = ?', [(int)post('id'), $tid]);
        if (!$sx || $sx['pagada']) throw new RuntimeException('Multa no encontrada o ya pagada.');
        sincronizar_cargos($tid);
        $mid = registrar_pago($lid, $tid, (int)$sx['eq'], (float)$sx['multa'], 'multa',
            'Pago de multa' . ($es_eq ? '' : ' de ' . trim($sx['nombres'] . ' ' . $sx['apellidos'])) . ($sx['motivo'] ? ': ' . $sx['motivo'] : ''),
            array_key_exists(post('forma_pago'), formas_pago()) ? post('forma_pago') : 'efectivo', '', date('Y-m-d'), usuario()['nombre'], 'pago_multa_' . ($es_eq ? 'e' : 'j') . ':' . $sx['id']);
        q('UPDATE ' . ($es_eq ? 'sanciones_equipo' : 'sanciones') . ' SET pagada = 1 WHERE id = ?', [$sx['id']]);
        flash('Multa pagada y registrada en Finanzas.');
        ir('admin.php?p=finanzas&equipo=' . (int)$sx['eq'] . '&recibo=' . $mid);
    }

    if ($accion === 'quitar_sancion') {
        q('DELETE FROM sanciones WHERE id = ? AND torneo_id = ?', [(int)post('id'), $tid]);
        flash('Sanción eliminada.');
        ir($url . '&s=disciplina');
    }
    ir($url);
}

$inscritos = filas('SELECT e.*, te.grupo, te.orden_sorteo, (SELECT COUNT(*) FROM jugadores j WHERE j.equipo_id = e.id AND j.activo = 1) AS n_jug
                    FROM torneo_equipos te JOIN equipos e ON e.id = te.equipo_id WHERE te.torneo_id = ? ORDER BY te.grupo, te.orden_sorteo, e.nombre', [$tid]);
$todos = filas('SELECT id, nombre, escudo, color FROM equipos WHERE liga_id = ? ORDER BY nombre', [$lid]);
$ids_inscritos = array_map('intval', array_column($inscritos, 'id'));
$n_partidos = (int)valor('SELECT COUNT(*) FROM partidos WHERE torneo_id = ?', [$tid]);
if ($t['slug'] === '') {
    completar_slugs_torneos($lid);
    $t = torneo_de_liga($tid, $lid);
}

cabecera_admin($t['nombre']);
?>
<p><a href="admin.php?p=torneos">← Campeonatos</a></p>
<div class="fila-titulo">
  <h1><?= e($t['nombre']) ?> <small><?= e($t['temporada']) ?></small></h1>
  <a class="btn btn-sec btn-chico" target="_blank" href="<?= e(url_torneo($liga, $t)) ?>">Ver página pública ↗</a>
</div>
<?= html_direcciones_torneo($liga, $t) ?>
<p class="suave" style="margin-top:-6px"><?= e(resumen_reglas($t)) ?> · Calificación del árbitro: <?= e(nombre_calificacion($t['calificar_arbitro'])) ?> · <a href="<?= e($url . '&s=datos') ?>">cambiar reglas</a> · <a href="admin.php?p=torneos&amp;copiar=<?= $tid ?>#copiar">📋 copiar para nueva temporada</a></p>
<?php if (torneo_con_categoria($t)): ?><p style="margin-top:-6px"><span class="etiqueta amarilla">👶 <?= e(texto_categoria($t)) ?></span></p><?php endif; ?>
<nav class="pestanas">
  <?php foreach (['equipos' => '1. Equipos y sorteo', 'fixture' => '2. Fixture y resultados', 'tabla' => '3. Tabla y estadísticas', 'disciplina' => '4. Tarjetas y sanciones', 'datos' => 'Configuración'] as $k => $txt): ?>
    <a href="<?= e($url . '&s=' . $k) ?>" class="<?= $s === $k ? 'activa' : '' ?>"><?= e($txt) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($s === 'equipos'): ?>
  <div class="rejilla-2">
    <div class="tarjeta">
      <h2>Equipos inscritos (<?= count($inscritos) ?>)</h2>
      <form method="post">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="inscripcion">
        <?php if (!$todos): ?><p>Primero cree equipos en la sección <a href="admin.php?p=equipos">Equipos</a>.</p><?php endif; ?>
        <?php foreach ($todos as $e): ?>
          <label class="check" style="font-weight:400;margin-bottom:6px"><input type="checkbox" name="equipos[]" value="<?= (int)$e['id'] ?>" <?= in_array((int)$e['id'], $ids_inscritos, true) ? 'checked' : '' ?>> <?= escudo($e['escudo'], $e['nombre'], $e['color'], 'mini') ?> <?= e($e['nombre']) ?></label>
        <?php endforeach; ?>
        <?php if ($todos): ?><button class="btn btn-sec" type="submit">Guardar inscripción</button><?php endif; ?>
      </form>
      <?php
      $incompletos = array_filter($inscritos, fn($e) => (int)$e['n_jug'] < (int)$t['min_plantilla']);
      if ($incompletos): ?>
        <div class="aviso aviso-info" style="margin-top:14px">Equipos con menos de <?= (int)$t['min_plantilla'] ?> jugadores: <?= e(implode(', ', array_column($incompletos, 'nombre'))) ?></div>
      <?php endif; ?>
    </div>

    <div class="tarjeta">
      <h2>Sorteo</h2>
      <?php if ($t['fecha_sorteo']): ?>
        <p class="suave">Último sorteo: <?= e($t['fecha_sorteo']) ?> · <a target="_blank" href="index.php?l=<?= e($liga['slug']) ?>&amp;t=<?= $tid ?>&amp;v=sorteo">ver sorteo animado (para pantalla o transmisión) ↗</a></p>
      <?php endif; ?>
      <p>El sistema reparte los equipos al azar<?= $t['formato'] === 'grupos' ? ' en ' . (int)$t['num_grupos'] . ' grupos' : '' ?> y puede generar el fixture de una vez.</p>
      <form method="post" onsubmit="return confirm('<?= $n_partidos ? 'Ya existe un fixture. El sorteo cambiará el orden y se volverá a generar. ¿Continuar?' : '¿Realizar el sorteo ahora?' ?>')">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="sorteo">
        <label class="check"><input type="checkbox" name="con_fixture" value="1" checked> Generar el fixture después del sorteo</label>
        <div class="campos" style="margin-top:10px">
          <div><label>Fecha de la 1ª jornada</label><input type="date" name="fecha_inicio"><div class="ayuda">Opcional.</div></div>
          <div><label>Días entre jornadas</label><input type="number" name="dias" min="1" max="60" value="7"></div>
          <div><label>Hora por defecto</label><input type="time" name="hora"></div>
          <div><label>Cancha por defecto</label><input type="text" name="cancha" maxlength="60"></div>
        </div>
        <button class="btn" type="submit">🎲 Realizar sorteo</button>
      </form>
    </div>
  </div>

  <?php if (torneo_con_categoria($t) && $inscritos): $rc = resumen_categoria_torneo($t); ?>
  <div class="tarjeta">
    <h2>👶 Control de categoría</h2>
    <p class="suave"><?= e(texto_categoria($t)) ?></p>
    <div class="tabla-scroll"><table>
      <tr><th>Equipo</th><th class="num">Pasados</th><th>Fuera de edad (no pueden jugar)</th><th>Sin fecha de nacimiento</th></tr>
      <?php foreach ($rc as $r): ?>
        <tr class="<?= $r['excede'] || $r['fuera'] ? 'dif' : '' ?>"><td><a href="admin.php?p=equipo&amp;id=<?= (int)$r['equipo']['id'] ?>"><?= e($r['equipo']['nombre']) ?></a></td>
          <td class="num"><?= count($r['pasados']) ?>/<?= (int)$t['pasados_plantilla'] ?><?= $r['excede'] ? ' ⚠️' : '' ?></td>
          <td style="white-space:normal"><?= e(implode(', ', array_map(fn($j) => nombre_jugador($j) . ' (' . $j['sit']['edad'] . ')', $r['fuera']))) ?: '—' ?></td>
          <td style="white-space:normal"><?= e(implode(', ', array_map('nombre_jugador', $r['sin_fecha']))) ?: '—' ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <p class="ayuda">Los pasados de cada equipo: <?= e(implode(' · ', array_filter(array_map(fn($r) => $r['pasados'] ? $r['equipo']['nombre'] . ': ' . implode(', ', array_map(fn($j) => nombre_jugador($j) . ' (' . $j['sit']['edad'] . ')', $r['pasados'])) : '', $rc)))) ?: 'ninguno' ?></p>
  </div>
  <?php endif; ?>

  <?php if ($t['formato'] === 'grupos' && $inscritos): ?>
  <div class="tarjeta">
    <h2>Grupos (asignación manual)</h2>
    <p class="suave">Si el sorteo se hizo físicamente, puede asignar aquí el grupo de cada equipo y luego generar el fixture.</p>
    <form method="post">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="grupos_manual">
      <div class="campos">
        <?php foreach ($inscritos as $e): ?>
          <div><label><?= e($e['nombre']) ?></label>
            <select name="grupo[<?= (int)$e['id'] ?>]"><option value="">—</option>
              <?php foreach (letras_grupos((int)$t['num_grupos']) as $g): ?><option <?= $e['grupo'] === $g ? 'selected' : '' ?>><?= $g ?></option><?php endforeach; ?>
            </select></div>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-sec" type="submit">Guardar grupos</button>
    </form>
  </div>
  <?php elseif ($inscritos && $t['fecha_sorteo']): ?>
  <div class="tarjeta">
    <h2>Orden del sorteo</h2>
    <ol><?php foreach ($inscritos as $e): ?><li><?= e($e['nombre']) ?></li><?php endforeach; ?></ol>
  </div>
  <?php endif; ?>

<?php elseif ($s === 'fixture'):
    $partidos = partidos_torneo($tid);
    $grupos_eq = [];
    foreach ($inscritos as $e) $grupos_eq[$e['grupo']][] = $e;
?>
  <?php if ($partidos): ?>
  <div class="tarjeta acciones" style="justify-content:space-between">
    <span><b>📅 Programación:</b> fechas, horas, canchas y árbitros de cada jornada.</span>
    <span class="acciones"><a class="btn" href="admin.php?p=programar&amp;id=<?= $tid ?>">⚙ Programar automáticamente</a> <a class="btn btn-sec" href="admin.php?p=programar&amp;id=<?= $tid ?>&amp;s=manual">✍ Programar a mano</a></span>
  </div>
  <?php endif; ?>
  <?php // (v29) Fixture completo para imprimir y esquema del campeonato: exclusivo del plan Profesional
  $motivo_fp = fixture_pro_motivo($liga); ?>
  <div class="tarjeta fixture-pro<?= $motivo_fp !== '' ? ' fixture-pro-bloqueado' : '' ?>" id="esquema">
    <h2 style="margin-top:0">🗺 Fixture completo y esquema del campeonato <span class="etiqueta amarilla" style="font-size:.7rem;vertical-align:middle">⭐ Plan Profesional</span></h2>
    <?php if ($motivo_fp !== ''): ?>
      <?= html_bloqueo_fixture_pro($liga, $motivo_fp) ?>
    <?php else: ?>
      <div class="acciones">
        <a class="btn" target="_blank" href="admin.php?p=imprimir&amp;doc=fixture&amp;id=<?= $tid ?>">🖨 Imprimir fixture completo</a>
        <a class="btn btn-sec" target="_blank" href="admin.php?p=imprimir&amp;doc=fixture&amp;blanco=1&amp;id=<?= $tid ?>">📝 Fixture en blanco (para llenar a mano)</a>
        <a class="btn btn-sec" target="_blank" href="admin.php?p=imprimir&amp;doc=esquema&amp;id=<?= $tid ?>">🖨 Imprimir solo el esquema</a>
      </div>
      <details open>
        <summary><strong>Ver el esquema del campeonato</strong></summary>
        <?= html_esquema_campeonato($t, 'admin.php?p=partido&id=') ?>
      </details>
      <?php if (!tiene_llaves($tid)):
        $ng_fp = max(1, count(array_filter(array_keys(grupos_esquema($t)['grupos']), fn($l) => $l !== '?'))); ?>
      <form method="post" style="margin-top:10px">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="plan_final">
        <div class="campos">
          <div><label>Fase final: clasifican <?= $ng_fp > 1 ? 'por grupo' : 'de la tabla' ?></label><select name="por_grupo" style="min-width:290px">
            <option value="0">Sin fase final (gana el 1º de la tabla)</option>
            <?php foreach (opciones_clasifican($ng_fp) as $k => $tot): ?><option value="<?= $k ?>" <?= (int)$t['llaves_por_grupo'] === $k ? 'selected' : '' ?>><?= $k ?> <?= $ng_fp > 1 ? 'por grupo' : '' ?> → <?= $tot ?> equipos (empieza en <?= e(mb_strtolower(nombre_fase(fase_por_equipos($tot)))) ?>)</option><?php endforeach; ?>
          </select></div>
          <div><label class="check" style="margin-top:28px"><input type="checkbox" name="tercer" value="1" <?= (int)$t['llaves_tercer'] === 1 ? 'checked' : '' ?>> Jugar tercer lugar</label></div>
        </div>
        <button class="btn btn-sec btn-chico">Guardar fase final prevista</button>
        <p class="ayuda">Así el esquema muestra toda la ruta del campeonato desde el primer día. Al terminar la fase regular, cree las llaves (más abajo) y los casilleros se llenan solos con los clasificados.</p>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <details class="tarjeta" <?= $partidos ? '' : 'open' ?>>
    <summary><strong><?= $partidos ? 'Regenerar fixture de la fase regular' : 'Generar fixture' ?></strong></summary>
    <p class="suave">Crea todos los partidos (todos contra todos<?= $t['formato'] === 'grupos' ? ', dentro de cada grupo' : '' ?>, <?= (int)$t['vueltas'] === 2 ? 'ida y vuelta' : 'solo ida' ?>). Si ya hay fixture sin partidos jugados, se reemplaza.</p>
    <form method="post" onsubmit="return confirm('¿Generar el fixture? Se reemplazan los partidos de la fase regular.')">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="fixture">
      <div class="campos">
        <div><label>Fecha de la 1ª jornada</label><input type="date" name="fecha_inicio"></div>
        <div><label>Días entre jornadas</label><input type="number" name="dias" min="1" max="60" value="7"></div>
        <div><label>Hora por defecto</label><input type="time" name="hora"></div>
        <div><label>Cancha por defecto</label><input type="text" name="cancha" maxlength="60"></div>
      </div>
      <button class="btn" type="submit">Generar fixture</button>
    </form>
  </details>

  <?php $con_llaves = tiene_llaves($tid); $n_grupos = max(1, count(tabla_posiciones($tid))); ?>
  <details class="tarjeta" id="llaves" <?= $con_llaves ? 'open' : '' ?>>
    <summary><strong>🏆 Llaves eliminatorias automáticas</strong> (octavos, cuartos, semifinal y final)</summary>
    <?php if ($con_llaves): ?>
      <p class="suave">Cuando se juega cada partido, el ganador pasa solo a la siguiente ronda (en empate, cuentan los penales). Si corrige un resultado, el clasificado se corrige también.</p>
      <?= html_llave($tid, 'admin.php?p=partido&id=') ?>
      <form method="post" style="margin-top:10px" onsubmit="return confirm('¿Borrar las llaves? Solo se puede si no se ha jugado ningún partido de la llave.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_llaves"><button class="btn btn-sec btn-chico">Borrar llaves</button></form>
    <?php else: ?>
      <p class="suave">Al terminar la fase regular, el sistema arma la llave con los mejores de la tabla (<?= $n_grupos > 1 ? $n_grupos . ' grupos' : 'tabla única' ?>): el 1º contra el último clasificado, y los de un mismo grupo no se cruzan en la primera ronda.</p>
      <form method="post" onsubmit="return confirm('¿Crear las llaves con la tabla actual?')">
        <?= csrf_campo() ?><input type="hidden" name="accion" value="llaves">
        <div class="campos">
          <div><label>Clasifican <?= $n_grupos > 1 ? 'por grupo' : 'de la tabla' ?></label><select name="por_grupo">
            <?php foreach ([1, 2, 4, 8, 16] as $k): $tot = $k * $n_grupos; if (!in_array($tot, [2, 4, 8, 16], true)) continue; ?><option value="<?= $k ?>" <?= ((int)$t['llaves_por_grupo'] > 0 ? (int)$t['llaves_por_grupo'] === $k : $tot === 4) ? 'selected' : '' ?>><?= $k ?> <?= $n_grupos > 1 ? 'por grupo' : '' ?> → <?= $tot ?> equipos (empieza en <?= e(mb_strtolower(nombre_fase(fase_por_equipos($tot)))) ?>)</option><?php endforeach; ?>
          </select></div>
          <div><label class="check" style="margin-top:28px"><input type="checkbox" name="tercer" value="1" <?= (int)$t['llaves_por_grupo'] > 0 && (int)$t['llaves_tercer'] === 0 ? '' : 'checked' ?>> Jugar tercer lugar</label></div>
          <div><label>Fecha de la primera ronda</label><input type="date" name="fecha"></div>
          <div><label>Hora</label><input type="time" name="hora"></div>
          <div><label>Cancha</label><input type="text" name="cancha" maxlength="60"></div>
        </div>
        <button class="btn">Crear llaves</button>
      </form>
    <?php endif; ?>
  </details>

  <details class="tarjeta">
    <summary><strong>+ Agregar partido manual</strong> (eliminatorias: cuartos, semifinal, final…)</summary>
    <form method="post" style="margin-top:12px">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="partido_manual">
      <div class="campos">
        <div><label>Fase</label><select name="fase"><?php foreach (fases() as $f): ?><option value="<?= $f ?>"><?= e(nombre_fase($f)) ?></option><?php endforeach; ?></select></div>
        <div><label>Local</label><select name="local_id" required><?= opciones_equipos($inscritos ?: $todos) ?></select></div>
        <div><label>Visitante</label><select name="visitante_id" required><?= opciones_equipos($inscritos ?: $todos) ?></select></div>
        <div><label>Jornada / número</label><input type="number" name="jornada" min="0" value="1"></div>
        <div><label>Grupo</label><input type="text" name="grupo" maxlength="3" placeholder="opcional"></div>
        <div><label>Fecha</label><input type="date" name="fecha"></div>
        <div><label>Hora</label><input type="time" name="hora"></div>
        <div><label>Cancha</label><input type="text" name="cancha" maxlength="60"></div>
        <div><label>Árbitros para este partido</label><select name="num_arbitros"><option value="0">Según el campeonato</option><?php foreach ([1, 2, 3, 4] as $k): ?><option value="<?= $k ?>"><?= $k ?></option><?php endforeach; ?></select></div>
      </div>
      <button class="btn btn-sec" type="submit">Agregar partido</button>
    </form>
  </details>

  <?php if (!$partidos): ?><p class="suave">Todavía no hay partidos.</p><?php endif; ?>
  <?php
  $clave_actual = null;
  foreach ($partidos as $p) {
      $clave = $p['fase'] === 'regular' ? 'Jornada ' . (int)$p['jornada'] : nombre_fase($p['fase']);
      if ($clave !== $clave_actual) {
          echo '<div class="jornada-titulo">' . e($clave) . '</div>';
          // (v15) Hojas de vocalía de toda la fecha, para imprimir
          if ($p['fase'] === 'regular') echo '<div class="acciones" style="margin:4px 0 6px"><a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=imprimir&amp;doc=hojas&amp;vacia=1&amp;t=' . (int)$tid . '&amp;j=' . (int)$p['jornada'] . '">🖨 Hojas de vocalía de la fecha</a></div>';
          if ($p['fase'] === 'regular') {
              $juegan = [];
              foreach ($partidos as $x) {
                  if ($x['fase'] === 'regular' && (int)$x['jornada'] === (int)$p['jornada']) { $juegan[] = (int)$x['local_id']; $juegan[] = (int)$x['visitante_id']; }
              }
              $descansan = array_filter($inscritos, fn($e) => !in_array((int)$e['id'], $juegan, true));
              if ($descansan) echo '<div class="descansa">Descansa: ' . e(implode(', ', array_column($descansan, 'nombre'))) . '</div>';
          }
          $clave_actual = $clave;
      }
      echo tarjeta_partido($p, 'admin.php?p=partido&id=' . (int)$p['id']);
  }
  ?>

<?php elseif ($s === 'tabla'):
    $tabla = tabla_posiciones($tid);
    $gol = goleadores($tid, 20);
?>
  <div class="acciones" style="margin-bottom:10px"><a class="btn btn-sec" target="_blank" href="admin.php?p=imprimir&amp;doc=boletin&amp;id=<?= $tid ?>">📰 Boletín oficial de la fecha</a> <a class="btn btn-sec" target="_blank" href="admin.php?p=imprimir&amp;doc=temporada&amp;id=<?= $tid ?>">🖨 Informe de la temporada (PDF)</a> <a class="btn btn-sec" href="admin.php?p=acumuladas">Σ Tablas acumuladas</a></div>
  <?php $Td = textos_deporte($t); // (v31) palabras del deporte: PF/PC en básquet, SG/SP en vóley, anotadores ?>
  <?php foreach ($tabla as $grupo => $filas_t): ?>
    <h2><?= $grupo !== '' ? 'Grupo ' . e($grupo) : 'Tabla de posiciones' ?></h2>
    <div class="tabla-scroll"><table>
      <tr><th>#</th><th>Equipo</th><th class="num">PJ</th><th class="num">G</th><th class="num">E</th><th class="num">P</th><th class="num"><?= e($Td['gf']) ?></th><th class="num"><?= e($Td['gc']) ?></th><th class="num"><?= e($Td['dg']) ?></th><th class="num">Pts</th></tr>
      <?php foreach ($filas_t as $i => $f): ?>
        <tr><td><?= $i + 1 ?></td><td><div class="eq-celda"><?= escudo($f['escudo'], $f['nombre'], $f['color']) ?> <?= e($f['nombre']) ?></div></td>
          <td class="num"><?= $f['pj'] ?></td><td class="num"><?= $f['pg'] ?></td><td class="num"><?= $f['pe'] ?></td><td class="num"><?= $f['pp'] ?></td>
          <td class="num"><?= $f['gf'] ?></td><td class="num"><?= $f['gc'] ?></td><td class="num"><?= $f['dg'] > 0 ? '+' : '' ?><?= $f['dg'] ?></td><td class="num pts"><?= $f['pts'] ?><?= $f['sancion'] ? ' <small style="color:var(--roja)">(−' . $f['sancion'] . ')</small>' : '' ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  <?php endforeach; ?>
  <?php if ($Td['con_goleadores']): ?>
  <h2><?= e($Td['goleadores']) ?></h2>
  <?php if (!$gol): ?><p class="suave">Aún no hay registros de <?= e(mb_strtolower($Td['goles'])) ?>.</p><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>#</th><th>Jugador</th><th>Equipo</th><th class="num"><?= e($Td['goles']) ?></th></tr>
    <?php foreach ($gol as $i => $g): ?><tr><td><?= $i + 1 ?></td><td><?= e(nombre_jugador($g)) ?></td><td><?= e($g['equipo']) ?></td><td class="num pts"><?= (int)$g['goles'] ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <?php endif; ?>

<?php elseif ($s === 'disciplina'):
    $susp = suspensiones($tid);
    $tar = tarjetas($tid);
    $acc = amarillas_acumuladas($tid);
    $sanciones = filas('SELECT s.*, j.nombres, j.apellidos, e.nombre AS equipo FROM sanciones s JOIN jugadores j ON j.id = s.jugador_id JOIN equipos e ON e.id = j.equipo_id WHERE s.torneo_id = ? ORDER BY s.id DESC', [$tid]);
    $jugadores = filas('SELECT j.id, j.nombres, j.apellidos, e.nombre AS equipo FROM jugadores j JOIN equipos e ON e.id = j.equipo_id JOIN torneo_equipos te ON te.equipo_id = e.id AND te.torneo_id = ? WHERE j.activo = 1 ORDER BY e.nombre, j.apellidos', [$tid]);
?>
  <div class="aviso aviso-info">Reglas de este campeonato: <?= (int)$t['amarillas_suspension'] ?> amarillas = <?= (int)$t['partidos_susp_amarillas'] ?> partido(s) de suspensión · Roja = <?= (int)$t['partidos_susp_roja'] ?> partido(s)<?= $t['doble_amarilla_roja'] ? ' · Doble amarilla = roja' : '' ?>. <a href="<?= e($url . '&s=datos') ?>">Cambiar</a></div>
  <h2>Suspendidos para el próximo partido</h2>
  <?php if (!$susp): ?><p class="suave">No hay jugadores suspendidos.</p><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>Jugador</th><th>Equipo</th><th class="num">Partidos que le faltan</th><th>Motivo</th></tr>
    <?php foreach ($susp as $x): ?><tr><td><?= e(nombre_jugador($x['jugador'])) ?></td><td><?= e($x['jugador']['equipo']) ?></td><td class="num pts"><?= (int)$x['pendientes'] ?></td><td style="white-space:normal;min-width:220px"><?= html_motivos_suspension($x) ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>

  <h2>Tarjetas por jugador</h2>
  <?php if (!$tar): ?><p class="suave">Aún no hay tarjetas.</p><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>Jugador</th><th>Equipo</th><th class="num"><span class="tarjeta-am"></span> Total</th><th class="num"><span class="tarjeta-ro"></span> Rojas</th><th class="num">Amarillas acumuladas</th></tr>
    <?php foreach ($tar as $x): $a = $acc[(int)$x['id']] ?? 0; ?>
      <tr><td><?= e(nombre_jugador($x)) ?></td><td><?= e($x['equipo']) ?></td><td class="num"><?= (int)$x['amarillas'] ?></td><td class="num"><?= (int)$x['rojas'] ?></td>
        <td class="num"><?= $a ?>/<?= (int)$t['amarillas_suspension'] ?><?= $a === (int)$t['amarillas_suspension'] - 1 ? ' <span class="etiqueta amarilla">al límite</span>' : '' ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>

  <div class="tarjeta" style="margin-top:20px">
    <h2>Sanción manual (comisión disciplinaria)</h2>
    <form method="post">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="sancion">
      <div class="campos">
        <div class="campo-ancho"><label>Jugador</label><select name="jugador_id" required><option value="">— elegir —</option>
          <?php foreach ($jugadores as $j): ?><option value="<?= (int)$j['id'] ?>"><?= e($j['equipo'] . ' — ' . nombre_jugador($j)) ?></option><?php endforeach; ?></select></div>
        <div><label>Partidos de sanción</label><input type="number" name="partidos" min="0" max="99" value="1"><div class="ayuda">Se suman a la suspensión automática de la roja.</div></div>
        <div><label>Multa en dinero ($)</label><input type="number" name="multa" min="0" step="0.01" value="0"></div>
        <div><label>Desde la fecha</label><input type="date" name="desde" value="<?= date('Y-m-d') ?>"><div class="ayuda">Cuenta los partidos jugados desde esta fecha.</div></div>
        <div class="campo-ancho"><label>Motivo</label><input type="text" name="motivo" maxlength="200" placeholder="Ej: agresión al árbitro"></div>
      </div>
      <button class="btn" type="submit">Registrar sanción</button>
    </form>
    <?php if ($sanciones): ?>
      <h3>Sanciones registradas</h3>
      <div class="tabla-scroll"><table>
        <tr><th>Jugador</th><th>Equipo</th><th class="num">Partidos</th><th>Desde</th><th>Motivo</th><th class="num">Multa</th><th></th></tr>
        <?php foreach ($sanciones as $x): ?>
          <tr><td><?= e(nombre_jugador($x)) ?></td><td><?= e($x['equipo']) ?></td><td class="num"><?= (int)$x['partidos'] ?></td><td><?= e($x['desde']) ?></td><td><?= e($x['motivo']) ?></td>
            <td class="num"><?= $x['multa'] > 0 ? dinero($x['multa']) . ($x['pagada'] ? ' ✓' : '') : '—' ?></td>
            <td><form class="inline" method="post" onsubmit="return confirm('¿Quitar esta sanción?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="quitar_sancion"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-chico">Quitar</button></form></td></tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  </div>

  <?php
  $sanc_eq = filas('SELECT s.*, e.nombre AS equipo FROM sanciones_equipo s JOIN equipos e ON e.id = s.equipo_id WHERE s.torneo_id = ? ORDER BY s.id DESC', [$tid]);
  $multas = multas_pendientes($tid);
  ?>
  <div class="tarjeta">
    <h2>Sanción a un equipo (resta de puntos y/o multa)</h2>
    <p class="suave">Ej: incidentes de la barra, alineación indebida, abandono de cancha. Los puntos se restan automáticamente en la tabla de posiciones (se muestra con * en la página pública).</p>
    <form method="post">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="sancion_equipo">
      <div class="campos">
        <div><label>Equipo</label><select name="equipo_id" required><option value="">— elegir —</option><?= opciones_equipos($inscritos) ?></select></div>
        <div><label>Puntos a restar</label><input type="number" name="puntos" min="0" max="99" value="0"></div>
        <div><label>Multa en dinero ($)</label><input type="number" name="multa" min="0" step="0.01" value="0"></div>
        <div><label>Fecha de la resolución</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
        <div class="campo-ancho"><label>Motivo</label><input type="text" name="motivo" maxlength="200" placeholder="Ej: agresión de la barra al árbitro"></div>
      </div>
      <button class="btn" type="submit">Registrar sanción al equipo</button>
    </form>
    <?php if ($sanc_eq): ?>
      <h3>Sanciones a equipos</h3>
      <div class="tabla-scroll"><table>
        <tr><th>Equipo</th><th class="num">Puntos</th><th class="num">Multa</th><th>Fecha</th><th>Motivo</th><th></th></tr>
        <?php foreach ($sanc_eq as $x): ?>
          <tr><td><?= e($x['equipo']) ?></td><td class="num"><?= (int)$x['puntos'] ? '−' . (int)$x['puntos'] : '—' ?></td><td class="num"><?= $x['multa'] > 0 ? dinero($x['multa']) . ($x['pagada'] ? ' ✓' : '') : '—' ?></td><td><?= e($x['fecha']) ?></td><td><?= e($x['motivo']) ?></td>
            <td><form class="inline" method="post" onsubmit="return confirm('¿Quitar esta sanción? Los puntos vuelven a la tabla.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="quitar_sancion_equipo"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-chico">Quitar</button></form></td></tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  </div>

  <div class="tarjeta">
    <h2>Multas pendientes de pago</h2>
    <?php if (!$multas): ?><p class="suave">No hay multas pendientes.</p><?php else: ?>
    <div class="tabla-scroll"><table>
      <tr><th>Equipo</th><th>Sancionado</th><th>Motivo</th><th>Fecha</th><th class="num">Valor</th><th></th></tr>
      <?php $total = 0; foreach ($multas as $x): $total += $x['multa']; ?>
        <tr><td><?= e($x['equipo']) ?></td><td><?= $x['clase'] === 'equipo' ? 'El equipo' : e(nombre_jugador($x)) ?></td><td><?= e($x['motivo']) ?></td><td><?= e($x['fecha']) ?></td><td class="num"><?= dinero($x['multa']) ?></td>
          <td><form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="pagar_multa"><input type="hidden" name="clase" value="<?= e($x['clase']) ?>"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-chico">Marcar pagada</button></form></td></tr>
      <?php endforeach; ?>
      <tr><td colspan="4"><b>Total pendiente</b></td><td class="num pts"><?= dinero($total) ?></td><td></td></tr>
    </table></div>
    <?php endif; ?>
  </div>

<?php else: /* datos */ ?>
  <div class="tarjeta">
    <h2>Configuración del campeonato</h2>
    <form method="post">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="datos">
      <div class="campos">
        <div><label>Nombre</label><input type="text" name="nombre" required maxlength="100" value="<?= e($t['nombre']) ?>"></div>
        <div><label>Temporada</label><input type="text" name="temporada" maxlength="30" value="<?= e($t['temporada']) ?>"></div>
        <div class="campo-ancho"><label>Dirección web del campeonato</label><div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap"><span class="suave"><?= e(preg_replace('#^https?://#', '', rtrim(url_torneo($liga, ['slug' => 'x'] + $t), 'x'))) ?></span><input type="text" name="slug" maxlength="60" value="<?= e($t['slug']) ?>" pattern="[a-z0-9-]*" style="flex:1;min-width:160px" placeholder="apertura-2026"></div><div class="ayuda">Solo minúsculas, números y guiones. Vacío = se arma sola con el nombre. Además funcionan /tabla, /fixture y /en-vivo al final.</div></div>
        <div><label>Formato</label><select name="formato"><option value="liga" <?= $t['formato'] === 'liga' ? 'selected' : '' ?>>Todos contra todos</option><option value="grupos" <?= $t['formato'] === 'grupos' ? 'selected' : '' ?>>Por grupos</option></select></div>
        <div><label>Número de grupos</label><input type="number" name="num_grupos" min="2" max="16" value="<?= max(2, (int)$t['num_grupos']) ?>"></div>
        <div><label>Vueltas</label><select name="vueltas"><option value="1" <?= (int)$t['vueltas'] === 1 ? 'selected' : '' ?>>Solo ida</option><option value="2" <?= (int)$t['vueltas'] === 2 ? 'selected' : '' ?>>Ida y vuelta</option></select></div>
        <div><label>Estado</label><select name="estado"><?php foreach (['inscripcion' => 'Inscripción', 'sorteado' => 'Sorteado', 'en_juego' => 'En juego', 'finalizado' => 'Finalizado'] as $k => $v): ?><option value="<?= $k ?>" <?= $t['estado'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      </div>
      <p class="ayuda">Si cambia formato, grupos o vueltas, vuelva a hacer el sorteo o a generar el fixture.</p>
      <fieldset>
        <legend>📝 Inscripción en línea</legend>
        <div class="campos">
          <div><label>Los delegados pueden inscribir jugadores hasta</label><input type="date" name="jugadores_hasta" value="<?= e($t['jugadores_hasta']) ?>"><div class="ayuda">Vacío = cerrado. La liga aprueba cada jugador en "Inscripciones".</div></div>
          <div><label>Equipos nuevos pueden inscribirse hasta</label><input type="date" name="equipos_hasta" value="<?= e($t['equipos_hasta']) ?>"><div class="ayuda">Vacío = cerrado. Aparece el formulario "Inscribe tu equipo" en la página pública.</div></div>
        </div>
      </fieldset>
      <?php html_categoria($t); ?>
      <fieldset>
        <legend>🏆 Campeón</legend>
        <?php [$sug_c, $sug_s] = campeon_sugerido($tid); $nom_eq = array_column($inscritos, 'nombre', 'id'); ?>
        <div class="campos">
          <div><label>Campeón</label><select name="campeon_id"><option value="">— sin definir —</option><?= opciones_equipos($inscritos, $t['campeon_id']) ?></select></div>
          <div><label>Subcampeón</label><select name="subcampeon_id"><option value="">— sin definir —</option><?= opciones_equipos($inscritos, $t['subcampeon_id']) ?></select></div>
        </div>
        <p class="ayuda">Aparecen en la página pública (pestaña Historia).<?= $sug_c ? ' Según los resultados: campeón <b>' . e($nom_eq[$sug_c] ?? '') . '</b>' . ($sug_s ? ', subcampeón <b>' . e($nom_eq[$sug_s] ?? '') . '</b>' : '') . '. Si deja vacío y pone el estado "Finalizado", se llenan solos.' : '' ?></p>
      </fieldset>
      <?php html_reglas($t, 'Reglas de este campeonato'); ?>
      <button class="btn" type="submit">Guardar configuración y reglas</button>
    </form>
  </div>
  <div class="tarjeta">
    <h2>Eliminar campeonato</h2>
    <p class="suave">Borra el fixture, resultados, goles y tarjetas de este campeonato (los equipos y jugadores se conservan).</p>
    <form method="post" onsubmit="return confirm('¿Eliminar el campeonato?')">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar">
      <p><label>Escriba el nombre exacto: <em><?= e($t['nombre']) ?></em></label><input type="text" name="confirmar" required></p>
      <button class="btn btn-peligro" type="submit">Eliminar campeonato</button>
    </form>
  </div>
<?php endif;
pie_admin();
