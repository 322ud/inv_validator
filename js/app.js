document.addEventListener('DOMContentLoaded', () => {
    const fileInput = document.getElementById('kosit-xml-file');
    const button = document.getElementById('kosit-validate-button');
    const status = document.getElementById('kosit-status');
    const reportFrame = document.getElementById('kosit-report-frame');

    const maxXmlSize = 5 * 1024 * 1024;

    button.addEventListener('click', async () => {
        const file = fileInput.files[0];

        if (!file) {
            status.textContent = 'Bitte eine XML-Datei auswählen.';
            return;
        }

        if (file.size > maxXmlSize) {
            status.textContent = 'XML-Datei ist zu groß. Maximal erlaubt sind 5 MB.';
            return;
        }

        status.textContent = 'Validierung läuft ...';
        button.disabled = true;
        reportFrame.style.display = 'none';
        reportFrame.srcdoc = '';

        try {
            const xml = await file.text();

            const formData = new FormData();
            formData.append('xml', xml);
            formData.append('requesttoken', OC.requestToken);

            const response = await fetch(OC.generateUrl('/apps/kosit_validator/validate'), {
                method: 'POST',
                headers: {
                    requesttoken: OC.requestToken,
                },
                credentials: 'same-origin',
                body: formData,
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                status.textContent = result.message || 'Validierung fehlgeschlagen.';
                return;
            }

            status.textContent = result.valid
            ? 'XML ist gültig.'
            : 'XML ist nicht gültig.';

            if (result.reportHtml) {
                reportFrame.srcdoc = result.reportHtml;
                reportFrame.style.display = 'block';
            } else {
                status.textContent += ' Kein HTML-Prüfbericht gefunden.';
            }
        } catch (error) {
            status.textContent = 'Fehler beim Aufruf des Validators.';
        } finally {
            button.disabled = false;
        }
    });
});
