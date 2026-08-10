<?php
    script('kosit_validator', 'app');
    style('kosit_validator', 'app');
?>

<div id="kosit-validator" class="section">
<h2>KoSIT Validator</h2>

<p>XML-Datei hochladen und gegen den KoSIT Validator prüfen.</p>

<input type="file" id="kosit-xml-file" accept=".xml,text/xml,application/xml">

<button id="kosit-validate-button" class="primary">
    Validieren
</button>

<div id="kosit-status"></div>

    <iframe
        id="kosit-report-frame"
        sandbox=""
        title="HTML Prüfbericht"
        style="display:none;"
    ></iframe>
</div>
