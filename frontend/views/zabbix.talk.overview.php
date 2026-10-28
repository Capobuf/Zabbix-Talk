<?php

require __DIR__.'/partials/navigation.php';
zbx_add_post_js($this->readJsFile('zabbix.talk.overview.js.php'));
$status = $data['telegram'];
$bot = $status['bot'] ?? null;
$destination = $status['destination'] ?? null;
$test = $status['test'] ?? null;
$config = $data['notifications']['config'];
$ready = $data['notifications']['ready'];
$text = static fn(string $key): CSpan => (new CSpan($data['labels'][$key]))->setId('talk-label-'.$key);
$notification_form = static fn(string $id): CForm => (new CForm('post', 'zabbix.php?action=zabbix.talk.configure'))
    ->setId($id)->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('zabbix.talk.configure')))->removeId())
    ->addItem((new CVar('section', 'tests'))->removeId());
$body = (new CDiv($navigation))->addClass('talk-ui');
$body->addItem((new CDiv())->setId('talk-refresh-feedback')->setAttribute('role', 'status')->addClass('talk-feedback'));
$page = (new CHtmlPage())->setTitle(_('Zabbix Talk').' · '.$sections[$current_section])
    ->setControls((new CTag('nav', true, new CList([$refresh(_('Aggiorna stato'))])))
        ->setAttribute('aria-label', _('Controlli della pagina')));

if ($current_section === 'overview') {
    $table = (new CTableInfo())->setHeader([_('Componente'), _('Stato'), _('Configurazione')])
        ->addRow([_('Bridge'), $text('bridge')->addClass($data['bridge_reachable'] ? ZBX_STYLE_GREEN : ZBX_STYLE_RED),
            new CLink(_('Diagnostica'), $url('settings'))])
        ->addRow([_('Bot Telegram'), $text('bot'), new CLink(_('Configura bot'), $url('settings'))])
        ->addRow([_('Ricezione comandi'), $text('polling'), new CLink(_('Verifica connessione'), $url('settings'))])
        ->addRow([_('Destinazioni registrate'), $text('destinations_count'),
            new CLink(_('Gestisci destinazioni'), $url('destinations'))])
        ->addRow([_('Conflitti di destinazione'), $text('conflicts')->addClass(!empty($data['conflicts']) ? ZBX_STYLE_RED : ''),
            new CLink(_('Esamina associazioni'), $url('destinations')->getUrl().'#talk-conflicts')])
        ->addRow([_('Notifiche'), $text('notification_summary'), new CLink(_('Configura notifiche'), $url('notifications'))]);
    $body->addItem($section(_('Stato generale'), $table));
    $body->addItem((new CDiv($text('diagnostics')))->addClass('talk-feedback')->addClass(ZBX_STYLE_RED)->setAttribute('role', 'alert'));
}
elseif ($current_section === 'settings') {
    $body->addItem($section(_('Bot Telegram'), $update_form('talk-bot')->addItem(new CFormGrid([
        new CLabel(_('Bot')), new CFormField($text('bot')),
        new CLabel(_('Ultima verifica')), new CFormField($text('verified')),
        new CLabel(_('Token del bot'), 'token'), new CFormField([
            (new CPassBox('token', '', 256))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
                ->setEnabled($data['secure_connection'] && $data['bridge_reachable']),
            $help(_('Sostituzione del token'), _('Il token salvato non viene mostrato. Inserirne uno di un altro bot azzera la destinazione selezionata e il test Telegram.'))
        ]),
        new CLabel(_('Connessione')), new CFormField((new CDiv([
            $button(_('Salva e verifica bot'), 'connect', $data['secure_connection'] && $data['bridge_reachable']),
            $button(_('Verifica connessione e permessi'), 'verify', $data['bridge_reachable'] && (bool) $bot)->addClass(ZBX_STYLE_BTN_ALT)
        ]))->addClass('talk-actions'))
    ]))));
    if (!$data['secure_connection']) {
        $body->addItem((new CDiv(_('Apri Zabbix tramite HTTPS per inserire e salvare il token del bot.')))
            ->addClass(ZBX_STYLE_RED)->setAttribute('role', 'alert'));
    }
    $body->addItem($section(_('Diagnostica bridge'), new CFormGrid([
        new CLabel(_('Bridge locale')), new CFormField($text('bridge')),
        new CLabel(_('Ricezione comandi')), new CFormField($text('polling')),
        new CLabel(_('Errori')), new CFormField((new CDiv($text('diagnostics')))->addClass(ZBX_STYLE_RED)->setAttribute('role', 'alert'))
    ])));
    $body->addItem($section(_('Prove di funzionamento'), new CTag('p', true,
        new CLink(_('Apri collaudi Telegram, Webhook e PROBLEM/RECOVERY'), $url('tests')))));
}
else {
    $options = [];
    foreach ($status['destinations'] ?? [] as $key => $entry) {
        $options[] = new CSelectOption($key, $destination_label($entry));
    }
    $select = (new CSelect('destination_key'))->setFocusableElementId('talk-destination-select')
        ->addOptions($options)->setValue($destination ? $destination['chat_id'].':'.($destination['message_thread_id'] ?? '') : '')
        ->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
    $body->addItem($section(_('Destinazione del collaudo'), $update_form('talk-select')->addItem(new CFormGrid([
        new CLabel(_('Destinazione Telegram'), 'talk-destination-select'), new CFormField($select),
        new CLabel(_('Selezione')), new CFormField($button(_('Usa per i collaudi'), 'select', $data['bridge_reachable'] && (bool) $destination))
    ]))->addItem(new CTag('p', true, new CLink(_('Collega un altro gruppo o topic'), $url('destinations'))))));
    $body->addItem((new CDiv($text('diagnostics')))->addClass('talk-feedback')->addClass(ZBX_STYLE_RED)->setAttribute('role', 'alert'));
    $body->addItem($section(_('1. Telegram · invio e modifica'), $update_form('talk-test')
        ->addItem(new CTag('p', true, _('Verifica il collegamento diretto con Telegram. Non genera eventi Zabbix.')))
        ->addItem(new CFormGrid([
            new CLabel(_('Ultimo test')), new CFormField($text('test')),
            new CLabel(_('Ultima modifica')), new CFormField($text('updated'))
        ]))->addItem((new CDiv([
            $button(_('Invia messaggio di prova'), 'send', $data['bridge_reachable'] && (bool) $destination),
            $button(_('Modifica lo stesso messaggio'), 'edit', $data['bridge_reachable'] && (bool) $test)->addClass(ZBX_STYLE_BTN_ALT)
        ]))->addClass('talk-actions'))
        ->addItem($help(_('Come verificare il risultato'), _('Controlla il messaggio TEST TRASPORTO in Telegram; la modifica deve aggiornare quello stesso messaggio. Un nuovo invio crea un altro test. Se la risposta è incerta, controlla Telegram prima di riprovare.')))));
    $body->addItem($section(_('2. Webhook · trasporto dal server Zabbix'), $notification_form('talk-transport')
        ->addItem(new CTag('p', true, _('Verifica Media Type → bridge → Telegram. Non verifica eventi, filtri o Action.')))
        ->addItem((new CDiv($button(_('Invia test Webhook'), 'transport', $ready)))->addClass('talk-actions'))));
    $body->addItem($section(_('3. Evento reale · PROBLEM e RECOVERY'), $notification_form('talk-notification-tests')
        ->addItem(new CTag('p', true, $text('notification_setup')))
        ->addItem((new CDiv([
            $button(_('Crea host di prova'), 'prepare_test', $ready && empty($config['fixture'])),
            $button(_('Genera PROBLEM'), 'problem_test', $ready && !empty($config['fixture'])),
            $button(_('Genera RECOVERY'), 'recovery_test', $ready && !empty($config['recovery_enabled']) && !empty($config['fixture'])),
            $button(_('Rimuovi host di prova'), 'remove_test', !empty($config['fixture']))->addClass(ZBX_STYLE_BTN_ALT)
                ->setAttribute('onclick', 'return confirm('.json_encode(_('Rimuovere host, item, trigger e storico della prova? Genera prima il RECOVERY per verificare il ripristino.')).');')
        ]))->addClass('talk-actions'))
        ->addItem($help(_('Procedura e precauzioni'), new CTag('ol', true, [
            new CTag('li', true, _('Crea l’host isolato nel gruppo configurato. Usa la severità massima abilitata e può attivare altre Action applicabili al gruppo.')),
            new CTag('li', true, _('Attendi circa un minuto per la configurazione del server, poi genera il PROBLEM. Controlla Telegram e aggiorna lo stato: un valore accettato non conferma la consegna.')),
            new CTag('li', true, _('Per provare l’ACK premi Riconosci in Telegram dal tuo account membro del gruppo. Verifica evento e audit nei dettagli.')),
            new CTag('li', true, _('Per lo snooze scegli Sospendi nel PROBLEM Telegram. La sospensione vale per quell’incidente nella chat/topic, per tutti i membri. Per una durata personalizzata rispondi all’originale con /snooze@nomebot 1m (oppure 3h, 2d). Alla scadenza arriva un solo promemoria se Zabbix conferma il problema ancora aperto.')),
            new CTag('li', true, _('Genera il RECOVERY: il messaggio originale deve diventare RISOLTO. Alta/Disastro aggiungono un messaggio salvo snooze attivo; le severità 0–3 modificano soltanto l’originale.')),
            new CTag('li', true, _('Aggiorna lo stato e confronta le ricevute nei dettagli. Rimuovi l’host solo dopo il RECOVERY: la rimozione elimina anche lo storico della prova.'))
        ])))));
    $body->addItem($help(_('Diagnostica avanzata · eventi, ricevute e audit'), new CFormGrid([
        new CLabel(_('Evento di prova')), new CFormField($text('problem_test')),
        new CLabel(_('Ricevute del bridge')), new CFormField((new CDiv($text('notification_receipts')))->addClass('talk-diagnostics')),
        new CLabel(_('Operazioni Telegram')), new CFormField((new CDiv($text('ack_audit')))->addClass('talk-diagnostics')),
        new CLabel(_('Snooze per incidente e destinazione')), new CFormField((new CDiv($text('snoozes')))->addClass('talk-diagnostics')),
        new CLabel(_('Messaggio Telegram')), new CFormField((new CSpan($test ? 'message_id '.$test['message_id'] : '—'))->setId('talk-test-id'))
    ])));
}
$page->addItem($body)->show();
