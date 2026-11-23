<?php
// Router for `php -S` in HttpsFetcherTest: records every requested path, then answers by path.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$hits = getenv('WINDOWSILL_HITS');
if ($hits) {
    file_put_contents($hits, $path."\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');

switch ($path) {
    case '/ok':
    case '/secret':
        echo '{"ok":true}';
        break;
    case '/redirect':
        header('Location: /secret', true, 302);
        echo '{"ok":true}';
        break;
    case '/status/404':
        http_response_code(404);
        echo '{"error":true}';
        break;
    case '/status/500':
        http_response_code(500);
        echo '{"error":true}';
        break;
    case '/big':
        echo '"', str_repeat('x', 100 * 1024), '"';
        break;
    case '/slow':
        sleep(3);
        echo '{"ok":true}';
        break;
    default:
        http_response_code(404);
}
