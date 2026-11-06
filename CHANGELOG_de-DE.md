# 1.0.0

- Erste Version: Zahlungsart **„SEPA-Lastschrift (GoCardless)“** (`nova_gocardless_sepa`) für Shopware 6.7, bei der Installation inaktiv angelegt
- Zahlung über die gehostete Seite von GoCardless (Billing Request mit Zahlung und SEPA-Mandat `sepa_core`, Billing Request Flow), Kundendaten vorausgefüllt; keine Bankdaten im Shop
- Rückkehr: Billing Request erfüllt → Zahlungsstatus „In Bearbeitung“ (wahlweise „Autorisiert“), Abbruch → Kundenabbruch und Stornierung des offenen Billing Requests
- Webhook `/api/_action/nova-gocardless/webhook` mit Signaturprüfung (HMAC-SHA256, Antwort 498 bei falscher Signatur), idempotent über Ereignis-IDs: Zahlung bestätigt/ausgezahlt → bezahlt, fehlgeschlagen → fehlgeschlagen, storniert → abgebrochen, Rückbuchung → Rückbuchung mit Benachrichtigung in der Administration, Mandatsereignisse als Notiz an der Bestellung
- Statuswechsel über die Zustandsmaschine (kürzester Weg aus den Übergängen in der Datenbank, keine Rückschritte, erstattete Zahlungen bleiben unberührt)
- Einstellungen je Verkaufskanal: Modus Sandbox/Live, Access Token, Webhook-Secret, Status nach Mandat, Zahlungsbeschreibung, optionale eigene Mandatsreferenz, Benachrichtigung
- Konsolenbefehl `nova-gocardless:test` (Token, Modus, Gläubiger, Webhook-Adresse)
- Eigener API-Client (Symfony HttpClient), GoCardless-Version 2015-07-06, Idempotency-Key, Zeitlimits, Protokoll ohne Token und Kundendaten
