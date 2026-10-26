<?php

(new CHtmlPage())
    ->setTitle(_('Zabbix Talk'))
    ->addItem(new CDiv($data['status']))
    ->show();
