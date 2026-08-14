import { beforeEach, describe, expect, test, vi } from 'vitest';

const adminMarkup = `
    <div id="kosit-admin">
        <table><tbody id="kosit-paths-body">
            <tr><td><input class="kosit-path-input" name="javaBinary" value="/old/java"></td></tr>
        </tbody></table>
        <span id="kosit-validator-installed-version"></span>
        <span id="kosit-validator-latest-version"></span>
        <span id="kosit-configuration-installed-version"></span>
        <span id="kosit-configuration-latest-version"></span>
        <p id="kosit-update-summary"></p>
        <p id="kosit-admin-message"></p>
        <span id="kosit-pdf-availability"></span>
        <span id="kosit-python-version"></span>
        <span id="kosit-pymupdf-version"></span>
        <p id="kosit-pdf-reason"></p>
        <button id="kosit-save-paths"></button>
        <button id="kosit-check-update"></button>
        <button id="kosit-check-pdf"></button>
        <button id="kosit-install"></button>
    </div>
`;

const status = (overrides = {}) => ({
    paths: [
        { label: 'Java', key: 'javaBinary', path: '/usr/bin/java', state: 'available' },
        { label: 'Szenario-Datei', path: '/opt/kosit/xrechnung/current/scenarios.xml', state: 'available' },
    ],
    validator: {
        installed: true,
        installedVersion: '1.6.1',
        latestVersion: '1.6.2',
        updateAvailable: true,
        updateError: null,
    },
    configuration: {
        installed: true,
        installedVersion: '2025-07-10',
        installedXRechnungVersion: '3.0.2',
        latestVersion: '2026-01-31',
        latestXRechnungVersion: '3.0.2',
        updateAvailable: true,
        updateError: null,
    },
    pdfSupport: {
        available: false,
        pythonVersion: '3.12.0',
        pymupdfVersion: null,
        reason: 'PyMuPDF fehlt.',
    },
    ...overrides,
});

const response = (body, httpStatus = 200) => ({
    ok: httpStatus >= 200 && httpStatus < 300,
    status: httpStatus,
    text: vi.fn().mockResolvedValue(typeof body === 'string' ? body : JSON.stringify(body)),
});

const loadAdmin = async () => {
    const addEventListener = vi.spyOn(document, 'addEventListener');
    vi.resetModules();
    await import('../../js/admin.js');
    const initializer = addEventListener.mock.calls.findLast(([event]) => event === 'DOMContentLoaded')?.[1];
    addEventListener.mockRestore();
    initializer();
};

describe('admin UI', () => {
    beforeEach(() => {
        document.body.innerHTML = adminMarkup;
    });

    test('saves paths and safely refreshes both component versions', async () => {
        const resultStatus = status({
            paths: [{ label: '<img src=x onerror=alert(1)>', key: 'javaBinary', path: '/new/java', state: 'available' }],
            validator: { ...status().validator, installedVersion: '1.6.2', updateAvailable: false },
            configuration: { ...status().configuration, installedVersion: '2026-01-31', updateAvailable: false },
        });
        const fetch = vi.fn().mockResolvedValue(response({ success: true, message: 'Pfade gespeichert', status: resultStatus }));
        vi.stubGlobal('fetch', fetch);
        await loadAdmin();

        document.getElementById('kosit-save-paths').click();

        await vi.waitFor(() => expect(document.getElementById('kosit-admin-message').textContent).toBe('Pfade gespeichert'));
        expect(fetch.mock.calls[0][1].headers.requesttoken).toBe('test-request-token');
        expect(fetch.mock.calls[0][1].body.get('javaBinary')).toBe('/old/java');
        expect(document.querySelector('#kosit-paths-body img')).toBeNull();
        expect(document.getElementById('kosit-validator-installed-version').textContent).toBe('1.6.2');
        expect(document.getElementById('kosit-configuration-installed-version').textContent)
            .toBe('XRechnung 3.0.2 / 2026-01-31');
        expect(document.getElementById('kosit-update-summary').textContent).toBe('Die Installation ist aktuell.');
    });

    test('installs both components and restores action buttons after errors', async () => {
        const current = status({
            validator: { ...status().validator, installedVersion: '1.6.2', updateAvailable: false },
            configuration: { ...status().configuration, installedVersion: '2026-01-31', updateAvailable: false },
        });
        const fetch = vi.fn()
            .mockResolvedValueOnce(response({ success: true, message: 'Installiert', status: current }))
            .mockRejectedValueOnce(new Error('offline'));
        vi.stubGlobal('fetch', fetch);
        await loadAdmin();

        document.getElementById('kosit-install').click();
        await vi.waitFor(() => expect(document.getElementById('kosit-admin-message').textContent).toBe('Installiert'));
        expect(document.getElementById('kosit-install').textContent).toBe('Komponenten aktualisieren');
        expect(document.getElementById('kosit-install').disabled).toBe(false);

        document.getElementById('kosit-install').click();
        await vi.waitFor(() => expect(document.getElementById('kosit-admin-message').textContent).toBe('offline'));
        expect(document.getElementById('kosit-check-update').disabled).toBe(false);
    });

    test('reports component-specific update errors', async () => {
        const failed = status({
            validator: { ...status().validator, updateError: 'Validator nicht erreichbar' },
            configuration: { ...status().configuration, updateError: 'Konfiguration nicht erreichbar' },
        });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({ success: true, status: failed })));
        await loadAdmin();

        document.getElementById('kosit-check-update').click();

        await vi.waitFor(() => expect(document.getElementById('kosit-admin-message').textContent)
            .toBe('Die Update-Prüfung konnte nicht abgeschlossen werden.'));
        expect(document.getElementById('kosit-update-summary').textContent)
            .toContain('Validator nicht erreichbar / Konfiguration nicht erreichbar');
    });

    test('checks optional PDF support again', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({
            success: true,
            pdfSupport: { available: true, pythonVersion: '3.13.5', pymupdfVersion: '1.26.4', reason: null },
        })));
        await loadAdmin();

        document.getElementById('kosit-check-pdf').click();

        await vi.waitFor(() => expect(document.getElementById('kosit-pdf-availability').textContent).toBe('Verfügbar'));
    });
});
