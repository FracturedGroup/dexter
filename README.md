# Dexter – FX Layer for Fractured

<img width="311" height="360" alt="dexter" src="https://github.com/user-attachments/assets/188f372a-8626-499f-af96-d205a42e3afb" />

Dexter is a standalone WordPress plugin that provides a **central currency conversion layer** for Fractured’s multi-currency vendors.

It lets vendors price products in their **native currencies** (EUR, CAD, AED, INR, etc.) while the Fractured storefront, orders, and commissions stay in **GBP**. Dexter performs all conversions **at import time** (e.g. via SyncSpider → WooCommerce REST), and stores full FX audit metadata for finance/compliance.

---

## Core responsibilities

1. **FX rates storage & update**
   - Maintains a custom table `wp_fxd_fx_rates`.
   - Fetches daily rates from `frankfurter.app` (ECB-based, no API key).
   - Base currency is GBP by default.
   - Admin can manually refresh rates in WP Admin → **Dexter FX**.

2. **Vendor currency management**
   - Adds a **“Dexter – Vendor Currency”** dropdown on Dokan vendor user profiles.
   - Stores vendor currency as user meta: `fxd_vendor_currency`.
   - Supported currencies (filterable):
     - `GBP`, `EUR`, `CAD`, `AED`, `INR`.

3. **REST price conversion layer**
   - Hooks into:
     - `woocommerce_rest_pre_insert_product_object`
     - `woocommerce_rest_pre_insert_product_variation_object`
   - Resolves the vendor for each product/variation.
   - Converts incoming prices **from vendor currency → GBP**.
   - Writes standard WooCommerce price fields in GBP:
     - `_regular_price`, `_sale_price`, `_price`.
   - Stores FX audit metadata:
     - `_fxd_orig_currency`
     - `_fxd_orig_regular_price`
     - `_fxd_orig_sale_price`
     - `_fxd_fx_rate_used`
     - `_fxd_fx_converted_at`

The storefront remains a normal single-currency GBP WooCommerce + Dokan shop, but internal integrations (like SyncSpider) can safely use multiple vendor currencies.

---

## Tech stack & structure

- **Platform:** WordPress, WooCommerce, Dokan.
- **Language:** PHP (no child-theme edits, no Python).
- **Deployment:** Single plugin folder: `wp-content/plugins/dexter/`.

### High-level structure

```text
dexter/
  dexter.php                 # Main plugin bootstrap
  src/
    bootstrap.php            # Simple PSR-4 autoloader for Fractured\Dexter\
    Plugin.php               # Central plugin orchestrator

    Infrastructure/
      Activation.php         # Activation hook + FX table creation

    Fx/
      RateRepository.php     # Read/write FX rates in wp_fxd_fx_rates
      Updater.php            # FX updater (cron + manual refresh + HTTP client)

    Vendor/
      Currency.php           # Vendor currency meta + admin UI

    Rest/
      Hooks.php              # Registers Woo REST hooks
      PriceConverter.php     # Conversion logic for products/variations

    Admin/
      Menu.php               # Dexter FX admin page (status + manual refresh)
```

## Requirements

- WordPress (version consistent with your Fractured stack).
- WooCommerce.
- Dokan (multi-vendor marketplace).
- Outbound HTTP allowed (for https://api.frankfurter.app).
- PHP 7.4+ recommended.

## Installation

1. Clone or copy the dexter folder into:

```bash
wp-content/plugins/dexter/
```

2. In WP Admin, go to Plugins → Dexter – FX Layer for Fractured and click Activate.
3. On activation, Dexter will:

- Create/upgrade the DB table: wp_fxd_fx_rates.
- Be ready to fetch FX rates and convert prices.

## Configuration

### FX rates

Dexter uses frankfurter.app with GBP as base.
- Go to WP Admin → Dexter FX.
- Click “Refresh FX Rates Now”.
- You should see rows like:

| Base Currency | Currency | Rate | Source |
| :---: | :---: | :---: | :---: |
| GBP | GBP | 1.0000 | frankfurter.app |
| GBP | EUR | 1.1870 | frankfurter.app |
| GBP | CAD | … | frankfurter.app |

Dexter also schedules a daily cron job to keep rates updated automatically.
```text
Note: Rates are stored as “units of CURRENCY per 1 GBP”.
Example: if rate = 1.1379 for EUR, that means:
1 GBP = 1.1379 EUR.
```
To convert vendor currency → GBP, Dexter divides:
```text
amount_in_gbp = amount_in_vendor_currency / rate
```

### Vendor currency
- Go to Users → All Users.
- Edit a Dokan vendor (role usually seller).
- You’ll see a section: “Dexter – Vendor Currency”.
- Choose one of:
    - GBP – Pound Sterling
	- EUR – Euro
	- CAD – Canadian Dollar
	- AED – UAE Dirham
	- INR – Indian Rupee

This is stored as:
```text
user_meta: fxd_vendor_currency = "EUR" (for example)
```

If no value is set, Dexter assumes GBP by default.

## How the REST conversion works

Dexter intercepts product/variation creations and updates via the WooCommerce REST API.

### Vendor resolution

When a REST request creates/updates a product, Dexter tries to identify the vendor using:
1.  Request parameters (in order):
	- author
	- dokan_vendor_id
	- dokan_vendor
	- vendor_id
	- seller_id
2.	If none are present, Dexter falls back to:
	- The existing product’s post_author (for updates).

If no vendor ID can be resolved, Dexter does not touch prices.

### Currency and FX lookup

Once Dexter has a `$vendor_id`:
1.	Read vendor currency:

```php
Vendor\Currency::get_vendor_currency( $vendor_id );
```

2.	If vendor `currency == base (GBP)`:
	•	Dexter does no conversion, but can still store audit meta if prices are present.
3.	If vendor `currency != base`:
	•	Dexter gets FX rate from:

```php
RateRepository::get_rate_to_base( $vendor_currency, 'GBP' );
```

If no rate is available, Dexter bails out gracefully and leaves prices unchanged.

### Price conversion

Dexter reads regular_price and sale_price from the REST request (vendor currency), then:

```php
$gbp_regular = amount_in_vendor / rate;
$gbp_sale    = amount_in_vendor / rate;
```

Then it sets:
- $product->set_regular_price( $gbp_regular );
- $product->set_sale_price( $gbp_sale );
- $product->set_price( active_price );
(where active price follows Woo’s normal “sale or regular” logic)

No conversion happens on the frontend; all GBP prices are pre-computed and stored.

### Audit metadata

On each converted product/variation, Dexter stores:
- _fxd_orig_currency → vendor currency (e.g. EUR).
- _fxd_orig_regular_price → original numeric string from request (e.g. "100").
- _fxd_orig_sale_price → original sale price if provided.
- _fxd_fx_rate_used → rate retrieved from wp_fxd_fx_rates (e.g. 1.1379).
- _fxd_fx_converted_at → UTC timestamp of conversion.

This gives full traceability for finance/compliance:

“Vendor set 100 EUR, 1 GBP = 1.1379 EUR, so stored price is 87.88 GBP.”

## Testing Dexter (manual test flow)

You can test Dexter end-to-end without SyncSpider by using Postman or curl.

### Prepare a non-GBP test vendor
- In Users, pick a Dokan vendor (or create a test vendor).
- Set “Dexter – Vendor Currency” to EUR.
- Ensure FX rates include GBP → EUR on the Dexter FX page.

### Create a WooCommerce REST API key
- WooCommerce → Settings → Advanced → REST API.
- Add Key → Read/Write → generate.
- Use these in Postman as Basic Auth (username = consumer key, password = consumer secret).

### Send a test product

**POST** to:
```text
https://preprod.fracturedstore.com/wp-json/wc/v3/products
```

Body (raw JSON):
```json
{
  "name": "Dexter EUR Test Product",
  "type": "simple",
  "status": "draft",
  "regular_price": "100",
  "author": 23,
  "dokan_vendor_id": 23
}
```

Where 23 is the vendor user ID you configured as EUR.

If Dexter is working:
- The response will show:

```json
"regular_price": "87.88",
"price": "87.88"
```
(value depends on current FX rate, example shown for 1.1379).

- 	And meta_data will contain:
```json
{ "key": "_fxd_orig_regular_price", "value": "100" }
{ "key": "_fxd_orig_currency",       "value": "EUR" }
{ "key": "_fxd_fx_rate_used",        "value": "1.1379" }
{ "key": "_fxd_fx_converted_at",     "value": "2025-11-26 01:23:41" }
```

### GBP vendor test

Repeat the test with a vendor whose Dexter currency is **GBP**.
- Send regular_price: "100".
- Dexter will **not** convert, so regular price remains 100.
- Audit metadata will record:
- _fxd_orig_currency = GBP
- _fxd_fx_rate_used = 1.0

## SyncSpider integration notes

As long as SyncSpider:
- Uses the WooCommerce REST API for product/variation creation/update, and
- Sends a vendor identifier in one of:
- author
- dokan_vendor_id
- dokan_vendor
- vendor_id
- seller_id

…Dexter will apply the correct FX logic automatically.

You **do not** need to do any FX math in SyncSpider. Just send:
- regular_price and/or sale_price in the **vendor’s native currency**.
- The vendor identifier as per your Dopkan mapping.

Dexter handles the rest.

## Safety & behaviour notes
- If **no vendor can be resolved**, prices are left untouched.
- If **no FX rate** is available for the vendor currency, prices are left untouched.
- If vendor currency is **GBP**, prices are left as-is (but audit meta can be stored).
- Dexter does **no conversion on frontend**; only at REST insert/update time.
- FX updater runs via:
- Daily WP cron, and
- Manual “Refresh FX Rates Now” button.

## Extensibility

Filter hooks:
- fractured_dexter_fx_base_currency
- Change the base currency if needed (defaults to GBP).
- fractured_dexter_fx_target_currencies
- Control which currencies Dexter fetches from the FX API.
- fractured_dexter_vendor_currencies
- Control which currencies appear in the vendor dropdown / are allowed.

Potential future extensions (not yet implemented):
- CLI commands (wp dexter fx:update, wp dexter vendor:list).
- Admin logs / dashboard for last N conversions.
- Per-vendor overrides (e.g. fixed FX rates for special contracts).
- More UI around SyncSpider mapping diagnostics.

## Versioning

Current internal version: 0.1.0
- 0.1.0 – Initial implementation:
- FX table + updater (Frankfurter, GBP base).
- Dexter FX admin page.
- Vendor currency meta & UI.
- REST price conversion for products & variations.
- FX audit metadata.

## License

Internal use only – © 2025 Fractured UK Limited. All rights reserved.