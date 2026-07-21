# Currency Geo Pricing

A single-file WooCommerce plugin that gives each product its own **EUR** and **USD** prices and automatically shows the right currency based on where the visitor is:

- 🇺🇦 **UAH** in Ukraine (the store's base currency)
- 🇪🇺 **EUR** across Europe
- 🌍 **USD** for the rest of the world

WooCommerce then renders the correct code and symbol (₴, €, $) throughout the shop, cart, and checkout totals.

- **Version:** 2.2.0
- **Author:** envoydev
- **Requires:** WooCommerce

## How it works

For every front-end request the plugin decides the visitor's currency, then swaps each product's regular price, sale price, and active price to the matching value before WooCommerce displays it.

### 1. Visitor country detection

The country is resolved once per request, in this order:

1. **Cloudflare** — the `CF-IPCountry` header (`HTTP_CF_IPCOUNTRY`), most reliable when the site sits behind Cloudflare.
2. **WooCommerce geolocation** — `WC_Geolocation` (MaxMind GeoLite2 database with an ip-api fallback).

If neither yields a country, the visitor is treated as "rest of the world".

### 2. Currency selection

| Visitor location | Currency |
| --- | --- |
| Ukraine (`UA`) | UAH — always, cannot be disabled |
| A European country (see list) | EUR (falls back to UAH if EUR is turned off) |
| Everywhere else / country unknown | USD (falls back to UAH if USD is turned off) |

Currency is **never** changed in `wp-admin`, WP-Cron, or WP-CLI (front-end AJAX is allowed), so admin and background tasks always work in the base UAH currency.

### 3. Price swapping

For EUR/USD visitors the plugin filters:

- `woocommerce_product_get_regular_price` / `woocommerce_product_variation_get_regular_price`
- `woocommerce_product_get_sale_price` / `woocommerce_product_variation_get_sale_price`
- `woocommerce_product_get_price` / `woocommerce_product_variation_get_price`
- `woocommerce_currency` (switches the store code + symbol)

A **sale price** is treated as active only when it is below the regular price **and** the current time falls inside the product's own **Schedule** window (the standard WooCommerce sale-from / sale-to dates are reused).

Variable-product price caches are keyed per currency (`woocommerce_get_variation_prices_hash`) so the first cached currency never leaks to later visitors.

## Requirements

- WordPress with **WooCommerce** active (declared via `Requires Plugins: woocommerce`).
- PHP 7.x or newer.

## Installation

1. Copy `currency-geo-pricing.php` into `wp-content/plugins/currency-geo-pricing/` (or zip it and upload it under **Plugins → Add New → Upload Plugin**).
2. Activate **Currency Geo Pricing** from the **Plugins** screen.
3. Make sure WooCommerce is active and your base store currency is set to **UAH**.

## Setting prices

Each product (and each variation) gets extra price fields next to the standard UAH pricing:

- **EUR regular price (€)** — shown to visitors in Europe.
- **EUR sale price (€)** — optional; must be lower than the EUR regular price. Reuses the product's Schedule dates.
- **USD regular price ($)** — shown to visitors outside Ukraine and Europe.
- **USD sale price ($)** — optional; must be lower than the USD regular price. Reuses the product's Schedule dates.

The fields appear on the **Product data → General** tab for simple products, and inside each **Variation** for variable products.

If a product has no price set for the visitor's currency, it falls back to the optional static conversion rate (below), or — if that is disabled — shows its raw UAH number under the foreign symbol.

## Optional fallback conversion rates

If you don't want to enter every EUR/USD price by hand, you can set static **UAH → foreign** rates. When a target-currency price is missing, the UAH price is divided by the matching rate to produce a figure.

Set them under **WooCommerce → Settings → General** in the **Currency geo pricing** section:

- **UAH per 1 EUR** (`eurgeo_uah_per_eur`) — e.g. `45` means 1 EUR = 45 UAH.
- **UAH per 1 USD** (`eurgeo_uah_per_usd`) — e.g. `41` means 1 USD = 41 UAH.

Notes:

- Default is `0`, which **disables** the fallback (the product then shows its raw UAH number under the foreign symbol).
- Explicit per-product EUR/USD prices always take priority over these rates.

## Settings

Under **WooCommerce → Settings → General** a **Currency geo pricing** section adds:

- **Show prices by location** (`eurgeo_geo_enabled`) — the master switch. When off, every visitor sees UAH. Use it to enter EUR/USD prices first and stage them before going live.
- **Enabled currencies:**
  - **UAH — Ukraine** — always on, cannot be switched off.
  - **EUR — Europe** (`eurgeo_eur_enabled`) — when off, European visitors fall back to UAH.
  - **USD — rest of the world** (`eurgeo_usd_enabled`) — when off, those visitors fall back to UAH.
- **UAH per 1 EUR** (`eurgeo_uah_per_eur`) and **UAH per 1 USD** (`eurgeo_uah_per_usd`) — optional fallback conversion rates (see below); `0` disables them.

## Customizing the EUR country list

Countries billed in EUR are defined in `cgp_eur_countries()` and can be changed with the `cgp_eur_countries` filter:

```php
add_filter( 'cgp_eur_countries', function ( $countries ) {
	$countries[] = 'RU'; // add Russia to the EUR group
	return array_diff( $countries, array( 'GB' ) ); // remove the UK
} );
```

Notes on the default list:

- **UA** is intentionally absent — Ukraine always uses UAH.
- **RU** and **BY** are intentionally absent — they fall through to USD; add them if you want EUR there.
- **GB, CH, NO** and a few others use their own currency in reality but are billed EUR here under the "Europe" rule.

## Notes

- The store's base currency should be **UAH**; EUR/USD are display currencies swapped in at render time.
- The plugin does not perform live currency conversion — you either enter EUR/USD prices per product or supply static fallback rates.
