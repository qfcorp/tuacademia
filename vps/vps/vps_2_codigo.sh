#!/bin/bash
# TuAcademia - Instala o ACTUALIZA el programa en el VPS desde GitHub (repositorio qfcorp/tuacademia, rama main).
# No toca la base de datos ni las fotos. La primera vez crea la base vacia y pide el superadministrador.
# Uso: sudo bash vps_2_codigo.sh            (baja la ultima version de GitHub)
#      sudo bash vps_2_codigo.sh RAMA       (otra rama, por ejemplo una de pruebas)
DIR="$(cd "$(dirname "$0")" && pwd)"
. "$DIR/comun.sh"
[ "$(id -u)" = "0" ] || { rojo "Ejecute con sudo: sudo bash $0"; exit 1; }
RAMA="${1:-main}"
REPO_URL="${TUACADEMIA_REPO:-https://github.com/qfcorp/tuacademia.git}"
docker ps --format '{{.Names}}' | grep -qx "$CONTENEDOR" || { rojo "Primero haga el paso 1 (sudo bash vps_1_preparar.sh)."; exit 1; }
paso "1/4 Bajando el programa de GitHub (rama $RAMA)"
T="/tmp/tuacademia_codigo"; rm -rf "$T"
if ! git clone -q --depth 1 -b "$RAMA" "$REPO_URL" "$T" 2>/tmp/tuacademia_git.log; then
  rojo "No se pudo bajar de GitHub. Si el repositorio es privado, primero:  sudo gh auth login  (o use una llave de despliegue)."
  cat /tmp/tuacademia_git.log; exit 1
fi
[ -d "$T/app" ] && [ -d "$T/public" ] || { rojo "Lo que se bajo no tiene app/ y public/."; exit 1; }
VERSION="$(git -C "$T" log -1 --format='%h %cd' --date=short)"
verde "Version $VERSION"
paso "2/4 Copiando el programa (la base de datos y las fotos no se tocan)"
if [ -d "$DESTINO/app" ]; then
  tar -czf "$RESPALDOS/codigo_antes_$(date +%Y%m%d_%H%M%S).tar.gz" --exclude=public/uploads -C "$DESTINO" app public 2>/dev/null
  amarillo "Respaldo del programa anterior en $RESPALDOS"
fi
mkdir -p "$DESTINO/data/sesiones" "$DESTINO/public/uploads"
rm -rf "$DESTINO/app.nuevo"; cp -r "$T/app" "$DESTINO/app.nuevo"
rm -f "$DESTINO/app.nuevo/config.php"; [ -f "$DESTINO/app/config.php" ] && cp -a "$DESTINO/app/config.php" "$DESTINO/app.nuevo/"
rm -rf "$DESTINO/app.viejo"; [ -d "$DESTINO/app" ] && mv "$DESTINO/app" "$DESTINO/app.viejo"
mv "$DESTINO/app.nuevo" "$DESTINO/app"; rm -rf "$DESTINO/app.viejo"
( cd "$T/public" && find . -path ./uploads -prune -o -type f -print | while read -r f; do
    mkdir -p "$DESTINO/public/$(dirname "$f")"; cp "$f" "$DESTINO/public/$f"; done )
printf 'upload_max_filesize = 12M\npost_max_size = 14M\nmemory_limit = 256M\nmax_execution_time = 60\n' > "$DESTINO/public/.user.ini"
touch "$DESTINO/data/.servidor"   # marca: aqui NUNCA se corren las pruebas (reiniciar.sh borra la base)
# app/config.php del servidor (solo la primera vez). Google Play APAGADO hasta que se decida.
if [ ! -f "$DESTINO/app/config.php" ]; then
  cat > "$DESTINO/app/config.php" <<'PHP'
<?php
// TuAcademia en el VPS. Producto 'academia'. App de Google Play APAGADA: se enciende con 'google_play' => true cuando se decida
// (y 'huella_firma' => 'AA:BB:...' cuando exista la llave de firma nueva de Android).
return ['producto' => 'academia', 'google_play' => false];
PHP
  verde "app/config.php creado (Google Play apagado)."
fi
chown -R www-data:www-data "$DESTINO"
find "$DESTINO" -path "$DESTINO/data" -prune -o -type d -exec chmod 755 {} \;
find "$DESTINO" -path "$DESTINO/data" -prune -o -type f -exec chmod 644 {} \;
chmod 770 "$DESTINO/data"; find "$DESTINO/data" -mindepth 1 -type d -exec chmod 770 {} \; ; find "$DESTINO/data" -type f -exec chmod 660 {} \;
chmod 640 "$DESTINO/app/config.php"
chmod 775 "$DESTINO/public/uploads"
echo "$PUERTO_WEB" > "$DESTINO/data/puerto_nginx"; chown www-data:www-data "$DESTINO/data/puerto_nginx"
git -C "$T" log -1 --format=%h > "$DESTINO/app/VERSION" 2>/dev/null && chown www-data:www-data "$DESTINO/app/VERSION"  # version para el Panel Tukan
# Panel Tukan Central: el comando para conectar queda en $DESTINO/vps/conectar_central.sh (de root: PHP no lo puede cambiar)
if [ -f "$T/vps/conectar_central.sh" ]; then
  mkdir -p "$DESTINO/vps" && cp "$T/vps/conectar_central.sh" "$DESTINO/vps/conectar_central.sh" \
    && chown -R root:root "$DESTINO/vps" && chmod 755 "$DESTINO/vps" && chmod 644 "$DESTINO/vps/conectar_central.sh"
fi
# IA y correo de Tukan (21 oct 2026): sudo bash $DESTINO/vps/configurar_ia_correo.sh (el contenedor no ve /etc: lo guarda en data/)
if [ -f "$T/vps/configurar_ia_correo.sh" ]; then
  mkdir -p "$DESTINO/vps" && cp "$T/vps/configurar_ia_correo.sh" "$DESTINO/vps/configurar_ia_correo.sh" \
    && chown -R root:root "$DESTINO/vps" && chmod 755 "$DESTINO/vps" && chmod 644 "$DESTINO/vps/configurar_ia_correo.sh"
fi
docker exec "$CONTENEDOR" kill -USR2 1 >/dev/null 2>&1   # PHP toma el programa nuevo al instante
verde "Programa copiado."
paso "3/4 Base de datos"
NUEVA=0; [ -f "$DESTINO/data/ligas.sqlite" ] || NUEVA=1
R="$(php_ta -r 'require "'"$DESTINO"'/app/bootstrap.php"; db(); echo "ok ", db()->query("PRAGMA user_version")->fetchColumn();' 2>&1)"
echo "$R" | grep -q "^ok" && verde "Base de datos al dia (version ${R#ok })" || { rojo "Error con la base de datos:"; echo "$R"; exit 1; }
if [ "$NUEVA" = "1" ]; then
  amarillo "Base NUEVA: cree ahora el superadministrador (su correo y una clave de 8 o mas caracteres)."
  docker exec -it -u www-data "$CONTENEDOR" php "$DESTINO/app/crear_admin.php" < /dev/tty
fi
paso "4/4 Tareas automaticas y prueba"
cat > /etc/cron.d/tuacademia <<CRON
# TuAcademia en el VPS: avisos cada minuto y respaldo diario a la 1:40 (antes del respaldo general de las 2:00)
* * * * * root docker exec -u www-data $CONTENEDOR php $DESTINO/app/tareas.php >/dev/null 2>&1
40 1 * * * root docker exec -u www-data $CONTENEDOR php $DESTINO/app/respaldo.php $RESPALDOS >/dev/null 2>&1
CRON
chmod 644 /etc/cron.d/tuacademia
C="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $SITIO" http://127.0.0.1:$PUERTO_WEB/index.php)"
[ "$C" = "200" ] && verde "La pagina responde (200)." || amarillo "La pagina respondio $C. Revise: sudo tail -n 20 /var/log/nginx/tuacademia_error.log ; sudo docker logs --tail 20 $CONTENEDOR"
rm -rf "$T"
echo; verde "PROGRAMA INSTALADO ($VERSION). Abra https://$SITIO"
