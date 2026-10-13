<?php
/* Un partido: resultado, vocalía en vivo, informe del árbitro (con comparación), fotos internas y datos */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$p = partido_de_liga((int)get('id'), $lid);
$pid = (int)$p['id'];
$s = get('s', 'resultado');
if (!in_array($s, ['resultado', 'vocalia', 'arbitro', 'fotos', 'datos'], true)) $s = 'resultado';
$url = 'admin.php?p=partido&id=' . $pid;
$reglas = reglas_torneo((int)$p['torneo_id']);
$max_minuto = (int)$reglas['num_tiempos'] * (int)$reglas['minutos_tiempo'] + 30;
$dep = deporte_de($reglas);
$T = textos_deporte($dep);

// Cada partido tiene su enlace secreto para la vocalía
if ($p['vocal_token'] === '') {
    $p['vocal_token'] = nuevo_token();
    q('UPDATE partidos SET vocal_token = ? WHERE id = ?', [$p['vocal_token'], $pid]);
}

if (es_post()) {
    $accion = post('accion');

    if ($accion === 'datos') {
        $fase = in_array(post('fase'), fases(), true) ? post('fase') : $p['fase'];
        $l = (int)post('local_id');
        $v = (int)post('visitante_id');
        if ($l === $v) throw new RuntimeException('Local y visitante deben ser distintos.');
        equipo_de_liga($l, $lid);
        equipo_de_liga($v, $lid);
        if (($l !== (int)$p['local_id'] || $v !== (int)$p['visitante_id']) && (int)valor('SELECT COUNT(*) FROM eventos WHERE partido_id = ?', [$pid]) + (int)valor('SELECT COUNT(*) FROM informe_eventos WHERE partido_id = ?', [$pid]) > 0) {
            throw new RuntimeException('No se pueden cambiar los equipos: el partido ya tiene goles o tarjetas registrados.');
        }
        $p['num_arbitros'] = entero(post('num_arbitros'), 0, 4) ?: null;
        $p['fase'] = $fase;
        $roles = roles_arbitro(num_arbitros_partido($p));
        $arb = function (string $campo) use ($lid, $roles) {
            if (!isset($roles[$campo])) return null;
            $id = (int)post($campo);
            return $id && fila('SELECT id FROM arbitros WHERE id = ? AND liga_id = ?', [$id, $lid]) ? $id : null;
        };
        $asig = array_filter([$arb('arbitro_id'), $arb('asistente1_id'), $arb('asistente2_id'), $arb('cuarto_id')]);
        if (count($asig) !== count(array_unique($asig))) throw new RuntimeException('Un mismo árbitro no puede tener dos funciones en el partido.');
        q('UPDATE partidos SET fase = ?, grupo = ?, jornada = ?, local_id = ?, visitante_id = ?, fecha = ?, hora = ?, cancha = ?, arbitro = ?, arbitro_id = ?, asistente1_id = ?, asistente2_id = ?, cuarto_id = ?, num_arbitros = ? WHERE id = ?', [
            $fase, mb_substr(post('grupo'), 0, 3), entero(post('jornada'), 0, 200), $l, $v,
            post('fecha') !== '' && strtotime(post('fecha')) ? date('Y-m-d', strtotime(post('fecha'))) : '',
            hora_valida(post('hora')), mb_substr(post('cancha'), 0, 60), mb_substr(post('arbitro'), 0, 80),
            $arb('arbitro_id'), $arb('asistente1_id'), $arb('asistente2_id'), $arb('cuarto_id'), $p['num_arbitros'], $pid,
        ]);
        $pat_id = (int)post('patrocinador_id');
        q('UPDATE partidos SET patrocinador_id = ? WHERE id = ?', [$pat_id && fila('SELECT id FROM patrocinadores WHERE id = ? AND liga_id = ?', [$pat_id, $lid]) ? $pat_id : null, $pid]);
        $n_tg = post('avisar') === '1' ? aviso_programacion((int)$p['torneo_id'], [$pid]) : 0;
        flash('Datos del partido guardados.' . ($n_tg ? ' Avisos enviados por Telegram.' : ''));
        ir($url . '&s=datos');
    }

    if ($accion === 'resultado') {
        $estado = in_array(post('estado'), ['programado', 'en_vivo', 'jugado', 'wo', 'suspendido', 'postergado'], true) ? post('estado') : 'programado';
        $gl = entero_o_null(post('goles_local'));
        $gv = entero_o_null(post('goles_visitante'));
        $pl = entero_o_null(post('penales_local'));
        $pv = entero_o_null(post('penales_visitante'));
        $wo = null;
        if ($estado === 'wo') {
            $wo = (int)post('wo_ganador');
            if (!in_array($wo, [(int)$p['local_id'], (int)$p['visitante_id']], true)) throw new RuntimeException('Elija qué equipo gana por W.O.');
            $gl = $wo === (int)$p['local_id'] ? (int)$reglas['goles_wo'] : 0;
            $gv = $wo === (int)$p['visitante_id'] ? (int)$reglas['goles_wo'] : 0;
        } elseif (($estado === 'jugado' || $estado === 'en_vivo') && $dep === 'ecuavoley') {
            // Ecuavóley: el marcador son los sets ganados, calculados con los puntos de cada set
            $sets_post = [];
            for ($i = 1; $i <= 5; $i++) $sets_post[] = [entero_o_null(post('set' . $i . '_l')), entero_o_null(post('set' . $i . '_v'))];
            [$gl, $gv] = guardar_sets($pid, $sets_post);
            if ($estado === 'jugado' && $gl + $gv === 0) throw new RuntimeException('Escriba los puntos de cada set.');
        } elseif ($estado === 'jugado' || $estado === 'en_vivo') {
            if (post('usar_eventos') === '1') {
                [$gl, $gv] = marcador_desde_eventos($p);
            }
            if ($estado === 'jugado' && ($gl === null || $gv === null)) throw new RuntimeException('Escriba el marcador (goles de cada equipo).');
        } else {
            $gl = $gv = $pl = $pv = null;
        }
        if ($pl === null || $pv === null) $pl = $pv = null;
        $fecha = $p['fecha'];
        if (in_array($estado, ['jugado', 'wo'], true) && $fecha === '') $fecha = date('Y-m-d');
        q('UPDATE partidos SET estado = ?, goles_local = ?, goles_visitante = ?, penales_local = ?, penales_visitante = ?, wo_ganador = ?, observaciones = ?, fecha = ?, hora_inicio = ?, hora_fin = ?, vivo_pausa = CASE WHEN ? = \'en_vivo\' THEN vivo_pausa ELSE 1 END WHERE id = ?', [
            $estado, $gl, $gv, $pl, $pv, $wo, mb_substr(post('observaciones'), 0, 1000), $fecha, hora_valida(post('hora_inicio')), hora_valida(post('hora_fin')), $estado, $pid,
        ]);
        if (in_array($estado, ['jugado', 'wo'], true)) aviso_resultado($pid);
        flash('Resultado guardado.');
        ir($url);
    }

    if ($accion === 'evento') {
        $tipo = post('tipo');
        if (!in_array($tipo, tipos_evento_de($reglas), true)) throw new RuntimeException('Tipo de evento no válido para este deporte.');
        $j = jugador_de_liga((int)post('jugador_id'), $lid);
        if (!in_array((int)$j['equipo_id'], [(int)$p['local_id'], (int)$p['visitante_id']], true)) throw new RuntimeException('Ese jugador no pertenece a ninguno de los dos equipos.');
        $min = post('minuto') === '' ? null : entero(post('minuto'), 0, $max_minuto);
        $cantidad = entero(post('cantidad', '1'), 1, 20);
        for ($i = 0; $i < $cantidad; $i++) {
            q("INSERT INTO eventos (partido_id, jugador_id, equipo_id, tipo, minuto, nota, origen, registrado) VALUES (?,?,?,?,?,?, 'admin', ?)", [$pid, $j['id'], $j['equipo_id'], $tipo, $min, mb_substr(post('nota'), 0, 200), date('Y-m-d H:i:s')]);
        }
        if ($p['estado'] === 'programado') {
            [$gl, $gv] = marcador_desde_eventos($p);
            q("UPDATE partidos SET estado = 'jugado', goles_local = ?, goles_visitante = ?, fecha = CASE WHEN fecha = '' THEN ? ELSE fecha END WHERE id = ?", [$gl, $gv, date('Y-m-d'), $pid]);
            flash('Registrado. El partido pasó a "Jugado"; revise el marcador.');
        } elseif (in_array($p['estado'], ['jugado', 'en_vivo'], true) && es_anotacion($tipo) && post('sumar') === '1') {
            [$gl, $gv] = actualizar_marcador($p);
            flash('Registrado. Marcador actualizado a ' . $gl . ' - ' . $gv . '.');
        } else {
            flash('Registrado.');
        }
        ir($url . '#eventos');
    }

    if ($accion === 'borrar_evento') {
        q('DELETE FROM eventos WHERE id = ? AND partido_id = ?', [(int)post('evento_id'), $pid]);
        flash('Evento eliminado. Revise si el marcador sigue correcto.');
        ir($url . '#eventos');
    }

    if ($accion === 'nuevo_enlace') {
        q('UPDATE partidos SET vocal_token = ? WHERE id = ?', [nuevo_token(), $pid]);
        flash('Se creó un enlace nuevo. El enlace anterior ya no funciona.');
        ir($url . '&s=vocalia');
    }
    if ($accion === 'reabrir_vocalia') {
        q("UPDATE partidos SET vocal_cerrada = 0, vocal_cerrada_en = '' WHERE id = ?", [$pid]);
        flash('Vocalía reabierta: el vocal puede volver a editarla.');
        ir($url . '&s=vocalia');
    }
    if ($accion === 'cerrar_vocalia') {
        q('UPDATE partidos SET vocal_cerrada = 1, vocal_cerrada_en = ? WHERE id = ?', [date('Y-m-d H:i'), $pid]);
        flash('Vocalía cerrada.');
        ir($url . '&s=vocalia');
    }
    if ($accion === 'quitar_vocal') {
        q('DELETE FROM vocales_partido WHERE id = ? AND partido_id = ?', [(int)post('vocal_id'), $pid]);
        flash('Vocal quitado.');
        ir($url . '&s=vocalia');
    }
    if ($accion === 'agregar_vocal') {
        if (post('nombre') === '') throw new RuntimeException('Escriba el nombre del vocal.');
        $tipo = post('tipo') === 'equipo' ? 'equipo' : 'directivo';
        $eq = $tipo === 'equipo' ? (int)post('equipo_id') : null;
        if ($eq) equipo_de_liga($eq, $lid);
        q('INSERT INTO vocales_partido (partido_id, nombre, tipo, equipo_id) VALUES (?,?,?,?)', [$pid, mb_substr(post('nombre'), 0, 80), $tipo, $eq ?: null]);
        flash('Vocal agregado.');
        ir($url . '&s=vocalia');
    }

    if ($accion === 'usar_arbitro') {
        $inf = informe_partido($pid);
        if (!$inf || $inf['estado'] !== 'enviado') throw new RuntimeException('El árbitro todavía no ha enviado su informe.');
        $pdo = db();
        $pdo->beginTransaction();
        q('DELETE FROM eventos WHERE partido_id = ?', [$pid]);
        q("INSERT INTO eventos (partido_id, jugador_id, equipo_id, tipo, minuto, nota, origen, registrado)
           SELECT partido_id, jugador_id, equipo_id, tipo, minuto, nota, 'arbitro', ? FROM informe_eventos WHERE partido_id = ?", [date('Y-m-d H:i:s'), $pid]);
        [$gl, $gv] = marcador_desde_eventos($p);
        if ($inf['goles_local'] !== null && $inf['goles_visitante'] !== null) {
            $gl = (int)$inf['goles_local'];
            $gv = (int)$inf['goles_visitante'];
        }
        q("UPDATE partidos SET goles_local = ?, goles_visitante = ?, estado = CASE WHEN estado IN ('programado','en_vivo') THEN 'jugado' ELSE estado END,
           hora_inicio = CASE WHEN ? <> '' THEN ? ELSE hora_inicio END, hora_fin = CASE WHEN ? <> '' THEN ? ELSE hora_fin END, revisado = 1 WHERE id = ?",
            [$gl, $gv, $inf['hora_inicio'], $inf['hora_inicio'], $inf['hora_fin'], $inf['hora_fin'], $pid]);
        $pdo->commit();
        aviso_resultado($pid);
        flash('Se reemplazaron los goles y tarjetas oficiales por los del informe del árbitro.');
        ir($url . '&s=arbitro');
    }
    if ($accion === 'revisado') {
        q('UPDATE partidos SET revisado = ? WHERE id = ?', [post('valor') === '1' ? 1 : 0, $pid]);
        flash(post('valor') === '1' ? 'Marcado como revisado.' : 'Se quitó la marca de revisado.');
        ir($url . '&s=arbitro');
    }
    if ($accion === 'reabrir_informe') {
        q("UPDATE informes SET estado = 'borrador' WHERE partido_id = ?", [$pid]);
        flash('Informe reabierto: el árbitro puede corregirlo y volver a enviarlo.');
        ir($url . '&s=arbitro');
    }

    if ($accion === 'calificar') {
        if ($reglas['calificar_arbitro'] === 'no') throw new RuntimeException('Este campeonato no usa calificación de árbitros (se activa en las reglas del campeonato).');
        guardar_calificacion($p, (int)post('arbitro_id'), $_POST, 'Admin: ' . usuario()['nombre']);
        flash('Calificación guardada.');
        ir($url . '&s=arbitro#calificacion');
    }

    if ($accion === 'fotos') {
        $rutas = subir_fotos_privadas('fotos');
        if (!$rutas) throw new RuntimeException('Elija al menos una foto.');
        foreach ($rutas as $r) {
            q('INSERT INTO fotos_partido (partido_id, archivo, descripcion, subido_por) VALUES (?,?,?,?)', [$pid, $r, mb_substr(post('descripcion'), 0, 200), 'Admin: ' . usuario()['nombre']]);
        }
        flash(count($rutas) . ' foto(s) guardada(s). Solo se ven dentro del sistema.');
        ir($url . '&s=fotos');
    }
    if ($accion === 'borrar_foto') {
        $f = fila('SELECT * FROM fotos_partido WHERE id = ? AND partido_id = ?', [(int)post('foto_id'), $pid]);
        if ($f) {
            borrar_foto_privada($f['archivo']);
            q('DELETE FROM fotos_partido WHERE id = ?', [$f['id']]);
        }
        flash('Foto eliminada.');
        ir($url . '&s=fotos');
    }

    if ($accion === 'eliminar') {
        eliminar_partido($pid);
        flash('Partido eliminado.');
        ir('admin.php?p=torneo&id=' . (int)$p['torneo_id'] . '&s=fixture');
    }
    ir($url);
}

$eventos = filas('SELECT ev.*, j.nombres, j.apellidos, j.numero FROM eventos ev JOIN jugadores j ON j.id = ev.jugador_id WHERE ev.partido_id = ? ORDER BY (ev.minuto IS NULL), ev.minuto, ev.id', [$pid]);
$susp = partido_cerrado($p) ? [] : suspensiones((int)$p['torneo_id']);
$jl = jugadores_para_partido($p, (int)$p['local_id'], $susp);
$jv = jugadores_para_partido($p, (int)$p['visitante_id'], $susp);
[$gl_ev, $gv_ev] = marcador_desde_eventos($p);
$hay_goles_ev = $dep !== 'ecuavoley' && count(array_filter($eventos, fn($x) => es_anotacion($x['tipo']))) > 0;
$susp_partido = array_filter($susp, fn($x) => in_array((int)($x['jugador']['equipo_id'] ?? 0), [(int)$p['local_id'], (int)$p['visitante_id']], true));
$comp = comparar_informe($p);
$n_fotos = (int)valor('SELECT COUNT(*) FROM fotos_partido WHERE partido_id = ?', [$pid]);
$enlace_vocal = url_base() . 'vocal.php?k=' . $p['vocal_token'];

$calif_pend = calificacion_pendiente($p);
$chip_arbitro = ($calif_pend ? ' <span class="estado-chip chip-pend">calificar</span>' : '') . match ($comp['estado']) {
    'sin_arbitro' => '',
    'pendiente', 'borrador' => ' <span class="estado-chip chip-pend">pendiente</span>',
    default => $comp['diferencias'] ? ' <span class="estado-chip chip-mal">' . count($comp['diferencias']) . ' diferencias</span>' : ' <span class="estado-chip chip-ok">coincide</span>',
};

cabecera_admin($p['local_nombre'] . ' vs ' . $p['visitante_nombre']);
?>
<p><a href="admin.php?p=torneo&amp;id=<?= (int)$p['torneo_id'] ?>&amp;s=fixture">← Fixture de <?= e($p['torneo']) ?></a></p>
<?= tarjeta_partido($p) ?>
<p class="suave" style="text-align:center;margin-top:-4px"><?= implode(' · ', array_map(fn($x) => e($x[1]) . ': ' . e(($x[0] === 'arbitro_id' ? nombre_arbitro($p) : $x[3]) ?: 'sin asignar'), arbitros_del_partido($p))) ?><?= $p['hora_inicio'] !== '' ? ' · Inició: ' . e($p['hora_inicio']) : '' ?><?= $p['hora_fin'] !== '' ? ' · Terminó: ' . e($p['hora_fin']) : '' ?></p>

<nav class="pestanas">
  <?php foreach (['resultado' => 'Resultado, goles y tarjetas', 'vocalia' => 'Vocalía y en vivo' . ($p['vocal_cerrada'] ? ' ✓' : ''), 'arbitro' => 'Informe del árbitro', 'fotos' => 'Fotos internas (' . $n_fotos . ')', 'datos' => 'Datos y árbitros'] as $k => $txt): ?>
    <a href="<?= e($url . '&s=' . $k) ?>" class="<?= $s === $k ? 'activa' : '' ?>"><?= e($txt) ?><?= $k === 'arbitro' ? $chip_arbitro : '' ?></a>
  <?php endforeach; ?>
</nav>

<?php
sincronizar_cargos((int)$p['torneo_id']);
if ($reglas['bloqueo_deuda'] !== 'no'):
    foreach ([[(int)$p['local_id'], $p['local_nombre']], [(int)$p['visitante_id'], $p['visitante_nombre']]] as [$eid_d, $enom_d]):
        $sal_d = saldo_equipo($eid_d);
        if ($sal_d > (float)$reglas['deuda_maxima'] + 0.001): ?>
  <div class="aviso aviso-error">💵 <b><?= e($enom_d) ?></b> debe <b><?= dinero($sal_d) ?></b> a la liga<?= $reglas['bloqueo_deuda'] === 'bloquear' ? ' — la vocalía no podrá iniciar el partido (usted sí puede, desde "Resultado")' : '' ?>. <a href="admin.php?p=finanzas&amp;equipo=<?= $eid_d ?>">Ver cuenta / cobrar</a></div>
<?php endif; endforeach; endif; ?>
<?php if ($susp_partido): ?>
  <div class="aviso aviso-error"><strong>Jugadores suspendidos que NO pueden jugar este partido:</strong><br>
    <?php foreach ($susp_partido as $x): ?>• <?= e(nombre_jugador($x['jugador'])) ?> (<?= e($x['jugador']['equipo']) ?>) — <?= e(implode('; ', $x['motivos'])) ?><br><?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($s === 'resultado'): ?>
<div class="rejilla-2">
  <div class="tarjeta">
    <h2>Resultado</h2>
    <form method="post">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="resultado">
      <div class="campos">
        <div class="campo-ancho"><label>Estado del partido</label>
          <select name="estado" id="estado" onchange="mostrarWo()">
            <?php foreach (['programado', 'en_vivo', 'jugado', 'wo', 'suspendido', 'postergado'] as $est): ?><option value="<?= $est ?>" <?= $p['estado'] === $est ? 'selected' : '' ?>><?= e(nombre_estado_partido($est)) ?></option><?php endforeach; ?>
          </select></div>
        <?php if ($dep === 'ecuavoley'): $sets_g = sets_partido($pid); ?>
        <div class="campo-ancho"><label>Puntos de cada set (<?= e($p['local_nombre']) ?> - <?= e($p['visitante_nombre']) ?>)</label>
          <div class="sets-form">
          <?php for ($i = 1; $i <= max(3, (int)$reglas['sets_ganar'] * 2 - 1); $i++): $sg = $sets_g[$i - 1] ?? null; ?>
            <span>Set <?= $i ?>: <input type="number" name="set<?= $i ?>_l" min="0" max="99" value="<?= $sg ? (int)$sg['puntos_local'] : '' ?>" aria-label="Set <?= $i ?> local"> - <input type="number" name="set<?= $i ?>_v" min="0" max="99" value="<?= $sg ? (int)$sg['puntos_visitante'] : '' ?>" aria-label="Set <?= $i ?> visitante"></span>
          <?php endfor; ?>
          </div>
          <div class="ayuda">El marcador (sets ganados: <?= (int)$p['goles_local'] ?> - <?= (int)$p['goles_visitante'] ?>) se calcula solo. Deje vacíos los sets que no se jugaron.</div></div>
        <?php else: ?>
        <div><label><?= e($T['goles']) ?> <?= e($p['local_nombre']) ?></label><input type="number" name="goles_local" min="0" max="300" value="<?= e($p['goles_local']) ?>"></div>
        <div><label><?= e($T['goles']) ?> <?= e($p['visitante_nombre']) ?></label><input type="number" name="goles_visitante" min="0" max="300" value="<?= e($p['goles_visitante']) ?>"></div>
        <?php endif; ?>
        <div id="caja-wo" class="campo-ancho"><label>Gana por W.O.</label>
          <select name="wo_ganador"><option value="">— elegir —</option>
            <option value="<?= (int)$p['local_id'] ?>" <?= (int)$p['wo_ganador'] === (int)$p['local_id'] ? 'selected' : '' ?>><?= e($p['local_nombre']) ?></option>
            <option value="<?= (int)$p['visitante_id'] ?>" <?= (int)$p['wo_ganador'] === (int)$p['visitante_id'] ? 'selected' : '' ?>><?= e($p['visitante_nombre']) ?></option>
          </select><div class="ayuda">Se asigna <?= (int)$reglas['goles_wo'] ?>-0 automáticamente.</div></div>
        <?php if ($p['fase'] !== 'regular' && $T['con_empate']): ?>
        <div><label>Penales <?= e($p['local_nombre']) ?></label><input type="number" name="penales_local" min="0" max="99" value="<?= e($p['penales_local']) ?>"></div>
        <div><label>Penales <?= e($p['visitante_nombre']) ?></label><input type="number" name="penales_visitante" min="0" max="99" value="<?= e($p['penales_visitante']) ?>"></div>
        <?php endif; ?>
        <div><label>Hora de inicio real</label><input type="time" name="hora_inicio" value="<?= e($p['hora_inicio']) ?>"></div>
        <div><label>Hora de finalización</label><input type="time" name="hora_fin" value="<?= e($p['hora_fin']) ?>"></div>
        <?php if ($hay_goles_ev): ?>
        <div class="campo-ancho"><label class="check" style="font-weight:400"><input type="checkbox" name="usar_eventos" value="1"> Usar el marcador de lo registrado abajo (<?= $gl_ev ?> - <?= $gv_ev ?>)</label></div>
        <?php endif; ?>
        <div class="campo-ancho"><label>Observaciones (públicas)</label><textarea name="observaciones" maxlength="1000"><?= e($p['observaciones']) ?></textarea></div>
      </div>
      <button class="btn" type="submit">Guardar resultado</button>
    </form>
    <?php if ($p['estado'] === 'jugado' && $hay_goles_ev && ($gl_ev !== (int)$p['goles_local'] || $gv_ev !== (int)$p['goles_visitante'])): ?>
      <div class="aviso aviso-info" style="margin-top:12px">Atención: <?= e(mb_strtolower($T['goles'])) ?> registrados suman <?= $gl_ev ?> - <?= $gv_ev ?>, pero el marcador guardado es <?= (int)$p['goles_local'] ?> - <?= (int)$p['goles_visitante'] ?>.</div>
    <?php endif; ?>
  </div>

  <div class="tarjeta" id="eventos">
    <h2><?= $dep === 'basquet' ? 'Canastas y faltas' : ($dep === 'ecuavoley' ? 'Tarjetas' : 'Goles y tarjetas') ?> (oficiales)</h2>
    <p class="suave" style="margin-top:-6px;font-size:.85rem">Los que carga el vocal en vivo aparecen aquí automáticamente.</p>
    <?php if (!$eventos): ?><p class="suave">Sin eventos registrados.</p><?php endif; ?>
    <ul class="eventos">
      <?php foreach ($eventos as $x): ?>
        <li>
          <span class="minuto"><?= $x['minuto'] !== null ? (int)$x['minuto'] . "'" : '—' ?></span>
          <span><?= icono_evento($x['tipo'], $dep) ?></span>
          <span><?= e(nombre_jugador($x)) ?><?= $x['numero'] !== null ? ' #' . (int)$x['numero'] : '' ?> <small>(<?= (int)$x['equipo_id'] === (int)$p['local_id'] ? e($p['local_nombre']) : e($p['visitante_nombre']) ?><?= $x['origen'] !== 'admin' ? ' · ' . e($x['origen']) : '' ?>)</small><?= $x['nota'] !== '' ? '<br><small>' . e($x['nota']) . '</small>' : '' ?></span>
          <form class="inline" method="post" style="margin-left:auto" onsubmit="return confirm('¿Borrar este evento?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_evento"><input type="hidden" name="evento_id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sec btn-chico" title="Borrar">✕</button></form>
        </li>
      <?php endforeach; ?>
    </ul>
    <h3>Agregar</h3>
    <?php if (!$jl && !$jv): ?>
      <p class="suave">Los equipos no tienen jugadores registrados.</p>
    <?php else: ?>
    <form method="post">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="evento">
      <div class="campos">
        <div class="campo-ancho"><label>Jugador</label><select name="jugador_id" required><?= opciones_jugadores_partido($p, $jl, $jv) ?></select></div>
        <div><label>Tipo</label><select name="tipo"><?= opciones_tipo_evento($reglas) ?></select></div>
        <div><label>Minuto</label><input type="number" name="minuto" min="0" max="<?= $max_minuto ?>" placeholder="opcional"></div>
        <div><label>Cantidad</label><input type="number" name="cantidad" min="1" max="20" value="1"><div class="ayuda">Ej: 3 si hizo 3 <?= $dep === 'basquet' ? 'canastas de ese tipo' : 'goles' ?>.</div></div>
        <div class="campo-ancho"><label>Nota / motivo</label><input type="text" name="nota" maxlength="200" placeholder="Ej: roja por agresión"></div>
      </div>
      <?php if (in_array($p['estado'], ['jugado', 'en_vivo'], true) && $dep !== 'ecuavoley'): ?><label class="check" style="font-weight:400;margin-bottom:10px"><input type="checkbox" name="sumar" value="1" checked> Actualizar el marcador con lo registrado</label><?php endif; ?>
      <button class="btn" type="submit">Agregar</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<div class="acciones">
  <a class="btn btn-chico" target="_blank" href="admin.php?p=imprimir&amp;doc=hoja&amp;vacia=1&amp;id=<?= $pid ?>">🖨 Hoja de vocalía (para llenar)</a>
  <a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=imprimir&amp;doc=hoja&amp;id=<?= $pid ?>">🖨 Hoja de vocalía llena</a>
  <a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=planilla&amp;id=<?= $pid ?>">🖨 Planilla en blanco</a>
  <a class="btn btn-sec btn-chico" target="_blank" href="admin.php?p=imprimir&amp;doc=vocalia&amp;id=<?= $pid ?>">🖨 Hoja de vocalía (PDF)</a>
  <a class="btn btn-sec btn-chico" target="_blank" href="index.php?l=<?= e($liga['slug']) ?>&amp;p=<?= $pid ?>">Ver en página pública ↗</a>
  <a class="btn btn-sec btn-chico" href="admin.php?p=bitacora&amp;partido=<?= $pid ?>">🔎 Historial de cambios</a>
</div>
<script>
function mostrarWo() { document.getElementById('caja-wo').style.display = document.getElementById('estado').value === 'wo' ? '' : 'none'; }
mostrarWo();
</script>

<?php elseif ($s === 'vocalia'):
    $vocales = filas('SELECT v.*, e.nombre AS equipo FROM vocales_partido v LEFT JOIN equipos e ON e.id = v.equipo_id WHERE v.partido_id = ? ORDER BY v.id', [$pid]);
    $n_pl = count(array_filter($jl, fn($j) => $j['presente']));
    $n_pv = count(array_filter($jv, fn($j) => $j['presente']));
    $msg_wa = "Vocalía " . $p['local_nombre'] . " vs " . $p['visitante_nombre'] . ($p['fecha'] ? ' (' . $p['fecha'] . ($p['hora'] ? ' ' . $p['hora'] : '') . ')' : '') . ":\n" . $enlace_vocal;
    $equipos_liga = filas('SELECT id, nombre FROM equipos WHERE liga_id = ? ORDER BY nombre', [$lid]);
?>
<div class="rejilla-2">
  <div class="tarjeta">
    <h2>Enlace para el vocal</h2>
    <p>El vocal abre este enlace en su <b>celular</b> (no necesita usuario ni contraseña) y desde ahí: pone su nombre, marca los jugadores presentes, inicia el cronómetro y va cargando goles y tarjetas <b>en vivo</b>. El público lo ve en la pestaña <b>🔴 En vivo</b>.</p>
    <input type="text" readonly value="<?= e($enlace_vocal) ?>" onclick="this.select()" id="enlace-vocal">
    <div class="acciones" style="margin-top:10px">
      <a class="btn" target="_blank" href="https://wa.me/?text=<?= rawurlencode($msg_wa) ?>">📲 Enviar por WhatsApp</a>
      <button class="btn btn-sec" type="button" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('enlace-vocal').value); this.textContent='✓ Copiado'">Copiar enlace</button>
      <a class="btn btn-sec" target="_blank" href="<?= e($enlace_vocal) ?>">Abrir vocalía</a>
    </div>
    <form method="post" style="margin-top:12px" onsubmit="return confirm('El enlace actual dejará de funcionar. ¿Continuar?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="nuevo_enlace"><button class="btn btn-sec btn-chico">Cambiar enlace (anular el anterior)</button></form>
    <p class="ayuda">Mantenga el enlace en privado: quien lo tenga puede llenar la vocalía hasta que se cierre.</p>
  </div>
  <div class="tarjeta">
    <h2>Estado de la vocalía</h2>
    <p>Estado del partido: <b><?= e(nombre_estado_partido($p['estado'])) ?></b>
      <?php if ($p['estado'] === 'en_vivo' && ($mv = minuto_vivo($p, $liga))): ?> · <span class="en-vivo" style="display:inline"><?= e($mv['etiqueta'] . ' ' . $mv['txt']) ?></span><?php endif; ?></p>
    <p>Hora de inicio: <b><?= e($p['hora_inicio'] ?: '—') ?></b> · Fin: <b><?= e($p['hora_fin'] ?: '—') ?></b></p>
    <p>Presentes: <?= e($p['local_nombre']) ?> <b><?= $n_pl ?></b> · <?= e($p['visitante_nombre']) ?> <b><?= $n_pv ?></b></p>
    <p>Vocalía: <?= $p['vocal_cerrada'] ? '<span class="estado-chip chip-ok">Cerrada ' . e($p['vocal_cerrada_en']) . '</span>' : '<span class="estado-chip chip-pend">Abierta</span>' ?></p>
    <form method="post"><?= csrf_campo() ?>
      <?php if ($p['vocal_cerrada']): ?><input type="hidden" name="accion" value="reabrir_vocalia"><button class="btn btn-sec btn-chico">Reabrir vocalía</button>
      <?php else: ?><input type="hidden" name="accion" value="cerrar_vocalia"><button class="btn btn-sec btn-chico" onclick="return confirm('¿Cerrar la vocalía? El vocal ya no podrá editar.')">Cerrar vocalía</button><?php endif; ?>
    </form>
    <h3>Vocales</h3>
    <?php if (!$vocales): ?><p class="suave">Aún no se ha registrado el vocal (lo registra él mismo al abrir el enlace, o agréguelo aquí).</p><?php endif; ?>
    <ul class="eventos">
      <?php foreach ($vocales as $v): ?>
        <li><span><b><?= e($v['nombre']) ?></b> — <?= $v['tipo'] === 'equipo' ? 'Delegado de ' . e($v['equipo'] ?? 'equipo') : 'Directivo de la liga' ?></span>
          <form class="inline" method="post" style="margin-left:auto"><?= csrf_campo() ?><input type="hidden" name="accion" value="quitar_vocal"><input type="hidden" name="vocal_id" value="<?= (int)$v['id'] ?>"><button class="btn btn-sec btn-chico">✕</button></form></li>
      <?php endforeach; ?>
    </ul>
    <form method="post" style="margin-top:10px">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="agregar_vocal">
      <div class="campos">
        <div><label>Nombre</label><input type="text" name="nombre" maxlength="80" required></div>
        <div><label>Es</label><select name="tipo"><option value="directivo">Directivo de la liga</option><option value="equipo">Delegado de un equipo</option></select></div>
        <div><label>Equipo (si es delegado)</label><select name="equipo_id"><option value="">—</option><?= opciones_equipos($equipos_liga) ?></select></div>
      </div>
      <button class="btn btn-sec btn-chico">Agregar vocal</button>
    </form>
  </div>
</div>
<?php if ($p['vocal_obs'] !== ''): ?><div class="tarjeta"><strong>Observaciones del vocal:</strong><br><?= nl2br(e($p['vocal_obs'])) ?></div><?php endif; ?>
<div class="acciones"><a class="btn" target="_blank" href="admin.php?p=imprimir&amp;doc=vocalia&amp;id=<?= $pid ?>">🖨 Imprimir / PDF hoja de vocalía</a></div>

<?php elseif ($s === 'arbitro'):
    $inf = informe_partido($pid);
    $iev = filas('SELECT ie.*, j.nombres, j.apellidos, j.numero FROM informe_eventos ie JOIN jugadores j ON j.id = ie.jugador_id WHERE ie.partido_id = ? ORDER BY (ie.minuto IS NULL), ie.minuto, ie.id', [$pid]);
?>
  <?php if ($comp['estado'] === 'sin_arbitro'): ?>
    <div class="aviso aviso-info">Este partido no tiene un árbitro del catálogo asignado. Asígnelo en la pestaña <a href="<?= e($url . '&s=datos') ?>">Datos y árbitros</a> para que pueda enviar su informe.</div>
  <?php elseif ($comp['estado'] === 'pendiente'): ?>
    <div class="aviso aviso-info">El árbitro <b><?= e(nombre_arbitro($p)) ?></b> todavía no ha empezado su informe.</div>
  <?php elseif ($comp['estado'] === 'borrador'): ?>
    <div class="aviso aviso-info">El árbitro está llenando el informe (borrador, aún no lo envía).</div>
  <?php endif; ?>

  <?php if ($comp['estado'] === 'enviado'): ?>
  <div class="tarjeta">
    <h2>Comparación: vocalía vs. informe del árbitro</h2>
    <?php if (!$comp['diferencias']): ?>
      <div class="aviso aviso-ok">✓ La vocalía y el informe del árbitro coinciden en marcador, goleadores y tarjetas.</div>
    <?php else: ?>
      <div class="aviso aviso-error">Se encontraron <b><?= count($comp['diferencias']) ?></b> diferencias. Revise y decida cuál es la correcta.</div>
      <div class="tabla-scroll"><table>
        <tr><th>Concepto</th><th>Detalle</th><th class="num">Vocalía</th><th class="num">Árbitro</th></tr>
        <?php foreach ($comp['diferencias'] as $d): ?><tr class="dif"><td><?= e($d[0]) ?></td><td><?= e($d[1]) ?></td><td class="num"><?= e($d[2]) ?></td><td class="num"><?= e($d[3]) ?></td></tr><?php endforeach; ?>
      </table></div>
      <p class="suave" style="font-size:.85rem">Opciones: corrija a mano en la pestaña <a href="<?= e($url) ?>">Resultado, goles y tarjetas</a>, o use el botón para copiar lo del árbitro como oficial.</p>
    <?php endif; ?>
    <div class="acciones">
      <?php if ($comp['diferencias']): ?>
      <form class="inline" method="post" onsubmit="return confirm('Se reemplazarán TODOS los goles y tarjetas oficiales por los del informe del árbitro. ¿Continuar?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="usar_arbitro"><button class="btn">Usar la versión del árbitro como oficial</button></form>
      <?php endif; ?>
      <form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="revisado"><input type="hidden" name="valor" value="<?= $p['revisado'] ? '0' : '1' ?>"><button class="btn btn-sec"><?= $p['revisado'] ? '✓ Revisado (quitar marca)' : 'Marcar como revisado' ?></button></form>
      <form class="inline" method="post" onsubmit="return confirm('¿Reabrir el informe para que el árbitro lo corrija?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="reabrir_informe"><button class="btn btn-sec">Reabrir informe al árbitro</button></form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($inf): ?>
  <div class="tarjeta">
    <h2>Informe de <?= e($inf['arbitro'] ?? nombre_arbitro($p)) ?> <small><?= $inf['estado'] === 'enviado' ? 'enviado ' . e($inf['enviado_en']) : '(borrador)' ?></small></h2>
    <p>Hora de inicio: <b><?= e($inf['hora_inicio'] ?: '—') ?></b> · Fin: <b><?= e($inf['hora_fin'] ?: '—') ?></b> · Marcador: <b><?= $inf['goles_local'] === null ? '—' : (int)$inf['goles_local'] ?> - <?= $inf['goles_visitante'] === null ? '—' : (int)$inf['goles_visitante'] ?></b></p>
    <ul class="eventos">
      <?php foreach ($iev as $x): ?>
        <li><span class="minuto"><?= $x['minuto'] !== null ? (int)$x['minuto'] . "'" : '—' ?></span><span><?= icono_evento($x['tipo'], $dep) ?></span>
          <span><?= e(nombre_jugador($x)) ?><?= $x['numero'] !== null ? ' #' . (int)$x['numero'] : '' ?> <small>(<?= (int)$x['equipo_id'] === (int)$p['local_id'] ? e($p['local_nombre']) : e($p['visitante_nombre']) ?>)</small><?= $x['nota'] !== '' ? '<br><small>' . e($x['nota']) . '</small>' : '' ?></span></li>
      <?php endforeach; ?>
      <?php if (!$iev): ?><li class="suave">Sin goles ni tarjetas en el informe.</li><?php endif; ?>
    </ul>
    <h3>Informe / incidentes</h3>
    <div style="white-space:pre-wrap"><?= e($inf['incidentes'] ?: '—') ?></div>
    <div class="acciones" style="margin-top:12px"><a class="btn btn-sec" target="_blank" href="admin.php?p=imprimir&amp;doc=informe&amp;id=<?= $pid ?>">🖨 Imprimir / PDF informe arbitral</a></div>
  </div>
  <?php endif; ?>

  <?php if ($reglas['calificar_arbitro'] !== 'no'): ?>
  <div class="tarjeta" id="calificacion">
    <h2>Calificación del árbitro <small>(<?= e(nombre_calificacion($reglas['calificar_arbitro'])) ?> en este campeonato · uso interno)</small></h2>
    <?php if (!array_filter(arbitros_del_partido($p), fn($x) => $x[2])): ?>
      <p class="suave">Primero asigne los árbitros en la pestaña <a href="<?= e($url . '&s=datos') ?>">Datos y árbitros</a>.</p>
    <?php elseif (!in_array($p['estado'], ['jugado', 'wo'], true)): ?>
      <p class="suave">Se califica cuando el partido haya terminado.</p>
    <?php else: ?>
      <?= form_calificacion($p) ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

<?php elseif ($s === 'fotos'):
    $fotos = filas('SELECT * FROM fotos_partido WHERE partido_id = ? ORDER BY id DESC', [$pid]);
?>
  <div class="aviso aviso-info">🔒 Estas fotos son <b>internas</b>: solo las ven los administradores, el árbitro del partido y el vocal. No aparecen en la página pública.</div>
  <div class="tarjeta">
    <h2>Subir fotos</h2>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="fotos">
      <div class="campos">
        <div><label>Fotos (puede elegir varias)</label><input type="file" name="fotos[]" accept="image/*" multiple required></div>
        <div><label>Descripción</label><input type="text" name="descripcion" maxlength="200" placeholder="Ej: planilla firmada"></div>
      </div>
      <button class="btn" type="submit">Subir</button>
    </form>
  </div>
  <?php if (!$fotos): ?><p class="suave">No hay fotos todavía.</p><?php endif; ?>
  <div class="galeria">
    <?php foreach ($fotos as $f): ?>
      <figure>
        <a href="foto.php?id=<?= (int)$f['id'] ?>" target="_blank"><img src="foto.php?id=<?= (int)$f['id'] ?>" alt="" loading="lazy"></a>
        <figcaption><?= e($f['descripcion'] ?: 'Sin descripción') ?><br><small><?= e($f['subido_por']) ?> · <?= e($f['creado']) ?></small>
          <form method="post" onsubmit="return confirm('¿Borrar esta foto?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_foto"><input type="hidden" name="foto_id" value="<?= (int)$f['id'] ?>"><button class="btn btn-sec btn-chico" style="margin-top:4px">Borrar</button></form>
        </figcaption>
      </figure>
    <?php endforeach; ?>
  </div>

<?php else: /* datos */
    $equipos_liga = filas('SELECT id, nombre FROM equipos WHERE liga_id = ? ORDER BY nombre', [$lid]);
    $arbitros = filas('SELECT id, nombre, activo FROM arbitros WHERE liga_id = ? ORDER BY activo DESC, nombre', [$lid]);
    $sel_arb = function (string $campo) use ($arbitros, $p) {
        $h = '<option value="">— ninguno —</option>';
        foreach ($arbitros as $a) {
            $h .= '<option value="' . (int)$a['id'] . '"' . ((int)$p[$campo] === (int)$a['id'] ? ' selected' : '') . '>' . e($a['nombre']) . ($a['activo'] ? '' : ' (inactivo)') . '</option>';
        }
        return $h;
    };
?>
<div class="tarjeta">
  <h2>Datos del partido</h2>
  <form method="post">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="datos">
    <datalist id="lista-canchas"><?php foreach (filas('SELECT nombre FROM canchas WHERE liga_id = ? AND activa = 1 ORDER BY orden, nombre', [$lid]) as $c): ?><option value="<?= e($c['nombre']) ?>"><?php endforeach; ?></datalist>
    <div class="campos">
      <div><label>Fecha</label><input type="date" name="fecha" value="<?= e($p['fecha']) ?>"></div>
      <div><label>Hora programada</label><input type="time" name="hora" value="<?= e($p['hora']) ?>"></div>
      <div><label>Cancha</label><input type="text" name="cancha" list="lista-canchas" maxlength="60" value="<?= e($p['cancha']) ?>"></div>
      <div><label>Fase</label><select name="fase"><?php foreach (fases() as $f): ?><option value="<?= $f ?>" <?= $p['fase'] === $f ? 'selected' : '' ?>><?= e(nombre_fase($f)) ?></option><?php endforeach; ?></select></div>
      <div><label>Jornada</label><input type="number" name="jornada" min="0" max="200" value="<?= (int)$p['jornada'] ?>"></div>
      <div><label>Grupo</label><input type="text" name="grupo" maxlength="3" value="<?= e($p['grupo']) ?>"></div>
      <div><label>Local</label><select name="local_id"><?= opciones_equipos($equipos_liga, $p['local_id']) ?></select></div>
      <div><label>Visitante</label><select name="visitante_id"><?= opciones_equipos($equipos_liga, $p['visitante_id']) ?></select></div>
    </div>
    <h3>Árbitros que pitan el encuentro</h3>
    <div class="campos">
      <div><label>¿Cuántos árbitros pitan este partido?</label>
        <select name="num_arbitros" onchange="this.form.querySelector('.aviso-cambio').style.display=''">
          <option value="0">Según el campeonato (<?= $p['fase'] !== 'regular' && (int)$reglas['num_arbitros_final'] > 0 ? (int)$reglas['num_arbitros_final'] . ' en eliminatorias' : (int)$reglas['num_arbitros'] ?>)</option>
          <?php foreach ([1, 2, 3, 4] as $k): ?><option value="<?= $k ?>" <?= (int)$p['num_arbitros'] === $k ? 'selected' : '' ?>><?= $k ?> árbitro<?= $k > 1 ? 's' : '' ?> solo en este partido</option><?php endforeach; ?>
        </select>
        <div class="ayuda aviso-cambio" style="display:none;color:var(--roja)">Presione "Guardar datos" y vuelva a elegir a los árbitros.</div></div>
    </div>
    <div class="campos">
      <?php foreach (roles_arbitro(num_arbitros_partido($p)) as $campo => $etq): ?>
        <div><label><?= e($etq) ?></label><select name="<?= $campo ?>"><?= $sel_arb($campo) ?></select><?= $campo === 'arbitro_id' ? '<div class="ayuda">Él llena el informe arbitral.</div>' : '' ?></div>
      <?php endforeach; ?>
      <div><label>Árbitro (texto libre)</label><input type="text" name="arbitro" maxlength="80" value="<?= e($p['arbitro']) ?>"><div class="ayuda">Solo si no está en la lista.</div></div>
    </div>
    <p class="ayuda">Para cambiarlo en todo el campeonato, use las <a href="admin.php?p=torneo&amp;id=<?= (int)$p['torneo_id'] ?>&amp;s=datos">reglas del campeonato</a>.</p>
    <?php $pats_p = patrocinadores_activos($lid, 'en_partidos'); if ($pats_p || $p['patrocinador_id']): ?>
    <h3>Patrocinio</h3>
    <div class="campos"><div><label>"Partido presentado por…"</label><select name="patrocinador_id"><option value="">— ninguno —</option>
      <?php foreach ($pats_p as $pt): ?><option value="<?= (int)$pt['id'] ?>" <?= (int)$p['patrocinador_id'] === (int)$pt['id'] ? 'selected' : '' ?>><?= e($pt['nombre']) ?></option><?php endforeach; ?></select>
      <div class="ayuda">Aparece en la página pública del partido. Se administra en Redes y público → Patrocinadores.</div></div></div>
    <?php endif; ?>
    <?php if (tg_liga_activa($liga)): ?><label class="check" style="font-weight:400;margin-bottom:10px"><input type="checkbox" name="avisar" value="1"> Avisar por Telegram el horario y la designación (canal, delegados y árbitros)</label><?php endif; ?>
    <button class="btn" type="submit">Guardar datos</button>
  </form>
  <form method="post" style="margin-top:16px" onsubmit="return confirm('¿Eliminar este partido con sus goles, tarjetas, informe y fotos?')">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar">
    <button class="btn btn-peligro btn-chico" type="submit">Eliminar partido</button>
  </form>
</div>
<?php endif;
pie_admin();
