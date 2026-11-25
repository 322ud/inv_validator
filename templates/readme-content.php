<?php
$document = in_array($_['document'] ?? '', ['admin', 'user', 'developer'], true) ? $_['document'] : 'readme';
$documentPath = $document === 'readme' ? 'README.md' : 'docs/' . $document . '.md';
?>
<div class="kosit-readme-viewer" data-kosit-readme data-document="<?php p($documentPath); ?>" data-document-url="<?php p($_['readmeUrl']); ?>">
    <div class="kosit-readme-rich" aria-label="Formatierte Dokumentation" hidden></div>
    <pre class="kosit-readme-fallback" tabindex="0" aria-label="Dokumentation"><?php
        $readme = file_get_contents(dirname(__DIR__) . '/' . $documentPath) ?: 'README konnte nicht geladen werden.';
        foreach (explode("\n", $readme) as $line) {
            if (preg_match('/^#{1,6}\s+(.+)$/', $line, $heading)) {
                print_unescaped('<span data-readme-heading="');
                p($heading[1]);
                print_unescaped('">');
                p($line);
                print_unescaped('</span>');
            } else { p($line); }
            print_unescaped("\n");
        }
    ?></pre>
</div>
