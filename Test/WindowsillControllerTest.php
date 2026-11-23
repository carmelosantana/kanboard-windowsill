<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Windowsill\Controller\WindowsillController;
use Kanboard\Plugin\Windowsill\Model\OpenMeteoClient;
use Kanboard\Plugin\Windowsill\Model\ProviderException;
use Kanboard\Plugin\Windowsill\Plugin;
use PHPUnit\Framework\Attributes\DataProvider;

class WindowsillControllerTest extends Base
{
    private int $alice;
    private int $bob;
    private string $html = '';
    private ?string $redirect = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container['logger'] = $this->createMock(\Psr\Log\LoggerInterface::class);
        (new Plugin($this->container))->initialize();

        $users = new UserModel($this->container);
        $this->alice = $users->create(['username' => 'alice', 'name' => 'Alice Example']);
        $this->bob = $users->create(['username' => 'bob', 'name' => 'Bob Example']);
        $this->loginAs($this->alice);
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    private function places(array $places): void
    {
        $client = $this->createMock(OpenMeteoClient::class);
        $client->method('searchPlaces')->willReturn($places);
        $this->container['windowsillClient'] = $client;
    }

    private function clientNeverCalled(): void
    {
        $client = $this->createMock(OpenMeteoClient::class);
        $client->expects($this->never())->method('searchPlaces');
        $this->container['windowsillClient'] = $client;
    }

    private static function fixturePlaces(): array
    {
        $json = json_decode(file_get_contents(__DIR__.'/fixtures/geocode.json'), true);
        return array_map(fn ($r) => [
            'name' => $r['name'], 'admin1' => $r['admin1'], 'country_code' => $r['country_code'],
            'lat' => $r['latitude'], 'lon' => $r['longitude'],
        ], $json['results']);
    }

    private function call(string $action, ?array $post = null, bool $csrf = true): string
    {
        $this->html = '';
        $this->redirect = null;
        if ($post === null) {
            $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], ['_' => '1'], [], [], []);
        } else {
            if ($csrf) {
                $post['csrf_token'] = $this->container['token']->getCSRFToken();
            }
            $post = $post ?: ['_' => '1'];
            $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'POST'], ['_' => '1'], $post, [], []);
        }
        $resp = $this->createMock(Response::class);
        $resp->method('html')->willReturnCallback(function ($out) { $this->html = (string) $out; });
        $resp->method('redirect')->willReturnCallback(function ($url) { $this->redirect = (string) $url; });
        $this->container['response'] = $resp;
        (new WindowsillController($this->container))->$action();
        return $this->html;
    }

    private function href(string $action): string
    {
        return $this->container['helper']->url->href('WindowsillController', $action, ['plugin' => 'Windowsill']);
    }

    private function assertRedirectedToSettings(): void
    {
        $this->assertSame(
            $this->container['helper']->url->to('WindowsillController', 'settings', ['plugin' => 'Windowsill']),
            $this->redirect
        );
    }

    private function store()
    {
        return $this->container['windowsillLocation'];
    }

    private function seedLocation(int $userId): void
    {
        $this->store()->save($userId, ['name' => 'Springfield', 'admin1' => 'Missouri', 'country_code' => 'US', 'lat' => 37.2, 'lon' => -93.3]);
    }

    public function testSettingsRendersSearchFormWithCsrf(): void
    {
        $html = $this->call('settings');

        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('name="q"', $html);
        $this->assertStringContainsString('action="'.$this->href('search').'"', $html);
        $this->assertStringNotContainsString('action="'.$this->href('remove').'"', $html);
        $this->assertStringNotContainsString('action="'.$this->href('units').'"', $html);
    }

    public function testSettingsShowsLocationUnitsAndRemove(): void
    {
        $this->store()->save($this->alice, ['name' => '<b>Town</b>', 'admin1' => '', 'country_code' => 'DE', 'lat' => 52.5, 'lon' => 13.4]);

        $html = $this->call('settings');

        $this->assertStringContainsString('&lt;b&gt;Town&lt;/b&gt;, DE', $html);
        $this->assertStringNotContainsString('<b>Town', $html);
        $this->assertStringContainsString('action="'.$this->href('units').'"', $html);
        $this->assertStringContainsString('action="'.$this->href('remove').'"', $html);
        $this->assertMatchesRegularExpression('/<option value="metric" selected="selected">/', $html);
        $this->assertSame(3, substr_count($html, 'name="csrf_token"'));
    }

    public function testSearchListsPlacesAsPickForms(): void
    {
        $this->places(self::fixturePlaces());

        $html = $this->call('search', ['q' => 'Springfield']);

        $this->assertSame(5, substr_count($html, 'action="'.$this->href('save').'"'));
        $this->assertStringContainsString('name="lat" value="37.21533"', $html);
        $this->assertStringContainsString('Missouri', $html);
        $this->assertStringContainsString('<option value="us" selected="selected">', $html, 'US places default to US units');
        $this->assertStringNotContainsString('No places found.', $html);
    }

    public function testSearchDefaultsNonUsPlacesToMetric(): void
    {
        $this->places([['name' => 'Berlin', 'admin1' => 'Land Berlin', 'country_code' => 'DE', 'lat' => 52.52, 'lon' => 13.41]]);

        $html = $this->call('search', ['q' => 'Berlin']);

        $this->assertStringContainsString('<option value="metric" selected="selected">', $html);
    }

    public function testSearchEscapesProviderText(): void
    {
        $this->places([['name' => '<img src=x onerror=1>', 'admin1' => '"><script>', 'country_code' => 'US', 'lat' => 1.0, 'lon' => 2.0]]);

        $html = $this->call('search', ['q' => 'img']);

        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('"><script>', $html);
    }

    public function testSearchResultTextHasNoSpaceBeforeCommas(): void
    {
        $this->places([
            ['name' => 'Berlin', 'admin1' => 'State of Berlin', 'country_code' => 'DE', 'lat' => 52.52, 'lon' => 13.41],
            ['name' => 'Zürich', 'admin1' => '', 'country_code' => 'CH', 'lat' => 47.37, 'lon' => 8.55],
        ]);

        $html = $this->call('search', ['q' => 'test']);

        $this->assertStringContainsString('<strong>Berlin</strong>, State of Berlin, DE', $html);
        $this->assertStringContainsString('<strong>Zürich</strong>, CH', $html);
    }

    public function testSearchWithNoResultsSaysSo(): void
    {
        $this->places([]);

        $html = $this->call('search', ['q' => 'Nowhere']);

        $this->assertStringContainsString('No places found.', $html);
    }

    public function testSearchQueryOutOfRangeFlashes(): void
    {
        $client = $this->createMock(OpenMeteoClient::class);
        $client->method('searchPlaces')->willThrowException(new \InvalidArgumentException('x'));
        $this->container['windowsillClient'] = $client;

        $this->call('search', ['q' => 'a']);

        $this->assertRedirectedToSettings();
        $this->assertSame('Enter 2 to 100 characters.', $this->container['flash']->getMessage('failure'));
    }

    public function testSearchProviderFailureFlashes(): void
    {
        $client = $this->createMock(OpenMeteoClient::class);
        $client->method('searchPlaces')->willThrowException(new ProviderException('x'));
        $this->container['windowsillClient'] = $client;

        $this->call('search', ['q' => 'Berlin']);

        $this->assertRedirectedToSettings();
        $this->assertSame('Place search is unavailable right now.', $this->container['flash']->getMessage('failure'));
    }

    public static function postActions(): array
    {
        return [
            'search' => ['search', ['q' => 'Berlin']],
            'save' => ['save', ['name' => 'Berlin', 'admin1' => '', 'country_code' => 'DE', 'lat' => '52.52', 'lon' => '13.41']],
            'units' => ['units', ['units' => 'metric']],
            'remove' => ['remove', []],
        ];
    }

    /**
     * @dataProvider postActions
     */
    #[DataProvider('postActions')]
    public function testPostsWithoutCsrfAreRejected(string $action, array $post): void
    {
        $this->seedLocation($this->alice);
        $this->clientNeverCalled();
        $before = $this->store()->get($this->alice);

        try {
            $this->call($action, $post, false);
            $this->fail('expected AccessForbiddenException');
        } catch (AccessForbiddenException $e) {
            $this->assertSame($before, $this->store()->get($this->alice));
        }
    }

    public function testSaveStoresForCurrentUserOnly(): void
    {
        $this->call('save', [
            'name' => 'Springfield', 'admin1' => 'Missouri', 'country_code' => 'US',
            'lat' => '37.21533', 'lon' => '-93.29824', 'units' => '', 'user_id' => (string) $this->bob,
        ]);

        $this->assertRedirectedToSettings();
        $this->assertSame('Location saved.', $this->container['flash']->getMessage('success'));
        $this->assertSame(
            ['lat' => 37.22, 'lon' => -93.3, 'label' => 'Springfield, Missouri, US', 'units' => 'us'],
            $this->store()->get($this->alice)
        );
        $this->assertNull($this->store()->get($this->bob));
    }

    public function testSaveHonoursChosenUnits(): void
    {
        $this->call('save', ['name' => 'Springfield', 'admin1' => '', 'country_code' => 'US', 'lat' => '37.2', 'lon' => '-93.3', 'units' => 'metric']);

        $this->assertSame('metric', $this->store()->get($this->alice)['units']);
    }

    public function testSaveInvalidFlashesAndStoresNothing(): void
    {
        $this->call('save', ['name' => 'Nowhere', 'admin1' => '', 'country_code' => 'US', 'lat' => '200', 'lon' => '0']);

        $this->assertRedirectedToSettings();
        $this->assertSame('That location is not valid.', $this->container['flash']->getMessage('failure'));
        $this->assertNull($this->store()->get($this->alice));
    }

    public function testUnitsAndRemove(): void
    {
        $this->seedLocation($this->alice);

        $this->call('units', ['units' => 'metric']);
        $this->assertRedirectedToSettings();
        $this->assertSame('metric', $this->store()->get($this->alice)['units']);

        $this->call('units', ['units' => 'kelvin']);
        $this->assertSame('That unit is not valid.', $this->container['flash']->getMessage('failure'));
        $this->assertSame('metric', $this->store()->get($this->alice)['units']);

        $this->call('remove', []);
        $this->assertRedirectedToSettings();
        $this->assertSame('Location removed.', $this->container['flash']->getMessage('success'));
        $this->assertNull($this->store()->get($this->alice));
    }

    /**
     * A userMetadataModel whose save() reports failure; getAll() answers $rows.
     */
    private function failingMetadata(array $rows = []): void
    {
        $meta = $this->createMock(\Kanboard\Model\UserMetadataModel::class);
        $meta->method('getAll')->willReturn($rows);
        $meta->method('save')->willReturn(false);
        $this->container['userMetadataModel'] = $meta;
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->never())->method($this->anything());
        $this->container['logger'] = $logger;
    }

    public function testSaveStorageFailureFlashes(): void
    {
        $this->failingMetadata();

        $this->call('save', ['name' => 'Berlin', 'admin1' => '', 'country_code' => 'DE', 'lat' => '52.52', 'lon' => '13.41']);

        $this->assertRedirectedToSettings();
        $this->assertSame('Your location could not be saved.', $this->container['flash']->getMessage('failure'));
        $this->assertSame('', (string) $this->container['flash']->getMessage('success'));
    }

    public function testUnitsStorageFailureFlashes(): void
    {
        $this->failingMetadata([
            'windowsill_lat' => '52.52', 'windowsill_lon' => '13.41',
            'windowsill_label' => 'Berlin, DE', 'windowsill_units' => 'metric',
        ]);

        $this->call('units', ['units' => 'us']);

        $this->assertRedirectedToSettings();
        $this->assertSame('Your location could not be saved.', $this->container['flash']->getMessage('failure'));
    }

    public function testUnitsWithoutLocationAsksForOne(): void
    {
        $this->call('units', ['units' => 'metric']);

        $this->assertRedirectedToSettings();
        $this->assertSame('Choose a location first.', $this->container['flash']->getMessage('failure'));
    }

    public static function malformedPosts(): array
    {
        $place = ['name' => 'Berlin', 'admin1' => '', 'country_code' => 'DE', 'lat' => '52.52', 'lon' => '13.41'];
        return [
            'q array' => ['search', ['q' => ['x']], 'Enter 2 to 100 characters.'],
            'q missing' => ['search', [], 'Enter 2 to 100 characters.'],
            'lat array' => ['save', ['lat' => ['1']] + $place, 'That location is not valid.'],
            'name missing' => ['save', array_diff_key($place, ['name' => 1]), 'That location is not valid.'],
            'name array' => ['save', ['name' => ['Berlin']] + $place, 'That location is not valid.'],
            'save units array' => ['save', $place + ['units' => ['us']], 'That location is not valid.'],
            'units array' => ['units', ['units' => ['us']], 'Choose a location first.'],
            'units missing' => ['units', [], 'Choose a location first.'],
        ];
    }

    /**
     * @dataProvider malformedPosts
     */
    #[DataProvider('malformedPosts')]
    public function testMalformedPostsDoNotCrash(string $action, array $post, string $flash): void
    {
        $this->clientNeverCalled();

        $this->call($action, $post);

        $this->assertRedirectedToSettings();
        $this->assertSame($flash, $this->container['flash']->getMessage('failure'));
        $this->assertNull($this->store()->get($this->alice));
    }

    public function testSidebarLinkOnlyOnOwnProfile(): void
    {
        $template = $this->container['template'];

        $own = $template->render('Windowsill:user/sidebar', ['user' => ['id' => $this->alice]]);
        $other = $template->render('Windowsill:user/sidebar', ['user' => ['id' => $this->bob]]);

        $this->assertStringContainsString('Weather', $own);
        $this->assertStringContainsString('href="'.$this->href('settings').'"', $own);
        $this->assertSame('', trim($other));
    }

    public function testSidebarHookRegistered(): void
    {
        $this->assertContains('Windowsill:user/sidebar', array_column(
            $this->container['hook']->getListeners('template:user:sidebar:actions'),
            'template'
        ));
    }

    public function testRoutesRegistered(): void
    {
        $this->container['route']->enable();
        (new Plugin($this->container))->initialize();

        foreach (['settings', 'search', 'save', 'units', 'remove', 'widget'] as $action) {
            $this->assertSame(
                'windowsill/'.$action,
                $this->container['route']->findUrl('WindowsillController', $action, ['plugin' => 'Windowsill'])
            );
        }
    }
}
