<?php

namespace Modules\ZabbixTalk;

use RuntimeException;

class BridgeClient {
    public static function request(string $path, ?array $data = null): array {
        $request = curl_init('http://localhost'.$path);
        curl_setopt_array($request, [
            CURLOPT_UNIX_SOCKET_PATH => '/run/zabbix-talk/control.sock',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT_MS => 300,
            CURLOPT_TIMEOUT => $data === null ? 2 : 45
        ]);
        if ($data !== null) {
            curl_setopt_array($request, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($data)
            ]);
        }
        $body = curl_exec($request);
        $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        curl_close($request);
        $result = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($result)) {
            throw new RuntimeException(_('Il bridge non risponde. Verifica il servizio zabbix-talk; se un test era in corso, controlla Telegram prima di ripeterlo.'));
        }
        if ($status !== 200) {
            throw new RuntimeException($result['error'] ?? _('Errore del bridge locale.'));
        }
        return $result;
    }
}
