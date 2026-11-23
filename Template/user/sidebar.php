<?php if ($user['id'] == $this->user->getId()): ?>
    <li <?= $this->app->checkMenuSelection('WindowsillController', '', 'Windowsill') ?>>
        <?= $this->url->link(t('Weather'), 'WindowsillController', 'settings', ['plugin' => 'Windowsill']) ?>
    </li>
<?php endif ?>
