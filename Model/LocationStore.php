<?php
namespace Kanboard\Plugin\Windowsill\Model;

use Kanboard\Core\Base;

/**
 * Per-user location in user_has_metadata.
 *
 * Location: ['lat' => float (2dp), 'lon' => float (2dp), 'label' => string (<=120 chars), 'units' => 'us'|'metric'].
 * Numbers are stored with number_format($x, 2, '.', '') so a comma-decimal LC_NUMERIC never leaks in.
 */
class LocationStore extends Base
{
    /**
     * Keys clear() removes. Throttle's keys (lease, last fetch, search window) are deliberately absent, as
     * save() and setUnits() leave them alone too: removing a location or toggling units never resets a limit.
     */
    public const KEYS = [
        'windowsill_lat', 'windowsill_lon', 'windowsill_label',
        'windowsill_units', ForecastCache::CACHE_KEY, ForecastCache::FAILED_KEY,
    ];

    public const UNITS = ['us', 'metric'];

    private const LABEL_MAX = 120;

    public function get(int $userId): ?array
    {
        $all = $this->userMetadataModel->getAll($userId);
        $lat = self::coordinate($all['windowsill_lat'] ?? null, 90);
        $lon = self::coordinate($all['windowsill_lon'] ?? null, 180);
        $label = $all['windowsill_label'] ?? '';
        $units = $all['windowsill_units'] ?? '';

        if ($lat === null || $lon === null || !self::validLabel($label) || !in_array($units, self::UNITS, true)) {
            return null;
        }

        return ['lat' => $lat, 'lon' => $lon, 'label' => $label, 'units' => $units];
    }

    /**
     * @param array $place Place shape; values may be form strings.
     * @throws \InvalidArgumentException for an invalid place or unit.
     * @throws \RuntimeException when the location could not be stored.
     */
    public function save(int $userId, array $place, ?string $units = null): array
    {
        $lat = self::coordinate($place['lat'] ?? null, 90);
        $lon = self::coordinate($place['lon'] ?? null, 180);
        if ($lat === null || $lon === null) {
            throw new \InvalidArgumentException('Coordinates are missing or out of range.');
        }

        $parts = [];
        foreach (['name', 'admin1', 'country_code'] as $field) {
            $value = $place[$field] ?? '';
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                throw new \InvalidArgumentException("Place field $field must be a UTF-8 string.");
            }
            $parts[$field] = trim($value);
        }
        if ($parts['name'] === '') {
            throw new \InvalidArgumentException('Place name is required.');
        }

        $units ??= strtoupper($parts['country_code']) === 'US' ? 'us' : 'metric';
        self::checkUnits($units);

        $label = mb_substr(implode(', ', array_filter($parts, fn ($p) => $p !== '')), 0, self::LABEL_MAX, 'UTF-8');
        // Truncation can cut between parts: never end the label on a dangling separator.
        $label = rtrim($label, ', ');

        $this->store($userId, [
            'windowsill_lat' => self::format($lat),
            'windowsill_lon' => self::format($lon),
            'windowsill_label' => $label,
            'windowsill_units' => $units,
        ]);

        return ['lat' => $lat, 'lon' => $lon, 'label' => $label, 'units' => $units];
    }

    /**
     * @throws \InvalidArgumentException for a bad unit or when the user has no location.
     * @throws \RuntimeException when the units could not be stored.
     */
    public function setUnits(int $userId, string $units): void
    {
        self::checkUnits($units);
        if ($this->get($userId) === null) {
            throw new \InvalidArgumentException('No location is set.');
        }
        $this->store($userId, ['windowsill_units' => $units]);
    }

    public function clear(int $userId): void
    {
        foreach (self::KEYS as $key) {
            $this->userMetadataModel->remove($userId, $key);
        }
    }

    /**
     * Saves location values and lifts any back-off, so the new place or units are fetched straight away.
     *
     * @throws \RuntimeException
     */
    private function store(int $userId, array $values): void
    {
        if (!$this->userMetadataModel->save($userId, $values)) {
            throw new \RuntimeException('Could not store location');
        }
        $this->windowsillCache->clearFailed($userId);
    }

    /**
     * Core's user integrations form saves any posted key, so a stored label is re-checked on read.
     */
    private static function validLabel(mixed $label): bool
    {
        return is_string($label) && $label !== '' && mb_check_encoding($label, 'UTF-8')
            && mb_strlen($label, 'UTF-8') <= self::LABEL_MAX;
    }

    private static function checkUnits(string $units): void
    {
        if (!in_array($units, self::UNITS, true)) {
            throw new \InvalidArgumentException('Units must be "us" or "metric".');
        }
    }

    /**
     * A number (or numeric string) within ±$limit, rounded to 2dp; null otherwise (including NaN/INF).
     */
    private static function coordinate(mixed $value, int $limit): ?float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }
        $value = (float) $value;
        if (!($value >= -$limit && $value <= $limit)) {
            return null;
        }
        // Round through the stored representation so save() and get() agree; + 0.0 folds -0.0 into 0.0.
        return (float) self::format($value) + 0.0;
    }

    private static function format(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
