<?php

namespace Modules\ZabbixTalk\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ZabbixTalk\BridgeClient;
use RuntimeException;
use Throwable;
use Modules\ZabbixTalk\Notifications;

require_once __DIR__.'/../includes/BridgeClient.php';
require_once __DIR__.'/../includes/Notifications.php';

class Overview extends CController {
    public function init(): void {
        $this->disableCsrfValidation();
        header('Cache-Control: no-store');
    }

    protected function checkInput(): bool {
        return $this->validateInput(['section' => 'in overview,settings,tests']);
    }

    protected function checkPermissions(): bool {
        return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        $status = [];
        $bridge_error = '';
        try {
            $status = BridgeClient::request('/status');
        }
        catch (RuntimeException $error) {
            $bridge_error = $error->getMessage();
        }
        $bot = $status['bot'] ?? null;
        $destination = $status['destination'] ?? null;
        $test = $status['test'] ?? null;
        $registration = $status['registration'] ?? null;
        $data = [
            'section' => $this->getInput('section', 'overview'),
            'bridge_reachable' => $bridge_error === '',
            'secure_connection' => ($_SERVER['HTTPS'] ?? '') === 'on',
            'telegram' => $status,
            'labels' => [
                'bridge' => $bridge_error === '' ? _('Bridge raggiungibile') : _('Bridge non raggiungibile'),
                'bot' => $bot ? '@'.($bot['username'] ?? '').' · '.($bot['first_name'] ?? '')
                    : _('Non configurato'),
                'polling' => $bot ? (($status['polling'] ?? false) ? _('Attiva')
                    : _('In attesa: aggiorna lo stato')) : _('Bot non configurato'),
                'verified' => isset($status['verified_at'])
                    ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $status['verified_at']) : _('Non ancora verificato'),
                'destination' => $destination ? $destination['title']
                    : _('Nessun gruppo o topic collegato.'),
                'topic' => $destination ? ($destination['message_thread_id']
                    ? _('Topic').' '.$destination['message_thread_id'] : _('Gruppo senza topic / topic Generale'))
                    : _('Non collegato'),
                'command' => $registration ? '/zabbix_talk@'.($bot['username'] ?? '').' '.$registration['code'] : '',
                'expires' => $registration ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $registration['expires']) : '',
                'test' => $test ? _('Messaggio inviato').' · '._('Revisione').' '.$test['revision']
                    : _('Nessun messaggio di prova inviato.'),
                'updated' => $test ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $test['updated_at']) : '—',
                'destinations_count' => $bridge_error === '' ? (string) count($status['destinations'] ?? []) : _('Non disponibili'),
                'conflicts' => _('Non disponibili'),
                'diagnostics' => implode(' ', array_filter([
                    $bridge_error, $status['poll_error'] ?? '', $status['registration_error'] ?? ''
                ]))
            ]
        ];
        $audit = [];
        foreach ($status['audit'] ?? [] as $entry) {
            $audit[] = zbx_date2str(DATE_TIME_FORMAT_SECONDS, $entry['created_at']).' · '.$entry['operation']
                .' · eventid '.($entry['eventid'] ?: '—').' · '.$entry['actor']
                .' · chat_id '.$entry['chat_id'].($entry['thread_id'] ? ' · topic '.$entry['thread_id'] : '')
                .' · '.$entry['detail'];
        }
        $data['labels']['ack_audit'] = $bridge_error !== '' ? _('Audit non disponibile: verifica il bridge.')
            : ($audit ? implode("\n", $audit) : _('Nessuna operazione Telegram registrata.'));
        $snoozes = [];
        foreach ($status['snoozes'] ?? [] as $entry) {
            $snoozes[] = 'eventid '.$entry['eventid'].' · chat_id '.$entry['chat_id']
                .($entry['thread_id'] ? ' · topic '.$entry['thread_id'] : '').' · '.$entry['actor']
                .' · '._('Scadenza').' '.zbx_date2str(DATE_TIME_FORMAT_SECONDS, $entry['until_at'])
                .' · '.$entry['detail'].($entry['reminder_id'] ? ' · message_id '.$entry['reminder_id'] : '');
        }
        $data['labels']['snoozes'] = $bridge_error !== '' ? _('Snooze non disponibili: verifica il bridge.')
            : ($snoozes ? implode("\n", $snoozes) : _('Nessuno snooze registrato.'));
        if ($bridge_error !== '') {
            foreach (['bot', 'polling', 'verified', 'destination', 'topic', 'test', 'updated'] as $label) {
                $data['labels'][$label] = _('Stato non disponibile: verifica il bridge.');
            }
        }
        try {
            $notifications = new Notifications();
            $data['notifications'] = $notifications->status($status);
            $data['labels']['notification_setup'] = $data['notifications']['message'];
            $data['labels']['notification_summary'] = $data['notifications']['ready']
                ? _('Pronte per l’invio') : $data['notifications']['message'];
            $data['labels']['problem_test'] = $notifications->testStatus();
            $receipts = [];
            foreach ($status['notifications'] ?? [] as $receipt) {
                $receipts[] = (['transport' => 'TEST TRASPORTO', 'problem' => 'PROBLEM', 'recovery' => 'RECOVERY'][$receipt['kind']] ?? $receipt['kind'])
                    .' · eventid '.$receipt['eventid'].' · '.$receipt['host'].' · '
                    .(['sent' => _('Confermato'), 'sending' => _('Invio in corso o interrotto'),
                        'editing' => _('Modifica in corso o interrotta'),
                        'unconfirmed' => _('Operazione non confermata')][$receipt['status']] ?? $receipt['status'])
                    .($receipt['message_id'] ? ' · message_id '.$receipt['message_id'] : '')
                    .(!empty($receipt['recovery_eventid']) ? ' · recovery eventid '.$receipt['recovery_eventid'] : '')
                    .(!empty($receipt['recovery_message_id']) ? ' · nuovo message_id '.$receipt['recovery_message_id'] : '')
                    .' · chat_id '.$receipt['chat_id'].($receipt['thread_id'] ? ' · topic '.$receipt['thread_id'] : '')
                    .' · '.zbx_date2str(DATE_TIME_FORMAT_SECONDS, $receipt['updated_at']).' · '.$receipt['detail'];
            }
            $data['labels']['notification_receipts'] = $bridge_error !== '' ? _('Ricevute non disponibili: verifica il bridge.')
                : ($receipts ? implode("\n", $receipts) : _('Nessun evento ricevuto dal bridge.'));
        }
        catch (Throwable $error) {
            $data['notifications'] = ['config' => [], 'ready' => false];
            $data['labels']['notification_setup'] = $error->getMessage();
            $data['labels']['notification_summary'] = $error->getMessage();
            $data['labels']['problem_test'] = _('Stato del test non disponibile.');
            $data['labels']['notification_receipts'] = _('Diagnostica non disponibile.');
        }
        if (isset($notifications) && $data['section'] === 'overview') {
            try {
                $hosts = $notifications->preview($notifications->routes(), $notifications->config()['choices'] ?? []);
                $data['conflicts'] = count(array_filter($hosts, static fn($host) => $host['selected'] === ''));
                $data['labels']['conflicts'] = $data['conflicts'] ? $data['conflicts'].' '._('host: invio bloccato') : _('Nessuno');
            }
            catch (Throwable $error) { $data['labels']['conflicts'] = $error->getMessage(); }
        }
        $this->setResponse(new CControllerResponseData($this->getAction() === 'zabbix.talk.status'
            ? ['main_block' => json_encode($data)] : $data));
    }
}
