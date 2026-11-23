# Windowsill

A per-user weather widget for the Kanboard dashboard: current conditions and a three-day forecast
for a location each person picks for themselves.

## Setup

1. Open your profile and choose **Weather**.
2. Search for a place by name.
3. Pick the right match from the results and save. The widget appears on your dashboard.

Each user sets their own location; nobody else's dashboard shows it.

## Weather data

Forecasts come from [Open-Meteo](https://open-meteo.com/). Windowsill uses the Open-Meteo free tier,
which is for **non-commercial** use only and is limited to fewer than 10,000 calls per day. Data is
licensed under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/); the widget shows the
required "Weather data by Open-Meteo.com" attribution. Commercial deployments need an Open-Meteo
commercial plan.

## Privacy

- Your coordinates are rounded to two decimal places (about 1 km) before they are stored or sent.
- The Kanboard server fetches the forecast; your browser never contacts the weather provider.
- The text you type in place search is sent from the Kanboard server to Open-Meteo's geocoding API.
- Your location is stored in the Kanboard database, so whoever administers the server or database can
  read it. Kanboard admins do not see it in the user interface.

## Trust

The server talks only to `api.open-meteo.com` and `geocoding-api.open-meteo.com`, over HTTPS on port
443, through the plugin's own fetcher rather than Kanboard's HTTP client. It never follows a redirect
(any 3xx is a failure), gives up after 5 seconds, and stops reading a response past 64 KB. Kanboard's
proxy and certificate settings (`HTTP_PROXY_*`, `HTTP_VERIFY_SSL_CERTIFICATE`) still apply.

## Limits

Every search and forecast fetch shares the Kanboard server's Open-Meteo quota, which is counted per IP
address (600 calls a minute, 10,000 a day). To keep one user from spending it, each user gets:

- at most 10 place searches a minute;
- one forecast refresh in flight at a time;
- forecast fetches at least 15 seconds apart, however often the location or units change.

A held-back refresh shows the cached forecast (or "Loading weather…") and the widget tries again up to
twice on that page load.

## Requirements

- Kanboard 1.2.47 or later, PHP 8.4 or later.
- No dependencies: no other plugins, no Composer or npm packages, no build step.

## License

MIT — see [LICENSE](LICENSE).
