#!/usr/bin/env python3
"""
SOLO PRUEBAS. Identidad gráfica común de Tukán (10 nov 2026) en el panel del negocio. Sirve para TuVocalía y TuAcademia (mismo código).
  - kit común (assets/tukan-ui.css y tukan-ui.js) idéntico al de los demás programas (md5 del kit v1) y cargado en el panel
  - body.tk, barra .tk-arriba con nombre del negocio, «Programa · Plan», usuario, perfil y Salir; menú .tk-menu con títulos y emoji
  - Inicio en orden: saludo, (tira), Primeros pasos con «Hacerlo», cifras, tarjetas, accesos rápidos
  - «Ocultar» Primeros pasos se guarda por negocio (la otra cuenta lo sigue viendo)
  - perfiles: «Solo consulta» no ve Primeros pasos; el menú respeta permisos
  - recorre todas las opciones del menú (súper, liga y escuela) sin errores de PHP y con el diseño nuevo
  - la página pública de la liga NO usa el diseño del panel
Uso (TuVocalía):  TA_PUERTO=9310 bash pruebas/tukan_central/reiniciar.sh && TA_PUERTO=9310 python3 pruebas/identidad_grafica/prueba_identidad.py
Uso (TuAcademia): TA_PUERTO=9350 TA_SIM=9351 bash pruebas/v38/reiniciar.sh && TA_PUERTO=9350 python3 pruebas/identidad_grafica/prueba_identidad.py
"""
import hashlib, os, re, subprocess, sys
import requests

PUERTO = os.environ.get('TA_PUERTO', '8196')
B = 'http://127.0.0.1:%s/' % PUERTO
REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
ERRORES = re.compile(r'(Fatal error|Warning:|Deprecated:|Uncaught|Notice:|Parse error)')
MD5_KIT = {'tukan-ui.css': 'c6d61e0390258cf1da582deec5048632', 'tukan-ui.js': 'fd8ce506e242b5ed9db34781b8f80543'}
ok, fallos = 0, []


def chk(cond, nombre, extra=''):
    global ok
    if cond:
        ok += 1
        print('  OK   ', nombre)
    else:
        fallos.append(nombre)
        print('  FALLA', nombre, extra)


def php(codigo):
    r = subprocess.run(['php', '-r', 'require "app/bootstrap.php"; ' + codigo], cwd=REPO, capture_output=True, text=True)
    return r.stdout.strip()


def entrar(email):
    s = requests.Session()
    r = s.get(B + 'admin.php?p=login')
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', r.text)
    r = s.post(B + 'admin.php?p=login', data={'csrf': m.group(1) if m else '', 'email': email, 'clave': 'clave12345'})
    return s, r


def csrf(html):
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', html)
    return m.group(1) if m else ''


print('== Kit común')
for f, md5 in MD5_KIT.items():
    ruta = os.path.join(REPO, 'public', 'assets', f)
    chk(os.path.isfile(ruta) and hashlib.md5(open(ruta, 'rb').read()).hexdigest() == md5, '%s idéntico al kit común (md5)' % f)

print('\n== Ingreso')
r = requests.get(B + 'admin.php?p=login')
chk('<body class="tk admin tk-sin-menu">' in r.text and 'class="tk-arriba"' in r.text and 'assets/tukan-ui.css' in r.text, 'ingreso con body.tk, barra del programa y el kit')
chk('--tk-color:' in r.text and 'class="tk-menu"' not in r.text, 'ingreso: color del programa y sin menú')

print('\n== Liga o academia (dueño)')
marca = php('echo marca();')
cuentas = [x for x in php('foreach (filas("SELECT u.email, l.tipo, l.id FROM usuarios u JOIN ligas l ON l.id = u.liga_id WHERE u.rol = \'liga\' AND l.es_demo = 0 ORDER BY u.id") as $x) echo $x["email"], "|", $x["tipo"], "|", $x["id"], "\\n";').splitlines() if x]
chk(len(cuentas) >= 1, 'hay cuentas de prueba', cuentas)
lids = {}
for linea in cuentas:
    email, tipo, lid = linea.split('|')
    lids[email] = lid
    s, r = entrar(email)
    nombre = php('echo fila("SELECT nombre FROM ligas WHERE id = %s")["nombre"];' % lid)
    chk(r.status_code == 200 and '<body class="tk admin">' in r.text, '%s: panel con body.tk' % email)
    chk(('<b>%s</b>' % nombre) in r.text.replace('&amp;', '&') and ('<small>%s · ' % marca) in r.text, '%s: barra con el negocio y «%s · Plan»' % (email, marca))
    chk('class="tk-rol"' in r.text and 'href="admin.php?p=salir"' in r.text and 'class="tk-boton-menu"' in r.text, '%s: perfil en chip, Salir y botón ☰' % email)
    menu = re.search(r'<nav class="tk-menu"[^>]*>(.*?)</nav>', r.text, re.S)
    menu = menu.group(1) if menu else ''
    titulos = re.findall(r'<div class="tk-menu-titulo">([^<]+)</div>', menu)
    chk(titulos[:1] == ['Principal'] and 'Configuración' in titulos and 'Mi cuenta' in titulos, '%s: menú con secciones %s' % (email, titulos))
    opciones = re.findall(r'<a href="([^"]+)"[^>]*><span class="tk-ico">([^<]+)</span>', menu)
    chk(len(opciones) >= 8 and all(ic.strip() for _, ic in opciones), '%s: %d opciones, todas con emoji' % (email, len(opciones)))
    chk('aria-current="page"' in menu, '%s: la opción activa está marcada' % email)
    # Orden del Inicio
    pos = [r.text.find(x) for x in ('class="tk-saludo"', 'class="tk-pasos', 'class="tk-cifras"', 'class="tk-columnas"', 'tk-accesos')]
    chk(all(p > 0 for p in pos) and pos == sorted(pos), '%s: Inicio en orden saludo → primeros pasos → cifras → tarjetas → accesos' % email, pos)
    chk(re.search(r'(Buenos días|Buenas tardes|Buenas noches)</span>, ', r.text) is not None, '%s: saludo según la hora' % email)
    hacer = re.findall(r'<a class="tk-boton chico" href="([^"]+)">Hacerlo</a>', r.text)
    chk(len(hacer) >= 1, '%s: Primeros pasos con «Hacerlo» (%d)' % (email, len(hacer)))
    chk(len(re.findall(r'class="tk-cifra[ "]', r.text)) >= 3, '%s: 3 o más cifras' % email)
    chk(not ERRORES.search(r.text), '%s: Inicio sin errores de PHP' % email)
    # Recorre todas las opciones del menú
    malas = []
    for url, _ in opciones:
        if 'p=salir' in url or url.startswith('http'):
            continue
        rr = s.get(B + url.replace('&amp;', '&'))
        if rr.status_code != 200 or ERRORES.search(rr.text) or ('<body class="tk admin">' not in rr.text and 'admin.php' in rr.url):
            malas.append((url, rr.status_code, (ERRORES.search(rr.text).group(0) if ERRORES.search(rr.text) else '')))
    chk(not malas, '%s: todas las opciones del menú abren con el diseño nuevo y sin errores (%d)' % (email, len(opciones)), malas[:5])
    lids[email + '#s'] = s

print('\n== Ocultar Primeros pasos (por negocio)')
e1 = cuentas[0].split('|')[0]
s1 = lids[e1 + '#s']
r = s1.get(B + 'admin.php')
r = s1.post(B + 'admin.php', data={'csrf': csrf(r.text), 'accion': 'ocultar_pasos'})
chk('class="tk-pasos' not in r.text and 'ya no se muestra' in r.text, 'al ocultar, el Inicio ya no muestra Primeros pasos')
chk(php('echo ajuste("pasos_ocultos_%s");' % lids[e1]) == '1', 'queda guardado para ese negocio')
if len(cuentas) > 1:
    e2 = cuentas[1].split('|')[0]
    r2 = lids[e2 + '#s'].get(B + 'admin.php')
    chk('class="tk-pasos' in r2.text, 'la otra cuenta sigue viendo sus Primeros pasos')
php('q("DELETE FROM ajustes WHERE clave = ?", ["pasos_ocultos_%s"]);' % lids[e1])

print('\n== Perfil «Solo consulta»')
php('q("INSERT INTO usuarios (nombre, email, clave, rol, liga_id, perfil, activo) VALUES (?,?,?,?,?,?,1)", ["Consulta Prueba", "consulta.ig@prueba.com", password_hash("clave12345", PASSWORD_DEFAULT), "liga", %s, "consulta"]);' % lids[e1])
sc, r = entrar('consulta.ig@prueba.com')
chk('<body class="tk admin">' in r.text and 'class="tk-pasos' not in r.text, 'Solo consulta: entra al panel nuevo y no ve Primeros pasos')
chk('admin.php?p=usuarios' not in r.text and 'admin.php?p=salir' in r.text, 'Solo consulta: el menú respeta su perfil (sin Usuarios)')
r = sc.post(B + 'admin.php', data={'csrf': csrf(r.text), 'accion': 'ocultar_pasos'})
chk(php('echo ajuste("pasos_ocultos_%s");' % lids[e1]) != '1', 'Solo consulta: no puede ocultar Primeros pasos del negocio')

print('\n== Superadministrador')
ss, r = entrar('super@prueba.com')
chk('<body class="tk admin">' in r.text and 'Superadministrador' in r.text, 'súper: panel nuevo con la sección Superadministrador')
menu = re.search(r'<nav class="tk-menu"[^>]*>(.*?)</nav>', r.text, re.S)
ops = re.findall(r'<a href="([^"]+)"', menu.group(1) if menu else '')
malas = []
for url in ops:
    if 'p=salir' in url:
        continue
    rr = ss.get(B + url.replace('&amp;', '&'))
    if rr.status_code != 200 or ERRORES.search(rr.text):
        malas.append((url, rr.status_code))
chk(ops and not malas, 'súper: todas las opciones del menú abren sin errores (%d)' % len(ops), malas[:5])

print('\n== La página pública no cambia')
slug = php('echo fila("SELECT slug FROM ligas WHERE id = %s")["slug"];' % lids[e1])
r = requests.get(B + 'index.php?l=' + slug)
chk(r.status_code == 200 and 'class="tk' not in r.text and 'tukan-ui.css' not in r.text, 'la página pública de la liga/escuela sigue con su diseño (sin body.tk)')

log = os.path.join(REPO, 'pruebas', 'tukan_central', 'web.log')
if not os.path.isfile(log):
    log = os.path.join(REPO, 'pruebas', 'v38', 'web.log')
txt = open(log, encoding='utf-8', errors='replace').read() if os.path.isfile(log) else ''
chk(not ERRORES.search(txt), 'php -S sin errores de PHP', (ERRORES.search(txt).group(0) if ERRORES.search(txt) else ''))

print('\nIdentidad gráfica — correctas: %d · fallas: %d' % (ok, len(fallos)))
for f in fallos:
    print('  -', f)
sys.exit(1 if fallos else 0)
