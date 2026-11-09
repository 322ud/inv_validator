<?php
    /** @var array<string, mixed> $_ */

    script('inv_validator', 'readme');
    script('inv_validator', 'app');
    style('inv_validator', 'app');

    $status = $_['status'];
    $pdfAvailable = (bool)($status['pdfSupport']['available'] ?? false);
    $readme = file_get_contents(dirname(__DIR__) . '/README.md') ?: 'README konnte nicht geladen werden.';
?>

<div
    id="kosit-scroll-container"
    style="flex:1 1 auto;width:100%;height:100%;min-width:0;overflow-x:hidden;overflow-y:auto;"
>
    <div
        id="inv-validator"
        class="section"
        data-pdf-supported="<?php p($pdfAvailable ? 'true' : 'false'); ?>"
        data-favicon-url="<?php p(image_path('inv_validator', 'app.svg')); ?>"
    >
        <h2>INValidator</h2>

        <p class="inv-validator-intro">
            Prüft XML-Rechnungsdokumente mit dem Standard
            <span id="kosit-configuration-name"><?php p($status['configuration']['name'] ?? 'unbekannt'); ?></span>.
            <a
                class="kosit-link-icon"
                href="https://de.wikipedia.org/wiki/XRechnung"
                target="_blank"
                rel="noopener noreferrer"
                aria-label="Informationen zu XRechnung bei Wikipedia öffnen (externer Link)"
                title="XRechnung bei Wikipedia (externer Link)"
            >
                <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false">
                    <path fill="currentColor" d="M9 2h5v5h-2V5.41l-5.3 5.3-1.4-1.42L10.58 4H9V2Z"/>
                    <path fill="currentColor" d="M3 3h4v2H4v7h7V9h2v4a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/>
                </svg>
            </a>
            Weitere Info
            <a
                class="kosit-link-icon"
                href="#kosit-readme-dialog"
                id="kosit-readme-open"
                aria-label="README in diesem Fenster öffnen (interner Link)"
                title="README öffnen (interner Link)"
            >
                <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false">
                    <path fill="currentColor" d="M6.6 4H4a4 4 0 0 0 0 8h2.6v-2H4a2 2 0 0 1 0-4h2.6V4Zm2.8 0v2H12a2 2 0 1 1 0 4H9.4v2H12a4 4 0 1 0 0-8H9.4ZM5 7h6v2H5V7Z"/>
                </svg>
            </a>
        </p>

        <div
            id="kosit-readme-dialog"
            class="kosit-readme-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="kosit-readme-title"
            hidden
        >
            <div class="kosit-readme-dialog-panel" role="document">
                <div class="kosit-readme-dialog-header">
                    <h3 id="kosit-readme-title">README</h3>
                    <button id="kosit-readme-close" type="button" aria-label="README schließen">Schließen</button>
                </div>
                <div class="kosit-readme-viewer" data-kosit-readme>
                    <div class="kosit-readme-rich" aria-label="Formatierter Inhalt der README" hidden></div>
                    <pre class="kosit-readme-fallback" tabindex="0" aria-label="Inhalt der README"><?php p($readme); ?></pre>
                </div>
            </div>
        </div>

        <div id="kosit-upload-options">
            <input
                type="file"
                id="kosit-xml-file"
                accept=".xml,text/xml,application/xml<?php if ($pdfAvailable): ?>,.pdf,application/pdf<?php endif; ?>"
                hidden
            >
            <button id="kosit-select-file-button" type="button" class="primary" aria-describedby="kosit-drop-hint">
                Datei auswählen
            </button>
        </div>

        <div id="kosit-status" role="status" aria-live="polite"></div>

        <div id="kosit-report-actions" hidden>
            <button id="kosit-print-button" type="button" disabled>
                Prüfbericht drucken
            </button>
            <button id="kosit-download-button" type="button" disabled>
                HTML herunterladen
            </button>
        </div>

        <iframe
            id="kosit-report-frame"
            sandbox="allow-same-origin allow-modals"
            scrolling="no"
            title="HTML Prüfbericht"
            style="display:none;"
        ></iframe>
    </div>
    <div id="kosit-drop-hint">
        <span><?php p($pdfAvailable ? 'XML- oder PDF-Datei auf dieser Seite fallenlassen' : 'XML-Datei auf dieser Seite fallenlassen'); ?></span>
    </div>
</div>
