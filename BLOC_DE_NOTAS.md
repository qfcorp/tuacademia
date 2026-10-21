# Bloc de notas — TuAcademia (nombre provisional)

Para Dimitry. Escrito en español sencillo. Última actualización: 21 de octubre de 2026 (ver **§16 Ayuda, Asistente IA y «Enviar por correo»**).

---

## 1. Qué es

**TuAcademia** es el sistema de **Tukán Software** para **academias**: de baile, de música, de natación y escuelas deportivas.
Sirve para llevar los alumnos, los cursos y horarios, la asistencia, las **pensiones** (con recordatorio por WhatsApp),
los pagos por **DeUna**, transferencia o tarjeta, los **niveles** con examen y certificado, los **recitales** y las **clases particulares**,
y tiene un **portal para los padres**.

- Dirección provisional: **https://tuacademia.tukanec.com**
- Repositorio: **qfcorp/tuacademia** (aparte de TuVocalía; se instala aparte en el VPS).

## 2. De dónde sale

Es una **copia de TuVocalía v38** (rama `v38-apps`, la más nueva, con las apps Android y sus pruebas).
TuVocalía ya tenía casi todo lo de escuelas (alumnos, grupos, asistencia, mensualidades, profesores, evaluaciones,
portal de padres, cinturones de artes marciales). Sobre eso se hizo TuAcademia.

- **No se copió**: la base de datos, las fotos, los respaldos, la llave de firma de Android, el APK, `app/config.php`
  ni los manuales en PDF (tenían la marca TuVocalía y pesaban 141 MB).
- La rama `main` de TuVocalía no tenía nada que no estuviera en `v38-apps` (el flujo de GitHub Actions ya venía).
- No se tocó nada del repositorio de TuVocalía.

## 3. Qué se cambió

1. **Marca**: dice **TuAcademia** en todo lo visible (sitio, encabezados, portada, correos, manifiesto de la app, manual).
   Empresa: **Tukán Software**. Correo de soporte: `soporte@tuacademia.tukanec.com`.
2. **Producto academia**: en `app/bootstrap.php` está el interruptor `'producto' => 'academia'`.
   - Toda cuenta nueva es **academia** (aunque alguien mande "liga", el sistema la crea como academia).
   - Se **apagan** (sin borrar el código): ligas, campeonatos, equipos, árbitros, vocales, delegados, carnet del jugador,
     venta de entradas, olimpiadas y federaciones. Esas páginas no se abren y no salen en el menú ni en la portada.
   - Para volver a tener todo como TuVocalía: en `app/config.php` poner `'producto' => 'ligas'`.
3. **Palabras de academia**: "curso" en vez de "grupo", "disciplina" en vez de "deporte", "pensión" en vez de "mensualidad".
   En baile y música además: "clase" en vez de "entrenamiento", "salón" en vez de "cancha", edades en vez de "Sub-10".
   Las escuelas de fútbol siguen con sus palabras de siempre.
4. **Ficha del alumno**: "posición" y "pie hábil" solo salen si la academia tiene deportes de equipo.
   Nuevos campos: **instrumento** (música), **talla de vestuario** (baile), **carril** (natación) y **nivel actual**.
5. **Planes nuevos** (provisionales, se cambian en Superadministrador → Ventas → Planes):

   | Plan | Mes | Semestre (paga 5, recibe 6) | Año (paga 10, recibe 12) |
   |---|---|---|---|
   | **Básico** | $19 | $95 | $190 |
   | **Completo** | $29 | $145 | $290 + dominio propio |

   - Básico: alumnos, cursos, asistencia, pensiones, portal de padres, avisos, DeUna, Mensajes de hoy y niveles con certificado.
   - Completo: todo (recitales, clases particulares, Payphone, profesores y rol de pago, evaluaciones, tienda, contabilidad, videos…).
   - **Prueba gratis de 7 días** (registro abierto: la academia se crea sola desde la página).
   - Los planes de TuVocalía quedan guardados pero apagados.
6. **App de Google Play: APAGADA** (como en TuSalón). Todo el código está, pero nadie ve precios ni botones de Google.
   Se enciende en `app/config.php` con `'google_play' => true` cuando se decida.
7. **Apps Android**: paquetes `com.tukan.tuacademia` (Google Play) y `com.tukan.tuacademia.directo` (APK).
   **La huella de firma está vacía**: hay que generar una llave nueva (ver sección 9).
8. **Demo**: en el producto academia la demostración es una **academia de baile y música** (ya no hay liga de demostración).

## 4. Qué se agregó

### Disciplinas nuevas
- **Baile y danza**: ballet, contemporáneo, jazz, salsa, bachata, ritmos latinos, urbano (hip hop, breaking), folclor, tap, danza árabe y "otro estilo".
- **Música**: piano, guitarra, canto, violín, batería, bajo, ukelele, teoría y solfeo, y "otro instrumento".
- Cada una trae sus **tests** (flexibilidad, giros, lectura a primera vista, escalas con metrónomo…), **habilidades** y **ejercicios** para planificar las clases.
- Se venden como módulos ("Baile y danza", "Música") igual que los deportes.

### Niveles con examen y certificado (para cualquier disciplina)
- Se generalizó el sistema de cinturones de artes marciales a **niveles**. Mismo funcionamiento: examen con cobro, resultados, acta y **certificado con código QR** que cualquiera verifica.
- Niveles de fábrica (cada academia los puede cambiar):
  - **Baile**: Iniciación, Básico, Intermedio, Avanzado, Pre-profesional.
  - **Música**: Nivel 1 a Nivel 6.
  - **Natación**: Matronatación (bebés), Adaptación al agua, Nivel 1 Flotación, 2 Crol, 3 Espalda, 4 Pecho, 5 Mariposa, Pre-competitivo, Competitivo.
  - Deportes: Iniciación, Básico, Intermedio, Avanzado, Competitivo. Artes marciales: sus cinturones de siempre.
- Un alumno puede tener nivel en varias disciplinas (por ejemplo, ballet y piano).

### Recitales y presentaciones (plan Completo)
- Crear el evento (recital, presentación, festival, audición o clase abierta).
- Agregar las **coreografías u obras**; si se elige un curso, sus alumnos entran solos. Cada participante con su **talla** y su **papel** (ej: solista).
- **Ensayos** (para todos o para una coreografía), que los padres ven en su portal.
- **Cobros**: inscripción (una sola vez por alumno) y vestuario (por coreografía; cada una puede tener otro valor). Un botón los carga a todos y no duplica.
- Imprimir el **programa** y la **lista de participantes con tallas** (para pedir el vestuario).

### Clases particulares con paquetes (plan Completo)
- Paquetes de **4, 8 y 12 clases** (con precio, días de vigencia y disciplina; se pueden crear otros).
- Vender un paquete a un alumno (se carga el cobro) y armar su **horario 1 a 1** con el profesor (avisa si el profesor ya tiene clase a esa hora).
- **Clases del día**: un toque en ✅ Vino / ❌ Faltó / 📞 Avisó. La clase **se descuenta sola** del paquete. Si faltó sin avisar también se descuenta (se puede cambiar en Parámetros).
- Avisa cuando se termina el paquete y lista los paquetes por terminar, con botón de WhatsApp para ofrecer el siguiente.
- El padre ve en su portal cuántas clases le quedan.

### Cobros
- **DeUna**: en Parámetros se pone el celular de DeUna de la academia. El padre, desde su portal, marca lo que paga (puede ser varios valores juntos), paga por DeUna o transferencia y **sube una sola foto del comprobante**. La academia lo aprueba en **Pagos → Comprobantes** y sale **un recibo** por todo.
- **Payphone** (pago con tarjeta de los padres, plan Completo): **hecho**, igual que en TuSalón. Cada academia pone su Token y Store ID en Parámetros; el padre paga en la cajita de Payphone y al volver el sistema le pregunta a Payphone si se aprobó y por cuánto; si coincide, se cobra y sale el recibo. Probado con un Payphone simulado (aprobado, cancelado, monto distinto, repetido). **Falta probarlo con claves reales**.
- **Mensajes de hoy** (recordatorio de pensiones): pantalla con las familias que tienen pensiones que vencen en los próximos **3 días** (se puede cambiar) o ya vencidas, **un mensaje por familia** (si tiene varios hijos van juntos), listo con saludo, valores, DeUna, datos de transferencia y el enlace al portal. Un toque abre WhatsApp con el mensaje escrito y queda **marcado como enviado**. El menú muestra cuántos faltan. Lo que tiene un comprobante en revisión no sale.
  No es automático (para enviar solo hace falta WhatsApp oficial pagado): lo envía la persona de la academia desde su celular.

### Natación: qué existía y qué se agregó
- **Ya existía (TuVocalía)**:
  - En **ligas**: campeonatos de natación por pruebas (libre, espalda, pecho, mariposa, combinado y relevos), por edad y rama, con **series y carriles**, tiempos al centésimo, medallero y puntos por club (`app/pruebas.php`).
  - En **escuelas**: natación como deporte del grupo, con tests (25 m libre, 50 m libre, 25 m espalda, 25 m pecho, nado continuo 12 minutos), habilidades (crol, espalda, pecho, mariposa, salidas, virajes, respiración), ejercicios por andariveles y **competencias con marca y medalla** (Alumnos → Competencias).
- **Se agregó para escuelas de natación**: los **niveles** de fábrica (de matronatación a competitivo) con examen y certificado, el **carril** de cada alumno en su ficha, el lugar "Piscina" en los cursos, y "festival" como evento para las muestras.
  Lo que no se agregó: torneos internos con carriles dentro de la academia (eso sigue siendo del producto de ligas).

### Fútbol y deportes en modo academia
Funcionan igual que en TuVocalía (grupos por categoría Sub-N, asistencia, evaluaciones, divisiones formativas, partidos del club, cinturones, competencias). Probado. Lo que **les falta** en TuAcademia:
- No pueden mandar un grupo a jugar en una liga (las ligas están apagadas).
- No tienen torneos internos (copa de la escuela) con tabla: solo los partidos del club.
- El manual de escuelas todavía habla de "escuela deportiva" en varias partes.

## 5. Archivos nuevos o con cambios importantes

| Archivo | Qué tiene |
|---|---|
| `app/academia.php` | Todo lo nuevo: disciplinas, palabras, niveles, mensajes, DeUna, Payphone, recitales, paquetes, demo |
| `app/admin/mensajes.php` | Pantalla "Mensajes de hoy" |
| `app/admin/recitales.php` | Recitales y presentaciones |
| `app/admin/particulares.php` | Clases particulares y paquetes |
| `public/payphone.php` | A donde vuelve el padre después de pagar con Payphone |
| `app/publico/landing_academia.php` | Portada de venta para academias |
| `app/db.php` → `esquema_v34()` | Tablas nuevas (`acad_*`) y campos nuevos; planes de TuAcademia |
| `pruebas/academia/prueba_academia.py` | Pruebas de todo lo nuevo |
| `app/tukan_central.php` + `public/tukan_central.php` | Conector con el Panel Tukán Central, protocolos v1 y v2 (§12) |
| `app/cuentas_super.php` | Funciones de las cuentas (crear demo, pagos, anular, alargar, suspender…) que usan el súper panel y el conector (§12.1) |
| `vps/conectar_central.sh` | Comando para conectar con el Panel Tukán Central en el VPS (§12) |
| `pruebas/tukan_central/prueba_central.py` y `prueba_central_v2.py` | Pruebas del conector (v1 y v2) |

## 6. Cómo correr las pruebas (en una computadora, nunca en el servidor)

```bash
cd tuacademia
bash pruebas/v38/reiniciar.sh                    # base nueva (modo academia) y servidor en http://127.0.0.1:8196
python3 pruebas/academia/prueba_academia.py      # todo lo nuevo de TuAcademia
bash pruebas/v38/reiniciar.sh && python3 pruebas/academia/prueba_arreglos_14oct.py   # arreglos del 14-oct y avisos por Telegram (§13)
python3 pruebas/v38/recorrido.py 1500            # abre todas las páginas de todos los paneles buscando errores de PHP

TA_PRODUCTO=ligas bash pruebas/v38/reiniciar.sh  # modo ligas (como TuVocalía) con Google Play encendido
python3 pruebas/v38/prueba38.py                  # apps Android y cobro con Google Play (simulado)
python3 pruebas/v38/navegador38.py               # Chromium de verdad con el cobro de Google simulado

bash pruebas/v38/reiniciar.sh && python3 pruebas/tukan_central/prueba_central.py      # conector del Panel Tukán Central, v1 (§12)
bash pruebas/v38/reiniciar.sh && python3 pruebas/tukan_central/prueba_central_v2.py   # conector del Panel Tukán Central, v2 (§12.1)
```

Resultados del 13 de octubre de 2026:
- `prueba_academia.py`: **236/236**. `navegador_academia.py`: **19/19**.
- `prueba38.py`: **100/100** (modo ligas). `navegador38.py`: **13/13** (Chromium 141).
- `prueba_central.py` (Panel Tukán Central, v1): **125/125** en modo academia y **121/121** en modo ligas.
- `prueba_central_v2.py` (Panel Tukán Central, v2): **343/343** en modo academia y **338/338** en modo ligas (con el anexo A: pausar y papelera).
- `recorrido.py`: **11.074 páginas, 0 con error de PHP**.

Seguridad de usuarios (21-oct-2026, igual que en TuVocalía): el perfil tiene que estar en `perfiles_para()` de la cuenta al crear
y al cambiar (si no, error y no se guarda nada; antes quedaba «Administrador»), y siempre queda al menos 1 Administrador activo
por academia, escuela o liga. Prueba: `python3 pruebas/usuarios/prueba_usuarios.py` (43/43). Detalle en `README_GITHUB.md`.

## 7. Cómo instalar en el servidor (VPS del ERP)

TuAcademia va **aparte** de TuVocalía: carpeta `/var/www/tuacademia`, contenedor `tuacademia-php`, nginx en el puerto
interno **8096** (TuVocalía usa 8095) y PHP en el **9086**. Base de datos nueva (no se copian datos de TuVocalía).
Las instrucciones paso a paso están en **`vps/vps/INSTRUCCIONES.txt`**. Resumen (PuTTY en el VPS, una línea a la vez):

```
cd
git clone https://github.com/qfcorp/tuacademia.git
sudo bash tuacademia/vps/vps/vps_0_revisar.sh
sudo bash tuacademia/vps/vps/vps_1_preparar.sh
sudo bash tuacademia/vps/vps/vps_2_codigo.sh
```

Después, en **Cloudflare** (navegador): Networks → Tunnels → el túnel del VPS → Public hostname → Add:
`Subdomain: tuacademia`, `Domain: tukanec.com`, `Type: HTTP`, `URL: 127.0.0.1:8096`.

Para actualizar: `cd tuacademia && git pull && cd .. && sudo bash tuacademia/vps/vps/vps_2_codigo.sh`.
(Los comandos no llevan tildes ni ñ, para que se puedan pegar desde el celular.)

## 8. Pendientes

- [ ] **Elegir el nombre definitivo** (ver sección 11) y cambiarlo: Ajustes → Marca, `vps/vps/comun.sh` (SITIO), Cloudflare, Android.
- [ ] **Llave de firma de Android nueva** (ver sección 9) y su huella en `app/config.php`.
- [ ] **Ícono de la app** de academia (el actual es la pelota de "LIGAS") y capturas para Google Play.
- [ ] **Probar Payphone con claves reales** de una academia (está hecho y probado con un simulador).
- [ ] **Manual en PDF** nuevo (`Manual-TuAcademia-Academias.pdf`); el manual en la web ya tiene la sección 31 de academias. Revisar el texto del manual (todavía dice "escuela deportiva" en varias partes) y las traducciones (inglés, francés, portugués e italiano no tienen los textos nuevos).
- [ ] **Recordatorio automático por WhatsApp** (sin tocar nada): necesita la API oficial de WhatsApp (pagada, unos $0,01 por mensaje). Hoy es "con un toque". (Por **Telegram** ya es automático: §13.)
- [ ] **Bot de Telegram en el servidor**: para que funcionen los avisos a los padres hay que poner el token y el usuario del bot en Superadministrador → Ajustes → Bot de Telegram (§13.4).
- [ ] **Comisión de Payphone**: hoy la academia recibe el pago completo en su cuenta de Payphone; no se cobra comisión de Tukán por esos pagos.
- [ ] Revisar los **precios** provisionales ($19 / $29) y qué va en cada plan.
- [ ] Torneos internos de la academia (fútbol, natación) con tabla o carriles, si los piden.
- [ ] Encender la app de **Google Play** cuando se decida (`'google_play' => true`).
- [ ] Correos de bienvenida y avisos: revisar que el texto hable de academias (algunos todavía dicen "liga o escuela").

## 9. Apps Android: llave de firma nueva

TuAcademia **no** usa la llave de TuVocalía. Para crear la nueva (en una computadora con Java, una sola vez):

```
keytool -genkeypair -v -keystore android/firma/tuacademia.keystore -alias tuacademia -keyalg RSA -keysize 2048 -validity 10000
keytool -list -v -keystore android/firma/tuacademia.keystore -alias tuacademia
```

- Guardar el archivo `.keystore` y su clave en un lugar seguro (**no** va a GitHub; está en `.gitignore`).
- Copiar la línea **SHA256** que muestra el segundo comando y ponerla en `app/config.php` del servidor: `'huella_firma' => 'AA:BB:…'`.
- En GitHub (Settings → Secrets → Actions) crear el secreto `TV_CLAVE_FIRMA` con la clave, y subir el keystore como indique el flujo `android.yml`.

## 10. Resultado de las pruebas (13-oct-2026)

| Prueba | Modo | Resultado |
|---|---|---|
| `pruebas/academia/prueba_academia.py` (todo lo nuevo: baile, música, natación, niveles y certificado, recital y vestuario, paquetes de clases, DeUna, Payphone simulado, Mensajes de hoy, demo, portal de padres) | academia | **236/236** |
| `pruebas/academia/navegador_academia.py` (Chromium: sin errores de JavaScript, sin scroll de lado en el celular, botón de WhatsApp, cajita de Payphone) | academia | **19/19** |
| `pruebas/v38/prueba38.py` (apps Android y Google Play simulado) | ligas | **100/100** |
| `pruebas/v38/navegador38.py` (Chromium con la Digital Goods API simulada) | ligas | **13/13** |
| `pruebas/v38/recorrido.py` (abre todas las páginas: súper, liga, escuela, 3 academias, 2 portales de padres, público) | academia | **11.074 páginas, 0 errores de PHP** |
| `pruebas/tukan_central/prueba_central.py` (conector del Panel Tukán Central v1, ver §12) | academia | **125/125** (en modo ligas: **121/121**) |
| `pruebas/tukan_central/prueba_central_v2.py` (conector del Panel Tukán Central v2, ver §12.1) | academia | **343/343** (en modo ligas: **338/338**; con el anexo A) |

Las pruebas de la v38 (Google Play) se corren en modo **ligas** porque en TuAcademia la app de Google Play está apagada.
Lo que **no** se pudo probar: Payphone con claves reales (no hay cuenta de prueba), compilar las apps Android (no hay llave ni SDK de Android aquí).

## 11. Nombres candidatos (para que Dimitry elija)

| Nombre | A favor | En contra |
|---|---|---|
| **TuAcademia** | Dice exactamente lo que es; sirve para baile, música, natación y deportes | Largo; "academia" suena formal |
| **TuClase** | Corto, fácil de recordar; sirve para clases grupales y particulares | Puede sonar a colegio |
| **TuEscuela** | Muy claro para escuelas deportivas | Suena a colegio; choca con "escuelas" de TuVocalía |
| **TuAula** | Corto, suena a música y estudio | Menos claro para natación y deportes |
| **TuMaestro** | Cálido, va bien con música y baile | Se centra en el profesor, no en la academia |

Todos siguen el estilo de la familia Tukán (TuPass, TuVocalía, TuSalón, TuGuardería). Antes de elegir, revisar que el dominio
`.com` / `.ec` y el usuario en Instagram y Facebook estén libres.

## 12. Conexión con el Panel Tukán Central (panel.tukanec.com)

Desde el **Panel Tukán Central** se ven las cifras de TuAcademia y se entra como superadministrador con un clic, sin escribir la clave
(protocolo v1, igual para todos los programas Tukán). Con el **protocolo v2** (§12.1) el panel también ve y atiende las
solicitudes, las academias, los pagos y las finanzas.

- **Código**: `app/tukan_central.php` (toda la lógica, igual que en TuVocalía) y `public/tukan_central.php` (la dirección `/tukan_central.php`).
- **Conectar** (PuTTY en el VPS). Primero actualizar el programa (trae el conector y deja el comando en `/var/www/tuacademia/vps/`):
  ```
  cd tuacademia && git pull && cd .. && sudo bash tuacademia/vps/vps/vps_2_codigo.sh
  sudo bash /var/www/tuacademia/vps/conectar_central.sh ID SECRETO URL
  ```
  (el segundo comando, con el ID y el SECRETO, lo muestra el panel central para copiar y pegar). Guarda la configuración en
  `/var/www/tuacademia/data/tukan_central.env` (no va a GitHub), se prueba solo y dice **OK** o el error. Se puede repetir sin problema.
- Sin configurar, `/tukan_central.php` responde **404** (código `sin_configurar`) y todo sigue igual.
- **Entrar desde el panel**: si ese correo no existe como superadministrador, se crea solo (con una clave al azar que nadie sabe).
  Si el correo ya es de una academia (cliente), **no** se convierte en super: se rechaza. Arriba en el panel aparece **← Panel Tukán**.
  Queda anotado en la bitácora: "Entró desde el Panel Tukán Central".
- **Cifras que ve el panel**: solo las **academias** (las demos del sistema no cuentan, y una liga que haya quedado de TuVocalía
  tampoco, porque no es cliente de TuAcademia) en **prueba** (los 7 días gratis), **activas** (pagadas con más de 7 días o sin
  fecha de vencimiento), **por vencer** (pagadas, 7 días o menos), **vencidas** (en días de gracia o ya bloqueadas) y
  **suspendidas** (desactivadas por el super); academias nuevas en 30 días; lo cobrado por suscripciones este mes y el anterior;
  pruebas que vencen en 7 días; pagos vencidos (solo de academias que ya pagaban, no de pruebas). Y estas 8 cifras:
  - **Academias** (todas, sin la demo) · **Alumnos activos** · **Cursos activos**
  - **Clases este mes**: clases de los cursos + clases particulares dadas
  - **Asistencias este mes**: alumnos presentes o atrasados en las clases de los cursos + clases particulares a las que fue el alumno
  - **Pensiones cobradas este mes**: pensiones mensuales pagadas este mes (cuántas, por fecha de pago)
  - **Profesores** (activos) · **Usuarios** (activos: super y usuarios de las academias)
- **Solo desde el panel central** (opcional): `sudo bash /var/www/tuacademia/vps/conectar_central.sh --solo-central 1`.
  El superadministrador ya no entra con su clave (ve un mensaje con el enlace al panel). Las academias, profesores y padres siguen igual.
- **Emergencia** (el panel central no funciona): `sudo bash /var/www/tuacademia/vps/conectar_central.sh --solo-central 0`
  y entrar con correo y clave. Si ese super lo creó el panel central (no tiene clave), ponerle una:
  `sudo docker exec -it -u www-data tuacademia-php php /var/www/tuacademia/app/crear_admin.php`.
  Apagar todo el conector: `sudo bash /var/www/tuacademia/vps/conectar_central.sh --desconectar`.
- **Pruebas** (cada una con la base nueva): `bash pruebas/v38/reiniciar.sh && python3 pruebas/tukan_central/prueba_central.py`
  (v1: **125/125**, modo academia) y `bash pruebas/v38/reiniciar.sh && python3 pruebas/tukan_central/prueba_central_v2.py` (v2 con el anexo A: **343/343**).
  En modo ligas: `TA_PRODUCTO=ligas bash pruebas/v38/reiniciar.sh && python3 pruebas/tukan_central/prueba_central.py` (**121/121**)
  y lo mismo con `prueba_central_v2.py` (**338/338**).

### 12.1 Protocolo v2: lo que el panel central ve y hace

El contrato está en `tukan-central/docs/PROTOCOLO_V2.md`. El panel **pide** y TuAcademia **hace**, con las mismas funciones que usa
el súper panel (`app/cuentas_super.php`). Todo lo que cambia algo queda en la **Bitácora** con «Panel Tukán Central · Dimitry <correo>».
El mismo `app/tukan_central.php` sirve para TuVocalía; en modo academia solo cuentan las **academias**.

- **Clientes**: todas las academias (sin la demo del sistema) con su estado: *prueba*, *por vencer* (pagada, vence en 0 a 7 días),
  *activo* (más de 7 días o sin fecha), *vencido* (incluye los días de gracia) o *suspendido*; plan, fechas, dueño, pagos, alumnos y
  cursos, vendedor y de dónde vino. Los números son los mismos del «estado».
- **Ficha de la academia**: pagos, solicitudes abiertas, últimas acciones, notas internas, mensajes de WhatsApp listos y botones.
- **Solicitudes** (la bandeja del panel): pedidos de demo, **registros nuevos** (la prueba gratis de 7 días), comprobantes de pago,
  tickets de soporte, protección de datos (LOPDP), pagos automáticos (tarjeta, Google Play), seguimiento de pruebas (faltan 2 días,
  vence hoy, terminó sin pagar), renovaciones (vence en 5 días o ya venció), dominio gratis por registrar, comisiones de vendedores
  por pagar y cobros con tarjeta por transferir. Los recordatorios atendidos desde el panel se guardan en `tukan_central_atenciones`.
- **Finanzas**: lo cobrado por mes (por plan, por tiempo y por forma de pago), lo devengado, la gestión de cobros con tarjeta y los
  costos que el programa conoce (comisión de Plux, comisiones de vendedores y la comisión de Google Play, estimada en 15 %).
- **Operaciones** desde el panel: crear demo (siempre academia), alargar prueba, registrar pago, anular pago, aprobar o rechazar
  comprobante, suspender, reactivar, cambiar plan, clave nueva del dueño, nota interna, atender solicitud y responder ticket. Si el panel
  repite una operación (por ejemplo porque se cortó la red), TuAcademia **no** la hace dos veces (tabla `tukan_central_operaciones`).
- **Entrar a una pantalla exacta**: el panel puede abrir directo la ficha de una academia (`/admin.php?p=ligas&editar=12`).
- **Arreglos que vinieron con el v2** (también en las pantallas del súper):
  - **Suspender ya es de verdad**: la academia suspendida ve «Cuenta suspendida. Comuníquese con…», no puede guardar cambios y su página
    pública se oculta. Sus datos se guardan. Reactivar la deja como estaba.
  - **Eliminar un pago** (Ventas) devuelve la fecha de vencimiento a la del pago anterior.
  - **Alargar la demo** (Embudo) ya no pone en demo a una academia que paga, y permite de 1 a 90 días.
  - Cada cuenta guarda **de dónde vino** (`origen`: registro, pedido de demo, súper o panel central) y tiene **notas internas**.
- **Límite**: 120 pedidos por minuto por conexión y 30 rechazados por minuto (después responde 429 «espere un minuto»).
- **En el VPS** no hay que hacer nada nuevo: basta con actualizar el programa. `conectar_central.sh --probar` dice «Protocolo v2: si».

### 12.2 Anexo A (15 oct 2026): pausar y borrar clientes desde el panel central
Pedido de Dimitry: «en clientes se debería poder borrar o pausar por algún motivo». Código en `app/papelera.php` (igual en TuVocalía
y TuAcademia) y las operaciones en `app/tukan_central.php`. Todo queda en la **Bitácora** («Panel Tukán Central · Dimitry <correo>»)
y se puede repetir sin miedo (misma `operacion_id` → no se hace dos veces).
- **Pausar** (`pausar`): se elige el tipo (*falta de pago*, *a pedido del cliente*, *en revisión*, *uso indebido* u *otro*) y se escribe
  el motivo (3 a 300 letras). Es la suspensión de verdad (`activa = 0`) con `suspendida_tipo` y `suspendida_motivo`.
  - Los usuarios de la academia (administrador, profesores; en modo ligas también vocal, árbitro y delegado) ven **«Tu cuenta está pausada»** con el motivo y el botón de
    WhatsApp de Tukán (Ajustes → WhatsApp de ventas). Con *en revisión* o *uso indebido* ven un texto general, no el motivo.
  - Sus clientes (página pública, familias, pagos, carnés, entradas…) ven **«temporalmente no disponible»**.
  - **Reactivar** la quita. *Suspender* (sin tipo) sigue igual que antes: aviso arriba, solo lectura y la página pública da 404.
- **Borrar** (`borrar_cliente`): hay que escribir el **nombre exacto** (o el id) y el motivo.
  1. Se guarda una **copia completa** de la cuenta en `data/borrados/<id>_<AAAAMMDD-HHMMSS>.json.gz` (fuera de la carpeta pública,
     no se puede bajar por la web): todas sus filas, tabla por tabla. Las tablas **se sacan del esquema** de la base (no de una lista
     a mano): las que tienen `liga_id` (o `cliente_id`) y todas las que cuelgan de ellas por claves foráneas (jugadores, eventos,
     asistencia, mensajes de soporte…).
  2. La cuenta pasa a la **papelera** (`ligas.eliminada`): nadie puede entrar (tampoco con la sesión abierta), no sale en Academias (Ligas), Ventas,
     Usuarios, Embudo, Negocio ni en las cifras del panel central (estado, clientes, solicitudes), su página pública da **404** y no
     se le mandan avisos de Telegram ni correos. Lo que pagó **antes** de borrarla sigue en las finanzas de los meses pasados.
  3. **30 días en la papelera**: se puede **restaurar** tal cual estaba (también si estaba pausada), desde el panel central
     (Clientes → Papelera → Restaurar, `restaurar_cliente`) o desde el súper panel (**Academias → 🗑️ Papelera → Restaurar**).
  4. Pasados los 30 días, la **tarea automática** (`app/tareas.php`, una vez al día) la borra de verdad. La copia se guarda **1 año**
     y después también se borra. La **bitácora** de auditoría no se borra nunca (el programa la protege); sus líneas van en la copia.
     Cada cuenta que pasó por la papelera queda anotada en la tabla `ligas_borradas` (restaurada o borrada, con la ruta de la copia).
- **Panel central**: `clientes` con `filtros.estado = "papelera"` (o `estado: "papelera"`) lista la papelera; cada cliente trae
  `papelera: {desde, hasta, motivo, por, copia}` y, si está pausado, `pausa: {tipo, motivo, motivo_cliente, desde}`. La respuesta
  de `clientes` trae además `papelera_total`. La ficha de un cliente trae los botones *Pausar* (o *Cambiar motivo de la pausa*) y
  *Borrar*; la de uno de la papelera, solo *Restaurar*. `capacidades` trae `tipos_pausa` y `revision: 2`.
- **Leer una copia** (por ejemplo para revisar qué tenía): `php -r 'require "app/bootstrap.php"; print_r(papelera_leer_copia("data/borrados/12_20261015-101500.json.gz")["conteo"]);'`
  (formato `tukan-papelera-1`: `{cuenta, motivo, por, conteo, tablas: {tabla: {columnas, filas}}}`).
- **Pruebas (15-oct-2026)**: `prueba_central_v2.py` **343/343** (modo academia) y **338/338** (modo ligas), con efectos reales, copia
  completa, restaurar, borrado definitivo con fecha simulada y con `app/tareas.php`, aislamiento, entrada bloqueada y 404. Siguen bien:
  `prueba_academia.py` 236/236, `navegador_academia.py` 19/19, `prueba_arreglos_14oct.py` 107/107, `prueba38.py` 100/100,
  `navegador38.py` 13/13, `recorrido.py` (5.159 páginas, 0 con error de PHP) y `prueba_central.py` 125/125 y 121/121.

## 13. Arreglos del 14 de octubre de 2026

Salieron al hacer el material de ventas. Todo probado en una copia local (nunca en el servidor) y subido a `main`.

### 13.1 Prueba gratis = 7 días en todo lado
- La página en vivo decía **14 días** porque el ajuste `dias_prueba` guardado en la base del VPS no estaba en 7.
- En el código, todos los valores por defecto pasaron de 30 a **7** (registro, portada, planes, imagen de venta, vendedores, Panel Tukán Central, Ajustes)
  y el manual de academias ya no dice "14 días".
- Al actualizar, la base pasa a la **versión 35** y eso deja `dias_prueba` en **7** una sola vez (después se puede cambiar en Ajustes si se quiere).
- (18 oct 2026) Ya no hay prueba gratis: ver la sección 14. El comando `poner_prueba_7_dias.sh` se quitó.

### 13.2 Textos que quedaron de ligas y escuelas
- La pestaña dice **"Resumen de la academia"** (antes "Resumen de la escuela").
- **Usuarios**: los perfiles de una academia ya no hablan de equipos, campeonatos, fixture ni árbitros (Secretario, Tesorero, Profesor, Equipo de apoyo);
  la nota de abajo habla de profesores y padres, no de árbitros y delegados.
- **Federación**: el botón y la tarjeta ya no salen en TuAcademia (la página tampoco se abre).
- **Requisitos y documentos**: solo sale si alguna disciplina de la academia pide algo **obligatorio** (por ejemplo certificado médico en artes marciales).
  En baile, música y natación no sale.
- **Carnet, ficha, portal de padres e informe**: en ballet, música, natación… se ve la **edad** ("7 años") en vez de "Sub-7". En fútbol y deportes de equipo sigue "Sub-7".
- **"Mensualidad" → "pensión"** y **"la escuela" → "la academia"** en todas las ayudas y textos del panel y del portal (ej.: "Descuento por segunda disciplina … esa pensión lleva este descuento").
- La cinta de la demo dice "Cree su academia gratis" (antes "Cree su liga gratis"); "Recomiende y gane" y "Mi plan" ya no hablan de ligas ni campeonatos.

### 13.3 Fotos de la demo
- `app/demo/fotos_clase/clase1..6.jpg` ahora son **ilustraciones propias** de clase de ballet (2), piano, salsa, guitarra y canto (las de antes eran de fútbol).
  El dibujo de cada una está en `app/demo/fuentes_ilustraciones/` (se pueden volver a generar).
- La academia de demostración ahora tiene **clases de las últimas 3 semanas** con asistencia, lo que se trabajó y esas fotos (antes no tenía ninguna clase).
  La demo se vuelve a crear sola cada madrugada, así que en el servidor se ve al día siguiente de actualizar (o al momento con el botón de reiniciar demos del súper).

### 13.4 Avisos por Telegram a los padres (nuevo)
Usa el **mismo bot y la misma cola** de Telegram que ya tenía el sistema (no hay que instalar nada).
- **Portal de padres** → tarjeta **"Avisos por Telegram"** → botón **Conectar Telegram**. Se abre el bot, la familia toca INICIAR y queda conectada.
  El mismo botón sirve para papá y mamá (hasta 4 Telegram por familia). Se puede **Desconectar** desde el mismo portal.
- **Avisos automáticos** (la academia elige cuáles en **Dinero → Avisos por Telegram**):
  - **Pensión por vencer**: N días antes (3 por defecto; se cambia ahí mismo, de 0 a 15).
  - **Pensión vencida**: al día siguiente de vencer si sigue pendiente (solo lo que venció en los últimos 30 días, para no mandar deudas viejas al activarlo).
  - **Pago recibido**: al cobrar en efectivo, aprobar un comprobante (DeUna / transferencia) o con tarjeta, con el número de recibo.
  - **Falta a clase**: cuando el profesor marca "Faltó" en la asistencia (de hoy o de ayer) y en clases particulares "Faltó sin avisar".
  - **Comunicados**: la academia escribe un mensaje para todas las familias o para un curso.
- **Nunca se repite** un aviso (cada uno queda anotado en `acad_tg_avisos`). Si la familia ya subió el comprobante, no se le recuerda.
- **Si Telegram falla nada se rompe**: el cobro, la asistencia o el comunicado se guardan igual; el mensaje queda en la cola y la tarea automática lo reintenta cada minuto (hasta 5 veces).
- Los avisos de pensiones salen una vez al día (a partir de las 8:00) con la tarea automática que ya existe.
- Requisitos: el bot configurado en **Superadministrador → Ajustes → Bot de Telegram** (token y usuario del bot) y el módulo "Avisos por Telegram" en el plan
  (los dos planes de TuAcademia ya lo traen). Si no está el bot, la academia ve un aviso y los padres no ven el botón.
- **Archivos**: `app/acad_telegram.php` (toda la lógica), `app/admin/avisos_padres.php` (pantalla de la academia), tarjeta en `public/familia.php`,
  `/start famCODIGO` en `app/avisos.php`, base versión 35 en `app/db.php` (`esc_familias.tg_codigo`, `acad_tg_chats`, `acad_tg_avisos`, `ligas.acad_tg`).
- **Textos de venta**: la portada y el módulo "Avisos por Telegram a los padres" describen exactamente esto; el plan Básico dice "Avisos a los padres por Telegram".

### 13.5 Pruebas (14-oct-2026)
| Prueba | Modo | Resultado |
|---|---|---|
| `pruebas/academia/prueba_arreglos_14oct.py` (nueva: 7 días, textos, fotos, Telegram simulado: conectar, por vencer, días configurables, vencida, pago, falta, particular, comunicados, activar/desactivar, sin repetir, Telegram caído, desconectar) | academia | **107/107** |
| `pruebas/academia/prueba_academia.py` | academia | **236/236** |
| `pruebas/academia/navegador_academia.py` | academia | **19/19** |
| `pruebas/tukan_central/prueba_central.py` / `prueba_central_v2.py` | academia | **125/125** / **262/262** |
| `pruebas/tukan_central/prueba_central.py` / `prueba_central_v2.py` | ligas | **121/121** / **258/258** |
| `pruebas/v38/prueba38.py` / `navegador38.py` | ligas | **100/100** / **13/13** |
| `pruebas/v38/recorrido.py 1500` | academia | **5.319 páginas, 0 con error de PHP** |

El simulador (`pruebas/v38/simulador_google.php`) ahora también hace de **Telegram** (`'telegram_api'` en el `app/config.php` de pruebas).
Capturas revisadas en celular (390 px) y computadora (1440 px): sin scroll de lado.

### 13.6 Cómo actualizar el servidor
Una línea a la vez en PuTTY (VPS):
```
cd tuacademia && git pull && cd .. && sudo bash tuacademia/vps/vps/vps_2_codigo.sh
```
Para los avisos a los padres: Superadministrador → Ajustes → **Bot de Telegram** (token de @BotFather y usuario del bot), si todavía no está.

## 14. Programas modulares (Anexo B) y «nada se da gratis, todo se pide demo» (18 oct 2026)

Pedido de Dimitry: «todos los programas deben ser modulares para encender o apagar las funciones que necesita el cliente, para
poder cobrar más o menos» y «nada se da gratis, todo se pide demo». El código de los módulos está en `app/modulos_catalogo.php`
(**el mismo archivo en TuVocalía y TuAcademia**), y el conector en `app/tukan_central.php` (también igual en los dos).

### 14.1 Módulos por academia
- **Lo básico** (alumnos, cursos, asistencia, pensiones con recibo, página pública, usuarios) lo tiene toda academia: no se apaga.
- Cada **módulo** tiene **precio al mes**, **depende de** y **grupo**. Precios de fábrica (USD al mes): Carnets 3 · Portal de padres 4 ·
  Entrenamientos 3 · Evaluaciones 4 · Divisiones formativas 5 (necesita Evaluaciones) · Niveles y certificados 4 · Recitales 3 ·
  Clases particulares 3 · Cobros con tarjeta 5 · Payphone 4 (necesita Portal de padres) · Tienda 3 · Contabilidad 4 · Avisos por
  Telegram 3 · Videos 3 · Dominio propio 2 · Profesores y rol de pago 4 · Subida desde Excel 2. Se cambian en
  **Módulos deportivos → 🧩 Funciones y precios**. Las disciplinas (baile, música…) siguen con sus precios de siempre.
- **Activos = (los del plan − los quitados) + los extra.** **Mensual = plan + extra − descuentos** (o un **precio acordado** fijo).
- **Apagar no borra nada**: se esconden menús, páginas, portal de padres (diario, desarrollo, libreta, niveles, recitales, clases
  particulares, carnet), PDF, Payphone y avisos. Al encender vuelve todo como estaba.
- La **demo** tiene todos los módulos, salvo que el súper los elija.
- **Mi plan → 🧩 Mis módulos**: la academia ve lo encendido y lo que puede agregar con su precio, y toca **Pedir este módulo**.
  El pedido llega por Telegram al súper, a **Ligas → Editar → Pedidos de módulos** y al Panel Tukán Central (`cambio_modulos`).
- **Súper**: Ligas → Editar → **Módulos de la cuenta** (interruptores, descuento, precio acordado y total).
- **Panel Tukán Central (revisión 3)**: acción `modulos`, `cliente.modulos`, `cambiar_modulos`, `guardar_modulos`, solicitudes
  `cambio_modulos` (Aprobar / No aprobar), `finanzas.por_modulo` y `crear_demo` con `plan` y `modulos`.

### 14.2 Sin prueba gratis
- Se quitó el **registro abierto** (`app/publico/registro.php`) y la prueba gratis: los enlaces viejos `/registro` llevan a
  **Solicitar demo**. El pedido sale en Ventas → Demos y en el Panel Tukán Central; Tukán crea la demo.
- Los botones dicen **Solicitar demo** / **Pedir demo** (también en inglés, francés, portugués e italiano). «Meses gratis» pasó a
  **«ahorra 1 mes / 2 meses»**, «dominio gratis» a **«dominio incluido»** y el premio por recomendar a **«meses de descuento»**.
- Las academias que ya existen (y las demos de prueba del VPS) siguen igual, con todos sus datos.

### 14.3 Pruebas (18-oct-2026)
| Prueba | Resultado |
|---|---|
| `prueba_academia.py` | **239/239** |
| `navegador_academia.py` | **19/19** |
| `prueba_arreglos_14oct.py` | **105/105** (se quitaron las revisiones de la prueba gratis) |
| `prueba38.py` / `navegador38.py` (modo ligas) | **100/100** / **13/13** |
| `recorrido.py` | 5.789 páginas sin errores PHP |
| `tukan_central/prueba_central.py` (academia / ligas) | **125/125** / **121/121** |
| `tukan_central/prueba_central_v2.py` (academia / ligas) | **380/380** / **374/374** |
| `tukan_central/prueba_modulos.py` (academia / ligas) | **61/61** / **62/62** (nueva) |

Puertos de prueba usados: 8412 (web) y 8413 (simulador). Capturas: `TA_PUERTO=8412 python3 pruebas/tukan_central/capturas_modulos.py`.

### 14.4 Cómo actualizar el servidor
Una línea en PuTTY (VPS):
```
cd tuacademia && git pull && cd .. && sudo bash tuacademia/vps/vps/vps_2_codigo.sh
```
La base se actualiza sola (tablas `modulos_catalogo` y `modulos_pedidos`, columnas nuevas en `ligas`); no se borra nada.


## 15. Sin precios del software para el cliente y sin «gratis» (20 oct 2026)

Regla de Dimitry: **los precios del software Tukán nunca se publican; siempre se pide cotización**. Nunca se escribe «gratis»
ni «prueba gratis»: solo hay demos, y en textos de venta se dice «meses de regalo».

- **Quién ve precios**: solo el súper usuario (`ver_precios_software()` en `app/modulos_catalogo.php`) y el Panel Tukán Central.
- **Mi plan de la academia**: ve solo **lo que ya paga por su plan** (y las opciones para renovar ese mismo plan). El otro plan,
  los módulos que no tiene y las disciplinas adicionales dicen **Por cotización / Pedir cotización**.
  - Módulos: **Solicitar este módulo** (el pedido `cambio_modulos` de siempre).
  - Planes y disciplinas: **Pedir cotización** crea un ticket «Pagos, plan o factura» (le llega al súper por Telegram y al
    Panel Tukán Central como solicitud `soporte`). La misma cotización no se repite.
  - Si intenta contratar otro plan con comprobante o tarjeta, el programa le pide cotizar.
  - En la **demo** no se ve el precio de ningún plan (tampoco en el aviso ni en el correo de vencimiento: «el valor de su plan se lo cotizamos»).
- Sin precio por disciplina en «Agregue más deportes», sin «% de descuento» y sin `data-precio` escondido en el HTML.
- **«gratis» → «sin costo»** en todo lo que se ve (polla, pases, inscripciones, cortesías, derechos de datos, ayudas) y en los
  diccionarios de los 5 idiomas; «Gratis, sin Telegram ni WhatsApp» → «No necesita Telegram ni WhatsApp».
- **Pantalla de ingreso** en modo academia: «Aquí ingresan con su correo y contraseña el dueño o la dueña de la academia, la
  secretaría y los profesores…». En modo ligas (TuVocalía) sigue el texto de siempre.
- **Manuales**: Mi plan reescrito en los 5 idiomas (sin tabla de precios), capturas nuevas con los valores tapados, capítulo 14
  del manual de TuAcademia al día, captura nueva del ingreso y PDF regenerados.
- **Pruebas** (20 oct 2026): `prueba_academia` **259/259** (ya no busca precios en la portada: revisa que NO haya), `navegador_academia`
  **19/19**, `recorrido` sin errores PHP, `prueba_arreglos_14oct` **105/105**, conector v1 **125/125** y **121/121**, v2 **380/380** y
  **374/374**, `prueba_modulos` **75/75** (academia) y **76/76** (ligas), `prueba38` **100/100** y `navegador38` **13/13** (modo ligas).
  - `prueba38` en esta computadora necesita `TA_SIM=8462` porque `pruebas/v38/cuenta_servicio.json` (no va a GitHub) quedó con ese puerto.
- Servidor: solo `cd tuacademia && git pull && cd .. && sudo bash tuacademia/vps/vps/vps_2_codigo.sh` (no hay tablas nuevas).


## 16. Ayuda, Asistente IA y «Enviar por correo» (21 oct 2026)

Guías del Panel: `tukan-central/compartido/LEEME_IA.md` y `LEEME_CORREO.md`. Las librerías están copiadas **sin cambios** en
`app/tukan_ia.php` y `app/tukan_correo.php`; lo del programa está en `app/asistente_ia.php` (**el mismo archivo que en TuVocalía**).

- **Ayuda para todos** (menú Mi cuenta → ❓ Ayuda y el botón verde ❓ Ayuda → «Pregunte cómo se hace algo»): «¿En qué le ayudamos?»
  responde con una pregunta frecuente guardada (sin costo), con la IA y el manual de TuAcademia (`app/manuales/academia.html`, copia de
  `docs/manual/manual.html` que deja `generar.sh`; una prueba revisa que sean iguales) o, si no se puede gastar, con el manual tal cual +
  WhatsApp 099 640 8397. Hasta 10 preguntas por minuto.
- **Preguntas frecuentes** (súper: `admin.php?p=ayuda_faq`): Por revisar, Aprobar, Editar, Borrar y Agregar. Con CSRF y bitácora.
- **Asistente IA** (módulo `asistente_ia`, grupo Extras; **incluido en el plan más caro (Completo)**, en los demás planes es un extra **por cotización**; no entra en la demo):
  **Informe del avance del alumno para los padres** (asistencia de 3 meses, nivel y notas del profesor) y **Mensaje de cobro amable**
  (pensiones pendientes del alumno y sus hermanos, con el total exacto). Botones en la ficha del alumno. Siempre se revisa antes de
  enviarlo por WhatsApp. Si no se puede: «En este momento no pudimos procesar el texto automáticamente; puede hacerlo a mano aquí: …».
  En modo ligas (como TuVocalía) son la crónica del partido y la foto de la hoja de vocalía.
- **Nunca** se le dice al cliente «límite», «cupo» ni «saldo».
- **Gasto y tope**: tabla `tukan_ia_gasto` por academia y mes; tope `ligas.ia_tope_usd` (vacío = $0.50 sin el módulo y 30 respuestas nuevas
  al mes, $4.00 con el módulo, venga en el plan o comprado aparte; -1 = sin tope propio). Las tablas y columnas se crean solas.
  Migración que se puede repetir (`ia_plan_alto`, ajuste una sola vez): el plan activo más caro queda `*,asistente_ia`.
- **Panel Tukán Central** (revisión 4, Anexo C): `ia_uso` y `ejecutar ia_tope` (botón «Tope de IA del mes» en la ficha de la academia).
- **Enviar por correo** en «Demo lista» (súper → Ligas → Crear): «Su demo de TuAcademia está lista». La contraseña va sellada (no se guarda),
  queda en la bitácora «Envió la demo por correo a …» sin la contraseña. Usa el correo de Tukán o, si no está, el correo propio del programa
  (Ajustes). Sin ninguno: «Configure el correo de Tukán».
- **En el VPS (Docker)**: el contenedor no ve `/etc`, así que la clave de la IA y el correo van en `data/tukan-ia.env` y `data/tukan-correo.env`:
  `sudo bash /var/www/tuacademia/vps/configurar_ia_correo.sh` (ver `vps/vps/INSTRUCCIONES.txt`). También sirven las variables de entorno o
  `app/config.php` (`'tukan_ia_env'`, `'tukan_correo_env'` o las claves `TUKAN_IA_CLAVE`, `TUKAN_SMTP_HOST`… como valores).
- **Manual**: capítulo 15 «Ayuda y Asistente IA» (preguntas frecuentes pasa a 16) y PDF regenerado. Textos nuevos en los 5 idiomas.
- **Pruebas** (21 oct 2026, todas con base nueva; `TA_SIM=8462`):
  modo academia: prueba_academia 259/259, navegador_academia 19/19 (sobre la misma base), recorrido 1500 páginas sin error,
  arreglos 105/105, central 125/125, central v2 380/380, módulos 76/76, usuarios 43/43, **prueba_ia 88/88**;
  modo ligas: prueba38 100/100, navegador38 13/13, central 121/121, central v2 374/374, módulos 77/77, usuarios 43/43, **prueba_ia 104/104**.
  `pruebas/ia/prueba_ia.py` usa los servidores de mentira del Panel (`tukan-central/tests/anthropic_falso.py` y `smtp_falso.py`).

