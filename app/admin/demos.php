<?php
/* (v14) SOLICITUDES DE DEMO (solo superadministrador): quién pidió, contactar, crear su cuenta de prueba o descartar */
if (!es_super()) ir('admin.php');
$yo = usuario();

if (es_post()) {
    $s = fila('SELECT * FROM solicitudes_demo WHERE id = ?', [(int)post('id')]) ?? throw new RuntimeException('Solicitud no encontrada.');
    $accion = post('accion');
    if (in_array($accion, ['contactada', 'descartada', 'nueva'], true)) {
        q('UPDATE solicitudes_demo SET estado = ?, nota = ?, atendido_por = ?, atendido_en = ? WHERE id = ?', [$accion, mb_substr(post('nota', $s['nota']), 0, 300), $yo['nombre'], date('Y-m-d H:i'), $s['id']]);
        flash('Solicitud marcada como: ' . estados_solicitud_demo()[$accion] . '.');
    } elseif ($accion === 'nota') {
        q('UPDATE solicitudes_demo SET nota = ? WHERE id = ?', [mb_substr(post('nota'), 0, 300), $s['id']]);
        flash('Nota guardada.');
    }
    ir('admin.php?p=demos&estado=' . rawurlencode(get('estado', 'nueva')));
}

$estado = array_key_exists(get('estado'), estados_solicitud_demo()) ? get('estado') : (get('estado') === 'todas' ? 'todas' : 'nueva');
$lista = filas('SELECT s.*, p.nombre AS plan, v.nombre AS vendedor, l.nombre AS liga, l.slug FROM solicitudes_demo s LEFT JOIN planes p ON p.id = s.plan_id
                LEFT JOIN vendedores v ON v.id = s.vendedor_id LEFT JOIN ligas l ON l.id = s.liga_id'
    . ($estado === 'todas' ? '' : ' WHERE s.estado = ?') . ' ORDER BY s.id DESC LIMIT 200', $estado === 'todas' ? [] : [$estado]);
cabecera_admin('Solicitudes de demo');
?>
<h1>📝 Solicitudes de demo</h1>
<p class="suave">La prueba gratis automática está <?= prueba_automatica() ? '<b>abierta</b>' : '<b>cerrada</b>' ?> (se cambia en <a href="admin.php?p=ajustes">Ajustes</a>). Los interesados piden la demo en <a href="index.php?v=solicitar" target="_blank"><?= e(url_base()) ?>solicitar-demo</a> y usted decide a quién se la crea y por cuántos días.</p>
<nav class="pestanas">
  <?php foreach (estados_solicitud_demo() + ['todas' => 'Todas'] as $k => $v): $n = $k === 'todas' ? (int)valor('SELECT COUNT(*) FROM solicitudes_demo') : (int)valor('SELECT COUNT(*) FROM solicitudes_demo WHERE estado = ?', [$k]); ?>
    <a class="<?= $estado === $k ? 'activa' : '' ?>" href="admin.php?p=demos&amp;estado=<?= $k ?>"><?= e($v) ?> (<?= $n ?>)</a>
  <?php endforeach; ?>
</nav>
<?php if (!$lista): ?><p class="suave">No hay solicitudes aquí.</p><?php endif; ?>
<?php foreach ($lista as $s): $wa = telefono_wa($s['telefono']); ?>
<div class="tarjeta">
  <div class="fila-titulo"><h3 style="margin:0"><?= $s['tipo'] === 'escuela' ? '🎓' : '🏆' ?> <?= e($s['organizacion']) ?> <small class="suave"><?= e($s['ciudad']) ?></small></h3>
    <span class="etiqueta"><?= e(estados_solicitud_demo()[$s['estado']] ?? $s['estado']) ?></span></div>
  <p style="margin:.4rem 0">👤 <b><?= e($s['contacto']) ?></b> · 📞 <?= e($s['telefono']) ?><?= $s['email'] ? ' · ✉ ' . e($s['email']) : '' ?> · <?= e(substr($s['creado'], 0, 16)) ?></p>
  <p style="margin:.4rem 0"><?= $s['tamano'] ? 'Tamaño: ' . e($s['tamano']) . ' · ' : '' ?><?= $s['plan'] ? 'Le interesa: <b>' . e($s['plan']) . '</b> · ' : '' ?><?= $s['vendedor'] ? '🤝 Vendedor: ' . e($s['vendedor']) : 'Venta directa' ?></p>
  <?php if (($s['deportes'] ?? '') !== ''): ?><p style="margin:.4rem 0">🧩 Deportes: <b><?= e(texto_modulos(explode(',', $s['deportes']))) ?></b></p><?php endif; ?>
  <?php if ($s['mensaje']): ?><p style="margin:.4rem 0" class="suave">“<?= e($s['mensaje']) ?>”</p><?php endif; ?>
  <?php if ($s['liga']): ?><p style="margin:.4rem 0">✓ Cuenta creada: <a href="admin.php?p=ligas&amp;editar=<?= (int)$s['liga_id'] ?>"><?= e($s['liga']) ?></a></p><?php endif; ?>
  <div class="acciones" style="margin-top:6px">
    <?php if ($wa): ?><a class="btn btn-sec btn-chico" target="_blank" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode(mensaje_contacto_demo($s)) ?>">📲 Contactar por WhatsApp</a><?php endif; ?>
    <?php if ($s['estado'] !== 'creada'): ?><a class="btn btn-chico" href="admin.php?p=ligas&amp;nueva=1&amp;demo=<?= (int)$s['id'] ?>">➕ Crear su cuenta de demo</a><?php endif; ?>
    <?php foreach (['contactada' => 'Marcar contactada', 'descartada' => 'Descartar', 'nueva' => 'Volver a nueva'] as $k => $t): if ($s['estado'] === $k || $s['estado'] === 'creada') continue; ?>
      <form class="inline" method="post" action="admin.php?p=demos&amp;estado=<?= e($estado) ?>"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sec btn-chico" name="accion" value="<?= $k ?>"><?= e($t) ?></button></form>
    <?php endforeach; ?>
  </div>
  <form method="post" class="acciones" style="margin-top:6px" action="admin.php?p=demos&amp;estado=<?= e($estado) ?>"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="accion" value="nota">
    <input type="text" name="nota" maxlength="300" value="<?= e($s['nota']) ?>" placeholder="Nota interna (ej: llamar el lunes)" style="flex:1"><button class="btn btn-sec btn-chico">Guardar nota</button></form>
</div>
<?php endforeach;
pie_admin();
