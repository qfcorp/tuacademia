<?php
/*
 * (v31) VERIFICACIÓN DE CERTIFICADO DE GRADO (lo que se abre al escanear el QR del certificado)
 *  certificado.php?c=CÓDIGO  → dice si el certificado es VÁLIDO: nombre, cinturón, fecha del examen, examinador y escuela.
 *  Sin código muestra un cuadro para escribirlo.
 */
require __DIR__ . '/../app/bootstrap.php';

$c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', get('c')));
if ($c === '') {
    cabecera_publica('Verificar certificado de grado');
    ?>
<div class="tarjeta" style="max-width:520px;margin:auto;text-align:center">
  <h1>🥋 Verificar certificado de grado</h1>
  <p>Escriba el código que aparece debajo del QR del certificado.</p>
  <form method="get" class="acciones" style="justify-content:center"><input type="text" name="c" maxlength="20" required placeholder="Ej: K7M2Q9XR4TPA" style="text-transform:uppercase;max-width:240px"> <button class="btn">Verificar</button></form>
</div>
<?php
    pie_publico();
    exit;
}

$i = strlen($c) === 12 ? fila("SELECT i.*, x.fecha, x.lugar, x.examinador, x.disciplina, x.liga_id AS lid, a.nombres, a.apellidos, a.cedula, a.foto, a.liga_id, a.id AS aid
                                 FROM esc_examen_inscritos i JOIN esc_examenes x ON x.id = i.examen_id JOIN esc_alumnos a ON a.id = i.alumno_id
                                 WHERE i.certificado = ? AND i.resultado = 'aprobado' AND x.estado = 'cerrado'", [$c]) : null;
$esc = $i ? fila('SELECT * FROM ligas WHERE id = ?', [$i['lid']]) : null;
if (!$i || !$esc) {
    http_response_code(404);
    cabecera_publica('Certificado no válido');
    echo '<div class="tarjeta" style="text-align:center;max-width:560px;margin:auto"><h1 style="color:var(--roja)">✗ CERTIFICADO NO VÁLIDO</h1><p>El código <b>' . e($c) . '</b> no corresponde a ningún certificado de grado, o la escuela lo anuló.</p>'
        . '<p><a class="btn btn-sec" href="certificado.php">Probar con otro código</a></p></div>';
    pie_publico();
    exit;
}
$cint = $i['cinturon_id'] ? fila('SELECT * FROM esc_cinturones WHERE id = ?', [$i['cinturon_id']]) : null;
cabecera_publica('Certificado · ' . nombre_alumno($i), $esc);
?>
<div class="carnet-verif tarjeta" style="border-top:10px solid #1a9e5c">
  <div class="estado-grande" style="background:#1a9e5c">✓ CERTIFICADO VÁLIDO</div>
  <div class="carnet-foto"><?= foto_alumno(['id' => $i['aid'], 'foto' => $i['foto'], 'nombres' => $i['nombres'], 'apellidos' => $i['apellidos'], 'liga_id' => $i['liga_id']], 'foto-verif') ?></div>
  <h1 style="text-align:center;margin:.4rem 0"><?= e(nombre_alumno($i)) ?></h1>
  <?php if ($i['cedula'] !== ''): ?><p style="text-align:center;margin:0" class="suave">C.I. <?= e(cedula_oculta($i['cedula'])) ?></p><?php endif; ?>
  <p style="text-align:center;font-size:1.15rem;margin:.8rem 0"><?= html_cinturon($cint, $i['grado_aspira']) ?></p>
  <table class="tabla-verif" style="margin:auto">
    <tr><th>Disciplina</th><td><?= e(disciplinas_marciales()[$i['disciplina']] ?? '') ?></td></tr>
    <tr><th>Escuela</th><td><?= e($esc['nombre']) ?><?= $esc['ciudad'] !== '' ? ' · ' . e($esc['ciudad']) : '' ?></td></tr>
    <tr><th>Fecha del examen</th><td><?= e(fecha_larga($i['fecha'])) ?></td></tr>
    <?php if ($i['lugar'] !== ''): ?><tr><th>Lugar</th><td><?= e($i['lugar']) ?></td></tr><?php endif; ?>
    <?php if ($i['examinador'] !== ''): ?><tr><th>Examinador</th><td><?= e($i['examinador']) ?></td></tr><?php endif; ?>
    <tr><th>Código</th><td><code><?= e($c) ?></code></td></tr>
  </table>
  <p class="suave" style="text-align:center;font-size:.8rem;margin-top:12px">Verificación en línea con los datos de la escuela. Si algún dato del papel no coincide con esta página, el certificado fue alterado.</p>
</div>
<?php
pie_publico();
