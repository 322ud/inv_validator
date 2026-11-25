# INValidator – Entwicklerdokumentation

Servereinrichtung und Betrieb stehen in der [Administrationsanleitung](admin.md), Bedienung und Eingabegrenzen in der [Benutzeranleitung](user.md).

## Validierungsablauf

Das Frontend prüft die [Eingabeanforderungen](user.md#eingaben-und-grenzen), extrahiert bei PDF-Eingaben die Rechnungs-XML und startet anschließend den KoSIT-Validator. Die fachliche Bewertung stammt aus `rep:accept` beziehungsweise `rep:reject` im XML-Bericht. Der erzeugte HTML-Bericht wird an den Browser zurückgegeben.

## API

### Validierung

```http
POST /apps/inv_validator/validate
```

### Request

```http
Content-Type: multipart/form-data

document=@invoice.xml
# oder, wenn PDF-Unterstützung verfügbar ist:
document=@invoice.pdf
```

Das bisherige Formularfeld `xml` mit dem XML-Inhalt wird für bestehende API-Clients weiterhin akzeptiert.

### Erfolgreiche Antwort

```json
{
    "success": true,
    "valid": true,
    "documentType": "xml",
    "exitCode": 0,
    "reportHtml": "<html>...</html>"
}
```

### Fehlerantwort

```json
{
    "success": false,
    "message": "..."
}
```

## Rückgabefelder

| Feld | Bedeutung |
|--------|------------|
| success | Request erfolgreich verarbeitet |
| valid | Fachliche Bewertung aus dem XML-Prüfbericht |
| exitCode | Technischer Rückgabecode des Validator-Prozesses |
| reportHtml | HTML-Bericht des Validators |
| message | Fehlermeldung |

## Sicherheit

Die Endpunkte erfordern Nextcloud-Authentifizierung und CSRF-Token. Status- und Installationsendpunkte verlangen zusätzlich Administratorrechte. Die [Zugriffsregeln](admin.md#zugriff-und-konfiguration) und das Verhalten [automatischer Updates](admin.md#kosit-validator-und-xrechnung-konfiguration-installieren) sind in der Administrationsanleitung beschrieben.

Prozessausgaben und Berichte besitzen serverseitige Größenlimits. XML-Berichte müssen wohlgeformt sein, den KoSIT-Namensraum verwenden und genau eine eindeutige Bewertung enthalten; `DOCTYPE` ist nicht erlaubt.

## Technische Umsetzung

### Backend

- `PageController`
  - Liefert die Hauptansicht.

- `ValidationController`
  - Nimmt XML- und PDF-Dokumente entgegen.
  - Führt Vorvalidierung durch.
  - Startet den KoSIT-Validator.
  - Liefert JSON-Antworten zurück.

- `KoSITValidatorService`
  - Erzeugt temporäre Dateien.
  - Führt den Validator-Prozess aus.
  - Extrahiert den HTML-Bericht mit der Validator-Option `-h`.
  - Begrenzt Laufzeit, Parallelität und Speichernutzung für Ausgaben.
  - Liest den HTML-Bericht ein.
  - Bereinigt temporäre Dateien.

- `ValidationResult`
  - DTO für das Validierungsergebnis.

- `ValidatorConfigService`
  - Ermittelt Java sowie installierte Validator-JARs und liefert die
    konfigurierten Systempfade.

- `ValidatorAdminService`
  - Fragt die neuesten offiziellen Releases von Validator und XRechnung-Konfiguration ab.
  - Prüft Downloads und ZIP-Inhalte und aktiviert beide Komponenten aus temporären Zielen.
  - Verwaltet XRechnung-Konfigurationen in versionierten Verzeichnissen.

- `AdminController` / `Settings\\Admin`
  - Stellen die nur für Administratoren erreichbare Status-, Installations- und Update-Oberfläche bereit.

### Frontend

- Upload von XML- und optional PDF-Dateien
- AJAX-Aufruf des Validierungsendpunkts
- Anzeige des HTML-Berichts über `iframe`
- Drucken des Berichts über den Browser-Druckdialog
- Herunterladen des aufbereiteten HTML-Berichts
- Darstellung des Validierungsstatus

## Fehlerbehandlung

Eingabefehler und Hinweise zur Bedienung beschreibt die [Benutzeranleitung](user.md#probleme-beheben). Technische Validator-Fehler werden serverseitig protokolliert und als HTTP 503 zurückgegeben. Sie werden nicht als fachlich ungültige Rechnung dargestellt. Dazu zählen fehlende Programme oder Ressourcen, Zeitüberschreitungen, inkompatible Szenarien und ein bereits belegter Validator.

## Entwicklung und Tests

Die Abhängigkeiten installieren und die PHP- sowie JavaScript-Tests ausführen:

```bash
composer install
composer lint
composer analyse
composer test
npm ci
npm run lint
npm test
```

Ein reproduzierbares, unsigniertes Produktionsarchiv mit SHA-256-Datei für lokale Tests erzeugen:

```bash
bash scripts/build-release.sh
```

Das Archiv unter `dist/` verwendet das USTAR-Format ohne PAX-Metadaten und enthält die zur Laufzeit benötigten App-Dateien und die Dokumentation. Nicht in USTAR darstellbare Pfade oder Metadaten führen zum Abbruch der Paketerstellung. CI prüft das Archivformat, installiert genau dieses Archiv zusätzlich in einer frischen Nextcloud-34-Instanz, aktiviert die App und führt Framework-Smoke-Tests aus.

Bei einem Push eines Versions-Tags wie `v1.0.2` veröffentlicht CI nach allen erfolgreichen Prüfungen ein GitHub-Release mit signiertem `inv_validator-VERSION.tar.gz`, Archivsignaturen, öffentlichem Zertifikat und SHA-256-Prüfsummen als Anhängen. Tag und App-Version müssen übereinstimmen. Für ein bereits vorhandenes Tag lässt sich der Workflow **Tests** manuell mit `release_tag` starten; alternativ löst ein Push eines Branches `release/vVERSION` die Veröffentlichung des zugehörigen unveränderten Tags aus. Bestehende Anhänge werden nur bei identischem SHA-256-Digest akzeptiert.

### Signierte Releases

Für Veröffentlichungen benötigt GitHub Actions zwei Repository-Secrets mit PEM-Inhalt:

- `APP_SIGNING_PRIVATE_KEY`: unverschlüsselter RSA-Schlüssel mit mindestens 4096 Bit.
- `APP_SIGNING_CERTIFICATE`: das zugehörige von Nextcloud ausgestellte öffentliche Zertifikat.

Das Zertifikat muss zur App-ID in `appinfo/info.xml` passen (derzeit `inv_validator`). Eine spätere Änderung der App-ID erfordert ein dazu passendes Zertifikat. Den Schlüssel außerhalb des Repositorys sicher verwahren; nur den Zertifikatsantrag bei [Nextcloud einreichen](https://github.com/nextcloud/app-certificate-requests). Schlüssel und Zertifikat werden beim Release nur vorübergehend im Runner-Verzeichnis mit eingeschränkten Rechten abgelegt und anschließend auch bei Fehlern entfernt. Pull Requests und gewöhnliche Branch-Builds erhalten diese Schlüsseldateien nicht.

Ein Release bricht bei fehlenden Secrets, falscher App-ID, abgelaufenem Zertifikat, unpassendem Schlüssel oder fehlgeschlagener Integritätsprüfung ab. Die CI initialisiert eine isolierte Nextcloud, signiert das fertige App-Verzeichnis mit `occ integrity:sign-app` und prüft es mit `occ integrity:check-app`, einschließlich Nextcloud-Zertifikatsvertrauen. Erst danach wird das Archiv erzeugt und mit OpenSSL/SHA-512 signiert. Geprüft und veröffentlicht wird genau dieses Paket. Der zusätzliche Build-Test verwendet ausschließlich temporäre Testschlüssel und einen simulierten `occ`-Aufruf; die eigentliche Nextcloud-Vertrauensprüfung erfolgt beim signierten Release.

Für einen lokalen signierten Build eine eingerichtete Nextcloud mit verfügbarem `occ` verwenden:

```bash
APP_REQUIRE_SIGNING=1 \
APP_SIGNING_KEY_FILE=/sicherer/pfad/inv_validator.key \
APP_SIGNING_CERTIFICATE_FILE=/sicherer/pfad/inv_validator.crt \
NEXTCLOUD_OCC=/pfad/zu/nextcloud/occ \
bash scripts/build-release.sh
```

Bereits einer der beiden gesetzten Schlüssel-/Zertifikatspfade aktiviert die Signierung; unvollständige Angaben führen zum Abbruch. `APP_REQUIRE_SIGNING=1` verhindert außerdem einen unbeabsichtigten unsignierten Build, wenn beide Pfade fehlen. Ohne Schlüsselpfade und ohne diese Pflicht bleiben lokale Builds unsigniert. Eine alte `appinfo/signature.json` aus dem Checkout wird nicht übernommen.

Signierte Releases enthalten:

- `inv_validator-VERSION.tar.gz`, einschließlich `appinfo/signature.json`.
- `inv_validator-VERSION.tar.gz.sig`: binäre SHA-512-Archivsignatur.
- `inv_validator-VERSION.tar.gz.sig.b64`: dieselbe Signatur in Base64 für die Store-Einreichung.
- `inv_validator-VERSION.tar.gz.crt`: öffentliches Zertifikat.
- `inv_validator-VERSION.tar.gz.sha256`: Prüfsummen für das Archiv und alle drei Begleitdateien.

Die Prüfung heruntergeladener Release-Artefakte beschreibt die [Administrationsanleitung](admin.md#release-artefakte-prüfen).

Die Einrichtung veröffentlicht weiterhin auf GitHub. Eine automatische Registrierung oder Einreichung im Nextcloud App Store ist nicht enthalten; dafür wird zusätzlich ein Store-Konto bzw. API-Token benötigt. Details: [Nextcloud-Signierung](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/code_signing.html) und [App-Store-Veröffentlichung](https://github.com/nextcloud/appstore/blob/master/docs/developer.rst).

Die Tests decken insbesondere die Interpretation der KoSIT-Berichte, die Pfadkonfiguration, Validator- und XRechnung-Updates, externe Release-Daten, Prozessabbrüche und konkurrierende Validierungen sowie den HTTP-Vertrag der Validierungs- und Admin-Endpunkte ab.

Bei Pushes und Pull Requests führt GitHub Actions die PHP-Suite mit PHP 8.2, 8.3, 8.4 und 8.5 sowie die JavaScript-Suite mit Node.js 22 aus. Ein zusätzlicher Integrationsjob installiert Nextcloud 34, aktiviert die App und prüft Container, Routen, Templates sowie die Admin- und CSRF-Attribute gegen das echte Framework. Ein weiterer Smoke-Test lädt fest versionierte und per SHA-256 geprüfte KoSIT- und XRechnung-Artefakte, validiert eine offizielle Testrechnung mit dem echten Java-Prozess und prüft außerdem die Ablehnung einer gezielt beschädigten Variante. Der Nextcloud-Job führt zusätzlich mit Playwright den vollständigen Browserfluss vom Login und Datei-Upload bis zum eingebetteten und herunterladbaren Prüfbericht für gültige, fachlich ungültige und nicht wohlgeformte XML-Dateien aus. Der administrative Browserfluss prüft das CSRF-geschützte Speichern und Wiederherstellen der Pfade. Ein Sicherheitslauf bestätigt zusätzlich, dass normale Benutzer zwar validieren, aber keine Admin-Endpunkte aufrufen dürfen und fehlende oder ungültige Request-Tokens die Konfiguration nicht verändern. Automatisierte Axe-Prüfungen kontrollieren beide App-Oberflächen gegen WCAG 2.0, 2.1 und 2.2 auf Stufe A/AA. Playwright prüft außerdem die Tastaturauslösung der Dateiauswahl.

## Offene Aufgaben

- [ ] Internationalisierung (i18n) einführen, zunächst mit englischen Übersetzungen
- [ ] Zugriff auf die Validierung nach Nextcloud-Gruppen oder Rollen beschränkbar machen
- [ ] Warteschlange für parallele Validierungsanfragen einführen

