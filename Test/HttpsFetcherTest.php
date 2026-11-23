<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Windowsill\Model\HttpsFetcher;
use Kanboard\Plugin\Windowsill\Model\ProviderException;
use Kanboard\Plugin\Windowsill\Plugin;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;

/**
 * Stops at the first step after the host check, so a test can tell whether transport was reached
 * without ever touching the network.
 */
class WindowsillCountingFetcher extends HttpsFetcher
{
    public int $reached = 0;

    protected function protocols(): string
    {
        $this->reached++;
        throw new \LogicException('transport reached');
    }
}

/**
 * Allows plain http to the local test server only.
 */
class WindowsillLocalFetcher extends HttpsFetcher
{
    public static int $port = 0;
    public int $seconds = self::TIMEOUT;

    protected function isAllowed(array $parts): bool
    {
        return ($parts['scheme'] ?? '') === 'http' && ($parts['host'] ?? '') === '127.0.0.1'
            && ($parts['port'] ?? 0) === self::$port && !isset($parts['user']) && !isset($parts['pass']);
    }

    protected function protocols(): string
    {
        return 'http,https';
    }

    protected function timeout(): int
    {
        return $this->seconds;
    }
}

class HttpsFetcherTest extends Base
{
    /** @var resource|null */
    private static $server = null;
    private static string $hits = '';
    private static string $unavailable = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!function_exists('curl_init')) {
            self::$unavailable = 'curl extension not loaded';
            return;
        }
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::$unavailable = 'cannot bind 127.0.0.1';
            return;
        }
        $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        self::$hits = tempnam(sys_get_temp_dir(), 'windowsill-hits-');
        $env = array_merge(getenv(), ['WINDOWSILL_HITS' => self::$hits]);
        $null = ['file', '/dev/null', 'w'];
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/fixtures/fetcher-router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null],
            $pipes,
            __DIR__.'/fixtures',
            $env
        );
        if (!is_resource($process)) {
            self::$unavailable = 'cannot start php -S';
            return;
        }
        self::$server = $process;

        for ($deadline = microtime(true) + 3; microtime(true) < $deadline; usleep(50000)) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                WindowsillLocalFetcher::$port = $port;
                return;
            }
            if (!proc_get_status($process)['running']) {
                break;
            }
        }
        self::$unavailable = 'php -S did not accept connections on 127.0.0.1:'.$port;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        if (self::$hits !== '' && file_exists(self::$hits)) {
            unlink(self::$hits);
        }
        parent::tearDownAfterClass();
    }

    private function local(int $seconds = HttpsFetcher::TIMEOUT): WindowsillLocalFetcher
    {
        if (self::$unavailable !== '') {
            $this->markTestSkipped(self::$unavailable);
        }
        $fetcher = new WindowsillLocalFetcher($this->container);
        $fetcher->seconds = $seconds;
        return $fetcher;
    }

    private static function url(string $path): string
    {
        return 'http://127.0.0.1:'.WindowsillLocalFetcher::$port.$path;
    }

    private function assertFails(HttpsFetcher $fetcher, string $path): void
    {
        try {
            $fetcher->get(self::url($path));
            $this->fail("expected ProviderException for $path");
        } catch (ProviderException $e) {
            $this->assertStringNotContainsString('127.0.0.1', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function testConstants(): void
    {
        $this->assertSame(['api.open-meteo.com', 'geocoding-api.open-meteo.com'], HttpsFetcher::ALLOWED_HOSTS);
        $this->assertSame(5, HttpsFetcher::TIMEOUT);
        $this->assertSame(3, HttpsFetcher::CONNECT_TIMEOUT);
        $this->assertSame(65536, HttpsFetcher::MAX_BYTES);
    }

    public static function rejectedUrls(): array
    {
        return [
            'plain http' => ['http://api.open-meteo.com/v1/forecast'],
            'other host' => ['https://evil.example/v1/forecast'],
            'suffix host' => ['https://api.open-meteo.com.evil.example/'],
            'credentials' => ['https://user:pass@api.open-meteo.com/'],
            'other port' => ['https://api.open-meteo.com:8443/'],
            'ftp' => ['ftp://api.open-meteo.com/'],
            'empty' => [''],
            'loopback' => ['https://127.0.0.1/'],
            'trailing dot host' => ['https://api.open-meteo.com./'],
            'userinfo before evil host' => ['https://api.open-meteo.com@evil.example/'],
            'backslash userinfo' => ['https://evil.example\\@api.open-meteo.com/'],
            'IDN look-alike (Cyrillic a)' => ["https://\u{0430}pi.open-meteo.com/"],
            'IDN look-alike (punycode)' => ['https://xn--pi-7kc.open-meteo.com/'],
            'NUL byte' => ["https://api.open-meteo.com/v1/forecast\0x"],
            'newline' => ["https://api.open-meteo.com/v1/forecast\nHost: evil.example"],
            'carriage return' => ["https://api.open-meteo.com/\r"],
            'tab' => ["https://api.open-meteo.com/\tx"],
            'space' => ['https://api.open-meteo.com/ x'],
            'DEL' => ["https://api.open-meteo.com/\x7f"],
        ];
    }

    /**
     * @dataProvider rejectedUrls
     */
    #[DataProvider('rejectedUrls')]
    public function testRejectedBeforeAnyIo(string $url): void
    {
        $fetcher = new WindowsillCountingFetcher($this->container);
        try {
            $fetcher->get($url);
            $this->fail('expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame(0, $fetcher->reached);
            if ($url !== '') {
                $this->assertStringNotContainsString($url, $e->getMessage());
            }
        }
    }

    public static function allowedUrls(): array
    {
        return [
            'forecast' => ['https://api.open-meteo.com/v1/forecast?latitude=52.52'],
            'geocoding' => ['https://geocoding-api.open-meteo.com/v1/search?name=x'],
            'upper case host' => ['https://API.OPEN-METEO.COM/'],
            'explicit 443' => ['https://api.open-meteo.com:443/v1/forecast'],
        ];
    }

    /**
     * @dataProvider allowedUrls
     */
    #[DataProvider('allowedUrls')]
    public function testAllowedUrlsReachTransport(string $url): void
    {
        $fetcher = new WindowsillCountingFetcher($this->container);
        try {
            $fetcher->get($url);
            $this->fail('expected the counting fetcher to stop at transport');
        } catch (\LogicException) {
            $this->assertSame(1, $fetcher->reached);
        }
    }

    public function testOkReturnsBody(): void
    {
        $this->assertSame('{"ok":true}', $this->local()->get(self::url('/ok')));
    }

    public function testRedirectIsNotFollowed(): void
    {
        $fetcher = $this->local();
        file_put_contents(self::$hits, '');
        $this->assertFails($fetcher, '/redirect');
        $hits = file(self::$hits, FILE_IGNORE_NEW_LINES);
        $this->assertSame(['/redirect'], $hits);
    }

    public static function failingPaths(): array
    {
        return [
            'server error' => ['/status/500'],
            'not found' => ['/status/404'],
            'oversize' => ['/big'],
        ];
    }

    /**
     * @dataProvider failingPaths
     */
    #[DataProvider('failingPaths')]
    public function testFailuresThrow(string $path): void
    {
        $this->assertFails($this->local(), $path);
    }

    public function testSlowResponseTimesOut(): void
    {
        $fetcher = $this->local(1);
        $start = microtime(true);
        $this->assertFails($fetcher, '/slow');
        $this->assertLessThan(2.5, microtime(true) - $start);
    }

    public function testNothingIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $method) {
            $logger->expects($this->never())->method($method);
        }
        $this->container['logger'] = $logger;

        $fetcher = new WindowsillCountingFetcher($this->container);
        foreach (self::rejectedUrls() as [$url]) {
            try {
                $fetcher->get($url);
            } catch (ProviderException) {
            }
        }

        // php -S serves one request at a time: wait out any /slow left from an earlier test before timing anything.
        $local = $this->local();
        $this->assertSame('{"ok":true}', $local->get(self::url('/ok')));

        // Each non-slow path must take its own branch (served at once, not timed out), and the redirect is not followed.
        $paths = ['/redirect', '/status/500', '/status/404', '/big'];
        file_put_contents(self::$hits, '');
        foreach ($paths as $path) {
            $start = microtime(true);
            $this->assertFails($local, $path);
            $this->assertLessThan(0.9, microtime(true) - $start, "$path answered, not timed out");
        }
        $this->assertSame($paths, file(self::$hits, FILE_IGNORE_NEW_LINES));

        // Last, so nothing queues behind it.
        $this->assertFails($this->local(1), '/slow');
    }

    public function testRegisteredTransportIsTheFetcher(): void
    {
        (new Plugin($this->container))->initialize();
        $this->assertInstanceOf(HttpsFetcher::class, $this->container['windowsillHttp']);
    }
}
