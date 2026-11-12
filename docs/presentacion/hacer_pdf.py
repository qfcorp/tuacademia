#!/usr/bin/env python3
"""Presentación de ventas de TuAcademia: docs/presentacion/presentacion.html -> docs/TuAcademia_Presentacion.pdf (hojas de 1920 x 1080 px).
Misma forma que la presentación de TuSpa. Desde la raíz del repo:  python3 docs/presentacion/hacer_pdf.py
Regla de ventas: la presentación NO muestra precios; se cotiza por WhatsApp según los módulos."""
import os, sys
from pathlib import Path
from playwright.sync_api import sync_playwright
AQUI = Path(__file__).resolve().parent
HTML = AQUI / 'presentacion.html'
SALIDA = AQUI.parent / 'TuAcademia_Presentacion.pdf'
proxy = os.environ.get('HTTPS_PROXY') or os.environ.get('https_proxy')
with sync_playwright() as p:
    nav = p.chromium.launch(args=['--lang=es-EC'], proxy={'server': proxy} if proxy else None)
    pg = nav.new_context(locale='es-EC', viewport={'width': 1920, 'height': 1080}).new_page()
    pg.goto(HTML.as_uri())
    pg.wait_for_load_state('networkidle')
    pg.evaluate("async () => { await document.fonts.ready; await Promise.all([...document.images].map(i => i.complete ? 1 : new Promise(r => { i.onload = i.onerror = r; }))); }")
    malas = pg.evaluate("[...document.images].filter(i => !i.naturalWidth).map(i => i.src)")
    if malas:
        sys.exit('Imágenes que no cargan: %s' % malas)
    pg.emulate_media(media='print')
    pg.pdf(path=str(SALIDA), width='1920px', height='1080px', print_background=True, margin={'top': '0', 'right': '0', 'bottom': '0', 'left': '0'})
    nav.close()
print('ok', SALIDA)
