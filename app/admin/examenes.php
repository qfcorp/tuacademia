<?php
/*
 * (v31) 🥋 EXÁMENES DE GRADO, CINTURONES Y POOMSAE / KATA (escuelas de taekwondo, karate y judo)
 *  admin.php?p=examenes                       → lista de exámenes y nuevo examen
 *  admin.php?p=examenes&v=cinturones          → lista de cinturones de la escuela (grado, color, punta y forma)
 *  admin.php?p=examenes&v=formas              → poomsae / kata de cada alumno (aprendiendo / la domina)
 *  admin.php?p=examenes&id=N                  → un examen: inscritos, cobro, resultados, cierre, acta y certificados
 *  admin.php?p=examenes&id=N&doc=acta|certificados[&i=ID] → documentos para imprimir
 */
$liga = requiere_liga();
$lid = (int)$liga['id'];
$por = usuario()['nombre'];
$ed = puede('evaluaciones', true);
if (!es_escuela($liga)) ir('admin.php');
$discs = disciplinas_de_escuela($liga);
$id = (int)get('id');
$ex = $id ? examen_de_liga($id, $lid) : null;
$disc = $ex ? $ex['disciplina'] : (in_array(get('d'), $discs, true) ? get('d') : ($discs[0] ?? 'taekwondo'));
$voc = vocabulario_marcial($disc);
$vista = $ex ? 'examen' : (in_array(get('v'), ['cinturones', 'formas'], true) ? get('v') : 'examenes');
$url = 'admin.php?p=examenes' . ($ex ? '&id=' . (int)$ex['id'] : '&d=' . $disc);

// Grupos y alumnos de la disciplina (lo que ve este usuario)
$grupos = array_values(array_filter(grupos_visibles($lid), fn($g) => es_deporte_marcial(deporte_grupo($g, $liga)) && disciplina_de(deporte_grupo($g, $liga)) === $disc));
$ids_g = array_map(fn($g) => (int)$g['id'], $grupos);
$alumnos = $ids_g ? filas("SELECT a.* FROM esc_alumnos a WHERE a.liga_id = ? AND a.estado = 'activo' AND " . sql_en_grupo('a', $ids_g) . ' ORDER BY a.apellidos, a.nombres', [$lid]) : [];
$alumno_ok = function (int $aid) use ($lid, $ids_g, $disc): array {
    $a = fila('SELECT * FROM esc_alumnos WHERE id = ? AND liga_id = ?', [$aid, $lid]) ?? throw new RuntimeException('Alumno no encontrado.');
    if (!array_intersect(ids_grupos_alumno($a), $ids_g)) throw new RuntimeException('Ese alumno no está en un grupo de ' . (disciplinas_marciales()[$disc] ?? 'esta disciplina') . '.');
    return $a;
};

// Documentos para imprimir
if ($ex && get('doc') === 'acta') { doc_acta_examen($ex, $liga, $url); return; }
if ($ex && get('doc') === 'certificados') { doc_certificados($ex, $liga, $url, (int)get('i')); return; }

if (es_post()) {
    $accion = post('accion');
    if (!$ed && !($accion === 'cobrar' && puede('cobros', true))) throw new RuntimeException('Su perfil no puede hacer cambios aquí.');
    $fecha_post = fn(string $c) => post($c) !== '' && strtotime(post($c)) ? date('Y-m-d', strtotime(post($c))) : date('Y-m-d');

    if ($accion === 'nuevo' || $accion === 'datos') {
        $d = ['fecha' => $fecha_post('fecha'), 'hora' => hora_valida(post('hora')), 'lugar' => mb_substr(trim(post('lugar')), 0, 120), 'examinador' => mb_substr(trim(post('examinador')), 0, 100),
              'valor' => max(0, round((float)str_replace(',', '.', post('valor') ?: '0'), 2)), 'notas' => mb_substr(trim(post('notas')), 0, 1000)];
        if ($accion === 'nuevo') {
            if (!in_array(post('disciplina'), $discs, true)) throw new RuntimeException('Elija la disciplina del examen.');
            q('INSERT INTO esc_examenes (liga_id, disciplina, fecha, hora, lugar, examinador, valor, notas, registrado_por) VALUES (?,?,?,?,?,?,?,?,?)',
                [$lid, post('disciplina'), $d['fecha'], $d['hora'], $d['lugar'], $d['examinador'], $d['valor'], $d['notas'], $por]);
            $nid = (int)db()->lastInsertId();
            flash('Examen creado. Ahora inscriba a los alumnos que van a rendirlo.');
            ir('admin.php?p=examenes&id=' . $nid . '#inscribir');
        }
        q('UPDATE esc_examenes SET fecha = ?, hora = ?, lugar = ?, examinador = ?, valor = ?, notas = ? WHERE id = ?', [$d['fecha'], $d['hora'], $d['lugar'], $d['examinador'], $d['valor'], $d['notas'], $ex['id']]);
        flash('Datos del examen guardados.');
        ir($url);
    }

    if ($accion === 'inscribir') {
        if ($ex['estado'] !== 'abierto') throw new RuntimeException('El examen está cerrado. Ábralo de nuevo para inscribir.');
        $lista = cinturones_escuela($lid, $disc);
        $por_id = array_column($lista, null, 'id');
        $n = 0;
        foreach ((array)($_POST['alumnos'] ?? []) as $aid) {
            $a = $alumno_ok((int)$aid);
            $cid = (int)($_POST['aspira'][(int)$aid] ?? 0);
            $c = $por_id[$cid] ?? null;
            if (!$c) continue;
            [$gr] = cinturon_alumno($a, $liga);
            $fs = formas_alumno((int)$a['id']);
            q('INSERT OR IGNORE INTO esc_examen_inscritos (examen_id, alumno_id, grado_actual, grado_aspira, cinturon_id, forma, forma_ok) VALUES (?,?,?,?,?,?,?)', [
                $ex['id'], $a['id'], $gr['grado'] ?? '', etiqueta_cinturon($c), $c['id'], $c['forma'], ($c['forma'] !== '' && ($fs[$c['forma']]['estado'] ?? '') === 'domina') ? 1 : 0]);
            $n++;
        }
        flash($n ? $n . ' alumno(s) inscrito(s) en el examen.' : 'Marque al menos un alumno.');
        ir($url . '#inscritos');
    }

    if ($accion === 'quitar') {
        $i = fila('SELECT * FROM esc_examen_inscritos WHERE id = ? AND examen_id = ?', [(int)post('iid'), $ex['id']]) ?? throw new RuntimeException('No encontrado.');
        if ($i['cargo_id']) {
            $cg = fila('SELECT * FROM esc_cargos WHERE id = ?', [$i['cargo_id']]);
            if ($cg && $cg['estado'] === 'pagado') throw new RuntimeException('Ese alumno ya pagó el derecho a examen: anule primero el pago en Pagos.');
            if ($cg) q('DELETE FROM esc_cargos WHERE id = ?', [$cg['id']]);
        }
        q('DELETE FROM esc_grados WHERE alumno_id = ? AND examen_id = ?', [$i['alumno_id'], $ex['id']]);
        q('DELETE FROM esc_examen_inscritos WHERE id = ?', [$i['id']]);
        flash('Alumno quitado del examen.');
        ir($url . '#inscritos');
    }

    if ($accion === 'cobrar') {
        if ((float)$ex['valor'] <= 0) throw new RuntimeException('Escriba primero el valor del derecho a examen (en los datos del examen).');
        $n = 0;
        foreach (inscritos_examen((int)$ex['id']) as $i) {
            if ($i['cargo_id'] && fila('SELECT id FROM esc_cargos WHERE id = ?', [$i['cargo_id']])) continue;
            $cid = crear_cargo_escuela($liga, (int)$i['alumno_id'], 'examen', 'Derecho a examen de grado: ' . $i['grado_aspira'] . ' (' . fecha_corta($ex['fecha']) . ')', (float)$ex['valor'], $ex['fecha'], $por);
            q('UPDATE esc_examen_inscritos SET cargo_id = ? WHERE id = ?', [$cid, $i['id']]);
            $n++;
        }
        flash($n ? 'Se cargó el derecho a examen (' . dinero($ex['valor']) . ') a ' . $n . ' alumno(s). Ya aparece en Pagos y en el portal de los padres.' : 'Todos los inscritos ya tienen el cobro.');
        ir($url . '#inscritos');
    }

    if ($accion === 'resultados') {
        if ($ex['estado'] !== 'abierto') throw new RuntimeException('El examen está cerrado. Ábralo de nuevo para corregir resultados.');
        foreach (inscritos_examen((int)$ex['id']) as $i) {
            $k = (int)$i['id'];
            $res = (string)($_POST['res'][$k] ?? $i['resultado']);
            q('UPDATE esc_examen_inscritos SET resultado = ?, nota = ?, forma_ok = ?, observaciones = ? WHERE id = ?', [
                array_key_exists($res, resultados_examen()) ? $res : 'pendiente', mb_substr(trim((string)($_POST['nota'][$k] ?? '')), 0, 20),
                !empty($_POST['forma_ok'][$k]) ? 1 : 0, mb_substr(trim((string)($_POST['obs'][$k] ?? '')), 0, 200), $k]);
            if (!empty($_POST['forma_ok'][$k]) && $i['forma'] !== '') guardar_forma_alumno((int)$i['alumno_id'], $i['forma'], 'domina', $por);
        }
        flash('Resultados guardados. Cuando estén todos, toque «Cerrar examen y entregar cinturones».');
        ir($url . '#resultados');
    }

    if ($accion === 'cerrar') {
        $pend = (int)valor("SELECT COUNT(*) FROM esc_examen_inscritos WHERE examen_id = ? AND resultado = 'pendiente'", [$ex['id']]);
        if ($pend) throw new RuntimeException('Faltan ' . $pend . ' resultado(s): marque aprobado, no aprobó o no se presentó para cada inscrito.');
        $n = cerrar_examen($ex, $por);
        bitacora_evento('examen', 'Cerró el examen de grado del ' . $ex['fecha'] . ' (' . $n . ' cinturón(es) nuevo(s))', $lid, (int)$ex['id']);
        flash('¡Examen cerrado! ' . $n . ' alumno(s) tienen su cinturón nuevo en la ficha, el carnet y el portal de los padres. Ya puede imprimir los certificados.');
        ir($url);
    }
    if ($accion === 'reabrir') {
        q("UPDATE esc_examenes SET estado = 'abierto' WHERE id = ?", [$ex['id']]);
        flash('Examen abierto de nuevo: corrija y vuelva a cerrarlo (los cinturones se actualizan solos).');
        ir($url);
    }
    if ($accion === 'eliminar') {
        if ((int)valor("SELECT COUNT(*) FROM esc_examen_inscritos i JOIN esc_cargos c ON c.id = i.cargo_id WHERE i.examen_id = ? AND c.estado = 'pagado'", [$ex['id']])) {
            throw new RuntimeException('No se puede eliminar: hay derechos a examen pagados.');
        }
        foreach (inscritos_examen((int)$ex['id']) as $i) if ($i['cargo_id']) q("DELETE FROM esc_cargos WHERE id = ? AND estado <> 'pagado'", [$i['cargo_id']]);
        q('DELETE FROM esc_grados WHERE examen_id = ?', [$ex['id']]);
        q('DELETE FROM esc_examenes WHERE id = ?', [$ex['id']]);
        flash('Examen eliminado.');
        ir('admin.php?p=examenes&d=' . $disc);
    }

    if ($accion === 'guardar_cinturones') {
        $filas_post = (array)($_POST['c'] ?? []);
        $n = 0;
        foreach ($filas_post as $k => $f) {
            $nombre = mb_substr(trim((string)($f['nombre'] ?? '')), 0, 60);
            $d = [(int)($f['orden'] ?? 0), mb_substr(trim((string)($f['grado'] ?? '')), 0, 30), $nombre,
                  array_key_exists($f['color'] ?? '', colores_cinturon()) ? $f['color'] : 'blanco', array_key_exists($f['punta'] ?? '', colores_cinturon()) ? $f['punta'] : '',
                  mb_substr(trim((string)($f['forma'] ?? '')), 0, 80), !empty($f['activo']) ? 1 : 0];
            if (str_starts_with((string)$k, 'n')) {
                if ($nombre === '') continue;
                q('INSERT INTO esc_cinturones (orden, grado, nombre, color, punta, forma, activo, liga_id, disciplina) VALUES (?,?,?,?,?,?,?,?,?)', array_merge($d, [$lid, $disc]));
            } else {
                if ($nombre === '') throw new RuntimeException('Cada cinturón debe tener nombre (o desmárquelo como activo).');
                q('UPDATE esc_cinturones SET orden = ?, grado = ?, nombre = ?, color = ?, punta = ?, forma = ?, activo = ? WHERE id = ? AND liga_id = ?', array_merge($d, [(int)$k, $lid]));
            }
            $n++;
        }
        flash('Lista de cinturones guardada (' . $n . ').');
        ir('admin.php?p=examenes&v=cinturones&d=' . $disc);
    }
    if ($accion === 'fabrica') {
        cargar_cinturones_fabrica($lid, $disc, true);
        flash('Se cargó la lista de fábrica de ' . disciplinas_marciales()[$disc] . '.');
        ir('admin.php?p=examenes&v=cinturones&d=' . $disc);
    }
    if ($accion === 'forma') {
        $a = $alumno_ok((int)post('alumno_id'));
        guardar_forma_alumno((int)$a['id'], post('forma'), post('estado'), $por);
        flash(post('estado') === 'quitar' ? 'Quitado.' : $voc['forma'] . ' «' . post('forma') . '» de ' . nombre_alumno($a) . ': ' . strip_tags(estados_forma()[post('estado')][0] ?? '') . '.');
        ir('admin.php?p=examenes&v=formas&d=' . $disc . '#al' . (int)$a['id']);
    }
}

cabecera_admin('Exámenes de grado');
?>
<h1>🥋 Exámenes de grado y cinturones</h1>
<?php if (!$discs): ?>
  <div class="tarjeta">Para usar los exámenes de grado, los cinturones y los <?= e(mb_strtolower($voc['formas'])) ?>, cree un grupo de <b>Taekwondo</b>, <b>Karate</b> o <b>Judo</b> en
    <a href="admin.php?p=grupos">Grupos y horarios</a> (campo <b>Deporte</b>), o elija ese deporte como deporte principal en Parámetros.</div>
<?php pie_admin(); return; endif; ?>
<?php if (!$ex): ?>
  <?php if (count($discs) > 1): ?><p class="acciones"><?php foreach ($discs as $dd): ?><a class="btn btn-chico <?= $dd === $disc ? '' : 'btn-sec' ?>" href="admin.php?p=examenes&amp;v=<?= e($vista) ?>&amp;d=<?= e($dd) ?>">🥋 <?= e(disciplinas_marciales()[$dd]) ?></a> <?php endforeach; ?></p><?php endif; ?>
  <nav class="pestanas">
    <a class="<?= $vista === 'examenes' ? 'activa' : '' ?>" href="admin.php?p=examenes&amp;d=<?= e($disc) ?>">📝 Exámenes</a>
    <a class="<?= $vista === 'formas' ? 'activa' : '' ?>" href="admin.php?p=examenes&amp;v=formas&amp;d=<?= e($disc) ?>">📖 <?= e($voc['formas']) ?> de los alumnos</a>
    <a class="<?= $vista === 'cinturones' ? 'activa' : '' ?>" href="admin.php?p=examenes&amp;v=cinturones&amp;d=<?= e($disc) ?>">⚙ Cinturones y <?= e(mb_strtolower($voc['formas'])) ?></a>
  </nav>
<?php endif; ?>

<?php if ($vista === 'examenes'):
    $lista = filas('SELECT e.*, (SELECT COUNT(*) FROM esc_examen_inscritos i WHERE i.examen_id = e.id) AS n, (SELECT COUNT(*) FROM esc_examen_inscritos i WHERE i.examen_id = e.id AND i.resultado = \'aprobado\') AS ap
                    FROM esc_examenes e WHERE e.liga_id = ? AND e.disciplina = ? ORDER BY e.fecha DESC, e.id DESC', [$lid, $disc]); ?>
  <div class="rejilla-2">
    <div class="tarjeta">
      <h2 style="margin-top:0">Exámenes de <?= e(disciplinas_marciales()[$disc]) ?></h2>
      <?php if (!$lista): ?><p class="suave">Todavía no hay exámenes. Cree el primero con el formulario.</p><?php else: ?>
      <div class="tabla-scroll"><table><tr><th>Fecha</th><th>Lugar</th><th class="num">Inscritos</th><th class="num">Aprobados</th><th>Estado</th></tr>
        <?php foreach ($lista as $x): ?><tr><td><a href="admin.php?p=examenes&amp;id=<?= (int)$x['id'] ?>"><b><?= e(fecha_bonita($x['fecha'])) ?></b></a></td><td><?= e($x['lugar']) ?></td><td class="num"><?= (int)$x['n'] ?></td><td class="num"><?= (int)$x['ap'] ?></td>
          <td><?= $x['estado'] === 'cerrado' ? '<span class="chip-estado chip-ok">✓ Cerrado</span>' : '<span class="chip-estado chip-pend">Abierto</span>' ?></td></tr><?php endforeach; ?>
      </table></div><?php endif; ?>
    </div>
    <?php if ($ed): ?>
    <div class="tarjeta">
      <h2 style="margin-top:0">+ Nuevo examen de grado</h2>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="nuevo"><input type="hidden" name="disciplina" value="<?= e($disc) ?>">
        <div class="campos">
          <div><label>Fecha *</label><input type="date" name="fecha" required value="<?= date('Y-m-d', strtotime('+14 days')) ?>"></div>
          <div><label>Hora</label><input type="time" name="hora" value="09:00"></div>
          <div><label>Lugar</label><input type="text" name="lugar" maxlength="120" placeholder="Ej: <?= e($voc['lugar']) ?> principal"></div>
          <div><label>Examinador</label><input type="text" name="examinador" maxlength="100" placeholder="Ej: <?= e($voc['maestro']) ?> Carlos Andrade, 4.º Dan"></div>
          <div><label>Derecho a examen ($)</label><input type="number" name="valor" min="0" step="0.01" value="0"><div class="ayuda">Si pone un valor, con un botón se carga a cada inscrito en Pagos.</div></div>
          <div class="campo-ancho"><label>Notas</label><input type="text" name="notas" maxlength="1000" placeholder="Ej: traer el dobok limpio y el carnet"></div>
        </div>
        <button class="btn">Crear examen</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

<?php elseif ($vista === 'cinturones'):
    $lista = cinturones_escuela($lid, $disc, false);
    $colores = colores_cinturon(); ?>
  <div class="tarjeta">
    <h2 style="margin-top:0">⚙ Cinturones de <?= e(disciplinas_marciales()[$disc]) ?></h2>
    <p class="suave">Esta es la lista que usa su escuela, en orden del primero al último. Puede cambiar el grado, el nombre, los colores, la punta y el <?= e(mb_strtolower($voc['forma'])) ?> de cada cinturón, o desmarcar los que no usa. El orden decide cuál es el «próximo cinturón» de cada alumno.</p>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="guardar_cinturones">
      <div class="tabla-scroll"><table class="tabla-cinturones"><tr><th>Orden</th><th>Cinturón</th><th>Grado</th><th>Nombre</th><th>Color</th><th>Punta</th><th><?= e($voc['forma']) ?></th><th>Activo</th></tr>
        <?php foreach (array_merge($lista, [['id' => 'n1'], ['id' => 'n2'], ['id' => 'n3']]) as $i => $c): $nuevo = str_starts_with((string)$c['id'], 'n'); $k = e((string)$c['id']); ?>
        <tr><td><input type="number" name="c[<?= $k ?>][orden]" value="<?= $nuevo ? (int)(end($lista)['orden'] ?? 0) + $i - count($lista) + 1 : (int)$c['orden'] ?>" style="width:64px"></td>
          <td><?= $nuevo ? '<small class="suave">nuevo</small>' : html_cinturon($c, '', false) ?></td>
          <td><input type="text" name="c[<?= $k ?>][grado]" value="<?= e($c['grado'] ?? '') ?>" maxlength="30" style="width:96px" placeholder="8.º Kup"></td>
          <td><input type="text" name="c[<?= $k ?>][nombre]" value="<?= e($c['nombre'] ?? '') ?>" maxlength="60" placeholder="Amarillo"></td>
          <td><select name="c[<?= $k ?>][color]"><?php foreach ($colores as $ck => [$cn]): ?><option value="<?= $ck ?>" <?= ($c['color'] ?? 'blanco') === $ck ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select></td>
          <td><select name="c[<?= $k ?>][punta]"><option value="">—</option><?php foreach ($colores as $ck => [$cn]): ?><option value="<?= $ck ?>" <?= ($c['punta'] ?? '') === $ck ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select></td>
          <td><input type="text" name="c[<?= $k ?>][forma]" value="<?= e($c['forma'] ?? '') ?>" maxlength="80" placeholder="<?= $disc === 'taekwondo' ? 'Taegeuk Il Jang' : 'Heian Shodan' ?>"></td>
          <td class="num"><input type="checkbox" name="c[<?= $k ?>][activo]" value="1" <?= $nuevo || (int)$c['activo'] ? 'checked' : '' ?>></td></tr>
        <?php endforeach; ?>
      </table></div>
      <?php if ($ed): ?><button class="btn">💾 Guardar cinturones</button><?php endif; ?>
    </form>
    <?php if ($ed): ?>
    <form method="post" style="margin-top:12px" onsubmit="return confirm('¿Reemplazar su lista por la de fábrica de <?= e(disciplinas_marciales()[$disc]) ?>? Los grados ya registrados de los alumnos no se borran.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="fabrica">
      <button class="btn btn-sec btn-chico">↺ Volver a la lista de fábrica de <?= e(disciplinas_marciales()[$disc]) ?></button>
      <span class="ayuda"><?= $disc === 'taekwondo' ? 'Sistema kup/dan de World Taekwondo con puntas y los poomsae Taegeuk y de Dan.' : ($disc === 'karate' ? 'Sistema kyu/dan con los katas Heian, Tekki, Bassai y Kanku.' : ($disc === 'judo' ? 'Sistema kyu/dan de judo.' : 'Colores generales.')) ?></span>
    </form>
    <?php endif; ?>
  </div>

<?php elseif ($vista === 'formas'): ?>
  <div class="tarjeta">
    <h2 style="margin-top:0">📖 <?= e($voc['formas']) ?> de los alumnos</h2>
    <p class="suave">Para cada alumno: su cinturón, el próximo y el <?= e(mb_strtolower($voc['forma'])) ?> que debe presentar para subir. Márquelo cuando lo esté <b>aprendiendo</b> y cuando lo <b>domine</b>; los padres lo ven en su portal.</p>
    <?php if (!$alumnos): ?><p class="suave">No hay alumnos activos en grupos de <?= e(disciplinas_marciales()[$disc]) ?>.</p><?php else: ?>
    <div class="tabla-scroll"><table class="tabla-examen"><tr><th>Alumno</th><th>Cinturón → próximo</th><th><?= e($voc['forma']) ?> del próximo</th><?php if ($ed): ?><th></th><?php endif; ?></tr>
      <?php foreach ($alumnos as $a): [$gr, $act, $sig] = cinturon_alumno($a, $liga); $fs = formas_alumno((int)$a['id']); $f = $sig['forma'] ?? ''; ?>
        <tr id="al<?= (int)$a['id'] ?>"><td><a href="admin.php?p=alumno&amp;id=<?= (int)$a['id'] ?>"><?= e(nombre_alumno($a)) ?></a></td>
          <td><?= $gr ? html_cinturon($act, $act ? '' : $gr['grado']) : '<span class="suave">sin registrar</span>' ?><?= $sig ? '<br><small class="suave">→ ' . html_cinturon($sig, '', false) . ' ' . e(etiqueta_cinturon($sig)) . '</small>' : '' ?></td>
          <td><?= $f !== '' ? '<b>' . e($f) . '</b><br>' . chip_forma($fs[$f] ?? null) : '<span class="suave">—</span>' ?></td>
          <?php if ($ed): ?><td class="acciones"><?php if ($f !== ''): foreach (['aprendiendo' => '📖', 'domina' => '✅'] as $est => $ic): ?>
            <form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="forma"><input type="hidden" name="alumno_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="forma" value="<?= e($f) ?>"><input type="hidden" name="estado" value="<?= $est ?>">
              <button class="btn btn-sec btn-chico" title="<?= e(strip_tags(estados_forma()[$est][0])) ?>"><?= $ic ?> <?= $est === 'domina' ? 'La domina' : 'Aprendiendo' ?></button></form>
          <?php endforeach; endif; ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
    </table></div><?php endif; ?>
  </div>

<?php else: /* un examen */
    $ins = inscritos_examen((int)$ex['id']);
    $ids_ins = array_map(fn($i) => (int)$i['alumno_id'], $ins);
    $lista_c = cinturones_escuela($lid, $disc);
    $abierto = $ex['estado'] === 'abierto';
    $cargos = [];
    foreach ($ins as $i) if ($i['cargo_id']) $cargos[(int)$i['id']] = fila('SELECT * FROM esc_cargos WHERE id = ?', [$i['cargo_id']]);
    $n_ap = count(array_filter($ins, fn($i) => $i['resultado'] === 'aprobado')); ?>
  <p><a href="admin.php?p=examenes&amp;d=<?= e($disc) ?>">← Exámenes de grado</a></p>
  <div class="fila-titulo"><h2 style="margin:0">📝 Examen de <?= e(disciplinas_marciales()[$disc]) ?> · <?= e(fecha_bonita($ex['fecha'])) ?></h2>
    <span><?= $abierto ? '<span class="chip-estado chip-pend">Abierto</span>' : '<span class="chip-estado chip-ok">✓ Cerrado</span>' ?></span></div>
  <div class="acciones" style="margin:8px 0 12px">
    <a class="btn btn-sec" target="_blank" href="<?= e($url) ?>&amp;doc=acta">🖨 Acta del examen</a>
    <?php if (!$abierto && $n_ap): ?><a class="btn" target="_blank" href="<?= e($url) ?>&amp;doc=certificados">🏅 Certificados de los aprobados (<?= $n_ap ?>)</a><?php endif; ?>
  </div>

  <details class="tarjeta" <?= $ins ? '' : 'open' ?>>
    <summary><strong>Datos del examen</strong> <small class="suave">· <?= e(trim(($ex['hora'] ?: '') . ' ' . ($ex['lugar'] ? '· ' . $ex['lugar'] : '') . ($ex['examinador'] ? ' · ' . $ex['examinador'] : '') . ((float)$ex['valor'] > 0 ? ' · ' . dinero($ex['valor']) : ''))) ?></small></summary>
    <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="datos">
      <div class="campos">
        <div><label>Fecha</label><input type="date" name="fecha" value="<?= e($ex['fecha']) ?>"></div>
        <div><label>Hora</label><input type="time" name="hora" value="<?= e($ex['hora']) ?>"></div>
        <div><label>Lugar</label><input type="text" name="lugar" maxlength="120" value="<?= e($ex['lugar']) ?>"></div>
        <div><label>Examinador</label><input type="text" name="examinador" maxlength="100" value="<?= e($ex['examinador']) ?>"></div>
        <div><label>Derecho a examen ($)</label><input type="number" name="valor" min="0" step="0.01" value="<?= e((string)(float)$ex['valor']) ?>"></div>
        <div class="campo-ancho"><label>Notas</label><input type="text" name="notas" maxlength="1000" value="<?= e($ex['notas']) ?>"></div>
      </div>
      <?php if ($ed): ?><button class="btn btn-sec">Guardar datos</button><?php endif; ?>
    </form>
    <?php if ($ed && $abierto): ?><form method="post" style="margin-top:8px" onsubmit="return confirm('¿Eliminar este examen?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar"><button class="btn btn-peligro btn-chico">Eliminar examen</button></form><?php endif; ?>
  </details>

  <?php if ($ed && $abierto):
    $libres = array_values(array_filter($alumnos, fn($a) => !in_array((int)$a['id'], $ids_ins, true))); ?>
  <details class="tarjeta" id="inscribir" <?= $ins ? '' : 'open' ?>>
    <summary><strong>+ Inscribir alumnos</strong> <small class="suave">· <?= count($libres) ?> sin inscribir</small></summary>
    <?php if (!$libres): ?><p class="suave">Todos los alumnos de <?= e(disciplinas_marciales()[$disc]) ?> ya están inscritos.</p><?php else: ?>
    <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="inscribir">
      <p class="ayuda">Viene marcado el próximo cinturón de cada alumno; puede cambiarlo (por ejemplo, si salta un grado).</p>
      <div class="tabla-scroll"><table><tr><th><input type="checkbox" onclick="document.querySelectorAll('.chk-ins').forEach(c => c.checked = this.checked)" aria-label="Marcar todos"></th><th>Alumno</th><th>Cinturón actual</th><th>Aspira a</th><th><?= e($voc['forma']) ?></th></tr>
        <?php foreach ($libres as $a): [$gr, $act, $sig] = cinturon_alumno($a, $liga); $fs = formas_alumno((int)$a['id']); ?>
          <tr><td><input type="checkbox" class="chk-ins" name="alumnos[]" value="<?= (int)$a['id'] ?>"></td><td><?= e(nombre_alumno($a)) ?></td>
            <td><?= $gr ? html_cinturon($act, $act ? '' : $gr['grado']) : '<span class="suave">sin registrar</span>' ?></td>
            <td><select name="aspira[<?= (int)$a['id'] ?>]"><?php foreach ($lista_c as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $sig && (int)$sig['id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e(etiqueta_cinturon($c)) ?></option><?php endforeach; ?></select></td>
            <td><?= $sig && $sig['forma'] !== '' ? e($sig['forma']) . ' ' . chip_forma($fs[$sig['forma']] ?? null) : '' ?></td></tr>
        <?php endforeach; ?>
      </table></div>
      <button class="btn">Inscribir a los marcados</button>
    </form><?php endif; ?>
  </details>
  <?php endif; ?>

  <div class="tarjeta" id="inscritos">
    <div class="fila-titulo"><h2 style="margin:0">Inscritos (<?= count($ins) ?>)</h2>
      <?php if ($ins && $abierto && (float)$ex['valor'] > 0 && (puede('cobros', true) || $ed)): ?><form method="post" class="inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="cobrar"><button class="btn btn-sec btn-chico">💵 Cargar derecho a examen (<?= dinero($ex['valor']) ?>) a los que falten</button></form><?php endif; ?></div>
    <?php if (!$ins): ?><p class="suave">Todavía no hay inscritos.</p><?php else: ?>
    <form method="post" id="resultados"><?= csrf_campo() ?><input type="hidden" name="accion" value="resultados">
      <div class="tabla-scroll"><table class="tabla-examen"><tr><th>Alumno</th><th>Aspira a</th><th><?= e($voc['forma']) ?></th><th>Pago</th><th>Nota</th><th>Resultado</th><th>Observaciones</th><th></th></tr>
        <?php foreach ($ins as $i): $cg = $cargos[(int)$i['id']] ?? null; $c = null; foreach ($lista_c as $x) if ((int)$x['id'] === (int)$i['cinturon_id']) $c = $x; ?>
          <tr><td><a href="admin.php?p=alumno&amp;id=<?= (int)$i['alumno_id'] ?>"><?= e(nombre_alumno($i)) ?></a><br><small class="suave"><span>Actual:</span> <span><?= e($i['grado_actual'] ?: '—') ?></span></small></td>
            <td><?= html_cinturon($c, $i['grado_aspira']) ?></td>
            <td><?= $i['forma'] === '' ? '—' : e($i['forma']) . '<br>' . ($abierto ? '<label class="check" style="font-weight:400"><input type="checkbox" name="forma_ok[' . (int)$i['id'] . ']" value="1" ' . ($i['forma_ok'] ? 'checked' : '') . '> la domina</label>' : ($i['forma_ok'] ? '<small>✅ la domina</small>' : '')) ?></td>
            <td><?= $cg ? ($cg['estado'] === 'pagado' ? '<span class="chip-estado chip-ok">Pagado</span>' : '<span class="chip-estado chip-pend">Pendiente</span>') : '<span class="suave">—</span>' ?></td>
            <?php if ($abierto): ?>
            <td><input type="text" name="nota[<?= (int)$i['id'] ?>]" value="<?= e($i['nota']) ?>" maxlength="20" style="width:64px;min-width:0"></td>
            <td><select name="res[<?= (int)$i['id'] ?>]"><?php foreach (resultados_examen() as $k => [$t]): ?><option value="<?= $k ?>" <?= $i['resultado'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></td>
            <td><input type="text" name="obs[<?= (int)$i['id'] ?>]" value="<?= e($i['observaciones']) ?>" maxlength="200"></td>
            <?php else: [$rt, $rc] = resultados_examen()[$i['resultado']] ?? ['', '']; ?>
            <td><?= e($i['nota'] ?: '—') ?></td><td><span class="chip-estado <?= $rc ?>"><?= e($rt) ?></span></td><td><?= e($i['observaciones']) ?></td>
            <?php endif; ?>
            <td class="acciones"><?php if (!$abierto && $i['resultado'] === 'aprobado' && $i['certificado'] !== ''): ?><a class="btn btn-sec btn-chico" target="_blank" href="<?= e($url) ?>&amp;doc=certificados&amp;i=<?= (int)$i['id'] ?>">🏅 Certificado</a><?php endif; ?></td></tr>
        <?php endforeach; ?>
      </table></div>
      <?php if ($ed && $abierto): ?><button class="btn">💾 Guardar resultados</button><?php endif; ?>
    </form>
    <?php if ($ed && $abierto): ?>
      <div class="acciones" style="margin-top:12px">
        <form method="post" class="inline" onsubmit="return confirm('¿Cerrar el examen? A los aprobados se les registra el cinturón nuevo.')"><?= csrf_campo() ?><input type="hidden" name="accion" value="cerrar"><button class="btn">🥋 Cerrar examen y entregar cinturones</button></form>
      </div>
      <details style="margin-top:10px"><summary class="suave">Quitar a un inscrito</summary>
        <?php foreach ($ins as $i): ?><form method="post" class="inline" onsubmit="return confirm('¿Quitar a este alumno del examen?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="quitar"><input type="hidden" name="iid" value="<?= (int)$i['id'] ?>"><button class="btn btn-sec btn-chico">✕ <?= e(nombre_alumno($i)) ?></button></form> <?php endforeach; ?>
      </details>
    <?php elseif ($ed): ?>
      <form method="post" style="margin-top:12px"><?= csrf_campo() ?><input type="hidden" name="accion" value="reabrir"><button class="btn btn-sec btn-chico">Abrir de nuevo para corregir</button></form>
    <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php
pie_admin();
