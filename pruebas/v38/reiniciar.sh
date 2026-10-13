#!/bin/bash
# Deja el entorno de pruebas limpio: base nueva, súper, liga y escuela de prueba, simulador de Google vacío.
# Uso: copiar esta carpeta a /tmp/claude-0/pruebas38 y correr: bash reiniciar.sh && python3 prueba38.py && python3 navegador38.py
# Necesita app/config.php con: <?php return ['play_api' => 'http://127.0.0.1:8299'];  (¡solo en pruebas, nunca en el servidor!)
P=/tmp/claude-0/pruebas38
if [ ! -f $P/cuenta_servicio.json ]; then
  openssl genrsa -out $P/cuenta_privada.pem 2048 2>/dev/null && openssl rsa -in $P/cuenta_privada.pem -pubout -out $P/cuenta_publica.pem 2>/dev/null
  php -r '$k=file_get_contents("'$P'/cuenta_privada.pem"); echo json_encode(["type"=>"service_account","private_key"=>$k,"client_email"=>"tuvocalia-play@tuvocalia-prueba.iam.gserviceaccount.com","token_uri"=>"http://127.0.0.1:8299/token"], JSON_UNESCAPED_SLASHES);' > $P/cuenta_servicio.json
fi
cd /home/claude/tuvocalia
pkill -f "php -S 127.0.0.1:8[1]99" ; pkill -f "php -S 127.0.0.1:8[2]99"; sleep 0.5
rm -rf data && mkdir -p data
rm -f /tmp/claude-0/pruebas38/sim_estado.json /tmp/claude-0/pruebas38/web.log /tmp/claude-0/pruebas38/sim.log
printf 'Super Prueba\nsuper@prueba.com\nclave12345\n' | php app/crear_admin.php >/dev/null
php -r '
require "app/bootstrap.php";
crear_liga_completa(["nombre"=>"Liga Prueba Play","modalidad"=>"F7","admin_nombre"=>"Admin Liga","admin_email"=>"liga@prueba.com","admin_clave"=>"clave12345","plan_id"=>1,"vence"=>date("Y-m-d", strtotime("+3 days")),"deportes"=>["futbol"],"crear_campeonato"=>true]);
crear_liga_completa(["nombre"=>"Escuela Prueba Play","modalidad"=>"F7","admin_nombre"=>"Admin Escuela","admin_email"=>"escuela@prueba.com","admin_clave"=>"clave12345","tipo"=>"escuela","plan_id"=>5,"vence"=>date("Y-m-d", strtotime("+3 days")),"deportes"=>["futbol"]]);'
(cd /tmp/claude-0/pruebas38 && nohup php -S 127.0.0.1:8299 simulador_google.php > sim.log 2>&1 &)
(nohup php -S 127.0.0.1:8199 -t public > /tmp/claude-0/pruebas38/web.log 2>&1 &)
sleep 1; echo "entorno listo"
