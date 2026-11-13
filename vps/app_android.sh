#!/bin/bash
# =====================================================================
#  TuAcademia - APP DE ANDROID (Google Play, paquete com.tukansoftware.tuacademia)   (13 nov 2026)
#  Deja todo listo para que la app abra el sitio sin la barra del navegador:
#    1) enciende Google Play en app/config.php ('google_play' => true; lo demas del archivo no se toca)
#    2) pone el paquete com.tukansoftware.tuacademia (ajuste play_paquete). La huella de la llave de subida de Tukan
#       ya va en el codigo (app/play.php, PLAY_HUELLA_SUBIDA)
#    3) si se le pasa la huella SHA-256 de "Firma de apps" de Google, la agrega (ajuste play_huellas, el mismo cuadro de
#       Superadministrador -> Google Play), sin borrar las que ya estaban
#    4) revisa la regla de nginx de /.well-known/assetlinks.json (la agrega si falta) y prueba con curl que responde
#       con el paquete y las huellas
#  Se puede repetir las veces que haga falta. vps_2_codigo.sh lo deja en /var/www/tuacademia/vps/.
#
#  Uso (en el VPS del ERP):
#    sudo bash /var/www/tuacademia/vps/app_android.sh            Google Play + paquete + nginx + prueba
#    sudo bash /var/www/tuacademia/vps/app_android.sh HUELLA     ademas agrega la huella de Google (32 pares como 39:0C:08:...)
#  Mensajes sin tildes a proposito (se leen bien por SSH desde el celular).
# =====================================================================
DESTINO="${AA_DESTINO:-/var/www/tuacademia}"
NG="${AA_NGINX:-/etc/nginx/sites-available/tuacademia}"
SITIO="${AA_SITIO:-tuacademia.tukanec.com}"
CONTENEDOR="${AA_CONTENEDOR:-tuacademia-php}"
PAQUETE="com.tukansoftware.tuacademia"
HUELLA_TUKAN="39:0C:08:10:D4:40:C1:71:C4:30:C8:D0:F1:93:7B:8F:E4:37:03:25:27:2E:B2:F6:2E:4E:A6:5E:01:98:40:DC"
verde()    { echo -e "\e[32m$1\e[0m"; }
rojo()     { echo -e "\e[31m$1\e[0m"; }
amarillo() { echo -e "\e[33m$1\e[0m"; }
# PHP de TuAcademia (en el VPS: dentro del contenedor, como www-data). Para las pruebas: AA_PHP=php
php_ta() { if [ -n "$AA_PHP" ]; then $AA_PHP "$@"; else docker exec -u www-data "$CONTENEDOR" php "$@"; fi; }

if [ "${AA_SIN_ROOT:-0}" != "1" ] && [ "$(id -u)" != "0" ]; then rojo "ERROR: ejecute con sudo:  sudo bash $0"; exit 1; fi
if [ ! -f "$DESTINO/public/assetlinks.php" ] || ! grep -q "PLAY_HUELLA_SUBIDA" "$DESTINO/app/play.php" 2>/dev/null; then
  rojo "ERROR: esta version de TuAcademia todavia no trae la app de Android nueva. Actualice primero:"
  echo "  cd \$HOME/tuacademia && git pull && cd \$HOME && sudo bash tuacademia/vps/vps/vps_2_codigo.sh"
  exit 1
fi
if [ -z "$AA_PHP" ] && ! docker ps --format '{{.Names}}' | grep -qx "$CONTENEDOR"; then rojo "ERROR: el contenedor $CONTENEDOR no esta corriendo."; exit 1; fi

# 0) Huella de Google (opcional): se valida antes de tocar nada
HG=""
if [ -n "$1" ]; then
  HG="$(echo "$1" | tr 'a-f' 'A-F' | tr -d ' ')"
  echo "$HG" | grep -qE '^[0-9A-F]{64}$' && HG="$(echo "$HG" | sed 's/../&:/g; s/:$//')"
  echo "$HG" | grep -qE '^([0-9A-F]{2}:){31}[0-9A-F]{2}$' || { rojo "ERROR: eso no es una huella SHA-256 (32 pares como 39:0C:08:...)."; exit 1; }
fi

# 1) app/config.php: 'google_play' => true (sin tocar lo demas; se guardan los comentarios de arriba)
R="$(php_ta -r '
$f = $argv[1] . "/app/config.php";
$orig = is_file($f) ? (string)file_get_contents($f) : "";
$c = $orig !== "" ? (require $f) : ["producto" => "academia"];
if (!is_array($c)) { echo "ERROR config"; exit(1); }
if (($c["google_play"] ?? false) === true) { echo "YA"; exit(0); }
$c["google_play"] = true;
preg_match_all("~^//.*$~m", $orig, $m);
$txt = "<?php\n" . ($m[0] ? implode("\n", $m[0]) . "\n" : "") . "// " . date("Y-m-d") . ": Google Play encendido por vps/app_android.sh (app com.tukansoftware.tuacademia)\nreturn " . var_export($c, true) . ";\n";
if (file_put_contents($f, $txt) === false) { echo "ERROR escribir"; exit(1); }
echo "OK";' "$DESTINO" 2>&1)"
case "$R" in
  YA) verde "Google Play ya estaba encendido en app/config.php" ;;
  OK) verde "Google Play encendido en app/config.php" ;;
  *)  rojo "ERROR: no se pudo cambiar $DESTINO/app/config.php: $R"; exit 1 ;;
esac

# 2) y 3) Paquete y huella de Google (ajustes de TuAcademia; los mismos del panel Superadministrador -> Google Play)
R="$(php_ta -r '
require $argv[1] . "/app/bootstrap.php";
guardar_ajuste("play_paquete", $argv[2]);
$hg = $argv[3] ?? "";
if ($hg !== "") {
    $hay = array_filter(preg_split("/[\s,;]+/", strtoupper((string)ajuste("play_huellas", ""))));
    if (in_array($hg, $hay, true)) echo "YA ";
    else { $hay[] = $hg; guardar_ajuste("play_huellas", implode("\n", array_values(array_unique($hay)))); echo "NUEVA "; }
}
echo "OK ", play_paquete(), " ", count(play_huellas());' "$DESTINO" "$PAQUETE" "$HG" 2>&1)"
echo "$R" | grep -q "OK $PAQUETE" || { rojo "ERROR al guardar el paquete o la huella: $R"; exit 1; }
verde "Paquete: $PAQUETE"
case "$R" in YA*) amarillo "La huella de Google ya estaba." ;; NUEVA*) verde "Huella de Google agregada." ;; esac
[ -z "$AA_PHP" ] && docker exec "$CONTENEDOR" kill -USR2 1 >/dev/null 2>&1   # PHP toma la config nueva al instante

# 4) nginx: /.well-known/assetlinks.json -> assetlinks.php (antes de la regla que esconde los archivos que empiezan con punto)
if [ "${AA_SIN_NGINX:-0}" != "1" ]; then
  [ -f "$NG" ] || { rojo "ERROR: no encuentro $NG"; exit 1; }
  if grep -q "assetlinks" "$NG"; then
    verde "nginx ya tenia /.well-known/assetlinks.json"
  else
    COPIA="${AA_COPIA_NGINX:-/root/tuacademia_nginx_antes_app_android}"
    cp -a "$NG" "$COPIA"
    perl -0pi -e 's{\n([ \t]*)location ~ /\\\. \{ deny all; \}}{\n$1location = /.well-known/assetlinks.json { rewrite ^ /assetlinks.php last; } # app de Android (Google Play)\n$1location ~ /\\. { deny all; }}' "$NG"
    if [ "${AA_SIN_RECARGAR:-0}" = "1" ]; then
      grep -q "assetlinks" "$NG" && verde "nginx: regla agregada (sin recargar)" || { rojo "No se pudo agregar la regla a nginx"; exit 1; }
    elif grep -q "assetlinks" "$NG" && nginx -t >/tmp/tuacademia_nginx.log 2>&1; then
      systemctl reload nginx && verde "nginx: /.well-known/assetlinks.json listo."
    else
      cp -a "$COPIA" "$NG"
      nginx -t >/dev/null 2>&1 && systemctl reload nginx
      rojo "No se pudo agregar /.well-known/assetlinks.json a nginx (se dejo como estaba). Envie a Claude: cat /tmp/tuacademia_nginx.log"; exit 1
    fi
  fi
fi

# 5) Prueba
[ "${AA_SIN_PRUEBA:-0}" = "1" ] && exit 0
sleep 3   # PHP (opcache) revisa los archivos cada 2 segundos: se espera para probar con la config nueva
PUERTO="${AA_PUERTO:-$(tr -dc '0-9' < "$DESTINO/data/puerto_nginx" 2>/dev/null)}"; [ -z "$PUERTO" ] && PUERTO=8096
R="$(curl -s -H "Host: $SITIO" "http://127.0.0.1:$PUERTO/.well-known/assetlinks.json")"
echo "$R"
if ! echo "$R" | grep -q "\"$PAQUETE\""; then rojo "ERROR: assetlinks.json no trae el paquete $PAQUETE."; exit 1; fi
if ! echo "$R" | grep -q "$HUELLA_TUKAN"; then rojo "ERROR: assetlinks.json no trae la huella de la llave de subida de Tukan."; exit 1; fi
if [ -n "$HG" ]; then
  echo "$R" | grep -q "$HG" && verde "La huella de Google tambien esta puesta." || { rojo "ERROR: assetlinks.json no trae la huella de Google."; exit 1; }
fi
verde "OK: la app de Android ya puede abrir https://$SITIO sin barra del navegador (assetlinks.json con $PAQUETE)."
