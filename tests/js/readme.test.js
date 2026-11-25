import { beforeEach, describe, expect, test, vi } from 'vitest';

const markup = `
    <div data-kosit-readme>
        <div class="kosit-readme-rich" aria-label="Formatierter Inhalt der README" hidden></div>
        <pre class="kosit-readme-fallback"># README</pre>
    </div>
`;

const loadReadme = async () => {
    vi.resetModules();
    await import('../../js/readme.js');
    return window.OCA.INValidator.initializeReadme;
};

describe('README rich text integration', () => {
    beforeEach(() => {
        document.body.innerHTML = markup;
        window.OCA = {};
    });

    test('uses the read-only Nextcloud Text editor when available', async () => {
        const editor = { setReadOnly: vi.fn() };
        const createEditor = vi.fn().mockImplementation(({ el }) => {
            const editorInput = document.createElement('div');
            editorInput.setAttribute('role', 'textbox');
            el.appendChild(editorInput);
            return Promise.resolve(editor);
        });
        window.OCA.Text = { createEditor };
        const initializeReadme = await loadReadme();
        const viewer = document.querySelector('[data-kosit-readme]');

        await initializeReadme(viewer);

        expect(createEditor).toHaveBeenCalledWith({
            el: viewer.querySelector('.kosit-readme-rich'),
            content: '# README',
            readOnly: true,
            autofocus: false,
        });
        expect(editor.setReadOnly).toHaveBeenCalledWith(true);
        expect(viewer.querySelector('[role="textbox"]').getAttribute('aria-label'))
            .toBe('Formatierter Inhalt der README');
        expect(viewer.querySelector('.kosit-readme-rich').hidden).toBe(false);
        expect(viewer.querySelector('.kosit-readme-fallback').hidden).toBe(true);
    });

    test('preserves an accessible name supplied by Nextcloud Text', async () => {
        const editor = { setReadOnly: vi.fn() };
        const createEditor = vi.fn().mockImplementation(({ el }) => {
            const editorInput = document.createElement('div');
            editorInput.setAttribute('role', 'textbox');
            editorInput.setAttribute('aria-label', 'Nextcloud Text');
            el.appendChild(editorInput);
            return Promise.resolve(editor);
        });
        window.OCA.Text = { createEditor };
        const initializeReadme = await loadReadme();
        const viewer = document.querySelector('[data-kosit-readme]');

        await initializeReadme(viewer);

        expect(viewer.querySelector('[role="textbox"]').getAttribute('aria-label'))
            .toBe('Nextcloud Text');
    });

    test('labels the editor input when Nextcloud Text renders it asynchronously', async () => {
        const editor = { setReadOnly: vi.fn() };
        const createEditor = vi.fn().mockResolvedValue(editor);
        window.OCA.Text = { createEditor };
        const initializeReadme = await loadReadme();
        const viewer = document.querySelector('[data-kosit-readme]');

        await initializeReadme(viewer);

        const editorInput = document.createElement('div');
        editorInput.setAttribute('role', 'textbox');
        viewer.querySelector('.kosit-readme-rich').appendChild(editorInput);

        await vi.waitFor(() => {
            expect(editorInput.getAttribute('aria-label'))
                .toBe('Formatierter Inhalt der README');
        });
    });

    test('resolves documentation and screenshots locally before mounting Text', async () => {
        const viewer = document.querySelector('[data-kosit-readme]');
        viewer.dataset.document = 'README.md';
        viewer.dataset.documentUrl = '/nextcloud/apps/inv_validator/documentation';
        viewer.querySelector('pre').textContent = '[Admin](docs/admin.md#java-installieren)\n![Start](img/startpage.png)';
        window.OC.imagePath = vi.fn(() => '/nextcloud/apps/inv_validator/img/startpage.png');
        const createEditor = vi.fn(async ({ el }) => {
            el.innerHTML = '<a href="#kosit-document-image-0">Start</a>';
            return { setReadOnly: vi.fn() };
        });
        window.OCA.Text = { createEditor };
        const initializeReadme = await loadReadme();
        await initializeReadme(viewer);
        expect(createEditor.mock.calls[0][0].content).toContain('/documentation?document=admin#java-installieren');
        expect(viewer.querySelector('img').getAttribute('src')).toBe('/nextcloud/apps/inv_validator/img/startpage.png');
        expect(viewer.querySelector('img').alt).toBe('Start');
        expect(viewer.querySelector('pre a').getAttribute('href')).toContain('/documentation?document=admin');
    });

    test('opens a local chapter in the plaintext fallback without Text', async () => {
        const viewer = document.querySelector('[data-kosit-readme]');
        viewer.querySelector('pre').innerHTML = '<span data-readme-heading="Java installieren">### Java installieren</span>';
        const heading = viewer.querySelector('span');
        heading.scrollIntoView = vi.fn();
        window.history.replaceState({}, '', '#java-installieren');
        const initializeReadme = await loadReadme();
        await initializeReadme(viewer);
        expect(heading.scrollIntoView).toHaveBeenCalled();
        window.history.replaceState({}, '', window.location.pathname);
    });

    test('keeps the plaintext fallback when Nextcloud Text is unavailable', async () => {
        const initializeReadme = await loadReadme();
        const viewer = document.querySelector('[data-kosit-readme]');

        expect(await initializeReadme(viewer)).toBeNull();
        expect(viewer.querySelector('.kosit-readme-rich').hidden).toBe(true);
        expect(viewer.querySelector('.kosit-readme-fallback').hidden).toBe(false);
    });
});
