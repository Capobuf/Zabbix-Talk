import json
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
            return {'message_id': 42, 'rich_message': {'blocks': []}}
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
        self.assertIn('TEST Zabbix Talk', writes[0][1]['rich_message']['html'])
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


if __name__ == '__main__':
    unittest.main()
