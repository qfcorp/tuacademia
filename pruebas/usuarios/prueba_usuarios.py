#!/usr/bin/env python3
"""
Pruebas de seguridad de los usuarios del panel (21 oct 2026). El mismo archivo sirve para TuVocalía y TuAcademia.
  - El perfil tiene que estar en perfiles_para(tipo de cuenta) al CREAR y al CAMBIAR, en el servidor
    (Usuarios del administrador de la liga, Usuarios del súper y Ligas → Editar → Usuarios). Si no: error y no se guarda nada
    (antes se guardaba como 'admin' en silencio). Sin perfil tampoco se crea.
  - Nadie se cambia el perfil, se desactiva ni se elimina a sí mismo (ya existía: se mantiene).
  - Siempre queda al menos 1 Administrador activo por liga o escuela: al último no se le quita el perfil, no se desactiva
    ni se elimina.

Antes (base nueva):
  TuVocalía:   TA_PUERTO=8231 bash pruebas/tukan_central/reiniciar.sh
  TuAcademia:  TA_PUERTO=8231 TA_SIM=8232 bash pruebas/v38/reiniciar.sh
Correr:        TA_PUERTO=8231 python3 pruebas/usuarios/prueba_usuarios.py
"""
import os, re, secrets, subprocess, sys, time
import requests

PUERTO = os.environ.get('TA_PUERTO', '8196')
B = 'http://127.0.0.1:' + PUERTO + '/'
REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
ERRORES = re.compile(r'(Fatal error|Warning:|Deprecated:|Uncaught|Notice:|Parse error)')
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
    return php('echo valor(' + repr(sql) + ', ' + repr(list(p)).replace("'", '"') + ');')


def csrf(html):
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', html)
    return m.group(1) if m else ''


def ip():
    return '10.%d.%d.%d' % (secrets.randbelow(250), secrets.randbelow(250), 1 + secrets.randbelow(250))


def entrar(email, clave='clave12345'):
    s = requests.Session()
    r = s.get(B + 'admin.php?p=login')
    s.post(B + 'admin.php?p=login', data={'csrf': csrf(r.text), 'email': email, 'clave': clave}, headers={'CF-Connecting-IP': ip()})
    return s


def enviar(s, pagina, **datos):
    r = s.get(B + 'admin.php?p=' + pagina)
    datos['csrf'] = csrf(r.text)
    return s.post(B + 'admin.php?p=' + pagina, data=datos)


def uid(email):
    return val('SELECT id FROM usuarios WHERE email = ?', email)


def perfil(email):
    return val('SELECT perfil FROM usuarios WHERE email = ?', email)


LIGA = val("SELECT liga_id FROM usuarios WHERE email = 'liga@prueba.com'")
ESC = val("SELECT liga_id FROM usuarios WHERE email = 'escuela@prueba.com'")
chk(LIGA != '' and ESC != '', 'Hay una liga y una escuela de prueba')
MAL_PERFIL = 'no es válido para esta cuenta'
import json


def perfiles_de(liga_id):  # claves de perfiles_para() de esa cuenta y las que existen pero no le tocan
    p = json.loads(php('echo json_encode(array_keys(perfiles_para(fila("SELECT * FROM ligas WHERE id = ?", [%s]))));' % liga_id))
    todos = json.loads(php('echo json_encode(array_keys(perfiles()));'))
    return p, [k for k in todos if k not in p]


P_LIGA, AJ_LIGA = perfiles_de(LIGA)
P_ESC, AJ_ESC = perfiles_de(ESC)
chk('secretario' in P_LIGA and 'tesorero' in P_LIGA and 'admin' in P_LIGA and 'secretario' in P_ESC and AJ_LIGA and AJ_ESC,
    'Cada cuenta tiene perfiles propios y perfiles que no le tocan (liga: %s · escuela: %s)' % (AJ_LIGA, AJ_ESC))
AJ_L, AJ_E = (AJ_LIGA or ['x'])[0], (AJ_ESC or ['x'])[0]
OTRO_L = next((k for k in P_LIGA if k not in ('admin', 'secretario', 'tesorero', 'asistente')), 'consulta')
ULTIMO = 'último Administrador activo'

print('-- 1. Administrador de la liga: perfil válido al crear y al cambiar')
a = entrar('liga@prueba.com')
chk('Usuarios' in a.get(B + 'admin.php?p=usuarios').text, 'El administrador de la liga abre Usuarios')
r = enviar(a, 'usuarios', accion='crear', nombre='Perfil Inventado', email='inventado@prueba.com', clave='clave12345', perfil='jefe_supremo', acepta_datos='1')
chk(uid('inventado@prueba.com') == '', 'Crear con un perfil inventado no crea nada (antes quedaba como Administrador)')
chk(MAL_PERFIL in r.text, '...y muestra el error')
enviar(a, 'usuarios', accion='crear', nombre='Sin Perfil', email='sinperfil@prueba.com', clave='clave12345', acepta_datos='1')
chk(uid('sinperfil@prueba.com') == '', 'Crear sin perfil no crea nada (no se pone Administrador por defecto)')
r = enviar(a, 'usuarios', accion='crear', nombre='Perfil Ajeno', email='profeliga@prueba.com', clave='clave12345', perfil=AJ_L, acepta_datos='1')
chk(uid('profeliga@prueba.com') == '', 'Crear con un perfil que no es de este tipo de cuenta (%s) no crea nada' % AJ_L)
chk(MAL_PERFIL in r.text, '...y muestra el error')
enviar(a, 'usuarios', accion='crear', nombre='Secretaria Liga', email='secre@prueba.com', clave='clave12345', perfil='secretario', acepta_datos='1')
chk(perfil('secre@prueba.com') == 'secretario', 'Crear con un perfil válido (Secretario) funciona')
SEC = uid('secre@prueba.com')
r = enviar(a, 'usuarios', accion='perfil', id=SEC, perfil='jefe_supremo')
chk(perfil('secre@prueba.com') == 'secretario', 'Cambiar a un perfil inventado no cambia nada (antes quedaba Administrador)')
chk(MAL_PERFIL in r.text, '...y muestra el error')
enviar(a, 'usuarios', accion='perfil', id=SEC, perfil=AJ_L)
chk(perfil('secre@prueba.com') == 'secretario', 'Cambiar a un perfil que no es de este tipo de cuenta (%s) no cambia nada' % AJ_L)
enviar(a, 'usuarios', accion='perfil', id=SEC, perfil='tesorero')
chk(perfil('secre@prueba.com') == 'tesorero', 'Cambiar a un perfil válido (Tesorero) funciona')

print('-- 2. Su propia cuenta (se mantiene)')
YO = uid('liga@prueba.com')
r = enviar(a, 'usuarios', accion='perfil', id=YO, perfil='consulta')
chk(perfil('liga@prueba.com') == 'admin' and 'No puede cambiar su propio perfil' in r.text, 'No puede cambiarse su propio perfil')
r = enviar(a, 'usuarios', accion='activo', id=YO)
chk(val('SELECT activo FROM usuarios WHERE id = ?', YO) == '1' and 'No puede desactivarse a sí mismo' in r.text, 'No puede desactivarse a sí mismo')
r = enviar(a, 'usuarios', accion='eliminar', id=YO)
chk(uid('liga@prueba.com') == YO and 'No puede eliminarse a sí mismo' in r.text, 'No puede eliminarse a sí mismo')

print('-- 3. Con 2 administradores activos sí se puede bajar o desactivar al otro')
enviar(a, 'usuarios', accion='crear', nombre='Segundo Admin', email='admin2@prueba.com', clave='clave12345', perfil='admin', acepta_datos='1')
A2 = uid('admin2@prueba.com')
chk(perfil('admin2@prueba.com') == 'admin', 'Se crea un segundo Administrador')
enviar(a, 'usuarios', accion='activo', id=A2)
chk(val('SELECT activo FROM usuarios WHERE id = ?', A2) == '0', 'Desactivar al otro Administrador (queda uno activo) funciona')
enviar(a, 'usuarios', accion='activo', id=A2)
enviar(a, 'usuarios', accion='perfil', id=A2, perfil='consulta')
chk(perfil('admin2@prueba.com') == 'consulta', 'Quitarle el perfil Administrador al otro (queda uno activo) funciona')

print('-- 4. Súper (Usuarios): el último Administrador activo de la liga no se toca')
su = entrar('super@prueba.com')
chk('Usuarios' in su.get(B + 'admin.php?p=usuarios').text, 'El súper abre Usuarios')
r = enviar(su, 'usuarios', accion='perfil', id=YO, perfil='consulta')
chk(perfil('liga@prueba.com') == 'admin', 'No se le quita el perfil al último Administrador activo')
chk(ULTIMO in r.text, '...y lo explica')
r = enviar(su, 'usuarios', accion='activo', id=YO)
chk(val('SELECT activo FROM usuarios WHERE id = ?', YO) == '1', 'No se desactiva al último Administrador activo')
chk(ULTIMO in r.text, '...y lo explica')
r = enviar(su, 'usuarios', accion='eliminar', id=YO)
chk(uid('liga@prueba.com') == YO, 'No se elimina al último Administrador activo')
chk(ULTIMO in r.text, '...y lo explica')
# Un Administrador inactivo no cuenta: el activo sigue siendo el último
php("q(\"UPDATE usuarios SET perfil = 'admin', activo = 0 WHERE id = ?\", [%s]);" % A2)
enviar(su, 'usuarios', accion='activo', id=YO)
chk(val('SELECT activo FROM usuarios WHERE id = ?', YO) == '1', 'Un Administrador inactivo no cuenta para la regla')
r = enviar(su, 'usuarios', accion='crear', liga_id=LIGA, nombre='Super Crea Mal', email='supermal@prueba.com', clave='clave12345', perfil=AJ_L, acepta_datos='1')
chk(uid('supermal@prueba.com') == '' and MAL_PERFIL in r.text, 'El súper tampoco crea con un perfil que no es de la cuenta')
enviar(su, 'usuarios', accion='perfil', id=SEC, perfil='jefe_supremo')
chk(perfil('secre@prueba.com') == 'tesorero', 'El súper tampoco cambia a un perfil inventado')
enviar(su, 'usuarios', accion='perfil', id=SEC, perfil=OTRO_L)
chk(perfil('secre@prueba.com') == OTRO_L, 'El súper cambia a un perfil válido de la cuenta (%s)' % OTRO_L)

print('-- 5. Súper (Ligas → Editar → Usuarios), en la escuela')
EA = uid('escuela@prueba.com')
r = enviar(su, 'ligas&editar=' + ESC, accion='admin_crear', id=ESC, nombre='Ajeno Escuela', email='discesc@prueba.com', clave='clave12345', perfil=AJ_E)
chk(uid('discesc@prueba.com') == '', 'Crear en la escuela con un perfil de ligas (%s) no crea nada' % AJ_E)
chk(MAL_PERFIL in r.text, '...y muestra el error')
enviar(su, 'ligas&editar=' + ESC, accion='admin_crear', id=ESC, nombre='Inventado Escuela', email='invesc@prueba.com', clave='clave12345', perfil='jefe_supremo')
chk(uid('invesc@prueba.com') == '', 'Crear con un perfil inventado no crea nada')
enviar(su, 'ligas&editar=' + ESC, accion='admin_crear', id=ESC, nombre='Profesora Escuela', email='profesc@prueba.com', clave='clave12345', perfil='secretario')
chk(perfil('profesc@prueba.com') == 'secretario', 'Crear en la escuela con Secretario funciona')
PE = uid('profesc@prueba.com')
enviar(su, 'ligas&editar=' + ESC, accion='admin_perfil', id=ESC, usuario_id=PE, perfil=AJ_E)
chk(perfil('profesc@prueba.com') == 'secretario', 'Cambiar en la escuela a un perfil de ligas no cambia nada (antes se guardaba)')
r = enviar(su, 'ligas&editar=' + ESC, accion='admin_perfil', id=ESC, usuario_id=EA, perfil='secretario')
chk(perfil('escuela@prueba.com') == 'admin' and ULTIMO in r.text, 'No se le quita el perfil al último Administrador de la escuela')
r = enviar(su, 'ligas&editar=' + ESC, accion='admin_activo', id=ESC, usuario_id=EA)
chk(val('SELECT activo FROM usuarios WHERE id = ?', EA) == '1' and ULTIMO in r.text, 'No se desactiva al último Administrador de la escuela')
r = enviar(su, 'ligas&editar=' + ESC, accion='admin_eliminar', id=ESC, usuario_id=EA)
chk(uid('escuela@prueba.com') == EA and ULTIMO in r.text, 'No se elimina al último Administrador de la escuela')
enviar(su, 'ligas&editar=' + ESC, accion='admin_perfil', id=ESC, usuario_id=PE, perfil='admin')
enviar(su, 'ligas&editar=' + ESC, accion='admin_activo', id=ESC, usuario_id=EA)
chk(val('SELECT activo FROM usuarios WHERE id = ?', EA) == '0', 'Con otro Administrador activo en la escuela, sí se desactiva al primero')
enviar(su, 'ligas&editar=' + ESC, accion='admin_activo', id=ESC, usuario_id=EA)
enviar(su, 'ligas&editar=' + ESC, accion='admin_eliminar', id=ESC, usuario_id=PE)
chk(uid('profesc@prueba.com') == '', 'Con otro Administrador activo, sí se elimina al segundo')
chk(val("SELECT COUNT(*) FROM usuarios WHERE liga_id = ? AND rol = 'liga' AND perfil = 'admin' AND activo = 1", ESC) == '1', 'La escuela sigue con 1 Administrador activo')
chk(val("SELECT COUNT(*) FROM usuarios WHERE liga_id = ? AND rol = 'liga' AND perfil = 'admin' AND activo = 1", LIGA) == '1', 'La liga sigue con 1 Administrador activo')

for log in (os.path.join(REPO, 'pruebas', 'tukan_central', 'web.log'), os.path.join(REPO, 'pruebas', 'v38', 'web.log')):
    if os.path.exists(log) and os.path.getmtime(log) > time.time() - 3600:
        chk(not ERRORES.search(open(log, errors='replace').read()), 'php -S sin errores de PHP (%s)' % os.path.relpath(log, REPO))
print('Usuarios — correctas: %d · Fallas: %d' % (ok, len(fallos)))
sys.exit(1 if fallos else 0)
