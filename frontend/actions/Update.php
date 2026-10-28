<?php

namespace Modules\ZabbixTalk\Actions;

use CController;
use CControllerResponseRedirect;
use CCsrfTokenHelper;
use CMessageHelper;
use CUrl;
use CWebUser;
use Modules\ZabbixTalk\BridgeClient;
use RuntimeException;

require_once __DIR__.'/../includes/BridgeClient.php';

class Update extends CController {
    protected function checkInput(): bool {
        $valid = $this->validateInput([
            'operation' => 'required|in connect,verify,register,send,edit,select,create_topic',
            'section' => 'in destinations,settings,tests', 'token' => 'string', 'destination_key' => 'string', 'topic_name' => 'string'
        ]);
        $token = $this->getInput('token', '');
        if (!$valid || strlen($token) > 256) {
            CMessageHelper::setErrorTitle(_('Richiesta Zabbix Talk non valida.'));
            $this->setResponse($this->returnResponse());
            return false;
        }
        return true;
    }

    private function returnResponse(): CControllerResponseRedirect {
        $section = $this->getInput('section', 'settings');
        $response = new CControllerResponseRedirect((new CUrl('zabbix.php'))->setArgument('action',
            $section === 'destinations' ? 'zabbix.talk.destinations' : 'zabbix.talk.overview')
            ->setArgument('section', $section));
        if ($section === 'destinations') {
            // Native redirects relay feedback with a signed POST to the destination controller.
            $response->setFormData([CSRF_TOKEN_NAME => CCsrfTokenHelper::get('zabbix.talk.destinations')]);
        }
        return $response;
    }

    protected function checkPermissions(): bool {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            if ($this->getInput('operation') === 'connect' && ($_SERVER['HTTPS'] ?? '') !== 'on') {
                throw new RuntimeException(_('Apri Zabbix tramite HTTPS prima di salvare il token del bot.'));
            }
            $result = BridgeClient::request('/action', [
                'operation' => $this->getInput('operation'),
                'token' => $this->getInput('token', ''),
                'destination_key' => $this->getInput('destination_key', ''),
                'topic_name' => $this->getInput('topic_name', '')
            ]);
            CMessageHelper::setSuccessTitle($result['message']);
        }
        catch (RuntimeException $error) {
            CMessageHelper::setErrorTitle(_('Operazione Zabbix Talk non riuscita.'));
            error($error->getMessage());
        }
        $this->setResponse($this->returnResponse());
    }
}
