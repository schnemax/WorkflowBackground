Extrahiere aus einer deutschen Eingangsrechnung folgende Felder:

- issuer_name: Name des Rechnungsstellers (juristischer Name, ohne Rechtsformzusatz nur wenn eindeutig)
- invoice_date: Rechnungsdatum (YYYY-MM-DD)
- invoice_number: Rechnungsnummer (Originalschreibweise)
- invoice_amount: Gesamtbetrag brutto (Dezimalpunkt als Trenner, z.B. 1234.56)
- issuer_iban: IBAN des Rechnungsstellers (ohne Leerzeichen)
- issuer_bic: BIC des Rechnungsstellers (ohne Leerzeichen)
- payment_purpose: Verwendungszweck/Kunden-/Mandatsreferenz, wenn vorhanden
- direct_debit: true, wenn SEPA-Lastschrift/Einzug vereinbart ist; sonst false.

Dokumentinhalt:
-----
{{CONTENT}}
-----

Antworte im JSON-Schema:
{{SCHEMA_JSON}}
