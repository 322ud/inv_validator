document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('kosit-admin');
    if (!root) {
        return;
    }
    const message = root.querySelector('#kosit-admin-message');
    const body = root.querySelector('#kosit-system-body');
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

    const updateView = (status) => {
        updateManagedComponents(status.managedComponents || []);
        if (status.autoUpdates) {
            for (const key of ['validator', 'configuration']) {
                root.querySelector(`#kosit-settings-form [name="${key}"]`).checked = status.autoUpdates[key];
            }
        }
        body.replaceChildren(...status.checks.map((check) => {
            const row = document.createElement('tr');
            row.dataset.check = check.id;
            const label = document.createElement('th');
            label.scope = 'row';
            label.textContent = check.label;
            const version = document.createElement('td');
            version.textContent = check.version || '—';
            const result = document.createElement('td');
            const state = document.createElement('strong');
            state.className = `kosit-state--${check.ok ? 'available' : 'missing'}`;
            state.textContent = check.ok ? 'OK' : 'Nicht bereit';
            const detail = document.createElement('div');
            detail.className = 'kosit-check-detail';
            detail.textContent = check.detail;
            result.append(state, detail);
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
                const help = document.createElement('a');
                help.href = `#kosit-help-${check.help}`;
                help.dataset.readmeHelp = '';
                help.textContent = 'Lösung in der README';
                action.append(help);
            }
            row.append(label, version, result, action);
            return row;
        }));
    };

    const run = async (url, options, progress) => {
        if (busy) {
            return;
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
        } catch (error) {
            message.textContent = error.message;
        } finally {
            busy = false;
            root.querySelectorAll('button, input').forEach((element) => { element.disabled = false; });
        }
    };

    root.querySelector('#kosit-settings-form').addEventListener('submit', (event) => {
        event.preventDefault();
        const data = new FormData();
        for (const key of ['validator', 'configuration']) {
            data.append(key, event.target.elements[key].checked ? 'yes' : 'no');
        }
        run('settings', { method: 'POST', body: data }, 'Einstellungen werden gespeichert …');
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
