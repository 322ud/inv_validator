# INValidator administrieren

Diese Anleitung beschreibt Einrichtung und Betrieb. Zur Bedienung siehe die [Benutzeranleitung](user.md), für API und Implementierung die [Entwicklerdokumentation](developer.md).

## Zugriff und Konfiguration

Die App benötigt PHP ab 8.2. Die Prüfoberfläche steht allen angemeldeten Nextcloud-Benutzern zur Verfügung; eine Einschränkung nach Gruppen oder Rollen ist nicht implementiert. Die Administrationseinstellungen sind nur für Administratoren zugänglich.

Gespeicherte App-Werte haben Vorrang vor `config.php` und den eingebauten Standardwerten.

## Voraussetzungen

### Standardpfade

Ohne explizite Konfiguration verwendet die Anwendung folgende Pfade:

| Komponente | Pfad |
|------------|-------|
| Java | `java` aus dem `PATH` des Webservers; Fallback `/usr/bin/java` |
| Python (optional) | `/opt/kosit/pymupdf-venv/bin/python`, falls ausführbar; sonst `python3` aus dem Webserver-`PATH`, Fallback `/usr/bin/python3` |
| Validator-Zielverzeichnis | `/opt/kosit` |
| Validator JAR | `/opt/kosit/validator-standalone.jar` |
| XRechnung-Installationsverzeichnis | `/opt/kosit/xrechnung` |
| XRechnung-Releases | `/opt/kosit/xrechnung/releases` |
| XRechnung-Repository | `/opt/kosit/xrechnung/current` |
| Szenario-Datei | `/opt/kosit/xrechnung/current/scenarios.xml` (automatisch abgeleitet) |

| Temporäre Arbeitsverzeichnisse | Von Nextcloud über `ITempManager` bereitgestellt; zentrale Einstellung `tempdirectory` und Nextcloud-Fallbacks |

Für PHP-Prozessstart und ZipArchive gibt es keinen separat konfigurierbaren Programmpfad: Beide werden in der PHP-Umgebung des Webservers geprüft. PyMuPDF wird aus der ausgewählten Python-Umgebung geladen; Die unten beschriebene Umgebung `/opt/kosit/pymupdf-venv` wird ohne ausdrücklich gesetzten Python-Pfad automatisch bevorzugt.

Die Admin-Seite unter **Administrationseinstellungen → INValidator** zeigt die tatsächlich ermittelten Pfade und deren Status. Java wird, sofern nicht explizit konfiguriert, über den `PATH` des Webserver-Prozesses gesucht. Für Python wird zuerst die ausführbare virtuelle Umgebung `/opt/kosit/pymupdf-venv/bin/python` berücksichtigt, danach der `PATH`. Die Admin-Seite prüft mit dem konfigurierten Interpreter, ob PyMuPDF importiert werden kann. Nur dann wird PDF als Eingabe angeboten.

Die Pfade für Java und das optionale Python können direkt auf der Admin-Seite bearbeitet und gespeichert werden. Validator-JAR und XRechnung-Repository werden über die folgenden Optionen in der Nextcloud-`config.php` konfiguriert. Die Szenariodatei ist immer `scenarios.xml` innerhalb des konfigurierten XRechnung-Repositorys:

```php
'inv_validator_java_binary' => '/usr/bin/java',
'inv_validator_python_binary' => '/opt/kosit/pymupdf-venv/bin/python',
'inv_validator_jar' => '/opt/kosit/validator-standalone.jar',
'inv_validator_repository' => '/opt/kosit/xrechnung/current',
```

Ohne explizite Konfiguration verwendet die App für den Validator ausschließlich den versionsneutralen Pfad `/opt/kosit/validator-standalone.jar`. Dieser Pfad ist ein von der App verwalteter symbolischer Link auf die aktive Originaldatei nach dem kontrollierten Namensschema `validator-<Version>-standalone.jar`. Link und Ziel müssen innerhalb von `/opt/kosit` liegen.

Validator-JAR und XRechnung-Konfiguration müssen zueinander kompatibel sein. Die App unterstützt derzeit die Validator-Reihe 1.6.x. Die XRechnung-Konfiguration wird nicht mit der App ausgeliefert, sondern vollständig aus den offiziellen KoSIT-Releases installiert.

## Installation

### Java installieren

Benötigt wird Java ab Version 11. Standard: `java` aus dem Webserver-`PATH`, andernfalls `/usr/bin/java`. Der Pfad lässt sich unter **Einstellungen** ändern.

Beispiel für Debian/Ubuntu:

```bash
sudo apt install openjdk-17-jre
```

### PHP und Prozessstart

Die PHP-Umgebung des Webservers muss `proc_open` und die zugehörigen Prozessfunktionen erlauben. Ist `proc_open` in `disable_functions` deaktiviert, muss die Administration bzw. der Hosting-Anbieter die PHP-Konfiguration für den Webserver anpassen. Die CLI-Konfiguration kann davon abweichen.

### PHP ZipArchive

Für die Installation und Aktualisierung der XRechnung-Konfiguration wird zusätzlich die PHP-Erweiterung `zip` (`ZipArchive`) benötigt, unter Debian/Ubuntu zum Beispiel über das zur PHP-Version passende Paket `php-zip`. Nach Änderungen den zuständigen PHP-FPM-Dienst bzw. Webserver neu laden.

### Verzeichnisse und Berechtigungen

Die Standardziele sind `/opt/kosit` für den Validator und `/opt/kosit/xrechnung` einschließlich `releases` für das XRechnung-Szenario. Die Tabellenzeilen zeigen bei abweichender Konfiguration die tatsächlich geprüften Zielverzeichnisse.

Der Webserver-Benutzer benötigt Lese- und Verzeichniszugriffsrechte für Validator-JAR, Szenariodateien und die vollständigen `resources`. Das temporäre Arbeitsverzeichnis muss beschreibbar sein. Für automatische Installation und Updates sind zusätzlich Schreibrechte im Validator-Zielverzeichnis, im übergeordneten Verzeichnis des Repositorypfads und im Unterverzeichnis `releases` nötig. Fehlende Verzeichnisse dürfen nur unter einem zugänglichen, beschreibbaren Elternverzeichnis angelegt werden. Eigentümer bzw. Gruppen und gezielte Rechte passend zur Installation setzen; keine pauschalen Schreibrechte für alle Benutzer vergeben. Container-Mounts und Hosting-Beschränkungen berücksichtigen.

Die Systemprüfung führt kurze Prozess- und Schreibtests mit den Rechten des Webservers aus. Prüfdateien und private Prüfverzeichnisse werden anschließend entfernt. Die Prüfung ersetzt keine vollständige Rechnungsvalidierung und garantiert weder freien Speicherplatz noch die Kompatibilität sämtlicher Szenario-Ressourcen. Bei vorhandenen Dateien ohne ermittelbare Version wird „Version unbekannt“ angezeigt.

### Temporäre Arbeitsdateien

Für jede XML-Prüfung und PDF-Extraktion fordert die App über Nextclouds `ITempManager` ein lokales Arbeitsverzeichnis an. Es enthält die Rechnungs-XML und die erzeugten Prüfberichte beziehungsweise das aus einer PDF extrahierte XML. Die App entfernt diese Arbeitsverzeichnisse nach Abschluss und bei behandelten Fehlern; zusätzlich werden sie von Nextclouds Temp-Verwaltung erfasst.

Es gibt keinen separaten Temp-Pfad der App mehr. Die bisherige Option `inv_validator_temp_directory` wird nicht mehr ausgewertet und kann aus `config.php` entfernt werden. Maßgeblich ist Nextclouds zentrale Temp-Verwaltung, insbesondere `tempdirectory`. Der PHP-Benutzer muss dort schreiben können; zusätzliche Root-Rechte benötigt die App nicht. Alte Arbeitsdateien im bisherigen App-Temp-Verzeichnis werden nicht automatisch migriert oder gelöscht.

Eine kleine Sperrdatei `inv-validator-<Hash des App-Pfads>.lock` bleibt im von Nextcloud ermittelten Temp-Basisverzeichnis. Alle Prüfungen dieser App-Installation verwenden dieselbe Datei, damit weiterhin nur ein Validator-Prozess gleichzeitig läuft. Die Betriebssystem-Sperre wird nach der Prüfung und auch bei einem Prozessende freigegeben. Die Datei wird absichtlich nicht nach jeder Prüfung gelöscht, damit parallele Anfragen nicht unterschiedliche Sperrdateien verwenden. Sie enthält keine Rechnungsdaten.

### KoSIT-Validator und XRechnung-Konfiguration installieren

Die Installation erfolgt in der Admin-Seite unter **Komponenten → Validierung** über **Installieren** in der jeweiligen Komponenten-Zeile. Validator und XRechnung-Konfiguration werden getrennt installiert. Die App ermittelt die neuesten offiziellen GitHub-Releases, lädt das zugehörige `validator-<version>-standalone.jar` und das vollständige XRechnung-Konfigurationspaket. Beide Downloads werden vor ihrer Aktivierung geprüft. Die Validator-JAR wird unter ihrem Originalnamen gespeichert und anschließend durch einen atomaren Austausch des neutralen Symlinks aktiviert; die zuvor aktive Originaldatei bleibt für ein Rollback erhalten. Das Konfigurationspaket wird geprüft, in einem versionierten Verzeichnis bereitgestellt und anschließend über den konfigurierten Repositorypfad aktiviert. PHP benötigt dafür die Erweiterung `ZipArchive`. Der Webserver-Benutzer muss für beide Zielverzeichnisse Schreibrechte besitzen.

Unter **Komponenten → Validierung** lassen sich automatische Updates für **KoSIT-Validator** und **XRechnung-Szenario** unabhängig aktivieren (Standard: aus). Die Schalter erscheinen nur bei vorhandener Komponente, verfügbarem ZipArchive und Schreibrecht für die jeweilige Installation. Unter **Einstellungen** sind die Programmpfade für Java und Python direkt bearbeitbar. Für aktivierte Komponenten wird beim Öffnen der Prüfseite nach Updates gesucht und eine neuere Version automatisch installiert. Manuelle Updates sind unter **Komponenten → Validierung** über **Update auf VERSION** möglich; der Button erscheint nur bei einer erkannten neueren Version.

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
sudo python3 -m venv /opt/kosit/pymupdf-venv
sudo /opt/kosit/pymupdf-venv/bin/python -m pip install pymupdf
```

Ohne ausdrücklich gesetzten Python-Pfad erkennt die App `/opt/kosit/pymupdf-venv/bin/python` automatisch. Anschließend **Systemprüfung wiederholen**. Bereits gespeicherte Python-Pfade (auch `/usr/bin/python3`) behalten Vorrang und müssen gegebenenfalls auf diesen Interpreter geändert werden. Der Webserver-Benutzer benötigt Lese- und Ausführungsrechte auf die Umgebung. Fehlt Python oder PyMuPDF, bleibt der XML-Upload ohne Einschränkung verfügbar und PDF wird nicht angeboten.

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
