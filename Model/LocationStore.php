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
    public const KEYS = [
        'windowsill_lat', 'windowsill_lon', 'windowsill_label',
        'windowsill_units', 'windowsill_cache', 'windowsill_failed_at',
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

        if ($lat === null || $lon === null || !is_string($label) || $label === '' || !in_array($units, self::UNITS, true)) {
            return null;
        }

        return ['lat' => $lat, 'lon' => $lon, 'label' => $label, 'units' => $units];
    }

    /**
     * @param array $place Place shape; values may be form strings.
     * @throws \InvalidArgumentException
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
            if (!is_string($value)) {
                throw new \InvalidArgumentException("Place field $field must be a string.");
            }
            $parts[$field] = trim($value);
        }
        if ($parts['name'] === '') {
            throw new \InvalidArgumentException('Place name is required.');
        }

        $units ??= strtoupper($parts['country_code']) === 'US' ? 'us' : 'metric';
        self::checkUnits($units);

        $label = mb_substr(implode(', ', array_filter($parts, fn ($p) => $p !== '')), 0, self::LABEL_MAX, 'UTF-8');

        $this->userMetadataModel->save($userId, [
            'windowsill_lat' => self::format($lat),
            'windowsill_lon' => self::format($lon),
            'windowsill_label' => $label,
            'windowsill_units' => $units,
        ]);
        $this->userMetadataModel->remove($userId, 'windowsill_failed_at');

        return ['lat' => $lat, 'lon' => $lon, 'label' => $label, 'units' => $units];
    }

    /**
     * @throws \InvalidArgumentException for a bad unit or when the user has no location.
     */
    public function setUnits(int $userId, string $units): void
    {
        self::checkUnits($units);
        if ($this->get($userId) === null) {
            throw new \InvalidArgumentException('No location is set.');
        }
        $this->userMetadataModel->save($userId, ['windowsill_units' => $units]);
        $this->userMetadataModel->remove($userId, 'windowsill_failed_at');
    }

    public function clear(int $userId): void
    {
        foreach (self::KEYS as $key) {
            $this->userMetadataModel->remove($userId, $key);
        }
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
