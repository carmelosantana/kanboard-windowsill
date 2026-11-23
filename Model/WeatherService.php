<?php
namespace Kanboard\Plugin\Windowsill\Model;

use Kanboard\Core\Base;

/**
 * Turns the stored location, the forecast cache and the provider into the widget's view model.
 *
 * View model: ['state' => 'unset'|'fresh'|'stale'|'pending'|'unavailable', 'location' => ?Location,
 *              'forecast' => ?Forecast, 'fetched_at' => ?int, 'aged' => bool, 'now' => int]
 *
 * cached() never calls the provider (page render); refresh() may, and never answers 'pending'.
 * After a failure (or a result that could not be stored) the provider is not asked again for BACKOFF seconds.
 */
class WeatherService extends Base
{
    public const FRESH = 1800;
    public const STALE_MAX = 86400;
    public const BACKOFF = 600;

    /** Clock skew tolerated, in seconds: a cache or failure stamped further ahead than this is ignored. */
    private const SKEW = 60;

    public function cached(int $userId, ?int $now = null): array
    {
        return $this->resolve($userId, $now ?? time(), false);
    }

    public function refresh(int $userId, ?int $now = null): array
    {
        return $this->resolve($userId, $now ?? time(), true);
    }

    private function resolve(int $userId, int $now, bool $fetch): array
    {
        $location = $this->windowsillLocation->get($userId);
        if ($location === null) {
            return self::view('unset', null, null, null, $now);
        }

        $entry = $this->windowsillCache->read($userId);
        if ($entry !== null && ($entry['key'] !== ForecastCache::keyFor($location) || $entry['fetched_at'] > $now + self::SKEW)) {
            $entry = null;
        }
        $age = $entry === null ? null : $now - $entry['fetched_at'];

        if ($entry !== null && $age < self::FRESH) {
            return self::view('fresh', $location, $entry['forecast'], $entry['fetched_at'], $now);
        }

        $failedAt = $this->windowsillCache->failedAt($userId);
        $inBackoff = $failedAt > 0 && $failedAt <= $now + self::SKEW && $now - $failedAt < self::BACKOFF;

        if ($inBackoff) {
            return self::fallback($location, $entry, $age, $now);
        }

        if (!$fetch) {
            return $entry !== null && $age < self::STALE_MAX
                ? self::view('pending', $location, $entry['forecast'], $entry['fetched_at'], $now)
                : self::view('pending', $location, null, null, $now);
        }

        try {
            $forecast = $this->windowsillClient->forecast($location['lat'], $location['lon'], $location['units']);
        } catch (ProviderException) {
            $this->windowsillCache->markFailed($userId, $now);
            return self::fallback($location, $entry, $age, $now);
        }

        if ($this->windowsillCache->write($userId, $forecast, $now, ForecastCache::keyFor($location))) {
            $this->windowsillCache->clearFailed($userId);
        } else {
            // Back off anyway, so a result we cannot store does not mean a fetch on every page load.
            $this->windowsillCache->markFailed($userId, $now);
            $this->logger->warning('Windowsill: could not store forecast');
        }
        return self::view('fresh', $location, $forecast, $now, $now);
    }

    /**
     * The provider is backing off or just failed: the cached forecast while it is under STALE_MAX old, else nothing.
     */
    private static function fallback(array $location, ?array $entry, ?int $age, int $now): array
    {
        return $entry !== null && $age < self::STALE_MAX
            ? self::view('stale', $location, $entry['forecast'], $entry['fetched_at'], $now)
            : self::view('unavailable', $location, null, null, $now);
    }

    private static function view(string $state, ?array $location, ?array $forecast, ?int $fetchedAt, int $now): array
    {
        return [
            'state' => $state,
            'location' => $location,
            'forecast' => $forecast,
            'fetched_at' => $fetchedAt,
            'aged' => $forecast !== null && $now - $fetchedAt >= self::FRESH,
            'now' => $now,
        ];
    }
}
