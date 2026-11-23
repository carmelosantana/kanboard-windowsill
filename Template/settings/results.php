<div class="page-header">
    <h2><?= t('Weather') ?></h2>
</div>

<p><?= t('Results for') ?> <strong><?= $this->text->e($query) ?></strong></p>

<?php if (empty($places)): ?>
    <p class="alert"><?= t('No places found.') ?></p>
<?php else: ?>
    <ul class="windowsill-places">
        <?php foreach ($places as $place): ?>
            <li>
                <form method="post" action="<?= $this->url->href('WindowsillController', 'save', ['plugin' => 'Windowsill']) ?>" autocomplete="off">
                    <?= $this->form->csrf() ?>
                    <?php foreach (['name', 'admin1', 'country_code', 'lat', 'lon'] as $field): ?>
                        <input type="hidden" name="<?= $field ?>" value="<?= $this->text->e($place[$field]) ?>">
                    <?php endforeach ?>
                    <?php $rest = array_filter([$place['admin1'], $place['country_code']], fn ($p) => $p !== '') ?>
                    <strong><?= $this->text->e($place['name']) ?></strong><?php foreach ($rest as $part): ?>, <?= $this->text->e($part) ?><?php endforeach ?>
                    <?php $default = strtoupper($place['country_code']) === 'US' ? 'us' : 'metric' ?>
                    <select name="units" aria-label="<?= $this->text->e(t('Units')) ?>">
                        <?php foreach ($unit_options as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $value === $default ? ' selected="selected"' : '' ?>><?= $this->text->e($label) ?></option>
                        <?php endforeach ?>
                    </select>
                    <button type="submit" class="btn btn-blue"><?= t('Use this place') ?></button>
                </form>
            </li>
        <?php endforeach ?>
    </ul>
<?php endif ?>

<p><?= $this->url->link(t('Back'), 'WindowsillController', 'settings', ['plugin' => 'Windowsill']) ?></p>
