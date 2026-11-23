<div class="page-header">
    <h2><?= t('Weather') ?></h2>
</div>

<?php if ($location): ?>
    <p class="windowsill-current"><?= t('Your location:') ?> <strong><?= $this->text->e($location['label']) ?></strong></p>

    <form method="post" action="<?= $this->url->href('WindowsillController', 'units', ['plugin' => 'Windowsill']) ?>" autocomplete="off">
        <?= $this->form->csrf() ?>
        <?= $this->form->label(t('Units'), 'units') ?>
        <?= $this->form->select('units', $unit_options, ['units' => $location['units']]) ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
        </div>
    </form>
<?php else: ?>
    <p class="alert"><?= t('No location set. Search for a place to show the weather on your dashboard.') ?></p>
<?php endif ?>

<form method="post" action="<?= $this->url->href('WindowsillController', 'search', ['plugin' => 'Windowsill']) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>
    <?= $this->form->label(t('Find a place'), 'q') ?>
    <?= $this->form->text('q', [], [], ['required', 'minlength="2"', 'maxlength="100"', 'placeholder="'.$this->text->e(t('City or town')).'"']) ?>
    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Search') ?></button>
    </div>
</form>

<?php if ($location): ?>
    <form method="post" action="<?= $this->url->href('WindowsillController', 'remove', ['plugin' => 'Windowsill']) ?>">
        <?= $this->form->csrf() ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-red"><?= t('Remove location') ?></button>
        </div>
    </form>
<?php endif ?>

<p class="form-help"><a href="https://open-meteo.com/" target="_blank" rel="noopener noreferrer">Weather data by Open-Meteo.com</a></p>
