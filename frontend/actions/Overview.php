<?php

namespace Modules\ZabbixTalk\Actions;

use CController;
use CControllerResponseData;
use CWebUser;

class Overview extends CController {
    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        $request = curl_init('http://127.0.0.1:8765/health');
        curl_setopt_array($request, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT_MS => 300,
            CURLOPT_TIMEOUT_MS => 1000
        ]);

        $body = curl_exec($request);
        $http_status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        curl_close($request);

        $health = is_string($body) ? json_decode($body, true) : null;
        $reachable = $http_status === 200 && is_array($health)
            && ($health['service'] ?? null) === 'zabbix-talk'
            && ($health['status'] ?? null) === 'ok';

        $this->setResponse(new CControllerResponseData([
            'bridge_reachable' => $reachable
        ]));
    }
}
