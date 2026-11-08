#!/usr/bin/env python3
# SOLO PRUEBAS. «Cancelar mi plan» (módulo común app/TukanCancelarPlan.php, 7 nov 2026) en Mi plan:
#   la sección sale al administrador, sin la casilla no cancela, cancelar queda guardado, «Seguir con mi plan» lo quita.
# Uso: TA_PUERTO=8221 bash pruebas/tukan_central/reiniciar.sh && TA_PUERTO=8221 python3 pruebas/tukan_central/prueba_cancelar_plan.py
import os, re, subprocess, sys
import requests

PUERTO = os.environ.get('TA_PUERTO', '8196')
B = 'http://127.0.0.1:' + PUERTO + '/'
REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
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
    return r.stdout.strip()


def csrf(html):
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', html)
    return m.group(1) if m else ''


s = requests.Session()
s.trust_env = False
academia = php('echo function_exists("es_academia") && es_academia() ? "1" : "0";') == '1'
correo = 'escuela@prueba.com' if academia else 'liga@prueba.com'
r = s.get(B + 'admin.php?p=login')
s.post(B + 'admin.php?p=login', data={'csrf': csrf(r.text), 'email': correo, 'clave': 'clave12345'})
lid = php('echo (int) db()->query("SELECT id FROM ligas WHERE admin_email = ' + "'" + correo + "'" + '")->fetchColumn();') or php('echo (int) db()->query("SELECT liga_id FROM usuarios WHERE email = ' + "'" + correo + "'" + '")->fetchColumn();')
cuenta = lambda: php('$s = db()->query("SELECT count(*) FROM sqlite_master WHERE name = \'tukan_cancelaciones\'")->fetchColumn(); echo $s ? (int) db()->query("SELECT count(*) FROM tukan_cancelaciones")->fetchColumn() : 0;')

# Aceptar los términos (módulo común app/TukanLegal.php, 7 nov 2026)
r = s.get(B + 'admin.php')
chk('value="aceptar_terminos_tukan"' in r.text and 'tukansoftware.com/terminos.html' in r.text, 'El administrador ve el aviso para aceptar los términos y la privacidad')
s.post(B + 'admin.php?p=mi_plan', data={'csrf': csrf(r.text), 'accion': 'aceptar_terminos_tukan'})
tabla = lambda: php('$s = db()->query("SELECT count(*) FROM sqlite_master WHERE name = \'tukan_aceptaciones\'")->fetchColumn(); echo $s ? (int) db()->query("SELECT count(*) FROM tukan_aceptaciones")->fetchColumn() : 0;')
chk(tabla() == '0', 'Sin marcar la casilla no se acepta')
r = s.get(B + 'admin.php')
s.post(B + 'admin.php?p=mi_plan', data={'csrf': csrf(r.text), 'accion': 'aceptar_terminos_tukan', 'acepto_terminos': '1'})
chk(tabla() == '1', 'Aceptar: queda guardado')
r = s.get(B + 'admin.php')
chk('value="aceptar_terminos_tukan"' not in r.text and 'Términos</a> · <a href="index.php?v=privacidad"' in r.text, 'Ya no sale el aviso y el pie trae Términos · Privacidad')
r = s.get(B + 'admin.php?p=mi_plan')
chk(r.status_code == 200 and 'id="cancelar-plan"' in r.text, 'Mi plan trae «Cancelar mi plan»')
chk('name="confirmo" value="1" required' in r.text and 'Puede cancelar cuando quiera' in r.text, '… con la casilla de confirmar y trato de usted')
s.post(B + 'admin.php?p=mi_plan', data={'csrf': csrf(r.text), 'accion': 'cancelar_plan', 'motivo': 'x'})
chk(cuenta() == '0', 'Sin la casilla no cancela')
r = s.get(B + 'admin.php?p=mi_plan')
s.post(B + 'admin.php?p=mi_plan', data={'csrf': csrf(r.text), 'accion': 'cancelar_plan', 'confirmo': '1', 'motivo': 'Termina la temporada'})
chk(php('echo db()->query("SELECT motivo FROM tukan_cancelaciones")->fetchColumn();') == 'Termina la temporada', 'Cancelar: queda guardada con el motivo')
r = s.get(B + 'admin.php?p=mi_plan')
chk('value="seguir_plan"' in r.text and 'Pidió cancelar su plan' in r.text, 'Mi plan dice «Pidió cancelar su plan» y ofrece seguir')
s.post(B + 'admin.php?p=mi_plan', data={'csrf': csrf(r.text), 'accion': 'seguir_plan'})
chk(cuenta() == '0', 'Seguir con mi plan: se quita')
log = os.path.join(REPO, 'pruebas', 'tukan_central', 'web.log')
chk(not re.search(r'(Fatal error|Warning:|Deprecated:|Uncaught|Notice:)', open(log, encoding='utf-8', errors='ignore').read() if os.path.exists(log) else ''), 'Sin avisos de PHP')
print('\nCancelar mi plan — correctas: %d · fallas: %d' % (ok, len(fallos)))
sys.exit(1 if fallos else 0)
