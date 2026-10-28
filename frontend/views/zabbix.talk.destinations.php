<?php

require __DIR__.'/partials/navigation.php';
zbx_add_post_js($this->readJsFile('zabbix.talk.overview.js.php'));
zbx_add_post_js($this->readJsFile('zabbix.talk.destinations.js.php'));
$status = $data['telegram'];
$destination = $status['destination'] ?? null;
$bot = $status['bot'] ?? null;
$registration = $status['registration'] ?? null;
$body = (new CDiv($navigation))->addClass('talk-ui');
$body->addItem((new CDiv())->setId('talk-refresh-feedback')->setAttribute('role', 'status')->addClass('talk-feedback'));
if ($data['error']) {
    $body->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [], $data['error']));
}
if ($data['message']) {
    $body->addItem(makeMessageBox(ZBX_STYLE_MSG_GOOD, [], $data['message']));
}
$destinations_panel = (new CDiv())->setId('talk-panel-destinations');
$registered = (new CTableInfo())->setId('talk-registered')->setHeader([_('Gruppo Telegram'), _('Topic'), _('Selezione'), _('Azioni')])
    ->setNoDataMessage(_('Nessuna destinazione registrata. Collega un gruppo o topic con il comando Telegram.'));
foreach ($data['destinations'] as $key => $entry) {
    $selected = $destination && $key === $destination['chat_id'].':'.($destination['message_thread_id'] ?? '');
    $registered->addRow([
        [$entry['title'], $help(_('Dettagli tecnici'), 'chat_id '.$entry['chat_id'])],
        $entry['message_thread_id'] ? _('Topic').' '.$entry['message_thread_id'] : _('Generale'),
        $selected ? _('Per collaudi e nuovo topic') : '—',
        $update_form('talk-select-'.str_replace(['-', ':'], ['n', '-'], $key))
            ->addItem((new CVar('destination_key', $key))->removeId())
            ->addItem($button(_('Seleziona'), 'select')->addClass(ZBX_STYLE_BTN_LINK))
    ]);
}
$command = $registration ? '/zabbix_talk@'.($bot['username'] ?? '').' '.$registration['code'] : '';
$command_block = (new CDiv(new CFormGrid([
    new CLabel(_('Comando Telegram')), new CFormField([
        (new CTag('code', true, $command))->setId('talk-label-command')->addClass(ZBX_STYLE_WORDBREAK),
        (new CDiv([
            (new CSimpleButton(_('Copia comando')))->setId('talk-copy')->addClass(ZBX_STYLE_BTN_ALT),
            $refresh(_('Ho inviato il comando: aggiorna collegamento'))
        ]))->addClass('talk-actions'),
        (new CSpan())->setId('talk-copy-feedback')->setAttribute('role', 'status')
    ]),
    new CLabel(_('Scadenza')), new CFormField((new CSpan($registration ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $registration['expires']) : ''))->setId('talk-label-expires'))
])))->setId('talk-registration');
if (!$registration) { $command_block->setAttribute('hidden', 'hidden'); }
$linking = $section(_('Collega gruppo o topic'), [
    $update_form('talk-destination')->addItem($button(_('Genera comando di collegamento'), 'register', (bool) $bot)),
    $command_block,
    $help(_('Come collegare una destinazione'), new CTag('ol', true, [
        new CTag('li', true, _('Aggiungi il bot al gruppo con il permesso di inviare messaggi.')),
        new CTag('li', true, _('Genera e copia il comando, poi invialo nel gruppo o topic scelto dal tuo account amministratore Telegram, senza modalità anonima.')),
        new CTag('li', true, _('Il comando è monouso e scade dopo 10 minuti. Dopo l’invio aggiorna il collegamento: la destinazione sarà disponibile nelle associazioni.'))
    ])),
    $help(_('Crea un nuovo topic'), $update_form('talk-topic')->addItem(new CFormGrid([
        new CLabel(_('Gruppo selezionato')), new CFormField((new CSpan($destination ? $destination['title'] : _('Seleziona prima una destinazione registrata.')))->setId('talk-topic-group')),
        new CLabel(_('Nome del topic'), 'topic_name'), new CFormField((new CTextBox('topic_name'))->setAttribute('maxlength', 128)),
        new CLabel(_('Creazione')), new CFormField([
            $button(_('Crea topic nel gruppo selezionato'), 'create_topic', (bool) $destination),
            new CTag('p', true, _('Richiede un gruppo forum e il bot amministratore con permesso di gestire i topic. Per un topic esistente usa il comando di collegamento.'))
        ])
    ])))
]);
$destinations_panel->addItem($section(_('Destinazioni registrate'), (new CDiv($registered))->addClass('talk-table')))->addItem($linking);
$destinations_panel->addItem((new CDiv())->setId('talk-destination-feedback')->setAttribute('role', 'alert')->addClass('talk-feedback'));

// Both panels belong to this form: every save still submits the complete configuration.
$form = (new CForm('post', $url($current_section)->getUrl()))->setId('talk-routing')
    ->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('zabbix.talk.destinations')))->removeId())
    ->addItem(new CVar('section', $current_section));
$table = (new CTableInfo())->setHeader([_('Gruppo host Zabbix'), _('Destinazione Telegram')]);
$available = (new CTableInfo())->setHeader([_('Gruppo host Zabbix'), _('Destinazione Telegram')]);
$table->setNoDataMessage(_('Nessuna associazione. Scegli un gruppo host da associare.'));
foreach ($data['groups'] as $group) {
    $options = [new CSelectOption('', _('Nessuna associazione'))];
    foreach ($data['destinations'] as $key => $entry) { $options[] = new CSelectOption($key, $destination_label($entry)); }
    $target = empty($data['mapping'][$group['groupid']]) ? $available : $table;
    $target->addRow([$group['name'], (new CSelect('mapping['.$group['groupid'].']'))
        ->setFocusableElementId('mapping-'.$group['groupid'])->setAttribute('aria-label', _('Destinazione per ').$group['name'])
        ->addOptions($options)->setValue($data['mapping'][$group['groupid']] ?? '')->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)]);
}
$routing_panel = (new CDiv())->setId('talk-routing-destinations')->addItem($section(_('Associazioni dei gruppi host'), [
    (new CDiv($table))->addClass('talk-table'),
    $help(_('Associa un altro gruppo host'), (new CDiv($available))->addClass('talk-table')),
    new CTag('p', true, _('Sono inclusi i sottogruppi. Nessuna associazione significa nessun invio.'))
]));
$conflicts = (new CTableInfo())->setHeader([_('Host'), _('Gruppi attuali'), _('Destinazione / scelta')])
    ->setNoDataMessage(_('Nessun conflitto tra gli host inclusi nell’anteprima.'));
$hosts = (new CTableInfo())->setHeader([_('Host'), _('Gruppi attuali'), _('Destinazione')])
    ->setNoDataMessage(_('Nessun host incluso. Scegli le associazioni e aggiorna l’anteprima.'));
$rendered_choices = [];
foreach ($data['hosts'] as $host) {
    $groups = array_map(static fn($group) => new CTag('div', true, $group['name']), $host['hostgroups']);
    if (count($host['candidates']) > 1) {
        $options = [new CSelectOption('', _('Conflitto: invio bloccato'))];
        foreach ($host['candidates'] as $key => $entry) { $options[] = new CSelectOption($key, $destination_label($entry)); }
        $effect = (new CSelect('choices['.$host['hostid'].']'))->setFocusableElementId('choice-'.$host['hostid'])
            ->setAttribute('aria-label', _('Destinazione per ').$host['name'])->addOptions($options)
            ->setValue($host['selected'])->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
        $conflicts->addRow([$host['name'], $groups, $effect]);
        $rendered_choices[$host['hostid']] = true;
    }
    else {
        $hosts->addRow([$host['name'], $groups, $destination_label(reset($host['candidates']))]);
    }
}
foreach ($data['choices'] as $hostid => $key) {
    if (!isset($rendered_choices[$hostid])) { $form->addItem((new CVar('choices['.$hostid.']', $key))->removeId()); }
}
$routing_panel->addItem($section(_('Conflitti e scelte per host'), [
    (new CDiv($conflicts))->addClass('talk-table'),
    new CTag('p', true, _('Per gli host con destinazioni diverse scegli una candidata. Finché il conflitto resta irrisolto, l’invio è bloccato.'))
])->setId('talk-conflicts'));
$routing_panel->addItem($help(_('Anteprima degli altri host inclusi').' ('.(count($data['hosts']) - count($rendered_choices)).')',
    (new CDiv($hosts))->addClass('talk-table')));
$routing_panel->addItem((new CDiv(new CSubmitButton(_('Aggiorna anteprima host'), 'operation', 'preview')))->addClass('talk-actions'));

$options = [];
foreach (['Non classificato', 'Informazione', 'Avviso', 'Media', 'Alta', 'Disastro'] as $id => $name) {
    $options[] = ['value' => $id, 'label' => $name];
}
$notifications_panel = (new CDiv())->setId('talk-panel-notifications');
$notifications_panel->addItem($section(_('Politica notifiche'), new CFormGrid([
    new CLabel(_('Periodo')), new CFormField(_('Sempre · 24 ore su 24, 7 giorni su 7')),
    new CLabel(_('Severità abilitate')), new CFormField((new CCheckBoxList('severities'))
        ->setOptions($options)->setChecked($data['severities'])->setColumns(3)),
    new CLabel(_('Ripristino')), new CFormField(_('Alta e Disastro: aggiorna il messaggio e invia il ripristino. Altre severità: aggiorna soltanto il messaggio.')),
    new CLabel(_('Riconoscimento')), new CFormField(_('ACK nativo dai nuovi messaggi PROBLEM, disponibile ai membri attuali dei gruppi associati.'))
])));
$resources = (new CTableInfo())->setHeader([_('Risorsa gestita'), _('Stato')]);
foreach (['media' => _('Media Type Webhook'), 'action' => _('Action PROBLEM/RECOVERY'), 'user' => _('Destinatario tecnico'),
    'group' => _('Gruppo utenti'), 'role' => _('Ruolo tecnico'), 'macro' => _('Macro segreta del canale')] as $key => $caption) {
    $resources->addRow([$caption, $data['notifications']['resources'][$key]
        ?? ($data['error'] ? _('Non disponibile') : _('Non configurata'))]);
}
$notifications_panel->addItem($section(_('Risorse Zabbix'), [
    new CTag('p', true, $data['notifications']['message']), $resources
]));
$ids = (new CTableInfo())->setHeader([_('Risorsa'), _('ID Zabbix')]);
foreach ($data['notifications']['config']['ids'] ?? [] as $resource => $id) { $ids->addRow([$resource, $id]); }
$notifications_panel->addItem($help(_('Identificativi delle risorse gestite'), $ids));
if ($current_section === 'notifications') {
    $destinations_panel->setAttribute('hidden', 'hidden');
    $routing_panel->setAttribute('hidden', 'hidden');
}
else { $notifications_panel->setAttribute('hidden', 'hidden'); }
$form->addItem([$routing_panel, $notifications_panel]);
$form->addItem((new CDiv([
    new CTag('p', true, _('Il salvataggio applica insieme associazioni, scelte per host e severità, aggiornando le risorse Zabbix gestite.')),
    (new CCheckBox('confirm', '1'))->setLabel(_('Confermo l’aggiornamento delle risorse e l’attivazione delle notifiche. Senza associazioni l’Action viene disabilitata.')),
    new CTag('p', true, _('Per gli ACK il bot deve essere amministratore in ogni gruppo associato.')),
    makeFormFooter(new CSubmitButton(_('Salva destinazioni e notifiche'), 'operation', 'save')),
    (new CSpan())->setId('talk-unsaved')->setAttribute('role', 'status')
]))->addClass('talk-save'));
$body->addItem([$destinations_panel, $form, (new CTag('template', true,
    $update_form('talk-select-template-form')->addItem((new CVar('destination_key', ''))->removeId())
        ->addItem($button(_('Seleziona'), 'select')->addClass(ZBX_STYLE_BTN_LINK))))
        ->setId('talk-select-template')]);
(new CHtmlPage())->setTitle(_('Zabbix Talk').' · '.$sections[$current_section])->addItem($body)->show();
