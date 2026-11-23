<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Windowsill\Controller\WindowsillController;
use Kanboard\Plugin\Windowsill\Model\ForecastCache;
use Kanboard\Plugin\Windowsill\Model\OpenMeteoClient;
use Kanboard\Plugin\Windowsill\Plugin;

class WidgetTest extends Base
{
    private const ATTRIBUTION = '<a href="https://open-meteo.com/" target="_blank" rel="noopener noreferrer">Weather data by Open-Meteo.com</a>';
    private const SPRINGFIELD = ['name' => 'Springfield', 'admin1' => 'Missouri', 'country_code' => 'US', 'lat' => 37.2, 'lon' => -93.3];

    private int $alice;
    private string $html = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->container['logger'] = $this->createMock(\Psr\Log\LoggerInterface::class);
        (new Plugin($this->container))->initialize();
        $this->alice = (new UserModel($this->container))->create(['username' => 'alice', 'name' => 'Alice Example']);
        $this->loginAs($this->alice);
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    /** 2026-11-23 01:00 at the location (UTC+1). */
    private static function now(int $plusDays = 0): int
    {
        return gmmktime(0, 0, 0, 11, 23, 2026) + $plusDays * 86400;
    }

    private static function forecast(): array
    {
        $json = json_decode(file_get_contents(__DIR__.'/fixtures/forecast.json'), true);
        $days = [];
        for ($i = 0; $i < 3; $i++) {
            $days[] = [
                'date' => $json['daily']['time'][$i],
                'code' => $json['daily']['weather_code'][$i],
                'max' => (float) $json['daily']['temperature_2m_max'][$i],
                'min' => (float) $json['daily']['temperature_2m_min'][$i],
                'pop' => $json['daily']['precipitation_probability_max'][$i],
            ];
        }
        return [
            'utc_offset' => $json['utc_offset_seconds'],
            'current' => [
                'temp' => (float) $json['current']['temperature_2m'],
                'feels' => (float) $json['current']['apparent_temperature'],
                'code' => $json['current']['weather_code'],
                'wind' => (float) $json['current']['wind_speed_10m'],
                'is_day' => $json['current']['is_day'] === 1,
            ],
            'days' => $days,
        ];
    }

    private static function vm(string $state, bool $withForecast = true, array $overrides = []): array
    {
        $now = $overrides['now'] ?? self::now();
        $aged = $overrides['aged'] ?? false;
        return [
            'state' => $state,
            'location' => $state === 'unset' ? null : ['lat' => 52.51, 'lon' => 13.42, 'label' => $overrides['label'] ?? 'Berlin, Land Berlin, DE', 'units' => $overrides['units'] ?? 'us'],
            'forecast' => $withForecast ? self::forecast() : null,
            'fetched_at' => $withForecast ? ($aged ? $now - 3600 : $now - 60) : null,
            'aged' => $aged,
            'now' => $now,
        ];
    }

    private function render(?array $vm, string $refreshUrl = '/?controller=WindowsillController&action=widget&plugin=Windowsill&csrf_token=abc'): string
    {
        return $this->container['template']->render('Windowsill:dashboard/widget', [
            'windowsill' => $vm,
            'windowsill_refresh_url' => $refreshUrl,
        ]);
    }

    private function settingsHref(): string
    {
        return $this->container['helper']->url->href('WindowsillController', 'settings', ['plugin' => 'Windowsill']);
    }

    private function call(array $query): string
    {
        $this->html = '';
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], $query + ['_' => '1'], [], [], []);
        $resp = $this->createMock(Response::class);
        $resp->method('html')->willReturnCallback(function ($out) { $this->html = (string) $out; });
        $this->container['response'] = $resp;
        (new WindowsillController($this->container))->widget();
        return $this->html;
    }

    private function metadataKeys(int $userId): array
    {
        return array_values(array_filter(
            array_keys($this->container['userMetadataModel']->getAll($userId)),
            fn ($k) => str_starts_with($k, 'windowsill_') && !in_array($k, ['windowsill_lat', 'windowsill_lon', 'windowsill_label', 'windowsill_units'], true)
        ));
    }

    public function testFresh(): void
    {
        $html = $this->render(self::vm('fresh'));

        $this->assertStringContainsString('<section class="windowsill" data-windowsill-state="fresh"', $html);
        foreach (['Berlin, Land Berlin, DE', '36°F', 'Partly cloudy', 'Feels like 28°', '9 mph', 'Today', 'Tue', 'Wed', '30%', '13%', '39°', '33°', self::ATTRIBUTION, 'href="'.$this->settingsHref().'"', 'aria-hidden="true"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('data-windowsill-refresh', $html);
        $this->assertStringNotContainsString('as of', $html);
        $this->assertStringNotContainsString('Mon', $html, 'the matching day is Today, not its weekday');
    }

    public function testMetricUnits(): void
    {
        $html = $this->render(self::vm('fresh', true, ['units' => 'metric']));

        $this->assertStringContainsString('36°C', $html);
        $this->assertStringContainsString('9 km/h', $html);
        $this->assertStringNotContainsString('°F', $html);
        $this->assertStringNotContainsString('mph', $html);
    }

    public function testNullPopIsOmitted(): void
    {
        $vm = self::vm('fresh');
        $vm['forecast']['days'][0]['pop'] = null;
        $html = $this->render($vm);

        $this->assertStringNotContainsString('30%', $html);
        $this->assertStringContainsString('13%', $html);
    }

    public function testStaleShowsAsOf(): void
    {
        $vm = self::vm('stale', true, ['aged' => true]);
        $html = $this->render($vm);

        $this->assertStringContainsString('data-windowsill-state="stale"', $html);
        $this->assertStringContainsString('as of '.$this->container['helper']->dt->time($vm['fetched_at']), $html);
        $this->assertStringContainsString('36°F', $html);
        $this->assertStringNotContainsString('data-windowsill-refresh', $html);
    }

    public function testPendingWithForecastShowsAsOfAndRefreshUrl(): void
    {
        $url = '/?controller=WindowsillController&action=widget&plugin=Windowsill&csrf_token=abc';
        $html = $this->render(self::vm('pending', true, ['aged' => true]), $url);

        $this->assertStringContainsString('data-windowsill-state="pending"', $html);
        $this->assertStringContainsString('data-windowsill-refresh="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'"', $html);
        $this->assertStringContainsString('as of', $html);
        $this->assertStringContainsString('36°F', $html);
        $this->assertStringNotContainsString('Loading weather', $html);
    }

    public function testPendingWithoutForecastShowsLoading(): void
    {
        $url = '/?a=1&b="2"';
        $html = $this->render(self::vm('pending', false), $url);

        $this->assertStringContainsString('data-windowsill-refresh="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'"', $html);
        $this->assertStringContainsString('Loading weather…', $html);
        $this->assertStringContainsString('<noscript>Weather needs JavaScript to load.</noscript>', $html);
        $this->assertStringNotContainsString('as of', $html);
    }

    public function testUnset(): void
    {
        $html = $this->render(self::vm('unset', false));

        $this->assertStringContainsString('data-windowsill-state="unset"', $html);
        $this->assertStringContainsString('Set your location to see the weather.', $html);
        $this->assertStringContainsString('href="'.$this->settingsHref().'"', $html);
    }

    public function testUnavailable(): void
    {
        $html = $this->render(self::vm('unavailable', false));

        $this->assertStringContainsString('data-windowsill-state="unavailable"', $html);
        $this->assertStringContainsString('Weather is unavailable right now.', $html);
        $this->assertStringContainsString('href="'.$this->settingsHref().'"', $html);
    }

    public function testNullRendersNothing(): void
    {
        $this->assertSame('', trim($this->render(null)));
    }

    public function testLabelIsEscaped(): void
    {
        $html = $this->render(self::vm('fresh', true, ['label' => '<b>x</b>']));

        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function testMidnightDropsPastDays(): void
    {
        $html = $this->render(self::vm('stale', true, ['now' => self::now(1), 'aged' => true]));

        $this->assertStringContainsString('data-windowsill-date="2026-11-24"', $html);
        $this->assertStringNotContainsString('2026-11-23', $html);
        $this->assertMatchesRegularExpression('/data-windowsill-date="2026-11-24"[^>]*>\s*<span class="windowsill-day-name">Today</', $html);
        $this->assertStringContainsString('Wed', $html);
        $this->assertStringNotContainsString('Tue', $html);

        $html = $this->render(self::vm('stale', true, ['now' => self::now(4), 'aged' => true]));
        $this->assertStringNotContainsString('windowsill-days', $html);
        $this->assertStringNotContainsString('Today', $html);
        $this->assertStringContainsString('36°F', $html);
    }

    public function testWidgetActionRequiresReusableToken(): void
    {
        $this->container['windowsillLocation']->save($this->alice, self::SPRINGFIELD);
        $client = $this->createMock(OpenMeteoClient::class);
        $client->expects($this->never())->method('forecast');
        $this->container['windowsillClient'] = $client;

        foreach ([[], ['csrf_token' => 'bogus']] as $query) {
            try {
                $this->call($query);
                $this->fail('Expected AccessForbiddenException');
            } catch (AccessForbiddenException $e) {
                $this->assertSame('', $this->html);
            }
        }
        $this->assertSame([], $this->metadataKeys($this->alice));
    }

    public function testWidgetActionRefreshes(): void
    {
        $this->container['windowsillLocation']->save($this->alice, self::SPRINGFIELD);
        $client = $this->createMock(OpenMeteoClient::class);
        $client->expects($this->once())->method('forecast')->willReturn(self::forecast());
        $this->container['windowsillClient'] = $client;

        $html = $this->call(['csrf_token' => $this->container['token']->getReusableCSRFToken()]);

        $this->assertStringStartsWith('<section class="windowsill" data-windowsill-state="fresh"', trim($html));
        $this->assertStringContainsString('36°F', $html);
        $this->assertStringNotContainsString('data-windowsill-refresh', $html);
    }

    public function testHookShowsOnlyOwnDashboard(): void
    {
        $location = $this->container['windowsillLocation']->save($this->alice, self::SPRINGFIELD);
        $this->assertTrue($this->container['windowsillCache']->write($this->alice, self::forecast(), time(), ForecastCache::keyFor($location)));
        $alice = $this->container['userModel']->getById($this->alice);

        $html = $this->container['helper']->hook->render('template:dashboard:show:before-filter-box', ['user' => $alice]);
        $this->assertStringContainsString('class="windowsill" data-windowsill-state="fresh"', $html);
        $this->assertStringContainsString('Springfield', $html);

        $this->loginAs(1); // admin
        $html = $this->container['helper']->hook->render('template:dashboard:show:before-filter-box', ['user' => $alice]);
        $this->assertStringNotContainsString('windowsill', $html);
        $this->assertStringNotContainsString('Springfield', $html);
    }

    public function testHookCarriesReusableRefreshUrlWhenPending(): void
    {
        $this->container['windowsillLocation']->save($this->alice, self::SPRINGFIELD);
        $alice = $this->container['userModel']->getById($this->alice);

        $html = $this->container['helper']->hook->render('template:dashboard:show:before-filter-box', ['user' => $alice]);
        $prefix = htmlspecialchars($this->container['helper']->url->href('WindowsillController', 'widget', ['plugin' => 'Windowsill']), ENT_QUOTES, 'UTF-8', false);
        $this->assertSame(1, preg_match('/data-windowsill-state="pending" data-windowsill-refresh="'.preg_quote($prefix, '/').'&amp;csrf_token=([0-9a-f]+)"/', $html, $m));
        $this->assertTrue($this->container['token']->validateReusableCSRFToken($m[1]));
    }

    public function testAssetsAreHooked(): void
    {
        $this->assertContains(['template' => 'plugins/Windowsill/Assets/css/windowsill.css'], $this->container['hook']->getListeners('template:layout:css'));
        $this->assertContains(['template' => 'plugins/Windowsill/Assets/js/windowsill.js'], $this->container['hook']->getListeners('template:layout:js'));
    }
}
