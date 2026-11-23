<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Windowsill\Plugin;

// Tag == plugin.json == getPluginVersion(): drift across the three is the most common release defect.
class PluginTest extends Base
{
    private function json(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../plugin.json'), true);
    }

    public function testVersionsAgree(): void
    {
        $json = $this->json();
        $plugin = new Plugin($this->container);
        $this->assertSame('0.1.0', $json['version']);
        $this->assertSame($json['version'], $plugin->getPluginVersion());
        $this->assertSame('Windowsill', $json['name']);
        $this->assertSame($json['name'], $plugin->getPluginName());
        $this->assertSame('>=1.2.47', $plugin->getCompatibleVersion());
    }

    public function testDependencyShapeIsArrays(): void
    {
        $json = $this->json();
        $this->assertArrayHasKey('requires', $json);
        $this->assertArrayHasKey('recommends', $json);
        $this->assertSame([], $json['requires']);
        $this->assertSame([], $json['recommends']);
    }

    public function testNoOptionalPluginIsReferenced(): void
    {
        $root = realpath(__DIR__.'/..');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (str_starts_with($path, $root.'/Test/') || str_starts_with($path, $root.'/.git/')) {
                continue;
            }
            if (!in_array($file->getExtension(), ['php', 'js'], true)) {
                continue;
            }
            $checked++;
            $source = file_get_contents($path);
            foreach (['AiConnector', 'ShadcnTheme', 'BattleLobby', 'Steward', 'ModMenu'] as $name) {
                $this->assertStringNotContainsString($name, $source, "$path references $name");
            }
        }
        $this->assertGreaterThan(0, $checked);
    }

    public function testInitializeRunsWithNoOtherPlugins(): void
    {
        (new Plugin($this->container))->initialize();
        $this->addToAssertionCount(1);
    }

    public function testReadmeStatesOpenMeteoTerms(): void
    {
        $readme = file_get_contents(__DIR__.'/../README.md');
        $this->assertStringContainsString('non-commercial', $readme);
        $this->assertStringContainsString('Open-Meteo', $readme);
    }
}
