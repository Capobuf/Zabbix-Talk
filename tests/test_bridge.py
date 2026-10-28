import json
import sqlite3
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from bridge.server import Bridge, BridgeError


class TelegramFlowTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.path = Path(self.temporary.name) / 'telegram.json'
        self.bridge = Bridge(self.path)
        self.calls = []
        self.member_status = 'administrator'
        self.actor_status = None
        self.deleted = False
        self.next_message = 41
        self.token = '123456:' + 'x' * 35
        self.mock = patch('bridge.server.telegram', side_effect=self.telegram)
        self.mock.start()
        self.addCleanup(self.mock.stop)
        self.bridge.action('connect', self.token)

    def telegram(self, token, method, params=None):
        self.calls.append((method, params))
        if method == 'getMe':
            return {'id': 123456, 'username': 'TestBot', 'first_name': 'Test'}
        if method == 'getWebhookInfo':
            return {'url': ''}
        if method == 'getChat':
            return {'id': -1001, 'type': 'supergroup', 'title': 'Test',
                    'permissions': {'can_send_messages': True}}
        if method == 'getChatMember':
            if params['user_id'] != 123456 and self.actor_status is not None:
                return {'status': self.actor_status}
            return {'status': self.member_status}
        if method == 'answerCallbackQuery':
            return True
        if method == 'editMessageText' and self.deleted:
            raise BridgeError('Telegram 400: message to edit not found')
        if method in ('sendRichMessage', 'editMessageText'):
            if method == 'sendRichMessage':
                self.next_message += 1
            return {'message_id': params.get('message_id', self.next_message), 'rich_message': {'blocks': []}}
        raise AssertionError(method)

    def register(self, thread=None, code=None):
        if not self.bridge.registration:
            self.bridge.action('register')
        self.bridge.register_message({
            'text': '/zabbix_talk@TestBot ' + (code or self.bridge.registration['code']),
            'from': {'id': 99}, 'chat': {'id': -1001},
            'is_topic_message': thread is not None, 'message_thread_id': thread
        }, self.token)

    def test_topic_send_and_edit_same_message_after_restart(self):
        self.register(17)
        self.bridge.action('send')
        self.bridge = Bridge(self.path)
        self.bridge.action('edit')
        writes = [(method, params) for method, params in self.calls if method in ('sendRichMessage', 'editMessageText')]
        self.assertEqual([method for method, _ in writes], ['sendRichMessage', 'editMessageText'])
        self.assertEqual(writes[0][1]['message_thread_id'], 17)
        self.assertEqual(writes[1][1]['message_id'], 42)
        self.assertIn('TEST TRASPORTO', writes[0][1]['rich_message']['html'])
        self.assertEqual(self.bridge.status()['test']['message_id'], 42)
        self.assertEqual(self.path.stat().st_mode & 0o777, 0o600)
        self.assertNotIn(self.token, json.dumps(self.bridge.status()))

    def test_group_without_topic_and_switch_clears_test(self):
        self.register()
        self.bridge.action('send')
        params = next(params for method, params in self.calls if method == 'sendRichMessage')
        self.assertNotIn('message_thread_id', params)
        self.bridge.action('register')
        self.register(17)
        self.assertIsNone(self.bridge.status()['test'])

    def test_invalid_expired_and_non_admin_commands_cannot_link(self):
        self.register(17, code='0' * 32)
        self.assertIsNone(self.bridge.status()['destination'])
        self.bridge.registration['expires'] = 0
        self.register(17)
        self.assertIsNone(self.bridge.status()['destination'])
        self.bridge.action('register')
        self.member_status = 'member'
        self.register(17)
        self.assertIsNone(self.bridge.status()['destination'])
        self.assertIn('amministratore', self.bridge.status()['registration_error'])

    def test_deleted_message_reports_error_without_another_send(self):
        self.register(17)
        self.bridge.action('send')
        self.deleted = True
        with self.assertRaisesRegex(BridgeError, 'message to edit not found'):
            self.bridge.action('edit')
        self.assertEqual(sum(method == 'sendRichMessage' for method, _ in self.calls), 1)
        self.assertEqual(self.bridge.status()['test']['revision'], 0)

    def test_rejected_token_preserves_working_configuration(self):
        self.register(17)
        before = self.path.read_text()
        with patch('bridge.server.telegram', side_effect=BridgeError('Telegram 401: Unauthorized')):
            with self.assertRaises(BridgeError):
                self.bridge.action('connect', '789:' + 'y' * 35)
        self.assertEqual(self.path.read_text(), before)

    def test_rate_limit_preserves_retry_and_blocks_followup(self):
        with patch('bridge.server.telegram', side_effect=BridgeError('Too Many Requests', 30)) as api:
            with self.assertRaises(BridgeError):
                self.bridge.action('verify')
            with self.assertRaisesRegex(BridgeError, 'Limite Telegram'):
                self.bridge.action('verify')
            self.assertEqual(api.call_count, 1)
        self.assertGreater(self.bridge.status()['retry_after'], 0)

    def notification(self, **changes):
        return dict({'kind': 'problem', 'eventid': '99', 'hostid': '17', 'host': '<test & host>',
                     'problem': '<b>literal problem</b>', 'severity': '4',
                     'occurred_at': '2026-10-27 00:00:00', 'event_value': '1',
                     'bot_id': '123456', 'chat_id': '-1001', 'message_thread_id': '17'}, **changes)

    def test_notification_is_escaped_persistent_and_delivered_once(self):
        self.register(17)
        self.bridge.notify(self.notification())
        self.bridge = Bridge(self.path)
        self.assertTrue(self.bridge.notify(self.notification())['duplicate'])
        writes = [params for method, params in self.calls if method == 'sendRichMessage']
        self.assertEqual(len(writes), 1)
        self.assertEqual(writes[0]['message_thread_id'], 17)
        self.assertIn('&lt;b&gt;literal problem&lt;/b&gt;', writes[0]['rich_message']['html'])
        self.assertTrue(writes[0]['rich_message']['html'].startswith('<h3>🔴 &lt;test &amp; host&gt;</h3>'))
        self.assertIn('<blockquote><p>&lt;b&gt;literal problem&lt;/b&gt;</p></blockquote>', writes[0]['rich_message']['html'])
        self.assertTrue(writes[0]['rich_message']['html'].endswith('<footer>Inizio | 27/10 | 00:00</footer>'))
        self.assertNotIn('Host ID', writes[0]['rich_message']['html'])
        self.assertNotIn('Evento:', writes[0]['rich_message']['html'])
        self.assertEqual(self.bridge.status()['notifications'][0]['message_id'], 42)
        self.assertNotIn(self.token, json.dumps(self.bridge.status()))

    def test_critical_presentation_keeps_original_data_for_recovery(self):
        self.register(17)
        host = 'SW-MILANO-PRODUZIONE-ARMADIO-NORD-ACCESSO-03'
        problem = 'Interfaccia <uplink> & rete: ' + 'descrizione completa ' * 90
        for severity in (4, 5):
            with self.subTest(severity=severity):
                event = self.notification(eventid=str(400 + severity), severity=str(severity),
                                          host=host, problem=problem, occurred_at='2026.10.27 00:57:23')
                original = dict(event)
                self.bridge.notify(event)
                content = self.calls[-1][1]['rich_message']['html']
                self.assertTrue(content.startswith('<h3>🔴 SW-MILANO-…-ACCESSO-03</h3>'))
                self.assertIn('<blockquote><p>Interfaccia &lt;uplink&gt; &amp; rete: ' + 'descrizione completa ' * 90 + '</p></blockquote>', content)
                self.assertTrue(content.endswith('<footer>Inizio | 27/10 | 00:57</footer>'))
                for omitted in ('PROBLEMA', 'High', 'Disaster', 'details', 'button', 'callback', 'Host ID', 'Evento:'):
                    self.assertNotIn(omitted, content)
                self.assertEqual(event, original)
                with self.bridge.receipts() as db:
                    stored = db.execute('SELECT host,payload FROM receipts WHERE eventid=?', (event['eventid'],)).fetchone()
                self.assertEqual(stored[0], host)
                self.assertEqual(json.loads(stored[1])['host'], host)
                self.assertEqual(json.loads(stored[1])['occurred_at'], '2026.10.27 00:57:23')
                self.bridge.notify(self.recovery(eventid=event['eventid']))
                self.assertNotIn('SW-MILANO', self.calls[-1][1]['rich_message']['html'])
                self.assertTrue(self.calls[-1][1]['rich_message']['html'].startswith('<h3>🟢 RISOLTO</h3>'))

    def test_notification_rejects_bad_payload_and_changed_bot(self):
        self.register()
        for changes in [{'severity': '6'}, {'event_value': '0'}, {'eventid': '{EVENT.ID}'},
                        {'host': []}, {'bot_id': '999'}, {'message_thread_id': '-1'}]:
            with self.assertRaises(BridgeError):
                self.bridge.notify(self.notification(**changes))
        self.assertFalse(any(method == 'sendRichMessage' for method, _ in self.calls))

    def test_long_names_and_update_presentation_do_not_change_event_support(self):
        host = 'Sede Milano · ' + 'R' * 241
        problem = 'Spazio disponibile < 10% & volume dati: ' + 'è' * 2000
        data = self.notification(kind='update', host=host, problem=problem, severity='2', severity_name='Attenzione')
        rich = Bridge.event_message(data)
        self.assertTrue(rich['html'].startswith('<h3>🟡 Sede Milan-…-' + 'R' * 10 + '</h3>'))
        self.assertIn('<p><b>AGGIORNAMENTO</b></p><blockquote><p>', rich['html'])
        self.assertIn('Spazio disponibile &lt; 10% &amp; volume dati: ' + 'è' * 2000, rich['html'])
        self.assertTrue(rich['skip_entity_detection'])
        with self.assertRaisesRegex(BridgeError, 'Tipo o valore evento'):
            self.bridge.notify(data)

    def test_recovery_shows_resolved_state_and_uses_native_duration(self):
        self.register(17)
        self.bridge.notify(self.notification(severity_name='Critica <rete>'))
        self.bridge = Bridge(self.path)
        self.bridge.notify(self.recovery(severity='0', severity_name='Non classificato', duration='12m 08s'))
        for method, params in self.calls:
            if method == 'editMessageText' or (method == 'sendRichMessage' and 'RISOLTO' in params['rich_message']['html']):
                content = params['rich_message']['html']
                self.assertTrue(content.startswith('<h3>🟢 RISOLTO</h3><blockquote>'))
                self.assertNotIn('&lt;test &amp; host&gt;', content)
                self.assertNotIn('✓', content)
                self.assertIn('<br>Durata | 12 min 8 s', content)
                self.assertNotIn('99', content)
                self.assertNotIn('100', content)
        for missing in ('', '{EVENT.DURATION}', '*UNKNOWN*'):
            content = Bridge.event_message(self.notification(severity_name='{EVENT.SEVERITY}'),
                                           self.recovery(duration=missing))['html']
            self.assertNotIn('Durata', content)
            self.assertNotIn('{EVENT.', content)
            self.assertIn('<h3>🟢 ', content)
        transport = Bridge.event_message(self.notification(kind='transport'))['html']
        self.assertEqual(transport, '<h3>TEST TRASPORTO</h3><blockquote><p>Verifica Media Type → bridge → Telegram</p></blockquote>')

    def test_temporal_metadata_formats_dates_and_preserves_unknown_formats(self):
        event = self.notification(occurred_at='2026.10.26 23:59:10')
        for recovered_at, expected in [('2026.10.26 23:59:58', '23:59'),
                                       ('2026-10-27 00:01:18', '27/10 | 00:01')]:
            content = Bridge.event_message(event, self.recovery(recovered_at=recovered_at, duration='2m 08s'))['html']
            self.assertNotIn('<details>', content)
            self.assertTrue(content.endswith('</footer>'))
            self.assertIn('<footer>26/10 | 23:59 → ' + expected + '<br>Durata | 2 min 8 s', content)
        content = Bridge.event_message(self.notification(occurred_at='custom <date>'),
                                       self.recovery(recovered_at='custom <recovery>', duration='custom <duration>'))['html']
        self.assertIn('custom &lt;date&gt;', content)
        self.assertIn('custom &lt;recovery&gt;', content)
        self.assertIn('custom &lt;duration&gt;', content)

    def test_uncertain_notification_does_not_retry_and_channel_is_private(self):
        key = self.bridge.channel()['key']
        self.assertFalse(self.bridge.authorized(''))
        self.assertFalse(self.bridge.authorized('Bearer wrong'))
        self.assertTrue(self.bridge.authorized('Bearer ' + key))
        self.assertEqual(self.bridge.channel_path.stat().st_mode & 0o777, 0o600)
        self.assertNotIn(key, json.dumps(self.bridge.status()))
        self.bridge.channel_path.write_text('')
        self.assertFalse(self.bridge.authorized('Bearer '))
        with self.assertRaises(BridgeError):
            self.bridge.channel()
        self.bridge.channel_path.write_text(key)
        self.register(17)
        with patch('bridge.server.telegram', side_effect=BridgeError('Network interrupted')) as api:
            with self.assertRaises(BridgeError):
                self.bridge.notify(self.notification())
            self.bridge = Bridge(self.path)
            with self.assertRaisesRegex(BridgeError, 'già tentato'):
                self.bridge.notify(self.notification())
            self.assertEqual(api.call_count, 1)
        self.assertEqual(self.bridge.status()['notifications'][0]['status'], 'unconfirmed')

    def recovery(self, **changes):
        return self.notification(**dict({'kind': 'recovery', 'event_value': '0',
                                         'recovery_eventid': '100', 'recovered_at': '2026-10-27 01:00:00'}, **changes))

    def test_recovery_low_severities_only_edit_original(self):
        self.register(17)
        for severity in range(4):
            with self.subTest(severity=severity):
                eventid = str(200 + severity)
                original = self.bridge.notify(self.notification(eventid=eventid, severity=str(severity)))
                self.assertTrue(self.calls[-1][1]['rich_message']['html'].startswith(
                    '<h3>' + ['⚪', '🔵', '🟡', '🟠'][severity] + ' '))
                before = len(self.calls)
                result = self.bridge.notify(self.recovery(eventid=eventid, severity=str(severity)))
                writes = [(method, params) for method, params in self.calls[before:]]
                self.assertEqual([method for method, _ in writes], ['editMessageText'])
                self.assertEqual(result['message_id'], original['message_id'])
                self.assertIsNone(result['recovery_message_id'])
                self.assertIn('RISOLTO', writes[0][1]['rich_message']['html'])
                self.assertIn('&lt;b&gt;literal problem&lt;/b&gt;', writes[0][1]['rich_message']['html'])
                self.assertTrue(self.bridge.notify(self.recovery(eventid=eventid, severity=str(severity)))['duplicate'])

    def test_recovery_high_disaster_after_restart_preserves_destination_and_deduplicates(self):
        for severity in (4, 5):
            with self.subTest(severity=severity):
                self.bridge.action('register')
                self.register(17)
                eventid = str(300 + severity)
                original = self.bridge.notify(self.notification(eventid=eventid, severity=str(severity)))
                self.bridge.action('register')
                self.register()
                self.bridge = Bridge(self.path)
                recovery = self.recovery(eventid=eventid, severity='0', chat_id='-9999', message_thread_id='29')
                before = len(self.calls)
                result = self.bridge.notify(recovery)
                self.bridge = Bridge(self.path)
                self.assertTrue(self.bridge.notify(recovery)['duplicate'])
                self.assertTrue(self.bridge.notify(self.notification(eventid=eventid))['duplicate'])
                writes = self.calls[before:]
                self.assertEqual([method for method, _ in writes], ['editMessageText', 'sendRichMessage'])
                self.assertEqual(writes[0][1]['message_id'], original['message_id'])
                self.assertEqual(writes[1][1]['chat_id'], -1001)
                self.assertEqual(writes[1][1]['message_thread_id'], 17)
                self.assertEqual(writes[1][1]['reply_parameters'], {'message_id': original['message_id']})
                self.assertIn('RISOLTO', writes[1][1]['rich_message']['html'])
                self.assertEqual(writes[0][1]['rich_message'], writes[1][1]['rich_message'])
                self.assertNotEqual(result['recovery_message_id'], original['message_id'])
                self.assertEqual(self.bridge.status()['notifications'][0]['status'], 'sent')

    def test_recovery_deleted_original_and_partial_send_failure_never_claim_success(self):
        self.register(17)
        self.bridge.notify(self.notification())
        self.deleted = True
        before = len(self.calls)
        with self.assertRaisesRegex(BridgeError, 'message to edit not found'):
            self.bridge.notify(self.recovery())
        self.assertEqual([method for method, _ in self.calls[before:]], ['editMessageText'])
        self.assertEqual(self.bridge.status()['notifications'][0]['status'], 'unconfirmed')
        self.bridge = Bridge(self.path)
        with self.assertRaisesRegex(BridgeError, 'già tentato'):
            self.bridge.notify(self.recovery())
        self.deleted = False
        self.bridge.notify(self.notification(eventid='101'))
        api = self.bridge.api

        def fail_send(token, method, params):
            if method == 'sendRichMessage':
                raise BridgeError('Network interrupted')
            return api(token, method, params)

        with patch.object(self.bridge, 'api', side_effect=fail_send) as mocked:
            with self.assertRaisesRegex(BridgeError, 'Originale aggiornato; nuovo ripristino non confermato'):
                self.bridge.notify(self.recovery(eventid='101'))
            self.assertEqual(mocked.call_count, 2)
        self.bridge = Bridge(self.path)
        with patch.object(self.bridge, 'api') as mocked:
            with self.assertRaisesRegex(BridgeError, 'già tentato'):
                self.bridge.notify(self.recovery(eventid='101'))
            mocked.assert_not_called()

    def test_recovery_rejects_missing_or_mismatched_association(self):
        self.register(17)
        with self.assertRaisesRegex(BridgeError, 'originale non confermato'):
            self.bridge.notify(self.recovery())
        self.bridge.notify(self.notification())
        for changes in [{'hostid': '18'}, {'bot_id': '77'}, {'recovery_eventid': '{EVENT.RECOVERY.ID}'},
                        {'recovered_at': ''}, {'event_value': '1'}]:
            with self.assertRaises(BridgeError):
                self.bridge.notify(self.recovery(**changes))
        self.assertFalse(any(method == 'editMessageText' for method, _ in self.calls))

    def routing(self):
        self.register(17)
        first = self.bridge.state['destination']
        self.bridge.action('register')
        self.register(18)
        second = self.bridge.state['destination']
        second = dict(second, chat_id=-1002)
        self.bridge.state['destinations'][Bridge.destination_key(second)] = second
        routes = [{'groupid': '1', 'group_name': 'Client A', 'destination': first},
                  {'groupid': '2', 'group_name': 'Client B', 'destination': second}]
        self.bridge.configure_routing({'routes': routes, 'choices': {}, 'revision': 'v1',
                                      'api_url': 'http://127.0.0.1/zabbix/api_jsonrpc.php', 'api_token': 'a' * 64})
        return routes

    def test_routing_reads_membership_each_time_and_blocks_conflicts(self):
        routes = self.routing()
        one = [{'groupid': '1', 'name': 'Client A'}]
        two = one + [{'groupid': '2', 'name': 'Client B'}]
        with patch.object(self.bridge, 'host_groups', side_effect=[one, two, [], [two[1]]]) as groups:
            self.bridge.notify(self.notification(routing_revision='v1'))
            self.assertEqual(self.calls[-1][1]['chat_id'], -1001)
            before = len(self.calls)
            with self.assertRaisesRegex(BridgeError, 'Conflitto'):
                self.bridge.notify(self.notification(eventid='101', routing_revision='v1'))
            self.assertEqual(len(self.calls), before)
            self.assertTrue(self.bridge.notify(self.notification(eventid='102', routing_revision='v1'))['skipped'])
            self.bridge.notify(self.notification(eventid='103', routing_revision='v1'))
            self.assertEqual(self.calls[-1][1]['chat_id'], -1002)
            self.assertEqual(self.calls[-1][1]['message_thread_id'], 18)
            self.assertEqual(groups.call_count, 4)
        self.bridge.notify(self.recovery())
        self.assertEqual(self.calls[-1][1]['chat_id'], -1001)
        self.assertEqual(self.calls[-1][1]['message_thread_id'], 17)
        self.assertEqual(len(self.bridge.status()['destinations']), 3)
        self.assertNotIn('api_token', json.dumps(self.bridge.status()))

    def test_routing_same_destination_override_subgroup_and_stale_choice(self):
        routes = self.routing()
        groups = [{'groupid': '11', 'name': 'Client A/Servers'}, {'groupid': '2', 'name': 'Client B'}]
        with self.assertRaisesRegex(BridgeError, 'Conflitto'):
            Bridge.resolve(groups, routes, {'17': '-999:'}, '17')
        selected = Bridge.destination_key(routes[1]['destination'])
        self.assertEqual(Bridge.resolve(groups, routes, {'17': selected}, '17'), routes[1]['destination'])
        routes[1]['destination'] = routes[0]['destination']
        self.assertEqual(Bridge.resolve(groups, routes, {}, '17'), routes[0]['destination'])
        self.assertIsNone(Bridge.resolve([{'groupid': '3', 'name': 'Client AB'}], routes, {}, '17'))
        with self.assertRaisesRegex(BridgeError, 'non sincronizzato'):
            self.bridge.notify(self.notification(routing_revision='old'))
        self.bridge = Bridge(self.path)
        self.assertEqual(self.bridge.state['routing']['revision'], 'v1')

    def test_routing_fails_closed_on_zabbix_error_and_multi_host_event(self):
        self.routing()
        with patch.object(self.bridge, 'zabbix_api', return_value=[{'hosts': [{'hostid': '17'}, {'hostid': '18'}]}]):
            with self.assertRaisesRegex(BridgeError, 'più host'):
                self.bridge.notify(self.notification(routing_revision='v1'))
        with patch.object(self.bridge, 'host_groups', side_effect=BridgeError('API non raggiungibile')):
            with self.assertRaisesRegex(BridgeError, 'API non raggiungibile'):
                self.bridge.notify(self.notification(routing_revision='v1'))
        self.assertFalse(any(method == 'sendRichMessage' for method, _ in self.calls))

    def test_topic_creation_permission_and_persistence(self):
        self.register()
        original = self.bridge.api
        def api(token, method, params=None):
            if method == 'getChat':
                return {'type': 'supergroup', 'is_forum': True}
            if method == 'getChatMember':
                return {'status': 'administrator', 'can_manage_topics': True}
            if method == 'createForumTopic':
                self.assertEqual(params['name'], 'Client A')
                return {'message_thread_id': 73}
            return original(token, method, params)
        with patch.object(self.bridge, 'api', side_effect=api):
            self.bridge.action('create_topic', topic_name='Client A')
        self.bridge = Bridge(self.path)
        self.assertEqual(self.bridge.state['destination']['message_thread_id'], 73)
        self.assertEqual(len(self.bridge.status()['destinations']), 2)
        with self.assertRaisesRegex(BridgeError, 'can_manage_topics'):
            self.bridge.action('create_topic', topic_name='No permission')

    def test_receipts_schema_upgrade_preserves_legacy_rows(self):
        legacy = Path(self.temporary.name) / 'legacy'
        legacy.mkdir()
        with sqlite3.connect(legacy / 'notifications.sqlite3') as db:
            db.execute('CREATE TABLE receipts (id TEXT PRIMARY KEY, kind TEXT, eventid TEXT, host TEXT, '
                       'chat_id TEXT, thread_id TEXT, message_id INTEGER, status TEXT, detail TEXT, updated_at INTEGER)')
            db.execute("INSERT INTO receipts VALUES ('problem:99','problem','99','legacy','-1001','17',42,'sent','ok',1)")
        old = Bridge(legacy / 'telegram.json')
        old.state = self.bridge.state
        self.assertEqual(old.status()['notifications'][0]['message_id'], 42)
        with self.assertRaisesRegex(BridgeError, 'precedente al supporto RECOVERY'):
            old.notify(self.recovery())

    def ack_fixture(self):
        self.actor_status = None
        self.routing()
        self.bridge.configure_routing(dict(self.bridge.state['routing'], ack_enabled=True))
        self.actor_status = 'member'
        self.zabbix_calls = []
        self.native_event = {'eventid': '99', 'acknowledged': '0', 'r_eventid': '0',
                             'hosts': [{'hostid': '17'}], 'acknowledges': []}

        def zabbix(routing, method, params):
            self.zabbix_calls.append((method, params))
            if method == 'event.get':
                return [json.loads(json.dumps(self.native_event))]
            if method == 'hostgroup.get':
                return [{'groupid': '1', 'name': 'Client A'}, {'groupid': '2', 'name': 'Client B'}]
            if method == 'host.get':
                return [{'hostgroups': [{'groupid': '1', 'name': 'Client A'}]}]
            if method == 'event.acknowledge':
                self.assertEqual(params['action'], 6)
                self.assertEqual(params['eventids'], ['99'])
                self.native_event['acknowledged'] = '1'
                self.native_event['acknowledges'].append({'acknowledgeid': '1', 'action': '6', 'message': params['message']})
                return {'eventids': ['99']}
            raise AssertionError(method)

        mocked = patch.object(Bridge, 'zabbix_api', side_effect=zabbix)
        mocked.start()
        self.addCleanup(mocked.stop)
        self.bridge.notify(self.notification(routing_revision='v1'))
        self.ack_message_id = self.calls[-1][1].get('message_id', self.next_message)
        self.ack_data = self.calls[-1][1]['reply_markup']['inline_keyboard'][0][0]['callback_data']
        self.assertLessEqual(len(self.ack_data.encode()), 64)

    def ack_query(self, **changes):
        query = {'id': 'callback1', 'data': self.ack_data,
                 'from': {'id': 99, 'is_bot': False, 'first_name': 'Mario <&>', 'username': 'mario'},
                 'message': {'message_id': self.ack_message_id, 'date': 1, 'from': {'id': 123456, 'is_bot': True},
                             'chat': {'id': -1001, 'type': 'supergroup'}, 'message_thread_id': 17}}
        return dict(query, **changes)

    def test_member_ack_is_native_audited_and_persistent_without_duplicate_comments(self):
        self.ack_fixture()
        self.bridge = Bridge(self.path)
        self.bridge.callback(self.ack_query(), self.token)
        self.assertEqual(self.native_event['acknowledged'], '1')
        comment = self.native_event['acknowledges'][0]['message']
        self.assertIn('Mario <&>', comment)
        self.assertIn('Telegram ID 99', comment)
        self.assertIn('Chat -1001; topic 17', comment)
        edit = next(params for method, params in self.calls if method == 'editMessageText')
        self.assertEqual(edit['message_id'], 42)
        self.assertEqual(edit['reply_markup'], {'inline_keyboard': []})
        self.assertIn('Riconosciuto in Zabbix', edit['rich_message']['html'])
        self.assertIn('Mario &lt;&amp;&gt;', edit['rich_message']['html'])
        self.bridge = Bridge(self.path)
        self.bridge.callback(self.ack_query(), self.token)
        self.bridge.callback(self.ack_query(id='callback2', **{'from': {'id': 100, 'first_name': 'Luca'}}), self.token)
        self.assertEqual(len(self.native_event['acknowledges']), 1)
        self.assertNotIn('Luca', next(params for method, params in reversed(self.calls) if method == 'editMessageText')['rich_message']['html'])
        self.assertEqual(len(self.bridge.status()['audit']), 2)
        self.assertEqual(self.bridge.status()['audit'][0]['status'], 'confirmed')
        self.assertNotIn(self.ack_data, json.dumps(self.bridge.status()))
        self.assertNotIn('api_token', json.dumps(self.bridge.status()))

    def test_ack_rejects_forged_context_and_non_members_without_zabbix_mutation(self):
        self.ack_fixture()
        original = self.ack_query()
        changes = [
            {'data': 'ack:' + '0' * 32}, {'inline_message_id': 'forwarded'},
            {'from': {'id': 99, 'is_bot': True}}, {'from': {}},
            *[{'message': dict(original['message'], **fields)} for fields in [
                {'date': 0}, {'from': {'id': 777}}, {'chat': {'id': -1002, 'type': 'supergroup'}},
                {'message_id': 51}, {'message_thread_id': 18}]]]
        for i, fields in enumerate(changes):
            self.bridge.callback(self.ack_query(**dict(fields, id='invalid' + str(i))), self.token)
        for status in ('left', 'kicked', 'restricted'):
            self.actor_status = status
            self.bridge.callback(self.ack_query(id=status), self.token)
        self.assertFalse(any(method == 'event.acknowledge' for method, _ in self.zabbix_calls))
        self.assertFalse(any(method == 'editMessageText' for method, _ in self.calls))
        self.assertTrue(all(row['status'] == 'denied' for row in self.bridge.status()['audit']))

    def test_ack_rechecks_bot_permissions_and_current_routing(self):
        self.ack_fixture()
        self.member_status = 'member'
        self.bridge.callback(self.ack_query(), self.token)
        self.assertIn('amministratore', self.bridge.status()['audit'][0]['detail'])
        self.member_status = 'administrator'
        self.bridge.state['routing']['routes'] = []
        self.bridge.callback(self.ack_query(id='no-route'), self.token)
        self.assertIn('non più autorizzata', self.bridge.status()['audit'][0]['detail'])
        self.assertFalse(any(method == 'event.acknowledge' for method, _ in self.zabbix_calls))

    def test_external_ack_is_reflected_without_attributing_it_to_the_clicking_member(self):
        self.ack_fixture()
        self.native_event['acknowledged'] = '1'
        self.native_event['acknowledges'] = [{'acknowledgeid': '1', 'action': '2', 'message': ''}]
        self.bridge.callback(self.ack_query(), self.token)
        self.assertFalse(any(method == 'event.acknowledge' for method, _ in self.zabbix_calls))
        edit = next(params for method, params in self.calls if method == 'editMessageText')
        self.assertIn('Riconosciuto in Zabbix', edit['rich_message']['html'])
        self.assertNotIn('Mario', edit['rich_message']['html'])

    def test_resolved_event_never_gets_ack_or_repainted_as_open(self):
        self.ack_fixture()
        self.native_event['r_eventid'] = '100'
        self.bridge.callback(self.ack_query(), self.token)
        self.assertFalse(any(method == 'event.acknowledge' for method, _ in self.zabbix_calls))
        self.assertFalse(any(method == 'editMessageText' for method, _ in self.calls))
        self.assertEqual(self.bridge.status()['audit'][0]['status'], 'resolved')

    def test_ack_denied_by_zabbix_and_failed_telegram_edit_do_not_claim_full_success(self):
        self.ack_fixture()
        api = self.bridge.zabbix_api
        def deny(routing, method, params):
            if method == 'event.acknowledge':
                raise BridgeError('Zabbix ha rifiutato event.acknowledge.')
            return api(routing, method, params)
        with patch.object(self.bridge, 'zabbix_api', side_effect=deny):
            self.bridge.callback(self.ack_query(), self.token)
        self.assertEqual(self.native_event['acknowledged'], '0')
        self.assertEqual(self.bridge.status()['audit'][0]['status'], 'failed')
        self.assertFalse(any(method == 'editMessageText' for method, _ in self.calls))
        self.deleted = True
        self.bridge.callback(self.ack_query(id='retry'), self.token)
        self.assertEqual(self.native_event['acknowledged'], '1')
        self.assertEqual(self.bridge.status()['audit'][0]['status'], 'telegram_failed')
        self.assertIn('non aggiornato', self.calls[-1][1]['text'])

    def test_uncertain_ack_after_restart_reads_native_state_without_repeating_mutation(self):
        for committed in (True, False):
            with self.subTest(committed=committed):
                self.ack_fixture()
                api = self.bridge.zabbix_api
                def uncertain(routing, method, params):
                    if method == 'event.acknowledge':
                        if committed:
                            api(routing, method, params)
                        raise BridgeError('Risposta interrotta', uncertain=True)
                    return api(routing, method, params)
                with patch.object(self.bridge, 'zabbix_api', side_effect=uncertain):
                    self.bridge.callback(self.ack_query(), self.token)
                self.assertEqual(self.bridge.status()['audit'][0]['status'], 'uncertain')
                self.bridge = Bridge(self.path)
                before = len(self.native_event['acknowledges'])
                self.bridge.callback(self.ack_query(id='after-restart'), self.token)
                self.assertEqual(len(self.native_event['acknowledges']), before)
                self.assertEqual(self.bridge.status()['audit'][0]['status'], 'confirmed' if committed else 'denied')
                with self.bridge.receipts() as db:
                    db.execute('DELETE FROM audit')
                    db.execute('DELETE FROM receipts')

    def test_poll_dispatches_callbacks_and_persists_offset(self):
        self.ack_fixture()
        api = self.bridge.api
        def poll(token, method, params=None):
            if method == 'getUpdates':
                self.assertIn('callback_query', params['allowed_updates'])
                self.bridge.stop.set()
                return [{'update_id': 42, 'callback_query': self.ack_query()}]
            return api(token, method, params)
        with patch.object(self.bridge, 'api', side_effect=poll):
            self.bridge.poll()
        self.assertEqual(Bridge(self.path).state['offset'], 43)
        self.assertEqual(self.native_event['acknowledged'], '1')


if __name__ == '__main__':
    unittest.main()
