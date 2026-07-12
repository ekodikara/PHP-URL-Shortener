/* Snip — front-end interactions: AJAX shorten, copy, QR codes, toast. */
(function () {
  'use strict';

  function toast(msg) {
    var t = document.getElementById('toast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'toast';
      t.className = 'toast';
      // announce toasts (copy confirmations, AJAX errors) to assistive tech
      t.setAttribute('role', 'status');
      t.setAttribute('aria-live', 'polite');
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
      colorDark: '#2a2118',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M
    });
  }

  // Light/dark toggle. Effective theme = explicit choice, else OS preference.
  // The choice is persisted and applied pre-paint by an inline <head> script.
  var themeBtn = document.getElementById('theme-toggle');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var root = document.documentElement;
      var explicit = root.getAttribute('data-theme');
      var dark = explicit
        ? explicit === 'dark'
        : window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
      var next = dark ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      themeBtn.setAttribute('data-mode', next);
      try { localStorage.setItem('snip-theme', next); } catch (e) {}
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

  // Live activity feed on the per-link stats page (polled; no websockets).
  var liveFeed = document.getElementById('live-feed');
  if (liveFeed) {
    var liveList = document.getElementById('live-list');
    var code = liveFeed.getAttribute('data-code');
    var since = parseInt(liveFeed.getAttribute('data-since'), 10) || 0;

    function relTime(ts) {
      var s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
      if (s < 60) return s + 's ago';
      if (s < 3600) return Math.floor(s / 60) + 'm ago';
      if (s < 86400) return Math.floor(s / 3600) + 'h ago';
      return Math.floor(s / 86400) + 'd ago';
    }
    function eventRow(ev) {
      var li = document.createElement('li');
      var bits = [ev.device, ev.browser, ev.platform].filter(Boolean).join(' · ');
      li.innerHTML =
        '<span class="lv-flag">' + (ev.flag || '') + '</span>' +
        '<span class="lv-main">' + escapeHtml(bits) + '</span>' +
        '<span class="lv-ref">' + escapeHtml(ev.ref || '') + '</span>' +
        '<span class="lv-time" data-ts="' + ev.ts + '">' + relTime(ev.ts) + '</span>';
      return li;
    }
    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }
    function poll() {
      fetch('stats?code=' + encodeURIComponent(code) + '&events=1&since=' + since, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin'
      })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data || !data.events) return;
          if (data.events.length) {
            var empty = liveList.querySelector('.live-empty');
            if (empty) empty.remove();
            // API returns newest-first; insert so newest ends up on top.
            data.events.slice().reverse().forEach(function (ev) {
              if (ev.ts > since) since = ev.ts;
              liveList.insertBefore(eventRow(ev), liveList.firstChild);
            });
            while (liveList.children.length > 40) liveList.removeChild(liveList.lastChild);
          }
          // Refresh relative timestamps already on screen.
          liveList.querySelectorAll('.lv-time').forEach(function (el) {
            el.textContent = relTime(parseInt(el.getAttribute('data-ts'), 10) || 0);
          });
        })
        .catch(function () {});
    }
    poll();
    setInterval(poll, 5000);
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
            var shortDisplay = res.body.short_url.replace(/^https?:\/\//, '');
            result.querySelector('.url').textContent = shortDisplay;
            // honest measurement: how much paper the cut removed
            var savedEl = result.querySelector('[data-saved]');
            var longEl = form.querySelector('[name=longurl]');
            if (savedEl && longEl) {
              var longLen = longEl.value.replace(/^https?:\/\//, '').length;
              var diff = longLen - shortDisplay.length;
              if (diff > 0) {
                savedEl.innerHTML = '<b>' + diff + ' characters</b> shorter.';
                savedEl.hidden = false;
              } else {
                savedEl.hidden = true;
              }
            }
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
