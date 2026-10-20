#!/usr/bin/env python3
"""Convierte el manual de TuAcademia (HTML) en PDF A4 con Chromium (Playwright). Mismo diseño que el manual de TuSpa.

  python3 docs/manual/generar/pdf.py docs/manual/manual.html docs/Manual_TuAcademia.pdf "Manual de TuAcademia"

La portada va sin pie de página; las demás llevan "Manual de TuAcademia · Tukán Software" y el número. Dos pasadas: la
primera ubica en qué página empieza cada capítulo y la segunda escribe esos números en el índice.
Las fuentes (Space Grotesk y DM Sans) están en docs/manual/fonts: no hace falta internet.
"""
import io, re, sys
from pathlib import Path
from playwright.sync_api import sync_playwright
from pypdf import PdfReader, PdfWriter

if len(sys.argv) < 4:
    sys.exit(__doc__)
HTML = Path(sys.argv[1]).resolve()
SALIDA = Path(sys.argv[2]).resolve()
TITULO = sys.argv[3]

PIE = ('<div style="width:100%;font-family:Helvetica,Arial,sans-serif;font-size:7.5px;color:#5B6478;padding:0 15mm;'
       'display:flex;justify-content:space-between;">'
       f'<span>{TITULO} · Tukán Software · tukansoftware.com</span><span class="pageNumber"></span></div>')

faltan = [m for m in re.findall(r'src="([^"]+\.(?:png|jpg))"', HTML.read_text(encoding="utf-8")) if not (HTML.parent / m).exists()]
if faltan:
    print("Faltan capturas:", ", ".join(sorted(set(faltan))))

with sync_playwright() as p:
    nav = p.chromium.launch(args=["--lang=es-EC"])
    pg = nav.new_context(locale="es-EC", viewport={'width': 1200, 'height': 900}).new_page()
    pg.goto(HTML.as_uri())
    pg.wait_for_load_state("networkidle")
    pg.emulate_media(media="print")
    pg.evaluate("document.fonts.ready")
    pg.wait_for_timeout(600)

    def imprimir(con_pie=True):
        return pg.pdf(format="A4", print_background=True, prefer_css_page_size=True,
                      display_header_footer=con_pie, header_template="<div></div>", footer_template=PIE if con_pie else "<div></div>")

    lector = PdfReader(io.BytesIO(imprimir()))
    titulos = pg.eval_on_selector_all("section.cap > h1", "els => els.map(e => [e.parentElement.id, e.innerText.replace(/\\s+/g,' ').trim()])")
    textos = [(pagina.extract_text() or "") for pagina in lector.pages]
    paginas = {}
    desde = 2
    for sid, titulo in titulos:
        num, nombre = titulo.split(" ", 1)
        for i in range(desde, len(textos)):
            lineas = [re.sub(r"\s+", " ", l).strip() for l in textos[i].splitlines()]
            if any(l.startswith(num) and nombre[:20] in l for l in lineas[:6]) or any(nombre[:20] in l for l in lineas[:3]):
                paginas[sid] = i + 1
                desde = i
                break
    sin = [sid for sid, _ in titulos if sid not in paginas]
    if sin:
        print("No ubiqué la página de:", ", ".join(sin))
    pg.evaluate("(p) => { for (const [k, v] of Object.entries(p)) { const e = document.querySelector(`[data-pag='${k}']`); if (e) e.textContent = v; } }", paginas)
    final = PdfReader(io.BytesIO(imprimir()))
    portada = PdfReader(io.BytesIO(imprimir(con_pie=False)))
    escritor = PdfWriter()
    escritor.add_page(portada.pages[0])
    for pagina in final.pages[1:]:
        escritor.add_page(pagina)
    nav.close()

escritor.add_metadata({"/Title": TITULO, "/Author": "Tukán Software", "/Subject": TITULO})
with open(SALIDA, "wb") as f:
    escritor.write(f)
print(f"PDF listo: {SALIDA} ({len(escritor.pages)} páginas, {SALIDA.stat().st_size / 1e6:.1f} MB)")
print("Capítulos:", ", ".join(f"{k}={v}" for k, v in paginas.items()))
