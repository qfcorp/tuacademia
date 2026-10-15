<?php
/* VER PORTALES (solo superadministrador): abrir cada panel o portal de una liga o escuela tal como lo ve cada persona.
   «Solo mirar» en todas; «Probar (simular)», que sí guarda, solo en las de demostración. */
if (!es_super()) ir('admin.php');

if (es_post()) {
    ir(iniciar_vista_previa(post('tipo'), (int)post('id'), post('modo') === 'probar'));
}

$ligas = filas("SELECT id, nombre, es_demo, COALESCE(tipo, 'liga') AS tipo FROM ligas WHERE COALESCE(eliminada, '') = '' ORDER BY CASE WHEN COALESCE(tipo, 'liga') = 'escuela' THEN 1 ELSE 0 END, nombre");
$lid = (int)get('liga', (string)(liga_trabajo()['id'] ?? 0));
if (!in_array($lid, array_map('intval', array_column($ligas, 'id')), true)) $lid = (int)($ligas[0]['id'] ?? 0);
$es_demo = (int)(array_column($ligas, 'es_demo', 'id')[$lid] ?? 0) === 1;
$escuela = (array_column($ligas, 'tipo', 'id')[$lid] ?? 'liga') === 'escuela';
$org = $escuela ? 'escuela' : 'liga';

$tipos = tipos_vista_previa();
$formulario = function (string $tipo, string $opciones, string $vacio, string $titulo = '') use ($tipos, $es_demo): string {
    [$tit, $ico] = $tipos[$tipo];
    $h = '<div class="tarjeta"><h2 style="margin-top:0">' . e($titulo !== '' ? $titulo : $ico . ' ' . $tit) . '</h2>';
    if ($opciones === '') return $h . '<p class="suave">' . e($vacio) . '</p></div>';
    return $h . '<form method="post" action="admin.php?p=portales">' . csrf_campo() . '<input type="hidden" name="tipo" value="' . e($tipo) . '">'
        . '<p><select name="id" required style="width:100%">' . $opciones . '</select></p>'
        . '<div class="acciones"><button class="btn" name="modo" value="mirar">👀 Solo mirar</button>'
        . ($es_demo ? '<button class="btn btn-sec" name="modo" value="probar" title="Sí guarda: la demo se borra cada madrugada">🧪 Probar (simular)</button>' : '') . '</div></form></div>';
};
$op = fn(array $x, string $txt) => '<option value="' . (int)$x['id'] . '">' . e($txt) . '</option>';
/** Opciones agrupadas (optgroup) por la columna $col. */
$agrupadas = function (array $filas, string $col, callable $txt) use ($op): string {
    $h = '';
    $grupo = null;
    foreach ($filas as $f) {
        if ($f[$col] !== $grupo) {
            $h .= ($grupo !== null ? '</optgroup>' : '') . '<optgroup label="' . e((string)$f[$col]) . '">';
            $grupo = $f[$col];
        }
        $h .= $op($f, $txt($f));
    }
    return $grupo !== null ? $h . '</optgroup>' : $h;
};

// Usuarios del panel (en una escuela: dueño, entrenadores y demás perfiles)
$usuarios = filas("SELECT id, nombre, email, perfil FROM usuarios WHERE liga_id = ? AND rol = 'liga' AND activo = 1 ORDER BY CASE WHEN COALESCE(perfil, 'admin') = 'admin' THEN 0 ELSE 1 END, nombre", [$lid]);
$de_perfil = fn(bool $prof) => implode('', array_map(fn($d) => $op($d, $d['nombre'] . ' · ' . nombre_perfil((string)($d['perfil'] ?? 'admin')) . ' · ' . $d['email']),
    array_filter($usuarios, fn($d) => (($d['perfil'] ?? 'admin') === 'profesor') === $prof)));

if ($escuela) {
    $alumnos = filas("SELECT a.id, a.nombres, a.apellidos, a.representante_nombre, a.representante_cedula, COALESCE(g.nombre, 'Sin grupo') AS grupo FROM esc_alumnos a
                      LEFT JOIN esc_grupos g ON g.id = a.grupo_id WHERE a.liga_id = ? AND a.estado <> 'retirado' ORDER BY grupo, a.apellidos, a.nombres LIMIT 3000", [$lid]);
    $op_fam = $agrupadas(array_values(array_filter($alumnos, fn($a) => $a['representante_cedula'] !== '')), 'grupo',
        fn($a) => nombre_alumno($a) . ' — familia de ' . ($a['representante_nombre'] !== '' ? $a['representante_nombre'] : 'C.I. ' . $a['representante_cedula']));
    $op_car = $agrupadas($alumnos, 'grupo', fn($a) => nombre_alumno($a));
} else {
    $arbitros = filas("SELECT a.id, a.nombre, a.categoria FROM arbitros a JOIN usuarios u ON u.id = a.usuario_id AND u.rol = 'arbitro' AND u.activo = 1 WHERE a.liga_id = ? AND a.activo = 1 ORDER BY a.nombre", [$lid]);
    $delegados = filas("SELECT u.id, u.nombre, e.nombre AS equipo FROM usuarios u JOIN equipos e ON e.id = u.equipo_id WHERE e.liga_id = ? AND u.rol = 'delegado' AND u.activo = 1 ORDER BY e.nombre, u.nombre", [$lid]);
    $jugadores = filas('SELECT j.id, j.nombres, j.apellidos, j.numero, e.nombre AS equipo FROM jugadores j JOIN equipos e ON e.id = j.equipo_id
                        WHERE e.liga_id = ? AND j.activo = 1 AND j.anonimizado = 0 ORDER BY e.nombre, j.apellidos, j.nombres LIMIT 3000', [$lid]);
    $partidos = filas("SELECT p.id, p.fecha, p.hora, p.estado, l.nombre AS local, v.nombre AS visita, t.nombre AS torneo FROM partidos p JOIN torneos t ON t.id = p.torneo_id
                       JOIN equipos l ON l.id = p.local_id JOIN equipos v ON v.id = p.visitante_id WHERE t.liga_id = ?
                       ORDER BY CASE p.estado WHEN 'en_vivo' THEN 0 WHEN 'programado' THEN 1 ELSE 2 END, CASE WHEN p.estado = 'programado' THEN p.fecha END ASC, p.fecha DESC, p.hora LIMIT 80", [$lid]);
    $op_arb = implode('', array_map(fn($a) => $op($a, $a['nombre'] . ($a['categoria'] !== '' ? ' · ' . $a['categoria'] : '')), $arbitros));
    $op_del = implode('', array_map(fn($d) => $op($d, $d['equipo'] . ' — ' . $d['nombre']), $delegados));
    $op_jug = $agrupadas($jugadores, 'equipo', fn($j) => ($j['numero'] !== null ? '#' . $j['numero'] . ' ' : '') . nombre_jugador($j));
    $op_par = implode('', array_map(fn($p) => $op($p, ($p['estado'] === 'en_vivo' ? '● EN VIVO · ' : '') . $p['local'] . ' vs ' . $p['visita'] . ' · ' . ($p['fecha'] !== '' ? $p['fecha'] : 'sin fecha') . ($p['hora'] !== '' ? ' ' . $p['hora'] : '') . ' · ' . $p['torneo']), $partidos));
}

cabecera_admin('Ver portales');
?>
<h1>👀 Ver portales</h1>
<p class="suave">Abra cada panel o portal de una liga o escuela <b>tal como lo ve esa persona</b>. Con <b>«Solo mirar»</b> no se guarda ningún cambio. Para volver, use <b>«✖ Salir y volver a mi panel»</b> en la cinta de arriba.</p>
<?php if (!$ligas): ?>
  <p class="suave">Todavía no hay ligas ni escuelas creadas.</p>
<?php else: ?>
<form method="get" class="acciones"><input type="hidden" name="p" value="portales">
  <label>Liga o escuela: <select name="liga" onchange="this.form.submit()">
    <?php foreach (['liga' => '🏆 Ligas', 'escuela' => '🎓 Escuelas y academias'] as $t => $etq): $del_tipo = array_filter($ligas, fn($l) => ($l['tipo'] === 'escuela') === ($t === 'escuela')); if (!$del_tipo) continue; ?>
      <optgroup label="<?= e($etq) ?>"><?php foreach ($del_tipo as $l): ?><option value="<?= (int)$l['id'] ?>"<?= (int)$l['id'] === $lid ? ' selected' : '' ?>><?= e($l['nombre']) ?><?= (int)$l['es_demo'] === 1 ? ' (demostración)' : '' ?></option><?php endforeach; ?></optgroup>
    <?php endforeach; ?>
  </select></label>
</form>
<?php if ($es_demo): ?>
  <p class="aviso aviso-info" style="margin-top:10px">🧪 Esta es una <b><?= $org ?> de demostración</b>: además de «Solo mirar» puede usar <b>«Probar (simular)»</b>, que sí guarda los cambios<?= $escuela ? ' (tomar asistencia, registrar pagos, evaluar alumnos...)' : ' (iniciar la vocalía, marcar goles y tarjetas, llenar el informe arbitral, inscribir jugadores...)' ?>. Todo se borra cada madrugada.</p>
<?php else: ?>
  <p class="suave" style="margin-top:10px">Para simular de verdad elija la <b>liga o escuela de demostración</b>: ahí aparece el botón «🧪 Probar (simular)». En las reales solo se puede mirar, para no tocar sus datos.</p>
<?php endif; ?>
<div class="rejilla-2" style="margin-top:12px">
<?php if ($escuela): ?>
  <?= $formulario('directivo', $de_perfil(false), 'Esta escuela no tiene usuarios activos del panel.', '🏫 Panel de la escuela (dueño y administración)') ?>
  <?= $formulario('directivo', $de_perfil(true), 'Ningún entrenador tiene usuario. Créelo en Profesores → acceso del profesor.', '🧑‍🏫 Panel del entrenador (profesor)') ?>
  <?= $formulario('familia', $op_fam, 'Todavía no hay alumnos con la cédula de su representante.') ?>
  <?= $formulario('carnet', $op_car, 'Todavía no hay alumnos.') ?>
<?php else: ?>
  <?= $formulario('directivo', $de_perfil(false) . $de_perfil(true), 'Esta liga no tiene usuarios de directivos activos.') ?>
  <?= $formulario('arbitro', $op_arb, 'Esta liga no tiene árbitros con usuario. Créelos en Árbitros (con correo y contraseña) para poder ver su portal.') ?>
  <?= $formulario('vocal', $op_par, 'Esta liga todavía no tiene partidos.') ?>
  <?= $formulario('jugador', $op_jug, 'Esta liga todavía no tiene jugadores.') ?>
  <?= $formulario('delegado', $op_del, 'Ningún club de esta liga tiene usuario de delegado. Créelo en Equipos → el equipo → acceso del delegado.') ?>
<?php endif; ?>
</div>
<?php endif;
pie_admin();
