<?php
/* (v10) TABLERO DEL NEGOCIO (solo superadministrador): ingresos por mes, ligas nuevas, ligas que no renovaron y ligas en riesgo */
if (!es_super()) ir('admin.php');

$hoy = date('Y-m-d');
$meses = [];
for ($i = 11; $i >= 0; $i--) $meses[] = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
$desde = $meses[0] . '-01';
$nombres_mes = ['01' => 'ene', '02' => 'feb', '03' => 'mar', '04' => 'abr', '05' => 'may', '06' => 'jun', '07' => 'jul', '08' => 'ago', '09' => 'sep', '10' => 'oct', '11' => 'nov', '12' => 'dic'];
$etq = fn(string $ym) => $nombres_mes[substr($ym, 5, 2)] . ' ' . substr($ym, 2, 2);

$ingresos = array_column(filas("SELECT substr(ps.fecha, 1, 7) AS m, SUM(ps.monto) AS total FROM pagos_suscripcion ps JOIN ligas l ON l.id = ps.liga_id
                                WHERE l.es_demo = 0 AND ps.fecha >= ? GROUP BY m", [$desde]), 'total', 'm');
$nuevas = array_column(filas("SELECT substr(creado, 1, 7) AS m, COUNT(*) AS n FROM ligas WHERE es_demo = 0 AND creado >= ? GROUP BY m", [$desde]), 'n', 'm');
// Primer pago de cada liga (pasó de prueba a pagar o empezó pagando)
$primeras = array_column(filas("SELECT substr(f, 1, 7) AS m, COUNT(*) AS n FROM (SELECT MIN(ps.fecha) AS f FROM pagos_suscripcion ps JOIN ligas l ON l.id = ps.liga_id WHERE l.es_demo = 0 GROUP BY ps.liga_id) WHERE f >= ? GROUP BY m", [$desde]), 'n', 'm');
// No renovaron: su fecha de vencimiento cayó en ese mes y todavía siguen vencidas
$no_renov = array_column(filas("SELECT substr(vence, 1, 7) AS m, COUNT(*) AS n FROM ligas WHERE es_demo = 0 AND activa = 1 AND vence <> '' AND vence < ? AND vence >= ? GROUP BY m", [$hoy, $desde]), 'n', 'm');

$ligas = filas("SELECT l.*, p.nombre AS plan,
                  (SELECT MAX(u.ultimo_ingreso) FROM usuarios u WHERE u.liga_id = l.id AND u.rol = 'liga') AS ultimo_ingreso,
                  (SELECT MAX(COALESCE(NULLIF(substr(pa.vocal_cerrada_en, 1, 10), ''), pa.fecha)) FROM partidos pa JOIN torneos t ON t.id = pa.torneo_id WHERE t.liga_id = l.id AND pa.estado IN ('jugado','wo')) AS ultimo_partido,
                  (SELECT COUNT(*) FROM equipos e WHERE e.liga_id = l.id) AS n_eq,
                  (SELECT ps.monto * 1.0 / MAX(ps.meses, 1) FROM pagos_suscripcion ps WHERE ps.liga_id = l.id ORDER BY ps.fecha DESC, ps.id DESC LIMIT 1) AS mensual
                FROM ligas l LEFT JOIN planes p ON p.id = l.plan_id WHERE l.es_demo = 0 AND l.activa = 1 ORDER BY l.nombre");
$por_estado = ['prueba' => 0, 'activa' => 0, 'gracia' => 0, 'vencida' => 0, 'sin_limite' => 0];
$mrr = 0.0;
$riesgo = [];
$limite = date('Y-m-d', strtotime('-15 days'));
foreach ($ligas as $l) {
    $s = estado_suscripcion($l);
    $por_estado[$s['estado']]++;
    if ($s['estado'] === 'activa') $mrr += (float)$l['mensual'];
    if (!in_array($s['estado'], ['activa', 'prueba', 'gracia'], true)) continue;
    if (substr((string)$l['creado'], 0, 10) > $limite) continue; // ligas nuevas: todavía se están armando
    $motivos = [];
    if ((string)$l['ultimo_ingreso'] === '' || substr((string)$l['ultimo_ingreso'], 0, 10) < $limite) {
        $motivos[] = (string)$l['ultimo_ingreso'] === '' ? 'Nunca ha ingresado al panel' : 'Sin ingresar desde el ' . substr((string)$l['ultimo_ingreso'], 0, 10);
    }
    if ((string)$l['ultimo_partido'] === '' || (string)$l['ultimo_partido'] < $limite) {
        $motivos[] = (string)$l['ultimo_partido'] === '' ? 'No ha registrado ningún partido' : 'Sin partidos jugados desde el ' . $l['ultimo_partido'];
    }
    if ($motivos) $riesgo[] = $l + ['motivos' => $motivos, 'sus' => $s];
}
$recientes_no = filas("SELECT l.*, p.nombre AS plan FROM ligas l LEFT JOIN planes p ON p.id = l.plan_id WHERE l.es_demo = 0 AND l.activa = 1 AND l.vence <> '' AND l.vence < ? AND l.vence >= ? ORDER BY l.vence DESC", [$hoy, date('Y-m-d', strtotime('-60 days'))]);
$por_vencer = filas("SELECT l.*, p.nombre AS plan FROM ligas l LEFT JOIN planes p ON p.id = l.plan_id WHERE l.es_demo = 0 AND l.activa = 1 AND l.vence <> '' AND l.vence BETWEEN ? AND ? ORDER BY l.vence", [$hoy, date('Y-m-d', strtotime('+15 days'))]);
$max_ing = max(1, ...array_map(fn($m) => (float)($ingresos[$m] ?? 0), $meses));
$max_lig = max(1, ...array_map(fn($m) => max((int)($nuevas[$m] ?? 0), (int)($no_renov[$m] ?? 0), (int)($primeras[$m] ?? 0)), $meses));
$total_12 = array_sum(array_map('floatval', $ingresos));
$wa_liga = function (array $l, string $msg): string {
    $wa = telefono_wa((string)$l['contacto_telefono']);
    return $wa ? '<a class="btn btn-sec btn-chico" target="_blank" href="https://wa.me/' . e($wa) . '?text=' . rawurlencode($msg) . '">📲 WhatsApp</a>' : '';
};
cabecera_admin('Negocio');
?>
<div class="fila-titulo"><h1>📈 Tablero del negocio</h1><div class="acciones"><a class="btn btn-sec btn-chico" href="admin.php?p=suscripciones">💳 Ventas y pagos</a> <a class="btn btn-sec btn-chico" href="admin.php?p=planes">Planes y precios</a></div></div>
<div class="cifras">
  <div class="cifra"><b><?= dinero($mrr) ?></b>Ingreso mensual estimado (ligas pagando)</div>
  <div class="cifra"><b><?= dinero((float)($ingresos[date('Y-m')] ?? 0)) ?></b>Cobrado este mes</div>
  <div class="cifra"><b><?= dinero($total_12) ?></b>Cobrado últimos 12 meses</div>
  <div class="cifra"><b><?= $por_estado['activa'] ?></b>Ligas pagando</div>
  <div class="cifra"><b><?= $por_estado['prueba'] ?></b>En demo</div>
  <div class="cifra"><b><?= $por_estado['gracia'] + $por_estado['vencida'] ?></b>Vencidas / en gracia</div>
  <div class="cifra"><b style="color:var(--roja)"><?= count($riesgo) ?></b>Ligas en riesgo</div>
</div>

<div class="tarjeta">
  <h2 style="margin-top:0">💵 Ingresos por mes</h2>
  <div class="barras">
    <?php foreach ($meses as $m): $v = (float)($ingresos[$m] ?? 0); ?>
      <div class="barra-col" title="<?= e($etq($m) . ': ' . dinero($v)) ?>"><b><?= $v > 0 ? '$' . number_format($v, 0) : '' ?></b><i style="height:<?= round($v / $max_ing * 100) ?>%"></i></div>
    <?php endforeach; ?>
  </div>
  <div class="barras-eje"><?php foreach ($meses as $m): ?><span><?= e($etq($m)) ?></span><?php endforeach; ?></div>
</div>

<div class="tarjeta">
  <h2 style="margin-top:0">🏟 Ligas por mes</h2>
  <p class="suave" style="margin-top:0"><span style="color:#1565c0">■</span> Ligas nuevas (registradas) · <span style="color:#1a9e5c">■</span> Empezaron a pagar · <span style="color:#d93a3a">■</span> No renovaron (vencieron ese mes y siguen vencidas)</p>
  <div class="barras">
    <?php foreach ($meses as $m): $a = (int)($nuevas[$m] ?? 0); $b = (int)($primeras[$m] ?? 0); $c = (int)($no_renov[$m] ?? 0); ?>
      <div class="barra-col" style="flex-direction:row;align-items:flex-end;gap:2px" title="<?= e($etq($m) . ": $a nuevas, $b empezaron a pagar, $c no renovaron") ?>">
        <i style="height:<?= round($a / $max_lig * 100) ?>%;background:#1565c0"></i><i style="height:<?= round($b / $max_lig * 100) ?>%;background:#1a9e5c"></i><i style="height:<?= round($c / $max_lig * 100) ?>%;background:#d93a3a"></i>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="barras-eje"><?php foreach ($meses as $m): ?><span><?= e($etq($m)) ?></span><?php endforeach; ?></div>
  <div class="tabla-scroll" style="margin-top:10px"><table>
    <tr><th>Mes</th><?php foreach (array_slice($meses, -6) as $m): ?><th class="num"><?= e($etq($m)) ?></th><?php endforeach; ?></tr>
    <tr><td>Cobrado</td><?php foreach (array_slice($meses, -6) as $m): ?><td class="num"><?= dinero((float)($ingresos[$m] ?? 0)) ?></td><?php endforeach; ?></tr>
    <tr><td>Ligas nuevas</td><?php foreach (array_slice($meses, -6) as $m): ?><td class="num"><?= (int)($nuevas[$m] ?? 0) ?></td><?php endforeach; ?></tr>
    <tr><td>Empezaron a pagar</td><?php foreach (array_slice($meses, -6) as $m): ?><td class="num"><?= (int)($primeras[$m] ?? 0) ?></td><?php endforeach; ?></tr>
    <tr><td>No renovaron</td><?php foreach (array_slice($meses, -6) as $m): ?><td class="num"><?= (int)($no_renov[$m] ?? 0) ?></td><?php endforeach; ?></tr>
  </table></div>
</div>

<h2>⚠️ Ligas en riesgo <small class="suave">(sin ingresar al panel o sin partidos en 15 días)</small></h2>
<?php if (!$riesgo): ?><p class="suave">Ninguna. Todas las ligas activas están usando el sistema. 👍</p><?php else: ?>
<div class="tabla-scroll"><table>
  <tr><th>Liga</th><th>Estado</th><th>Por qué</th><th>Contacto</th><th></th></tr>
  <?php foreach ($riesgo as $l): ?>
    <tr class="riesgo"><td><b><?= e($l['nombre']) ?></b><br><small><?= e($l['ciudad']) ?> · <?= (int)$l['n_eq'] ?> equipos</small></td>
      <td><?= e($l['sus']['texto']) ?></td>
      <td style="white-space:normal"><?= e(implode(' · ', $l['motivos'])) ?></td>
      <td style="white-space:normal"><?= e($l['contacto_nombre']) ?><br><small><?= e($l['contacto_telefono']) ?></small></td>
      <td class="acciones"><?= $wa_liga($l, 'Hola ' . $l['contacto_nombre'] . ', le saluda ' . ajuste('empresa') . '. ¿Cómo va la liga ' . $l['nombre'] . '? Queremos ayudarle a sacarle el máximo provecho a ' . marca() . ' (fixture, vocalía en vivo, tabla). ¿Tiene 10 minutos para una llamada?') ?>
        <a class="btn btn-sec btn-chico" href="admin.php?p=ligas&amp;entrar=<?= (int)$l['id'] ?>">Entrar</a></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>

<div class="rejilla-2">
  <div>
    <h2>⏰ Vencen en los próximos 15 días</h2>
    <?php if (!$por_vencer): ?><p class="suave">Ninguna.</p><?php else: ?>
    <div class="tabla-scroll"><table><tr><th>Liga</th><th>Vence</th><th>Plan</th><th></th></tr>
      <?php foreach ($por_vencer as $l): ?><tr><td><?= e($l['nombre']) ?><?= $l['es_prueba'] ? ' <span class="etiqueta amarilla">prueba</span>' : '' ?></td><td><?= e($l['vence']) ?></td><td><?= e($l['plan'] ?? '—') ?></td>
        <td class="acciones"><a class="btn btn-chico" href="admin.php?p=suscripciones&amp;liga=<?= (int)$l['id'] ?>#pago">Registrar pago</a> <?= $wa_liga($l, 'Hola ' . $l['contacto_nombre'] . ', le saluda ' . ajuste('empresa') . '. ' . ($l['es_prueba'] ? 'Su prueba gratis' : 'Su suscripción') . ' de ' . marca() . ' para ' . $l['nombre'] . ' vence el ' . $l['vence'] . '. ' . texto_valor_renovacion($l) . '. ¿Le ayudamos a renovar?') ?></td></tr><?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>
  <div>
    <h2>❌ No renovaron (últimos 60 días)</h2>
    <?php if (!$recientes_no): ?><p class="suave">Ninguna.</p><?php else: ?>
    <div class="tabla-scroll"><table><tr><th>Liga</th><th>Venció</th><th>Plan</th><th></th></tr>
      <?php foreach ($recientes_no as $l): ?><tr><td><?= e($l['nombre']) ?></td><td><?= e($l['vence']) ?></td><td><?= e($l['plan'] ?? '—') ?></td>
        <td><?= $wa_liga($l, 'Hola ' . $l['contacto_nombre'] . ', le saluda ' . ajuste('empresa') . '. La suscripción de ' . $l['nombre'] . ' venció el ' . $l['vence'] . '. ' . texto_valor_renovacion($l) . '. ¿Le ayudamos a renovar para seguir usando el sistema?') ?></td></tr><?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>
</div>
<p class="suave" style="font-size:.85rem">La liga de demostración no se cuenta. "Ingreso mensual estimado" = último pago de cada liga pagando dividido para los meses que cubre.</p>
<?php
pie_admin();
