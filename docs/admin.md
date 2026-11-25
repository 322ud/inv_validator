# INValidator administrieren

Diese Anleitung beschreibt Einrichtung und Betrieb. Zur Bedienung siehe die [Benutzeranleitung](user.md), für API und Implementierung die [Entwicklerdokumentation](developer.md).

## Zugriff und Konfiguration

Die App benötigt PHP ab 8.2. Die Prüfoberfläche steht allen angemeldeten Nextcloud-Benutzern zur Verfügung; eine Einschränkung nach Gruppen oder Rollen ist nicht implementiert. Die Administrationseinstellungen sind nur für Administratoren zugänglich.

Gespeicherte App-Werte haben Vorrang vor `config.php` und den eingebauten Standardwerten.

## Voraussetzungen

Die Anwendung verwendet folgende Standardpfade:

| Komponente | Pfad |
|------------|-------|
| Java | `/usr/bin/java` |
| Python (optional) | `/usr/bin/python3` |
| Validator JAR | `/opt/kosit/validator-standalone.jar` |
| XRechnung-Repository | `/opt/kosit/xrechnung/current` |
| Szenario-Datei | `/opt/kosit/xrechnung/current/scenarios.xml` (automatisch abgeleitet) |

Die Admin-Seite unter **Administrationseinstellungen → INValidator** zeigt die tatsächlich ermittelten Pfade und deren Status. Java wird, sofern nicht explizit konfiguriert, über den `PATH` des Webserver-Prozesses gesucht. Dasselbe gilt für das optionale Python. Die Admin-Seite prüft mit dem konfigurierten Interpreter, ob PyMuPDF importiert werden kann. Nur dann wird PDF als Eingabe angeboten.

Die Pfade für Java und das optionale Python können direkt auf der Admin-Seite bearbeitet und gespeichert werden. Validator-JAR, XRechnung-Repository und temporäres Verzeichnis werden dort nur angezeigt und über die folgenden Optionen in der Nextcloud-`config.php` konfiguriert. Die Szenariodatei ist immer `scenarios.xml` innerhalb des konfigurierten XRechnung-Repositorys:

```php
'inv_validator_java_binary' => '/usr/bin/java',
'inv_validator_python_binary' => '/opt/kosit/pymupdf-venv/bin/python',
'inv_validator_jar' => '/opt/kosit/validator-standalone.jar',
'inv_validator_repository' => '/opt/kosit/xrechnung/current',
'inv_validator_temp_directory' => '/tmp/nextcloud-inv-validator',
```

Ohne explizite Konfiguration verwendet die App für den Validator ausschließlich den versionsneutralen Pfad `/opt/kosit/validator-standalone.jar`. Dieser Pfad ist ein von der App verwalteter symbolischer Link auf die aktive Originaldatei nach dem kontrollierten Namensschema `validator-<Version>-standalone.jar`. Link und Ziel müssen innerhalb von `/opt/kosit` liegen.

Validator-JAR und XRechnung-Konfiguration müssen zueinander kompatibel sein. Die App unterstützt derzeit die Validator-Reihe 1.6.x. Die XRechnung-Konfiguration wird nicht mit der App ausgeliefert, sondern vollständig aus den offiziellen KoSIT-Releases installiert.

## Installation

### Java installieren

Beispiel für Debian/Ubuntu:

```bash
sudo apt install openjdk-17-jre
```

### PHP und Prozessstart

Die PHP-Umgebung des Webservers muss `proc_open` und die zugehörigen Prozessfunktionen erlauben. Ist `proc_open` in `disable_functions` deaktiviert, muss die Administration bzw. der Hosting-Anbieter die PHP-Konfiguration für den Webserver anpassen. Die CLI-Konfiguration kann davon abweichen. Für die Installation und Aktualisierung der XRechnung-Konfiguration wird zusätzlich die PHP-Erweiterung `zip` (`ZipArchive`) benötigt, unter Debian/Ubuntu zum Beispiel über das zur PHP-Version passende Paket `php-zip`. Nach Änderungen den zuständigen PHP-FPM-Dienst bzw. Webserver neu laden.

### Verzeichnisse und Berechtigungen

Der Webserver-Benutzer benötigt Lese- und Verzeichniszugriffsrechte für Validator-JAR, Szenariodateien und die vollständigen `resources`. Das temporäre Arbeitsverzeichnis muss beschreibbar sein. Für automatische Installation und Updates sind zusätzlich Schreibrechte im Validator-Zielverzeichnis, im übergeordneten Verzeichnis des Repositorypfads und im Unterverzeichnis `releases` nötig. Fehlende Verzeichnisse dürfen nur unter einem zugänglichen, beschreibbaren Elternverzeichnis angelegt werden. Eigentümer bzw. Gruppen und gezielte Rechte passend zur Installation setzen; keine pauschalen Schreibrechte für alle Benutzer vergeben. Container-Mounts und Hosting-Beschränkungen berücksichtigen.

Die Systemprüfung führt kurze Prozess- und Schreibtests mit den Rechten des Webservers aus. Prüfdateien und private Prüfverzeichnisse werden anschließend entfernt. Die Prüfung ersetzt keine vollständige Rechnungsvalidierung und garantiert weder freien Speicherplatz noch die Kompatibilität sämtlicher Szenario-Ressourcen. Bei vorhandenen Dateien ohne ermittelbare Version wird „—“ angezeigt.

### KoSIT-Validator und XRechnung-Konfiguration installieren

Die Installation erfolgt in der Admin-Seite unter **System** über **Installieren** in der jeweiligen Komponenten-Zeile. Validator und XRechnung-Konfiguration werden getrennt installiert. Die App ermittelt die neuesten offiziellen GitHub-Releases, lädt das zugehörige `validator-<version>-standalone.jar` und das vollständige XRechnung-Konfigurationspaket. Beide Downloads werden vor ihrer Aktivierung geprüft. Die Validator-JAR wird unter ihrem Originalnamen gespeichert und anschließend durch einen atomaren Austausch des neutralen Symlinks aktiviert; die zuvor aktive Originaldatei bleibt für ein Rollback erhalten. Das Konfigurationspaket wird geprüft, in einem versionierten Verzeichnis bereitgestellt und anschließend über den konfigurierten Repositorypfad aktiviert. PHP benötigt dafür die Erweiterung `ZipArchive`. Der Webserver-Benutzer muss für beide Zielverzeichnisse Schreibrechte besitzen.

Unter **Automatische Updates beim Starten der App** lassen sich **KoSIT-Validator** und **XRechnung-Konfiguration** unabhängig per Schiebeschalter aktivieren. Für aktivierte Komponenten wird beim Öffnen der Prüfseite nach Updates gesucht und eine neuere Version automatisch installiert. Manuelle Updates sind unter **System** über **Update auf VERSION** möglich; der Button erscheint nur bei einer erkannten neueren Version.

Alternativ kann das JAR manuell installiert werden.

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

Dann muss `/opt/kosit/pymupdf-venv/bin/python` als Python-Pfad in den Administrationseinstellungen gesetzt werden. Fehlt Python oder PyMuPDF, bleibt der XML-Upload ohne Einschränkung verfügbar und PDF wird nicht angeboten.

## Deinstallation

Nach einer Installation oder Aktualisierung externer Komponenten speichert die App pro Komponente einen persistenten Nachweis in der Nextcloud-App-Konfiguration (`external_component_validator` bzw. `external_component_configuration`). Er enthält Version, Systemzeit in UTC, Installationspfade und Prüfsummen der von der App angelegten Dateien. Die Daten liegen in der Nextcloud-Datenbank, außerhalb des App-Pakets. Vor dem Bereitstellen neuer Dateien wird der Nachweis als vorbereitet gespeichert und nach der Aktivierung als installiert markiert. Frühere, vor Einführung dieser Funktion angelegte Dateien werden nicht nachträglich als Eigentum der App eingestuft.

Vor dem Entfernen der App unter **Administrationseinstellungen → INValidator → Deinstallation vorbereiten** für jede aufgeführte Komponente entscheiden: Nur das Einschalten des jeweiligen Schalters und das Absenden des zugehörigen Formulars starten deren Deinstallation. Die App entfernt nur nachgewiesene unveränderte Dateien und verwaltete Links; fremde, nachträglich ergänzte oder veränderte Dateien werden nicht gelöscht. Ohne vorherige Bestätigung auf der Admin-Seite bleiben die Komponenten erhalten.

## Release-Artefakte prüfen

Alle Anhänge herunterladen und zuerst die Prüfsummen prüfen. Die Archivsignatur kann zusätzlich mit dem Zertifikat geprüft werden:

```bash
sha256sum --check inv_validator-VERSION.tar.gz.sha256
openssl x509 -in inv_validator-VERSION.tar.gz.crt -pubkey -noout > public.pem
openssl dgst -sha512 -verify public.pem \
  -signature inv_validator-VERSION.tar.gz.sig inv_validator-VERSION.tar.gz
```

Diese OpenSSL-Prüfung bestätigt die Signatur zum angegebenen Zertifikat; Nextcloud prüft zusätzlich dessen Vertrauenskette.