/*
 * Service worker: permite instalar el sistema como aplicación y que la VOCALÍA
 * se abra y funcione aunque no haya internet en la cancha.
 * (v10) También muestra las notificaciones de "Seguir a mi equipo" (goles, resultados, horarios).
 */
var VERSION = 'ligas-v33-1';
var FOTOS = 'ligas-fotos';
var BASICOS = ['assets/estilo.css?v=330', 'assets/js/jsQR.js', 'assets/js/qrcode.js', 'assets/icono-192.png', 'offline.html', 'app.php'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(VERSION).then(function (c) { return c.addAll(BASICOS); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (ks) {
    return Promise.all(ks.filter(function (k) { return k !== VERSION && k !== FOTOS; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var r = e.request;
  if (r.method !== 'GET') return;
  var u = new URL(r.url);
  if (u.origin !== self.location.origin) return;
  var p = u.pathname;

  // Vocalía: primero internet; si no hay, la última copia guardada
  if (/\/vocal\.php$/.test(p) && u.searchParams.get('k') && !u.searchParams.get('imprimir')) {
    var clave = u.origin + p + '?k=' + u.searchParams.get('k');
    e.respondWith(fetch(r).then(function (resp) {
      if (resp.ok && !u.searchParams.get('verificar') && !u.searchParams.get('buscar')) {
        var copia = resp.clone();
        caches.open(VERSION).then(function (c) { c.put(clave, copia); });
      }
      return resp;
    }).catch(function () {
      return caches.match(clave).then(function (m) { return m || caches.match('offline.html'); });
    }));
    return;
  }
  // Fotos de jugadores y escudos: se guardan para verificar sin internet
  if (p.indexOf('/uploads/') >= 0) {
    e.respondWith(caches.open(FOTOS).then(function (c) {
      return c.match(r).then(function (m) {
        return m || fetch(r).then(function (resp) { if (resp.ok) c.put(r, resp.clone()); return resp; });
      });
    }));
    return;
  }
  // Estilos, scripts e íconos
  if (p.indexOf('/assets/') >= 0) {
    e.respondWith(caches.match(r).then(function (m) {
      return m || fetch(r).then(function (resp) {
        if (resp.ok) { var copia = resp.clone(); caches.open(VERSION).then(function (c) { c.put(r, copia); }); }
        return resp;
      });
    }));
    return;
  }
  // Otras páginas: siempre por internet; si no hay, aviso de "sin conexión"
  if (r.mode === 'navigate') {
    e.respondWith(fetch(r).catch(function () {
      return caches.match(/\/app\.php$/.test(p) ? 'app.php' : 'offline.html').then(function (m) { return m || caches.match('offline.html'); });
    }));
  }
});

// (v10) Notificaciones "Seguir a mi equipo"
self.addEventListener('push', function (e) {
  var d = {};
  try { d = e.data ? e.data.json() : {}; } catch (x) { d = { t: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.t || 'TuVocalía', {
    body: d.b || '', icon: 'assets/icono-192.png', badge: 'assets/icono-192.png', lang: 'es', data: { u: d.u || 'app.php' }
  }));
});

self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var u = new URL((e.notification.data && e.notification.data.u) || 'app.php', self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (cs) {
    for (var i = 0; i < cs.length; i++) { if (cs[i].url === u && 'focus' in cs[i]) return cs[i].focus(); }
    return self.clients.openWindow ? self.clients.openWindow(u) : null;
  }));
});
