/*
 * TuAcademia (v38) — Genera los 2 proyectos Android (Trusted Web Activity) con Bubblewrap:
 *   android/play     → app de Google Play (com.tukan.tuacademia), con cobro de Google Play (Digital Goods API)
 *   android/directo  → APK para descargar desde tuacademia.tukanec.com (com.tukan.tuacademia.directo), sin cobro de Google
 * Uso (lo corre compilar.sh, o a mano):  cd android && npm install && node generar.js
 * Para una versión nueva de la app: subir VERSION_CODIGO (y VERSION_NOMBRE) aquí abajo.
 */
const path = require('path');
const fs = require('fs');
const {TwaManifest, TwaGenerator, ConsoleLog} = require('@bubblewrap/core');

const VERSION_CODIGO = 1;          // súbalo en 1 cada vez que suba una versión nueva a Google Play
const VERSION_NOMBRE = '1.0.0';
const SITIO = 'tuacademia.tukanec.com';
const ICONO = 'https://tuacademia.tukanec.com/assets/icono-512.png';

const comun = {
  host: SITIO,
  name: 'TuAcademia',
  launcherName: 'TuAcademia',
  display: 'standalone',
  orientation: 'portrait',
  themeColor: '#0f1614',
  navigationColor: '#0f1614',
  navigationColorDark: '#0f1614',
  navigationDividerColor: '#0f1614',
  navigationDividerColorDark: '#0f1614',
  backgroundColor: '#0f1614',
  enableNotifications: true,
  iconUrl: ICONO,
  maskableIconUrl: ICONO,
  splashScreenFadeOutDuration: 300,
  appVersionCode: VERSION_CODIGO,
  appVersion: VERSION_NOMBRE,
  fallbackType: 'customtabs',
  enableSiteSettingsShortcut: false,
  minSdkVersion: 24,   // Android 7 o superior (lo exigen las librerías de Google)
  signingKey: {path: '../firma/tuacademia.keystore', alias: 'tuacademia'},
  webManifestUrl: 'https://tuacademia.tukanec.com/manifest.php',
  generatorApp: 'tuacademia-v38',
};

const variantes = {
  play: {packageId: 'com.tukan.tuacademia', startUrl: '/app.php?origen=play', features: {playBilling: {enabled: true}}},
  directo: {packageId: 'com.tukan.tuacademia.directo', startUrl: '/app.php?origen=apk', features: {}},
};

(async () => {
  const log = new ConsoleLog('generar');
  const solo = process.argv[2];
  for (const [nombre, v] of Object.entries(variantes)) {
    if (solo && solo !== nombre) continue;
    const dir = path.join(__dirname, nombre);
    fs.mkdirSync(dir, {recursive: true});
    const manifiesto = new TwaManifest({...comun, ...v});
    const errores = manifiesto.validate();
    if (errores) throw new Error(nombre + ': ' + errores);
    await manifiesto.saveToFile(path.join(dir, 'twa-manifest.json'));
    await new TwaGenerator().createTwaProject(dir, manifiesto, log);
    console.log('LISTO: ' + nombre + ' (' + v.packageId + ')');
  }
})().catch((e) => { console.error('ERROR: ' + (e && e.stack || e)); process.exit(1); });
