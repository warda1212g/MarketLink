# MarketLink - Top 10 AI Features (out of the 50-feature list)

The 50-feature document listed unique AI ideas for MarketLink. This build ships the
**top 10**, covering all three roles. Every feature is grounded in your platform's own
data (orders, order_items, products, vendors, users) and now uses an **API-first
OpenRouter AI decision layer** when the key is configured. A safe local calculation
remains as a fallback for temporary API/network/rate-limit failures.

## Setup (one extra step)

Import in phpMyAdmin, after the existing migrations:

```
database/ai-features-migration.sql
```

It adds one column: `products.harvest_date` (used by the Freshness Score).
If you already imported it, a "Duplicate column" error is harmless.

---

## OpenRouter API setup (required for API-first AI mode)

All 10 Top-10 features now call the shared OpenRouter AI decision layer when a key is configured. The model receives only the relevant live database-derived context and returns strict JSON; the application validates and applies the response. The local rule engine is retained only as a safety fallback:

- **All 10 Top-10 feature decisions** - Freshness, Discount, Market Gap, Weekly Stock, Confidence, Last Chance, Social Proof, Market Health, Wellbeing, and Location Scout.
- **AI Market Summary** and **farmer outreach drafts** in Admin -> AI Insights.

### Steps

1. Create a free account and key at **https://openrouter.ai/keys**
2. Open `config/config.php` and paste the key:
   ```php
   'openrouter' => [
       'api_key'     => 'sk-or-v1-XXXXXXXXXXXXXXXX',
       'model'       => 'meta-llama/llama-3.3-70b-instruct:free',
       ...
   ],
   ```
3. Done. Admin -> AI Insights shows **"OpenRouter connected"** and starts
   generating text. Requests are cached for 12 hours (storage/cache/ai/),
   so page views stay fast and your key quota is barely used.

### Notes

- Default model is a **free, tested-working** one
  (`nvidia/nemotron-3-super-120b-a12b:free`). Free models on OpenRouter share
  rate limits, so the service automatically retries on
  `google/gemma-4-31b-it:free`, `qwen/qwen3.8-27b:free` and
  `inclusionai/ling-3.0-flash-sante:free` before falling back to offline text.
  Any OpenRouter model id works - just change `model` in the config.
- Your key stays only in your local `config/config.php` - never paste it in
  code you share or upload.
- No key / network error? The badge shows **"Offline mode"** and every panel
  falls back to the built-in rule-based text. Nothing breaks.
- Requires internet access from your XAMPP server (it calls
  `https://openrouter.ai/api/v1/chat/completions`).

---

## Vendor features (4)

### #2 Harvest Freshness Score
- Vendor sets **Harvest Date** on any product (Add/Edit product modal -> My Products).
- Customers see a live score on the stall page: `Freshness 94/100 - harvested 8 hours ago`.
- Score decays over 72 hours; produce older than 3 days shows no badge.

### #4 Perishability Discount Engine
- Vendor Dashboard -> **Perishability Discount Engine** card.
- AI compares each product's remaining stock with what the vendor normally sells per
  week (last 4 weeks of completed orders). Overstocked perishables get a suggested
  10 / 20 / 30% price cut - one click **Apply** updates the live price.
- API: `api/vendor/ai-suggest.php` (GET suggestions, POST apply_discount).

### #5 Market Gap Opportunity Detector
- Vendor Dashboard -> **Market Gap Opportunities** card.
- Finds categories that this vendor's own customers buy from *other* stalls - but
  this vendor doesn't sell. "6 of your customers bought dairy from other stalls."

### #9 Smart Weekly Stock Template (AI Prefill)
- Weekly Stock page -> **AI Prefill** button on every day.
- Suggests quantities per product from the last 6 weeks of actual sales
  (+15% headroom), falling back to the saved template for items never sold.
- Fills the day editor instantly; the vendor reviews and presses Save.

---

## Customer features (3)

### #16 Pre-Visit Confidence Score
- Stall page hero shows `Pre-visit confidence: 91%`.
- 70% weight on how many of the last 8 weeks the stall actually had orders,
  30% on fulfilment (completed vs cancelled). New stalls show no badge.

### #17 "Last Chance" Stock Alert
- Customer Dashboard -> **Last Chance For You** tab.
- Products down to 3 units or fewer that THIS customer bought before (or
  favourited): "Only 2 kg left, Bought 3x before, Pre-order now".

### #29 Pre-Order Social Proof Signal
- Stall page product cards show `14 customers pre-ordered this week`
  when a product has 2+ pre-orders in the last 7 days.

---

## Admin features (3) - new page: Admin -> AI Insights

### #28 Market Profitability Health Score
- Per-stall score 0-100: attendance 30% + fulfilment 30% + customer
  retention 20% + revenue growth (28d vs previous 28d) 20%.
- Status badge (Thriving / Healthy / Needs attention / At risk) plus a
  concrete suggested action per stall.

### #47 Farmer Wellbeing Monitor
- Flags stalls that used to sell but had **3+ consecutive weeks without a
  completed order**, with the farmer's usual weekly sales and a suggested
  outreach message + one-click mailto.

### #26 New Market Location Scout
- Clusters approved customers by shared location (≈5 km grid, from their
  saved coordinates) and scores clusters with no active stall within 3 km -
  `340 customers, nearest stall 8 km away, score 94` with a map link.
- Note: customer coordinates come from profile location / map use, so the
  panel explains itself when there isn't enough location data yet.

---

## Files added / changed

| Change | File |
|---|---|
| New | `database/ai-features-migration.sql` |
| New | `includes/services/ai_features.php` (all engines) |
| New | `api/vendor/ai-suggest.php` |
| New | `admin/insights.php` (Admin -> AI Insights) |
| New | `assets/js/vendor/ai-insights.js` |
| New | `includes/services/openrouter.php` (OpenRouter LLM client + cache) |
| New | `README-AI-FEATURES.md` (this file) |
| Changed | `vendor/dashboard.php` (Smart Insights section) |
| Changed | `vendor/weekly-stock.php` + `assets/js/vendor/weekly-stock.js` (AI Prefill) |
| Changed | `vendor/products.php` + `api/vendor/products.php` + `assets/js/vendor/products.js` (harvest date) |
| Changed | `market.php` (freshness badge, social proof, confidence) |
| Changed | `customer/dashboard.php` (Last Chance section) |
| Changed | `admin/includes/sidebar.php` (AI Insights nav item) |
| Changed | `config/config.php` (openrouter section) |
| Changed | `includes/partials/icons.php` (sparkle icon) |
