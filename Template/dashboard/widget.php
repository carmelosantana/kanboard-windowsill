<?php
use Kanboard\Plugin\Windowsill\Model\WeatherCodes;

if (empty($windowsill)) {
    return;
}

$vm = $windowsill;
$state = $vm['state'];
$forecast = $vm['forecast'];
$metric = ($vm['location']['units'] ?? 'us') === 'metric';
$settingsLink = $this->url->link(t('Weather settings'), 'WindowsillController', 'settings', ['plugin' => 'Windowsill'], false, 'windowsill-settings');

if ($forecast !== null) {
    // The location's own date, not the server's: a cached forecast must never call yesterday "Today".
    $today = gmdate('Y-m-d', $vm['now'] + $forecast['utc_offset']);
    $days = array_values(array_filter($forecast['days'], fn ($day) => $day['date'] >= $today));
    $now = WeatherCodes::describe($forecast['current']['code'], $forecast['current']['is_day']);
}
?>
<section class="windowsill" data-windowsill-state="<?= $this->text->e($state) ?>"<?php if ($state === 'pending'): ?> data-windowsill-refresh="<?= $this->text->e($windowsill_refresh_url ?? '') ?>"<?php endif ?>>
<?php if ($state === 'unset'): ?>
    <p class="windowsill-message"><?= $this->url->link(t('Set your location to see the weather.'), 'WindowsillController', 'settings', ['plugin' => 'Windowsill']) ?></p>
<?php elseif ($forecast !== null): ?>
    <header class="windowsill-header">
        <span class="windowsill-label"><?= $this->text->e($vm['location']['label']) ?></span>
        <?php if ($vm['aged']): ?>
            <span class="windowsill-aged"><?= t('as of %s', $this->dt->time($vm['fetched_at'])) ?></span>
        <?php endif ?>
    </header>
    <div class="windowsill-now">
        <span class="windowsill-glyph" aria-hidden="true"><?= $this->text->e($now['glyph']) ?></span>
        <span class="windowsill-temp"><?= (int) round($forecast['current']['temp']) ?><?= $metric ? '°C' : '°F' ?></span>
        <span class="windowsill-condition"><?= $this->text->e($now['label']) ?></span>
        <span class="windowsill-feels"><?= t('Feels like %d°', (int) round($forecast['current']['feels'])) ?></span>
        <span class="windowsill-wind"><?= (int) round($forecast['current']['wind']) ?> <?= $metric ? 'km/h' : 'mph' ?></span>
    </div>
    <?php if ($days !== []): ?>
        <ul class="windowsill-days">
            <?php foreach ($days as $day): ?>
                <?php $d = WeatherCodes::describe($day['code']); ?>
                <li class="windowsill-day" data-windowsill-date="<?= $this->text->e($day['date']) ?>">
                    <span class="windowsill-day-name"><?= $day['date'] === $today ? t('Today') : t(gmdate('D', strtotime($day['date'].' 00:00:00 UTC'))) ?></span>
                    <span class="windowsill-glyph" aria-hidden="true"><?= $this->text->e($d['glyph']) ?></span>
                    <span class="windowsill-day-condition"><?= $this->text->e($d['label']) ?></span>
                    <span class="windowsill-range"><?= (int) round($day['max']) ?>° / <?= (int) round($day['min']) ?>°</span>
                    <?php if ($day['pop'] !== null): ?>
                        <span class="windowsill-pop"><?= (int) $day['pop'] ?>%</span>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
    <footer class="windowsill-footer">
        <a href="https://open-meteo.com/" target="_blank" rel="noopener noreferrer">Weather data by Open-Meteo.com</a>
        <?= $settingsLink ?>
    </footer>
<?php elseif ($state === 'pending'): ?>
    <p class="windowsill-message"><?= t('Loading weather…') ?></p>
<?php else: ?>
    <p class="windowsill-message"><?= t('Weather is unavailable right now.') ?></p>
    <footer class="windowsill-footer"><?= $settingsLink ?></footer>
<?php endif ?>
</section>
