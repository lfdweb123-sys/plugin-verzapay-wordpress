# Verzapay Gateway for WooCommerce

Accept [Verzapay](https://www.verzapay.com) payments (API v1) in WooCommerce: card and Mobile Money.

- Countries synced with the Verzapay documentation (all countries, western only, or custom)
- Phone number required, in international format, matched to the billing country
- Neutral payment description sent to the aggregator (`Service rendu` by default)
- Secure webhook: secret token, API re-check, amount/currency/reference match, idempotence
- Texts follow the site language (fr, en, es, de, it, pt, nl) and can be edited per language in the settings
- Responsive settings page and checkout box

Requires WordPress 6.0+, WooCommerce 7.0+, PHP 7.4+, classic checkout.

Install: upload `verzapay-woocommerce.zip` in **Plugins → Add New → Upload Plugin**, then open
**WooCommerce → Settings → Payments → Verzapay**. Full instructions (French): [LISEZ-MOI.txt](LISEZ-MOI.txt).
