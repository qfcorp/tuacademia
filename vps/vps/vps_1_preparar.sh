#!/bin/bash
# TuAcademia - PASO 1 en el VPS: PHP 8.5 aislado en Docker (con limites) + sitio de nginx solo interno (127.0.0.1:8096).
# No toca el ERP, su PHP 8.3, TuVocalia (8095), ni los puertos 80/443. Uso: sudo bash vps_1_preparar.sh
DIR="$(cd "$(dirname "$0")" && pwd)"
. "$DIR/comun.sh"
[ "$(id -u)" = "0" ] || { rojo "Ejecute con sudo: sudo bash $0"; exit 1; }

paso "1/5 Carpetas"
mkdir -p "$DESTINO/data/sesiones" "$DESTINO/public/uploads" "$RESPALDOS" "$OPT"
chown -R www-data:www-data "$DESTINO" "$RESPALDOS"
chmod 770 "$DESTINO/data" "$RESPALDOS"
verde "$DESTINO y $RESPALDOS listos (los dos entran solos en el respaldo nocturno del VPS, que copia /var/www)."

paso "2/5 Imagen de PHP 8.5 (tarda 1 a 3 minutos; usa UN solo nucleo para no molestar al ERP)"
cp "$DIR"/docker/* "$OPT"/
if docker image inspect "$IMAGEN" >/dev/null 2>&1 && [ "$1" != "--rehacer" ]; then
  verde "La imagen $IMAGEN ya existe (para rehacerla: sudo bash $0 --rehacer)"
else
  if ! DOCKER_BUILDKIT=0 docker build --cpuset-cpus=0 --cpu-shares=128 --memory=1g -t "$IMAGEN" "$OPT" >/tmp/tuacademia_build.log 2>&1; then
    amarillo "Reintentando con el constructor normal de Docker..."
    docker build -t "$IMAGEN" "$OPT" >>/tmp/tuacademia_build.log 2>&1 || { rojo "No se pudo crear la imagen. Envie a Claude: tail -n 30 /tmp/tuacademia_build.log"; exit 1; }
  fi
  verde "Imagen creada."
fi
FALTA="$(docker run --rm "$IMAGEN" php -r 'foreach (["pdo_sqlite","gd","zip","mbstring","curl","openssl"] as $e) if (!extension_loaded($e)) echo $e," "; if (!function_exists("imagettftext")) echo "freetype";')"
[ -z "$FALTA" ] && verde "PHP $(docker run --rm "$IMAGEN" php -r 'echo PHP_VERSION;') con todo lo necesario." || { rojo "Faltan extensiones: $FALTA"; exit 1; }

paso "3/5 Contenedor con limites (maximo $LIM_CPUS nucleos, $LIM_RAM de RAM, prioridad baja)"
docker rm -f "$CONTENEDOR" >/dev/null 2>&1
docker run -d --name "$CONTENEDOR" --restart unless-stopped \
  --cpus="$LIM_CPUS" --cpu-shares="$LIM_PRIORIDAD" --memory="$LIM_RAM" --memory-swap="$LIM_RAM" --pids-limit=200 \
  -p 127.0.0.1:$PUERTO_PHP:9000 \
  -v "$DESTINO":"$DESTINO" -v "$RESPALDOS":"$RESPALDOS" \
  -e TZ=America/Guayaquil --log-opt max-size=10m --log-opt max-file=3 \
  "$IMAGEN" >/dev/null || { rojo "No se pudo iniciar el contenedor."; exit 1; }
sleep 3
docker ps --filter "name=^${CONTENEDOR}$" --format '{{.Status}}' | grep -q Up && verde "Contenedor $CONTENEDOR funcionando." || { rojo "El contenedor no arranco:"; docker logs --tail 20 "$CONTENEDOR"; exit 1; }

paso "4/5 nginx: sitio de TuAcademia SOLO en 127.0.0.1:$PUERTO_WEB (el ERP no se toca)"
cp -a /etc/nginx /root/respaldo_nginx_antes_tuacademia_$(date +%Y%m%d_%H%M%S) 2>/dev/null
if grep -q "include /etc/nginx/conf.d/\*.conf" /etc/nginx/nginx.conf; then
  cat > /etc/nginx/conf.d/tuacademia_limite.conf <<'NGX'
# TuAcademia: maximo 20 paginas por segundo por visitante (protege al ERP de abusos)
limit_req_zone $http_cf_connecting_ip zone=tuacademia:10m rate=20r/s;
NGX
  LIMITE="limit_req zone=tuacademia burst=60 nodelay;"
else
  LIMITE=""
fi
cat > /etc/nginx/sites-available/tuacademia <<NGX
# TuAcademia (academias) - solo dentro del servidor; lo publica el tunel de Cloudflare
server {
    listen 127.0.0.1:$PUERTO_WEB default_server;
    server_name _;
    root $DESTINO/public;
    index index.php;
    client_max_body_size 15M;
    access_log /var/log/nginx/tuacademia_access.log;
    error_log  /var/log/nginx/tuacademia_error.log;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ^~ /uploads/ {
        location ~ \.php\$ { deny all; }
        expires 30d;
    }
    location = /.well-known/assetlinks.json { rewrite ^ /assetlinks.php last; } # apps de Android
    location ~ /\. { deny all; }
    location = /sw.js { add_header Cache-Control "no-cache"; }
    location ~ \.php\$ {
        $LIMITE
        include snippets/fastcgi-php.conf;
        fastcgi_pass 127.0.0.1:$PUERTO_PHP;
        fastcgi_read_timeout 120s;
    }
}
NGX
ln -sf /etc/nginx/sites-available/tuacademia /etc/nginx/sites-enabled/tuacademia
if nginx -t >/tmp/tuacademia_nginx.log 2>&1; then
  systemctl reload nginx && verde "nginx recargado (sin cortar al ERP)."
else
  rojo "nginx dio un error: se quita el sitio nuevo para no afectar al ERP:"; cat /tmp/tuacademia_nginx.log
  rm -f /etc/nginx/sites-enabled/tuacademia /etc/nginx/conf.d/tuacademia_limite.conf
  exit 1
fi
echo "$PUERTO_WEB" > "$DESTINO/data/puerto_nginx"; chown www-data:www-data "$DESTINO/data/puerto_nginx"

paso "5/5 Prueba"
echo '<?php echo "TUACADEMIA-OK PHP " . PHP_VERSION;' > "$DESTINO/public/zz_prueba_vps.php"; chown www-data "$DESTINO/public/zz_prueba_vps.php"
R="$(curl -s -H "Host: $SITIO" http://127.0.0.1:$PUERTO_WEB/zz_prueba_vps.php)"
rm -f "$DESTINO/public/zz_prueba_vps.php"
if echo "$R" | grep -q TUACADEMIA-OK; then verde "Funciona: $R"; else rojo "La prueba fallo: $R"; echo "Revise: sudo tail -n 20 /var/log/nginx/tuacademia_error.log"; exit 1; fi
ERP="$(curl -s -o /dev/null -w '%{http_code}' --resolve erp.tupassec.com:443:127.0.0.1 https://erp.tupassec.com/ -k)"
echo "El ERP sigue respondiendo (codigo $ERP; 200 o 302 es normal)."
echo; verde "PASO 1 TERMINADO. Siga con el paso 2 de las instrucciones."
