<?php

/** @var array<string, mixed> $_ */

script('kosit_validator', 'readme');
script('kosit_validator', 'admin');
style('kosit_validator', 'admin');

$status = $_['status'];
$readme = file_get_contents(dirname(__DIR__) . '/README.md') ?: 'README konnte nicht geladen werden.';

$stateLabels = [
    'available' => 'Vorhanden',
    'creatable' => 'Wird bei Bedarf angelegt',
    'not-executable' => 'Nicht ausführbar',
    'missing' => 'Fehlt',
];
?>

<div id="kosit-admin">
    <h2>Anbindung des KoSIT-Validators</h2>
    <p>
        Konfiguration der Anbindung an den externen
        <a href="https://github.com/itplr-kosit/validator" target="_blank" rel="noopener noreferrer">KoSIT-Validator</a>.
        Hier können Systempfade sowie der KoSIT-Validator und die offizielle
        XRechnung-Konfiguration verwaltet werden.
    </p>

    <h4>Systempfade</h4>
    <table class="kosit-paths">
        <thead>
            <tr>
                <th>Komponente</th>
                <th>Ermittelter Pfad</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody id="kosit-paths-body">
            <?php foreach ($status['paths'] as $path) : ?>
                <tr>
                    <th scope="row"><?php p($path['label']); ?></th>
                    <td>
                        <?php if (isset($path['key'])) : ?>
                            <input
                                class="kosit-path-input"
                                type="text"
                                name="<?php p($path['key']); ?>"
                                value="<?php p($path['path']); ?>"
                                aria-label="<?php p($path['label'] . '-Pfad'); ?>"
                                autocomplete="off"
                                spellcheck="false"
                            >
                        <?php else : ?>
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

    <h4 class="kosit-status-heading">Optionale PDF-Unterstützung</h4>
    <dl class="kosit-versions kosit-pdf-status">
        <div>
            <dt>PDF-Eingabe</dt>
            <dd id="kosit-pdf-availability">
                <?php p($status['pdfSupport']['available'] ? 'Verfügbar' : 'Nicht verfügbar'); ?>
            </dd>
        </div>
        <div>
            <dt>Python-Version</dt>
            <dd id="kosit-python-version"><?php p($status['pdfSupport']['pythonVersion'] ?? 'Nicht ermittelbar'); ?></dd>
        </div>
        <div>
            <dt>PyMuPDF-Version</dt>
            <dd id="kosit-pymupdf-version"><?php p($status['pdfSupport']['pymupdfVersion'] ?? 'Nicht installiert'); ?></dd>
        </div>
    </dl>
    <p id="kosit-pdf-reason" class="kosit-update-summary">
        <?php if ($status['pdfSupport']['reason'] !== null) : ?>
            <?php p($status['pdfSupport']['reason']); ?>
        <?php else : ?>
            PDFs mit eingebettetem XML können verarbeitet werden.
        <?php endif; ?>
    </p>
    <div class="kosit-pdf-actions">
        <button id="kosit-check-pdf" type="button">Erneut prüfen</button>
    </div>
    <p class="settings-hint">
    Wenn der Server Python und darin PyMuPDF bereitstellt, kann diese App auch PDF-Dateien mit eingebundener (embedded) XML-Rechnung verarbeiten.
        Wenn beispielsweise PyMuPDF mit
        <code class="kosit-install-command">python3 -m venv /opt/kosit/pymupdf-venv
/opt/kosit/pymupdf-venv/bin/python -m pip install pymupdf</code>
    in einer virtuellen Umgebung installiert ist, muss der Systempfad (oben) für Python entsprechend auf
        <code class="kosit-install-command">/opt/kosit/pymupdf-venv/bin/python</code>
    gesetzt werden.
    </p>

    <h4 class="kosit-status-heading">
        Installierte Komponenten der
        <a href="https://www.xoev.de/" target="_blank" rel="noopener noreferrer">Koordinierungsstelle für IT-Standards (KoSIT)</a>
    </h4>
    <dl class="kosit-versions">
        <div>
            <dt>Validator installiert</dt>
            <dd id="kosit-validator-installed-version">
                <?php p($status['validator']['installedVersion'] ?? ($status['validator']['installed'] ? 'Unbekannt' : 'Nicht installiert')); ?>
            </dd>
        </div>
        <div>
            <dt>Validator neueste Version</dt>
            <dd id="kosit-validator-latest-version"><?php p($status['validator']['latestVersion'] ?? 'Nicht ermittelbar'); ?></dd>
        </div>
        <div>
            <dt>XRechnung-Konfiguration installiert</dt>
            <dd id="kosit-configuration-installed-version">
                <?php if ($status['configuration']['installedVersion'] !== null) : ?>
                    <?php p('XRechnung ' . $status['configuration']['installedXRechnungVersion'] . ' / ' . $status['configuration']['installedVersion']); ?>
                <?php else : ?>
                    <?php p($status['configuration']['installed'] ? 'Manuell installiert / Version unbekannt' : 'Nicht installiert'); ?>
                <?php endif; ?>
            </dd>
        </div>
        <div>
            <dt>XRechnung-Konfiguration neueste Version</dt>
            <dd id="kosit-configuration-latest-version">
                <?php p($status['configuration']['latestVersion'] === null
                    ? 'Nicht ermittelbar'
                    : 'XRechnung ' . $status['configuration']['latestXRechnungVersion'] . ' / ' . $status['configuration']['latestVersion']); ?>
            </dd>
        </div>
    </dl>

    <p id="kosit-update-summary" class="kosit-update-summary">
        <?php if ($status['validator']['updateError'] !== null || $status['configuration']['updateError'] !== null) : ?>
            Die Update-Prüfung konnte nicht vollständig abgeschlossen werden.
        <?php elseif (!$status['validator']['installed'] || !$status['configuration']['installed']) : ?>
            Mindestens eine benötigte Komponente ist nicht installiert.
        <?php elseif ($status['validator']['installedVersion'] === null || $status['configuration']['installedVersion'] === null) : ?>
            Mindestens ein installierter Versionsstand konnte nicht ermittelt werden.
        <?php elseif ($status['validator']['updateAvailable'] || $status['configuration']['updateAvailable']) : ?>
            Ein Update ist verfügbar.
        <?php else : ?>
            Die Installation ist aktuell.
        <?php endif; ?>
    </p>

    <div class="kosit-admin-actions">
        <button id="kosit-check-update" type="button">Erneut prüfen</button>
        <button id="kosit-install" type="button" class="primary">
            <?php p($status['validator']['installed'] && $status['configuration']['installed']
                ? 'Komponenten aktualisieren'
                : 'Komponenten installieren'); ?>
        </button>
        <a href="<?php p($status['validator']['releaseUrl']); ?>" target="_blank" rel="noopener noreferrer">
            Validator-Releases
        </a>
        <a href="<?php p($status['configuration']['releaseUrl']); ?>" target="_blank" rel="noopener noreferrer">
            XRechnung-Releases
        </a>
    </div>

    <p id="kosit-admin-message" role="status" aria-live="polite"></p>
    <p class="settings-hint">
        Die Installation lädt das offizielle Standalone-JAR und das vollständige
        XRechnung-Konfigurationspaket. Der Webserver-Benutzer benötigt an den
        angezeigten Zielpfaden Schreibrechte. Validator 1.6 benötigt Java 11 oder neuer.
    </p>

    <section class="kosit-readme-embed" aria-labelledby="kosit-admin-readme-title">
        <h3 id="kosit-admin-readme-title">README</h3>
        <div class="kosit-readme-viewer" data-kosit-readme>
            <div class="kosit-readme-rich" aria-label="Formatierter Inhalt der README" hidden></div>
            <pre class="kosit-readme-fallback" tabindex="0" aria-label="Inhalt der README"><?php p($readme); ?></pre>
        </div>
    </section>
</div>
