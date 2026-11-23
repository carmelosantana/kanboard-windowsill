<?php
namespace Kanboard\Plugin\Windowsill\Model;

use Kanboard\Core\Base;

/**
 * Per-user forecast cache in one user_has_metadata value (VARCHAR(255)), plus the last failure time.
 *
 * Format, version 1 (floats %.1F so a comma-decimal LC_NUMERIC never leaks in; pop empty when null):
 * 1|<fetched_at>|<key>|<utc_offset>|<temp>,<feels>,<code>,<wind>,<is_day 0/1>|<date>,<code>,<max>,<min>,<pop>;<day2>;<day3>
 *
 * Anything that does not parse, or decodes to a forecast failing ForecastShape::check(), is a miss (null).
 */
class ForecastCache extends Base
{
    public const MAX_LEN = 255;

    private const VERSION = '1';
    public const CACHE_KEY = 'windowsill_cache';
    public const FAILED_KEY = 'windowsill_failed_at';

    private const INT = '-?\d{1,6}';
    private const FLOAT = '-?\d{1,3}\.\d';

    public static function keyFor(array $location): string
    {
        return sprintf('%.2F,%.2F,%s', $location['lat'], $location['lon'], $location['units']);
    }

    public function encode(array $forecast, int $fetchedAt, string $key): ?string
    {
        if ($fetchedAt < 0 || str_contains($key, '|') || !ForecastShape::check($forecast)) {
            return null;
        }

        $c = $forecast['current'];
        $days = array_map(fn (array $d) => sprintf(
            '%s,%d,%.1F,%.1F,%s',
            $d['date'], $d['code'], $d['max'], $d['min'], $d['pop'] === null ? '' : (string) $d['pop']
        ), $forecast['days']);

        $raw = implode('|', [
            self::VERSION,
            (string) $fetchedAt,
            $key,
            (string) $forecast['utc_offset'],
            sprintf('%.1F,%.1F,%d,%.1F,%d', $c['temp'], $c['feels'], $c['code'], $c['wind'], $c['is_day'] ? 1 : 0),
            implode(';', $days),
        ]);

        return strlen($raw) <= self::MAX_LEN ? $raw : null;
    }

    /**
     * @return array{fetched_at: int, key: string, forecast: array}|null
     */
    public function decode(string $raw): ?array
    {
        // Core's user integrations form can store any length; never split what encode() could not have written.
        if (strlen($raw) > self::MAX_LEN) {
            return null;
        }
        $fields = explode('|', $raw);
        if (count($fields) !== 6 || $fields[0] !== self::VERSION) {
            return null;
        }
        [, $fetchedAt, $key, $offset, $current, $days] = $fields;

        if (!preg_match('/^\d{1,10}$/D', $fetchedAt)
            || !preg_match('/^-?\d{1,2}\.\d{2},-?\d{1,3}\.\d{2},(us|metric)$/D', $key)
            || !preg_match('/^'.self::INT.'$/D', $offset)) {
            return null;
        }

        $f = self::FLOAT;
        $i = self::INT;
        if (!preg_match("/^($f),($f),($i),($f),([01])$/D", $current, $c)) {
            return null;
        }

        $parsedDays = [];
        foreach (explode(';', $days) as $day) {
            if (!preg_match("/^(\d{4}-\d{2}-\d{2}),($i),($f),($f),(\d{0,3})$/D", $day, $d)) {
                return null;
            }
            $parsedDays[] = [
                'date' => $d[1],
                'code' => (int) $d[2],
                'max' => (float) $d[3],
                'min' => (float) $d[4],
                'pop' => $d[5] === '' ? null : (int) $d[5],
            ];
        }

        $forecast = [
            'utc_offset' => (int) $offset,
            'current' => [
                'temp' => (float) $c[1],
                'feels' => (float) $c[2],
                'code' => (int) $c[3],
                'wind' => (float) $c[4],
                'is_day' => $c[5] === '1',
            ],
            'days' => $parsedDays,
        ];

        if (!ForecastShape::check($forecast)) {
            return null;
        }

        return ['fetched_at' => (int) $fetchedAt, 'key' => $key, 'forecast' => $forecast];
    }

    public function read(int $userId): ?array
    {
        $raw = $this->userMetadataModel->get($userId, self::CACHE_KEY, '');
        return is_string($raw) && $raw !== '' ? $this->decode($raw) : null;
    }

    public function write(int $userId, array $forecast, int $fetchedAt, string $key): bool
    {
        $raw = $this->encode($forecast, $fetchedAt, $key);
        if ($raw === null) {
            return false;
        }
        return (bool) $this->userMetadataModel->save($userId, [self::CACHE_KEY => $raw]);
    }

    public function failedAt(int $userId): int
    {
        $raw = $this->userMetadataModel->get($userId, self::FAILED_KEY, '');
        return is_string($raw) && preg_match('/^\d{1,10}$/D', $raw) ? (int) $raw : 0;
    }

    public function markFailed(int $userId, int $at): void
    {
        $this->userMetadataModel->save($userId, [self::FAILED_KEY => (string) $at]);
    }

    public function clearFailed(int $userId): void
    {
        $this->userMetadataModel->remove($userId, self::FAILED_KEY);
    }
}
