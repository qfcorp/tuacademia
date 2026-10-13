<?php
/* Página pública de un partido (se actualiza sola cada 15 segundos si está EN VIVO) */
$p = partido_completo((int)get('p'));
if (!$p || (int)$p['liga_id'] !== $lid) { ir($base); }

function contenido_partido_publico(array $p, array $liga, string $base): void
{
    $eventos = filas('SELECT ev.*, j.nombres, j.apellidos, j.numero FROM eventos ev JOIN jugadores j ON j.id = ev.jugador_id WHERE ev.partido_id = ? ORDER BY (ev.minuto IS NULL), ev.minuto, ev.id', [$p['id']]);
    $dep = deporte_de(reglas_torneo((int)$p['torneo_id']) ?? []);
    echo tarjeta_partido($p);
    if ($dep === 'ecuavoley') {
        // Detalle de cada set (el marcador grande son los sets ganados)
        $sets = sets_partido((int)$p['id']);
        if ($sets) {
            echo '<div class="sets-detalle">';
            foreach ($sets as $st) {
                echo '<span class="' . ((int)$st['cerrado'] ? '' : 'set-vivo') . '">Set ' . (int)$st['num'] . ': <b>' . (int)$st['puntos_local'] . '-' . (int)$st['puntos_visitante'] . '</b>' . ((int)$st['cerrado'] ? '' : ' ●') . '</span>';
            }
            echo '</div>';
        }
    }
    if ($dep === 'beisbol') echo html_linea_beisbol($p, reglas_torneo((int)$p['torneo_id']) ?? []); // (v36) pizarra por entradas
    $info = [];
    if (nombre_arbitro($p) !== '') $info[] = 'Árbitro: ' . e(nombre_arbitro($p));
    if ($p['hora_inicio'] !== '') $info[] = 'Inicio: ' . e($p['hora_inicio']);
    if ($p['hora_fin'] !== '') $info[] = 'Fin: ' . e($p['hora_fin']);
    if ($info) echo '<p class="suave" style="text-align:center">' . implode(' · ', $info) . '</p>';
    if ($eventos): ?>
    <div class="rejilla-2">
      <?php foreach ([[$p['local_id'], $p['local_nombre']], [$p['visitante_id'], $p['visitante_nombre']]] as [$eid, $enom]): ?>
        <div class="tarjeta">
          <h3 style="margin-top:0"><?= e($enom) ?></h3>
          <ul class="eventos">
            <?php $hay = false; foreach ($eventos as $x): if ((int)$x['equipo_id'] !== (int)$eid) continue; $hay = true; ?>
              <li><span class="minuto"><?= e(fmt_minuto_evento($x['minuto'], $dep)) ?></span> <?= icono_evento($x['tipo'], $dep) ?> <a style="color:inherit" href="<?= e($base . '&j=' . $x['jugador_id']) ?>"><?= e(nombre_jugador($x)) ?></a></li>
            <?php endforeach; if (!$hay): ?><li class="suave">Sin eventos</li><?php endif; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
    <?php elseif ($p['estado'] === 'en_vivo'): ?>
      <p class="suave" style="text-align:center">Aún no hay <?= $dep === 'basquet' ? 'canastas ni faltas' : ($dep === 'ecuavoley' ? 'tarjetas' : ($dep === 'beisbol' ? 'carreras' : 'goles ni tarjetas')) ?>.</p>
    <?php endif;
    if ($p['observaciones'] !== ''): ?><div class="tarjeta"><strong>Observaciones:</strong> <?= nl2br(e($p['observaciones'])) ?></div><?php endif;
}

if (get('parcial') === '1') {
    echo '<div data-estado="' . e($p['estado']) . '">';
    contenido_partido_publico($p, $liga, $base);
    echo '</div>';
    exit;
}

cabecera_publica($p['local_nombre'] . ' vs ' . $p['visitante_nombre'], $liga);
?>
<p><a href="<?= e($base . '&t=' . (int)$p['torneo_id'] . '&v=' . ($p['estado'] === 'en_vivo' ? 'envivo' : 'fixture')) ?>">← <?= $p['estado'] === 'en_vivo' ? 'En vivo' : 'Fixture de ' . e($p['torneo']) ?></a></p>
<p class="suave" style="text-align:center;margin-bottom:6px"><?= e(nombre_fase($p['fase'])) ?><?= $p['fase'] === 'regular' ? ' · Jornada ' . (int)$p['jornada'] : '' ?></p>
<?= presentado_por($p) ?>
<div id="zona-vivo" data-url="<?= e($base . '&p=' . (int)$p['id'] . '&parcial=1') ?>">
  <div data-estado="<?= e($p['estado']) ?>"><?php contenido_partido_publico($p, $liga, $base); ?></div>
</div>
<?php if ($p['estado'] === 'en_vivo'): ?><p class="suave" style="text-align:center;font-size:.8rem">Se actualiza automáticamente cada 15 segundos.</p><?php endif; ?>
<?= script_refresco() ?>
<?php
pie_publico();
