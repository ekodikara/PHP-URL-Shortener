/* Snip — front-end interactions: AJAX shorten, copy, QR codes, toast. */
(function () {
  'use strict';

  function toast(msg) {
    var t = document.getElementById('toast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'toast';
      t.className = 'toast';
      document.body.appendChild(t);
    }
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(t._timer);
    t._timer = setTimeout(function () { t.classList.remove('show'); }, 1900);
  }

  function copy(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { toast('Copied to clipboard'); });
    } else {
      var ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); toast('Copied to clipboard'); } catch (e) {}
      document.body.removeChild(ta);
    }
  }

  function drawQR(el, text) {
    if (!el || typeof QRCode === 'undefined') return;
    el.innerHTML = '';
    new QRCode(el, {
      text: text,
      width: 88,
      height: 88,
      colorDark: '#070b14',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M
    });
  }

  // Anti-bot JS-proof: real browsers set this; scripted clients that don't run
  // JS leave it empty and are rejected server-side.
  document.querySelectorAll('input[name=js_ok]').forEach(function (i) { i.value = '1'; });

  // Billing interval toggle (monthly / yearly) on the pricing cards.
  var billingToggle = document.getElementById('billing-toggle');
  if (billingToggle) {
    billingToggle.addEventListener('click', function (ev) {
      var opt = ev.target.closest('.bt-opt');
      if (!opt) return;
      var interval = opt.getAttribute('data-interval');
      billingToggle.querySelectorAll('.bt-opt').forEach(function (b) {
        b.classList.toggle('is-active', b === opt);
      });
      document.querySelectorAll('.price[data-monthly]').forEach(function (price) {
        var yearly = interval === 'year';
        price.querySelector('.js-price').textContent =
          price.getAttribute(yearly ? 'data-yearly' : 'data-monthly');
        price.querySelector('.js-unit').textContent = yearly ? '/yr' : '/mo';
      });
      document.querySelectorAll('.js-save-note').forEach(function (n) {
        n.style.visibility = interval === 'year' ? 'visible' : 'hidden';
      });
      document.querySelectorAll('.js-interval').forEach(function (i) {
        i.value = interval;
      });
    });
  }

  // Delegated copy buttons (works for static + ajax-inserted nodes).
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-copy]');
    if (btn) {
      ev.preventDefault();
      copy(btn.getAttribute('data-copy'));
    }
  });

  // Render any QR placeholders present on the page (dashboard rows).
  document.querySelectorAll('[data-qr]').forEach(function (el) {
    drawQR(el, el.getAttribute('data-qr'));
  });

  // AJAX shorten form (landing + dashboard).
  var form = document.getElementById('shorten-form');
  if (form) {
    var result = document.getElementById('shorten-result');
    var btn = form.querySelector('button[type=submit]');

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (btn) { btn.disabled = true; btn.textContent = 'Shortening…'; }

      fetch('shorten', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form)
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
          if (btn) { btn.disabled = false; btn.textContent = 'Shorten'; }
          if (!res.ok) {
            toast(res.body.error || 'Something went wrong');
            return;
          }
          if (result) {
            result.querySelector('.url').textContent = res.body.short_url.replace(/^https?:\/\//, '');
            var copyBtn = result.querySelector('[data-copy]');
            if (copyBtn) copyBtn.setAttribute('data-copy', res.body.short_url);
            var open = result.querySelector('.open-link');
            if (open) open.setAttribute('href', res.body.short_url);
            drawQR(result.querySelector('[data-qr-target]'), res.body.short_url);
            result.classList.add('show');
          }
          var custom = form.querySelector('[name=slug]');
          if (custom) custom.value = '';
          // Refresh the dashboard list (if present) after a beat.
          if (document.getElementById('links-table') && res.body.reload) {
            setTimeout(function () { location.reload(); }, 900);
          }
        })
        .catch(function () {
          if (btn) { btn.disabled = false; btn.textContent = 'Shorten'; }
          toast('Network error');
        });
    });
  }
})();
