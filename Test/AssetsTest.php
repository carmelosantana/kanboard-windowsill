<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

class AssetsTest extends Base
{
    private const CSS = __DIR__.'/../Assets/css/windowsill.css';
    private const JS = __DIR__.'/../Assets/js/windowsill.js';

    private static function files(string $dir, array $extensions): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../'.$dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (in_array($file->getExtension(), $extensions, true)) {
                $out[] = $file->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    public function testAssetFilesAreFilled(): void
    {
        $this->assertNotSame('', trim((string) @file_get_contents(self::CSS)));
        $this->assertStringContainsString('window.KBWindowsill', (string) @file_get_contents(self::JS));
    }

    public function testTemplatesHaveNoScriptOrInlineHandlers(): void
    {
        $templates = self::files('Template', ['php']);
        $this->assertNotEmpty($templates);
        foreach ($templates as $path) {
            $src = file_get_contents($path);
            $this->assertStringNotContainsStringIgnoringCase('<script', $src, $path);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $src, $path);
        }
    }

    public function testOnlyExternalUrlIsTheAttributionLink(): void
    {
        $files = array_merge(self::files('Template', ['php']), self::files('Assets', ['css', 'js']));
        $this->assertGreaterThanOrEqual(3, count($files));
        foreach ($files as $path) {
            $src = file_get_contents($path);
            $all = preg_match_all('#https?://#i', $src);
            $allowed = preg_match_all('#href="https://open-meteo\.com/"#', $src);
            $this->assertSame($allowed, $all, $path);
        }
    }

    public function testCssIsSelfContainedAndResponsive(): void
    {
        $css = file_get_contents(self::CSS);
        $this->assertStringNotContainsString('@import', $css);
        $this->assertDoesNotMatchRegularExpression('/url\(\s*[\'"]?\s*(https?:)?\/\//i', $css);
        $this->assertMatchesRegularExpression('/var\(--color-[a-z-]+,\s*[^)]+\)/', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('flex-wrap: wrap', $css);
    }

    public function testJsStaysSameOriginAndNeverParsesIntoTheLivePage(): void
    {
        $js = file_get_contents(self::JS);
        $this->assertStringContainsString("'same-origin'", $js);
        $this->assertStringContainsString('location.origin', $js);
        $this->assertStringContainsString("createElement('template')", $js);
        // The only innerHTML assignment is into the detached <template> used to parse the fragment.
        $this->assertSame(1, preg_match_all('/innerHTML\s*=/', $js));
        $this->assertMatchesRegularExpression('/tpl\.innerHTML\s*=/', $js);
        $this->assertDoesNotMatchRegularExpression('/\b(eval|document\.write|insertAdjacentHTML|outerHTML)\b/', $js);
    }
}
