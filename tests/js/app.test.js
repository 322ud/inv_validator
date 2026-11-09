import { beforeEach, describe, expect, test, vi } from 'vitest';

const appMarkup = `
    <div id="kosit-scroll-container"><div id="inv-validator">
        <input id="kosit-xml-file" type="file">
        <button id="kosit-select-file-button" type="button">Datei auswählen</button>
        <div id="kosit-status"></div>
        <a id="kosit-readme-open" href="#kosit-readme-dialog">README</a>
        <div id="kosit-readme-dialog" hidden>
            <button id="kosit-readme-close" type="button">Schließen</button>
        </div>
        <div id="kosit-report-actions" hidden>
            <button id="kosit-print-button" disabled></button>
            <button id="kosit-download-button" disabled></button>
        </div>
        <iframe id="kosit-report-frame" style="display:none"></iframe>
    </div></div>
`;

const jsonResponse = (result, ok = true) => ({
    ok,
    json: vi.fn().mockResolvedValue(result),
});

const droppedFile = (name, content, size = content.length) => {
    const type = name.endsWith('.pdf') ? 'application/pdf' : 'application/xml';
    const file = new File([content], name, { type });

    if (size !== file.size) {
        Object.defineProperty(file, 'size', { value: size });
    }

    return file;
};

const drop = (files, target = document.getElementById('kosit-scroll-container')) => {
    const event = new Event('drop', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'dataTransfer', {
        value: { files, dropEffect: '' },
    });
    target.dispatchEvent(event);
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

    test('replaces existing favicons with the app SVG and preserves other head links', async () => {
        const original = document.createElement('link');
        original.rel = 'shortcut icon';
        original.href = '/theming/favicon.ico';
        const touch = document.createElement('link');
        touch.rel = 'apple-touch-icon';
        touch.href = '/touch.png';
        document.head.append(original, touch);
        document.getElementById('inv-validator').dataset.faviconUrl = '/apps/inv_validator/img/app.svg?v=1';
        try {
            await loadApp();
            const icons = document.head.querySelectorAll('link[rel~="icon"]');
            expect(icons).toHaveLength(1);
            expect(icons[0].getAttribute('href')).toBe('/apps/inv_validator/img/app.svg?v=1');
            expect(icons[0].type).toBe('image/svg+xml');
            expect(touch.isConnected).toBe(true);
        } finally {
            document.head.querySelectorAll('link[rel~="icon"]').forEach(link => link.remove());
            touch.remove();
        }
    });

    test('opens the native file dialog only from the select button', async () => {
        const input = document.getElementById('kosit-xml-file');
        const click = vi.spyOn(input, 'click').mockImplementation(() => {});
        await loadApp();

        document.getElementById('kosit-select-file-button').click();
        document.getElementById('kosit-scroll-container').click();
        document.getElementById('kosit-readme-open').click();

        expect(click).toHaveBeenCalledTimes(1);
    });

    test('highlights the whole page while dragging across its children', async () => {
        await loadApp();
        const zone = document.getElementById('kosit-scroll-container');
        const button = document.getElementById('kosit-select-file-button');
        const enter = new Event('dragenter', { bubbles: true, cancelable: true });
        Object.defineProperty(enter, 'dataTransfer', { value: { dropEffect: '' } });
        button.dispatchEvent(enter);
        expect(enter.defaultPrevented).toBe(true);
        expect(zone.classList.contains('is-dragover')).toBe(true);

        button.dispatchEvent(new MouseEvent('dragleave', { bubbles: true, relatedTarget: zone }));
        expect(zone.classList.contains('is-dragover')).toBe(true);
        zone.dispatchEvent(new MouseEvent('dragleave', { bubbles: true }));
        expect(zone.classList.contains('is-dragover')).toBe(false);
    });

    test('ignores additional drops while validation is running', async () => {
        let resolveResponse;
        const fetch = vi.fn().mockReturnValue(new Promise(resolve => { resolveResponse = resolve; }));
        vi.stubGlobal('fetch', fetch);
        await loadApp();
        const file = droppedFile('invoice.xml', '<Invoice/>');
        drop([file]);
        expect(document.getElementById('kosit-select-file-button').disabled).toBe(true);
        drop([file], document.getElementById('kosit-select-file-button'));
        expect(fetch).toHaveBeenCalledTimes(1);
        resolveResponse(jsonResponse({ success: true, valid: true, reportHtml: null }));
        await vi.waitFor(() => {
            expect(document.getElementById('kosit-select-file-button').disabled).toBe(false);
        });
    });

    test('opens and closes the README dialog', async () => {
        const dialog = document.getElementById('kosit-readme-dialog');
        await loadApp();

        expect(dialog.hidden).toBe(true);
        document.getElementById('kosit-readme-open').click();
        expect(dialog.hidden).toBe(false);

        document.getElementById('kosit-readme-close').click();
        expect(dialog.hidden).toBe(true);

        document.getElementById('kosit-readme-open').click();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(dialog.hidden).toBe(true);
    });

    test('rejects multiple and oversized dropped files without a request', async () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        await loadApp();

        drop([droppedFile('one.xml', '<one/>'), droppedFile('two.xml', '<two/>')]);
        expect(document.getElementById('kosit-status').textContent)
            .toBe('Bitte genau eine XML-Datei ablegen.');

        drop([droppedFile('large.xml', '<xml/>', 5 * 1024 * 1024 + 1)]);
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

        drop([droppedFile('invoice.xml', '<Invoice/>')], document.getElementById('inv-validator'));

        await vi.waitFor(() => {
            expect(document.getElementById('kosit-status').textContent).toBe('XML ist gültig.');
        });
        const [, options] = fetch.mock.calls[0];
        expect(fetch.mock.calls[0][0]).toBe('/nextcloud/apps/inv_validator/validate');
        expect(options.method).toBe('POST');
        expect(options.credentials).toBe('same-origin');
        expect(options.headers.requesttoken).toBe('test-request-token');
        expect(options.body.get('document').name).toBe('invoice.xml');
        expect(options.body.get('requesttoken')).toBe('test-request-token');

        const frame = document.getElementById('kosit-report-frame');
        expect(frame.srcdoc).toBe('<html><body>report</body></html>');
        expect(frame.style.display).toBe('block');
        frame.dispatchEvent(new Event('load'));
        expect(document.getElementById('kosit-report-actions').hidden).toBe(false);
        expect(document.getElementById('kosit-print-button').disabled).toBe(false);
        expect(document.getElementById('kosit-download-button').disabled).toBe(false);
        expect(document.getElementById('kosit-select-file-button').disabled).toBe(false);
        expect(document.getElementById('kosit-scroll-container').hasAttribute('aria-busy')).toBe(false);
    });

    test('rejects PDF when the server capability is disabled', async () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        await loadApp();

        drop([droppedFile('invoice.pdf', '%PDF-fake')]);
        expect(document.getElementById('kosit-status').textContent)
            .toBe('PDF-Eingabe ist auf diesem Server nicht verfügbar.');
        expect(fetch).not.toHaveBeenCalled();
    });

    test('submits PDF when the server capability is enabled', async () => {
        const fetch = vi.fn().mockResolvedValue(jsonResponse({
            success: true,
            valid: true,
            documentType: 'pdf',
            reportHtml: null,
        }));
        vi.stubGlobal('fetch', fetch);
        document.getElementById('inv-validator').dataset.pdfSupported = 'true';
        await loadApp();
        drop([droppedFile('invoice.pdf', '%PDF-fake')]);

        await vi.waitFor(() => {
            expect(document.getElementById('kosit-status').textContent)
                .toBe('Eingebettetes XML ist gültig. Kein HTML-Prüfbericht gefunden.');
        });
        expect(fetch.mock.calls[0][1].body.get('document').name).toBe('invoice.pdf');
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

    test('ignores the transient empty iframe document while replacing a report', async () => {
        await loadApp();

        const frame = document.getElementById('kosit-report-frame');
        Object.defineProperty(frame, 'contentDocument', {
            configurable: true,
            value: {
                body: null,
                documentElement: null,
                head: null,
            },
        });
        frame.style.display = 'block';

        expect(() => frame.dispatchEvent(new Event('load'))).not.toThrow();
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
        expect(document.getElementById('kosit-scroll-container').hasAttribute('aria-busy')).toBe(false);
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
