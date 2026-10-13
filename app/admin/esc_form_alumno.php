<?php
/* (v11) Campos del formulario del alumno. Se incluye desde alumnos.php y alumno.php con $a (valores) y $grupos. */
$v = fn(string $k, $def = '') => e((string)($a[$k] ?? $def));
?>
    <h3 style="margin:.4rem 0">Alumno</h3>
    <div class="campos">
      <div><label>Nombres *</label><input type="text" name="nombres" required maxlength="60" value="<?= $v('nombres') ?>"></div>
      <div><label>Apellidos</label><input type="text" name="apellidos" maxlength="60" value="<?= $v('apellidos') ?>"></div>
      <div><label>Cédula</label><input type="text" name="cedula" maxlength="20" value="<?= $v('cedula') ?>"></div>
      <div><label>Fecha de nacimiento *</label><input type="date" name="fecha_nac" required value="<?= $v('fecha_nac') ?>"><div class="ayuda">Define la categoría Sub.</div></div>
      <div><label>Género</label><select name="genero"><option value="">—</option><?php foreach (['M' => 'Masculino', 'F' => 'Femenino'] as $k => $t): ?><option value="<?= $k ?>" <?= ($a['genero'] ?? '') === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
      <div><label>Grupo</label><select name="grupo_id"><?= opciones_grupos($grupos, isset($a['grupo_id']) ? (int)$a['grupo_id'] : null) ?></select><div class="ayuda">Vacío = se asigna por la edad.</div></div>
      <div><label>Posición</label><input type="text" name="posicion" maxlength="30" value="<?= $v('posicion') ?>"></div>
      <div><label>Pie hábil</label><select name="pie"><option value="">—</option><?php foreach (['derecho', 'izquierdo', 'ambos'] as $t): ?><option value="<?= $t ?>" <?= ($a['pie'] ?? '') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option><?php endforeach; ?></select></div>
      <div><label>Colegio</label><input type="text" name="colegio" maxlength="100" value="<?= $v('colegio') ?>"></div>
      <div class="campo-ancho"><label>Dirección</label><input type="text" name="direccion" maxlength="160" value="<?= $v('direccion') ?>"></div>
      <div><label>Foto</label><input type="file" name="foto" accept="image/*" capture="environment"></div>
      <div><label class="check" style="margin-top:26px;font-weight:400"><input type="checkbox" name="foto_publica" value="1" <?= !empty($a['foto_publica']) ? 'checked' : '' ?>> Se puede publicar su foto</label></div>
    </div>
    <h3 style="margin:.8rem 0 .4rem">Representante</h3>
    <div class="campos">
      <div><label>Nombre</label><input type="text" name="representante_nombre" maxlength="80" value="<?= $v('representante_nombre') ?>"></div>
      <div><label>Cédula</label><input type="text" name="representante_cedula" maxlength="20" value="<?= $v('representante_cedula') ?>"><div class="ayuda">Con la misma cédula se agrupan hermanos (descuento).</div></div>
      <div><label>Parentesco</label><input type="text" name="parentesco" maxlength="30" value="<?= $v('parentesco') ?>" placeholder="Madre, padre…"></div>
      <div><label>Celular (WhatsApp)</label><input type="tel" name="representante_telefono" maxlength="30" value="<?= $v('representante_telefono') ?>"></div>
      <div><label>Correo</label><input type="email" name="representante_email" value="<?= $v('representante_email') ?>"></div>
    </div>
    <details style="margin-top:8px"><summary><b>🩺 Ficha médica y emergencia</b></summary>
      <div class="campos" style="margin-top:8px">
        <div><label>Tipo de sangre</label><select name="tipo_sangre"><option value="">—</option><?php foreach (['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'] as $t): ?><option <?= ($a['tipo_sangre'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
        <div><label>Alergias</label><input type="text" name="alergias" maxlength="200" value="<?= $v('alergias') ?>"></div>
        <div><label>Enfermedades o condiciones</label><input type="text" name="condiciones" maxlength="200" value="<?= $v('condiciones') ?>" placeholder="Asma, diabetes…"></div>
        <div><label>Medicamentos</label><input type="text" name="medicamentos" maxlength="200" value="<?= $v('medicamentos') ?>"></div>
        <div><label>Seguro médico</label><input type="text" name="seguro" maxlength="100" value="<?= $v('seguro') ?>"></div>
        <div><label>Contacto de emergencia</label><input type="text" name="emergencia_nombre" maxlength="80" value="<?= $v('emergencia_nombre') ?>"></div>
        <div><label>Teléfono de emergencia</label><input type="tel" name="emergencia_telefono" maxlength="30" value="<?= $v('emergencia_telefono') ?>"></div>
      </div>
    </details>
    <details style="margin-top:8px"><summary><b>💵 Pagos del alumno</b></summary>
      <div class="campos" style="margin-top:8px">
        <div><label>Beca (%)</label><input type="number" name="beca" min="0" max="100" step="1" value="<?= $v('beca', '0') ?>"></div>
        <div><label>Mensualidad especial ($)</label><input type="number" name="mensualidad_especial" min="0" step="0.01" value="<?= isset($a['mensualidad_especial']) && $a['mensualidad_especial'] !== null ? e(number_format((float)$a['mensualidad_especial'], 2, '.', '')) : '' ?>" placeholder="la del grupo"></div>
        <div><label>Día de vencimiento</label><input type="number" name="dia_vence" min="1" max="28" value="<?= $v('dia_vence') ?>" placeholder="el de la escuela"></div>
        <div><label>Fecha de ingreso</label><input type="date" name="fecha_ingreso" value="<?= $v('fecha_ingreso', date('Y-m-d')) ?>"></div>
      </div>
    </details>
    <p><label>Notas</label><input type="text" name="notas" maxlength="300" value="<?= $v('notas') ?>"></p>
