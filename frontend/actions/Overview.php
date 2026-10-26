<?php

namespace Modules\ZabbixTalk\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ZabbixTalk\BridgeClient;
use RuntimeException;

require_once __DIR__.'/../includes/BridgeClient.php';

class Overview extends CController {
    public function init(): void {
        $this->disableCsrfValidation();
        header('Cache-Control: no-store');
    }

    protected function checkInput(): bool {
        return true;
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
            'bridge_reachable' => $bridge_error === '',
            'secure_connection' => ($_SERVER['HTTPS'] ?? '') === 'on',
            'telegram' => $status,
            'labels' => [
                'bridge' => $bridge_error === '' ? _('Bridge raggiungibile') : _('Bridge non raggiungibile'),
                'bot' => $bot ? '@'.($bot['username'] ?? '').' · '.($bot['first_name'] ?? '').' · ID '.$bot['id']
                    : _('Non configurato: inserisci il token del bot dedicato.'),
                'polling' => $bot ? (($status['polling'] ?? false) ? _('Long polling attivo')
                    : _('In attesa di ricezione: aggiorna lo stato.')) : _('Collega prima il bot.'),
                'verified' => isset($status['verified_at'])
                    ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $status['verified_at']) : _('Non ancora verificato'),
                'destination' => $destination ? $destination['title'].' · chat_id '.$destination['chat_id']
                    : _('Nessun gruppo o topic collegato.'),
                'topic' => $destination ? ($destination['message_thread_id']
                    ? 'message_thread_id '.$destination['message_thread_id'] : _('Gruppo senza topic / topic Generale'))
                    : _('Non collegato'),
                'command' => $registration ? '/zabbix_talk@'.($bot['username'] ?? '').' '.$registration['code'] : '',
                'expires' => $registration ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $registration['expires']) : '',
                'test' => $test ? 'message_id '.$test['message_id'].' · '._('Revisione').' '.$test['revision']
                    : _('Nessun messaggio di prova inviato.'),
                'updated' => $test ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $test['updated_at']) : '—',
                'diagnostics' => implode(' ', array_filter([
                    $bridge_error, $status['poll_error'] ?? '', $status['registration_error'] ?? ''
                ]))
            ]
        ];
        if ($bridge_error !== '') {
            foreach (['bot', 'polling', 'verified', 'destination', 'topic', 'test', 'updated'] as $label) {
                $data['labels'][$label] = _('Stato non disponibile: verifica il bridge.');
            }
        }
        $this->setResponse(new CControllerResponseData($this->getAction() === 'zabbix.talk.status'
            ? ['main_block' => json_encode($data)] : $data));
    }
}
