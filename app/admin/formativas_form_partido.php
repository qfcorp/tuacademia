<?php /* (v17) Formulario de datos de un partido de divisiones formativas ($pe = partido o ['grupo_id' => ...], $grupos) */ ?>
<form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="partido"><input type="hidden" name="id" value="<?= (int)($pe['id'] ?? 0) ?>">
  <div class="campos">
    <div><label>Categoría</label><select name="grupo_id"><?= opciones_grupos($grupos, (int)($pe['grupo_id'] ?? 0), false) ?></select></div>
    <div><label>Rival</label><input type="text" name="rival" required maxlength="80" value="<?= e($pe['rival'] ?? '') ?>"></div>
    <div><label>Fecha</label><input type="date" name="fecha" value="<?= e($pe['fecha'] ?? date('Y-m-d')) ?>"></div>
    <div><label>Hora</label><input type="time" name="hora" value="<?= e($pe['hora'] ?? '') ?>"></div>
    <div><label>Competencia</label><select name="competencia"><?php foreach (competencias_partido() as $c): ?><option <?= ($pe['competencia'] ?? 'Liga') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
    <div><label>Condición</label><select name="condicion"><option value="local">🏠 Local</option><option value="visitante" <?= ($pe['condicion'] ?? '') === 'visitante' ? 'selected' : '' ?>>✈️ Visitante</option></select></div>
    <div><label>Cancha / lugar</label><input type="text" name="lugar" maxlength="120" value="<?= e($pe['lugar'] ?? '') ?>"></div>
    <div><label>Duración (min)</label><input type="number" name="duracion" min="20" max="120" value="<?= (int)($pe['duracion'] ?? 70) ?>"></div>
  </div>
  <button class="btn">💾 Guardar partido</button>
</form>
