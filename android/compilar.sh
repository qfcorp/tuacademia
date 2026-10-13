#!/bin/bash
# TuAcademia (v38) — Compila y firma las 2 apps Android.
# Necesita: Java 17+, el SDK de Android (ANDROID_HOME) y la clave de la firma en la variable TV_CLAVE_FIRMA.
# Uso:  TV_CLAVE_FIRMA='la-clave' bash android/compilar.sh
# Deja en android/salida/:
#   tuacademia-play.aab  → se sube a Google Play Console
#   tuacademia.apk       → APK para descargar desde tuacademia.tukanec.com (también se copia a public/descargas/)
set -e
cd "$(dirname "$0")"
[ -n "$TV_CLAVE_FIRMA" ] || { echo "ERROR: falta la variable TV_CLAVE_FIRMA (clave de la firma)"; exit 1; }
[ -n "$ANDROID_HOME" ] || { echo "ERROR: falta ANDROID_HOME (carpeta del SDK de Android)"; exit 1; }
BT="$(ls -d "$ANDROID_HOME"/build-tools/* | sort -V | tail -n1)"
LLAVE="$PWD/firma/tuacademia.keystore"
mkdir -p salida ../public/descargas

[ -d node_modules/@bubblewrap/core ] || npm install --no-audit --no-fund
node generar.js

for v in play directo; do
  echo "== Compilando $v =="
  ( cd "$v" && echo "sdk.dir=$ANDROID_HOME" > local.properties && chmod +x gradlew && ./gradlew --no-daemon -q assembleRelease bundleRelease )
done

echo "== Firmando =="
# App de Google Play (.aab)
cp play/app/build/outputs/bundle/release/app-release.aab salida/tuacademia-play.aab
jarsigner -keystore "$LLAVE" -storepass "$TV_CLAVE_FIRMA" -keypass "$TV_CLAVE_FIRMA" -sigalg SHA256withRSA -digestalg SHA-256 salida/tuacademia-play.aab tuacademia >/dev/null
jarsigner -verify salida/tuacademia-play.aab | grep -q "jar verified" || { echo "ERROR: el .aab no quedó firmado"; exit 1; }
# APK directo
"$BT/zipalign" -f -p 4 directo/app/build/outputs/apk/release/app-release-unsigned.apk salida/directo-alineado.apk
"$BT/apksigner" sign --ks "$LLAVE" --ks-key-alias tuacademia --ks-pass env:TV_CLAVE_FIRMA --key-pass env:TV_CLAVE_FIRMA --out salida/tuacademia.apk salida/directo-alineado.apk
rm -f salida/directo-alineado.apk salida/*.idsig
"$BT/apksigner" verify salida/tuacademia.apk || { echo "ERROR: el APK no quedó firmado"; exit 1; }
cp salida/tuacademia.apk ../public/descargas/tuacademia.apk
ls -la salida
echo "LISTO: android/salida/tuacademia-play.aab y android/salida/tuacademia.apk (copiado a public/descargas/)"
