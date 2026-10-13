#!/bin/bash
# TuAcademia - Instala o ACTUALIZA el programa en el VPS desde un paquete tuacademia_vXX.tar.gz
# (en el VPS NO se usa instalar.sh: ese es para la laptop). No toca la base de datos ni las fotos.
# Uso: sudo bash vps_2_codigo.sh /home/ubuntu/tuacademia_v23.tar.gz
DIR="$(cd "$(dirname "$0")" && pwd)"
. "$DIR/comun.sh"
[ "$(id -u)" = "0" ] || { rojo "Ejecute con sudo: sudo bash $0 /home/ubuntu/tuacademia_vXX.tar.gz"; exit 1; }
PAQ="$1"
[ -z "$PAQ" ] && PAQ="$(ls -t /home/*/tuacademia_v*.tar.gz 2>/dev/null | head -n1)"
[ -f "$PAQ" ] || { rojo "No encuentro el paquete. Uso: sudo bash $0 /home/ubuntu/tuacademia_vXX.tar.gz"; exit 1; }
docker ps --format '{{.Names}}' | grep -qx "$CONTENEDOR" || { rojo "Primero haga el paso 1 (sudo bash vps_1_preparar.sh)."; exit 1; }
paso "1/3 Abriendo $PAQ"
T="/tmp/tuacademia_paquete"; rm -rf "$T"; mkdir -p "$T"
tar -xzf "$PAQ" -C "$T" || { rojo "El paquete esta danado."; exit 1; }
ORIGEN="$(dirname "$(find "$T" -maxdepth 3 -type d -name app -path '*/sistema/app' | head -n1)")"
[ -d "$ORIGEN/app" ] && [ -d "$ORIGEN/public" ] || { rojo "El paquete no tiene la carpeta sistema/app."; exit 1; }
VERSION="$(grep -o 'VERSION [0-9.]*' "$ORIGEN/../LEEME.txt" 2>/dev/null | head -n1)"
verde "Paquete correcto ${VERSION:+($VERSION)}"
paso "2/3 Copiando el programa (la base de datos y las fotos no se tocan)"
if [ -d "$DESTINO/app" ]; then
  tar -czf "$RESPALDOS/codigo_antes_$(date +%Y%m%d_%H%M%S).tar.gz" --exclude=public/uploads -C "$DESTINO" app public 2>/dev/null
  amarillo "Respaldo del programa anterior en $RESPALDOS"
fi
mkdir -p "$DESTINO/data/sesiones" "$DESTINO/public/uploads"
rm -rf "$DESTINO/app.nuevo"; cp -r "$ORIGEN/app" "$DESTINO/app.nuevo"
rm -f "$DESTINO/app.nuevo/config.php"; [ -f "$DESTINO/app/config.php" ] && cp -a "$DESTINO/app/config.php" "$DESTINO/app.nuevo/"
rm -rf "$DESTINO/app.viejo"; [ -d "$DESTINO/app" ] && mv "$DESTINO/app" "$DESTINO/app.viejo"
mv "$DESTINO/app.nuevo" "$DESTINO/app"; rm -rf "$DESTINO/app.viejo"
( cd "$ORIGEN/public" && find . -path ./uploads -prune -o -type f -print | while read -r f; do
    mkdir -p "$DESTINO/public/$(dirname "$f")"; cp "$f" "$DESTINO/public/$f"; done )
printf 'upload_max_filesize = 12M\npost_max_size = 14M\nmemory_limit = 256M\nmax_execution_time = 60\n' > "$DESTINO/public/.user.ini"
chown -R www-data:www-data "$DESTINO"
find "$DESTINO" -path "$DESTINO/data" -prune -o -type d -exec chmod 755 {} \;
find "$DESTINO" -path "$DESTINO/data" -prune -o -type f -exec chmod 644 {} \;
chmod 770 "$DESTINO/data"; find "$DESTINO/data" -mindepth 1 -type d -exec chmod 770 {} \; ; find "$DESTINO/data" -type f -exec chmod 660 {} \;
chmod 775 "$DESTINO/public/uploads"
echo "$PUERTO_WEB" > "$DESTINO/data/puerto_nginx"; chown www-data:www-data "$DESTINO/data/puerto_nginx"
docker exec "$CONTENEDOR" kill -USR2 1 >/dev/null 2>&1   # PHP toma el programa nuevo al instante
verde "Programa copiado."
# (v38) Apps de Android: /.well-known/assetlinks.json lo responde assetlinks.php (una sola vez; si nginx no lo acepta, se deja como estaba)
NG=/etc/nginx/sites-available/tuacademia
if [ -f "$NG" ] && ! grep -q "assetlinks" "$NG"; then
  cp -a "$NG" /root/tuacademia_nginx_antes_v38
  perl -0pi -e 's{\n([ \t]*)location ~ /\\\. \{ deny all; \}}{\n$1location = /.well-known/assetlinks.json { rewrite ^ /assetlinks.php last; } # (v38) apps de Android\n$1location ~ /\\. { deny all; }}' "$NG"
  if grep -q "assetlinks" "$NG" && nginx -t >/tmp/tuacademia_nginx.log 2>&1; then
    systemctl reload nginx && verde "nginx: /.well-known/assetlinks.json listo (apps de Android)."
  else
    cp -a /root/tuacademia_nginx_antes_v38 "$NG"; nginx -t >/dev/null 2>&1 && systemctl reload nginx
    amarillo "No se pudo agregar /.well-known/assetlinks.json a nginx (se dejó como estaba). Envie a Claude: cat /tmp/tuacademia_nginx.log"
  fi
fi
paso "3/3 Base de datos y prueba"
R="$(php_tv -r 'require "/var/www/ligas/app/bootstrap.php"; db(); echo "ok ", db()->query("PRAGMA user_version")->fetchColumn();' 2>&1)"
echo "$R" | grep -q "^ok" && verde "Base de datos al dia (version ${R#ok })" || { rojo "Error con la base de datos:"; echo "$R"; exit 1; }
C="$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: prueba-tuacademia.tukanec.com' http://127.0.0.1:$PUERTO_WEB/index.php)"
[ "$C" = "200" ] && verde "La pagina responde (200)." || amarillo "La pagina respondio $C. Revise: sudo tail -n 20 /var/log/nginx/tuacademia_error.log ; sudo docker logs --tail 20 $CONTENEDOR"
rm -rf "$T"
echo; verde "PROGRAMA INSTALADO ${VERSION:+($VERSION)}."
