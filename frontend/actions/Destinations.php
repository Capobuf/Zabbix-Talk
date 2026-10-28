<?php

namespace Modules\ZabbixTalk\Actions;

use API;
use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ZabbixTalk\BridgeClient;
use Modules\ZabbixTalk\Notifications;
use RuntimeException;
use Throwable;

require_once __DIR__.'/../includes/Notifications.php';

class Destinations extends CController {
    public function init(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') { $this->disableCsrfValidation(); }
        header('Cache-Control: no-store');
    }

    protected function checkInput(): bool {
        return $this->validateInput(['operation' => 'in preview,save', 'mapping' => 'array',
            'choices' => 'array', 'severities' => 'array', 'confirm' => 'in 1', 'section' => 'in destinations,notifications']);
    }

    protected function checkPermissions(): bool {
        return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        $data = ['section' => $this->getInput('section', 'destinations'), 'telegram' => [],
            'notifications' => ['config' => [], 'ready' => false, 'message' => _('Stato delle risorse non disponibile.')],
            'error' => '', 'message' => '', 'hosts' => [], 'groups' => [], 'destinations' => [],
            'mapping' => [], 'choices' => [], 'severities' => [2, 3, 4, 5]];
        try {
            $notifications = new Notifications();
            $state = BridgeClient::request('/status');
            $data['telegram'] = $state;
            $data['notifications'] = $notifications->status($state);
            $data['destinations'] = $state['destinations'] ?? [];
            $data['groups'] = API::HostGroup()->get(['output' => ['groupid', 'name'], 'sortfield' => 'name']);
            $groups = array_column($data['groups'], null, 'groupid');
            $config = $notifications->config();
            $data['choices'] = $config['choices'] ?? [];
            $data['severities'] = $config['severities'] ?? $data['severities'];
            foreach ($notifications->routes() as $route) {
                $destination = $route['destination'];
                $data['mapping'][$route['groupid']] = $destination['chat_id'].':'.($destination['message_thread_id'] ?? '');
            }
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $this->hasInput('operation')) {
                $data['mapping'] = $this->getInput('mapping', []);
                $data['choices'] = $this->getInput('choices', []);
                $data['severities'] = $this->getInput('severities', []);
            }
            $routes = [];
            foreach ($data['mapping'] as $id => $key) {
                if ($key === '') { continue; }
                if (!is_string($key) || !isset($groups[$id], $data['destinations'][$key])) {
                    throw new RuntimeException(_('Gruppo o destinazione non più disponibili. Riapri la pagina e correggi le associazioni.'));
                }
                $routes[] = ['groupid' => (string) $id, 'group_name' => $groups[$id]['name'],
                    'destination' => $data['destinations'][$key]];
            }
            foreach ($data['choices'] as $hostid => $key) {
                if (!ctype_digit((string) $hostid) || !is_string($key)) {
                    throw new RuntimeException(_('Scelta per host non valida.'));
                }
            }
            $data['hosts'] = $notifications->preview($routes, $data['choices']);
            if ($this->getInput('operation', '') === 'preview') { $data['message'] = _('Anteprima aggiornata, modifiche non ancora salvate.'); }
            if ($this->getInput('operation', '') === 'save' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                $data['message'] = $notifications->provision($routes[0]['groupid'] ?? '', $data['severities'],
                    $this->hasInput('confirm'), $routes, $data['choices']);
                $data['notifications'] = $notifications->status();
            }
        }
        catch (Throwable $error) { $data['error'] = $error->getMessage(); }
        $this->setResponse(new CControllerResponseData($data));
    }
}
