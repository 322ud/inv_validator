import { beforeEach, describe, expect, test, vi } from 'vitest';

const adminMarkup = `
    <div id="kosit-admin">
        <table><tbody id="kosit-paths-body">
            <tr><td><input class="kosit-path-input" name="javaBinary" value="/old/java"></td></tr>
            <tr><td><input class="kosit-path-input" name="validatorJar" value="/old/validator.jar"></td></tr>
        </tbody></table>
        <span id="kosit-installed-version"></span>
        <span id="kosit-latest-version"></span>
        <p id="kosit-update-summary"></p>
        <p id="kosit-admin-message"></p>
        <button id="kosit-save-paths"></button>
        <button id="kosit-check-update"></button>
        <button id="kosit-install"></button>
        <button id="kosit-edit-scenario"></button>
        <p id="kosit-scenario-message"></p>
        <section id="kosit-scenario-editor" hidden>
            <code id="kosit-scenario-path"></code>
            <textarea id="kosit-scenario-content"></textarea>
            <button id="kosit-save-scenario"></button>
            <button id="kosit-cancel-scenario"></button>
        </section>
    </div>
`;

const status = (overrides = {}) => ({
    paths: [
        { label: 'Java', key: 'javaBinary', path: '/usr/bin/java', state: 'available' },
        { label: 'App', path: '/apps/kosit', state: 'available' },
    ],
    installed: true,
    installedVersion: '1.0.0',
    latestVersion: '2.0.0',
    updateAvailable: true,
    updateError: null,
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

    test('saves paths, sends CSRF header and safely refreshes the view', async () => {
        const fetch = vi.fn().mockResolvedValue(response({
            success: true,
            message: 'Pfade gespeichert',
            status: status({
                paths: [{
                    label: '<img src=x onerror=alert(1)>',
                    key: 'javaBinary',
                    path: '/new/java',
                    state: 'available',
                }],
            }),
        }));
        vi.stubGlobal('fetch', fetch);
        await loadAdmin();

        document.getElementById('kosit-save-paths').click();

        await vi.waitFor(() => {
            expect(document.getElementById('kosit-admin-message').textContent).toBe('Pfade gespeichert');
        });
        const [, options] = fetch.mock.calls[0];
        expect(fetch.mock.calls[0][0]).toBe('/nextcloud/apps/kosit_validator/admin/paths');
        expect(options.method).toBe('POST');
        expect(options.credentials).toBe('same-origin');
        expect(options.headers.requesttoken).toBe('test-request-token');
        expect(options.body.get('javaBinary')).toBe('/old/java');
        expect(options.body.get('validatorJar')).toBe('/old/validator.jar');
        expect(document.querySelector('#kosit-paths-body img')).toBeNull();
        expect(document.getElementById('kosit-paths-body').textContent)
            .toContain('<img src=x onerror=alert(1)>');
        expect(document.getElementById('kosit-save-paths').disabled).toBe(false);
    });

    test('reports malformed and unsuccessful server responses and restores the button', async () => {
        const fetch = vi.fn()
            .mockResolvedValueOnce(response('<html>error</html>', 502))
            .mockResolvedValueOnce(response({ success: false, message: 'Speichern fehlgeschlagen' }, 400));
        vi.stubGlobal('fetch', fetch);
        await loadAdmin();

        document.getElementById('kosit-save-paths').click();
        await vi.waitFor(() => {
            expect(document.getElementById('kosit-admin-message').textContent)
                .toBe('Unerwartete Serverantwort (HTTP 502).');
        });
        document.getElementById('kosit-save-paths').click();
        await vi.waitFor(() => {
            expect(document.getElementById('kosit-admin-message').textContent)
                .toBe('Speichern fehlgeschlagen');
        });
        expect(document.getElementById('kosit-save-paths').disabled).toBe(false);
    });

    test('loads a read-only scenario and supports cancelling the editor', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({
            success: true,
            scenario: {
                path: '/config/scenario.xml',
                content: '<scenarios/>',
                checksum: 'a'.repeat(64),
                writable: false,
            },
        })));
        await loadAdmin();

        document.getElementById('kosit-edit-scenario').click();

        await vi.waitFor(() => {
            expect(document.getElementById('kosit-scenario-editor').hidden).toBe(false);
        });
        expect(document.getElementById('kosit-scenario-path').textContent).toBe('/config/scenario.xml');
        expect(document.getElementById('kosit-scenario-content').value).toBe('<scenarios/>');
        expect(document.getElementById('kosit-scenario-content').readOnly).toBe(true);
        expect(document.getElementById('kosit-save-scenario').disabled).toBe(true);

        document.getElementById('kosit-cancel-scenario').click();
        expect(document.getElementById('kosit-scenario-editor').hidden).toBe(true);
        expect(document.getElementById('kosit-scenario-content').value).toBe('');
    });

    test('uses the checksum returned by the previous scenario save', async () => {
        const fetch = vi.fn()
            .mockResolvedValueOnce(response({
                success: true,
                scenario: {
                    path: '/config/scenario.xml',
                    content: '<scenarios id="one"/>',
                    checksum: 'a'.repeat(64),
                    writable: true,
                },
            }))
            .mockResolvedValueOnce(response({
                success: true,
                message: 'Gespeichert',
                scenario: {
                    content: '<scenarios id="two"/>',
                    checksum: 'b'.repeat(64),
                },
            }))
            .mockResolvedValueOnce(response({
                success: true,
                message: 'Erneut gespeichert',
                scenario: {
                    content: '<scenarios id="three"/>',
                    checksum: 'c'.repeat(64),
                },
            }));
        vi.stubGlobal('fetch', fetch);
        await loadAdmin();

        document.getElementById('kosit-edit-scenario').click();
        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalledTimes(1);
            expect(document.getElementById('kosit-scenario-editor').hidden).toBe(false);
            expect(document.getElementById('kosit-save-scenario').disabled).toBe(false);
        });
        document.getElementById('kosit-scenario-content').value = '<scenarios id="two"/>';
        document.getElementById('kosit-save-scenario').click();
        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalledTimes(2);
            expect(document.getElementById('kosit-save-scenario').disabled).toBe(false);
        });
        document.getElementById('kosit-scenario-content').value = '<scenarios id="three"/>';
        document.getElementById('kosit-save-scenario').click();
        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalledTimes(3);
            expect(document.getElementById('kosit-save-scenario').disabled).toBe(false);
        });

        expect(fetch.mock.calls[1][1].body.get('action')).toBe('saveScenario');
        expect(fetch.mock.calls[1][1].body.get('checksum')).toBe('a'.repeat(64));
        expect(fetch.mock.calls[2][1].body.get('checksum')).toBe('b'.repeat(64));
        expect(document.getElementById('kosit-scenario-content').value)
            .toBe('<scenarios id="three"/>');
        expect(document.getElementById('kosit-save-scenario').disabled).toBe(false);
        expect(document.getElementById('kosit-cancel-scenario').disabled).toBe(false);
    });

    test('updates installation state and always restores both action buttons', async () => {
        const fetch = vi.fn()
            .mockResolvedValueOnce(response({
                success: true,
                message: 'Installiert',
                status: status({ installedVersion: '2.0.0', updateAvailable: false }),
            }))
            .mockRejectedValueOnce(new Error('offline'));
        vi.stubGlobal('fetch', fetch);
        await loadAdmin();

        document.getElementById('kosit-install').click();
        expect(document.getElementById('kosit-install').disabled).toBe(true);
        expect(document.getElementById('kosit-check-update').disabled).toBe(true);
        await vi.waitFor(() => {
            expect(document.getElementById('kosit-admin-message').textContent).toBe('Installiert');
        });
        expect(document.getElementById('kosit-installed-version').textContent).toBe('2.0.0');
        expect(document.getElementById('kosit-update-summary').textContent)
            .toBe('Die Installation ist aktuell.');
        expect(document.getElementById('kosit-install').disabled).toBe(false);
        expect(document.getElementById('kosit-check-update').disabled).toBe(false);

        document.getElementById('kosit-install').click();
        await vi.waitFor(() => {
            expect(document.getElementById('kosit-admin-message').textContent).toBe('offline');
        });
        expect(document.getElementById('kosit-install').disabled).toBe(false);
        expect(document.getElementById('kosit-check-update').disabled).toBe(false);
    });

    test('refreshes update status and distinguishes update-check errors', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({
            success: true,
            status: status({ updateError: 'GitHub nicht erreichbar' }),
        })));
        await loadAdmin();

        document.getElementById('kosit-check-update').click();

        await vi.waitFor(() => {
            expect(document.getElementById('kosit-admin-message').textContent)
                .toBe('Die Update-Prüfung konnte nicht abgeschlossen werden.');
        });
        expect(document.getElementById('kosit-update-summary').textContent)
            .toBe('Update-Prüfung fehlgeschlagen: GitHub nicht erreichbar');
        expect(document.getElementById('kosit-check-update').disabled).toBe(false);
    });
});
