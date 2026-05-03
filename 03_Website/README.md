# Website

The current site is deliberately small and shared-hosting-friendly.

## Pages

- `public/index.html`: landing page and opt-in
- `public/thank-you/index.html`: access page
- `public/lead-magnet/index.html`: interactive reset planner
- `public/resources/index.html`: affiliate/resource hub
- `public/legal/affiliate-disclosure.html`: affiliate disclosure
- `public/legal/privacy.html`: privacy placeholder

## Affiliate Offers

Edit `public/assets/js/affiliate-offers.js` to add or update offers.

Use `status: "research"` before an offer is chosen, `status: "pending"` while waiting for affiliate approval, and `status: "active"` once the `url` field contains your approved affiliate link.

## Lead Capture

`public/api/collect-lead.php` writes opt-ins to `public/storage/leads.csv`.

This is a starter bridge so the funnel can be tested quickly. Before sending meaningful traffic, consider moving capture and follow-up to a real email marketing platform with unsubscribe handling.

## Local PHP Preview

```powershell
php -S 127.0.0.1:8098 -t 03_Website/public
```

Then open `http://127.0.0.1:8098/`.
