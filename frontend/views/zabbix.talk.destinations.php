<?php

$page = (new CHtmlPage())->setTitle(_('Zabbix Talk · Destinazioni'));
$page->addItem(new CLink(_('Torna a collegamento e collaudo'), 'zabbix.php?action=zabbix.talk.overview'));
if ($data['error']) { $page->addItem((new CDiv($data['error']))->addClass(ZBX_STYLE_RED)->setAttribute('role', 'alert')); }
if ($data['message']) { $page->addItem((new CDiv($data['message']))->addClass(ZBX_STYLE_GREEN)->setAttribute('role', 'status')); }
$label = static fn(array $d): string => $d['title'].' · '.$d['chat_id'].' · '.
    ($d['message_thread_id'] ? _('Topic').' '.$d['message_thread_id'] : _('Generale / senza topic'));
$form = (new CForm('post', 'zabbix.php?action=zabbix.talk.destinations'))
    ->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('zabbix.talk.destinations')))->removeId());
$page->addItem(new CTag('p', true, _('Associa i gruppi host, inclusi i sottogruppi, alle destinazioni registrate al punto 2. Nessuna associazione: nessun invio. Più gruppi verso la stessa chat/topic: un solo invio. Destinazioni diverse: invio bloccato fino alla scelta per host. Premi Anteprima prima di salvare.')));
$table = (new CTableInfo())->setHeader([_('Gruppo host Zabbix'), _('Destinazione Telegram')]);
foreach ($data['groups'] as $group) {
    $options = [new CSelectOption('', _('Nessuna associazione'))];
    foreach ($data['destinations'] as $key => $destination) { $options[] = new CSelectOption($key, $label($destination)); }
    $table->addRow([$group['name'], (new CSelect('mapping['.$group['groupid'].']'))
        ->setFocusableElementId('mapping-'.$group['groupid'])->setAttribute('aria-label', _('Destinazione per ').$group['name'])
        ->addOptions($options)->setValue($data['mapping'][$group['groupid']] ?? '')->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)]);
}
$form->addItem($table)->addItem(new CTag('h2', true, _('Anteprima host e conflitti')));
$hosts = (new CTableInfo())->setHeader([_('Host'), _('Gruppi attuali'), _('Esito / scelta esplicita')]);
foreach ($data['hosts'] as $host) {
    $options = [new CSelectOption('', _('Conflitto: invio bloccato'))];
    foreach ($host['candidates'] as $key => $destination) { $options[] = new CSelectOption($key, $label($destination)); }
    $effect = count($host['candidates']) === 1 ? $label(reset($host['candidates'])) :
        (new CSelect('choices['.$host['hostid'].']'))->setFocusableElementId('choice-'.$host['hostid'])
            ->setAttribute('aria-label', _('Destinazione per ').$host['name'])->addOptions($options)->setValue($host['selected']);
    $hosts->addRow([$host['name'].' · ID '.$host['hostid'], array_map(static fn($group) => new CTag('div', true, $group['name']), $host['hostgroups']), $effect]);
}
$form->addItem($hosts);
$options = [];
foreach (['Non classificato', 'Informazione', 'Avviso', 'Media', 'Alta', 'Disastro'] as $id => $name) {
    $options[] = ['value' => $id, 'label' => $name];
}
$form->addItem(new CTag('p', true, _('Severità native Zabbix, 24/7:')))
    ->addItem((new CCheckBoxList('severities'))->setOptions($options)->setChecked($data['severities'])->setColumns(3))
    ->addItem(new CTag('p', true, (new CCheckBox('confirm', '1'))->setLabel(_('Confermo aggiornamento delle risorse gestite e attivazione per queste associazioni. Nessuna associazione disabilita l’Action.'))))
    ->addItem(new CTag('p', true, _('Il salvataggio abilita Riconosci nei nuovi messaggi PROBLEM. Tutti i membri attuali del gruppo possono eseguire l’ACK nativo, con identità Telegram nel commento Zabbix e nell’audit. Il bot deve essere amministratore in ogni gruppo associato per verificarne i membri. Non servono permessi per eliminare messaggi o gestire utenti.')))
    ->addItem(new CTag('p', true, _('Il salvataggio ricalcola gli host. Prima di ogni nuovo PROBLEM il bridge legge le appartenenze correnti via API: un nuovo conflitto blocca l’invio. Riapri questa pagina per vederlo. I messaggi già inviati e i loro RECOVERY restano nella destinazione originale.')))
    ->addItem(makeFormFooter(new CSubmitButton(_('Anteprima'), 'operation', 'preview'), [
        new CSubmitButton(_('Salva e attiva routing'), 'operation', 'save')]));
$page->addItem($form)->show();
