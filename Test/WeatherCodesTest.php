<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Windowsill\Model\WeatherCodes;

class WeatherCodesTest extends Base
{
    public function testClearDayAndNight(): void
    {
        $this->assertSame(['label' => 'Clear', 'glyph' => '☀'], WeatherCodes::describe(0));
        $this->assertSame(['label' => 'Clear', 'glyph' => '☀'], WeatherCodes::describe(0, true));
        $this->assertSame(['label' => 'Clear', 'glyph' => '☾'], WeatherCodes::describe(0, false));
    }

    public function testMainlyClearDayAndNight(): void
    {
        $this->assertSame(['label' => 'Mainly clear', 'glyph' => '☀'], WeatherCodes::describe(1));
        $this->assertSame(['label' => 'Mainly clear', 'glyph' => '☾'], WeatherCodes::describe(1, false));
    }

    public static function labelProvider(): array
    {
        return [
            [2, 'Partly cloudy'], [3, 'Overcast'],
            [45, 'Fog'], [48, 'Fog'],
            [51, 'Drizzle'], [53, 'Drizzle'], [55, 'Drizzle'], [56, 'Drizzle'], [57, 'Drizzle'],
            [61, 'Rain'], [63, 'Rain'], [65, 'Rain'], [66, 'Rain'], [67, 'Rain'],
            [71, 'Snow'], [73, 'Snow'], [75, 'Snow'], [77, 'Snow'], [85, 'Snow'], [86, 'Snow'],
            [80, 'Showers'], [81, 'Showers'], [82, 'Showers'],
            [95, 'Thunderstorm'], [96, 'Thunderstorm'], [99, 'Thunderstorm'],
        ];
    }

    /** @dataProvider labelProvider */
    public function testLabels(int $code, string $label): void
    {
        $described = WeatherCodes::describe($code);
        $this->assertSame($label, $described['label']);
        $this->assertContains($described['glyph'], ['⛅', '☁', '🌫', '🌦', '🌧', '🌨', '⛈']);
        // Non-clear codes do not change with day/night.
        $this->assertSame($described, WeatherCodes::describe($code, false));
    }

    public function testUnknownCodes(): void
    {
        foreach ([42, -1, 4, 100, 1000] as $code) {
            $this->assertSame(['label' => 'Unknown', 'glyph' => '·'], WeatherCodes::describe($code), "code $code");
        }
    }

    public function testLabelsPassThroughTranslator(): void
    {
        $source = file_get_contents(__DIR__.'/../Model/WeatherCodes.php');
        foreach (['Clear', 'Mainly clear', 'Partly cloudy', 'Overcast', 'Fog', 'Drizzle', 'Rain', 'Snow', 'Showers', 'Thunderstorm', 'Unknown'] as $label) {
            $this->assertStringContainsString("t('$label')", $source);
        }
    }
}
