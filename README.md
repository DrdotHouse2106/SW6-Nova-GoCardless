# Nova GoCardless

[![Spendier mir einen Kaffee](https://img.shields.io/badge/☕_Spendier_mir_einen_Kaffee-PayPal-0070ba?style=for-the-badge&logo=paypal&logoColor=white)](https://paypal.me/DrdotHouse)

*[English version](README.en.md)*

Shopware-6.7-Plugin für die Zahlungsart **„SEPA-Lastschrift (GoCardless)“**. Der Kunde erteilt das
SEPA-Lastschriftmandat auf der **gehosteten Seite von GoCardless** (Billing Request Flow) – IBAN und
Kontoinhaber gibt er nur dort ein, der Shop sieht und speichert **keine Bankdaten**. Den weiteren
Zahlungsstatus (eingezogen, fehlgeschlagen, Rückbuchung) meldet GoCardless per **Webhook**.

> **Stand 1.0.0:** Die Logik ist mit Unit-Tests und statischer Analyse geprüft, aber noch nicht gegen die echte
> GoCardless-API gelaufen. Bitte zuerst im **Sandbox-Modus** testen (Anleitung unten), bevor Sie Live schalten.

## Funktionen

- **Zahlungsart** „SEPA-Lastschrift (GoCardless)“ (technischer Name `nova_gocardless_sepa`), wird bei der
  Installation **inaktiv** angelegt.
- **Gehostete Zahlungsseite:** Je Bestellung ein GoCardless *Billing Request* (Zahlung + SEPA-Mandat,
  Schema `sepa_core`) und ein *Billing Request Flow*. Name, E-Mail und Rechnungsadresse sind vorausgefüllt
  (der Kunde kann sie dort ändern), Sprache aus der Bestellung.
- **Zahlungsstatus in Shopware** über die Zustandsmaschine – Flows, Statushistorie und Status-Mails funktionieren
  wie gewohnt (siehe [Zahlungsstatus](#zahlungsstatus)).
- **Webhook** mit Signaturprüfung (HMAC-SHA256, `hash_equals`), Antwort **498** bei falscher Signatur,
  **jedes Ereignis nur einmal** verarbeitet (Ereignis-IDs werden gespeichert), Reihenfolge egal.
- **Rückbuchungen und fehlgeschlagene Lastschriften:** Status „Rückbuchung“ bzw. „Fehlgeschlagen“, Notiz am
  internen Kommentar der Bestellung und Benachrichtigung in der Administration (Glocke).
- **Mandatsereignisse** (storniert, fehlgeschlagen, abgelaufen …) als Notiz an der Bestellung.
- **Mehrere Verkaufskanäle:** Zugang je Kanal einstellbar (z. B. verschiedene GoCardless-Konten); ein
  Webhook-Endpunkt für alle.
- **Konsole:** `nova-gocardless:test` prüft Token und Modus, zeigt den Gläubiger und die Webhook-Adresse.
- Eigener, schlanker API-Client (Symfony HttpClient, kein SDK), Zeitlimits, Idempotency-Key bei jedem POST,
  Fehlerprotokoll ohne Token und ohne Kundendaten.

## Ablauf aus Kundensicht

1. Kasse: Zahlungsart „SEPA-Lastschrift (GoCardless)“ wählen. Unter der Zahlungsart steht ein kurzer Hinweis
   auf die Weiterleitung und die Vorabankündigung.
2. „Zahlungspflichtig bestellen“ – die Bestellung wird angelegt, der Kunde landet auf der Seite von GoCardless.
3. Dort prüft er Name, E-Mail und Adresse, gibt IBAN/Kontoinhaber ein und bestätigt das Mandat.
4. GoCardless leitet zurück zum Shop → Bestellabschluss-Seite. Zahlungsstatus: **In Bearbeitung** (oder
   **Autorisiert**, je nach Einstellung).
5. GoCardless schickt dem Kunden die Mandatsbestätigung und die **Vorabankündigung** per E-Mail und zieht den
   Betrag ein. Nach erfolgreichem Einzug (meist wenige Werktage) wird die Zahlung **Bezahlt**.

Bricht der Kunde auf der GoCardless-Seite ab (oder kommt mit dem Zurück-Knopf zurück), setzt Shopware die
Zahlung auf **Abgebrochen** und bietet wie gewohnt an, die Zahlungsart zu wechseln bzw. es erneut zu
versuchen. Der offene Billing Request wird bei GoCardless storniert.

## Installation

**Über den Nova Updater:** *Erweiterungen → Meine Erweiterungen → Plugin-Katalog* → „Nova GoCardless“ →
*Installieren*. Danach aktivieren.

**Per ZIP:** Das ZIP `NovaGoCardless-<Version>.zip` aus den
[Releases](https://github.com/DrdotHouse2106/SW6-Nova-GoCardless/releases) unter *Erweiterungen → Meine
Erweiterungen → Erweiterung hochladen* hochladen, installieren und aktivieren. Danach den Cache leeren.

Nach der Installation ist die Zahlungsart **inaktiv** und keinem Verkaufskanal zugeordnet.

## Einrichten – Schritt für Schritt

1. **GoCardless-Konto:** Für den Test ein Sandbox-Konto anlegen (<https://manage-sandbox.gocardless.com/signup>),
   für den Echtbetrieb das normale Konto (<https://manage.gocardless.com>). Sandbox und Live sind getrennt –
   jedes hat eigene Tokens und Webhooks.
2. **Access Token:** im GoCardless-Dashboard *Developers → Create → Access token*, Bereich **Read-write**.
3. **Plugin konfigurieren:** *Erweiterungen → Meine Erweiterungen → Nova GoCardless → Konfigurieren*. Modus
   (Sandbox/Live) und Access Token eintragen. Oben „Alle Verkaufskanäle“ für eine gemeinsame Einstellung oder
   einen Kanal für abweichende Werte.
4. **Verbindung prüfen:** `bin/console nova-gocardless:test` – zeigt Modus, Gläubiger (Creditor) und die
   Webhook-Adresse.
5. **Webhook einrichten** (siehe unten) und das Secret in der Plugin-Konfiguration eintragen.
6. **Zahlungsart aktivieren** (das GoCardless-Logo setzt das Plugin selbst, ein eigenes Bild bleibt): *Einstellungen → Zahlungsarten → SEPA-Lastschrift (GoCardless)* → aktiv; dann im
   Verkaufskanal unter *Zahlung und Versand* zuordnen. Empfohlen: eine **Verfügbarkeitsregel** „Währung ist
   Euro“ (und ggf. Rechnungsland im SEPA-Raum) – andere Währungen lehnt das Plugin ab.
7. **Datenschutzerklärung** ergänzen (siehe [Datenschutz](#datenschutz)).

### Webhook einrichten

1. GoCardless-Dashboard → *Developers → Create → Webhook endpoint*.
2. **URL:** `https://<ihre-shop-domain>/api/_action/nova-gocardless/webhook`
   (Domain des Shops bzw. der Administration, `https` mit vollständiger Zertifikatskette – GoCardless sendet
   nicht an `http`). `bin/console nova-gocardless:test` zeigt die Adresse auf Basis von `APP_URL`.
3. Das angezeigte **Secret** kopieren und in der Plugin-Konfiguration bei **Webhook-Secret** eintragen.
4. Im Dashboard „Send test webhook“ ausprobieren: Ein Test mit richtigem Secret wird mit 204 (kein passendes
   Ereignis) beantwortet, mit falschem Secret mit 498.

Der Endpunkt braucht keine Anmeldung; geschützt ist er allein durch die Signatur. Eine Firewall darf Anfragen
von den Absender-IP-Adressen von GoCardless nicht blockieren. Ohne funktionierenden Webhook bleiben Zahlungen auf
„In Bearbeitung“ und werden nie „Bezahlt“.

## Einstellungen (je Verkaufskanal)

| Einstellung | Standard | Bedeutung |
|---|---|---|
| Modus | Sandbox | Sandbox (`https://api-sandbox.gocardless.com`) oder Live (`https://api.gocardless.com`) |
| Access Token | leer | Token aus dem GoCardless-Dashboard (Read-write), passend zum Modus |
| Webhook-Secret | leer | Secret des Webhook-Endpunkts; je GoCardless-Konto bzw. Endpunkt eines |
| Zahlungsstatus nach erteiltem Mandat | In Bearbeitung | oder „Autorisiert“ – bezahlt ist erst der eingezogene Betrag |
| Zahlungsbeschreibung | `Bestellung {orderNumber}` | auf der GoCardless-Seite und in der Vorabankündigung; Platzhalter `{orderNumber}`, `{shopName}`; höchstens 140 Zeichen |
| Eigene Mandatsreferenz (Präfix) | leer | Präfix + Bestellnummer als Mandatsreferenz (A–Z, 0–9, „-“, max. 35 Zeichen). **Nur nutzen, wenn der GoCardless-Support eigene Referenzen freigeschaltet hat**, sonst schlägt die Zahlung fehl. Leer = GoCardless vergibt die Referenz |
| Administration benachrichtigen | an | Benachrichtigung bei fehlgeschlagener Lastschrift und Rückbuchung (Notiz an der Bestellung gibt es immer) |

Ein „Verbindung testen“-Knopf in der Administration fehlt bewusst (er bräuchte einen eigenen Admin-Build);
dafür gibt es den Konsolenbefehl.

## Zahlungsstatus

| Ereignis bei GoCardless | Zahlungsstatus in Shopware | Zusätzlich |
|---|---|---|
| Rückkehr vom Flow, Billing Request erfüllt (oder Webhook `billing_requests.fulfilled`) | In Bearbeitung / Autorisiert | Mandat- und Zahlungs-ID werden gespeichert |
| Abbruch auf der GoCardless-Seite | Abgebrochen (durch Shopware) | Billing Request wird storniert |
| `payments.confirmed`, `payments.paid_out` | **Bezahlt** | Notiz „Lastschrift bestätigt“ |
| `payments.failed` | Fehlgeschlagen | Notiz + Benachrichtigung |
| `payments.late_failure_settled` | Rückbuchung (wenn schon bezahlt), sonst Fehlgeschlagen | Notiz + Benachrichtigung |
| `payments.cancelled` | Abgebrochen | Notiz |
| `payments.charged_back` | **Rückbuchung** | Notiz + Benachrichtigung |
| `payments.chargeback_cancelled` | Bezahlt | Notiz |
| `payments.resubmission_requested`, `chargeback_settled` | unverändert | Notiz |
| `billing_requests.cancelled` / `failed` | Abgebrochen / Fehlgeschlagen (nur solange nicht bezahlt) | |
| `mandates.cancelled`, `failed`, `expired`, `blocked`, `replaced`, `transferred` | unverändert | Notiz an allen Bestellungen mit diesem Mandat |

Regeln dahinter:

- **Kein Rückschritt:** Ist eine Zahlung schon „Bezahlt“, setzt ein verspätetes Ereignis sie nicht zurück auf
  „In Bearbeitung“; „Abgebrochen“ wird nach „Bezahlt“ ignoriert. **Erstattete** Zahlungen fasst das Plugin nie an.
- Die nötigen Übergänge werden aus der Zustandsmaschine gelesen (kürzester Weg, höchstens zwei Schritte, nie
  über „Abgebrochen“/„Fehlgeschlagen“/„Erstattet“). Beispiel: „Fehlgeschlagen“ → „Bezahlt“ (erneuter Einzug) =
  *Wieder öffnen* + *Bezahlt*.
- Ereignisse ohne passende Bestellung (z. B. Zahlungen, die nicht aus dem Shop stammen) werden ignoriert.
- Kennt der Shop eine Zahlungs-ID noch nicht (Webhook „fulfilled“ verpasst), ruft das Plugin die Zahlung bei
  GoCardless ab und ordnet sie über die Metadaten (`shopware_transaction`) oder das Mandat zu.
- Scheitert die Verarbeitung, antwortet der Endpunkt mit 500; GoCardless wiederholt den Webhook.
- **Erstattungen** laufen im GoCardless-Dashboard; Shopwares Erstattungsfunktion wird nicht unterstützt.

## Konsole

```bash
bin/console nova-gocardless:test                       # alle Verkaufskanäle (gleiche Zugänge nur einmal)
bin/console nova-gocardless:test --sales-channel=<ID>  # nur ein Kanal
```

## Sandbox-Test

1. Modus **Sandbox**, Sandbox-Token und -Webhook-Secret eintragen, `bin/console nova-gocardless:test`.
2. Der Webhook braucht eine öffentlich erreichbare https-Adresse. Lokal geht das z. B. über einen Tunnel
   (ngrok, Cloudflare Tunnel) auf den Shop.
3. Testbestellung mit der Zahlungsart aufgeben. Auf der GoCardless-Seite eine **Test-IBAN** verwenden, z. B.
   `DE89370400440532013000` (beliebiger Name und Adresse).
4. Rückkehr zum Shop → Zahlungsstatus „In Bearbeitung“. In der Bestellung steht unter *Interner Kommentar*
   bzw. in der Statushistorie der Verlauf.
5. Im Sandbox-Dashboard die Zahlung öffnen und unter *Developers → Scenario simulators* z. B. „Payment
   confirmed“, „Payment paid out“, „Payment failed“ oder „Payment charged back“ auslösen. Der Webhook kommt
   nach wenigen Sekunden; im Dashboard unter *Developers → Webhooks* sehen Sie Zustellung und Antwortcode.
6. Abbruch testen: auf der GoCardless-Seite „Abbrechen“/Zurück → Shopware zeigt die Fehlerseite mit
   „Zahlungsart ändern“.

## Datenschutz

Mit der Zahlungsart übermittelt der Shop an GoCardless: **Name, E-Mail-Adresse und Rechnungsadresse** (zum
Vorausfüllen), **Bestellnummer, Betrag und Währung** sowie eine technische Transaktions-ID. **Bankdaten**
(IBAN, Kontoinhaber) erfasst GoCardless direkt auf seiner Seite; der Shop erhält sie nicht. GoCardless
verarbeitet die Daten auch zu eigenen Zwecken (z. B. Betrugsprävention, Prüfung des Kontos) und ist dafür
selbst verantwortlich; für Zahler im EWR ist das die GoCardless SAS, 7 rue de Madrid, 75008 Paris, Frankreich.
Datenschutzhinweise für Zahler: <https://gocardless.com/privacy/payers/>.

Das Plugin speichert in der Tabelle `nova_gocardless_transaction` nur die Zuordnung Bestelltransaktion ↔
GoCardless-IDs (Billing Request, Mandat, Zahlung), Modus und letzten GoCardless-Status, in
`nova_gocardless_event` die IDs verarbeiteter Webhook-Ereignisse (nach 90 Tagen gelöscht). Keine IBAN, keine
Kontodaten. Warum eine eigene Tabelle statt Zusatzfeldern an der Transaktion: Webhooks suchen über Zahlungs-
und Mandats-ID, dafür brauchen diese Spalten einen Datenbankindex; außerdem bleibt die Zuordnung bei einer
Deinstallation sauber entfernbar.

**Hinweis für die Datenschutzerklärung:** Nennen Sie GoCardless als Zahlungsdienstleister (Zweck:
Abwicklung der SEPA-Lastschrift, Rechtsgrundlage Art. 6 Abs. 1 lit. b DSGVO; eigene Verarbeitung von
GoCardless z. B. zur Betrugsprävention). Mit **Nova Rechtstexte ab 1.8.8** wird der Baustein `gocardless`
automatisch eingesetzt, sobald Nova GoCardless installiert und aktiv und die Zahlungsart im Verkaufskanal aktiv
ist. Das Plugin und diese Beschreibung sind keine Rechtsberatung.

## Hinweise

- **Nur Euro:** SEPA-Lastschrift gibt es nur in EUR. Bei anderer Währung bricht die Zahlung mit Fehler ab –
  deshalb die Verfügbarkeitsregel setzen.
- **Ein Mandat je Bestellung:** Jede Bestellung erzeugt einen eigenen Billing Request mit neuem Mandat.
  Wiederkehrende Zahlungen oder das Wiederverwenden eines Mandats für Folgebestellungen sind nicht enthalten.
- **Lieferung erst nach Zahlungseingang?** Das entscheiden Sie im Ablauf (z. B. Flow „Zahlungsstatus wechselt
  auf Bezahlt“). Bei Lastschrift ist ein Einzug bis zur Rückbuchungsfrist (8 Wochen) nicht endgültig.
- **AGB:** Prüfen Sie Ihren AGB-Text zur Lastschrift (z. B. Frist der Vorabankündigung: bei GoCardless in der
  Regel mindestens 3 Werktage).
- Zeitlimit je Anfrage an GoCardless 15 Sekunden; bei Netzwerkfehler oder 5xx genau eine Wiederholung mit
  demselben Idempotency-Key (keine doppelten Zahlungen).

## Entwicklung

Unit-Tests der reinen Logik (Signaturprüfung, Ereignis-/Statusabbildung, Statusübergänge, Betragsumrechnung,
Payload-Bau) laufen ohne Shopware-Kernel:

```bash
php phpunit.phar   # oder vendor/bin/phpunit
```

## Häufige Probleme

### Zahlung bleibt auf „In Bearbeitung“

**Ursache:** Webhook nicht eingerichtet, falsches Secret (GoCardless zeigt Antwort 498), Webhook-URL nicht
öffentlich/kein gültiges Zertifikat, oder der Einzug ist einfach noch nicht erfolgt (dauert einige Werktage)

**Lösung:** Im GoCardless-Dashboard unter *Developers → Webhooks* Zustellungen und Antwortcodes prüfen;
Secret neu eintragen; fehlgeschlagene Webhooks dort erneut senden

### „GoCardless ist nicht eingerichtet“ beim Bestellen

**Ursache:** Kein Access Token für den Verkaufskanal

**Lösung:** Token eintragen (auch die Einstellung „Alle Verkaufskanäle“ zählt), `bin/console nova-gocardless:test`

### Fehler 401 beim Test

**Ursache:** Token falsch oder für den anderen Modus (Sandbox-Token funktionieren nur im Sandbox-Modus und
umgekehrt)

**Lösung:** Modus und Token prüfen

### Zahlung schlägt mit Fehler zur Referenz fehl

**Ursache:** „Eigene Mandatsreferenz“ gesetzt, aber bei GoCardless nicht freigeschaltet

**Lösung:** Feld leeren oder Freischaltung beim GoCardless-Support anfragen

## Deinstallation

Die Zahlungsart wird nur deaktiviert (Bestellungen verweisen auf sie). Ohne „Daten behalten“ werden die
Tabellen `nova_gocardless_transaction` und `nova_gocardless_event` sowie die Plugin-Konfiguration gelöscht.
Offene Lastschriften laufen bei GoCardless weiter – Status-Updates kommen dann aber nicht mehr im Shop an.

## Entstehung

Dieses Plugin ist mit Unterstützung von KI entstanden (Claude von Anthropic). Die Anforderungen kommen aus dem täglichen Betrieb mehrerer Shopware-Shops; der Code wurde mit KI geschrieben und überarbeitet. Geprüft wird er mit automatischen Tests und statischer Analyse (soweit im Repository vorhanden), mit regelmäßigen Sicherheitsprüfungen des Codes und im Echtbetrieb in den eigenen Shops. Trotzdem gilt: Nutzung auf eigene Verantwortung und ohne Garantie (siehe Lizenz). Fehler und Verbesserungsvorschläge bitte als Issue melden. Mehr zum Hintergrund: [SW6-Nova-Plugins](https://github.com/DrdotHouse2106/SW6-Nova-Plugins).

## Lizenz

MIT – siehe [LICENSE](LICENSE).
