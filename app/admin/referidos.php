<?php
/*
 * (v28) RECOMIENDE Y GANE: la liga o escuela comparte su enlace; cuando la recomendada contrata, gana meses gratis.
 */
$liga = requiere_liga();
$demo = !empty($liga['es_demo']);
$meses = meses_premio_referido();
$lista = filas('SELECT r.*, l.nombre, l.tipo, l.es_prueba, l.vence FROM referidos r JOIN ligas l ON l.id = r.referido_id WHERE r.liga_id = ? ORDER BY r.id DESC', [$liga['id']]);
$ganados = array_sum(array_map(fn($r) => (int)$r['meses'], $lista));
cabecera_admin('Recomiende y gane');
?>
<h1>🤝 Recomiende y gane</h1>
<div class="tarjeta" style="border:2px solid var(--acento)">
  <p style="font-size:1.1rem;margin-top:0">¿Conoce otra <?= es_academia() ? 'academia' : 'liga, escuela o federación' ?> que necesite ordenarse? Recomiéndenos: <b>cuando contrate <?= e(marca()) ?>, usted gana <?= $meses ?> mes<?= $meses === 1 ? '' : 'es' ?> gratis</b> en su plan. Sin límite: mientras más recomiende, más meses gana.</p>
  <?php if ($demo): ?>
    <div class="aviso aviso-info">En la demostración no se generan enlaces de recomendación. Cuando tenga su cuenta, aquí estará su enlace.</div>
  <?php else: $lnk_l = enlace_referido($liga); $lnk_e = enlace_referido($liga, 'escuela');
      $msg = 'Hola, yo uso ' . marca() . ' para ' . $liga['nombre'] . (es_academia() ? ' y me ha servido mucho: alumnos, asistencia, pensiones, portal de padres y más. Pida su demo gratis aquí: ' : ' y me ha servido mucho: fixture, vocalía en el celular, carnets con QR, pagos y más. Pida su demo gratis aquí: '); ?>
    <?php if (!es_academia()): // (arreglos 14-oct) en TuAcademia solo el enlace para academias ?>
    <label>Su enlace para ligas</label>
    <div class="acciones"><input readonly value="<?= e($lnk_l) ?>" onclick="this.select()" style="flex:1;min-width:240px"><button type="button" class="btn btn-sec btn-chico" onclick="navigator.clipboard && navigator.clipboard.writeText('<?= e($lnk_l) ?>'); this.textContent='✓ copiado'">Copiar</button>
      <a class="btn btn-chico" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode($msg . $lnk_l) ?>">💬 Enviar por WhatsApp</a></div>
    <?php endif; ?>
    <label style="margin-top:10px"><?= es_academia() ? 'Su enlace para recomendar' : 'Su enlace para escuelas deportivas' ?></label>
    <div class="acciones"><input readonly value="<?= e($lnk_e) ?>" onclick="this.select()" style="flex:1;min-width:240px"><button type="button" class="btn btn-sec btn-chico" onclick="navigator.clipboard && navigator.clipboard.writeText('<?= e($lnk_e) ?>'); this.textContent='✓ copiado'">Copiar</button>
      <a class="btn btn-chico" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode($msg . $lnk_e) ?>">💬 Enviar por WhatsApp</a></div>
    <p class="suave" style="margin-bottom:0">Su código: <b><?= e(codigo_referido($liga)) ?></b>. Quien entra por su enlace queda anotado como recomendado por usted.</p>
  <?php endif; ?>
</div>
<div class="cifras">
  <div class="cifra"><b><?= count($lista) ?></b>Recomendados</div>
  <div class="cifra"><b><?= count(array_filter($lista, fn($r) => $r['estado'] === 'premiado')) ?></b>Ya contrataron</div>
  <div class="cifra"><b><?= $ganados ?></b>Meses gratis ganados</div>
</div>
<?php if ($lista): ?>
  <div class="tabla-scroll"><table><tr><th>Cuenta recomendada</th><th>Estado</th><th>Premio</th></tr>
    <?php foreach ($lista as $r): ?><tr><td><?= e($r['nombre']) ?> <small class="suave">(<?= ($r['tipo'] ?? 'liga') === 'escuela' ? (es_academia() ? 'academia' : 'escuela') : 'liga' ?>)</small></td>
      <td><?= $r['estado'] === 'premiado' ? '<span class="estado-chip chip-ok">Contrató</span>' : ($r['es_prueba'] ? '<span class="estado-chip chip-pend">En demo</span>' : '<span class="estado-chip">Pendiente</span>') ?></td>
      <td><?= $r['estado'] === 'premiado' ? '🎁 ' . (int)$r['meses'] . ' mes(es) · ' . e(fecha_bonita(substr($r['premiado_en'], 0, 10))) : '—' ?></td></tr><?php endforeach; ?></table></div>
<?php endif;
pie_admin();
