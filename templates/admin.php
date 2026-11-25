<?php

/** @var array<string, mixed> $_ */

script('inv_validator', 'readme');
script('inv_validator', 'admin');
style('inv_validator', 'admin');

$status = $_['status'];
$checks = array_column($status['checks'], null, 'id');
$groups = [
    'requirements' => ['Systemvoraussetzungen', ['php', 'java']],
    'validation' => ['Validierung', ['validator', 'configuration']],
    'pdf' => ['PDF-Verarbeitung', ['pymupdf', 'python']],
    'installation' => ['automatische Installation/Updates', ['zip', 'validator-write', 'configuration-write']],
];
$labels = ['php' => 'PHP-Prozessstart', 'java' => 'Java', 'validator' => 'KoSIT-Validator',
    'configuration' => 'XRechnung-Szenario', 'pymupdf' => 'PyMuPDF', 'python' => 'Python', 'zip' => 'PHP ZipArchive'];
$helpSections = [
    'php' => 'php-und-prozessstart', 'java' => 'java-installieren',
    'validator' => 'kosit-validator-und-xrechnung-konfiguration-installieren',
    'configuration' => 'kosit-validator-und-xrechnung-konfiguration-installieren',
    'pymupdf' => 'optionale-pdf-unterstützung', 'python' => 'optionale-pdf-unterstützung',
    'zip' => 'php-ziparchive', 'validator-write' => 'verzeichnisse-und-berechtigungen',
    'configuration-write' => 'verzeichnisse-und-berechtigungen',
];
$readme = file_get_contents(dirname(__DIR__) . '/README.md') ?: 'README konnte nicht geladen werden.';
$readme = preg_replace('~(\]\()(?!https?://|#)([^)]+)~', '$1https://github.com/322ud/inv_validator/blob/HEAD/$2', $readme);
?>
<div id="kosit-admin" data-help-sections="<?php p(json_encode($helpSections)); ?>">
    <h2>INValidator</h2>
    <section aria-labelledby="kosit-settings-title">
        <h3 id="kosit-settings-title">Einstellungen</h3>
        <form id="kosit-paths-form">
            <?php foreach ($status['paths'] as $path) : ?>
                <?php if (isset($path['key'])) : ?>
                    <label><span><?php p($path['label']); ?></span>
                        <input class="kosit-path-input" type="text" name="<?php p($path['key']); ?>"
                               value="<?php p($path['path']); ?>" autocomplete="off" spellcheck="false">
                    </label>
                <?php endif; ?>
            <?php endforeach; ?>
            <button id="kosit-save-paths" type="submit">Pfade speichern</button>
        </form>
    </section>
    <section aria-labelledby="kosit-system-title">
        <h3 id="kosit-system-title">Komponenten</h3>
        <form id="kosit-settings-form" data-auto-updates="<?php p(json_encode($status['autoUpdates'])); ?>">
        <?php foreach ($groups as $group => [$title, $ids]) : ?>
            <h4 id="kosit-<?php p($group); ?>-title"><?php p($title); ?></h4>
            <div class="kosit-table-scroll">
            <table class="kosit-system" aria-labelledby="kosit-<?php p($group); ?>-title">
            <tbody data-group="<?php p($group); ?>">
            <?php foreach ($ids as $id) :
                $check = $checks[$id];
                if (($id === 'python' && !$checks['pymupdf']['ok'])
                    || (str_ends_with($id, '-write') && !$checks['zip']['ok'])) {
                    continue;
                }
                $writable = str_ends_with($id, '-write');
                $label = $labels[$id] ?? $check['detail'];
                $missing = $id === 'php' ? 'nicht verfügbar' : ($id === 'java' ? '>= v11 nicht verfügbar' : 'nicht installiert');
                $value = $writable ? ($check['ok'] ? 'Schreibrecht' : 'kein Schreibrecht')
                    : ($check['ok'] ? ($check['version'] ?: 'Version unbekannt') : $missing);
                ?>
                <tr data-check="<?php p($id); ?>">
                    <th scope="row"><?php p($label); ?></th>
                    <td><?php p($value); ?></td>
                    <td>
                        <?php if (in_array($id, ['validator', 'configuration'], true)
                            && $check['ok'] && $checks['zip']['ok'] && $checks[$id . '-write']['ok']) : ?>
                            <label class="kosit-switch">
                                <input type="checkbox" role="switch" name="<?php p($id); ?>" aria-label="<?php p($label . ' automatisch aktualisieren'); ?>" <?php if ($status['autoUpdates'][$id]) : ?>checked<?php endif; ?>>
                                automatisch aktualisieren
                            </label>
                        <?php endif; ?>
                        <?php if (!empty($check['action'])) : ?>
                            <button type="button" data-component="<?php p($id); ?>"><?php p($check['action'] === 'update' ? 'Update auf ' . $check['latestVersion'] : 'Installieren'); ?></button>
                        <?php endif; ?>
                        <?php if (!empty($check['updateError'])) : ?><p><?php p('Update-Prüfung: ' . $check['updateError']); ?></p><?php endif; ?>
                        <details><summary>Details</summary>
                            <p><?php p($check['detail']); ?></p>
                        </details>
                    </td>
                    <td class="kosit-help-cell">
                        <?php $helpDescription = 'Hilfe zu „' . $label . '“ (öffnet ein neues Fenster)'; ?>
                        <a class="kosit-admin-help" href="https://github.com/322ud/inv_validator/blob/HEAD/docs/admin.md#<?php p($helpSections[$id]); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php p($helpDescription); ?>" title="<?php p($helpDescription); ?>"><span aria-hidden="true">i</span></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            </div>
        <?php endforeach; ?>
        </form>
        <button id="kosit-check-system" type="button">Systemprüfung wiederholen</button>
    </section>
    <details id="kosit-component-removal">
        <summary>Deinstallation vorbereiten</summary>
        <p>Die folgenden Komponenten wurden über diese App installiert oder aktualisiert.
            Wähle jede zu entfernende Komponente einzeln über ihren Schalter aus.
            Ohne Bestätigung bleibt sie auch beim Entfernen der App erhalten.</p>
        <div id="kosit-managed-components">
        <?php foreach ($status['managedComponents'] ?? [] as $component) : ?>
            <form class="kosit-remove-component" data-remove-component="<?php p($component['component']); ?>">
                <h4><?php p($component['label']); ?></h4>
                <p><?php p('Version: ' . ($component['version'] ?? '—') . ' · Letzter Installationshinweis (Systemzeit, UTC): ' . $component['lastOperationAt']); ?></p>
                <ul><?php foreach ($component['paths'] as $path) : ?><li><code><?php p($path); ?></code></li><?php endforeach; ?></ul>
                <input type="hidden" name="component" value="<?php p($component['component']); ?>">
                <input type="hidden" name="recordToken" value="<?php p($component['recordToken']); ?>">
                <label class="kosit-switch">
                    <input type="checkbox" role="switch" name="confirmed" value="1" required>
                    <?php p($component['label']); ?> deinstallieren
                </label>
                <button type="submit">Diese Komponente deinstallieren</button>
            </form>
        <?php endforeach; ?>
        <?php if (empty($status['managedComponents'])) : ?><p>Keine offenen Installationshinweise vorhanden.</p><?php endif; ?>
        </div>
    </details>
    <p id="kosit-admin-message" role="status" aria-live="polite"></p>
    <section class="kosit-readme-embed" aria-labelledby="kosit-admin-readme-title">
        <h3 id="kosit-admin-readme-title">README</h3>
        <div class="kosit-readme-viewer" data-kosit-readme>
            <div class="kosit-readme-rich" aria-label="Formatierter Inhalt der README" hidden></div>
            <pre class="kosit-readme-fallback" tabindex="0" aria-label="Inhalt der README"><?php
            p($readme);
            ?></pre>
        </div>
    </section>
</div>
