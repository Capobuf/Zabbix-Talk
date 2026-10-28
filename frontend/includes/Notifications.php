<?php

namespace Modules\ZabbixTalk;

use API;
use CSessionHelper;
use CSettingsHelper;
use CZabbixServer;
use RuntimeException;
use Throwable;

require_once __DIR__.'/BridgeClient.php';

class Notifications {
    private array $module;
    private string $routing_revision = '';
    private string $routing_token = '';

    public function __construct() {
        $modules = self::ok(API::Module()->get(['output' => ['moduleid', 'config'], 'filter' => ['id' => 'zabbix-talk']]));
        if (count($modules) !== 1) {
            throw new RuntimeException(_('Registrazione del modulo non disponibile.'));
        }
        $this->module = $modules[0];
    }

    private static function ok($result): array {
        if ($result === false) {
            throw new RuntimeException(_('Zabbix ha rifiutato l’operazione. Consulta il dettaglio dei permessi o dei parametri.'));
        }
        return $result;
    }

    public function config(): array {
        return $this->module['config']['notifications'] ?? [];
    }

    private function save(array $config): void {
        $all = $this->module['config'];
        $all['notifications'] = $config;
        self::ok(API::Module()->update(['moduleid' => $this->module['moduleid'], 'config' => $all]));
        $this->module['config'] = $all;
    }

    private function resources(): array {
        $config = $this->config();
        $result = [];
        $requests = [
            'macro' => ['usermacro', 'globalmacroid', ['output' => ['globalmacroid', 'macro', 'type', 'description'],
                'globalmacro' => true]],
            'role' => ['role', 'roleid', ['output' => 'extend', 'selectRules' => 'extend']],
            'group' => ['usergroup', 'usrgrpid', ['output' => 'extend', 'selectHostGroupRights' => 'extend',
                'selectTemplateGroupRights' => 'extend', 'selectTagFilters' => 'extend', 'selectUsers' => ['userid']]],
            'user' => ['user', 'userid', ['output' => ['userid', 'username', 'roleid'],
                'selectUsrgrps' => ['usrgrpid'], 'selectMedias' => 'extend']],
            'media' => ['mediatype', 'mediatypeid', ['output' => 'extend', 'selectMessageTemplates' => 'extend']],
            'action' => ['action', 'actionid', ['output' => 'extend', 'selectFilter' => 'extend',
                'selectOperations' => 'extend', 'selectRecoveryOperations' => 'extend', 'selectUpdateOperations' => 'extend']]
        ];
        foreach ($requests as $key => [$api, $id, $options]) {
            $result[$key] = isset($config['ids'][$key])
                ? (self::ok(API::getApi($api)->get($options + [$id.'s' => [$config['ids'][$key]]]))[0] ?? null) : null;
        }
        return $result;
    }

    private static function canonical($value) {
        if (!is_array($value)) {
            return (string) $value;
        }
        $value = array_map([self::class, 'canonical'], $value);
        if (array_is_list($value)) {
            usort($value, static fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
        }
        else {
            ksort($value);
        }
        return $value;
    }

    private function changes(array $resources): array {
        $changed = [];
        foreach ($resources as $key => $resource) {
            if ($resource !== null && isset($this->config()['observed'][$key])
                    && self::canonical($resource) !== self::canonical($this->config()['observed'][$key])) {
                $changed[] = $key;
            }
        }
        return $changed;
    }

    public function status(?array $telegram = null): array {
        $config = $this->config();
        if (!$config) {
            return ['config' => [], 'message' => _('Notifiche reali non configurate.'), 'ready' => false];
        }
        $resources = $this->resources();
        $changed = $this->changes($resources);
        $missing = array_keys(array_filter($resources, static fn($resource) => $resource === null));
        $telegram = $telegram ?? BridgeClient::request('/status');
        $destination = $telegram['destination'] ?? [];
        $same_telegram = (string) ($telegram['bot']['id'] ?? '') === (string) $config['bot_id']
            && (string) ($destination['chat_id'] ?? '') === (string) $config['destination']['chat_id']
            && (string) ($destination['message_thread_id'] ?? '') === (string) ($config['destination']['message_thread_id'] ?? '');
        if (isset($config['routes'])) {
            $same_telegram = (string) ($telegram['bot']['id'] ?? '') === (string) $config['bot_id']
                && ($telegram['routing_revision'] ?? '') === $config['routing_revision'];
        }
        $enabled = !$missing && $resources['action']['status'] == ACTION_STATUS_ENABLED;
        if (!$same_telegram) {
            $message = _('Bot o routing non sincronizzati. Apri Destinazioni e salva nuovamente prima di ricevere nuovi PROBLEM.');
        }
        elseif ($changed) {
            $message = _('Modifiche esterne rilevate; salvataggio bloccato: ').implode(', ', $changed);
        }
        elseif ($missing) {
            $message = _('Risorse mancanti: ').implode(', ', $missing)._('. Conferma nuovamente il setup per ricrearle.');
        }
        else {
            $message = $enabled ? (empty($config['recovery_enabled'])
                ? _('Salva e attiva notifiche per abilitare anche i RECOVERY sugli eventi successivi.')
                : _('Action PROBLEM/RECOVERY attiva e risorse sincronizzate.')) : _('Action disabilitata.');
            $message .= !empty($telegram['ack_enabled']) ? _(' ACK da Telegram abilitati.')
                : _(' Salva nuovamente le destinazioni per abilitare gli ACK da Telegram.');
        }
        return ['config' => array_diff_key($config, ['observed' => true]),
            'ready' => $same_telegram && !$changed && $enabled, 'message' => $message];
    }

    private function transaction(callable $work) {
        DBstart();
        try {
            // Serialize setup requests using the module row, including the first provisioning.
            DBselect('SELECT moduleid FROM module WHERE moduleid='.zbx_dbstr($this->module['moduleid']).' FOR UPDATE');
            $this->module = self::ok(API::Module()->get(['output' => ['moduleid', 'config'],
                'moduleids' => [$this->module['moduleid']]]))[0];
            $result = $work();
            if (!DBend(true)) {
                throw new RuntimeException(_('Salvataggio Zabbix non confermato. Riapri la pagina prima di riprovare.'));
            }
            return $result;
        }
        catch (Throwable $error) {
            global $DB;
            if ($DB['TRANSACTIONS'] > 0) {
                DBend(false);
            }
            throw $error;
        }
    }

    private function put(string $key, $api, string $id, array $desired, array $existing, array &$config): void {
        if ($existing[$key] !== null) {
            $method = $key === 'macro' ? 'updateGlobal' : 'update';
            self::ok($api->$method([$id => $config['ids'][$key]] + $desired));
        }
        else {
            $method = $key === 'macro' ? 'createGlobal' : 'create';
            $created = self::ok($api->$method($desired));
            $config['ids'][$key] = $created[$id.'s'][0];
        }
    }

    private function parameters(array $destination, array $bot): array {
        $values = ['channel_key' => '{$ZABBIX_TALK_CHANNEL_KEY}', 'kind' => '{ALERT.MESSAGE}', 'eventid' => '{EVENT.ID}',
            'event_value' => '{EVENT.VALUE}', 'hostid' => '{HOST.ID}', 'host' => '{HOST.NAME}',
            'problem' => '{EVENT.NAME}', 'severity' => '{EVENT.NSEVERITY}', 'severity_name' => '{EVENT.SEVERITY}',
            'duration' => '{EVENT.DURATION}',
            'recovery_eventid' => '{EVENT.RECOVERY.ID}', 'recovery_value' => '{EVENT.RECOVERY.VALUE}',
            'recovered_at' => '{EVENT.RECOVERY.DATE} {EVENT.RECOVERY.TIME}',
            'occurred_at' => '{EVENT.DATE} {EVENT.TIME}', 'bot_id' => (string) $bot['id'],
            'chat_id' => (string) $destination['chat_id'],
            'message_thread_id' => $destination['message_thread_id'] ? (string) $destination['message_thread_id'] : '',
            'routing_revision' => $this->routing_revision ?? ''];
        $parameters = [];
        foreach ($values as $name => $value) {
            $parameters[] = ['name' => $name, 'value' => $value];
        }
        return $parameters;
    }

    public function provision(string $groupid, array $severities, bool $confirmed, ?array $routes = null, array $choices = []): string {
        if (!$confirmed) {
            throw new RuntimeException(_('Conferma la creazione/aggiornamento delle risorse e l’attivazione dell’Action.'));
        }
        foreach ($severities as $severity) {
            if ((!is_int($severity) && !is_string($severity)) || !preg_match('/^[0-5]$/D', (string) $severity)) {
                throw new RuntimeException(_('Seleziona soltanto severità numeriche da 0 a 5.'));
            }
        }
        $severities = array_values(array_unique($severities));
        if (!$severities || array_diff($severities, range(0, 5))) {
            throw new RuntimeException(_('Seleziona almeno una severità valida.'));
        }
        $groupids = $routes === null ? [$groupid] : array_column($routes, 'groupid');
        $groups = $groupids ? self::ok(API::HostGroup()->get(['output' => ['groupid', 'name'], 'groupids' => $groupids])) : [];
        if (count($groups) !== count($groupids)) {
            throw new RuntimeException(_('Scegli un gruppo host accessibile. Nessuna Action è stata attivata.'));
        }
        $state = BridgeClient::request('/status');
        if (empty($state['bot']) || empty($state['destination'])) {
            throw new RuntimeException(_('Collega prima bot e destinazione Telegram.'));
        }
        BridgeClient::request('/action', ['operation' => 'verify']);
        $channel = BridgeClient::request('/channel', ['operation' => 'channel']);
        $routes = $routes ?? [['groupid' => $groupid, 'group_name' => $groups[0]['name'], 'destination' => $state['destination']]];
        $this->preview($routes, $choices);
        BridgeClient::request('/ack-permissions', ['destinations' => array_values(array_unique(array_map(
            static fn($route) => $route['destination']['chat_id'].':'.($route['destination']['message_thread_id'] ?? ''), $routes)))]);
        $rights = [];
        foreach (self::ok(API::HostGroup()->get(['output' => ['groupid', 'name']])) as $group) {
            foreach ($routes as $route) {
                if ($group['groupid'] == $route['groupid'] || str_starts_with($group['name'], $route['group_name'].'/')) {
                    $rights[$group['groupid']] = ['id' => $group['groupid'], 'permission' => PERM_READ];
                }
            }
        }
        $rights = array_values($rights);
        $this->routing_revision = bin2hex(random_bytes(16));
        $message = $this->transaction(function () use ($rights, $groupid, $groupids, $routes, $choices, $severities, $groups, $state, $channel) {
            $config = $this->config();
            $existing = $this->resources();
            if ($this->changes($existing)) {
                throw new RuntimeException(_('Risorse modificate esternamente: nessuna sovrascrittura. Ripristina la configurazione gestita prima di salvare.'));
            }
            if ($existing['action']) {
                self::ok(API::Action()->update(['actionid' => $config['ids']['action'], 'status' => ACTION_STATUS_DISABLED]));
            }
            $this->put('macro', API::UserMacro(), 'globalmacroid', [
                'macro' => '{$ZABBIX_TALK_CHANNEL_KEY}', 'type' => ZBX_MACRO_TYPE_SECRET,
                'value' => $channel['key'], 'description' => 'Managed by Zabbix Talk. Local Webhook authentication.'
            ], $existing, $config);
            $this->put('role', API::Role(), 'roleid', [
                'name' => 'Zabbix Talk - Notify', 'type' => USER_TYPE_ZABBIX_USER,
                'rules' => ['ui.default_access' => 0, 'ui' => [['name' => 'monitoring.problems', 'status' => 1]],
                    'api.access' => 1, 'api.mode' => ZBX_ROLE_RULE_API_MODE_ALLOW,
                    'api' => ['host.get', 'hostgroup.get', 'event.get', 'event.acknowledge'],
                    'actions.default_access' => 0, 'actions' => [
                        ['name' => 'acknowledge_problems', 'status' => 1],
                        ['name' => 'add_problem_comments', 'status' => 1]
                    ], 'modules.default_access' => 0]
            ], $existing, $config);
            // Keep previously granted read scope so native RECOVERY can reach the original recipient after rerouting.
            foreach ($existing['group']['hostgroup_rights'] ?? [] as $right) {
                if (!in_array($right['id'], array_column($rights, 'id'))) { $rights[] = $right; }
            }
            $this->put('group', API::UserGroup(), 'usrgrpid', [
                'name' => 'Zabbix Talk - Notify', 'gui_access' => GROUP_GUI_ACCESS_DISABLED,
                'users_status' => GROUP_STATUS_ENABLED,
                'hostgroup_rights' => $rights,
                'templategroup_rights' => [], 'tag_filters' => []
            ], $existing, $config);
            $this->put('media', API::MediaType(), 'mediatypeid', [
                'name' => 'Zabbix Talk - Webhook', 'type' => MEDIA_TYPE_WEBHOOK, 'status' => MEDIA_TYPE_STATUS_ACTIVE,
                'description' => 'Managed by Zabbix Talk. Local authenticated PROBLEM/RECOVERY transport.',
                'script' => file_get_contents(__DIR__.'/webhook.js'), 'timeout' => '30s',
                'maxattempts' => 1, 'maxsessions' => 1, 'process_tags' => 0, 'show_event_menu' => 0,
                'parameters' => $this->parameters($state['destination'], $state['bot']),
                'message_templates' => []
            ], $existing, $config);
            $user = ['username' => 'zabbix-talk-notify', 'roleid' => $config['ids']['role'],
                'usrgrps' => [['usrgrpid' => $config['ids']['group']]],
                'medias' => [['mediatypeid' => $config['ids']['media'], 'sendto' => ['local-bridge'],
                    'active' => MEDIA_STATUS_ACTIVE, 'severity' => 63, 'period' => '1-7,00:00-24:00']]];
            if (!$existing['user']) {
                $user['passwd'] = bin2hex(random_bytes(32)).'aA1!';
            }
            $this->put('user', API::User(), 'userid', $user, $existing, $config);
            $conditions = array_map(static fn($id) => ['conditiontype' => ZBX_CONDITION_TYPE_HOST_GROUP,
                'operator' => CONDITION_OPERATOR_EQUAL, 'value' => $id], $groupids);
            if (!$conditions) {
                // An empty mapping must never turn into an unfiltered Action.
                $conditions = array_values(array_filter($existing['action']['filter']['conditions'] ?? [],
                    static fn($c) => $c['conditiontype'] == ZBX_CONDITION_TYPE_HOST_GROUP));
                $conditions = array_map(static fn($c) => array_intersect_key($c,
                    array_flip(['conditiontype', 'operator', 'value'])), $conditions);
                if (!$conditions) { throw new RuntimeException(_('Aggiungi almeno un’associazione prima del primo setup.')); }
            }
            foreach ($severities as $severity) {
                $conditions[] = ['conditiontype' => ZBX_CONDITION_TYPE_TRIGGER_SEVERITY,
                    'operator' => CONDITION_OPERATOR_EQUAL, 'value' => (string) $severity];
            }
            $this->put('action', API::Action(), 'actionid', [
                'name' => 'Zabbix Talk - PROBLEM', 'eventsource' => EVENT_SOURCE_TRIGGERS,
                'status' => $routes ? ACTION_STATUS_ENABLED : ACTION_STATUS_DISABLED, 'esc_period' => '1m',
                'filter' => ['evaltype' => CONDITION_EVAL_TYPE_AND_OR, 'conditions' => $conditions],
                'operations' => [['operationtype' => OPERATION_TYPE_MESSAGE, 'esc_step_from' => 1, 'esc_step_to' => 1,
                    'opmessage' => ['default_msg' => 0, 'subject' => 'Zabbix Talk', 'message' => 'problem',
                        'mediatypeid' => $config['ids']['media']],
                    'opmessage_usr' => [['userid' => $config['ids']['user']]]]],
                'recovery_operations' => [['operationtype' => OPERATION_TYPE_RECOVERY_MESSAGE,
                    'opmessage' => ['default_msg' => 0, 'subject' => 'Zabbix Talk', 'message' => 'recovery']]],
                'update_operations' => []
            ], $existing, $config);
            $config['groupid'] = $groupid;
            $config['group_name'] = $groups[0]['name'] ?? '';
            $config['routes'] = $routes;
            $config['choices'] = $choices;
            $config['routing_revision'] = $this->routing_revision;
            $tokens = self::ok(API::Token()->get(['output' => ['tokenid'],
                'userids' => [$config['ids']['user']], 'filter' => ['name' => 'Zabbix Talk - Routing']]));
            $tokenid = $tokens[0]['tokenid'] ?? self::ok(API::Token()->create([
                'name' => 'Zabbix Talk - Routing', 'userid' => $config['ids']['user']]))['tokenids'][0];
            $token = self::ok(API::Token()->generate([$tokenid]))[0]['token'];
            $config['routing_tokenid'] = $tokenid;
            $this->routing_token = $token;
            $config['severities'] = $severities;
            $config['destination'] = $state['destination'];
            $config['bot_id'] = $state['bot']['id'];
            $config['recovery_enabled'] = true;
            $config['observed'] = [];
            $this->save($config);
            $config['observed'] = $this->resources();
            $this->save($config);
            return $routes ? _('Routing e notifiche salvati. I conflitti restano bloccati fino alla scelta esplicita per host.')
                : _('Associazioni rimosse e Action disabilitata.');
        });
        $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/').'/api_jsonrpc.php';
        BridgeClient::request('/routing', ['routes' => $routes, 'choices' => (object) $choices,
            'api_url' => 'http://127.0.0.1'.$path, 'api_token' => $this->routing_token,
            'revision' => $this->routing_revision, 'ack_enabled' => true]);
        return $message;
    }

    public function routes(): array {
        $config = $this->config();
        return $config['routes'] ?? (empty($config['groupid']) ? [] : [[
            'groupid' => $config['groupid'], 'group_name' => $config['group_name'], 'destination' => $config['destination']]]);
    }

    public function preview(array $routes, array $choices): array {
        $all = self::ok(API::HostGroup()->get(['output' => ['groupid', 'name']]));
        $ids = [];
        foreach ($all as $group) {
            foreach ($routes as $route) {
                if ($group['groupid'] == $route['groupid'] || str_starts_with($group['name'], $route['group_name'].'/')) {
                    $ids[] = $group['groupid'];
                }
            }
        }
        if (!$ids) { return []; }
        $hosts = self::ok(API::Host()->get(['output' => ['hostid', 'name'], 'groupids' => array_values(array_unique($ids)),
            'selectHostGroups' => ['groupid', 'name'], 'sortfield' => 'name']));
        foreach ($hosts as &$host) {
            $host['candidates'] = [];
            foreach ($routes as $route) {
                foreach ($host['hostgroups'] as $group) {
                    if ($group['groupid'] == $route['groupid'] || str_starts_with($group['name'], $route['group_name'].'/')) {
                        $destination = $route['destination'];
                        $key = $destination['chat_id'].':'.($destination['message_thread_id'] ?? '');
                        $host['candidates'][$key] = $destination;
                    }
                }
            }
            $host['selected'] = count($host['candidates']) === 1 ? array_key_first($host['candidates'])
                : (isset($host['candidates'][$choices[$host['hostid']] ?? '']) ? $choices[$host['hostid']] : '');
        }
        unset($host);
        return $hosts;
    }

    public function transportTest(): string {
        if (!$this->status()['ready']) {
            throw new RuntimeException(_('Completa il setup e correggi le risorse prima del test.'));
        }
        $media = $this->resources()['media'];
        $parameters = array_column($media['parameters'], 'value', 'name');
        $destination = BridgeClient::request('/status')['destination'];
        $parameters['chat_id'] = (string) $destination['chat_id'];
        $parameters['message_thread_id'] = (string) ($destination['message_thread_id'] ?? '');
        $parameters['channel_key'] = BridgeClient::request('/channel', ['operation' => 'channel'])['key'];
        $parameters = array_replace($parameters, ['kind' => 'transport', 'eventid' => (string) random_int(1, PHP_INT_MAX),
            'event_value' => '1', 'hostid' => '1', 'host' => 'Test trasporto', 'problem' => 'Prova Media Type → bridge → Telegram. Nessun evento Zabbix generato.',
            'severity' => '0', 'occurred_at' => date('Y-m-d H:i:s T')]);
        global $ZBX_SERVER, $ZBX_SERVER_PORT;
        $server = new CZabbixServer($ZBX_SERVER, $ZBX_SERVER_PORT,
            timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::CONNECT_TIMEOUT)),
            timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::MEDIA_TYPE_TEST_TIMEOUT)), ZBX_SOCKET_BYTES_LIMIT);
        $result = $server->testMediaType(['mediatypeid' => $media['mediatypeid'], 'parameters' => $parameters], CSessionHelper::getId());
        if ($result === false) {
            throw new RuntimeException(str_replace($parameters['channel_key'], '[omesso]', $server->getError()));
        }
        $receipt = json_decode($result, true);
        if (empty($receipt['message_id'])) {
            throw new RuntimeException(_('Trasporto non confermato. Consulta la diagnostica e Telegram prima di riprovare.'));
        }
        return _('Test trasporto riuscito, message_id ').$receipt['message_id']._('. Il test non verifica trigger e Action.');
    }

    public function prepareTest(): string {
        if (!$this->status()['ready']) {
            throw new RuntimeException(_('Completa prima il setup notifiche.'));
        }
        return $this->transaction(function () {
            $config = $this->config();
            if (!empty($config['fixture'])) {
                throw new RuntimeException(_('Host di prova già presente. Usa Genera PROBLEM o Ripristina e rimuovi host di prova.'));
            }
            $host = self::ok(API::Host()->create(['host' => 'Zabbix-Talk-Test-'.$this->module['moduleid'],
                'name' => 'TEST Zabbix Talk', 'groups' => [['groupid' => $config['groupid']]],
                'description' => 'Isolated test fixture managed by Zabbix Talk.']))['hostids'][0];
            $item = self::ok(API::Item()->create(['hostid' => $host, 'name' => 'TEST Zabbix Talk signal',
                'key_' => 'zabbix.talk.test', 'type' => ITEM_TYPE_TRAPPER, 'value_type' => ITEM_VALUE_TYPE_UINT64,
                'history' => '1d', 'trends' => '0']))['itemids'][0];
            $severity = max($config['severities']);
            $trigger = self::ok(API::Trigger()->create(['description' => 'TEST Zabbix Talk - PROBLEM controllato',
                'expression' => 'last(/Zabbix-Talk-Test-'.$this->module['moduleid'].'/zabbix.talk.test)=1',
                'priority' => $severity]))['triggerids'][0];
            $config['fixture'] = ['hostid' => $host, 'itemid' => $item, 'triggerid' => $trigger,
                'groupid' => $config['groupid'], 'severity' => $severity];
            $this->save($config);
            return _('Host di prova isolato creato nel gruppo selezionato. Attendi l’aggiornamento della configurazione del server, poi genera il PROBLEM.');
        });
    }

    private function fixture(): array {
        $fixture = $this->config()['fixture'] ?? [];
        if (!$fixture) {
            throw new RuntimeException(_('Crea prima l’host di prova isolato.'));
        }
        $hosts = self::ok(API::Host()->get(['output' => ['hostid', 'host'], 'hostids' => [$fixture['hostid']],
            'selectItems' => ['itemid'], 'selectTriggers' => ['triggerid'], 'selectHostGroups' => ['groupid']]));
        $host = $hosts[0] ?? null;
        if (!$host || $host['host'] !== 'Zabbix-Talk-Test-'.$this->module['moduleid']
                || array_column($host['items'], 'itemid') !== [$fixture['itemid']]
                || array_column($host['triggers'], 'triggerid') !== [$fixture['triggerid']]
                || array_column($host['hostgroups'], 'groupid') !== [$fixture['groupid']]) {
            throw new RuntimeException(_('Host di prova assente o modificato esternamente. Nessuna modifica automatica.'));
        }
        return $fixture;
    }

    public function problemTest(): string {
        if (!$this->status()['ready']) {
            throw new RuntimeException(_('Action o risorse non pronte: verifica il setup.'));
        }
        $fixture = $this->fixture();
        if ($fixture['groupid'] !== $this->config()['groupid']
                || !in_array($fixture['severity'], $this->config()['severities'])) {
            throw new RuntimeException(_('Il setup è cambiato. Rimuovi e ricrea l’host di prova.'));
        }
        $result = self::ok(API::History()->push([['itemid' => $fixture['itemid'], 'value' => '1']]));
        foreach ($result['data'] as $row) {
            if (!empty($row['error'])) {
                throw new RuntimeException($row['error']);
            }
        }
        return _('Valore di prova accettato dal server Zabbix. Attendi l’evento e premi Aggiorna stato: il percorso completo è confermato solo dall’evento PROBLEM e dal relativo message_id Telegram. Ripetere il valore 1 non genera un nuovo evento finché il trigger è già in PROBLEM.');
    }

    public function removeTest(): string {
        return $this->transaction(function () {
            $fixture = $this->fixture();
            self::ok(API::Host()->delete([$fixture['hostid']]));
            $config = $this->config();
            unset($config['fixture']);
            $this->save($config);
            return _('Host, item e trigger di prova rimossi. Per ripetere il collaudo crea un nuovo host di prova.');
        });
    }

    public function recoveryTest(): string {
        if (!$this->status()['ready'] || empty($this->config()['recovery_enabled'])) {
            throw new RuntimeException(_('Salva prima il setup PROBLEM/RECOVERY.'));
        }
        $fixture = $this->fixture();
        $result = self::ok(API::History()->push([['itemid' => $fixture['itemid'], 'value' => '0']]));
        foreach ($result['data'] as $row) {
            if (!empty($row['error'])) {
                throw new RuntimeException($row['error']);
            }
        }
        return _('Valore di ripristino accettato da Zabbix. Attendi il RECOVERY e premi Aggiorna stato: verifica la ricevuta e lo stesso message_id risolto in Telegram. Per Alta/Disastro è previsto anche un nuovo messaggio di ripristino.');
    }

    public function testStatus(): string {
        $fixture = $this->config()['fixture'] ?? null;
        if (!$fixture) {
            return _('Nessun host di prova creato.');
        }
        $events = self::ok(API::Event()->get(['output' => ['eventid', 'clock', 'name', 'severity', 'r_eventid', 'acknowledged'],
            'source' => EVENT_SOURCE_TRIGGERS, 'object' => EVENT_OBJECT_TRIGGER,
            'objectids' => [$fixture['triggerid']], 'value' => TRIGGER_VALUE_TRUE,
            'sortfield' => 'eventid', 'sortorder' => 'DESC', 'limit' => 1]));
        return 'Host ID '.$fixture['hostid'].' · trigger ID '.$fixture['triggerid'].' · '.($events
            ? 'PROBLEM eventid '.$events[0]['eventid'].' · '.zbx_date2str(DATE_TIME_FORMAT_SECONDS, $events[0]['clock'])
                .($events[0]['acknowledged'] === '1' ? _(' · riconosciuto in Zabbix') : _(' · non riconosciuto'))
                .($events[0]['r_eventid'] !== '0' ? ' · RECOVERY eventid '.$events[0]['r_eventid'] : _(' · ancora aperto'))
            : _('Nessun evento PROBLEM rilevato.'));
    }

}
