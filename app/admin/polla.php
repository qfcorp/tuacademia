<?php
/* (v7) Polla de pronósticos de la liga (gratis, sin dinero, con premios de patrocinadores) */
$liga = requiere_liga();
$lid = (int)$liga['id'];

if (es_post()) {
    $accion = post('accion');
    if ($accion === 'config') {
        q('UPDATE ligas SET polla_activa = ?, polla_exacto = ?, polla_resultado = ?, polla_premio = ? WHERE id = ?', [
            post('polla_activa') === '1' ? 1 : 0, entero(post('polla_exacto'), 1, 20), entero(post('polla_resultado'), 0, 20), mb_substr(post('polla_premio'), 0, 300), $lid,
        ]);
        flash('Polla guardada.' . (post('polla_activa') === '1' ? ' Ya aparece la pestaña 🎯 Polla en la página pública.' : ' La polla está apagada.'));
    } elseif ($accion === 'quitar') {
        $pp = fila('SELECT * FROM polla_participantes WHERE id = ? AND liga_id = ?', [(int)post('id'), $lid]) ?? throw new RuntimeException('Participante no encontrado.');
        q('UPDATE polla_participantes SET activo = 1 - activo WHERE id = ?', [$pp['id']]);
        bitacora_evento('polla', ($pp['activo'] ? 'Quitó' : 'Volvió a activar') . ' al participante de la polla ' . $pp['nombre'], $lid, (int)$pp['id']);
        flash('Participante ' . ($pp['activo'] ? 'quitado de la tabla' : 'activado') . '.');
    } elseif ($accion === 'reiniciar') {
        if (post('confirmar') !== 'REINICIAR') throw new RuntimeException('Escriba REINICIAR para confirmar.');
        q('DELETE FROM polla_pronosticos WHERE participante_id IN (SELECT id FROM polla_participantes WHERE liga_id = ?)', [$lid]);
        bitacora_evento('polla', 'Reinició la polla (borró todos los pronósticos)', $lid);
        flash('Polla reiniciada: se borraron todos los pronósticos. Los participantes siguen registrados.');
    }
    ir('admin.php?p=polla');
}

$liga = fila('SELECT * FROM ligas WHERE id = ?', [$lid]);
$ranking = polla_ranking($liga, 200);
$participantes = filas('SELECT pp.*, (SELECT COUNT(*) FROM polla_pronosticos x WHERE x.participante_id = pp.id) AS n FROM polla_participantes pp WHERE pp.liga_id = ? ORDER BY pp.activo DESC, pp.creado DESC', [$lid]);
$enlace_pub = url_liga($liga) . '?v=polla';
cabecera_admin('Polla');
subnav_publico('polla');
?>
<h1>🎯 Polla de pronósticos</h1>
<p class="suave">Los hinchas adivinan los resultados de los próximos partidos desde la página de la liga y suman puntos. <b>Sin costo y sin apuestas</b>: el premio lo pone un patrocinador (camiseta, cena, recarga…). Hace que la gente entre a la página todas las semanas, y eso le da más vistas a sus patrocinadores.</p>

<div class="tarjeta">
  <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="config">
    <label class="check"><input type="checkbox" name="polla_activa" value="1" <?= $liga['polla_activa'] ? 'checked' : '' ?> <?= puede('comunicacion', true) ? '' : 'disabled' ?>> <b>Polla activa</b> (aparece la pestaña 🎯 Polla en la página pública)</label>
    <div class="campos" style="margin-top:10px">
      <div><label>Puntos por marcador exacto</label><input type="number" name="polla_exacto" min="1" max="20" value="<?= (int)$liga['polla_exacto'] ?>"></div>
      <div><label>Puntos por acertar solo el ganador o el empate</label><input type="number" name="polla_resultado" min="0" max="20" value="<?= (int)$liga['polla_resultado'] ?>"></div>
      <div class="campo-ancho"><label>Premio (opcional)</label><input type="text" name="polla_premio" maxlength="300" value="<?= e($liga['polla_premio']) ?>" placeholder="Ej: El primero de la tabla al final del campeonato gana una camiseta oficial, cortesía de Deportes Andrés"></div>
    </div>
    <?php if (puede('comunicacion', true)): ?><button class="btn">Guardar</button><?php endif; ?>
  </form>
  <?php if ($liga['polla_activa']): ?><p style="margin-bottom:0">Enlace para compartir: <a href="<?= e($enlace_pub) ?>" target="_blank"><?= e($enlace_pub) ?></a> · <a target="_blank" href="https://wa.me/?text=<?= rawurlencode('🎯 ¡Juega sin costo la polla de ' . $liga['nombre'] . '! Adivina los resultados y gana premios 👉 ' . $enlace_pub) ?>">📲 Compartir por WhatsApp</a></p><?php endif; ?>
</div>

<div class="rejilla-2">
  <div>
    <h2>🏅 Tabla</h2>
    <?php if (!$ranking): ?><p class="suave">Aparece cuando se juegue el primer partido pronosticado.</p><?php else: ?>
    <div class="tabla-scroll"><table>
      <tr><th>#</th><th>Participante</th><th class="num">Exactos</th><th class="num">Aciertos</th><th class="num">Pts</th></tr>
      <?php foreach ($ranking as $i => $r): ?><tr><td><?= $i + 1 ?></td><td><?= e($r['nombre']) ?></td><td class="num"><?= $r['exactos'] ?></td><td class="num"><?= $r['aciertos'] ?></td><td class="num pts"><?= $r['puntos'] ?></td></tr><?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>
  <div>
    <h2>Participantes (<?= count($participantes) ?>)</h2>
    <p class="suave" style="margin-top:0">Los celulares solo los ve la liga (para entregar premios). No se publican.</p>
    <div class="tabla-scroll"><table>
      <tr><th>Nombre</th><th>Celular</th><th class="num">Pronós.</th><th></th></tr>
      <?php foreach ($participantes as $pp): ?><tr style="<?= $pp['activo'] ? '' : 'opacity:.55' ?>"><td><?= e($pp['nombre']) ?></td><td><?php $wa = telefono_wa($pp['telefono']); ?><?= $wa ? '<a target="_blank" href="https://wa.me/' . e($wa) . '">' . e($pp['telefono']) . '</a>' : e($pp['telefono']) ?></td><td class="num"><?= (int)$pp['n'] ?></td>
        <td><?php if (puede('comunicacion', true)): ?><form class="inline" method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="quitar"><input type="hidden" name="id" value="<?= (int)$pp['id'] ?>"><button class="btn btn-sec btn-chico"><?= $pp['activo'] ? 'Quitar' : 'Activar' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
    </table></div>
  </div>
</div>

<?php if (puede('comunicacion', true)): ?>
<details class="tarjeta" style="margin-top:20px"><summary><b>Reiniciar la polla</b> (para un campeonato nuevo)</summary>
  <form method="post" style="margin-top:10px" onsubmit="return confirm('¿Borrar TODOS los pronósticos?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="reiniciar">
    <p>Borra todos los pronósticos y la tabla vuelve a cero. Los participantes siguen registrados. Escriba <b>REINICIAR</b>:</p>
    <input type="text" name="confirmar" required style="max-width:200px"> <button class="btn btn-peligro">Reiniciar</button></form>
</details>
<?php endif;
pie_admin();
