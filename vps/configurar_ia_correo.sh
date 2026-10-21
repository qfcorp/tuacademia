#!/bin/bash
# =====================================================================
#  TuAcademia - IA (Ayuda y Asistente IA) y CORREO de Tukan en el VPS (Docker)
#  El contenedor de PHP solo ve /var/www/... : NO ve /etc/tukan-ia.env ni /etc/tukan-correo.env.
#  Por eso la configuracion se guarda en DATOS/tukan-ia.env y DATOS/tukan-correo.env (carpeta data/, no va a GitHub).
#  El programa la lee sola (app/asistente_ia.php). Se puede repetir sin problema.
#
#  Uso (en el VPS):
#    sudo bash /var/www/tuacademia/vps/configurar_ia_correo.sh              copia /etc/tukan-ia.env y /etc/tukan-correo.env si existen;
#                                                                      si no, pregunta los datos (la clave no se ve al escribir)
#    sudo bash /var/www/tuacademia/vps/configurar_ia_correo.sh --ia         solo la IA (pregunta la clave de Anthropic)
#    sudo bash /var/www/tuacademia/vps/configurar_ia_correo.sh --correo     solo el correo (servidor, puerto, usuario, clave)
#    sudo bash /var/www/tuacademia/vps/configurar_ia_correo.sh --probar [correo@destino.com]
#    sudo bash /var/www/tuacademia/vps/configurar_ia_correo.sh --pausar | --reanudar     corta / vuelve a encender el gasto de IA
#    sudo bash /var/www/tuacademia/vps/configurar_ia_correo.sh --quitar     borra los dos archivos (la Ayuda sigue con el manual)
# =====================================================================
NOMBRE="TuAcademia"
DESTINO="${TC_DESTINO:-/var/www/tuacademia}"
CONTENEDOR="${TC_CONTENEDOR-tuacademia-php}"
USUARIO_WEB="${TC_USUARIO_WEB:-www-data}"
IA="$DESTINO/data/tukan-ia.env"
CORREO="$DESTINO/data/tukan-correo.env"

verde()    { echo -e "\e[32m$1\e[0m"; }
rojo()     { echo -e "\e[31m$1\e[0m"; }
amarillo() { echo -e "\e[33m$1\e[0m"; }

if [ "${TC_SIN_ROOT:-0}" != "1" ] && [ "$(id -u)" != "0" ]; then rojo "ERROR: ejecute con sudo:  sudo bash $0"; exit 1; fi
[ -d "$DESTINO/data" ] && [ -f "$DESTINO/app/tukan_ia.php" ] || { rojo "ERROR: no encuentro $NOMBRE con la IA en $DESTINO (actualice primero con vps_2_codigo.sh)."; exit 1; }

guardar() { # guardar ARCHIVO (lee el contenido de la entrada)
  local tmp="$1.nuevo.$$"
  umask 027
  cat > "$tmp" || { rojo "ERROR: no se pudo escribir $1"; rm -f "$tmp"; exit 1; }
  [ "${TC_SIN_ROOT:-0}" != "1" ] && chown "$USUARIO_WEB:$USUARIO_WEB" "$tmp" 2>/dev/null
  chmod 640 "$tmp"
  mv -f "$tmp" "$1" || { rojo "ERROR: no se pudo guardar $1"; exit 1; }
}
leer() { [ -f "$2" ] && grep -E "^$1=" "$2" | tail -n1 | cut -d= -f2- | tr -d '\r'; }
preguntar() { local r; read -r -p "$1 [$2]: " r < /dev/tty; echo "${r:-$2}"; }
secreto() { local r; read -r -s -p "$1: " r < /dev/tty; echo >&2; echo "$r"; }
php_c() { # corre PHP como el programa (en el contenedor si hay Docker)
  if [ -n "$CONTENEDOR" ]; then docker exec -u "$USUARIO_WEB" -e TUKAN_IA_ENV="$IA" -e TUKAN_CORREO_ENV="$CORREO" "$CONTENEDOR" php "$@"
  else TUKAN_IA_ENV="$IA" TUKAN_CORREO_ENV="$CORREO" php "$@"; fi
}

configurar_ia() {
  local clave rapido fuerte
  echo "IA de Tukan (clave de la API de Anthropic, sk-ant-...)."
  clave="$(secreto 'Clave (no se ve al escribir; Enter = dejar la que ya esta)')"
  [ -z "$clave" ] && clave="$(leer TUKAN_IA_CLAVE "$IA")"
  [ -z "$clave" ] && { rojo "Sin clave no hay IA (la Ayuda sigue con el manual)."; return 1; }
  rapido="$(preguntar 'Modelo rapido' "$(leer TUKAN_IA_MODELO_RAPIDO "$IA" | grep . || echo claude-haiku-5-5)")"
  fuerte="$(preguntar 'Modelo fuerte (fotos)' "$(leer TUKAN_IA_MODELO_FUERTE "$IA" | grep . || echo claude-sonnet-5-5)")"
  printf '# IA de Tukan para %s (lo escribe vps/configurar_ia_correo.sh). NO compartir: tiene la clave.\nTUKAN_IA_CLAVE=%s\nTUKAN_IA_MODELO_RAPIDO=%s\nTUKAN_IA_MODELO_FUERTE=%s\nTUKAN_IA_PAUSA=%s\n' \
    "$NOMBRE" "$clave" "$rapido" "$fuerte" "$(leer TUKAN_IA_PAUSA "$IA" | grep . || echo 0)" | guardar "$IA"
  verde "IA guardada en $IA"
}

configurar_correo() {
  local host puerto seg usuario clave de
  echo "Correo de Tukan (info@tukansoftware.com)."
  host="$(preguntar 'Servidor' "$(leer TUKAN_SMTP_HOST "$CORREO" | grep . || echo mail.qfproducciones.com)")"
  puerto="$(preguntar 'Puerto' "$(leer TUKAN_SMTP_PUERTO "$CORREO" | grep . || echo 587)")"
  seg="$(preguntar 'Seguridad (tls o ssl)' "$(leer TUKAN_SMTP_SEGURIDAD "$CORREO" | grep . || echo tls)")"
  usuario="$(preguntar 'Usuario' "$(leer TUKAN_SMTP_USUARIO "$CORREO" | grep . || echo info@tukansoftware.com)")"
  clave="$(secreto 'Clave del buzon (no se ve al escribir; Enter = dejar la que ya esta)')"
  [ -z "$clave" ] && clave="$(leer TUKAN_SMTP_CLAVE "$CORREO")"
  de="$(preguntar 'Remitente' "$(leer TUKAN_CORREO_DE "$CORREO" | grep . || echo "$usuario")")"
  printf '# Correo de Tukan para %s (lo escribe vps/configurar_ia_correo.sh). NO compartir: tiene la clave.\nTUKAN_SMTP_HOST=%s\nTUKAN_SMTP_PUERTO=%s\nTUKAN_SMTP_SEGURIDAD=%s\nTUKAN_SMTP_USUARIO=%s\nTUKAN_SMTP_CLAVE=%s\nTUKAN_CORREO_DE=%s\nTUKAN_CORREO_NOMBRE=Tukan Software\n' \
    "$NOMBRE" "$host" "$puerto" "$seg" "$usuario" "$clave" "$de" | guardar "$CORREO"
  verde "Correo guardado en $CORREO"
}

copiar_etc() { # copiar ORIGEN DESTINO
  [ -f "$1" ] || return 1
  guardar "$2" < "$1" && verde "Copiado $1 -> $2"
}

probar() {
  echo "== Prueba de la IA (como el usuario de PHP)"
  php_c "$DESTINO/app/tukan_ia.php" probar
  if [ -n "$1" ]; then
    echo "== Prueba del correo a $1"
    php_c "$DESTINO/app/tukan_correo.php" probar "$1"
  else
    amarillo "Para probar el correo: sudo bash $0 --probar su@correo.com"
  fi
}

pausa() { # pausa 1|0
  [ -f "$IA" ] || { rojo "Primero configure la IA: sudo bash $0 --ia"; exit 1; }
  { grep -v '^TUKAN_IA_PAUSA=' "$IA"; echo "TUKAN_IA_PAUSA=$1"; } | guardar "$IA"
  [ "$1" = "1" ] && verde "IA en PAUSA: no se gasta nada (la Ayuda sigue con las preguntas frecuentes y el manual)." || verde "IA encendida otra vez."
}

case "$1" in
  --ia) configurar_ia && probar ;;
  --correo) configurar_correo; probar "$(preguntar 'Correo para la prueba (vacio = no probar)' '')" ;;
  --probar) probar "$2" ;;
  --pausar) pausa 1 ;;
  --reanudar) pausa 0 ;;
  --quitar) rm -f "$IA" "$CORREO"; verde "Listo: sin IA ni correo de Tukan en $NOMBRE (la Ayuda sigue con el manual)." ;;
  "")
    copiar_etc /etc/tukan-ia.env "$IA" || configurar_ia
    copiar_etc /etc/tukan-correo.env "$CORREO" || configurar_correo
    probar ;;
  *) echo "Uso: sudo bash $0 [--ia | --correo | --probar correo@x.com | --pausar | --reanudar | --quitar]"; exit 1 ;;
esac
