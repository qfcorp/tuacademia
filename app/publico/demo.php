<?php
/* (v10/v15) DEMOSTRACIÓN: una liga y una escuela de ejemplo; se entra con un clic a cada panel. Se reinician cada noche. */
$demo = liga_demo();
$edemo = escuela_demo();
if (es_post()) {
    csrf_verificar();
    if (demasiados_intentos('demo', 60, '-1 hour')) {
        flash('Demasiadas entradas seguidas desde su conexión. Intente en un rato.', 'error');
        ir('index.php?v=demo');
    }
    anotar_intento('demo');
    try {
        ir(entrar_demo(post('rol', 'admin')));
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
        ir('index.php?v=demo');
    }
}
$wa = telefono_wa((string)ajuste('ventas_whatsapp'));
$boton = function (string $rol, array $a): string {
    return '<form method="post" action="index.php?v=demo" class="demo-acceso">' . csrf_campo() . '<input type="hidden" name="rol" value="' . e($rol) . '">'
        . '<button><span class="demo-ico">' . $a[2] . '</span><b>' . e($a[0]) . '</b><small>' . e($a[1]) . '</small></button></form>';
};
cabecera_publica('Demostración · ' . marca());
foreach (tomar_flash() as [$tipo_f, $msg_f]) echo '<div class="aviso aviso-' . e($tipo_f) . '">' . e($msg_f) . '</div>';
?>
<section class="hero">
  <h1>Conozca <?= e(marca()) ?> por dentro</h1>
  <p>Entre con un clic a cada panel, como si fuera el administrador, el árbitro, el delegado, el vocal o el jugador. <?= demo_guardar() ? 'Puede probar y guardar lo que quiera:' : 'Puede recorrer todo:' ?> los datos vuelven a estar como nuevos cada madrugada.</p>
</section>

<?php if (!$demo && !$edemo): ?>
  <p class="hero-nota" style="text-align:center">La demostración no está disponible en este momento.<?= $wa ? ' Escríbanos y se la mostramos.' : '' ?></p>
<?php endif; ?>

<?php if ($demo): ?>
<section style="margin-top:18px">
  <div class="fila-titulo"><h2 style="margin:0">🏆 Liga de demostración</h2><a class="btn btn-sec" href="<?= e(url_liga($demo)) ?>" target="_blank">⚽ Ver su página pública ↗</a></div>
  <p class="suave">Campeonatos Apertura (terminado, con campeón) y Clausura (en juego), 8 equipos, resultados, goleadores, sanciones, boletín y tabla acumulada. Hay un partido <b>hoy</b> listo para la vocalía en vivo.</p>
  <div class="demo-accesos"><?php foreach (accesos_demo_liga() as $rol => $a) echo $boton($rol, $a); ?></div>
</section>
<?php
// (v24) Deportes individuales de la liga demo (carreras y, desde v25, duelos y bolos): enlaces directos a su página pública
$indiv = array_values(array_filter(filas('SELECT * FROM torneos WHERE liga_id = ? ORDER BY id', [(int)$demo['id']]),
    fn($x) => es_individual($x)));
if ($indiv): ?>
<section style="margin-top:22px">
  <h2 style="margin:0">🏁 Deportes individuales</h2>
  <p class="suave">Carreras, deportes de raqueta, artes marciales, boxeo, bolos, natación y atletismo: por tiempo, por puntos, por llaves o por pinos, con categorías y medallero. Toque uno para ver su página pública; para verlos por dentro entre como <b>Administrador de la liga</b> → <b>Campeonatos</b>.</p>
  <div class="demo-accesos">
  <?php foreach ($indiv as $ti):
      $info = [nombre_modalidad($ti['modalidad']), icono_individual($ti)];
      $desc = match ($ti['modalidad']) {
          'AUTOX' => 'Puntos por fecha, categorías por cilindrada, mangas y penalizaciones.',
          'CICLO' => 'Reglas UCI: vuelta por etapas, mismo tiempo, bonificaciones, puntos, montaña, mejor joven y equipos.',
          'MTB' => 'XCO por vueltas (regla del 80 %), descenso con la mejor bajada y enduro por tramos.',
          'PISTA' => 'Velocidad, keirin, persecución, scratch, puntos, eliminación y omnium con las reglas UCI.',
          'BMX' => 'Motos de 8, tres rondas con puntos por puesto, semifinales y final.',
          'BMXFS' => 'Park y flatland: jueces de 0 a 100, promedio en la clasificatoria y mejor pasada en la final.',
          'TENIS', 'PADEL', 'TMESA' => 'Llave con cabezas de serie, marcador por sets y todos contra todos.',
          'SQUASH' => 'Games a 11: grupos y luego llave, Monrad con todos los puestos, escalera del club y ranking.',
          'AJED' => 'Sistema suizo con Buchholz, ELO y todos contra todos.',
          'ESPOR' => 'eFootball 1 vs 1: grupos, llave con penales y tercer puesto.',
          'RUTA' => '10K con tiempo de chip, grupos de edad, equipos, buscador por dorsal y diplomas.',
          'PESAS' => 'Arranque y envión con 3 intentos, total y peso corporal (IWF).',
          'TRIAT' => 'Natación, transiciones, ciclismo y carrera, con grupos de edad.',
          'MARC' => 'Categorías por edad, peso y cinturón; llave con dos bronces y medallero por club.',
          'BOXEO' => 'Categorías por peso, KO/TKO y decisión por round, medallero.',
          'BOLOS' => 'Jornadas con líneas, handicap automático, récords y clasificación por clubes.',
          'NATAC' => 'Pruebas por edad y rama, tiempos al centésimo, medallero y puntos por club. Inscripción en línea abierta.',
          'ATLET' => 'Series y final con viento, concursos con intentos, salto alto por alturas, heptatlón y decatlón.',
          default => 'Llaves, tabla y medallero por categoría.',
      }; ?>
    <div class="demo-acceso"><a class="demo-link" href="<?= e(url_torneo($demo, $ti)) ?>" target="_blank"><span class="demo-ico"><?= $info[1] ?></span><b><?= e($ti['nombre']) ?></b><small><?= e($info[0]) ?> · <?= e($desc) ?></small></a></div>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<?php endif; ?>

<?php if ($edemo): ?>
<section style="margin-top:22px">
  <div class="fila-titulo"><h2 style="margin:0">🎓 Escuela de demostración</h2><a class="btn btn-sec" href="<?= e(url_liga($edemo)) ?>" target="_blank">Ver su página pública e inscripción ↗</a></div>
  <p class="suave">Dos sedes, seis grupos (Sub-7 a Sub-15 y categoría abierta), 42 alumnos, asistencia de las últimas semanas, mensualidades con vencimiento, evaluaciones físicas y técnicas, libreta, tienda y pagos a los entrenadores.</p>
  <div class="demo-accesos"><?php foreach (accesos_demo_escuela() as $rol => $a) echo $boton($rol, $a); ?></div>
</section>
<?php endif; ?>

<?php if ($demo || $edemo): ?>
<p class="suave" style="text-align:center;margin-top:14px">En los portales de jugador y de familia, si le piden clave, es <b><?= DEMO_CLAVE ?></b>. Para salir de un panel y entrar a otro, vuelva a esta página.</p>
<?php endif; ?>

<section class="cta-final">
  <h2>¿Le gustó?</h2>
  <div class="hero-botones">
    <a class="btn btn-grande btn-blanco" href="<?= e(url_empezar()) ?>"><?= prueba_automatica() ? '🎁 Cree su liga gratis' : '📝 Solicitar mi demo' ?></a>
    <?php if ($wa): ?><a class="btn btn-grande btn-wa" target="_blank" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola, vi la demostración de ' . marca() . ' y quiero información') ?>">💬 WhatsApp</a><?php endif; ?>
  </div>
</section>
<?php
pie_publico();
