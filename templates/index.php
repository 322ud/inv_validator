<?php
    /** @var array<string, mixed> $_ */

    script('inv_validator', 'readme');
    script('inv_validator', 'app');
    style('inv_validator', 'app');

    $status = $_['status'];
    preg_match('/XRechnung\s+(\d+(?:\.\d+)+)/i', (string)($status['configuration']['name'] ?? ''), $scenarioVersion);
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
            Prüft XML-Rechnungsdokumente mit dem KoSIT-Validator
            <?php p($status['validator']['displayVersion'] ?? $status['validator']['installedVersion'] ?? 'unbekannt'); ?>,
            XRechnung-Szenario <span id="kosit-configuration-name"><?php p($scenarioVersion[1] ?? 'unbekannt'); ?></span>
            der Koordinierungsstelle für IT-Standards (<a href="http://www.xoev.de" target="_blank" rel="noopener noreferrer">KoSIT</a>).
        </p>
        <a
            class="kosit-link-icon"
            href="#kosit-readme-dialog"
            id="kosit-readme-open"
            aria-label="README in diesem Fenster öffnen (interner Link)"
            title="README öffnen (interner Link)"
        >
            <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false">
                <circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" stroke-width="1.5"/>
                <circle cx="8" cy="4.5" r="1" fill="currentColor"/>
                <path fill="currentColor" d="M7 7h2v5H7z"/>
            </svg>
        </a>

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
        <span id="kosit-drop-text"><?php p($pdfAvailable ? 'XML- oder PDF-Datei auf dieser Seite fallenlassen oder' : 'XML-Datei auf dieser Seite fallenlassen oder'); ?></span>
        <button id="kosit-select-file-button" type="button" class="primary" aria-describedby="kosit-drop-text">
            Datei auswählen
        </button>
    </div>
</div>
