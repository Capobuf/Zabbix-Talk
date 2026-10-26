<?php

namespace Modules\ZabbixTalk;

use APP;
use CMenuItem;
use Zabbix\Core\CModule;

class Module extends CModule {
    public function init(): void {
        APP::Component()->get('menu.main')
            ->add(
                (new CMenuItem(_('Zabbix Talk')))
                    ->setAction('zabbix.talk.overview')
            );
    }
}
