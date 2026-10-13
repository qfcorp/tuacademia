<?php
/* Página pública de una liga con pestañas */
$torneos = filas("SELECT * FROM torneos WHERE liga_id = ? ORDER BY CASE WHEN " . sql_sin_carreras() . " THEN 0 ELSE 1 END, CASE estado WHEN 'en_juego' THEN 0 WHEN 'sorteado' THEN 1 WHEN 'inscripcion' THEN 2 ELSE 3 END, id DESC", [$lid]);
$t = null;
if (get('t') !== '') {
    $t = fila('SELECT * FROM torneos WHERE id = ? AND liga_id = ?', [(int)get('t'), $lid]);
}
$t = $t ?? ($torneos[0] ?? null);
$v = get('v', 'inicio');
$n_vivo = (int)valor("SELECT COUNT(*) FROM partidos p JOIN torneos t ON t.id = p.torneo_id WHERE t.liga_id = ? AND p.estado = 'en_vivo'", [$lid]);
$T = textos_deporte($t ?? $liga);
$pestanas = ['inicio' => 'Inicio', 'envivo' => '🔴 En vivo' . ($n_vivo ? ' (' . $n_vivo . ')' : ''), 'posiciones' => 'Posiciones', 'fixture' => 'Fixture y resultados'];
if ($T['con_goleadores']) $pestanas['goleadores'] = $T['goleadores'];
$pestanas += ['tarjetas' => ($T['deporte'] === 'basquet' ? 'Faltas y suspendidos' : 'Tarjetas y suspendidos'), 'equipos' => 'Equipos'];
// (v7) Votación del público y polla
$votaciones_pub = filas("SELECT * FROM votaciones WHERE liga_id = ? AND (estado = 'abierta' OR creado >= datetime('now', '-30 days')) ORDER BY (estado = 'abierta') DESC, id DESC LIMIT 20", [$lid]);
if ($votaciones_pub) $pestanas['votar'] = '🗳 Votaciones';
if ((int)$liga['polla_activa'] === 1) $pestanas['polla'] = '🎯 Polla';
if ($t && $t['fecha_sorteo']) $pestanas['sorteo'] = 'Sorteo';
$insc_abiertas = filas("SELECT * FROM torneos WHERE liga_id = ? AND " . sql_sin_carreras() . " AND estado <> 'finalizado' AND equipos_hasta <> '' AND equipos_hasta >= ? ORDER BY id DESC", [$lid, date('Y-m-d')]);
if ($insc_abiertas) $pestanas['inscribir'] = '📝 Inscribe tu equipo';
if ((int)valor("SELECT COUNT(*) FROM torneos WHERE liga_id = ? AND (estado = 'finalizado' OR campeon_id IS NOT NULL)", [$lid]) > 0) $pestanas['historia'] = '🏆 Historia';
// (v8) Llaves, estadísticas y reserva de canchas
if ($t && tiene_llaves((int)$t['id'])) $pestanas = array_slice($pestanas, 0, 4, true) + ['llaves' => '🏆 Llaves'] + $pestanas;
if ($t && $T['deporte'] !== 'ecuavoley' && (int)valor("SELECT COUNT(*) FROM partidos WHERE torneo_id = ? AND estado IN ('jugado','wo')", [$t['id']]) > 0) $pestanas['estadisticas'] = '📊 Estadísticas';
// (v10) Tablas acumuladas (Apertura + Clausura...)
$acumuladas = filas('SELECT * FROM tablas_acumuladas WHERE liga_id = ? AND publica = 1 ORDER BY id DESC', [$lid]);
if ($acumuladas) $pestanas['acumulada'] = 'Σ Acumulada';
// (v18) Documentos de la liga (reglamento, bases…) y buscador
$docs_pub = documentos_liga($lid, $t ? (int)$t['id'] : null);
if ($docs_pub) $pestanas['documentos'] = '📄 Documentos';
$pestanas['buscar'] = '🔎 Buscar';
if (es_federacion($liga)) $pestanas = ['federacion' => '🏛 Federación'] + $pestanas; // (v28) volver a la portada de la federación
if (entradas_activas($liga) && ventas_abiertas($lid)) $pestanas['entradas'] = '🎟 Entradas'; // (v28)
$canchas_res = (int)$liga['reservas_activas'] === 1 ? filas('SELECT * FROM canchas WHERE liga_id = ? AND activa = 1 AND reservable = 1 ORDER BY orden, nombre', [$lid]) : [];
if ($canchas_res) $pestanas['reservar'] = '🏟 Reservar cancha';

/* (v8) Reserva de cancha hecha por el público */
if ($v === 'reservar' && es_post() && $canchas_res) {
    csrf_verificar();
    if (post('sitio') !== '') exit;
    contexto_auditoria(['usuario' => 'Público (reserva): ' . mb_substr(post('nombre'), 0, 60), 'rol' => 'publico', 'liga_id' => $lid, 'origen' => 'publico']);
    try {
        if (post('acepto') !== '1') throw new RuntimeException('Debe aceptar las condiciones.');
        if (demasiados_intentos('reserva', 5, '-1 hour')) throw new RuntimeException('Demasiadas reservas seguidas desde esta conexión. Escríbanos por WhatsApp.');
        $c = fila('SELECT * FROM canchas WHERE id = ? AND liga_id = ? AND reservable = 1 AND activa = 1', [(int)post('cancha_id'), $lid]) ?? throw new RuntimeException('Elija la cancha.');
        if (post('fecha') > date('Y-m-d', strtotime('+60 days'))) throw new RuntimeException('Solo se puede reservar hasta 60 días antes.');
        $rid = crear_reserva($c, post('fecha'), post('hora'), entero(post('turnos'), 1, 3), post('nombre'), post('telefono'), 'publico');
        registrar_consentimiento('reserva', $lid, post('nombre'), 'titular', ['ref_tabla' => 'reservas', 'ref_id' => $rid,
            'texto' => 'Acepto las condiciones y que la liga use mi nombre y celular solo para esta reserva, según la política de privacidad (datos guardados en servidores fuera del Ecuador).']); // (v24)
        anotar_intento('reserva');
        $r = fila('SELECT * FROM reservas WHERE id = ?', [$rid]);
        aviso_admins($liga, '🏟 <b>Nueva reserva</b> por confirmar' . "\n" . e($c['nombre']) . ' · ' . fecha_bonita($r['fecha']) . ' ' . $r['hora_inicio'] . "\n" . e($r['cliente_nombre']) . ' · ' . e($r['cliente_telefono']), 'res:' . $rid);
        $_SESSION['reserva_hecha'] = $rid;
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    }
    ir($base . '&v=reservar&fecha=' . rawurlencode(post('fecha')));
}

/* Formulario público de inscripción de equipos nuevos */
if ($v === 'inscribir' && es_post() && $insc_abiertas) {
    csrf_verificar();
    $errores_f = [];
    if (post('sitio') !== '') exit; // campo trampa contra robots
    $ip = ip_cliente();
    if ((int)valor("SELECT COUNT(*) FROM solicitudes_equipo WHERE ip = ? AND creado >= datetime('now', '-1 hour')", [$ip]) >= 3) $errores_f[] = 'Demasiadas solicitudes seguidas. Intente más tarde.';
    $tsel = null;
    foreach ($insc_abiertas as $x) if ((int)$x['id'] === (int)post('torneo_id')) $tsel = $x;
    if (!$tsel) $errores_f[] = 'Elija el campeonato.';
    $nombre = mb_substr(post('nombre'), 0, 80);
    if (mb_strlen($nombre) < 3) $errores_f[] = 'Escriba el nombre del equipo.';
    elseif (fila('SELECT id FROM equipos WHERE liga_id = ? AND lower(nombre) = lower(?)', [$lid, $nombre]) || fila("SELECT id FROM solicitudes_equipo WHERE liga_id = ? AND lower(nombre) = lower(?) AND estado = 'pendiente'", [$lid, $nombre])) $errores_f[] = 'Ya existe (o está en trámite) un equipo con ese nombre.';
    if (mb_strlen(post('delegado')) < 5) $errores_f[] = 'Escriba el nombre completo del delegado.';
    $ced = strtoupper(preg_replace('/\s+/', '', post('cedula')));
    if ($err = error_documento($ced)) $errores_f[] = $err;
    $email = mb_strtolower(post('email'), 'UTF-8');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores_f[] = 'Escriba un correo válido (será su usuario).';
    elseif (fila('SELECT id FROM usuarios WHERE lower(email) = ?', [$email])) $errores_f[] = 'Ese correo ya tiene una cuenta. Use otro.';
    if (strlen(post('telefono')) < 7) $errores_f[] = 'Escriba un teléfono (WhatsApp) de contacto.';
    if (strlen(post('clave')) < 8) $errores_f[] = 'La contraseña debe tener al menos 8 caracteres.';
    elseif (post('clave') !== post('clave2')) $errores_f[] = 'Las dos contraseñas no coinciden.';
    if (post('acepto') !== '1') $errores_f[] = 'Debe aceptar el uso de los datos para la organización del campeonato.';
    if (!$errores_f) {
        try {
            contexto_auditoria(['usuario' => 'Público: ' . mb_substr(post('delegado'), 0, 60) . ' <' . $email . '>', 'rol' => 'publico', 'liga_id' => $lid, 'origen' => 'publico']);
            $esc = subir_imagen('escudo', 'escudos', 400) ?? '';
            q('INSERT INTO solicitudes_equipo (liga_id, torneo_id, nombre, abreviatura, escudo, color, delegado, cedula, telefono, email, clave, ip) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
                $lid, $tsel['id'], $nombre, mb_strtoupper(mb_substr(post('abreviatura'), 0, 4)), $esc, color_valido(post('color'), '#333333'),
                mb_substr(post('delegado'), 0, 80), $ced, mb_substr(post('telefono'), 0, 30), $email, password_hash(post('clave'), PASSWORD_DEFAULT), $ip,
            ]);
            $sole_id = (int)db()->lastInsertId();
            registrar_consentimiento('inscripcion_equipo', $lid, post('delegado'), 'titular', ['ref_tabla' => 'solicitudes_equipo', 'ref_id' => $sole_id, 'documento' => $ced,
                'texto' => 'Autorizo a la liga a usar estos datos (y los de los jugadores que inscriba, con su autorización) solo para la organización del campeonato, según la política de privacidad, incluido su almacenamiento en servidores fuera del Ecuador.']); // (v24)
            aviso_admins($liga, '🆕 <b>Equipo nuevo quiere inscribirse</b>' . "\n" . e($nombre) . ' · delegado ' . e(post('delegado')) . ' · ' . e(post('telefono')) . "\nRevíselo en Inscripciones.", 'sole:' . $sole_id);
            flash('¡Solicitud enviada! La liga revisará la inscripción de ' . $nombre . '. Cuando sea aprobada podrá ingresar con su correo y contraseña para inscribir a sus jugadores.');
            ir($base . '&v=inscribir');
        } catch (RuntimeException $ex) {
            $errores_f[] = $ex->getMessage();
        }
    }
}
/* (v7) Votación del público */
if ($v === 'votar' && es_post() && isset($pestanas['votar'])) {
    csrf_verificar();
    $vt = fila('SELECT * FROM votaciones WHERE id = ? AND liga_id = ?', [(int)post('votacion_id'), $lid]);
    try {
        if (!$vt) throw new RuntimeException('Votación no encontrada.');
        contexto_auditoria(['usuario' => 'Público (votación)', 'rol' => 'publico', 'liga_id' => $lid, 'origen' => 'publico']);
        votar($vt, (int)post('opcion_id'));
        flash('¡Gracias! Su voto quedó registrado.');
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    }
    ir($base . '&v=votar#vot' . (int)post('votacion_id'));
}

/* (v7) Polla de pronósticos */
$polla_yo = isset($pestanas['polla']) ? polla_participante($liga) : null;
if ($v === 'polla' && es_post() && isset($pestanas['polla'])) {
    csrf_verificar();
    contexto_auditoria(['usuario' => 'Público (polla)', 'rol' => 'publico', 'liga_id' => $lid, 'origen' => 'publico']);
    try {
        $acc = post('accion');
        if ($acc === 'registrar') {
            if (post('sitio') !== '') exit;
            if (post('acepto') !== '1') throw new RuntimeException('Debe aceptar las condiciones de la polla.');
            $pp_id = polla_registrar($liga, post('nombre'), post('telefono'), post('pin'));
            registrar_consentimiento('polla', $lid, post('nombre'), 'titular', ['ref_tabla' => 'polla_participantes', 'ref_id' => (int)$pp_id,
                'texto' => 'Acepto que la liga use mi nombre y celular solo para la polla y para contactarme si gano un premio, según la política de privacidad (datos guardados en servidores fuera del Ecuador). El celular no se publica.']); // (v24)
            polla_iniciar_sesion($liga, $pp_id);
            flash('¡Listo! Ya está en la polla. Escriba sus pronósticos y presione Guardar.');
        } elseif ($acc === 'ingresar') {
            polla_iniciar_sesion($liga, polla_ingresar($liga, post('telefono'), post('pin')));
            flash('Bienvenido de nuevo.');
        } elseif ($acc === 'salir') {
            setcookie('polla_' . $lid, '', ['expires' => time() - 3600, 'path' => '/']);
            ir($base . '&v=polla');
        } elseif ($acc === 'pronosticos' && $polla_yo) {
            $n = 0;
            $cerrados = 0;
            foreach ((array)($_POST['pl'] ?? []) as $pid => $gl) {
                $gv = $_POST['pv'][$pid] ?? '';
                if (!is_string($gl) || !is_string($gv) || $gl === '' || $gv === '') continue;
                $pp = fila('SELECT p.* FROM partidos p JOIN torneos t ON t.id = p.torneo_id WHERE p.id = ? AND t.liga_id = ?', [(int)$pid, $lid]);
                if (!$pp) continue;
                if (!polla_abierto($pp)) { $cerrados++; continue; }
                q('INSERT INTO polla_pronosticos (participante_id, partido_id, goles_local, goles_visitante, actualizado) VALUES (?,?,?,?,?)
                   ON CONFLICT(participante_id, partido_id) DO UPDATE SET goles_local = excluded.goles_local, goles_visitante = excluded.goles_visitante, actualizado = excluded.actualizado',
                   [$polla_yo['id'], (int)$pid, entero($gl, 0, 200), entero($gv, 0, 200), date('Y-m-d H:i:s')]);
                $n++;
            }
            flash($n . ' pronóstico(s) guardado(s).' . ($cerrados ? ' ' . $cerrados . ' partido(s) ya empezaron y no se pudieron cambiar.' : ''));
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    }
    ir($base . '&v=polla');
}

if (!isset($pestanas[$v])) $v = 'inicio';

function tabla_html(array $grupos, string $base, bool $compacta = false, ?array $T = null): string
{
    $T = $T ?? textos_deporte('futbol');
    $emp = $T['con_empate'];
    $volley = $T['deporte'] === 'ecuavoley';
    ob_start();
    foreach ($grupos as $grupo => $filas_t): ?>
      <?php if ($grupo !== '' || count($grupos) > 1): ?><h3>Grupo <?= e($grupo) ?></h3><?php endif; ?>
      <div class="tabla-scroll"><table>
        <tr><th>#</th><th>Equipo</th><th class="num">PJ</th><?php if (!$compacta): ?><th class="num">G</th><?php if ($emp): ?><th class="num">E</th><?php endif; ?><th class="num">P</th><th class="num"><?= e($T['gf']) ?></th><th class="num"><?= e($T['gc']) ?></th><?php endif; ?><th class="num"><?= e($T['dg']) ?></th><?php if ($volley && !$compacta): ?><th class="num" title="Diferencia de puntos en los sets">DP</th><?php endif; ?><th class="num">Pts</th><?php if (!$compacta): ?><th>Últimos</th><?php endif; ?></tr>
        <?php foreach ($filas_t as $i => $f): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><a class="eq-celda" style="color:inherit;text-decoration:none" href="<?= e($base . '&e=' . $f['id']) ?>"><?= escudo($f['escudo'], $f['nombre'], $f['color']) ?> <?= e($f['nombre']) ?></a></td>
            <td class="num"><?= $f['pj'] ?></td>
            <?php if (!$compacta): ?><td class="num"><?= $f['pg'] ?></td><?php if ($emp): ?><td class="num"><?= $f['pe'] ?></td><?php endif; ?><td class="num"><?= $f['pp'] ?></td><td class="num"><?= $f['gf'] ?></td><td class="num"><?= $f['gc'] ?></td><?php endif; ?>
            <td class="num"><?= $f['dg'] > 0 ? '+' : '' ?><?= $f['dg'] ?></td>
            <?php if ($volley && !$compacta): $dp = $f['pf'] - $f['pc']; ?><td class="num"><?= $dp > 0 ? '+' : '' ?><?= $dp ?></td><?php endif; ?>
            <td class="num pts"><?= $f['pts'] ?><?= $f['sancion'] ? '<sup title="Puntos restados por sanción" style="color:var(--roja)">*</sup>' : '' ?></td>
            <?php if (!$compacta): ?><td><span class="forma"><?php foreach ($f['ultimos'] as $r): ?><span class="<?= $r ?>"><?= $r ?></span><?php endforeach; ?></span></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </table></div>
    <?php endforeach;
    return (string)ob_get_clean();
}

/** Partidos en vivo de la liga + los terminados y los que faltan hoy. */
function lista_en_vivo(int $lid, string $base): void
{
    $hoy = date('Y-m-d');
    $sql = "SELECT p.*, el.nombre AS local_nombre, el.escudo AS local_escudo, el.color AS local_color,
                   ev.nombre AS visitante_nombre, ev.escudo AS visitante_escudo, ev.color AS visitante_color, t.nombre AS torneo
            FROM partidos p JOIN torneos t ON t.id = p.torneo_id JOIN equipos el ON el.id = p.local_id JOIN equipos ev ON ev.id = p.visitante_id
            WHERE t.liga_id = ? AND ";
    $vivos = filas($sql . "p.estado = 'en_vivo' ORDER BY p.hora, p.id", [$lid]);
    $hoy_fin = filas($sql . "p.estado IN ('jugado','wo') AND p.fecha = ? ORDER BY p.hora_fin DESC, p.id DESC", [$lid, $hoy]);
    $hoy_pend = filas($sql . "p.estado = 'programado' AND p.fecha = ? ORDER BY p.hora, p.id", [$lid, $hoy]);
    echo '<h2>En vivo ahora</h2>';
    if (!$vivos) echo '<p class="suave">No hay partidos en juego en este momento.</p>';
    foreach ($vivos as $p) {
        echo '<div data-estado="en_vivo">' . tarjeta_partido($p, $base . '&p=' . (int)$p['id']) . '</div>';
    }
    if ($hoy_pend) {
        echo '<h2>Más tarde hoy</h2>';
        foreach ($hoy_pend as $p) echo tarjeta_partido($p, $base . '&p=' . (int)$p['id']);
    }
    if ($hoy_fin) {
        echo '<h2>Terminados hoy</h2>';
        foreach ($hoy_fin as $p) echo tarjeta_partido($p, $base . '&p=' . (int)$p['id']);
    }
}

if ($v === 'envivo' && get('parcial') === '1') {
    lista_en_vivo($lid, $base);
    exit;
}

cabecera_publica(($t ? $t['nombre'] . ' · ' : '') . $liga['nombre'], $liga);
// (v37) Olimpiadas: aviso del evento en la portada de la liga y en cada campeonato que es disciplina de un evento
if (olimp_activo($liga) && $v !== 'reservar') echo olimp_html_aviso_publico($liga, $t, $base, get('t') === '');

if ($v === 'reservar' && $canchas_res):
    // (v8) Reserva de canchas (no depende de ningún campeonato)
    $fres = get('fecha') !== '' && strtotime(get('fecha')) ? max(date('Y-m-d'), date('Y-m-d', strtotime(get('fecha')))) : date('Y-m-d');
    $hecha = !empty($_SESSION['reserva_hecha']) ? fila('SELECT r.*, c.nombre AS cancha FROM reservas r JOIN canchas c ON c.id = r.cancha_id WHERE r.id = ?', [(int)$_SESSION['reserva_hecha']]) : null;
    unset($_SESSION['reserva_hecha']);
    $wa_res = telefono_wa($liga['reservas_whatsapp'] !== '' ? $liga['reservas_whatsapp'] : (string)$liga['contacto_telefono']);
    if ($t) echo html_pestanas_publicas($pestanas, $v, $base . '&t=' . (int)$t['id']); // (v19) pestañas con ícono
    foreach (tomar_flash() as [$tipo_f, $msg_f]) echo '<div class="aviso aviso-' . e($tipo_f) . '">' . e($msg_f) . '</div>';
?>
  <h2>🏟 Reservar cancha</h2>
  <?php if ($hecha): $msg_wa = 'Hola, hice la reserva ' . $hecha['codigo'] . ': ' . $hecha['cancha'] . ' el ' . fecha_bonita($hecha['fecha']) . ' de ' . $hecha['hora_inicio'] . ' a ' . $hecha['hora_fin'] . ' a nombre de ' . $hecha['cliente_nombre'] . '.'; ?>
    <div class="tarjeta" style="border:2px solid var(--acento)">
      <h3 style="margin-top:0">✓ ¡Reserva recibida! Código <?= e($hecha['codigo']) ?></h3>
      <p><?= e($hecha['cancha']) ?> · <?= e(fecha_bonita($hecha['fecha'])) ?> de <?= e($hecha['hora_inicio']) ?> a <?= e($hecha['hora_fin']) ?><?= (float)$hecha['precio'] > 0 ? ' · Valor: <b>' . dinero($hecha['precio']) . '</b>' : '' ?></p>
      <p>Queda <b>por confirmar</b>. <?= $wa_res ? 'Escríbanos para confirmarla:' : 'La liga se comunicará con usted.' ?></p>
      <?php if ($wa_res): ?><a class="btn" target="_blank" href="https://wa.me/<?= e($wa_res) ?>?text=<?= rawurlencode($msg_wa) ?>">📲 Confirmar por WhatsApp</a><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($liga['reservas_texto'] !== ''): ?><div class="aviso aviso-info"><?= nl2br(e($liga['reservas_texto'])) ?></div><?php endif; ?>
  <form method="get" class="acciones" style="margin:8px 0"><input type="hidden" name="l" value="<?= e($liga['slug']) ?>"><input type="hidden" name="v" value="reservar">
    <label>Día: <input type="date" name="fecha" value="<?= e($fres) ?>" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+60 days')) ?>" onchange="this.form.submit()"></label> <b><?= e(fecha_bonita($fres)) ?></b></form>
  <div class="agenda-canchas">
  <?php foreach ($canchas_res as $c): ?>
    <div class="agenda-col"><h3><?= e($c['nombre']) ?><?= (float)$c['precio_hora'] > 0 ? ' <small class="suave">' . dinero($c['precio_hora']) . ' la hora</small>' : '' ?></h3>
      <?php if ($c['direccion'] !== ''): ?><p class="suave" style="margin:0 0 6px;font-size:.85rem">📍 <?= e($c['direccion']) ?></p><?php endif; ?>
      <?php foreach (turnos_cancha($c, $fres) as $tu): ?>
        <?php if ($tu['libre']): ?><button type="button" class="turno libre" onclick="elegirTurno(<?= (int)$c['id'] ?>, '<?= e($tu['ini']) ?>', <?= json_encode($c['nombre'] . ' · ' . $tu['ini'] . ' a ' . $tu['fin']) ?>)"><b><?= e($tu['ini']) ?></b> Libre</button>
        <?php else: ?><div class="turno ocupado"><b><?= e($tu['ini']) ?></b> <?= $tu['motivo'] === 'Ya pasó' ? 'Ya pasó' : 'Ocupado' ?></div><?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  </div>
  <div class="tarjeta" id="form-reserva" style="display:none;margin-top:12px">
    <h3 style="margin-top:0">Reservar: <span id="turno-txt"></span></h3>
    <form method="post" action="<?= e($base . '&v=reservar') ?>">
      <?= csrf_campo() ?><input type="hidden" name="cancha_id" id="r-cancha"><input type="hidden" name="hora" id="r-hora"><input type="hidden" name="fecha" value="<?= e($fres) ?>">
      <input type="text" name="sitio" value="" style="display:none" tabindex="-1" autocomplete="off">
      <div class="campos">
        <div><label>Su nombre</label><input type="text" name="nombre" required maxlength="80"></div>
        <div><label>Celular (WhatsApp)</label><input type="tel" name="telefono" required maxlength="20" inputmode="numeric"></div>
        <div><label>Turnos seguidos</label><select name="turnos"><option value="1">1</option><option value="2">2</option><option value="3">3</option></select></div>
      </div>
      <label class="check" style="font-weight:400"><input type="checkbox" name="acepto" value="1" required> Acepto las condiciones y que la liga use mi nombre y celular solo para esta reserva (<a href="<?= e($base . '&v=privacidad') ?>" target="_blank">privacidad</a>; datos guardados en servidores fuera del Ecuador).</label>
      <button class="btn btn-grande" style="margin-top:8px">Reservar</button>
    </form>
  </div>
  <script>
  function elegirTurno(c, h, txt) {
    document.getElementById('r-cancha').value = c; document.getElementById('r-hora').value = h;
    document.getElementById('turno-txt').textContent = txt;
    var f = document.getElementById('form-reserva'); f.style.display = ''; f.scrollIntoView({ behavior: 'smooth' });
  }
  </script>
<?php pie_publico(); return; endif;

if (!$t): ?>
  <div class="tarjeta"><h2>Próximamente</h2><p>Esta liga todavía no tiene campeonatos publicados.</p></div>
<?php pie_publico(); return; endif;

// (v22) Campeonato de carreras (autocross, ciclismo): clasificación por categoría en vez de tabla de equipos
if (es_carrera($t)) { require __DIR__ . '/carrera.php'; pie_publico(); return; }
// (v25) Duelos (tenis, pádel, tenis de mesa, artes marciales, boxeo) y bolos
if (es_duelo($t)) { require __DIR__ . '/duelo.php'; pie_publico(); return; }
if (es_bolos($t)) { require __DIR__ . '/bolos.php'; pie_publico(); return; }
if (es_pruebas($t)) { require __DIR__ . '/pruebas.php'; pie_publico(); return; } // (v26) natación y atletismo

$bt = $base . '&t=' . (int)$t['id'];
$url_t = url_torneo($liga, $t);
$hay_jugados = (int)valor("SELECT COUNT(*) FROM partidos WHERE torneo_id = ? AND estado IN ('jugado','wo')", [$t['id']]) > 0;
?>
<div class="fila-titulo">
  <h1 style="margin:.2rem 0"><?= e($t['nombre']) ?> <small><?= e($t['temporada']) ?> · <?= $T['icono'] ?> <?= e(nombre_modalidad($t['modalidad'])) ?><?= $t['categoria'] !== '' ? ' · ' . e($t['categoria']) : '' ?></small></h1>
  <?php if (count($torneos) > 1): ?>
    <form method="get"><input type="hidden" name="l" value="<?= e($liga['slug']) ?>">
      <select name="t" onchange="this.form.submit()" aria-label="Campeonato">
        <?php foreach ($torneos as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)$x['id'] === (int)$t['id'] ? 'selected' : '' ?>><?= e($x['nombre'] . ' ' . $x['temporada']) ?></option><?php endforeach; ?>
      </select></form>
  <?php endif; ?>
  <form method="get" action="index.php" class="buscar-liga" role="search"><input type="hidden" name="l" value="<?= e($liga['slug']) ?>"><input type="hidden" name="t" value="<?= (int)$t['id'] ?>"><input type="hidden" name="v" value="buscar"><input type="search" name="q" placeholder="🔎 Buscar jugador o equipo" minlength="2" required aria-label="Buscar jugador o equipo"></form>
</div>
<p class="suave" style="margin:-4px 0 6px;font-size:.85rem">🔗 <a href="<?= e($url_t) ?>"><?= e(preg_replace('#^https?://#', '', $url_t)) ?></a>
  <button type="button" class="btn btn-sec btn-mini" onclick="navigator.clipboard && navigator.clipboard.writeText('<?= e($url_t) ?>'); this.textContent='✓ copiado'">copiar</button>
  <?php if ($hay_jugados): ?> · <a href="<?= e($bt . '&v=boletin') ?>">📰 Boletín de la fecha</a><?php endif; ?></p>
<?= html_pestanas_publicas($pestanas, $v, $bt) // (v19) pestañas grandes con ícono, fijas arriba al bajar ?>

<?php
$enlace_partido = fn($p) => $bt . '&p=' . (int)$p['id'];

if ($v === 'envivo'): ?>
  <div id="zona-vivo" data-url="<?= e($bt . '&v=envivo&parcial=1') ?>"><?php lista_en_vivo($lid, $base); ?></div>
  <p class="suave" style="font-size:.8rem">Los marcadores en vivo se actualizan solos cada 15 segundos.</p>
  <?= script_refresco() ?>
<?php elseif ($v === 'inicio'):
    if ((int)($liga['mostrar_cumples'] ?? 1) === 1) echo html_cumples($liga, $base);
    $vivos_t = partidos_torneo((int)$t['id'], "AND p.estado = 'en_vivo'");
    if ($vivos_t): ?>
      <h2>🔴 En vivo ahora</h2>
      <?php foreach ($vivos_t as $p) echo tarjeta_partido($p, $enlace_partido($p)); ?>
    <?php endif;
    $proximos = partidos_torneo((int)$t['id'], "AND p.estado NOT IN ('jugado','wo','en_vivo')");
    $ultimos = array_reverse(partidos_torneo((int)$t['id'], "AND p.estado IN ('jugado','wo')"));
    $gol = goleadores((int)$t['id'], 5);
?>
  <div class="rejilla-2">
    <div>
      <h2>Últimos resultados</h2>
      <?php if (!$ultimos): ?><p class="suave">Aún no se juegan partidos.</p><?php endif; ?>
      <?php foreach (array_slice($ultimos, 0, 5) as $p) echo tarjeta_partido($p, $enlace_partido($p)); ?>
      <h2>Próximos partidos</h2>
      <?php if (!$proximos): ?><p class="suave">No hay partidos programados.</p><?php endif; ?>
      <?php foreach (array_slice($proximos, 0, 5) as $p) echo tarjeta_partido($p, $enlace_partido($p)); ?>
    </div>
    <div>
      <h2>Posiciones</h2>
      <?= tabla_html(tabla_posiciones((int)$t['id']), $base, true, $T) ?>
      <p><a href="<?= e($bt . '&v=posiciones') ?>">Ver tabla completa →</a></p>
      <?php if ($T['con_goleadores']): ?>
      <h2><?= e($T['goleadores']) ?></h2>
      <?php if (!$gol): ?><p class="suave">Aún no hay <?= e(mb_strtolower($T['goles'])) ?>.</p><?php endif; ?>
      <?php foreach ($gol as $i => $g): ?>
        <a class="tarjeta partido-link eq-celda" style="padding:10px;margin-bottom:8px" href="<?= e($base . '&j=' . $g['id']) ?>">
          <strong style="width:20px"><?= $i + 1 ?></strong> <?= foto_jugador($g) ?> <span style="flex:1"><strong><?= e(nombre_jugador($g)) ?></strong><br><small><?= e($g['equipo']) ?></small></span> <strong style="font-size:1.3rem"><?= $T['icono'] ?> <?= (int)$g['goles'] ?></strong>
        </a>
      <?php endforeach; endif; ?>
    </div>
  </div>

<?php elseif ($v === 'documentos'): ?>
  <h2>📄 Documentos</h2>
  <?php if (!$docs_pub): ?><p class="suave">La liga todavía no publica documentos.</p><?php endif; ?>
  <?php foreach ($docs_pub as $d): ?>
    <a class="tarjeta partido-link eq-celda" style="padding:12px;margin-bottom:8px" href="<?= e($d['archivo']) ?>" target="_blank" rel="noopener">
      <span style="font-size:1.8rem"><?= icono_documento($d['archivo']) ?></span>
      <span style="flex:1"><strong><?= e($d['titulo']) ?></strong><br><small><?= e(tipos_documento()[$d['tipo']] ?? 'Documento') ?><?= $d['torneo'] ? ' · ' . e($d['torneo']) : '' ?> · publicado el <?= e(fecha_bonita(substr($d['creado'], 0, 10))) ?></small></span>
      <span class="btn btn-sec btn-chico">Ver</span>
    </a>
  <?php endforeach; ?>

<?php elseif ($v === 'buscar'):
    $qb = mb_substr(trim(get('q')), 0, 60);
    $res_b = $qb !== '' ? buscar_en_liga($lid, $qb) : null; ?>
  <h2>🔎 Buscar jugador o equipo</h2>
  <form method="get" action="index.php" class="acciones" style="margin-bottom:12px">
    <input type="hidden" name="l" value="<?= e($liga['slug']) ?>"><?php if ($t): ?><input type="hidden" name="t" value="<?= (int)$t['id'] ?>"><?php endif; ?><input type="hidden" name="v" value="buscar">
    <input type="search" name="q" value="<?= e($qb) ?>" placeholder="Nombre del jugador o del equipo" minlength="2" required style="flex:1;min-width:200px" aria-label="Buscar">
    <button class="btn">Buscar</button>
  </form>
  <?php if ($res_b !== null): ?>
    <?php if (!$res_b['jugadores'] && !$res_b['equipos']): ?><p class="suave">No se encontró "<?= e($qb) ?>". Revise cómo está escrito o busque solo por el apellido.</p><?php endif; ?>
    <?php if ($res_b['equipos']): ?><h3>Equipos</h3><div class="rejilla res-busca">
      <?php foreach ($res_b['equipos'] as $eb): ?><a class="tarjeta partido-link eq-celda" href="<?= e($base . '&e=' . (int)$eb['id']) ?>"><?= escudo($eb['escudo'], $eb['nombre'], $eb['color']) ?> <strong><?= e($eb['nombre']) ?></strong></a><?php endforeach; ?>
    </div><?php endif; ?>
    <?php if ($res_b['jugadores']): ?><h3>Jugadores</h3><div class="rejilla res-busca">
      <?php foreach ($res_b['jugadores'] as $jb): ?><a class="tarjeta partido-link eq-celda" href="<?= e($base . '&j=' . (int)$jb['id']) ?>"><?= foto_jugador($jb) ?> <span class="txt"><strong><?= e(nombre_jugador($jb)) ?></strong><br><small><?= e($jb['equipo']) ?><?= $jb['numero'] !== null ? ' · #' . (int)$jb['numero'] : '' ?></small></span></a><?php endforeach; ?>
    </div>
    <?php if (!empty($res_b['mas'])): ?><p class="suave">Hay más resultados: escriba también el apellido para acotar.</p><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>

<?php elseif ($v === 'posiciones'): ?>
  <?= tabla_html(tabla_posiciones((int)$t['id']), $base, false, $T) ?>
  <?php $sanc_eq = filas('SELECT s.*, e.nombre FROM sanciones_equipo s JOIN equipos e ON e.id = s.equipo_id WHERE s.torneo_id = ? AND s.puntos <> 0 ORDER BY s.fecha, s.id', [$t['id']]);
  if ($sanc_eq): ?>
    <div class="aviso aviso-error" style="margin-top:10px"><strong>* Puntos restados por la comisión disciplinaria:</strong><br>
      <?php foreach ($sanc_eq as $x): ?>• <?= e($x['nombre']) ?>: −<?= (int)$x['puntos'] ?> pts<?= $x['motivo'] !== '' ? ' (' . e($x['motivo']) . ')' : '' ?><br><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <p class="suave" style="font-size:.85rem">PJ: jugados · G: ganados<?= $T['con_empate'] ? ' · E: empatados' : '' ?> · P: perdidos · <?= e($T['gf'] . '/' . $T['gc']) ?>: <?= e($T['a_favor'] . ' / ' . $T['en_contra']) ?> · <?= e($T['dg']) ?>: diferencia<?= $T['deporte'] === 'ecuavoley' ? ' · DP: diferencia de puntos en los sets' : '' ?> · Pts: puntos (<?= (int)$t['puntos_ganado'] ?> por victoria<?= $T['con_empate'] ? ', ' . (int)$t['puntos_empate'] . ' por empate' : '' ?><?= (int)$t['puntos_perdido'] > 0 ? ', ' . (int)$t['puntos_perdido'] . ' por derrota' : '' ?>).</p>
  <?php
  $elim = partidos_torneo((int)$t['id'], "AND p.fase <> 'regular'");
  if ($elim): ?>
    <h2>Fase final</h2>
    <?php $fa = null; foreach ($elim as $p) { if ($p['fase'] !== $fa) { echo '<div class="jornada-titulo">' . e(nombre_fase($p['fase'])) . '</div>'; $fa = $p['fase']; } echo tarjeta_partido($p, $enlace_partido($p)); } ?>
  <?php endif; ?>

<?php elseif ($v === 'acumulada'):
    $ac = null;
    foreach ($acumuladas as $x) if ((int)$x['id'] === (int)get('a')) $ac = $x;
    $ac = $ac ?? $acumuladas[0];
    $ts_ac = torneos_acumulada($ac);
?>
  <div class="fila-titulo"><h2 style="margin:.4rem 0"><?= e($ac['nombre']) ?></h2>
  <?php if (count($acumuladas) > 1): ?><form method="get"><input type="hidden" name="l" value="<?= e($liga['slug']) ?>"><input type="hidden" name="t" value="<?= (int)$t['id'] ?>"><input type="hidden" name="v" value="acumulada">
    <select name="a" onchange="this.form.submit()"><?php foreach ($acumuladas as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)$x['id'] === (int)$ac['id'] ? 'selected' : '' ?>><?= e($x['nombre']) ?></option><?php endforeach; ?></select></form><?php endif; ?></div>
  <p class="suave" style="margin-top:0">Suma de: <?= e(implode(' + ', array_map(fn($x) => trim($x['nombre'] . ' ' . $x['temporada']), $ts_ac))) ?> (solo fase regular).</p>
  <?= tabla_html(['' => tabla_acumulada($ac)], $base, false, $T) ?>
  <?php if ($ac['nota'] !== ''): ?><p class="aviso aviso-info"><?= nl2br(e($ac['nota'])) ?></p><?php endif; ?>

<?php elseif ($v === 'fixture'):
    $partidos = partidos_torneo((int)$t['id']);
    $clave_actual = null;
    // (v29) Plan Profesional: fixture completo para imprimir y esquema de todo el campeonato
    if (fixture_pro_activo($liga, true)) {
        echo '<div class="acciones no-imprimir" style="margin:6px 0 10px"><a class="btn btn-sec btn-chico" target="_blank" href="' . e($bt . '&v=fixture_pdf') . '">🖨 Imprimir fixture completo</a> '
            . '<a class="btn btn-sec btn-chico" target="_blank" href="' . e($bt . '&v=esquema') . '">🗺 Esquema del campeonato</a></div>';
        echo '<details class="tarjeta" style="margin-bottom:12px"><summary><strong>🗺 Ver el esquema de todo el campeonato</strong></summary>' . html_esquema_campeonato($t, $bt . '&p=') . '</details>';
    }
    if (!$partidos) echo '<p class="suave">El fixture aún no está publicado.</p>';
    foreach ($partidos as $p) {
        $clave = $p['fase'] === 'regular' ? 'Jornada ' . (int)$p['jornada'] : nombre_fase($p['fase']);
        if ($clave !== $clave_actual) {
            echo '<div class="jornada-titulo">' . e($clave) . '</div>';
            $clave_actual = $clave;
        }
        echo tarjeta_partido($p, $enlace_partido($p));
    }

elseif ($v === 'goleadores'):
    $gol = goleadores((int)$t['id'], 100);
?>
  <h2>Tabla de <?= e(mb_strtolower($T['goleadores'])) ?></h2>
  <?php if (!$gol): ?><p class="suave">Aún no hay registros de <?= e(mb_strtolower($T['goles'])) ?>.</p><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>#</th><th>Jugador</th><th>Equipo</th><th class="num"><?= e($T['goles']) ?></th><th class="num"><?= $T['deporte'] === 'basquet' ? 'Triples' : 'De penal' ?></th><?php if ($T['deporte'] === 'basquet'): ?><th class="num">Prom.</th><?php endif; ?></tr>
    <?php foreach ($gol as $i => $g): ?>
      <tr><td><?= $i + 1 ?></td>
        <td><a class="eq-celda" style="color:inherit;text-decoration:none" href="<?= e($base . '&j=' . $g['id']) ?>"><?= foto_jugador($g) ?> <?= e(nombre_jugador($g)) ?></a></td>
        <td><div class="eq-celda"><?= escudo($g['escudo'], $g['equipo'], $g['color']) ?> <?= e($g['equipo']) ?></div></td>
        <td class="num pts"><?= (int)$g['goles'] ?></td><td class="num"><?= $T['deporte'] === 'basquet' ? (int)$g['triples'] : (int)$g['penales'] ?></td><?php if ($T['deporte'] === 'basquet'): ?><td class="num"><?= number_format((int)$g['goles'] / max(1, (int)$g['partidos']), 1) ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <?php
  // Valla menos vencida (equipos)
  $vallas = [];
  foreach (tabla_posiciones((int)$t['id']) as $g) foreach ($g as $f) if ($f['pj'] > 0) $vallas[] = $f;
  usort($vallas, fn($a, $b) => [$a['gc'] / $a['pj'], $b['pj']] <=> [$b['gc'] / $b['pj'], $a['pj']]);
  if ($vallas && $T['valla']): ?>
    <h2>Valla menos vencida</h2>
    <div class="tabla-scroll"><table>
      <tr><th>Equipo</th><th class="num">PJ</th><th class="num">Goles recibidos</th><th class="num">Promedio</th></tr>
      <?php foreach (array_slice($vallas, 0, 10) as $f): ?><tr><td><div class="eq-celda"><?= escudo($f['escudo'], $f['nombre'], $f['color']) ?> <?= e($f['nombre']) ?></div></td><td class="num"><?= $f['pj'] ?></td><td class="num"><?= $f['gc'] ?></td><td class="num"><?= number_format($f['gc'] / $f['pj'], 2) ?></td></tr><?php endforeach; ?>
    </table></div>
  <?php endif; ?>

<?php elseif ($v === 'tarjetas'):
    $susp = suspensiones((int)$t['id']);
    $tar = tarjetas((int)$t['id']);
    $fp = fair_play((int)$t['id']);
?>
  <h2>Suspendidos para la próxima fecha</h2>
  <?php if (!$susp): ?><p class="suave">No hay jugadores suspendidos.</p><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>Jugador</th><th>Equipo</th><th class="num">Le faltan</th><th>Motivo y avance</th></tr>
    <?php foreach ($susp as $x): ?><tr><td><a href="<?= e($base . '&j=' . (int)$x['jugador']['id']) ?>"><?= e(nombre_jugador($x['jugador'])) ?></a></td><td><?= e($x['jugador']['equipo']) ?></td><td class="num pts"><?= (int)$x['pendientes'] ?></td><td style="white-space:normal;min-width:220px"><?= html_motivos_suspension($x) ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <?php if ($docs_pub): ?><p style="font-size:.9rem">📄 <a href="<?= e($bt . '&v=documentos') ?>">Ver el reglamento y los documentos de la liga</a></p><?php endif; ?>
  <p class="suave" style="font-size:.85rem">Reglamento: <?= (int)$t['amarillas_suspension'] ?> <?= e($T['amarillas']) ?> = <?= (int)$t['partidos_susp_amarillas'] ?> partido(s) de suspensión · <?= e($T['roja']) ?> = <?= (int)$t['partidos_susp_roja'] ?> partido(s)<?= $t['doble_amarilla_roja'] ? ' · Doble amarilla = roja' : '' ?>.</p>

  <h2><?= e($T['tarjetas']) ?> por jugador</h2>
  <?php if (!$tar): ?><p class="suave">Aún no hay tarjetas.</p><?php else: ?>
  <div class="tabla-scroll"><table>
    <tr><th>Jugador</th><th>Equipo</th><th class="num" title="<?= e($T['amarilla']) ?>"><?= icono_evento('amarilla', $T['deporte']) ?></th><th class="num" title="<?= e($T['roja']) ?>"><?= icono_evento('roja', $T['deporte']) ?></th></tr>
    <?php foreach ($tar as $x): ?><tr><td><a style="color:inherit" href="<?= e($base . '&j=' . $x['id']) ?>"><?= e(nombre_jugador($x)) ?></a></td><td><?= e($x['equipo']) ?></td><td class="num"><?= (int)$x['amarillas'] ?></td><td class="num"><?= (int)$x['rojas'] ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>

  <?php if ($fp): ?>
  <h2>Juego limpio (Fair Play)</h2>
  <div class="tabla-scroll"><table>
    <tr><th>Equipo</th><th class="num"><span class="tarjeta-am"></span></th><th class="num"><span class="tarjeta-ro"></span></th><th class="num">Puntos</th></tr>
    <?php foreach ($fp as $x): ?><tr><td><div class="eq-celda"><?= escudo($x['escudo'], $x['nombre'], $x['color']) ?> <?= e($x['nombre']) ?></div></td><td class="num"><?= (int)$x['amarillas'] ?></td><td class="num"><?= (int)$x['rojas'] ?></td><td class="num"><?= (int)$x['puntos'] ?></td></tr><?php endforeach; ?>
  </table></div>
  <p class="suave" style="font-size:.85rem">Amarilla = 1 punto, roja = 3 puntos. Menos puntos = más limpio.</p>
  <?php endif; ?>

<?php elseif ($v === 'equipos'):
    $equipos = filas('SELECT e.*, te.grupo, (SELECT COUNT(*) FROM jugadores j WHERE j.equipo_id = e.id AND j.activo = 1) AS n FROM torneo_equipos te JOIN equipos e ON e.id = te.equipo_id WHERE te.torneo_id = ? ORDER BY e.nombre', [$t['id']]);
?>
  <div class="rejilla">
    <?php foreach ($equipos as $e): ?>
      <a class="tarjeta partido-link" href="<?= e($base . '&e=' . $e['id']) ?>" style="text-align:center">
        <?= escudo($e['escudo'], $e['nombre'], $e['color'], 'escudo-grande') ?>
        <h3 style="margin:.6rem 0 .2rem"><?= e($e['nombre']) ?></h3>
        <small><?= (int)$e['n'] ?> jugadores<?= $e['grupo'] !== '' ? ' · Grupo ' . e($e['grupo']) : '' ?></small>
      </a>
    <?php endforeach; ?>
  </div>

<?php elseif ($v === 'inscribir'): ?>
  <?php foreach (tomar_flash() as [$tipo_f, $msg_f]): ?><div class="aviso aviso-<?= e($tipo_f) ?>"><?= e($msg_f) ?></div><?php endforeach; ?>
  <?php if (!$insc_abiertas): ?><div class="tarjeta">La inscripción de equipos está cerrada.</div><?php else: ?>
  <?php foreach ($errores_f ?? [] as $er): ?><div class="aviso aviso-error"><?= e($er) ?></div><?php endforeach; ?>
  <div class="tarjeta">
    <h2 style="margin-top:0">Inscribe a tu equipo</h2>
    <p class="suave">Llena el formulario. La liga revisa la solicitud; cuando sea aprobada, el delegado entra con su correo y contraseña a <b><?= e(url_base()) ?>admin.php?p=login</b> para inscribir a sus jugadores con foto.</p>
    <form method="post" enctype="multipart/form-data" action="<?= e($bt . '&v=inscribir') ?>">
      <?= csrf_campo() ?>
      <input type="text" name="sitio" value="" style="display:none" tabindex="-1" autocomplete="off">
      <div class="campos">
        <div><label>Campeonato *</label><select name="torneo_id" required><?php foreach ($insc_abiertas as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['nombre'] . ' ' . $x['temporada']) ?> (hasta <?= e($x['equipos_hasta']) ?>)</option><?php endforeach; ?></select></div>
        <div><label>Nombre del equipo *</label><input type="text" name="nombre" required maxlength="80" value="<?= e(post('nombre')) ?>"></div>
        <div><label>Abreviatura</label><input type="text" name="abreviatura" maxlength="4" value="<?= e(post('abreviatura')) ?>"></div>
        <div><label>Color del equipo</label><input type="color" name="color" value="<?= e(color_valido(post('color'), '#333333')) ?>"></div>
        <div class="campo-ancho"><label>Escudo</label><input type="file" name="escudo" accept="image/*"></div>
        <div><label>Nombre del delegado *</label><input type="text" name="delegado" required maxlength="80" value="<?= e(post('delegado')) ?>"></div>
        <div><label>Cédula del delegado *</label><input type="text" name="cedula" required maxlength="20" inputmode="numeric" value="<?= e(post('cedula')) ?>"></div>
        <div><label>Teléfono / WhatsApp *</label><input type="tel" name="telefono" required maxlength="30" value="<?= e(post('telefono')) ?>"></div>
        <div><label>Correo (será su usuario) *</label><input type="email" name="email" required value="<?= e(post('email')) ?>"></div>
        <div><label>Contraseña (mín. 8) *</label><input type="password" name="clave" required minlength="8"></div>
        <div><label>Repita la contraseña *</label><input type="password" name="clave2" required minlength="8"></div>
        <div class="campo-ancho"><label class="check" style="font-weight:400"><input type="checkbox" name="acepto" value="1" required> Autorizo a la liga a usar estos datos (y los de los jugadores que inscriba, con su autorización) solo para la organización del campeonato, según la <a href="<?= e($base . '&v=privacidad') ?>" target="_blank">política de privacidad</a>, incluido su almacenamiento en servidores fuera del Ecuador.</label></div>
      </div>
      <button class="btn btn-grande">Enviar inscripción</button>
    </form>
  </div>
  <?php endif; ?>

<?php elseif ($v === 'llaves'): ?>
  <h2>🏆 Llaves eliminatorias</h2>
  <?= html_llave((int)$t['id'], $bt . '&p=') ?>
  <p class="suave" style="font-size:.85rem">El ganador de cada partido pasa solo a la siguiente ronda.</p>

<?php elseif ($v === 'estadisticas'):
    $nc = numeros_campeonato((int)$t['id']);
    $asis = asistidores((int)$t['id'], 10);
    $vall = $T['valla'] ? array_slice(vallas_invictas((int)$t['id']), 0, 8) : [];
    $arq = $T['valla'] ? array_slice(arqueros((int)$t['id']), 0, 8) : [];
    $rach = rachas((int)$t['id']);
    $mpj = mas_partidos((int)$t['id'], 10);
?>
  <div class="cifras">
    <div class="cifra"><b><?= $nc['jugados'] ?></b>Partidos jugados</div>
    <div class="cifra"><b><?= $nc['goles'] ?></b><?= e($T['goles']) ?></div>
    <div class="cifra"><b><?= $nc['promedio'] ?></b>Por partido</div>
    <div class="cifra"><b><?= $nc['amarillas'] ?></b><?= e($T['amarilla']) ?>s</div>
  </div>
  <?php if ($nc['mayor']): ?><p>🔥 Partido con más <?= e(mb_strtolower($T['goles'])) ?>: <a href="<?= e($bt . '&p=' . (int)$nc['mayor']['id']) ?>"><?= e($nc['mayor']['local_nombre'] . ' ' . $nc['mayor']['goles_local'] . ' - ' . $nc['mayor']['goles_visitante'] . ' ' . $nc['mayor']['visitante_nombre']) ?></a></p><?php endif; ?>
  <div class="rejilla-2">
    <div>
      <h2>🅰 Asistencias</h2>
      <?php if (!$asis): ?><p class="suave">Aún no hay asistencias registradas (la vocalía las marca con el botón ASISTENCIA).</p><?php else: ?>
      <div class="tabla-scroll"><table><tr><th>#</th><th>Jugador</th><th>Equipo</th><th class="num">Asist.</th></tr>
        <?php foreach ($asis as $i => $g): ?><tr><td><?= $i + 1 ?></td><td><a href="<?= e($base . '&j=' . (int)$g['id']) ?>"><?= e(nombre_jugador($g)) ?></a></td><td><?= e($g['equipo']) ?></td><td class="num pts"><?= (int)$g['asistencias'] ?></td></tr><?php endforeach; ?></table></div>
      <?php endif; ?>
      <h2>📈 Rachas</h2>
      <div class="tabla-scroll"><table><tr><th>Equipo</th><th>Últimos</th><th class="num" title="Partidos seguidos sin perder">Invicto</th><th class="num" title="Mejor racha de victorias">Mejor</th></tr>
        <?php foreach ($rach as $r): ?><tr><td><div class="eq-celda"><?= escudo($r['escudo'], $r['nombre'], $r['color']) ?> <?= e($r['nombre']) ?></div></td>
          <td><span class="forma"><?php foreach ($r['ultimos'] as $u_): ?><span class="<?= $u_ ?>"><?= $u_ ?></span><?php endforeach; ?></span></td><td class="num"><?= $r['invicto'] ?></td><td class="num"><?= $r['mejor'] ?></td></tr><?php endforeach; ?></table></div>
    </div>
    <div>
      <?php if ($vall): ?>
      <h2>🧤 Vallas invictas</h2>
      <div class="tabla-scroll"><table><tr><th>Equipo</th><th class="num">Sin recibir</th></tr>
        <?php foreach ($vall as $x): ?><tr><td><div class="eq-celda"><?= escudo($x['escudo'], $x['nombre'], $x['color']) ?> <?= e($x['nombre']) ?></div></td><td class="num pts"><?= (int)$x['vallas'] ?></td></tr><?php endforeach; ?></table></div>
      <?php endif; ?>
      <?php if ($arq): ?>
      <h2>🧤 Arqueros</h2>
      <div class="tabla-scroll"><table><tr><th>Arquero</th><th>Equipo</th><th class="num">PJ</th><th class="num">Recibidos</th><th class="num">Prom.</th></tr>
        <?php foreach ($arq as $a): ?><tr><td><a href="<?= e($base . '&j=' . (int)$a['id']) ?>"><?= e(nombre_jugador($a)) ?></a></td><td><?= e($a['equipo']) ?></td><td class="num"><?= $a['pj'] ?></td><td class="num"><?= $a['recibidos'] ?></td><td class="num"><?= number_format($a['recibidos'] / max(1, $a['pj']), 2) ?></td></tr><?php endforeach; ?></table></div>
      <p class="suave" style="font-size:.8rem">Cuenta a los jugadores con posición "Arquero" presentes en la vocalía.</p>
      <?php endif; ?>
      <?php if ($mpj): ?>
      <h2>⏱ Más partidos jugados</h2>
      <div class="tabla-scroll"><table><tr><th>Jugador</th><th>Equipo</th><th class="num">PJ</th></tr>
        <?php foreach ($mpj as $x): ?><tr><td><a href="<?= e($base . '&j=' . (int)$x['id']) ?>"><?= e(nombre_jugador($x)) ?></a></td><td><?= e($x['equipo']) ?></td><td class="num pts"><?= (int)$x['pj'] ?></td></tr><?php endforeach; ?></table></div>
      <?php endif; ?>
    </div>
  </div>

<?php elseif ($v === 'historia'):
    $palm = palmares_liga($lid);
    $tit = titulos_por_equipo($lid);
    $gh = goleadores_historicos($lid, 15);
?>
  <h2>🏆 Campeones</h2>
  <div class="tabla-scroll"><table>
    <tr><th>Campeonato</th><th>Campeón</th><th>Subcampeón</th><th><?= e(textos_deporte($liga)['goleador'] ?: 'Goleador') ?></th></tr>
    <?php foreach ($palm as $x): ?>
      <tr><td><b><?= e($x['nombre']) ?></b> <small><?= e($x['temporada']) ?><?= $x['categoria'] !== '' ? ' · ' . e($x['categoria']) : '' ?></small></td>
        <td><?= $x['campeon'] ? '<div class="eq-celda">' . escudo($x['campeon_escudo'], $x['campeon'], $x['campeon_color']) . ' <b>' . e($x['campeon']) . '</b></div>' : '<span class="suave">—</span>' ?></td>
        <td><?= e($x['subcampeon'] ?? '—') ?></td>
        <td><?= $x['goleador'] ? e(nombre_jugador($x['goleador'])) . ' (' . (int)$x['goleador']['goles'] . ')' : '—' ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php if ($tit): ?>
  <h2>Títulos por equipo</h2>
  <div class="tabla-scroll"><table>
    <tr><th>Equipo</th><th class="num">Títulos</th></tr>
    <?php foreach ($tit as $x): ?><tr><td><div class="eq-celda"><?= escudo($x['escudo'], $x['nombre'], $x['color']) ?> <?= e($x['nombre']) ?></div></td><td class="num pts"><?= str_repeat('🏆', min(5, (int)$x['titulos'])) ?> <?= (int)$x['titulos'] ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <?php if ($gh): ?>
  <h2><?= e(textos_deporte($liga)['goleadores'] ?: 'Goleadores') ?> de todos los tiempos</h2>
  <div class="tabla-scroll"><table>
    <tr><th>#</th><th>Jugador</th><th>Equipo actual</th><th class="num">Campeonatos</th><th class="num">Goles</th></tr>
    <?php foreach ($gh as $i => $g): ?><tr><td><?= $i + 1 ?></td><td><a class="eq-celda" style="color:inherit;text-decoration:none" href="<?= e($base . '&j=' . $g['id']) ?>"><?= foto_jugador($g) ?> <?= e(nombre_jugador($g)) ?></a></td><td><?= e($g['equipo']) ?></td><td class="num"><?= (int)$g['campeonatos'] ?></td><td class="num pts"><?= (int)$g['goles'] ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>

<?php elseif ($v === 'votar'): ?>
  <?php foreach (tomar_flash() as [$tipo_f, $msg_f]): ?><div class="aviso aviso-<?= e($tipo_f) ?>"><?= e($msg_f) ?></div><?php endforeach; ?>
  <h2>🗳 Votaciones del público</h2>
  <p class="suave">Vote por su favorito. Un voto por persona en cada votación.</p>
  <?php foreach ($votaciones_pub as $vt):
      $abierta = votacion_abierta($vt);
      $mio = voto_de((int)$vt['id']);
      $res = resultados_votacion((int)$vt['id']);
      $pat = $vt['patrocinador_id'] ? fila('SELECT * FROM patrocinadores WHERE id = ?', [$vt['patrocinador_id']]) : null;
      if ($pat) contar_patrocinio((int)$pat['id'], 'votacion');
      $ver_resultados = !$abierta || $mio !== null; ?>
    <div class="tarjeta votacion" id="vot<?= (int)$vt['id'] ?>">
      <h3 style="margin-top:0"><?= e($vt['titulo']) ?> <?= $abierta ? '<span class="estado-chip chip-ok">Abierta</span>' : '<span class="estado-chip">Cerrada</span>' ?></h3>
      <?php if ($vt['premio'] !== '' || $pat): ?><p style="margin:.2rem 0 .6rem">🎁 <?= e($vt['premio']) ?><?= $pat ? ' · Auspicia: ' . ($pat['logo'] !== '' ? '<img src="' . e($pat['logo']) . '" alt="" class="patro-logo"> ' : '') . '<b>' . e($pat['nombre']) . '</b>' : '' ?></p><?php endif; ?>
      <?php if ($abierta && $vt['cierra'] !== ''): ?><p class="suave" style="margin-top:0">Se puede votar hasta el <?= e(fecha_bonita(substr($vt['cierra'], 0, 10))) ?> a las <?= e(substr($vt['cierra'], 11, 5)) ?>.</p><?php endif; ?>
      <?php if (!$ver_resultados): ?>
        <form method="post" action="<?= e($base . '&v=votar') ?>">
          <?= csrf_campo() ?><input type="hidden" name="votacion_id" value="<?= (int)$vt['id'] ?>">
          <div class="opciones-voto">
            <?php foreach ($res['opciones'] as $o): ?>
              <label class="opcion-voto"><input type="radio" name="opcion_id" value="<?= (int)$o['id'] ?>" required>
                <?= $o['jugador_id'] ? foto_jugador($o) : '' ?> <span><b><?= e($o['texto']) ?></b><?= $o['detalle'] !== '' ? '<br><small>' . e($o['detalle']) . '</small>' : '' ?></span></label>
            <?php endforeach; ?>
          </div>
          <button class="btn btn-grande" style="margin-top:10px">Votar</button>
        </form>
      <?php else: ?>
        <?php if ($mio !== null): ?><p class="suave">✓ Usted ya votó. Así van los resultados:</p><?php endif; ?>
        <?php foreach ($res['opciones'] as $i => $o): ?>
          <div class="barra-voto<?= $mio === (int)$o['id'] ? ' mio' : '' ?>">
            <div class="barra-voto-txt"><?= !$abierta && $i === 0 && $o['votos'] > 0 ? '🏆 ' : '' ?><b><?= e($o['texto']) ?></b><?= $o['detalle'] !== '' ? ' <small>' . e($o['detalle']) . '</small>' : '' ?><span><?= (int)$o['pct'] ?>% · <?= (int)$o['votos'] ?></span></div>
            <div class="barra-voto-fondo"><div style="width:<?= (int)$o['pct'] ?>%"></div></div>
          </div>
        <?php endforeach; ?>
        <p class="suave" style="font-size:.85rem"><?= (int)$res['total'] ?> voto(s) en total.</p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

<?php elseif ($v === 'polla'):
    $pl_partidos = polla_partidos($liga);
    $mis = [];
    if ($polla_yo) foreach (filas('SELECT * FROM polla_pronosticos WHERE participante_id = ?', [$polla_yo['id']]) as $x) $mis[(int)$x['partido_id']] = $x;
    $ranking = polla_ranking($liga, 50);
    $pats_polla = patrocinadores_activos($lid, 'en_pagina');
?>
  <?php foreach (tomar_flash() as [$tipo_f, $msg_f]): ?><div class="aviso aviso-<?= e($tipo_f) ?>"><?= e($msg_f) ?></div><?php endforeach; ?>
  <h2>🎯 Polla de <?= e($liga['nombre']) ?></h2>
  <p>Adivine los resultados de los próximos partidos y sume puntos: <b><?= (int)$liga['polla_exacto'] ?> puntos</b> si acierta el marcador exacto y <b><?= (int)$liga['polla_resultado'] ?></b> si acierta solo quién gana (o el empate). <b>Es gratis</b>: no se apuesta dinero.</p>
  <?php if ($liga['polla_premio'] !== ''): ?><div class="aviso aviso-info">🎁 <b>Premio:</b> <?= e($liga['polla_premio']) ?></div><?php endif; ?>
  <div class="rejilla-2">
    <div>
    <?php if (!$polla_yo): ?>
      <div class="tarjeta">
        <h3 style="margin-top:0">Participar (gratis)</h3>
        <form method="post" action="<?= e($base . '&v=polla') ?>">
          <?= csrf_campo() ?><input type="hidden" name="accion" value="registrar">
          <input type="text" name="sitio" value="" style="display:none" tabindex="-1" autocomplete="off">
          <p><label>Nombre o apodo (se ve en la tabla)</label><input type="text" name="nombre" required maxlength="40"></p>
          <p><label>Celular</label><input type="tel" name="telefono" required maxlength="15" inputmode="numeric" placeholder="09..."></p>
          <p><label>Clave de 4 números (para entrar desde otro celular)</label><input type="password" name="pin" required pattern="[0-9]{4}" maxlength="4" inputmode="numeric"></p>
          <label class="check" style="font-weight:400"><input type="checkbox" name="acepto" value="1" required> Acepto que la liga use mi nombre y celular solo para la polla y para contactarme si gano un premio (<a href="<?= e($base . '&v=privacidad') ?>" target="_blank">privacidad</a>; datos guardados en servidores fuera del Ecuador). El celular no se publica.</label>
          <button class="btn btn-grande" style="margin-top:8px">Entrar a la polla</button>
        </form>
        <details style="margin-top:12px"><summary><b>Ya estoy registrado</b></summary>
          <form method="post" action="<?= e($base . '&v=polla') ?>" style="margin-top:8px">
            <?= csrf_campo() ?><input type="hidden" name="accion" value="ingresar">
            <p><label>Celular</label><input type="tel" name="telefono" required maxlength="15" inputmode="numeric"></p>
            <p><label>Clave de 4 números</label><input type="password" name="pin" required maxlength="4" inputmode="numeric"></p>
            <button class="btn btn-sec">Ingresar</button>
          </form>
        </details>
      </div>
    <?php else: ?>
      <div class="tarjeta">
        <div class="fila-titulo"><h3 style="margin:0">Hola, <?= e($polla_yo['nombre']) ?></h3>
          <form method="post" action="<?= e($base . '&v=polla') ?>"><?= csrf_campo() ?><input type="hidden" name="accion" value="salir"><button class="btn btn-sec btn-chico">Salir</button></form></div>
        <?php if (!$pl_partidos): ?><p class="suave">Por ahora no hay partidos programados para pronosticar.</p><?php else: ?>
        <form method="post" action="<?= e($base . '&v=polla') ?>">
          <?= csrf_campo() ?><input type="hidden" name="accion" value="pronosticos">
          <?php foreach ($pl_partidos as $pp): $ab = polla_abierto($pp); $m = $mis[(int)$pp['id']] ?? null; ?>
            <div class="polla-fila">
              <small class="suave"><?= e($pp['torneo']) ?> · <?= fecha_bonita($pp['fecha']) ?><?= $pp['hora'] !== '' ? ' ' . e($pp['hora']) : '' ?></small>
              <div class="polla-partido">
                <span class="eq-celda"><?= escudo($pp['local_escudo'], $pp['local_nombre'], $pp['local_color']) ?> <?= e($pp['local_nombre']) ?></span>
                <input type="number" name="pl[<?= (int)$pp['id'] ?>]" min="0" max="200" inputmode="numeric" value="<?= $m ? (int)$m['goles_local'] : '' ?>" <?= $ab ? '' : 'disabled' ?> aria-label="Local">
                <b>-</b>
                <input type="number" name="pv[<?= (int)$pp['id'] ?>]" min="0" max="200" inputmode="numeric" value="<?= $m ? (int)$m['goles_visitante'] : '' ?>" <?= $ab ? '' : 'disabled' ?> aria-label="Visitante">
                <span class="eq-celda" style="justify-content:flex-end"><?= e($pp['visitante_nombre']) ?> <?= escudo($pp['visitante_escudo'], $pp['visitante_nombre'], $pp['visitante_color']) ?></span>
              </div>
            </div>
          <?php endforeach; ?>
          <button class="btn btn-grande" style="margin-top:10px">💾 Guardar mis pronósticos</button>
          <p class="suave" style="font-size:.85rem">Puede cambiarlos hasta que empiece cada partido.</p>
        </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    </div>
    <div>
      <h3>🏅 Tabla de la polla</h3>
      <?php if (!$ranking): ?><p class="suave">La tabla aparece cuando se juegue el primer partido pronosticado.</p><?php else: ?>
      <div class="tabla-scroll"><table>
        <tr><th>#</th><th>Participante</th><th class="num" title="Marcador exacto">Exactos</th><th class="num">Pts</th></tr>
        <?php foreach ($ranking as $i => $r): ?><tr<?= $polla_yo && (int)$polla_yo['id'] === $r['id'] ? ' style="background:#fff7d6"' : '' ?>><td><?= $i + 1 ?></td><td><?= e($r['nombre']) ?></td><td class="num"><?= $r['exactos'] ?></td><td class="num pts"><?= $r['puntos'] ?></td></tr><?php endforeach; ?>
      </table></div>
      <?php endif; ?>
      <?php if ($pats_polla): ?><p class="suave" style="margin-top:14px">Auspician: <?= implode(' · ', array_map(fn($x) => '<b>' . e($x['nombre']) . '</b>', $pats_polla)) ?></p><?php endif; ?>
    </div>
  </div>

<?php elseif ($v === 'sorteo'):
    $eqs = filas('SELECT e.*, te.grupo, te.orden_sorteo FROM torneo_equipos te JOIN equipos e ON e.id = te.equipo_id WHERE te.torneo_id = ? ORDER BY te.orden_sorteo', [$t['id']]);
    $por_grupo = [];
    foreach ($eqs as $e) $por_grupo[$e['grupo']][] = $e;
    ksort($por_grupo);
?>
  <div class="fila-titulo"><h2>Sorteo · <?= e($t['nombre']) ?></h2><button class="btn btn-sec btn-chico" onclick="location.reload()">↻ Repetir animación</button></div>
  <div class="sorteo-grupos">
    <?php foreach ($por_grupo as $g => $lista): ?>
      <div class="sorteo-grupo">
        <h3><?= $g !== '' ? 'Grupo ' . e($g) : 'Orden del sorteo' ?></h3>
        <ul>
          <?php foreach ($lista as $e): ?>
            <li style="animation-delay: <?= number_format(((int)$e['orden_sorteo']) * 0.9, 1, '.', '') ?>s"><?= escudo($e['escudo'], $e['nombre'], $e['color']) ?> <?= e($e['nombre']) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="suave" style="margin-top:14px">Sorteo realizado el <?= e($t['fecha_sorteo']) ?>.</p>
<?php endif;

pie_publico();
