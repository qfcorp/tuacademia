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


### App de Android para Google Play (13 nov 2026)
La app (repositorio `qfcorp/tukan-apps`, `apps/tuacademia`, paquete `com.tukansoftware.tuacademia`, firmada con la **llave de subida de
Tukán**) abre `https://tuacademia.tukanec.com/app.php?origen=play`. Código: `app/play.php` (`PLAY_PAQUETE`, `PLAY_HUELLA_SUBIDA`,
`play_huellas()`, `play_assetlinks()` y, al final, la cuenta del revisor), `public/assetlinks.php`, `vps/app_android.sh`,
`app/revisor_google.php` (y `app/demo_google.php`, el mismo programa con el nombre de la guía de tukan-apps).
- **Assetlinks**: `/.well-known/assetlinks.json` sale con la app solo si Google Play está encendido (`app/config.php` `'google_play' => true`).
  La huella de la llave de subida de Tukán (`39:0C:08:...:40:DC`) va siempre; la de «Firma de apps» de Google se agrega después de la
  primera subida (Súper → 📱 Google Play, o `vps/app_android.sh` con la huella). `vps/app_android.sh` enciende Google Play en
  `app/config.php` (sin tocar lo demás), pone el paquete, revisa la regla de nginx y prueba con curl; se puede repetir.
- **Cuenta del revisor**: `crear` arma la **Academia Ejemplo Tukán** aparte (dirección `academia-ejemplo-tukan`, origen `google_play`, datos
  inventados con la receta de la Academia Demo: 5 cursos, ~34 alumnos, asistencia, pensiones, niveles, recital, clases particulares),
  plan **Completo** activo por 1 año sin pagos a Tukán, usuario `revisor.google@tukanec.com` (administrador, sin doble verificación).
  La contraseña (`Demo-` + 6 números) sale **una sola vez**; `crear` otra vez rehace todo con clave nueva; `borrar` lo quita. Nunca toca
  otra academia: si el correo es de otra cuenta o la dirección es de otra academia, se niega. Solo por consola (por la web: 404).
- **Dentro de la app** (`en_app_play()`): el plan solo se paga con Google Play (Básico y Completo; sin disciplinas adicionales). No se ven
  el valor del software de la web («Su mensual», «Lo que paga hoy»), cotizaciones, «Solicitar este módulo», transferencia ni WhatsApp de
  ventas; la portada, pedir demo, planes y comparar llevan al inicio de la app. Las pensiones, matrículas y vestuario que los padres pagan
  a la academia sí se quedan (servicio presencial).

En el VPS del ERP (PuTTY), una línea a la vez:
```bash
cd $HOME/tuacademia && git pull && cd $HOME && sudo bash tuacademia/vps/vps/vps_2_codigo.sh && sudo bash /var/www/tuacademia/vps/app_android.sh
```
Debe terminar en `OK: la app de Android ya puede abrir https://tuacademia.tukanec.com ...`. Después, la cuenta del revisor (copiar la contraseña):
```bash
sudo docker exec -u www-data tuacademia-php php /var/www/tuacademia/app/revisor_google.php crear
```
Ver si existe / borrarla cuando ya no se necesite:
```bash
sudo docker exec -u www-data tuacademia-php php /var/www/tuacademia/app/revisor_google.php estado
sudo docker exec -u www-data tuacademia-php php /var/www/tuacademia/app/revisor_google.php borrar
```
Cuando Google dé la huella de «Firma de apps» (Play Console → Integridad de la app), Claude arma el comando
`sudo bash /var/www/tuacademia/vps/app_android.sh` seguido de esa huella (la agrega sin borrar la de Tukán).
Prueba: `bash pruebas/v38/reiniciar.sh && python3 pruebas/app_android/prueba_app_android.py` → **73/73**.
