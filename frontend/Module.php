<?php

namespace Modules\ZabbixTalk;

use APP;
use CMenuItem;
use CWebUser;
use Zabbix\Core\CModule;

class Module extends CModule {
    public function init(): void {
        if (CWebUser::getType() != USER_TYPE_SUPER_ADMIN) {
            return;
        }

        APP::Component()->get('menu.main')
            ->add(
                (new CMenuItem(_('Zabbix Talk')))
                    ->setAction('zabbix.talk.overview')
                    ->setAliases(['zabbix.talk.destinations'])
            );
    }
}
