<?php
/* PLANES Y PRECIOS (solo superadministrador): lo que se muestra en la página de venta.
 * (v12) Cada plan: precio por mes, trimestre, semestre y año; módulos activos; torneos, equipos por torneo, equipos y alumnos. */
if (!es_super()) ir('admin.php');

$dinero_post = fn(string $k) => max(0, round((float)str_replace(',', '.', post($k)), 2));
if (es_post()) {
    $accion = post('accion');
    if ($accion === 'guardar') {
        if (mb_strlen(post('nombre')) < 2) throw new RuntimeException('Escriba el nombre del plan.');
        $mods_plan = array_values(array_diff(array_keys(modulos_sistema()), modulos_fuera_de_planes())); // (21 oct 2026) «*» = los de siempre; el Asistente IA se nombra aparte
        $mods = array_values(array_intersect(array_keys(modulos_sistema()), (array)($_POST['modulos'] ?? [])));
        $slug = slugify(post('slug') !== '' ? post('slug') : post('nombre'));
        if (fila('SELECT id FROM planes WHERE slug = ? AND id <> ?', [$slug, (int)post('id')])) $slug .= '-' . random_int(2, 99);
        $d = [
            'nombre' => mb_substr(post('nombre'), 0, 60), 'slug' => $slug, 'eslogan' => mb_substr(post('eslogan'), 0, 120),
            'precio_mensual' => $dinero_post('precio_mensual'), 'precio_trimestral' => $dinero_post('precio_trimestral'),
            'precio_semestral' => $dinero_post('precio_semestral'), 'precio_anual' => $dinero_post('precio_anual'),
            'max_campeonatos' => entero(post('max_campeonatos'), 0, 999), 'max_equipos_torneo' => entero(post('max_equipos_torneo'), 0, 9999),
            'max_equipos' => entero(post('max_equipos'), 0, 9999), 'max_alumnos' => entero(post('max_alumnos'), 0, 99999),
            'modulos' => !array_diff($mods_plan, $mods) ? '*' . implode('', array_map(fn($k) => ',' . $k, array_values(array_intersect(modulos_fuera_de_planes(), $mods)))) : (implode(',', $mods) ?: '-'), // (28 oct 2026) '-' = ninguno
            // (28 oct 2026) planes de $20 y $45: módulos que elige el cliente (incluidos) y precio de cada módulo más (vacío = el del catálogo)
            'modulos_elegir' => entero(post('modulos_elegir'), 0, 50),
            'precio_modulo_extra' => trim(post('precio_modulo_extra')) === '' ? null : $dinero_post('precio_modulo_extra'),
            'tipo_cuenta' => in_array(post('tipo_cuenta'), ['ambos', 'liga', 'escuela'], true) ? post('tipo_cuenta') : 'ambos',
            'descripcion' => mb_substr(post('descripcion'), 0, 2000),
            'destacado' => post('destacado') === '1' ? 1 : 0, 'dominio_anual' => post('dominio_anual') === '1' ? 1 : 0, 'activo' => post('activo') === '1' ? 1 : 0, 'orden' => entero(post('orden'), 0, 99),
        ];
        if (!array_filter([$d['precio_mensual'], $d['precio_trimestral'], $d['precio_semestral'], $d['precio_anual']])) throw new RuntimeException('Ponga al menos un precio (mensual, trimestral, semestral o anual).');
        // (v16) límites coherentes: si la liga tiene tope de equipos, un torneo no puede tener más
        if ($d['max_equipos'] && $d['max_equipos_torneo'] > $d['max_equipos']) throw new RuntimeException('Los equipos por torneo no pueden ser más que los equipos de la liga. Deje los dos en 0 para "ilimitado".');
        if ((int)post('id')) {
            q('UPDATE planes SET ' . implode(', ', array_map(fn($k) => "$k = :$k", array_keys($d))) . ' WHERE id = :id', $d + ['id' => (int)post('id')]);
        } else {
            q('INSERT INTO planes (' . implode(', ', array_keys($d)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($d))) . ')', $d);
        }
        flash('Plan guardado. Los cambios de módulos y límites se aplican de inmediato a las ligas y escuelas con este plan.');
    } elseif ($accion === 'eliminar') {
        if ((int)valor('SELECT COUNT(*) FROM ligas WHERE plan_id = ?', [(int)post('id')]) > 0) throw new RuntimeException('Hay ligas con este plan. Desactívelo en vez de eliminarlo (así ya no aparece en la página de venta).');
        q('DELETE FROM planes WHERE id = ?', [(int)post('id')]);
        flash('Plan eliminado.');
    }
    ir('admin.php?p=planes');
}
$planes = filas('SELECT p.*, (SELECT COUNT(*) FROM ligas l WHERE l.plan_id = p.id) AS n_ligas FROM planes p ORDER BY orden, precio_mensual');
$editar = get('editar') !== '' ? fila('SELECT * FROM planes WHERE id = ?', [(int)get('editar')]) : null;
$v = $editar ?? ['id' => 0, 'nombre' => '', 'slug' => '', 'eslogan' => '', 'precio_mensual' => 0, 'precio_trimestral' => 0, 'precio_semestral' => 0, 'precio_anual' => 0,
    'max_equipos' => 0, 'max_campeonatos' => 0, 'max_equipos_torneo' => 0, 'max_alumnos' => 0, 'modulos' => '*', 'tipo_cuenta' => 'ambos', 'modulos_elegir' => 0, 'precio_modulo_extra' => null,
    'descripcion' => '', 'destacado' => 0, 'dominio_anual' => 0, 'activo' => 1, 'orden' => count($planes) + 1];
$mods_v = modulos_plan($v);
$para = ['ambos' => 'Ligas y escuelas', 'liga' => 'Solo ligas', 'escuela' => 'Solo escuelas'];
cabecera_admin('Planes');
?>
<p><a href="admin.php?p=suscripciones">← Ventas</a></p>
<div class="fila-titulo"><h1>Planes y precios</h1><div class="acciones"><a class="btn btn-sec btn-chico" target="_blank" href="index.php#planes">Ver en la página de venta ↗</a> <a class="btn btn-sec btn-chico" target="_blank" href="comparar">Tabla comparativa ↗</a></div></div>
<p class="suave">Los límites y módulos se aplican solos a cada liga o escuela según su plan (en la demo tienen todo). 0 = sin límite. Un precio en 0 = ese período no se ofrece. En Ligas → Editar se encienden o apagan los módulos de cada cliente.</p>
<div class="tabla-scroll"><table>
  <tr><th>Plan</th><th class="num">Mes</th><th class="num">Trimestre</th><th class="num">Semestre</th><th class="num">Año</th><th>Límites</th><th class="num">Módulos</th><th class="num">Ligas</th><th>Estado</th><th></th></tr>
  <?php foreach ($planes as $pl): $o = opciones_precio($pl); ?>
    <tr><td><b><?= e($pl['nombre']) ?></b><?= $pl['destacado'] ? ' <span class="etiqueta verde">recomendado</span>' : '' ?><br><small class="suave"><?= e($para[$pl['tipo_cuenta']] ?? '') ?></small></td>
      <?php foreach (['mensual', 'trimestral', 'semestral', 'anual'] as $k): ?><td class="num"><?= isset($o[$k]) ? dinero($o[$k]['precio']) . ($o[$k]['gratis'] ? '<br><small class="suave">' . texto_ahorro($o[$k]['gratis']) . '</small>' : '') : '—' ?></td><?php endforeach; ?>
      <td style="white-space:normal;font-size:.85rem"><?= e(implode(' · ', textos_limites_plan($pl))) ?></td>
      <td class="num"><?= count(modulos_plan($pl)) ?>/<?= count(modulos_sistema()) ?><?= modulos_elegir_plan($pl) ? '<br><small class="suave">+ ' . modulos_elegir_plan($pl) . ' a elegir · cada uno más ' . (precio_modulo_extra_plan($pl) === null ? 'precio del catálogo' : dinero(precio_modulo_extra_plan($pl))) . '</small>' : '' ?></td><td class="num"><?= (int)$pl['n_ligas'] ?></td>
      <td><?= $pl['activo'] ? 'Visible' : '<span class="etiqueta gris">Oculto</span>' ?></td>
      <td class="acciones"><a class="btn btn-sec btn-chico" href="admin.php?p=planes&amp;editar=<?= (int)$pl['id'] ?>#form">Editar</a>
        <a class="btn btn-sec btn-chico" target="_blank" href="<?= e(url_plan($pl)) ?>">Folleto</a>
        <a class="btn btn-sec btn-chico" target="_blank" href="imagen.php?tipo=plan&amp;plan=<?= (int)$pl['id'] ?>">Imagen</a>
        <form class="inline" method="post" onsubmit="return confirm('¿Eliminar el plan?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$pl['id'] ?>"><button class="btn btn-peligro btn-chico">Eliminar</button></form></td></tr>
  <?php endforeach; ?>
</table></div>

<div class="tarjeta" id="form" style="margin-top:20px">
  <h2 style="margin-top:0"><?= $editar ? 'Editar plan: ' . e($editar['nombre']) : 'Nuevo plan' ?></h2>
  <form method="post" action="admin.php?p=planes">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
    <div class="campos">
      <div><label>Nombre *</label><input type="text" name="nombre" required maxlength="60" value="<?= e($v['nombre']) ?>"></div>
      <div><label>Dirección del folleto</label><input type="text" name="slug" maxlength="40" value="<?= e($v['slug']) ?>" placeholder="se arma con el nombre"><div class="ayuda"><?= e(preg_replace('#^https?://#', '', url_base())) ?>planes/<b>…</b></div></div>
      <div><label>Para</label><select name="tipo_cuenta"><?php foreach ($para as $k => $t): ?><option value="<?= $k ?>" <?= $v['tipo_cuenta'] === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
      <div class="campo-ancho"><label>Frase corta (debajo del nombre)</label><input type="text" name="eslogan" maxlength="120" value="<?= e($v['eslogan']) ?>" placeholder="Ej: La liga completa, con árbitros, patrocinadores y cobros"></div>
    </div>
    <h3>💲 Precios <small class="suave">(0 = no se ofrece)</small></h3>
    <div class="campos">
      <div><label>Mensual ($)</label><input type="number" name="precio_mensual" id="pr-m" min="0" step="0.01" value="<?= e($v['precio_mensual']) ?>"></div>
      <div><label>Trimestral · 3 meses ($)</label><input type="number" name="precio_trimestral" min="0" step="0.01" value="<?= e($v['precio_trimestral']) ?>"></div>
      <div><label>Semestral · 6 meses ($)</label><input type="number" name="precio_semestral" id="pr-s" min="0" step="0.01" value="<?= e($v['precio_semestral']) ?>"><div class="ayuda"><a href="#" onclick="var m=parseFloat(document.getElementById('pr-m').value)||0;document.getElementById('pr-s').value=(m*5).toFixed(2);return false">Poner 1 mes de ahorro</a></div></div>
      <div><label>Anual · 12 meses ($)</label><input type="number" name="precio_anual" id="pr-a" min="0" step="0.01" value="<?= e($v['precio_anual']) ?>"><div class="ayuda"><a href="#" onclick="var m=parseFloat(document.getElementById('pr-m').value)||0;document.getElementById('pr-a').value=(m*10).toFixed(2);return false">Poner 2 meses de ahorro</a></div></div>
    </div>
    <p class="ayuda">Un plan de un solo período (por ejemplo el plan Torneo: solo trimestral) dura ese tiempo; al terminar se renueva o se cambia de plan.</p>
    <h3>📏 Límites <small class="suave">(0 = sin límite)</small></h3>
    <div class="campos">
      <div><label>Torneos a la vez</label><input type="number" name="max_campeonatos" min="0" value="<?= (int)$v['max_campeonatos'] ?>"></div>
      <div><label>Equipos por torneo</label><input type="number" name="max_equipos_torneo" min="0" value="<?= (int)$v['max_equipos_torneo'] ?>"></div>
      <div><label>Equipos en total en la liga</label><input type="number" name="max_equipos" min="0" value="<?= (int)$v['max_equipos'] ?>"></div>
      <div><label>Alumnos (escuelas)</label><input type="number" name="max_alumnos" min="0" value="<?= (int)$v['max_alumnos'] ?>"></div>
    </div>
    <h3>🧩 Módulos incluidos</h3>
    <p class="ayuda">Lo básico (<?= e(implode(', ', array_slice(funciones_base(), 0, 5))) ?>…) viene en todos los planes. Marque los módulos que trae este plan:</p>
    <div class="rejilla-modulos">
      <?php foreach (modulos_sistema() as $k => [$ico, $nom, $tipo, $desc]): ?>
        <label class="check modulo-check" style="font-weight:400" title="<?= e($desc) ?>"><input type="checkbox" name="modulos[]" value="<?= e($k) ?>" <?= in_array($k, $mods_v, true) ? 'checked' : '' ?>> <?= $ico ?> <b><?= e($nom) ?></b> <small class="suave"><?= $tipo === 'liga' ? '(ligas)' : ($tipo === 'escuela' ? '(escuelas)' : '') ?></small></label>
      <?php endforeach; ?>
    </div>
    <p><a href="#" onclick="document.querySelectorAll('input[name=\'modulos[]\']').forEach(function(c){c.checked=true});return false">Marcar todos</a> · <a href="#" onclick="document.querySelectorAll('input[name=\'modulos[]\']').forEach(function(c){c.checked=false});return false">Quitar todos</a></p>
    <h3>🎯 Módulos a elección del cliente</h3>
    <p class="ayuda">Plan Básico: la base + los módulos que elige cada cliente (vienen incluidos en el precio); cada uno más cuesta lo de «Cada módulo más». <?= e(nombres_modulos(modulos_fuera_del_cupo())) ?> no cuenta entre los elegidos: se cobra con su precio del catálogo (Ventas → Planes → catálogo de módulos). Los deportes o disciplinas adicionales se cobran aparte, como siempre.</p>
    <div class="campos">
      <div><label>Módulos que elige el cliente</label><input type="number" name="modulos_elegir" min="0" max="50" value="<?= (int)($v['modulos_elegir'] ?? 0) ?>"><div class="ayuda">0 = el cliente no elige (el plan trae los marcados arriba).</div></div>
      <div><label>Cada módulo más ($ al mes)</label><input type="number" name="precio_modulo_extra" min="0" step="0.01" value="<?= ($v['precio_modulo_extra'] ?? null) === null || $v['precio_modulo_extra'] === '' ? '' : e((string)$v['precio_modulo_extra']) ?>" placeholder="vacío = precio del catálogo"></div>
    </div>
    <div class="campos">
      <div class="campo-ancho"><label>Puntos extra para la página de venta (uno por línea, opcional)</label><textarea name="descripcion" rows="4" placeholder="Soporte prioritario por WhatsApp&#10;Capacitación por videollamada"><?= e($v['descripcion']) ?></textarea></div>
      <div><label>Orden</label><input type="number" name="orden" min="0" max="99" value="<?= (int)$v['orden'] ?>"></div>
      <div><label class="check"><input type="checkbox" name="destacado" value="1" <?= $v['destacado'] ? 'checked' : '' ?>> Recomendado (resaltado)</label></div>
      <div><label class="check"><input type="checkbox" name="dominio_anual" value="1" <?= (int)($v['dominio_anual'] ?? 0) ? 'checked' : '' ?>> 🌐 Dominio propio incluido al pagar 1 año</label><div class="ayuda">Sale con * y la nota del dominio (Ajustes → Marca y venta).</div></div>
      <div><label class="check"><input type="checkbox" name="activo" value="1" <?= $v['activo'] ? 'checked' : '' ?>> Visible en la página de venta</label></div>
    </div>
    <button class="btn">Guardar plan</button> <?php if ($editar): ?><a href="admin.php?p=planes">Cancelar</a><?php endif; ?>
  </form>
</div>
<?php
pie_admin();
