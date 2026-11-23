# Changelog

## 0.1.0 — 2026-11-23

- Dashboard widget with current conditions and a three-day forecast, shown only on your own dashboard.
- Weather page in your profile: search for a place, pick a match, choose US or metric units, or remove
  your location.
- Forecasts and place search come from Open-Meteo, fetched by the server, with the required attribution;
  the free tier is for non-commercial use.
- Forecasts are cached for 30 minutes; an older forecast is shown (marked "as of") for up to 24 hours
  while the provider is down, and a failed fetch is not retried for 10 minutes.
- The widget refreshes itself from the Kanboard server (same origin, CSRF-protected); the browser never
  contacts the weather provider.
- The server fetches only from the two Open-Meteo hosts over HTTPS, never follows a redirect, and
  bounds each request to 5 seconds and 64 KB.
- Per-user request limits: 10 place searches a minute, one forecast refresh in flight, and forecast
  fetches at least 15 seconds apart; removing a location or switching units does not reset them.
- Request budgets are counted atomically by the database, so parallel requests cannot pass them, and the
  server as a whole makes at most 300 Open-Meteo calls a minute and 8,000 a day.
- Each user makes at most 200 Open-Meteo calls a day, searches and forecasts together; an invalid search
  query spends no budget.
- Privacy: coordinates are rounded to about 1 km before they are stored or sent, and coordinates and
  place queries are never written to Kanboard's logs.
