(() => {
    const editors = new WeakMap();

    const initializeReadme = async (viewer) => {
        if (!viewer || editors.has(viewer)) {
            return editors.get(viewer) || null;
        }

        const richContent = viewer.querySelector('.kosit-readme-rich');
        const fallback = viewer.querySelector('.kosit-readme-fallback');
        const createEditor = window.OCA?.Text?.createEditor;

        if (!richContent || !fallback || typeof createEditor !== 'function') {
            return null;
        }

        try {
            const editor = await createEditor({
                el: richContent,
                content: fallback.textContent,
                readOnly: true,
                autofocus: false,
            });

            editor.setReadOnly(true);
            editors.set(viewer, editor);
            richContent.hidden = false;
            fallback.hidden = true;
            return editor;
        } catch {
            richContent.replaceChildren();
            richContent.hidden = true;
            fallback.hidden = false;
            return null;
        }
    };

    window.OCA = window.OCA || {};
    window.OCA.KoSITValidator = {
        ...window.OCA.KoSITValidator,
        initializeReadme,
    };
})();
