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

<input type="file" id="kosit-xml-file" accept=".xml,text/xml,application/xml">

<button id="kosit-validate-button" class="primary">
    Validieren
</button>

<div id="kosit-status" role="status" aria-live="polite"></div>

    <iframe
        id="kosit-report-frame"
        sandbox="allow-same-origin"
        scrolling="no"
        title="HTML Prüfbericht"
        style="display:none;"
    ></iframe>
</div>
</div>
