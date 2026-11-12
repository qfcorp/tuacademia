# Manual de TuAcademia (para el cliente)

**PDF:** [docs/Manual_TuAcademia.pdf](Manual_TuAcademia.pdf) (A4, 27 páginas, diseño de marca Tukán, igual que el manual de TuSpa).
Es para el dueño de la academia, los profesores y la secretaría. Todo lo que dice está revisado contra el programa en
modo academia (menú, pantallas y botones reales) y las capturas son de la «Academia Demo».

La guía corta de 3 páginas sigue en [docs/TuAcademia_Primeros_Pasos.pdf](TuAcademia_Primeros_Pasos.pdf).

## Capítulos

| # | Capítulo | Base o módulo |
|---|---|---|
| 1 | Qué es TuAcademia y cómo se arma (base, módulos y planes, sin precios) | — |
| 2 | Entrar y el Resumen (computadora, celular, menú «Más», botón ❓ Ayuda) | Base |
| 3 | Quién hace qué: usuarios y perfiles | Base |
| 4 | Configurar la academia (datos, pensiones, DeUna, Payphone, inscripción en línea, sedes, idioma) | Base (Payphone e inscripción en línea: módulos) |
| 5 | Cursos, horarios y profesores | Base · Profesores: módulo |
| 6 | Alumnos e inscripciones (ficha, por aprobar, retiros, carnets, Excel) | Base · por aprobar, carnets y Excel: módulos |
| 7 | Asistencia y clases (planificar, clases particulares, evaluaciones, videos) | Base · lo demás: módulos |
| 8 | Pensiones, cobros y caja (estado de pagos, cobrar, comprobantes, cargos a un grupo, caja del mes) | Base · tarjeta: módulos |
| 9 | Mensajes de hoy y avisos por Telegram | Base · Telegram: módulo |
| 10 | Niveles, exámenes y recitales | Módulos |
| 11 | Portal de padres y página de la academia (carnet con QR) | Página: base · portal y carnet: módulos |
| 12 | El profesor en su celular (Mi rol de pago) | Perfil Profesor · rol de pago: módulo |
| 13 | Reportes (indicadores, contabilidad, descargar mis datos, bitácora) | Base · Contabilidad: módulo |
| 14 | Mi plan y los módulos | Base |
| 15 | Ayuda y Asistente IA (la Ayuda «¿En qué le ayudamos?» para todos; informe para los padres y cobro amable) | Ayuda: base · Asistente IA: módulo extra |
| 16 | Preguntas frecuentes y contacto | — |

## Reglas que cumple

- Ningún precio del software. En las capturas los valores en dólares salen como «•••» (lo hace `capturas.py` y avisa si queda alguno).
- Nunca dice «gratis». Todo por módulos. Contacto: WhatsApp 098 366 2181 · info@tukansoftware.com · tukansoftware.com · Grupo de ayuda en Telegram: https://t.me/+dQfU0W9yvo85MzRh.

## Cómo se vuelve a generar

```bash
bash docs/manual/generar/generar.sh          # base de prueba nueva + Academia Demo + 36 capturas + PDF del manual y de la guía
SOLO_PDF=1 bash docs/manual/generar/generar.sh   # solo vuelve a armar los PDF con las capturas que ya hay
```

- `docs/manual/manual.html`: el texto del manual (se edita aquí). `generar.sh` lo copia a `app/manuales/academia.html`: lo lee la Ayuda del programa.
- `docs/manual/generar/capturas.py`: capturas reales con Playwright (celular 390 px y computadora 1280 px); con `--guia` también las de la guía Primeros pasos.
- `docs/manual/generar/pdf.py`: HTML → PDF A4 con índice de páginas.
- `docs/manual/fonts/`: Space Grotesk y DM Sans (sin internet).
- Usa `pruebas/v38/reiniciar.sh` (borra `data/`): **solo en una computadora de pruebas, nunca en el servidor.**

El mismo PDF se copia a `public/assets/manual/Manual-TuAcademia-Academias.pdf`: en modo academia la página `/manual`
del programa (`app/publico/manual.php`) muestra el botón «⬇ Descargar PDF». En modo ligas (TuVocalía) no cambia nada.

## Factura electrónica con TuFactura (módulo aparte, viene apagado)
*9 nov 2026*

Con este módulo, los recibos de las mensualidades y cobros (y, en ligas, los pagos de equipos, reservas y entradas) sale como **factura electrónica autorizada por el SRI** y le llega al cliente por correo.

**Cómo se consigue**
- **TuFactura cuesta $30 + IVA al año** y este enlace va incluido. En TuAcademia el módulo cuesta $0.
- Pídelo a Tukán Software. Tukán crea tu cuenta de TuFactura y enciende el módulo desde el Panel Tukán Central.
- No viene en ningún plan ni en la demo.

**Conectar tu cuenta** (una sola vez, el administrador)
1. En TuFactura, abre **Configuración → Conexiones** y crea una clave para el punto de emisión que vas a usar. La clave empieza con `tf_`.
2. En TuAcademia, abre **🧾 Factura electrónica**, pega la clave y toca **Conectar**. La clave se guarda cifrada y no se vuelve a mostrar.
3. Si quieres, marca **Facturar automático al cobrar**. Revisa también si tus precios ya traen IVA y la tarifa (15 %, 5 % o 0 %).

**Facturar**
- **A mano:** toca **Facturar**, en **Dinero → Factura electrónica**.
- **Sola:** con «automático» encendido, cada cobro sale facturado al momento.
- La factura va a nombre de la cédula o el RUC del cliente. Si no los tiene, va a consumidor final.
- El total es exactamente lo que cobraste. Cuando la cuenta del SRI no lo permite, queda 1 centavo menos.
- El mismo cobro nunca se factura dos veces.
- El PDF autorizado se baja con un toque.
- Los recibos van a nombre del representante (su cédula).

**Si algo falla**
- Si TuFactura o el SRI no responden, el cobro igual se guarda. La factura queda pendiente: toca **Reintentar las pendientes** o **Revisar**.
- Si apagas el módulo, las facturas hechas se quedan en TuFactura.
