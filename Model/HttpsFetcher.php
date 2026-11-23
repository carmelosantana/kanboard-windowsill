<?php

namespace Kanboard\Plugin\Windowsill\Model;

use Pimple\Container;

/**
 * The plugin's only outbound transport: HTTPS GET to two fixed Open-Meteo hosts, never following a
 * redirect, bounded in time and size. Every failure is a ProviderException with a fixed message
 * (never the URL), and nothing is logged here: OpenMeteoClient owns the one fixed warning.
 */
class HttpsFetcher
{
    const ALLOWED_HOSTS = ['api.open-meteo.com', 'geocoding-api.open-meteo.com'];
    const TIMEOUT = 5;
    const CONNECT_TIMEOUT = 3;
    const MAX_BYTES = 65536;
    const USER_AGENT = 'Kanboard-Windowsill';

    public function __construct(Container $container)
    {
    }

    /**
     * @return string the body of an HTTP 200 response
     * @throws ProviderException
     */
    public function get(string $url): string
    {
        // Control characters and whitespace never belong in our URLs; curl rejects a NUL with a ValueError.
        $parts = preg_match('/[\x00-\x20\x7f]/', $url) ? false : parse_url($url);
        if (!is_array($parts) || !$this->isAllowed($parts)) {
            throw new ProviderException('Provider URL is not allowed');
        }
        return $this->transfer($url);
    }

    protected function isAllowed(array $parts): bool
    {
        return strtolower($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), self::ALLOWED_HOSTS, true)
            && !isset($parts['user']) && !isset($parts['pass'])
            && ($parts['port'] ?? 443) === 443;
    }

    /**
     * Comma-separated curl protocol names for the request and any redirect.
     */
    protected function protocols(): string
    {
        return 'https';
    }

    protected function timeout(): int
    {
        return self::TIMEOUT;
    }

    private function transfer(string $url): string
    {
        $protocols = $this->protocols();
        if (!function_exists('curl_init')) {
            throw new ProviderException('Provider transport is unavailable');
        }

        $body = '';
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_TIMEOUT => $this->timeout(),
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            // Returning fewer bytes than given aborts the transfer.
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        self::limitProtocols($curl, $protocols);

        // Proxy and TLS settings exactly as core's Client::doRequestWithCurl applies them.
        if (HTTP_VERIFY_SSL_CERTIFICATE === false) {
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        }
        if (HTTP_PROXY_HOSTNAME) {
            curl_setopt($curl, CURLOPT_PROXY, HTTP_PROXY_HOSTNAME);
            curl_setopt($curl, CURLOPT_PROXYPORT, HTTP_PROXY_PORT);
            curl_setopt($curl, CURLOPT_NOPROXY, HTTP_PROXY_EXCLUDE);
        }
        if (HTTP_PROXY_USERNAME) {
            curl_setopt($curl, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
            curl_setopt($curl, CURLOPT_PROXYUSERPWD, HTTP_PROXY_USERNAME.':'.HTTP_PROXY_PASSWORD);
        }

        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        unset($curl);

        if ($ok === false) {
            throw new ProviderException('Provider request failed');
        }
        if ($status !== 200) {
            throw new ProviderException('Provider returned an unexpected status');
        }
        return $body;
    }

    /**
     * CURLOPT_*_PROTOCOLS_STR needs curl 7.85; older builds take the CURLPROTO_* bitmask.
     */
    private static function limitProtocols(\CurlHandle $curl, string $protocols): void
    {
        if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            curl_setopt($curl, CURLOPT_PROTOCOLS_STR, $protocols);
            curl_setopt($curl, CURLOPT_REDIR_PROTOCOLS_STR, $protocols);
            return;
        }
        $mask = 0;
        foreach (explode(',', $protocols) as $name) {
            $mask |= constant('CURLPROTO_'.strtoupper(trim($name)));
        }
        curl_setopt($curl, CURLOPT_PROTOCOLS, $mask);
        curl_setopt($curl, CURLOPT_REDIR_PROTOCOLS, $mask);
    }
}
