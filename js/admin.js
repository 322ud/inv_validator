document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('kosit-admin');
    if (!root) {
        return;
    }
    const message = root.querySelector('#kosit-admin-message');
    const body = root.querySelector('#kosit-system-body');
    const writeChecks = root.querySelector('#kosit-write-checks');
    const writeCheckIds = ['temp', 'validator-write', 'configuration-write'];
    const viewer = root.querySelector('[data-kosit-readme]');
    window.OCA?.KoSITValidator?.initializeReadme(viewer);
    let busy = false;

    const request = async (url, options = {}) => {
        const response = await fetch(OC.generateUrl(`/apps/kosit_validator/admin/${url}`), {
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

    const createHelp = (check) => {
        const help = document.createElement('a');
        help.href = `#kosit-help-${check.help}`;
        help.dataset.readmeHelp = '';
        help.className = 'kosit-help-link';
        help.setAttribute('aria-label', 'Info in der README anzeigen');
        help.append(document.createTextNode('Info '));
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 16 16');
        svg.setAttribute('width', '16');
        svg.setAttribute('height', '16');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('fill', 'currentColor');
        path.setAttribute('d', 'M6.6 4H4a4 4 0 0 0 0 8h2.6v-2H4a2 2 0 0 1 0-4h2.6V4Zm2.8 0v2H12a2 2 0 1 1 0 4H9.4v2H12a4 4 0 1 0 0-8H9.4ZM5 7h6v2H5V7Z');
        svg.append(path);
        help.append(svg);
        return help;
    };

    const createState = (check) => {
        const state = document.createElement('span');
        state.className = `kosit-state kosit-state--${check.ok ? 'available' : 'missing'}`;
        state.setAttribute('role', 'img');
        state.setAttribute('aria-label', check.ok ? 'Bereit' : 'Nicht bereit');
        state.textContent = check.ok ? '✓' : '✕';
        return state;
    };

    const updateView = (status) => {
        updateManagedComponents(status.managedComponents || []);
        if (status.autoUpdates) {
            for (const key of ['validator', 'configuration']) {
                root.querySelector(`#kosit-settings-form [name="${key}"]`).checked = status.autoUpdates[key];
            }
        }
        writeChecks.replaceChildren(...status.checks.filter(check => writeCheckIds.includes(check.id)).map(check => {
            const item = document.createElement('li');
            item.dataset.check = check.id;
            const content = document.createElement('div');
            const label = document.createElement('strong');
            label.textContent = check.label;
            const detail = document.createElement('span');
            detail.className = 'kosit-check-detail';
            detail.textContent = `(${check.detail})`;
            content.append(label, document.createTextNode(' '), detail);
            if (!check.ok) {
                content.append(document.createTextNode(' '), createHelp(check));
            }
            item.append(createState(check), content);
            return item;
        }));
        body.replaceChildren(...status.checks.filter(check => !writeCheckIds.includes(check.id)).map((check) => {
            const row = document.createElement('tr');
            row.dataset.check = check.id;
            const label = document.createElement('th');
            label.scope = 'row';
            label.textContent = check.label;
            const version = document.createElement('td');
            version.textContent = check.version || '—';
            const result = document.createElement('td');
            const stateCell = document.createElement('td');
            stateCell.className = 'kosit-result-column';
            stateCell.append(createState(check));
            const detail = document.createElement('div');
            detail.className = 'kosit-check-detail';
            detail.textContent = check.detail;
            result.append(detail);
            if (check.updateError) {
                const error = document.createElement('div');
                error.textContent = `Update-Prüfung: ${check.updateError}`;
                result.append(error);
            }
            const action = document.createElement('td');
            if (check.action) {
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.component = check.id;
                button.textContent = check.action === 'update' ? `Update auf ${check.latestVersion}` : 'Installieren';
                action.append(button);
            }
            if (!check.ok || check.updateError) {
                action.append(createHelp(check));
            }
            row.append(stateCell, label, version, result, action);
            return row;
        }));
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
        const data = new FormData();
        for (const key of ['validator', 'configuration']) {
            data.append(key, settingsForm.elements[key].checked ? 'yes' : 'no');
        }
        const saved = await run('settings', { method: 'POST', body: data }, 'Update-Einstellungen werden gespeichert …');
        if (!saved) {
            input.checked = previous;
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
    root.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-component]');
        if (button) {
            const data = new FormData();
            data.append('component', button.dataset.component);
            run('install', { method: 'POST', body: data }, 'Komponente wird installiert / aktualisiert …');
        }
        const help = event.target.closest('[data-readme-help]');
        if (help) {
            const target = document.getElementById(help.hash.slice(1));
            if (!target || !viewer) {
                return;
            }
            event.preventDefault();
            const rich = viewer.querySelector('.kosit-readme-rich');
            const heading = [...rich.querySelectorAll('h1, h2, h3, h4')]
                .find((element) => element.textContent.trim() === target.dataset.heading);
            if (heading && !rich.hidden) {
                heading.scrollIntoView({ block: 'center' });
            } else {
                rich.hidden = true;
                viewer.querySelector('.kosit-readme-fallback').hidden = false;
                target.scrollIntoView({ block: 'center' });
            }
        }
    });
});
