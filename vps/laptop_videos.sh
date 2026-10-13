#!/bin/bash
# =====================================================================
#  TuVocalía (v28) — RECEPTOR DE VIDEOS EN LA LAPTOP qfcorp
#  Guarda los videos de jugadores y alumnos en el disco grande de la laptop
#  (/mnt/transferencias/tuvocalia-videos) en vez del VPS.
#  Uso (en la laptop):  sudo bash laptop_videos.sh
# =====================================================================
SITIO="/var/www/tuvocalia-videos"
PUERTO="8096"
RECEPTOR_B64="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"
verde(){ echo -e "\e[32m$*\e[0m"; }
amarillo(){ echo -e "\e[33m$*\e[0m"; }
rojo(){ echo -e "\e[31m$*\e[0m"; }
if [ "$(id -u)" != "0" ]; then rojo "Ejecute con sudo: sudo bash laptop_videos.sh"; exit 1; fi

if [ -d /mnt/transferencias ]; then DATOS="/mnt/transferencias/tuvocalia-videos"; else DATOS="$SITIO/archivos"; fi

verde "[1/6] Programas (php-fpm y ffprobe para medir la duracion)..."
apt-get install -y -qq ffmpeg >/dev/null 2>&1 || amarillo "      No se pudo instalar ffmpeg: no se controlara la duracion (los videos igual funcionan)."
SOCK=$(ls /run/php/php*-fpm.sock 2>/dev/null | sort -V | tail -1)
if [ -z "$SOCK" ]; then
  apt-get install -y -qq php-fpm >/dev/null 2>&1
  SOCK=$(ls /run/php/php*-fpm.sock 2>/dev/null | sort -V | tail -1)
fi
if [ -z "$SOCK" ]; then rojo "No encuentro PHP-FPM en la laptop."; exit 1; fi
verde "      PHP: $SOCK"

verde "[2/6] Carpetas..."
mkdir -p "$SITIO" "$DATOS/tmp"
echo "$RECEPTOR_B64" | base64 -d | gunzip > "$SITIO/videos_receptor.php"
if ! php -l "$SITIO/videos_receptor.php" >/dev/null 2>&1; then rojo "El receptor se copio mal. Vuelva a pegar el instalador completo."; exit 1; fi
if [ -f "$SITIO/config.php" ]; then
  CLAVE=$(php -r '$c = require "'"$SITIO"'/config.php"; echo $c["clave"];')
else
  CLAVE=$(openssl rand -hex 32)
fi
cat > "$SITIO/config.php" << CFG
<?php
return ['dir' => '$DATOS', 'clave' => '$CLAVE', 'max_mb' => 400, 'max_seg' => 120, 'dias' => 10];
CFG
cat > "$SITIO/index.php" << 'IDX'
<?php
// Receptor de videos de TuVocalía en la laptop (todo va firmado con la clave de config.php)
require __DIR__ . '/videos_receptor.php';
$cfg = require __DIR__ . '/config.php';
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') === 'limpiar') echo vr_limpiar($cfg['dir'], $cfg['dias'] + 1) . " videos borrados\n";
    exit;
}
@set_time_limit(300);
vr_atender($cfg);
IDX
chown -R www-data:www-data "$SITIO" "$DATOS"
chmod 640 "$SITIO/config.php"
verde "      Videos en: $DATOS ($(df -h "$DATOS" | awk 'NR==2{print $4}') libres)"

verde "[3/6] Sitio en nginx (solo dentro de la laptop, puerto $PUERTO)..."
cat > /etc/nginx/sites-available/tuvocalia-videos << NGX
server {
    listen 127.0.0.1:$PUERTO;
    server_name _;
    root $SITIO;
    client_max_body_size 20m;
    location = / { rewrite ^ /index.php last; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root/index.php;
        fastcgi_param PHP_VALUE "post_max_size=20M
upload_max_filesize=20M
memory_limit=128M
max_execution_time=300";
        fastcgi_pass unix:$SOCK;
        fastcgi_read_timeout 300;
        fastcgi_request_buffering off;
    }
    location / { return 404; }
}
NGX
ln -sf /etc/nginx/sites-available/tuvocalia-videos /etc/nginx/sites-enabled/tuvocalia-videos
if ! nginx -t >/dev/null 2>&1; then
  rm -f /etc/nginx/sites-enabled/tuvocalia-videos
  rojo "nginx dio error; quite el sitio para no afectar a los demas:"; nginx -t; exit 1
fi
systemctl reload nginx

verde "[4/6] Limpieza diaria (borra los videos de mas de 10 dias)..."
echo "15 4 * * * www-data php $SITIO/index.php limpiar >/dev/null 2>&1" > /etc/cron.d/tuvocalia-videos
chmod 644 /etc/cron.d/tuvocalia-videos

verde "[5/6] Prueba local..."
sleep 1
R=$(curl -s "http://127.0.0.1:$PUERTO/index.php")
if echo "$R" | grep -q '"ok":true'; then verde "      Responde: $R"; else rojo "      No responde bien: $R"; exit 1; fi

verde "[6/6] Listo en la laptop."
echo
amarillo "================= FALTAN 2 PASOS EN EL NAVEGADOR ================="
amarillo " A) Cloudflare: https://one.dash.cloudflare.com -> Networks -> Tunnels"
amarillo "    -> tunel de la laptop (servidorqf) -> Configure -> Public Hostname"
amarillo "    -> Add a public hostname:"
amarillo "       Subdomain: videos    Domain: tuvocalia.com"
amarillo "       Type: HTTP           URL: 127.0.0.1:$PUERTO      -> Save"
echo
amarillo " B) TuVocalia (superadministrador): Ajustes -> Videos"
amarillo "       Direccion del receptor:  https://videos.tuvocalia.com"
amarillo "       Clave del receptor:      $CLAVE"
amarillo "    -> Guardar -> Probar la conexion"
amarillo "=================================================================="
