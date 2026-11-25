(() => {
    const editors = new WeakMap();
    const pending = new WeakMap();
    const documentImages = new WeakMap();
    const helpWindows = new Map();
    const scrolledTargets = new WeakMap();

    const slug = text => text.toLowerCase().replace(/[^\p{L}\p{N}_\s-]/gu, '').replace(/ /g, '-');
    const localLink = (viewer, href) => {
        if (!href || /^(?:[a-z][a-z0-9+.-]*:|\/\/|\/|#)/i.test(href)) return href;
        const source = new URL(viewer.dataset.document || 'README.md', 'https://documentation.invalid/');
        const target = new URL(href, source);
        if (target.pathname.startsWith('/img/')) {
            const imagePath = target.pathname.slice('/img/'.length);
            return typeof window.OC?.imagePath === 'function'
                ? window.OC.imagePath('inv_validator', imagePath)
                : 'https://raw.githubusercontent.com/322ud/inv_validator/HEAD' + target.pathname;
        }
        const documents = {'/README.md': 'readme', '/docs/admin.md': 'admin', '/docs/user.md': 'user', '/docs/developer.md': 'developer'};
        if (!documents[target.pathname]) {
            return 'https://github.com/322ud/inv_validator/blob/HEAD' + target.pathname + target.search + target.hash;
        }
        if (!viewer.dataset.documentUrl) return href;
        const destination = new URL(viewer.dataset.documentUrl, window.location.href);
        destination.searchParams.set('document', documents[target.pathname]);
        destination.hash = target.hash;
        return destination.pathname + destination.search + destination.hash;
    };
    const prepareNavigation = viewer => {
        const rich = viewer.querySelector('.kosit-readme-rich');
        const fallback = viewer.querySelector('.kosit-readme-fallback');
        // Keep Markdown source intact for Text while making fallback links usable.
        if (fallback && !fallback.dataset.linked) {
            fallback.dataset.linked = 'true';
            const walker = document.createTreeWalker(fallback, NodeFilter.SHOW_TEXT);
            const nodes = [];
            while (walker.nextNode()) nodes.push(walker.currentNode);
            for (const node of nodes) {
                const pattern = /\[([^\]]+)\]\(([^)]+)\)/g;
                let match, offset = 0;
                const fragment = document.createDocumentFragment();
                while ((match = pattern.exec(node.data))) {
                    const href = localLink(viewer, match[2]);
                    if (href === match[2] && !href.startsWith('#')) continue;
                    fragment.append(node.data.slice(offset, match.index));
                    const link = document.createElement('a');
                    link.href = href;
                    link.textContent = match[0];
                    fragment.append(link);
                    offset = pattern.lastIndex;
                }
                if (offset) {
                    fragment.append(node.data.slice(offset));
                    node.replaceWith(fragment);
                }
            }
        }
        const update = () => {
            rich?.querySelectorAll('a[href]').forEach(link => {
                const href = link.getAttribute('href');
                const picture = documentImages.get(viewer)?.get(href);
                if (picture) {
                    const img = document.createElement('img');
                    img.src = picture.src;
                    img.alt = picture.alt;
                    img.className = 'kosit-document-image';
                    img.addEventListener('error', () => {
                        link.textContent = picture.alt + ' – Bild konnte nicht geladen werden (öffnen)';
                    }, {once: true});
                    link.href = picture.src;
                    link.replaceChildren(img);
                    return;
                }
                const local = localLink(viewer, href);
                if (local !== href) link.setAttribute('href', local);
            });
        };
        const scroll = () => {
            let hash;
            try { hash = decodeURIComponent(window.location.hash.slice(1)); } catch { return; }
            if (!hash || viewer.closest('.kosit-readme-modal')) return;
            const root = rich && !rich.hidden ? rich : fallback;
            const previous = scrolledTargets.get(viewer);
            if (previous?.hash === hash && previous.root === root) return;
            const scrollTo = heading => {
                heading.scrollIntoView({block: 'start'});
                scrolledTargets.set(viewer, {hash, root});
            };
            if (root === rich && hash.startsWith('h-')) {
                // Use Text's actual anchor (and its scroll styling), not our heading slug.
                const anchor = [...rich.querySelectorAll('[id]')].find(node => node.id === hash);
                if (anchor) scrollTo(anchor);
                return;
            }
            // Plain Markdown has no Text-generated anchors; retain legacy links as well.
            const viewerAnchor = root === fallback && hash.startsWith('h-');
            if (viewerAnchor) hash = hash.slice(2);
            const headings = root?.querySelectorAll('h1,h2,h3,h4,h5,h6,[data-readme-heading]') || [];
            const counts = new Map();
            for (const heading of headings) {
                const copy = heading.cloneNode(true);
                copy.querySelectorAll('button,a').forEach(node => node.remove());
                const title = heading.dataset.readmeHeading || copy.textContent.trim();
                // Text's slugs transliterate German umlauts instead of retaining them.
                const base = slug(viewerAnchor
                    ? title.normalize('NFD').replace(/\p{M}/gu, '').replace(/ß/g, 'ss')
                    : title);
                const count = counts.get(base) || 0;
                counts.set(base, count + 1);
                if ((count ? base + (viewerAnchor ? '--' : '-') + count : base) === hash) {
                    scrollTo(heading);
                    break;
                }
            }
        };
        update();
        scroll();
        if (!viewer.navigationObserver) {
            viewer.navigationObserver = new MutationObserver(() => { update(); scroll(); });
            viewer.navigationObserver.observe(rich, {childList: true, subtree: true});
            const navigate = () => { scrolledTargets.delete(viewer); scroll(); };
            window.addEventListener('hashchange', navigate);
            window.addEventListener('kosit-help-focus', navigate);
        }
    };

    const mountReadme = async (viewer) => {
        if (!viewer || editors.has(viewer)) {
            return editors.get(viewer) || null;
        }

        if (viewer.closest('.kosit-readme-page')) {
            viewer.tabIndex = 0;
            viewer.setAttribute('role', 'region');
            viewer.setAttribute('aria-label', 'Dokumentation');
            viewer.focus({preventScroll: true});
        }
        prepareNavigation(viewer);
        const richContent = viewer.querySelector('.kosit-readme-rich');
        const fallback = viewer.querySelector('.kosit-readme-fallback');
        const createEditor = window.OCA?.Text?.createEditor;
        const accessibleLabel = richContent?.getAttribute('aria-label');

        if (!richContent || !fallback || typeof createEditor !== 'function') {
            return null;
        }

        const labelEditorInput = () => {
            const editorInput = richContent.querySelector('[role="textbox"]');

            if (!editorInput) {
                return false;
            }

            if (
                accessibleLabel
                && !editorInput.hasAttribute('aria-label')
                && !editorInput.hasAttribute('aria-labelledby')
                && !editorInput.hasAttribute('title')
            ) {
                editorInput.setAttribute('aria-label', accessibleLabel);
            }

            return true;
        };
        const editorInputObserver = new MutationObserver(() => {
            if (labelEditorInput()) {
                editorInputObserver.disconnect();
            }
        });

        editorInputObserver.observe(richContent, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['role'],
        });

        // Text's attachment loader needs a file context. App screenshots are
        // static assets, so render them as native images via temporary links.
        const pictures = new Map();
        documentImages.set(viewer, pictures);
        const markdown = fallback.textContent.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, (match, alt, href) => {
            const source = new URL(viewer.dataset.document || 'README.md', 'https://documentation.invalid/');
            const target = new URL(href, source);
            if (target.origin !== source.origin || !/^\/img\/[^/]+\.png$/i.test(target.pathname)) return match;
            const marker = '#kosit-document-image-' + pictures.size;
            pictures.set(marker, {alt, src: localLink(viewer, href)});
            return '[' + alt + '](' + marker + ')';
        });
        try {
            const editor = await createEditor({
                el: richContent,
                // Resolve against the Markdown document before Text can resolve links
                // against the administration page's URL.
                content: markdown.replace(/(\[[^\]]+\]\()([^)]+)(\))/g,
                    (_, start, href, end) => start + localLink(viewer, href) + end),
                readOnly: true,
                autofocus: false,
            });

            editor.setReadOnly(true);

            if (labelEditorInput()) {
                editorInputObserver.disconnect();
            }

            editors.set(viewer, editor);
            richContent.hidden = false;
            fallback.hidden = true;
            prepareNavigation(viewer);
            return editor;
        } catch {
            editorInputObserver.disconnect();
            richContent.replaceChildren();
            richContent.hidden = true;
            fallback.hidden = false;
            return null;
        }
    };

    const initializeReadme = (viewer) => {
        if (!viewer) return Promise.resolve(null);
        if (pending.has(viewer)) return pending.get(viewer);
        const task = mountReadme(viewer).finally(() => pending.delete(viewer));
        pending.set(viewer, task);
        return task;
    };

    function init() {
        const page = document.querySelector('.kosit-readme-page[data-document-title]');
        if (page) {
            const updateTitle = () => {
                if (page.isConnected && document.title !== page.dataset.documentTitle) {
                    document.title = page.dataset.documentTitle;
                }
            };
            updateTitle();
            // Nextcloud may reset the title after the Text viewer has initialized.
            const titleObserver = new MutationObserver(updateTitle);
            titleObserver.observe(document.head, {childList: true, subtree: true, characterData: true});
            window.addEventListener('kosit-help-focus', updateTitle);
        }
        document.querySelectorAll('.kosit-readme-page [data-kosit-readme]').forEach(viewer => {
            if (!viewer.closest('.kosit-readme-modal')) initializeReadme(viewer);
        });
        document.addEventListener('click', event => {
                const link = event.target.closest('[data-kosit-help]');
                if (!link) return;
                if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
                event.preventDefault();
                const url = new URL(link.href, window.location.href);
                if (url.origin !== window.location.origin) return;
                const document = url.searchParams.get('document');
                if (!['admin', 'user'].includes(document)) return;
                // Include the installation path so separate Nextcloud installations do not collide.
                const name = 'kosit-help-' + encodeURIComponent(url.pathname) + '-' + document;
                let help = helpWindows.get(name);
                if (!help || help.closed) {
                    help = window.open(url.href, name, 'popup,width=1024,height=800');
                    if (!help) return;
                    helpWindows.set(name, help);
                }
                try {
                    // Keep a reference from the GUI, but deny the help page reverse access.
                    help.opener = null;
                    if (help.location.href !== url.href) help.location.href = url.href;
                    // A repeated click on the same chapter must also restore its scroll position.
                    help.dispatchEvent(new help.Event('kosit-help-focus'));
                } catch {
                    // The user may have navigated the help window to another origin.
                    help.location.href = url.href;
                }
                help.focus();
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
    else init();

    window.OCA = window.OCA || {};
    window.OCA.INValidator = {
        ...window.OCA.INValidator,
        initializeReadme,
    };
})();
