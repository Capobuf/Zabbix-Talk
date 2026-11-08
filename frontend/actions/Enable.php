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

class Enable extends CController {
    protected function checkInput(): bool {
        $valid = $this->validateInput(['triggerid' => 'required|db triggers.triggerid', 'confirm' => 'required|in 1']);
        if (!$valid) {
            $this->setResponse(new CControllerResponseRedirect((new CUrl('zabbix.php'))->setArgument('action', 'zabbix.talk.exclusions')));
        }
        return $valid;
    }

    protected function checkPermissions(): bool {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $result = BridgeClient::request('/enable-control', ['triggerid' => $this->getInput('triggerid'),
                'actor' => 'Zabbix: '.CWebUser::$data['username'].' (user ID '.CWebUser::$data['userid'].')']);
            CMessageHelper::setSuccessTitle($result['message']);
        }
        catch (RuntimeException $error) {
            CMessageHelper::setErrorTitle(_('Riattivazione non riuscita.'));
            error($error->getMessage());
        }
        $this->setResponse(new CControllerResponseRedirect((new CUrl('zabbix.php'))->setArgument('action', 'zabbix.talk.exclusions')));
    }
}
