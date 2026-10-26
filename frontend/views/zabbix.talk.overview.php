<?php

(new CHtmlPage())
    ->setTitle(_('Zabbix Talk'))
    ->setControls(
        (new CTag('nav', true, [
            (new CList())->addItem(
                (new CLink(_('Aggiorna stato'),
                    (new CUrl('zabbix.php'))->setArgument('action', 'zabbix.talk.overview')
                ))->addClass(ZBX_STYLE_BTN_ALT)
            )
        ]))->setAttribute('aria-label', _('Controlli della pagina'))
    )
    ->addItem(
        (new CTableInfo())
            ->setHeader([_('Componente'), _('Stato')])
            ->addRow([
                _('Bridge locale'),
                (new CSpan($data['bridge_reachable']
                    ? _('Bridge raggiungibile')
                    : _('Bridge non raggiungibile')
                ))->addClass($data['bridge_reachable'] ? ZBX_STYLE_GREEN : ZBX_STYLE_RED)
            ])
    )
    ->addItem(new CTag('p', true, $data['bridge_reachable']
        ? _('Verifica locale riuscita. La connessione Telegram non è ancora configurata.')
        : _('Il servizio locale non risponde correttamente. Verifica il servizio zabbix-talk sulla VM e aggiorna lo stato.')
    ))
    ->show();
