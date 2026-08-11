import { beforeEach, describe, expect, test, vi } from 'vitest';

const appMarkup = `
    <div id="kosit-validator">
        <input id="kosit-xml-file" type="file">
        <button id="kosit-select-file-button" type="button">Datei auswählen</button>
        <div id="kosit-drop-zone"><span></span></div>
        <small id="kosit-selected-file">Keine Datei ausgewählt</small>
        <div id="kosit-status"></div>
        <div id="kosit-report-actions" hidden>
            <button id="kosit-print-button" disabled></button>
            <button id="kosit-download-button" disabled></button>
        </div>
        <iframe id="kosit-report-frame" style="display:none"></iframe>
    </div>
`;

const jsonResponse = (result, ok = true) => ({
    ok,
    json: vi.fn().mockResolvedValue(result),
});

const droppedFile = (name, content, size = content.length) => ({
    name,
    size,
    text: vi.fn().mockResolvedValue(content),
});

const drop = (files) => {
    const event = new Event('drop', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'dataTransfer', {
        value: { files, dropEffect: '' },
    });
    document.getElementById('kosit-drop-zone').dispatchEvent(event);
};

const loadApp = async () => {
    const addEventListener = vi.spyOn(document, 'addEventListener');
    vi.resetModules();
    await import('../../js/app.js');
    const initializer = addEventListener.mock.calls.findLast(([event]) => event === 'DOMContentLoaded')?.[1];
    addEventListener.mockRestore();
    initializer();
};

describe('validation UI', () => {
    beforeEach(() => {
        document.body.innerHTML = appMarkup;
    });

    test('opens the native file dialog from button and keyboard drop zone', async () => {
        const input = document.getElementById('kosit-xml-file');
        const click = vi.spyOn(input, 'click').mockImplementation(() => {});
        await loadApp();

        document.getElementById('kosit-select-file-button').click();
        document.getElementById('kosit-drop-zone').dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })
        );

        expect(click).toHaveBeenCalledTimes(2);
    });

    test('rejects multiple and oversized dropped files without a request', async () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        await loadApp();

        drop([droppedFile('one.xml', '<one/>'), droppedFile('two.xml', '<two/>')]);
        expect(document.getElementById('kosit-status').textContent)
            .toBe('Bitte genau eine XML-Datei ablegen.');

        drop([droppedFile('large.xml', '<xml/>', 5 * 1024 * 1024 + 1)]);
        expect(document.getElementById('kosit-selected-file').textContent).toBe('large.xml');
        expect(document.getElementById('kosit-status').textContent)
            .toBe('XML-Datei ist zu groß. Maximal erlaubt sind 5 MB.');
        expect(fetch).not.toHaveBeenCalled();
    });

    test('submits XML and displays a successful report', async () => {
        const fetch = vi.fn().mockResolvedValue(jsonResponse({
            success: true,
            valid: true,
            reportHtml: '<html><body>report</body></html>',
        }));
        vi.stubGlobal('fetch', fetch);
        await loadApp();

        drop([droppedFile('invoice.xml', '<Invoice/>')]);

        await vi.waitFor(() => {
            expect(document.getElementById('kosit-status').textContent).toBe('XML ist gültig.');
        });
        const [, options] = fetch.mock.calls[0];
        expect(fetch.mock.calls[0][0]).toBe('/nextcloud/apps/kosit_validator/validate');
        expect(options.method).toBe('POST');
        expect(options.credentials).toBe('same-origin');
        expect(options.headers.requesttoken).toBe('test-request-token');
        expect(options.body.get('xml')).toBe('<Invoice/>');
        expect(options.body.get('requesttoken')).toBe('test-request-token');

        const frame = document.getElementById('kosit-report-frame');
        expect(frame.srcdoc).toBe('<html><body>report</body></html>');
        expect(frame.style.display).toBe('block');
        frame.dispatchEvent(new Event('load'));
        expect(document.getElementById('kosit-report-actions').hidden).toBe(false);
        expect(document.getElementById('kosit-print-button').disabled).toBe(false);
        expect(document.getElementById('kosit-download-button').disabled).toBe(false);
        expect(document.getElementById('kosit-select-file-button').disabled).toBe(false);
        expect(document.getElementById('kosit-drop-zone').hasAttribute('aria-disabled')).toBe(false);
    });

    test('shows business rejection and missing report separately', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({
            success: true,
            valid: false,
            reportHtml: null,
        })));
        await loadApp();

        drop([droppedFile('invoice.xml', '<Invoice/>')]);

        await vi.waitFor(() => {
            expect(document.getElementById('kosit-status').textContent)
                .toBe('XML ist nicht gültig. Kein HTML-Prüfbericht gefunden.');
        });
        expect(document.getElementById('kosit-report-frame').style.display).toBe('none');
    });

    test('shows server message and restores controls after a failed response', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({
            success: false,
            message: 'Validator ist belegt',
        }, false)));
        await loadApp();

        drop([droppedFile('invoice.xml', '<Invoice/>')]);

        await vi.waitFor(() => {
            expect(document.getElementById('kosit-status').textContent).toBe('Validator ist belegt');
        });
        expect(document.getElementById('kosit-select-file-button').disabled).toBe(false);
        expect(document.getElementById('kosit-drop-zone').hasAttribute('aria-disabled')).toBe(false);
    });

    test('handles transport failures and can download the generated report', async () => {
        const fetch = vi.fn()
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValueOnce(jsonResponse({
                success: true,
                valid: true,
                reportHtml: '<html><body>download</body></html>',
            }));
        vi.stubGlobal('fetch', fetch);
        const createObjectURL = vi.fn().mockReturnValue('blob:report');
        const revokeObjectURL = vi.fn();
        Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: createObjectURL });
        Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: revokeObjectURL });
        const linkClick = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
        await loadApp();

        drop([droppedFile('first.xml', '<Invoice/>')]);
        await vi.waitFor(() => {
            expect(document.getElementById('kosit-status').textContent)
                .toBe('Fehler beim Aufruf des Validators.');
        });

        drop([droppedFile('second.xml', '<Invoice/>')]);
        await vi.waitFor(() => {
            expect(document.getElementById('kosit-status').textContent).toBe('XML ist gültig.');
        });
        document.getElementById('kosit-report-frame').dispatchEvent(new Event('load'));
        document.getElementById('kosit-download-button').click();

        expect(createObjectURL).toHaveBeenCalledOnce();
        expect(linkClick).toHaveBeenCalledOnce();
        await vi.waitFor(() => expect(revokeObjectURL).toHaveBeenCalledWith('blob:report'));
    });
});
