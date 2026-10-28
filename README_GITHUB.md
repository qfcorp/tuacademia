# TuAcademia (código)
Versión de TuVocalía para academias (baile, música, natación y deportes). Ver README.md y BLOC_DE_NOTAS.md.
No contiene la base de datos, fotos, sesiones, claves (app/config.php) ni la llave de firma de Android.

### Seguridad de los usuarios del panel (21 oct 2026)
Código en `app/perfiles.php` (`validar_perfil_cuenta`, `exigir_otro_admin_activo`), igual en TuAcademia y TuVocalía; lo usan
`crear_usuario_liga()` (`app/admin/comun.php`), **Usuarios** (`app/admin/usuarios.php`, el administrador de la cuenta y el súper) y
**Ligas → Editar → Usuarios** (`app/admin/ligas.php`, el súper).
- **Perfil**: tiene que estar en `perfiles_para()` del tipo de cuenta (liga o escuela), revisado en el servidor al **crear** y al
  **cambiar**. Si no, sale el error «El perfil elegido no es válido para esta cuenta» y no se crea ni se cambia nada. Antes un perfil
  inventado (o ninguno) se guardaba como **Administrador** en silencio. Las listas de perfiles del súper ahora muestran solo los de
  la cuenta de cada usuario.
- **Su propia cuenta** (se mantiene): nadie se cambia el perfil, se desactiva ni se elimina a sí mismo.
- **Siempre al menos 1 Administrador activo por liga o escuela**: al último no se le quita el perfil Administrador, no se desactiva
  ni se elimina (tampoco el súper). Un Administrador desactivado no cuenta.
- Prueba: `TA_PUERTO=8241 TA_SIM=8242 bash pruebas/v38/reiniciar.sh && TA_PUERTO=8241 python3 pruebas/usuarios/prueba_usuarios.py` → **43/43 en modo academia y en modo ligas** (con el código anterior fallan 26).

### Ayuda, Asistente IA y «Enviar por correo» (21 oct 2026)
Ver BLOC_DE_NOTAS §16. Código en `app/asistente_ia.php` (igual que en TuVocalía) con las librerías del Panel `app/tukan_ia.php` y
`app/tukan_correo.php` (copias sin cambios). Ayuda para todos (`admin.php?p=ayuda`), preguntas frecuentes del súper (`ayuda_faq`),
módulo «Asistente IA» (`asistente_ia`: incluido en el plan Completo; desde el 28 oct 2026, $5 al mes en el Básico, fuera de los 3 módulos a elegir; tope de IA $4 al mes por academia; en demo, $0,50 en total), `ia_uso` / `ia_tope` en el conector (revisión 4) y «Enviar por correo» en
«Demo lista». Configuración del VPS: `sudo bash /var/www/tuacademia/vps/configurar_ia_correo.sh` (escribe `data/tukan-ia.env` y
`data/tukan-correo.env`). Prueba: `TA_PUERTO=8441 TA_SIM=8462 bash pruebas/v38/reiniciar.sh && TA_PUERTO=8441 python3 pruebas/ia/prueba_ia.py`
(necesita `../tukan-central/tests`, o `TUKAN_CENTRAL_DIR`).

