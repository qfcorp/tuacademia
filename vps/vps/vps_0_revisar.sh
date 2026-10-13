#!/bin/bash
# TuVocalia - PASO 0 en el VPS: SOLO MIRA (no cambia nada). Uso: sudo bash vps_0_revisar.sh
. "$(cd "$(dirname "$0")" && pwd)/comun.sh"
[ "$(id -u)" = "0" ] || { rojo "Ejecute con sudo: sudo bash $0"; exit 1; }
paso "Servidor"
echo "Nombre: $(hostname)   Sistema: $(. /etc/os-release; echo $PRETTY_NAME)"
echo "Nucleos: $(nproc)   Carga (1/5/15 min): $(cut -d' ' -f1-3 /proc/loadavg)"
free -h | awk 'NR==1||NR==2'
df -h / | awk 'NR==2{print "Disco /: usado " $3 " de " $2 " (" $5 "), libre " $4}'
LIBRE_GB=$(df -BG / | awk 'NR==2{gsub("G","",$4); print $4}')
[ "${LIBRE_GB:-0}" -ge 5 ] && verde "Espacio en disco suficiente" || rojo "Hay menos de 5 GB libres: libere espacio antes de seguir"
paso "Docker"
if command -v docker >/dev/null && docker info >/dev/null 2>&1; then verde "Docker funcionando: $(docker version --format '{{.Server.Version}}')"; else rojo "Docker no responde"; fi
docker ps --format '  {{.Names}}  ({{.Status}})' 2>/dev/null
docker ps -a --format '{{.Names}}' | grep -qx "$CONTENEDOR" && amarillo "Ya existe el contenedor $CONTENEDOR (se reemplazara en el paso 1)"
paso "Puertos que va a usar TuVocalia (solo dentro del servidor)"
for p in $PUERTO_WEB $PUERTO_PHP; do
  if ss -ltnH "sport = :$p" | grep -q .; then
    D="$(ss -ltnpH "sport = :$p" | head -n1)"
    if echo "$D" | grep -q -e nginx -e docker-proxy && [ -d "$OPT" ]; then verde "Puerto $p: ya es de TuVocalia"; else rojo "Puerto $p OCUPADO por otro programa: $D"; fi
  else verde "Puerto $p libre"; fi
done
paso "nginx (el mismo del ERP)"
nginx -t >/dev/null 2>&1 && verde "Configuracion de nginx correcta" || rojo "nginx -t da error (arreglar antes de seguir)"
grep -q "include /etc/nginx/conf.d/\*.conf" /etc/nginx/nginx.conf && verde "nginx lee conf.d" || amarillo "nginx no lee /etc/nginx/conf.d (el limite de visitas se omitira)"
paso "PHP del ERP (no se toca)"
ls /run/php/ 2>/dev/null | grep -E 'fpm.sock$' | sed 's/^/  /'
paso "Tunel de Cloudflare en el VPS"
command -v cloudflared >/dev/null && echo "cloudflared: $(cloudflared --version 2>/dev/null | head -n1)" || rojo "cloudflared no esta instalado"
if systemctl is-active --quiet cloudflared; then verde "Ya hay un tunel corriendo como servicio (cloudflared)."; else amarillo "No hay tunel como servicio: se crea en el paso 2 de las instrucciones (panel de Cloudflare)."; fi
paso "Lo que mas consume ahora"
ps -eo pid,comm,%cpu,%mem --sort=-%mem | head -n 8
[ -d "$DESTINO/app" ] && amarillo "Ya existe $DESTINO (TuVocalia ya estaba copiado aqui)"
echo; verde "Revision terminada. Copie TODO lo que salio y envielo a Claude."
