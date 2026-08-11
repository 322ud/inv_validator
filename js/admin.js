document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('kosit-admin');

    if (!root) {
        return;
    }

    const pathsBody = document.getElementById('kosit-paths-body');
    const installedVersion = document.getElementById('kosit-installed-version');
    const latestVersion = document.getElementById('kosit-latest-version');
    const summary = document.getElementById('kosit-update-summary');
    const message = document.getElementById('kosit-admin-message');
    const saveButton = document.getElementById('kosit-save-paths');
    const checkButton = document.getElementById('kosit-check-update');
    const installButton = document.getElementById('kosit-install');
    const editScenarioButton = document.getElementById('kosit-edit-scenario');
    const scenarioMessage = document.getElementById('kosit-scenario-message');
    const scenarioEditor = document.getElementById('kosit-scenario-editor');
    const scenarioPath = document.getElementById('kosit-scenario-path');
    const scenarioContent = document.getElementById('kosit-scenario-content');
    const saveScenarioButton = document.getElementById('kosit-save-scenario');
    const cancelScenarioButton = document.getElementById('kosit-cancel-scenario');
    let scenarioChecksum = '';

    const stateLabels = {
        available: 'Vorhanden',
        creatable: 'Wird bei Bedarf angelegt',
        'not-executable': 'Nicht ausführbar',
        missing: 'Fehlt',
    };

    const updateView = (status) => {
        pathsBody.replaceChildren(...status.paths.map((path) => {
            const row = document.createElement('tr');
            const label = document.createElement('th');
            const value = document.createElement('td');
            const stateCell = document.createElement('td');
            const state = document.createElement('span');

            label.scope = 'row';
            label.textContent = path.label;
            state.className = `kosit-state kosit-state--${path.state}`;
            state.textContent = stateLabels[path.state] || path.state;

            if (path.key) {
                const input = document.createElement('input');

                input.className = 'kosit-path-input';
                input.type = 'text';
                input.name = path.key;
                input.value = path.path;
                input.setAttribute('aria-label', `${path.label}-Pfad`);
                input.autocomplete = 'off';
                input.spellcheck = false;
                value.appendChild(input);
            } else {
                const code = document.createElement('code');

                code.textContent = path.path;
                value.appendChild(code);
            }

            stateCell.appendChild(state);
            row.append(label, value, stateCell);
            return row;
        }));

        installedVersion.textContent = status.installedVersion
            || (status.installed ? 'Unbekannt' : 'Nicht installiert');
        latestVersion.textContent = status.latestVersion || 'Nicht ermittelbar';
        installButton.textContent = status.installed
            ? 'Neueste Version installieren'
            : 'Jetzt installieren';

        if (status.updateError) {
            summary.textContent = `Update-Prüfung fehlgeschlagen: ${status.updateError}`;
        } else if (!status.installed) {
            summary.textContent = 'KoSIT Validator ist nicht installiert.';
        } else if (!status.installedVersion) {
            summary.textContent = 'Die installierte Version konnte nicht ermittelt werden.';
        } else if (status.updateAvailable) {
            summary.textContent = 'Ein Update ist verfügbar.';
        } else {
            summary.textContent = 'Die Installation ist aktuell.';
        }
    };

    const request = async (url, options = {}) => {
        const response = await fetch(OC.generateUrl(url), {
            credentials: 'same-origin',
            headers: {
                requesttoken: OC.requestToken,
                ...options.headers,
            },
            ...options,
        });
        const responseBody = await response.text();
        let result;

        try {
            result = JSON.parse(responseBody);
        } catch {
            throw new Error(`Unerwartete Serverantwort (HTTP ${response.status}).`);
        }

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Die Aktion ist fehlgeschlagen.');
        }

        return result;
    };

    saveButton.addEventListener('click', async () => {
        const formData = new FormData();

        root.querySelectorAll('.kosit-path-input').forEach((input) => {
            formData.append(input.name, input.value);
        });

        message.textContent = 'Pfade werden gespeichert …';
        saveButton.disabled = true;

        try {
            const result = await request('/apps/kosit_validator/admin/paths', {
                method: 'POST',
                body: formData,
            });
            updateView(result.status);
            message.textContent = result.message;
        } catch (error) {
            message.textContent = error.message;
        } finally {
            saveButton.disabled = false;
        }
    });

    editScenarioButton.addEventListener('click', async () => {
        scenarioEditor.hidden = true;
        scenarioPath.textContent = '';
        scenarioContent.value = '';
        scenarioChecksum = '';
        scenarioMessage.textContent = 'Szenario-Datei wird geladen …';
        editScenarioButton.disabled = true;

        try {
            const result = await request('/apps/kosit_validator/admin/status?scenario=1');
            scenarioPath.textContent = result.scenario.path;
            scenarioContent.value = result.scenario.content;
            scenarioChecksum = result.scenario.checksum;
            scenarioEditor.hidden = false;
            scenarioContent.readOnly = !result.scenario.writable;
            saveScenarioButton.disabled = !result.scenario.writable;
            scenarioMessage.textContent = result.scenario.writable
                ? 'Szenario-Datei wurde geladen.'
                : 'Szenario-Datei wurde geladen, ist aber nicht beschreibbar.';
            scenarioContent.focus();
        } catch (error) {
            scenarioMessage.textContent = error.message;
        } finally {
            editScenarioButton.disabled = false;
        }
    });

    cancelScenarioButton.addEventListener('click', () => {
        scenarioEditor.hidden = true;
        scenarioContent.value = '';
        scenarioChecksum = '';
        scenarioMessage.textContent = '';
        editScenarioButton.focus();
    });

    saveScenarioButton.addEventListener('click', async () => {
        const formData = new FormData();
        formData.append('action', 'saveScenario');
        formData.append('content', scenarioContent.value);
        formData.append('checksum', scenarioChecksum);

        scenarioMessage.textContent = 'Szenario-Datei wird geprüft und gespeichert …';
        saveScenarioButton.disabled = true;
        cancelScenarioButton.disabled = true;

        try {
            const result = await request('/apps/kosit_validator/admin/paths', {
                method: 'POST',
                body: formData,
            });
            scenarioChecksum = result.scenario.checksum;
            scenarioContent.value = result.scenario.content;
            scenarioMessage.textContent = result.message;
        } catch (error) {
            scenarioMessage.textContent = error.message;
        } finally {
            saveScenarioButton.disabled = false;
            cancelScenarioButton.disabled = false;
        }
    });

    checkButton.addEventListener('click', async () => {
        message.textContent = 'GitHub wird auf Updates geprüft …';
        checkButton.disabled = true;

        try {
            const result = await request('/apps/kosit_validator/admin/status');
            updateView(result.status);
            message.textContent = result.status.updateError
                ? 'Die Update-Prüfung konnte nicht abgeschlossen werden.'
                : 'Update-Prüfung abgeschlossen.';
        } catch (error) {
            message.textContent = error.message;
        } finally {
            checkButton.disabled = false;
        }
    });

    installButton.addEventListener('click', async () => {
        message.textContent = 'Neueste Validator-Version wird heruntergeladen und installiert …';
        installButton.disabled = true;
        checkButton.disabled = true;

        try {
            const result = await request('/apps/kosit_validator/admin/install', {
                method: 'POST',
            });
            updateView(result.status);
            message.textContent = result.message;
        } catch (error) {
            message.textContent = error.message;
        } finally {
            installButton.disabled = false;
            checkButton.disabled = false;
        }
    });
});
