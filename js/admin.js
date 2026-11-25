document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('kosit-admin');
    if (!root) {
        return;
    }
    const message = root.querySelector('#kosit-admin-message');
    let autoUpdates = JSON.parse(root.querySelector('#kosit-settings-form').dataset.autoUpdates || 'null') || Object.fromEntries(['validator', 'configuration'].map(key =>
        [key, root.querySelector(`[name="${key}"]`)?.checked || false]));
    const viewer = root.querySelector('[data-kosit-readme]');
    window.OCA?.INValidator?.initializeReadme(viewer);
    let busy = false;

    const request = async (url, options = {}) => {
        const response = await fetch(OC.generateUrl(`/apps/inv_validator/admin/${url}`), {
            credentials: 'same-origin',
            ...options,
            headers: { requesttoken: OC.requestToken, ...options.headers },
        });
        let result;
        try {
            result = JSON.parse(await response.text());
        } catch {
            throw new Error(`Unerwartete Serverantwort (HTTP ${response.status}).`);
        }
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Die Aktion ist fehlgeschlagen.');
        }
        return result;
    };

    const updateManagedComponents = (components) => {
        const container = root.querySelector('#kosit-managed-components');
        if (!container) {
            return;
        }
        container.replaceChildren();
        if (!components.length) {
            container.textContent = 'Keine offenen Installationshinweise vorhanden.';
        }
        for (const component of components) {
            const form = document.createElement('form');
            form.className = 'kosit-remove-component';
            form.dataset.removeComponent = component.component;
            const heading = document.createElement('h4');
            heading.textContent = component.label;
            const notice = document.createElement('p');
            notice.textContent = `Version: ${component.version || '—'} · Letzter Installationshinweis (Systemzeit, UTC): ${component.lastOperationAt}`;
            const paths = document.createElement('ul');
            for (const path of component.paths) {
                const item = document.createElement('li');
                const code = document.createElement('code');
                code.textContent = path;
                item.append(code);
                paths.append(item);
            }
            for (const name of ['component', 'recordToken']) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = component[name];
                form.append(input);
            }
            const label = document.createElement('label');
            label.className = 'kosit-switch';
            label.append(document.createTextNode(`${component.label} deinstallieren`));
            const confirmed = document.createElement('input');
            confirmed.type = 'checkbox';
            confirmed.setAttribute('role', 'switch');
            confirmed.name = 'confirmed';
            confirmed.value = '1';
            confirmed.required = true;
            label.prepend(confirmed);
            const button = document.createElement('button');
            button.type = 'submit';
            button.textContent = 'Diese Komponente deinstallieren';
            form.append(heading, notice, paths, label, button);
            container.append(form);
        }
    };

    const helpSections = JSON.parse(root.dataset.helpSections || '{}');
    const createHelp = (id, label) => {
        const help = document.createElement('a');
        help.href = `https://github.com/322ud/inv_validator/blob/HEAD/docs/admin.md#${helpSections[id]}`;
        help.className = 'kosit-admin-help';
        help.target = '_blank';
        help.rel = 'noopener noreferrer';
        const description = `Hilfe zu „${label}“ (öffnet ein neues Fenster)`;
        help.setAttribute('aria-label', description);
        help.title = description;
        const icon = document.createElement('span');
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = 'i';
        help.append(icon);
        return help;
    };

    const updateView = (status) => {
        updateManagedComponents(status.managedComponents || []);
        autoUpdates = status.autoUpdates || autoUpdates;
        const checks = Object.fromEntries(status.checks.map(check => [check.id, check]));
        const groups = {
            requirements: ['php', 'java'], validation: ['validator', 'configuration'],
            pdf: ['pymupdf', 'python'], installation: ['zip', 'validator-write', 'configuration-write'],
        };
        const labels = { php: 'PHP-Prozessstart', java: 'Java', validator: 'KoSIT-Validator',
            configuration: 'XRechnung-Szenario', pymupdf: 'PyMuPDF', python: 'Python', zip: 'PHP ZipArchive' };
        for (const [group, ids] of Object.entries(groups)) {
            const body = root.querySelector(`[data-group="${group}"]`);
            body.replaceChildren();
            for (const id of ids) {
                const check = checks[id];
                if (!check || (id === 'python' && !checks.pymupdf?.ok)
                    || (id.endsWith('-write') && !checks.zip?.ok)) continue;
                const row = document.createElement('tr');
                row.dataset.check = id;
                const label = document.createElement('th');
                label.scope = 'row';
                label.textContent = labels[id] || check.detail;
                const value = document.createElement('td');
                const missing = id === 'php' ? 'nicht verfügbar' : id === 'java' ? '>= v11 nicht verfügbar' : 'nicht installiert';
                value.textContent = id.endsWith('-write') ? (check.ok ? 'Schreibrecht' : 'kein Schreibrecht')
                    : check.ok ? (check.version || 'Version unbekannt') : missing;
                const actions = document.createElement('td');
                if (['validator', 'configuration'].includes(id) && check.ok && checks.zip?.ok && checks[`${id}-write`]?.ok) {
                    const toggle = document.createElement('label');
                    toggle.className = 'kosit-switch';
                    const input = document.createElement('input');
                    input.type = 'checkbox';
                    input.name = id;
                    input.setAttribute('role', 'switch');
                    input.setAttribute('aria-label', `${labels[id]} automatisch aktualisieren`);
                    input.checked = autoUpdates[id];
                    toggle.append(input, document.createTextNode('automatisch aktualisieren'));
                    actions.append(toggle);
                }
                if (check.action) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.dataset.component = id;
                    button.textContent = check.action === 'update' ? `Update auf ${check.latestVersion}` : 'Installieren';
                    actions.append(button);
                }
                if (check.updateError) {
                    const error = document.createElement('p');
                    error.textContent = `Update-Prüfung: ${check.updateError}`;
                    actions.append(error);
                }
                const details = document.createElement('details');
                const summary = document.createElement('summary');
                summary.textContent = 'Details';
                const detail = document.createElement('p');
                detail.textContent = check.detail;
                details.append(summary, detail);
                actions.append(details);
                const helpCell = document.createElement('td');
                helpCell.className = 'kosit-help-cell';
                helpCell.append(createHelp(id, label.textContent));
                row.append(label, value, actions, helpCell);
                body.append(row);
            }
        }
    };

    const run = async (url, options, progress) => {
        if (busy) {
            return false;
        }
        busy = true;
        root.querySelectorAll('button, input').forEach((element) => { element.disabled = true; });
        message.textContent = progress;
        try {
            const result = await request(url, options);
            if (result.status) {
                updateView(result.status);
            }
            message.textContent = result.message || 'Systemprüfung abgeschlossen.';
            return true;
        } catch (error) {
            message.textContent = error.message;
            return false;
        } finally {
            busy = false;
            root.querySelectorAll('button, input').forEach((element) => { element.disabled = false; });
        }
    };

    const settingsForm = root.querySelector('#kosit-settings-form');
    settingsForm.addEventListener('submit', event => event.preventDefault());
    settingsForm.addEventListener('change', async (event) => {
        const input = event.target;
        if (!input.matches('input[role="switch"]')) {
            return;
        }
        const previous = !input.checked;
        autoUpdates[input.name] = input.checked;
        const data = new FormData();
        for (const key of ['validator', 'configuration']) {
            data.append(key, (settingsForm.elements[key]?.checked ?? autoUpdates[key]) ? 'yes' : 'no');
        }
        const saved = await run('settings', { method: 'POST', body: data }, 'Update-Einstellungen werden gespeichert …');
        if (!saved) {
            input.checked = previous;
            autoUpdates[input.name] = previous;
        }
    });
    root.querySelector('#kosit-paths-form').addEventListener('submit', (event) => {
        event.preventDefault();
        run('paths', { method: 'POST', body: new FormData(event.target) }, 'Pfade werden gespeichert …');
    });
    root.querySelector('#kosit-check-system').addEventListener('click', () => {
        run('status', {}, 'System und verfügbare Updates werden geprüft …');
    });
    root.addEventListener('submit', (event) => {
        if (!event.target.matches('.kosit-remove-component')) {
            return;
        }
        event.preventDefault();
        if (!event.target.elements.confirmed.checked) {
            message.textContent = 'Die Komponente muss zum Entfernen ausgewählt sein.';
            return;
        }
        run('components/remove', { method: 'POST', body: new FormData(event.target) }, 'Bestätigte Komponente wird entfernt …');
    });
    root.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-component]');
        if (button) {
            const data = new FormData();
            data.append('component', button.dataset.component);
            run('install', { method: 'POST', body: data }, 'Komponente wird installiert / aktualisiert …');
        }
    });
});
