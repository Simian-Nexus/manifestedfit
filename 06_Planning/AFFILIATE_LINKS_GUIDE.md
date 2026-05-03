# Affiliate Links Guide

## Where Links Live

Public offer links live in:

```text
03_Website/public/assets/js/affiliate-offers.js
```

That file is safe to commit because it should contain public affiliate URLs only. Do not put affiliate account passwords, Impact login details, or payout information there.

## How To Add An Offer

Add a new object to `window.ManifestedFitOffers`:

```js
{
  slug: "example-offer",
  name: "Example Offer",
  category: "Movement",
  status: "active",
  url: "https://your-approved-affiliate-link.example",
  fallbackUrl: "",
  buttonLabel: "View Resource",
  summary: "Short public description of why this fits the Manifested Fit audience.",
  note: "Internal-facing reminder written safely enough to appear on the resources page."
}
```

## Status Values

- `research`: visible as a planned slot, but not a live recommendation.
- `pending`: waiting for affiliate approval or final link.
- `active`: live offer. Use only after `url` is your approved affiliate link.

## Current Funnel Behavior

- The homepage form collects name and email for the 7-day reset.
- Successful opt-ins redirect to `/thank-you/`.
- The thank-you page links to `/lead-magnet/`.
- The lead magnet and thank-you pages link to `/resources/` for deeper-practice offers.
- `/resources/` renders whatever is in `affiliate-offers.js`.

## Good Offer Categories

- meditation and breathwork apps
- gentle yoga or mobility programs
- habit trackers and wellness journals
- guided personal growth courses
- recovery, sleep, or stress-management tools

Avoid offers that depend on aggressive health, weight-loss, or income claims.

