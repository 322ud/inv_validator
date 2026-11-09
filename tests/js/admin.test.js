import { beforeEach, describe, expect, test, vi } from 'vitest';

const markup = `
<div id="kosit-admin">
<form id="kosit-settings-form">
<input type="checkbox" name="validator" role="switch">
<input type="checkbox" name="configuration" role="switch">
</form>
<form id="kosit-paths-form">
<input name="javaBinary" value="/usr/bin/java"><input name="pythonBinary" value="/usr/bin/python3">
<button type="submit" id="kosit-save-paths">Pfade speichern</button>
</form>
<button id="kosit-check-system">Prüfen</button>
<table><tbody id="kosit-system-body"><tr><td><button data-component="validator">Update auf 1.6.2</button></td></tr></tbody></table>
<ul id="kosit-write-checks"></ul>
<div id="kosit-managed-components"></div><p id="kosit-admin-message"></p>
<div data-kosit-readme><div class="kosit-readme-rich" hidden></div><pre class="kosit-readme-fallback"><span id="kosit-help-java" data-heading="Java installieren">Java installieren</span></pre></div>
</div>`;
const checks = [
    { id: 'java', label: 'Java', ok: false, version: '8', detail: '/usr/bin/java', help: 'java' },
    { id: 'validator', label: 'Validator', ok: true, version: '1.6.2', detail: '/opt/kosit/validator.jar', action: null },
];
const response = (body, httpStatus = 200) => ({
    ok: httpStatus >= 200 && httpStatus < 300,
    status: httpStatus,
    text: vi.fn().mockResolvedValue(typeof body === 'string' ? body : JSON.stringify(body)),
});
const loadAdmin = async () => {
    const listener = vi.spyOn(document, 'addEventListener');
    vi.resetModules();
    await import('../../js/readme.js');
    await import('../../js/admin.js');
    const initialize = listener.mock.calls.findLast(([event]) => event === 'DOMContentLoaded')[1];
    listener.mockRestore();
    initialize();
};
const waitMessage = async (text) => {
    await vi.waitFor(() => expect(document.getElementById('kosit-admin-message').textContent).toBe(text));
};

describe('admin system UI', () => {
    beforeEach(() => { document.body.innerHTML = markup; window.OCA = {}; });

    test('saves independent switches with a CSRF token', async () => {
        const fetch = vi.fn().mockResolvedValue(response({ success: true, message: 'Gespeichert' }));
        vi.stubGlobal('fetch', fetch);
        await loadAdmin();
        document.querySelector('[name="validator"]').click();
        await waitMessage('Gespeichert');
        const [url, options] = fetch.mock.calls[0];
        expect(url).toBe('/nextcloud/apps/inv_validator/admin/settings');
        expect(options.body.get('validator')).toBe('yes');
        expect(options.body.get('configuration')).toBe('no');
        expect(options.headers.requesttoken).toBe('test-request-token');
    });

    test('restores a switch after saving fails and allows retrying', async () => {
        const fetch = vi.fn().mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValueOnce(response({ success: true, message: 'Gespeichert' }));
        vi.stubGlobal('fetch', fetch);
        await loadAdmin();
        const toggle = document.querySelector('[name="configuration"]');
        toggle.click();
        await waitMessage('offline');
        expect(toggle.checked).toBe(false);
        expect(toggle.disabled).toBe(false);
        toggle.click();
        await waitMessage('Gespeichert');
        expect(toggle.checked).toBe(true);
        expect(fetch.mock.calls[1][1].body.get('configuration')).toBe('yes');
    });

    test('separates writable paths and renders status symbols and compact help', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({ success: true, status: { checks: [
            ...checks,
            { id: 'temp', label: 'Temporäres Verzeichnis', ok: true, detail: '/tmp/validator', help: 'paths' },
            { id: 'configuration-write', label: 'Konfigurationsinstallation', ok: false, detail: '/opt/kosit/xrechnung', help: 'paths' },
        ] } })));
        await loadAdmin();
        document.getElementById('kosit-check-system').click();
        await waitMessage('Systemprüfung abgeschlossen.');
        expect(document.querySelector('#kosit-system-body [data-check="temp"]')).toBeNull();
        const entries = document.querySelectorAll('#kosit-write-checks li');
        expect(entries).toHaveLength(2);
        expect(entries[0].textContent).toContain('Temporäres Verzeichnis (/tmp/validator)');
        expect(entries[0].firstElementChild.getAttribute('aria-label')).toBe('Bereit');
        expect(entries[1].firstElementChild.getAttribute('aria-label')).toBe('Nicht bereit');
        const help = entries[1].querySelector('a');
        expect(help.textContent.trim()).toBe('Info');
        expect(help.querySelector('svg')).not.toBeNull();
        expect(help.hash).toBe('#kosit-help-paths');
        expect(document.querySelector('[data-check="java"] td:first-child [role="img"]').textContent).toBe('✕');
    });

    test('saves paths and renders versions, problems and help without HTML injection', async () => {
        const fetch = vi.fn().mockResolvedValue(response({ success: true, message: 'Gespeichert', status: {
            checks: [{ ...checks[0], detail: '<img src=x onerror=alert(1)>' }, checks[1]],
        } }));
        vi.stubGlobal('fetch', fetch);
        await loadAdmin();
        document.getElementById('kosit-save-paths').click();
        await waitMessage('Gespeichert');
        expect(fetch.mock.calls[0][1].body.get('javaBinary')).toBe('/usr/bin/java');
        expect(document.querySelector('#kosit-system-body img')).toBeNull();
        expect(document.querySelector('[data-check="validator"]').textContent).toContain('1.6.2');
        expect(document.querySelector('[data-check="validator"] button')).toBeNull();
        expect(document.querySelector('[data-check="java"] a').hash).toBe('#kosit-help-java');
    });

    test('installs only the requested component and removes the update button after success', async () => {
        const fetch = vi.fn().mockResolvedValue(response({ success: true, message: 'Installiert', status: { checks } }));
        vi.stubGlobal('fetch', fetch);
        await loadAdmin();
        document.querySelector('[data-component]').click();
        await waitMessage('Installiert');
        expect(fetch.mock.calls[0][1].body.get('component')).toBe('validator');
        expect(document.querySelector('[data-component]')).toBeNull();
    });

    test('shows an available version on the update button and offers installation for missing components', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({ success: true, status: { checks: [
            { ...checks[1], action: 'update', latestVersion: '1.6.3' },
            { ...checks[0], id: 'configuration', action: 'install', version: null },
        ] } })));
        await loadAdmin();
        document.getElementById('kosit-check-system').click();
        await waitMessage('Systemprüfung abgeschlossen.');
        expect(document.querySelector('[data-component="validator"]').textContent).toBe('Update auf 1.6.3');
        expect(document.querySelector('[data-component="configuration"]').textContent).toBe('Installieren');
    });

    test('recovers from network and non-JSON errors', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValueOnce(response('<html>Gateway</html>', 502)));
        await loadAdmin();
        document.getElementById('kosit-check-system').click();
        await waitMessage('offline');
        expect(document.getElementById('kosit-check-system').disabled).toBe(false);
        document.getElementById('kosit-check-system').click();
        await waitMessage('Unerwartete Serverantwort (HTTP 502).');
    });

    test('requires a separate enabled switch for each recorded component', async () => {
        const managedComponents = ['validator', 'configuration'].map((component) => ({
            component, label: component, version: '1', lastOperationAt: '2026-11-06T13:00:00+00:00',
            paths: [`/opt/kosit/${component}`], recordToken: `${component}-record`,
        }));
        const fetch = vi.fn()
            .mockResolvedValueOnce(response({ success: true, status: { checks, managedComponents } }))
            .mockResolvedValueOnce(response({ success: true, message: 'Entfernt', status: { checks, managedComponents: [managedComponents[1]] } }));
        vi.stubGlobal('fetch', fetch);
        await loadAdmin();
        document.getElementById('kosit-check-system').click();
        await waitMessage('Systemprüfung abgeschlossen.');
        const form = document.querySelector('[data-remove-component="validator"]');
        expect(form.elements.confirmed.checked).toBe(false);
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        expect(fetch).toHaveBeenCalledTimes(1);
        form.elements.confirmed.checked = true;
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await waitMessage('Entfernt');
        expect(fetch.mock.calls[1][0]).toBe('/nextcloud/apps/inv_validator/admin/components/remove');
        expect(fetch.mock.calls[1][1].headers.requesttoken).toBe('test-request-token');
        expect(fetch.mock.calls[1][1].body.get('confirmed')).toBe('1');
        expect(fetch.mock.calls[1][1].body.get('component')).toBe('validator');
        expect(fetch.mock.calls[1][1].body.get('recordToken')).toBe('validator-record');
        expect(document.querySelector('[data-remove-component="configuration"]')).not.toBeNull();
        expect(document.querySelector('[data-remove-component="configuration"] [name="confirmed"]').checked).toBe(false);
    });

    test('help links navigate to the solution in the embedded README', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({ success: true, status: { checks } })));
        await loadAdmin();
        document.getElementById('kosit-check-system').click();
        await waitMessage('Systemprüfung abgeschlossen.');
        const scroll = vi.fn();
        document.getElementById('kosit-help-java').scrollIntoView = scroll;
        document.querySelector('[data-readme-help]').click();
        await vi.waitFor(() => expect(scroll).toHaveBeenCalledOnce());
    });
    test('help keeps formatted Markdown and ignores Nextcloud heading decorations', async () => {
        const scroll = vi.fn();
        window.OCA.Text = { createEditor: vi.fn(async ({ el }) => {
            el.innerHTML = '<div class="ProseMirror"><h3><span>###</span>Java installieren<button>Link kopieren</button></h3><p><strong>Formatierte Hilfe</strong></p></div>';
            el.querySelector('h3').scrollIntoView = scroll;
            return { setReadOnly: vi.fn() };
        }) };
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({ success: true, status: { checks } })));
        await loadAdmin();
        document.getElementById('kosit-check-system').click();
        await waitMessage('Systemprüfung abgeschlossen.');
        document.querySelector('[data-readme-help]').click();
        await vi.waitFor(() => expect(scroll).toHaveBeenCalledOnce());
        expect(document.querySelector('.kosit-readme-rich').hidden).toBe(false);
        expect(document.querySelector('.kosit-readme-fallback').hidden).toBe(true);
        expect(document.querySelector('.kosit-readme-rich strong').textContent).toBe('Formatierte Hilfe');
    });

    test('help waits for initialization and headings rendered later', async () => {
        const scroll = vi.fn();
        let finishEditor;
        window.OCA.Text = { createEditor: vi.fn(() => new Promise((resolve) => { finishEditor = resolve; })) };
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({ success: true, status: { checks } })));
        await loadAdmin();
        document.getElementById('kosit-check-system').click();
        await waitMessage('Systemprüfung abgeschlossen.');
        document.querySelector('[data-readme-help]').click();
        finishEditor({ setReadOnly: vi.fn() });
        await vi.waitFor(() => expect(document.querySelector('.kosit-readme-rich').hidden).toBe(false));
        const heading = document.createElement('h3');
        heading.innerHTML = '<span aria-hidden="true">###</span>Java installieren';
        heading.scrollIntoView = scroll;
        document.querySelector('.kosit-readme-rich').append(heading);
        await vi.waitFor(() => expect(scroll).toHaveBeenCalledOnce());
        expect(document.querySelector('.kosit-readme-fallback').hidden).toBe(true);
    });

    test('missing formatted heading never exposes raw Markdown', async () => {
        vi.useFakeTimers();
        const scroll = vi.fn();
        const rich = document.querySelector('.kosit-readme-rich');
        rich.scrollIntoView = scroll;
        window.OCA.Text = { createEditor: vi.fn(async () => ({ setReadOnly: vi.fn() })) };
        const help = document.createElement('a');
        help.href = '#kosit-help-java';
        help.dataset.readmeHelp = '';
        document.getElementById('kosit-admin').append(help);
        await loadAdmin();
        help.click();
        await vi.advanceTimersByTimeAsync(2100);
        expect(scroll).toHaveBeenCalledOnce();
        expect(rich.hidden).toBe(false);
        expect(document.querySelector('.kosit-readme-fallback').hidden).toBe(true);
    });

});
