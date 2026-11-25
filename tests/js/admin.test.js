import { beforeEach, describe, expect, test, vi } from 'vitest';

const markup = `
<div id="kosit-admin" data-help-sections='{"java":"java-installieren","validator":"kosit-validator-und-xrechnung-konfiguration-installieren"}'>
<form id="kosit-settings-form">
<input type="checkbox" name="validator" role="switch">
<input type="checkbox" name="configuration" role="switch">
</form>
<form id="kosit-paths-form">
<input name="javaBinary" value="/usr/bin/java"><input name="pythonBinary" value="/usr/bin/python3">
<button type="submit" id="kosit-save-paths">Pfade speichern</button>
</form>
<button id="kosit-check-system">Prüfen</button>

<table><tbody data-group="requirements"></tbody></table>
<table><tbody data-group="validation"><tr><td><button data-component="validator">Update auf 1.6.2</button></td></tr></tbody></table>
<table><tbody data-group="pdf"></tbody></table>
<table><tbody data-group="installation"></tbody></table>
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

    test('conditionally shows Python, writable paths and independent update switches', async () => {
        const status = { autoUpdates: { validator: false, configuration: true }, checks: [
            ...checks,
            { id: 'configuration', ok: true, version: '3' },
            { id: 'zip', ok: true, version: '1' },
            { id: 'validator-write', ok: true, detail: '/opt/kosit' },
            { id: 'configuration-write', ok: false, detail: '/opt/kosit/xrechnung' },
            { id: 'pymupdf', ok: true, version: '1' },
            { id: 'python', ok: true, version: '3' },
        ] };
        vi.stubGlobal('fetch', vi.fn().mockImplementation(async () => response({ success: true, status })));
        await loadAdmin();
        document.getElementById('kosit-check-system').click();
        await waitMessage('Systemprüfung abgeschlossen.');
        expect(document.querySelector('[data-check="python"]')).not.toBeNull();
        expect(document.querySelector('[data-check="validator"] input').checked).toBe(false);
        expect(document.querySelector('[data-check="configuration"] input')).toBeNull();
        expect(document.querySelector('[data-check="configuration-write"]').textContent).toContain('kein Schreibrecht');
        status.checks.find(check => check.id === 'zip').ok = false;
        status.checks.find(check => check.id === 'pymupdf').ok = false;
        document.getElementById('kosit-check-system').click();
        await vi.waitFor(() => expect(document.querySelector('[data-check="python"]')).toBeNull());
        expect(document.querySelector('[data-check="validator-write"]')).toBeNull();
        expect(document.querySelector('[data-check="validator"] input')).toBeNull();
        expect(document.querySelector('[data-check="java"]').textContent).toContain('>= v11 nicht verfügbar');
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
        expect(document.querySelector('#kosit-admin img')).toBeNull();
        expect(document.querySelector('[data-check="validator"]').textContent).toContain('1.6.2');
        expect(document.querySelector('[data-check="validator"] button')).toBeNull();
        const help = document.querySelector('[data-check="java"] td:last-child a');
        expect(help.href).toContain('/docs/admin.md#java-installieren');
        expect(help.textContent).toBe('i');
        expect(help.target).toBe('_blank');
        expect(help.rel).toBe('noopener noreferrer');
        expect(help.getAttribute('aria-label')).toContain('Java');
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

});
