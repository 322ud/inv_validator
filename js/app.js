document.addEventListener('DOMContentLoaded', () => {
    const validator = document.getElementById('kosit-validator');
    const fileInput = document.getElementById('kosit-xml-file');
    const button = document.getElementById('kosit-validate-button');
    const status = document.getElementById('kosit-status');
    const reportFrame = document.getElementById('kosit-report-frame');

    const maxXmlSize = 5 * 1024 * 1024;
    let reportResizeObserver = null;
    let reportMutationObserver = null;
    let resizeAnimationFrame = null;
    let resetHeightBeforeResize = false;

    const resizeValidator = () => {
        const left = Math.max(0, validator.getBoundingClientRect().left);
        const width = document.documentElement.clientWidth - (2 * left);

        if (width > 0) {
            validator.style.setProperty('width', `${width}px`, 'important');
        }
    };

    const prepareReportDocument = () => {
        const frameDocument = reportFrame.contentDocument;

        if (!frameDocument) {
            return;
        }

        [frameDocument.documentElement, frameDocument.body].forEach((element) => {
            if (!element) {
                return;
            }

            element.style.setProperty('height', 'auto', 'important');
            element.style.setProperty('min-height', '0', 'important');
            element.style.setProperty('max-height', 'none', 'important');
            element.style.setProperty('overflow-y', 'visible', 'important');
        });
    };

    const setReportFrameHeight = (height) => {
        reportFrame.style.setProperty('height', `${height}px`, 'important');
    };

    const resizeReportFrame = (resetHeight = false) => {
        const frameDocument = reportFrame.contentDocument;

        if (!frameDocument || reportFrame.style.display === 'none') {
            return;
        }

        const frameBorderHeight = reportFrame.offsetHeight - reportFrame.clientHeight;

        if (resetHeight) {
            setReportFrameHeight(1);
        }

        const getContentHeight = () => {
            let height = Math.max(
                frameDocument.documentElement.scrollHeight,
                frameDocument.documentElement.offsetHeight,
                frameDocument.body ? frameDocument.body.scrollHeight : 0,
                frameDocument.body ? frameDocument.body.offsetHeight : 0,
            );

            if (frameDocument.body) {
                const documentTop = frameDocument.documentElement.getBoundingClientRect().top;

                frameDocument.body.querySelectorAll('*').forEach((element) => {
                    height = Math.max(
                        height,
                        element.getBoundingClientRect().bottom - documentTop,
                    );
                });
            }

            return Math.ceil(height);
        };

        setReportFrameHeight(getContentHeight() + frameBorderHeight);
        setReportFrameHeight(getContentHeight() + frameBorderHeight);
    };

    const scheduleReportFrameResize = (resetHeight = false) => {
        resetHeightBeforeResize = resetHeightBeforeResize || resetHeight;

        if (resizeAnimationFrame !== null) {
            cancelAnimationFrame(resizeAnimationFrame);
        }

        resizeAnimationFrame = requestAnimationFrame(() => {
            const shouldResetHeight = resetHeightBeforeResize;

            resizeAnimationFrame = null;
            resetHeightBeforeResize = false;
            resizeReportFrame(shouldResetHeight);
        });
    };

    const observeReportContent = () => {
        reportResizeObserver?.disconnect();
        reportMutationObserver?.disconnect();

        const frameDocument = reportFrame.contentDocument;

        if (!frameDocument || !frameDocument.body) {
            return;
        }

        if ('ResizeObserver' in window) {
            reportResizeObserver = new ResizeObserver(() => scheduleReportFrameResize());
            reportResizeObserver.observe(frameDocument.documentElement);
            reportResizeObserver.observe(frameDocument.body);
        }

        reportMutationObserver = new MutationObserver(() => scheduleReportFrameResize(true));
        reportMutationObserver.observe(frameDocument.body, {
            childList: true,
            subtree: true,
            attributes: true,
            characterData: true,
        });

        frameDocument.querySelectorAll('img').forEach((image) => {
            if (!image.complete) {
                image.addEventListener('load', () => scheduleReportFrameResize(true), { once: true });
                image.addEventListener('error', () => scheduleReportFrameResize(true), { once: true });
            }
        });

        if (frameDocument.fonts) {
            frameDocument.fonts.ready.then(() => scheduleReportFrameResize(true));
        }
    };

    reportFrame.addEventListener('load', () => {
        resizeValidator();
        prepareReportDocument();
        resizeReportFrame(true);
        observeReportContent();
        window.setTimeout(() => resizeReportFrame(true), 250);
        window.setTimeout(() => resizeReportFrame(true), 1000);
    });

    if ('ResizeObserver' in window) {
        let reportFrameWidth = 0;
        new ResizeObserver(([entry]) => {
            if (entry.contentRect.width === reportFrameWidth) {
                return;
            }

            reportFrameWidth = entry.contentRect.width;
            scheduleReportFrameResize(true);
        }).observe(reportFrame);
    } else {
        window.addEventListener('resize', () => scheduleReportFrameResize(true));
    }

    window.addEventListener('resize', resizeValidator);
    resizeValidator();

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
        setReportFrameHeight(0);
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
