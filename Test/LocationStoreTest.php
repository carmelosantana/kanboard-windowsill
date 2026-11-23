<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Windowsill\Model\LocationStore;
use Kanboard\Plugin\Windowsill\Plugin;

class LocationStoreTest extends Base
{
    private function user(string $name): int
    {
        return (int) $this->container['userModel']->create(['username' => $name]);
    }

    private function store(): LocationStore
    {
        return new LocationStore($this->container);
    }

    private static function springfield(array $override = []): array
    {
        return array_merge([
            'name' => 'Springfield', 'admin1' => 'Missouri', 'country_code' => 'US',
            'lat' => 37.21533, 'lon' => -93.29824,
        ], $override);
    }

    private function windowsillKeys(int $id): array
    {
        return array_values(array_filter(
            array_keys($this->container['userMetadataModel']->getAll($id)),
            fn ($k) => str_starts_with($k, 'windowsill_')
        ));
    }

    public function testContainerRegistersStore(): void
    {
        (new Plugin($this->container))->initialize();
        $this->assertInstanceOf(LocationStore::class, $this->container['windowsillLocation']);
    }

    public function testSaveRoundsAndBuildsLabel(): void
    {
        $id = $this->user('alice');
        $expected = ['lat' => 37.22, 'lon' => -93.3, 'label' => 'Springfield, Missouri, US', 'units' => 'us'];
        $this->assertSame($expected, $this->store()->save($id, self::springfield()));
        $this->assertSame($expected, $this->store()->get($id));
    }

    public function testUnitsDefaultMetricOutsideUs(): void
    {
        $id = $this->user('alice');
        $this->assertSame('metric', $this->store()->save($id, self::springfield(['country_code' => 'DE']))['units']);
        $this->assertSame('us', $this->store()->save($id, self::springfield(['country_code' => 'DE']), 'us')['units']);
        $this->assertSame('metric', $this->store()->save($id, self::springfield(['country_code' => '']))['units']);
    }

    public function testLabelSkipsEmptyPartsAndTruncatesMultibyte(): void
    {
        $id = $this->user('alice');
        $zurich = ['name' => 'Zürich', 'admin1' => '', 'country_code' => 'CH', 'lat' => 47.37, 'lon' => 8.55];
        $this->assertSame('Zürich, CH', $this->store()->save($id, $zurich)['label']);

        $long = self::springfield(['name' => str_repeat('Ж', 100), 'admin1' => str_repeat('Ж', 100)]);
        $label = $this->store()->save($id, $long)['label'];
        $this->assertSame(120, mb_strlen($label));
        $this->assertTrue(mb_check_encoding($label, 'UTF-8'));
        $this->assertSame($label, $this->store()->get($id)['label']);
    }

    public static function invalidProvider(): array
    {
        return [
            'lat too high' => [self::springfield(['lat' => 90.01]), null],
            'lat too low' => [self::springfield(['lat' => -90.01]), null],
            'lon too high' => [self::springfield(['lon' => 180.01]), null],
            'lat non-numeric' => [self::springfield(['lat' => 'abc']), null],
            'empty name' => [self::springfield(['name' => '']), null],
            'bad units' => [self::springfield(), 'kelvin'],
        ];
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testRejectsOutOfRange(array $place, ?string $units): void
    {
        $id = $this->user('alice');
        try {
            $this->store()->save($id, $place, $units);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame([], $this->windowsillKeys($id));
        }
    }

    public function testUsersAreIsolated(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $this->store()->save($alice, self::springfield());
        $this->assertNull($this->store()->get($bob));
    }

    public function testClearRemovesEveryKey(): void
    {
        $id = $this->user('alice');
        $this->store()->save($id, self::springfield());
        $this->container['userMetadataModel']->save($id, ['windowsill_cache' => 'x', 'windowsill_failed_at' => '123']);
        $this->store()->clear($id);
        $this->assertSame([], $this->windowsillKeys($id));
        $this->assertNull($this->store()->get($id));
    }

    public function testKeysConstantListsAllSix(): void
    {
        $this->assertSame(
            ['windowsill_lat', 'windowsill_lon', 'windowsill_label', 'windowsill_units', 'windowsill_cache', 'windowsill_failed_at'],
            LocationStore::KEYS
        );
    }

    public function testGetIgnoresPartialRows(): void
    {
        $id = $this->user('alice');
        $this->container['userMetadataModel']->save($id, ['windowsill_lat' => '37.22']);
        $this->assertNull($this->store()->get($id));
    }

    public function testGetIgnoresCorruptRows(): void
    {
        $id = $this->user('alice');
        $this->store()->save($id, self::springfield());
        $this->container['userMetadataModel']->save($id, ['windowsill_lon' => 'garbage']);
        $this->assertNull($this->store()->get($id));
        $this->container['userMetadataModel']->save($id, ['windowsill_lon' => '-93.30', 'windowsill_units' => 'kelvin']);
        $this->assertNull($this->store()->get($id));
    }

    public function testFormStringsAndArrays(): void
    {
        $id = $this->user('alice');
        $saved = $this->store()->save($id, self::springfield(['lat' => '37.21533', 'lon' => '-93.29824']));
        $this->assertSame(37.22, $saved['lat']);
        $this->assertSame(-93.3, $saved['lon']);

        foreach ([['lat' => ['1']], ['name' => ['x']]] as $bad) {
            try {
                $this->store()->save($id, self::springfield($bad));
                $this->fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSaveAndUnitsClearBackoff(): void
    {
        $id = $this->user('alice');
        $meta = $this->container['userMetadataModel'];

        $meta->save($id, ['windowsill_failed_at' => '123']);
        $this->store()->save($id, self::springfield());
        $this->assertFalse($meta->exists($id, 'windowsill_failed_at'));

        $meta->save($id, ['windowsill_failed_at' => '123']);
        $this->store()->setUnits($id, 'metric');
        $this->assertFalse($meta->exists($id, 'windowsill_failed_at'));
        $this->assertSame('metric', $this->store()->get($id)['units']);

        $bob = $this->user('bob');
        $this->expectException(\InvalidArgumentException::class);
        $this->store()->setUnits($bob, 'us');
    }

    public function testSetUnitsRejectsBadUnit(): void
    {
        $id = $this->user('alice');
        $this->store()->save($id, self::springfield());
        $this->expectException(\InvalidArgumentException::class);
        $this->store()->setUnits($id, 'kelvin');
    }

    public function testCommaDecimalLocale(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        if (setlocale(LC_NUMERIC, 'en_DK.utf8') === false) {
            $this->markTestSkipped('en_DK.utf8 locale not installed');
        }
        try {
            $id = $this->user('alice');
            $this->store()->save($id, self::springfield());
            $location = $this->store()->get($id);
            $this->assertSame(37.22, $location['lat']);
            $this->assertSame(-93.3, $location['lon']);
            $this->assertSame('37.22', $this->container['userMetadataModel']->get($id, 'windowsill_lat'));
        } finally {
            setlocale(LC_NUMERIC, $previous);
        }
    }
}
