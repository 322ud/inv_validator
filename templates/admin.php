<?php

/** @var array<string, mixed> $_ */

script('kosit_validator', 'admin');
style('kosit_validator', 'admin');

$status = $_['status'];

$stateLabels = [
    'available' => 'Vorhanden',
    'creatable' => 'Wird bei Bedarf angelegt',
    'not-executable' => 'Nicht ausführbar',
    'missing' => 'Fehlt',
];
?>

<div id="kosit-admin" class="section">
    <h2>KoSIT Validator</h2>
    <p>
        Systempfade, Installation und verfügbare Updates des offiziellen
        KoSIT Validators verwalten.
    </p>

    <h3>Systempfade</h3>
    <table class="kosit-paths">
        <thead>
            <tr>
                <th>Komponente</th>
                <th>Ermittelter Pfad</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody id="kosit-paths-body">
            <?php foreach ($status['paths'] as $path): ?>
                <tr>
                    <th scope="row"><?php p($path['label']); ?></th>
                    <td>
                        <?php if (isset($path['key'])): ?>
                            <input
                                class="kosit-path-input"
                                type="text"
                                name="<?php p($path['key']); ?>"
                                value="<?php p($path['path']); ?>"
                                aria-label="<?php p($path['label'] . '-Pfad'); ?>"
                                autocomplete="off"
                                spellcheck="false"
                            >
                        <?php else: ?>
                            <code><?php p($path['path']); ?></code>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="kosit-state kosit-state--<?php p($path['state']); ?>">
                            <?php p($stateLabels[$path['state']] ?? $path['state']); ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="kosit-path-actions">
        <button id="kosit-save-paths" type="button" class="primary">Pfade speichern</button>
    </div>
    <p class="settings-hint">
        Gespeicherte Werte haben Vorrang vor den entsprechenden Einträgen in
        der Nextcloud-<code>config.php</code> und werden sofort für Validierungen,
        Installationen und Updates verwendet.
    </p>

    <h3>Installation und Updates</h3>
    <dl class="kosit-versions">
        <div>
            <dt>Installierte Version</dt>
            <dd id="kosit-installed-version">
                <?php p($status['installedVersion'] ?? ($status['installed'] ? 'Unbekannt' : 'Nicht installiert')); ?>
            </dd>
        </div>
        <div>
            <dt>Neueste Version auf GitHub</dt>
            <dd id="kosit-latest-version"><?php p($status['latestVersion'] ?? 'Nicht ermittelbar'); ?></dd>
        </div>
    </dl>

    <p id="kosit-update-summary" class="kosit-update-summary">
        <?php if ($status['updateError'] !== null): ?>
            <?php p('Update-Prüfung fehlgeschlagen: ' . $status['updateError']); ?>
        <?php elseif (!$status['installed']): ?>
            KoSIT Validator ist nicht installiert.
        <?php elseif ($status['installedVersion'] === null): ?>
            Die installierte Version konnte nicht ermittelt werden.
        <?php elseif ($status['updateAvailable']): ?>
            Ein Update ist verfügbar.
        <?php else: ?>
            Die Installation ist aktuell.
        <?php endif; ?>
    </p>

    <div class="kosit-admin-actions">
        <button id="kosit-check-update" type="button">Erneut prüfen</button>
        <button id="kosit-install" type="button" class="primary">
            <?php p($status['installed'] ? 'Neueste Version installieren' : 'Jetzt installieren'); ?>
        </button>
        <a href="<?php p($status['releaseUrl']); ?>" target="_blank" rel="noopener noreferrer">
            Releases auf GitHub
        </a>
    </div>

    <p id="kosit-admin-message" role="status" aria-live="polite"></p>
    <p class="settings-hint">
        Die Installation schreibt das offizielle Standalone-JAR atomar an den
        angezeigten Validator-Pfad. Der Webserver-Benutzer benötigt dort
        Schreibrechte. Ab Validator 1.6 ist Java 11 oder neuer erforderlich.
    </p>
</div>
