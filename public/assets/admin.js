/*
 * OpenSendForm — admin progressive enhancements.
 *
 * Every feature here is optional: each screen is fully usable with JavaScript
 * disabled (plain inputs, normal form posts, selectable text). This file only
 * upgrades the experience when it runs. No inline handlers, no inline styles,
 * no network calls — served self-hosted so the admin Content-Security-Policy
 * passes untouched.
 *
 * Enhancements, each guarded by the presence of its target:
 *   [data-copy]                  — copy the given text to the clipboard.
 *   [data-totp-code]             — replace a plain 6-digit input with six boxes.
 *   [data-totp-recovery-toggle]  — also add a link that swaps the boxes for
 *                                  the plain input, for a recovery code.
 *   [data-qr]                    — render an otpauth URI as an SVG QR (needs qrcode.js).
 *   [data-recovery-codes]        — copy-all / download-as-txt / "saved" gate.
 */
(function () {
    'use strict';

    // --- Clipboard copy ------------------------------------------------
    function writeClipboard(text, button) {
        var done = function (ok) {
            if (!button) {
                return;
            }
            var original = button.getAttribute('data-copy-label') || button.textContent;
            button.setAttribute('data-copy-label', original);
            button.textContent = ok ? 'Copied' : 'Press Ctrl+C';
            window.setTimeout(function () {
                button.textContent = original;
            }, 1500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                done(true);
            }, function () {
                done(false);
            });
            return;
        }

        // Fallback: a temporary textarea + execCommand.
        try {
            var area = document.createElement('textarea');
            area.value = text;
            document.body.appendChild(area);
            area.select();
            var ok = document.execCommand('copy');
            document.body.removeChild(area);
            done(ok);
        } catch (e) {
            done(false);
        }
    }

    function initCopyButtons() {
        var buttons = document.querySelectorAll('[data-copy]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', function (event) {
                event.preventDefault();
                writeClipboard(this.getAttribute('data-copy'), this);
            });
        }
    }

    // --- Segmented TOTP inputs ----------------------------------------
    function initTotpBoxes() {
        var fields = document.querySelectorAll('[data-totp-code]');
        for (var i = 0; i < fields.length; i++) {
            buildBoxes(fields[i]);
        }
    }

    function buildBoxes(input) {
        var length = 6;
        var form = input.form;

        // Hide the original input but keep it in the DOM as the value carrier
        // and the field that is actually submitted.
        input.setAttribute('type', 'hidden');

        var wrap = document.createElement('div');
        wrap.className = 'osf-code-boxes';

        var boxes = [];
        for (var i = 0; i < length; i++) {
            var box = document.createElement('input');
            box.type = 'text';
            box.inputMode = 'numeric';
            box.autocomplete = i === 0 ? 'one-time-code' : 'off';
            box.setAttribute('maxlength', '1');
            box.setAttribute('aria-label', 'Digit ' + (i + 1));
            boxes.push(box);
            wrap.appendChild(box);
        }

        var fieldP = input.parentNode;
        fieldP.insertBefore(wrap, input);

        function sync() {
            var value = '';
            for (var j = 0; j < length; j++) {
                value += boxes[j].value;
            }
            input.value = value;
            return value;
        }

        function maybeSubmit(value) {
            if (value.length === length && form) {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
        }

        var attach = function (index) {
            var box = boxes[index];

            box.addEventListener('input', function () {
                // Keep digits only; a paste of many digits spreads rightwards.
                var digits = box.value.replace(/\D/g, '');
                if (digits.length <= 1) {
                    box.value = digits;
                    if (digits.length === 1 && index < length - 1) {
                        boxes[index + 1].focus();
                    }
                } else {
                    distribute(index, digits);
                }
                maybeSubmit(sync());
            });

            box.addEventListener('keydown', function (event) {
                if (event.key === 'Backspace' && box.value === '' && index > 0) {
                    boxes[index - 1].focus();
                    boxes[index - 1].value = '';
                    sync();
                } else if (event.key === 'ArrowLeft' && index > 0) {
                    boxes[index - 1].focus();
                } else if (event.key === 'ArrowRight' && index < length - 1) {
                    boxes[index + 1].focus();
                }
            });

            box.addEventListener('paste', function (event) {
                event.preventDefault();
                var text = (event.clipboardData || window.clipboardData).getData('text');
                distribute(index, text.replace(/\D/g, ''));
                maybeSubmit(sync());
            });
        };

        function distribute(start, digits) {
            for (var k = 0; k < digits.length && (start + k) < length; k++) {
                boxes[start + k].value = digits.charAt(k);
            }
            var last = Math.min(start + digits.length, length) - 1;
            if (last >= 0 && last < length) {
                boxes[Math.min(last + 1, length - 1)].focus();
            }
        }

        for (var b = 0; b < length; b++) {
            attach(b);
        }

        // Recovery-code fallback: the digit boxes only take one numeral each,
        // so a 10-character alphanumeric recovery code cannot be typed into
        // them. Swap them for the plain input itself (same name="code") on
        // request, rather than adding a second field or form.
        if (input.hasAttribute('data-totp-recovery-toggle')) {
            var label = fieldP.querySelector('label');
            var codeLabelText = label ? label.textContent : '';
            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'osf-link-button';
            toggle.textContent = 'Use a recovery code instead';
            fieldP.parentNode.insertBefore(toggle, fieldP.nextSibling);

            toggle.addEventListener('click', function (event) {
                event.preventDefault();
                var inRecoveryMode = input.getAttribute('type') === 'text';
                if (inRecoveryMode) {
                    input.setAttribute('type', 'hidden');
                    input.value = '';
                    for (var k = 0; k < length; k++) {
                        boxes[k].value = '';
                    }
                    wrap.hidden = false;
                    toggle.textContent = 'Use a recovery code instead';
                    if (label) {
                        label.textContent = codeLabelText;
                    }
                    boxes[0].focus();
                } else {
                    wrap.hidden = true;
                    input.value = '';
                    input.setAttribute('type', 'text');
                    toggle.textContent = 'Use authenticator code instead';
                    if (label) {
                        label.textContent = 'Recovery code';
                    }
                    input.focus();
                }
            });
        }

        // Carry any server-preserved value into the boxes.
        if (input.value) {
            distribute(0, input.value.replace(/\D/g, ''));
            sync();
        }

        boxes[0].focus();
    }

    // --- QR rendering --------------------------------------------------
    function initQr() {
        var targets = document.querySelectorAll('[data-qr]');
        if (targets.length === 0 || typeof window.qrcode !== 'function') {
            return;
        }
        for (var i = 0; i < targets.length; i++) {
            var uri = targets[i].getAttribute('data-qr');
            if (!uri) {
                continue;
            }
            var qr = window.qrcode(0, 'M');
            qr.addData(uri);
            qr.make();
            targets[i].innerHTML = qr.createSvgTag({
                cellSize: 4,
                margin: 2,
                scalable: true,
                alt: 'Two-factor authentication QR code'
            });
        }
    }

    // --- Recovery codes: copy-all, download, saved-gate ---------------
    function initRecovery() {
        var container = document.querySelector('[data-recovery-codes]');
        if (!container) {
            return;
        }

        var codes = [];
        var items = container.querySelectorAll('[data-recovery-code]');
        for (var i = 0; i < items.length; i++) {
            codes.push(items[i].textContent.trim());
        }
        var text = codes.join('\n') + '\n';

        var copyButton = document.querySelector('[data-recovery-copy]');
        if (copyButton) {
            copyButton.hidden = false;
            copyButton.addEventListener('click', function (event) {
                event.preventDefault();
                writeClipboard(text, copyButton);
            });
        }

        var downloadButton = document.querySelector('[data-recovery-download]');
        if (downloadButton) {
            downloadButton.hidden = false;
            downloadButton.addEventListener('click', function (event) {
                event.preventDefault();
                var blob = new Blob([text], { type: 'text/plain' });
                var url = URL.createObjectURL(blob);
                var link = document.createElement('a');
                link.href = url;
                link.download = 'opensendform-recovery-codes.txt';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.setTimeout(function () {
                    URL.revokeObjectURL(url);
                }, 1000);
            });
        }

        // Gate the continue link behind an explicit "I have saved these"
        // acknowledgement. The gate is JS-only enhancement: with no JS the
        // link is a normal, working link.
        var gate = document.querySelector('[data-recovery-gate]');
        var link = document.querySelector('[data-recovery-continue]');
        if (gate && link) {
            gate.hidden = false;
            var checkbox = gate.querySelector('input[type="checkbox"]');
            var setState = function () {
                if (checkbox.checked) {
                    link.removeAttribute('aria-disabled');
                    link.classList.remove('osf-disabled-link');
                } else {
                    link.setAttribute('aria-disabled', 'true');
                    link.classList.add('osf-disabled-link');
                }
            };
            link.addEventListener('click', function (event) {
                if (!checkbox.checked) {
                    event.preventDefault();
                }
            });
            checkbox.addEventListener('change', setState);
            setState();
        }
    }

    function onReady() {
        initCopyButtons();
        initTotpBoxes();
        initQr();
        initRecovery();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
})();
