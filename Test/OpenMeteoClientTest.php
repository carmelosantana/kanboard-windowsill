<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Core\Http\Client;
use Kanboard\Core\Http\ClientException;
use Kanboard\Core\Http\InvalidStatusException;
use Kanboard\Plugin\Windowsill\Model\OpenMeteoClient;
use Kanboard\Plugin\Windowsill\Model\ProviderException;
use Psr\Log\LoggerInterface;

class OpenMeteoClientTest extends Base
{
    /** @var string[] */
    private array $urls = [];

    private static function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/fixtures/'.$name);
    }

    private static function forecastWith(callable $mutate): string
    {
        $data = json_decode(self::fixture('forecast.json'), true);
        $mutate($data);
        return json_encode($data);
    }

    /**
     * Replace the transport with a mock that records URLs and returns $body (or throws it).
     */
    private function transport(string|\Throwable $result, bool $expectCall = true): void
    {
        $http = $this->createMock(Client::class);
        $http->expects($expectCall ? $this->once() : $this->never())
            ->method('get')
            ->with($this->anything(), $this->anything(), $this->identicalTo(true))
            ->willReturnCallback(function (string $url) use ($result) {
                $this->urls[] = $url;
                if ($result instanceof \Throwable) {
                    throw $result;
                }
                return $result;
            });
        $this->container['windowsillHttp'] = $http;
    }

    private function query(string $prefix): array
    {
        $this->assertCount(1, $this->urls);
        $this->assertStringStartsWith($prefix.'?', $this->urls[0]);
        parse_str(substr($this->urls[0], strlen($prefix) + 1), $query);
        return $query;
    }

    public function testForecastUrlIsFixedHostWithExactParams(): void
    {
        $expected = [
            'latitude' => '52.52',
            'longitude' => '13.41',
            'current' => 'temperature_2m,apparent_temperature,weather_code,wind_speed_10m,is_day',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
            'temperature_unit' => 'fahrenheit',
            'wind_speed_unit' => 'mph',
            'timezone' => 'auto',
            'forecast_days' => '3',
        ];

        $this->transport(self::fixture('forecast.json'));
        (new OpenMeteoClient($this->container))->forecast(52.52, 13.41, 'us');
        $this->assertSame($expected, $this->query(OpenMeteoClient::FORECAST_URL));

        $this->urls = [];
        $this->transport(self::fixture('forecast.json'));
        (new OpenMeteoClient($this->container))->forecast(52.52, 13.41, 'metric');
        $this->assertSame(
            array_merge($expected, ['temperature_unit' => 'celsius', 'wind_speed_unit' => 'kmh']),
            $this->query(OpenMeteoClient::FORECAST_URL)
        );
    }

    public function testForecastUrlIsLocaleIndependent(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        try {
            if (setlocale(LC_NUMERIC, 'en_DK.utf8') === false) {
                $this->markTestSkipped('en_DK.utf8 locale not installed');
            }
            $this->assertSame('52,52', sprintf('%.2f', 52.52), 'locale should use a decimal comma');
            $this->transport(self::fixture('forecast.json'));
            (new OpenMeteoClient($this->container))->forecast(52.52, 13.41, 'us');
            $query = $this->query(OpenMeteoClient::FORECAST_URL);
            $this->assertSame('52.52', $query['latitude']);
            $this->assertSame('13.41', $query['longitude']);
        } finally {
            setlocale(LC_NUMERIC, $previous);
        }
    }

    public function testForecastNormalisesFixture(): void
    {
        $this->transport(self::fixture('forecast.json'));
        $f = (new OpenMeteoClient($this->container))->forecast(52.52, 13.41, 'us');

        $this->assertSame(3600, $f['utc_offset']);
        $this->assertSame(['temp' => 36.0, 'feels' => 28.1, 'code' => 2, 'wind' => 9.3, 'is_day' => false], $f['current']);
        $this->assertCount(3, $f['days']);
        $this->assertSame(['date' => '2026-11-23', 'code' => 71, 'max' => 38.6, 'min' => 32.7, 'pop' => 30], $f['days'][0]);
        $this->assertSame('2026-11-25', $f['days'][2]['date']);
    }

    public function testNullPrecipitationBecomesNull(): void
    {
        $this->transport(self::forecastWith(function (&$d) { $d['daily']['precipitation_probability_max'][1] = null; }));
        $f = (new OpenMeteoClient($this->container))->forecast(52.52, 13.41, 'us');
        $this->assertNull($f['days'][1]['pop']);
        $this->assertSame(30, $f['days'][0]['pop']);
    }

    public static function forecastFailures(): array
    {
        $with = fn (callable $m) => self::forecastWith($m);
        return [
            'client exception' => [new ClientException('Unreachable URL: https://api.open-meteo.com/v1/forecast?latitude=52.52')],
            'invalid status' => [new InvalidStatusException('Request failed with status code 500', 500, 'oops')],
            'empty body' => [''],
            'not json' => ['not json'],
            'empty array' => ['[]'],
            'current missing' => [$with(function (&$d) { unset($d['current']); })],
            'daily is a string' => [$with(function (&$d) { $d['daily'] = 'x'; })],
            'temperature is a string' => [$with(function (&$d) { $d['current']['temperature_2m'] = '36'; })],
            'temperature huge' => [$with(function (&$d) { $d['current']['temperature_2m'] = 1e200; })],
            'two days' => [$with(function (&$d) { $d['daily']['time'] = ['2026-11-23', '2026-11-24']; })],
            'ragged daily arrays' => [$with(function (&$d) { array_pop($d['daily']['temperature_2m_min']); })],
            'date format' => [$with(function (&$d) { $d['daily']['time'][0] = '23/11/2026'; })],
            'impossible date' => [$with(function (&$d) { $d['daily']['time'][0] = '2026-02-30'; })],
            'weather code string' => [$with(function (&$d) { $d['daily']['weather_code'][0] = 'x'; })],
            'is_day 2' => [$with(function (&$d) { $d['current']['is_day'] = 2; })],
            'pop 101' => [$with(function (&$d) { $d['daily']['precipitation_probability_max'][0] = 101; })],
            'utc offset missing' => [$with(function (&$d) { unset($d['utc_offset_seconds']); })],
        ];
    }

    /**
     * @dataProvider forecastFailures
     */
    public function testForecastFailuresThrowAndLogOnce(string|\Throwable $result): void
    {
        $this->transport($result);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('Windowsill: forecast request failed');
        $this->container['logger'] = $logger;

        $this->expectException(ProviderException::class);
        (new OpenMeteoClient($this->container))->forecast(52.52, 13.41, 'us');
    }

    public function testSearchPlacesUrlAndShape(): void
    {
        $this->transport(self::fixture('geocode.json'));
        $places = (new OpenMeteoClient($this->container))->searchPlaces('  Springfield ');

        $this->assertSame(
            ['name' => 'Springfield', 'count' => '5', 'language' => 'en', 'format' => 'json'],
            $this->query(OpenMeteoClient::GEOCODE_URL)
        );
        $this->assertNotEmpty($places);
        $this->assertLessThanOrEqual(5, count($places));
        $this->assertTrue(array_is_list($places));
        $this->assertSame(
            ['name' => 'Springfield', 'admin1' => 'Missouri', 'country_code' => 'US', 'lat' => 37.21533, 'lon' => -93.29824],
            $places[0]
        );
    }

    public function testSearchSkipsInvalidResults(): void
    {
        $ok = ['name' => 'Keep', 'latitude' => 10.5, 'longitude' => 20, 'country_code' => 'DE', 'admin1' => 'Bayern'];
        $long = str_repeat('ü', 120);
        $body = json_encode(['results' => [
            array_diff_key($ok, ['latitude' => 1]),
            array_merge($ok, ['latitude' => 91]),
            array_merge($ok, ['name' => '']),
            array_merge($ok, ['name' => ['x']]),
            array_diff_key($ok, ['admin1' => 1]),
            array_merge($ok, ['name' => $long, 'admin1' => $long]),
            array_merge($ok, ['country_code' => 'de']),
            array_merge($ok, ['country_code' => 'DEU']),
        ]]);
        $this->transport($body);
        $places = (new OpenMeteoClient($this->container))->searchPlaces('Keep');

        $this->assertSame([
            ['name' => 'Keep', 'admin1' => '', 'country_code' => 'DE', 'lat' => 10.5, 'lon' => 20.0],
            ['name' => str_repeat('ü', 100), 'admin1' => str_repeat('ü', 100), 'country_code' => 'DE', 'lat' => 10.5, 'lon' => 20.0],
            ['name' => 'Keep', 'admin1' => 'Bayern', 'country_code' => '', 'lat' => 10.5, 'lon' => 20.0],
            ['name' => 'Keep', 'admin1' => 'Bayern', 'country_code' => '', 'lat' => 10.5, 'lon' => 20.0],
        ], $places);
    }

    public function testSearchNoResults(): void
    {
        $this->transport(self::fixture('geocode-empty.json'));
        $this->assertSame([], (new OpenMeteoClient($this->container))->searchPlaces('Nowhereville'));
    }

    public static function badQueries(): array
    {
        return [
            'empty' => [''],
            'one char' => ['a'],
            'one char padded' => ['   a  '],
            'too long' => [str_repeat('a', 101)],
        ];
    }

    /**
     * @dataProvider badQueries
     */
    public function testSearchRejectsBadQuery(string $query): void
    {
        $this->transport('', false);
        $this->expectException(\InvalidArgumentException::class);
        (new OpenMeteoClient($this->container))->searchPlaces($query);
    }

    public static function searchFailures(): array
    {
        return [
            'client exception' => [new ClientException('Unreachable URL: https://geocoding-api.open-meteo.com/v1/search?name=Springfield')],
            'not json' => ['not json'],
            'results not a list' => ['{"results":"x"}'],
        ];
    }

    /**
     * @dataProvider searchFailures
     */
    public function testSearchFailuresThrowAndLogOnce(string|\Throwable $result): void
    {
        $this->transport($result);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Windowsill: place search failed');
        $this->container['logger'] = $logger;

        $this->expectException(ProviderException::class);
        (new OpenMeteoClient($this->container))->searchPlaces('Springfield');
    }
}
