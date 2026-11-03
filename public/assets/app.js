/* BINS: instant street look-up and in-page sign-up. The page works without this. */
(function () {
  'use strict';

  var COLOURS = { waste: '#2e7d32', recycling: '#8a9399', garden: '#8d6e3f', food: '#6d4c41' };
  var LABELS = { waste: 'General waste', recycling: 'Recycling', garden: 'Garden waste', food: 'Food waste' };
  var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

  Array.prototype.forEach.call(document.querySelectorAll('.js-hide'), function (el) { el.hidden = true; });

  function ymd(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text) e.textContent = text;
    return e;
  }

  // ---- Street look-up ----
  var select = document.getElementById('street');
  var out = document.getElementById('upcoming');
  var suStreet = document.getElementById('su-street');
  var data = null;

  function render(slug) {
    out.textContent = '';
    if (!slug) return;
    var street = data && data.streets[slug];
    if (!street || !street.next.length) {
      out.appendChild(el('p', 'muted', 'No collection dates are available for this street just now.'));
      return;
    }
    var now = new Date();
    var today = ymd(now);
    var tomorrow = ymd(new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1));
    var list = el('ol', 'days');
    street.next.forEach(function (c) {
      var p = c.day.split('-');
      var d = new Date(+p[0], +p[1] - 1, +p[2]);
      var when = c.day === today ? 'Today' : c.day === tomorrow ? 'Tomorrow' : DAYS[d.getDay()];
      var li = el('li', 'day');
      var date = el('span', 'day-date');
      date.appendChild(el('strong', '', when));
      date.appendChild(document.createTextNode(' ' + d.getDate() + ' ' + MONTHS[d.getMonth()]));
      var bins = el('span', 'day-bins');
      c.bins.forEach(function (b) {
        var s = el('span', 'bin bin-' + b);
        var dot = el('span', 'dot');
        dot.style.background = COLOURS[b] || '#56635e';
        s.appendChild(dot);
        s.appendChild(document.createTextNode(LABELS[b] || b));
        bins.appendChild(s);
      });
      li.appendChild(date);
      li.appendChild(bins);
      list.appendChild(li);
    });
    out.appendChild(list);
  }

  if (select) {
    fetch(select.getAttribute('data-collections'), { cache: 'no-cache' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { data = j; if (select.value) render(select.value); })
      .catch(function () { /* the server-rendered list stays */ });

    select.addEventListener('change', function () {
      render(select.value);
      if (suStreet && select.value) suStreet.value = select.value;
      try {
        var url = new URL(window.location.href);
        if (select.value) url.searchParams.set('street', select.value); else url.searchParams.delete('street');
        history.replaceState(null, '', url);
      } catch (e) { /* old browser */ }
    });
  }

  // ---- Sign-up ----
  var form = document.getElementById('signup-form');
  var result = document.getElementById('signup-result');
  if (!form || !window.fetch || !window.FormData) return;

  function show(message, ok) {
    result.textContent = message;
    result.className = 'signup-result ' + (ok ? 'is-ok' : 'is-error');
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var email = form.elements.email.value.trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { show('Enter a valid email address, like name@example.com.', false); return; }
    if (!form.elements.street.value) { show('Choose your street from the list.', false); return; }
    if (!form.elements.consent.checked) { show('Tick the box to agree to receive the reminders.', false); return; }
    var button = form.querySelector('button[type=submit]');
    button.disabled = true;
    show('Sending…', true);
    fetch('subscribe.php', { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        show(j.message, j.ok);
        if (j.ok) {
          Array.prototype.forEach.call(form.querySelectorAll('.field, .choice, button'), function (e) { e.hidden = true; });
        } else {
          button.disabled = false;
        }
      })
      .catch(function () {
        show('Something went wrong. Please try again later.', false);
        button.disabled = false;
      });
  });
})();
