#!/usr/bin/env python3
"""Recorrido: abre todas las páginas que se alcanzan desde el panel y la web pública (solo GET) y busca errores PHP."""
import os, re, sys
from urllib.parse import urljoin, urlparse
import requests

PUERTO = os.environ.get('TA_PUERTO', '8196')
B = 'http://127.0.0.1:' + PUERTO + '/'
ERR = re.compile(r'(Fatal error|Warning:|Deprecated:|Uncaught|Notice:|Parse error)')
SALTAR = re.compile(r'(p=salir|cancelar=1|salir|logout|\.(png|jpg|jpeg|webp|svg|css|js|ico|pdf|apk|mp4)(\?|$)|descargar\.php\?apk|wa\.me|mailto:|tel:)', re.I)
MAX = int(sys.argv[1]) if len(sys.argv) > 1 else 1500


def csrf(h):
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', h)
    return m.group(1) if m else ''


def sesion(email, origen):
    s = requests.Session()
    s.get(B + 'app.php?origen=' + origen)
    if email:
        r = s.get(B + 'admin.php?p=login')
        s.post(B + 'admin.php?p=login', data={'csrf': csrf(r.text), 'email': email, 'clave': 'clave12345'})
    return s


def recorrer(nombre, s, inicio):
    vistos, cola, malos = set(), list(inicio), []
    while cola and len(vistos) < MAX:
        u = cola.pop(0)
        if u in vistos:
            continue
        vistos.add(u)
        try:
            r = s.get(u, timeout=60)
        except Exception as ex:
            malos.append((u, str(ex)))
            continue
        if r.status_code >= 500 or ERR.search(r.text):
            m = ERR.search(r.text)
            malos.append((u, str(r.status_code) + ' ' + (r.text[max(0, m.start() - 80):m.end() + 200] if m else '')))
        if 'text/html' not in r.headers.get('Content-Type', ''):
            continue
        for h in re.findall(r'href="([^"#]+)', r.text):
            h = h.replace('&amp;', '&')
            v = urljoin(u, h)
            if urlparse(v).netloc != '127.0.0.1:' + PUERTO or SALTAR.search(v):
                continue
            if v not in vistos:
                cola.append(v)
    print('%-28s páginas: %5d  con error: %d' % (nombre, len(vistos), len(malos)))
    for u, t in malos[:15]:
        print('   ', u, '→', t.replace('\n', ' ')[:300])
    return len(malos)


total = 0
for nombre, email, origen in [('súper (web)', 'super@prueba.com', 'web'), ('liga (web)', 'liga@prueba.com', 'web'), ('liga (app Google Play)', 'liga@prueba.com', 'play'),
                              ('escuela (app Google Play)', 'escuela@prueba.com', 'play'), ('escuela (APK)', 'escuela@prueba.com', 'apk'), ('público (app Google Play)', '', 'play'), ('público (web)', '', 'web')]:
    s = sesion(email, origen)
    total += recorrer(nombre, s, [B + ('admin.php' if email else 'index.php'), B + 'app.php', B + 'descargar.php'])
print('TOTAL páginas con error:', total)
sys.exit(1 if total else 0)
