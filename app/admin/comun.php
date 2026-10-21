<?php
/*
 * Funciones compartidas por las páginas del panel.
 */

function equipo_de_liga(int $id, int $liga_id): array
{
    $e = fila('SELECT * FROM equipos WHERE id = ? AND liga_id = ?', [$id, $liga_id]);
    if (!$e) throw new RuntimeException('Equipo no encontrado.');
    return $e;
}

function jugador_de_liga(int $id, int $liga_id): array
{
    $j = fila('SELECT j.*, e.nombre AS equipo FROM jugadores j JOIN equipos e ON e.id = j.equipo_id WHERE j.id = ? AND e.liga_id = ?', [$id, $liga_id]);
    if (!$j) throw new RuntimeException('Jugador no encontrado.');
    return $j;
}

function torneo_de_liga(int $id, int $liga_id): array
{
    $t = fila('SELECT * FROM torneos WHERE id = ? AND liga_id = ?', [$id, $liga_id]);
    if (!$t) throw new RuntimeException('Campeonato no encontrado.');
    return $t;
}

function partido_de_liga(int $id, int $liga_id): array
{
    $p = partido_completo($id);
    if (!$p || (int)$p['liga_id'] !== $liga_id) throw new RuntimeException('Partido no encontrado.');
    return $p;
}

/** Campos de parámetros de la liga (se usa al crear y al editar). */
/** Campos numéricos de las reglas (se usan en la liga como "por defecto" y en cada campeonato). */
function campos_reglas(): array
{
    return [
        'Jugadores y cambios' => [
            'jugadores_cancha'        => ['Jugadores en cancha (por equipo)', 'En fútbol incluye al arquero. Ecuavóley: 3. Básquet: 5 (o 3).', 1, 11],
            'min_para_jugar'          => ['Mínimo de jugadores para empezar', 'Con menos, el equipo pierde por W.O.', 1, 11],
            'max_suplentes'           => ['Máximo de suplentes en planilla', 'La vocalía no deja marcar más presentes.', 0, 30],
            'max_cambios'             => ['Máximo de cambios por partido', '0 = cambios ilimitados.', 0, 30],
            'min_plantilla'           => ['Mínimo de jugadores inscritos por equipo', '', 1, 60],
            'max_plantilla'           => ['Máximo de jugadores inscritos por equipo', '', 1, 60],
        ],
        'Duración' => [
            'num_tiempos'             => ['Número de tiempos (en básquet: cuartos; en béisbol y softbol: entradas)', 'Béisbol: 9 entradas · softbol: 7.', 1, 12],
            'minutos_tiempo'          => ['Minutos de cada tiempo o cuarto', '', 5, 60],
        ],
        'Ecuavóley: sets' => [
            'sets_ganar'              => ['Sets que hay que ganar', 'Ej: 2 = partido al mejor de 3 sets.', 1, 3],
            'puntos_set'              => ['Puntos por set', 'Ej: 15 o 21.', 5, 30],
            'puntos_set_final'        => ['Puntos del set decisivo', 'El último set (ej: el 3º) puede ser más corto.', 5, 30],
        ],
        'Básquet: faltas' => [
            'faltas_limite'           => ['Faltas personales para salir del partido', 'Ej: 5. 0 = sin límite. La vocalía avisa cuando un jugador llega.', 0, 10],
        ],
        'Puntos' => [
            'puntos_ganado'           => ['Puntos por partido ganado', '', 0, 10],
            'puntos_empate'           => ['Puntos por empate', '', 0, 10],
            'puntos_perdido'          => ['Puntos por partido perdido', '', 0, 10],
            'goles_wo'                => ['Goles (puntos o sets) para el ganador por W.O.', 'Ej: fútbol 3 = queda 3-0 · básquet 20 · ecuavóley 2 sets.', 0, 30],
        ],
        'Tarjetas y suspensiones' => [
            'amarillas_suspension'    => ['Amarillas acumuladas para suspensión', 'Ej: 3 = al llegar a 3 amarillas se suspende. En básquet cuentan las faltas técnicas.', 1, 20],
            'partidos_susp_amarillas' => ['Partidos de suspensión por amarillas', '', 0, 10],
            'partidos_susp_roja'      => ['Partidos de suspensión por roja', 'La comisión puede sumar más con una sanción manual.', 0, 20],
        ],
    ];
}

/** Bloque HTML con todas las reglas (modalidad, jugadores, duración, puntos, tarjetas, árbitros, calificación). */
function html_reglas(array $v, string $titulo = 'Modalidad y reglas'): void
{
    static $n = 0;
    $n++;
    $pref = 'r' . $n . '_';
    ?>
  <fieldset>
    <legend><?= e($titulo) ?></legend>
    <div class="campos">
      <div>
        <label>Modalidad</label>
        <select name="modalidad" id="<?= $pref ?>modalidad">
          <?= opciones_modalidad($v['modalidad'] ?? 'F7') ?>
        </select>
        <div class="ayuda">Al cambiarla se llenan valores sugeridos. Luego ajústelos a su reglamento.</div>
      </div>
      <div>
        <label>Árbitros por partido</label>
        <select name="num_arbitros" id="<?= $pref ?>num_arbitros">
          <?php foreach ([1 => '1 árbitro (solo central)', 2 => '2 árbitros (central + segundo)', 3 => '3 árbitros (central + 2 de línea)', 4 => '4 árbitros (central + 2 de línea + cuarto)'] as $k => $txt): ?>
            <option value="<?= $k ?>" <?= (int)($v['num_arbitros'] ?? 1) === $k ? 'selected' : '' ?>><?= e($txt) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="ayuda">Según presupuesto. Se puede cambiar en cualquier momento.</div>
      </div>
      <div>
        <label>Árbitros en eliminatorias</label>
        <select name="num_arbitros_final">
          <option value="0">Igual que la fase regular</option>
          <?php foreach ([1, 2, 3, 4] as $k): ?><option value="<?= $k ?>" <?= (int)($v['num_arbitros_final'] ?? 0) === $k ? 'selected' : '' ?>><?= $k ?> árbitro<?= $k > 1 ? 's' : '' ?></option><?php endforeach; ?>
        </select>
        <div class="ayuda">Octavos, cuartos, semifinal, tercer lugar y final. Además, cada partido puede tener su propio número.</div>
      </div>
    </div>
    <?php foreach (campos_reglas() as $grupo => $campos):
        $solo = str_starts_with($grupo, 'Ecuavóley') ? 'ecuavoley' : (str_starts_with($grupo, 'Básquet') ? 'basquet' : ''); ?>
      <div class="grupo-regla" data-solo="<?= $solo ?>" style="<?= $solo !== '' && deporte_de($v) !== $solo ? 'display:none' : '' ?>">
      <h3 style="margin:.6rem 0 .4rem;font-size:.95rem"><?= e($grupo) ?></h3>
      <div class="campos">
        <?php foreach ($campos as $campo => [$tit, $ayuda, $min, $max]): ?>
          <div>
            <label><?= e($tit) ?></label>
            <input type="number" name="<?= $campo ?>" id="<?= $pref . $campo ?>" min="<?= $min ?>" max="<?= $max ?>" required value="<?= (int)($v[$campo] ?? 0) ?>">
            <?php if ($ayuda): ?><div class="ayuda"><?= e($ayuda) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($grupo === 'Tarjetas y suspensiones'): ?>
          <div><label class="check"><input type="checkbox" name="doble_amarilla_roja" value="1" <?= !empty($v['doble_amarilla_roja']) ? 'checked' : '' ?>> Doble amarilla en un partido = roja</label></div>
        <?php endif; ?>
      </div>
      </div>
    <?php endforeach; ?>
    <h3 style="margin:.6rem 0 .4rem;font-size:.95rem">Calificación del árbitro</h3>
    <div class="acciones">
      <?php foreach (['no' => 'No calificar', 'opcional' => 'Opcional', 'obligatorio' => 'Obligatoria'] as $k => $txt): ?>
        <label class="check" style="font-weight:400;margin-right:12px"><input type="radio" name="calificar_arbitro" value="<?= $k ?>" <?= ($v['calificar_arbitro'] ?? 'no') === $k ? 'checked' : '' ?>> <?= e($txt) ?></label>
      <?php endforeach; ?>
    </div>
    <div class="ayuda">La directiva o la comisión califica al árbitro después de cada partido (nota del 1 al 10). Si es <b>obligatoria</b>, el vocal directivo no puede cerrar la vocalía sin calificar y el panel avisa los partidos pendientes.</div>
    <h3 style="margin:.9rem 0 .4rem;font-size:.95rem">💵 Dinero</h3>
    <div class="campos">
      <div><label>Cuota de inscripción por equipo ($)</label><input type="number" name="cuota_inscripcion" min="0" step="0.01" value="<?= e(number_format((float)($v['cuota_inscripcion'] ?? 0), 2, '.', '')) ?>"><div class="ayuda">Se carga sola a cada equipo inscrito. 0 = no se cobra.</div></div>
      <div><label>Arbitraje por partido, cada equipo ($)</label><input type="number" name="arbitraje_por_equipo" min="0" step="0.01" value="<?= e(number_format((float)($v['arbitraje_por_equipo'] ?? 0), 2, '.', '')) ?>"><div class="ayuda">Se carga a los dos equipos al jugarse el partido. En W.O. paga el doble el que no se presentó.</div></div>
      <div><label>Si un equipo tiene deuda</label>
        <select name="bloqueo_deuda">
          <?php foreach (['no' => 'No hacer nada', 'avisar' => 'Avisar en la vocalía y en el carnet', 'bloquear' => 'No dejarlo jugar (la vocalía no puede iniciar)'] as $k => $txt): ?>
            <option value="<?= $k ?>" <?= ($v['bloqueo_deuda'] ?? 'no') === $k ? 'selected' : '' ?>><?= e($txt) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label>Pago al árbitro central por partido ($)</label><input type="number" name="pago_central" min="0" step="0.01" value="<?= e(number_format((float)($v['pago_central'] ?? 0), 2, '.', '')) ?>"><div class="ayuda">Para la liquidación de árbitros. 0 = no se calcula.</div></div>
      <div><label>Pago a cada asistente / segundo árbitro ($)</label><input type="number" name="pago_asistente" min="0" step="0.01" value="<?= e(number_format((float)($v['pago_asistente'] ?? 0), 2, '.', '')) ?>"></div>
      <div><label>Pago al cuarto árbitro ($)</label><input type="number" name="pago_cuarto" min="0" step="0.01" value="<?= e(number_format((float)($v['pago_cuarto'] ?? 0), 2, '.', '')) ?>"></div>
      <div><label>Deuda permitida ($)</label><input type="number" name="deuda_maxima" min="0" step="0.01" value="<?= e(number_format((float)($v['deuda_maxima'] ?? 0), 2, '.', '')) ?>"><div class="ayuda">Se avisa o bloquea solo si la deuda es MAYOR a este valor.</div></div>
    </div>
  </fieldset>
<script>
(function () {
  var presets = <?= json_encode(presets_modalidad()) ?>;
  var deportes = <?= json_encode(array_combine(modalidades(), array_map('deporte_de', modalidades()))) ?>;
  var sel = document.getElementById('<?= $pref ?>modalidad');
  sel.addEventListener('change', function () {
    var dep = deportes[sel.value] || 'futbol';
    sel.closest('fieldset').querySelectorAll('.grupo-regla').forEach(function (g) {
      var solo = g.getAttribute('data-solo');
      g.style.display = !solo || solo === dep ? '' : 'none';
    });
    var p = presets[sel.value];
    if (!p) return;
    Object.keys(p).forEach(function (k) {
      var el = document.getElementById('<?= $pref ?>' + k);
      if (el) el.value = p[k];
    });
  });
})();
</script>
<?php
}

/** Lee y valida las reglas enviadas en el formulario. */
function leer_reglas_post(array $def = []): array
{
    $def = array_merge(['modalidad' => 'F7', 'puntos_ganado' => 3, 'puntos_empate' => 1, 'puntos_perdido' => 0, 'goles_wo' => 3,
        'doble_amarilla_roja' => 1, 'num_arbitros_final' => 0, 'calificar_arbitro' => 'no',
        'cuota_inscripcion' => 0, 'arbitraje_por_equipo' => 0, 'bloqueo_deuda' => 'no', 'deuda_maxima' => 0,
        'pago_central' => 0, 'pago_asistente' => 0, 'pago_cuarto' => 0], presets_modalidad()['F7'], $def);
    $val = fn(string $k) => isset($_POST[$k]) ? post($k) : (string)$def[$k];
    // (v35) solo modalidades de los módulos deportivos de la cuenta (si cambia la que ya tenía)
    if (isset($_POST['modalidad']) && post('modalidad') !== (string)($def['modalidad'] ?? '') && function_exists('liga_trabajo')) exigir_modalidad(liga_trabajo(), post('modalidad'));
    $r = [
        'modalidad' => in_array($val('modalidad'), modalidades(), true) ? $val('modalidad') : 'F7',
        'num_arbitros' => entero($val('num_arbitros'), 1, 4),
        'num_arbitros_final' => entero($val('num_arbitros_final'), 0, 4),
        'doble_amarilla_roja' => isset($_POST['modalidad']) ? (post('doble_amarilla_roja') === '1' ? 1 : 0) : (int)$def['doble_amarilla_roja'],
        'calificar_arbitro' => in_array($val('calificar_arbitro'), ['no', 'opcional', 'obligatorio'], true) ? $val('calificar_arbitro') : 'no',
        'cuota_inscripcion' => max(0, round((float)str_replace(',', '.', $val('cuota_inscripcion')), 2)),
        'arbitraje_por_equipo' => max(0, round((float)str_replace(',', '.', $val('arbitraje_por_equipo')), 2)),
        'bloqueo_deuda' => in_array($val('bloqueo_deuda'), ['no', 'avisar', 'bloquear'], true) ? $val('bloqueo_deuda') : 'no',
        'deuda_maxima' => max(0, round((float)str_replace(',', '.', $val('deuda_maxima')), 2)),
        'pago_central' => max(0, round((float)str_replace(',', '.', $val('pago_central')), 2)),
        'pago_asistente' => max(0, round((float)str_replace(',', '.', $val('pago_asistente')), 2)),
        'pago_cuarto' => max(0, round((float)str_replace(',', '.', $val('pago_cuarto')), 2)),
    ];
    foreach (campos_reglas() as $campos) {
        foreach ($campos as $campo => [$t, $a, $min, $max]) {
            $r[$campo] = entero($val($campo), $min, $max);
        }
    }
    if ($r['max_plantilla'] < $r['min_plantilla']) {
        throw new RuntimeException('El máximo de jugadores inscritos no puede ser menor que el mínimo.');
    }
    if ($r['min_para_jugar'] > $r['jugadores_cancha']) {
        throw new RuntimeException('El mínimo para empezar no puede ser mayor que los jugadores en cancha.');
    }
    return $r;
}

function formulario_liga(?array $l, string $accion): void
{
    $l = $l ?? array_merge(['nombre' => '', 'slug' => '', 'ciudad' => '', 'logo' => '', 'color' => '#0b6e4f', 'modalidad' => 'F7',
        'puntos_ganado' => 3, 'puntos_empate' => 1, 'puntos_perdido' => 0, 'doble_amarilla_roja' => 1, 'goles_wo' => 3, 'activa' => 1, 'calificar_arbitro' => 'no'], presets_modalidad()['F7']);
    ?>
<form method="post" enctype="multipart/form-data" action="<?= e($accion) ?>">
  <?= csrf_campo() ?>
  <fieldset>
    <legend>Datos de la liga</legend>
    <div class="campos">
      <div><label>Nombre de la liga *</label><input type="text" name="nombre" required maxlength="120" value="<?= e($l['nombre']) ?>"></div>
      <div><label>Ciudad / sector</label><input type="text" name="ciudad" maxlength="80" value="<?= e($l['ciudad']) ?>"></div>
      <?php if (es_super()): ?>
      <div><label>Dirección corta</label><input type="text" name="slug" maxlength="60" value="<?= e($l['slug']) ?>" placeholder="se crea sola"><div class="ayuda">Ej: liga-norte → <?= e(url_base()) ?>liga-norte</div></div>
      <?php endif; ?>
      <div><label>Color principal</label><input type="color" name="color" value="<?= e(color_valido($l['color'])) ?>"><div class="ayuda">Botones, barras y títulos.</div></div>
      <div><label>Color secundario</label><input type="color" name="color2" value="<?= e(color_valido((string)($l['color2'] ?? ''), '#0f1614')) ?>"><div class="ayuda">Se mezcla con el principal en la cabecera de la página pública. <label style="display:inline;font-weight:400"><input type="checkbox" name="sin_color2" value="1" <?= ($l['color2'] ?? '') === '' ? 'checked' : '' ?>> no usar</label></div></div>
      <div><label>Logo de la liga</label><input type="file" name="logo" accept="image/*"><?php if ($l['logo']): ?><div class="ayuda"><img src="<?= e($l['logo']) ?>" class="mini" alt=""> ya tiene logo (suba otro para cambiarlo)</div><?php endif; ?></div>
      <?php if (es_super()): ?>
      <div><label class="check"><input type="checkbox" name="activa" value="1" <?= $l['activa'] ? 'checked' : '' ?>> Liga activa (visible al público)</label></div>
      <?php endif; ?>
      <div><label>Patrocinador principal (nombre)</label><input type="text" name="patrocinador_nombre" maxlength="80" value="<?= e($l['patrocinador_nombre'] ?? '') ?>"><div class="ayuda">Aparece en las imágenes para redes.</div></div>
      <div><label>Logo del patrocinador</label><input type="file" name="patrocinador_logo" accept="image/*"><?php if (!empty($l['patrocinador_logo'])): ?><div class="ayuda"><img src="<?= e($l['patrocinador_logo']) ?>" class="mini" alt=""> ya tiene logo · <label style="display:inline;font-weight:400"><input type="checkbox" name="quitar_patrocinador" value="1"> quitar</label></div><?php endif; ?></div>
      <div class="campo-ancho"><label>Datos para pagos (banco, cuenta, a nombre de)</label><textarea name="datos_pago" maxlength="500" rows="2" placeholder="Ej: Banco Pichincha · Cta. de ahorros 2200000000 · a nombre de Liga Barrial Norte · RUC ..."><?= e($l['datos_pago'] ?? '') ?></textarea><div class="ayuda">Los delegados los ven al enviar el comprobante de su transferencia.</div></div>
      <div><label>Correo de contacto de la liga</label><input type="email" name="contacto_email" maxlength="120" value="<?= e($l['contacto_email'] ?? '') ?>"><div class="ayuda">Aparece en la política de privacidad (para pedir datos o su eliminación).</div></div>
    </div>
  </fieldset>
  <fieldset>
    <legend>🔁 Pases entre equipos</legend>
    <div class="campos">
      <div><label>Valor del pase ($)</label><input type="number" name="costo_pase" min="0" step="0.01" value="<?= e(number_format((float)($l['costo_pase'] ?? 0), 2, '.', '')) ?>"><div class="ayuda">Se carga al equipo que recibe al jugador. 0 = sin costo. Se puede cambiar en cada pase.</div></div>
      <div><label class="check" style="margin-top:28px"><input type="checkbox" name="pases_delegados" value="1" <?= !isset($l['pases_delegados']) || $l['pases_delegados'] ? 'checked' : '' ?>> Los delegados pueden pedir pases en línea</label><div class="ayuda">Solo mientras la inscripción de jugadores esté abierta. La liga aprueba cada pase.</div></div>
    </div>
  </fieldset>
  <div class="aviso aviso-info">Estas son las reglas <b>por defecto</b>. Cada campeonato nuevo empieza con ellas, pero cada campeonato puede tener las suyas (se cambian en Campeonato → Configuración).</div>
  <?php html_reglas($l, 'Reglas por defecto para nuevos campeonatos'); ?>
  <button class="btn" type="submit">Guardar</button>
</form>
<?php
}

/** Guarda la liga (nueva si $id es null). Devuelve el id. */
function guardar_liga(?int $id): int
{
    $nombre = post('nombre');
    if ($nombre === '') throw new RuntimeException('Escriba el nombre de la liga.');
    $datos = array_merge([
        'nombre' => mb_substr($nombre, 0, 120),
        'ciudad' => mb_substr(post('ciudad'), 0, 80),
        'color' => color_valido(post('color')),
        'color2' => post('sin_color2') === '1' ? '' : color_valido(post('color2'), ''),
    ], leer_reglas_post($id ? (fila('SELECT * FROM ligas WHERE id = ?', [$id]) ?? []) : []));
    if (es_super()) {
        $slug = slugify(post('slug') !== '' ? post('slug') : $nombre);
        if (in_array($slug, rutas_reservadas(), true)) $slug .= '-liga';
        $base = $slug;
        $n = 2;
        while ((int)valor('SELECT COUNT(*) FROM ligas WHERE slug = ? AND id <> ?', [$slug, $id ?? 0]) > 0) {
            $slug = $base . '-' . $n++;
        }
        $datos['slug'] = $slug;
        $datos['activa'] = post('activa') === '1' ? 1 : 0;
    }
    $datos['patrocinador_nombre'] = mb_substr(post('patrocinador_nombre'), 0, 80);
    $datos['contacto_email'] = filter_var(post('contacto_email'), FILTER_VALIDATE_EMAIL) ? mb_substr(post('contacto_email'), 0, 120) : '';
    $datos['costo_pase'] = max(0, round((float)str_replace(',', '.', post('costo_pase')), 2));
    $datos['datos_pago'] = mb_substr(post('datos_pago'), 0, 500);
    $datos['pases_delegados'] = post('pases_delegados') === '1' ? 1 : 0;
    $pat = subir_imagen('patrocinador_logo', 'ligas', 600);
    if ($pat || post('quitar_patrocinador') === '1') {
        if ($id) borrar_imagen((string)valor('SELECT patrocinador_logo FROM ligas WHERE id = ?', [$id]));
        $datos['patrocinador_logo'] = $pat ?? '';
    }
    $logo = subir_imagen('logo', 'ligas', 500);
    if ($logo) {
        if ($id) borrar_imagen((string)valor('SELECT logo FROM ligas WHERE id = ?', [$id]));
        $datos['logo'] = $logo;
    }
    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($datos)));
        $datos['id'] = $id;
        q("UPDATE ligas SET $sets WHERE id = :id", $datos);
        return $id;
    }
    if (!isset($datos['slug'])) throw new RuntimeException('Solo el superadministrador crea ligas.');
    $cols = implode(', ', array_keys($datos));
    $vals = implode(', ', array_map(fn($k) => ":$k", array_keys($datos)));
    q("INSERT INTO ligas ($cols) VALUES ($vals)", $datos);
    return (int)db()->lastInsertId();
}

/**
 * Límites de plantilla de un equipo: los de los campeonatos activos en los que está inscrito
 * (si juega varios, se usa el mayor). Si no está en ninguno, los de la liga.
 */
function limites_plantilla(int $equipo_id, array $liga): array
{
    $r = fila("SELECT MAX(t.min_plantilla) AS mn, MAX(t.max_plantilla) AS mx FROM torneos t JOIN torneo_equipos te ON te.torneo_id = t.id
               WHERE te.equipo_id = ? AND t.estado <> 'finalizado'", [$equipo_id]);
    if ($r && $r['mx'] !== null) return [(int)$r['mn'], (int)$r['mx']];
    return [(int)$liga['min_plantilla'], (int)$liga['max_plantilla']];
}

/** Bloque del formulario: categoría por edad del campeonato (versión 6). */
function html_categoria(array $t): void
{
    $con = torneo_con_categoria($t);
    ?>
  <fieldset>
    <legend>👶 Categoría por edad</legend>
    <label class="check"><input type="checkbox" name="con_categoria" value="1" <?= $con ? 'checked' : '' ?> onchange="document.getElementById('caja-cat').style.display = this.checked ? '' : 'none'"> Este campeonato es de una categoría por edad (Sub-12, Sub-17, Máster…)</label>
    <div id="caja-cat" style="<?= $con ? '' : 'display:none' ?>;margin-top:10px">
      <div class="campos">
        <div><label>Nombre de la categoría</label><input type="text" name="categoria" maxlength="40" value="<?= e($t['categoria'] ?? '') ?>" placeholder="Ej: Sub-15, Máster +40"></div>
        <div><label>Edad mínima</label><input type="number" name="edad_min" min="0" max="80" value="<?= (int)($t['edad_min'] ?? 0) ?>"><div class="ayuda">0 = sin mínimo. Ej: Máster +40 → 40.</div></div>
        <div><label>Edad máxima</label><input type="number" name="edad_max" min="0" max="80" value="<?= (int)($t['edad_max'] ?? 0) ?>"><div class="ayuda">0 = sin máximo. Ej: Sub-15 → 15.</div></div>
        <div><label>Edad cumplida al</label><input type="date" name="edad_corte" value="<?= e($t['edad_corte'] ?? '') ?>"><div class="ayuda">Vacío = 31 de diciembre de la temporada (es decir, por <b>año de nacimiento</b>).</div></div>
        <div><label>"Pasados" permitidos por equipo</label><input type="number" name="pasados_plantilla" min="0" max="30" value="<?= (int)($t['pasados_plantilla'] ?? 0) ?>"><div class="ayuda">Jugadores fuera de la edad que cada equipo puede inscribir. 0 = ninguno.</div></div>
        <div><label>"Pasados" a la vez en un partido</label><input type="number" name="pasados_partido" min="0" max="30" value="<?= (int)($t['pasados_partido'] ?? 0) ?>"><div class="ayuda">Cuántos pueden estar presentes en la vocalía. 0 = los mismos de arriba.</div></div>
        <div><label>Máximo de años fuera de la edad</label><input type="number" name="pasados_max_anios" min="0" max="30" value="<?= (int)($t['pasados_max_anios'] ?? 0) ?>"><div class="ayuda">Ej: 2 = en Sub-15 se permite un pasado de hasta 17 años. 0 = sin límite.</div></div>
      </div>
    </div>
  </fieldset>
    <?php
}

/** Lee la categoría del formulario. */
function leer_categoria_post(): array
{
    if (post('con_categoria') !== '1') {
        return ['categoria' => '', 'edad_min' => 0, 'edad_max' => 0, 'edad_corte' => '', 'pasados_plantilla' => 0, 'pasados_partido' => 0, 'pasados_max_anios' => 0];
    }
    $r = [
        'categoria' => mb_substr(post('categoria'), 0, 40), 'edad_min' => entero(post('edad_min'), 0, 80), 'edad_max' => entero(post('edad_max'), 0, 80),
        'edad_corte' => post('edad_corte') !== '' && strtotime(post('edad_corte')) ? date('Y-m-d', strtotime(post('edad_corte'))) : '',
        'pasados_plantilla' => entero(post('pasados_plantilla'), 0, 30), 'pasados_partido' => entero(post('pasados_partido'), 0, 30), 'pasados_max_anios' => entero(post('pasados_max_anios'), 0, 30),
    ];
    if ($r['edad_min'] === 0 && $r['edad_max'] === 0) throw new RuntimeException('Para una categoría por edad escriba la edad mínima o la máxima.');
    if ($r['edad_max'] > 0 && $r['edad_min'] > $r['edad_max']) throw new RuntimeException('La edad mínima no puede ser mayor que la máxima.');
    return $r;
}

/** (v7) Campos de límites de una liga (solo el superadministrador). Vacío = lo que diga el plan; 0 = ilimitado. */
function html_limites_liga(array $l): void
{
    $v = fn(string $k) => array_key_exists($k, $l) && $l[$k] !== null ? (string)(int)$l[$k] : '';
    ?>
      <div class="campos">
        <div><label>Máximo de campeonatos</label><input type="number" name="max_campeonatos" min="0" max="9999" value="<?= e($v('max_campeonatos')) ?>" placeholder="según el plan"><div class="ayuda">Vacío = lo del plan · 0 = ilimitado.</div></div>
        <div><label>¿Cómo se cuentan?</label><select name="modo_campeonatos">
          <option value="" <?= ($l['modo_campeonatos'] ?? '') !== 'total' ? 'selected' : '' ?>>A la vez (no cuentan los finalizados)</option>
          <option value="total" <?= ($l['modo_campeonatos'] ?? '') === 'total' ? 'selected' : '' ?>>En total (todos los que se creen)</option></select>
          <div class="ayuda">"A la vez": al finalizar un campeonato puede crear otro.</div></div>
        <div><label>Máximo de equipos</label><input type="number" name="max_equipos" min="0" max="9999" value="<?= e($v('max_equipos')) ?>" placeholder="según el plan"><div class="ayuda">Vacío = lo del plan · 0 = ilimitado.</div></div>
      </div>
      <?php if (isset($l['id'])): ?><p class="suave" style="margin:.6rem 0 0">🧩 Los módulos (encender, apagar, precio) están en <a href="#modulos-cuenta">Módulos de la cuenta</a>.</p><?php endif; // (Anexo B) ?>
    <?php
}

/** (v7) Crea un usuario del panel de una liga con su perfil. */
function crear_usuario_liga(int $liga_id, string $nombre, string $email, string $clave, string $perfil): int
{
    $email = mb_strtolower(trim($email), 'UTF-8');
    if (mb_strlen(trim($nombre)) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Nombre y correo válido son obligatorios.');
    if (strlen($clave) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
    if (fila('SELECT id FROM usuarios WHERE lower(email) = ?', [$email])) throw new RuntimeException('Ese correo ya está registrado en el sistema. Use otro.');
    $perfil = isset(perfiles()[$perfil]) ? $perfil : 'admin';
    q("INSERT INTO usuarios (nombre, email, clave, rol, liga_id, perfil) VALUES (?,?,?, 'liga', ?, ?)", [mb_substr(trim($nombre), 0, 80), $email, password_hash($clave, PASSWORD_DEFAULT), $liga_id, $perfil]);
    return (int)db()->lastInsertId();
}


/** (v7) Pestañas de la sección "Redes y público". */
function subnav_publico(string $activa): void
{
    $items = ['redes' => '🖼 Imágenes para redes', 'patrocinadores' => '🤝 Patrocinadores', 'votaciones' => '🗳 Votaciones', 'polla' => '🎯 Polla', 'widgets' => '🧩 Para su web'];
    $items = array_filter($items, fn($k) => modulo_menu(liga_trabajo(), modulo_de_pagina($k)), ARRAY_FILTER_USE_KEY); // (v12) según el plan
    echo '<nav class="pestanas">';
    foreach ($items as $k => $txt) echo '<a href="admin.php?p=' . $k . '" class="' . ($activa === $k ? 'activa' : '') . '">' . e($txt) . '</a>';
    echo '</nav>';
}
