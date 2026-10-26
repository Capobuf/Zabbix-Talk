<?php

zbx_add_post_js($this->readJsFile('zabbix.talk.overview.js.php'));

$status = $data['telegram'];
$bot = $status['bot'] ?? null;
$destination = $status['destination'] ?? null;
$test = $status['test'] ?? null;
$registration = $status['registration'] ?? null;

$text = static function (string $key) use ($data): CSpan {
    return (new CSpan($data['labels'][$key]))->setId('talk-label-'.$key);
};
$form = static function (string $id): CForm {
    return (new CForm('post', (new CUrl('zabbix.php'))->setArgument('action', 'zabbix.talk.update')->getUrl()))
        ->setId($id)
        ->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('zabbix.talk.update')))->removeId());
};
$button = static function (string $label, string $operation, bool $enabled = true): CSubmitButton {
    return (new CSubmitButton($label, 'operation', $operation))->setEnabled($enabled);
};
$refresh = static function (string $label): CSimpleButton {
    return (new CSimpleButton($label))->setAttribute('data-talk-refresh', '1')->addClass(ZBX_STYLE_BTN_ALT);
};

$page = (new CHtmlPage())
    ->setTitle(_('Zabbix Talk'))
    ->setControls(
        (new CTag('nav', true, [(new CList())->addItem($refresh(_('Aggiorna stato')))]))
            ->setAttribute('aria-label', _('Controlli della pagina'))
    );
$page->addItem((new CDiv())->setId('talk-refresh-feedback')->setAttribute('role', 'status'));
$page->addItem((new CTableInfo())
    ->setHeader([_('Componente'), _('Stato')])
    ->addRow([_('Bridge locale'), $text('bridge')->addClass($data['bridge_reachable'] ? ZBX_STYLE_GREEN : ZBX_STYLE_RED)])
    ->addRow([_('Bot Telegram'), $text('bot')])
    ->addRow([_('Ricezione comandi'), $text('polling')])
    ->addRow([_('Ultima verifica del bot'), $text('verified')])
);
$page->addItem((new CDiv($text('diagnostics')))->addClass(ZBX_STYLE_RED)->setAttribute('role', 'alert'));

$bot_fields = new CFormGrid([
    new CLabel(_('Token del bot'), 'token'),
    new CFormField([
        (new CPassBox('token', '', 256))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
            ->setEnabled($data['secure_connection'] && $data['bridge_reachable']),
        new CTag('p', true, _('Inserisci il token del bot dedicato e premi Salva e verifica bot. Per sostituirlo, inserisci il nuovo token. Il token salvato non viene mostrato.'))
    ])
]);
if (!$data['secure_connection']) {
    $page->addItem(new CTag('p', true, _('Apri Zabbix tramite HTTPS per inserire e salvare il token del bot.')));
}
$page->addItem($form('talk-bot')->addItem(
    (new CTabView(['id' => 'talk-bot-tabs']))
        ->addTab('talk-bot-tab', _('Bot Telegram'), [new CTag('h2', true, _('1. Collega il bot')), $bot_fields])
        ->setFooter(makeFormFooter($button(_('Salva e verifica bot'), 'connect', $data['secure_connection'] && $data['bridge_reachable']), [
            $button(_('Verifica collegamento e permessi'), 'verify', (bool) $bot)->addClass(ZBX_STYLE_BTN_ALT)
        ]))
));

$command_fields = new CFormGrid([
    new CLabel(_('Comando da inviare')),
    new CFormField([
        (new CTag('code', true, $text('command')))->addClass(ZBX_STYLE_WORDBREAK),
        BR(),
        (new CSimpleButton(_('Copia comando')))->setId('talk-copy')->addClass(ZBX_STYLE_BTN_ALT),
        (new CSpan())->setId('talk-copy-feedback')->setAttribute('role', 'status')
    ]),
    new CLabel(_('Valido fino a')),
    new CFormField($text('expires')),
    new CLabel(_('Dopo l’invio in Telegram')),
    new CFormField($refresh(_('Ho inviato il comando: aggiorna collegamento')))
]);
$command_block = (new CDiv($command_fields))->setId('talk-registration');
if (!$registration) {
    $command_block->setAttribute('hidden', 'hidden');
}
$destination_fields = new CFormGrid([
    new CLabel(_('Gruppo Telegram')),
    new CFormField($text('destination')),
    new CLabel(_('Topic')),
    new CFormField($text('topic')),
    new CLabel(_('Come collegare la destinazione')),
    new CFormField(new CTag('ol', true, [
        new CTag('li', true, _('Aggiungi il bot al gruppo e consentigli di inviare messaggi.')),
        new CTag('li', true, _('Premi Genera comando di collegamento, poi Copia comando.')),
        new CTag('li', true, _('Incolla e invia il comando in Telegram, nel gruppo o nel topic scelto, dal tuo account amministratore.')),
        new CTag('li', true, _('Torna qui e premi Ho inviato il comando: aggiorna collegamento. Compariranno gruppo e topic; poi passa alla prova del punto 3.'))
    ])),
    new CLabel(_('Destinazione corrente')),
    new CFormField(_('Un nuovo collegamento sostituisce la destinazione corrente e azzera il test se scegli un gruppo/topic diverso. Il comando scade dopo 10 minuti ed è monouso.'))
]);
$page->addItem($form('talk-destination')->addItem(
    (new CTabView(['id' => 'talk-destination-tabs']))
        ->addTab('talk-destination-tab', _('Destinazione Telegram'), [
            new CTag('h2', true, _('2. Collega il gruppo o il topic')), $destination_fields, $command_block
        ])
        ->setFooter(makeFormFooter($button(_('Genera comando di collegamento'), 'register', (bool) $bot)))
));

$test_fields = new CFormGrid([
    new CLabel(_('Come provare il collegamento')),
    new CFormField(_('Premi Invia notifica di prova e controlla il messaggio «TEST Zabbix Talk» in Telegram. Poi premi Aggiorna notifica di prova: cambierà quello stesso messaggio, mantenendo il message_id. Un altro invio crea un nuovo test.')),
    new CLabel(_('Ultimo messaggio di prova')),
    new CFormField($text('test')),
    new CLabel(_('Ultima modifica')),
    new CFormField($text('updated'))
]);
$page->addItem($form('talk-test')->addItem(
    (new CTabView(['id' => 'talk-test-tabs']))
        ->addTab('talk-test-tab', _('Notifica di prova'), [new CTag('h2', true, _('3. Invia e aggiorna la prova')), $test_fields])
        ->setFooter(makeFormFooter($button(_('Invia notifica di prova'), 'send', (bool) $destination), [
            $button(_('Aggiorna notifica di prova'), 'edit', (bool) $test)->addClass(ZBX_STYLE_BTN_ALT)
        ]))
));
$page->show();
