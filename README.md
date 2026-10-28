# TuAcademia (nombre provisional) · Tukán Software

Sistema para **academias de baile, música, natación y escuelas deportivas**: alumnos, cursos y horarios, asistencia,
pensiones con recordatorio por WhatsApp, DeUna y transferencia con comprobante, pago con tarjeta (Payphone),
niveles con examen y certificado con QR, recitales con cobro de vestuario, clases particulares con paquetes
y portal de padres.

Sale de **TuVocalía v38** (rama `v38-apps` de `qfcorp/tuvocalia`): es el mismo programa con el **producto academia**
encendido. Todo lo de ligas y campeonatos sigue en el código, apagado.

- Dirección provisional: **https://tuacademia.tukanec.com**
- PHP 8.3 a 8.5, SQLite, sin framework. Base en `data/ligas.sqlite` (se crea sola).
- Marca, empresa, WhatsApp y precios se cambian desde el panel (Superadministrador → Ajustes y Ventas → Planes).

## Lo más importante

| Qué | Dónde |
|---|---|
| Interruptor del producto | `app/bootstrap.php` → `'producto' => 'academia'` (o `'ligas'` en `app/config.php` para volver a TuVocalía) |
| App de Google Play | APAGADA: `'google_play' => false`. Se enciende en `app/config.php` cuando se decida |
| Todo lo nuevo de academias | `app/academia.php` (+ `app/admin/mensajes.php`, `recitales.php`, `particulares.php`, `public/payphone.php`) |
| Migración de la base | `app/db.php` → `esquema_v34()` (user_version 34) |
| Módulos por academia (Anexo B: encender, apagar, precios, Mi plan, pedidos) | `app/modulos_catalogo.php` (igual en TuVocalía). Ver BLOC_DE_NOTAS §14 |
| Sin prueba gratis: todo se pide demo | `index.php?v=solicitar` (el registro abierto ya no existe) |
| (28 oct 2026) Solo dos planes: Básico $20 (la base + 3 módulos que elige el cliente, $5 cada uno más) y Completo $45 (todo, con el Asistente IA). Se ponen una sola vez (ajuste `planes_20_45`) y se cambian en Ventas → Planes | `planes_20_45()`, `modulos_cliente()` y `precio_extras_plan()` en `app/modulos_catalogo.php`; columnas `planes.modulos_elegir` y `precio_modulo_extra`. Ver BLOC_DE_NOTAS §17 |
| Precios del software solo para Tukán (el cliente pide cotización) | `ver_precios_software()` en `app/modulos_catalogo.php`. Ver BLOC_DE_NOTAS §15 |
| Ayuda («¿En qué le ayudamos?»), Asistente IA (incluido en el plan Completo; $5 al mes en el Básico, fuera de los 3 módulos a elegir; tope $4 al mes) y «Enviar por correo» de la demo | `app/asistente_ia.php` (+ `app/tukan_ia.php`, `app/tukan_correo.php`). En el VPS: `sudo bash /var/www/tuacademia/vps/configurar_ia_correo.sh`. Ver BLOC_DE_NOTAS §16 |
| Notas para Dimitry | **[BLOC_DE_NOTAS.md](BLOC_DE_NOTAS.md)** (qué se cambió, pendientes, nombres candidatos) |
| Manual para el cliente | **[docs/Manual_TuAcademia.pdf](docs/Manual_TuAcademia.pdf)** (27 páginas, capturas reales sin precios) · cómo se arma: [docs/MANUAL.md](docs/MANUAL.md) · guía corta: [docs/TuAcademia_Primeros_Pasos.pdf](docs/TuAcademia_Primeros_Pasos.pdf) |
| Instalar en el VPS | [vps/vps/INSTRUCCIONES.txt](vps/vps/INSTRUCCIONES.txt) (carpeta `/var/www/tuacademia`, puerto 8096) |
| Panel Tukán Central | `app/tukan_central.php` + `public/tukan_central.php`. Conectar en el VPS: `sudo bash /var/www/tuacademia/vps/conectar_central.sh ID SECRETO URL` (el panel central muestra el comando completo). El panel ve las academias por estado de suscripción y 8 cifras (alumnos, cursos, clases, asistencias, pensiones…) y, con el protocolo v2, las solicitudes, la ficha de cada academia, los pagos y las finanzas, y hace operaciones (crear demo, registrar pago, suspender…) con las funciones de `app/cuentas_super.php`. Ver BLOC_DE_NOTAS §12 y §12.1 |

## Probar en una computadora

```bash
# Necesita php 8.3+ con sqlite, gd, curl; python3 con requests (y playwright + chromium para la prueba del navegador)
bash pruebas/v38/reiniciar.sh                    # base nueva en modo academia, servidor en http://127.0.0.1:8196
python3 pruebas/academia/prueba_academia.py      # lo nuevo de TuAcademia
python3 pruebas/v38/recorrido.py 1500            # abre todas las páginas buscando errores de PHP

TA_PRODUCTO=ligas bash pruebas/v38/reiniciar.sh  # modo ligas (como TuVocalía, con Google Play encendido)
python3 pruebas/v38/prueba38.py                  # apps Android y Google Play (simulado)
python3 pruebas/v38/navegador38.py               # Chromium real con la Digital Goods API simulada

bash pruebas/v38/reiniciar.sh && python3 pruebas/tukan_central/prueba_central.py      # conector del Panel Tukán Central v1 (125/125; en modo ligas 121/121)
bash pruebas/v38/reiniciar.sh && python3 pruebas/tukan_central/prueba_central_v2.py   # conector v2 con el anexo A (343/343; en modo ligas 338/338)
bash pruebas/v38/reiniciar.sh && python3 pruebas/ia/prueba_ia.py                     # Ayuda, Asistente IA, ia_uso/ia_tope y correo de la demo (servidores de mentira de ../tukan-central/tests)
```
(Para otro puerto: `TA_PUERTO=8221 TA_SIM=8222`. Si se cambia `TA_SIM`, borrar antes `pruebas/v38/cuenta_servicio.json`
y los `.pem` para que `reiniciar.sh` los cree con el puerto nuevo.)

Usuarios de prueba (clave `clave12345`): `super@prueba.com`, `escuela@prueba.com` y, después de `prueba_academia.py`,
`baile@prueba.com`, `musica@prueba.com`, `natacion@prueba.com`.

**Nunca** correr `pruebas/` en el servidor: `reiniciar.sh` borra la base (en el VPS no corre porque existe `data/.servidor`).

## Qué NO está en el repositorio
`data/` (base, sesiones, fotos privadas), `public/uploads/`, `app/config.php`, la llave de firma de Android
(`android/firma/*.keystore`), el APK y los manuales en PDF (llevaban la marca TuVocalía). Ver `.gitignore`.
