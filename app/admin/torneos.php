<?php
/* Lista de campeonatos + crear campeonato */
$liga = requiere_liga();
$lid = (int)$liga['id'];
// (v35) solo los deportes de los módulos que tiene la cuenta
$mods_equipo = filtrar_modalidades($liga, modalidades());
$mods_carrera = filtrar_modalidades($liga, modalidades_carrera());
$mods_duelo = filtrar_modalidades($liga, modalidades_duelo());
$mods_pruebas = filtrar_modalidades($liga, modalidades_pruebas());
$con_bolos = modalidad_permitida($liga, 'BOLOS');

// (v9) Copiar un campeonato para la nueva temporada
if (es_post() && post('accion') === 'copiar') {
    exigir_modalidad($liga, (string)valor('SELECT modalidad FROM torneos WHERE id = ? AND liga_id = ?', [(int)post('torneo_id'), $lid]));
    $id = copiar_campeonato($liga, (int)post('torneo_id'), post('nombre'), post('temporada'), post('con_equipos') === '1', post('con_grupos') === '1');
    olimp_tras_crear_torneo($id); // (v37) creado desde un evento de olimpiadas: queda como disciplina
    flash('Campeonato creado con las mismas reglas' . (post('con_equipos') === '1' ? ' y los mismos equipos' : '') . '. Revise la configuración y haga el sorteo cuando esté listo.');
    ir('admin.php?p=torneo&id=' . $id);
}

// (v22) Campeonato de carreras (autocross, ciclismo): sin equipos ni fixture, tiene su propio panel
if (es_post() && post('accion') === 'nuevo_carrera') {
    $nombre = post('nombre');
    if ($nombre === '') throw new RuntimeException('Escriba el nombre del campeonato.');
    if (!isset(modalidades_carrera()[post('modalidad')])) throw new RuntimeException('Elija el deporte.');
    exigir_modalidad($liga, post('modalidad'));
    verificar_limite_plan($liga, 'campeonato');
    $m = post('modalidad');
    $datos = array_merge(leer_reglas_post($liga), ['liga_id' => $lid, 'nombre' => mb_substr($nombre, 0, 100), 'temporada' => mb_substr(post('temporada'), 0, 30), 'formato' => 'liga', 'num_grupos' => 1, 'vueltas' => 1,
        'modalidad' => $m, 'carrera_mangas' => in_array($m, ['CICLO', 'MTB'], true) ? 1 : 2, 'carrera_modo' => 'mejor', 'carrera_clasif' => post('clasif') === 'tiempo' ? 'tiempo' : 'puntos']);
    $datos = aplicar_reglas_modulo($datos); // (v35) reglas por defecto del módulo deportivo
    q('INSERT INTO torneos (' . implode(', ', array_keys($datos)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($datos))) . ')', $datos);
    $id = (int)db()->lastInsertId();
    $nuevo_t = fila('SELECT * FROM torneos WHERE id = ?', [$id]);
    carrera_cargar_categorias_base($nuevo_t);
    carrera_cargar_penalidades_base($nuevo_t); // (v23)
    olimp_tras_crear_torneo($id); // (v37) creado desde un evento de olimpiadas: queda como disciplina
    flash('Campeonato creado con las categorías y penalizaciones de fábrica. Inscriba a los participantes y cree la primera fecha.');
    ir('admin.php?p=carrera&id=' . $id);
}

// (v25) Campeonato de DUELOS (tenis, pádel, tenis de mesa, artes marciales, boxeo): llaves o todos contra todos por categoría
if (es_post() && post('accion') === 'nuevo_duelo') {
    $nombre = post('nombre');
    if ($nombre === '') throw new RuntimeException('Escriba el nombre del campeonato.');
    if (!isset(modalidades_duelo()[post('modalidad')])) throw new RuntimeException('Elija el deporte.');
    exigir_modalidad($liga, post('modalidad'));
    verificar_limite_plan($liga, 'campeonato');
    $m = post('modalidad');
    $datos = array_merge(leer_reglas_post($liga), ['liga_id' => $lid, 'nombre' => mb_substr($nombre, 0, 100), 'temporada' => mb_substr(post('temporada'), 0, 30), 'formato' => 'liga', 'num_grupos' => 1, 'vueltas' => 1,
        'modalidad' => $m] + duelo_reglas_fabrica($m)); // (v36) squash y tenis de mesa a 11, ajedrez 1-½-0, e-sports 3-1-0
    $datos = aplicar_reglas_modulo($datos); // (v35) reglas por defecto del módulo deportivo
    q('INSERT INTO torneos (' . implode(', ', array_keys($datos)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($datos))) . ')', $datos);
    $id = (int)db()->lastInsertId();
    duelo_cargar_categorias_base(fila('SELECT * FROM torneos WHERE id = ?', [$id]));
    olimp_tras_crear_torneo($id); // (v37) creado desde un evento de olimpiadas: queda como disciplina
    flash('Campeonato creado con las categorías de fábrica. Inscriba a los competidores y sortee cada categoría.');
    ir('admin.php?p=duelo&id=' . $id . '&s=participantes');
}

// (v25) Campeonato de BOLOS: jornadas con líneas, handicap y clasificación por pinos, promedio o puntos
if (es_post() && post('accion') === 'nuevo_bolos') {
    $nombre = post('nombre');
    if ($nombre === '') throw new RuntimeException('Escriba el nombre del campeonato.');
    exigir_modalidad($liga, 'BOLOS');
    verificar_limite_plan($liga, 'campeonato');
    $datos = array_merge(leer_reglas_post($liga), ['liga_id' => $lid, 'nombre' => mb_substr($nombre, 0, 100), 'temporada' => mb_substr(post('temporada'), 0, 30), 'formato' => 'liga', 'num_grupos' => 1, 'vueltas' => 1,
        'modalidad' => 'BOLOS', 'bolos_lineas' => entero(post('lineas'), 1, 10), 'bolos_hcp' => array_key_exists(post('hcp'), bolos_textos_hcp()) ? post('hcp') : 'no',
        'bolos_clasif' => array_key_exists(post('clasif'), bolos_textos_clasif()) ? post('clasif') : 'total']);
    $datos = aplicar_reglas_modulo($datos); // (v35) reglas por defecto del módulo deportivo
    q('INSERT INTO torneos (' . implode(', ', array_keys($datos)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($datos))) . ')', $datos);
    $id = (int)db()->lastInsertId();
    bolos_cargar_categorias_base(fila('SELECT * FROM torneos WHERE id = ?', [$id]));
    olimp_tras_crear_torneo($id); // (v37) creado desde un evento de olimpiadas: queda como disciplina
    flash('Campeonato de bolos creado. Inscriba a los jugadores y cree la primera jornada.');
    ir('admin.php?p=bolos&id=' . $id . '&s=participantes');
}

// (v26) Campeonato de NATACIÓN o ATLETISMO: pruebas (50 m libre, 100 m planos, salto largo...) por categoría y rama
if (es_post() && post('accion') === 'nuevo_pruebas') {
    $nombre = post('nombre');
    if ($nombre === '') throw new RuntimeException('Escriba el nombre del campeonato.');
    if (!isset(modalidades_pruebas()[post('modalidad')])) throw new RuntimeException('Elija el deporte.');
    exigir_modalidad($liga, post('modalidad'));
    verificar_limite_plan($liga, 'campeonato');
    $datos = array_merge(leer_reglas_post($liga), ['liga_id' => $lid, 'nombre' => mb_substr($nombre, 0, 100), 'temporada' => mb_substr(post('temporada'), 0, 30), 'formato' => 'liga', 'num_grupos' => 1, 'vueltas' => 1,
        'modalidad' => post('modalidad'), 'carrera_puntos' => '9,7,6,5,4,3,2,1']);
    $datos = aplicar_reglas_modulo($datos); // (v35) reglas por defecto del módulo deportivo
    q('INSERT INTO torneos (' . implode(', ', array_keys($datos)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($datos))) . ')', $datos);
    $id = (int)db()->lastInsertId();
    olimp_tras_crear_torneo($id); // (v37) creado desde un evento de olimpiadas: queda como disciplina
    flash('Campeonato creado. Paso 1: arme el programa de pruebas (elija pruebas, ramas y categorías). Paso 2: inscriba a los competidores.');
    ir('admin.php?p=pruebas&id=' . $id . '&s=programa');
}

if (es_post()) {
    $nombre = post('nombre');
    if ($nombre === '') throw new RuntimeException('Escriba el nombre del campeonato.');
    verificar_limite_plan($liga, 'campeonato');
    $formato = post('formato') === 'grupos' ? 'grupos' : 'liga';
    $datos = array_merge([
        'liga_id' => $lid, 'nombre' => mb_substr($nombre, 0, 100), 'temporada' => mb_substr(post('temporada'), 0, 30), 'formato' => $formato,
        'num_grupos' => $formato === 'grupos' ? entero(post('num_grupos'), 2, 16) : 1, 'vueltas' => entero(post('vueltas'), 1, 2),
    ], leer_reglas_post($liga), leer_categoria_post());
    exigir_modalidad($liga, $datos['modalidad']); // (v35)
    $datos = aplicar_reglas_modulo($datos); // (v35) reglas por defecto del módulo deportivo
    q('INSERT INTO torneos (' . implode(', ', array_keys($datos)) . ') VALUES (' . implode(', ', array_map(fn($k) => ":$k", array_keys($datos))) . ')', $datos);
    $id = (int)db()->lastInsertId();
    // Inscribe automáticamente todos los equipos de la liga (se pueden quitar después)
    if (post('inscribir_todos') === '1' && !torneo_con_categoria($datos)) {
        verificar_equipos_torneo($liga, $id, (int)valor('SELECT COUNT(*) FROM equipos WHERE liga_id = ?', [$lid])); // (v12)
        q('INSERT INTO torneo_equipos (torneo_id, equipo_id) SELECT ?, id FROM equipos WHERE liga_id = ?', [$id, $lid]);
    }
    olimp_tras_crear_torneo($id); // (v37) creado desde un evento de olimpiadas: queda como disciplina
    flash('Campeonato creado.');
    ir('admin.php?p=torneo&id=' . $id);
}

$torneos = filas('SELECT t.*, (SELECT COUNT(*) FROM torneo_equipos te WHERE te.torneo_id = t.id) AS n_eq,
                         (SELECT COUNT(*) FROM partidos p WHERE p.torneo_id = t.id) AS n_part,
                         (SELECT COUNT(*) FROM partidos p WHERE p.torneo_id = t.id AND p.estado IN (\'jugado\',\'wo\')) AS n_jug
                  FROM torneos t WHERE t.liga_id = ? ORDER BY t.id DESC', [$lid]);
$estados = ['inscripcion' => 'Inscripción', 'sorteado' => 'Sorteado', 'en_juego' => 'En juego', 'finalizado' => 'Finalizado'];
$lim = limites_liga($liga);
$lleno = !es_super() && $lim['campeonatos'] > 0 && $lim['usados_campeonatos'] >= $lim['campeonatos'];
cabecera_admin('Campeonatos');
$n_ped = pedidos_pendientes($lid);
?>
<div class="fila-titulo"><h1>Campeonatos</h1>
  <div class="acciones"><a class="btn btn-sec btn-chico" href="admin.php?p=pedidos">📨 Pedidos de delegados<?= $n_ped ? ' <span class="etiqueta roja">' . $n_ped . '</span>' : '' ?></a> <a class="btn btn-sec btn-chico" href="admin.php?p=acumuladas">Σ Tablas acumuladas</a></div></div>
<div class="aviso <?= $lleno ? 'aviso-error' : 'aviso-info' ?>">🏆 <b><?= e(texto_limite_campeonatos($liga)) ?></b><?= $lleno ? '. ' . ($lim['modo'] === 'total' ? 'Pida a ' . e(ajuste('empresa')) . ' que amplíe su límite.' : 'Para crear otro, ponga uno como <b>Finalizado</b> o pida a ' . e(ajuste('empresa')) . ' que amplíe su límite.') : '' ?></div>
<?php if (!$torneos): ?><div class="tarjeta">Aún no hay campeonatos. Cree el primero abajo.</div><?php endif; ?>
<div class="rejilla">
  <?php foreach ($torneos as $t): ?>
    <?php if (es_individual($t)): // (v22/v25/v26) carreras, duelos, bolos y pruebas ?>
    <a class="tarjeta partido-link" href="<?= e(url_admin_individual($t)) ?>">
      <strong><?= e($t['nombre']) ?></strong> <span class="etiqueta"><?= e($estados[$t['estado']] ?? $t['estado']) ?></span>
      <p class="suave" style="margin:.4rem 0 0"><?= e($t['temporada']) ?> · <?= icono_individual($t) ?> <?= e(nombre_modalidad($t['modalidad'])) ?><br><?= e(resumen_individual($t)) ?></p>
    </a>
    <?php continue; endif; ?>
    <a class="tarjeta partido-link" href="admin.php?p=torneo&amp;id=<?= (int)$t['id'] ?>">
      <strong><?= e($t['nombre']) ?></strong> <span class="etiqueta"><?= e($estados[$t['estado']] ?? $t['estado']) ?></span><?= $t['categoria'] !== '' ? ' <span class="etiqueta amarilla">' . e($t['categoria']) . '</span>' : '' ?>
      <p class="suave" style="margin:.4rem 0 0"><?= e($t['temporada']) ?> · <?= icono_deporte(deporte_de($t)) ?> <?= e(nombre_modalidad($t['modalidad'])) ?><?= deporte_de($t) === 'ecuavoley' ? ' · a ' . (int)$t['sets_ganar'] . ' sets' : ' · ' . (int)$t['num_tiempos'] . '×' . (int)$t['minutos_tiempo'] . ' min' ?> · <?= (int)$t['num_arbitros'] ?> árbitro(s) · <?= $t['formato'] === 'grupos' ? (int)$t['num_grupos'] . ' grupos' : 'Todos contra todos' ?> · <?= (int)$t['vueltas'] === 2 ? 'ida y vuelta' : 'solo ida' ?><br>
      <?= (int)$t['n_eq'] ?> equipos · <?= (int)$t['n_jug'] ?>/<?= (int)$t['n_part'] ?> partidos jugados</p>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($torneos && !$lleno && puede('campeonatos', true)): ?>
<details class="tarjeta" style="margin-top:20px" id="copiar" <?= get('copiar') !== '' ? 'open' : '' ?>>
  <summary><b>📋 Copiar un campeonato para la nueva temporada</b> (mismas reglas y, si quiere, los mismos equipos)</summary>
  <form method="post" style="margin-top:10px">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="copiar">
    <div class="campos">
      <div class="campo-ancho"><label>Copiar de</label><select name="torneo_id" id="cp-origen" onchange="cpSugerir()">
        <?php foreach ($torneos as $t): if (es_individual($t)) continue; ?><option value="<?= (int)$t['id'] ?>" data-nombre="<?= e($t['nombre']) ?>" data-temp="<?= e(temporada_siguiente($t['temporada'])) ?>" <?= (int)get('copiar') === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['nombre'] . ' ' . $t['temporada']) ?> (<?= (int)$t['n_eq'] ?> equipos)</option><?php endforeach; ?>
      </select></div>
      <div><label>Nombre del nuevo *</label><input type="text" name="nombre" id="cp-nombre" required maxlength="80"></div>
      <div><label>Temporada</label><input type="text" name="temporada" id="cp-temp" maxlength="20"></div>
    </div>
    <label class="check" style="font-weight:400"><input type="checkbox" name="con_equipos" value="1" checked> Inscribir los mismos equipos</label>
    <label class="check" style="font-weight:400"><input type="checkbox" name="con_grupos" value="1"> Mantener los mismos grupos (si no, se sortean de nuevo)</label>
    <p class="ayuda">Se copian las reglas de juego, puntos, tarjetas, árbitros, dinero y categoría. No se copian partidos, resultados, sanciones ni el campeón. Las fechas de inscripción en línea quedan cerradas hasta que las ponga.</p>
    <button class="btn">Crear copia</button>
  </form>
  <script>
  function cpSugerir() {
    var o = document.getElementById('cp-origen').selectedOptions[0];
    document.getElementById('cp-nombre').value = o.getAttribute('data-nombre');
    document.getElementById('cp-temp').value = o.getAttribute('data-temp');
  }
  cpSugerir();
  </script>
</details>
<?php endif; ?>

<?php if ($lleno): ?>
<div class="tarjeta" style="margin-top:20px"><h2>Nuevo campeonato</h2><p>Llegó al máximo de campeonatos permitido. <?= e(texto_limite_campeonatos($liga)) ?>.</p></div>
<?php elseif (puede('campeonatos', true)):
    $liga_form = $mods_equipo && !in_array($liga['modalidad'], $mods_equipo, true) ? array_merge($liga, presets_modalidad()[$mods_equipo[0]] ?? [], ['modalidad' => $mods_equipo[0]]) : $liga;
    if ($mods_equipo): ?>
<div class="tarjeta" style="margin-top:20px">
  <h2>Nuevo campeonato <small class="suave">(<?= e(implode(', ', array_unique(array_map(fn($m) => nombre_deporte(deporte_de($m)), $mods_equipo)))) ?>)</small></h2>
  <p class="suave" style="margin-top:0"><?= e(texto_limite_campeonatos($liga)) ?>.</p>
  <form method="post">
    <?= csrf_campo() ?>
    <div class="campos">
      <div><label>Nombre *</label><input type="text" name="nombre" required maxlength="100" placeholder="Ej: Campeonato Apertura"></div>
      <div><label>Temporada</label><input type="text" name="temporada" maxlength="30" value="<?= date('Y') ?>"></div>
      <div><label>Formato</label>
        <select name="formato" onchange="document.getElementById('ng').style.display = this.value === 'grupos' ? '' : 'none'">
          <option value="liga">Todos contra todos (una sola tabla)</option>
          <option value="grupos">Por grupos (A, B, C...)</option>
        </select></div>
      <div id="ng" style="display:none"><label>Número de grupos</label><input type="number" name="num_grupos" min="2" max="16" value="2"></div>
      <div><label>Vueltas</label><select name="vueltas"><option value="1">Solo ida</option><option value="2">Ida y vuelta</option></select></div>
      <div><label class="check"><input type="checkbox" name="inscribir_todos" value="1" checked> Inscribir todos los equipos de la liga</label><div class="ayuda">No aplica si es un campeonato por categoría (se eligen los equipos después).</div></div>
    </div>
    <?php html_categoria([]); ?>
    <p class="suave">Las reglas vienen llenas con los valores por defecto de la liga. Cámbielas solo si este campeonato es distinto (otra modalidad, otra duración, otro número de árbitros, etc.).</p>
    <?php html_reglas($liga_form, 'Reglas de este campeonato'); ?>
    <button class="btn" type="submit">Crear campeonato</button>
  </form>
</div>
<?php endif; if ($mods_carrera): ?>
<div class="tarjeta" style="margin-top:20px">
  <h2>🏁 Nuevo campeonato de carreras <small class="suave">(automovilismo, ciclismo de ruta y de montaña)</small></h2>
  <p class="suave" style="margin-top:0">Sin equipos ni fixture: se inscriben pilotos o ciclistas por categoría (cilindrada y motor, o edad y rama), se cargan los tiempos de cada fecha con penalizaciones y el sistema arma la clasificación de cada fecha y la general. En <b>ruta</b> usa las reglas de la UCI (etapas, mismo tiempo, bonificaciones, puntos, montaña, mejor joven y equipos); en <b>MTB</b>, XCO por vueltas, descenso y enduro. Para pista y BMX use «campeonato por pruebas».</p>
  <form method="post">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="nuevo_carrera">
    <div class="campos">
      <div><label>Nombre *</label><input type="text" name="nombre" required maxlength="100" placeholder="Ej: Campeonato de Autocross"></div>
      <div><label>Temporada</label><input type="text" name="temporada" maxlength="30" value="<?= date('Y') ?>"></div>
      <div><label>Deporte</label><select name="modalidad"><?php foreach ($mods_carrera as $k => [$nm, $ic]): ?><option value="<?= $k ?>"><?= $ic ?> <?= e($nm) ?></option><?php endforeach; ?></select></div>
      <div><label>Clasificación general</label><select name="clasif"><option value="puntos">Por puntos en cada fecha</option><option value="tiempo">Por tiempo total (ej: vuelta ciclística)</option></select></div>
    </div>
    <p class="ayuda">Después puede cambiar mangas, penalización por cono, puntos por puesto y descartes en «Reglas y configuración».</p>
    <button class="btn" type="submit">Crear campeonato de carreras</button>
  </form>
</div>
<?php endif; if ($mods_duelo): ?>
<div class="tarjeta" style="margin-top:20px">
  <h2>🎾🥋🥊 Nuevo campeonato de duelos <small class="suave">(<?= e(mb_strtolower(implode(', ', array_map(fn($x) => preg_replace('/\s*\(.*\)$/', '', $x[0]), $mods_duelo)))) ?>)</small></h2>
  <p class="suave" style="margin-top:0">Uno contra uno (o parejas): se inscriben los competidores por categoría (peso, edad, rama o cinturón), el sistema sortea la <b>llave</b> (con cabezas de serie y pases directos) o el <b>todos contra todos</b>, se cargan los resultados y arma solo el cuadro, la tabla y el <b>medallero</b> por categoría y por club.</p>
  <form method="post">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="nuevo_duelo">
    <div class="campos">
      <div><label>Nombre *</label><input type="text" name="nombre" required maxlength="100" placeholder="Ej: Abierto de Tenis Quito"></div>
      <div><label>Temporada</label><input type="text" name="temporada" maxlength="30" value="<?= date('Y') ?>"></div>
      <div><label>Deporte</label><select name="modalidad"><?php foreach ($mods_duelo as $k => [$nm, $ic]): ?><option value="<?= $k ?>"><?= $ic ?> <?= e($nm) ?></option><?php endforeach; ?></select></div>
    </div>
    <p class="ayuda">Después puede cambiar sets o rounds, el tercer puesto y los puntos del todos contra todos en «Reglas y configuración».</p>
    <button class="btn" type="submit">Crear campeonato de duelos</button>
  </form>
</div>
<?php endif; if ($con_bolos): ?>
<div class="tarjeta" style="margin-top:20px">
  <h2>🎳 Nuevo campeonato de bolos</h2>
  <p class="suave" style="margin-top:0">Por jornadas: en cada una se cargan los pinos de cada línea. El sistema suma, aplica el handicap y arma la clasificación de la jornada, la general, la de clubes y los récords (línea y serie más altas).</p>
  <form method="post">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="nuevo_bolos">
    <div class="campos">
      <div><label>Nombre *</label><input type="text" name="nombre" required maxlength="100" placeholder="Ej: Liga de Bolos Quito"></div>
      <div><label>Temporada</label><input type="text" name="temporada" maxlength="30" value="<?= date('Y') ?>"></div>
      <div><label>Líneas por jornada</label><input type="number" name="lineas" value="3" min="1" max="10"></div>
      <div><label>Handicap</label><select name="hcp"><?php foreach (bolos_textos_hcp() as $k => $x): ?><option value="<?= $k ?>"><?= e($x) ?></option><?php endforeach; ?></select></div>
      <div><label>Clasificación general</label><select name="clasif"><?php foreach (bolos_textos_clasif() as $k => $x): ?><option value="<?= $k ?>"><?= e($x) ?></option><?php endforeach; ?></select></div>
    </div>
    <button class="btn" type="submit">Crear campeonato de bolos</button>
  </form>
</div>
<?php endif; if ($mods_pruebas): ?>
<div class="tarjeta" style="margin-top:20px">
  <h2>🏊🏃🚴 Nuevo campeonato por pruebas <small class="suave">(<?= e(mb_strtolower(implode(', ', array_map(fn($x) => $x[0], $mods_pruebas)))) ?>)</small></h2>
  <p class="suave" style="margin-top:0">Por pruebas (50 m libre, 100 m planos, salto largo, velocidad, keirin, omnium, BMX, park...) separadas por categoría de edad y rama. Se cargan los tiempos, distancias, puestos o notas y el sistema arma los resultados de cada prueba con las reglas de cada deporte (en ciclismo, las de la UCI), el <b>medallero</b>, los <b>puntos por club</b> y los ganadores.</p>
  <form method="post">
    <?= csrf_campo() ?><input type="hidden" name="accion" value="nuevo_pruebas">
    <div class="campos">
      <div><label>Nombre *</label><input type="text" name="nombre" required maxlength="100" placeholder="Ej: Festival de Natación Quito"></div>
      <div><label>Temporada</label><input type="text" name="temporada" maxlength="30" value="<?= date('Y') ?>"></div>
      <div><label>Deporte</label><select name="modalidad"><?php foreach ($mods_pruebas as $k => [$nm, $ic]): ?><option value="<?= $k ?>"><?= $ic ?> <?= e($nm) ?></option><?php endforeach; ?></select></div>
    </div>
    <button class="btn" type="submit">Crear campeonato por pruebas</button>
  </form>
</div>
<?php endif; endif;
echo html_modulos_bloqueados($liga); // (v35)
pie_admin();
