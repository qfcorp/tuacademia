#!/usr/bin/env python3
"""Capturas reales de TuAcademia (modo academia) con la «Academia Demo» para el manual del cliente.

  python3 docs/manual/generar/capturas.py --base http://127.0.0.1:8461 --salida docs/manual/capturas [--solo nombre1,nombre2]
                                          [--guia docs/primeros_pasos/capturas]

Con --guia también saca las capturas recortadas de la guía «Primeros pasos» que tenían precios (cursos y cobrar).

Entra por la página de la demo (index.php?v=demo) como la dueña, la profesora y la familia, y guarda cada pantalla
en celular (390 px) o computadora (1280 px).

REGLA DE LA CASA: en las capturas NO se ve ningún precio. Antes de cada foto se ocultan todos los valores en dólares
(«$40.00», «$40.00/mes», «$1,229.50») con «•••», se vacían los campos de valores de los formularios y la dirección de
pruebas se cambia por la de verdad. Si después de eso queda un «$» con números o la palabra «gratis», se avisa.
"""
import argparse, re, sqlite3, struct, zlib
from pathlib import Path
from playwright.sync_api import sync_playwright

ap = argparse.ArgumentParser()
ap.add_argument('--base', required=True)
ap.add_argument('--salida', required=True)
ap.add_argument('--db', default=str(Path(__file__).resolve().parents[3] / 'data' / 'ligas.sqlite'))
ap.add_argument('--solo', default='')
ap.add_argument('--guia', default='', help='carpeta de las capturas de la guía Primeros pasos')
a = ap.parse_args()
SAL = Path(a.salida); SAL.mkdir(parents=True, exist_ok=True)
SOLO = set(x for x in a.solo.split(',') if x)
B = a.base.rstrip('/') + '/'

db = sqlite3.connect(a.db)
q = lambda sql, *p: (db.execute(sql, p).fetchone() or [None])[0]
LID = q("SELECT id FROM ligas WHERE es_demo = 1 AND tipo = 'escuela' ORDER BY id DESC LIMIT 1")
D = {
    'emilia': q("SELECT id FROM esc_alumnos WHERE liga_id = ? AND nombres = 'Emilia' AND apellidos = 'Andrade Proaño'", LID),
    'piano': q("SELECT a.id FROM esc_alumnos a JOIN acad_paquetes_alumno p ON p.alumno_id = a.id WHERE a.liga_id = ? AND a.apellidos <> 'Andrade Proaño' ORDER BY a.id LIMIT 1", LID),
    'recital': q("SELECT id FROM acad_eventos WHERE liga_id = ? ORDER BY id LIMIT 1", LID),
}

# (archivo, quién entra, ruta, ancho, acción antes de la foto, página completa)
PANTALLAS = [
    ('01_entrar', 'nadie', 'admin.php?p=login', 390, 'recortar_login', False),
    ('02_resumen', 'dueno', 'admin.php', 1280, None, False),
    ('03_resumen_celular', 'dueno', 'admin.php', 390, None, False),
    ('04_menu_celular', 'dueno', 'admin.php', 390, 'abrir_menu', False),
    ('05_primeros_pasos', 'dueno', 'admin.php', 1280, 'ir_primeros', False),
    ('06_usuarios', 'dueno', 'admin.php?p=usuarios', 1280, None, False),
    ('07_parametros', 'dueno', 'admin.php?p=escuela_config', 1280, None, False),
    ('08_pensiones', 'dueno', 'admin.php?p=escuela_config', 1280, 'ir:Pensiones', False),
    ('09_cursos', 'dueno', 'admin.php?p=grupos', 1280, None, False),
    ('10_curso_nuevo', 'dueno', 'admin.php?p=grupos', 1280, 'ir:Nuevo curso', False),
    ('11_profesores', 'dueno', 'admin.php?p=profesores', 1280, None, False),
    ('12_alumnos', 'dueno', 'admin.php?p=alumnos', 1280, None, False),
    ('13_alumno_nuevo_celular', 'dueno', 'admin.php?p=alumnos', 390, 'ir:Nuevo alumno', False),
    ('14_ficha_alumno', 'dueno', 'admin.php?p=alumno&id={emilia}&s=datos', 1280, None, False),
    ('15_asistencia_celular', 'dueno', 'admin.php?p=asistencia', 390, None, False),
    ('16_asistencia_historial', 'dueno', 'admin.php?p=asistencia', 1280, 'ir:Historial', False),
    ('17_planificacion', 'dueno', 'admin.php?p=planificacion', 1280, None, False),
    ('18_particulares', 'dueno', 'admin.php?p=particulares', 1280, None, False),
    ('19_pagos', 'dueno', 'admin.php?p=cobros', 1280, None, False),
    ('20_cobrar', 'dueno', 'admin.php?p=alumno&id={piano}&s=pagos', 1280, None, False),
    ('21_comprobantes', 'dueno', 'admin.php?p=cobros&ver=comprobantes', 1280, None, False),
    ('22_caja', 'dueno', 'admin.php?p=cobros&ver=caja', 1280, None, False),
    ('23_mensajes_celular', 'dueno', 'admin.php?p=mensajes', 390, None, False),
    ('24_avisos', 'dueno', 'admin.php?p=avisos_padres', 1280, None, False),
    ('25_niveles', 'dueno', 'admin.php?p=examenes&v=formas&d=ballet', 1280, None, False),
    ('26_recital', 'dueno', 'admin.php?p=recitales&id={recital}', 1280, None, False),
    ('27_indicadores', 'dueno', 'admin.php?p=indicadores', 1280, None, False),
    ('28_contabilidad', 'dueno', 'admin.php?p=contabilidad', 1280, None, False),
    ('29_mis_modulos', 'dueno', 'admin.php?p=mi_plan', 1280, 'ir:Mis módulos', False),
    ('30_portal_padres', 'familia', 'familia.php', 390, 'ir_hija', False),
    ('31_portal_pagar', 'familia', 'familia.php', 390, None, False),
    ('32_carnet', 'carnet', '', 390, None, False),
    ('33_pagina_publica', 'nadie', 'academia-demo', 390, None, False),
    ('34_inscripcion', 'nadie', 'index.php?l=academia-demo&v=inscribirse', 390, 'ir:Inscripción en línea', False),
    ('35_profesora_celular', 'profesor', 'admin.php', 390, None, False),
    ('36_mi_rol', 'profesor', 'admin.php?p=mi_rol', 390, None, False),
]

OCULTAR = r"""(dir) => {
  const dinero = /\$\s?-?\d[\d.,]*(\s*\/\s*mes)?/g;
  // 1) Textos: «· $40.00/mes» se quita; los demás valores quedan como «•••»
  const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  const nodos = []; while (w.nextNode()) nodos.push(w.currentNode);
  for (const n of nodos) {
    let t = n.nodeValue;
    if (t.includes('127.0.0.1')) t = t.replace(/https?:\/\/127\.0\.0\.1:\d+\/?/g, dir);
    if (/\$\s?-?\d/.test(t)) t = t.replace(/\s*·\s*\$\s?\d[\d.,]*\s*\/\s*mes/g, '').replace(dinero, '•••');
    if (t !== n.nodeValue) n.nodeValue = t;
  }
  // 2) Campos con valores de dinero
  document.querySelectorAll('input').forEach(i => {
    const etiqueta = i.closest('div')?.querySelector('label')?.innerText || '';
    const n = (i.name || '') + ' ' + etiqueta;
    if (i.type !== 'hidden' && /\(\$\)|mensualidad|matricula|monto|valor|precio/i.test(n) && /^[\d.,]+$/.test(i.value || '')) i.value = '';
    if (i.value && i.value.includes('127.0.0.1')) i.value = i.value.replace(/https?:\/\/127\.0\.0\.1:\d+\/?/g, dir);
  });
  document.querySelectorAll('textarea').forEach(t => { if (/\$\s?\d/.test(t.value)) t.value = t.value.replace(dinero, '•••'); });
  // 3) La cinta «Está en la demostración…» solo existe en la demo (la academia del cliente no la tiene)
  document.querySelectorAll('.demo-cinta').forEach(x => x.remove());
  // 4) Enlaces que se muestran como texto
  document.querySelectorAll('a[href*="127.0.0.1"]').forEach(x => x.setAttribute('href', x.getAttribute('href').replace(/https?:\/\/127\.0\.0\.1:\d+\/?/g, dir)));
}"""


def png_comprobante(ruta):
    """Una imagen sencilla (comprobante de ejemplo) para subir desde el portal de padres."""
    ancho, alto = 120, 80
    fila = b'\x00' + b'\xee\xf3\xf1' * ancho
    datos = zlib.compress(fila * alto)
    def trozo(tipo, d):
        return struct.pack('>I', len(d)) + tipo + d + struct.pack('>I', zlib.crc32(tipo + d) & 0xffffffff)
    Path(ruta).write_bytes(b'\x89PNG\r\n\x1a\n' + trozo(b'IHDR', struct.pack('>IIBBBBB', ancho, alto, 8, 2, 0, 0, 0)) + trozo(b'IDAT', datos) + trozo(b'IEND', b''))


with sync_playwright() as p:
    nav = p.chromium.launch(args=['--lang=es-EC'])
    sesiones = {}

    def contexto(quien, ancho):
        k = (quien, ancho)
        if k in sesiones:
            return sesiones[k]
        movil = ancho < 600
        ctx = nav.new_context(viewport={'width': ancho, 'height': 844 if movil else 820}, device_scale_factor=2 if movil else 1.5,
                              locale='es-EC', is_mobile=movil, has_touch=movil)
        pg = ctx.new_page()
        if quien in ('dueno', 'profesor', 'familia'):
            pg.goto(B + 'index.php?v=demo'); pg.wait_for_load_state('networkidle')
            pg.click(f'form.demo-acceso:has(input[name=rol][value={quien}]) button'); pg.wait_for_load_state('networkidle')
        sesiones[k] = pg
        return pg

    def subir_comprobante():
        # Preparación: una familia sube un comprobante por DeUna para que «Comprobantes» tenga algo que aprobar
        if not q("SELECT COUNT(*) FROM esc_cargos WHERE liga_id = ? AND comprobante_estado = 'pendiente'", LID):
            pg = contexto('familia', 1280)
            pg.goto(B + 'familia.php'); pg.wait_for_load_state('networkidle')
            img = SAL.parent / '_comprobante.png'
            png_comprobante(img)
            f = pg.locator('#pagar form')
            f.locator('select[name=forma]').select_option('deuna')
            f.locator('input[type=file]').set_input_files(str(img))
            f.locator('button[value=comprobante_varios]').click(); pg.wait_for_load_state('networkidle')
            img.unlink()
            print('comprobante de ejemplo subido')

    # El portal de padres se fotografía antes de que la familia suba el comprobante (si no, «Pagar» sale en revisión)
    orden = sorted(PANTALLAS, key=lambda x: 0 if x[1] == 'familia' else 1)
    for nombre, quien, ruta, ancho, accion, completa in orden:
        if SOLO and nombre not in SOLO:
            continue
        if nombre == '21_comprobantes':
            subir_comprobante()
        pg = contexto(quien, ancho)
        if quien == 'carnet':
            pg.goto(B + 'index.php?v=demo'); pg.wait_for_load_state('networkidle')
            pg.click('form.demo-acceso:has(input[name=rol][value=carnet]) button')
        else:
            pg.goto(B + ruta.format(**D))
        pg.wait_for_load_state('networkidle')
        if accion == 'abrir_menu':
            pg.evaluate("() => { document.body.classList.add('menu-abierto'); window.scrollTo(0, 0); }")
        elif accion == 'ir_primeros':
            pg.evaluate("() => { const e = [...document.querySelectorAll('details, .tarjeta, section')].find(x => /Primeros pasos/.test(x.innerText) && x.innerText.length < 1500); if (e) { if (e.tagName === 'DETAILS') e.open = true; window.scrollTo(0, e.getBoundingClientRect().top + window.scrollY - 70); } }")
        elif accion == 'ir_hija':
            pg.evaluate("() => { const h = [...document.querySelectorAll('.tarjeta')].find(x => /Emilia Andrade/.test(x.innerText) && !x.querySelector('form')); if (h) window.scrollTo(0, h.getBoundingClientRect().top + window.scrollY - 8); }")
        elif accion and accion.startswith('ir:'):
            texto = accion[3:]
            pg.evaluate("(t) => { const e = [...document.querySelectorAll('h1, h2, h3, summary, legend, b')].find(x => x.innerText.includes(t)); if (e) { const d = e.closest('details'); if (d) d.open = true; window.scrollTo(0, e.getBoundingClientRect().top + window.scrollY - 70); } }", texto)
        pg.evaluate(OCULTAR, 'https://tuacademia.tukanec.com/')
        pg.wait_for_timeout(350)
        visible = pg.evaluate('() => document.body.innerText')
        if re.search(r'\$\s?\d', visible):
            print('  OJO: queda un precio en', nombre, re.findall(r'.{0,30}\$\s?\d[\d.,]*.{0,10}', visible)[:3])
        if re.search(r'gratis', visible, re.I):
            print('  OJO: dice «gratis» en', nombre)
        recorte = None
        if accion == 'recortar_login':  # solo el formulario (el texto de abajo de esta pantalla es el de TuVocalía)
            c = pg.evaluate("() => { const f = document.querySelector('form'); const o = [...document.querySelectorAll('a')].find(x => /Olvidó/.test(x.innerText)); const r = (o || f).getBoundingClientRect(); return {x: 0, y: 0, width: window.innerWidth, height: Math.round(r.bottom + window.scrollY + 24)}; }")
            recorte = c
        pg.screenshot(path=str(SAL / f'{nombre}.png'), full_page=completa, clip=recorte)
        print('captura', nombre)

    # Guía «Primeros pasos»: recortes de 1000 px (1200 px en la imagen), igual que antes pero sin ningún precio
    if a.guia:
        GUIA = Path(a.guia); GUIA.mkdir(parents=True, exist_ok=True)
        ctx = nav.new_context(viewport={'width': 1000, 'height': 900}, device_scale_factor=1.2, locale='es-EC')
        pg = ctx.new_page()
        pg.goto(B + 'index.php?v=demo'); pg.wait_for_load_state('networkidle')
        pg.click('form.demo-acceso:has(input[name=rol][value=dueno]) button'); pg.wait_for_load_state('networkidle')
        def recorte(nombre, ruta, desde, hasta, alto_max=None):
            pg.goto(B + ruta); pg.wait_for_load_state('networkidle')
            pg.evaluate(OCULTAR, 'https://tuacademia.tukanec.com/')
            pg.evaluate("() => { document.querySelectorAll('.boton-ayuda, .ayuda-flotante, [class*=ayuda]').forEach(x => { if (getComputedStyle(x).position === 'fixed') x.style.display = 'none'; }); document.querySelectorAll('*').forEach(x => { const c = getComputedStyle(x); if (c.position === 'fixed' || c.position === 'sticky') x.style.visibility = 'hidden'; }); }")
            r = pg.evaluate("([a, b]) => { const A = document.querySelector(a).getBoundingClientRect(), Bb = [...document.querySelectorAll(b.sel)][b.n].getBoundingClientRect(); return {x: 0, y: Math.max(0, A.top + scrollY - 12), width: innerWidth, height: Bb.bottom - A.top + 24}; }", [desde, hasta])
            if alto_max: r['height'] = min(r['height'], alto_max)
            texto = pg.evaluate('() => document.body.innerText')
            if re.search(r'\$\s?\d', texto): print('  OJO: queda un precio en la guía', nombre)
            pg.screenshot(path=str(GUIA / f'{nombre}.png'), clip=r, full_page=True)
            print('guía', nombre)
        recorte('cursos', 'admin.php?p=grupos', 'main h1', {'sel': 'main .tarjeta', 'n': 1})
        recorte('cobrar', f"admin.php?p=alumno&id={D['piano']}&s=pagos", 'main .tarjeta', {'sel': 'main form', 'n': 0})
    nav.close()
