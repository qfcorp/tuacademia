/*
 * (v38) Pagos con Google Play dentro de la app de TuAcademia (Trusted Web Activity con Digital Goods API).
 * 1. Pide a Google los precios reales (en la moneda del cliente) y los pone en los botones.
 * 2. Al tocar un botón abre el cobro de Google Play (PaymentRequest con https://play.google.com/billing).
 * 3. Manda el purchaseToken al servidor (play.php), que lo verifica con Google, lo reconoce y activa el plan.
 * 4. Al abrir la página revisa las compras que ya tiene el celular (por si una quedó a medias).
 */
(function () {
  var caja = document.getElementById('play-caja');
  if (!caja) return;
  var METODO = 'https://play.google.com/billing';
  var csrf = caja.getAttribute('data-csrf');
  var activo = caja.getAttribute('data-activo') === '1';
  var msg = document.getElementById('play-msg');
  var botones = [].slice.call(caja.querySelectorAll('.play-comprar'));
  var servicio = null;

  function aviso(texto, tipo) {
    if (!msg) { alert(texto); return; }
    msg.textContent = texto;
    msg.className = 'aviso aviso-' + (tipo || 'info');
    msg.style.display = '';
    msg.scrollIntoView({behavior: 'smooth', block: 'center'});
  }

  function enviar(datos) {
    var f = new FormData();
    f.append('csrf', csrf);
    Object.keys(datos).forEach(function (k) { f.append(k, datos[k]); });
    return fetch('play.php', {method: 'POST', body: f, credentials: 'same-origin'}).then(function (r) { return r.json(); });
  }

  function bloquear(si) { botones.forEach(function (b) { b.disabled = si; }); }

  function sinGoogle() {
    bloquear(true);
    var s = document.getElementById('play-sin-google');
    if (s) s.style.display = '';
  }

  if (!activo) { bloquear(true); return; }
  if (!('getDigitalGoodsService' in window) || !window.PaymentRequest) { sinGoogle(); return; }

  window.getDigitalGoodsService(METODO).then(function (s) {
    if (!s) { sinGoogle(); return; }
    servicio = s;
    // Precios reales de Google
    var ids = botones.map(function (b) { return b.getAttribute('data-producto'); });
    s.getDetails(ids).then(function (det) {
      (det || []).forEach(function (d) {
        var txt;
        try { txt = new Intl.NumberFormat(navigator.language || 'es', {style: 'currency', currency: d.price.currency}).format(Number(d.price.value)); } catch (e) { txt = d.price.currency + ' ' + d.price.value; }
        [].slice.call(caja.querySelectorAll('.play-precio[data-producto="' + d.itemId + '"]')).forEach(function (el) { el.textContent = txt; });
      });
      var conPrecio = (det || []).map(function (d) { return d.itemId; });
      botones.forEach(function (b) { if (conPrecio.indexOf(b.getAttribute('data-producto')) < 0) { b.disabled = true; b.title = 'Todavía no está a la venta en Google Play'; } });
    }).catch(function () {});
    // Compras que ya tiene este celular (restaurar)
    if (s.listPurchases) {
      s.listPurchases().then(function (lista) {
        if (!lista || !lista.length) return;
        enviar({accion: 'restaurar', compras: JSON.stringify(lista.map(function (p) { return {itemId: p.itemId, purchaseToken: p.purchaseToken}; }))})
          .then(function (r) { if (r && r.ok && r.revisadas && sessionStorage.getItem('play_restaurado') !== '1') { sessionStorage.setItem('play_restaurado', '1'); location.reload(); } })
          .catch(function () {});
      }).catch(function () {});
    }
  }).catch(function () { sinGoogle(); });

  botones.forEach(function (b) {
    b.addEventListener('click', function () {
      if (!servicio) { sinGoogle(); return; }
      var producto = b.getAttribute('data-producto');
      var pedido;
      try {
        pedido = new PaymentRequest([{supportedMethods: METODO, data: {sku: producto}}], {total: {label: 'Total', amount: {currency: 'USD', value: '0'}}});
      } catch (e) { aviso('No se pudo abrir el pago de Google Play: ' + e.message, 'error'); return; }
      bloquear(true);
      pedido.show().then(function (resp) {
        var token = resp.details && resp.details.purchaseToken;
        if (!token) { resp.complete('fail'); throw new Error('Google no devolvió la compra.'); }
        aviso('Pago recibido. Activando su plan…', 'info');
        return enviar({accion: 'verificar', producto: producto, token: token}).then(function (r) {
          resp.complete(r && r.ok ? 'success' : 'fail');
          if (r && r.ok) { aviso(r.mensaje || '¡Listo!', 'ok'); setTimeout(function () { location.reload(); }, 2500); }
          else { aviso((r && r.error) || 'No se pudo activar. Su pago está protegido: escríbanos a soporte.', 'error'); bloquear(false); }
        });
      }).catch(function (e) {
        bloquear(false);
        if (e && e.name === 'AbortError') return; // el cliente cerró el pago
        aviso('No se completó el pago: ' + (e && e.message ? e.message : e), 'error');
      });
    });
  });
})();
