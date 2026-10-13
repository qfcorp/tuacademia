# Bloc de notas — TuAcademia (nombre provisional)

Para Dimitry. Escrito en español sencillo. Última actualización: 13 de octubre de 2026.

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
- `prueba_central_v2.py` (Panel Tukán Central, v2): **262/262** en modo academia y **258/258** en modo ligas.
- `recorrido.py`: **11.074 páginas, 0 con error de PHP**.

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
- [ ] **Recordatorio automático por WhatsApp** (sin tocar nada): necesita la API oficial de WhatsApp (pagada, unos $0,01 por mensaje). Hoy es "con un toque".
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
| `pruebas/tukan_central/prueba_central_v2.py` (conector del Panel Tukán Central v2, ver §12.1) | academia | **262/262** (en modo ligas: **258/258**) |

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
  (v1: **125/125**, modo academia) y `bash pruebas/v38/reiniciar.sh && python3 pruebas/tukan_central/prueba_central_v2.py` (v2: **262/262**).
  En modo ligas: `TA_PRODUCTO=ligas bash pruebas/v38/reiniciar.sh && python3 pruebas/tukan_central/prueba_central.py` (**121/121**)
  y lo mismo con `prueba_central_v2.py` (**258/258**).

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
