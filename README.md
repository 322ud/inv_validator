# Nextcloud-Frontend für den KoSIT-Validator

**Aktuelle Version:** [0.5.1](https://github.com/322ud/kosit_validator/tree/0.5.1)

Prüft XML-Rechnungsdokumente mit dem KoSIT-Validator auf Konformität mit der [XRechnung-Spezifikation](https://de.wikipedia.org/wiki/XRechnung).

Diese App ist ein Nextcloud-Frontend für den separat installierten [KoSIT-Validator](https://github.com/itplr-kosit/validator). Sie übergibt XML-Rechnungsdokumente an den Validator, der sie anhand des konfigurierten Validierungsszenarios auf Konformität mit der [XRechnung-Spezifikation](https://de.wikipedia.org/wiki/XRechnung) prüft. Anschließend zeigt die App den erzeugten HTML-Prüfbericht an.

Der Validator stammt von der [Koordinierungsstelle für IT-Standards (KoSIT)](https://www.xoev.de/). Bei hybriden PDF-Rechnungen extrahiert die App zunächst das eingebettete XML-Rechnungsdokument. Geprüft wird ausschließlich das extrahierte XML, nicht die PDF-Datei.

---

## Funktionen

- Prüfung von XML-Rechnungsdokumenten durch den separat installierten KoSIT-Validator
- Optionale Extraktion eingebetteter Rechnungs-XML aus PDF über PyMuPDF
- Anzeige des vom Validator erzeugten HTML-Prüfberichts
- A4-optimierter Ausdruck und HTML-Download des Prüfberichts
- Prüfung der XML-Struktur vor dem Validator-Aufruf
- Größenbegrenzung für XML-Dateien
- Aufbereitung der Validator-Ergebnisse als JSON-API
- Admin-Seite mit automatisch ermittelten Systempfaden
- Gemeinsame Installation des offiziellen Standalone-JAR und der vollständigen XRechnung-Konfiguration
- Getrennte Anzeige der installierten und neuesten Versionen beider Komponenten
- Sichere, versionierte Aktivierung der XRechnung-Konfiguration

---

## Voraussetzungen

Die Anwendung verwendet folgende Standardpfade:

| Komponente | Pfad |
|------------|-------|
| Java | `/usr/bin/java` |
| Python (optional) | `/usr/bin/python3` |
| Validator JAR | `/opt/kosit/validator-standalone.jar` |
| XRechnung-Repository | `/opt/kosit/xrechnung/current` |
| Szenario-Datei | `/opt/kosit/xrechnung/current/scenarios.xml` (automatisch abgeleitet) |

Die Admin-Seite unter **Administrationseinstellungen → Weitere Einstellungen** zeigt die tatsächlich ermittelten Pfade und deren Status. Java wird, sofern nicht explizit konfiguriert, über den `PATH` des Webserver-Prozesses gesucht. Dasselbe gilt für das optionale Python. Die Admin-Seite prüft mit dem konfigurierten Interpreter, ob PyMuPDF importiert werden kann. Nur dann wird PDF als Eingabe angeboten.

Die Pfade für Java und das optionale Python können direkt auf der Admin-Seite bearbeitet und gespeichert werden. Validator-JAR, XRechnung-Repository und temporäres Verzeichnis werden dort nur angezeigt und über die folgenden Optionen in der Nextcloud-`config.php` konfiguriert. Die Szenariodatei ist immer `scenarios.xml` innerhalb des konfigurierten XRechnung-Repositorys:

```php
'kosit_validator_java_binary' => '/usr/bin/java',
'kosit_validator_python_binary' => '/opt/kosit/pymupdf-venv/bin/python',
'kosit_validator_jar' => '/opt/kosit/validator-standalone.jar',
'kosit_validator_repository' => '/opt/kosit/xrechnung/current',
'kosit_validator_temp_directory' => '/tmp/nextcloud-kosit-validator',
```

Ohne explizite Konfiguration verwendet die App für den Validator ausschließlich den versionsneutralen Pfad `/opt/kosit/validator-standalone.jar`. Dieser Pfad ist ein von der App verwalteter symbolischer Link auf die aktive Originaldatei nach dem kontrollierten Namensschema `validator-<Version>-standalone.jar`. Link und Ziel müssen innerhalb von `/opt/kosit` liegen. Alte `.version`-Dateien werden nicht ausgewertet; eine vorhandene echte Datei am neutralen Pfad wird nicht automatisch migriert und blockiert die Installation.

Validator-JAR und XRechnung-Konfiguration müssen zueinander kompatibel sein. Die App unterstützt derzeit die Validator-Reihe 1.6.x. Die XRechnung-Konfiguration wird nicht mit der App ausgeliefert, sondern vollständig aus den offiziellen KoSIT-Releases installiert.

---

## Installation

### Java installieren

Beispiel für Debian/Ubuntu:

```bash
sudo apt install openjdk-17-jre
```

### KoSIT-Validator und XRechnung-Konfiguration installieren

Die empfohlene Installation erfolgt in der Admin-Seite über **Komponenten installieren**. Die App ermittelt die neuesten offiziellen GitHub-Releases, lädt das zugehörige `validator-<version>-standalone.jar` und das vollständige XRechnung-Konfigurationspaket. Beide Downloads werden vor ihrer Aktivierung geprüft. Die Validator-JAR wird unter ihrem Originalnamen gespeichert und anschließend durch einen atomaren Austausch des neutralen Symlinks aktiviert; die zuvor aktive Originaldatei bleibt für ein Rollback erhalten. Das Konfigurationspaket wird geprüft, in einem versionierten Verzeichnis bereitgestellt und anschließend über den konfigurierten Repositorypfad aktiviert. PHP benötigt dafür die Erweiterung `ZipArchive`. Der Webserver-Benutzer muss für beide Zielverzeichnisse Schreibrechte besitzen.

Ein bereits vorhandenes echtes Verzeichnis am Repositorypfad wird aus Sicherheitsgründen nicht überschrieben. Für eine von der App verwaltete Installation muss der Pfad fehlen oder ein zuvor von der App angelegter symbolischer Link sein.

Beim Update von einer App-Version vor 0.5 muss der Repositorypfad einmalig auf ein neues, noch nicht vorhandenes Ziel wie `/opt/kosit/xrechnung/current` gesetzt werden. Der frühere separate Szenariopfad wird nicht mehr ausgewertet. Anschließend installiert **Komponenten installieren** Szenario und Ressourcen gemeinsam. Eine vorhandene manuelle Konfiguration bleibt unangetastet und kann weiterhin verwendet werden, wenn ihr Verzeichnis unmittelbar `scenarios.xml` und `resources/` enthält; ihre Release-Version wird ohne App-Marker als unbekannt angezeigt.

Alternativ kann das JAR weiterhin manuell installiert werden.

Beispielstruktur:

```text
/opt/kosit/
├── validator-standalone.jar -> validator-1.6.3-standalone.jar
├── validator-1.6.2-standalone.jar
├── validator-1.6.3-standalone.jar
└── xrechnung/
    ├── current -> releases/2026-01-31
    └── releases/
        └── 2026-01-31/
            ├── scenarios.xml
            └── resources/
```

Die Pfade müssen mit den in den Administrationseinstellungen hinterlegten Pfaden übereinstimmen.

### Optionale PDF-Unterstützung

PyMuPDF wird nicht durch die Nextcloud-App installiert. Empfohlen wird eine eigene virtuelle Python-Umgebung:

```bash
python3 -m venv /opt/kosit/pymupdf-venv
/opt/kosit/pymupdf-venv/bin/python -m pip install pymupdf
```

Anschließend wird `/opt/kosit/pymupdf-venv/bin/python` als Python-Pfad in den Administrationseinstellungen gespeichert. Fehlt Python oder PyMuPDF, bleibt der XML-Upload ohne Einschränkung verfügbar und PDF wird nicht angeboten.

---

## Verwendung

1. XML-Rechnungsdokument oder – falls serverseitig verfügbar – PDF mit eingebettetem XML auswählen.
2. Validierung starten.
3. Der separat installierte KoSIT-Validator wird ausgeführt.
4. Die fachliche Bewertung wird aus `rep:accept` beziehungsweise `rep:reject`
   im XML-Bericht gelesen.
5. HTML-Bericht wird im Browser dargestellt.

---

## Validierungsablauf

Vor dem Aufruf des KoSIT-Validators führt das Nextcloud-Frontend folgende technische Prüfungen durch:

1. XML- oder PDF-Inhalt vorhanden
2. Maximale Größe von 5 MB für XML beziehungsweise 20 MB für PDF
3. Bei PDF: genau eine passende eingebettete Rechnungs-XML extrahieren
4. Extrahiertes XML ist maximal 5 MB groß und wohlgeformt
5. DOCTYPE-Deklarationen werden abgelehnt

Erst danach wird der Validator gestartet.

---

## API

### Validierung

```http
POST /apps/kosit_validator/validate
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

---

## Rückgabefelder

| Feld | Bedeutung |
|--------|------------|
| success | Request erfolgreich verarbeitet |
| valid | Fachliche Bewertung aus dem XML-Prüfbericht |
| exitCode | Technischer Rückgabecode des Validator-Prozesses |
| reportHtml | HTML-Bericht des Validators |
| message | Fehlermeldung |

---

## Sicherheit

Aktueller Implementierungsstand:

- Die Oberfläche ist für alle angemeldeten Nextcloud-Benutzer verfügbar.
- Der Validierungsendpunkt kann von jedem angemeldeten Benutzer aufgerufen werden.
- Der Endpunkt ist durch den Nextcloud-CSRF-Token geschützt.
- Status, Installation und Updates sind ausschließlich für Administratoren erreichbar und ebenfalls CSRF-geschützt.
- Es wird höchstens ein Validator-Prozess gleichzeitig ausgeführt.
- Prozessausgaben und Berichte besitzen serverseitige Größenlimits.
- XML-Berichte müssen wohlgeformt sein, den KoSIT-Namensraum verwenden und genau eine eindeutige Bewertung enthalten; `DOCTYPE` ist nicht erlaubt.
- Es existiert derzeit keine Gruppen- oder Rollenprüfung.

Für produktive Umgebungen sollte geprüft werden, ob der Zugriff auf Administratoren oder definierte Benutzergruppen beschränkt werden soll.

---

## Technische Umsetzung

### Backend

- `PageController`
  - Liefert die Hauptansicht.

- `ValidationController`
  - Nimmt XML entgegen.
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

- Upload von XML-Dateien
- AJAX-Aufruf des Validierungsendpunkts
- Anzeige des HTML-Berichts über `iframe`
- Drucken des Berichts über den Browser-Druckdialog
- Herunterladen des aufbereiteten HTML-Berichts
- Darstellung des Validierungsstatus

---

## Fehlerbehandlung

Mögliche Fehler:

- Kein XML übergeben
- XML-Datei zu groß
- Ungültiger XML-Inhalt
- Java nicht gefunden
- Validator-JAR nicht gefunden
- Repository nicht gefunden
- Timeout des Validator-Prozesses
- Inkompatible oder ungültige Szenario-Konfiguration
- Validator ist bereits durch eine andere Prüfung belegt
- Interner Fehler während der Validierung

Technische Validator-Fehler werden serverseitig protokolliert und als HTTP 503 zurückgegeben. Sie werden nicht als fachlich ungültige Rechnung dargestellt.

---

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

Ein reproduzierbares Produktionsarchiv mit SHA-256-Datei erzeugen:

```bash
bash scripts/build-release.sh
```

Das Archiv unter `dist/` enthält ausschließlich die zur Laufzeit benötigten App-Dateien. CI installiert genau dieses Archiv zusätzlich in einer frischen Nextcloud-34-Instanz, aktiviert die App und führt Framework-Smoke-Tests aus.

Die Tests decken insbesondere die Interpretation der KoSIT-Berichte, die Pfadkonfiguration, Validator- und XRechnung-Updates, externe Release-Daten, Prozessabbrüche und konkurrierende Validierungen sowie den HTTP-Vertrag der Validierungs- und Admin-Endpunkte ab.

Bei Pushes und Pull Requests führt GitHub Actions die PHP-Suite mit PHP 8.2, 8.3, 8.4 und 8.5 sowie die JavaScript-Suite mit Node.js 22 aus. Ein zusätzlicher Integrationsjob installiert Nextcloud 34, aktiviert die App und prüft Container, Routen, Templates sowie die Admin- und CSRF-Attribute gegen das echte Framework. Ein weiterer Smoke-Test lädt fest versionierte und per SHA-256 geprüfte KoSIT- und XRechnung-Artefakte, validiert eine offizielle Testrechnung mit dem echten Java-Prozess und prüft außerdem die Ablehnung einer gezielt beschädigten Variante. Der Nextcloud-Job führt zusätzlich mit Playwright den vollständigen Browserfluss vom Login und Datei-Upload bis zum eingebetteten und herunterladbaren Prüfbericht für gültige, fachlich ungültige und nicht wohlgeformte XML-Dateien aus. Der administrative Browserfluss prüft das CSRF-geschützte Speichern und Wiederherstellen der Pfade. Ein Sicherheitslauf bestätigt zusätzlich, dass normale Benutzer zwar validieren, aber keine Admin-Endpunkte aufrufen dürfen und fehlende oder ungültige Request-Tokens die Konfiguration nicht verändern. Automatisierte Axe-Prüfungen kontrollieren beide App-Oberflächen gegen WCAG 2.0, 2.1 und 2.2 auf Stufe A/AA. Playwright prüft außerdem die Tastaturauslösung der Dateiauswahl.

---

## Offene Aufgaben

- [ ] Internationalisierung (i18n) einführen, zunächst mindestens mit deutschen, englischen und französischen Übersetzungen
- [ ] Nutzerspezifische Validierungsszenarien über eine eigene Konfigurationsseite ermöglichen
- [ ] Zugriff auf die Validierung nach Nextcloud-Gruppen oder Rollen beschränkbar machen
- [ ] Warteschlange für parallele Validierungsanfragen einführen
- [ ] Mehrere XML-Rechnungsdokumente in einem Vorgang prüfen
- [ ] Kompatibilitätsmatrix für zukünftige Validator-Hauptversionen erweitern

---

## Bekannte Einschränkungen

- Pfade können von Administratoren bearbeitet werden. Bereits gespeicherte App-Werte haben Vorrang vor `config.php` und den eingebauten Standardwerten.
- Keine rollenbasierte Zugriffskontrolle.
- XML-Dateien größer als 5 MB werden abgelehnt.
- PDF-Dateien größer als 20 MB sowie eingebettete XML-Dateien größer als 5 MB werden abgelehnt.
- Bei PDF-Eingaben wird ausschließlich das eingebettete XML validiert, nicht die PDF/A-Konformität oder die Übereinstimmung mit der sichtbaren Rechnung.
- Zur Begrenzung der Serverlast wird jeweils nur ein Dokument verarbeitet.
- HTML-Berichte größer als 10 MB werden abgelehnt.

---

## Lizenz

Der Quellcode dieses Projekts steht unter der [GNU Affero General Public License, Version 3 oder später](LICENSE).

Die zur Laufzeit installierbare KoSIT-XRechnung-Konfiguration steht unter der [Apache License 2.0](LICENSES/Apache-2.0.txt). Details stehen in den [Drittanbieterhinweisen](THIRD_PARTY_NOTICES.md).
