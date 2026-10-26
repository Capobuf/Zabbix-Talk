<?php

namespace Modules\ZabbixTalk\Actions;

use CController;
use CControllerResponseData;

class Overview extends CController {
    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return true;
    }

    protected function doAction(): void {
        $this->setResponse(new CControllerResponseData([
            'status' => _('Il modulo Zabbix Talk è caricato. La connessione Telegram non è ancora configurata.')
        ]));
    }
}
