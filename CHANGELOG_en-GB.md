# 1.0.0

- First release: payment method **"SEPA Direct Debit (GoCardless)"** (`nova_gocardless_sepa`) for Shopware 6.7, created inactive on installation
- Payment via GoCardless' hosted page (billing request with payment and SEPA mandate `sepa_core`, billing request flow), customer data prefilled; no bank details in the shop
- Return: billing request fulfilled → payment status "In progress" (optionally "Authorized"), cancel → customer cancellation and the open billing request is cancelled
- Webhook `/api/_action/nova-gocardless/webhook` with signature check (HMAC-SHA256, response 498 on invalid signature), idempotent via event IDs: payment confirmed/paid out → paid, failed → failed, cancelled → cancelled, charged back → chargeback with administration notification, mandate events as a note on the order
- Status changes through the state machine (shortest path from the transitions in the database, never backwards, refunded payments untouched)
- Settings per sales channel: mode sandbox/live, access token, webhook secret, status after mandate, payment description, optional own mandate reference, notification
- Console command `nova-gocardless:test` (token, mode, creditor, webhook URL)
- Own API client (Symfony HttpClient), GoCardless version 2015-07-06, idempotency key, timeouts, logging without token and customer data
