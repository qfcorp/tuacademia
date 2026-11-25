#!/usr/bin/env python3
"""
El superadministrador entra por el LOGIN NORMAL (admin.php?p=login, el que abre la app de iPhone) con su clave y su código de 2 pasos
del Panel Tukán. El Panel Central es de mentira (pruebas/tukan_central/panel_super_simulado.php): clave «ClaveBuena1», código 123456.
Sirve para TuVocalía y TuAcademia (mismo código).

Antes (base nueva):
  TuAcademia:  TA_PUERTO=8241 TA_SIM=8242 bash pruebas/v38/reiniciar.sh
  TuVocalía:   TA_PUERTO=8241 bash pruebas/tukan_central/reiniciar.sh
Correr:        TA_PUERTO=8241 python3 pruebas/tukan_central/prueba_super_login.py
(Escribe data/tukan_central.env y lo quita al terminar; levanta el panel de mentira en un puerto libre.)
"""
import json, os, re, secrets, socket, subprocess, sys, time
import requests

PUERTO = os.environ.get('TA_PUERTO', '8196')
B = 'http://127.0.0.1:' + PUERTO + '/'
DIR = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(DIR))
ENV = os.path.join(REPO, 'data', 'tukan_central.env')
ERRORES = re.compile(r'(Fatal error|Warning:|Deprecated:|Uncaught|Notice:|Parse error)')
ID = 'tuacademia-prueba'
SECRETO = secrets.token_hex(32)
SIM_DIR = os.path.join(REPO, 'data', 'panel_super_sim')
ok, fallos = 0, []


def chk(cond, nombre):
    global ok
    if cond:
        ok += 1
        print('  OK   ', nombre)
    else:
        fallos.append(nombre)
        print('  FALLA', nombre)


def php(codigo):
    r = subprocess.run(['php', '-r', 'require "app/bootstrap.php"; ' + codigo], cwd=REPO, capture_output=True, text=True)
    if r.returncode != 0 or ERRORES.search(r.stdout + r.stderr):
        print('PHP CLI:', r.stdout[-800:], r.stderr[-800:])
    return r.stdout.strip()


def val(sql, *p):
    return php('echo valor(' + repr(sql) + ', ' + json.dumps(list(p)) + ');')


def csrf(html):
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', html)
    return m.group(1) if m else ''


def ip():
    return '10.%d.%d.%d' % (secrets.randbelow(250), secrets.randbelow(250), 1 + secrets.randbelow(250))


def entrar(email, clave, codigo=None, s=None):
    """Como la pantalla: GET (para el csrf) y POST. Devuelve (sesión, respuesta sin seguir el redirect)."""
    s = s or requests.Session()
    h = {'CF-Connecting-IP': ip()}
    r = s.get(B + 'admin.php?p=login', headers=h)
    d = {'csrf': csrf(r.text), 'email': email, 'clave': clave}
    if codigo is not None:
        d['codigo'] = codigo
    return s, s.post(B + 'admin.php?p=login', data=d, headers=h, allow_redirects=False)


def puerto_libre():
    s = socket.socket()
    s.bind(('127.0.0.1', 0))
    p = s.getsockname()[1]
    s.close()
    return p


def llamadas():
    f = os.path.join(SIM_DIR, 'super.log')
    return len([l for l in open(f).read().split('\n') if l.strip()]) if os.path.exists(f) else 0


def ultima():
    ls = [l for l in open(os.path.join(SIM_DIR, 'super.log')).read().split('\n') if l.strip()]
    return json.loads(ls[-1])


os.makedirs(SIM_DIR, exist_ok=True)
for f in os.listdir(SIM_DIR):
    os.remove(os.path.join(SIM_DIR, f))
PP = puerto_libre()
env = dict(os.environ, PANEL_SIM_DIR=SIM_DIR, TUKAN_CENTRAL_ID=ID, TUKAN_CENTRAL_SECRET=SECRETO)
panel = subprocess.Popen(['php', '-S', '127.0.0.1:%d' % PP, os.path.join(DIR, 'panel_super_simulado.php')], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
os.makedirs(os.path.dirname(ENV), exist_ok=True)
open(ENV, 'w').write('TUKAN_CENTRAL_ID=%s\nTUKAN_CENTRAL_SECRET=%s\nTUKAN_CENTRAL_URL=http://127.0.0.1:%d\nTUKAN_SOLO_CENTRAL=0\n' % (ID, SECRETO, PP))
try:
    for _ in range(50):
        try:
            requests.get('http://127.0.0.1:%d/' % PP, timeout=1)
            break
        except Exception:
            time.sleep(0.1)

    print('1. Un cliente normal que entra bien NO toca el panel')
    s, r = entrar('escuela@prueba.com', 'clave12345')
    chk(r.status_code == 302 and 'admin.php' in r.headers.get('Location', ''), 'Admin de una escuela con su clave: entra (302 a admin.php)')
    chk(llamadas() == 0, 'El panel central no recibió ningún pedido')
    s, r = entrar('escuela@prueba.com', 'claveMala1')
    chk(r.status_code == 200 and 'Correo o contraseña incorrectos.' in r.text, 'Cliente con clave mala: mensaje de siempre')
    chk(llamadas() == 0, 'La clave de un usuario de cliente NO se manda al panel')

    print('2. Superadministrador: clave bien, falta el código')
    chk(val("SELECT COUNT(*) FROM usuarios WHERE email = 'dimitry-super@prueba.ec'") == '0', 'Todavía no hay súper con ese correo en el programa')
    s, r = entrar('dimitry-super@prueba.ec', 'ClaveBuena1')
    chk(r.status_code == 200, 'Vuelve al login (200)')
    chk('name="codigo"' in r.text, 'Pide el código de 2 pasos')
    chk('Escriba también el código de 2 pasos' in r.text or 'Escribe también el código de 2 pasos' in r.text, 'Con el aviso del panel')
    chk('autocomplete="one-time-code"' in r.text, 'El campo del código es de un solo uso para el celular')
    chk(val("SELECT COUNT(*) FROM usuarios WHERE email = 'dimitry-super@prueba.ec'") == '0', 'Sin código no se crea ningún usuario')
    # Pedir el código muchas veces NO cuenta como intento fallido (el freno local es de 10 por 15 minutos desde la misma IP)
    IP = ip()
    s = requests.Session()
    for _ in range(12):
        rr = s.get(B + 'admin.php?p=login', headers={'CF-Connecting-IP': IP})
        s.post(B + 'admin.php?p=login', data={'csrf': csrf(rr.text), 'email': 'dimitry-super@prueba.ec', 'clave': 'ClaveBuena1'}, headers={'CF-Connecting-IP': IP})
    rr = s.get(B + 'admin.php?p=login', headers={'CF-Connecting-IP': IP})
    r = s.post(B + 'admin.php?p=login', data={'csrf': csrf(rr.text), 'email': 'dimitry-super@prueba.ec', 'clave': 'ClaveBuena1', 'codigo': '123456'}, headers={'CF-Connecting-IP': IP}, allow_redirects=False)
    chk(r.status_code == 302 and 'admin.php' in r.headers.get('Location', ''), 'Pedir el código 12 veces NO cuenta como intento fallido: con el código entra')

    print('3. Con clave y código entra al panel del súper')
    pa = s.get(B + 'admin.php', headers={'CF-Connecting-IP': IP})
    chk(pa.status_code == 200 and 'Panel Tukán' in pa.text, 'El panel de administración abre y muestra «← Panel Tukán»')
    chk(val("SELECT rol || '|' || nombre FROM usuarios WHERE email = 'dimitry-super@prueba.ec'") == 'super|Dimitry Panel', 'Se creó el súper con el correo y nombre del panel')
    chk(val("SELECT COUNT(*) FROM bitacora WHERE descripcion LIKE 'Entró escribiendo su clave y código de 2 pasos%' AND ip = ?", IP) == '1', 'Quedó anotado en la bitácora (con la IP)')
    u = ultima()
    chk(u['codigo'] == '123456' and u['tipo'] == 'super' and u['vida'] <= 60 and u['ip'] == IP, 'El panel recibió el token firmado (tipo super, vida ≤ 60 s), el código y la IP de quien escribe')
    s2, r = entrar('dimitry-super@prueba.ec', 'ClaveBuena1', '123456')
    chk(r.status_code == 302 and val("SELECT COUNT(*) FROM usuarios WHERE email = 'dimitry-super@prueba.ec'") == '1', 'Entrar otra vez reutiliza el mismo súper')
    # El súper que ya existe (con clave local aleatoria) y también el de crear_admin siguen entrando como siempre
    s3, r = entrar('super@prueba.com', 'clave12345')
    chk(r.status_code == 302 and 'admin.php' in r.headers.get('Location', ''), 'El súper de siempre (clave local) sigue entrando sin tocar el panel')

    print('4. Fallas: mensajes sin dar pistas')
    s, r = entrar('dimitry-super@prueba.ec', 'mala')
    chk(r.status_code == 200 and 'Correo o contraseña incorrectos.' in r.text and 'name="codigo"' not in r.text, 'Clave mala: mensaje de siempre y no pide código')
    s, r = entrar('dimitry-super@prueba.ec', 'ClaveBuena1', '000000')
    chk('El código de 2 pasos no es correcto.' in r.text and 'name="codigo"' in r.text, 'Código malo: vuelve a pedir el código con el texto del panel')
    s, r = entrar('bloqueado@prueba.ec', 'ClaveBuena1', '123456')
    chk('espera 15 minutos' in r.text, 'Bloqueado: muestra el texto del panel')
    s, r = entrar('sin2@prueba.ec', 'ClaveBuena1', '123456')
    chk('Primero activa la verificación en 2 pasos' in r.text, 'Sin 2 pasos: dice que lo active en el panel')
    chk(val("SELECT COUNT(*) FROM usuarios WHERE email IN ('bloqueado@prueba.ec','sin2@prueba.ec')") == '0', 'Ninguna de esas entró ni creó usuarios')

    print('5. Panel central caído')
    open(os.path.join(SIM_DIR, 'caido'), 'w').close()
    s, r = entrar('dimitry-super@prueba.ec', 'ClaveBuena1', '123456')
    chk(r.status_code == 200 and 'Correo o contraseña incorrectos.' in r.text, 'Panel en mantenimiento: no entra, mensaje de siempre')
    s, r = entrar('escuela@prueba.com', 'clave12345')
    chk(r.status_code == 302, 'Con el panel caído el cliente normal entra igual')
    os.remove(os.path.join(SIM_DIR, 'caido'))

    print('6. TUKAN_SOLO_CENTRAL=1: el súper entra con clave y código; el local con clave sola, no')
    open(ENV, 'a').write('TUKAN_SOLO_CENTRAL=1\n')
    s, r = entrar('super@prueba.com', 'clave12345')
    chk(r.status_code == 200 and 'solo-central' in r.text, 'El súper local con solo su clave local: bloqueado con el aviso de siempre')
    s, r = entrar('dimitry-super@prueba.ec', 'ClaveBuena1', '123456')
    chk(r.status_code == 302 and 'admin.php' in r.headers.get('Location', ''), 'Con la clave y el código del Panel entra aunque sea solo-central')
    s, r = entrar('escuela@prueba.com', 'clave12345')
    chk(r.status_code == 302, 'Los clientes siguen entrando')
    s, r = entrar('dimitry-super@prueba.ec', 'ClaveBuena1')
    chk('name="codigo"' in r.text and 'solo-central' not in r.text, 'Sin código en solo-central: pide el código (no muestra el bloqueo)')

    print('7. Un correo que ya es de un cliente nunca se vuelve súper')
    s, r = entrar('escuela@prueba.com', 'ClaveBuena1', '123456')
    chk(r.status_code == 200 and val("SELECT rol FROM usuarios WHERE email = 'escuela@prueba.com'") != 'super', 'El panel acepta esa clave pero el admin de escuela sigue siendo de la escuela')
finally:
    panel.terminate()
    try:
        os.remove(ENV)
    except OSError:
        pass
    subprocess.run(['rm', '-rf', SIM_DIR])

for log in (os.path.join(REPO, 'pruebas', 'tukan_central', 'web.log'), os.path.join(REPO, 'pruebas', 'v38', 'web.log')):
    if os.path.exists(log) and os.path.getmtime(log) > time.time() - 3600:
        chk(not ERRORES.search(open(log, errors='replace').read()), 'php -S sin errores de PHP (%s)' % os.path.relpath(log, REPO))
print('Súper por el login — correctas: %d · Fallas: %d' % (ok, len(fallos)))
sys.exit(1 if fallos else 0)
