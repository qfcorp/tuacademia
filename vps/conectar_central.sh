#!/bin/bash
# =====================================================================
#  TuAcademia - CONECTAR CON EL PANEL TUKAN CENTRAL (protocolo v1)
#  Se puede repetir las veces que haga falta (no duplica nada).
#
#  Uso (en el VPS):
#    sudo bash /var/www/tuacademia/vps/conectar_central.sh ID SECRETO URL   conecta (o cambia el secreto) y se prueba solo
#    sudo bash /var/www/tuacademia/vps/conectar_central.sh --probar         solo repite la prueba
#    sudo bash /var/www/tuacademia/vps/conectar_central.sh --solo-central 1 el super entra SOLO desde el panel central
#    sudo bash /var/www/tuacademia/vps/conectar_central.sh --solo-central 0 EMERGENCIA: el super vuelve a entrar con su clave
#    sudo bash /var/www/tuacademia/vps/conectar_central.sh --desconectar    apaga el conector (tukan_central.php da 404)
#
#  La configuracion queda en DATOS/tukan_central.env (carpeta data/, que no va a GitHub). El contenedor de PHP
#  ve esa carpeta (esta montada) y la lee en cada pedido: no hace falta reiniciar nada.
# =====================================================================
NOMBRE="TuAcademia"
DESTINO="${TC_DESTINO:-/var/www/tuacademia}"          # carpeta del programa (app/, public/, data/)
CONTENEDOR="${TC_CONTENEDOR-tuacademia-php}"       # contenedor de PHP (vacio = sin Docker, solo para pruebas)
SITIO="${TC_SITIO:-tuacademia.tukanec.com}"                # nombre que se manda a nginx en la prueba
PUERTO_WEB="${TC_PUERTO:-}"                       # nginx interno; si esta vacio se lee de data/puerto_nginx (o 8096)
USUARIO_WEB="${TC_USUARIO_WEB:-www-data}"

verde()    { echo -e "\e[32m$1\e[0m"; }
rojo()     { echo -e "\e[31m$1\e[0m"; }
amarillo() { echo -e "\e[33m$1\e[0m"; }

ENV="$DESTINO/data/tukan_central.env"
if [ -z "$PUERTO_WEB" ]; then
  PUERTO_WEB="$(tr -dc '0-9' < "$DESTINO/data/puerto_nginx" 2>/dev/null)"
  [ -z "$PUERTO_WEB" ] && PUERTO_WEB="8096"
fi

if [ "${TC_SIN_ROOT:-0}" != "1" ] && [ "$(id -u)" != "0" ]; then
  rojo "ERROR: ejecute con sudo:  sudo bash $0 ID SECRETO URL"; exit 1
fi
if [ ! -d "$DESTINO/data" ] || [ ! -d "$DESTINO/app" ]; then
  rojo "ERROR: no encuentro $NOMBRE en $DESTINO (faltan las carpetas app/ y data/)."; exit 1
fi
if [ ! -f "$DESTINO/app/tukan_central.php" ] || [ ! -f "$DESTINO/public/tukan_central.php" ]; then
  rojo "ERROR: esta version de $NOMBRE todavia no tiene el conector (falta tukan_central.php)."
  echo "Primero actualice el programa (vps_2_codigo.sh) y vuelva a correr este comando."; exit 1
fi
if [ "${TC_SIN_ROOT:-0}" != "1" ] && [ "$(stat -c %U "$0" 2>/dev/null)" != "root" ]; then
  amarillo "AVISO: este archivo no es de root ($0). Por seguridad: sudo chown root:root $0"
fi

leer() { [ -f "$ENV" ] && grep -E "^$1=" "$ENV" | tail -n1 | cut -d= -f2- | tr -d '\r"'"'"; }

escribir() { # escribir ID SECRETO URL SOLO
  local tmp="$ENV.nuevo.$$"
  umask 027
  {
    echo "# Conector con el Panel Tukan Central (lo escribe vps/conectar_central.sh). NO compartir: tiene el secreto."
    echo "TUKAN_CENTRAL_ID=$1"
    echo "TUKAN_CENTRAL_SECRET=$2"
    echo "TUKAN_CENTRAL_URL=$3"
    echo "TUKAN_SOLO_CENTRAL=$4"
  } > "$tmp" || { rojo "ERROR: no se pudo escribir $ENV"; rm -f "$tmp"; exit 1; }
  if [ "${TC_SIN_ROOT:-0}" != "1" ]; then chown "$USUARIO_WEB:$USUARIO_WEB" "$tmp" 2>/dev/null; fi
  chmod 640 "$tmp"
  mv -f "$tmp" "$ENV" || { rojo "ERROR: no se pudo guardar $ENV"; exit 1; }
}

recargar_php() { # PHP toma el programa al instante (la configuracion igual se lee en cada pedido)
  [ -z "$CONTENEDOR" ] && return 0
  if ! command -v docker >/dev/null 2>&1 || ! docker ps --format '{{.Names}}' | grep -qx "$CONTENEDOR"; then
    rojo "ERROR: el contenedor $CONTENEDOR no esta funcionando. Revise: sudo docker ps -a ; sudo docker logs --tail 20 $CONTENEDOR"
    exit 1
  fi
  docker exec "$CONTENEDOR" kill -USR2 1 >/dev/null 2>&1
  sleep 1
}

b64url() { base64 | tr -d '\n=' | tr '+/' '-_'; }

probar() { # pide accion=estado con un token recien firmado, por el nginx interno
  local id secreto ahora nonce payload a firma token r codigo cuerpo i
  id="$(leer TUKAN_CENTRAL_ID)"; secreto="$(leer TUKAN_CENTRAL_SECRET)"
  [ -n "$id" ] && [ -n "$secreto" ] || { rojo "ERROR: el conector no esta configurado ($ENV). Uso: sudo bash $0 ID SECRETO URL"; exit 1; }
  command -v curl >/dev/null 2>&1 || { rojo "ERROR: falta curl (sudo apt install curl)"; exit 1; }
  command -v openssl >/dev/null 2>&1 || { rojo "ERROR: falta openssl (sudo apt install openssl)"; exit 1; }
  for i in 1 2 3 4 5; do
    ahora="$(date +%s)"
    nonce="$(openssl rand -hex 16)"
    payload="{\"v\":1,\"tipo\":\"estado\",\"inst\":\"$id\",\"email\":\"prueba-conector@tukanec.com\",\"nombre\":\"Prueba del conector\",\"iat\":$ahora,\"exp\":$((ahora + 60)),\"nonce\":\"$nonce\"}"
    a="$(printf '%s' "$payload" | b64url)"
    firma="$(printf '%s' "$a" | openssl dgst -sha256 -hmac "$secreto" -binary | b64url)"
    token="$a.$firma"
    r="$(curl -s -m 15 -w '\n%{http_code}' -H "Host: $SITIO" "http://127.0.0.1:$PUERTO_WEB/tukan_central.php?accion=estado&t=$token")"
    codigo="$(printf '%s' "$r" | tail -n1)"; cuerpo="$(printf '%s' "$r" | sed '$d')"
    [ "$codigo" = "200" ] && break
    [ "$codigo" = "401" ] && break   # un 401 no se arregla esperando
    sleep 2
  done
  if [ "$codigo" = "200" ] && printf '%s' "$cuerpo" | grep -q '"ok":true'; then
    verde "OK: $NOMBRE esta conectado al Panel Tukan Central (instancia $id)."
    echo "    Negocios: $(printf '%s' "$cuerpo" | grep -o '"total":[0-9]*' | head -n1 | cut -d: -f2)   Version: $(printf '%s' "$cuerpo" | grep -o '"version":"[^"]*"' | cut -d'"' -f4)"
    echo "    Solo desde el panel central (TUKAN_SOLO_CENTRAL): $(leer TUKAN_SOLO_CENTRAL)"
    return 0
  fi
  case "$codigo" in
    404) rojo "ERROR: tukan_central.php responde 404: PHP no ve la configuracion. Revise que exista $ENV y que sea legible para $USUARIO_WEB." ;;
    401) rojo "ERROR: el programa rechazo el token de prueba: $(printf '%s' "$cuerpo" | grep -o '"error":"[^"]*"' | cut -d'"' -f4 | iconv -f utf-8 -t ascii//TRANSLIT 2>/dev/null)"
         echo "Revise que la hora del servidor este bien (date) y que el secreto este completo." ;;
    000|"") rojo "ERROR: nginx no responde en 127.0.0.1:$PUERTO_WEB. Revise: sudo systemctl status nginx" ;;
    *)   rojo "ERROR: respuesta inesperada (codigo $codigo). Revise: sudo docker logs --tail 20 $CONTENEDOR" ;;
  esac
  exit 1
}

case "$1" in
  --probar)
    probar ;;
  --solo-central)
    [ "$2" = "0" ] || [ "$2" = "1" ] || { rojo "Uso: sudo bash $0 --solo-central 1   (o 0 para volver a permitir el ingreso local)"; exit 1; }
    [ -n "$(leer TUKAN_CENTRAL_ID)" ] || { rojo "ERROR: primero conecte el programa: sudo bash $0 ID SECRETO URL"; exit 1; }
    escribir "$(leer TUKAN_CENTRAL_ID)" "$(leer TUKAN_CENTRAL_SECRET)" "$(leer TUKAN_CENTRAL_URL)" "$2"
    if [ "$2" = "1" ]; then verde "Listo: el superadministrador entra SOLO desde el Panel Tukan Central (los clientes siguen igual)."
    else verde "Listo: el superadministrador puede volver a entrar con su correo y clave en /admin.php"; fi
    probar ;;
  --desconectar)
    rm -f "$ENV"
    verde "Listo: conector apagado (tukan_central.php responde 404). El superadministrador entra con su clave como antes." ;;
  -*|"")
    echo "Uso: sudo bash $0 ID SECRETO URL"
    echo "     sudo bash $0 --probar | --solo-central 1 | --solo-central 0 | --desconectar"
    exit 1 ;;
  *)
    ID="$1"; SECRETO="$2"; URL="${3:-https://panel.tukanec.com}"
    printf '%s' "$ID" | grep -Eq '^[a-z0-9_-]{2,40}$' || { rojo "ERROR: el ID debe tener de 2 a 40 letras minusculas, numeros, - o _ (llego: $ID)"; exit 1; }
    printf '%s' "$SECRETO" | grep -Eq '^[A-Za-z0-9]{32,256}$' || { rojo "ERROR: el SECRETO debe tener al menos 32 letras o numeros (copielo completo del panel central)."; exit 1; }
    printf '%s' "$URL" | grep -Eq '^https?://[A-Za-z0-9.:/_-]+$' || { rojo "ERROR: la URL del panel no es valida (ejemplo: https://panel.tukanec.com)"; exit 1; }
    URL="${URL%/}"
    SOLO="$(leer TUKAN_SOLO_CENTRAL)"; [ "$SOLO" = "1" ] || SOLO="0"   # se conserva lo que ya estaba
    escribir "$ID" "$SECRETO" "$URL" "$SOLO"
    verde "Configuracion guardada en $ENV"
    recargar_php
    probar ;;
esac
