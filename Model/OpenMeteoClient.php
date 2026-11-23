<?php

namespace Kanboard\Plugin\Windowsill\Model;

use Kanboard\Core\Http\ClientException;
use Pimple\Container;

/**
 * Open-Meteo forecast and geocoding, fetched server-side through `windowsillHttp`.
 * Fixed hosts; every failure is one ProviderException and one fixed warning (no coordinates, no query).
 */
class OpenMeteoClient
{
    const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';
    const GEOCODE_URL = 'https://geocoding-api.open-meteo.com/v1/search';
    const MAX_PLACES = 5;
    const MAX_NAME = 100;

    public function __construct(private Container $container)
    {
    }

    /**
     * @return array Forecast (see ForecastShape)
     * @throws ProviderException
     */
    public function forecast(float $lat, float $lon, string $units): array
    {
        $metric = $units === 'metric';
        $url = self::FORECAST_URL.'?'.self::query([
            'latitude' => number_format($lat, 2, '.', ''),
            'longitude' => number_format($lon, 2, '.', ''),
            'current' => 'temperature_2m,apparent_temperature,weather_code,wind_speed_10m,is_day',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
            'temperature_unit' => $metric ? 'celsius' : 'fahrenheit',
            'wind_speed_unit' => $metric ? 'kmh' : 'mph',
            'timezone' => 'auto',
            'forecast_days' => ForecastShape::DAYS,
        ]);

        try {
            $forecast = self::normaliseForecast($this->fetch($url));
            if (!ForecastShape::check($forecast)) {
                throw new ProviderException('Forecast is out of bounds');
            }
            return $forecast;
        } catch (ProviderException $e) {
            $this->container['logger']->warning('Windowsill: forecast request failed');
            throw $e;
        }
    }

    /**
     * @return array list of Place
     * @throws \InvalidArgumentException when the trimmed query is not 2–100 characters
     * @throws ProviderException
     */
    public function searchPlaces(string $query): array
    {
        $query = trim($query);
        $length = mb_strlen($query);
        if ($length < 2 || $length > self::MAX_NAME) {
            throw new \InvalidArgumentException('Place query must be 2 to 100 characters');
        }

        $url = self::GEOCODE_URL.'?'.self::query([
            'name' => $query,
            'count' => self::MAX_PLACES,
            'language' => 'en',
            'format' => 'json',
        ]);

        try {
            return self::normalisePlaces($this->fetch($url));
        } catch (ProviderException $e) {
            $this->container['logger']->warning('Windowsill: place search failed');
            throw $e;
        }
    }

    private static function query(array $params): string
    {
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * GET and decode a JSON object; transport and decoding errors become ProviderException.
     */
    private function fetch(string $url): array
    {
        try {
            $body = $this->container['windowsillHttp']->get($url, [], true);
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (ClientException | \JsonException) {
            // Not chained: core's exception message carries the request URL.
            throw new ProviderException('Provider request failed');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new ProviderException('Provider response is not an object');
        }
        return $data;
    }

    private static function normaliseForecast(array $data): array
    {
        $current = $data['current'] ?? null;
        $daily = $data['daily'] ?? null;
        if (!is_array($current) || !is_array($daily) || !is_int($data['utc_offset_seconds'] ?? null)) {
            throw new ProviderException('Forecast is missing sections');
        }

        $columns = ['time', 'weather_code', 'temperature_2m_max', 'temperature_2m_min', 'precipitation_probability_max'];
        $length = null;
        foreach ($columns as $column) {
            $values = $daily[$column] ?? null;
            if (!is_array($values) || !array_is_list($values) || ($length !== null && count($values) !== $length)) {
                throw new ProviderException('Daily forecast is malformed');
            }
            $length = count($values);
        }
        if ($length < ForecastShape::DAYS) {
            throw new ProviderException('Daily forecast is too short');
        }

        $days = [];
        for ($i = 0; $i < ForecastShape::DAYS; $i++) {
            $pop = $daily['precipitation_probability_max'][$i];
            $days[] = [
                'date' => $daily['time'][$i],
                'code' => $daily['weather_code'][$i],
                'max' => self::number($daily['temperature_2m_max'][$i]),
                'min' => self::number($daily['temperature_2m_min'][$i]),
                'pop' => $pop,
            ];
        }

        $isDay = $current['is_day'] ?? null;
        if ($isDay !== 0 && $isDay !== 1) {
            throw new ProviderException('Forecast is_day is malformed');
        }

        return [
            'utc_offset' => $data['utc_offset_seconds'],
            'current' => [
                'temp' => self::number($current['temperature_2m'] ?? null),
                'feels' => self::number($current['apparent_temperature'] ?? null),
                'code' => $current['weather_code'] ?? null,
                'wind' => self::number($current['wind_speed_10m'] ?? null),
                'is_day' => $isDay === 1,
            ],
            'days' => $days,
        ];
    }

    /**
     * JSON numbers may decode as int (e.g. `32`); strings and null are left for ForecastShape to reject.
     */
    private static function number(mixed $v): mixed
    {
        return is_int($v) ? (float) $v : $v;
    }

    private static function normalisePlaces(array $data): array
    {
        $results = $data['results'] ?? [];
        if (!is_array($results) || !array_is_list($results)) {
            throw new ProviderException('Place results are malformed');
        }

        $places = [];
        foreach ($results as $r) {
            if (count($places) === self::MAX_PLACES) {
                break;
            }
            if (!is_array($r) || !is_string($r['name'] ?? null) || trim($r['name']) === ''
                || !self::coordinate($r['latitude'] ?? null, 90) || !self::coordinate($r['longitude'] ?? null, 180)) {
                continue;
            }
            $admin1 = is_string($r['admin1'] ?? null) ? $r['admin1'] : '';
            $country = $r['country_code'] ?? null;
            $places[] = [
                'name' => mb_substr(trim($r['name']), 0, self::MAX_NAME),
                'admin1' => mb_substr($admin1, 0, self::MAX_NAME),
                'country_code' => is_string($country) && preg_match('/^[A-Z]{2}$/D', $country) === 1 ? $country : '',
                'lat' => (float) $r['latitude'],
                'lon' => (float) $r['longitude'],
            ];
        }
        return $places;
    }

    private static function coordinate(mixed $v, int $limit): bool
    {
        return (is_int($v) || is_float($v)) && is_finite((float) $v) && abs($v) <= $limit;
    }
}
