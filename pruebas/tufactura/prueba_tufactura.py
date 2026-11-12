#!/usr/bin/env python3
"""
(9 nov 2026) Factura electrónica con TuFactura (módulo «facturacion»), contra el TuFactura SIMULADO (pruebas/tufactura/tufactura_simulado.php).
El mismo archivo sirve para TuVocalía y TuAcademia. Prueba: viene apagado (la demo y los planes no lo traen), la pantalla apagada
explica TuFactura, se enciende como extra, se conecta la clave, se facturan pagos de equipos, recibos de la escuela y compras de
entradas (a mano o solos con «automático»), nunca dos veces, con la cédula correcta; apagado no factura. Al final: sin errores de PHP.

Antes (base nueva):
  TuVocalía:   TA_PUERTO=8410 bash pruebas/tukan_central/reiniciar.sh
  TuAcademia:  TA_PUERTO=8412 TA_SIM=8413 bash pruebas/v38/reiniciar.sh
Correr:        TA_PUERTO=8410 python3 pruebas/tufactura/prueba_tufactura.py
"""
import json, os, re, socket, subprocess, sys, tempfile, time
import requests

PUERTO = os.environ.get('TA_PUERTO', '8196')
B = 'http://127.0.0.1:' + PUERTO + '/'
DIR = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(DIR))
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
        print('PHP CLI:', r.stdout[-1500:], r.stderr[-1500:])
    return r.stdout.strip()


def pj(codigo):
    return json.loads(php('echo json_encode(' + codigo + ', JSON_UNESCAPED_UNICODE);') or 'null')


def csrf(html):
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', html)
    return m.group(1) if m else ''


def entrar(email, clave='clave12345'):
    s = requests.Session()
    r = s.get(B + 'admin.php?p=login')
    s.post(B + 'admin.php?p=login', data={'csrf': csrf(r.text), 'email': email, 'clave': clave})
    return s


# TuFactura simulado en un puerto libre
so = socket.socket(); so.bind(('127.0.0.1', 0)); PS = so.getsockname()[1]; so.close()
SIMDIR = tempfile.mkdtemp()
sim = subprocess.Popen(['php', '-S', '127.0.0.1:%d' % PS, os.path.join(DIR, 'tufactura_simulado.php')], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=dict(os.environ, TF_SIM_DIR=SIMDIR))
time.sleep(0.8)
SIM = 'http://127.0.0.1:%d' % PS

LIGA = php("echo (int)valor(\"SELECT id FROM ligas WHERE tipo <> 'escuela' AND es_demo = 0 ORDER BY id LIMIT 1\");")
ESC = php("echo (int)valor(\"SELECT MAX(id) FROM ligas WHERE tipo = 'escuela' AND es_demo = 0\");")
HAY_LIGA = LIGA not in ('', '0')   # TuAcademia en modo academia: solo escuelas
L = "FacturaTukan::liga(" + LIGA + ")"
E = "FacturaTukan::liga(" + ESC + ")"

print('== El módulo')
chk(pj("isset(catalogo_modulos()['facturacion'])"), 'el módulo existe en el catálogo')
chk(pj("in_array('facturacion', modulos_fuera_de_planes(), true)"), 'fuera de los planes («*» no lo trae)')
chk(pj("catalogo_modulos()['facturacion']['precio_mensual']") == 0, 'precio en el programa: $0 (TuFactura se paga aparte)')
if HAY_LIGA: chk(pj("FacturaTukan::activo(" + L + ")") is False, 'la liga lo tiene APAGADO (aunque esté en demo con todo)')
chk(pj("FacturaTukan::activo(" + E + ")") is False, 'la escuela lo tiene APAGADO')
chk(pj("modulo_de_pagina('tufactura') === '' && in_array('tufactura', paginas_base(), true)"), 'la pantalla está en el mapa de páginas (base)')

print('== Pantalla apagada')
s = entrar('liga@prueba.com' if HAY_LIGA else 'escuela@prueba.com')
CW = LIGA if HAY_LIGA else ESC   # la cuenta que se prueba por la web
r = s.get(B + 'admin.php?p=tufactura')
chk(r.status_code == 200 and '<h1>🧾 Factura electrónica</h1>' in r.text and 'Conoce TuFactura' not in r.text and 'TuFactura' in r.text and 'value="conectar"' not in r.text and 'Próximamente' in r.text and 'Quiero facturar' not in r.text, 'la pantalla abre y explica TuFactura, dice Próximamente y no deja pedirla')
chk('p=tufactura' in s.get(B + 'admin.php').text, 'el menú tiene «Factura electrónica»')

if HAY_LIGA:
    print('== Pago de un equipo')
    EQ = php("$e = (int)valor('SELECT id FROM equipos WHERE liga_id = ? ORDER BY id LIMIT 1', [" + LIGA + "]); if (!$e) { q(\"INSERT INTO equipos (liga_id, nombre) VALUES (?, 'Deportivo Prueba')\", [" + LIGA + "]); $e = (int)db()->lastInsertId(); } echo $e;")
    M0 = php("echo registrar_pago(" + LIGA + ", null, " + EQ + ", 25, 'inscripcion', 'Inscripción del campeonato', 'efectivo', '', date('Y-m-d'), 'prueba');")
    chk(pj("FacturaTukan::enlace()->deOrigen(" + LIGA + ", 'cobro', 'm" + M0 + "')") is None, 'apagado: el pago no se factura')
    php("q(\"UPDATE ligas SET modulos_extra = 'facturacion' WHERE id = ?\", [" + LIGA + "]);")
    chk(pj("FacturaTukan::activo(" + L + ")") is True, 'encendido como extra desde el Panel')
    d = pj("FacturaTukan::datos(" + LIGA + ", 'm" + M0 + "')")
    chk(d and d[1][0]['precio'] == 25 and 'Inscripción' in d[1][0]['descripcion'] and d[2]['forma'] == 'efectivo', 'datos del pago: rubro, valor y forma')
    chk(pj("FacturaTukan::datos(" + ESC + ", 'm" + M0 + "')") is None, 'otra cuenta no ve ese pago')
    r = s.get(B + 'admin.php?p=tufactura')
    chk('value="conectar"' in r.text, 'encendido: formulario para conectar')
    r = s.post(B + 'admin.php?p=tufactura', data={'csrf': csrf(r.text), 'tf_accion': 'conectar', 'clave': 'tf_' + 'f6' * 20, 'url': SIM})
    chk('EMPRESA DE PRUEBA' in r.text and 'f6f6f6f6f6f6' not in r.text, 'conecta con TuFactura y la clave no sale al navegador')
    chk(('m' + M0) in r.text, 'la lista de cobros muestra el pago')
    r = s.post(B + 'admin.php?p=tufactura', data={'csrf': csrf(r.text), 'tf_accion': 'facturar_origen', 'origen': 'cobro', 'origen_id': 'm' + M0})
    doc = pj("FacturaTukan::enlace()->deOrigen(" + LIGA + ", 'cobro', 'm" + M0 + "')")
    chk(doc and doc['estado'] == 'autorizado' and abs(float(doc['total']) - 25) <= 0.011, 'facturar a mano: autorizada por lo cobrado')
    chk(json.loads(doc['envio'])['cliente']['identificacion'] == '9999999999999', 'pago de equipo sin datos: consumidor final')
    p = s.get(B + 'admin.php?p=tufactura&pdf=' + str(doc['id']))
    chk(p.status_code == 200 and p.content[:4] == b'%PDF', 'baja el PDF de la factura')
    chk(pj("(int)FacturaTukan::enlace()->facturar(" + LIGA + ", 'cobro', 'm" + M0 + "', ...FacturaTukan::datos(" + LIGA + ", 'm" + M0 + "'))['id']") == int(doc['id']), 'el mismo pago no se factura dos veces')
    M1 = php("echo registrar_pago(" + LIGA + ", null, " + EQ + ", 10, 'arbitraje', 'Arbitraje fecha 1', 'transferencia', '', date('Y-m-d'), 'prueba');")
    chk(pj("FacturaTukan::enlace()->deOrigen(" + LIGA + ", 'cobro', 'm" + M1 + "')") is None, 'sin «automático» no factura solo')
    php("FacturaTukan::enlace()->opciones(" + LIGA + ", true, true, 15);")
    M2 = php("echo registrar_pago(" + LIGA + ", null, " + EQ + ", 10, 'arbitraje', 'Arbitraje fecha 2', 'tarjeta', '', date('Y-m-d'), 'prueba');")
    d2 = pj("FacturaTukan::enlace()->deOrigen(" + LIGA + ", 'cobro', 'm" + M2 + "')")
    chk(d2 and d2['estado'] == 'autorizado' and json.loads(d2['envio'])['forma_pago'] == '19', 'automático: el pago sale facturado solo (tarjeta = 19)')

    print('== Entradas')
    O = php("""q("INSERT INTO bol_ventas (liga_id, titulo, estado) VALUES (?, 'Final del campeonato', 'publicada')", [""" + LIGA + """]); $v = (int)db()->lastInsertId();
    q("INSERT INTO bol_ordenes (venta_id, liga_id, codigo, nombre, cedula, email, canal, metodo, estado, total, iva, imp_municipal, pagada_en) VALUES (?, ?, ?, 'Ana Torres', '1710034065', 'ana@ejemplo.com', 'web', 'tarjeta', 'pagada', 23, 3, 0, ?)", [$v, """ + LIGA + """, bin2hex(random_bytes(10)), date('Y-m-d H:i:s')]);
    $o = (int)db()->lastInsertId(); q('INSERT INTO bol_items (orden_id, localidad_id, cantidad, precio) VALUES (?, 0, 2, 11.5)', [$o]); echo $o;""")
    d = pj("FacturaTukan::datos(" + LIGA + ", 'b" + O + "')")
    chk(d and d[0]['identificacion'] == '1710034065' and d[1][0]['cantidad'] == 2 and d[1][0]['iva'] == 15 and abs(d[1][0]['precio_sin_iva'] * 2 - 20) < 0.01, 'compra de entradas: cédula del comprador y el IVA ya separado')
    f = pj("FacturaTukan::enlace()->facturar(" + LIGA + ", 'cobro', 'b" + O + "', ...FacturaTukan::datos(" + LIGA + ", 'b" + O + "'))")
    chk(f['estado'] == 'autorizado' and abs(float(f['total']) - 23) <= 0.011, 'entradas: factura por lo pagado (23,00)')
    php("q(\"UPDATE bol_ordenes SET total = 0 WHERE id = ?\", [" + O + "]);")
    chk(pj("FacturaTukan::datos(" + LIGA + ", 'b" + O + "')") is None, 'una cortesía ($0) no se factura')

print('== Recibo de la escuela')
php("q(\"UPDATE ligas SET modulos_extra = 'facturacion' WHERE id = ?\", [" + ESC + "]);")
A = php("""q("INSERT INTO esc_alumnos (liga_id, nombres, apellidos, cedula, representante_nombre, representante_cedula, representante_email) VALUES (?, 'Mateo', 'Vera', '', 'Lucía Vera', '1710034065', 'lucia@ejemplo.com')", [""" + ESC + """]); echo (int)db()->lastInsertId();""")
C = php("""q("INSERT INTO esc_cargos (liga_id, alumno_id, tipo, periodo, descripcion, monto, descuento, vence, estado) VALUES (?, ?, 'mensualidad', '2026-11', 'Mensualidad de noviembre', 40, 5, '2026-11-05', 'pendiente')", [""" + ESC + ", " + A + """]); echo (int)db()->lastInsertId();""")
if not HAY_LIGA:   # sin ligas: la pantalla, conectar y facturar a mano se prueban con la escuela
    C0 = php("""q("INSERT INTO esc_cargos (liga_id, alumno_id, tipo, periodo, descripcion, monto, descuento, vence, estado) VALUES (?, ?, 'mensualidad', '2026-10', 'Mensualidad de octubre', 40, 0, '2026-10-05', 'pendiente')", [""" + ESC + ", " + A + """]); echo (int)db()->lastInsertId();""")
    R0 = php("echo cobrar_cargos(" + E + ", [" + C0 + "], 'efectivo', 'prueba');")
    chk(pj("FacturaTukan::enlace()->deOrigen(" + ESC + ", 'cobro', 'r" + R0 + "')") is None, 'sin conectar: el recibo no se factura')
    r = s.get(B + 'admin.php?p=tufactura')
    chk('value="conectar"' in r.text, 'encendido: formulario para conectar')
    r = s.post(B + 'admin.php?p=tufactura', data={'csrf': csrf(r.text), 'tf_accion': 'conectar', 'clave': 'tf_' + 'a7' * 20, 'url': SIM})
    chk('EMPRESA DE PRUEBA' in r.text and 'a7a7a7a7a7a7' not in r.text, 'conecta con TuFactura y la clave no sale al navegador')
    r = s.post(B + 'admin.php?p=tufactura', data={'csrf': csrf(r.text), 'tf_accion': 'facturar_origen', 'origen': 'cobro', 'origen_id': 'r' + R0})
    doc = pj("FacturaTukan::enlace()->deOrigen(" + ESC + ", 'cobro', 'r" + R0 + "')")
    chk(doc and doc['estado'] == 'autorizado', 'facturar a mano el recibo: autorizada')
    p = s.get(B + 'admin.php?p=tufactura&pdf=' + str(doc['id'] if doc else 0))
    chk(p.status_code == 200 and p.content[:4] == b'%PDF', 'baja el PDF de la factura')
else:
    php("FacturaTukan::enlace()->conectar(" + ESC + ", 'tf_' . str_repeat('a7', 20), '" + SIM + "');")
php("FacturaTukan::enlace()->opciones(" + ESC + ", true, true, 15);")
R = php("echo cobrar_cargos(" + E + ", [" + C + "], 'transferencia', 'prueba');")
dr = pj("FacturaTukan::enlace()->deOrigen(" + ESC + ", 'cobro', 'r" + R + "')")
chk(dr and dr['estado'] == 'autorizado', 'automático: el recibo sale facturado al cobrarlo')
env = json.loads(dr['envio']) if dr else {}
chk(env.get('cliente', {}).get('identificacion') == '1710034065', 'a nombre del representante (su cédula)')
chk(dr and abs(float(dr['total']) - 35) <= 0.011, 'recibo: total con el descuento (40 − 5 = 35)')
chk(any(c[0] == 'r' + R for c in pj("FacturaTukan::cobros(" + ESC + ")")), 'la lista de la escuela muestra el recibo')

print('== Apagar')
CA = LIGA if HAY_LIGA else ESC
php("q(\"UPDATE ligas SET modulos_extra = '' WHERE id = ?\", [" + CA + "]);")
if HAY_LIGA:
    M3 = php("echo registrar_pago(" + LIGA + ", null, " + EQ + ", 5, 'multa', 'Multa', 'efectivo', '', date('Y-m-d'), 'prueba');")
    chk(pj("FacturaTukan::enlace()->deOrigen(" + LIGA + ", 'cobro', 'm" + M3 + "')") is None, 'apagado: ya no factura solo')
    chk(len(pj("FacturaTukan::enlace()->lista(" + LIGA + ")")) == 3, 'apagado: las facturas hechas se quedan')
else:
    C3 = php("""q("INSERT INTO esc_cargos (liga_id, alumno_id, tipo, periodo, descripcion, monto, descuento, vence, estado) VALUES (?, ?, 'mensualidad', '2026-12', 'Mensualidad', 40, 0, '2026-12-05', 'pendiente')", [""" + ESC + ", " + A + """]); echo (int)db()->lastInsertId();""")
    R3 = php("echo cobrar_cargos(" + E + ", [" + C3 + "], 'efectivo', 'prueba');")
    chk(pj("FacturaTukan::enlace()->deOrigen(" + ESC + ", 'cobro', 'r" + R3 + "')") is None, 'apagado: ya no factura solo')
    chk(len(pj("FacturaTukan::enlace()->lista(" + ESC + ")")) == 2, 'apagado: las facturas hechas se quedan')

sim.terminate()
logs = [x for x in (os.path.join(REPO, 'pruebas', d, 'web.log') for d in ('tukan_central', 'v38')) if os.path.exists(x)]
log = max(logs, key=os.path.getmtime) if logs else ''
if log:
    malos = [l for l in open(log, encoding='utf-8', errors='replace') if ERRORES.search(l)]
    chk(not malos, 'sin errores de PHP en el servidor' + (': ' + malos[0][:300] if malos else ''))
print('\nTuFactura: %d bien, %d mal' % (ok, len(fallos)))
sys.exit(1 if fallos else 0)
