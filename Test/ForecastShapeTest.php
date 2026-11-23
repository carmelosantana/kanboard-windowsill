<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Windowsill\Model\ForecastShape;

class ForecastShapeTest extends Base
{
    public static function valid(): array
    {
        return [
            'utc_offset' => 3600,
            'current' => ['temp' => 36.0, 'feels' => 28.1, 'code' => 2, 'wind' => 9.3, 'is_day' => false],
            'days' => [
                ['date' => '2026-11-23', 'code' => 71, 'max' => 38.6, 'min' => 32.7, 'pop' => 30],
                ['date' => '2026-11-24', 'code' => 77, 'max' => 35.9, 'min' => 32.4, 'pop' => 13],
                ['date' => '2026-11-25', 'code' => 3, 'max' => 35.6, 'min' => 30.6, 'pop' => 0],
            ],
        ];
    }

    public function testValidPasses(): void
    {
        $this->assertTrue(ForecastShape::check(self::valid()));
    }

    public function testBoundsAreInclusive(): void
    {
        $f = self::valid();
        $f['utc_offset'] = -50400;
        $f['current'] = ['temp' => -150.0, 'feels' => 150.0, 'code' => 99, 'wind' => 500.0, 'is_day' => true];
        $f['days'][0] = ['date' => '2024-02-29', 'code' => 0, 'max' => 150.0, 'min' => -150.0, 'pop' => null];
        $f['days'][1]['pop'] = 100;
        $this->assertTrue(ForecastShape::check($f));
    }

    public static function invalidRows(): array
    {
        $rows = [];
        $set = function (string $name, callable $mutate) use (&$rows) {
            $f = self::valid();
            $mutate($f);
            $rows[$name] = [$f];
        };
        $set('temp low', function (&$f) { $f['current']['temp'] = -150.1; });
        $set('temp high', function (&$f) { $f['current']['temp'] = 150.1; });
        $set('feels low', function (&$f) { $f['current']['feels'] = -150.1; });
        $set('feels high', function (&$f) { $f['current']['feels'] = 150.1; });
        $set('max low', function (&$f) { $f['days'][0]['max'] = -150.1; });
        $set('max high', function (&$f) { $f['days'][0]['max'] = 150.1; });
        $set('min low', function (&$f) { $f['days'][2]['min'] = -150.1; });
        $set('min high', function (&$f) { $f['days'][2]['min'] = 150.1; });
        $set('wind negative', function (&$f) { $f['current']['wind'] = -0.1; });
        $set('wind high', function (&$f) { $f['current']['wind'] = 500.1; });
        $set('current code low', function (&$f) { $f['current']['code'] = -1; });
        $set('current code high', function (&$f) { $f['current']['code'] = 100; });
        $set('current code float', function (&$f) { $f['current']['code'] = 2.0; });
        $set('day code low', function (&$f) { $f['days'][1]['code'] = -1; });
        $set('day code high', function (&$f) { $f['days'][1]['code'] = 100; });
        $set('is_day int', function (&$f) { $f['current']['is_day'] = 1; });
        $set('pop low', function (&$f) { $f['days'][0]['pop'] = -1; });
        $set('pop high', function (&$f) { $f['days'][0]['pop'] = 101; });
        $set('pop float', function (&$f) { $f['days'][0]['pop'] = 30.0; });
        $set('utc_offset low', function (&$f) { $f['utc_offset'] = -50401; });
        $set('utc_offset high', function (&$f) { $f['utc_offset'] = 50401; });
        $set('utc_offset string', function (&$f) { $f['utc_offset'] = '3600'; });
        $set('date format', function (&$f) { $f['days'][0]['date'] = '23/11/2026'; });
        $set('date impossible', function (&$f) { $f['days'][0]['date'] = '2026-02-30'; });
        $set('date with suffix', function (&$f) { $f['days'][0]['date'] = '2026-11-23x'; });
        $set('temp NaN', function (&$f) { $f['current']['temp'] = NAN; });
        $set('temp string', function (&$f) { $f['current']['temp'] = '36.0'; });
        $set('two days', function (&$f) { array_pop($f['days']); });
        $set('four days', function (&$f) { $f['days'][] = $f['days'][0]; });
        $set('days not a list', function (&$f) { $f['days'] = ['a' => $f['days'][0], 'b' => $f['days'][1], 'c' => $f['days'][2]]; });
        $set('extra key in current', function (&$f) { $f['current']['humidity'] = 50; });
        $set('missing key in current', function (&$f) { unset($f['current']['wind']); });
        $set('extra key in day', function (&$f) { $f['days'][0]['snow'] = 1.0; });
        $set('extra top-level key', function (&$f) { $f['extra'] = 1; });
        $set('missing current', function (&$f) { unset($f['current']); });
        $set('current not array', function (&$f) { $f['current'] = 'x'; });
        $set('day not array', function (&$f) { $f['days'][0] = 'x'; });
        return $rows;
    }

    /**
     * @dataProvider invalidRows
     */
    public function testInvalidFails(array $f): void
    {
        $this->assertFalse(ForecastShape::check($f));
    }
}
