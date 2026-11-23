<?php

namespace Kanboard\Plugin\Windowsill\Model;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Wraps the application logger for Windowsill's HTTP transport: Kanboard's HTTP client logs full
 * request URLs on failure (and response bodies under DEBUG), and ours carry coordinates and place
 * queries. Every http(s) URL in a message loses its query string, a logged body is replaced whole,
 * and context is dropped.
 */
class RedactingLogger extends AbstractLogger
{
    public function __construct(private LoggerInterface $inner)
    {
    }

    public function log($level, $message, array $context = array())
    {
        $this->inner->log($level, self::redact((string) $message));
    }

    private static function redact(string $message): string
    {
        // Under DEBUG core logs the response body, which echoes coordinates and place names.
        if (str_starts_with($message, 'HttpClient: body=')) {
            return 'HttpClient: body=[redacted]';
        }
        return preg_replace('~(https?://[^\s?#)]*)\?[^\s)]*~i', '$1?[redacted]', $message);
    }
}
