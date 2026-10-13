#!/usr/bin/env python3
"""
Pruebas de TuAcademia (lo nuevo para academias) contra php -S + simulador de Payphone.
Antes:  bash pruebas/v38/reiniciar.sh        (modo academia, por defecto)
Correr: python3 pruebas/academia/prueba_academia.py

Prueba: marca y producto academia (ligas apagadas, planes nuevos, Google Play apagado), registro de una academia,
academias de baile, música y natación (cursos, palabras, campos del alumno), niveles con examen y certificado QR,
recital con participantes, ensayos y cobro de vestuario, paquete de clases que se descuenta, DeUna con comprobante
(un comprobante para varios valores), Payphone (aprobado, cancelado, monto distinto, repetido), Mensajes de hoy,
y que el portal de padres lo muestre todo. Al final revisa que php -S no haya registrado errores de PHP.
"""
import json, os, re, subprocess, sys, time
from datetime import date, timedelta
import requests

PUERTO = os.environ.get('TA_PUERTO', '8196')
B = 'http://127.0.0.1:' + PUERTO + '/'
DIR = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(DIR))
V38 = os.path.join(REPO, 'pruebas', 'v38')
SIM = os.path.join(V38, 'sim_estado.json')
ERRORES = re.compile(r'(Fatal error|Warning:|Deprecated:|Uncaught|Notice:|Parse error)')
ok, fallos = 0, []
HOY = date.today()


def chk(cond, nombre):
    global ok
    if cond:
        ok += 1
    else:
        fallos.append(nombre)
        print('FALLA:', nombre)


def limpio(r, nombre):
    chk(r.status_code < 500 and not ERRORES.search(r.text), nombre + ' sin errores PHP (' + str(r.status_code) + ')')
    m = ERRORES.search(r.text)
    if m:
        print('   →', r.text[max(0, m.start() - 100):m.end() + 300].replace('\n', ' '))


def csrf(html):
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', html) or re.search(r'data-csrf="([0-9a-f]+)"', html)
    return m.group(1) if m else ''


def php(codigo):
    r = subprocess.run(['php', '-r', 'require "app/bootstrap.php"; ' + codigo], cwd=REPO, capture_output=True, text=True)
    if r.returncode != 0 or ERRORES.search(r.stdout + r.stderr):
        print('PHP CLI:', r.stdout[-800:], r.stderr[-800:])
    return r.stdout.strip()


def pj(codigo):
    return json.loads(php('echo json_encode(' + codigo + ', JSON_UNESCAPED_UNICODE);') or 'null')


def entrar(email, clave='clave12345'):
    s = requests.Session()
    r = s.get(B + 'admin.php?p=login')
    r = s.post(B + 'admin.php?p=login', data={'csrf': csrf(r.text), 'email': email, 'clave': clave})
    chk('salir' in r.text.lower(), 'login ' + email)
    return s


def post(s, url, datos, files=None):
    r = s.get(B + url)
    datos = dict(datos)
    datos['csrf'] = csrf(r.text)
    return s.post(B + url, data=datos, files=files)


def imagen():
    # PNG de 1x1 válido (el comprobante)
    return ('comprobante.png', bytes.fromhex('89504e470d0a1a0a0000000d4948445200000001000000010806000000'
                                              '1f15c4890000000d49444154789c6360000002000100e221bc330000000049454e44ae426082'), 'image/png')


def sim():
    return json.load(open(SIM)) if os.path.exists(SIM) else {}


def sim_guardar(e):
    for k in ('tokens', 'subs', 'payphone'):
        if not isinstance(e.get(k), dict) or not e.get(k):
            e[k] = e.get(k) or {}
    json.dump(e, open(SIM, 'w'))


# =====================================================================
# 1. Producto academia: marca, portada, planes, Google Play apagado, ligas apagadas
# =====================================================================
anon = requests.Session()
r = anon.get(B + 'index.php')
limpio(r, 'portada')
chk('TuAcademia' in r.text and 'TuVocal' not in r.text, 'portada con la marca TuAcademia')
chk('Academias de baile' in r.text and 'Escuelas de música' in r.text and 'Escuelas de natación' in r.text, 'portada habla de baile, música y natación')
chk('$19.00' in r.text and '$29.00' in r.text, 'planes Básico $19 y Completo $29 en la portada')
chk('$95.00' in r.text and '$290.00' in r.text, 'semestral $95 (paga 5) y anual $290 (paga 10)')
chk('Vocalía' not in r.text and 'Árbitros' not in r.text and 'campeonato' not in r.text.lower(), 'portada sin ligas, campeonatos, árbitros ni vocalía')
chk('14 días' in r.text, 'prueba gratis de 14 días')
planes = pj('filas("SELECT slug, precio_mensual, precio_semestral, precio_anual, dominio_anual FROM planes WHERE activo = 1 ORDER BY orden")')
chk([p['slug'] for p in planes] == ['academia-basico', 'academia-completo'], 'solo los 2 planes de academia activos (%s)' % [p['slug'] for p in planes])
chk(planes[1]['dominio_anual'] == 1 and float(planes[1]['precio_anual']) == 290, 'Completo anual con dominio')
r = anon.get(B + 'manifest.php')
chk(r.json().get('name') == 'TuAcademia', 'manifest PWA con el nombre TuAcademia')
r = anon.get(B + 'assetlinks.php')
chk(r.status_code == 200 and r.json() == [], 'assetlinks vacío (sin llave de firma todavía)')
chk(pj('play_permitido()') is False and pj('play_activo()') is False, 'Google Play apagado')
r = anon.get(B + 'app.php?origen=play')
limpio(r, 'app.php con origen play')
chk('Ingresar (academia y profesores)' in r.text and 'Soy jugador' not in r.text, 'inicio de la app para academias')
for portal in ['vocal.php?k=abc', 'arbitro.php', 'delegado.php', 'mi.php']:
    r = anon.get(B + portal)
    chk(r.status_code == 404 and 'No disponible' in r.text, 'portal de ligas apagado: ' + portal)
r = anon.get(B + 'index.php?v=ligas')
limpio(r, 'directorio')
chk('Academias que usan' in r.text, 'directorio muestra academias, no ligas')

# Registro abierto (prueba gratis de 14 días): la cuenta nueva es academia
r = anon.get(B + 'index.php?v=registro')
limpio(r, 'registro')
chk('Tipo de cuenta' not in r.text and 'Disciplina principal' in r.text and 'Liga (campeonatos)' not in r.text, 'registro sin elegir liga: siempre academia')
r = anon.post(B + 'index.php?v=registro', data={'csrf': csrf(r.text), 'sitio': '', 'tipo': 'liga', 'nombre': 'Academia de Danza Allegro', 'ciudad': 'Quito',
                                                  'deporte_escuela': 'ballet', 'deportes[]': ['baile'], 'modalidad': 'F7', 'color': '#7b3fb0', 'plan_id': str(planes and 8),
                                                  'admin_nombre': 'María Torres', 'telefono': '0991112233', 'email': 'baile@prueba.com', 'clave': 'clave12345', 'clave2': 'clave12345', 'acepto': '1'})
limpio(r, 'crear academia por el registro')
l_baile = pj('fila("SELECT * FROM ligas WHERE contacto_email = \'baile@prueba.com\'")')
chk(l_baile and l_baile['tipo'] == 'escuela' and l_baile['deporte_escuela'] == 'ballet' and l_baile['deportes'] == 'baile', 'academia de baile creada como escuela (aunque mandó tipo=liga)')
chk(l_baile and l_baile['vence'] == str(HOY + timedelta(days=14)) and int(l_baile['es_prueba']) == 1, 'con 14 días de prueba')
chk(int(pj('valor("SELECT COUNT(*) FROM torneos WHERE liga_id = ?", [' + str(l_baile['id']) + '])')) == 0, 'sin campeonato creado')
LB = int(l_baile['id'])

# =====================================================================
# 2. Academia de BAILE: menú, cursos, palabras, campos, niveles
# =====================================================================
ba = entrar('baile@prueba.com')
r = ba.get(B + 'admin.php')
limpio(r, 'resumen de la academia de baile')
for txt in ['Cursos y horarios', 'Niveles y exámenes', 'Recitales y presentaciones', 'Mensajes de hoy', 'Clases particulares']:
    chk(txt in r.text, 'menú con ' + txt)
for txt in ['Campeonatos', 'Árbitros', 'Equipos</b>', 'Vocalía']:
    chk(txt not in r.text, 'menú sin ' + txt)
chk('Academia de ballet' in r.text or 'Academia de Ballet' in r.text, 'subtítulo «Academia de ballet»')
chk('🎓 TuAcademia' in r.text, 'encabezado del panel con TuAcademia')
r = ba.get(B + 'admin.php?p=torneos')
chk('Campeonatos' not in r.text.split('<main')[-1][:3000] or 'Hoy tienen clase' in r.text, 'admin.php?p=torneos no abre campeonatos')
r = post(ba, 'admin.php?p=grupos', {'accion': 'guardar', 'id': '0', 'nombre': 'Ballet infantil', 'deporte': 'ballet', 'categoria': 'abierta', 'anio_desde': '', 'anio_hasta': '',
                                     'dias[]': ['2', '4'], 'hora_inicio': '16:00', 'hora_fin': '17:00', 'cancha': 'Salón de espejos', 'cupo': '15', 'mensualidad': '40', 'activo': '1'})
limpio(r, 'crear curso de ballet')
chk('Curso guardado' in r.text, 'mensaje «Curso guardado»')
r = post(ba, 'admin.php?p=grupos', {'accion': 'guardar', 'id': '0', 'nombre': 'Salsa adultos', 'deporte': 'salsa', 'categoria': 'abierta', 'dias[]': ['5'],
                                     'hora_inicio': '19:00', 'hora_fin': '20:30', 'cancha': 'Salón 2', 'cupo': '0', 'mensualidad': '35', 'activo': '1'})
g_ballet = pj('fila("SELECT * FROM esc_grupos WHERE liga_id = ' + str(LB) + ' AND nombre = \'Ballet infantil\'")')
g_salsa = pj('fila("SELECT * FROM esc_grupos WHERE liga_id = ' + str(LB) + ' AND nombre = \'Salsa adultos\'")')
chk(g_ballet and g_ballet['deporte'] == 'ballet' and g_salsa['deporte'] == 'salsa', 'cursos de ballet y salsa')
r = ba.get(B + 'admin.php?p=grupos')
limpio(r, 'cursos y horarios')
chk('Cursos y horarios' in r.text and 'Disciplina' in r.text and 'Sub-10' not in r.text and 'Jugar en una liga' not in r.text, 'pantalla de cursos con palabras de academia')
chk('optgroup label="💃 Baile y danza"' in r.text and '💃 Salsa' in r.text and '🎹 Piano' not in r.text, 'disciplinas agrupadas; solo las de su plan (baile, no música)')
chk(int(pj('valor("SELECT COUNT(*) FROM esc_tests WHERE liga_id = ' + str(LB) + ' AND deporte = \'baile\'")')) == 5, 'tests de fábrica de baile')
chk(int(pj('valor("SELECT COUNT(*) FROM esc_habilidades WHERE liga_id = ' + str(LB) + ' AND deporte = \'baile\'")')) == 7, 'habilidades de fábrica de baile')
chk(int(pj('valor("SELECT COUNT(*) FROM esc_ejercicios WHERE liga_id = ' + str(LB) + ' AND deporte = \'baile\'")')) == 7, 'ejercicios de fábrica de baile')
r = ba.get(B + 'admin.php?p=alumnos')
limpio(r, 'alumnos (formulario)')
chk('Talla de vestuario' in r.text and 'Pie hábil' not in r.text and 'name="posicion"' in r.text and 'Instrumento' not in r.text and 'Carril' not in r.text, 'ficha de baile: talla de vestuario, sin pie hábil ni posición visible')
chk('Nivel actual' in r.text and 'Iniciación' in r.text, 'ficha con el nivel actual de ballet')

def nuevo_alumno(s, nombres, apellidos, gid, nac, extra=None, rep=('Ana Pérez', '1710034065', '0987654321')):
    d = {'accion': 'nuevo', 'nombres': nombres, 'apellidos': apellidos, 'cedula': '', 'fecha_nac': nac, 'genero': 'F', 'grupo_id': str(gid),
         'representante_nombre': rep[0], 'representante_cedula': rep[1], 'representante_telefono': rep[2], 'parentesco': 'Madre', 'autorizacion': '1', 'fecha_ingreso': str(HOY)}
    d.update(extra or {})
    r = post(s, 'admin.php?p=alumnos', d)
    limpio(r, 'inscribir ' + nombres)
    return pj('fila("SELECT * FROM esc_alumnos WHERE nombres = \'' + nombres + '\' AND apellidos = \'' + apellidos + '\'")')

nivel_basico = pj('fila("SELECT * FROM esc_cinturones WHERE liga_id = ' + str(LB) + ' AND disciplina = \'ballet\' AND nombre = \'Básico\'")')
chk(nivel_basico is not None, 'niveles de fábrica de ballet cargados')
niveles_ballet = pj('array_column(filas("SELECT nombre FROM esc_cinturones WHERE liga_id = ' + str(LB) + ' AND disciplina = \'ballet\' ORDER BY orden"), "nombre")')
chk(niveles_ballet == ['Iniciación', 'Básico', 'Intermedio', 'Avanzado', 'Pre-profesional'], 'niveles de baile: Iniciación a Pre-profesional')
sofia = nuevo_alumno(ba, 'Sofía', 'Andrade', g_ballet['id'], '2016-05-10', {'talla_vestuario': '10', 'nivel_inicial': str(nivel_basico['id'])})
emma = nuevo_alumno(ba, 'Emma', 'Andrade', g_ballet['id'], '2018-02-01', {'talla_vestuario': '8'})
valen = nuevo_alumno(ba, 'Valentina', 'Ruiz', g_ballet['id'], '2015-09-20', {'talla_vestuario': '12'}, rep=('Carla Ruiz', '0900079195', '0991234567'))
chk(sofia and sofia['talla_vestuario'] == '10' and emma and valen, 'alumnas con talla de vestuario')
chk(pj('nivel_actual(' + str(sofia['id']) + ', "ballet")')['grado'] == 'Básico', 'nivel inicial «Básico» guardado en la ficha')
r = ba.get(B + 'admin.php?p=alumno&id=' + str(sofia['id']))
limpio(r, 'ficha de Sofía')
chk('talla 10' in r.text and 'Básico' in r.text, 'ficha muestra talla y nivel')
r = ba.get(B + 'admin.php?p=alumno&id=' + str(sofia['id']) + '&s=desarrollo')
limpio(r, 'desarrollo de Sofía')
chk('Nivel en Ballet' in r.text and 'Intermedio' in r.text, 'tarjeta de nivel con el próximo (Intermedio)')

# --- Examen de nivel con certificado QR ---
r = ba.get(B + 'admin.php?p=examenes')
limpio(r, 'niveles y exámenes')
chk('Niveles y exámenes' in r.text and 'Exámenes de Ballet' in r.text and 'Cinturón' not in r.text, 'pantalla de niveles con palabras de baile')
r = post(ba, 'admin.php?p=examenes', {'accion': 'nuevo', 'disciplina': 'ballet', 'fecha': str(HOY + timedelta(days=10)), 'hora': '10:00', 'lugar': 'Salón de espejos',
                                       'examinador': 'Prof. Lucía Mena', 'valor': '15', 'notas': ''})
limpio(r, 'crear examen de nivel')
ex = pj('fila("SELECT * FROM esc_examenes WHERE liga_id = ' + str(LB) + ' ORDER BY id DESC")')
chk(ex and ex['disciplina'] == 'ballet', 'examen de ballet creado')
EXU = 'admin.php?p=examenes&id=' + str(ex['id'])
r = ba.get(B + EXU)
limpio(r, 'examen de nivel')
asp_s = re.search(r'name="aspira\[' + str(sofia['id']) + r'\]">(.*?)</select>', r.text, re.S)
chk(asp_s and 'selected>Intermedio' in asp_s.group(1).replace('" selected', ' selected').replace('"', ''), 'Sofía aspira a Intermedio (el próximo)')
nivel_ini = pj('fila("SELECT * FROM esc_cinturones WHERE liga_id = ' + str(LB) + ' AND disciplina = \'ballet\' AND nombre = \'Básico\'")')
nivel_int = pj('fila("SELECT * FROM esc_cinturones WHERE liga_id = ' + str(LB) + ' AND disciplina = \'ballet\' AND nombre = \'Intermedio\'")')
r = post(ba, EXU, {'accion': 'inscribir', 'alumnos[]': [str(sofia['id']), str(emma['id'])], 'aspira[%d]' % sofia['id']: str(nivel_int['id']), 'aspira[%d]' % emma['id']: str(nivel_ini['id'])})
limpio(r, 'inscribir al examen')
chk(int(pj('valor("SELECT COUNT(*) FROM esc_examen_inscritos WHERE examen_id = ' + str(ex['id']) + '")')) == 2, '2 inscritas en el examen')
r = post(ba, EXU, {'accion': 'cobrar'})
chk(int(pj('valor("SELECT COUNT(*) FROM esc_cargos WHERE liga_id = ' + str(LB) + ' AND tipo = \'examen\' AND monto = 15")')) == 2, 'derecho a examen cobrado a las 2')
ins = pj('filas("SELECT * FROM esc_examen_inscritos WHERE examen_id = ' + str(ex['id']) + ' ORDER BY alumno_id")')
datos = {'accion': 'resultados'}
for i in ins:
    datos['res[%d]' % i['id']] = 'aprobado' if int(i['alumno_id']) == int(sofia['id']) else 'reprobado'
    datos['nota[%d]' % i['id']] = '9' if int(i['alumno_id']) == int(sofia['id']) else '5'
r = post(ba, EXU, datos)
limpio(r, 'resultados del examen')
r = post(ba, EXU, {'accion': 'cerrar'})
limpio(r, 'cerrar examen')
chk('Cerrado' in r.text and 'nivel nuevo' in r.text.lower(), 'examen cerrado con «nivel nuevo»')
chk(pj('nivel_actual(' + str(sofia['id']) + ', "ballet")')['grado'] == 'Intermedio', 'Sofía sube a Intermedio')
chk(pj('nivel_actual(' + str(emma['id']) + ', "ballet")') is None, 'Emma (no aprobó) sigue sin nivel')
cod = pj('valor("SELECT certificado FROM esc_examen_inscritos WHERE examen_id = ' + str(ex['id']) + ' AND alumno_id = ' + str(sofia['id']) + '")')
chk(cod and len(cod) == 12, 'código de certificado generado')
r = ba.get(B + EXU + '&doc=certificados')
limpio(r, 'certificados')
chk('CERTIFICADO DE NIVEL' in r.text and 'aprobó el examen de nivel' in r.text and 'data-url=' in r.text and 'certificado.php?c=' + cod in r.text, 'certificado de nivel con QR')
r = ba.get(B + EXU + '&doc=acta')
limpio(r, 'acta del examen')
chk('ACTA DEL EXAMEN DE NIVEL' in r.text and 'Nivel actual' in r.text, 'acta del examen de nivel')
r = anon.get(B + 'certificado.php?c=' + cod)
limpio(r, 'verificar certificado')
chk('CERTIFICADO VÁLIDO' in r.text and 'Ballet' in r.text and 'Intermedio' in r.text and 'Academia de Danza Allegro' in r.text, 'verificación pública del certificado')
r = anon.get(B + 'certificado.php?c=ABCDEFGHJKLM')
chk(r.status_code == 404 and 'NO VÁLIDO' in r.text, 'certificado falso: no válido')

# =====================================================================
# 3. Recital con participantes, tallas, ensayos y cobro de vestuario
# =====================================================================
r = ba.get(B + 'admin.php?p=recitales')
limpio(r, 'recitales')
chk('Recitales y presentaciones' in r.text, 'pantalla de recitales')
fecha_rec = HOY + timedelta(days=40)
r = post(ba, 'admin.php?p=recitales', {'accion': 'nuevo', 'tipo': 'recital', 'nombre': 'Recital de Navidad', 'fecha': str(fecha_rec), 'hora': '17:00', 'lugar': 'Teatro Sucre',
                                        'valor_inscripcion': '10', 'valor_vestuario': '25', 'vence_cobro': '', 'publico': '1'})
limpio(r, 'crear recital')
ev = pj('fila("SELECT * FROM acad_eventos WHERE liga_id = ' + str(LB) + '")')
chk(ev and ev['nombre'] == 'Recital de Navidad', 'recital creado')
EVU = 'admin.php?p=recitales&id=' + str(ev['id'])
r = post(ba, EVU, {'accion': 'numero', 'nid': '0', 'titulo': 'El lago de los cisnes', 'grupo_id': str(g_ballet['id']), 'con_curso': '1', 'disciplina': '', 'profesor': 'Lucía Mena',
                    'autor': 'Chaikovski', 'duracion': '5', 'vestuario': 'Tutú blanco', 'valor_vestuario': ''})
limpio(r, 'agregar coreografía con el curso')
n1 = pj('fila("SELECT * FROM acad_numeros WHERE evento_id = ' + str(ev['id']) + ' ORDER BY id")')
chk(n1 and n1['disciplina'] == 'ballet' and int(pj('valor("SELECT COUNT(*) FROM acad_participantes WHERE numero_id = ' + str(n1['id']) + '")')) == 3, 'coreografía con las 3 alumnas del curso')
chk(pj('valor("SELECT talla FROM acad_participantes WHERE numero_id = ' + str(n1['id']) + ' AND alumno_id = ' + str(sofia['id']) + '")') == '10', 'la talla viene de la ficha')
r = post(ba, EVU, {'accion': 'numero', 'nid': '0', 'titulo': 'Cascanueces (solo)', 'grupo_id': '', 'disciplina': 'ballet', 'profesor': '', 'autor': '', 'duracion': '3', 'vestuario': 'Vestido rojo', 'valor_vestuario': '40'})
n2 = pj('fila("SELECT * FROM acad_numeros WHERE evento_id = ' + str(ev['id']) + ' ORDER BY id DESC")')
r = post(ba, EVU, {'accion': 'participantes', 'nid': str(n2['id']), 'alumnos[]': [str(sofia['id'])]})
limpio(r, 'agregar participante a la segunda coreografía')
r = post(ba, EVU, {'accion': 'talla', 'nid': str(n2['id']), 'talla[%d]' % int(pj('valor("SELECT id FROM acad_participantes WHERE numero_id = ' + str(n2['id']) + '")')): '12',
                    'rol[%d]' % int(pj('valor("SELECT id FROM acad_participantes WHERE numero_id = ' + str(n2['id']) + '")')): 'Solista'})
chk(pj('fila("SELECT talla, rol FROM acad_participantes WHERE numero_id = ' + str(n2['id']) + '")') == {'talla': '12', 'rol': 'Solista'}, 'talla y papel guardados')
r = post(ba, EVU, {'accion': 'ensayo', 'fecha': str(HOY + timedelta(days=30)), 'hora_inicio': '15:00', 'hora_fin': '17:00', 'numero_id': '', 'tipo': 'general', 'lugar': 'Teatro Sucre', 'notas': 'Con vestuario'})
r = post(ba, EVU, {'accion': 'ensayo', 'fecha': str(HOY + timedelta(days=20)), 'hora_inicio': '16:00', 'hora_fin': '17:00', 'numero_id': str(n2['id']), 'tipo': 'ensayo', 'lugar': 'Salón 1', 'notas': ''})
limpio(r, 'ensayos')
chk(int(pj('valor("SELECT COUNT(*) FROM acad_ensayos WHERE evento_id = ' + str(ev['id']) + '")')) == 2, '2 ensayos')
r = post(ba, EVU, {'accion': 'cobrar'})
limpio(r, 'cargar cobros del recital')
chk('3 inscripción(es) y 4 vestuario(s)' in r.text, 'cobros: 3 inscripciones y 4 vestuarios')
ves = pj('filas("SELECT alumno_id, monto, descripcion, tipo FROM esc_cargos WHERE liga_id = ' + str(LB) + ' AND tipo = \'vestuario\' ORDER BY monto, alumno_id")')
chk(len(ves) == 4 and sum(float(v['monto']) for v in ves) == 25 * 3 + 40, 'vestuario $25 × 3 + $40 del solo')
chk(any('talla 12' in v['descripcion'] and 'Cascanueces' in v['descripcion'] for v in ves), 'el cobro del vestuario dice la coreografía y la talla')
chk(int(pj('valor("SELECT COUNT(*) FROM esc_cargos WHERE liga_id = ' + str(LB) + ' AND tipo = \'recital\' AND monto = 10")')) == 3, 'inscripción $10 una vez por alumna (Sofía está en 2)')
r = post(ba, EVU, {'accion': 'cobrar'})
chk('No hay cobros nuevos' in r.text, 'cargar cobros otra vez no duplica')
r = ba.get(B + EVU + '&doc=lista')
limpio(r, 'lista de participantes')
chk('Tutú blanco' in r.text and 'Solista' in r.text, 'lista imprimible con vestuario y papel')
r = ba.get(B + EVU + '&doc=programa')
limpio(r, 'programa del recital')
chk('El lago de los cisnes' in r.text and 'Chaikovski' in r.text, 'programa imprimible')
pid_emma = int(pj('valor("SELECT id FROM acad_participantes WHERE numero_id = ' + str(n1['id']) + ' AND alumno_id = ' + str(emma['id']) + '")'))
r = post(ba, EVU, {'accion': 'quitar', 'pid': str(pid_emma)})
chk(int(pj('valor("SELECT COUNT(*) FROM esc_cargos WHERE alumno_id = ' + str(emma['id']) + ' AND tipo IN (\'vestuario\', \'recital\') AND estado = \'pendiente\'")')) == 0, 'quitar a Emma anula su vestuario e inscripción pendientes')
r = ba.get(B + EVU)
limpio(r, 'detalle del recital')

# =====================================================================
# 4. Mensajes de hoy (recordatorio de pensiones por WhatsApp)
# =====================================================================
php('q("UPDATE ligas SET deuna_celular = ?, deuna_titular = ?, datos_pago = ? WHERE id = ?", ["0998887766", "Academia Allegro", "Banco Pichincha cta. 2200112233", ' + str(LB) + ']);')
# Pensiones de prueba: una vencida (Sofía), una que vence en 2 días (Valentina, otra familia) y una que vence en 10 días (no debe salir)
php('foreach ([[' + str(sofia['id']) + ', -5, "Pensión de septiembre"], [' + str(valen['id']) + ', 2, "Pensión de octubre"], [' + str(valen['id']) + ', 10, "Pensión de noviembre"]] as [$a, $d, $t]) '
    'q("INSERT INTO esc_cargos (liga_id, alumno_id, tipo, periodo, descripcion, monto, vence, estado) VALUES (?,?,?,?,?,?,?,\'pendiente\')", [' + str(LB) + ', $a, "otro", "", $t, 40, date("Y-m-d", strtotime("$d days"))]);')
r = ba.get(B + 'admin.php?p=mensajes')
limpio(r, 'mensajes de hoy')
chk('Mensajes de hoy' in r.text and 'Ana Pérez' in r.text and 'Carla Ruiz' in r.text, 'las 2 familias en la lista')
fams = pj('acad_mensajes_del_dia(fila("SELECT * FROM ligas WHERE id = ' + str(LB) + '"))')
fa = fams.get('c:1710034065', {})
fc = fams.get('c:0900079195', {})
chk(fa and fa['vencido'] and 'Sofía' in fa['alumnos'].values() and 'Emma' in fa['alumnos'].values(), 'familia Pérez: vencido y con sus 2 hijas en un solo mensaje')
chk(fc and any(c['descripcion'] == 'Pensión de octubre' for c in fc['cargos']) and all('noviembre' not in c['descripcion'] for c in fc['cargos']), 'familia Ruiz: con la que vence en 2 días y sin la de dentro de 10 días')
chk('DeUna al 0998887766 (Academia Allegro)' in fa.get('texto', '') and 'familia.php' in fa.get('texto', '') and 'Hola Ana' in fa.get('texto', ''), 'mensaje con saludo, DeUna, transferencia y enlace al portal')
chk('https://wa.me/593987654321?text=' in r.text, 'botón de WhatsApp con el celular en formato internacional')
chk(int(pj('acad_mensajes_pendientes(fila("SELECT * FROM ligas WHERE id = ' + str(LB) + '"))')) == 2, 'contador del menú: 2 por enviar')
r = post(ba, 'admin.php?p=mensajes', {'accion': 'enviado', 'clave': 'c:0900079195'})
limpio(r, 'marcar como enviado')
chk(int(pj('acad_mensajes_pendientes(fila("SELECT * FROM ligas WHERE id = ' + str(LB) + '"))')) == 1, 'queda 1 por enviar')
chk('Enviados hoy (1)' in r.text, 'sección de enviados hoy')
r = post(ba, 'admin.php?p=mensajes', {'accion': 'enviado', 'clave': 'c:0900079195'})
chk(int(pj('valor("SELECT COUNT(*) FROM acad_mensajes WHERE liga_id = ' + str(LB) + '")')) == 1, 'marcar 2 veces no duplica')
r = ba.post(B + 'admin.php?p=mensajes', data={'csrf': csrf(ba.get(B + 'admin.php?p=mensajes').text), 'accion': 'enviado', 'clave': 'c:1710034065', 'ajax': '1'})
chk(r.headers.get('Content-Type', '').startswith('application/json') and r.json().get('ok'), 'marcar desde el botón de WhatsApp (sin recargar)')
r = post(ba, 'admin.php?p=mensajes', {'accion': 'deshacer', 'clave': 'c:1710034065'})
chk(int(pj('acad_mensajes_pendientes(fila("SELECT * FROM ligas WHERE id = ' + str(LB) + '"))')) == 1, 'deshacer: vuelve a pendientes')
r = post(ba, 'admin.php?p=mensajes', {'accion': 'dias', 'dias': '12'})
fams12 = pj('acad_mensajes_del_dia(fila("SELECT * FROM ligas WHERE id = ' + str(LB) + '"))')
chk(any('noviembre' in c['descripcion'] for c in fams12.get('c:0900079195', {}).get('cargos', [])), 'con 12 días de anticipación sale también la de noviembre')
post(ba, 'admin.php?p=mensajes', {'accion': 'dias', 'dias': '3'})

# =====================================================================
# 5. Portal de padres: DeUna con comprobante (uno para varios valores) y aprobación
# =====================================================================
r = post(ba, 'admin.php?p=alumno&id=' + str(sofia['id']), {'accion': 'familia'})
cod_f = pj('valor("SELECT codigo FROM esc_familias WHERE liga_id = ' + str(LB) + ' AND cedula = \'1710034065\'")')
chk(cod_f and len(cod_f) == 8, 'código de acceso para la familia')
fam = requests.Session()
r = fam.get(B + 'familia.php?c=' + cod_f)
r = fam.post(B + 'familia.php', data={'csrf': csrf(r.text), 'accion': 'crear_clave', 'c': cod_f, 'pin': '4321', 'pin2': '4321'})
limpio(r, 'portal de padres')
chk('Pagar' in r.text and '0998887766' in r.text and 'DeUna' in r.text, 'portal: cuadro Pagar con el DeUna de la academia')
chk('Próximos recitales' in r.text and 'Recital de Navidad' in r.text and 'Cascanueces' in r.text and 'Ensayo general' in r.text, 'portal: recital, coreografías y ensayos de Sofía')
chk('Nivel en Ballet' in fam.get(B + 'familia.php?h=' + str(sofia['id']) + '&v=desarrollo').text, 'portal: nivel de Sofía en desarrollo')
pend_fam = pj('array_column(filas("SELECT c.id FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE a.representante_cedula = \'1710034065\' AND c.estado = \'pendiente\' AND c.tipo IN (\'otro\', \'vestuario\')"), "id")')
chk(len(pend_fam) >= 3, 'la familia tiene varios valores pendientes (%d)' % len(pend_fam))
r = fam.get(B + 'familia.php')
r = fam.post(B + 'familia.php', data={'csrf': csrf(r.text), 'accion': 'comprobante_varios', 'forma': 'deuna', 'referencia': 'DU-7788', 'cargos[]': [str(x) for x in pend_fam[:3]]}, files={'foto': imagen()})
limpio(r, 'subir comprobante de DeUna')
chk('Recibimos su pago por DeUna' in r.text, 'mensaje de DeUna recibido')
grupo = pj('array_values(array_unique(array_column(filas("SELECT comprobante_grupo FROM esc_cargos WHERE id IN (' + ','.join(str(x) for x in pend_fam[:3]) + ')"), "comprobante_grupo")))')
chk(len(grupo) == 1 and grupo[0].startswith('g'), 'un solo comprobante para los 3 valores')
chk(pj('valor("SELECT COUNT(*) FROM esc_cargos WHERE comprobante_forma = \'deuna\' AND comprobante_estado = \'pendiente\' AND comprobante_ref = \'DU-7788\'")') == 3, 'marcados como DeUna, en revisión, con el número')
r = fam.get(B + 'familia.php')
chk('en revisión' in r.text, 'portal: comprobante en revisión')
fams_h = pj('acad_mensajes_del_dia(fila("SELECT * FROM ligas WHERE id = ' + str(LB) + '"))')
chk(all(int(c['cargo_id']) not in pend_fam[:3] for c in fams_h.get('c:1710034065', {}).get('cargos', [])), 'lo que tiene comprobante en revisión no sale en Mensajes de hoy')
r = ba.get(B + 'admin.php?p=cobros&ver=comprobantes')
limpio(r, 'comprobantes en Pagos')
chk('📱 DeUna' in r.text and 'DU-7788' in r.text and '$105.00' in r.text, 'Pagos → Comprobantes muestra el DeUna una vez con el total')
cid0 = pend_fam[0]
r = post(ba, 'admin.php?p=cobros&ver=comprobantes', {'accion': 'aprobar_comp', 'id': str(cid0)})
limpio(r, 'aprobar DeUna')
chk('(DeUna)' in r.text and 'por 3 valores' in r.text, 'aprobado: un recibo por los 3 valores')
pag = pj('filas("SELECT estado, forma_pago, recibo FROM esc_cargos WHERE id IN (' + ','.join(str(x) for x in pend_fam[:3]) + ')")')
chk(all(p['estado'] == 'pagado' and p['forma_pago'] == 'deuna' for p in pag) and len(set(p['recibo'] for p in pag)) == 1, 'los 3 pagados por DeUna con el mismo recibo')
r = ba.get(B + 'admin.php?p=esc_recibo&n=' + str(pag[0]['recibo']))
limpio(r, 'recibo')
chk('DeUna' in r.text, 'el recibo dice DeUna')
# Rechazo
pend2 = pj('array_column(filas("SELECT c.id FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE a.representante_cedula = \'1710034065\' AND c.estado = \'pendiente\'"), "id")')
r = fam.get(B + 'familia.php')
r = fam.post(B + 'familia.php', data={'csrf': csrf(r.text), 'accion': 'comprobante_varios', 'forma': 'transferencia', 'referencia': '', 'cargos[]': [str(pend2[0])]}, files={'foto': imagen()})
r = post(ba, 'admin.php?p=cobros&ver=comprobantes', {'accion': 'rechazar_comp', 'id': str(pend2[0])})
chk(pj('valor("SELECT comprobante_estado FROM esc_cargos WHERE id = ' + str(pend2[0]) + '")') == 'rechazado', 'comprobante rechazado')
chk('comprobante rechazado' in fam.get(B + 'familia.php').text, 'portal: avisa el rechazo')

# =====================================================================
# 6. Payphone (pago con tarjeta de los padres; simulado)
# =====================================================================
php('q("UPDATE ligas SET payphone_token = ?, payphone_store_id = ? WHERE id = ?", ["tok-academia-123", "store-77", ' + str(LB) + ']);')
chk(pj('acad_tiene_payphone(fila("SELECT * FROM ligas WHERE id = ' + str(LB) + '"))') is True, 'Payphone listo (en prueba tiene todos los módulos)')
r = fam.get(B + 'familia.php')
chk('Pagar con tarjeta (Payphone)' in r.text, 'portal: botón de Payphone')
pend3 = pj('array_column(filas("SELECT c.id FROM esc_cargos c JOIN esc_alumnos a ON a.id = c.alumno_id WHERE a.representante_cedula = \'1710034065\' AND c.estado = \'pendiente\' AND COALESCE(c.comprobante_estado, \'\') <> \'pendiente\' ORDER BY c.id"), "id")')
total3 = round(float(pj('valor("SELECT SUM(monto - descuento) FROM esc_cargos WHERE id IN (' + ','.join(str(x) for x in pend3[:2]) + ')")')), 2)
r = fam.post(B + 'familia.php', data={'csrf': csrf(r.text), 'accion': 'payphone', 'cargos[]': [str(x) for x in pend3[:2]]})
limpio(r, 'preparar Payphone')
chk('PPaymentButtonBox' in r.text and '"storeId":"store-77"' in r.text and '"amount":%d' % round(total3 * 100) in r.text, 'cajita de Payphone con la tienda y el monto en centavos')
pp = pj('fila("SELECT * FROM acad_payphone ORDER BY id DESC")')
chk(pp and re.match(r'^TA\d+-[0-9a-f]{8}$', pp['cliente_tx']) and float(pp['monto']) == total3, 'transacción de Payphone guardada')
e = sim(); e.setdefault('payphone', {})
e['payphone'] = e['payphone'] or {}
e['payphone']['9001'] = {'estado': 'Approved', 'monto': round(total3 * 100) + 100, 'token': 'tok-academia-123'}
sim_guardar(e)
r = fam.get(B + 'payphone.php?id=9001&clientTransactionId=' + pp['cliente_tx'])
limpio(r, 'respuesta de Payphone (monto distinto)')
chk('NO APROBADO' in r.text and pj('valor("SELECT estado FROM esc_cargos WHERE id = ' + str(pend3[0]) + '")') == 'pendiente', 'monto distinto: no se cobra')
r = fam.post(B + 'familia.php', data={'csrf': csrf(fam.get(B + 'familia.php').text), 'accion': 'payphone', 'cargos[]': [str(x) for x in pend3[:2]]})
pp = pj('fila("SELECT * FROM acad_payphone ORDER BY id DESC")')
e = sim(); e['payphone']['9002'] = {'estado': 'Approved', 'monto': round(total3 * 100), 'token': 'tok-academia-123'}; sim_guardar(e)
r = fam.get(B + 'payphone.php?id=9002&clientTransactionId=' + pp['cliente_tx'])
limpio(r, 'respuesta de Payphone (aprobado)')
chk('PAGO APROBADO' in r.text and 'recibo' in r.text, 'Payphone aprobado con recibo')
est = pj('filas("SELECT estado, forma_pago FROM esc_cargos WHERE id IN (' + ','.join(str(x) for x in pend3[:2]) + ')")')
chk(all(x['estado'] == 'pagado' and x['forma_pago'] == 'payphone' for x in est), 'valores pagados con Payphone')
r = fam.get(B + 'payphone.php?id=9002&clientTransactionId=' + pp['cliente_tx'])
chk('PAGO APROBADO' in r.text and sim()['payphone']['9002'].get('confirmado') == 1, 'volver a abrir la respuesta no cobra 2 veces')
r = fam.get(B + 'payphone.php?id=9002&clientTransactionId=TA999-deadbeef')
chk('No pudimos confirmar' in r.text, 'transacción desconocida: rechazada')
r = fam.post(B + 'familia.php', data={'csrf': csrf(fam.get(B + 'familia.php').text), 'accion': 'payphone', 'cargos[]': [str(pend3[-1])]})
pp = pj('fila("SELECT * FROM acad_payphone ORDER BY id DESC")')
e = sim(); e['payphone']['9003'] = {'estado': 'Canceled', 'monto': 0, 'token': 'tok-academia-123'}; sim_guardar(e)
r = fam.get(B + 'payphone.php?id=9003&clientTransactionId=' + pp['cliente_tx'])
chk('PAGO CANCELADO' in r.text, 'Payphone cancelado por el padre')

# =====================================================================
# 7. Academia de MÚSICA: clases particulares con paquetes que se descuentan
# =====================================================================
su = entrar('super@prueba.com')
r = su.get(B + 'admin.php?p=ligas&nueva=1')
limpio(r, 'súper: nueva academia')
chk('Nueva academia' in r.text and 'Crear también su primer campeonato' not in r.text and 'Tipo de cuenta' not in r.text, 'súper: formulario solo de academia')
r = post(su, 'admin.php?p=ligas&nueva=1', {'accion': 'crear', 'nombre': 'Escuela de Música Do Re Mi', 'ciudad': 'Cuenca', 'tipo': 'escuela', 'modalidad': 'F7', 'deporte_escuela': 'piano',
                                             'deportes[]': ['musica'], 'color': '#2563c9', 'slug': '', 'admin_nombre': 'Jorge Vélez', 'admin_email': 'musica@prueba.com', 'admin_clave': 'clave12345',
                                             'telefono': '0990001122', 'plan_id': '8', 'vigencia': 'pagada', 'vence': str(HOY + timedelta(days=30)), 'dias_prueba': '14'})
limpio(r, 'súper crea academia de música')
lm = pj('fila("SELECT * FROM ligas WHERE contacto_email = \'musica@prueba.com\'")')
chk(lm and lm['tipo'] == 'escuela' and lm['deporte_escuela'] == 'piano' and lm['deportes'] == 'musica', 'academia de música creada')
LM = int(lm['id'])
mu = entrar('musica@prueba.com')
post(mu, 'admin.php?p=grupos', {'accion': 'guardar', 'id': '0', 'nombre': 'Piano nivel 1', 'deporte': 'piano', 'categoria': 'abierta', 'dias[]': ['1', '3'], 'hora_inicio': '15:00',
                                 'hora_fin': '16:00', 'cancha': 'Aula 2', 'cupo': '6', 'mensualidad': '50', 'activo': '1'})
post(mu, 'admin.php?p=grupos', {'accion': 'guardar', 'id': '0', 'nombre': 'Canto grupal', 'deporte': 'canto', 'categoria': 'abierta', 'dias[]': ['6'], 'hora_inicio': '10:00',
                                 'hora_fin': '11:00', 'cancha': 'Aula 1', 'cupo': '10', 'mensualidad': '35', 'activo': '1'})
g_piano = pj('fila("SELECT * FROM esc_grupos WHERE liga_id = ' + str(LM) + ' AND nombre = \'Piano nivel 1\'")')
r = mu.get(B + 'admin.php?p=alumnos')
chk('Instrumento' in r.text and 'Talla de vestuario' not in r.text and 'Pie hábil' not in r.text, 'ficha de música: instrumento, sin talla ni pie hábil')
mateo = nuevo_alumno(mu, 'Mateo', 'Vega', g_piano['id'], '2012-03-15', {'instrumento': 'Piano'}, rep=('Luis Vega', '0912345675', '0981112222'))
chk(mateo and mateo['instrumento'] == 'Piano', 'alumno con instrumento')
niveles_piano = pj('array_column(filas("SELECT nombre FROM esc_cinturones WHERE liga_id = ' + str(LM) + ' AND disciplina = \'piano\' ORDER BY orden"), "nombre")')
chk(niveles_piano == ['Nivel 1', 'Nivel 2', 'Nivel 3', 'Nivel 4', 'Nivel 5', 'Nivel 6'], 'niveles de música: Nivel 1 a 6')
r = mu.get(B + 'admin.php')
limpio(r, 'resumen de la academia de música')
chk('Hoy tienen clase' in r.text and 'Hoy entrenan' not in r.text, 'palabras de música: «Hoy tienen clase»')
chk(int(pj('valor("SELECT COUNT(*) FROM esc_tests WHERE liga_id = ' + str(LM) + ' AND deporte = \'musica\'")')) == 5, 'tests de fábrica de música')
r = mu.get(B + 'admin.php?p=planificacion')
limpio(r, 'planificar clases (música)')
r = mu.get(B + 'admin.php?p=particulares&v=paquetes')
limpio(r, 'paquetes de clases')
paq = pj('filas("SELECT * FROM acad_paquetes WHERE liga_id = ' + str(LM) + ' ORDER BY clases")')
chk([int(p['clases']) for p in paq] == [4, 8, 12], 'paquetes de fábrica de 4, 8 y 12 clases')
r = post(mu, 'admin.php?p=particulares&a=' + str(mateo['id']), {'accion': 'vender', 'alumno_id': str(mateo['id']), 'paquete_id': str(paq[0]['id']), 'profesor_id': '', 'precio': ''})
limpio(r, 'vender paquete de 4 clases')
pa = pj('fila("SELECT * FROM acad_paquetes_alumno WHERE alumno_id = ' + str(mateo['id']) + '")')
chk(pa and int(pa['clases_total']) == 4 and int(pa['clases_usadas']) == 0 and pa['estado'] == 'activo', 'paquete de 4 clases activo')
chk(pj('fila("SELECT tipo, monto FROM esc_cargos WHERE id = ' + str(pa['cargo_id']) + '")') == {'tipo': 'paquete', 'monto': 60}, 'cobro del paquete ($60) en Pagos')
dia = HOY.isoweekday()
r = post(mu, 'admin.php?p=particulares&a=' + str(mateo['id']), {'accion': 'horario', 'alumno_id': str(mateo['id']), 'dia': str(dia), 'hora_inicio': '16:00', 'hora_fin': '16:45', 'profesor_id': '', 'lugar': 'Aula 2'})
limpio(r, 'horario 1 a 1')
r = mu.get(B + 'admin.php?p=particulares')
limpio(r, 'clases del día')
chk('Mateo Vega' in r.text and '4</b> clases' in r.text, 'la clase de hoy aparece con el saldo de 4')
r = post(mu, 'admin.php?p=particulares', {'accion': 'marcar', 'alumno_id': str(mateo['id']), 'fecha': str(HOY), 'hora': '16:00', 'profesor_id': '', 'estado': 'asistio'})
limpio(r, 'marcar asistencia de la clase particular')
chk('le quedan 3' in r.text, 'al marcar «vino» se descuenta: le quedan 3')
r = post(mu, 'admin.php?p=particulares', {'accion': 'marcar', 'alumno_id': str(mateo['id']), 'fecha': str(HOY), 'hora': '16:00', 'profesor_id': '', 'estado': 'asistio'})
chk('ya está marcada' in r.text and int(pj('valor("SELECT clases_usadas FROM acad_paquetes_alumno WHERE id = ' + str(pa['id']) + '")')) == 1, 'la misma clase no se descuenta 2 veces')
ayer = HOY - timedelta(days=1)
post(mu, 'admin.php?p=particulares&a=' + str(mateo['id']), {'accion': 'marcar', 'alumno_id': str(mateo['id']), 'fecha': str(ayer), 'hora': '16:00', 'estado': 'aviso', 'profesor_id': '', 'tema': ''})
chk(int(pj('valor("SELECT clases_usadas FROM acad_paquetes_alumno WHERE id = ' + str(pa['id']) + '")')) == 1, '«avisó que no viene» no descuenta')
post(mu, 'admin.php?p=particulares&a=' + str(mateo['id']), {'accion': 'marcar', 'alumno_id': str(mateo['id']), 'fecha': str(ayer), 'hora': '17:00', 'estado': 'falto', 'profesor_id': '', 'tema': ''})
chk(int(pj('valor("SELECT clases_usadas FROM acad_paquetes_alumno WHERE id = ' + str(pa['id']) + '")')) == 2, '«faltó sin avisar» descuenta (ajuste por defecto)')
php('q("UPDATE ligas SET acad_falta_descuenta = 0 WHERE id = ' + str(LM) + '");')
post(mu, 'admin.php?p=particulares&a=' + str(mateo['id']), {'accion': 'marcar', 'alumno_id': str(mateo['id']), 'fecha': str(ayer), 'hora': '18:00', 'estado': 'falto', 'profesor_id': '', 'tema': ''})
chk(int(pj('valor("SELECT clases_usadas FROM acad_paquetes_alumno WHERE id = ' + str(pa['id']) + '")')) == 2, 'con el ajuste apagado, faltar no descuenta')
for h in ['09:00', '10:00']:
    r = post(mu, 'admin.php?p=particulares&a=' + str(mateo['id']), {'accion': 'marcar', 'alumno_id': str(mateo['id']), 'fecha': str(HOY - timedelta(days=2)), 'hora': h, 'estado': 'asistio', 'profesor_id': '', 'tema': 'Escalas'})
chk('Se terminó el paquete' in r.text, 'al usar la última clase avisa que se terminó')
chk(pj('valor("SELECT estado FROM acad_paquetes_alumno WHERE id = ' + str(pa['id']) + '")') == 'terminado' and int(pj('saldo_clases(' + str(mateo['id']) + ')')) == 0, 'paquete terminado, saldo 0')
r = post(mu, 'admin.php?p=particulares&a=' + str(mateo['id']), {'accion': 'marcar', 'alumno_id': str(mateo['id']), 'fecha': str(HOY - timedelta(days=3)), 'hora': '10:00', 'estado': 'asistio', 'profesor_id': '', 'tema': ''})
chk('No tiene un paquete con clases' in r.text, 'sin paquete: avisa que hay que vender uno')
ult = int(pj('valor("SELECT id FROM acad_clases WHERE alumno_id = ' + str(mateo['id']) + ' AND hora = \'10:00\' AND fecha = \'' + str(HOY - timedelta(days=2)) + '\'")'))
post(mu, 'admin.php?p=particulares&a=' + str(mateo['id']), {'accion': 'borrar_clase', 'id': str(ult)})
chk(int(pj('saldo_clases(' + str(mateo['id']) + ')')) == 1 and pj('valor("SELECT estado FROM acad_paquetes_alumno WHERE id = ' + str(pa['id']) + '")') == 'activo', 'borrar una clase la devuelve al paquete')
r = mu.get(B + 'admin.php?p=alumno&id=' + str(mateo['id']))
limpio(r, 'ficha de Mateo')
chk('le queda 1 clase' in r.text and '🎵 Piano' in r.text, 'ficha: saldo del paquete e instrumento')
r = mu.get(B + 'admin.php?p=particulares&a=' + str(mateo['id']))
limpio(r, 'clases particulares de Mateo')
# Portal del padre de Mateo
post(mu, 'admin.php?p=alumno&id=' + str(mateo['id']), {'accion': 'familia'})
cod_m = pj('valor("SELECT codigo FROM esc_familias WHERE liga_id = ' + str(LM) + ' AND cedula = \'0912345675\'")')
fm = requests.Session()
r = fm.get(B + 'familia.php?c=' + cod_m)
r = fm.post(B + 'familia.php', data={'csrf': csrf(r.text), 'accion': 'crear_clave', 'c': cod_m, 'pin': '1111', 'pin2': '1111'})
limpio(r, 'portal del padre de Mateo')
chk('Clases particulares' in r.text and 'le queda 1' in r.text, 'portal: clases particulares con el saldo')
chk('Pagar con tarjeta' not in r.text, 'sin Payphone configurado no aparece la tarjeta')

# =====================================================================
# 8. Academia de NATACIÓN
# =====================================================================
r = post(su, 'admin.php?p=ligas&nueva=1', {'accion': 'crear', 'nombre': 'Escuela de Natación Delfines', 'ciudad': 'Guayaquil', 'tipo': 'escuela', 'modalidad': 'F7', 'deporte_escuela': 'natacion',
                                             'deportes[]': ['natacion'], 'color': '#0ea5e9', 'slug': '', 'admin_nombre': 'Pedro Mora', 'admin_email': 'natacion@prueba.com', 'admin_clave': 'clave12345',
                                             'telefono': '0990003344', 'plan_id': '7', 'vigencia': 'prueba', 'dias_prueba': '14'})
ln = pj('fila("SELECT * FROM ligas WHERE contacto_email = \'natacion@prueba.com\'")')
chk(ln and ln['deporte_escuela'] == 'natacion', 'academia de natación creada')
LN = int(ln['id'])
na = entrar('natacion@prueba.com')
post(na, 'admin.php?p=grupos', {'accion': 'guardar', 'id': '0', 'nombre': 'Natación bebés', 'deporte': 'natacion', 'categoria': 'abierta', 'dias[]': ['6'], 'hora_inicio': '09:00',
                                 'hora_fin': '09:45', 'cancha': 'Piscina temperada', 'cupo': '8', 'mensualidad': '45', 'activo': '1'})
g_nat = pj('fila("SELECT * FROM esc_grupos WHERE liga_id = ' + str(LN) + '")')
r = na.get(B + 'admin.php?p=grupos')
chk('Piscina' in r.text, 'lugar «Piscina» en el curso')
r = na.get(B + 'admin.php?p=alumnos')
chk('Carril' in r.text and 'Talla de vestuario' not in r.text and 'Instrumento' not in r.text, 'ficha de natación: carril')
niveles_nat = pj('array_column(filas("SELECT nombre FROM esc_cinturones WHERE liga_id = ' + str(LN) + ' AND disciplina = \'natacion\' ORDER BY orden"), "nombre")')
chk(niveles_nat[0].startswith('Matronatación') and niveles_nat[2] == 'Nivel 1 · Flotación' and niveles_nat[-1] == 'Competitivo' and len(niveles_nat) == 9, 'niveles de natación: de matronatación a competitivo (9)')
lucas = nuevo_alumno(na, 'Lucas', 'Mora', g_nat['id'], '2021-06-01', {'carril': '2'}, rep=('Pedro Mora', '0900158387', '0983334444'))
chk(lucas and lucas['carril'] == '2', 'alumno con carril')
chk(int(pj('valor("SELECT COUNT(*) FROM esc_tests WHERE liga_id = ' + str(LN) + ' AND deporte = \'natacion\'")')) == 5, 'tests de natación (25 m libre, espalda, pecho…)')
r = na.get(B + 'admin.php?p=examenes')
limpio(r, 'niveles de natación')
chk('Exámenes de Natación' in r.text, 'exámenes de nivel de natación')
r = na.get(B + 'admin.php?p=competencias')
limpio(r, 'competencias de natación (pruebas y medallas)')
chk('Pruebas y medallas' in r.text, 'natación conserva pruebas con medallas')

# =====================================================================
# 9. Escuela de FÚTBOL en modo academia (lo de siempre sigue funcionando)
# =====================================================================
fu = entrar('escuela@prueba.com')
r = fu.get(B + 'admin.php')
limpio(r, 'escuela de fútbol en modo academia')
chk('Grupos y horarios' in r.text and 'Hoy entrenan' in r.text and 'Hoy tienen clase' not in r.text, 'fútbol mantiene sus palabras (grupos, «Hoy entrenan»)')
r = fu.get(B + 'admin.php?p=alumnos')
chk('Pie hábil' in r.text and 'Posición' in r.text, 'fútbol: posición y pie hábil siguen')
chk('Disciplina principal' in fu.get(B + 'admin.php?p=escuela_config').text, 'parámetros: disciplina principal')
r = fu.get(B + 'admin.php?p=config')
chk('Parámetros de la academia' in r.text, 'p=config en una academia abre sus parámetros')
r = fu.get(B + 'index.php?l=escuela-prueba-play')
limpio(r, 'página pública de la escuela de fútbol')
r = anon.get(B + 'index.php?l=academia-de-danza-allegro')
limpio(r, 'página pública de la academia de baile')
chk('Ballet infantil' in r.text and 'Salón de espejos' in r.text and 'Sub-' not in r.text, 'página pública de baile sin «Sub-»')

# =====================================================================
# 10. Mi plan: planes de academia
# =====================================================================
r = ba.get(B + 'admin.php?p=mi_plan')
limpio(r, 'mi plan')
chk('$19.00' in r.text and '$29.00' in r.text and 'Escuela Completo' not in r.text, 'mi plan: solo los planes de TuAcademia')
chk('play-caja' not in r.text, 'mi plan: sin Google Play')

# =====================================================================
# 11. Academia de demostración (Súper → Ajustes → Demostraciones)
# =====================================================================
r = su.get(B + 'admin.php?p=ajustes')
r = su.post(B + 'admin.php?p=ajustes', data={'csrf': csrf(r.text), 'accion': 'demo', 'demo_activa': '1', 'demo_guardar': '0', 'crear': '1'})
limpio(r, 'crear la academia de demostración')
chk('Academia de demostración lista' in r.text, 'súper: academia de demostración creada')
chk(pj('liga_demo()') is None and pj('escuela_demo()')['nombre'] == 'Academia Demo TuAcademia', 'solo la academia demo (sin liga demo)')
r = anon.get(B + 'index.php?v=demo')
limpio(r, 'página de la demo')
chk('Academia de demostración' in r.text and 'Liga de demostración' not in r.text and 'Dueña de la academia' in r.text, 'página de la demo solo con la academia')
d = requests.Session()
r = d.get(B + 'index.php?v=demo')
r = d.post(B + 'index.php?v=demo', data={'csrf': csrf(r.text), 'rol': 'dueno'})
limpio(r, 'entrar a la demo como dueña')
chk('Academia Demo' in r.text and 'Recitales y presentaciones' in r.text, 'panel de la demo')
for pg in ['mensajes', 'recitales', 'particulares', 'examenes', 'cobros', 'grupos', 'alumnos']:
    limpio(d.get(B + 'admin.php?p=' + pg), 'demo: ' + pg)
r = d.get(B + 'index.php?v=demo')
r = d.post(B + 'index.php?v=demo', data={'csrf': csrf(r.text), 'rol': 'familia'})
limpio(r, 'demo: portal de padres')
chk('Recital de fin de año' in r.text and 'Clases particulares' in r.text, 'demo: la familia ve el recital y las clases de piano')
chk('Ver demo' in anon.get(B + 'index.php').text, 'la portada muestra «Ver demo»')

log = open(os.path.join(V38, 'web.log')).read()
chk(not ERRORES.search(log), 'registro de php -S sin errores PHP')
if ERRORES.search(log):
    print(log[max(0, ERRORES.search(log).start() - 300):][:1500])
print('\nRESULTADO prueba_academia: %d/%d' % (ok, ok + len(fallos)))
sys.exit(1 if fallos else 0)
