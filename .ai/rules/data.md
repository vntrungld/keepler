---
paths:
  - resources/data/services.json
---

# Data

## One service catalog feeds Gmail detection, branding, and the picker
Add or edit a supported service in `resources/data/services.json` only. `config/providers.php` loads it (filling in shared defaults) for Gmail detection; `resources/js/orbit/brandColors.js` imports it for colour/domain; `brandIcon.js` maps its `icon` field to a static simple-icons import. Never grow those three separately.

Two fields look alike but are not:
- `match_keywords` — used by ProviderMatcher on real email. Keep them specific ("apple music", not "apple"), or receipts get mis-attributed.
- `aliases` — display-only, for the frontend colour/icon lookup. Safe to be loose ("apple"), and never read by PHP.

Matching is longest-keyword-wins, not catalog-order-wins, in both ProviderMatcher::best() and brandColors' KEYWORDS. That is what keeps the five apple.com and three youtube.com products apart. Any provider sharing a `sender_domains` entry with another MUST set `requires_product_match: true` — a test in ProviderMatcherTest enforces this, along with a well-formedness check on every entry.
