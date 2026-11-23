<?php
namespace Kanboard\Plugin\Windowsill;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize(): void
    {
    }

    public function getPluginName(): string        { return 'Windowsill'; }
    public function getPluginDescription(): string { return t('A per-user weather widget on the Kanboard dashboard: current conditions and a three-day forecast.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '0.1.0'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-windowsill'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
