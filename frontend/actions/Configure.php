<?php

namespace Modules\ZabbixTalk\Actions;

use CController;
use CControllerResponseRedirect;
use CCsrfTokenHelper;
use CMessageHelper;
use CUrl;
use CWebUser;
use Modules\ZabbixTalk\Notifications;
use Throwable;

require_once __DIR__.'/../includes/Notifications.php';

class Configure extends CController {
    protected function checkInput(): bool {
        if (!$this->validateInput([
            'operation' => 'required|in provision,transport,prepare_test,problem_test,recovery_test,remove_test',
            'groupid' => 'db hstgrp.groupid', 'severities' => 'array', 'confirm' => 'in 1', 'section' => 'in notifications,tests'
        ])) {
            $this->setResponse($this->returnResponse());
            return false;
        }
        return true;
    }

    private function returnResponse(): CControllerResponseRedirect {
        $section = $this->getInput('section', $this->getInput('operation', '') === 'provision' ? 'notifications' : 'tests');
        $response = new CControllerResponseRedirect((new CUrl('zabbix.php'))->setArgument('action',
            $section === 'notifications' ? 'zabbix.talk.destinations' : 'zabbix.talk.overview')
            ->setArgument('section', $section));
        if ($section === 'notifications') {
            $response->setFormData([CSRF_TOKEN_NAME => CCsrfTokenHelper::get('zabbix.talk.destinations')]);
        }
        return $response;
    }

    protected function checkPermissions(): bool {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $notifications = new Notifications();
            switch ($this->getInput('operation')) {
                case 'provision':
                    $message = $notifications->provision($this->getInput('groupid', ''),
                        $this->getInput('severities', []), $this->hasInput('confirm'));
                    break;
                case 'transport':
                    $message = $notifications->transportTest();
                    break;
                case 'prepare_test':
                    $message = $notifications->prepareTest();
                    break;
                case 'problem_test':
                    $message = $notifications->problemTest();
                    break;
                case 'recovery_test':
                    $message = $notifications->recoveryTest();
                    break;
                case 'remove_test':
                    $message = $notifications->removeTest();
                    break;
            }
            CMessageHelper::setSuccessTitle($message);
        }
        catch (Throwable $error) {
            CMessageHelper::setErrorTitle(_('Configurazione o test non riuscito.'));
            error($error->getMessage());
        }
        $this->setResponse($this->returnResponse());
    }
}
