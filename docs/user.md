# INValidator – Benutzeranleitung

## Rechnung prüfen

1. INValidator in Nextcloud öffnen.
2. Ein XML-Rechnungsdokument oder – wenn angeboten – eine PDF mit eingebettetem Rechnungs-XML auswählen. Alternativ die Datei per Drag-and-drop auf die Prüfseite ziehen.
3. Die Prüfung startet nach der Dateiauswahl automatisch. Den Prüfbericht abwarten.

## Ergebnis verwenden

Der HTML-Prüfbericht erscheint direkt auf der Prüfseite. Er lässt sich über den Browser-Druckdialog A4-optimiert drucken oder als HTML herunterladen.

## Eingaben und Grenzen

- XML-Dateien dürfen höchstens 5 MB groß sein und müssen wohlgeformt sein. DOCTYPE-Deklarationen werden abgelehnt.
- PDF-Dateien dürfen höchstens 20 MB groß sein und müssen genau eine passende eingebettete Rechnungs-XML enthalten. Für diese XML gelten dieselben Anforderungen wie beim direkten XML-Upload.
- Bei PDF-Eingaben wird ausschließlich das eingebettete XML geprüft, nicht die PDF/A-Konformität oder die Übereinstimmung mit der sichtbaren Rechnung.
- Es wird jeweils nur ein Dokument verarbeitet.
- HTML-Berichte größer als 10 MB werden abgelehnt.
