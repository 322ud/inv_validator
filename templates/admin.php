<?php

/** @var array<string, mixed> $_ */

script('kosit_validator', 'readme');
script('kosit_validator', 'admin');
style('kosit_validator', 'admin');

$status = $_['status'];
$writeCheckIds = ['temp', 'validator-write', 'configuration-write'];
$systemChecks = array_filter($status['checks'], static fn (array $check): bool => !in_array($check['id'], $writeCheckIds, true));
$writeChecks = array_filter($status['checks'], static fn (array $check): bool => in_array($check['id'], $writeCheckIds, true));
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
        <h3 id="kosit-settings-title">Automatische Updates beim Starten der App</h3>
        <form id="kosit-settings-form">
            <?php foreach (['validator' => 'KoSIT-Validator', 'configuration' => 'XRechnung-Konfiguration'] as $key => $label) : ?>
                <label class="kosit-switch">
                    <input type="checkbox" role="switch" name="<?php p($key); ?>" <?php if ($status['autoUpdates'][$key]) : ?>checked<?php endif; ?>>
                    <?php p($label); ?>
                </label>
            <?php endforeach; ?>
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
                <thead><tr><th scope="col" class="kosit-result-column">Ergebnis</th><th scope="col">Komponente / Prüfung</th><th scope="col">Version</th><th scope="col">Pfad / Details</th><th scope="col">Aktion / Hilfe</th></tr></thead>
                <tbody id="kosit-system-body">
                <?php foreach ($systemChecks as $check) : ?>
                    <tr data-check="<?php p($check['id']); ?>">
                        <td class="kosit-result-column"><span class="kosit-state kosit-state--<?php p($check['ok'] ? 'available' : 'missing'); ?>" role="img" aria-label="<?php p($check['ok'] ? 'Bereit' : 'Nicht bereit'); ?>"><?php p($check['ok'] ? '✓' : '✕'); ?></span></td>
                        <th scope="row"><?php p($check['label']); ?></th>
                        <td><?php p($check['version'] ?? '—'); ?></td>
                        <td>
                            <div class="kosit-check-detail"><?php p($check['detail']); ?></div>
                            <?php if (!empty($check['updateError'])) : ?><div><?php p('Update-Prüfung: ' . $check['updateError']); ?></div><?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($check['action'])) : ?>
                                <button type="button" data-component="<?php p($check['id']); ?>"><?php p($check['action'] === 'update' ? 'Update auf ' . $check['latestVersion'] : 'Installieren'); ?></button>
                            <?php endif; ?>
                            <?php if (!$check['ok'] || !empty($check['updateError'])) : ?>
                                <a href="#kosit-help-<?php p($check['help']); ?>" class="kosit-help-link" data-readme-help aria-label="Info in der README anzeigen">Info <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M6.6 4H4a4 4 0 0 0 0 8h2.6v-2H4a2 2 0 0 1 0-4h2.6V4Zm2.8 0v2H12a2 2 0 1 1 0 4H9.4v2H12a4 4 0 1 0 0-8H9.4ZM5 7h6v2H5V7Z"/></svg></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <h4 id="kosit-write-title">Schreibrechte</h4>
        <ul id="kosit-write-checks" aria-labelledby="kosit-write-title">
            <?php foreach ($writeChecks as $check) : ?>
                <li data-check="<?php p($check['id']); ?>">
                    <span class="kosit-state kosit-state--<?php p($check['ok'] ? 'available' : 'missing'); ?>" role="img" aria-label="<?php p($check['ok'] ? 'Bereit' : 'Nicht bereit'); ?>"><?php p($check['ok'] ? '✓' : '✕'); ?></span>
                    <div><strong><?php p($check['label']); ?></strong>
                        <span class="kosit-check-detail">(<?php p($check['detail']); ?>)</span>
                        <?php if (!$check['ok']) : ?><a href="#kosit-help-<?php p($check['help']); ?>" class="kosit-help-link" data-readme-help aria-label="Info in der README anzeigen">Info <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M6.6 4H4a4 4 0 0 0 0 8h2.6v-2H4a2 2 0 0 1 0-4h2.6V4Zm2.8 0v2H12a2 2 0 1 1 0 4H9.4v2H12a4 4 0 1 0 0-8H9.4ZM5 7h6v2H5V7Z"/></svg></a><?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
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
