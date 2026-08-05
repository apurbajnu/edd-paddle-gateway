=== EDD Paddle Billing Gateway ===
Contributors: apurbajnu
Tags: easy digital downloads, edd, paddle, payment gateway, paddle billing, ecommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept one-time payments through Paddle Billing on your Easy Digital Downloads store with off-site redirect checkout.

== Description ==

**EDD Paddle Billing Gateway** connects your Easy Digital Downloads store to Paddle Billing v2 for one-time purchases with off-site redirect checkout.

= Key Features =

* **Paddle Billing v2 API** – built against the current Paddle API (transactions, products, prices), not the legacy Paddle Checkout SDK.
* **Sandbox + Live modes** – store credentials separately per mode and switch without re-entering keys.
* **Test Connection** – one-click admin button verifies your API key before going live.
* **Single-price product sync** – automatically push your EDD downloads to Paddle as products with prices.
* **Zero-decimal currencies** – correct minor-unit handling for JPY, KRW, and the rest of ISO 4217.
* **Signed webhook verification** – HMAC-SHA256 with a 10-minute clock-drift window. Rejects unsigned or replayed requests.
* **PII redaction in logs** – email, name, address, card, and API-key fields are masked in debug logs.

= Premium Add-on =

A separate premium add-on (edd-paddle-gateway-pro, sold at bestdecoders.com) unlocks:

* Overlay / inline checkout (Paddle.js modal — no redirect)
* EDD Recurring integration (subscriptions, renewals)
* Variable / multi-price product sync
* Refund handling (both directions)
* Subscription lifecycle webhooks
* Webhook idempotency for safe Paddle retries
* Customer portal session creation

= Requirements =

* WordPress 6.0 or later
* Easy Digital Downloads 3.0 or later
* PHP 7.4 or later
* A Paddle account (sandbox or live) with API key, client token, and webhook secret

= Privacy =

This plugin transmits the buyer's email address and name to Paddle in order to complete the purchase. PII is redacted from local logs. See Paddle's privacy policy for what they retain.

== Installation ==

1. In WordPress, go to **Plugins → Add New → Upload Plugin** and choose `edd-paddle-gateway.zip`.
2. Activate the plugin. If Easy Digital Downloads is not active, activation will fail with a clear message — activate EDD first.
3. Go to **Downloads → Settings → Payment Gateways → Paddle**.
4. Choose **Sandbox** or **Live** mode and paste your API key, client token, and webhook secret from the Paddle dashboard.
5. Click **Test Connection** to verify your credentials.
6. In Paddle, set the webhook endpoint to `https://yoursite.com/?edd-listener=paddle`.
7. Save settings and place a test order.

== Frequently Asked Questions ==

= Where do I find my Paddle API key and webhook secret? =

In the Paddle dashboard, go to **Developer Tools → Authentication** for the API key, and **Developer Tools → Events** for the webhook secret. The endpoint URL to register is shown on the plugin settings page.

= Does this support subscriptions or recurring payments? =

No — the free version handles one-time payments only. The premium add-on (sold separately) adds subscription support.

= Does this support inline / overlay checkout? =

No — the free version uses off-site redirect checkout. The premium add-on adds overlay mode (Paddle.js modal).

= What happens to existing payments when I uninstall the plugin? =

Nothing. Payment records and their Paddle transaction IDs stay in EDD so your order and tax history is preserved. The plugin only removes its own settings (API keys, webhook secrets) on uninstall — no order data is touched.

= Why are some debug log entries missing? =

Raw request bodies and webhook payloads are gated behind the `EDD_PADDLE_DEBUG` constant. Define it in `wp-config.php` (`define( 'EDD_PADDLE_DEBUG', true );`) to enable verbose logging for support. PII is redacted even in verbose mode.

== Screenshots ==

1. **Paddle settings panel** – mode, API key, client token, webhook secret, and Test Connection button.
2. **Checkout** – buyer is redirected to Paddle's hosted checkout page.
3. **Download edit screen** – Paddle product and price IDs auto-synced.

== Changelog ==

= 1.0.0 =
* Initial release.
* Paddle Billing v2 gateway with off-site redirect checkout.
* Sandbox + Live modes with separate credentials.
* Single-price product sync to Paddle.
* Signed webhook listener for transaction completion events.
* Test Connection admin button.
* Zero-decimal currency support (JPY, KRW, and ISO 4217 list).
* PII redaction in debug logs; raw payloads gated behind EDD_PADDLE_DEBUG.
* Activation guard refuses to load if Easy Digital Downloads is not present.

== Upgrade Notice ==

= 1.0.0 =
First public release.
