<?php
namespace Kanboard\Plugin\Windowsill;

use Kanboard\Core\Http\Client;
use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\Windowsill\Model\LocationStore;
use Kanboard\Plugin\Windowsill\Model\OpenMeteoClient;
use Kanboard\Plugin\Windowsill\Model\RedactingLogger;
use Pimple\Container;

class Plugin extends Base
{
    public function initialize(): void
    {
        // Kanboard's own HTTP client over a small container whose logger strips URL query strings:
        // core logs the full request URL on failure, and ours carry coordinates and place queries.
        $this->container['windowsillHttp'] = function ($c) {
            $small = new Container();
            $small['logger'] = function () use ($c) { return new RedactingLogger($c['logger']); };
            $small['queueManager'] = function () use ($c) { return $c['queueManager']; };
            return new Client($small);
        };
        $this->container['windowsillClient'] = function ($c) {
            return new OpenMeteoClient($c);
        };
        $this->container['windowsillLocation'] = function ($c) {
            return new LocationStore($c);
        };
    }

    public function getPluginName(): string        { return 'Windowsill'; }
    public function getPluginDescription(): string { return t('A per-user weather widget on the Kanboard dashboard: current conditions and a three-day forecast.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '0.1.0'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-windowsill'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
