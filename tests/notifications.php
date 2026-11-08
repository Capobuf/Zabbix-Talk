<?php

// Run with: php tests/notifications.php (no Zabbix bootstrap or external services).
use Modules\ZabbixTalk\Notifications;

require __DIR__.'/../frontend/includes/Notifications.php';

const USER_TYPE_ZABBIX_USER = 1;
const USER_TYPE_ZABBIX_ADMIN = 2;
const ITEM_TYPE_TRAPPER = 2;
const ITEM_VALUE_TYPE_UINT64 = 3;

set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

$reflection = new ReflectionClass(Notifications::class);
$notifications = $reflection->newInstanceWithoutConstructor();
$module = $reflection->getProperty('module');
$changes = $reflection->getMethod('changes');
$compare = static function (array $before, array $after) use ($notifications, $module, $changes): array {
    $module->setValue($notifications, ['config' => ['notifications' => ['observed' => $before]]]);
    $result = $changes->invoke($notifications, $after);
    check($notifications->config()['observed'] === $before, 'Comparison mutated the saved snapshot.');
    return $result;
};

$old = [
    'role' => ['roleid' => '6', 'type' => '1', 'rules' => [
        'ui.default_access' => '0', 'ui' => [['name' => 'monitoring.problems', 'status' => '1']],
        'actions.default_access' => '0', 'actions' => [['name' => 'acknowledge_problems', 'status' => '1']],
        'api.access' => '1', 'api.mode' => '1',
        'api' => ['host.get', 'hostgroup.get', 'event.get', 'event.acknowledge'],
        'modules.default_access' => '0', 'modules' => [['moduleid' => '34', 'status' => '0']]
    ]],
    'group' => ['usrgrpid' => '16', 'gui_access' => '3', 'users' => [['userid' => '5']],
        'hostgroup_rights' => [['id' => '25', 'permission' => '2']]],
    'user' => ['userid' => '5', 'medias' => [['mediatypeid' => '110', 'sendto' => ['local-bridge']]]],
    'action' => ['actionid' => '15', 'filter' => ['conditions' => [['value' => '25']]],
        'operations' => [['opmessage_usr' => [['userid' => '5']]]], 'recovery_operations' => [],
        'update_operations' => []]
];
$new = $old;
$new['group'] += ['proxy_mode' => '0', 'proxy_group_mode' => '0'];
$new['role']['rules'] += ['profile.redirect.enforce' => '0', 'profile.redirect.url' => '',
    'devices.access' => '0', 'devices.actions.default_access' => '0',
    'devices.actions' => [['name' => 'manage_own', 'status' => '0']]];
foreach (['apm.traces', 'apm.metrics', 'apm.logs'] as $name) {
    $new['role']['rules']['ui'][] = ['name' => $name, 'status' => '0'];
}
check($compare($old, $new) === [], 'Upgrade-only User snapshot reported a conflict.');
check($compare($new, $old) === [], 'Normalization is not symmetric.');
$old_admin = $old;
$new_admin = $new;
$old_admin['role']['type'] = $new_admin['role']['type'] = '2';
$new_admin['role']['rules']['ui'][] = ['name' => 'reports.system_info', 'status' => '0'];
$new_admin['role']['rules']['actions'][] = ['name' => 'select_server_for_monitoring', 'status' => '0'];
check($compare($old_admin, $new_admin) === [], 'Upgrade-only Admin snapshot reported a conflict.');

$mutations = [
    'permission' => static function (&$r) { $r['group']['hostgroup_rights'][0]['permission'] = '3'; },
    'member' => static function (&$r) { $r['group']['users'][] = ['userid' => '9']; },
    'API allowlist' => static function (&$r) { $r['role']['rules']['api'][] = 'user.get'; },
    'API access' => static function (&$r) { $r['role']['rules']['api.access'] = '0'; },
    'UI permission' => static function (&$r) { $r['role']['rules']['ui'][0]['status'] = '0'; },
    'module' => static function (&$r) { $r['role']['rules']['modules'][] = ['moduleid' => '35', 'status' => '0']; },
    'media recipient' => static function (&$r) { $r['user']['medias'][0]['sendto'][] = 'other'; },
    'condition' => static function (&$r) { $r['action']['filter']['conditions'][0]['value'] = '27'; },
    'operation recipient' => static function (&$r) { $r['action']['operations'][0]['opmessage_usr'][] = ['userid' => '9']; },
    'recovery operation' => static function (&$r) { $r['action']['recovery_operations'][] = ['operationtype' => '11']; },
    'update operation' => static function (&$r) { $r['action']['update_operations'][] = ['operationtype' => '0']; },
    'unexpected property' => static function (&$r) { $r['group']['future_permission'] = '1'; },
    'proxy migration value' => static function (&$r) { $r['group']['proxy_mode'] = '1'; },
    'proxy group migration value' => static function (&$r) { $r['group']['proxy_group_mode'] = '1'; },
    'redirect enforcement' => static function (&$r) { $r['role']['rules']['profile.redirect.enforce'] = '1'; },
    'redirect URL' => static function (&$r) { $r['role']['rules']['profile.redirect.url'] = 'zabbix.php?action=problem.view'; },
    'device access' => static function (&$r) { $r['role']['rules']['devices.access'] = '1'; },
    'device action' => static function (&$r) { $r['role']['rules']['devices.actions'][0]['status'] = '1'; },
    'device default' => static function (&$r) { $r['role']['rules']['devices.actions.default_access'] = '1'; },
    'new UI value' => static function (&$r) { $r['role']['rules']['ui'][1]['status'] = '1'; }
];
foreach ($mutations as $name => $mutate) {
    $modified = $new;
    $mutate($modified);
    check($compare($old, $modified) !== [], '7.4 snapshot hid '.$name);
    check($compare($new, $modified) !== [], '8.0 snapshot hid '.$name);
    check($compare($modified, $new) !== [], 'Existing value overwritten for '.$name);
}

class CWebUser {
    public static string $ip = '198.51.100.23';
    public static function getIp(): string { return self::$ip; }
}
class API {
    public static array $module = ['moduleid' => '34', 'config' => ['notifications' => [
        'groupid' => '25', 'severities' => ['2', '5']
    ]]];
    public static array $item;
    public static function Module() { return new class {
        public function get($options) { return [API::$module]; }
        public function update($module) { API::$module = $module; return ['moduleids' => ['34']]; }
    }; }
    public static function Host() { return new class {
        public function create($payload) { return ['hostids' => ['10816']]; }
    }; }
    public static function Item() { return new class {
        public function create($payload) { API::$item = $payload; return ['itemids' => ['75434']]; }
    }; }
    public static function Trigger() { return new class {
        public function create($payload) { return ['triggerids' => ['34575']]; }
    }; }
}
$DB = ['TRANSACTIONS' => 0];
function DBstart() {}
function DBselect($query) {}
function DBend($commit) { return true; }
function zbx_dbstr($value) { return "'".$value."'"; }
class FixtureNotifications extends Notifications {
    public function status(?array $telegram = null): array { return ['ready' => true]; }
}
$fixture = new FixtureNotifications();
$fixture->prepareTest();
check(API::$item === ['hostid' => '10816', 'name' => 'TEST Zabbix Talk signal', 'key_' => 'zabbix.talk.test',
    'type' => 2, 'value_type' => 3, 'trapper_hosts' => '127.0.0.1,::1,198.51.100.23',
    'history' => '1d', 'trends' => '0'], 'Unexpected fixture payload or origins.');
$existing = API::$module;
try {
    $fixture->prepareTest();
    throw new RuntimeException('Existing fixture was replaced.');
}
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'già presente'), $e->getMessage()); }
check(API::$module === $existing, 'Existing fixture was modified.');
unset(API::$module['config']['notifications']['fixture']);
CWebUser::$ip = '127.0.0.1';
(new FixtureNotifications())->prepareTest();
check(API::$item['trapper_hosts'] === '127.0.0.1,::1', 'Loopback client was duplicated.');
echo "OK: upgrade comparison, protected changes and fixture payload.\n";
