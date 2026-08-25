/*
 * OpenSendForm — installer progressive enhancements.
 *
 * Optional, like the admin ones: with JavaScript disabled the installer is
 * fully usable and the MySQL details simply stay visible (the server-rendered
 * default). No inline handlers, no network calls — served self-hosted so the
 * installer's strict Content-Security-Policy passes untouched.
 *
 * Enhancement:
 *   [data-db-driver]     — the database-type radios (sqlite / mysql).
 *   [data-mysql-details] — the MySQL fields, shown only while "mysql" is picked.
 */
(function () {
    'use strict';

    function initDatabaseToggle() {
        var details = document.querySelector('[data-mysql-details]');
        var radios = document.querySelectorAll('[data-db-driver]');
        if (!details || radios.length === 0) {
            return;
        }

        function sync() {
            var mysql = false;
            for (var i = 0; i < radios.length; i++) {
                if (radios[i].checked && radios[i].value === 'mysql') {
                    mysql = true;
                }
            }
            details.hidden = !mysql;
        }

        for (var i = 0; i < radios.length; i++) {
            radios[i].addEventListener('change', sync);
        }
        sync();
    }

    function onReady() {
        initDatabaseToggle();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
})();
