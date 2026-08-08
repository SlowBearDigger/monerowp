/*! <monero-pay> Monero checkout component. No CDN or third-party requests. */
(function () {
    var XP_STR = {
        en: {
            sendExactly: 'Send exactly',
            anyAmount: 'Send any amount',
            scanToSend: 'Scan or tap to send',
            addrLabel: 'Payment address: click to copy',
            copied: 'Copied ✓',
            openWallet: 'Open in wallet',
            trustToggle: 'Non-custodial · verify this payment',
            trustFunds: 'Funds go directly to the merchant’s wallet. This page never holds your money.',
            trustAddr: 'Check the address. It must start and end with:',
            trustAddrHint: 'Copy it and confirm with the merchant for large payments.',
            trustLink: 'Check the link. You are on',
            disclaimer: 'Verify the address before sending. Monero payments are final and cannot be reversed. This widget is provided as-is, with no warranty.',
            foot: 'Non-custodial. Funds go directly to the merchant.',
            invalidAddress: 'Missing or invalid payment address',
            qrLabel: 'Monero payment QR code',
        },
        es: {
            sendExactly: 'Envía exactamente',
            anyAmount: 'Envía cualquier cantidad',
            scanToSend: 'Escanea o toca para enviar',
            addrLabel: 'Dirección de pago: clic para copiar',
            copied: 'Copiada ✓',
            openWallet: 'Abrir en wallet',
            trustToggle: 'No-custodial · verifica este pago',
            trustFunds: 'Los fondos van directo a la wallet del comerciante. Esta página nunca toca tu dinero.',
            trustAddr: 'Comprueba la dirección. Debe empezar y terminar con:',
            trustAddrHint: 'Cópiala y confírmala con el comerciante en pagos grandes.',
            trustLink: 'Comprueba el enlace. Estás en',
            disclaimer: 'Verifica la dirección antes de enviar. Los pagos en Monero son finales e irreversibles. Este widget se ofrece tal cual, sin garantía.',
            foot: 'No-custodial. Los fondos van directo al comerciante.',
            invalidAddress: 'Falta la dirección de pago o no es válida',
            qrLabel: 'Código QR de pago Monero',
        },
    };

    var XP_CSS = [
        ':host{--xp-accent:#FF6600;--xp-qr:#F26822;--xp-bg:#1b1b1f;--xp-fg:#f7f7f8;--xp-muted:#9b9ba4;',
        '--xp-border:#33333b;--xp-input:#26262d;--xp-yellow:#eab308;--xp-red:#f87171;',
        '--xp-radius:14px;--xp-radius-sm:9px;--xp-shadow:0 10px 30px rgba(0,0,0,.35);',
        '--xp-font:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;--xp-mono:ui-monospace,"SF Mono",SFMono-Regular,Menlo,Consolas,monospace;',
        'display:block;max-width:400px;font-family:var(--xp-font);}',
        ':host([theme=light]){--xp-bg:#fff;--xp-fg:#141417;--xp-muted:#6e6e78;--xp-border:#e5e5ea;--xp-input:#f4f4f6;--xp-shadow:0 10px 26px rgba(0,0,0,.10);}',
        '*{box-sizing:border-box;margin:0;}',
        '.card{background:var(--xp-bg);color:var(--xp-fg);border:1px solid var(--xp-border);border-radius:var(--xp-radius);box-shadow:var(--xp-shadow);overflow:hidden;}',
        '.hd{padding:14px 16px;border-bottom:1px solid var(--xp-border);}',
        '.lbl{font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--xp-muted);}',
        '.amt{font-size:26px;font-weight:800;color:var(--xp-accent);line-height:1.1;margin-top:3px;font-family:var(--xp-mono);}',
        '.st{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.01em;margin-top:7px;color:var(--xp-yellow);}',
        '.qrwrap{display:flex;justify-content:center;padding:16px 16px 10px;}',
        '.qr{background:#fff;padding:8px;border:1px solid #e8e8ec;border-radius:var(--xp-radius-sm);width:228px;height:228px;}',
        '.qr svg{display:block;width:100%;height:100%;}',
        '.sec{padding:0 16px 12px;}',
        '.addr{width:100%;background:var(--xp-input);border:1px solid var(--xp-border);border-radius:var(--xp-radius-sm);color:var(--xp-fg);font-family:var(--xp-mono);font-size:10.5px;text-align:left;padding:8px;word-break:break-all;cursor:pointer;line-height:1.5;}',
        '.addr:hover{background:var(--xp-accent);color:#fff;}',
        '.addr b{color:var(--xp-accent);font-weight:800;}.addr:hover b{color:#fff;}',
        '.wallet{display:block;text-align:center;margin-top:8px;background:var(--xp-accent);color:#fff;border-radius:var(--xp-radius-sm);font-size:12px;font-weight:700;text-decoration:none;padding:11px;}',
        '.wallet:hover{background:#e25c00;color:#fff;}',
        '.tgl{width:100%;background:none;border:0;border-top:1px solid var(--xp-input);color:var(--xp-muted);font-family:var(--xp-font);font-size:10px;font-weight:700;text-transform:uppercase;padding:10px 4px;cursor:pointer;display:flex;justify-content:space-between;gap:8px;}',
        '.tgl:hover{color:var(--xp-accent);}.tgl .car{transition:transform .15s;}.tgl.open .car{transform:rotate(180deg);}',
        '.fold{border:1px solid var(--xp-border);border-radius:var(--xp-radius-sm);padding:10px;margin-bottom:10px;font-size:10px;color:var(--xp-muted);line-height:1.55;}',
        '.fold p{margin-bottom:7px;}.fold b{color:var(--xp-fg);text-transform:uppercase;}',
        '.fp{display:block;border:1px solid var(--xp-border);border-radius:var(--xp-radius-sm);background:var(--xp-input);padding:6px 8px;margin:4px 0;color:var(--xp-fg);word-break:break-all;font-family:var(--xp-mono);}',
        '.fp b{color:var(--xp-accent);}',
        '.hint{margin-top:7px;font-size:9px;color:var(--xp-muted);line-height:1.5;}',
        '.foot{padding:10px 16px;border-top:1px solid var(--xp-input);font-size:9px;color:var(--xp-muted);text-align:center;}',
        '.hidden{display:none;}',
        ':host *:focus-visible{outline:2px solid var(--xp-accent);outline-offset:2px;}',
        '@media (prefers-reduced-motion:reduce){.tgl .car{transition:none;}}',
    ].join('');

    function xpEsc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
        });
    }

    function xpNormAmount(amount) {
        amount = String(amount == null ? '' : amount).trim();
        if (!/^\d+(\.\d{1,12})?$/.test(amount) || amount.indexOf('.') < 0) return amount;
        return amount.replace(/0+$/, '').replace(/\.$/, '');
    }

    function xpQrSvg(text, label) {
        var qr = window.MoneroWPQRCode(0, 'M');
        qr.addData(text);
        qr.make();
        var count = qr.getModuleCount();
        var scale = 4;
        var quiet = 2;
        var size = (count + quiet * 2) * scale;
        var rects = '';

        function isFinder(row, column) {
            return (row < 7 && column < 7) ||
                (row < 7 && column >= count - 7) ||
                (row >= count - 7 && column < 7);
        }

        for (var row = 0; row < count; row++) {
            for (var column = 0; column < count; column++) {
                if (!qr.isDark(row, column)) continue;
                rects += '<rect x="' + (column + quiet) * scale + '" y="' + (row + quiet) * scale +
                    '" width="' + scale + '" height="' + scale + '" fill="' +
                    (isFinder(row, column) ? '#000' : '#F26822') + '"/>';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + size + ' ' + size +
            '" shape-rendering="crispEdges" role="img" aria-label="' + xpEsc(label) + '">' + rects + '</svg>';
    }

    class MoneroPay extends HTMLElement {
        static get observedAttributes() {
            return ['address', 'amount', 'show-qr', 'label', 'theme', 'lang'];
        }

        connectedCallback() {
            this._render();
        }

        attributeChangedCallback() {
            if (this.isConnected) this._render();
        }

        get _t() {
            var requested = (this.getAttribute('lang') || document.documentElement.lang || navigator.language || 'en').toLowerCase();
            return requested.indexOf('es') === 0 ? XP_STR.es : XP_STR.en;
        }

        _uri(address, amount) {
            var params = [];
            var label = this.getAttribute('label');
            if (amount) params.push('tx_amount=' + encodeURIComponent(xpNormAmount(amount)));
            if (label) params.push('tx_description=' + encodeURIComponent(label));
            return 'monero:' + address + (params.length ? '?' + params.join('&') : '');
        }

        _render() {
            var t = this._t;
            var address = (this.getAttribute('address') || '').trim();
            var amount = (this.getAttribute('amount') || '').trim();
            var label = this.getAttribute('label') || '';
            var showQr = this.getAttribute('show-qr') !== 'no';
            var root = this.shadowRoot || this.attachShadow({ mode: 'open' });

            if (!/^[1-9A-HJ-NP-Za-km-z]{95}$/.test(address)) {
                root.innerHTML = '<style>' + XP_CSS + '</style><div class="card"><div class="hd">' +
                    '<div class="lbl">Monero</div><div class="st" style="color:var(--xp-red)">' +
                    xpEsc(t.invalidAddress) + '</div></div></div>';
                return;
            }

            var uri = this._uri(address, amount);
            var head = xpEsc(address.slice(0, 8));
            var middle = xpEsc(address.slice(8, -8));
            var tail = xpEsc(address.slice(-8));
            var host = location.host || location.hostname || '';

            root.innerHTML = '<style>' + XP_CSS + '</style>' +
                '<div class="card">' +
                '<div class="hd"><div class="lbl">' + (amount ? t.sendExactly : t.anyAmount) +
                (label ? ' · ' + xpEsc(label) : '') + '</div>' +
                (amount ? '<div class="amt">' + xpEsc(amount) + ' XMR</div>' : '') +
                '<div class="st">' + t.scanToSend + '</div></div>' +
                '<div class="body">' +
                (showQr ? '<div class="qrwrap"><div class="qr">' + xpQrSvg(uri, t.qrLabel) + '</div></div>' : '') +
                '<div class="sec"><div class="lbl" style="margin-bottom:4px">' + t.addrLabel + '</div>' +
                '<button class="addr" type="button" aria-label="' + xpEsc(t.addrLabel + ': ' + address) + '">' +
                '<b>' + head + '</b>' + middle + '<b>' + tail + '</b></button>' +
                '<a class="wallet" href="' + xpEsc(uri) + '">' + t.openWallet + '</a></div>' +
                '<div class="sec"><button class="tgl trust-toggle" type="button" aria-expanded="false" aria-controls="monero-pay-trust">' +
                '<span>⛨ ' + t.trustToggle + '</span><span class="car" aria-hidden="true">▾</span></button>' +
                '<div class="fold trust-body hidden" id="monero-pay-trust">' +
                '<p>' + t.trustFunds + '</p><p><b>' + t.trustAddr + '</b></p>' +
                '<span class="fp"><b>' + head + '</b> … <b>' + tail + '</b></span>' +
                '<p class="hint">' + t.trustAddrHint + '</p><p><b>' + t.trustLink + '</b> ' +
                '<span style="color:var(--xp-accent)">' + xpEsc(host) + '</span></p>' +
                '<p class="hint" style="border-top:1px solid var(--xp-input);padding-top:8px;margin-top:4px">' +
                t.disclaimer + '</p></div></div></div><div class="foot">' + t.foot + '</div></div>';

            this._wire(root, address, t);
        }

        _wire(root, address, t) {
            var addressButton = root.querySelector('.addr');
            if (addressButton) {
                addressButton.addEventListener('click', function () {
                    var original = addressButton.innerHTML;
                    var originalLabel = addressButton.getAttribute('aria-label');
                    var done = function () {
                        addressButton.textContent = t.copied;
                        addressButton.setAttribute('aria-label', t.copied);
                        setTimeout(function () {
                            addressButton.innerHTML = original;
                            addressButton.setAttribute('aria-label', originalLabel);
                        }, 1600);
                    };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(address).then(done, done);
                    } else {
                        done();
                    }
                });
            }

            var toggle = root.querySelector('.trust-toggle');
            if (toggle) {
                toggle.addEventListener('click', function () {
                    var open = toggle.classList.toggle('open');
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    var body = root.querySelector('.trust-body');
                    if (body) body.classList.toggle('hidden');
                });
            }
        }
    }

    if (!customElements.get('monero-pay')) customElements.define('monero-pay', MoneroPay);
})();
