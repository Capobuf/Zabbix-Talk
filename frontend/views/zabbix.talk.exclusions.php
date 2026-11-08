<?php

require __DIR__.'/partials/navigation.php';

$body = (new CDiv($navigation))->addClass('talk-ui');
$body->addItem(new CTag('p', true, _('Controlli esclusi da Telegram. Lo stato viene riletto da Zabbix a ogni apertura. La riattivazione riguarda soltanto il trigger indicato.')));
if ($data['error'] !== '') {
    $body->addItem((new CDiv($data['error']))->addClass(ZBX_STYLE_RED)->setAttribute('role', 'alert'));
}
elseif (!$data['enabled']) {
    $body->addItem(new CTag('p', true, new CLink(_('Salva destinazioni e notifiche per abilitare esclusione e riattivazione.'), $url('notifications'))));
}
$filter = (new CForm('get'))->setId('talk-exclusions-filter')->addVar('action', 'zabbix.talk.exclusions')->addItem(new CFormGrid([
    new CLabel(_('Cerca host, controllo, ID o operatore'), 'talk-exclusion-search'),
    new CFormField((new CTextBox('filter_search', $data['search']))->setId('talk-exclusion-search')->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)),
    new CLabel(_('Ricerca')), new CFormField(new CSubmitButton(_('Cerca')))
]));
$body->addItem($filter);
$table = (new CTableInfo())->setHeader([_('Host / controllo'), _('Escluso da / data'), _('Destinazione / motivo'), _('Stato Zabbix'), _('Gestione')])
    ->setNoDataMessage($data['error'] !== '' ? _('Elenco non disponibile: verifica il bridge e riprova.')
        : ($data['search'] !== '' ? _('Nessun controllo corrisponde alla ricerca.') : _('Nessun controllo escluso da Zabbix Talk.')));
foreach ($data['exclusions'] as $row) {
    $state = !isset($row['current_status']) ? _('Non verificabile')
        : ($row['current_status'] === '1' ? _('Disabilitato') : _('Attivo in Zabbix'));
    $management = $row['detail'];
    if ($row['can_enable'] && $data['enabled']) {
        $form = (new CForm('post', 'zabbix.php?action=zabbix.talk.enable'))
            ->setId('talk-enable-'.$row['triggerid'])
            ->setAttribute('onsubmit', 'return window.confirm('.json_encode(
                _('Riattivare il singolo trigger').' '.$row['description'].' · '.$row['host'].' (ID '.$row['triggerid'].')?').');')
            ->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('zabbix.talk.enable')))->removeId())
            ->addItem((new CVar('triggerid', $row['triggerid']))->removeId())
            ->addItem((new CVar('confirm', '1'))->removeId())
            ->addItem(new CSubmitButton(_('Riattiva')));
        $management = $form;
    }
    $table->addRow([
        [new CTag('strong', true, $row['host']), new CDiv($row['description']),
            new CDiv('Host ID '.$row['hostid'].' · trigger ID '.$row['triggerid'])],
        [$row['actor'], new CDiv(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $row['created_at']))],
        ['Chat '.$row['chat_id'].' / '.($row['thread_id'] ? _('Topic').' '.$row['thread_id'] : _('Generale')),
            new CDiv($row['reason'] ?: _('Nessuna motivazione indicata'))],
        (new CSpan($state))->addClass($row['can_enable'] ? ZBX_STYLE_ORANGE : ''), $management
    ]);
}
$body->addItem((new CDiv($table))->addClass('talk-table'))->addItem($help(_('Effetti e limiti'), _('L’esclusione disabilita realmente il trigger per tutti i canali, senza chiudere artificialmente il problema. Item e altri trigger continuano a essere valutati. I trigger LLD cancellati e ricreati hanno una nuova identità: non sono esclusi automaticamente. Un esito incerto o una configurazione modificata richiede verifica in Zabbix.')));
(new CHtmlPage())->setTitle(_('Zabbix Talk').' · '._('Controlli esclusi'))
    ->setControls(new CLink(_('Aggiorna elenco'), (new CUrl('zabbix.php'))->setArgument('action', 'zabbix.talk.exclusions')
        ->setArgument('filter_search', $data['search'])))->addItem($body)->show();
