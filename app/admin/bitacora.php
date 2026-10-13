<?php
/* BITÁCORA DE AUDITORÍA: quién hizo qué, cuándo, desde dónde, y qué cambió */
$u = usuario();
$liga = liga_trabajo();
if (!es_super() && !$liga) ir('admin.php');

$por_pagina = 100;
$pag = max(1, (int)get('pag', '1'));
$w = [];
$prm = [];
if (!es_super()) {
    $w[] = 'b.liga_id = ?';
    $prm[] = (int)$u['liga_id'];
} elseif (get('todas') !== '1' && $liga) {
    $w[] = '(b.liga_id = ? OR b.liga_id IS NULL)'; // el superadmin también ve lo general (ingresos, usuarios, ligas)
    $prm[] = (int)$liga['id'];
}
if (get('desde') !== '' && strtotime(get('desde'))) { $w[] = "b.fecha >= ?"; $prm[] = gmdate('Y-m-d H:i:s', strtotime(get('desde') . ' 00:00:00')); }
if (get('hasta') !== '' && strtotime(get('hasta'))) { $w[] = "b.fecha <= ?"; $prm[] = gmdate('Y-m-d H:i:s', strtotime(get('hasta') . ' 23:59:59')); }
if (get('usuario') !== '') { $w[] = 'b.usuario = ?'; $prm[] = get('usuario'); }
if (get('tabla') !== '') { $w[] = 'b.tabla = ?'; $prm[] = get('tabla'); }
if (get('operacion') !== '') { $w[] = 'b.operacion = ?'; $prm[] = get('operacion'); }
if (get('origen') !== '') { $w[] = 'b.origen = ?'; $prm[] = get('origen'); }
if (get('texto') !== '') { $w[] = '(b.antes LIKE ? OR b.despues LIKE ? OR b.descripcion LIKE ? OR b.usuario LIKE ?)'; $t = '%' . get('texto') . '%'; array_push($prm, $t, $t, $t, $t); }
// Historial de un partido, jugador o equipo (incluye lo relacionado: goles, tarjetas, presentes, pagos...)
foreach (['partido' => ['partidos', 'partido_id'], 'jugador' => ['jugadores', 'jugador_id'], 'equipo' => ['equipos', 'equipo_id']] as $k => [$tb, $col]) {
    if (get($k) !== '') {
        $id = (int)get($k);
        $w[] = "((b.tabla = '$tb' AND b.registro_id = ?) OR json_extract(COALESCE(b.despues, b.antes), '$.$col') = ?)";
        array_push($prm, $id, $id);
    }
}
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

if (get('csv') === '1') {
    bitacora_evento('exportacion', 'Descargó la bitácora de auditoría en Excel');
    $filas = filas("SELECT b.* FROM bitacora b $where ORDER BY b.id DESC LIMIT 50000", $prm);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bitacora_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Fecha (hora Ecuador)', 'Usuario', 'Rol', 'Desde', 'IP', 'Acción', 'Qué', 'Registro', 'Detalle de cambios'], ';');
    foreach ($filas as $r) {
        $d = json_decode($r['despues'] ?? $r['antes'] ?? '[]', true) ?: [];
        $cambios = implode(' | ', array_map(fn($c) => $c[0] . ': ' . ($c[1] !== '' ? $c[1] . ' -> ' : '') . $c[2], detalle_cambios($r)));
        fputcsv($out, [fecha_local($r['fecha']), $r['usuario'], $r['rol'], $r['origen'], $r['ip'], $r['operacion'] === 'evento' ? $r['descripcion'] : nombre_operacion($r['operacion']),
            nombre_tabla($r['tabla']), '#' . $r['registro_id'] . ' ' . etiqueta_registro($r['tabla'], $d), $cambios], ';');
    }
    exit;
}

function fecha_local(string $utc): string
{
    try {
        return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(CFG['zona']))->format('Y-m-d H:i:s');
    } catch (Exception) {
        return $utc;
    }
}

$total = (int)valor("SELECT COUNT(*) FROM bitacora b $where", $prm);
$filas = filas("SELECT b.* FROM bitacora b $where ORDER BY b.id DESC LIMIT $por_pagina OFFSET " . (($pag - 1) * $por_pagina), $prm);
$scope = es_super() && get('todas') === '1' ? '' : 'WHERE liga_id = ' . (int)(es_super() ? ($liga['id'] ?? 0) : $u['liga_id']);
$usuarios_log = array_column(filas("SELECT DISTINCT usuario FROM bitacora $scope ORDER BY usuario LIMIT 300"), 'usuario');
$tablas_log = array_column(filas("SELECT DISTINCT tabla FROM bitacora $scope ORDER BY tabla"), 'tabla');
$qs = $_GET;
unset($qs['pag'], $qs['p']);
$base = 'admin.php?p=bitacora&' . http_build_query($qs);
$colores = ['crear' => 'verde', 'modificar' => 'amarilla', 'eliminar' => 'roja', 'evento' => 'gris'];

cabecera_admin('Bitácora');
?>
<div class="fila-titulo">
  <h1>🔎 Bitácora de auditoría</h1>
  <a class="btn btn-sec btn-chico" href="<?= e($base) ?>&amp;csv=1">⬇ Descargar en Excel</a>
</div>
<p class="suave">Todo cambio en el sistema queda registrado aquí automáticamente: quién lo hizo, desde dónde, cuándo y qué valores tenía antes y después. Este registro <b>no se puede borrar ni modificar</b>.</p>
<?php if (get('partido') !== '' || get('jugador') !== '' || get('equipo') !== ''): ?>
  <div class="aviso aviso-info">Mostrando el historial completo de <?= get('partido') !== '' ? 'este partido (resultado, goles, tarjetas, presentes, vocalía, informe…)' : (get('jugador') !== '' ? 'este jugador' : 'este equipo (incluye sus jugadores y pagos)') ?>. <a href="admin.php?p=bitacora">Ver todo</a></div>
<?php endif; ?>
<form method="get" class="tarjeta">
  <input type="hidden" name="p" value="bitacora">
  <?php foreach (['partido', 'jugador', 'equipo'] as $k) if (get($k) !== '') echo '<input type="hidden" name="' . $k . '" value="' . (int)get($k) . '">'; ?>
  <div class="campos">
    <div><label>Desde</label><input type="date" name="desde" value="<?= e(get('desde')) ?>"></div>
    <div><label>Hasta</label><input type="date" name="hasta" value="<?= e(get('hasta')) ?>"></div>
    <div><label>Usuario</label><select name="usuario"><option value="">Todos</option><?php foreach ($usuarios_log as $x): ?><option <?= get('usuario') === $x ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></div>
    <div><label>Qué</label><select name="tabla"><option value="">Todo</option><?php foreach ($tablas_log as $x): ?><option value="<?= e($x) ?>" <?= get('tabla') === $x ? 'selected' : '' ?>><?= e(nombre_tabla($x)) ?></option><?php endforeach; ?></select></div>
    <div><label>Acción</label><select name="operacion"><option value="">Todas</option><?php foreach (['crear' => 'Creó', 'modificar' => 'Modificó', 'eliminar' => 'Eliminó', 'evento' => 'Eventos (ingresos, descargas…)'] as $k => $x): ?><option value="<?= $k ?>" <?= get('operacion') === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></div>
    <div><label>Desde dónde</label><select name="origen"><option value="">Todos</option><?php foreach (['panel' => 'Panel de la liga', 'vocal' => 'Vocalía', 'arbitro' => 'Árbitro', 'delegado' => 'Delegado', 'publico' => 'Página pública', 'sistema' => 'Sistema'] as $k => $x): ?><option value="<?= $k ?>" <?= get('origen') === $k ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select></div>
    <div><label>Buscar texto</label><input type="search" name="texto" value="<?= e(get('texto')) ?>" placeholder="nombre, cédula, valor…"></div>
    <?php if (es_super()): ?><div><label class="check" style="margin-top:28px"><input type="checkbox" name="todas" value="1" <?= get('todas') === '1' ? 'checked' : '' ?>> Todas las ligas</label></div><?php endif; ?>
  </div>
  <button class="btn btn-sec">Filtrar</button> <a href="admin.php?p=bitacora">Limpiar</a>
</form>

<p class="suave"><?= number_format($total) ?> registros<?= $total > $por_pagina ? ' · página ' . $pag . ' de ' . ceil($total / $por_pagina) : '' ?></p>
<div class="tabla-scroll"><table>
  <tr><th>Fecha y hora</th><th>Quién</th><th>Acción</th><th>Detalle</th></tr>
  <?php foreach ($filas as $r):
      $d = json_decode($r['despues'] ?? $r['antes'] ?? '[]', true) ?: [];
      $cambios = detalle_cambios($r);
      $etq = etiqueta_registro($r['tabla'], $d);
  ?>
  <tr>
    <td style="white-space:nowrap"><?= e(fecha_local($r['fecha'])) ?></td>
    <td style="white-space:normal;min-width:170px"><b><?= e($r['usuario']) ?></b><br><small class="suave"><?= e($r['rol']) ?> · <?= e($r['origen']) ?><?= $r['ip'] !== '' ? ' · IP ' . e($r['ip']) : '' ?></small></td>
    <td style="white-space:normal;min-width:180px">
      <span class="etiqueta <?= $colores[$r['operacion']] ?? '' ?>"><?= e($r['operacion'] === 'evento' ? 'Evento' : nombre_operacion($r['operacion'])) ?></span>
      <?= e(nombre_tabla($r['tabla'])) ?><?= $r['registro_id'] ? ' <small>#' . (int)$r['registro_id'] . '</small>' : '' ?>
      <?= $etq !== '' ? '<br><b>' . e($etq) . '</b>' : '' ?>
    </td>
    <td style="white-space:normal;min-width:260px;font-size:.88rem">
      <?php if ($r['operacion'] === 'evento'): ?><?= e($r['descripcion']) ?><?php endif; ?>
      <?php foreach ($cambios as [$campo, $antes, $despues]): ?>
        <div><b><?= e($campo) ?>:</b> <?php if ($r['operacion'] === 'modificar'): ?><span style="color:var(--roja);text-decoration:line-through"><?= e($antes) ?></span> → <span style="color:#1a9e5c;font-weight:700"><?= e($despues) ?></span><?php else: ?><?= e($despues) ?><?php endif; ?></div>
      <?php endforeach; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$filas): ?><tr><td colspan="4" class="suave">Sin registros con esos filtros.</td></tr><?php endif; ?>
</table></div>
<div class="acciones" style="margin-top:12px">
  <?php if ($pag > 1): ?><a class="btn btn-sec btn-chico" href="<?= e($base . '&pag=' . ($pag - 1)) ?>">← Más recientes</a><?php endif; ?>
  <?php if ($pag * $por_pagina < $total): ?><a class="btn btn-sec btn-chico" href="<?= e($base . '&pag=' . ($pag + 1)) ?>">Más antiguos →</a><?php endif; ?>
</div>
<?php
pie_admin();
