#!/bin/bash
# ==========================================================
#  Activa el DOMINIO PROPIO de una academia (ej: academiaallegro.com)
#  Uso (en PuTTY):  sudo bash /var/www/tuacademia/app/dominio.sh academiaallegro.com
#
#  Antes:
#   1. En el panel: Academias > Editar la academia > "Dominio propio": escriba el dominio y guarde.
#   2. El dominio debe estar agregado en la cuenta de Cloudflare.
# ==========================================================

DESTINO="/var/www/tuacademia"
LISTA="/etc/nginx/tuacademia_dominios.conf"

verde() { echo -e "\e[32m$1\e[0m"; }
rojo()  { echo -e "\e[31m$1\e[0m"; }
amarillo() { echo -e "\e[33m$1\e[0m"; }

if [ "$(id -u)" != "0" ]; then rojo "Ejecute con sudo:  sudo bash $0 sudominio.com"; exit 1; fi
DOM="$(echo "$1" | tr 'A-Z' 'a-z' | sed -E 's#^https?://##; s#/.*$##; s#^www\.##')"
if ! echo "$DOM" | grep -Eq '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$'; then
  rojo "Escriba el dominio, por ejemplo:  sudo bash $0 academiaallegro.com"; exit 1
fi
PUERTO="$(cat "$DESTINO/data/puerto_nginx" 2>/dev/null || echo 80)"

# 1) nginx acepta el dominio
[ -f "$LISTA" ] || echo "# Dominios propios de las academias (TuAcademia)" > "$LISTA"
if grep -q "server_name $DOM " "$LISTA"; then
  verde "nginx ya tenia el dominio $DOM"
else
  cp "$LISTA" "$LISTA.respaldo"
  echo "server_name $DOM www.$DOM;" >> "$LISTA"
  if nginx -t >/tmp/tuacademia_dom.log 2>&1; then
    systemctl reload nginx
    verde "nginx: dominio $DOM agregado."
  else
    cp "$LISTA.respaldo" "$LISTA"
    rojo "nginx dio un error; se deshizo el cambio:"; cat /tmp/tuacademia_dom.log; exit 1
  fi
fi

# 2) cloudflared publica el dominio
CFG=""
for c in /etc/cloudflared/config.yml /etc/cloudflared/config.yaml /root/.cloudflared/config.yml /home/*/.cloudflared/config.yml /home/*/.cloudflared/config.yaml; do
  if [ -f "$c" ] && grep -q "ingress:" "$c"; then CFG="$c"; break; fi
done
if [ -n "$CFG" ]; then
  if grep -q "hostname: $DOM\$" "$CFG"; then
    verde "cloudflared ya tenia el dominio."
  else
    cp "$CFG" "$CFG.respaldo_$(date +%Y%m%d_%H%M%S)"
    python3 - "$CFG" "$DOM" "$PUERTO" <<'PY'
import sys, re
ruta, dom, puerto = sys.argv[1], sys.argv[2], sys.argv[3]
lineas = open(ruta).read().split('\n')
for i, l in enumerate(lineas):
    if re.match(r'\s*-\s*service:\s*http_status:404', l):
        s = re.match(r'(\s*)-', l).group(1)
        lineas[i:i] = [f"{s}- hostname: {dom}", f"{s}  service: http://127.0.0.1:{puerto}", f"{s}- hostname: www.{dom}", f"{s}  service: http://127.0.0.1:{puerto}"]
        break
open(ruta, 'w').write('\n'.join(lineas))
PY
    systemctl restart cloudflared 2>/dev/null
    verde "cloudflared: dominio agregado y reiniciado."
  fi
  TUNEL="$(grep -E '^\s*tunnel:' "$CFG" | head -n 1 | awk '{print $2}')"
  echo
  amarillo "FALTA UN PASO EN CLOUDFLARE (navegador), en la zona del dominio $DOM:"
  echo "  1. https://dash.cloudflare.com > $DOM > DNS > Records > Add record"
  echo "       Type: CNAME   Name: @     Target: ${TUNEL:-<ID-DEL-TUNEL>}.cfargotunnel.com   Proxy: activado (nube naranja)"
  echo "  2. Otro registro:  Type: CNAME   Name: www   Target: ${TUNEL:-<ID-DEL-TUNEL>}.cfargotunnel.com   Proxy: activado"
  echo "  (Si ya existen registros A o CNAME con esos nombres, edítelos o bórrelos primero.)"
else
  echo
  amarillo "FALTA UN PASO EN CLOUDFLARE (navegador):"
  echo "  1. https://one.dash.cloudflare.com > Networks > Tunnels > su tunel > Configure > Public Hostname > Add a public hostname"
  echo "       Subdomain: (vacio)   Domain: $DOM   Type: HTTP   URL: 127.0.0.1:$PUERTO   > Save"
  echo "  2. Repita con Subdomain: www   Domain: $DOM   Type: HTTP   URL: 127.0.0.1:$PUERTO"
fi
echo
verde "Listo. En unos minutos abra: https://$DOM"
