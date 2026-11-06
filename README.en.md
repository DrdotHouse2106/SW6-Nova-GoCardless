# Nova GoCardless

[![Buy me a coffee](https://img.shields.io/badge/☕_Buy_me_a_coffee-PayPal-0070ba?style=for-the-badge&logo=paypal&logoColor=white)](https://paypal.me/DrdotHouse)

*[Deutsche Version](README.md)*

Shopware 6.7 plugin for the payment method **"SEPA Direct Debit (GoCardless)"**. The customer sets up the SEPA
Direct Debit mandate on **GoCardless' hosted page** (Billing Request Flow) – IBAN and account holder are only
entered there, the shop never sees or stores **bank details**. The further payment status (collected, failed,
chargeback) is reported by GoCardless via **webhook**.

> **Status 1.0.0:** the logic is covered by unit tests and static analysis but has not yet run against the real
> GoCardless API. Please test in **sandbox mode** first (see below) before going live.

## Features

- **Payment method** "SEPA Direct Debit (GoCardless)" (technical name `nova_gocardless_sepa`), created
  **inactive** on installation.
- **Hosted payment page:** one GoCardless *billing request* (payment + SEPA mandate, scheme `sepa_core`) and one
  *billing request flow* per order. Name, e-mail and billing address are prefilled (editable by the customer),
  language taken from the order.
- **Payment status** set through Shopware's state machine – flows, state history and status mails work as usual.
- **Webhook** with signature check (HMAC-SHA256, `hash_equals`), response **498** on invalid signature, **each
  event processed once** (event IDs are stored), order of delivery does not matter.
- **Chargebacks and failed debits:** status "Chargeback" / "Failed", note in the order's internal comment and a
  notification in the administration.
- **Mandate events** (cancelled, failed, expired, …) as a note on the order.
- **Several sales channels:** credentials per channel (e.g. different GoCardless accounts), one webhook endpoint.
- **Console:** `nova-gocardless:test` checks token and mode, shows the creditor and the webhook URL.
- Own lean API client (Symfony HttpClient, no SDK), timeouts, idempotency key on every POST, error log without
  token and without customer data.

## Customer flow

1. Checkout: choose "SEPA Direct Debit (GoCardless)". A short notice about the redirect and the advance notice
   is shown below the payment method.
2. Place the order – the order is created and the customer is redirected to GoCardless.
3. There they check name, e-mail and address, enter IBAN/account holder and confirm the mandate.
4. GoCardless redirects back to the shop → order finish page. Payment status: **In progress** (or **Authorized**).
5. GoCardless e-mails the mandate confirmation and the **advance notice** and collects the amount. Once collected
   (usually a few working days), the payment becomes **Paid**.

If the customer cancels on the GoCardless page, Shopware sets the payment to **Cancelled** and offers to change
the payment method or retry as usual. The open billing request is cancelled at GoCardless.

## Installation

**Via Nova Updater:** *Extensions → My extensions → plugin catalogue* → "Nova GoCardless" → *Install*, then activate.

**As ZIP:** upload `NovaGoCardless-<version>.zip` from the
[releases](https://github.com/DrdotHouse2106/SW6-Nova-GoCardless/releases) under *Extensions → My extensions →
Upload extension*, install and activate, then clear the cache.

## Setup

1. **GoCardless account:** sandbox for testing (<https://manage-sandbox.gocardless.com/signup>), the normal
   account for live (<https://manage.gocardless.com>). Each has its own tokens and webhooks.
2. **Access token:** GoCardless dashboard *Developers → Create → Access token*, scope **read-write**.
3. **Configure the plugin:** mode (sandbox/live) and access token, globally or per sales channel.
4. **Check:** `bin/console nova-gocardless:test`.
5. **Webhook:** *Developers → Create → Webhook endpoint*, URL
   `https://<your-shop-domain>/api/_action/nova-gocardless/webhook` (https with full certificate chain), copy the
   **secret** into the plugin configuration. Without a working webhook payments never become "Paid".
6. **Activate the payment method** and assign it to the sales channel. Recommended: an availability rule
   "currency is Euro" – other currencies are rejected.
7. **Privacy policy:** see below.

## Settings (per sales channel)

| Setting | Default | Meaning |
|---|---|---|
| Mode | Sandbox | Sandbox (`https://api-sandbox.gocardless.com`) or Live (`https://api.gocardless.com`) |
| Access token | empty | read-write token matching the mode |
| Webhook secret | empty | secret of the webhook endpoint |
| Payment status after the mandate is set up | In progress | or "Authorized" – paid only once collected |
| Payment description | `Bestellung {orderNumber}` | shown on the GoCardless page and in the advance notice; placeholders `{orderNumber}`, `{shopName}`; max. 140 characters |
| Own mandate reference (prefix) | empty | prefix + order number (A–Z, 0–9, "-", max. 35). **Only if GoCardless support enabled custom references** |
| Notify administration | on | notification on failed debits and chargebacks (the order note is always written) |

## Payment status

| GoCardless | Shopware payment status | Also |
|---|---|---|
| Return from the flow, billing request fulfilled (or webhook `billing_requests.fulfilled`) | In progress / Authorized | mandate and payment ID stored |
| Cancel on the GoCardless page | Cancelled (by Shopware) | billing request cancelled |
| `payments.confirmed`, `payments.paid_out` | **Paid** | note |
| `payments.failed` | Failed | note + notification |
| `payments.late_failure_settled` | Chargeback (if already paid), otherwise Failed | note + notification |
| `payments.cancelled` | Cancelled | note |
| `payments.charged_back` | **Chargeback** | note + notification |
| `payments.chargeback_cancelled` | Paid | note |
| `mandates.cancelled`, `failed`, `expired`, … | unchanged | note |

The status never moves backwards (e.g. "Paid" is not reset to "In progress" by a late event), refunded payments
are never touched, and the required transitions are read from the state machine (shortest path, max. two steps).
Refunds are handled in the GoCardless dashboard.

## Sandbox test

Set mode **Sandbox**, enter sandbox token and webhook secret, run `bin/console nova-gocardless:test`. The
webhook needs a public https URL (locally e.g. via a tunnel). Place a test order, use a test IBAN such as
`DE89370400440532013000` on the GoCardless page, then trigger events in the sandbox dashboard under
*Developers → Scenario simulators* (payment confirmed, paid out, failed, charged back) and check deliveries under
*Developers → Webhooks*.

## Privacy

The shop sends **name, e-mail address and billing address** (for prefilling), **order number, amount and
currency** and a technical transaction ID to GoCardless. **Bank details** are collected by GoCardless on its own
page; the shop does not receive them. GoCardless also processes data for its own purposes (e.g. fraud
prevention) as a controller; for payers in the EEA this is GoCardless SAS, 7 rue de Madrid, 75008 Paris, France.
Payer privacy notice: <https://gocardless.com/privacy/payers/>.

The plugin only stores the mapping order transaction ↔ GoCardless IDs (billing request, mandate, payment),
mode and last status (`nova_gocardless_transaction`) and the IDs of processed webhook events
(`nova_gocardless_event`, deleted after 90 days). No IBAN, no account data. With **Nova Rechtstexte 1.8.8+** the
privacy block `gocardless` is inserted automatically when Nova GoCardless is installed and active and the payment
method is active in the sales channel. This is not legal advice.

## Notes

- Euro only; one mandate per order (no recurring payments); refunds via the GoCardless dashboard.
- Check your terms and conditions regarding direct debit (GoCardless' advance notice is usually at least
  3 working days).

## How this plugin was made

This plugin was developed with the help of AI (Claude by Anthropic). The requirements come from the daily operation of several Shopware shops; the code was written and revised with AI. It is checked with automated tests and static analysis (where present in the repository), regular security reviews of the code and in production in our own shops. Nevertheless: use at your own risk and without warranty (see license). Please report bugs and suggestions as an issue. More background: [SW6-Nova-Plugins](https://github.com/DrdotHouse2106/SW6-Nova-Plugins).

## License

MIT – see [LICENSE](LICENSE).
