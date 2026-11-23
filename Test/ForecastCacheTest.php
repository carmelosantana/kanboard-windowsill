<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Windowsill\Model\ForecastCache;
use Kanboard\Plugin\Windowsill\Model\HttpsFetcher;
use Kanboard\Plugin\Windowsill\Model\OpenMeteoClient;
use Kanboard\Plugin\Windowsill\Plugin;
use PHPUnit\Framework\Attributes\DataProvider;

class ForecastCacheTest extends Base
{
    private const KEY = '52.52,13.41,us';

    private function user(string $name): int
    {
        return (int) $this->container['userModel']->create(['username' => $name]);
    }

    private function cache(): ForecastCache
    {
        return new ForecastCache($this->container);
    }

    /**
     * The Task 3 fixture forecast, as produced by OpenMeteoClient::forecast().
     */
    private function fixtureForecast(): array
    {
        $http = $this->createMock(HttpsFetcher::class);
        $http->method('get')->willReturn(file_get_contents(__DIR__.'/fixtures/forecast.json'));
        $this->container['windowsillHttp'] = $http;
        return (new OpenMeteoClient($this->container))->forecast(52.52, 13.41, 'us');
    }

    private static function worstCase(): array
    {
        $day = fn (string $date) => ['date' => $date, 'code' => 99, 'max' => -149.9, 'min' => -149.9, 'pop' => 100];
        return [
            'utc_offset' => -50400,
            'current' => ['temp' => -149.9, 'feels' => -149.9, 'code' => 99, 'wind' => 499.9, 'is_day' => true],
            'days' => [$day('2026-11-23'), $day('2026-11-24'), $day('2026-11-25')],
        ];
    }

    private function assertForecastEquals(array $expected, array $actual): void
    {
        $round = function (array $f): array {
            array_walk_recursive($f, function (&$v) {
                if (is_float($v)) {
                    $v = round($v, 1);
                }
            });
            return $f;
        };
        $this->assertSame($round($expected), $round($actual));
    }

    public function testContainerRegistersCache(): void
    {
        (new Plugin($this->container))->initialize();
        $this->assertInstanceOf(ForecastCache::class, $this->container['windowsillCache']);
    }

    public function testKeyFor(): void
    {
        $this->assertSame(self::KEY, ForecastCache::keyFor(['lat' => 52.52, 'lon' => 13.41, 'label' => 'Berlin', 'units' => 'us']));
        $this->assertSame('-89.99,-179.99,metric', ForecastCache::keyFor(['lat' => -89.99, 'lon' => -179.99, 'label' => 'x', 'units' => 'metric']));
    }

    public function testRoundTrip(): void
    {
        $forecast = $this->fixtureForecast();
        $cache = $this->cache();

        $raw = $cache->encode($forecast, 1700000000, self::KEY);
        $this->assertIsString($raw);
        $this->assertLessThanOrEqual(ForecastCache::MAX_LEN, strlen($raw));

        $decoded = $cache->decode($raw);
        $this->assertNotNull($decoded);
        $this->assertSame(1700000000, $decoded['fetched_at']);
        $this->assertSame(self::KEY, $decoded['key']);
        $this->assertForecastEquals($forecast, $decoded['forecast']);
        $this->assertSame(36.0, $decoded['forecast']['current']['temp']);
        $this->assertFalse($decoded['forecast']['current']['is_day']);
    }

    public function testNullPopRoundTrips(): void
    {
        $forecast = $this->fixtureForecast();
        $forecast['days'][1]['pop'] = null;
        $decoded = $this->cache()->decode($this->cache()->encode($forecast, 1, self::KEY));
        $this->assertNull($decoded['forecast']['days'][1]['pop']);
        $this->assertForecastEquals($forecast, $decoded['forecast']);
    }

    public function testWriteAndRead(): void
    {
        $id = $this->user('alice');
        $forecast = $this->fixtureForecast();

        $this->assertNull($this->cache()->read($id));
        $this->assertTrue($this->cache()->write($id, $forecast, 1700000000, self::KEY));

        $read = $this->cache()->read($id);
        $this->assertSame(1700000000, $read['fetched_at']);
        $this->assertSame(self::KEY, $read['key']);
        $this->assertForecastEquals($forecast, $read['forecast']);
    }

    public function testWorstCaseFitsColumn(): void
    {
        $raw = $this->cache()->encode(self::worstCase(), 9999999999, '-89.99,-179.99,metric');
        $this->assertIsString($raw);
        $this->assertLessThanOrEqual(255, strlen($raw));
        $this->assertNotNull($this->cache()->decode($raw));
    }

    public function testInvalidForecastIsNotEncoded(): void
    {
        $id = $this->user('bob');
        $forecast = $this->fixtureForecast();
        $forecast['current']['temp'] = 1e200;

        $this->assertNull($this->cache()->encode($forecast, 1700000000, self::KEY));
        $this->assertFalse($this->cache()->write($id, $forecast, 1700000000, self::KEY));
        $this->assertFalse($this->container['userMetadataModel']->exists($id, 'windowsill_cache'));
    }

    public function testCommaDecimalLocale(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        try {
            if (setlocale(LC_NUMERIC, 'en_DK.utf8') === false) {
                $this->markTestSkipped('en_DK.utf8 locale not installed');
            }
            if (sprintf('%.2f', 52.52) !== '52,52') {
                $this->markTestSkipped('locale does not format with a decimal comma');
            }
            $this->assertSame(self::KEY, ForecastCache::keyFor(['lat' => 52.52, 'lon' => 13.41, 'label' => 'Berlin', 'units' => 'us']));

            $forecast = $this->fixtureForecast();
            $decoded = $this->cache()->decode($this->cache()->encode($forecast, 1700000000, self::KEY));
            $this->assertNotNull($decoded);
            $this->assertForecastEquals($forecast, $decoded['forecast']);
        } finally {
            setlocale(LC_NUMERIC, $previous);
        }
    }

    public static function garbage(): array
    {
        $head = '1|1700000000|52.52,13.41,us|3600|36.0,28.1,2,9.3,0|';
        $d1 = '2026-11-23,71,38.6,32.7,30';
        $d2 = '2026-11-24,77,35.9,32.4,13';
        $d3 = '2026-11-25,3,35.6,30.6,0';
        $valid = $head.$d1.';'.$d2.';'.$d3;
        return [
            'empty' => [''],
            'garbage' => ['garbage'],
            'three fields' => ['1|x|y'],
            'unknown version' => ['2'.substr($valid, 1)],
            'two days' => [$head.$d1.';'.$d2],
            'bad date' => [$head.'2026-13-45,71,38.6,32.7,30;'.$d2.';'.$d3],
            'temp not a number' => [str_replace('36.0,28.1', 'abc,28.1', $valid)],
            'extra field' => [$valid.'|x'],
            'missing field' => ['1|1700000000|52.52,13.41,us|36.0,28.1,2,9.3,0|'.$d1.';'.$d2.';'.$d3],
            'pop 101' => [$head.'2026-11-23,71,38.6,32.7,101;'.$d2.';'.$d3],
            'is_day 2' => [str_replace('9.3,0|', '9.3,2|', $valid)],
            'fetched_at negative' => [str_replace('|1700000000|', '|-1|', $valid)],
            'bad key' => [str_replace('52.52,13.41,us', 'x', $valid)],
        ];
    }

    public function testValidRowDecodes(): void
    {
        // Guards the garbage cases: the row they mutate is itself valid.
        $valid = '1|1700000000|52.52,13.41,us|3600|36.0,28.1,2,9.3,0|2026-11-23,71,38.6,32.7,30;2026-11-24,77,35.9,32.4,13;2026-11-25,3,35.6,30.6,0';
        $this->assertNotNull($this->cache()->decode($valid));
    }

    /**
     * @dataProvider garbage
     */
    #[DataProvider('garbage')]
    public function testDecodeRejectsGarbage(string $raw): void
    {
        $this->assertNull($this->cache()->decode($raw));
    }

    public static function oversized(): array
    {
        $valid = '1|1700000000|52.52,13.41,us|3600|36.0,28.1,2,9.3,0|2026-11-23,71,38.6,32.7,30;2026-11-24,77,35.9,32.4,13;2026-11-25,3,35.6,30.6,0';
        return [
            '256 bytes' => [$valid.str_repeat('0', ForecastCache::MAX_LEN + 1 - strlen($valid))],
            '1 MB' => [str_repeat($valid.'|', intdiv(1 << 20, strlen($valid) + 1) + 1)],
        ];
    }

    /**
     * @dataProvider oversized
     */
    #[DataProvider('oversized')]
    public function testDecodeRejectsOversizedInput(string $raw): void
    {
        $this->assertGreaterThan(ForecastCache::MAX_LEN, strlen($raw));
        $this->assertNull($this->cache()->decode($raw));
    }

    public function testDecodeDoesNotSplitOversizedInput(): void
    {
        // A metadata value longer than the column allows is rejected before explode() allocates per-field strings.
        $raw = str_repeat('|', 1 << 20);
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        $this->assertNull($this->cache()->decode($raw));
        $this->assertLessThan(1 << 20, memory_get_peak_usage() - $before);
    }

    public function testFailedAtRoundTrip(): void
    {
        $id = $this->user('carol');
        $this->assertSame(0, $this->cache()->failedAt($id));

        $this->cache()->markFailed($id, 1700000000);
        $this->assertSame(1700000000, $this->cache()->failedAt($id));

        $this->cache()->clearFailed($id);
        $this->assertSame(0, $this->cache()->failedAt($id));
    }
}
