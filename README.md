# INValidator

Validiert XML-Rechnungsdokumente anhand eines XRechnung-Szenarios

INValidator ist ein Nextcloud-Frontend für den [KoSIT-Validator](https://github.com/itplr-kosit/validator). Die App übergibt XML-Rechnungsdokumente an den Validator, der sie anhand des konfigurierten Validierungsszenarios prüft und zeigt den erzeugten HTML-Prüfbericht an.

Optional kann die App ein in hybriden PDF-Rechnungen eingebettetes XML-Rechnungsdokument extrahieren. Geprüft wird ausschließlich das extrahierte XML, nicht die PDF-Datei.

## Dokumentation

- [Benutzeranleitung](docs/user.md): Rechnungen prüfen, Berichte verwenden und Eingabegrenzen beachten.
- [Administrationsanleitung](docs/admin.md): Voraussetzungen, Installation, Konfiguration, Updates und Deinstallation.
- [Entwicklerdokumentation](docs/developer.md): API, technische Abläufe, Tests und Release-Signierung.

## Lizenz

Der Quellcode dieses Projekts steht unter der [GNU Affero General Public License, Version 3 oder später](LICENSE).

Die zur Laufzeit installierbare KoSIT-XRechnung-Konfiguration steht unter der [Apache License 2.0](LICENSES/Apache-2.0.txt). Details stehen in den [Drittanbieterhinweisen](THIRD_PARTY_NOTICES.md).
