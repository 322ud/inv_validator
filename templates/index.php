<?php
    script('kosit_validator', 'app');
    style('kosit_validator', 'app');
?>

<div
    id="kosit-scroll-container"
    style="flex:1 1 auto;width:100%;height:100%;min-width:0;overflow-x:hidden;overflow-y:auto;"
>
    <div id="kosit-validator" class="section">
        <h2>KoSIT Validator</h2>

        <p>XML-Datei hochladen und gegen den KoSIT Validator prüfen.</p>

        <div id="kosit-upload-options">
            <input
                type="file"
                id="kosit-xml-file"
                accept=".xml,text/xml,application/xml"
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
                <span>XML-Datei hier fallenlassen</span>
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
