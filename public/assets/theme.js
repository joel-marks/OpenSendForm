/*
 * OpenSendForm — theme application and manual toggle.
 *
 * Loaded as a BLOCKING script in <head> so the stored preference is applied
 * to <html> before first paint (no flash of the wrong theme). With no stored
 * choice we leave data-theme unset, so the browser's prefers-color-scheme
 * decides (Pico's automatic behaviour). The toggle button (wired on DOM
 * ready) cycles the explicit choice and persists it in localStorage.
 *
 * Progressive enhancement only: with JS disabled the page still renders in
 * the OS-preferred scheme; there is simply no manual override.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'osf-theme';
    var root = document.documentElement;

    function stored() {
        try {
            return window.localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return null;
        }
    }

    function persist(value) {
        try {
            if (value === null) {
                window.localStorage.removeItem(STORAGE_KEY);
            } else {
                window.localStorage.setItem(STORAGE_KEY, value);
            }
        } catch (e) {
            /* Private mode or storage disabled — degrade to session-only. */
        }
    }

    // Apply the stored theme immediately, before the body paints.
    var choice = stored();
    if (choice === 'light' || choice === 'dark') {
        root.setAttribute('data-theme', choice);
    }

    function currentScheme() {
        var attr = root.getAttribute('data-theme');
        if (attr === 'light' || attr === 'dark') {
            return attr;
        }
        // No explicit choice: fall back to what the OS prefers.
        return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
            ? 'dark'
            : 'light';
    }

    function apply(scheme) {
        root.setAttribute('data-theme', scheme);
        persist(scheme);
        updateButtons(scheme);
    }

    function updateButtons(scheme) {
        var buttons = document.querySelectorAll('[data-theme-toggle]');
        for (var i = 0; i < buttons.length; i++) {
            var next = scheme === 'dark' ? 'light' : 'dark';
            buttons[i].textContent = scheme === 'dark' ? '☀' : '☾';
            buttons[i].setAttribute('aria-label', 'Switch to ' + next + ' theme');
            buttons[i].setAttribute('title', 'Switch to ' + next + ' theme');
        }
    }

    function onReady() {
        var buttons = document.querySelectorAll('[data-theme-toggle]');
        updateButtons(currentScheme());
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', function () {
                apply(currentScheme() === 'dark' ? 'light' : 'dark');
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
})();
