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
            return {'status': self.member_status}
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


if __name__ == '__main__':
    unittest.main()
