<?php

/** @var array<string, mixed> $_ */

script('kosit_validator', 'readme');
script('kosit_validator', 'admin');
style('kosit_validator', 'admin');

$status = $_['status'];
$readme = file_get_contents(dirname(__DIR__) . '/README.md') ?: 'README konnte nicht geladen werden.';
$helpHeadings = [
    'Java installieren' => 'java',
    'PHP und Prozessstart' => 'php',
    'Verzeichnisse und Berechtigungen' => 'paths',
    'KoSIT-Validator und XRechnung-Konfiguration installieren' => 'components',
    'Optionale PDF-Unterstützung' => 'pdf',
    'Update-Probleme' => 'updates',
];
?>
<div id="kosit-admin">
    <h2>prüfeXRechnung</h2>
    <section aria-labelledby="kosit-settings-title">
        <h3 id="kosit-settings-title">Einstellungen</h3>
        <form id="kosit-settings-form">
            <?php foreach (['validator' => 'KoSIT-Validator', 'configuration' => 'XRechnung-Konfiguration'] as $key => $label) : ?>
                <label class="kosit-switch">
                    <input type="checkbox" role="switch" name="<?php p($key); ?>" <?php if ($status['autoUpdates'][$key]) : ?>checked<?php endif; ?>>
                    <?php p($label); ?>: Update beim App-Start
                </label>
            <?php endforeach; ?>
            <button type="submit">Einstellungen speichern</button>
        </form>
        <details class="kosit-path-settings">
            <summary>Programmpfade</summary>
            <form id="kosit-paths-form">
                <?php foreach ($status['paths'] as $path) : ?>
                    <?php if (isset($path['key'])) : ?>
                        <label><?php p($path['label']); ?>
                            <input class="kosit-path-input" type="text" name="<?php p($path['key']); ?>"
                                   value="<?php p($path['path']); ?>" autocomplete="off" spellcheck="false">
                        </label>
                    <?php endif; ?>
                <?php endforeach; ?>
                <button id="kosit-save-paths" type="submit">Pfade speichern</button>
            </form>
        </details>
    </section>
    <section aria-labelledby="kosit-system-title">
        <h3 id="kosit-system-title">System</h3>
        <button id="kosit-check-system" type="button">Systemprüfung wiederholen</button>
        <div class="kosit-table-scroll" tabindex="0" role="region" aria-label="Ergebnisse der Systemprüfung">
            <table class="kosit-system">
                <thead><tr><th scope="col">Komponente / Prüfung</th><th scope="col">Version</th><th scope="col">Ergebnis / Pfad</th><th scope="col">Aktion / Hilfe</th></tr></thead>
                <tbody id="kosit-system-body">
                <?php foreach ($status['checks'] as $check) : ?>
                    <tr data-check="<?php p($check['id']); ?>">
                        <th scope="row"><?php p($check['label']); ?></th>
                        <td><?php p($check['version'] ?? '—'); ?></td>
                        <td>
                            <strong class="kosit-state--<?php p($check['ok'] ? 'available' : 'missing'); ?>"><?php p($check['ok'] ? 'OK' : 'Nicht bereit'); ?></strong>
                            <div class="kosit-check-detail"><?php p($check['detail']); ?></div>
                            <?php if (!empty($check['updateError'])) : ?><div><?php p('Update-Prüfung: ' . $check['updateError']); ?></div><?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($check['action'])) : ?>
                                <button type="button" data-component="<?php p($check['id']); ?>"><?php p($check['action'] === 'update' ? 'Update auf ' . $check['latestVersion'] : 'Installieren'); ?></button>
                            <?php endif; ?>
                            <?php if (!$check['ok'] || !empty($check['updateError'])) : ?>
                                <a href="#kosit-help-<?php p($check['help']); ?>" data-readme-help>Lösung in der README</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
            foreach (explode("\n", $readme) as $line) {
                $heading = preg_replace('/^#+\s+/', '', $line);
                if (isset($helpHeadings[$heading])) {
                    ?><span id="kosit-help-<?php p($helpHeadings[$heading]); ?>" data-heading="<?php p($heading); ?>"><?php p($line); ?></span><?php
                } else {
                    p($line);
                }
                echo "\n";
            }
            ?></pre>
        </div>
    </section>
</div>
