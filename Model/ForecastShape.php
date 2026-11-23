<?php

namespace Kanboard\Plugin\Windowsill\Model;

/**
 * The one validator for a normalised Forecast: used after provider parsing and after cache decoding.
 */
class ForecastShape
{
    const DAYS = 3;

    public static function check(array $f): bool
    {
        if (!self::hasExactKeys($f, ['utc_offset', 'current', 'days'])) {
            return false;
        }
        if (!self::intIn($f['utc_offset'], -50400, 50400)) {
            return false;
        }

        $c = $f['current'];
        if (!is_array($c) || !self::hasExactKeys($c, ['temp', 'feels', 'code', 'wind', 'is_day'])) {
            return false;
        }
        if (!self::temp($c['temp']) || !self::temp($c['feels']) || !self::code($c['code'])
            || !self::floatIn($c['wind'], 0, 500) || !is_bool($c['is_day'])) {
            return false;
        }

        $days = $f['days'];
        if (!is_array($days) || !array_is_list($days) || count($days) !== self::DAYS) {
            return false;
        }
        foreach ($days as $d) {
            if (!is_array($d) || !self::hasExactKeys($d, ['date', 'code', 'max', 'min', 'pop'])) {
                return false;
            }
            if (!self::date($d['date']) || !self::code($d['code']) || !self::temp($d['max']) || !self::temp($d['min'])
                || !($d['pop'] === null || self::intIn($d['pop'], 0, 100))) {
                return false;
            }
        }

        return true;
    }

    private static function hasExactKeys(array $a, array $keys): bool
    {
        $actual = array_keys($a);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }

    private static function intIn(mixed $v, int $min, int $max): bool
    {
        return is_int($v) && $v >= $min && $v <= $max;
    }

    private static function floatIn(mixed $v, float $min, float $max): bool
    {
        return is_float($v) && is_finite($v) && $v >= $min && $v <= $max;
    }

    private static function temp(mixed $v): bool
    {
        return self::floatIn($v, -150, 150);
    }

    private static function code(mixed $v): bool
    {
        return self::intIn($v, 0, 99);
    }

    private static function date(mixed $v): bool
    {
        if (!is_string($v) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
