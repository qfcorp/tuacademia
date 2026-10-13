#!/bin/bash
# TuVocalia - EN LA LAPTOP, SOLO EL DIA DEL CAMBIO DEFINITIVO AL VPS:
# pone el aviso "Estamos mejorando el sistema" (nadie puede escribir), apaga avisos y respaldos de la laptop
# y saca la copia FINAL de los datos. Para deshacer: sudo bash laptop_3_reabrir.sh
verde() { echo -e "\e[32m$1\e[0m"; }; rojo() { echo -e "\e[31m$1\e[0m"; }; amarillo() { echo -e "\e[33m$1\e[0m"; }
[ "$(id -u)" = "0" ] || { rojo "Ejecute con sudo: sudo bash $0"; exit 1; }
DIR="$(cd "$(dirname "$0")" && pwd)"
SITIO=/etc/nginx/sites-available/ligas
[ -f "$SITIO" ] || { rojo "No encuentro $SITIO"; exit 1; }
PUERTO="$(cat /var/www/ligas/data/puerto_nginx 2>/dev/null || echo 80)"
[ -f "$SITIO.antes_vps" ] || cp "$SITIO" "$SITIO.antes_vps"
mkdir -p /var/www/ligas_mantenimiento
cat > /var/www/ligas_mantenimiento/mantenimiento.html <<'HTML'
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TuVocalía</title>
<style>body{font-family:system-ui,sans-serif;background:#0f5c45;color:#fff;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;text-align:center;padding:20px}h1{font-size:1.6rem}</style></head>
<body><div><h1>⚽ TuVocalía se está mudando a un servidor más rápido</h1><p>Volvemos en unos minutos. Gracias por su paciencia.</p></div></body></html>
HTML
cat > "$SITIO" <<NGX
# MANTENIMIENTO (mudanza al VPS). El original esta en $SITIO.antes_vps
server {
    listen $PUERTO;
    server_name tuvocalia.com www.tuvocalia.com;
    include /etc/nginx/ligas_dominios.conf;
    root /var/www/ligas_mantenimiento;
    error_page 503 /mantenimiento.html;
    location = /mantenimiento.html { internal; }
    location / { return 503; }
}
NGX
if nginx -t >/dev/null 2>&1; then systemctl reload nginx; verde "Aviso de mantenimiento puesto."; else cp "$SITIO.antes_vps" "$SITIO"; nginx -t; rojo "Error en nginx: se dejo todo como estaba."; exit 1; fi
mkdir -p /root/tuvocalia_cron_laptop
for c in /etc/cron.d/ligas-tareas /etc/cron.d/ligas-respaldo; do [ -f "$c" ] && mv "$c" /root/tuvocalia_cron_laptop/; done
verde "Avisos y respaldos de la laptop apagados."
sleep 3
bash "$DIR/laptop_1_sacar_datos.sh"
