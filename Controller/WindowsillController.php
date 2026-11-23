<?php

namespace Kanboard\Plugin\Windowsill\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\Windowsill\Model\ProviderException;

/**
 * Weather settings: pick a place (searched server-side), choose units, remove it.
 * Every action acts on the logged-in user; none accepts a user_id.
 */
class WindowsillController extends BaseController
{
    public function settings(): void
    {
        $this->renderSettings('Windowsill:settings/show', [
            'location' => $this->windowsillLocation->get($this->userSession->getId()),
        ]);
    }

    public function search(): void
    {
        $this->checkCSRFForm();
        $values = $this->request->getValues();
        $query = $values['q'] ?? null;

        try {
            if (!is_string($query)) {
                throw new \InvalidArgumentException('Query must be a string.');
            }
            $places = $this->windowsillClient->searchPlaces($query);
        } catch (\InvalidArgumentException $e) {
            $this->flash->failure(t('Enter 2 to 100 characters.'));
            $this->redirectToSettings();
            return;
        } catch (ProviderException $e) {
            $this->flash->failure(t('Place search is unavailable right now.'));
            $this->redirectToSettings();
            return;
        }

        $this->renderSettings('Windowsill:settings/results', [
            'query' => trim($query),
            'places' => $places,
        ]);
    }

    public function save(): void
    {
        $this->checkCSRFForm();
        $values = $this->request->getValues();
        $units = $values['units'] ?? null;

        try {
            if ($units !== null && !is_string($units)) {
                throw new \InvalidArgumentException('Units must be a string.');
            }
            $place = array_intersect_key($values, array_flip(['name', 'admin1', 'country_code', 'lat', 'lon']));
            $this->windowsillLocation->save($this->userSession->getId(), $place, $units === '' ? null : $units);
            $this->flash->success(t('Location saved.'));
        } catch (\InvalidArgumentException $e) {
            $this->flash->failure(t('That location is not valid.'));
        }

        $this->redirectToSettings();
    }

    public function units(): void
    {
        $this->checkCSRFForm();
        $values = $this->request->getValues();
        $units = $values['units'] ?? null;
        $userId = $this->userSession->getId();

        try {
            $this->windowsillLocation->setUnits($userId, is_string($units) ? $units : '');
        } catch (\InvalidArgumentException $e) {
            $this->flash->failure($this->windowsillLocation->get($userId) === null
                ? t('Choose a location first.')
                : t('That unit is not valid.'));
        }

        $this->redirectToSettings();
    }

    public function remove(): void
    {
        $this->checkCSRFForm();
        $this->windowsillLocation->clear($this->userSession->getId());
        $this->flash->success(t('Location removed.'));
        $this->redirectToSettings();
    }

    private function renderSettings(string $template, array $params): void
    {
        $this->response->html($this->helper->layout->user($template, $params + [
            'user' => $this->currentUser(),
            'unit_options' => [
                'us' => t('US (°F, mph)'),
                'metric' => t('Metric (°C, km/h)'),
            ],
        ]));
    }

    private function currentUser(): array
    {
        return $this->userModel->getById($this->userSession->getId());
    }

    private function redirectToSettings(): void
    {
        $this->response->redirect($this->helper->url->to('WindowsillController', 'settings', ['plugin' => 'Windowsill']));
    }
}
