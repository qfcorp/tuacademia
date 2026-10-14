<?php
/*
 * (TuAcademia, 14-oct-2026) 🔔 AVISOS POR TELEGRAM A LOS PADRES
 * La academia elige qué avisos automáticos se envían (pensión por vencer y con cuántos días, vencida, pago recibido, falta a clase
 * y comunicados), escribe comunicados para todas las familias o para un curso y ve cuántas familias ya conectaron su Telegram.
 * La lógica está en app/acad_telegram.php.
 */
$liga = requiere_liga();
$lid = (int)$liga['id'];
if (!es_escuela($liga)) ir('admin.php');
$ed = puede('cobros', true);

if (es_post()) {
    if (!$ed) throw new RuntimeException('Su perfil no puede cambiar los avisos.');
    $acc = post('accion');
    if ($acc === 'opciones') {
        $o = ['activo' => post('activo') === '1' ? 1 : 0, 'dias' => entero(post('dias'), 0, 15)];
        foreach (array_keys(acad_tg_tipos()) as $k) $o[$k] = post('t_' . $k) === '1' ? 1 : 0;
        q('UPDATE ligas SET acad_tg = ? WHERE id = ?', [json_encode($o), $lid]);
        flash($o['activo'] ? 'Avisos guardados.' : 'Avisos por Telegram apagados para su academia.');
    } elseif ($acc === 'comunicado') {
        $liga = fila('SELECT * FROM ligas WHERE id = ?', [$lid]);
        $n = acad_tg_comunicado($liga, post('titulo'), post('texto'), (int)post('grupo_id'), post('token'));
        // No se envía aquí mismo: la tarea automática lo manda en menos de un minuto (si Telegram tarda o falla, la pantalla no se queda esperando)
        flash($n ? 'Comunicado en camino a ' . $n . ' familia(s) por Telegram (llega en menos de un minuto).' : 'Ninguna familia nueva lo recibió (ya se había enviado o todavía no hay familias con Telegram conectado).');
    }
    ir('admin.php?p=avisos_padres');
}

$liga = fila('SELECT * FROM ligas WHERE id = ?', [$lid]);
$o = acad_tg_opciones($liga);
$cif = acad_tg_cifras($lid);
$disp = tg_activo() && modulo_activo($liga, 'avisos');
$ultimos = filas('SELECT tipo, texto, creado, chats FROM acad_tg_avisos WHERE liga_id = ? AND texto <> \'\' ORDER BY id DESC LIMIT 15', [$lid]);
$grupos = grupos_visibles($lid);
cabecera_admin('Avisos por Telegram');
?>
<h1>🔔 Avisos por Telegram a los padres</h1>
<p class="suave" style="margin-top:0">Los padres conectan su Telegram una sola vez desde su <b>portal de padres</b> (botón «Conectar Telegram»). Desde ahí les llegan solos los avisos que usted elija aquí. Cada aviso se envía <b>una sola vez</b>.</p>

<?php if (!tg_activo()): ?>
  <div class="aviso aviso-info">Los avisos por Telegram todavía no están encendidos en el sistema. Escríbale a <?= e(ajuste('empresa', 'Tukán Software')) ?> para activarlos.</div>
<?php elseif (!modulo_activo($liga, 'avisos')): ?>
  <div class="aviso aviso-info">Su plan no incluye los avisos por Telegram. <a href="admin.php?p=mi_plan">Ver planes</a>.</div>
<?php endif; ?>

<div class="cifras">
  <div class="cifra"><b><?= $cif['conectadas'] ?> / <?= $cif['familias'] ?></b>Familias con Telegram conectado</div>
  <div class="cifra"><b><?= $cif['avisos_mes'] ?></b>Avisos enviados este mes</div>
</div>

<div class="rejilla-2">
<form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="opciones">
  <h2 style="margin-top:0">Qué avisos se envían</h2>
  <label class="check"><input type="checkbox" name="activo" value="1" <?= $o['activo'] ? 'checked' : '' ?>> <b>Enviar avisos por Telegram a los padres</b></label>
  <div style="margin:10px 0 0 4px">
  <?php foreach (acad_tg_tipos() as $k => [$n, $d]): ?>
    <label class="check" style="font-weight:400;margin-bottom:8px"><input type="checkbox" name="t_<?= e($k) ?>" value="1" <?= $o[$k] ? 'checked' : '' ?>> <b><?= e($n) ?></b><br><small class="suave"><?= e($d) ?></small></label>
    <?php if ($k === 'por_vencer'): ?><div class="acciones" style="margin:-2px 0 10px 28px"><input type="number" name="dias" min="0" max="15" value="<?= (int)$o['dias'] ?>" style="max-width:80px" aria-label="Días antes"> <span class="suave">días antes del vencimiento</span></div><?php endif; ?>
  <?php endforeach; ?>
  </div>
  <?php if ($ed): ?><button class="btn">Guardar</button><?php endif; ?>
</form>

<form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="comunicado"><input type="hidden" name="token" value="<?= bin2hex(random_bytes(6)) ?>">
  <h2 style="margin-top:0">📣 Enviar un comunicado</h2>
  <p class="suave" style="margin-top:0">Ej: «Mañana no hay clases por feriado», «Ensayo general el sábado a las 15:00».</p>
  <p><label>Para</label><select name="grupo_id"><option value="0">Todas las familias</option><?php foreach ($grupos as $g): ?><option value="<?= (int)$g['id'] ?>">Solo el curso <?= e($g['nombre']) ?></option><?php endforeach; ?></select></p>
  <p><label>Título</label><input type="text" name="titulo" maxlength="100" placeholder="Comunicado"></p>
  <p><label>Mensaje</label><textarea name="texto" rows="4" maxlength="1500" required></textarea></p>
  <?php if ($ed): ?><button class="btn" <?= $disp && $o['activo'] && $o['comunicado'] ? '' : 'disabled' ?>>Enviar por Telegram</button><?php endif; ?>
  <p class="ayuda">Lo reciben las familias que conectaron su Telegram. A las demás puede escribirles por WhatsApp desde <a href="admin.php?p=mensajes">Mensajes de hoy</a>.</p>
</form>
</div>

<div class="tarjeta">
  <h2 style="margin-top:0">Últimos avisos</h2>
  <?php if (!$ultimos): ?><p class="suave">Todavía no se ha enviado ningún aviso.</p><?php else: ?>
  <?php foreach ($ultimos as $x): ?>
    <div style="border-top:1px solid var(--borde);padding:8px 0"><b><?= e(acad_tg_tipos()[$x['tipo']][0] ?? $x['tipo']) ?></b> <small class="suave">· <?= e(date('d/m H:i', strtotime($x['creado']))) ?> · <?= (int)$x['chats'] ?> Telegram</small>
      <div class="suave" style="font-size:.88rem"><?= e(mb_strimwidth(preg_replace('/\s+/u', ' ', strip_tags($x['texto'])), 0, 170, '…')) ?></div></div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php
pie_admin();
