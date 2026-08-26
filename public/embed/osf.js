/*
 * OpenSendForm — embed script (public/embed/osf.js).
 *
 * ONE static, dependency-free file. A site owner pastes a plain HTML <form>
 * with data-osf-key + data-osf-url and one <script> line; this upgrades every
 * such form on the page independently. Vanilla ES2017, no build step.
 *
 * Progressive enhancement is absolute: with this script absent/failed the form
 * still POSTs natively to its action and the endpoint returns a readable HTML
 * page. When it runs, submission goes over fetch with a richer UX — and every
 * unexpected failure degrades back to the native POST. Reserved wire fields:
 * _osf_token, _osf_hp (honeypot), _osf_cf (Turnstile). See the README.
 *
 * Indentation is 2-space (not the repo's 4) to keep this shipped client asset
 * as small as possible; see HISTORY/QUESTIONS on the size budget.
 */
(function () {
  'use strict';

  if (typeof window === 'undefined' || !window.fetch || !window.Promise) {
    return;
  }

  var STYLE_ID = 'osf-embed-styles';
  var uid = 0;
  var CSS = [
    '.osf-root{position:relative}',
    '.osf-hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden;opacity:0}',
    '.osf-vh{position:absolute!important;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}',
    '.osf-field-error{color:var(--osf-error,#b00020);font-size:.875em;margin:.3em 0 0}',
    '.osf-invalid{outline:2px solid var(--osf-error,#b00020);outline-offset:1px}',
    '.osf-form-error{color:var(--osf-error,#b00020);border:1px solid currentColor;border-radius:var(--osf-radius,6px);padding:.6em .8em;margin:0 0 1em}',
    '.osf-spinner{display:inline-block;width:1em;height:1em;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;vertical-align:-.15em;margin-right:.45em;animation:osf-spin .6s linear infinite}',
    '@keyframes osf-spin{to{transform:rotate(360deg)}}',
    '.osf-cf{margin:0 0 1em}',
    '.osf-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:1em;background:var(--osf-overlay-bg,rgba(255,255,255,.92));z-index:20}',
    '.osf-dialog{max-width:22rem;width:100%;text-align:center;padding:1.5em;border-radius:var(--osf-radius,6px);background:var(--osf-surface,#fff);box-shadow:0 6px 30px rgba(0,0,0,.18)}',
    '.osf-dialog h2,.osf-submitted h2{margin:0 0 .4em}',
    '.osf-dialog p,.osf-submitted p{margin:0 0 1.1em}',
    '.osf-btn{font:inherit;cursor:pointer;color:var(--osf-on-accent,#fff);background:var(--osf-accent,#2563eb);border:0;border-radius:var(--osf-radius,6px);padding:.55em 1.2em}',
    '.osf-submitted{text-align:center;padding:1.5em;border:1px solid var(--osf-border,#d7d7d7);border-radius:var(--osf-radius,6px)}',
    '@media (prefers-reduced-motion:reduce){.osf-spinner{animation:none}}'
  ].join('');

  function el(tag, props, text) {
    var node = document.createElement(tag);
    if (props) {
      for (var k in props) {
        if (props.hasOwnProperty(k)) { node.setAttribute(k, props[k]); }
      }
    }
    if (text != null) { node.textContent = text; }
    return node;
  }

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) { return; }
    var style = el('style', { id: STYLE_ID });
    style.textContent = CSS;
    (document.head || document.documentElement).appendChild(style);
  }

  var turnstilePromise = null;
  function loadTurnstile() {
    if (window.turnstile) { return Promise.resolve(); }
    if (turnstilePromise) { return turnstilePromise; }
    turnstilePromise = new Promise(function (resolve, reject) {
      var s = el('script', {
        src: 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
      });
      s.async = true;
      s.defer = true;
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('turnstile')); };
      (document.head || document.documentElement).appendChild(s);
    });
    return turnstilePromise;
  }

  function dispatch(form, name, detail) {
    var ev;
    try {
      ev = new CustomEvent(name, { detail: detail, bubbles: true });
    } catch (e) {
      ev = document.createEvent('CustomEvent');
      ev.initCustomEvent(name, true, false, detail);
    }
    form.dispatchEvent(ev);
  }

  function enhance(form) {
    if (form.getAttribute('data-osf-init') === '1') { return; }
    var key = (form.getAttribute('data-osf-key') || '').trim();
    var base = (form.getAttribute('data-osf-url') || '').trim().replace(/\/+$/, '');
    if (key === '' || base === '') { return; } // can't enhance; native POST stays
    form.setAttribute('data-osf-init', '1');

    var ui = form.getAttribute('data-osf-ui') !== 'none';
    var encKey = encodeURIComponent(key);
    var tokenUrl = base + '/v1/form/' + encKey + '/token';
    var submitUrl = base + '/v1/form/' + encKey + '/submit';

    if (!form.getAttribute('action')) { form.setAttribute('action', submitUrl); }
    if (!form.getAttribute('method')) { form.setAttribute('method', 'post'); }

    if (ui) { injectStyles(); }

    var root = el('div', { 'class': 'osf-root' });
    form.parentNode.insertBefore(root, form);
    root.appendChild(form);

    var state = { token: null, submitting: false, widget: null, cfToken: null, lastFocus: null };

    // The snippet's own hidden _osf_hp input protects no-JS posts too; reuse
    // it if present instead of adding a second field with the same name.
    var honeypot = form.querySelector('[name="_osf_hp"]');
    if (!honeypot) {
      honeypot = el('input', {
        type: 'text', name: '_osf_hp', 'class': 'osf-hp',
        tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true'
      });
      if (!ui) { honeypot.style.cssText = 'position:absolute;left:-9999px;width:1px;height:1px;opacity:0'; }
      form.appendChild(honeypot);
    }

    var live = el('div', { 'class': 'osf-vh', 'aria-live': 'polite', role: 'status' });
    root.appendChild(live);
    function announce(text) { live.textContent = text; }

    var formError = null;

    fetchToken(false);
    form.addEventListener('submit', onSubmit);

    function fetchToken(force) {
      if (state.token && !force) { return Promise.resolve(); }
      return fetch(tokenUrl, { method: 'GET', headers: { 'Accept': 'application/json' }, credentials: 'omit' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && data.ok && data.token) {
            state.token = data.token;
            if (data.turnstile && data.turnstile.sitekey && !state.widget) {
              setupTurnstile(data.turnstile.sitekey);
            }
          }
        })
        .catch(function () { /* token stays null; the server decides */ });
    }

    function setupTurnstile(sitekey) {
      var submit = findSubmit();
      var box = el('div', { 'class': 'osf-cf' });
      if (submit && submit.parentNode) {
        submit.parentNode.insertBefore(box, submit);
      } else {
        form.appendChild(box);
      }
      loadTurnstile().then(function () {
        if (!window.turnstile) { return; }
        state.widget = window.turnstile.render(box, {
          sitekey: sitekey,
          callback: function (t) { state.cfToken = t; },
          'expired-callback': function () { state.cfToken = null; },
          'error-callback': function () { state.cfToken = null; }
        });
      }).catch(function () { /* widget down; server fails open */ });
    }

    function resetTurnstile() {
      state.cfToken = null;
      if (window.turnstile && state.widget !== null) {
        try { window.turnstile.reset(state.widget); } catch (e) { /* ignore */ }
      }
    }

    function onSubmit(event) {
      try {
        event.preventDefault();
        if (state.submitting) { return; }
        clearErrors();
        dispatch(form, 'osf:submit', {});
        setSubmitting(true);
        announce('Sending…');
        send(false);
      } catch (e) {
        nativeFallback();
      }
    }

    function send(isRetry) {
      fetchToken(false).then(function () {
        return fetch(submitUrl, {
          method: 'POST',
          headers: { 'Accept': 'application/json' },
          body: serialize(),
          credentials: 'omit'
        });
      }).then(function (res) {
        return res.json().catch(function () { return null; }).then(function (data) {
          handleResult(data, isRetry);
        });
      }).catch(function () {
        setSubmitting(false);
        var msg = 'Could not reach the server. Please check your connection and try again.';
        dispatch(form, 'osf:error', { code: 'network_error', message: msg });
        announce(msg);
        if (ui) { showFormError(msg); }
      });
    }

    function serialize() {
      var params = new URLSearchParams();
      new FormData(form).forEach(function (value, name) {
        params.append(name, typeof value === 'string' ? value : '');
      });
      params.set('_osf_token', state.token || '');
      params.set('_osf_hp', honeypot.value || '');
      if (state.cfToken) { params.set('_osf_cf', state.cfToken); }
      return params;
    }

    function handleResult(data, isRetry) {
      if (data && data.ok === true) {
        setSubmitting(false);
        dispatch(form, 'osf:success', { data: data });
        announce('Message sent.');
        if (ui) { showSuccess(); }
        return;
      }

      var error = (data && data.error) || {};
      var code = error.code || 'error';
      var message = error.message || 'Something went wrong. Please try again.';

      // token_expired is handled INVISIBLY: refresh + one retry first.
      if (code === 'token_expired' && !isRetry) {
        state.token = null;
        fetchToken(true).then(function () { send(true); });
        return;
      }

      if (code === 'turnstile_failed' || code === 'turnstile_required') {
        resetTurnstile();
      }

      setSubmitting(false);
      dispatch(form, 'osf:error', { code: code, message: message });
      announce(message);
      if (ui) { showError(code, message); }
    }

    function findSubmit() {
      return form.querySelector('button[type=submit],input[type=submit],button:not([type])');
    }

    function setSubmitting(on) {
      state.submitting = on;
      var submit = findSubmit();
      if (!submit) { return; }
      if (on) {
        submit.disabled = true;
        submit.setAttribute('aria-busy', 'true');
        if (ui && !submit.querySelector('.osf-spinner')) {
          submit.insertBefore(el('span', { 'class': 'osf-spinner', 'aria-hidden': 'true' }), submit.firstChild);
        }
      } else {
        submit.disabled = false;
        submit.removeAttribute('aria-busy');
        var spin = submit.querySelector('.osf-spinner');
        if (spin) { spin.parentNode.removeChild(spin); }
      }
    }

    function clearErrors() {
      if (formError) { formError.parentNode.removeChild(formError); formError = null; }
      each(form.querySelectorAll('.osf-invalid'), function (node) {
        node.classList.remove('osf-invalid');
        node.removeAttribute('aria-invalid');
      });
      each(form.querySelectorAll('.osf-field-error'), function (node) {
        node.parentNode.removeChild(node);
      });
    }

    function showError(code, message) {
      if (code === 'invalid_email' || code === 'email_domain_invalid') {
        var field = form.querySelector('[name=email]');
        if (field) { showFieldError(field, message); return; }
      }
      showFormError(message);
    }

    function showFieldError(field, message) {
      field.classList.add('osf-invalid');
      field.setAttribute('aria-invalid', 'true');
      var note = el('p', { 'class': 'osf-field-error' }, message);
      if (field.parentNode) { field.parentNode.insertBefore(note, field.nextSibling); }
      try { field.focus(); } catch (e) { /* ignore */ }
    }

    function showFormError(message) {
      formError = el('div', { 'class': 'osf-form-error', role: 'alert' }, message);
      form.insertBefore(formError, form.firstChild);
    }

    function showSuccess() {
      state.lastFocus = document.activeElement;
      var titleId = 'osf-dlg-' + (++uid);

      var dialog = el('div', { 'class': 'osf-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId });
      dialog.appendChild(el('h2', { id: titleId }, 'Message sent'));
      dialog.appendChild(el('p', null, 'Thanks — your message has been sent.'));
      var ok = el('button', { type: 'button', 'class': 'osf-btn' }, 'OK');
      dialog.appendChild(ok);

      var overlay = el('div', { 'class': 'osf-overlay' });
      overlay.appendChild(dialog);
      root.appendChild(overlay);

      var release = trapFocus(dialog);
      ok.focus();

      function close() {
        release();
        if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
        showSubmittedPanel();
      }
      ok.addEventListener('click', close);
      dialog.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.keyCode === 27) { e.preventDefault(); close(); }
      });
    }

    function showSubmittedPanel() {
      form.style.display = 'none';
      var panel = el('div', { 'class': 'osf-submitted' });
      panel.appendChild(el('h2', null, 'Thanks!'));
      panel.appendChild(el('p', null, 'Your message has been sent.'));
      var again = el('button', { type: 'button', 'class': 'osf-btn' }, 'Send another');
      panel.appendChild(again);
      root.appendChild(panel);
      again.focus();

      again.addEventListener('click', function () {
        if (panel.parentNode) { panel.parentNode.removeChild(panel); }
        clearErrors();
        try { form.reset(); } catch (e) { /* ignore */ }
        form.style.display = '';
        resetTurnstile();
        fetchToken(true);
        var first = form.querySelector('input,textarea,select');
        if (first) { try { first.focus(); } catch (e2) { /* ignore */ } }
      });
    }

    function trapFocus(container) {
      function onKey(e) {
        if (e.key !== 'Tab' && e.keyCode !== 9) { return; }
        var f = container.querySelectorAll(
          'a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])'
        );
        if (!f.length) { return; }
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
      container.addEventListener('keydown', onKey);
      return function () {
        container.removeEventListener('keydown', onKey);
        var target = (state.lastFocus && state.lastFocus.focus) ? state.lastFocus
          : form.querySelector('input,textarea,select');
        if (target && target.focus) { try { target.focus(); } catch (e) { /* ignore */ } }
      };
    }

    function nativeFallback() {
      try {
        form.removeEventListener('submit', onSubmit);
        setSubmitting(false);
        if (typeof form.submit === 'function') { form.submit(); }
      } catch (e) { /* nothing safe left to do */ }
    }
  }

  function each(list, fn) {
    for (var i = 0; i < list.length; i++) { fn(list[i]); }
  }

  function init() {
    each(document.querySelectorAll('form[data-osf-key]'), function (form) {
      try {
        enhance(form);
      } catch (e) { /* a broken enhancement must never disable the form */ }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
