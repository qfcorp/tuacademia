<?php
/*
 * Documentos imprimibles / PDF:
 *  - Planilla en blanco (antes del partido)
 *  - Hoja de vocalía llena (lo que registró el vocal)
 *  - Informe del árbitro
 * Se imprimen desde el navegador: botón "Imprimir / Guardar PDF".
 */

function doc_inicio(string $titulo, string $volver = ''): void
{
    ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?></title>
<meta name="robots" content="noindex">
<?= etiqueta_base() ?>
<link rel="stylesheet" href="assets/estilo.css?v=330">
<style>
  body { background: #fff; color: #000; font-size: 12px; }
  * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .contenedor { max-width: 900px; }
  .cab { display: flex; gap: 14px; align-items: center; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 8px; }
  .cab h1 { font-size: 18px; margin: 0; color: #000; }
  .meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px 14px; margin: 8px 0; }
  .meta div { border-bottom: 1px solid #000; padding: 3px 0; }
  .doc h2 { font-size: 14px; margin: 12px 0 5px; color: #000; }
  table.pl { width: 100%; border-collapse: collapse; background: #fff; }
  table.pl th, table.pl td { border: 1px solid #000; padding: 4px 5px; color: #000; white-space: normal; font-size: 12px; }
  table.pl th { font-size: 10px; background: #eee; }
  .susp { text-decoration: line-through; color: #b00 !important; }
  .caja-texto { border: 2px solid #000; padding: 8px 10px; min-height: 60px; white-space: pre-wrap; }
  .marcador-doc { font-size: 22px; font-weight: 800; text-align: center; border: 2px solid #000; padding: 8px; margin: 10px 0; }
  .firmas { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-top: 40px; text-align: center; }
  .firmas div { border-top: 1px solid #000; padding-top: 4px; }
  .pie-doc { margin-top: 18px; font-size: 10px; color: #444; }
  .barra-doc { background: #f3f5f4; padding: 10px; border-radius: 8px; margin-bottom: 12px; }
  @media (max-width: 600px) { .meta { grid-template-columns: 1fr 1fr; } .firmas { grid-template-columns: 1fr 1fr; } }
</style>
</head>
<body class="planilla">
<div class="contenedor doc">
  <div class="barra-doc no-imprimir">
    <button class="btn" onclick="window.print()">🖨 Imprimir / Guardar PDF</button>
    <?php if ($volver !== ''): ?> <a class="btn btn-sec" href="<?= e($volver) ?>">Volver</a><?php endif; ?>
    <p style="margin:8px 0 0;font-size:12px">Para guardar en PDF: presione el botón y en <b>Impresora/Destino</b> elija <b>"Guardar como PDF"</b>. En el celular: menú ⋮ → Compartir → Imprimir → Guardar como PDF.</p>
  </div>
<?php
}

function doc_fin(): void
{
    echo '</div><script>document.querySelectorAll(\'img[loading="lazy"]\').forEach(function (i) { i.loading = \'eager\'; });</script></body></html>';
}

function doc_cabecera(array $p, array $liga, string $titulo): void
{
    ?>
  <div class="cab">
    <?= escudo($liga['logo'], $liga['nombre'], $liga['color'], 'escudo-grande') ?>
    <div>
      <h1><?= e($liga['nombre']) ?> — <?= e($titulo) ?></h1>
      <div><?= e($p['torneo']) ?> <?= e($p['temporada']) ?> · <?= e(nombre_fase($p['fase'])) ?><?= $p['fase'] === 'regular' ? ' · Jornada ' . (int)$p['jornada'] : '' ?><?= $p['grupo'] !== '' ? ' · Grupo ' . e($p['grupo']) : '' ?></div>
      <div><?= e(resumen_reglas(reglas_torneo((int)$p['torneo_id']))) ?></div>
    </div>
  </div>
  <div class="meta">
    <div><b>Partido:</b> <?= e($p['local_nombre']) ?> vs <?= e($p['visitante_nombre']) ?></div>
    <div><b>Fecha:</b> <?= e($p['fecha']) ?></div>
    <div><b>Hora programada:</b> <?= e($p['hora']) ?></div>
    <div><b>Cancha:</b> <?= e($p['cancha']) ?></div>
    <?php foreach (arbitros_del_partido($p) as [$c, $etq, $id, $nom]): ?>
      <div><b><?= e($etq) ?>:</b> <?= e($c === 'arbitro_id' ? nombre_arbitro($p) : $nom) ?></div>
    <?php endforeach; ?>
    <div><b>Estado:</b> <?= e(nombre_estado_partido($p['estado'])) ?></div>
  </div>
<?php
}

/* ---------- Planilla en blanco ---------- */
function doc_planilla(array $p, array $liga, string $volver = ''): void
{
    $susp = suspensiones((int)$p['torneo_id']);
    doc_inicio('Planilla ' . $p['local_nombre'] . ' vs ' . $p['visitante_nombre'], $volver);
    doc_cabecera($p, $liga, 'Planilla de juego');
    foreach ([[$p['local_id'], $p['local_nombre'], 'LOCAL'], [$p['visitante_id'], $p['visitante_nombre'], 'VISITANTE']] as [$eid, $enombre, $rol]):
        $jug = filas('SELECT * FROM jugadores WHERE equipo_id = ? AND activo = 1 ORDER BY (numero IS NULL), numero, apellidos', [$eid]);
        ?>
  <h2><?= $rol ?>: <?= e($enombre) ?></h2>
  <table class="pl">
    <tr><th style="width:34px">N°</th><th>Jugador</th><th style="width:90px">Cédula</th><th style="width:34px">Tit.</th><?php $Tpl = textos_deporte(reglas_torneo((int)$p['torneo_id']) ?? []); ?><th style="width:90px"><?= e($Tpl['goles'] ?: 'Goles') ?></th><th style="width:40px"><?= $Tpl['deporte'] === 'basquet' ? 'Falt.' : 'T.A.' ?></th><th style="width:40px"><?= $Tpl['deporte'] === 'basquet' ? 'T/E' : 'T.R.' ?></th><th style="width:110px">Firma</th></tr>
    <?php foreach ($jug as $j): $s = $susp[(int)$j['id']] ?? null; ?>
      <tr><td><?= $j['numero'] !== null ? (int)$j['numero'] : '' ?></td><td class="<?= $s ? 'susp' : '' ?>"><?= e(nombre_jugador($j)) ?><?= $s ? ' — SUSPENDIDO' : '' ?></td><td><?= e($j['cedula']) ?></td><td></td><td></td><td></td><td></td><td></td></tr>
    <?php endforeach; ?>
    <?php for ($i = 0; $i < 3; $i++): ?><tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr><?php endfor; ?>
  </table>
  <p style="margin:4px 0">Delegado: ______________________ &nbsp; Capitán N°: ____</p>
    <?php endforeach; ?>
  <div class="meta"><div><b>Hora de inicio:</b> ______</div><div><b>Hora de fin:</b> ______</div><div><b>Vocal:</b> ____________</div><div><b>Directivo / equipo:</b> ________</div></div>
  <div class="marcador-doc"><?= e($p['local_nombre']) ?> ____ &nbsp; - &nbsp; ____ <?= e($p['visitante_nombre']) ?></div>
  <h2>Observaciones</h2><div class="caja-texto" style="min-height:80px"></div>
  <div class="firmas"><div>Árbitro</div><div>Vocal</div><div>Delegado local</div><div>Delegado visitante</div></div>
<?php
    doc_fin();
}

/* ---------- Hoja de vocalía llena ---------- */
function doc_vocalia(array $p, array $liga, string $volver = ''): void
{
    $vocales = filas('SELECT v.*, e.nombre AS equipo FROM vocales_partido v LEFT JOIN equipos e ON e.id = v.equipo_id WHERE v.partido_id = ? ORDER BY v.id', [$p['id']]);
    $eventos = filas('SELECT ev.*, j.nombres, j.apellidos, j.numero FROM eventos ev JOIN jugadores j ON j.id = ev.jugador_id WHERE ev.partido_id = ? ORDER BY (ev.minuto IS NULL), ev.minuto, ev.id', [$p['id']]);
    $por_jugador = [];
    foreach ($eventos as $x) {
        $por_jugador[(int)$x['jugador_id']][$x['tipo']][] = $x['minuto'];
    }
    $dep = deporte_de(reglas_torneo((int)$p['torneo_id']) ?? []);
    $T = textos_deporte($dep);
    doc_inicio('Vocalía ' . $p['local_nombre'] . ' vs ' . $p['visitante_nombre'], $volver);
    doc_cabecera($p, $liga, 'Hoja de vocalía');
    ?>
  <div class="meta">
    <div><b>Hora de inicio:</b> <?= e($p['hora_inicio']) ?></div>
    <div><b>Hora de fin:</b> <?= e($p['hora_fin']) ?></div>
    <div style="grid-column: span 2"><b>Vocal(es):</b>
      <?php if (!$vocales) echo '—'; foreach ($vocales as $i => $v): ?><?= $i ? ' · ' : '' ?><?= e($v['nombre']) ?> (<?= $v['tipo'] === 'equipo' ? 'delegado de ' . e($v['equipo'] ?? 'equipo') : 'directivo de la liga' ?>)<?php endforeach; ?>
    </div>
  </div>
  <div class="marcador-doc"><?= e($p['local_nombre']) ?> &nbsp; <?= $p['goles_local'] === null ? '—' : (int)$p['goles_local'] ?> - <?= $p['goles_visitante'] === null ? '—' : (int)$p['goles_visitante'] ?> &nbsp; <?= e($p['visitante_nombre']) ?>
    <?php if ($p['penales_local'] !== null): ?><div style="font-size:14px">Penales: <?= (int)$p['penales_local'] ?> - <?= (int)$p['penales_visitante'] ?></div><?php endif; ?>
    <?php if ($p['estado'] === 'wo'): ?><div style="font-size:14px">Resultado por W.O.</div><?php endif; ?>
    <?php if ($dep === 'ecuavoley' && ($ds = detalle_sets((int)$p['id'])) !== ''): ?><div style="font-size:14px">Sets: <?= e($ds) ?></div><?php endif; ?>
  </div>
  <?php foreach ([[$p['local_id'], $p['local_nombre'], 'LOCAL'], [$p['visitante_id'], $p['visitante_nombre'], 'VISITANTE']] as [$eid, $enombre, $rol]):
      $jug = jugadores_para_partido($p, (int)$eid, []);
      $hay_pres = count(array_filter($jug, fn($j) => $j['presente'])) > 0;
      if ($hay_pres) $jug = array_values(array_filter($jug, fn($j) => $j['presente']));
  ?>
  <h2><?= $rol ?>: <?= e($enombre) ?> <small>(<?= $hay_pres ? count($jug) . ' jugadores presentes' : 'no se registró alineación: plantilla completa' ?>)</small></h2>
  <table class="pl">
    <tr><th style="width:34px">N°</th><th>Jugador</th><th style="width:90px">Cédula</th><?php if ($T['con_goleadores']): ?><th style="width:110px"><?= e($T['goles']) ?><?= $dep === 'basquet' ? '' : ' (min)' ?></th><?php endif; ?><?php if ($dep === 'basquet'): ?><th style="width:60px">Faltas</th><?php endif; ?><th style="width:80px"><?= $dep === 'basquet' ? 'Técnicas' : 'T.A. (min)' ?></th><th style="width:80px"><?= $dep === 'basquet' ? 'Expulsión' : 'T.R. (min)' ?></th></tr>
    <?php foreach ($jug as $j):
        $d = $por_jugador[(int)$j['id']] ?? [];
        $fmt = fn($tipos) => implode(', ', array_map(fn($m) => $m === null ? '✓' : $m . "'", array_merge(...array_map(fn($t) => $d[$t] ?? [], $tipos))));
    ?>
      <tr><td><?= $j['numero'] !== null ? (int)$j['numero'] : '' ?></td><td><?= e(nombre_jugador($j)) ?></td><td><?= e($j['cedula']) ?></td>
        <?php if ($dep === 'basquet'): $pts_j = count($d['p1'] ?? []) + 2 * count($d['p2'] ?? []) + 3 * count($d['p3'] ?? []); ?>
        <td><?= $pts_j ?: '' ?></td><td><?= count($d['falta'] ?? []) ?: '' ?></td><td><?= count($d['amarilla'] ?? []) ?: '' ?></td><td><?= count($d['roja'] ?? []) ? '✓' : '' ?></td></tr>
        <?php else: ?>
        <?php if ($T['con_goleadores']): ?><td><?= $fmt(['gol', 'penal']) ?><?= !empty($d['autogol']) ? ' (a.g. ' . $fmt(['autogol']) . ')' : '' ?></td><?php endif; ?><td><?= $fmt(['amarilla']) ?></td><td><?= $fmt(['roja']) ?></td></tr>
        <?php endif; ?>
    <?php endforeach; ?>
  </table>
  <?php endforeach; ?>

  <h2>Cronología del partido</h2>
  <?php if (!$eventos): ?><p>Sin goles ni tarjetas registrados.</p><?php else: ?>
  <table class="pl">
    <tr><th style="width:50px">Min.</th><th>Evento</th><th>Jugador</th><th>Equipo</th><th>Nota</th></tr>
    <?php foreach ($eventos as $x): ?>
      <tr><td><?= $x['minuto'] !== null ? (int)$x['minuto'] . "'" : '' ?></td><td><?= e(nombre_evento($x['tipo'], deporte_de(reglas_torneo((int)$p['torneo_id']) ?? []))) ?></td><td><?= $x['numero'] !== null ? '#' . (int)$x['numero'] . ' ' : '' ?><?= e(nombre_jugador($x)) ?></td>
        <td><?= (int)$x['equipo_id'] === (int)$p['local_id'] ? e($p['local_nombre']) : e($p['visitante_nombre']) ?></td><td><?= e($x['nota']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
  <h2>Observaciones del vocal</h2>
  <div class="caja-texto"><?= e($p['vocal_obs'] ?: ($p['observaciones'] ?? '')) ?></div>
  <div class="firmas"><div>Árbitro<br><?= e(nombre_arbitro($p)) ?></div><div>Vocal<br><?= e($vocales[0]['nombre'] ?? '') ?></div><div>Delegado local</div><div>Delegado visitante</div></div>
  <p class="pie-doc">Documento generado el <?= date('Y-m-d H:i') ?>.<?= $p['vocal_cerrada'] ? ' Vocalía cerrada el ' . e($p['vocal_cerrada_en']) . '.' : ' Vocalía todavía abierta (puede cambiar).' ?></p>
<?php
    doc_fin();
}

/* ---------- Informe del árbitro ---------- */
function doc_informe(array $p, array $liga, string $volver = ''): void
{
    $inf = informe_partido((int)$p['id']);
    $ev = filas('SELECT ie.*, j.nombres, j.apellidos, j.numero FROM informe_eventos ie JOIN jugadores j ON j.id = ie.jugador_id WHERE ie.partido_id = ? ORDER BY (ie.minuto IS NULL), ie.minuto, ie.id', [$p['id']]);
    doc_inicio('Informe arbitral ' . $p['local_nombre'] . ' vs ' . $p['visitante_nombre'], $volver);
    doc_cabecera($p, $liga, 'Informe del árbitro');
    if (!$inf) {
        echo '<p><b>El árbitro todavía no ha llenado el informe.</b></p>';
        doc_fin();
        return;
    }
    ?>
  <div class="meta">
    <div><b>Árbitro:</b> <?= e($inf['arbitro'] ?? nombre_arbitro($p)) ?></div>
    <div><b>Hora de inicio:</b> <?= e($inf['hora_inicio']) ?></div>
    <div><b>Hora de fin:</b> <?= e($inf['hora_fin']) ?></div>
    <div><b>Estado:</b> <?= $inf['estado'] === 'enviado' ? 'Enviado ' . e($inf['enviado_en']) : 'Borrador' ?></div>
  </div>
  <div class="marcador-doc"><?= e($p['local_nombre']) ?> &nbsp; <?= $inf['goles_local'] === null ? '—' : (int)$inf['goles_local'] ?> - <?= $inf['goles_visitante'] === null ? '—' : (int)$inf['goles_visitante'] ?> &nbsp; <?= e($p['visitante_nombre']) ?></div>
  <h2>Goles y tarjetas según el árbitro</h2>
  <?php if (!$ev): ?><p>Sin goles ni tarjetas.</p><?php else: ?>
  <table class="pl">
    <tr><th style="width:50px">Min.</th><th>Evento</th><th>Jugador</th><th>Equipo</th><th>Motivo / detalle</th></tr>
    <?php foreach ($ev as $x): ?>
      <tr><td><?= $x['minuto'] !== null ? (int)$x['minuto'] . "'" : '' ?></td><td><?= e(nombre_evento($x['tipo'], deporte_de(reglas_torneo((int)$p['torneo_id']) ?? []))) ?></td><td><?= $x['numero'] !== null ? '#' . (int)$x['numero'] . ' ' : '' ?><?= e(nombre_jugador($x)) ?></td>
        <td><?= (int)$x['equipo_id'] === (int)$p['local_id'] ? e($p['local_nombre']) : e($p['visitante_nombre']) ?></td><td><?= e($x['nota']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
  <h2>Informe / incidentes</h2>
  <div class="caja-texto" style="min-height:120px"><?= e($inf['incidentes']) ?></div>
  <div class="firmas" style="grid-template-columns:1fr 1fr"><div>Firma del árbitro<br><?= e($inf['arbitro'] ?? '') ?></div><div>Recibido por la liga</div></div>
  <p class="pie-doc">Documento generado el <?= date('Y-m-d H:i') ?>.</p>
<?php
    doc_fin();
}

/* ---------- Recibo de pago y estado de cuenta (panel y delegado) ---------- */
function doc_cabecera_financiera(array $liga, string $titulo): void
{
    ?>
  <div class="cab">
    <?= escudo($liga['logo'], $liga['nombre'], $liga['color'], 'escudo-grande') ?>
    <div><h1><?= e($liga['nombre']) ?></h1><div style="font-size:15px;font-weight:700"><?= e($titulo) ?></div><?= $liga['ciudad'] !== '' ? '<div>' . e($liga['ciudad']) . '</div>' : '' ?></div>
  </div>
    <?php
}

function doc_estado_cuenta(array $eq, array $liga, string $volver): void
{
    $movs = filas('SELECT m.*, t.nombre AS torneo FROM movimientos m LEFT JOIN torneos t ON t.id = m.torneo_id WHERE m.equipo_id = ? AND m.anulado = 0 ORDER BY m.fecha, m.id', [$eq['id']]);
    doc_inicio('Estado de cuenta ' . $eq['nombre'], $volver);
    doc_cabecera_financiera($liga, 'Estado de cuenta');
    ?>
  <div class="meta"><div><b>Equipo:</b> <?= e($eq['nombre']) ?></div><div><b>Delegado:</b> <?= e($eq['delegado']) ?></div><div><b>Fecha de corte:</b> <?= date('Y-m-d') ?></div><div></div></div>
  <table class="pl">
    <tr><th>Fecha</th><th>Detalle</th><th>Campeonato</th><th style="width:80px">Cargo</th><th style="width:80px">Pago</th><th style="width:80px">Saldo</th></tr>
    <?php $run = 0; foreach ($movs as $m): $run += $m['tipo'] === 'cargo' ? $m['monto'] : -$m['monto']; ?>
      <tr><td><?= e($m['fecha']) ?></td><td><?= e($m['descripcion']) ?><?= $m['recibo'] ? ' (Recibo ' . str_pad((string)$m['recibo'], 6, '0', STR_PAD_LEFT) . ')' : '' ?></td><td><?= e($m['torneo'] ?? '') ?></td>
        <td style="text-align:right"><?= $m['tipo'] === 'cargo' ? dinero($m['monto']) : '' ?></td><td style="text-align:right"><?= $m['tipo'] === 'pago' ? dinero($m['monto']) : '' ?></td><td style="text-align:right"><?= dinero($run) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <div class="marcador-doc"><?= $run > 0.001 ? 'SALDO PENDIENTE: ' . dinero($run) : ($run < -0.001 ? 'SALDO A FAVOR: ' . dinero(-$run) : 'AL DÍA ✓') ?></div>
  <div class="firmas" style="grid-template-columns:1fr 1fr"><div>Tesorería de la liga</div><div>Recibí conforme (delegado)</div></div>
    <?php
    doc_fin();
}

function doc_recibo(array $m, array $liga, string $volver): void
{
    $saldo = saldo_equipo((int)$m['equipo_id']);
    doc_inicio('Recibo ' . $m['recibo'], $volver);
    doc_cabecera_financiera($liga, 'RECIBO DE PAGO N° ' . str_pad((string)$m['recibo'], 6, '0', STR_PAD_LEFT));
    ?>
  <?php if ($m['anulado']): ?><div class="marcador-doc" style="color:#b00;border-color:#b00">RECIBO ANULADO</div><?php endif; ?>
  <div class="meta" style="grid-template-columns:1fr 1fr">
    <div><b>Fecha:</b> <?= e($m['fecha']) ?></div>
    <div><b>Recibí de:</b> <?= e($m['equipo']) ?><?= $m['delegado'] !== '' ? ' (' . e($m['delegado']) . ')' : '' ?></div>
    <div><b>Por concepto de:</b> <?= e(conceptos_cargo()[$m['concepto']] ?? $m['concepto']) ?><?= $m['torneo'] ? ' — ' . e($m['torneo']) : '' ?></div>
    <div><b>Forma de pago:</b> <?= e(formas_pago()[$m['forma_pago']] ?? $m['forma_pago']) ?><?= $m['comprobante'] !== '' ? ' · Comprobante: ' . e($m['comprobante']) : '' ?></div>
    <div style="grid-column:1/-1"><b>Detalle:</b> <?= e($m['descripcion']) ?></div>
  </div>
  <div class="marcador-doc">LA SUMA DE: <?= dinero($m['monto']) ?></div>
  <p>Saldo del equipo a la fecha de impresión: <b><?= $saldo > 0 ? 'debe ' . dinero($saldo) : ($saldo < 0 ? 'a favor ' . dinero(-$saldo) : 'al día') ?></b></p>
  <div class="firmas" style="grid-template-columns:1fr 1fr"><div>Recibido por<br><?= e($m['registrado_por']) ?></div><div>Entregado por (delegado)</div></div>
    <?php
    doc_fin();
}

function movimiento_recibo(int $id, int $liga_id): ?array
{
    return fila("SELECT m.*, e.nombre AS equipo, e.delegado, t.nombre AS torneo FROM movimientos m JOIN equipos e ON e.id = m.equipo_id LEFT JOIN torneos t ON t.id = m.torneo_id
                 WHERE m.id = ? AND m.liga_id = ? AND m.tipo = 'pago'", [$id, $liga_id]);
}

/* ---------- (v8) Informe de fin de temporada de un campeonato ---------- */
function doc_temporada(array $t, array $liga, string $volver): void
{
    $tid = (int)$t['id'];
    $T = textos_deporte($t);
    sincronizar_cargos($tid);
    $num = numeros_campeonato($tid);
    $tabla = tabla_posiciones($tid);
    $gol = $T['con_goleadores'] ? goleadores($tid, 10) : [];
    $asis = asistidores($tid, 5);
    $vallas = $T['valla'] ? array_slice(vallas_invictas($tid), 0, 5) : [];
    $fp = array_slice(fair_play($tid), 0, 5);
    $elim = partidos_torneo($tid, "AND p.fase <> 'regular'");
    [$camp_id, $sub_id] = [$t['campeon_id'] ?: null, $t['subcampeon_id'] ?: null];
    if (!$camp_id) [$camp_id, $sub_id] = campeon_sugerido($tid);
    $camp = $camp_id ? fila('SELECT * FROM equipos WHERE id = ?', [$camp_id]) : null;
    $sub = $sub_id ? fila('SELECT * FROM equipos WHERE id = ?', [$sub_id]) : null;
    $rango = fila("SELECT MIN(NULLIF(fecha,'')) AS desde, MAX(NULLIF(fecha,'')) AS hasta FROM partidos WHERE torneo_id = ?", [$tid]);
    $sanc = (int)valor('SELECT COUNT(*) FROM sanciones WHERE torneo_id = ?', [$tid]) + (int)valor('SELECT COUNT(*) FROM sanciones_equipo WHERE torneo_id = ?', [$tid]);
    $fin = fila("SELECT COALESCE(SUM(CASE WHEN tipo = 'cargo' THEN monto END), 0) AS cargos, COALESCE(SUM(CASE WHEN tipo = 'pago' THEN monto END), 0) AS pagos,
                        COALESCE(SUM(CASE WHEN tipo = 'egreso' THEN monto END), 0) AS egresos FROM movimientos WHERE torneo_id = ? AND anulado = 0", [$tid]);
    $arbs = filas("SELECT a.nombre, COUNT(DISTINCT p.id) AS n, (SELECT ROUND(AVG(c.nota), 1) FROM calificaciones c JOIN partidos pp ON pp.id = c.partido_id WHERE c.arbitro_id = a.id AND pp.torneo_id = ?) AS nota
                   FROM arbitros a JOIN partidos p ON a.id IN (p.arbitro_id, p.asistente1_id, p.asistente2_id, p.cuarto_id)
                   WHERE p.torneo_id = ? AND p.estado IN ('jugado','wo') GROUP BY a.id ORDER BY n DESC", [$tid, $tid]);
    $votos = filas("SELECT v.titulo, o.texto, o.detalle FROM votaciones v JOIN votacion_opciones o ON o.id = v.ganador_opcion_id WHERE v.torneo_id = ? ORDER BY v.id", [$tid]);
    $pats = patrocinadores_activos((int)$liga['id']);
    doc_inicio('Informe de temporada ' . $t['nombre'], $volver);
    doc_cabecera_financiera($liga, 'INFORME DE FIN DE TEMPORADA');
    ?>
  <h2 style="margin-top:10px"><?= e($t['nombre']) ?> <?= e($t['temporada']) ?> <small>· <?= e(nombre_modalidad($t['modalidad'])) ?><?= $t['categoria'] !== '' ? ' · ' . e($t['categoria']) : '' ?></small></h2>
  <div class="meta">
    <div><b>Del:</b> <?= e($rango['desde'] ?? '—') ?> <b>al</b> <?= e($rango['hasta'] ?? '—') ?></div>
    <div><b>Equipos:</b> <?= (int)valor('SELECT COUNT(*) FROM torneo_equipos WHERE torneo_id = ?', [$tid]) ?></div>
    <div><b>Partidos jugados:</b> <?= $num['jugados'] ?> de <?= $num['total_partidos'] ?></div>
    <div><b><?= e($T['goles']) ?>:</b> <?= $num['goles'] ?> (<?= $num['promedio'] ?> por partido)</div>
    <div><b><?= e($T['amarilla']) ?>s:</b> <?= $num['amarillas'] ?> · <b><?= e($T['roja']) ?>s:</b> <?= $num['rojas'] ?></div>
    <div><b>Sanciones de la comisión:</b> <?= $sanc ?></div>
  </div>
  <div class="marcador-doc">🏆 CAMPEÓN: <?= $camp ? e($camp['nombre']) : '—' ?><?= $sub ? '<div style="font-size:14px">Subcampeón: ' . e($sub['nombre']) . '</div>' : '' ?></div>

  <h2>Tabla final</h2>
  <?php foreach ($tabla as $g => $filas_t): ?>
    <?php if ($g !== ''): ?><h3>Grupo <?= e($g) ?></h3><?php endif; ?>
    <table class="pl"><tr><th>#</th><th>Equipo</th><th>PJ</th><th>G</th><?php if ($T['con_empate']): ?><th>E</th><?php endif; ?><th>P</th><th><?= e($T['gf']) ?></th><th><?= e($T['gc']) ?></th><th><?= e($T['dg']) ?></th><th>Pts</th></tr>
      <?php foreach ($filas_t as $i => $f): ?><tr><td><?= $i + 1 ?></td><td><?= e($f['nombre']) ?></td><td><?= $f['pj'] ?></td><td><?= $f['pg'] ?></td><?php if ($T['con_empate']): ?><td><?= $f['pe'] ?></td><?php endif; ?><td><?= $f['pp'] ?></td><td><?= $f['gf'] ?></td><td><?= $f['gc'] ?></td><td><?= $f['dg'] ?></td><td><b><?= $f['pts'] ?></b></td></tr><?php endforeach; ?>
    </table>
  <?php endforeach; ?>

  <?php if ($elim): ?>
  <h2>Fase final</h2>
  <table class="pl"><tr><th>Fase</th><th>Partido</th><th>Resultado</th></tr>
    <?php foreach ($elim as $p): ?><tr><td><?= e(nombre_fase($p['fase'])) ?></td><td><?= e($p['local_nombre'] . ' vs ' . $p['visitante_nombre']) ?></td><td><?= partido_cerrado($p) ? (int)$p['goles_local'] . ' - ' . (int)$p['goles_visitante'] . ($p['penales_local'] !== null ? ' (pen ' . (int)$p['penales_local'] . '-' . (int)$p['penales_visitante'] . ')' : '') : 'Pendiente' ?></td></tr><?php endforeach; ?>
  </table>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
    <?php if ($gol): ?><div><h2><?= e($T['goleadores']) ?></h2><table class="pl"><tr><th>#</th><th>Jugador</th><th>Equipo</th><th><?= e($T['goles']) ?></th></tr>
      <?php foreach ($gol as $i => $g): ?><tr><td><?= $i + 1 ?></td><td><?= e(nombre_jugador($g)) ?></td><td><?= e($g['equipo']) ?></td><td><b><?= (int)$g['goles'] ?></b></td></tr><?php endforeach; ?></table></div><?php endif; ?>
    <?php if ($asis): ?><div><h2>Asistencias</h2><table class="pl"><tr><th>Jugador</th><th>Equipo</th><th>Asist.</th></tr>
      <?php foreach ($asis as $g): ?><tr><td><?= e(nombre_jugador($g)) ?></td><td><?= e($g['equipo']) ?></td><td><b><?= (int)$g['asistencias'] ?></b></td></tr><?php endforeach; ?></table></div><?php endif; ?>
    <?php if ($vallas): ?><div><h2>Vallas invictas</h2><table class="pl"><tr><th>Equipo</th><th>Partidos sin recibir</th></tr>
      <?php foreach ($vallas as $v): ?><tr><td><?= e($v['nombre']) ?></td><td><?= (int)$v['vallas'] ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
    <?php if ($fp): ?><div><h2>Juego limpio</h2><table class="pl"><tr><th>Equipo</th><th><?= e($T['amarilla']) ?></th><th><?= e($T['roja']) ?></th><th>Puntos</th></tr>
      <?php foreach ($fp as $x): ?><tr><td><?= e($x['nombre']) ?></td><td><?= (int)$x['amarillas'] ?></td><td><?= (int)$x['rojas'] ?></td><td><?= (int)$x['puntos'] ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
  </div>

  <?php if ($num['mayor'] || $num['goleada']): ?>
  <h2>Datos curiosos</h2>
  <ul>
    <?php if ($num['mayor']): ?><li>Partido con más <?= e(mb_strtolower($T['goles'])) ?>: <?= e($num['mayor']['local_nombre'] . ' ' . $num['mayor']['goles_local'] . ' - ' . $num['mayor']['goles_visitante'] . ' ' . $num['mayor']['visitante_nombre']) ?></li><?php endif; ?>
    <?php if ($num['goleada']): ?><li>Mayor diferencia: <?= e($num['goleada']['local_nombre'] . ' ' . $num['goleada']['goles_local'] . ' - ' . $num['goleada']['goles_visitante'] . ' ' . $num['goleada']['visitante_nombre']) ?></li><?php endif; ?>
    <?php foreach ($votos as $v): ?><li><?= e($v['titulo']) ?>: <b><?= e($v['texto']) ?></b> (votación del público)</li><?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <?php if ($arbs): ?>
  <h2>Árbitros</h2>
  <table class="pl"><tr><th>Árbitro</th><th>Partidos</th><th>Calificación promedio</th></tr>
    <?php foreach ($arbs as $a): ?><tr><td><?= e($a['nombre']) ?></td><td><?= (int)$a['n'] ?></td><td><?= $a['nota'] !== null ? e($a['nota']) . ' / 10' : '—' ?></td></tr><?php endforeach; ?>
  </table>
  <?php endif; ?>

  <h2>Dinero del campeonato</h2>
  <table class="pl"><tr><th>Cargos a los equipos</th><th>Cobrado</th><th>Por cobrar</th><th>Gastos del campeonato</th><th>Resultado (cobrado − gastos)</th></tr>
    <tr><td><?= dinero($fin['cargos']) ?></td><td><?= dinero($fin['pagos']) ?></td><td><?= dinero(max(0, $fin['cargos'] - $fin['pagos'])) ?></td><td><?= dinero($fin['egresos']) ?></td><td><b><?= dinero($fin['pagos'] - $fin['egresos']) ?></b></td></tr></table>
  <?php if ($pats): ?><p><b>Patrocinadores:</b> <?= e(implode(' · ', array_column($pats, 'nombre'))) ?>. ¡Gracias por su apoyo!</p><?php endif; ?>
  <div class="firmas"><div>Presidente</div><div>Secretario</div><div>Tesorero</div><div>Comisión disciplinaria</div></div>
  <p style="font-size:11px;color:#555">Generado el <?= date('d/m/Y H:i') ?> con <?= e(marca()) ?>.</p>
    <?php
    doc_fin();
}

/* ============================================================
 * (v15) HOJA DE VOCALÍA — formato de las ligas (soporte físico)
 * Una hoja horizontal por partido: título, fecha, hora, vocal, veedor, escudos, cuadros para el
 * marcador y las dos plantillas (Asis., Jugador, #, Cédula, G, TA, TR) con los suspendidos en rojo,
 * el DT/delegado al final y las firmas de los capitanes. Vacía para llenar a mano, o con lo que ya
 * registró la vocalía (presentes, goles y tarjetas).
 * ============================================================ */
function doc_hojas_vocalia(array $pids, array $liga, string $volver = '', bool $llena = true): void
{
    doc_inicio('Hojas de vocalía', $volver);
    ?>
<style>
  @page { size: A4 landscape; margin: 8mm; }
  .contenedor { max-width: 1120px; }
  .hoja { page-break-after: always; break-after: page; padding: 4px 2px; }
  .hoja:last-child { page-break-after: auto; break-after: auto; }
  .hv-cab { display: grid; grid-template-columns: 1.3fr 1fr 1.3fr; align-items: start; gap: 10px; }
  .hv-tit { color: #2c4f8f; font-weight: 800; font-size: 15px; margin: 0 0 4px; }
  .hv-dato { display: grid; grid-template-columns: 70px 1fr; gap: 2px 8px; font-size: 13px; }
  .hv-dato b { color: #2c4f8f; text-align: right; }
  .hv-lbl { color: #2c4f8f; font-weight: 800; font-size: 14px; margin-top: 6px; }
  .hv-val { font-weight: 700; font-size: 13px; }
  .hv-escudos { display: flex; justify-content: center; gap: 18px; align-items: center; min-height: 80px; }
  .hv-escudos img, .hv-escudos .escudo { width: 72px; height: 72px; object-fit: contain; }
  .hv-escudos .escudo { font-size: 20px; }
  .hv-logo { text-align: right; }
  .hv-logo img, .hv-logo .escudo { width: 110px; height: 90px; object-fit: contain; display: inline-block; }
  .hv-marcador { display: grid; grid-template-columns: 1fr 104px 1fr; align-items: end; gap: 10px; margin: 6px 0 10px; }
  .hv-caja { display: grid; grid-template-columns: 1fr 1fr; border: 2px solid #000; height: 46px; }
  .hv-caja div { border-right: 2px solid #000; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 800; }
  .hv-caja div:last-child { border-right: 0; }
  .hv-equipo { text-align: right; } .hv-equipo.vis { text-align: left; }
  .hv-tablas { display: grid; grid-template-columns: 1fr 1fr; gap: 26px; }
  table.hv { width: 100%; border-collapse: collapse; }
  table.hv th, table.hv td { border: 1px solid #000; padding: 1px 4px; font-size: 11.5px; line-height: 1.35; color: #000; }
  table.hv th { font-weight: 800; text-align: center; background: #fff; border-width: 2px 1px; text-transform: none; letter-spacing: 0; font-size: 12px; }
  table.hv td.c { text-align: center; } table.hv td.r { text-align: right; }
  table.hv tr.susp td { background: #ff1a1a !important; color: #7a0000 !important; }
  table.hv tr.dt td { font-style: italic; }
  .hv-firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 26px; margin-top: 46px; }
  .hv-firmas div { text-align: center; } .hv-firmas span { display: block; width: 60%; margin: 0 auto 4px; border-top: 3px solid #000; }
  .hv-pie { display: flex; justify-content: space-between; font-size: 10px; color: #444; margin-top: 10px; }
  @media print { .hoja { min-height: 180mm; } }
</style>
<?php
    foreach ($pids as $pid) {
        $p = partido_completo((int)$pid);
        if (!$p) continue;
        $susp = suspensiones((int)$p['torneo_id']);
        $vocales = filas('SELECT * FROM vocales_partido WHERE partido_id = ? ORDER BY id', [$p['id']]);
        $pres = $llena ? array_flip(array_column(filas('SELECT jugador_id FROM alineaciones WHERE partido_id = ?', [$p['id']]), 'jugador_id')) : [];
        $ev = [];
        if ($llena) foreach (filas('SELECT jugador_id, tipo FROM eventos WHERE partido_id = ?', [$p['id']]) as $x) {
            $k = in_array($x['tipo'], ['gol', 'penal', 'p1', 'p2', 'p3'], true) ? 'G' : ($x['tipo'] === 'amarilla' ? 'TA' : ($x['tipo'] === 'roja' ? 'TR' : ''));
            if ($k !== '') $ev[(int)$x['jugador_id']][$k] = ($ev[(int)$x['jugador_id']][$k] ?? 0) + 1;
        }
        $jugado = $llena && in_array($p['estado'], ['jugado', 'wo', 'en_vivo'], true);
        $vocal_txt = $vocales ? implode(' · ', array_map(fn($v) => $v['nombre'], $vocales)) : 'Directiva';
        $filas = max(count(filas('SELECT id FROM jugadores WHERE equipo_id = ? AND activo = 1', [$p['local_id']])), count(filas('SELECT id FROM jugadores WHERE equipo_id = ? AND activo = 1', [$p['visitante_id']])), 12) + 2;
        ?>
  <div class="hoja">
    <div class="hv-cab">
      <div>
        <p class="hv-tit">Hoja de Vocalía <?= e($p['torneo'] . ' ' . $p['temporada']) ?></p>
        <div class="hv-dato"><b>Fecha</b><span><?= $p['fecha'] ? e(date('j/n/Y', strtotime($p['fecha']))) : '________' ?></span><b>Hora</b><span><?= e($p['hora'] ?: '______') ?></span><?php if ($p['cancha']): ?><b>Cancha</b><span><?= e($p['cancha']) ?></span><?php endif; ?><b><?= $p['fase'] === 'regular' ? 'Fecha N°' : 'Fase' ?></b><span><?= $p['fase'] === 'regular' ? (int)$p['jornada'] : e(nombre_fase($p['fase'])) ?></span></div>
        <div class="hv-lbl">Vocal</div><div class="hv-val"><?= e($vocal_txt) ?></div>
        <div class="hv-lbl">Veedor</div><div class="hv-val">Directiva</div>
        <div class="hv-lbl">Árbitro</div><div class="hv-val"><?= e(nombre_arbitro($p) ?: '____________________') ?></div>
      </div>
      <div class="hv-escudos"><?= escudo($p['local_escudo'], $p['local_nombre'], $p['local_color'], 'escudo-grande') ?><?= escudo($p['visitante_escudo'], $p['visitante_nombre'], $p['visitante_color'], 'escudo-grande') ?></div>
      <div class="hv-logo"><?= escudo($liga['logo'], $liga['nombre'], $liga['color'], 'escudo-grande') ?></div>
    </div>
    <div class="hv-marcador">
      <div class="hv-equipo"><div class="hv-lbl" style="margin:0">Local:</div><div class="hv-val"><?= e($p['local_nombre']) ?></div></div>
      <div class="hv-caja"><div><?= $jugado ? (int)$p['goles_local'] : '' ?></div><div><?= $jugado ? (int)$p['goles_visitante'] : '' ?></div></div>
      <div class="hv-equipo vis"><div class="hv-lbl" style="margin:0">Visitante:</div><div class="hv-val"><?= e($p['visitante_nombre']) ?></div></div>
    </div>
    <div class="hv-tablas">
    <?php foreach ([[$p['local_id'], $p['local_nombre']], [$p['visitante_id'], $p['visitante_nombre']]] as [$eid, $enom]):
        $jug = filas('SELECT * FROM jugadores WHERE equipo_id = ? AND activo = 1 ORDER BY (numero IS NULL), numero, apellidos, nombres', [$eid]);
        $eq = fila('SELECT delegado FROM equipos WHERE id = ?', [$eid]);
        $n = 0; ?>
      <table class="hv">
        <tr><th style="width:34px">Asis.</th><th>Jugador</th><th style="width:30px">#</th><th style="width:86px">Cédula</th><th style="width:26px">G</th><th style="width:26px">TA</th><th style="width:26px">TR</th></tr>
        <?php foreach ($jug as $j): $s = $susp[(int)$j['id']] ?? null; $x = $ev[(int)$j['id']] ?? []; $n++; ?>
        <tr class="<?= $s ? 'susp' : '' ?>"><td class="c"><?= isset($pres[(int)$j['id']]) ? '✓' : '' ?></td><td><?= e(trim($j['apellidos'] . ' ' . $j['nombres'])) ?></td><td class="r"><?= $j['numero'] !== null ? (int)$j['numero'] : '' ?></td>
          <td><?= e($j['cedula']) ?></td><td class="c"><?= $x['G'] ?? '' ?></td><td class="c"><?= $x['TA'] ?? '' ?></td><td class="c"><?= $x['TR'] ?? '' ?></td></tr>
        <?php endforeach; ?>
        <?php if ($eq && $eq['delegado'] !== ''): $n++; ?><tr class="dt"><td></td><td><?= e($eq['delegado']) ?></td><td class="c">DT</td><td></td><td></td><td></td><td></td></tr><?php endif; ?>
        <?php for (; $n < $filas; $n++): ?><tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td></tr><?php endfor; ?>
      </table>
    <?php endforeach; ?>
    </div>
    <div class="hv-firmas"><div><span></span><b>Firma Capitán</b></div><div><span></span><b>Firma Capitán</b></div></div>
    <div class="hv-pie"><span>En rojo: jugadores suspendidos (no pueden jugar). DT: director técnico o delegado.</span><span><?= e($liga['nombre']) ?> · <?= e(marca()) ?></span></div>
  </div>
<?php
    }
    doc_fin();
}
