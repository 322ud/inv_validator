# KoSIT Validator für Nextcloud

Validierung von XML-Dokumenten mit dem KoSIT Validator innerhalb einer Nextcloud-App.

Die App ermöglicht das Hochladen bzw. Einfügen von XML-Dokumenten, führt eine Validierung mit dem KoSIT Validator durch und zeigt den erzeugten HTML-Bericht direkt in der Oberfläche an.

---

## Funktionen

- XML-Validierung über den KoSIT Validator
- Anzeige des HTML-Validierungsberichts
- Prüfung der XML-Struktur vor dem Validator-Aufruf
- Größenbegrenzung für XML-Dateien
- Aufbereitung der Validator-Ergebnisse als JSON-API

---

## Voraussetzungen

Die aktuelle Implementierung verwendet feste Systempfade:

| Komponente | Pfad |
|------------|-------|
| Java | `/usr/bin/java` |
| Validator JAR | `/opt/kosit/validator-1.6.2-standalone.jar` |
| Repository | `/opt/kosit` |
| Szenario-Datei | `/opt/kosit/scenarios.xml` |

Diese Pfade müssen auf dem Zielsystem vorhanden sein.

Die Pfade sind derzeit nicht über die Nextcloud-App-Konfiguration anpassbar.

---

## Installation

### Java installieren

Beispiel für Debian/Ubuntu:

```bash
sudo apt install openjdk-17-jre
```

### KoSIT Validator installieren

Beispielstruktur:

```text
/opt/kosit/
├── validator-1.6.2-standalone.jar
├── scenarios.xml
└── repository/
```

Die Pfade müssen mit den im Code hinterlegten Pfaden übereinstimmen.

---

## Verwendung

1. XML-Datei auswählen oder XML-Inhalt bereitstellen.
2. Validierung starten.
3. KoSIT Validator wird ausgeführt.
4. Ergebnis wird angezeigt.
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
| valid | Fachliche Validierung erfolgreich |
| exitCode | Rückgabecode des Validators |
| reportHtml | HTML-Bericht des Validators |
| message | Fehlermeldung |

---

## Sicherheit

Aktueller Implementierungsstand:

- Die Oberfläche ist für alle angemeldeten Nextcloud-Benutzer verfügbar.
- Der Validierungsendpunkt kann von jedem angemeldeten Benutzer aufgerufen werden.
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
  - Liest den HTML-Bericht ein.
  - Bereinigt temporäre Dateien.

- `ValidationResult`
  - DTO für das Validierungsergebnis.

### Frontend

- Upload von XML-Dateien
- AJAX-Aufruf des Validierungsendpunkts
- Anzeige des HTML-Berichts über `iframe`
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
- Interner Fehler während der Validierung

---

## Bekannte Einschränkungen

- Pfade sind fest im Code hinterlegt.
- Keine Konfiguration über die Nextcloud-Oberfläche.
- Keine rollenbasierte Zugriffskontrolle.
- XML-Dateien größer als 5 MB werden abgelehnt.
- HTML-Bericht wird unverändert vom Validator übernommen.

---

## Lizenz

Siehe Lizenzdatei des Projekts.
