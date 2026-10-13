#!/bin/bash
# TuAcademia - Carga en el VPS los DATOS que se sacaron de la laptop (base de datos, fotos, facturas).
#   Prueba:      sudo bash vps_3_datos.sh /home/ubuntu/tuacademia_datos_XXXX.tar.gz
#   Produccion:  sudo bash vps_3_datos.sh /home/ubuntu/tuacademia_datos_XXXX.tar.gz produccion
# En PRUEBA no se activan los avisos (Telegram, notificaciones) ni los respaldos, para no mandar
# mensajes repetidos a la gente: la laptop sigue siendo la oficial.
DIR="$(cd "$(dirname "$0")" && pwd)"
. "$DIR/comun.sh"
[ "$(id -u)" = "0" ] || { rojo "Ejecute con sudo."; exit 1; }
ARCH="$1"; MODO="${2:-prueba}"
[ -z "$ARCH" ] && ARCH="$(ls -t /home/*/tuacademia_datos_*.tar.gz 2>/dev/null | head -n1)"
[ -f "$ARCH" ] || { rojo "No encuentro el archivo de datos. Uso: sudo bash $0 /home/ubuntu/tuacademia_datos_XXXX.tar.gz"; exit 1; }
[ -d "$DESTINO/app" ] || { rojo "Primero instale el programa (paso vps_2_codigo.sh)."; exit 1; }
paso "1/4 Abriendo $ARCH"
T="/tmp/tuacademia_datos_carga"; rm -rf "$T"; mkdir -p "$T"
tar -xzf "$ARCH" -C "$T" || { rojo "El archivo esta danado."; exit 1; }
BASE="$(dirname "$(find "$T" -maxdepth 3 -type f -path '*/datos/ligas.sqlite' | head -n1)")"
BASE="$(dirname "$BASE")"
[ -f "$BASE/datos/ligas.sqlite" ] || { rojo "El archivo no trae datos/ligas.sqlite"; exit 1; }
[ -f "$BASE/resumen.txt" ] && { echo "Datos sacados de la laptop:"; cat "$BASE/resumen.txt"; }
paso "2/4 Guardando lo que habia antes en el VPS"
if [ -f "$DESTINO/data/ligas.sqlite" ]; then
  A="$RESPALDOS/datos_antes_$(date +%Y%m%d_%H%M%S).tar.gz"
  php_tv -r 'require "/var/www/ligas/app/bootstrap.php"; db()->exec("PRAGMA wal_checkpoint(TRUNCATE)");' >/dev/null 2>&1
  tar -czf "$A" --exclude=data/sesiones -C "$DESTINO" data public/uploads 2>/dev/null && amarillo "Respaldo de lo anterior: $A"
fi
paso "3/4 Copiando los datos"
docker stop "$CONTENEDOR" >/dev/null   # nadie escribe mientras se copia
rm -f "$DESTINO"/data/ligas.sqlite "$DESTINO"/data/ligas.sqlite-wal "$DESTINO"/data/ligas.sqlite-shm
find "$DESTINO/data/sesiones" -type f -delete 2>/dev/null
cp "$BASE/datos/ligas.sqlite" "$DESTINO/data/ligas.sqlite"
( cd "$BASE/datos" && for x in * .[!.]*; do [ -e "$x" ] || continue; case "$x" in ligas.sqlite|ligas.sqlite-*|sesiones|puerto_nginx) continue;; esac; rm -rf "$DESTINO/data/$x"; cp -a "$x" "$DESTINO/data/"; done )
if [ -d "$BASE/uploads" ]; then rm -rf "$DESTINO/public/uploads"; cp -a "$BASE/uploads" "$DESTINO/public/uploads"; fi
mkdir -p "$DESTINO/data/sesiones"
echo "$PUERTO_WEB" > "$DESTINO/data/puerto_nginx"
chown -R www-data:www-data "$DESTINO/data" "$DESTINO/public/uploads"
chmod 770 "$DESTINO/data"; find "$DESTINO/data" -mindepth 1 -type d -exec chmod 770 {} \; ; find "$DESTINO/data" -type f -exec chmod 660 {} \;
find "$DESTINO/public/uploads" -type d -exec chmod 775 {} \; ; find "$DESTINO/public/uploads" -type f -exec chmod 644 {} \;
docker start "$CONTENEDOR" >/dev/null; sleep 2
R="$(php_tv -r 'require "/var/www/ligas/app/bootstrap.php"; db(); echo "ok version ", db()->query("PRAGMA user_version")->fetchColumn(), " | organizaciones ", valor("SELECT COUNT(*) FROM ligas"), " | usuarios ", valor("SELECT COUNT(*) FROM usuarios"), " | campeonatos ", valor("SELECT COUNT(*) FROM torneos"), " | equipos ", valor("SELECT COUNT(*) FROM equipos"), " | alumnos ", valor("SELECT COUNT(*) FROM esc_alumnos");' 2>&1)"
echo "$R" | grep -q "^ok" && verde "Datos cargados: ${R#ok }" || { rojo "Error al abrir la base:"; echo "$R"; exit 1; }
paso "4/4 Tareas automaticas"
if [ "$MODO" = "produccion" ]; then
  cat > /etc/cron.d/tuacademia <<CRON
# TuAcademia en el VPS: avisos cada minuto y respaldo diario a la 1:30 (antes del respaldo general de las 2:00)
* * * * * root docker exec -u www-data $CONTENEDOR php $DESTINO/app/tareas.php >/dev/null 2>&1
30 1 * * * root docker exec -u www-data $CONTENEDOR php $DESTINO/app/respaldo.php $RESPALDOS >/dev/null 2>&1
CRON
  chmod 644 /etc/cron.d/tuacademia
  verde "MODO PRODUCCION: avisos cada minuto y respaldo diario (1:30 am) ACTIVADOS."
else
  rm -f /etc/cron.d/tuacademia
  amarillo "MODO PRUEBA: avisos y respaldos APAGADOS (la oficial sigue siendo la laptop)."
fi
C="$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: prueba-tuacademia.tukanec.com' http://127.0.0.1:$PUERTO_WEB/index.php)"
[ "$C" = "200" ] && verde "La pagina responde (200)." || amarillo "La pagina respondio $C"
rm -rf "$T"
echo; verde "DATOS CARGADOS ($MODO)."
