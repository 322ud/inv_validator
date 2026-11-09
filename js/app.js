document.addEventListener('DOMContentLoaded', () => {
    const validator = document.getElementById('inv-validator');
    const fileInput = document.getElementById('kosit-xml-file');
    const dropZone = document.getElementById('kosit-scroll-container');
    const selectFileButton = document.getElementById('kosit-select-file-button');
    const status = document.getElementById('kosit-status');
    const reportActions = document.getElementById('kosit-report-actions');
    const printButton = document.getElementById('kosit-print-button');
    const downloadButton = document.getElementById('kosit-download-button');
    const reportFrame = document.getElementById('kosit-report-frame');
    const readmeOpen = document.getElementById('kosit-readme-open');
    const readmeDialog = document.getElementById('kosit-readme-dialog');
    const readmeClose = document.getElementById('kosit-readme-close');
    const readmeViewer = readmeDialog?.querySelector('[data-kosit-readme]');

    if (validator.dataset.faviconUrl) {
        document.head.querySelectorAll('link[rel~="icon"]').forEach((icon) => icon.remove());

        const favicon = document.createElement('link');
        favicon.rel = 'icon';
        favicon.type = 'image/svg+xml';
        favicon.setAttribute('sizes', 'any');
        favicon.href = validator.dataset.faviconUrl;
        document.head.appendChild(favicon);
    }

    const maxXmlSize = 5 * 1024 * 1024;
    const maxPdfSize = 20 * 1024 * 1024;
    const pdfSupported = validator.dataset.pdfSupported === 'true';
    let reportResizeObserver = null;
    let reportMutationObserver = null;
    let resizeAnimationFrame = null;
    let resetHeightBeforeResize = false;
    let reportContentWidth = 0;
    let reportHtml = '';

    const closeReadme = () => {
        readmeDialog.hidden = true;
        readmeOpen.focus();
    };

    readmeOpen?.addEventListener('click', (event) => {
        event.preventDefault();
        readmeDialog.hidden = false;
        readmeClose.focus();
        window.OCA?.INValidator?.initializeReadme(readmeViewer);
    });

    readmeClose?.addEventListener('click', closeReadme);

    readmeDialog?.addEventListener('click', (event) => {
        if (event.target === readmeDialog) {
            closeReadme();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && readmeDialog && !readmeDialog.hidden) {
            event.preventDefault();
            closeReadme();
        }
    });

    const openFileDialog = () => {
        if (selectFileButton.disabled) {
            return;
        }

        fileInput.value = '';
        fileInput.click();
    };

    selectFileButton.addEventListener('click', openFileDialog);
    ['dragenter', 'dragover'].forEach((eventName) => {
        dropZone.addEventListener(eventName, (event) => {
            event.preventDefault();

            if (selectFileButton.disabled) {
                return;
            }

            event.dataTransfer.dropEffect = 'copy';
            dropZone.classList.add('is-dragover');
        });
    });

    dropZone.addEventListener('dragleave', (event) => {
        if (!dropZone.contains(event.relatedTarget)) {
            dropZone.classList.remove('is-dragover');
        }
    });

    dropZone.addEventListener('drop', (event) => {
        event.preventDefault();
        dropZone.classList.remove('is-dragover');

        if (selectFileButton.disabled) {
            return;
        }

        if (event.dataTransfer.files.length !== 1) {
            status.textContent = pdfSupported
                ? 'Bitte genau eine XML- oder PDF-Datei ablegen.'
                : 'Bitte genau eine XML-Datei ablegen.';
            return;
        }

        const file = event.dataTransfer.files[0];

        fileInput.value = '';
        validateFile(file);
    });

    const addPrintStyles = (frameDocument) => {
        if (!frameDocument.head || frameDocument.getElementById('kosit-print-styles')) {
            return;
        }

        const printStyles = frameDocument.createElement('style');

        printStyles.id = 'kosit-print-styles';
        printStyles.textContent = `
            @media print {
                @page {
                    size: A4;
                    margin: 10mm;
                }

                html,
                body {
                    width: auto !important;
                    max-width: none !important;
                    margin: 0 !important;
                    padding: 0 !important;
                }

                table {
                    max-width: 100% !important;
                }
            }
        `;

        frameDocument.head.appendChild(printStyles);
    };

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

        addPrintStyles(frameDocument);

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

    const setReportFrameWidth = (width) => {
        reportFrame.style.setProperty('width', `${width}px`, 'important');
    };

    const resizeReportFrame = (resetHeight = false) => {
        const frameDocument = reportFrame.contentDocument;

        if (
            !frameDocument
            || !frameDocument.documentElement
            || reportFrame.style.display === 'none'
        ) {
            return;
        }

        const frameBorderWidth = reportFrame.offsetWidth - reportFrame.clientWidth;
        const frameBorderHeight = reportFrame.offsetHeight - reportFrame.clientHeight;

        if (resetHeight) {
            reportFrame.style.removeProperty('width');
        }

        const measuredContentWidth = Math.max(
            frameDocument.documentElement.scrollWidth,
            frameDocument.documentElement.offsetWidth,
            frameDocument.body ? frameDocument.body.scrollWidth : 0,
            frameDocument.body ? frameDocument.body.offsetWidth : 0,
        );
        const availableWidth = validator.clientWidth;

        if (resetHeight || reportContentWidth === 0) {
            reportContentWidth = measuredContentWidth;
        }

        if (reportContentWidth > 0 && availableWidth > 0) {
            setReportFrameWidth(Math.min(
                Math.ceil(reportContentWidth) + frameBorderWidth,
                availableWidth,
            ));
        }

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

        if (reportHtml !== '') {
            printButton.disabled = false;
            downloadButton.disabled = false;
            reportActions.hidden = false;
        }
    });

    printButton.addEventListener('click', () => {
        try {
            const printWindow = reportFrame.contentWindow;

            if (!printWindow) {
                throw new Error('Report frame is not available');
            }

            printWindow.focus();
            printWindow.print();
        } catch {
            status.textContent = 'Der Druckdialog konnte nicht geöffnet werden.';
        }
    });

    downloadButton.addEventListener('click', () => {
        if (reportHtml === '') {
            return;
        }

        const reportDocument = new DOMParser().parseFromString(reportHtml, 'text/html');

        addPrintStyles(reportDocument);

        const downloadableHtml = '<!DOCTYPE html>\n' + reportDocument.documentElement.outerHTML;
        const blob = new Blob([downloadableHtml], {
            type: 'text/html;charset=utf-8',
        });
        const downloadUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');

        link.href = downloadUrl;
        link.download = 'kosit-pruefbericht.html';
        document.body.appendChild(link);
        link.click();
        link.remove();

        window.setTimeout(() => URL.revokeObjectURL(downloadUrl), 0);
    });

    if ('ResizeObserver' in window) {
        let reportFrameWidth = 0;
        new ResizeObserver(([entry]) => {
            if (entry.contentRect.width === reportFrameWidth) {
                return;
            }

            reportFrameWidth = entry.contentRect.width;
            scheduleReportFrameResize();
        }).observe(reportFrame);
    } else {
        window.addEventListener('resize', () => scheduleReportFrameResize(true));
    }

    window.addEventListener('resize', resizeValidator);
    resizeValidator();

    const validateFile = async (file) => {
        const lowerName = file.name.toLowerCase();
        const isPdf = lowerName.endsWith('.pdf') || file.type === 'application/pdf';
        const isXml = lowerName.endsWith('.xml')
            || file.type === 'application/xml'
            || file.type === 'text/xml';

        if (isPdf && !pdfSupported) {
            status.textContent = 'PDF-Eingabe ist auf diesem Server nicht verfügbar.';
            return;
        }
        if (!isPdf && !isXml) {
            status.textContent = pdfSupported
                ? 'Bitte eine XML- oder PDF-Datei auswählen.'
                : 'Bitte eine XML-Datei auswählen.';
            return;
        }

        const maxSize = isPdf ? maxPdfSize : maxXmlSize;
        if (file.size > maxSize) {
            status.textContent = isPdf
                ? 'PDF-Datei ist zu groß. Maximal erlaubt sind 20 MB.'
                : 'XML-Datei ist zu groß. Maximal erlaubt sind 5 MB.';
            return;
        }

        status.textContent = 'Validierung läuft ...';
        selectFileButton.disabled = true;
        dropZone.setAttribute('aria-busy', 'true');
        printButton.disabled = true;
        downloadButton.disabled = true;
        reportActions.hidden = true;
        reportHtml = '';
        reportFrame.style.display = 'none';
        reportContentWidth = 0;
        setReportFrameHeight(0);
        reportFrame.srcdoc = '';

        try {
            const formData = new FormData();
            formData.append('document', file, file.name);
            formData.append('requesttoken', OC.requestToken);

            const response = await fetch(OC.generateUrl('/apps/inv_validator/validate'), {
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

            const subject = result.documentType === 'pdf'
                ? 'Eingebettetes XML'
                : 'XML';
            status.textContent = result.valid
            ? `${subject} ist gültig.`
            : `${subject} ist nicht gültig.`;

            if (result.reportHtml) {
                reportHtml = result.reportHtml;
                reportFrame.srcdoc = reportHtml;
                reportFrame.style.display = 'block';
            } else {
                status.textContent += ' Kein HTML-Prüfbericht gefunden.';
            }
        } catch {
            status.textContent = 'Fehler beim Aufruf des Validators.';
        } finally {
            selectFileButton.disabled = false;
            dropZone.removeAttribute('aria-busy');
        }
    };

    fileInput.addEventListener('change', () => {
        const file = fileInput.files[0];

        if (!file) {
            return;
        }

        validateFile(file);
    });
});
