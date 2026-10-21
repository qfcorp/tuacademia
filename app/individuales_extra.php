<?php
/*
 * (v26) Herramientas comunes de los DEPORTES INDIVIDUALES (carreras, duelos, bolos, natación y atletismo):
 *   1) Inscripción en línea: el competidor se inscribe solo en la página del campeonato (con cobro opcional y
 *      autorización de datos); la organización la aprueba en admin.php?p=indiv&s=inscripciones.
 *   2) Página "en vivo": la página pública se actualiza sola cuando cambian los resultados (firma de los datos).
 *   3) Copiar un campeonato para la nueva temporada (categorías, reglas y, si se quiere, los inscritos).
 *   4) Imágenes para redes (imagen.php?tipo=indiv) y vistas para imprimir (admin.php?p=indiv&imprimir=…).
 */

/* ============================================================
 * BOTONES DEL PANEL
 * ============================================================ */

function html_botones_individual(array $t): string
{
    $liga = fila('SELECT * FROM ligas WHERE id = ?', [$t['liga_id']]);
    $n = insc_indiv_pendientes((int)$t['id']);
    $u = 'admin.php?p=indiv&amp;id=' . (int)$t['id'];
    return '<a class="btn btn-sec btn-chico" target="_blank" href="' . e(url_torneo($liga, $t)) . '">Ver página pública ↗</a> '
        . '<a class="btn btn-sec btn-chico" href="' . $u . '&amp;s=inscripciones">📝 Inscripciones' . ($n ? ' <span class="etiqueta roja">' . $n . '</span>' : '') . '</a> '
        . '<a class="btn btn-sec btn-chico" href="' . $u . '&amp;s=imagenes">🖼 Imágenes</a> '
        . '<a class="btn btn-sec btn-chico" href="' . $u . '&amp;s=imprimir">🖨 Imprimir</a> '
        . '<a class="btn btn-sec btn-chico" href="' . $u . '&amp;s=copiar">📋 Copiar</a>';
}

/* ============================================================
 * 1) INSCRIPCIÓN EN LÍNEA
 * ============================================================ */

function insc_indiv_abierta(array $t): bool
{
    return ($t['insc_indiv_hasta'] ?? '') !== '' && $t['insc_indiv_hasta'] >= date('Y-m-d') && $t['estado'] !== 'finalizado';
}

function insc_indiv_pendientes(int $tid): int
{
    return (int)valor("SELECT COUNT(*) FROM inscripciones_indiv WHERE torneo_id = ? AND estado = 'pendiente'", [$tid]);
}

/** Tarjeta para abrir o cerrar la inscripción en línea (va en la pestaña de reglas de cada deporte). */
function html_inscripcion_indiv_config(array $t): string
{
    ob_start(); ?>
    <div class="tarjeta" style="margin-top:16px" id="inscripcion-linea">
      <h2>📝 Inscripción en línea</h2>
      <p class="ayuda">Si la abre, aparece la pestaña <b>Inscribirse</b> en la página pública del campeonato. Cada inscripción llega a <a href="admin.php?p=indiv&amp;id=<?= (int)$t['id'] ?>&amp;s=inscripciones">Inscripciones</a> para que usted la apruebe.</p>
      <form method="post" action="admin.php?p=indiv&amp;id=<?= (int)$t['id'] ?>&amp;s=inscripciones"><?= csrf_campo() ?><input type="hidden" name="accion" value="insc_config">
        <div class="campos">
          <div><label>Abierta hasta (fecha)</label><input type="date" name="hasta" value="<?= e($t['insc_indiv_hasta']) ?>"><div class="ayuda">Vacío = cerrada.</div></div>
          <div><label>Valor de la inscripción ($)</label><input type="text" name="valor" value="<?= (float)$t['insc_indiv_valor'] > 0 ? e(number_format((float)$t['insc_indiv_valor'], 2, '.', '')) : '' ?>" inputmode="decimal" placeholder="0 = sin costo"><div class="ayuda">Si la liga cobra con tarjeta, se paga al inscribirse.</div></div>
          <div class="campo-ancho"><label>Cómo pagar (si no es con tarjeta)</label><textarea name="info" rows="2" maxlength="400" placeholder="Ej: Transferencia a la cuenta… o pago el día de la competencia en la mesa de control."><?= e($t['insc_indiv_info']) ?></textarea></div>
        </div>
        <button class="btn">Guardar</button>
      </form>
    </div>
    <?php return (string)ob_get_clean();
}

/** Categorías que el competidor puede elegir al inscribirse (las de su campeonato). */
function insc_indiv_categorias(array $t): array
{
    if (es_duelo($t)) return array_column(duelo_categorias((int)$t['id']), 'nombre', 'id');
    if (es_carrera($t) || es_bolos($t)) return array_column(carrera_categorias((int)$t['id']), 'nombre', 'id');
    return [];
}

/** Formulario público "Inscribirse". */
function html_form_inscripcion_indiv(array $liga, array $t, string $base): string
{
    $m = (string)$t['modalidad'];
    $url_priv = 'index.php?l=' . rawurlencode($liga['slug']) . '&v=privacidad';
    $cats = insc_indiv_categorias($t);
    $con_rama = es_duelo($t) || es_pruebas($t);
    $con_nac = es_duelo($t) || es_pruebas($t) || in_array($m, ['CICLO', 'MTB'], true);
    $valor = (float)$t['insc_indiv_valor'];
    $ok = $_SESSION['insc_indiv_ok'][(int)$t['id']] ?? null;
    $old = $_SESSION['insc_indiv_form'][(int)$t['id']] ?? [];
    unset($_SESSION['insc_indiv_form'][(int)$t['id']]);
    $v = fn(string $k): string => e(is_string($old[$k] ?? null) ? $old[$k] : '');
    $sel = fn(string $k, string $val): string => (($old[$k] ?? '') === $val) ? ' selected' : '';
    ob_start();
    foreach (tomar_flash() as [$tipo_f, $msg_f]) echo '<div class="aviso aviso-' . e($tipo_f) . '">' . e($msg_f) . '</div>';
    if ($ok): unset($_SESSION['insc_indiv_ok'][(int)$t['id']]); ?>
      <div class="tarjeta"><h2 style="margin-top:0">✓ ¡Inscripción enviada!</h2>
        <p>Gracias, <b><?= e($ok) ?></b>. La organización revisará su inscripción y le confirmará por WhatsApp o correo.</p>
        <?php if ($valor > 0 && trim((string)$t['insc_indiv_info']) !== ''): ?><p><b>Pago de la inscripción (<?= dinero($valor) ?>):</b><br><?= nl2br(e($t['insc_indiv_info'])) ?></p><?php endif; ?>
      </div>
    <?php return (string)ob_get_clean(); endif; ?>
    <div class="tarjeta">
      <h2 style="margin-top:0">📝 Inscribirse en <?= e($t['nombre']) ?></h2>
      <p class="suave">Inscripciones abiertas hasta el <b><?= e(fecha_bonita($t['insc_indiv_hasta'])) ?></b><?= $valor > 0 ? ' · Valor: <b>' . dinero($valor) . '</b>' : ' · Sin costo' ?>.</p>
      <form method="post" enctype="multipart/form-data" action="<?= e($base . '&t=' . (int)$t['id'] . '&v=inscribirse') ?>">
        <?= csrf_campo() ?><input type="text" name="sitio" value="" style="display:none" tabindex="-1" autocomplete="off">
        <div class="campos">
          <div><label><?= $m === 'PADEL' ? 'Jugador 1' : 'Nombre completo' ?> *</label><input type="text" name="nombre" required maxlength="80" value="<?= $v('nombre') ?>"></div>
          <?php if ($m === 'PADEL'): ?><div><label>Jugador 2 (su pareja) *</label><input type="text" name="pareja" required maxlength="80" value="<?= $v('pareja') ?>"></div><?php endif; ?>
          <div><label>Cédula o pasaporte *</label><input type="text" name="cedula" required maxlength="20" value="<?= $v('cedula') ?>"></div>
          <div><label>Celular / WhatsApp *</label><input type="tel" name="telefono" required maxlength="30" value="<?= $v('telefono') ?>"></div>
          <div><label>Correo</label><input type="email" name="email" maxlength="120" value="<?= $v('email') ?>"></div>
          <div><label>Club / equipo</label><input type="text" name="club" maxlength="60" value="<?= $v('club') ?>"></div>
          <div><label>Ciudad</label><input type="text" name="ciudad" maxlength="40" value="<?= $v('ciudad') ?>"></div>
          <?php if ($con_rama): ?><div><label>Rama *</label><select name="rama" required><option value="">— Elija —</option><option value="M"<?= $sel('rama', 'M') ?>>Varón</option><option value="F"<?= $sel('rama', 'F') ?>>Dama</option><?php if ($m === 'PADEL'): ?><option value="X"<?= $sel('rama', 'X') ?>>Pareja mixta</option><?php endif; ?></select></div><?php endif; ?>
          <?php if ($con_nac): ?><div><label>Fecha de nacimiento *</label><input type="date" name="nacimiento" required value="<?= $v('nacimiento') ?>"></div><?php endif; ?>
          <?php if (in_array($m, ['MARC', 'BOXEO'], true)): ?>
            <div><label>Peso (kg) *</label><input type="text" name="peso" required inputmode="decimal" placeholder="Ej: 63,5" value="<?= $v('peso') ?>"></div>
            <div><label><?= $m === 'MARC' ? 'Cinturón / grado' : 'Récord (G-P-E)' ?></label><input type="text" name="grado" maxlength="40" value="<?= $v('grado') ?>"></div>
          <?php endif; ?>
          <?php if ($m === 'AUTOX'): ?>
            <div><label>Vehículo (marca y modelo) *</label><input type="text" name="vehiculo" required maxlength="60" value="<?= $v('vehiculo') ?>"></div>
            <div><label>Cilindrada (cc) *</label><input type="number" name="cc" required min="0" max="20000" value="<?= $v('cc') ?>"></div>
            <div><label>Motor</label><select name="aspiracion"><option value="">Atmosférico</option><option value="turbo">Turbo / sobrealimentado</option></select></div>
          <?php elseif (in_array($m, ['CICLO', 'MTB'], true)): ?>
            <div><label>Bicicleta</label><input type="text" name="vehiculo" maxlength="60" placeholder="Ruta, montaña…" value="<?= $v('vehiculo') ?>"></div>
          <?php elseif (es_bolos($t)): ?>
            <div><label>Su promedio aproximado</label><input type="number" name="promedio" min="0" max="300" placeholder="Ej: 160" value="<?= $v('promedio') ?>"></div>
          <?php endif; ?>
          <?php if ($cats && !in_array($m, ['AUTOX'], true)): ?>
            <div><label>Categoría</label><select name="categoria_id"><option value="0"><?= es_duelo($t) ? 'Que la asigne la organización (por rama, peso y edad)' : 'Que la asigne la organización' ?></option><?php foreach ($cats as $id => $n): ?><option value="<?= (int)$id ?>"<?= $sel('categoria_id', (string)$id) ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
          <?php endif; ?>
        </div>
        <?php if (es_pruebas($t)): $evs = pruebas_eventos((int)$t['id']); ?>
          <label>Pruebas en las que quiere participar *</label>
          <div class="campos" style="grid-template-columns:repeat(auto-fill,minmax(240px,1fr))">
            <?php foreach ($evs as $ev): ?><label class="check" style="font-weight:400"><input type="checkbox" name="eventos[]" value="<?= (int)$ev['id'] ?>"<?= in_array((string)$ev['id'], (array)($old['eventos'] ?? []), true) ? ' checked' : '' ?>> <?= e(prueba_nombre($ev)) ?></label><?php endforeach; ?>
          </div>
          <p class="ayuda">Marque solo las de su rama y su edad (la que cumple en <?= e($t['temporada']) ?>).</p>
        <?php endif; ?>
        <?= html_requisitos_form(modulo_de_torneo($t), 'inscripciones_indiv', 0, true) ?>
        <label class="check" style="font-weight:400;margin-top:8px"><input type="checkbox" name="acepto" value="1" required> 🔒 <?= str_replace('política de privacidad', '<a href="' . e($url_priv) . '" target="_blank" rel="noopener">política de privacidad</a>', e(texto_autorizacion('titular', 'la organización del campeonato ' . $t['nombre'] . ' y la publicación de sus resultados'))) ?></label>
        <button class="btn btn-grande" type="submit" style="margin-top:10px"><?= $valor > 0 && org_cobra_con_tarjeta($liga) ? '💳 Inscribirme y pagar ' . dinero($valor) : '📝 Enviar inscripción' ?></button>
      </form>
    </div>
    <?php return (string)ob_get_clean();
}

/** Procesa el formulario público. Redirige (al pago o a la confirmación). */
function procesar_inscripcion_indiv(array $liga, array $t, string $base): void
{
    csrf_verificar();
    if (post('sitio') !== '') exit;
    if (!insc_indiv_abierta($t)) throw new RuntimeException('La inscripción en línea está cerrada.');
    if (demasiados_intentos('insc_indiv', 8, '-1 hour')) throw new RuntimeException('Demasiadas inscripciones seguidas desde esta conexión. Intente más tarde.');
    if (post('acepto') !== '1') throw new RuntimeException('Marque la casilla de autorización de uso de datos.');
    $m = (string)$t['modalidad'];
    $nombre = mb_substr(trim(post('nombre')), 0, 80);
    if (mb_strlen($nombre) < 5) throw new RuntimeException('Escriba el nombre completo.');
    $ced = strtoupper(preg_replace('/\s+/', '', post('cedula')));
    if ($err = error_documento($ced)) throw new RuntimeException($err);
    $tel = mb_substr(trim(post('telefono')), 0, 30);
    if (strlen(preg_replace('/\D/', '', $tel)) < 7) throw new RuntimeException('Escriba un celular (WhatsApp) para confirmarle.');
    $email = mb_strtolower(trim(post('email')));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo no es válido.');
    if (fila("SELECT id FROM inscripciones_indiv WHERE torneo_id = ? AND cedula = ? AND estado <> 'rechazada'", [$t['id'], $ced])) throw new RuntimeException('Ya hay una inscripción con esa cédula en este campeonato.');
    $nac = post('nacimiento') !== '' && strtotime(post('nacimiento')) ? date('Y-m-d', strtotime(post('nacimiento'))) : '';
    if ((es_duelo($t) || es_pruebas($t)) && $nac === '') throw new RuntimeException('Escriba la fecha de nacimiento.');
    $rama = in_array(post('rama'), ['M', 'F', 'X'], true) ? post('rama') : '';
    if ((es_duelo($t) || es_pruebas($t)) && $rama === '') throw new RuntimeException('Elija la rama.');
    $pareja = $m === 'PADEL' ? mb_substr(trim(post('pareja')), 0, 80) : '';
    if ($m === 'PADEL' && mb_strlen($pareja) < 3) throw new RuntimeException('Escriba el nombre de su pareja.');
    $peso = trim(post('peso')) !== '' ? round((float)str_replace(',', '.', post('peso')), 1) : null;
    if (in_array($m, ['MARC', 'BOXEO'], true) && (!$peso || $peso <= 0 || $peso > 250)) throw new RuntimeException('Escriba su peso en kilos (ej: 63,5).');
    $extra = [];
    if (es_carrera($t)) {
        $extra = ['vehiculo' => mb_substr(trim(post('vehiculo')), 0, 60), 'cc' => entero(post('cc'), 0, 20000), 'aspiracion' => post('aspiracion') === 'turbo' ? 'turbo' : ''];
        if ($m === 'AUTOX' && ($extra['vehiculo'] === '' || !$extra['cc'])) throw new RuntimeException('Escriba el vehículo y su cilindrada.');
    }
    if (es_bolos($t)) $extra = ['promedio' => entero(post('promedio'), 0, 300)];
    if (es_pruebas($t)) {
        $ids = array_map('intval', (array)($_POST['eventos'] ?? []));
        $evs = array_values(array_filter(pruebas_eventos((int)$t['id']), fn($ev) => in_array((int)$ev['id'], $ids, true)));
        if (!$evs) throw new RuntimeException('Marque al menos una prueba.');
        $p_tmp = ['nombre' => $nombre, 'rama' => $rama, 'nacimiento' => $nac];
        foreach ($evs as $ev) if ($mot = prueba_no_puede($ev, $p_tmp, $t)) throw new RuntimeException('No puede inscribirse en ' . prueba_nombre($ev, false) . ': ' . $mot . '.');
        $extra = ['eventos' => array_map(fn($ev) => (int)$ev['id'], $evs)];
    }
    $cats = insc_indiv_categorias($t);
    $cat = isset($cats[(int)post('categoria_id')]) ? (int)post('categoria_id') : null;
    $mod_req = modulo_de_torneo($t); // (v35) requisitos del módulo deportivo
    requisitos_validar_post($mod_req, 'inscripciones_indiv');
    contexto_auditoria(['usuario' => 'Inscripción en línea: ' . mb_substr($nombre, 0, 60), 'rol' => 'publico', 'liga_id' => (int)$liga['id'], 'origen' => 'publico']);
    q('INSERT INTO inscripciones_indiv (torneo_id, liga_id, nombre, pareja, cedula, telefono, email, club, ciudad, rama, nacimiento, peso, grado, categoria_id, extra, ip, creado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $t['id'], $liga['id'], $nombre, $pareja, $ced, $tel, $email, mb_substr(trim(post('club')), 0, 60), mb_substr(trim(post('ciudad')), 0, 40), $rama, $nac, $peso,
        mb_substr(trim(post('grado')), 0, 40), $cat, json_encode($extra, JSON_UNESCAPED_UNICODE), ip_cliente(), date('Y-m-d H:i:s'),
    ]);
    $iid = (int)db()->lastInsertId();
    guardar_requisitos((int)$liga['id'], $mod_req, 'inscripciones_indiv', $iid); // (v35)
    registrar_consentimiento('inscripcion_individual', (int)$liga['id'], $nombre, 'titular', ['ref_tabla' => 'inscripciones_indiv', 'ref_id' => $iid, 'documento' => $ced,
        'texto' => texto_autorizacion('titular', 'la organización del campeonato ' . $t['nombre'] . ' y la publicación de sus resultados')]);
    anotar_intento('insc_indiv');
    aviso_admins($liga, '📝 <b>Inscripción en línea</b> · ' . e($t['nombre']) . "\n" . e($nombre . ($pareja !== '' ? ' / ' . $pareja : '')) . ' · ' . e($tel) . "\nApruébela en Campeonatos → " . e($t['nombre']) . ' → Inscripciones.', 'insi:' . $iid);
    $valor = (float)$t['insc_indiv_valor'];
    if ($valor > 0 && org_cobra_con_tarjeta($liga)) {
        $token = crear_pago_linea($liga, [
            'destino' => 'organizacion', 'concepto' => 'inscripcion', 'detalle' => 'Inscripción en ' . $t['nombre'] . ' · ' . $nombre, 'monto_base' => $valor,
            'pagador_nombre' => $nombre, 'pagador_email' => $email, 'pagador_cedula' => $ced, 'pagador_telefono' => $tel,
            'datos' => ['inscripcion_id' => $iid, 'volver' => 'index.php?l=' . $liga['slug'] . '&t=' . (int)$t['id'] . '&v=inscribirse'],
        ]);
        q('UPDATE inscripciones_indiv SET pago_token = ? WHERE id = ?', [$token, $iid]);
        $_SESSION['insc_indiv_ok'][(int)$t['id']] = $nombre;
        ir(url_pago($token));
    }
    $_SESSION['insc_indiv_ok'][(int)$t['id']] = $nombre;
    ir($base . '&t=' . (int)$t['id'] . '&v=inscribirse');
}

/** Crea el competidor a partir de una inscripción aprobada. Devuelve su id. */
function aprobar_inscripcion_indiv(array $t, array $ins, ?int $cat = null): int
{
    $pid = aprobar_inscripcion_indiv_crear($t, $ins, $cat);
    // (v35) los datos y documentos de requisitos que subió en línea pasan a su ficha
    mover_requisitos('inscripciones_indiv', (int)$ins['id'], es_duelo($t) ? 'duelo_participantes' : (es_pruebas($t) ? 'prueba_participantes' : 'carrera_participantes'), $pid);
    return $pid;
}

function aprobar_inscripcion_indiv_crear(array $t, array $ins, ?int $cat = null): int
{
    $tid = (int)$t['id'];
    $x = json_decode((string)$ins['extra'], true) ?: [];
    $cat = $cat ?: ($ins['categoria_id'] ? (int)$ins['categoria_id'] : null);
    if (es_duelo($t)) {
        $cat = $cat ?: duelo_categoria_auto($tid, (string)$ins['rama'], $ins['peso'] !== null ? (float)$ins['peso'] : null, (string)$ins['nacimiento']);
        q('INSERT INTO duelo_participantes (torneo_id, categoria_id, nombre, pareja, club, ciudad, rama, nacimiento, peso, grado) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$tid, $cat, $ins['nombre'], $ins['pareja'], $ins['club'], $ins['ciudad'], $ins['rama'], $ins['nacimiento'], $ins['peso'], $ins['grado']]);
        return (int)db()->lastInsertId();
    }
    if (es_pruebas($t)) {
        q('INSERT INTO prueba_participantes (torneo_id, nombre, club, ciudad, rama, nacimiento) VALUES (?,?,?,?,?,?)', [$tid, $ins['nombre'], $ins['club'], $ins['ciudad'], in_array($ins['rama'], ['M', 'F'], true) ? $ins['rama'] : '', $ins['nacimiento']]);
        $pid = (int)db()->lastInsertId();
        if (in_array((string)$t['modalidad'], ['RUTA', 'TRIAT'], true)) { // (v36) dorsal automático en carreras de ruta y triatlón
            $sig = (int)valor("SELECT COALESCE(MAX(CAST(numero AS INTEGER)), 0) FROM prueba_participantes WHERE torneo_id = ? AND numero GLOB '[0-9]*'", [$tid]);
            q('UPDATE prueba_participantes SET numero = ? WHERE id = ?', [(string)($sig + 1), $pid]);
        }
        $p = fila('SELECT * FROM prueba_participantes WHERE id = ?', [$pid]);
        foreach ((array)($x['eventos'] ?? []) as $evid) {
            $ev = fila('SELECT * FROM prueba_eventos WHERE id = ? AND torneo_id = ?', [(int)$evid, $tid]);
            if ($ev && prueba_no_puede($ev, $p, $t) === '') q('INSERT OR IGNORE INTO prueba_resultados (evento_id, participante_id) VALUES (?, ?)', [$ev['id'], $pid]);
        }
        return $pid;
    }
    if (es_carrera($t) && !$cat && $t['modalidad'] === 'AUTOX') $cat = carrera_categoria_auto($tid, (int)($x['cc'] ?? 0), (string)($x['aspiracion'] ?? ''));
    $hcp = 0;
    if (es_bolos($t) && (int)($x['promedio'] ?? 0) > 0) {
        $cfg = bolos_cfg($t);
        $hcp = max(0, (int)floor(($cfg['base'] - (int)$x['promedio']) * $cfg['pct'] / 100));
    }
    q('INSERT INTO carrera_participantes (torneo_id, categoria_id, nombre, club, ciudad, vehiculo, cc, aspiracion, handicap, nacimiento) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$tid, $cat, $ins['nombre'], $ins['club'], $ins['ciudad'], (string)($x['vehiculo'] ?? ''), (int)($x['cc'] ?? 0), (string)($x['aspiracion'] ?? ''), $hcp, (string)($ins['nacimiento'] ?? '')]);
    return (int)db()->lastInsertId();
}

/** Resumen corto de lo que pidió (para la bandeja del panel). */
function texto_inscripcion_indiv(array $t, array $ins): string
{
    $x = json_decode((string)$ins['extra'], true) ?: [];
    $p = [];
    if ($ins['rama'] !== '') $p[] = ['M' => 'Varón', 'F' => 'Dama', 'X' => 'Mixta'][$ins['rama']] ?? '';
    if ($ins['nacimiento'] !== '') $p[] = 'nació ' . fecha_corta($ins['nacimiento']);
    if ($ins['peso'] !== null) $p[] = rtrim(rtrim(number_format((float)$ins['peso'], 1, ',', ''), '0'), ',') . ' kg';
    if ($ins['grado'] !== '') $p[] = $ins['grado'];
    if (!empty($x['vehiculo'])) $p[] = $x['vehiculo'] . (!empty($x['cc']) ? ' · ' . number_format((int)$x['cc'], 0, ',', '.') . ' cc' : '') . (($x['aspiracion'] ?? '') === 'turbo' ? ' turbo' : '');
    if (!empty($x['promedio'])) $p[] = 'promedio ' . (int)$x['promedio'];
    if (!empty($x['eventos'])) {
        $n = [];
        foreach ((array)$x['eventos'] as $id) if ($ev = fila('SELECT * FROM prueba_eventos WHERE id = ?', [(int)$id])) $n[] = prueba_nombre($ev, false);
        $p[] = implode(' · ', $n);
    }
    return implode(' · ', array_filter($p));
}

/* ============================================================
 * 2) EN VIVO
 * ============================================================ */

/** Firma de los resultados del campeonato: cambia cada vez que se carga algo. */
function firma_individual(array $t): string
{
    $tid = (int)$t['id'];
    if (es_duelo($t)) {
        $r = valor("SELECT group_concat(id || estado || COALESCE(ganador_id, '') || marcador || COALESCE(pts_a, '') || COALESCE(pts_b, '') || fecha || hora || COALESCE(a_id, '') || COALESCE(b_id, ''), '|') FROM duelo_combates WHERE torneo_id = ?", [$tid]);
        $n = valor('SELECT COUNT(*) FROM duelo_participantes WHERE torneo_id = ?', [$tid]);
    } elseif (es_pruebas($t)) {
        $r = valor("SELECT group_concat(r.id || COALESCE(r.marca, '') || r.estado, '|') FROM prueba_resultados r JOIN prueba_eventos e ON e.id = r.evento_id WHERE e.torneo_id = ?", [$tid]);
        $n = valor('SELECT COUNT(*) FROM prueba_participantes WHERE torneo_id = ?', [$tid]);
    } else {
        $r = valor("SELECT group_concat(r.id || COALESCE(r.tiempo_ms, '') || COALESCE(r.pinos, '') || r.estado || r.conos || r.ajuste_seg || COALESCE(r.pens, ''), '|') FROM carrera_resultados r JOIN carrera_fechas f ON f.id = r.fecha_id WHERE f.torneo_id = ?", [$tid]);
        $n = valor('SELECT COUNT(*) FROM carrera_participantes WHERE torneo_id = ?', [$tid]);
    }
    return substr(md5((string)$r . '#' . (string)$n), 0, 12);
}

/** Aviso "en vivo" + script que recarga la página cuando hay resultados nuevos (cada 20 s, solo con la pestaña visible). */
function html_en_vivo_individual(array $liga, array $t): string
{
    if ($t['estado'] === 'finalizado') return '';
    $url = 'index.php?l=' . rawurlencode($liga['slug']) . '&t=' . (int)$t['id'] . '&ping=1';
    ob_start(); ?>
    <p class="no-imprimir" style="margin:-2px 0 8px;font-size:.82rem"><span class="en-vivo" style="display:inline">● EN VIVO</span> <span class="suave">· la página se actualiza sola con cada resultado</span> · <a href="#" onclick="window.print();return false">🖨 Imprimir</a></p>
    <script>
    (function () {
      var firma = <?= json_encode(firma_individual($t)) ?>, url = <?= json_encode($url) ?>;
      setInterval(function () {
        if (document.visibilityState !== 'visible') return;
        fetch(url, { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
          if (d && d.f && d.f !== firma) { try { sessionStorage.setItem('scroll_vivo', String(window.scrollY)); } catch (e) {} location.reload(); }
        }).catch(function () {});
      }, 20000);
      try { var y = sessionStorage.getItem('scroll_vivo'); if (y) { sessionStorage.removeItem('scroll_vivo'); window.scrollTo(0, parseInt(y, 10)); } } catch (e) {}
    })();
    </script>
    <?php return (string)ob_get_clean();
}

/* ============================================================
 * 3) COPIAR PARA LA NUEVA TEMPORADA
 * ============================================================ */

function copiar_individual(array $liga, int $tid, string $nombre, string $temporada, bool $con_inscritos): int
{
    $t = fila('SELECT * FROM torneos WHERE id = ? AND liga_id = ?', [$tid, $liga['id']]) ?? throw new RuntimeException('Campeonato no encontrado.');
    if (!es_individual($t)) throw new RuntimeException('Ese campeonato no es de un deporte individual.');
    if (trim($nombre) === '') throw new RuntimeException('Escriba el nombre del nuevo campeonato.');
    verificar_limite_plan($liga, 'campeonato');
    $d = $t;
    unset($d['id']);
    $d['nombre'] = mb_substr(trim($nombre), 0, 100);
    $d['temporada'] = mb_substr(trim($temporada), 0, 30);
    $d['slug'] = '';
    $d['estado'] = 'inscripcion';
    $d['insc_indiv_hasta'] = '';
    if (array_key_exists('creado', $d)) $d['creado'] = date('Y-m-d H:i:s');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO torneos (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_fill(0, count($d), '?')) . ')', array_values($d));
        $nid = (int)$pdo->lastInsertId();
        $mapa = [];
        if (es_duelo($t)) {
            foreach (duelo_categorias($tid) as $c) {
                q('INSERT INTO duelo_categorias (torneo_id, nombre, orden, formato, rama, peso_min, peso_max, edad_min, edad_max, nota) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$nid, $c['nombre'], $c['orden'], $c['formato'], $c['rama'], $c['peso_min'], $c['peso_max'], $c['edad_min'], $c['edad_max'], $c['nota']]);
                $mapa[(int)$c['id']] = (int)$pdo->lastInsertId();
            }
            if ($con_inscritos) foreach (filas('SELECT * FROM duelo_participantes WHERE torneo_id = ? AND activo = 1', [$tid]) as $p) {
                q('INSERT INTO duelo_participantes (torneo_id, categoria_id, nombre, pareja, club, ciudad, rama, nacimiento, peso, grado, siembra) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [$nid, $mapa[(int)$p['categoria_id']] ?? null, $p['nombre'], $p['pareja'], $p['club'], $p['ciudad'], $p['rama'], $p['nacimiento'], $p['peso'], $p['grado'], $p['siembra']]);
            }
        } elseif (es_pruebas($t)) {
            foreach (pruebas_eventos($tid) as $ev) {
                q('INSERT INTO prueba_eventos (torneo_id, num, nombre, medida, rama, edad_min, edad_max, categoria, nota) VALUES (?,?,?,?,?,?,?,?,?)',
                    [$nid, $ev['num'], $ev['nombre'], $ev['medida'], $ev['rama'], $ev['edad_min'], $ev['edad_max'], $ev['categoria'], $ev['nota']]);
            }
            if ($con_inscritos) foreach (filas('SELECT * FROM prueba_participantes WHERE torneo_id = ? AND activo = 1', [$tid]) as $p) {
                q('INSERT INTO prueba_participantes (torneo_id, numero, nombre, club, ciudad, rama, nacimiento) VALUES (?,?,?,?,?,?,?)', [$nid, $p['numero'], $p['nombre'], $p['club'], $p['ciudad'], $p['rama'], $p['nacimiento']]);
            }
        } else {
            foreach (carrera_categorias($tid) as $c) {
                q('INSERT INTO carrera_categorias (torneo_id, nombre, orden, cc_min, cc_max, aspiracion, nota) VALUES (?,?,?,?,?,?,?)', [$nid, $c['nombre'], $c['orden'], $c['cc_min'], $c['cc_max'], $c['aspiracion'], $c['nota']]);
                $mapa[(int)$c['id']] = (int)$pdo->lastInsertId();
            }
            if (es_carrera($t)) foreach (carrera_penalidades($tid) as $pn) {
                q('INSERT INTO carrera_penalidades (torneo_id, nombre, segundos, orden, activo) VALUES (?,?,?,?,?)', [$nid, $pn['nombre'], $pn['segundos'], $pn['orden'], $pn['activo']]);
            }
            if ($con_inscritos) {
                $hcp = [];
                if (es_bolos($t)) foreach (bolos_calcular($t)['general'] as $pid => $g) $hcp[$pid] = (int)$g['hcp_actual'];
                foreach (filas('SELECT * FROM carrera_participantes WHERE torneo_id = ? AND activo = 1', [$tid]) as $p) {
                    q('INSERT INTO carrera_participantes (torneo_id, categoria_id, numero, nombre, club, ciudad, vehiculo, cc, aspiracion, handicap) VALUES (?,?,?,?,?,?,?,?,?,?)',
                        [$nid, $mapa[(int)$p['categoria_id']] ?? null, $p['numero'], $p['nombre'], $p['club'], $p['ciudad'], $p['vehiculo'], $p['cc'], $p['aspiracion'], $hcp[(int)$p['id']] ?? $p['handicap']]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    guardar_slug_torneo(fila('SELECT * FROM torneos WHERE id = ?', [$nid]), '');
    return $nid;
}

/* ============================================================
 * 4) IMÁGENES PARA REDES
 * ============================================================ */

/** Imágenes que se pueden sacar de un campeonato: [[título, 'que', sel], ...] */
function imagenes_individual(array $t): array
{
    $tid = (int)$t['id'];
    $r = [];
    if (es_duelo($t)) {
        foreach (duelo_categorias($tid) as $c) {
            $m = duelo_medallas_categoria($t, $c);
            if ($m['oro'] || $m['bronce']) $r[] = ['Podio · ' . $c['nombre'], 'podio', (int)$c['id']];
            elseif (in_array($c['formato'], ['liga', 'suizo'], true) && duelo_combates((int)$c['id'])) $r[] = ['Tabla · ' . $c['nombre'], 'tabla', (int)$c['id']];
        }
        if (duelo_medallero($t)['clubes']) $r[] = ['Medallero por club', 'medallero', 0];
        if ((int)valor("SELECT COUNT(*) FROM duelo_combates WHERE torneo_id = ? AND estado = 'jugado'", [$tid])) $r[] = ['Últimos resultados', 'resultados', 0];
    } elseif (es_pruebas($t)) {
        foreach (pruebas_eventos($tid) as $ev) if (prueba_con_marcas((int)$ev['id'])) $r[] = ['Resultado · ' . prueba_nombre($ev), 'prueba', (int)$ev['id']];
        if (pruebas_medallero($t)['clubes']) $r[] = ['Medallero por club', 'medallero', 0];
    } elseif (es_bolos($t)) {
        foreach (bolos_general($t)['cats'] as $c) $r[] = ['Clasificación · ' . $c['cat']['nombre'], 'general', (int)$c['cat']['id']];
        if (bolos_clubes(bolos_calcular($t))) $r[] = ['Clasificación por clubes', 'clubes', 0];
    } else {
        foreach (carrera_general($t)['cats'] as $cid => $c) $r[] = ['Clasificación · ' . $c['cat']['nombre'], 'general', (int)$cid];
        foreach (carrera_fechas($tid) as $f) if ((int)valor('SELECT COUNT(*) FROM carrera_resultados WHERE fecha_id = ?', [$f['id']])) $r[] = ['Resultado · ' . carrera_nombre_fecha($f, textos_carrera($t)), 'fecha', (int)$f['id']];
    }
    return $r;
}

/**
 * Datos de una imagen: ['titulo', 'sub', 'filas' => [[pos, nombre, sub, valor]]]. pos: 1..n, o 'oro'|'plata'|'bronce'.
 */
function datos_imagen_individual(array $t, string $que, int $sel): ?array
{
    $tid = (int)$t['id'];
    $sub = $t['nombre'] . ' ' . $t['temporada'];
    $f = [];
    if (es_duelo($t)) {
        $part = duelo_participantes($tid);
        if ($que === 'podio') {
            $c = fila('SELECT * FROM duelo_categorias WHERE id = ? AND torneo_id = ?', [$sel, $tid]) ?? null;
            if (!$c) return null;
            $m = duelo_medallas_categoria($t, $c);
            foreach (['oro', 'plata', 'bronce'] as $med) foreach ($m[$med] as $pid) if (isset($part[$pid])) $f[] = [$med, duelo_nombre($part[$pid]), $part[$pid]['club'], ['oro' => 'Campeón', 'plata' => 'Subcampeón', 'bronce' => 'Tercero'][$med]];
            return ['titulo' => 'Podio', 'sub' => $c['nombre'] . ' · ' . $sub, 'filas' => $f];
        }
        if ($que === 'tabla') {
            $c = fila('SELECT * FROM duelo_categorias WHERE id = ? AND torneo_id = ?', [$sel, $tid]) ?? null;
            if (!$c) return null;
            foreach (array_slice(duelo_tabla_liga($t, $sel), 0, 10) as $x) $f[] = [$x['pos'], duelo_nombre($x['p']), $x['p']['club'], duelo_num((float)$x['pts']) . ' pts'];
            return ['titulo' => 'Tabla', 'sub' => $c['nombre'] . ' · ' . $sub, 'filas' => $f];
        }
        if ($que === 'medallero') {
            foreach (array_slice(duelo_medallero($t)['clubes'], 0, 10) as $i => $c) $f[] = [$i + 1, $c['club'], 'Oro ' . $c['oro'] . ' · Plata ' . $c['plata'] . ' · Bronce ' . $c['bronce'], (string)($c['oro'] + $c['plata'] + $c['bronce'])];
            return ['titulo' => 'Medallero', 'sub' => $sub, 'filas' => $f];
        }
        if ($que === 'resultados') {
            $cats = array_column(duelo_categorias($tid), 'nombre', 'id');
            foreach (filas("SELECT * FROM duelo_combates WHERE torneo_id = ? AND estado = 'jugado' AND ganador_id IS NOT NULL ORDER BY fecha DESC, hora DESC, id DESC LIMIT 8", [$tid]) as $c) {
                $g = $part[(int)$c['ganador_id']] ?? null;
                $p = $part[(int)((int)$c['ganador_id'] === (int)$c['a_id'] ? $c['b_id'] : $c['a_id'])] ?? null;
                if (!$g || !$p) continue;
                $res = duelo_texto_resultado($t, $c);
                if ((int)$c['ganador_id'] === (int)$c['b_id'] && tipo_duelo($t) === 'sets') $res = duelo_invertir_sets($res);
                $f[] = ['✓', duelo_nombre($g), 'venció a ' . duelo_nombre($p) . ' · ' . ($cats[(int)$c['categoria_id']] ?? ''), $res];
            }
            return ['titulo' => 'Resultados', 'sub' => $sub, 'filas' => $f];
        }
        return null;
    }
    if (es_pruebas($t)) {
        if ($que === 'prueba') {
            $ev = fila('SELECT * FROM prueba_eventos WHERE id = ? AND torneo_id = ?', [$sel, $tid]) ?? null;
            if (!$ev) return null;
            foreach (array_slice(array_filter(prueba_resultados($ev), fn($r) => $r['puesto'] !== null), 0, 8) as $r) {
                $f[] = [$r['puesto'] <= 3 ? ['oro', 'plata', 'bronce'][$r['puesto'] - 1] : $r['puesto'], $r['p']['nombre'], $r['p']['club'], $r['texto'] ?? prueba_fmt_marca($ev['medida'], $r['marca'])];
            }
            return ['titulo' => $ev['nombre'], 'sub' => trim((pruebas_ramas()[$ev['rama']] ?? '') . ' ' . $ev['categoria']) . ' · ' . $sub, 'filas' => $f];
        }
        if ($que === 'medallero') {
            foreach (array_slice(pruebas_medallero($t)['clubes'], 0, 10) as $i => $c) $f[] = [$i + 1, $c['club'], 'Oro ' . $c['oro'] . ' · Plata ' . $c['plata'] . ' · Bronce ' . $c['bronce'], $c['puntos'] . ' pts'];
            return ['titulo' => 'Medallero', 'sub' => $sub, 'filas' => $f];
        }
        return null;
    }
    if (es_bolos($t)) {
        $calc = bolos_calcular($t);
        if ($que === 'clubes') {
            foreach (array_slice(bolos_clubes($calc), 0, 10) as $i => $c) $f[] = [$i + 1, $c['club'], implode(', ', $c['suman']), (string)$c['total']];
            return ['titulo' => 'Clubes', 'sub' => $sub, 'filas' => $f];
        }
        foreach (bolos_general($t, $calc)['cats'] as $c) {
            if ((int)$c['cat']['id'] !== $sel) continue;
            $cfg = $calc['cfg'];
            foreach (array_slice($c['filas'], 0, 10) as $x) $f[] = [$x['pos'], $x['p']['nombre'], $x['p']['club'] . ' · prom. ' . bolos_fmt_prom($x['promedio']), $cfg['clasif'] === 'puntos' ? $x['pts'] . ' pts' : (string)$x['total']];
            return ['titulo' => 'Clasificación', 'sub' => $c['cat']['nombre'] . ' · ' . $sub, 'filas' => $f];
        }
        return null;
    }
    // carreras
    $T = textos_carrera($t);
    if ($que === 'fecha') {
        $fe = fila('SELECT * FROM carrera_fechas WHERE id = ? AND torneo_id = ?', [$sel, $tid]) ?? null;
        if (!$fe) return null;
        $cl = carrera_clasificacion_fecha($t, $fe);
        $cn = array_column(carrera_categorias($tid), 'nombre', 'id');
        foreach (array_slice(array_values(array_filter($cl['abs'], fn($x) => $x['pos'] !== null)), 0, 10) as $x) {
            $f[] = [$x['pos'], $x['p']['nombre'], trim(($x['p']['club'] ?? '') . (isset($cn[(int)$x['p']['categoria_id']]) ? ' · ' . $cn[(int)$x['p']['categoria_id']] : ''), ' ·'), carrera_fmt_tiempo((int)round($x['valor']))];
        }
        return ['titulo' => carrera_nombre_fecha($fe, $T), 'sub' => $sub, 'filas' => $f];
    }
    $g = carrera_general($t);
    $c = $g['cats'][$sel] ?? null;
    if (!$c) return null;
    foreach (array_slice($c['filas'], 0, 10) as $x) $f[] = [$x['pos'], $x['p']['nombre'], $x['p']['club'], $g['cfg']['clasif'] === 'tiempo' ? carrera_fmt_tiempo($x['total_ms']) : $x['puntos'] . ' pts'];
    return ['titulo' => 'Clasificación', 'sub' => $c['cat']['nombre'] . ' · ' . $sub, 'filas' => $f];
}
