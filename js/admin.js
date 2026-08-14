document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('kosit-admin');

    if (!root) {
        return;
    }

    const pathsBody = document.getElementById('kosit-paths-body');
    const validatorInstalledVersion = document.getElementById('kosit-validator-installed-version');
    const validatorLatestVersion = document.getElementById('kosit-validator-latest-version');
    const configurationInstalledVersion = document.getElementById('kosit-configuration-installed-version');
    const configurationLatestVersion = document.getElementById('kosit-configuration-latest-version');
    const summary = document.getElementById('kosit-update-summary');
    const message = document.getElementById('kosit-admin-message');
    const saveButton = document.getElementById('kosit-save-paths');
    const checkButton = document.getElementById('kosit-check-update');
    const installButton = document.getElementById('kosit-install');
    const pdfAvailability = document.getElementById('kosit-pdf-availability');
    const pythonVersion = document.getElementById('kosit-python-version');
    const pymupdfVersion = document.getElementById('kosit-pymupdf-version');
    const pdfReason = document.getElementById('kosit-pdf-reason');
    const checkPdfButton = document.getElementById('kosit-check-pdf');
    const readmeViewer = root.querySelector('[data-kosit-readme]');
    window.OCA?.KoSITValidator?.initializeReadme(readmeViewer);

    const stateLabels = {
        available: 'Vorhanden',
        creatable: 'Wird bei Bedarf angelegt',
        'not-executable': 'Nicht ausführbar',
        missing: 'Fehlt',
    };

    const updatePdfSupport = (pdfSupport) => {
        if (!pdfSupport || !pdfAvailability) {
            return;
        }

        pdfAvailability.textContent = pdfSupport.available
            ? 'Verfügbar'
            : 'Nicht verfügbar';
        pythonVersion.textContent = pdfSupport.pythonVersion || 'Nicht ermittelbar';
        pymupdfVersion.textContent = pdfSupport.pymupdfVersion || 'Nicht installiert';
        pdfReason.textContent = pdfSupport.reason
            || 'PDFs mit eingebettetem XML können verarbeitet werden.';
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

        validatorInstalledVersion.textContent = status.validator.installedVersion
            || (status.validator.installed ? 'Unbekannt' : 'Nicht installiert');
        validatorLatestVersion.textContent = status.validator.latestVersion || 'Nicht ermittelbar';
        configurationInstalledVersion.textContent = status.configuration.installedVersion
            ? `XRechnung ${status.configuration.installedXRechnungVersion} / ${status.configuration.installedVersion}`
            : (status.configuration.installed ? 'Manuell installiert / Version unbekannt' : 'Nicht installiert');
        configurationLatestVersion.textContent = status.configuration.latestVersion
            ? `XRechnung ${status.configuration.latestXRechnungVersion} / ${status.configuration.latestVersion}`
            : 'Nicht ermittelbar';
        installButton.textContent = status.validator.installed && status.configuration.installed
            ? 'Komponenten aktualisieren'
            : 'Komponenten installieren';

        updatePdfSupport(status.pdfSupport);

        const errors = [status.validator.updateError, status.configuration.updateError].filter(Boolean);
        if (errors.length > 0) {
            summary.textContent = `Update-Prüfung fehlgeschlagen: ${errors.join(' / ')}`;
        } else if (!status.validator.installed || !status.configuration.installed) {
            summary.textContent = 'Mindestens eine benötigte Komponente ist nicht installiert.';
        } else if (!status.validator.installedVersion || !status.configuration.installedVersion) {
            summary.textContent = 'Mindestens ein installierter Versionsstand konnte nicht ermittelt werden.';
        } else if (status.validator.updateAvailable || status.configuration.updateAvailable) {
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

    checkButton.addEventListener('click', async () => {
        message.textContent = 'GitHub wird auf Updates geprüft …';
        checkButton.disabled = true;

        try {
            const result = await request('/apps/kosit_validator/admin/status');
            updateView(result.status);
            message.textContent = result.status.validator.updateError || result.status.configuration.updateError
                ? 'Die Update-Prüfung konnte nicht abgeschlossen werden.'
                : 'Update-Prüfung abgeschlossen.';
        } catch (error) {
            message.textContent = error.message;
        } finally {
            checkButton.disabled = false;
        }
    });

    checkPdfButton.addEventListener('click', async () => {
        pdfReason.textContent = 'PDF-Unterstützung wird geprüft …';
        checkPdfButton.disabled = true;

        try {
            const result = await request('/apps/kosit_validator/admin/status?pdf=1');
            updatePdfSupport(result.pdfSupport);
        } catch (error) {
            pdfReason.textContent = error.message;
        } finally {
            checkPdfButton.disabled = false;
        }
    });

    installButton.addEventListener('click', async () => {
        message.textContent = 'Validator und XRechnung-Konfiguration werden installiert …';
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
