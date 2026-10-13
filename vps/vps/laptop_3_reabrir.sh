#!/bin/bash
# TuAcademia - EN LA LAPTOP: deshace laptop_2_cerrar.sh (vuelve a abrir el sistema en la laptop).
verde() { echo -e "\e[32m$1\e[0m"; }; rojo() { echo -e "\e[31m$1\e[0m"; }
[ "$(id -u)" = "0" ] || { rojo "Ejecute con sudo: sudo bash $0"; exit 1; }
SITIO=/etc/nginx/sites-available/ligas
[ -f "$SITIO.antes_vps" ] || { rojo "No hay nada que deshacer ($SITIO.antes_vps no existe)."; exit 1; }
cp "$SITIO.antes_vps" "$SITIO"
nginx -t >/dev/null 2>&1 && systemctl reload nginx && verde "La laptop vuelve a atender TuAcademia."
for c in /root/tuacademia_cron_laptop/*; do [ -f "$c" ] && mv "$c" /etc/cron.d/; done
verde "Avisos y respaldos de la laptop encendidos otra vez."
