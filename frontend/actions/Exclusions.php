<?php

namespace Modules\ZabbixTalk\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ZabbixTalk\BridgeClient;
use RuntimeException;

require_once __DIR__.'/../includes/BridgeClient.php';

class Exclusions extends CController {
    public function init(): void {
        $this->disableCsrfValidation();
        header('Cache-Control: no-store');
    }

    protected function checkInput(): bool {
        return $this->validateInput(['filter_search' => 'string']);
    }

    protected function checkPermissions(): bool {
        return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        $data = ['section' => 'exclusions', 'search' => $this->getInput('filter_search', ''),
            'exclusions' => [], 'error' => '', 'enabled' => false];
        try {
            $data = array_replace($data, BridgeClient::request('/exclusions', ['operation' => 'list']));
            if ($data['search'] !== '') {
                $data['exclusions'] = array_values(array_filter($data['exclusions'], static fn($row) =>
                    mb_stripos($row['host'].' '.$row['description'].' '.$row['triggerid'].' '.$row['actor'], $data['search']) !== false));
            }
        }
        catch (RuntimeException $error) { $data['error'] = $error->getMessage(); }
        $this->setResponse(new CControllerResponseData($data));
    }
}
