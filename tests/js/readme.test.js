import { beforeEach, describe, expect, test, vi } from 'vitest';

const markup = `
    <div data-kosit-readme>
        <div class="kosit-readme-rich" hidden></div>
        <pre class="kosit-readme-fallback"># README</pre>
    </div>
`;

const loadReadme = async () => {
    vi.resetModules();
    await import('../../js/readme.js');
    return window.OCA.KoSITValidator.initializeReadme;
};

describe('README rich text integration', () => {
    beforeEach(() => {
        document.body.innerHTML = markup;
        window.OCA = {};
    });

    test('uses the read-only Nextcloud Text editor when available', async () => {
        const editor = { setReadOnly: vi.fn() };
        const createEditor = vi.fn().mockResolvedValue(editor);
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
        expect(viewer.querySelector('.kosit-readme-rich').hidden).toBe(false);
        expect(viewer.querySelector('.kosit-readme-fallback').hidden).toBe(true);
    });

    test('keeps the plaintext fallback when Nextcloud Text is unavailable', async () => {
        const initializeReadme = await loadReadme();
        const viewer = document.querySelector('[data-kosit-readme]');

        expect(await initializeReadme(viewer)).toBeNull();
        expect(viewer.querySelector('.kosit-readme-rich').hidden).toBe(true);
        expect(viewer.querySelector('.kosit-readme-fallback').hidden).toBe(false);
    });
});
