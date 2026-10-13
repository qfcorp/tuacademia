#!/bin/bash
# TuVocalia - EN LA LAPTOP qfcorp: saca una COPIA de los datos (base de datos, fotos, facturas) sin detener nada.
# Uso: sudo bash laptop_1_sacar_datos.sh       -> deja /home/qf/tuvocalia_datos_FECHA.tar.gz
verde() { echo -e "\e[32m$1\e[0m"; }; rojo() { echo -e "\e[31m$1\e[0m"; }; amarillo() { echo -e "\e[33m$1\e[0m"; }
[ "$(id -u)" = "0" ] || { rojo "Ejecute con sudo: sudo bash $0"; exit 1; }
SRC="${TUVOCALIA_ORIGEN:-/var/www/ligas}"
DUENO="${SUDO_USER:-qf}"; CASA="$(getent passwd "$DUENO" | cut -d: -f6)"; CASA="${CASA:-/home/qf}"
[ -f "$SRC/data/ligas.sqlite" ] || { rojo "No encuentro $SRC/data/ligas.sqlite"; exit 1; }
F="$(date +%Y%m%d_%H%M)"; N="tuvocalia_datos_$F"; T="/tmp/$N"
rm -rf "$T"; mkdir -p "$T/datos"
# Copia segura de la base aunque el sistema este en uso
if command -v sqlite3 >/dev/null; then sqlite3 "$SRC/data/ligas.sqlite" ".backup '$T/datos/ligas.sqlite'"; else php -r '$p=new PDO("sqlite:'"$SRC"'/data/ligas.sqlite"); $p->exec("VACUUM INTO '"'$T/datos/ligas.sqlite'"'");'; fi
[ -s "$T/datos/ligas.sqlite" ] || { rojo "No se pudo copiar la base de datos."; exit 1; }
( cd "$SRC/data" && for x in * .[!.]*; do [ -e "$x" ] || continue; case "$x" in ligas.sqlite|ligas.sqlite-*|sesiones|puerto_nginx|*.sqlite.bak*) continue;; esac; cp -a "$x" "$T/datos/"; done )
[ -d "$SRC/public/uploads" ] && cp -a "$SRC/public/uploads" "$T/uploads"
[ -f "$SRC/app/config.php" ] && cp -a "$SRC/app/config.php" "$T/config.php"
[ -f /etc/nginx/ligas_dominios.conf ] && cp /etc/nginx/ligas_dominios.conf "$T/"
{ echo "Sacado de $(hostname) el $(date '+%d/%m/%Y %H:%M')"
  sqlite3 "$T/datos/ligas.sqlite" "SELECT 'Version de la base ' || (SELECT user_version FROM pragma_user_version) || ' | organizaciones ' || (SELECT COUNT(*) FROM ligas) || ' | usuarios ' || (SELECT COUNT(*) FROM usuarios) || ' | campeonatos ' || (SELECT COUNT(*) FROM torneos) || ' | equipos ' || (SELECT COUNT(*) FROM equipos);" 2>/dev/null
  echo "Fotos y archivos: $(find "$T/uploads" -type f 2>/dev/null | wc -l)"; } > "$T/resumen.txt"
tar -czf "$CASA/$N.tar.gz" -C /tmp "$N" && chown "$DUENO":"$DUENO" "$CASA/$N.tar.gz"
rm -rf "$T"
verde "LISTO: $CASA/$N.tar.gz ($(du -h "$CASA/$N.tar.gz" | cut -f1))"
tar -xzOf "$CASA/$N.tar.gz" "$N/resumen.txt" 2>/dev/null
echo; amarillo "Ahora paselo a su computadora y de ahi al VPS (vea las instrucciones, paso 4)."
echo "Nombre del archivo: $N.tar.gz"
