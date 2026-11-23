<?php

namespace Kanboard\Plugin\Windowsill\Model;

/**
 * WMO weather interpretation codes (as returned by Open-Meteo) → label and plain-Unicode glyph.
 */
class WeatherCodes
{
    /**
     * @return array{label: string, glyph: string}
     */
    public static function describe(int $code, bool $isDay = true): array
    {
        return match ($code) {
            0 => ['label' => t('Clear'), 'glyph' => $isDay ? '☀' : '☾'],
            1 => ['label' => t('Mainly clear'), 'glyph' => $isDay ? '☀' : '☾'],
            2 => ['label' => t('Partly cloudy'), 'glyph' => '⛅'],
            3 => ['label' => t('Overcast'), 'glyph' => '☁'],
            45, 48 => ['label' => t('Fog'), 'glyph' => '🌫'],
            51, 53, 55, 56, 57 => ['label' => t('Drizzle'), 'glyph' => '🌦'],
            61, 63, 65, 66, 67 => ['label' => t('Rain'), 'glyph' => '🌧'],
            71, 73, 75, 77, 85, 86 => ['label' => t('Snow'), 'glyph' => '🌨'],
            80, 81, 82 => ['label' => t('Showers'), 'glyph' => '🌦'],
            95, 96, 99 => ['label' => t('Thunderstorm'), 'glyph' => '⛈'],
            default => ['label' => t('Unknown'), 'glyph' => '·'],
        };
    }
}
