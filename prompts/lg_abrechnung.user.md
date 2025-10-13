Extrahiere aus dem Beleg folgende Felder:

- issuer_name: Empfängername aus der Zeile welche mit Hospiz-Gruppe beginnt
- invoice_date: Datum aus Zeile welche Zahlungsvorschlag enthält (YYYY-MM-DD)
- payment_purpose: Monat und Jahr aus Zeile welche Zahlungsvorschlag enthält (Abrechnung-MM-YYYY)
- invoice_amount: Gesamt Zahlungsbetrag  (Dezimalpunkt als Trenner, z.B. 1234.56)

Dokumentinhalt:
-----
{{CONTENT}}
-----

Antworte im JSON-Schema:
{{SCHEMA_JSON}}
