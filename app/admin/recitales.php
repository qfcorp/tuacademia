<?php
/*
 * (TuAcademia) 🎭 RECITALES Y PRESENTACIONES
 *  admin.php?p=recitales            → lista de eventos y nuevo evento
 *  admin.php?p=recitales&id=N       → un evento: datos, números (coreografías / obras) con sus participantes, ensayos y cobros
 *  admin.php?p=recitales&id=N&doc=programa|lista → programa del recital y lista de participantes con tallas (para imprimir)
 */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$por = usuario()['nombre'];
if (!es_escuela($liga)) ir('admin.php');
$ed = puede('alumnos', true);
$va = vocab_academia($liga);
$id = (int)get('id');
$ev = $id ? evento_de_liga($id, $lid) : null;
$url = 'admin.php?p=recitales' . ($ev ? '&id=' . (int)$ev['id'] : '');
$grupos = grupos_visibles($lid);
$fecha_post = fn(string $c, string $def = '') => post($c) !== '' && strtotime(post($c)) ? date('Y-m-d', strtotime(post($c))) : ($def !== '' ? $def : date('Y-m-d'));
$monto_post = fn(string $c) => max(0, round((float)str_replace(',', '.', post($c) ?: '0'), 2));

/* ---------- Documentos para imprimir ---------- */
if ($ev && in_array(get('doc'), ['programa', 'lista'], true)) {
    $nums = numeros_evento((int)$ev['id']);
    doc_inicio(($ev['nombre']) . ' · ' . (get('doc') === 'programa' ? 'Programa' : 'Participantes'), $url);
    doc_cabecera_financiera($liga, mb_strtoupper($ev['nombre']));
    echo '<div class="meta"><div><b>Fecha:</b> ' . e(acad_fecha_texto($ev['fecha'])) . ($ev['hora'] ? ' · ' . e($ev['hora']) : '') . '</div><div><b>Lugar:</b> ' . e($ev['lugar'] ?: '—') . '</div><div><b>' . e($va['obras']) . ':</b> ' . count($nums) . '</div><div><b>Participantes:</b> ' . count(alumnos_evento((int)$ev['id'])) . '</div></div>';
    if (get('doc') === 'programa') {
        echo '<table class="pl"><tr><th>#</th><th>' . e($va['obra']) . '</th><th>Disciplina</th><th>Profesor(a)</th><th>' . (familia_deporte((string)($nums[0]['disciplina'] ?? '')) === 'musica' ? 'Autor' : 'Música') . '</th><th>Intérpretes</th></tr>';
        foreach ($nums as $k => $n) {
            $ps = participantes_numero((int)$n['id']);
            echo '<tr><td>' . ($k + 1) . '</td><td><b>' . e($n['titulo']) . '</b></td><td>' . e($n['disciplina'] !== '' ? nombre_deporte_corto($n['disciplina']) : '') . '</td><td>' . e($n['profesor']) . '</td><td>' . e($n['autor']) . '</td><td>' . e(implode(', ', array_map('nombre_alumno', $ps))) . '</td></tr>';
        }
        echo '</table>';
    } else {
        foreach ($nums as $k => $n) {
            $ps = participantes_numero((int)$n['id']);
            echo '<h2>' . ($k + 1) . '. ' . e($n['titulo']) . ($n['vestuario'] !== '' ? ' <small>· Vestuario: ' . e($n['vestuario']) . '</small>' : '') . '</h2><table class="pl"><tr><th>#</th><th>Alumno</th><th>Talla</th><th>Papel</th><th>Vestuario pagado</th><th>Firma / entregado</th></tr>';
            foreach ($ps as $i => $p) {
                $cg = $p['cargo_id'] ? fila('SELECT estado FROM esc_cargos WHERE id = ?', [$p['cargo_id']]) : null;
                echo '<tr><td>' . ($i + 1) . '</td><td>' . e(nombre_alumno($p)) . '</td><td>' . e($p['talla'] ?: $p['talla_vestuario']) . '</td><td>' . e($p['rol']) . '</td><td>' . ($cg ? ($cg['estado'] === 'pagado' ? 'Sí' : 'Pendiente') : '—') . '</td><td style="width:120px"></td></tr>';
            }
            echo '</table>';
        }
    }
    echo '<p style="font-size:11px;color:#555">Generado el ' . date('d/m/Y H:i') . ' con ' . e(marca()) . '.</p>';
    doc_fin();
    return;
}

/* ---------- Guardar ---------- */
if (es_post()) {
    if (!$ed && !(post('accion') === 'cobrar' && puede('cobros', true))) throw new RuntimeException('Su perfil no puede hacer cambios aquí.');
    $acc = post('accion');
    if ($acc === 'nuevo' || $acc === 'datos') {
        if (mb_strlen(trim(post('nombre'))) < 3) throw new RuntimeException('Escriba el nombre del evento (ej: Recital de fin de año).');
        $d = ['tipo' => array_key_exists(post('tipo'), tipos_evento_academia()) ? post('tipo') : 'recital', 'nombre' => mb_substr(trim(post('nombre')), 0, 120), 'fecha' => $fecha_post('fecha'),
            'hora' => hora_valida(post('hora')), 'lugar' => mb_substr(trim(post('lugar')), 0, 120), 'direccion' => mb_substr(trim(post('direccion')), 0, 200), 'descripcion' => mb_substr(trim(post('descripcion')), 0, 1000),
            'valor_inscripcion' => $monto_post('valor_inscripcion'), 'valor_vestuario' => $monto_post('valor_vestuario'), 'vence_cobro' => post('vence_cobro') !== '' ? $fecha_post('vence_cobro') : '',
            'publico' => post('publico') === '1' ? 1 : 0, 'estado' => array_key_exists(post('estado'), estados_evento_academia()) ? post('estado') : 'preparacion'];
        if ($acc === 'nuevo') {
            $d += ['liga_id' => $lid, 'registrado_por' => $por];
            q('INSERT INTO acad_eventos (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_fill(0, count($d), '?')) . ')', array_values($d));
            $nid = (int)db()->lastInsertId();
            bitacora_evento('academia', 'Creó el evento ' . $d['nombre'] . ' (' . $d['fecha'] . ')', $lid, $nid);
            flash('Evento creado. Ahora agregue las ' . mb_strtolower($va['obras']) . ' y sus participantes.');
            ir('admin.php?p=recitales&id=' . $nid . '#numeros');
        }
        q('UPDATE acad_eventos SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($d))) . ' WHERE id = ?', array_merge(array_values($d), [$ev['id']]));
        flash('Datos del evento guardados.');
        ir($url);
    }
    if ($acc === 'numero') {
        if (mb_strlen(trim(post('titulo'))) < 2) throw new RuntimeException('Escriba el nombre de la ' . mb_strtolower($va['obra']) . '.');
        $gid = (int)post('grupo_id');
        $g = $gid ? grupo_de_escuela($gid, $lid) : null;
        $d = [mb_substr(trim(post('titulo')), 0, 120), isset(catalogo_deportes()[post('disciplina')]) ? post('disciplina') : ($g ? deporte_grupo($g, $liga) : ''), $g['id'] ?? null,
            mb_substr(trim(post('profesor')), 0, 80), mb_substr(trim(post('autor')), 0, 120), entero(post('duracion'), 0, 120), mb_substr(trim(post('vestuario')), 0, 200),
            post('valor_vestuario') === '' ? null : $monto_post('valor_vestuario'), mb_substr(trim(post('notas')), 0, 300)];
        if ((int)post('nid')) {
            $n = fila('SELECT * FROM acad_numeros WHERE id = ? AND evento_id = ?', [(int)post('nid'), $ev['id']]) ?? throw new RuntimeException('No encontrado.');
            q('UPDATE acad_numeros SET titulo = ?, disciplina = ?, grupo_id = ?, profesor = ?, autor = ?, duracion = ?, vestuario = ?, valor_vestuario = ?, notas = ? WHERE id = ?', array_merge($d, [$n['id']]));
            flash('Guardado.');
        } else {
            q('INSERT INTO acad_numeros (titulo, disciplina, grupo_id, profesor, autor, duracion, vestuario, valor_vestuario, notas, evento_id, orden) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                array_merge($d, [$ev['id'], (int)valor('SELECT COALESCE(MAX(orden), 0) + 1 FROM acad_numeros WHERE evento_id = ?', [$ev['id']])]));
            $nn = fila('SELECT * FROM acad_numeros WHERE id = ?', [(int)db()->lastInsertId()]);
            // Si eligió un curso, sus alumnos quedan como participantes (se pueden quitar uno por uno)
            $agregados = $g && post('con_curso') === '1' ? agregar_participantes($ev, $nn, array_column(alumnos_grupo((int)$g['id']), 'id'), $lid) : 0;
            flash($va['obra'] . ' «' . $nn['titulo'] . '» agregada' . ($agregados ? ' con ' . $agregados . ' participante(s) del curso ' . $g['nombre'] : '') . '.');
        }
        ir($url . '#numeros');
    }
    if ($acc === 'borrar_numero') {
        $n = fila('SELECT * FROM acad_numeros WHERE id = ? AND evento_id = ?', [(int)post('nid'), $ev['id']]) ?? throw new RuntimeException('No encontrado.');
        foreach (participantes_numero((int)$n['id']) as $p) quitar_participante($ev, (int)$p['id']);
        q('DELETE FROM acad_numeros WHERE id = ?', [$n['id']]);
        flash('Quitada del evento.');
        ir($url . '#numeros');
    }
    if ($acc === 'orden') {
        foreach ((array)($_POST['orden'] ?? []) as $nid => $o) q('UPDATE acad_numeros SET orden = ? WHERE id = ? AND evento_id = ?', [(int)$o, (int)$nid, $ev['id']]);
        flash('Orden del programa guardado.');
        ir($url . '#numeros');
    }
    if ($acc === 'participantes') {
        $n = fila('SELECT * FROM acad_numeros WHERE id = ? AND evento_id = ?', [(int)post('nid'), $ev['id']]) ?? throw new RuntimeException('Elija la ' . mb_strtolower($va['obra']) . '.');
        $k = agregar_participantes($ev, $n, (array)($_POST['alumnos'] ?? []), $lid);
        flash($k ? $k . ' participante(s) agregado(s) a «' . $n['titulo'] . '».' : 'Marque al menos un alumno.');
        ir($url . '#n' . (int)$n['id']);
    }
    if ($acc === 'talla') {
        foreach ((array)($_POST['talla'] ?? []) as $pid => $t) {
            q('UPDATE acad_participantes SET talla = ?, rol = ? WHERE id = ? AND numero_id IN (SELECT id FROM acad_numeros WHERE evento_id = ?)',
                [mb_substr(trim((string)$t), 0, 20), mb_substr(trim((string)($_POST['rol'][$pid] ?? '')), 0, 60), (int)$pid, $ev['id']]);
        }
        flash('Tallas y papeles guardados.');
        ir($url . '#n' . (int)post('nid'));
    }
    if ($acc === 'quitar') {
        quitar_participante($ev, (int)post('pid'));
        flash('Participante quitado.');
        ir($url . '#numeros');
    }
    if ($acc === 'ensayo') {
        $nid = (int)post('numero_id');
        if ($nid && !fila('SELECT id FROM acad_numeros WHERE id = ? AND evento_id = ?', [$nid, $ev['id']])) $nid = 0;
        q('INSERT INTO acad_ensayos (evento_id, numero_id, fecha, hora_inicio, hora_fin, lugar, tipo, notas) VALUES (?,?,?,?,?,?,?,?)', [$ev['id'], $nid ?: null, $fecha_post('fecha'),
            hora_valida(post('hora_inicio')), hora_valida(post('hora_fin')), mb_substr(trim(post('lugar')), 0, 120), post('tipo') === 'general' ? 'general' : 'ensayo', mb_substr(trim(post('notas')), 0, 300)]);
        flash('Ensayo agregado. Los padres lo ven en su portal.');
        ir($url . '#ensayos');
    }
    if ($acc === 'borrar_ensayo') {
        q('DELETE FROM acad_ensayos WHERE id = ? AND evento_id = ?', [(int)post('eid'), $ev['id']]);
        flash('Ensayo borrado.');
        ir($url . '#ensayos');
    }
    if ($acc === 'cobrar') {
        [$ni, $nv] = cobrar_evento($liga, $ev, $por);
        bitacora_evento('academia', 'Cargó cobros del evento ' . $ev['nombre'] . ': ' . $ni . ' inscripción(es) y ' . $nv . ' vestuario(s)', $lid, (int)$ev['id']);
        flash($ni || $nv ? 'Listo: ' . $ni . ' inscripción(es) y ' . $nv . ' vestuario(s) cargados. Ya aparecen en Pagos, en Mensajes de hoy y en el portal de los padres.' : 'No hay cobros nuevos: todos los participantes ya tienen el suyo (o los valores están en $0).');
        ir($url . '#cobros');
    }
    if ($acc === 'eliminar') {
        $pag = (int)valor("SELECT COUNT(*) FROM esc_cargos WHERE estado = 'pagado' AND id IN (SELECT cargo_id FROM acad_inscritos WHERE evento_id = ? UNION SELECT p.cargo_id FROM acad_participantes p JOIN acad_numeros n ON n.id = p.numero_id WHERE n.evento_id = ?)", [$ev['id'], $ev['id']]);
        if ($pag) throw new RuntimeException('No se puede eliminar: hay ' . $pag . ' cobro(s) ya pagados. Márquelo como «Cancelado» en los datos del evento.');
        q("UPDATE esc_cargos SET estado = 'anulado' WHERE estado = 'pendiente' AND id IN (SELECT cargo_id FROM acad_inscritos WHERE evento_id = ? UNION SELECT p.cargo_id FROM acad_participantes p JOIN acad_numeros n ON n.id = p.numero_id WHERE n.evento_id = ?)", [$ev['id'], $ev['id']]);
        q('DELETE FROM acad_eventos WHERE id = ?', [$ev['id']]);
        flash('Evento eliminado.');
        ir('admin.php?p=recitales');
    }
    ir($url);
}

cabecera_admin($va['eventos']);
?>
<h1>🎭 <?= e($va['eventos']) ?></h1>
<?php if (!$ev):
    $lista = filas("SELECT e.*, (SELECT COUNT(*) FROM acad_numeros n WHERE n.evento_id = e.id) AS n_num, (SELECT COUNT(*) FROM acad_inscritos i WHERE i.evento_id = e.id) AS n_part FROM acad_eventos e WHERE e.liga_id = ? ORDER BY e.fecha < ?, CASE WHEN e.fecha >= ? THEN e.fecha END, e.fecha DESC", [$lid, date('Y-m-d'), date('Y-m-d')]); ?>
  <p class="suave" style="margin-top:0">Arme cada recital o presentación por <b><?= e(mb_strtolower($va['obras'])) ?></b>: quién participa en cada una (con su talla), los ensayos y el cobro de la inscripción y del vestuario. Los padres ven todo en su portal.</p>
  <div class="rejilla-2">
    <div class="tarjeta">
      <h2 style="margin-top:0">Eventos</h2>
      <?php if (!$lista): ?><p class="suave">Todavía no hay eventos. Cree el primero con el formulario.</p><?php else: ?>
      <div class="tabla-scroll"><table><tr><th>Fecha</th><th>Evento</th><th class="num"><?= e($va['obras']) ?></th><th class="num">Participantes</th><th>Estado</th></tr>
        <?php foreach ($lista as $x): [$et, $ec] = estados_evento_academia()[$x['estado']] ?? ['', '']; ?><tr><td><?= e(fecha_corta($x['fecha'])) ?></td><td><a href="admin.php?p=recitales&amp;id=<?= (int)$x['id'] ?>"><b><?= e($x['nombre']) ?></b></a><br><small class="suave"><?= strip_tags(tipos_evento_academia()[$x['tipo']] ?? '') ?></small></td>
          <td class="num"><?= (int)$x['n_num'] ?></td><td class="num"><?= (int)$x['n_part'] ?></td><td><span class="chip-estado <?= $ec ?>"><?= e($et) ?></span></td></tr><?php endforeach; ?>
      </table></div><?php endif; ?>
    </div>
    <?php if ($ed): ?>
    <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="nuevo">
      <h2 style="margin-top:0">+ Nuevo evento</h2>
      <div class="campos">
        <div><label>Tipo</label><select name="tipo"><?php foreach (tipos_evento_academia() as $k => $t): ?><option value="<?= $k ?>"><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="campo-ancho"><label>Nombre *</label><input type="text" name="nombre" required maxlength="120" placeholder="Ej: Recital de fin de año 2026"></div>
        <div><label>Fecha *</label><input type="date" name="fecha" required value="<?= date('Y-m-d', strtotime('+45 days')) ?>"></div>
        <div><label>Hora</label><input type="time" name="hora" value="17:00"></div>
        <div><label>Lugar</label><input type="text" name="lugar" maxlength="120" placeholder="Ej: Teatro Nacional Sucre"></div>
        <div><label>Inscripción por alumno ($)</label><input type="number" name="valor_inscripcion" min="0" step="0.01" value="0"><div class="ayuda">Una sola vez por alumno, aunque esté en varias.</div></div>
        <div><label>Vestuario por <?= e(mb_strtolower($va['obra'])) ?> ($)</label><input type="number" name="valor_vestuario" min="0" step="0.01" value="0"><div class="ayuda">Cada <?= e(mb_strtolower($va['obra'])) ?> puede tener otro valor.</div></div>
        <div><label>Fecha límite de pago</label><input type="date" name="vence_cobro" value=""><div class="ayuda">Vacío = una semana antes del evento.</div></div>
      </div>
      <input type="hidden" name="publico" value="1">
      <button class="btn">Crear evento</button>
    </form>
    <?php endif; ?>
  </div>
<?php pie_admin(); return; endif;

/* ---------- Un evento ---------- */
$nums = numeros_evento((int)$ev['id']);
$ensayos = filas('SELECT s.*, n.titulo FROM acad_ensayos s LEFT JOIN acad_numeros n ON n.id = s.numero_id WHERE s.evento_id = ? ORDER BY s.fecha, s.hora_inicio', [$ev['id']]);
$alumnos_act = filas("SELECT a.* FROM esc_alumnos a WHERE a.liga_id = ? AND a.estado = 'activo' ORDER BY a.apellidos, a.nombres", [$lid]);
$todos_part = alumnos_evento((int)$ev['id']);
$cargos_ev = filas("SELECT c.* FROM esc_cargos c WHERE c.estado <> 'anulado' AND c.id IN (SELECT cargo_id FROM acad_inscritos WHERE evento_id = ? UNION SELECT p.cargo_id FROM acad_participantes p JOIN acad_numeros n ON n.id = p.numero_id WHERE n.evento_id = ?)", [$ev['id'], $ev['id']]);
$cobrado = array_sum(array_map(fn($c) => $c['estado'] === 'pagado' ? (float)$c['monto'] - (float)$c['descuento'] : 0, $cargos_ev));
$por_cobrar = array_sum(array_map(fn($c) => $c['estado'] === 'pendiente' ? (float)$c['monto'] - (float)$c['descuento'] : 0, $cargos_ev));
[$et, $ec] = estados_evento_academia()[$ev['estado']] ?? ['', ''];
?>
<p><a href="admin.php?p=recitales">← <?= e($va['eventos']) ?></a></p>
<div class="fila-titulo"><h2 style="margin:0"><?= e(tipos_evento_academia()[$ev['tipo']] ?? '🎭') ?> · <?= e($ev['nombre']) ?></h2><span class="chip-estado <?= $ec ?>"><?= e($et) ?></span></div>
<p style="margin:.3rem 0">📅 <?= e(acad_fecha_texto($ev['fecha'])) ?><?= $ev['hora'] ? ' · ' . e($ev['hora']) : '' ?><?= $ev['lugar'] ? ' · 📍 ' . e($ev['lugar']) : '' ?></p>
<div class="cifras">
  <div class="cifra"><b><?= count($nums) ?></b><?= e($va['obras']) ?></div>
  <div class="cifra"><b><?= count($todos_part) ?></b>Participantes</div>
  <div class="cifra"><b><?= count($ensayos) ?></b>Ensayos</div>
  <div class="cifra"><b><?= dinero($cobrado) ?></b>Cobrado</div>
  <div class="cifra"><b><?= dinero($por_cobrar) ?></b>Por cobrar</div>
</div>
<div class="acciones" style="margin:8px 0 12px">
  <a class="btn btn-sec" target="_blank" href="<?= e($url) ?>&amp;doc=programa">🖨 Programa</a>
  <a class="btn btn-sec" target="_blank" href="<?= e($url) ?>&amp;doc=lista">🖨 Participantes y tallas</a>
</div>

<details class="tarjeta" <?= $nums ? '' : 'open' ?>>
  <summary><strong>Datos del evento</strong></summary>
  <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="datos">
    <div class="campos">
      <div><label>Tipo</label><select name="tipo"><?php foreach (tipos_evento_academia() as $k => $t): ?><option value="<?= $k ?>" <?= $ev['tipo'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
      <div class="campo-ancho"><label>Nombre</label><input type="text" name="nombre" required maxlength="120" value="<?= e($ev['nombre']) ?>"></div>
      <div><label>Fecha</label><input type="date" name="fecha" value="<?= e($ev['fecha']) ?>"></div>
      <div><label>Hora</label><input type="time" name="hora" value="<?= e($ev['hora']) ?>"></div>
      <div><label>Lugar</label><input type="text" name="lugar" maxlength="120" value="<?= e($ev['lugar']) ?>"></div>
      <div><label>Dirección</label><input type="text" name="direccion" maxlength="200" value="<?= e($ev['direccion']) ?>"></div>
      <div><label>Inscripción por alumno ($)</label><input type="number" name="valor_inscripcion" min="0" step="0.01" value="<?= e((string)(float)$ev['valor_inscripcion']) ?>"></div>
      <div><label>Vestuario por <?= e(mb_strtolower($va['obra'])) ?> ($)</label><input type="number" name="valor_vestuario" min="0" step="0.01" value="<?= e((string)(float)$ev['valor_vestuario']) ?>"></div>
      <div><label>Fecha límite de pago</label><input type="date" name="vence_cobro" value="<?= e($ev['vence_cobro']) ?>"></div>
      <div><label>Estado</label><select name="estado"><?php foreach (estados_evento_academia() as $k => [$t]): ?><option value="<?= $k ?>" <?= $ev['estado'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
      <div class="campo-ancho"><label>Indicaciones para los padres</label><textarea name="descripcion" rows="2" maxlength="1000" placeholder="Ej: llegar 1 hora antes, peinado con moño alto, maquillaje natural"><?= e($ev['descripcion']) ?></textarea></div>
    </div>
    <label class="check"><input type="checkbox" name="publico" value="1" <?= (int)$ev['publico'] ? 'checked' : '' ?>> Mostrarlo en la página pública de la academia</label>
    <?php if ($ed): ?><button class="btn btn-sec">Guardar datos</button><?php endif; ?>
  </form>
  <?php if ($ed): ?><form method="post" style="margin-top:8px" onsubmit="return confirm('¿Eliminar este evento? Se anulan los cobros que no se han pagado.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar"><button class="btn btn-peligro btn-chico">Eliminar evento</button></form><?php endif; ?>
</details>

<h2 id="numeros">🎶 <?= e($va['obras']) ?> y participantes</h2>
<?php if (!$nums): ?><p class="suave">Todavía no hay <?= e(mb_strtolower($va['obras'])) ?>. Agregue la primera abajo; si elige un curso, sus alumnos quedan como participantes.</p><?php endif; ?>
<?php if ($nums && $ed): ?>
<form method="post" class="acciones" style="margin-bottom:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="orden"><span class="suave">Orden en el programa:</span>
  <?php foreach ($nums as $n): ?><label style="font-weight:400"><?= e(mb_substr($n['titulo'], 0, 18)) ?> <input type="number" name="orden[<?= (int)$n['id'] ?>]" value="<?= (int)$n['orden'] ?>" style="width:56px"></label><?php endforeach; ?>
  <button class="btn btn-sec btn-chico">Guardar orden</button></form>
<?php endif; ?>
<?php foreach ($nums as $k => $n): $ps = participantes_numero((int)$n['id']); $ids_p = array_map(fn($p) => (int)$p['alumno_id'], $ps);
    $valor_v = $n['valor_vestuario'] !== null ? (float)$n['valor_vestuario'] : (float)$ev['valor_vestuario']; ?>
  <div class="tarjeta" id="n<?= (int)$n['id'] ?>">
    <div class="fila-titulo"><h3 style="margin:0"><?= $k + 1 ?>. <?= e($n['titulo']) ?> <?= $n['disciplina'] !== '' ? chip_deporte($n['disciplina']) : '' ?></h3>
      <small class="suave"><?= $n['profesor'] !== '' ? '🧑‍🏫 ' . e($n['profesor']) . ' · ' : '' ?><?= $n['autor'] !== '' ? '🎵 ' . e($n['autor']) . ' · ' : '' ?><?= $n['duracion'] ? (int)$n['duracion'] . ' min · ' : '' ?>👗 <?= $n['vestuario'] !== '' ? e($n['vestuario']) . ' · ' : '' ?><?= $valor_v > 0 ? dinero($valor_v) : 'sin cobro de vestuario' ?></small></div>
    <?php if ($ps): ?>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="talla"><input type="hidden" name="nid" value="<?= (int)$n['id'] ?>">
      <div class="tabla-scroll"><table><tr><th>Alumno</th><th>Talla</th><th>Papel</th><th>Vestuario</th><th></th></tr>
        <?php foreach ($ps as $p): $cg = $p['cargo_id'] ? fila('SELECT * FROM esc_cargos WHERE id = ?', [$p['cargo_id']]) : null; ?>
          <tr><td><a href="admin.php?p=alumno&amp;id=<?= (int)$p['alumno_id'] ?>"><?= e(nombre_alumno($p)) ?></a></td>
            <td><input type="text" name="talla[<?= (int)$p['id'] ?>]" value="<?= e($p['talla']) ?>" maxlength="20" style="width:70px;min-width:0" list="tallas-v"></td>
            <td><input type="text" name="rol[<?= (int)$p['id'] ?>]" value="<?= e($p['rol']) ?>" maxlength="60" placeholder="Ej: solista" style="min-width:0"></td>
            <td><?= $cg ? ($cg['estado'] === 'pagado' ? '<span class="chip-estado chip-ok">Pagado</span>' : ($cg['estado'] === 'anulado' ? '—' : '<span class="chip-estado chip-pend">Pendiente</span>')) : '<span class="suave">—</span>' ?></td>
            <td><?php if ($ed): ?><button class="btn btn-sec btn-chico" form="q<?= (int)$p['id'] ?>" title="Quitar">✕</button><?php endif; ?></td></tr>
        <?php endforeach; ?>
      </table></div>
      <?php if ($ed): ?><button class="btn btn-sec btn-chico">💾 Guardar tallas y papeles</button><?php endif; ?>
    </form>
    <?php if ($ed): foreach ($ps as $p): ?><form id="q<?= (int)$p['id'] ?>" method="post" onsubmit="return confirm('¿Quitar a <?= e(addslashes(nombre_alumno($p))) ?> de esta <?= e(mb_strtolower($va['obra'])) ?>?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="quitar"><input type="hidden" name="pid" value="<?= (int)$p['id'] ?>"></form><?php endforeach; endif; ?>
    <?php else: ?><p class="suave">Sin participantes todavía.</p><?php endif; ?>
    <?php if ($ed): $libres = array_values(array_filter($alumnos_act, fn($a) => !in_array((int)$a['id'], $ids_p, true))); if ($libres): ?>
    <details style="margin-top:8px"><summary>+ Agregar participantes</summary>
      <form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="participantes"><input type="hidden" name="nid" value="<?= (int)$n['id'] ?>">
        <div class="lista-chequeo" style="max-height:260px;overflow:auto;border:1px solid var(--borde,#d8dfdc);border-radius:8px;padding:6px 10px">
          <?php foreach ($libres as $a): ?><label class="check" style="font-weight:400"><input type="checkbox" name="alumnos[]" value="<?= (int)$a['id'] ?>"> <?= e(nombre_alumno($a)) ?><?= $a['talla_vestuario'] !== '' ? ' <small class="suave">(talla ' . e($a['talla_vestuario']) . ')</small>' : '' ?></label><?php endforeach; ?>
        </div>
        <button class="btn btn-sec btn-chico" style="margin-top:6px">Agregar a los marcados</button>
      </form>
    </details>
    <?php endif; ?>
    <details style="margin-top:6px"><summary class="suave">Editar o quitar esta <?= e(mb_strtolower($va['obra'])) ?></summary>
      <form method="post" style="margin-top:8px"><?= csrf_campo() ?><input type="hidden" name="accion" value="numero"><input type="hidden" name="nid" value="<?= (int)$n['id'] ?>">
        <div class="campos">
          <div class="campo-ancho"><label>Nombre</label><input type="text" name="titulo" maxlength="120" value="<?= e($n['titulo']) ?>"></div>
          <div><label>Disciplina</label><select name="disciplina"><option value="">—</option><?= opciones_disciplinas($n['disciplina']) ?></select></div>
          <div><label>Curso</label><select name="grupo_id"><option value="">—</option><?= opciones_grupos($grupos, $n['grupo_id'] ? (int)$n['grupo_id'] : null, false) ?></select></div>
          <div><label>Profesor(a)</label><input type="text" name="profesor" maxlength="80" value="<?= e($n['profesor']) ?>"></div>
          <div><label>Música / autor</label><input type="text" name="autor" maxlength="120" value="<?= e($n['autor']) ?>"></div>
          <div><label>Duración (min)</label><input type="number" name="duracion" min="0" max="120" value="<?= (int)$n['duracion'] ?>"></div>
          <div><label>Vestuario</label><input type="text" name="vestuario" maxlength="200" value="<?= e($n['vestuario']) ?>"></div>
          <div><label>Valor del vestuario ($)</label><input type="number" name="valor_vestuario" min="0" step="0.01" value="<?= $n['valor_vestuario'] !== null ? e((string)(float)$n['valor_vestuario']) : '' ?>" placeholder="el del evento"></div>
        </div>
        <button class="btn btn-sec btn-chico">Guardar</button>
      </form>
      <form method="post" style="margin-top:6px" onsubmit="return confirm('¿Quitar esta <?= e(mb_strtolower($va['obra'])) ?> del evento?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_numero"><input type="hidden" name="nid" value="<?= (int)$n['id'] ?>"><button class="btn btn-peligro btn-chico">Quitar del evento</button></form>
    </details>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<datalist id="tallas-v"><?php foreach (tallas_vestuario() as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist>

<?php if ($ed): ?>
<form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="numero">
  <h3 style="margin-top:0">+ Nueva <?= e(mb_strtolower($va['obra'])) ?></h3>
  <div class="campos">
    <div class="campo-ancho"><label>Nombre *</label><input type="text" name="titulo" required maxlength="120" placeholder="<?= familia_deporte((string)($liga['deporte_escuela'] ?? '')) === 'musica' ? 'Ej: Para Elisa (Beethoven)' : 'Ej: El lago de los cisnes · Salsa caleña' ?>"></div>
    <div><label>Curso</label><select name="grupo_id"><option value="">— elegir después —</option><?= opciones_grupos($grupos, null, false) ?></select></div>
    <div><label class="check" style="margin-top:26px;font-weight:400"><input type="checkbox" name="con_curso" value="1" checked> Agregar a los alumnos del curso</label></div>
    <div><label>Disciplina</label><select name="disciplina"><option value="">La del curso</option><?= opciones_disciplinas('') ?></select></div>
    <div><label>Profesor(a)</label><input type="text" name="profesor" maxlength="80"></div>
    <div><label>Música / autor</label><input type="text" name="autor" maxlength="120"></div>
    <div><label>Duración (min)</label><input type="number" name="duracion" min="0" max="120" value="4"></div>
    <div><label>Vestuario</label><input type="text" name="vestuario" maxlength="200" placeholder="Ej: tutú blanco y medias rosadas"></div>
    <div><label>Valor del vestuario ($)</label><input type="number" name="valor_vestuario" min="0" step="0.01" placeholder="el del evento (<?= dinero($ev['valor_vestuario']) ?>)"></div>
  </div>
  <button class="btn">Agregar</button>
</form>
<?php endif; ?>

<h2 id="ensayos">🗓 Ensayos</h2>
<div class="tarjeta">
  <?php if (!$ensayos): ?><p class="suave">Todavía no hay ensayos.</p><?php else: ?>
  <div class="tabla-scroll"><table><tr><th>Fecha</th><th>Hora</th><th>Qué</th><th>Lugar</th><th>Notas</th><th></th></tr>
    <?php foreach ($ensayos as $s): ?><tr><td><?= e(acad_fecha_texto($s['fecha'])) ?></td><td><?= e($s['hora_inicio']) ?><?= $s['hora_fin'] ? '–' . e($s['hora_fin']) : '' ?></td>
      <td><?= $s['tipo'] === 'general' ? '<b>⭐ Ensayo general</b>' : 'Ensayo' ?><?= $s['titulo'] ? ' · ' . e($s['titulo']) : ' · todo el elenco' ?></td><td><?= e($s['lugar']) ?></td><td><small><?= e($s['notas']) ?></small></td>
      <td><?php if ($ed): ?><form method="post" class="inline" onsubmit="return confirm('¿Borrar el ensayo?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_ensayo"><input type="hidden" name="eid" value="<?= (int)$s['id'] ?>"><button class="btn btn-sec btn-chico">✕</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </table></div><?php endif; ?>
  <?php if ($ed): ?>
  <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="ensayo">
    <div class="campos">
      <div><label>Fecha</label><input type="date" name="fecha" required value="<?= date('Y-m-d', strtotime('+7 days')) ?>"></div>
      <div><label>Desde</label><input type="time" name="hora_inicio" value="16:00"></div>
      <div><label>Hasta</label><input type="time" name="hora_fin" value="17:30"></div>
      <div><label>¿Para quién?</label><select name="numero_id"><option value="">Todo el elenco</option><?php foreach ($nums as $n): ?><option value="<?= (int)$n['id'] ?>"><?= e($n['titulo']) ?></option><?php endforeach; ?></select></div>
      <div><label>Tipo</label><select name="tipo"><option value="ensayo">Ensayo</option><option value="general">⭐ Ensayo general</option></select></div>
      <div><label>Lugar</label><input type="text" name="lugar" maxlength="120" placeholder="Ej: <?= e($va['lugar']) ?> principal"></div>
      <div class="campo-ancho"><label>Notas</label><input type="text" name="notas" maxlength="300" placeholder="Ej: venir con el vestuario completo"></div>
    </div>
    <button class="btn btn-sec">+ Agregar ensayo</button>
  </form>
  <?php endif; ?>
</div>

<h2 id="cobros">💵 Cobros del evento</h2>
<div class="tarjeta">
  <p style="margin-top:0">Inscripción: <b><?= dinero($ev['valor_inscripcion']) ?></b> por alumno · Vestuario: <b><?= dinero($ev['valor_vestuario']) ?></b> por <?= e(mb_strtolower($va['obra'])) ?> (o lo de cada una) · Pagar hasta: <b><?= e($ev['vence_cobro'] !== '' ? fecha_corta($ev['vence_cobro']) : fecha_corta(date('Y-m-d', strtotime($ev['fecha'] . ' -7 days')))) ?></b></p>
  <?php if (puede('cobros', true) || $ed): ?><form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="cobrar"><button class="btn">💵 Cargar los cobros que falten</button>
    <span class="ayuda">Crea la inscripción y el vestuario de cada participante (no duplica). Llegan a Pagos, a Mensajes de hoy y al portal de los padres.</span></form><?php endif; ?>
  <?php if ($cargos_ev): ?>
  <div class="tabla-scroll" style="margin-top:10px"><table><tr><th>Alumno</th><th>Concepto</th><th class="num">Valor</th><th>Estado</th></tr>
    <?php foreach ($cargos_ev as $c): $al = fila('SELECT nombres, apellidos FROM esc_alumnos WHERE id = ?', [$c['alumno_id']]); ?><tr><td><a href="admin.php?p=alumno&amp;id=<?= (int)$c['alumno_id'] ?>&amp;s=pagos"><?= e($al ? nombre_alumno($al) : '') ?></a></td><td><?= e($c['descripcion']) ?></td><td class="num"><?= dinero((float)$c['monto'] - (float)$c['descuento']) ?></td><td><?= $c['estado'] === 'pagado' ? '<span class="chip-estado chip-ok">Pagado</span>' : '<span class="chip-estado chip-pend">Pendiente</span>' ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<?php
pie_admin();
