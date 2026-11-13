#!/usr/bin/env python3
"""
(13 nov 2026) App de Android «TuAcademia» en Google Play (paquete com.tukansoftware.tuacademia): assetlinks, vps/app_android.sh,
cuenta del revisor de Google (app/revisor_google.php crear | estado | borrar) y que dentro de la app no se vean precios del software
ni pagos o cotizaciones fuera de Google Play.

Antes:  bash pruebas/v38/reiniciar.sh                (modo academia, Google Play apagado como en el servidor)
Correr: python3 pruebas/app_android/prueba_app_android.py
(usa también el puerto TA_PUERTO+5 para un php -S con la carpeta app/, para revisar que los scripts CLI respondan 404 por la web)
"""
import json, os, re, subprocess, sys, tempfile, time
from datetime import date
import requests

PUERTO = int(os.environ.get('TA_PUERTO', '8196'))
B = 'http://127.0.0.1:%d/' % PUERTO
DIR = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(DIR))
ERRORES = re.compile(r'(Fatal error|Warning:|Deprecated:|Uncaught|Notice:|Parse error)')
HUELLA_TUKAN = '39:0C:08:10:D4:40:C1:71:C4:30:C8:D0:F1:93:7B:8F:E4:37:03:25:27:2E:B2:F6:2E:4E:A6:5E:01:98:40:DC'
PAQUETE = 'com.tukansoftware.tuacademia'
REVISOR = 'revisor.google@tukanec.com'
ok, fallos = 0, []


def chk(cond, nombre):
    global ok
    if cond:
        ok += 1
    else:
        fallos.append(nombre)
        print('FALLA:', nombre)


def php(codigo):
    r = subprocess.run(['php', '-r', 'require "app/bootstrap.php"; ' + codigo], cwd=REPO, capture_output=True, text=True)
    if r.returncode != 0 or ERRORES.search(r.stdout + r.stderr):
        print('PHP CLI:', r.stdout, r.stderr)
    return r.stdout.strip()


def val(sql, *p):
    return php('echo json_encode(valor(%s, %s));' % (json.dumps(sql), json.dumps(list(p))))


def ascii(t):
    return all(ord(c) < 128 for c in re.sub(r'\x1b\[[0-9;]*m', '', t))


def csrf(html):
    m = re.search(r'name="csrf" value="([^"]+)"', html)
    return m.group(1) if m else ''


def entrar(clave, app=True):
    s = requests.Session()
    if app:
        s.get(B + 'app.php?origen=play')
    r = s.get(B + 'admin.php?p=login')
    r = s.post(B + 'admin.php?p=login', data={'csrf': csrf(r.text), 'email': REVISOR, 'clave': clave})
    return s, 'salir' in r.text.lower()


def sin_scripts(html):
    return re.sub(r'<script.*?</script>', '', html, flags=re.S)


assert 'SOLO PRUEBAS' in open(os.path.join(REPO, 'app/config.php')).read(), 'primero: bash pruebas/v38/reiniciar.sh'
assert not os.path.exists(os.path.join(REPO, 'data/.servidor'))

# ---------------------------------------------------------------- 1. assetlinks y vps/app_android.sh
print('== 1. assetlinks y vps/app_android.sh')
chk(requests.get(B + '.well-known/assetlinks.json').json() == [], 'Google Play apagado: assetlinks vacío')
tmp = tempfile.mkdtemp()
ng = os.path.join(tmp, 'tuacademia')
open(ng, 'w').write('server {\n    listen 127.0.0.1:8096;\n    location / { try_files $uri $uri/ /index.php?$query_string; }\n    location ~ /\\. { deny all; }\n}\n')
ENV = dict(os.environ, AA_SIN_ROOT='1', AA_DESTINO=REPO, AA_PHP='php', AA_NGINX=ng, AA_COPIA_NGINX=os.path.join(tmp, 'copia'),
           AA_SIN_RECARGAR='1', AA_PUERTO=str(PUERTO))
sh = lambda *a: subprocess.run(['bash', os.path.join(REPO, 'vps/app_android.sh'), *a], env=ENV, capture_output=True, text=True)
r = sh()
print(r.stdout[-400:], r.stderr[-400:])
chk(r.returncode == 0 and 'OK: la app de Android ya puede abrir' in r.stdout, 'app_android.sh termina en OK')
chk(ascii(r.stdout + r.stderr), 'app_android.sh: mensajes sin tildes ni ñ')
chk('Google Play encendido' in r.stdout, 'enciende Google Play')
conf = open(os.path.join(REPO, 'app/config.php')).read()
chk("'google_play' => true" in conf and 'SOLO PRUEBAS' in conf and "'play_api'" in conf, 'config.php: google_play encendido sin perder lo demás ni los comentarios')
chk(php('echo CFG["google_play"] === true ? "si" : "no";') == 'si', 'config.php sigue siendo PHP válido')
chk('location = /.well-known/assetlinks.json { rewrite ^ /assetlinks.php last; }' in open(ng).read()
    and open(ng).read().index('assetlinks') < open(ng).read().index('location ~ /\\.'), 'regla de nginx agregada antes de la que esconde los archivos con punto')
al = requests.get(B + '.well-known/assetlinks.json').json()
chk(len(al) == 1 and al[0]['target']['package_name'] == PAQUETE and al[0]['target']['sha256_cert_fingerprints'] == [HUELLA_TUKAN]
    and al[0]['relation'] == ['delegate_permission/common.handle_all_urls'], 'assetlinks: solo la app com.tukansoftware.tuacademia con la huella de la llave de subida de Tukán')
chk(val("SELECT valor FROM ajustes WHERE clave = 'play_paquete'").strip('"') == PAQUETE, 'ajuste play_paquete = com.tukansoftware.tuacademia')
r = sh()
chk(r.returncode == 0 and 'ya estaba encendido' in r.stdout and 'nginx ya tenia' in r.stdout, 'se puede repetir (no cambia nada)')
HG = 'ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12'
HGN = ':'.join(HG.upper()[i:i + 2] for i in range(0, 64, 2))
r = sh(HG)
chk(r.returncode == 0 and 'Huella de Google agregada' in r.stdout and 'La huella de Google tambien esta puesta.' in r.stdout, 'agrega la huella de Google (64 letras, sin dos puntos)')
al = requests.get(B + '.well-known/assetlinks.json').json()
chk(al[0]['target']['sha256_cert_fingerprints'] == [HUELLA_TUKAN, HGN], 'assetlinks: llave de Tukán + la de Google')
r = sh(HGN.lower())
chk(r.returncode == 0 and 'ya estaba' in r.stdout and len(requests.get(B + '.well-known/assetlinks.json').json()[0]['target']['sha256_cert_fingerprints']) == 2, 'la misma huella otra vez: no se duplica')
antes = open(os.path.join(REPO, 'app/config.php')).read()
r = sh('12:34:nada')
chk(r.returncode == 1 and 'no es una huella' in r.stdout and open(os.path.join(REPO, 'app/config.php')).read() == antes, 'huella mala: error y no toca nada')
r = subprocess.run(['bash', os.path.join(REPO, 'vps/app_android.sh')], env=dict(ENV, AA_DESTINO=tmp), capture_output=True, text=True)
chk(r.returncode == 1 and 'Actualice primero' in r.stdout, 'sin el código nuevo: pide actualizar primero')
codigo = open(os.path.join(REPO, 'vps/vps/vps_2_codigo.sh')).read()
chk('vps/app_android.sh' in codigo and '"$DESTINO/vps/app_android.sh"' in codigo, 'vps_2_codigo.sh deja app_android.sh en /var/www/tuacademia/vps/')
chk(php('echo json_encode(array_values(array_filter(array_keys(play_productos()), fn($k) => !str_starts_with($k, "plan_"))));') == '[]',
    'en Google Play solo se venden los planes (sin disciplinas adicionales)')

# ---------------------------------------------------------------- 2. Cuenta del revisor
print('== 2. Cuenta del revisor de Google')
php('crear_academia_demo();')  # la demo del sistema existe y no se debe tocar
CLIENTES = json.loads(php('echo json_encode(filas("SELECT id, nombre, slug, plan_id, vence, es_demo FROM ligas ORDER BY id"));'))
ALUMNOS_OTROS = val('SELECT COUNT(*) FROM esc_alumnos')
USUARIOS_OTROS = val('SELECT COUNT(*) FROM usuarios')
cli = lambda *a, f='app/revisor_google.php': subprocess.run(['php', f, *a], cwd=REPO, capture_output=True, text=True)

# Solo CLI: por la web responde 404 y no crea nada
web = subprocess.Popen(['php', '-S', '127.0.0.1:%d' % (PUERTO + 5), '-t', os.path.join(REPO, 'app')], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(0.7)
try:
    for f in ('revisor_google.php?accion=crear', 'demo_google.php'):
        x = requests.get('http://127.0.0.1:%d/%s' % (PUERTO + 5, f))
        chk(x.status_code == 404 and x.text == '', f + ' por la web: 404 vacío')
finally:
    web.terminate()
chk(val('SELECT COUNT(*) FROM ligas WHERE slug = ?', 'academia-ejemplo-tukan') == '0', 'por la web no se creó nada')

r = cli()
chk(r.returncode == 1 and 'Uso:' in r.stdout, 'sin acción: muestra el uso')
r = cli('estado')
chk(r.returncode == 0 and 'No hay cuenta del revisor' in r.stdout, 'estado sin cuenta')
r = cli('crear')
print(r.stdout, r.stderr)
chk(r.returncode == 0 and ascii(r.stdout + r.stderr), 'crear: sin errores y mensajes sin tildes ni ñ')
claves = re.findall(r'Contrasena: (\S+)', r.stdout)
chk(len(claves) == 1 and re.fullmatch(r'Demo-\d{6}', claves[0]), 'la contraseña (Demo- + 6 números) sale una sola vez')
clave1 = claves[0] if claves else ''
chk(r.stdout.count(clave1) == 1, 'la contraseña aparece una sola vez en la salida')
chk('Usuario:    ' + REVISOR in r.stdout and 'Academia Ejemplo Tukan' in r.stdout and 'Plan Completo' in r.stdout, 'dice usuario, academia y plan')
lg = json.loads(php('echo json_encode(fila("SELECT l.*, p.slug AS plan_slug FROM ligas l LEFT JOIN planes p ON p.id = l.plan_id WHERE l.slug = ?", ["academia-ejemplo-tukan"]));'))
lid = str(lg['id'])
hoy = date.today()
try:
    en_un_anio = hoy.replace(year=hoy.year + 1).isoformat()
except ValueError:
    en_un_anio = hoy.replace(year=hoy.year + 1, day=28).isoformat()
chk(lg['nombre'] == 'Academia Ejemplo Tukán' and lg['tipo'] == 'escuela' and lg['origen'] == 'google_play', 'Academia Ejemplo Tukán (academia, origen google_play)')
chk(lg['plan_slug'] == 'academia-completo' and int(lg['es_prueba']) == 0 and int(lg['es_demo']) == 0, 'plan Completo, no es demo ni prueba')
chk(lg['vence'] == en_un_anio, 'activa por 1 año (' + str(lg['vence']) + ')')
chk(php('echo estado_suscripcion(fila("SELECT * FROM ligas WHERE id = %s"))["estado"];' % lid) == 'activa', 'suscripción activa')
chk(php('$l = fila("SELECT * FROM ligas WHERE id = %s"); echo json_encode(array_values(array_diff(array_keys(catalogo_modulos(tipo_modulos($l))), modulos_liga($l))));' % lid) == '["facturacion"]'
    and php('echo modulo_activo(fila("SELECT * FROM ligas WHERE id = %s"), "asistente_ia") ? "si" : "no";' % lid) == 'si', 'tiene todos los módulos del plan Completo, con el Asistente IA (TuFactura es «Próximamente»)')
chk(val('SELECT COUNT(*) FROM pagos_suscripcion WHERE liga_id = ?', lid) == '0', 'sin pagos a Tukán')
u = json.loads(php('echo json_encode(fila("SELECT rol, perfil, totp_activo, liga_id FROM usuarios WHERE email = ?", [%s]));' % json.dumps(REVISOR)))
chk(u['rol'] == 'liga' and u['perfil'] == 'admin' and int(u['totp_activo']) == 0 and str(u['liga_id']) == lid, 'el revisor es dueño/administrador de esa academia (sin doble verificación)')
chk(int(val('SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ?', lid)) >= 20, 'alumnos inventados')
chk(int(val('SELECT COUNT(*) FROM esc_grupos WHERE liga_id = ?', lid)) == 5, '5 cursos')
chk(int(val('SELECT COUNT(*) FROM esc_sesiones s JOIN esc_grupos g ON g.id = s.grupo_id WHERE g.liga_id = ?', lid)) > 0
    and int(val('SELECT COUNT(*) FROM esc_asistencia a JOIN esc_sesiones s ON s.id = a.sesion_id JOIN esc_grupos g ON g.id = s.grupo_id WHERE g.liga_id = ?', lid)) > 0, 'asistencia de las últimas semanas')
chk(int(val("SELECT COUNT(*) FROM esc_cargos WHERE liga_id = ? AND tipo = 'mensualidad'", lid)) > 0, 'pensiones')
chk(int(val('SELECT COUNT(*) FROM acad_eventos WHERE liga_id = ?', lid)) == 1, 'un recital')
chk(val("SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ? AND (representante_telefono <> '0990000000' OR emergencia_telefono <> '0990000000')", lid) == '0'
    and val("SELECT COUNT(*) FROM esc_profesores WHERE liga_id = ? AND telefono <> ''", lid) == '0', 'sin teléfonos que puedan ser de alguien de verdad')
chk(val('SELECT COUNT(*) FROM usuarios WHERE liga_id = ?', lid) == '1', 'un solo usuario en la academia de ejemplo (sin el del profesor de la demo)')
otros = json.loads(php('echo json_encode(filas("SELECT id, nombre, slug, plan_id, vence, es_demo FROM ligas WHERE id <> %s ORDER BY id"));' % lid))
chk(otros == CLIENTES, 'no toca ninguna otra academia (ni la demo)')
chk(int(val('SELECT COUNT(*) FROM esc_alumnos WHERE liga_id <> ?', lid)) == int(ALUMNOS_OTROS), 'los alumnos de las otras academias siguen igual')

# Dentro de la app de Google Play
s, entro = entrar(clave1)
chk(entro, 'el revisor entra con esa contraseña (dentro de la app)')
r = s.get(B + 'admin.php')
chk(r.status_code == 200 and 'Academia Ejemplo Tukán' in r.text and not ERRORES.search(r.text), 've su academia')
r = s.get(B + 'admin.php?p=mi_plan')
t = sin_scripts(r.text)
chk(r.status_code == 200 and not ERRORES.search(r.text), 'Mi plan sin errores PHP')
chk('Su mensual' not in t and 'Lo que paga hoy' not in t and '$25.00' not in t, 'Mi plan en la app: sin el valor del software de la web')
chk('cotiz' not in t.lower() and 'Solicitar este módulo' not in t and 'transferencia' not in t.lower() and 'wa.me' not in t, 'Mi plan en la app: sin cotizaciones, pedidos, transferencia ni WhatsApp de ventas')
chk('play-comprar' in t and 'Google Play' in t and 'Deportes adicionales' not in t and 'modulo_' not in t, 'Mi plan en la app: solo los planes con Google Play')
precios = re.findall(r'\$\s?\d[\d.,]*', re.sub(r'<button[^>]*play-comprar.*?</button>', '', t, flags=re.S))
chk(not precios, 'Mi plan en la app: ningún precio fuera de los botones de Google Play ' + str(precios[:5]))
x = s.post(B + 'admin.php?p=mi_plan', data={'csrf': csrf(r.text), 'accion': 'pedir_cotizacion', 'que': 'plan'})
chk('no está disponible en la app' in x.text and val("SELECT COUNT(*) FROM soporte_tickets WHERE liga_id = ?", lid) == '0', 'pedir cotización dentro de la app: rechazado')
for p in ('index.php', 'solicitar-demo', 'planes', 'comparar', 'planes/academia-completo'):
    x = s.get(B + p, allow_redirects=False)
    chk(x.status_code == 302 and x.headers.get('location', '').endswith('app.php'), p + ' dentro de la app: lleva al inicio de la app')
chk(requests.get(B + 'index.php').status_code == 200 and 'Solicitar demo' in requests.get(B + 'index.php').text, 'la portada en la web sigue igual')
w, entro_w = entrar(clave1, app=False)
chk(entro_w and 'Su mensual' in w.get(B + 'admin.php?p=mi_plan').text, 'en la web (fuera de la app) Mi plan sigue como siempre')

# crear otra vez: rehace los datos con clave nueva
r = cli('crear')
clave2 = (re.findall(r'Contrasena: (\S+)', r.stdout) or [''])[0]
chk(r.returncode == 0 and re.fullmatch(r'Demo-\d{6}', clave2) and clave2 != clave1, 'crear otra vez: contraseña nueva')
chk(val('SELECT COUNT(*) FROM ligas WHERE slug = ?', 'academia-ejemplo-tukan') == '1' and val('SELECT COUNT(*) FROM usuarios WHERE email = ?', REVISOR) == '1', 'sigue habiendo una sola academia de ejemplo y un solo usuario')
chk(not entrar(clave1)[1] and entrar(clave2)[1], 'la contraseña vieja ya no sirve; la nueva sí')
lid2 = val('SELECT id FROM ligas WHERE slug = ?', 'academia-ejemplo-tukan')
chk(json.loads(php('echo json_encode(filas("SELECT id, nombre, slug, plan_id, vence, es_demo FROM ligas WHERE id <> %s ORDER BY id"));' % lid2)) == CLIENTES, 'crear otra vez: no toca las otras academias')
r = cli('estado')
chk(r.returncode == 0 and 'Existe' in r.stdout and ascii(r.stdout), 'estado: existe')
r = cli('estado', f='app/demo_google.php')
chk(r.returncode == 0 and 'Existe' in r.stdout, 'app/demo_google.php (nombre de la guía de tukan-apps) hace lo mismo')

# Nunca toca otras cuentas
esc = val("SELECT id FROM usuarios WHERE email = 'escuela@prueba.com'")
php('q("DELETE FROM usuarios WHERE email = ?", [%s]); q("UPDATE ligas SET slug = \'otra-academia\' WHERE id = %s"); q("UPDATE usuarios SET email = ? WHERE id = %s", [%s]);' % (json.dumps(REVISOR), lid2, esc, json.dumps(REVISOR)))
r = cli('crear')
chk(r.returncode == 1 and 'es de otra cuenta' in r.stderr and ascii(r.stderr), 'el correo del revisor en otra academia: se niega')
r = cli('borrar')
chk(r.returncode == 1 and 'es de otra cuenta' in r.stderr and val("SELECT COUNT(*) FROM usuarios WHERE id = ?", esc) == '1', 'borrar tampoco la toca')
php('q("UPDATE usuarios SET email = \'escuela@prueba.com\' WHERE id = %s"); q("UPDATE ligas SET slug = \'academia-ejemplo-tukan\', origen = \'super\' WHERE id = %s");' % (esc, lid2))
r = cli('crear')
chk(r.returncode == 1 and 'es de otra academia' in r.stderr, 'la dirección academia-ejemplo-tukan de una academia que no es la del revisor: se niega')
chk(cli('borrar').returncode == 1 and val('SELECT COUNT(*) FROM ligas WHERE id = ?', lid2) == '1', 'borrar tampoco la toca')
php('q("UPDATE ligas SET origen = \'google_play\' WHERE id = %s");' % lid2)

# borrar
r = cli('borrar')
chk(r.returncode == 0 and 'se borro' in r.stdout and ascii(r.stdout), 'borrar')
chk(val('SELECT COUNT(*) FROM ligas WHERE slug = ?', 'academia-ejemplo-tukan') == '0' and val('SELECT COUNT(*) FROM usuarios WHERE email = ?', REVISOR) == '0', 'se fue la academia y el usuario')
chk(val('SELECT COUNT(*) FROM esc_alumnos WHERE liga_id = ?', lid2) == '0' and val('SELECT COUNT(*) FROM esc_grupos WHERE liga_id = ?', lid2) == '0'
    and val('SELECT COUNT(*) FROM esc_cargos WHERE liga_id = ?', lid2) == '0', 'sin datos sueltos de la academia de ejemplo')
chk(json.loads(php('echo json_encode(filas("SELECT id, nombre, slug, plan_id, vence, es_demo FROM ligas ORDER BY id"));')) == CLIENTES
    and val('SELECT COUNT(*) FROM esc_alumnos') == ALUMNOS_OTROS and val('SELECT COUNT(*) FROM usuarios') == USUARIOS_OTROS, 'todo lo demás quedó como antes')
r = cli('borrar')
chk(r.returncode == 0 and 'nada que borrar' in r.stdout, 'borrar otra vez: nada que borrar')

log = open(os.path.join(REPO, 'pruebas/v38/web.log')).read() if os.path.exists(os.path.join(REPO, 'pruebas/v38/web.log')) else ''
chk(not ERRORES.search(log), 'php -S sin errores de PHP')
print('\nRESULTADO prueba_app_android: %d/%d' % (ok, ok + len(fallos)))
sys.exit(1 if fallos else 0)
