# Website

The current site is deliberately small and shared-hosting-friendly.

## Pages

- `public/index.html`: landing page and opt-in
- `public/thank-you/index.html`: access page
- `public/lead-magnet/index.html`: interactive reset planner
- `public/legal/affiliate-disclosure.html`: affiliate disclosure
- `public/legal/privacy.html`: privacy placeholder

## Lead Capture

`public/api/collect-lead.php` writes opt-ins to `public/storage/leads.csv`.

This is a starter bridge so the funnel can be tested quickly. Before sending meaningful traffic, consider moving capture and follow-up to a real email marketing platform with unsubscribe handling.

## Local PHP Preview

```powershell
php -S 127.0.0.1:8098 -t 03_Website/public
```

Then open `http://127.0.0.1:8098/`.

