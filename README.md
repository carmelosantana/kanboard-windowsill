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
- Your location is stored in the Kanboard database, so whoever administers the server or database can
  read it. Kanboard admins do not see it in the user interface.

## Trust

Requests start at two fixed Open-Meteo URLs (geocoding and forecast). Kanboard's HTTP client follows up
to 3 redirects, so the provider is trusted not to redirect elsewhere.

## Requirements

- Kanboard 1.2.47 or later, PHP 8.4 or later.
- No dependencies: no other plugins, no Composer or npm packages, no build step.

## License

MIT — see [LICENSE](LICENSE).
