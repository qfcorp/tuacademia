#!/bin/bash
# Deja el entorno de pruebas limpio: base nueva, súper, liga y escuela de prueba, simulador de Google vacío.
# Uso (desde cualquier carpeta):  bash pruebas/v38/reiniciar.sh && python3 pruebas/v38/prueba38.py && python3 pruebas/v38/navegador38.py
# Puertos: TA_PUERTO (web, por defecto 8196) y TA_SIM (simulador de Google, por defecto 8297).
# Modo: TA_PRODUCTO=academia (por defecto: TuAcademia, Google Play apagado) o TA_PRODUCTO=ligas (todo como TuVocalía, Google Play encendido,
#       para que prueba38.py / navegador38.py revisen que la base de TuVocalía sigue funcionando).
# Escribe app/config.php SOLO PARA PRUEBAS (play_api al simulador). ¡Nunca correr esto en el servidor! (si existe data/.servidor, no hace nada)
set -e
P="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$P/../.." && pwd)"
PUERTO="${TA_PUERTO:-8196}"
SIM="${TA_SIM:-8297}"
if [ -f "$REPO/data/.servidor" ]; then echo "ESTO ES UN SERVIDOR: no se reinicia la base"; exit 1; fi
if [ ! -f "$P/cuenta_servicio.json" ]; then
  openssl genrsa -out "$P/cuenta_privada.pem" 2048 2>/dev/null && openssl rsa -in "$P/cuenta_privada.pem" -pubout -out "$P/cuenta_publica.pem" 2>/dev/null
  php -r '$k=file_get_contents($argv[1]); echo json_encode(["type"=>"service_account","private_key"=>$k,"client_email"=>"tuacademia-play@tuacademia-prueba.iam.gserviceaccount.com","token_uri"=>"http://127.0.0.1:".$argv[2]."/token"], JSON_UNESCAPED_SLASHES);' "$P/cuenta_privada.pem" "$SIM" > "$P/cuenta_servicio.json"
fi
MODO="${TA_PRODUCTO:-academia}"
if [ -f "$REPO/app/config.php" ] && ! grep -q "SOLO PRUEBAS" "$REPO/app/config.php"; then echo "app/config.php no es de pruebas: no lo toco"; exit 1; fi
if [ "$MODO" = "ligas" ]; then EXTRA="'producto' => 'ligas', 'google_play' => true, 'huella_firma' => '11:22:33:44:55:66:77:88:99:00:AA:BB:CC:DD:EE:FF:11:22:33:44:55:66:77:88:99:00:AA:BB:CC:DD:EE:FF',"; else EXTRA="'producto' => 'academia',"; fi
printf "<?php\n// SOLO PRUEBAS (lo creó pruebas/v38/reiniciar.sh). En el servidor este archivo NO debe tener play_api.\nreturn [%s 'play_api' => 'http://127.0.0.1:%s', 'payphone_api' => 'http://127.0.0.1:%s'];\n" "$EXTRA" "$SIM" "$SIM" > "$REPO/app/config.php"
cd "$REPO"
pkill -f "php -S 127.0.0.1:$PUERTO" || true; pkill -f "php -S 127.0.0.1:$SIM" || true; sleep 0.5
rm -rf data && mkdir -p data
rm -f "$P/sim_estado.json" "$P/web.log" "$P/sim.log"
# APK de mentira solo para probar la descarga en modo ligas (public/descargas/*.apk no va a GitHub)
rm -f public/descargas/tuacademia.apk
if [ "$MODO" = "ligas" ]; then mkdir -p public/descargas && (cd "$P" && rm -f apk_prueba.zip && echo prueba > apk_prueba.txt && zip -q apk_prueba.zip apk_prueba.txt && mv apk_prueba.zip "$REPO/public/descargas/tuacademia.apk" && rm -f apk_prueba.txt); fi
printf 'Super Prueba\nsuper@prueba.com\nclave12345\n' | php app/crear_admin.php >/dev/null
php -r '
require "app/bootstrap.php";
crear_liga_completa(["nombre"=>"Liga Prueba Play","modalidad"=>"F7","admin_nombre"=>"Admin Liga","admin_email"=>"liga@prueba.com","admin_clave"=>"clave12345","tipo"=>"liga","plan_id"=>1,"vence"=>date("Y-m-d", strtotime("+3 days")),"deportes"=>["futbol"],"crear_campeonato"=>true]);
crear_liga_completa(["nombre"=>"Escuela Prueba Play","modalidad"=>"F7","admin_nombre"=>"Admin Escuela","admin_email"=>"escuela@prueba.com","admin_clave"=>"clave12345","tipo"=>"escuela","plan_id"=>5,"vence"=>date("Y-m-d", strtotime("+3 days")),"deportes"=>["futbol"]]);'
(cd "$P" && nohup php -S 127.0.0.1:$SIM simulador_google.php > sim.log 2>&1 &)
(nohup php -S 127.0.0.1:$PUERTO -t public > "$P/web.log" 2>&1 &)
sleep 1; echo "entorno listo ($MODO) en http://127.0.0.1:$PUERTO/"
