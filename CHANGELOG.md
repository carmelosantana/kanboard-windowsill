# Changelog

## 0.1.0 — unreleased

- Dashboard widget with current conditions and a three-day forecast, shown only on your own dashboard.
- Weather page in your profile: search for a place, pick a match, choose US or metric units, or remove
  your location.
- Forecasts and place search come from Open-Meteo, fetched by the server, with the required attribution;
  the free tier is for non-commercial use.
- Forecasts are cached for 30 minutes; an older forecast is shown (marked "as of") for up to 24 hours
  while the provider is down, and a failed fetch is not retried for 10 minutes.
- The widget refreshes itself from the Kanboard server (same origin, CSRF-protected); the browser never
  contacts the weather provider.
- Privacy: coordinates are rounded to about 1 km before they are stored or sent, and coordinates and
  place queries are redacted from Kanboard's logs.
