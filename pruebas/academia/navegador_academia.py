#!/usr/bin/env python3
"""
Prueba en Chromium real de las pantallas nuevas de TuAcademia (después de prueba_academia.py):
sin errores de JavaScript, el botón de WhatsApp de «Mensajes de hoy» marca como enviado sin recargar,
la cajita de Payphone se arma (con el script de Payphone simulado) y las pantallas se ven bien en el celular (sin scroll de lado).
"""
import os, re, subprocess, sys
from playwright.sync_api import sync_playwright

PUERTO = os.environ.get('TA_PUERTO', '8196')
B = 'http://127.0.0.1:' + PUERTO + '/'
ok, fallos = 0, []


def chk(c, n):
    global ok
    if c:
        ok += 1
    else:
        fallos.append(n)
        print('FALLA:', n)


def entrar(pg, email):
    pg.goto(B + 'admin.php?p=login')
    pg.fill('input[name=email]', email)
    pg.fill('input[name=clave]', 'clave12345')
    pg.click('button[type=submit]')
    pg.wait_for_load_state('networkidle')


with sync_playwright() as p:
    nav = p.chromium.launch()
    cx = nav.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True)
    errores_js = []
    cx.on('weberror', lambda e: errores_js.append(str(e.error)))
    pg = cx.new_page()
    pg.on('pageerror', lambda e: errores_js.append(str(e)))
    # Los enlaces a wa.me se abren en otra pestaña: no se deja salir a internet
    cx.route('https://wa.me/**', lambda r: r.fulfill(status=200, content_type='text/html', body='<html>WhatsApp</html>'))
    entrar(pg, 'baile@prueba.com')
    for url in ['admin.php', 'admin.php?p=mensajes', 'admin.php?p=recitales', 'admin.php?p=particulares', 'admin.php?p=particulares&v=paquetes',
                'admin.php?p=examenes', 'admin.php?p=grupos', 'admin.php?p=alumnos', 'admin.php?p=cobros&ver=comprobantes', 'admin.php?p=escuela_config']:
        pg.goto(B + url)
        pg.wait_for_load_state('networkidle')
        ancho = pg.evaluate('document.documentElement.scrollWidth')
        chk(ancho <= 400, 'sin scroll de lado en el celular: %s (%d px)' % (url, ancho))
    pg.goto(B + 'admin.php?p=recitales')
    enlace = pg.locator('a[href*="p=recitales&id="]').first
    enlace.click()
    pg.wait_for_load_state('networkidle')
    chk('Coreografías y participantes' in pg.content() or 'Coreografías' in pg.content(), 'detalle del recital con coreografías')
    chk(pg.evaluate('document.documentElement.scrollWidth') <= 400, 'detalle del recital sin scroll de lado')
    # Mensajes de hoy: tocar WhatsApp marca como enviado (por fetch) y atenúa la tarjeta (se deja una familia pendiente para la prueba)
    repo = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    subprocess.run(['php', '-r', 'require "app/bootstrap.php"; q("DELETE FROM acad_mensajes WHERE liga_id = (SELECT id FROM ligas WHERE contacto_email = \'baile@prueba.com\')");'], cwd=repo, check=True)
    pg.goto(B + 'admin.php?p=mensajes')
    pend_antes = pg.locator('a[data-marcar]').count()
    chk(pend_antes >= 1, 'hay mensajes por enviar (%d)' % pend_antes)
    if pend_antes:
        pg.locator('a[data-marcar]').first.click()
        pg.wait_for_timeout(1200)
        chk('✓ Enviado' in pg.content(), 'el botón cambia a «Enviado» sin recargar')
        pg.reload()
        pg.wait_for_load_state('networkidle')
        chk(pg.locator('a[data-marcar]').count() == pend_antes - 1, 'al recargar queda uno menos por enviar')
    # Portal de padres: cajita de Payphone (con el script de Payphone simulado)
    pf = cx.new_page()
    pf.on('pageerror', lambda e: errores_js.append(str(e)))
    pf.route('https://cdn.payphonetodoesposible.com/**', lambda r: r.fulfill(status=200, content_type='application/javascript' if r.request.url.endswith('.js') else 'text/css',
             body="window.PPaymentButtonBox=function(o){this.o=o};window.PPaymentButtonBox.prototype.render=function(id){var d=document.getElementById(id);d.setAttribute('data-monto',this.o.amount);d.textContent='CAJA PAYPHONE '+this.o.storeId;};" if r.request.url.endswith('.js') else ''))
    pf.goto(B + 'familia.php')
    pf.fill('input[name=cedula]', '1710034065')
    pf.fill('input[name=pin]', '4321')
    pf.click('button:has-text("Entrar")')
    pf.wait_for_load_state('networkidle')
    chk('Pagar' in pf.content(), 'portal de padres abierto')
    chk(pf.evaluate('document.documentElement.scrollWidth') <= 400, 'portal de padres sin scroll de lado')
    if pf.locator('button[value=payphone]').count():
        pf.locator('button[value=payphone]').click()
        pf.wait_for_load_state('networkidle')
        pf.wait_for_timeout(800)
        chk('CAJA PAYPHONE store-77' in pf.content() and pf.get_attribute('#pp-button', 'data-monto'), 'la cajita de Payphone se arma con la tienda y el monto')
    else:
        chk(False, 'botón de Payphone en el portal')
    chk(not errores_js, 'sin errores de JavaScript: ' + '; '.join(errores_js[:3]))
    nav.close()
print('\nRESULTADO navegador_academia: %d/%d' % (ok, ok + len(fallos)))
sys.exit(1 if fallos else 0)
