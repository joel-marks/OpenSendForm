/*
 * OpenSendForm — theme bootstrap.
 *
 * Loaded SYNCHRONOUSLY as the first element in <head> so the stored colour
 * preference is applied to <html> before the first paint (no flash of the
 * wrong theme). It only reads storage and sets two attributes; the toggle UI
 * lives in the deferred admin.js.
 *
 * Attributes set on <html>:
 *   data-theme      — the RESOLVED scheme ("dark" | "light"); tokens.css keys
 *                     the light palette off [data-theme="light"], dark is the
 *                     :root default.
 *   data-theme-mode — the user's CHOICE ("dark" | "light" | "auto"); drives
 *                     which toggle icon is shown (sun/moon/monitor) via CSS.
 *
 * Mode: default is dark (no stored value). The toggle cycles dark -> light ->
 * auto; "auto" follows prefers-color-scheme. Persisted in localStorage under
 * the key 'osf-theme'. External file (no inline script) so the strict admin
 * CSP script-src 'self' stays untouched.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'osf-theme';
    var root = document.documentElement;

    var mode;
    try {
        mode = window.localStorage.getItem(STORAGE_KEY);
    } catch (e) {
        mode = null;
    }
    if (mode !== 'dark' && mode !== 'light' && mode !== 'auto') {
        mode = 'dark'; // default
    }

    var scheme = mode;
    if (mode === 'auto') {
        scheme = (window.matchMedia &&
            window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }

    root.setAttribute('data-theme', scheme);
    root.setAttribute('data-theme-mode', mode);
})();
