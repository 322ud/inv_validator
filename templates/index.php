<?php
    /** @var array<string, mixed> $_ */

    script('kosit_validator', 'app');
    style('kosit_validator', 'app');

    $status = $_['status'];
    $pdfAvailable = (bool)($status['pdfSupport']['available'] ?? false);
?>

<div
    id="kosit-scroll-container"
    style="flex:1 1 auto;width:100%;height:100%;min-width:0;overflow-x:hidden;overflow-y:auto;"
>
    <div
        id="kosit-validator"
        class="section"
        data-pdf-supported="<?php p($pdfAvailable ? 'true' : 'false'); ?>"
    >
        <h2>KoSIT Validator</h2>

        <p class="kosit-validator-intro">
            XML-Datei<?php if ($pdfAvailable): ?> oder PDF mit eingebettetem XML<?php endif; ?>
            hochladen und XML gegen den Validator
            <span id="kosit-validator-version">
                <?php p($status['installedVersion'] ?? 'unbekannt'); ?>
            </span>
            <a
                class="kosit-external-link"
                href="<?php p($status['releaseUrl']); ?>"
                target="_blank"
                rel="noopener noreferrer"
                aria-label="KoSIT Validator auf GitHub öffnen (neuer Tab)"
                title="KoSIT Validator auf GitHub"
            >
                <svg viewBox="0 0 16 16" width="16" height="16" style="color: black" aria-hidden="true" focusable="false">
                    <path fill="currentColor" d="M9 2h5v5h-2V5.41l-5.3 5.3-1.4-1.42L10.58 4H9V2Z"/>
                    <path fill="currentColor" d="M3 3h4v2H4v7h7V9h2v4a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/>
                </svg>
            </a>
            der Koordinierungsstelle für IT-Standards (KoSIT) prüfen.
        </p>

        <?php if ($status['updateAvailable'] && $status['latestVersion'] !== null): ?>
            <p id="kosit-update-notice" role="status">
                Aktuelle Version <?php p($status['latestVersion']); ?> verfügbar - Administrator informieren!
            </p>
        <?php endif; ?>

        <div id="kosit-upload-options">
            <input
                type="file"
                id="kosit-xml-file"
                accept=".xml,text/xml,application/xml<?php if ($pdfAvailable): ?>,.pdf,application/pdf<?php endif; ?>"
                hidden
            >
            <button id="kosit-select-file-button" type="button" class="primary">
                Datei auswählen
            </button>

            <div
                id="kosit-drop-zone"
                role="button"
                tabindex="0"
                aria-controls="kosit-xml-file"
                aria-describedby="kosit-selected-file"
            >
                <span><?php p($pdfAvailable ? 'XML- oder PDF-Datei hier fallenlassen' : 'XML-Datei hier fallenlassen'); ?></span>
                <small id="kosit-selected-file" aria-live="polite">Keine Datei ausgewählt</small>
            </div>
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
</div>
