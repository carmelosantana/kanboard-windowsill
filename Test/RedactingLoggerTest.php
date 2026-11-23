<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Windowsill\Model\RedactingLogger;
use Kanboard\Plugin\Windowsill\Plugin;
use Psr\Log\AbstractLogger;

class WindowsillCapturingLogger extends AbstractLogger
{
    public array $records = [];

    public function log($level, $message, array $context = array())
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

class RedactingLoggerTest extends Base
{
    public function testUrlQueryIsRedactedAndContextDropped(): void
    {
        $inner = new WindowsillCapturingLogger();
        $logger = new RedactingLogger($inner);

        $logger->error(
            'HttpClient: request failed (https://api.open-meteo.com/v1/forecast?latitude=52.52&longitude=13.41)',
            ['url' => 'x?latitude=1']
        );

        $this->assertSame([[
            'level' => 'error',
            'message' => 'HttpClient: request failed (https://api.open-meteo.com/v1/forecast?[redacted])',
            'context' => [],
        ]], $inner->records);
    }

    public function testMessageWithoutUrlPassesUnchanged(): void
    {
        $inner = new WindowsillCapturingLogger();
        (new RedactingLogger($inner))->warning('Windowsill: nothing to see? here');

        $this->assertSame('warning', $inner->records[0]['level']);
        $this->assertSame('Windowsill: nothing to see? here', $inner->records[0]['message']);
    }

    public function testDebugBodyIsRedacted(): void
    {
        $inner = new WindowsillCapturingLogger();
        (new RedactingLogger($inner))->debug('HttpClient: body={"latitude":52.52,"results":[{"name":"Springfield"}]}'."\nmore");

        $this->assertSame('HttpClient: body=[redacted]', $inner->records[0]['message']);
    }

    public function testRegisteredTransportRedactsCoreFailureLogs(): void
    {
        (new Plugin($this->container))->initialize();
        $capture = new WindowsillCapturingLogger();
        $this->container['logger'] = $capture;

        $body = $this->container['windowsillHttp']->get('http://127.0.0.1:9/v1/forecast?latitude=52.52&longitude=13.41');

        $this->assertSame('', $body);
        $this->assertNotEmpty($capture->records);
        $messages = array_column($capture->records, 'message');
        foreach ($messages as $message) {
            $this->assertStringNotContainsString('latitude=', $message);
        }
        $this->assertNotEmpty(array_filter($messages, fn ($m) => str_contains($m, '/v1/forecast?[redacted]')));
    }
}
