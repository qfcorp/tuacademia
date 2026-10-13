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
| Notas para Dimitry | **[BLOC_DE_NOTAS.md](BLOC_DE_NOTAS.md)** (qué se cambió, pendientes, nombres candidatos) |
| Instalar en el VPS | [vps/vps/INSTRUCCIONES.txt](vps/vps/INSTRUCCIONES.txt) (carpeta `/var/www/tuacademia`, puerto 8096) |

## Probar en una computadora

```bash
# Necesita php 8.3+ con sqlite, gd, curl; python3 con requests (y playwright + chromium para la prueba del navegador)
bash pruebas/v38/reiniciar.sh                    # base nueva en modo academia, servidor en http://127.0.0.1:8196
python3 pruebas/academia/prueba_academia.py      # lo nuevo de TuAcademia
python3 pruebas/v38/recorrido.py 1500            # abre todas las páginas buscando errores de PHP

TA_PRODUCTO=ligas bash pruebas/v38/reiniciar.sh  # modo ligas (como TuVocalía, con Google Play encendido)
python3 pruebas/v38/prueba38.py                  # apps Android y Google Play (simulado)
python3 pruebas/v38/navegador38.py               # Chromium real con la Digital Goods API simulada
```

Usuarios de prueba (clave `clave12345`): `super@prueba.com`, `escuela@prueba.com` y, después de `prueba_academia.py`,
`baile@prueba.com`, `musica@prueba.com`, `natacion@prueba.com`.

**Nunca** correr `pruebas/` en el servidor: `reiniciar.sh` borra la base (en el VPS no corre porque existe `data/.servidor`).

## Qué NO está en el repositorio
`data/` (base, sesiones, fotos privadas), `public/uploads/`, `app/config.php`, la llave de firma de Android
(`android/firma/*.keystore`), el APK y los manuales en PDF (llevaban la marca TuVocalía). Ver `.gitignore`.
