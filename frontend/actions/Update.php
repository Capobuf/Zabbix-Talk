<?php

namespace Modules\ZabbixTalk\Actions;

use CController;
use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use CWebUser;
use Modules\ZabbixTalk\BridgeClient;
use RuntimeException;

require_once __DIR__.'/../includes/BridgeClient.php';

class Update extends CController {
    protected function checkInput(): bool {
        $valid = $this->validateInput([
            'operation' => 'required|in connect,verify,register,send,edit',
            'token' => 'string'
        ]);
        $token = $this->getInput('token', '');
        if (!$valid || strlen($token) > 256) {
            CMessageHelper::setErrorTitle(_('Richiesta Zabbix Talk non valida.'));
            $this->setResponse(new CControllerResponseRedirect(
                (new CUrl('zabbix.php'))->setArgument('action', 'zabbix.talk.overview')
            ));
            return false;
        }
        return true;
    }

    protected function checkPermissions(): bool {
        return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            if ($this->getInput('operation') === 'connect' && ($_SERVER['HTTPS'] ?? '') !== 'on') {
                throw new RuntimeException(_('Apri Zabbix tramite HTTPS prima di salvare il token del bot.'));
            }
            $result = BridgeClient::request('/action', [
                'operation' => $this->getInput('operation'),
                'token' => $this->getInput('token', '')
            ]);
            CMessageHelper::setSuccessTitle($result['message']);
        }
        catch (RuntimeException $error) {
            CMessageHelper::setErrorTitle(_('Operazione Zabbix Talk non riuscita.'));
            error($error->getMessage());
        }
        $this->setResponse(new CControllerResponseRedirect(
            (new CUrl('zabbix.php'))->setArgument('action', 'zabbix.talk.overview')
        ));
    }
}
