<?php
/* (v35) Panel del superadministrador: MÓDULOS DEPORTIVOS (resumen, precios, requisitos, reglas, cuentas y demos por módulo) */
if (!es_super()) ir('admin.php');
$s = get('s', 'resumen');
if (!in_array($s, ['resumen', 'precios', 'requisitos', 'reglas', 'cuentas', 'demo'], true)) $s = 'resumen';
$url = 'admin.php?p=modulos';
$mods = modulos_deporte();
$mk = get('m', array_key_first($mods));
if (!isset($mods[$mk])) $mk = array_key_first($mods);

if (es_post()) {
    $accion = post('accion');

    if ($accion === 'precios') {
        foreach ($mods as $k => $m) {
            $p = round((float)str_replace(',', '.', (string)($_POST['precio'][$k] ?? $m['precio'])), 2);
            if ($p < 0 || $p > 10000) throw new RuntimeException('Precio no válido para ' . $m['nombre'] . '.');
            q('UPDATE modulos_deporte SET precio_mensual = ?, activo = ?, orden = ?, descripcion = ?, actualizado = CURRENT_TIMESTAMP WHERE clave = ?', [
                $p, isset($_POST['activo'][$k]) ? 1 : 0, entero($_POST['orden'][$k] ?? $m['orden'], 0, 99), mb_substr(trim((string)($_POST['desc'][$k] ?? '')), 0, 300), $k]);
        }
        $d = round((float)str_replace(',', '.', post('descuento')), 1);
        if ($d < 0 || $d > 90) throw new RuntimeException('El descuento va de 0 a 90 %.');
        guardar_ajuste('modulo_descuento', (string)$d);
        guardar_ajuste('modulo_incluido', post('incluido') === '1' ? '1' : '0');
        bitacora_evento('ventas', 'Cambió los precios de los módulos deportivos (descuento ' . $d . ' %)');
        flash('Precios de los módulos guardados.');
        ir($url . '&s=precios');
    }

    if ($accion === 'requisitos') {
        $k = post('modulo');
        if (!isset($mods[$k])) throw new RuntimeException('Módulo no válido.');
        $campos = [];
        foreach (requisitos_campos_catalogo() as $c => $x) {
            $v = (int)($_POST['campo'][$c] ?? 0);
            if ($v > 0) $campos[$c] = min(2, $v);
        }
        $docs = [];
        foreach ((array)($_POST['doc_nombre'] ?? []) as $i => $n) {
            $n = trim((string)$n);
            if ($n === '') continue;
            $clave = trim((string)($_POST['doc_clave'][$i] ?? '')) ?: substr(preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $n) ?: 'doc')), 0, 24);
            $docs[] = ['clave' => $clave, 'nombre' => $n, 'obligatorio' => isset($_POST['doc_obl'][$i]) ? 1 : 0, 'vence' => isset($_POST['doc_vence'][$i]) ? 1 : 0];
        }
        $req = requisitos_normalizar(['campos' => $campos, 'docs' => $docs], $k);
        q('UPDATE modulos_deporte SET requisitos = ?, actualizado = CURRENT_TIMESTAMP WHERE clave = ?', [json_encode($req, JSON_UNESCAPED_UNICODE), $k]);
        bitacora_evento('ventas', 'Cambió los requisitos del módulo ' . $mods[$k]['nombre']);
        flash('Requisitos de ' . $mods[$k]['nombre'] . ' guardados. Se piden desde ya en las fichas e inscripciones de ese deporte.');
        ir($url . '&s=requisitos&m=' . $k);
    }

    if ($accion === 'requisitos_fabrica') {
        $k = post('modulo');
        if (!isset($mods[$k])) throw new RuntimeException('Módulo no válido.');
        q("UPDATE modulos_deporte SET requisitos = '' WHERE clave = ?", [$k]);
        flash('Se volvieron a poner los requisitos de fábrica de ' . $mods[$k]['nombre'] . '.');
        ir($url . '&s=requisitos&m=' . $k);
    }

    if ($accion === 'reglas') {
        $k = post('modulo');
        if (!isset($mods[$k])) throw new RuntimeException('Módulo no válido.');
        $r = [];
        foreach ($mods[$k]['modalidades'] as $mod) {
            foreach (reglas_editables($mod) as $c => $def) {
                $v = trim((string)($_POST['r'][$mod][$c] ?? ''));
                if ($v === '') continue;
                if ($def[1] === 'entero') {
                    if (!ctype_digit($v) || (int)$v > 999) throw new RuntimeException(nombre_modalidad($mod) . ' · ' . $def[0] . ': escriba un número entero.');
                    $r[$mod][$c] = (int)$v;
                } elseif ($def[1] === 'opcion') {
                    if (isset($def[2][$v])) $r[$mod][$c] = $v;
                } else {
                    if ($c === 'carrera_puntos' && !preg_match('/^\d+([\s,;]+\d+)*$/', $v)) throw new RuntimeException(nombre_modalidad($mod) . ' · ' . $def[0] . ': números separados por comas.');
                    $r[$mod][$c] = $c === 'carrera_puntos' ? implode(',', ciclo_lista_num($v)) : mb_substr($v, 0, 120);
                }
            }
        }
        q('UPDATE modulos_deporte SET reglas = ?, actualizado = CURRENT_TIMESTAMP WHERE clave = ?', [$r ? json_encode($r) : '', $k]);
        bitacora_evento('ventas', 'Cambió las reglas por defecto del módulo ' . $mods[$k]['nombre']);
        flash('Reglas por defecto de ' . $mods[$k]['nombre'] . ' guardadas: se usan en cada campeonato nuevo de ese deporte (cada cuenta puede cambiarlas en su campeonato).');
        ir($url . '&s=reglas&m=' . $k);
    }

    if ($accion === 'cuentas') {
        $n = 0;
        foreach (filas('SELECT id, nombre, deportes, es_demo FROM ligas WHERE es_demo = 0') as $l) {
            if (!isset($_POST['fila'][$l['id']])) continue;
            $ds = array_values(array_intersect(array_keys(modulos_deporte_base()), array_keys((array)($_POST['dep'][$l['id']] ?? []))));
            if (!$ds) throw new RuntimeException($l['nombre'] . ': deje al menos un módulo.');
            $nuevo = count($ds) === count(modulos_deporte_base()) ? '*' : implode(',', $ds);
            if ($nuevo !== (string)$l['deportes']) {
                q('UPDATE ligas SET deportes = ? WHERE id = ?', [$nuevo, $l['id']]);
                bitacora_evento('ventas', 'Módulos deportivos de ' . $l['nombre'] . ': ' . texto_modulos($ds), (int)$l['id']);
                $n++;
            }
        }
        flash($n ? "Módulos actualizados en $n cuenta(s)." : 'No hubo cambios.');
        ir($url . '&s=cuentas');
    }

    if ($accion === 'demo') {
        $ds = leer_deportes_post();
        $dias = entero(post('dias'), 1, 90);
        $tipo = post('tipo') === 'escuela' ? 'escuela' : 'liga';
        $dep_esc = post('deporte_escuela');
        if ($tipo === 'escuela' && $ds && !deporte_escuela_permitido(['deportes' => implode(',', $ds)], $dep_esc)) {
            foreach (catalogo_deportes() as $dk => $x) if (deporte_escuela_permitido(['deportes' => implode(',', $ds)], $dk)) { $dep_esc = $dk; break; }
        }
        $clave = post('clave') !== '' ? post('clave') : substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10);
        $r = crear_liga_completa([
            'nombre' => post('nombre'), 'ciudad' => post('ciudad'), 'admin_nombre' => post('admin_nombre'), 'admin_email' => post('admin_email'), 'admin_clave' => $clave,
            'telefono' => post('telefono'), 'plan_id' => (int)post('plan_id') ?: null, 'vence' => date('Y-m-d', strtotime('+' . $dias . ' days')), 'es_prueba' => true,
            'tipo' => $tipo, 'deporte_escuela' => $dep_esc, 'deportes' => $ds, 'crear_campeonato' => true,
            'modalidad' => filtrar_modalidades(['deportes' => implode(',', $ds)], modalidades())[0] ?? 'F7',
        ]);
        $_SESSION['liga_creada'] = ['id' => $r['liga_id'], 'email' => mb_strtolower(post('admin_email')), 'clave' => $clave, 'telefono' => post('telefono'), 'admin' => post('admin_nombre')];
        if ($r['usuario_id']) correo_bienvenida(fila('SELECT * FROM ligas WHERE id = ?', [$r['liga_id']]), mb_strtolower(post('admin_email')), $clave, post('admin_nombre'));
        bitacora_evento('ventas', 'Creó la demo de ' . texto_modulos($ds) . ': ' . post('nombre') . ' (' . $dias . ' días)', (int)$r['liga_id']);
        ir('admin.php?p=ligas&creada=' . $r['liga_id']);
    }
    ir($url);
}

// Números por módulo
$cuentas = filas("SELECT l.id, l.nombre, l.tipo, l.deportes, l.modalidad, l.deporte_escuela, l.es_demo, l.es_prueba, l.vence, l.plan_id, l.activa, l.es_federacion, p.nombre AS plan FROM ligas l LEFT JOIN planes p ON p.id = l.plan_id ORDER BY l.es_demo, l.nombre");
$stats = [];
foreach ($mods as $k => $m) $stats[$k] = ['pagadas' => 0, 'prueba' => 0, 'demo' => 0, 'campeonatos' => 0, 'grupos' => 0, 'ingreso' => 0.0];
foreach ($cuentas as $l) {
    foreach (!empty($l['es_demo']) ? deportes_liga($l) : deportes_cobrados($l) as $k) { // cuentas sin módulos elegidos: cuenta su deporte principal
        if (!isset($stats[$k])) continue;
        if (!empty($l['es_demo'])) $stats[$k]['demo']++;
        elseif (!empty($l['es_prueba'])) $stats[$k]['prueba']++;
        else $stats[$k]['pagadas']++;
    }
}
foreach (filas('SELECT modalidad, COUNT(*) AS n FROM torneos GROUP BY modalidad') as $x) if (($k = modulo_de_modalidad($x['modalidad'])) && isset($stats[$k])) $stats[$k]['campeonatos'] += (int)$x['n'];
foreach (filas("SELECT g.deporte, l.deporte_escuela, COUNT(*) AS n FROM esc_grupos g JOIN ligas l ON l.id = g.liga_id GROUP BY g.deporte, l.deporte_escuela") as $x) {
    $k = modulo_de_deporte_escuela((string)($x['deporte'] ?: $x['deporte_escuela'] ?: 'futbol'));
    if (isset($stats[$k])) $stats[$k]['grupos'] += (int)$x['n'];
}

cabecera_admin('Módulos deportivos');
?>
<h1>🧩 Módulos deportivos</h1>
<p class="suave" style="margin-top:0">El sistema se vende por deporte. Cada cuenta tiene sus módulos; quien compra más de uno lo ve todo junto en el mismo panel. Aquí maneja precios, requisitos, reglas por defecto, qué módulos tiene cada cuenta y las demos de cada deporte.</p>
<nav class="pestanas">
  <?php foreach (['resumen' => 'Resumen', 'precios' => '💵 Precios', 'requisitos' => '📋 Requisitos', 'reglas' => '📐 Reglas', 'cuentas' => '👥 Cuentas', 'demo' => '🎁 Crear demo'] as $k => $t): ?>
    <a href="<?= e($url . '&s=' . $k) ?>" class="<?= $s === $k ? 'activa' : '' ?>"><?= e($t) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($s === 'resumen'): ?>
  <div class="rejilla-mod">
    <?php foreach ($mods as $k => $m): $st = $stats[$k]; $req = $m['requisitos']; ?>
      <div class="tarjeta<?= $m['activo'] ? '' : ' mod-inactivo' ?>">
        <h2 style="margin:0"><?= $m['icono'] ?> <?= e($m['nombre']) ?> <?= $m['activo'] ? '' : '<span class="etiqueta gris">no se ofrece</span>' ?></h2>
        <p class="suave" style="margin:.3rem 0"><?= e($m['descripcion']) ?></p>
        <p style="margin:.3rem 0"><b><?= dinero($m['precio']) ?></b>/mes · <?= count($m['modalidades']) ?> modalidades · <?= count($req['campos']) ?> datos y <?= count($req['docs']) ?> documentos pedidos</p>
        <p style="margin:.3rem 0">👥 <b><?= $st['pagadas'] ?></b> pagadas · <b><?= $st['prueba'] ?></b> en demo · 🏆 <?= $st['campeonatos'] ?> campeonatos · 🎓 <?= $st['grupos'] ?> grupos</p>
        <p style="margin:.3rem 0"><small class="suave"><?= e(implode(', ', array_map('nombre_modalidad', $m['modalidades']))) ?></small></p>
        <div class="acciones">
          <a class="btn btn-chico" href="<?= e($url . '&s=demo&m=' . $k) ?>">🎁 Crear demo</a>
          <a class="btn btn-sec btn-chico" href="<?= e($url . '&s=requisitos&m=' . $k) ?>">📋 Requisitos</a>
          <a class="btn btn-sec btn-chico" href="<?= e($url . '&s=reglas&m=' . $k) ?>">📐 Reglas</a>
          <a class="btn btn-sec btn-chico" target="_blank" href="index.php?v=solicitar&amp;modulo=<?= e($k) ?>">🔗 Enlace de demo</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="aviso aviso-info" style="margin-top:14px">🔗 <b>Enlace de demo por deporte</b>: comparta <code><?= e(url_base()) ?>index.php?v=solicitar&amp;modulo=ciclismo</code> (o <?= e(implode(', ', array_keys(modulos_deporte()))) ?>) y el formulario llega con ese deporte marcado. La prueba gratis también: <code>index.php?v=registro&amp;modulo=…</code></div>
  <style>.rejilla-mod{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px}.mod-inactivo{opacity:.6}</style>

<?php elseif ($s === 'precios'): ?>
  <div class="tarjeta">
    <h2 style="margin-top:0">💵 Precios de los módulos</h2>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="precios">
      <div class="campos">
        <div><label>Descuento desde el 2.° módulo (%)</label><input type="text" name="descuento" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format(modulo_descuento(), 1, '.', ''), '0'), '.')) ?>"><div class="ayuda">Se aplica a cada módulo adicional (0 = sin descuento).</div></div>
        <div><label class="check" style="margin-top:28px"><input type="checkbox" name="incluido" value="1" <?= modulo_incluido() ? 'checked' : '' ?>> El plan incluye el primer módulo</label><div class="ayuda">Si lo desmarca, se cobran todos los módulos además del plan.</div></div>
      </div>
      <div class="tabla-scroll"><table>
        <tr><th>Módulo</th><th class="num">Precio mensual ($)</th><th>Se ofrece</th><th class="num">Orden</th><th>Descripción (lo que ve el cliente)</th></tr>
        <?php foreach ($mods as $k => $m): ?>
          <tr><td><?= $m['icono'] ?> <b><?= e($m['nombre']) ?></b></td>
            <td class="num"><input type="text" name="precio[<?= e($k) ?>]" value="<?= e(number_format($m['precio'], 2, '.', '')) ?>" inputmode="decimal" style="width:90px"></td>
            <td style="text-align:center"><input type="checkbox" name="activo[<?= e($k) ?>]" value="1" <?= $m['activo'] ? 'checked' : '' ?>></td>
            <td class="num"><input type="number" name="orden[<?= e($k) ?>]" value="<?= (int)$m['orden'] ?>" min="0" max="99" style="width:60px"></td>
            <td><textarea name="desc[<?= e($k) ?>]" rows="2" maxlength="300" style="min-width:280px"><?= e($m['descripcion']) ?></textarea></td></tr>
        <?php endforeach; ?>
      </table></div>
      <button class="btn">Guardar precios</button>
    </form>
  </div>
  <div class="tarjeta" style="margin-top:14px">
    <h3 style="margin-top:0">Ejemplos con los precios actuales</h3>
    <?php $ej = [['futbol'], ['futbol', 'basquet'], ['futbol', 'ciclismo', 'combate'], array_keys($mods)]; ?>
    <table><tr><th>Cuenta con</th><th class="num">Se suma al plan por mes</th><th>Detalle</th></tr>
      <?php foreach ($ej as $ks): $pm = precio_modulos($ks); ?><tr><td><?= e(texto_modulos($ks)) ?></td><td class="num"><b><?= dinero($pm['extra']) ?></b></td><td style="white-space:normal"><small><?= e(texto_precio_modulos($ks)) ?></small></td></tr><?php endforeach; ?>
    </table>
    <p class="ayuda">El total que paga el cliente = precio del plan (Básico, Profesional…) + lo de esta columna. En 6 y 12 meses se aplica la misma rebaja del plan (meses gratis).</p>
  </div>

<?php elseif ($s === 'requisitos'):
    $m = $mods[$mk];
    $req = $m['requisitos'];
    $cat = requisitos_campos_catalogo(); ?>
  <p><?php foreach ($mods as $k => $x): ?><a class="btn btn-chico <?= $k === $mk ? '' : 'btn-sec' ?>" href="<?= e($url . '&s=requisitos&m=' . $k) ?>"><?= $x['icono'] ?> <?= e($x['nombre']) ?></a> <?php endforeach; ?></p>
  <div class="tarjeta">
    <h2 style="margin-top:0">📋 Requisitos de <?= e($m['icono'] . ' ' . $m['nombre']) ?></h2>
    <p class="suave" style="margin-top:0">Se piden en la ficha del jugador, del competidor o del alumno de este deporte y en la inscripción en línea. Lo <b>obligatorio</b> que falte aparece como «⚠ falta» en las listas, y en la inscripción en línea no se puede enviar sin eso.</p>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="requisitos"><input type="hidden" name="modulo" value="<?= e($mk) ?>">
      <h3>Datos de inscripción</h3>
      <div class="tabla-scroll"><table>
        <tr><th>Dato</th><th>No se pide</th><th>Se pide</th><th>Obligatorio</th></tr>
        <?php foreach ($cat as $c => [$lab]): $v = $req['campos'][$c] ?? 0; ?>
          <tr><td><?= e($lab) ?></td><?php foreach ([0, 1, 2] as $o): ?><td style="text-align:center"><input type="radio" name="campo[<?= e($c) ?>]" value="<?= $o ?>" <?= $v === $o ? 'checked' : '' ?> aria-label="<?= e($lab) ?>"></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
      </table></div>
      <p class="ayuda">Nombre, cédula, foto, fecha de nacimiento y club ya están en cada ficha: aquí van los datos propios del deporte.</p>
      <h3>Documentos</h3>
      <div class="tabla-scroll"><table>
        <tr><th>Documento</th><th>Obligatorio</th><th>Tiene vencimiento</th></tr>
        <?php $filas_doc = array_merge($req['docs'], array_fill(0, 3, ['clave' => '', 'nombre' => '', 'obligatorio' => 0, 'vence' => 0]));
        foreach ($filas_doc as $i => $d): ?>
          <tr><td><input type="hidden" name="doc_clave[<?= $i ?>]" value="<?= e($d['clave']) ?>"><input type="text" name="doc_nombre[<?= $i ?>]" value="<?= e($d['nombre']) ?>" maxlength="80" placeholder="<?= $d['clave'] === '' ? 'Nuevo documento (ej: Ficha de la federación)' : '' ?>" style="min-width:260px"></td>
            <td style="text-align:center"><input type="checkbox" name="doc_obl[<?= $i ?>]" value="1" <?= $d['obligatorio'] ? 'checked' : '' ?>></td>
            <td style="text-align:center"><input type="checkbox" name="doc_vence[<?= $i ?>]" value="1" <?= $d['vence'] ? 'checked' : '' ?>></td></tr>
        <?php endforeach; ?>
      </table></div>
      <p class="ayuda">Para quitar un documento, borre su nombre. Con «tiene vencimiento» se pide la fecha y, cuando pasa, vuelve a aparecer como faltante (ej: certificado médico anual).</p>
      <button class="btn">Guardar requisitos</button>
    </form>
    <form method="post" style="margin-top:8px" onsubmit="return confirm('¿Volver a los requisitos de fábrica de este módulo?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="requisitos_fabrica"><input type="hidden" name="modulo" value="<?= e($mk) ?>"><button class="btn btn-sec btn-chico">↺ Requisitos de fábrica</button></form>
  </div>

<?php elseif ($s === 'reglas'):
    $m = $mods[$mk]; ?>
  <p><?php foreach ($mods as $k => $x): ?><a class="btn btn-chico <?= $k === $mk ? '' : 'btn-sec' ?>" href="<?= e($url . '&s=reglas&m=' . $k) ?>"><?= $x['icono'] ?> <?= e($x['nombre']) ?></a> <?php endforeach; ?></p>
  <div class="tarjeta">
    <h2 style="margin-top:0">📐 Reglas por defecto de <?= e($m['icono'] . ' ' . $m['nombre']) ?></h2>
    <p class="suave" style="margin-top:0">Son los valores con los que empieza cada campeonato nuevo de este deporte en todas las cuentas. Deje vacío para usar el valor de fábrica (entre paréntesis). Cada cuenta puede cambiarlos después en su campeonato.</p>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="reglas"><input type="hidden" name="modulo" value="<?= e($mk) ?>">
      <?php foreach ($m['modalidades'] as $mod): $ed = reglas_editables($mod); if (!$ed) continue; $act = (array)($m['reglas'][$mod] ?? []); $fab = presets_modalidad_fabrica()[$mod] ?? []; ?>
        <h3 style="margin:.8rem 0 .3rem"><?= e(nombre_modalidad($mod)) ?></h3>
        <div class="campos">
          <?php foreach ($ed as $c => $def): $f = $fab[$c] ?? ''; ?>
            <div><label><?= e($def[0]) ?></label>
              <?php if ($def[1] === 'opcion'): ?>
                <select name="r[<?= e($mod) ?>][<?= e($c) ?>]"><option value="">— de fábrica —</option><?php foreach ($def[2] as $ok => $ot): ?><option value="<?= e($ok) ?>" <?= (string)($act[$c] ?? '') === (string)$ok ? 'selected' : '' ?>><?= e($ot) ?></option><?php endforeach; ?></select>
              <?php else: ?>
                <input type="text" name="r[<?= e($mod) ?>][<?= e($c) ?>]" value="<?= e((string)($act[$c] ?? '')) ?>" placeholder="<?= e($f !== '' ? '(' . $f . ')' : '') ?>"<?= $def[1] === 'entero' ? ' inputmode="numeric" style="width:90px"' : '' ?>>
              <?php endif; ?></div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <button class="btn" style="margin-top:10px">Guardar reglas</button>
    </form>
  </div>

<?php elseif ($s === 'cuentas'):
    $reales = array_values(array_filter($cuentas, fn($l) => empty($l['es_demo']))); ?>
  <div class="tarjeta">
    <h2 style="margin-top:0">👥 Módulos de cada cuenta</h2>
    <p class="suave" style="margin-top:0">Marque los módulos de cada cliente y toque <b>Guardar</b>. Las demos del sistema siempre tienen todos. Para crear usuarios o cambiar el plan, entre a la cuenta (✏️).</p>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="cuentas">
      <div class="tabla-scroll"><table>
        <tr><th>Cuenta</th><th>Estado</th><?php foreach ($mods as $k => $m): ?><th style="text-align:center" title="<?= e($m['nombre']) ?>"><?= $m['icono'] ?><br><small><?= e($m['nombre']) ?></small></th><?php endforeach; ?><th class="num">Módulos $/mes</th><th></th></tr>
        <?php foreach ($reales as $l): $dl = deportes_liga($l); $sus = estado_suscripcion(fila('SELECT * FROM ligas WHERE id = ?', [$l['id']])); ?>
          <tr><td><input type="hidden" name="fila[<?= (int)$l['id'] ?>]" value="1"><?= ($l['tipo'] ?? 'liga') === 'escuela' ? '🎓' : (!empty($l['es_federacion']) ? '🏛' : '🏆') ?> <?= e($l['nombre']) ?></td>
            <td><small><?= e($sus['texto']) ?><?= $l['plan'] ? ' · ' . e($l['plan']) : '' ?></small></td>
            <?php foreach ($mods as $k => $m): ?><td style="text-align:center"><input type="checkbox" name="dep[<?= (int)$l['id'] ?>][<?= e($k) ?>]" value="1" <?= in_array($k, $dl, true) ? 'checked' : '' ?> aria-label="<?= e($m['nombre']) ?>"></td><?php endforeach; ?>
            <td class="num"><?= dinero(precio_modulos($dl)['extra']) ?></td>
            <td><a class="btn btn-sec btn-chico" href="admin.php?p=ligas&amp;editar=<?= (int)$l['id'] ?>" title="Editar cuenta, usuarios y plan">✏️</a></td></tr>
        <?php endforeach; ?>
      </table></div>
      <?php if ($reales): ?><button class="btn" style="margin-top:8px">Guardar</button><?php else: ?><p class="suave">Todavía no hay cuentas de clientes.</p><?php endif; ?>
    </form>
  </div>

<?php else: /* demo */
    $planes = filas('SELECT * FROM planes WHERE activo = 1 ORDER BY orden, id'); ?>
  <div class="tarjeta">
    <h2 style="margin-top:0">🎁 Crear una demo para un cliente</h2>
    <p class="suave" style="margin-top:0">Crea la cuenta con los deportes que eligió, su administrador y su primer campeonato (si tiene deportes de equipo). Al terminar le muestra los accesos para enviarlos por WhatsApp y, si el correo está configurado, le llegan por correo.</p>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="demo">
      <label>Deportes de la demo</label>
      <?= html_casillas_modulos([$mk], 'deportes', true) ?>
      <div class="campos">
        <div><label>Nombre de la liga, club o escuela *</label><input type="text" name="nombre" required maxlength="120"></div>
        <div><label>Ciudad</label><input type="text" name="ciudad" maxlength="80"></div>
        <div><label>Tipo de cuenta</label><select name="tipo"><option value="liga">🏆 Liga / campeonatos</option><option value="escuela">🎓 Escuela deportiva</option></select></div>
        <div><label>Deporte principal (escuela)</label><select name="deporte_escuela"><?php foreach (deportes_escuela() as $dk => $dv): ?><option value="<?= e($dk) ?>"><?= e($dv) ?></option><?php endforeach; ?></select><div class="ayuda">Si no es de los deportes elegidos, se toma el primero que sí lo sea.</div></div>
        <div><label>Plan</label><select name="plan_id"><option value="">Sin plan (todo)</option><?php foreach ($planes as $pl): ?><option value="<?= (int)$pl['id'] ?>"><?= e($pl['nombre']) ?></option><?php endforeach; ?></select></div>
        <div><label>Días de la demo</label><input type="number" name="dias" min="1" max="90" value="<?= (int)ajuste('dias_demo', 7) ?>"></div>
        <div><label>Nombre del administrador *</label><input type="text" name="admin_nombre" required maxlength="80"></div>
        <div><label>Correo (usuario) *</label><input type="email" name="admin_email" required></div>
        <div><label>Celular / WhatsApp</label><input type="tel" name="telefono" maxlength="30"></div>
        <div><label>Contraseña inicial</label><input type="text" name="clave" minlength="8" placeholder="vacío = se crea sola"></div>
      </div>
      <button class="btn btn-grande">🎁 Crear la demo</button>
    </form>
  </div>
<?php endif;
pie_admin();
