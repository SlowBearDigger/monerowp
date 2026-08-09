const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const widgetSource = fs.readFileSync(
    path.join(__dirname, '..', 'assets', 'js', 'monero-pay.js'),
    'utf8'
);

const address = '5'.repeat(95);

function loadWidget(options) {
    let Widget;
    let copiedValue = null;
    let fallbackCalls = 0;
    let nextTimer = 1;
    const appended = [];
    const pendingWrites = [];
    const timers = new Map();
    const body = {
        appendChild(node) {
            appended.push(node);
        },
        removeChild(node) {
            const index = appended.indexOf(node);
            if (index !== -1) appended.splice(index, 1);
        },
    };
    const document = {
        documentElement: { lang: options.lang || 'en' },
        activeElement: null,
        body: options.bodyMissing ? null : body,
        createElement() {
            const input = {
                value: '',
                style: {},
                setAttribute() {},
                focus() {
                    document.activeElement = input;
                },
                select() {
                    if (options.selectThrows) throw new Error('selection unavailable');
                },
                setSelectionRange() {
                    if (options.selectionThrows) throw new Error('range unavailable');
                },
            };
            return input;
        },
    };
    if (!options.execCommandMissing) {
        document.execCommand = function (command) {
            fallbackCalls++;
            assert.equal(command, 'copy');
            if (options.execCommandThrows) throw new Error('copy unavailable');
            if (appended[0]) copiedValue = appended[0].value;
            return options.fallbackSucceeds;
        };
    }

    const navigator = { language: 'en' };
    if (options.clipboard === 'resolve') {
        navigator.clipboard = {
            writeText(value) {
                copiedValue = value;
                return Promise.resolve();
            },
        };
    } else if (options.clipboard === 'reject') {
        navigator.clipboard = {
            writeText() {
                return Promise.reject(new Error('permission denied'));
            },
        };
    } else if (options.clipboard === 'throw') {
        navigator.clipboard = {
            writeText() {
                throw new Error('clipboard unavailable');
            },
        };
    } else if (options.clipboard === 'deferred') {
        navigator.clipboard = {
            writeText(value) {
                let resolvePromise;
                let rejectPromise;
                const promise = new Promise((resolve, reject) => {
                    resolvePromise = resolve;
                    rejectPromise = reject;
                });
                pendingWrites.push({
                    resolve() {
                        copiedValue = value;
                        resolvePromise();
                    },
                    reject() {
                        rejectPromise(new Error('permission denied'));
                    },
                });
                return promise;
            },
        };
    } else if (options.clipboard === 'partial') {
        navigator.clipboard = {};
    }

    const context = {
        HTMLElement: class {
            getAttribute() {
                return null;
            }
        },
        customElements: {
            get() {
                return null;
            },
            define(name, constructor) {
                assert.equal(name, 'monero-pay');
                Widget = constructor;
            },
        },
        document,
        navigator,
        location: { host: 'example.test', hostname: 'example.test' },
        setTimeout(callback) {
            const timer = nextTimer++;
            timers.set(timer, callback);
            return timer;
        },
        clearTimeout(timer) {
            timers.delete(timer);
        },
        encodeURIComponent,
    };
    context.window = context;
    vm.runInNewContext(widgetSource, context, { filename: 'monero-pay.js' });

    return {
        Widget,
        copiedValue: () => copiedValue,
        fallbackCalls: () => fallbackCalls,
        appended: () => appended.length,
        pendingWrites,
        timerCount: () => timers.size,
        runTimers() {
            const pending = Array.from(timers.values());
            timers.clear();
            pending.forEach((callback) => callback());
        },
    };
}

function wireAddress(options) {
    const loaded = loadWidget(options);
    const listeners = {};
    const attributes = { 'aria-label': 'Payment address: click to copy' };
    const statusClasses = new Set();
    const originalMarkup = '<b>55555555</b>…<b>55555555</b>';
    let markup = originalMarkup;
    let focusCalls = 0;
    const button = {
        addEventListener(event, listener) {
            listeners[event] = listener;
        },
        getAttribute(name) {
            return attributes[name];
        },
        setAttribute(name, value) {
            attributes[name] = value;
        },
        focus() {
            focusCalls++;
            if (options.buttonFocusThrows) throw new Error('focus unavailable');
        },
    };
    Object.defineProperties(button, {
        innerHTML: {
            get() {
                return markup;
            },
            set(value) {
                markup = value;
            },
        },
        textContent: {
            get() {
                return markup.replace(/<[^>]+>/g, '');
            },
            set(value) {
                markup = value;
            },
        },
    });
    const status = {
        textContent: '',
        classList: {
            toggle(name, enabled) {
                if (enabled) statusClasses.add(name);
                else statusClasses.delete(name);
            },
            contains(name) {
                return statusClasses.has(name);
            },
        },
    };
    const root = {
        querySelector(selector) {
            if (selector === '.addr') return button;
            if (selector === '.copy-status') return status;
            return null;
        },
    };
    const widget = new loaded.Widget();
    const translations = widget._t;
    widget._wire(root, address, translations);

    return {
        loaded,
        button,
        status,
        translations,
        attributes,
        originalMarkup,
        focusCalls: () => focusCalls,
        async click() {
            listeners.click();
            await Promise.resolve();
            await Promise.resolve();
        },
        async flush() {
            await Promise.resolve();
            await Promise.resolve();
        },
    };
}

async function clickAddress(options) {
    const wired = wireAddress(options);
    await wired.click();
    return wired;
}

function assertAddressPreserved(wired) {
    assert.equal(wired.button.innerHTML, wired.originalMarkup);
    assert.equal(wired.attributes['aria-label'], 'Payment address: click to copy');
}

function assertFailed(wired) {
    assert.equal(wired.status.textContent, wired.translations.copyFailed);
    assert.equal(wired.status.classList.contains('error'), true);
    assertAddressPreserved(wired);
}

async function run() {
    const success = await clickAddress({ clipboard: 'resolve', fallbackSucceeds: false });
    assert.equal(success.loaded.copiedValue(), address);
    assert.equal(success.status.textContent, 'Copied ✓');
    assert.equal(success.status.classList.contains('error'), false);
    assertAddressPreserved(success);

    const rejected = await clickAddress({ clipboard: 'reject', fallbackSucceeds: false });
    assert.equal(rejected.loaded.fallbackCalls(), 1);
    assert.equal(rejected.loaded.appended(), 0);
    assert.equal(rejected.focusCalls(), 1);
    assertFailed(rejected);

    const recovered = await clickAddress({ clipboard: 'reject', fallbackSucceeds: true });
    assert.equal(recovered.loaded.fallbackCalls(), 1);
    assert.equal(recovered.loaded.copiedValue(), address);
    assert.equal(recovered.loaded.appended(), 0);
    assert.equal(recovered.focusCalls(), 1);
    assert.equal(recovered.status.textContent, recovered.translations.copied);

    const fallback = await clickAddress({ clipboard: 'missing', fallbackSucceeds: true });
    assert.equal(fallback.loaded.fallbackCalls(), 1);
    assert.equal(fallback.loaded.copiedValue(), address);
    assert.equal(fallback.loaded.appended(), 0);
    assert.equal(fallback.focusCalls(), 1);
    assert.equal(fallback.status.textContent, fallback.translations.copied);

    for (const options of [
        { clipboard: 'missing', fallbackSucceeds: false, selectThrows: true },
        { clipboard: 'missing', fallbackSucceeds: false, selectionThrows: true },
        { clipboard: 'missing', fallbackSucceeds: false, execCommandThrows: true },
        { clipboard: 'missing', fallbackSucceeds: false, execCommandMissing: true },
        { clipboard: 'missing', fallbackSucceeds: false, bodyMissing: true },
        { clipboard: 'throw', fallbackSucceeds: false },
        { clipboard: 'partial', fallbackSucceeds: false },
    ]) {
        const failed = await clickAddress(options);
        assert.equal(failed.loaded.appended(), 0);
        assert.equal(failed.focusCalls(), 1);
        assertFailed(failed);
    }

    const focusFailure = await clickAddress({
        clipboard: 'missing',
        fallbackSucceeds: false,
        buttonFocusThrows: true,
    });
    assertFailed(focusFailure);

    const raced = wireAddress({ clipboard: 'deferred', fallbackSucceeds: false });
    await raced.click();
    await raced.click();
    assert.equal(raced.loaded.pendingWrites.length, 2);
    raced.loaded.pendingWrites[1].resolve();
    await raced.flush();
    assert.equal(raced.status.textContent, raced.translations.copied);
    assert.equal(raced.loaded.timerCount(), 1);
    raced.loaded.pendingWrites[0].reject();
    await raced.flush();
    assert.equal(raced.status.textContent, raced.translations.copied);
    assert.equal(raced.loaded.fallbackCalls(), 0);
    assert.equal(raced.focusCalls(), 0);
    assert.equal(raced.loaded.timerCount(), 1);
    assertAddressPreserved(raced);
    raced.loaded.runTimers();
    assert.equal(raced.status.textContent, '');
    assert.equal(raced.status.classList.contains('error'), false);
    assertAddressPreserved(raced);

    const spanish = await clickAddress({
        clipboard: 'missing',
        fallbackSucceeds: false,
        lang: 'es',
    });
    assert.equal(spanish.status.textContent, 'No se pudo copiar. Copia la dirección manualmente.');

    process.stdout.write('widget clipboard tests passed\n');
}

run().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
