<?php
namespace Kanboard\Plugin\Windowsill;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\Windowsill\Model\ForecastCache;
use Kanboard\Plugin\Windowsill\Model\HttpsFetcher;
use Kanboard\Plugin\Windowsill\Model\LocationStore;
use Kanboard\Plugin\Windowsill\Model\OpenMeteoClient;
use Kanboard\Plugin\Windowsill\Model\WeatherService;

class Plugin extends Base
{
    public function initialize(): void
    {
        // Our own transport, not core's Client: no redirects, two fixed hosts, bounded time and size.
        $this->container['windowsillHttp'] = function ($c) {
            return new HttpsFetcher($c);
        };
        $this->container['windowsillClient'] = function ($c) {
            return new OpenMeteoClient($c);
        };
        $this->container['windowsillLocation'] = function ($c) {
            return new LocationStore($c);
        };
        $this->container['windowsillCache'] = function ($c) {
            return new ForecastCache($c);
        };
        $this->container['windowsillService'] = function ($c) {
            return new WeatherService($c);
        };

        foreach (['settings', 'search', 'save', 'units', 'remove', 'widget'] as $action) {
            $this->route->addRoute('windowsill/'.$action, 'WindowsillController', $action, 'Windowsill');
        }
        $this->hook->on('template:user:sidebar:actions', ['template' => 'Windowsill:user/sidebar']);
        $this->hook->on('template:dashboard:show:before-filter-box', [
            'template' => 'Windowsill:dashboard/widget',
            // Only on your own dashboard: an admin viewing someone else's never sees their location.
            'callable' => fn (array $user) => [
                'windowsill' => $user['id'] == $this->userSession->getId() ? $this->windowsillService->cached((int) $user['id']) : null,
                'windowsill_refresh_url' => $this->helper->url->href('WindowsillController', 'widget', [
                    'plugin' => 'Windowsill',
                    'csrf_token' => $this->token->getReusableCSRFToken(),
                ]),
            ],
        ]);
        $this->hook->on('template:layout:css', ['template' => 'plugins/Windowsill/Assets/css/windowsill.css']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/Windowsill/Assets/js/windowsill.js']);
    }

    public function getPluginName(): string        { return 'Windowsill'; }
    public function getPluginDescription(): string { return t('A per-user weather widget on the Kanboard dashboard: current conditions and a three-day forecast.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '0.1.0'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-windowsill'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
