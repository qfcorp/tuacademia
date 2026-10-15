<?php
/* Gestión de ligas (solo superadministrador): crear una liga nueva con su administrador en un solo formulario */
if (!es_super()) {
    ir('admin.php');
}

if (get('salir_liga') === '1') {
    unset($_SESSION['liga_trabajo']);
    ir('admin.php?p=ligas');
}
if (get('entrar') !== '') {
    $l = fila('SELECT id, eliminada FROM ligas WHERE id = ?', [(int)get('entrar')]);
    if ($l && liga_eliminada($l)) { // (Anexo A) una cuenta de la papelera no se abre: primero se restaura
        flash('Esa cuenta está en la papelera. Restáurela para poder entrar.', 'error');
        ir('admin.php?p=ligas&papelera=1');
    }
    if ($l) {
        $_SESSION['liga_trabajo'] = (int)$l['id'];
    }
    ir('admin.php');
}

if (es_post()) {
    $accion = post('accion', 'guardar');
    if ($accion === 'restaurar') { // (Anexo A) sacar una cuenta de la papelera (vuelve tal cual estaba)
        $l = fila('SELECT * FROM ligas WHERE id = ?', [(int)post('id')]) ?? throw new RuntimeException('Esa cuenta ya no existe (quizás ya se borró de verdad).');
        $su = usuario();
        if (restaurar_cuenta($l, $su['nombre'] . ' <' . $su['email'] . '>')) flash($l['nombre'] . ' salió de la papelera y quedó tal cual estaba.');
        else flash($l['nombre'] . ' no estaba en la papelera.', 'error');
        ir('admin.php?p=ligas&papelera=1');
    }
    if ($accion === 'eliminar') {
        $l = fila('SELECT * FROM ligas WHERE id = ?', [(int)post('id')]);
        if ($l && post('confirmar') === $l['nombre']) {
            q('DELETE FROM ligas WHERE id = ?', [$l['id']]);
            unset($_SESSION['liga_trabajo']);
            flash('Liga eliminada con todos sus datos.');
        } else {
            flash('No se eliminó: debe escribir el nombre exacto de la liga para confirmar.', 'error');
        }
        ir('admin.php?p=ligas');
    }
    if ($accion === 'crear') {
        $tipo = post('vigencia');
        $vence = match ($tipo) {
            'prueba' => date('Y-m-d', strtotime('+' . entero(post('dias_prueba'), 1, 365) . ' days')),
            'pagada' => post('vence') !== '' && strtotime(post('vence')) ? date('Y-m-d', strtotime(post('vence'))) : throw new RuntimeException('Escriba hasta qué fecha está pagada.'),
            default => '',
        };
        $r = crear_demo_cuenta([ // (Tukán Central v2) la misma función que usa el Panel Tukán Central para crear demos
            'nombre' => post('nombre'), 'ciudad' => post('ciudad'), 'slug' => post('slug'), 'modalidad' => post('modalidad'), 'color' => post('color'),
            'admin_nombre' => post('admin_nombre'), 'admin_email' => post('admin_email'), 'admin_clave' => post('admin_clave'), 'telefono' => post('telefono'),
            'plan_id' => (int)post('plan_id') ?: null, 'vence' => $vence, 'es_prueba' => $tipo === 'prueba', 'crear_campeonato' => post('crear_campeonato') === '1',
            'tipo' => post('tipo'), 'deporte_escuela' => post('deporte_escuela'), 'combo_con' => (int)post('combo_con'), 'deportes' => isset($_POST['deportes']) ? leer_deportes_post() : null,
            'max_campeonatos' => post('max_campeonatos'), 'max_equipos' => post('max_equipos'), 'modo_campeonatos' => post('modo_campeonatos'),
            'vendedor_id' => (int)post('vendedor_id') && fila('SELECT id FROM vendedores WHERE id = ?', [(int)post('vendedor_id')]) ? (int)post('vendedor_id') : null,
            // (v14) Si viene de una solicitud de demo, queda marcada como creada (y la cuenta toma su referido, v28)
            'solicitud_demo' => (int)post('solicitud_demo') ?: null, 'enviar_correo' => true,
        ], usuario()['nombre']);
        if ($logo = subir_imagen('logo', 'ligas', 500)) q('UPDATE ligas SET logo = ? WHERE id = ?', [$logo, $r['liga_id']]);
        $_SESSION['liga_creada'] = ['id' => $r['liga_id'], 'email' => mb_strtolower(post('admin_email')), 'clave' => post('admin_clave'), 'telefono' => post('telefono'), 'admin' => post('admin_nombre')];
        // (v33) Correo de bienvenida con los accesos (si el correo saliente está configurado)
        if ($r['correo_enviado']) {
            flash('Correo de bienvenida con los accesos enviado a ' . mb_strtolower(post('admin_email')) . '.');
        }
        // (v16) Combo sin administrador nuevo: el dueño entra con su mismo usuario
        if (!$r['usuario_id'] && ($cb = fila('SELECT * FROM ligas WHERE id = ?', [(int)post('combo_con')]))) {
            $_SESSION['liga_creada'] = ['id' => $r['liga_id'], 'email' => $cb['contacto_email'], 'clave' => '', 'telefono' => $cb['contacto_telefono'], 'admin' => $cb['contacto_nombre'], 'combo' => $cb['nombre']];
        }
        ir('admin.php?p=ligas&creada=' . $r['liga_id']);
    }
    if ($accion === 'suscripcion') {
        $l = fila('SELECT * FROM ligas WHERE id = ?', [(int)post('id')]) ?? throw new RuntimeException('Liga no encontrada.');
        $dom = post('dominio') !== '' ? limpiar_dominio(post('dominio')) : '';
        if (post('dominio') !== '' && $dom === '') throw new RuntimeException('El dominio no es válido. Escríbalo así: suliga.com');
        if ($dom !== '' && fila('SELECT id FROM ligas WHERE dominio = ? AND id <> ?', [$dom, $l['id']])) throw new RuntimeException('Ese dominio ya lo usa otra liga.');
        q('UPDATE ligas SET plan_id = ?, vence = ?, es_prueba = ?, dominio = ?, contacto_nombre = ?, contacto_telefono = ?, contacto_email = ?, vendedor_id = ? WHERE id = ?', [
            (int)post('plan_id') ?: null, post('vence') !== '' && strtotime(post('vence')) ? date('Y-m-d', strtotime(post('vence'))) : '', post('es_prueba') === '1' ? 1 : 0,
            $dom, mb_substr(post('contacto_nombre'), 0, 80), mb_substr(post('contacto_telefono'), 0, 30), mb_substr(post('contacto_email'), 0, 120),
            (int)post('vendedor_id') && fila('SELECT id FROM vendedores WHERE id = ?', [(int)post('vendedor_id')]) ? (int)post('vendedor_id') : null, $l['id'],
        ]);
        q('UPDATE ligas SET en_directorio = ? WHERE id = ?', [post('en_directorio') === '1' ? 1 : 0, $l['id']]);
        // (v28) Cuenta de federación
        if (($l['tipo'] ?? 'liga') !== 'escuela') q('UPDATE ligas SET es_federacion = ? WHERE id = ?', [post('es_federacion') === '1' ? 1 : 0, $l['id']]);
        if (post('es_federacion') === '1' && ($l['tipo'] ?? 'liga') !== 'escuela') q("UPDATE ligas SET federacion_id = NULL, federacion_estado = '' WHERE id = ?", [$l['id']]);
        flash('Suscripción, contacto y dominio guardados.' . ($dom !== '' && $dom !== $l['dominio'] ? ' Falta activar el dominio en el servidor (vea las instrucciones de abajo).' : ''));
        ir('admin.php?p=ligas&editar=' . (int)$l['id']);
    }
    if ($accion === 'combo') {
        // (v16) Enlazar con la otra cuenta del mismo dueño (liga ↔ escuela)
        $l = fila('SELECT * FROM ligas WHERE id = ?', [(int)post('id')]) ?? throw new RuntimeException('Cuenta no encontrada.');
        enlazar_combo((int)$l['id'], (int)post('combo_id'));
        bitacora_evento('ventas', (int)post('combo_id') ? 'Enlazó en combo liga + escuela: ' . $l['nombre'] . ' con la cuenta N° ' . (int)post('combo_id') : 'Quitó el combo de ' . $l['nombre'], (int)$l['id']);
        flash((int)post('combo_id') ? 'Combo guardado: las dos cuentas tienen ' . (float)combo_descuento() . '% de descuento.' : 'Combo quitado.');
        ir('admin.php?p=ligas&editar=' . (int)$l['id'] . '#combo');
    }
    // (v35) Módulos deportivos de la cuenta
    if ($accion === 'deportes') {
        $l = fila('SELECT * FROM ligas WHERE id = ?', [(int)post('id')]) ?? throw new RuntimeException('Cuenta no encontrada.');
        $ds = leer_deportes_post();
        if (!$ds) throw new RuntimeException('Deje al menos un módulo deportivo.');
        $nuevo = count($ds) === count(modulos_deporte_base()) ? '*' : implode(',', $ds);
        q('UPDATE ligas SET deportes = ? WHERE id = ?', [$nuevo, $l['id']]);
        bitacora_evento('ventas', 'Módulos deportivos de ' . $l['nombre'] . ': ' . texto_modulos($ds), (int)$l['id']);
        flash('Módulos deportivos guardados: ' . texto_modulos($ds) . '.');
        ir('admin.php?p=ligas&editar=' . (int)$l['id'] . '#deportes');
    }
    if ($accion === 'limites') {
        $l = fila('SELECT * FROM ligas WHERE id = ?', [(int)post('id')]) ?? throw new RuntimeException('Liga no encontrada.');
        $num = fn(string $k) => post($k) === '' ? null : entero(post($k), 0, 9999);
        q('UPDATE ligas SET max_campeonatos = ?, max_equipos = ?, modo_campeonatos = ?, modulos_extra = ? WHERE id = ?', [
            $num('max_campeonatos'), $num('max_equipos'), post('modo_campeonatos') === 'total' ? 'total' : '',
            implode(',', array_intersect(array_keys(modulos_sistema()), (array)($_POST['modulos_extra'] ?? []))), $l['id'],
        ]);
        flash('Límites de la liga guardados.');
        ir('admin.php?p=ligas&editar=' . (int)$l['id'] . '#limites');
    }
    if (in_array($accion, ['admin_crear', 'admin_clave', 'admin_activo', 'admin_eliminar', 'admin_perfil'], true)) {
        $l = fila('SELECT * FROM ligas WHERE id = ?', [(int)post('id')]) ?? throw new RuntimeException('Liga no encontrada.');
        if ($accion === 'admin_crear') {
            crear_usuario_liga((int)$l['id'], post('nombre'), post('email'), post('clave'), post('perfil', 'admin'));
            flash('Usuario creado para ' . $l['nombre'] . '. Entréguele su correo y contraseña.');
        } else {
            $uid = (int)post('usuario_id');
            $ul = fila("SELECT * FROM usuarios WHERE id = ? AND liga_id = ? AND rol = 'liga'", [$uid, $l['id']]) ?? throw new RuntimeException('Usuario no encontrado en esa liga.');
            if ($accion === 'admin_clave') {
                if (strlen(post('clave')) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
                q('UPDATE usuarios SET clave = ? WHERE id = ?', [password_hash(post('clave'), PASSWORD_DEFAULT), $uid]);
                bitacora_evento('usuarios', 'Cambió la contraseña del usuario ' . $ul['email'], (int)$l['id'], $uid);
                flash('Contraseña cambiada.');
            } elseif ($accion === 'admin_activo') {
                q('UPDATE usuarios SET activo = 1 - activo WHERE id = ?', [$uid]);
                flash('Estado del usuario cambiado.');
            } elseif ($accion === 'admin_perfil') {
                q('UPDATE usuarios SET perfil = ? WHERE id = ?', [isset(perfiles()[post('perfil')]) ? post('perfil') : 'admin', $uid]);
                flash('Perfil cambiado.');
            } else {
                q('DELETE FROM usuarios WHERE id = ?', [$uid]);
                flash('Usuario eliminado.');
            }
        }
        ir('admin.php?p=ligas&editar=' . (int)$l['id'] . '#administradores');
    }
    $id = get('editar') !== '' ? (int)get('editar') : null;
    guardar_liga($id);
    flash('Liga actualizada.');
    ir('admin.php?p=ligas' . ($id ? '&editar=' . $id : ''));
}

$editar = get('editar') !== '' ? fila('SELECT * FROM ligas WHERE id = ?', [(int)get('editar')]) : null;
if ($editar && liga_eliminada($editar)) { // (Anexo A) en la papelera: solo se puede restaurar
    flash('«' . $editar['nombre'] . '» está en la papelera. Restáurela para poder editarla.', 'error');
    ir('admin.php?p=ligas&papelera=1');
}
$nueva = get('nueva') === '1';
$planes = filas('SELECT * FROM planes ORDER BY orden, precio_mensual');

cabecera_admin('Ligas y escuelas');
// (v27) El superadministrador debe recibir por Telegram los pedidos de demo y los tickets
if (($ea = estado_avisos_super()) && !$ea['ok']) echo '<div class="aviso aviso-error">⚠️ <b>Los avisos por Telegram no le están llegando</b> (pedidos de demo, tickets): ' . e(implode(' ', $ea['problemas'])) . ' <a href="admin.php?p=soporte#avisos">Ver cómo arreglarlo</a></div>';
if ($tk = tickets_pendientes_super()) echo '<div class="aviso aviso-info">🎫 Tiene <b>' . $tk . '</b> ticket(s) de soporte sin leer. <a href="admin.php?p=soporte">Responder</a></div>';

if (get('creada') !== '' && ($lc = fila('SELECT * FROM ligas WHERE id = ?', [(int)get('creada')]))):
    $dc = $_SESSION['liga_creada'] ?? [];
    unset($_SESSION['liga_creada']);
    $wa = telefono_wa((string)($dc['telefono'] ?? ''));
    $es_esc = ($lc['tipo'] ?? 'liga') === 'escuela';
    $msg = mensaje_accesos_cuenta($lc, (string)($dc['admin'] ?? ''), (string)($dc['email'] ?? ''), (string)($dc['clave'] ?? ''), (string)($dc['combo'] ?? '')); // (Tukán Central v2) el mismo texto que manda el panel central
?>
  <div class="tarjeta" style="border:3px solid var(--acento)">
    <h1 style="margin-top:0">✅ <?= $es_esc ? 'Escuela creada' : 'Liga creada' ?>: <?= e($lc['nombre']) ?></h1>
    <p>Página pública: <a href="<?= e(url_liga($lc)) ?>" target="_blank"><?= e(url_liga($lc)) ?></a><br>
       Administrador: <b><?= e($dc['email'] ?? '') ?></b> · <?= e(estado_suscripcion($lc)['texto']) ?></p>
    <div class="acciones">
      <?php if ($wa): ?><a class="btn" target="_blank" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode($msg) ?>">📲 Enviar accesos por WhatsApp</a><?php endif; ?>
      <a class="btn btn-sec" href="admin.php?p=ligas&amp;entrar=<?= (int)$lc['id'] ?>"><?= $es_esc ? 'Entrar al panel de la escuela' : 'Administrar esta liga' ?></a>
      <a class="btn btn-sec" href="admin.php?p=ligas&amp;editar=<?= (int)$lc['id'] ?>">Editar</a>
    </div>
    <details style="margin-top:10px"><summary>Ver el mensaje con los accesos</summary><pre class="mensaje"><?= e($msg) ?></pre></details>
  </div>
<?php endif;

if ($nueva): ?>
  <p><a href="admin.php?p=ligas">← Volver a <?= es_academia() ? 'las academias' : 'ligas y escuelas' ?></a></p>
  <h1>➕ <?= es_academia() ? 'Nueva academia' : (get('tipo') === 'escuela' ? 'Nueva escuela deportiva' : 'Nueva liga o escuela') ?></h1>
  <p class="suave"><?= es_academia() ? 'Llene este formulario y la academia queda funcionando con su administrador y su página pública.' : 'Llene este formulario y la liga queda funcionando con su administrador, su página pública y (si quiere) su primer campeonato.' ?> No hay que copiar archivos ni tocar el servidor.</p>
  <form method="post" enctype="multipart/form-data" action="admin.php?p=ligas&amp;nueva=1">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="crear">
    <fieldset><legend>1. Datos de la <?= es_academia() ? 'academia' : 'liga' ?></legend>
      <div class="campos">
        <div><label><?= es_academia() ? 'Nombre de la academia *' : 'Nombre de la liga o escuela *' ?></label><input type="text" name="nombre" required maxlength="120"></div>
        <div><label>Ciudad / sector</label><input type="text" name="ciudad" maxlength="80"></div>
        <?php if (es_academia()): // (TuAcademia) toda cuenta es academia ?>
        <input type="hidden" name="tipo" value="escuela"><input type="hidden" name="modalidad" value="F7">
        <div><label>Disciplina principal</label><select name="deporte_escuela"><?= opciones_disciplinas(post('deporte_escuela', 'ballet')) ?></select><div class="ayuda">Después, en Cursos y horarios, cada curso puede ser de otra disciplina.</div></div>
        <?php else: ?>
        <div><label>Tipo de cuenta</label><select name="tipo" onchange="document.querySelectorAll('.solo-escuela').forEach(function (x) { x.style.display = this.value === 'escuela' ? '' : 'none'; }, this); document.querySelectorAll('.solo-liga').forEach(function (x) { x.style.display = this.value === 'escuela' ? 'none' : ''; }, this)">
          <option value="liga" >🏆 Liga (campeonatos)</option><option value="escuela" <?= get('tipo') === 'escuela' ? 'selected' : '' ?>>🎓 Escuela deportiva (alumnos)</option></select>
          <?php if (get('tipo') === 'escuela'): ?><script>(function (s) { document.addEventListener('DOMContentLoaded', function () { s.dispatchEvent(new Event('change')); }); })(document.currentScript.previousElementSibling);</script><?php endif; ?></div>
        <div class="solo-escuela" style="display:none"><label>Deporte principal de la escuela</label><select name="deporte_escuela"><?php foreach (deportes_escuela() as $k => $v): ?><option value="<?= $k ?>" <?= post('deporte_escuela') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select><div class="ayuda">¿Multideporte? Elija el principal; después, dentro de la escuela, en Grupos y horarios cada grupo puede ser de otro deporte (natación, tenis, boxeo, karate…).</div></div>

        <div><label>Dirección corta</label><input type="text" name="slug" maxlength="60" placeholder="se crea sola"><div class="ayuda">Ej: liga-norte → <?= e(url_base()) ?>liga-norte</div></div>
        <div><label>Modalidad</label><select name="modalidad"><?= opciones_modalidad('F7') ?></select></div>
        <?php endif; ?>
        <div><label>Color</label><input type="color" name="color" value="#0b6e4f"></div>
        <div><label>Logo</label><input type="file" name="logo" accept="image/*"></div>
      </div>
    </fieldset>
    <fieldset><legend>🧩 <?= es_academia() ? 'Disciplinas' : 'Módulos deportivos' ?> que tendrá la cuenta</legend>
      <p class="suave" style="margin-top:0"><?= es_academia() ? 'Elija las disciplinas que compró (o que quiere probar en la demo).' : 'Elija los deportes que compró (o que quiere probar en la demo).' ?> El plan incluye uno y cada módulo adicional suma su precio con <?= e(rtrim(rtrim(number_format(modulo_descuento(), 1, ',', ''), '0'), ',')) ?> % de descuento. Se pueden cambiar después.</p>
      <?= html_casillas_modulos([es_academia() ? 'baile' : 'futbol'], 'deportes', true) ?>
    </fieldset>
    <fieldset><legend>2. Administrador de la <?= es_academia() ? 'academia' : 'liga' ?></legend>
      <?php $cuentas_combo = filas("SELECT id, nombre, tipo FROM ligas WHERE es_demo = 0 AND combo_id IS NULL ORDER BY nombre"); if ($cuentas_combo): ?>
      <div class="campos"><div class="campo-ancho"><label>🤝 Combo liga + escuela (mismo dueño, <?= e((string)(float)combo_descuento()) ?>% de descuento en los dos planes)</label>
        <select name="combo_con" id="combo-con"><option value="">— No, es un cliente nuevo —</option><?php foreach ($cuentas_combo as $cc): ?><option value="<?= (int)$cc['id'] ?>" data-tipo="<?= e($cc['tipo'] ?? 'liga') ?>"><?= ($cc['tipo'] ?? 'liga') === 'escuela' ? '🎓 ' : '🏆 ' ?><?= e($cc['nombre']) ?></option><?php endforeach; ?></select>
        <div class="ayuda">Elija la otra cuenta del mismo dueño (una liga para una escuela nueva, o una escuela para una liga nueva). Si deja vacíos los datos del administrador, el dueño entra con su <b>mismo usuario</b> y cambia de una a otra con "🔁 Ir a…".</div></div></div>
      <script>
      (function () { var c = document.getElementById('combo-con'); c.addEventListener('change', function () {
        var f = c.form; ['admin_nombre', 'admin_email', 'admin_clave'].forEach(function (n) { f.elements[n].required = !c.value; if (c.value && n !== 'admin_clave') f.elements[n].placeholder = 'vacío = mismo usuario de la otra cuenta'; else f.elements[n].placeholder = ''; });
        if (c.value) f.elements.admin_clave.value = '';
        var t = c.options[c.selectedIndex].dataset.tipo; if (t) { f.elements.tipo.value = t === 'escuela' ? 'liga' : 'escuela'; f.elements.tipo.dispatchEvent(new Event('change')); }
      }); })();
      </script>
      <?php endif; ?>
      <div class="campos">
        <div><label>Nombre *</label><input type="text" name="admin_nombre" required maxlength="80"></div>
        <div><label>Correo (usuario) *</label><input type="email" name="admin_email" required></div>
        <div><label>Contraseña inicial (mín. 8) *</label><input type="text" name="admin_clave" required minlength="8" value="<?= e(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10)) ?>"></div>
        <div><label>Celular / WhatsApp</label><input type="tel" name="telefono" maxlength="30"><div class="ayuda">Para enviarle sus accesos por WhatsApp.</div></div>
      </div>
    </fieldset>
    <fieldset><legend>3. Plan y vigencia</legend>
      <div class="campos">
        <div><label>Plan</label><select name="plan_id"><option value="">Sin plan (sin límites)</option><?php foreach ($planes as $pl): if (!$pl['activo']) continue; ?><option value="<?= (int)$pl['id'] ?>"><?= e($pl['nombre']) ?> — <?= e(texto_precio_principal($pl)) ?><?= ['liga' => ' (ligas)', 'escuela' => ' (escuelas)'][$pl['tipo_cuenta']] ?? '' ?></option><?php endforeach; ?></select></div>
        <div><label>Vigencia</label>
          <select name="vigencia" onchange="document.getElementById('vg-p').style.display=this.value==='prueba'?'':'none';document.getElementById('vg-f').style.display=this.value==='pagada'?'':'none'">
            <option value="prueba">Demo (por días)</option><option value="pagada">Pagada hasta una fecha</option><option value="sin">Sin vencimiento (cortesía / propia)</option></select></div>
        <div id="vg-p"><label>Días de la demo</label><input type="number" name="dias_prueba" min="1" max="365" value="<?= (int)ajuste('dias_demo', 7) ?>"></div>
        <?php $vends = filas('SELECT id, nombre, codigo FROM vendedores WHERE activo = 1 ORDER BY nombre'); if ($vends): ?><div><label>Vendedor (comisión)</label><select name="vendedor_id"><option value="">— venta directa —</option><?php foreach ($vends as $vd): ?><option value="<?= (int)$vd['id'] ?>"><?= e($vd['nombre'] . ' (' . $vd['codigo'] . ')') ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div id="vg-f" style="display:none"><label>Pagada hasta</label><input type="date" name="vence" value="<?= date('Y-m-d', strtotime('+1 month')) ?>"></div>
      </div>
      <?php if (!es_academia()): ?><label class="check"><input type="checkbox" name="crear_campeonato" value="1" checked> Crear también su primer campeonato (<?= date('Y') ?>)</label><?php endif; ?>
    </fieldset>
    <fieldset><legend>4. Límites de esta <?= es_academia() ? 'academia' : 'liga' ?> (opcional)</legend>
      <p class="suave" style="margin-top:0">Déjelos vacíos para usar los del plan. Escriba <b>0</b> para ilimitado.</p>
      <?php html_limites_liga([]); ?>
    </fieldset>
    <button class="btn btn-grande" type="submit"><?= es_academia() ? 'Crear academia' : 'Crear liga' ?></button>
  
    <?php if (get('demo') !== '' && ($sd = fila('SELECT * FROM solicitudes_demo WHERE id = ?', [(int)get('demo')]))): // (v14) datos de la solicitud de demo ?>
    <input type="hidden" name="solicitud_demo" value="<?= (int)$sd['id'] ?>">
    <script>
    (function () {
      var f = document.currentScript.closest('form'), d = <?= json_encode(['nombre' => $sd['organizacion'], 'ciudad' => $sd['ciudad'], 'tipo' => $sd['tipo'], 'admin_nombre' => $sd['contacto'], 'admin_email' => $sd['email'], 'telefono' => $sd['telefono'], 'plan_id' => (string)($sd['plan_id'] ?? ''), 'vendedor_id' => (string)($sd['vendedor_id'] ?? ''), 'vigencia' => 'prueba', 'dias_prueba' => (string)(int)ajuste('dias_demo', 7)], JSON_UNESCAPED_UNICODE) ?>;
      Object.keys(d).forEach(function (k) { var el = f.elements[k]; if (el && d[k] !== '') { el.value = d[k]; el.dispatchEvent(new Event('change')); } });
      var dep = <?= json_encode(array_values(array_filter(explode(',', (string)($sd['deportes'] ?? ''))))) ?>; // (v35) deportes que pidió
      if (dep.length) f.querySelectorAll('input[name="deportes[]"]').forEach(function (c) { c.checked = dep.indexOf(c.value) >= 0; });
    })();
    </script>
    <div class="aviso aviso-info">Datos tomados de la solicitud de demo de <b><?= e($sd['contacto']) ?></b>. Revise el correo (será su usuario) y los días de la demo.</div>
    <?php endif; ?>
  </form>

<?php elseif ($editar):
    $sus = estado_suscripcion($editar);
?>
  <p><a href="admin.php?p=ligas">← Volver a ligas</a></p>
  <h1>Editar liga: <?= e($editar['nombre']) ?></h1>
  <div class="tarjeta">
    <h2 style="margin-top:0">💳 Suscripción, contacto y dominio propio</h2>
    <p>Estado: <b><?= e($sus['texto']) ?></b> · <a href="admin.php?p=suscripciones&amp;liga=<?= (int)$editar['id'] ?>">Registrar pago / renovar</a></p>
    <form method="post" action="admin.php?p=ligas&amp;editar=<?= (int)$editar['id'] ?>">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="suscripcion"><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>">
      <div class="campos">
        <div><label>Plan</label><select name="plan_id"><option value="">Sin plan (sin límites)</option><?php foreach ($planes as $pl): ?><option value="<?= (int)$pl['id'] ?>" <?= (int)$editar['plan_id'] === (int)$pl['id'] ? 'selected' : '' ?>><?= e($pl['nombre']) ?><?= ['liga' => ' (ligas)', 'escuela' => ' (escuelas)'][$pl['tipo_cuenta']] ?? '' ?><?= $pl['activo'] ? '' : ' — ya no se ofrece' ?></option><?php endforeach; ?></select></div>
        <div><label>Vence el</label><input type="date" name="vence" value="<?= e($editar['vence']) ?>"><div class="ayuda">Vacío = sin vencimiento.</div></div>
        <div><label class="check" style="margin-top:28px"><input type="checkbox" name="es_prueba" value="1" <?= $editar['es_prueba'] ? 'checked' : '' ?>> Está en demo</label></div>
        <div><label class="check" style="margin-top:28px"><input type="checkbox" name="en_directorio" value="1" <?= ($editar['en_directorio'] ?? 1) ? 'checked' : '' ?>> Mostrar en el directorio público</label><div class="ayuda">Sale en el menú <?= es_escuela($editar) ? '🎓 Escuelas' : '🏆 Ligas' ?> de la página principal.</div></div>
        <?php if (($editar['tipo'] ?? 'liga') !== 'escuela'): ?><div><label class="check" style="margin-top:28px"><input type="checkbox" name="es_federacion" value="1" <?= !empty($editar['es_federacion']) ? 'checked' : '' ?>> 🏛 Es una FEDERACIÓN</label><div class="ayuda">Agrupa varias ligas y escuelas (cada una con sus torneos) y organiza campeonatos federativos.</div></div><?php endif; ?>
        <div><label>Contacto</label><input type="text" name="contacto_nombre" maxlength="80" value="<?= e($editar['contacto_nombre']) ?>"></div>
        <div><label>Teléfono</label><input type="text" name="contacto_telefono" maxlength="30" value="<?= e($editar['contacto_telefono']) ?>"></div>
        <div><label>Correo</label><input type="email" name="contacto_email" value="<?= e($editar['contacto_email']) ?>"></div>
        <div><label>Vendedor que trajo la liga</label><select name="vendedor_id"><option value="">— ninguno (venta directa) —</option><?php foreach (filas('SELECT id, nombre, codigo FROM vendedores ORDER BY activo DESC, nombre') as $vd): ?><option value="<?= (int)$vd['id'] ?>" <?= (int)$editar['vendedor_id'] === (int)$vd['id'] ? 'selected' : '' ?>><?= e($vd['nombre'] . ' (' . $vd['codigo'] . ')') ?></option><?php endforeach; ?></select><div class="ayuda">Gana comisión por los pagos de esta liga.</div></div>
        <div class="campo-ancho"><label>Dominio propio (opcional)</label><input type="text" name="dominio" maxlength="120" value="<?= e($editar['dominio']) ?>" placeholder="suliga.com"><div class="ayuda">Si la liga tiene su propio dominio, su página pública se abre en él directamente.</div></div>
      </div>
      <button class="btn btn-sec">Guardar</button>
    </form>
    <?php if ($editar['dominio'] !== ''): ?>
      <div class="aviso aviso-info" style="margin-top:12px"><b>Para activar el dominio <?= e($editar['dominio']) ?>:</b><br>
        1. El dominio debe estar agregado en su cuenta de <b>Cloudflare</b> (los DNS del dominio apuntando a Cloudflare).<br>
        2. En PuTTY, conectado al servidor, escriba (una sola línea):<br><code>sudo bash /var/www/tuacademia/app/dominio.sh <?= e($editar['dominio']) ?></code><br>
        3. Si el instalador le indica un paso en el navegador, hágalo. Luego abra https://<?= e($editar['dominio']) ?></div>
    <?php endif; ?>
    <p class="suave" style="margin-bottom:0">Dirección corta: <a href="<?= e(url_base() . $editar['slug']) ?>" target="_blank"><?= e(url_base() . $editar['slug']) ?></a></p>
  </div>
  <?php // (v16) Combo liga + escuela
  $cb_act = combo_de($editar); $es_e = ($editar['tipo'] ?? 'liga') === 'escuela';
  $otras = filas("SELECT id, nombre FROM ligas WHERE id <> ? AND es_demo = 0 AND COALESCE(tipo, 'liga') " . ($es_e ? "<> 'escuela'" : "= 'escuela'") . " AND (combo_id IS NULL OR combo_id = ?) ORDER BY nombre", [$editar['id'], $editar['id']]); ?>
  <div class="tarjeta combo-caja" id="combo">
    <h2 style="margin-top:0">🤝 Combo liga + escuela (<?= e((string)(float)combo_descuento()) ?>% de descuento en los dos planes)</h2>
    <p class="suave" style="margin-top:0">Para el mismo dueño que tiene una liga y una escuela: los dos planes bajan <?= e((string)(float)combo_descuento()) ?>% y los administradores de esta cuenta pueden pasar a la otra con su mismo usuario ("🔁 Ir a…").</p>
    <?php if ($cb_act): ?><p>En combo con <b><?= e($cb_act['nombre']) ?></b> (<?= ($cb_act['tipo'] ?? 'liga') === 'escuela' ? 'escuela' : 'liga' ?>) · <a href="admin.php?p=ligas&amp;editar=<?= (int)$cb_act['id'] ?>">ver</a></p><?php endif; ?>
    <form method="post" action="admin.php?p=ligas&amp;editar=<?= (int)$editar['id'] ?>" class="acciones"><?= csrf_campo() ?><input type="hidden" name="accion" value="combo"><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>">
      <select name="combo_id"><option value="0">— sin combo —</option><?php foreach ($otras as $o): ?><option value="<?= (int)$o['id'] ?>" <?= $cb_act && (int)$cb_act['id'] === (int)$o['id'] ? 'selected' : '' ?>><?= $es_e ? '🏆 ' : '🎓 ' ?><?= e($o['nombre']) ?></option><?php endforeach; ?></select>
      <button class="btn btn-sec">Guardar combo</button></form>
  </div>
  <div class="tarjeta" id="deportes">
    <h2 style="margin-top:0">🧩 Módulos deportivos</h2>
    <p>Ahora: <b><?= e(texto_modulos(deportes_liga($editar))) ?></b><?= !empty($editar['es_demo']) ? ' <span class="etiqueta">demo del sistema: siempre todos</span>' : '' ?> · Precio de los módulos adicionales: <b><?= dinero(precio_modulos(deportes_liga($editar))['extra']) ?>/mes</b></p>
    <form method="post" action="admin.php?p=ligas&amp;editar=<?= (int)$editar['id'] ?>"><?= csrf_campo() ?><input type="hidden" name="accion" value="deportes"><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>">
      <?= html_casillas_modulos(deportes_liga($editar), 'deportes', true) ?>
      <p class="ayuda">Al quitar un módulo, sus campeonatos y grupos no se borran: quedan para ver, pero no se pueden cambiar hasta que lo vuelva a agregar.</p>
      <button class="btn btn-sec">Guardar módulos</button></form>
  </div>
  <?php $lim = limites_liga($editar); ?>
  <div class="tarjeta" id="limites">
    <h2 style="margin-top:0">🔢 Límites: campeonatos y equipos</h2>
    <p>Ahora: <b><?= e(texto_limite_campeonatos($editar)) ?></b> · Equipos: <b><?= $lim['usados_equipos'] ?></b> de <b><?= $lim['equipos'] ?: '∞' ?></b>
      <small class="suave">(<?= $lim['origen_campeonatos'] === 'liga' ? 'límite propio de la liga' : ($lim['origen_campeonatos'] === 'plan' ? 'según el plan ' . e($lim['plan']['nombre']) : 'sin plan') ?>)</small></p>
    <form method="post" action="admin.php?p=ligas&amp;editar=<?= (int)$editar['id'] ?>">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="limites"><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>">
      <?php html_limites_liga($editar); ?>
      <button class="btn btn-sec">Guardar límites</button>
    </form>
  </div>
  <?php $admins = filas("SELECT * FROM usuarios WHERE liga_id = ? AND rol = 'liga' ORDER BY (perfil <> 'admin'), nombre", [$editar['id']]); ?>
  <div class="tarjeta" id="administradores">
    <h2 style="margin-top:0">👥 Administradores y usuarios de esta liga (<?= count($admins) ?>)</h2>
    <p class="suave" style="margin-top:0">El perfil <b>Administrador</b> puede todo en su liga y puede crear a los demás usuarios (secretario, tesorero, etc.) desde su propio menú <b>Usuarios</b>. Los árbitros y delegados se crean en Árbitros y en cada Equipo.</p>
    <?php if ($admins): ?>
    <div class="tabla-scroll"><table>
      <tr><th>Nombre</th><th>Correo</th><th>Perfil</th><th>Estado</th><th>Acciones</th></tr>
      <?php foreach ($admins as $ua): ?>
      <tr><td><?= e($ua['nombre']) ?></td><td><?= e($ua['email']) ?></td>
        <td><form class="inline" method="post" action="admin.php?p=ligas&amp;editar=<?= (int)$editar['id'] ?>"><?= csrf_campo() ?><input type="hidden" name="accion" value="admin_perfil"><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><input type="hidden" name="usuario_id" value="<?= (int)$ua['id'] ?>">
          <select name="perfil" onchange="this.form.submit()"><?php foreach (perfiles() as $k => [$pn]): ?><option value="<?= $k ?>" <?= $ua['perfil'] === $k ? 'selected' : '' ?>><?= e($pn) ?></option><?php endforeach; ?></select></form></td>
        <td><?= $ua['activo'] ? 'Activo' : '<span class="etiqueta gris">Inactivo</span>' ?><?= $ua['ultimo_ingreso'] ? '<br><small>' . e($ua['ultimo_ingreso']) . '</small>' : '' ?></td>
        <td class="acciones">
          <form class="inline" method="post" action="admin.php?p=ligas&amp;editar=<?= (int)$editar['id'] ?>" onsubmit="var c=prompt('Nueva contraseña (mínimo 8 caracteres):'); if(!c) return false; this.clave.value=c; return true;"><?= csrf_campo() ?><input type="hidden" name="accion" value="admin_clave"><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><input type="hidden" name="usuario_id" value="<?= (int)$ua['id'] ?>"><input type="hidden" name="clave" value=""><button class="btn btn-sec btn-chico">Cambiar clave</button></form>
          <form class="inline" method="post" action="admin.php?p=ligas&amp;editar=<?= (int)$editar['id'] ?>"><?= csrf_campo() ?><input type="hidden" name="accion" value="admin_activo"><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><input type="hidden" name="usuario_id" value="<?= (int)$ua['id'] ?>"><button class="btn btn-sec btn-chico"><?= $ua['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
          <form class="inline" method="post" action="admin.php?p=ligas&amp;editar=<?= (int)$editar['id'] ?>" onsubmit="return confirm('¿Eliminar este usuario?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="admin_eliminar"><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><input type="hidden" name="usuario_id" value="<?= (int)$ua['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar</button></form>
        </td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
    <h3>+ Agregar usuario a esta liga</h3>
    <form method="post" action="admin.php?p=ligas&amp;editar=<?= (int)$editar['id'] ?>">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="admin_crear"><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>">
      <div class="campos">
        <div><label>Nombre *</label><input type="text" name="nombre" required maxlength="80"></div>
        <div><label>Correo (usuario) *</label><input type="email" name="email" required></div>
        <div><label>Contraseña (mín. 8) *</label><input type="text" name="clave" required minlength="8" value="<?= e(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10)) ?>"></div>
        <div><label>Perfil</label><select name="perfil"><?php foreach (perfiles() as $k => [$pn, $pd]): ?><option value="<?= $k ?>" title="<?= e($pd) ?>"><?= e($pn) ?></option><?php endforeach; ?></select></div>
      </div>
      <button class="btn btn-sec">Crear usuario</button>
    </form>
  </div>
  <?php formulario_liga($editar, 'admin.php?p=ligas&editar=' . (int)$editar['id']); ?>
  <div class="tarjeta" style="margin-top:24px">
    <h2>Eliminar liga</h2>
    <p class="suave">Se borrarán TODOS sus equipos, jugadores, campeonatos y resultados. No se puede deshacer.</p>
    <form method="post" action="admin.php?p=ligas" onsubmit="return confirm('¿Seguro? Esto borra todo.')">
      <?= csrf_campo() ?>
      <input type="hidden" name="accion" value="eliminar">
      <input type="hidden" name="id" value="<?= (int)$editar['id'] ?>">
      <p><label>Escriba el nombre exacto: <em><?= e($editar['nombre']) ?></em></label><input type="text" name="confirmar" required></p>
      <button class="btn btn-peligro" type="submit">Eliminar definitivamente</button>
    </form>
  </div>
<?php else:
    // (v14.2) Ligas y escuelas en la misma lista, con filtro por tipo
    $filtro = in_array(get('tipo'), ['liga', 'escuela'], true) ? get('tipo') : '';
    $ver_papelera = get('papelera') === '1'; // (Anexo A) filtro Papelera: cuentas borradas desde el Panel Tukán (30 días para restaurarlas)
    $papelera = filas("SELECT * FROM ligas WHERE COALESCE(eliminada, '') <> '' ORDER BY eliminada DESC");
    $todas = filas('SELECT l.*, p.nombre AS plan, (SELECT COUNT(*) FROM equipos e WHERE e.liga_id = l.id) AS n_equipos,
                           (SELECT COUNT(*) FROM usuarios u WHERE u.liga_id = l.id) AS n_usuarios,
                           (SELECT COUNT(*) FROM esc_alumnos a WHERE a.liga_id = l.id AND a.estado = \'activo\') AS n_alumnos,
                           (SELECT COUNT(*) FROM esc_grupos g WHERE g.liga_id = l.id AND g.activo = 1) AS n_grupos,
                           (SELECT COUNT(*) FROM esc_profesores pr WHERE pr.liga_id = l.id AND pr.activo = 1) AS n_profes
                    FROM ligas l LEFT JOIN planes p ON p.id = l.plan_id WHERE COALESCE(l.eliminada, \'\') = \'\' ORDER BY l.nombre');
    $n_esc = count(array_filter($todas, fn($x) => ($x['tipo'] ?? 'liga') === 'escuela'));
    $ligas = $filtro === '' ? $todas : array_values(array_filter($todas, fn($x) => (($x['tipo'] ?? 'liga') === 'escuela') === ($filtro === 'escuela')));
    $chip = ['sin_limite' => 'chip-ok', 'activa' => 'chip-ok', 'prueba' => 'chip-pend', 'gracia' => 'chip-mal', 'vencida' => 'chip-mal'];
?>
  <div class="fila-titulo">
    <h1>Ligas y escuelas (<?= count($todas) ?>)</h1>
    <div class="acciones"><a class="btn" href="admin.php?p=ligas&amp;nueva=1">+ Nueva liga</a><a class="btn" href="admin.php?p=ligas&amp;nueva=1&amp;tipo=escuela">+ Nueva escuela</a></div>
  </div>
  <p class="suave" style="margin-top:0">Para trabajar dentro de una liga o escuela toque <b>Administrar</b> (liga) o <b>Entrar al panel</b> (escuela). Para volver aquí, arriba: "cambiar de liga o escuela".</p>
  <nav class="pestanas">
    <a class="<?= $filtro === '' ? 'activa' : '' ?>" href="admin.php?p=ligas">Todas (<?= count($todas) ?>)</a>
    <a class="<?= $filtro === 'liga' ? 'activa' : '' ?>" href="admin.php?p=ligas&amp;tipo=liga">🏆 Ligas (<?= count($todas) - $n_esc ?>)</a>
    <a class="<?= $filtro === 'escuela' ? 'activa' : '' ?>" href="admin.php?p=ligas&amp;tipo=escuela">🎓 Escuelas (<?= $n_esc ?>)</a>
    <a class="<?= $ver_papelera ? 'activa' : '' ?>" href="admin.php?p=ligas&amp;papelera=1" id="pestana-papelera">🗑️ Papelera (<?= count($papelera) ?>)</a>
  </nav>
  <?php if ($ver_papelera): // (Anexo A) ?>
    <p class="suave">Cuentas borradas desde el Panel Tukán: nadie puede entrar, no salen en las listas y su página pública da «no encontrado». Se guardó una copia completa de cada una. Pasados 30 días se borran de verdad (la copia se guarda 1 año).</p>
    <?php if (!$papelera): ?><div class="tarjeta">La papelera está vacía.</div><?php endif; ?>
    <div class="rejilla">
    <?php foreach ($papelera as $l): ?>
      <div class="tarjeta papelera-cuenta">
        <div class="eq-celda"><strong><?= e($l['nombre']) ?></strong> <span class="etiqueta gris">🗑️ en la papelera</span></div>
        <p class="suave" style="margin:.4rem 0">Borrada el <?= e(substr($l['eliminada'], 0, 16)) ?><?= $l['eliminada_por'] !== '' ? ' por ' . e($l['eliminada_por']) : '' ?>.<br>Motivo: <?= e($l['eliminada_motivo']) ?><br>Se borra de verdad el <b><?= e($l['eliminada_hasta']) ?></b>.<br><small>Copia: <?= e($l['eliminada_copia']) ?></small></p>
        <form method="post" action="admin.php?p=ligas" onsubmit="return confirm('¿Restaurar <?= e(addslashes($l['nombre'])) ?>? Vuelve tal cual estaba.')">
          <?= csrf_campo() ?><input type="hidden" name="accion" value="restaurar"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <button class="btn btn-chico" type="submit">♻️ Restaurar</button>
        </form>
      </div>
    <?php endforeach; ?>
    </div>
  <?php else: ?>
  <?php if (!$ligas): ?>
    <div class="tarjeta"><?= $filtro === 'escuela' ? 'Aún no hay escuelas. Cree la primera con el botón <b>+ Nueva escuela</b>.' : 'Aún no hay ligas. Cree la primera con el botón <b>+ Nueva liga</b>.' ?></div>
  <?php endif; ?>
  <div class="rejilla">
    <?php foreach ($ligas as $l): $sus = estado_suscripcion($l); $es_esc = ($l['tipo'] ?? 'liga') === 'escuela'; ?>
      <div class="tarjeta">
        <div class="eq-celda"><?= escudo($l['logo'], $l['nombre'], $l['color']) ?> <strong><?= e($l['nombre']) ?></strong><?= !empty($l['es_demo']) ? ' <span class="etiqueta amarilla">DEMO</span>' : '' ?></div>
        <p style="margin:.3rem 0 0"><span class="etiqueta <?= $es_esc ? 'amarilla' : 'gris' ?>"><?= $es_esc ? '🎓 Escuela deportiva' : '🏆 Liga' ?></span><?php if ($cbl = combo_de($l)): ?> <span class="etiqueta verde">🤝 combo con <?= e($cbl['nombre']) ?></span><?php endif; ?></p>
        <?php if ($es_esc): ?>
        <p class="suave" style="margin:.4rem 0"><?= e(count($dl = deportes_de_escuela($l)) > 1 ? 'Multideporte: ' . texto_deportes($dl) : (deportes_escuela()[$l['deporte_escuela'] ?? ''] ?? 'Escuela')) ?> · <?= (int)$l['n_alumnos'] ?> alumnos · <?= (int)$l['n_grupos'] ?> grupos · <?= (int)$l['n_profes'] ?> profesores · <?= (int)$l['n_usuarios'] ?> usuarios<?= $l['activa'] ? '' : ' · <span class="etiqueta gris">inactiva</span>' ?></p>
        <?php else: ?>
        <p class="suave" style="margin:.4rem 0"><?= e(nombre_modalidad($l['modalidad'])) ?> · <?= (int)$l['n_equipos'] ?> equipos · <?= (int)$l['n_usuarios'] ?> usuarios<br><?= e(texto_limite_campeonatos($l)) ?><?= $l['activa'] ? '' : ' · <span class="etiqueta gris">inactiva</span>' ?></p>
        <?php endif; ?>
        <p style="margin:.2rem 0" title="Módulos deportivos"><small>🧩 <?= e(texto_modulos(deportes_liga($l))) ?></small></p>
        <p style="margin:.2rem 0 .6rem"><span class="estado-chip <?= $chip[$sus['estado']] ?>"><?= e(['sin_limite' => 'Sin vencimiento', 'activa' => 'Activa', 'prueba' => 'Demo', 'gracia' => 'En gracia', 'vencida' => 'Vencida'][$sus['estado']]) ?></span>
          <small><?= e($l['plan'] ?? 'sin plan') ?><?= $l['vence'] !== '' ? ' · vence ' . e($l['vence']) : '' ?></small></p>
        <div class="acciones">
          <a class="btn btn-chico" href="admin.php?p=ligas&amp;entrar=<?= (int)$l['id'] ?>"><?= $es_esc ? '🎓 Entrar al panel' : 'Administrar' ?></a>
          <a class="btn btn-sec btn-chico" href="admin.php?p=ligas&amp;editar=<?= (int)$l['id'] ?>">Editar</a>
          <a class="btn btn-sec btn-chico" href="<?= e(url_liga($l)) ?>" target="_blank">Ver pública</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; // (Anexo A) fin de «no es la papelera» ?>
<?php endif;
pie_admin();
