# Línea gráfica de TuAcademia (identidad común de Tukán, 10 nov 2026)

Mismo diseño y misma lógica que todos los programas Tukán (modelo: ERP para productoras). Solo cambia el color.

- **Kit común**: `public/assets/tukan-ui.css` y `public/assets/tukan-ui.js` — idénticos byte a byte en todos los programas.
  **No se editan aquí.** Las reglas propias del programa están al final de `public/assets/estilo.css` (bloque «IDENTIDAD GRÁFICA COMÚN»):
  hacen que las pantallas que ya existían (`.tarjeta`, `.btn`, tablas, avisos, cifras, pestañas) se vean con el kit, y estilan lo que el
  kit no trae (selector de idioma en la barra, barra de abajo del celular, botón ❓ Ayuda).
- **Color del programa** (única línea que cambia, en `tk_color_programa()` de `app/layout.php`):
  `--tk-color:#7b3fb0` (el morado del ícono de TuAcademia y de la Academia Demo; el CSS viejo era una copia del verde de TuVocalía),
  `--tk-color-claro:#c9a2ef`, `--tk-color-texto:#ffffff` (contraste 6,4:1). En el Panel Tukán Central TuAcademia figura con #3B6FE0: si Dimitry prefiere ese azul, se cambia esta línea.
- **Dónde aplica**: el panel del negocio (`admin.php`: ingreso, Resumen, todas las pantallas, Mi plan, Mi cuenta) con `body class="tk"`.
  No cambian: la página pública de la liga/escuela, la vocalía en vivo, los portales de árbitro, delegado y familia, los PDF y los correos.

## Estructura
- Barra de arriba `.tk-arriba` del color del programa: logo del negocio (o «TA» en el círculo), nombre del negocio grande y
  «TuAcademia · tipo · Plan» chico; a la derecha ← Panel Tukán (si vino del central), 🌐 idioma, usuario, perfil en chip y **Salir**.
- Menú de la izquierda `.tk-menu` (azul noche), armado de `menu_panel()` (`app/menu.php`, misma lista de siempre con permisos por perfil,
  módulos del plan y modo app de Google Play). Grupos:
  - Liga: **Principal** (Resumen, Federación si es federación, Ver página pública ↗, Cambiar de liga / combo) · **Competencia** · **Dinero** ·
    **Redes y público** · **Configuración** · **Superadministrador** (solo súper) · **Mi cuenta**.
  - Academia (modo academia, el de siempre): **Principal** (Resumen, Ver página pública ↗, Cambiar de academia si es súper) · **Alumnos** (Alumnos, Cursos y horarios,
    Asistencia, Clases particulares, Planificar clases, Evaluaciones, Videos, Niveles y exámenes, Recitales) · **Dinero** (Pagos, Mensajes de hoy, Avisos por
    Telegram, Contabilidad, Factura electrónica, Profesores, Tienda) · **Configuración** · **Mi cuenta**. En modo ligas, como TuVocalía.
  Globitos `.tk-globito` donde había contadores. Celular: ☰ abre el menú; la barra de abajo (4 opciones principales + «Más») se queda.
- Los formularios con confirmación, los que abren otra pestaña y los GET (filtros y descargas) llevan `data-sin-ocupado` (script en
  `pie_admin()`), para que el «Procesando…» del kit no los deje trabados.

## Inicio (dashboard) — `app/admin/inicio.php` (liga) y `app/admin/escuela_inicio.php` (academia)
1. Saludo según la hora de Ecuador (`tk_saludo()` en `app/tukan_ui.php`).
2. Tira con el aviso más importante (`tk_tira_inicio()`): la demo («le quedan N días», manual y guía en PDF, **Ver Mi plan**; en la app de
   Google Play sin «Ver Mi plan») o la novedad sin ver. La suscripción vencida/en gracia y los términos por aceptar salen arriba en todas las pantallas.
3. **Primeros pasos** (`html_primeros_pasos()` en `app/v27.php`): los mismos pasos reales de siempre (`pasos_puesta_en_marcha()`, también
   usados en el seguimiento de demos), cada uno con **Hacerlo**, barra de avance y **Ocultar** (se guarda por negocio en el ajuste `pasos_ocultos_<id>`).
   - Liga (9, o 8 sin árbitros): logo y datos · canchas · 2 equipos · jugadores · árbitros · campeonato · sorteo y fixture · programar la primera fecha · primer partido con vocalía.
   - Academia (8): logo y pensión · sedes · profesores · cursos · 3 alumnos · primera asistencia · primer cobro · compartir la página con los padres.
4. Cifras:
   - Liga: Equipos · Jugadores activos · Campeonatos (N en curso) · Campeonato actual (modalidad).
   - Academia: Alumnos activos · Asistencia 30 días · Clases de la semana planificadas (si tiene entrenamientos) · Cobrado este mes · Por cobrar (solo quien ve Pagos).
5. Tarjetas del día:
   - Liga: alertas (en vivo, vocalía ≠ árbitro, falta calificar, falta informe) · Próximos partidos · Últimos resultados · Suspendidos · Cumpleaños · Deportes individuales (6 + enlace).
   - Academia: Hoy tienen clase (con Tomar asistencia) · Pagos vencidos (6 + enlace, con 📲 Recordar) · Cumpleaños · Lesionados.
6. Accesos rápidos (`html_accesos_rapidos()`): 3 a 6 botones con las opciones principales que el usuario puede usar.
