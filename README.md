# KoSIT Validator für Nextcloud

Validierung von XML-Dokumenten mit dem KoSIT Validator innerhalb einer Nextcloud-App.

Die App ermöglicht das Hochladen bzw. Einfügen von XML-Dokumenten, führt eine Validierung mit dem KoSIT Validator durch und zeigt den erzeugten HTML-Bericht direkt in der Oberfläche an.

---

## Funktionen

- XML-Validierung über den KoSIT Validator
- Anzeige des HTML-Validierungsberichts
- A4-optimierter Ausdruck und HTML-Download des Prüfberichts
- Prüfung der XML-Struktur vor dem Validator-Aufruf
- Größenbegrenzung für XML-Dateien
- Aufbereitung der Validator-Ergebnisse als JSON-API
- Admin-Seite mit automatisch ermittelten Systempfaden
- Installation des offiziellen Standalone-JAR aus den GitHub-Releases
- Prüfung auf neue KoSIT-Validator-Versionen und direkte Aktualisierung

---

## Voraussetzungen

Die Anwendung verwendet folgende Standardpfade:

| Komponente | Pfad |
|------------|-------|
| Java | `/usr/bin/java` |
| Validator JAR | `/opt/kosit/validator-1.6.2-standalone.jar` |
| Repository | `/opt/kosit` |
| Szenario-Datei | `config/EN16931_(CII)-szenario.xml` innerhalb der App |

Die Admin-Seite unter **Administrationseinstellungen → Weitere Einstellungen**
zeigt die tatsächlich ermittelten Pfade und deren Status. Java wird, sofern
nicht explizit konfiguriert, über den `PATH` des Webserver-Prozesses gesucht.

Die Pfade können direkt auf der Admin-Seite bearbeitet und gespeichert werden.
Gespeicherte Werte werden von Validierung, Installation und Update-Prüfung
sofort verwendet und haben Vorrang vor Einträgen in der Nextcloud-`config.php`.
Solange noch kein Wert über die Admin-Seite gespeichert wurde, bleiben folgende
`config.php`-Optionen als Fallback verfügbar:

```php
'kosit_validator_java_binary' => '/usr/bin/java',
'kosit_validator_jar' => '/opt/kosit/validator-1.6.2-standalone.jar',
'kosit_validator_repository' => '/opt/kosit',
'kosit_validator_scenarios' => '/pfad/zu/eigener/EN16931_(CII)-szenario.xml',
'kosit_validator_temp_directory' => '/tmp/nextcloud-kosit-validator',
```

Validator-JAR und Szenario-Konfiguration müssen zueinander kompatibel sein.
Eine bloß vorhandene Szenario-Datei reicht nicht aus; der Validator prüft sie
beim Start gegen sein Szenario-Schema.

Die mitgelieferte Szenariodatei stammt aus der offiziellen KoSIT-Konfiguration
für XRechnung 3.0.2 vom 31. Januar 2026. Die zugehörigen Schema- und
Schematron-Ressourcen müssen weiterhin vollständig im konfigurierten Repository
vorliegen.

---

## Installation

### Java installieren

Beispiel für Debian/Ubuntu:

```bash
sudo apt install openjdk-17-jre
```

### KoSIT Validator installieren

Die empfohlene Installation erfolgt in der Admin-Seite über **Jetzt
installieren**. Die App ermittelt das neueste offizielle GitHub-Release, lädt
das zugehörige `validator-<version>-standalone.jar` und aktiviert es atomar am
konfigurierten JAR-Pfad. Der Webserver-Benutzer muss für dessen Verzeichnis
Schreibrechte besitzen.

Alternativ kann das JAR weiterhin manuell installiert werden.

Beispielstruktur:

```text
/opt/kosit/
├── validator-1.6.2-standalone.jar
├── EN16931_(CII)-szenario.xml
└── repository/
```

Die Pfade müssen mit den im Code hinterlegten Pfaden übereinstimmen.

---

## Verwendung

1. XML-Datei auswählen oder XML-Inhalt bereitstellen.
2. Validierung starten.
3. KoSIT Validator wird ausgeführt.
4. Die fachliche Bewertung wird aus `rep:accept` beziehungsweise `rep:reject`
   im XML-Bericht gelesen.
5. HTML-Bericht wird im Browser dargestellt.

---

## Validierungsablauf

Vor dem Aufruf des KoSIT Validators werden folgende Prüfungen durchgeführt:

1. XML-Inhalt vorhanden
2. Maximale Größe von 5 MB
3. XML ist wohlgeformt
4. DOCTYPE-Deklarationen werden abgelehnt

Erst danach wird der Validator gestartet.

---

## API

### Validierung

```http
POST /apps/kosit_validator/validate
```

### Request

```json
{
    "xml": "<Invoice>...</Invoice>"
}
```

### Erfolgreiche Antwort

```json
{
    "success": true,
    "valid": true,
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
- Status, Installation und Updates sind ausschließlich für Administratoren
  erreichbar und ebenfalls CSRF-geschützt.
- Es wird höchstens ein Validator-Prozess gleichzeitig ausgeführt.
- Prozessausgaben und Berichte besitzen serverseitige Größenlimits.
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
  - Startet den KoSIT Validator.
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
  - Fragt das neueste offizielle GitHub-Release ab.
  - Prüft Version und Download und installiert das Standalone-JAR atomar.

- `AdminController` / `Settings\\Admin`
  - Stellen die nur für Administratoren erreichbare Status-, Installations-
    und Update-Oberfläche bereit.

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

Technische Validator-Fehler werden serverseitig protokolliert und als HTTP 503
zurückgegeben. Sie werden nicht als fachlich ungültige Rechnung dargestellt.

---

## Bekannte Einschränkungen

- Pfade können von Administratoren bearbeitet werden. Bereits gespeicherte
  App-Werte haben Vorrang vor `config.php` und den eingebauten Standardwerten.
- Keine rollenbasierte Zugriffskontrolle.
- XML-Dateien größer als 5 MB werden abgelehnt.
- Zur Begrenzung der Serverlast wird jeweils nur ein Dokument verarbeitet.
- HTML-Berichte größer als 10 MB werden abgelehnt.

---

## Lizenz

Siehe Lizenzdatei des Projekts.
