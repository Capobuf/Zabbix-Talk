"""Local Telegram bridge; authenticated notifications and health over loopback TCP."""

import copy
import fcntl
import grp
import html
import json
import logging
import os
import re
import secrets
import socketserver
import sqlite3
import threading
import time
import urllib.error
import urllib.request
from datetime import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

STATE = Path('/var/lib/zabbix-talk/telegram.json')
SOCKET = '/run/zabbix-talk/control.sock'


class BridgeError(Exception):
    def __init__(self, message, retry_after=0):
        super().__init__(message)
        self.retry_after = retry_after


def telegram(token, method, params=None):
    request = urllib.request.Request(
        f'https://api.telegram.org/bot{token}/{method}',
        data=json.dumps(params or {}).encode(),
        headers={'Content-Type': 'application/json'}, method='POST')
    try:
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        try:
            response = opener.open(request, timeout=35 if method == 'getUpdates' else 10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            result = json.loads(response.read(2_000_000))
    except (OSError, ValueError, urllib.error.URLError):
        raise BridgeError('Telegram non raggiungibile o risposta non valida. Verifica rete e DNS.') from None
    if not isinstance(result, dict) or not isinstance(result.get('ok'), bool):
        raise BridgeError('Risposta Telegram non valida. Controlla la chat prima di ripetere un test.')
    if not result.get('ok'):
        code = result.get('error_code', 0)
        description = str(result.get('description', 'Errore API Telegram')).replace(token, '[omesso]')
        description = re.sub(r'\d+:[A-Za-z0-9_-]+', '[omesso]', description)[:300]
        retry = int(result.get('parameters', {}).get('retry_after', 0))
        hint = {401: 'Verifica o sostituisci il token.',
                403: 'Verifica che il bot sia nel gruppo e possa inviare messaggi.',
                409: 'Ferma altri poller o rimuovi il webhook del bot dedicato.'}.get(code, '')
        raise BridgeError(f'Telegram {code}: {description}. {hint}'.strip()
                          + (f' Riprova tra {retry} secondi.' if retry else ''), retry)
    if 'result' not in result:
        raise BridgeError('Telegram non ha restituito il risultato. Controlla la chat prima di ripetere un test.')
    return result['result']


class Bridge:
    def __init__(self, path=STATE):
        self.path = path
        self.lock = threading.RLock()
        self.operations = threading.Lock()
        self.stop = threading.Event()
        self.state = json.loads(path.read_text()) if path.exists() else {}
        self.poll_error = ''
        self.registration_error = ''
        self.last_poll = 0
        self.registration = None
        self.cooldown = 0
        self.channel_path = path.with_name('channel.key')
        self.receipts_path = path.with_name('notifications.sqlite3')
        with self.receipts() as db:
            db.execute('CREATE TABLE IF NOT EXISTS receipts ('
                       'id TEXT PRIMARY KEY, kind TEXT, eventid TEXT, host TEXT, '
                       'chat_id TEXT, thread_id TEXT, message_id INTEGER, '
                       'status TEXT, detail TEXT, updated_at INTEGER)')
            columns = {row[1] for row in db.execute('PRAGMA table_info(receipts)')}
            for name, definition in [('hostid', 'TEXT'), ('bot_id', 'TEXT'), ('payload', 'TEXT'),
                                     ('recovery_eventid', 'TEXT'), ('recovery_message_id', 'INTEGER')]:
                if name not in columns:
                    db.execute(f'ALTER TABLE receipts ADD COLUMN {name} {definition}')

    def receipts(self):
        return sqlite3.connect(self.receipts_path)

    def channel(self):
        with self.operations:
            if not self.channel_path.exists():
                fd = os.open(self.channel_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
                with os.fdopen(fd, 'w') as stream:
                    stream.write(secrets.token_hex(32))
                    stream.flush()
                    os.fsync(stream.fileno())
            key = self.channel_path.read_text()
            if not re.fullmatch(r'[a-f0-9]{64}', key):
                raise BridgeError('Chiave locale non valida. Ripristina channel.key prima di salvare il setup.')
            return {'key': key}

    def authorized(self, header):
        if not self.channel_path.exists():
            return False
        key = self.channel_path.read_text()
        return bool(re.fullmatch(r'[a-f0-9]{64}', key)) and secrets.compare_digest(
            header.encode(), ('Bearer ' + key).encode())

    def notify(self, data):
        kind = data.get('kind')
        if kind not in ('problem', 'recovery', 'transport') or str(data.get('event_value')) != ('0' if kind == 'recovery' else '1'):
            raise BridgeError('Tipo o valore evento PROBLEM/RECOVERY non valido.')
        for field in ('eventid', 'hostid', 'bot_id'):
            if not re.fullmatch(r'[1-9][0-9]{0,19}', str(data.get(field, ''))):
                raise BridgeError('Identificativi evento, host o bot non validi.')
        if not re.fullmatch(r'-[1-9][0-9]{0,19}', str(data.get('chat_id', ''))):
            raise BridgeError('Destinazione del Webhook non valida.')
        thread = str(data.get('message_thread_id', ''))
        if thread and not re.fullmatch(r'[1-9][0-9]{0,19}', thread):
            raise BridgeError('Topic del Webhook non valido.')
        severity = str(data.get('severity', ''))
        if severity not in ('0', '1', '2', '3', '4', '5'):
            raise BridgeError('Severità numerica non valida.')
        for field, limit in [('host', 255), ('problem', 2048), ('occurred_at', 80)]:
            if not isinstance(data.get(field), str) or not 0 < len(data[field]) <= limit:
                raise BridgeError('Contenuto evento incompleto o troppo lungo.')
        for field, limit in [('severity_name', 255), ('duration', 80)]:
            if field in data and (not isinstance(data[field], str) or len(data[field]) > limit):
                raise BridgeError('Nome della severità o durata non validi.')
        if kind == 'recovery':
            if not re.fullmatch(r'[1-9][0-9]{0,19}', str(data.get('recovery_eventid', ''))):
                raise BridgeError('Identificativo RECOVERY non valido.')
            if not isinstance(data.get('recovered_at'), str) or not 0 < len(data['recovered_at']) <= 80:
                raise BridgeError('Data/ora RECOVERY non valida.')
        receipt_id = kind + ':' + str(data['eventid'])
        with self.operations:
            with self.lock:
                current = copy.deepcopy(self.state)
            if kind == 'recovery':
                return self.recover(data, current)
            with self.receipts() as db:
                previous = db.execute('SELECT message_id,status FROM receipts WHERE id=?', (receipt_id,)).fetchone()
                if previous:
                    if previous[1] == 'sent':
                        return {'message_id': previous[0], 'duplicate': True}
                    raise BridgeError('Evento già tentato senza conferma: controlla Telegram e la diagnostica; nessun reinvio automatico.')
            if str(current.get('bot', {}).get('id')) != str(data['bot_id']):
                raise BridgeError('Il bot è cambiato: salva nuovamente la configurazione notifiche.')
            destination = current.get('destination', {})
            if (str(destination.get('chat_id', '')) != str(data['chat_id'])
                    or str(destination.get('message_thread_id') or '') != thread):
                raise BridgeError('La destinazione è cambiata: salva nuovamente la configurazione notifiche.')
            with self.receipts() as db:
                db.execute('INSERT INTO receipts '
                           '(id,kind,eventid,host,chat_id,thread_id,status,detail,updated_at,hostid,bot_id,payload) '
                           'VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                           (receipt_id, kind, str(data['eventid']), data['host'], str(data['chat_id']),
                            thread, 'sending', 'Invio in corso; in caso di interruzione verificare Telegram.', int(time.time()),
                            str(data['hostid']), str(data['bot_id']), json.dumps({
                                key: data[key] for key in ('kind', 'eventid', 'hostid', 'host', 'problem', 'severity',
                                                         'severity_name', 'occurred_at') if key in data})))
            rich = self.event_message(data)
            params = {'chat_id': int(data['chat_id']), 'rich_message': rich}
            if thread:
                params['message_thread_id'] = int(thread)
            try:
                message = self.api(current['token'], 'sendRichMessage', params)
                if not isinstance(message, dict) or not message.get('message_id') or not message.get('rich_message'):
                    raise BridgeError('Telegram non ha confermato il Rich Message. Controlla la chat.')
            except BridgeError as error:
                with self.receipts() as db:
                    db.execute('UPDATE receipts SET status=?, detail=?, updated_at=? WHERE id=?',
                               ('unconfirmed', str(error), int(time.time()), receipt_id))
                raise
            with self.receipts() as db:
                db.execute('UPDATE receipts SET status=?, message_id=?, detail=?, updated_at=? WHERE id=?',
                           ('sent', message['message_id'], 'Rich Message confermato da Telegram.', int(time.time()), receipt_id))
            return {'message_id': message['message_id'], 'duplicate': False}

    @staticmethod
    def message_time(value, same_day_as=''):
        for date_format in ('%Y.%m.%d %H:%M:%S', '%Y-%m-%d %H:%M:%S'):
            try:
                date = datetime.strptime(value, date_format)
                if same_day_as[:10].replace('.', '-') == date.strftime('%Y-%m-%d'):
                    return date.strftime('%H:%M')
                return date.strftime('%d/%m | %H:%M')
            except ValueError:
                continue
        return value

    @staticmethod
    def event_message(data, recovery=None):
        if data['kind'] == 'transport':
            return Bridge.test_message(transport=True)
        leds = ['⚪', '🔵', '🟡', '🟠', '🔴', '🔴']
        severity = int(data['severity'])
        host = data['host']
        if len(host) > 32:
            host = host[:10].rstrip('- ') + '-…-' + host[-10:].lstrip('- ')
        led = '🟢' if recovery else leds[severity]
        content = '<h3>' + led + ' ' + ('RISOLTO' if recovery else html.escape(host)) + '</h3>'
        if not recovery and data['kind'] == 'update':
            content += '<p><b>AGGIORNAMENTO</b></p>'
        content += '<blockquote><p>' + html.escape(data['problem']) + '</p></blockquote>'
        started = Bridge.message_time(data['occurred_at'])
        if recovery:
            content += '<footer>' + html.escape(started) + ' → ' + html.escape(
                Bridge.message_time(recovery['recovered_at'], data['occurred_at']))
            duration = recovery.get('duration', '')
            if duration and duration not in ('{EVENT.DURATION}', '*UNKNOWN*'):
                if re.fullmatch(r'(?:[0-9]+[wdhms]\s*)+', duration):
                    units = {'w': 'sett', 'd': 'g', 'h': 'h', 'm': 'min', 's': 's'}
                    duration = ' '.join(str(int(value)) + ' ' + units[unit]
                                        for value, unit in re.findall(r'([0-9]+)([wdhms])', duration))
                content += '<br>Durata | ' + html.escape(duration)
        else:
            content += '<footer>Inizio | ' + html.escape(started)
        return {'html': content + '</footer>', 'skip_entity_detection': True}

    @staticmethod
    def test_message(revision=0, transport=False):
        purpose = 'Verifica Media Type → bridge → Telegram' if transport else 'Verifica del collegamento Telegram'
        content = '<h3>TEST TRASPORTO</h3><blockquote><p>' + purpose + '</p></blockquote>'
        if revision:
            content += f'<footer>Revisione | {revision}</footer>'
        return {'html': content, 'skip_entity_detection': True}

    def recover(self, data, current):
        receipt_id = 'recovery:' + str(data['eventid'])
        with self.receipts() as db:
            db.row_factory = sqlite3.Row
            original = db.execute('SELECT * FROM receipts WHERE id=?', ('problem:' + str(data['eventid']),)).fetchone()
            if not original or original['status'] != 'sent' or not original['payload']:
                raise BridgeError('PROBLEM originale non confermato o precedente al supporto RECOVERY: nessun nuovo messaggio inviato.')
            if (original['hostid'] != str(data['hostid']) or original['bot_id'] != str(data['bot_id'])
                    or original['bot_id'] != str(current.get('bot', {}).get('id'))):
                raise BridgeError('Host o bot diversi dal PROBLEM originale: impossibile aggiornare il messaggio.')
            previous = db.execute('SELECT * FROM receipts WHERE id=?', (receipt_id,)).fetchone()
            if previous:
                if previous['recovery_eventid'] != str(data['recovery_eventid']):
                    raise BridgeError('RECOVERY diverso da quello già associato al PROBLEM.')
                if previous['status'] == 'sent':
                    return {'message_id': original['message_id'], 'recovery_message_id': previous['recovery_message_id'], 'duplicate': True}
                raise BridgeError('RECOVERY già tentato senza conferma completa: controlla Telegram e la diagnostica; nessun reinvio automatico.')
            db.execute('INSERT INTO receipts '
                       '(id,kind,eventid,host,chat_id,thread_id,message_id,status,detail,updated_at,hostid,bot_id,recovery_eventid) '
                       'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                       (receipt_id, 'recovery', original['eventid'], original['host'], original['chat_id'],
                        original['thread_id'], original['message_id'], 'editing', 'Aggiornamento del PROBLEM in corso.',
                        int(time.time()), original['hostid'], original['bot_id'], str(data['recovery_eventid'])))
        event = json.loads(original['payload'])
        params = {'chat_id': int(original['chat_id']), 'message_id': original['message_id'],
                  'rich_message': self.event_message(event, data)}
        edited = False
        recovery_message_id = None
        try:
            message = self.api(current['token'], 'editMessageText', params)
            if not isinstance(message, dict) or message.get('message_id') != original['message_id'] or not message.get('rich_message'):
                raise BridgeError('Telegram non ha confermato la modifica del messaggio originale.')
            edited = True
            if int(event['severity']) in (4, 5):
                with self.receipts() as db:
                    db.execute('UPDATE receipts SET status=?,detail=?,updated_at=? WHERE id=?',
                               ('sending', 'Originale aggiornato; invio del messaggio di ripristino in corso.', int(time.time()), receipt_id))
                params = {'chat_id': int(original['chat_id']), 'rich_message': self.event_message(event, data),
                          'reply_parameters': {'message_id': original['message_id']}}
                if original['thread_id']:
                    params['message_thread_id'] = int(original['thread_id'])
                message = self.api(current['token'], 'sendRichMessage', params)
                if not isinstance(message, dict) or not message.get('message_id') or not message.get('rich_message'):
                    raise BridgeError('Telegram non ha confermato il messaggio di ripristino.')
                recovery_message_id = message['message_id']
        except BridgeError as error:
            detail = ('Originale aggiornato; nuovo ripristino non confermato. ' if edited else 'Modifica originale non confermata. ') + str(error)
            with self.receipts() as db:
                db.execute('UPDATE receipts SET status=?,detail=?,updated_at=? WHERE id=?',
                           ('unconfirmed', detail, int(time.time()), receipt_id))
            raise BridgeError(detail, error.retry_after) from None
        with self.receipts() as db:
            db.execute('UPDATE receipts SET status=?,detail=?,updated_at=?,recovery_message_id=? WHERE id=?',
                       ('sent', 'Originale risolto; ' + ('nuovo ripristino confermato.' if recovery_message_id else 'nessun nuovo messaggio previsto.'),
                        int(time.time()), recovery_message_id, receipt_id))
        return {'message_id': original['message_id'], 'recovery_message_id': recovery_message_id, 'duplicate': False}

    def save(self):
        temporary = self.path.with_suffix('.tmp')
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, 'w') as stream:
            json.dump(self.state, stream)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, self.path)

    def api(self, token, method, params=None):
        remaining = int(self.cooldown - time.time()) + 1
        if remaining > 0:
            raise BridgeError(f'Limite Telegram: riprova tra {remaining} secondi.', remaining)
        try:
            return telegram(token, method, params)
        except BridgeError as error:
            if error.retry_after:
                self.cooldown = time.time() + error.retry_after
            raise

    def status(self):
        with self.lock:
            registration = self.registration
            if registration and registration['expires'] <= time.time():
                self.registration = registration = None
            with self.receipts() as db:
                db.row_factory = sqlite3.Row
                receipts = [dict(row) for row in db.execute(
                    'SELECT kind,eventid,host,chat_id,thread_id,message_id,status,detail,updated_at,recovery_eventid,recovery_message_id '
                    'FROM receipts ORDER BY updated_at DESC, rowid DESC LIMIT 8')]
            return copy.deepcopy({
                'notifications': receipts,
                'bot': self.state.get('bot'),
                'verified_at': self.state.get('verified_at'),
                'polling': self.last_poll > time.time() - 80 and not self.poll_error,
                'poll_error': self.poll_error,
                'registration_error': self.registration_error,
                'registration': registration,
                'destination': self.state.get('destination'),
                'test': self.state.get('test'),
                'retry_after': max(0, int(self.cooldown - time.time()) + 1)
            })

    def permissions(self, token, chat_id, bot_id):
        chat = self.api(token, 'getChat', {'chat_id': chat_id})
        if chat.get('type') not in ('group', 'supergroup'):
            raise BridgeError('Scegli un gruppo o supergruppo Telegram, non una chat privata o un canale.')
        member = self.api(token, 'getChatMember', {'chat_id': chat_id, 'user_id': bot_id})
        status = member.get('status')
        if status in ('creator', 'administrator'):
            return chat
        if status == 'restricted':
            allowed = member.get('is_member') and member.get('can_send_messages')
        else:
            allowed = status == 'member' and chat.get('permissions', {}).get('can_send_messages', False)
        if not allowed:
            raise BridgeError('Permesso mancante: il bot deve essere membro del gruppo e poter inviare messaggi (can_send_messages).')
        return chat

    def action(self, operation, token=''):
        with self.operations:
            with self.lock:
                current = copy.deepcopy(self.state)
            if operation == 'connect':
                token = token.strip()
                if not re.fullmatch(r'[0-9]+:[A-Za-z0-9_-]{20,200}', token):
                    raise BridgeError('Inserisci un token bot Telegram valido.')
                bot = self.api(token, 'getMe')
                webhook = self.api(token, 'getWebhookInfo')
                if webhook.get('url'):
                    raise BridgeError('Il bot usa già un webhook. Rimuovilo prima di collegare il bot dedicato al long polling.')
                with self.lock:
                    if token != self.state.get('token'):
                        self.state = {'token': token, 'offset': 0}
                        self.registration = None
                        self.registration_error = ''
                        self.last_poll = 0
                    self.state['bot'] = {key: bot[key] for key in ('id', 'username', 'first_name') if key in bot}
                    self.state['verified_at'] = int(time.time())
                    self.poll_error = ''
                    self.save()
                return 'Bot verificato con getMe. Long polling avviato.'
            token = current.get('token')
            if not token:
                raise BridgeError('Collega prima il bot Telegram.')
            if operation == 'verify':
                self.api(token, 'getMe')
                if self.api(token, 'getWebhookInfo').get('url'):
                    raise BridgeError('Webhook attivo: il long polling non può ricevere i comandi.')
                destination = current.get('destination')
                if destination:
                    self.permissions(token, destination['chat_id'], current['bot']['id'])
                with self.lock:
                    self.state['verified_at'] = int(time.time())
                    self.save()
                return 'Identità del bot e permessi della destinazione verificati. Invio e modifica si provano con i pulsanti di test.'
            if operation == 'register':
                with self.lock:
                    self.registration = {'code': secrets.token_hex(16), 'expires': int(time.time()) + 600}
                    self.registration_error = ''
                return 'Comando generato: invialo nel gruppo o topic da collegare entro 10 minuti, poi aggiorna lo stato.'
            if operation not in ('send', 'edit'):
                raise BridgeError('Operazione non valida.')
            destination = current.get('destination')
            if not destination:
                raise BridgeError('Collega prima una destinazione Telegram.')
            self.permissions(token, destination['chat_id'], current['bot']['id'])
            test = current.get('test')
            if operation == 'edit' and not test:
                raise BridgeError('Invia prima una notifica di prova.')
            revision = test.get('revision', 0) + 1 if operation == 'edit' else 0
            rich = self.test_message(revision)
            params = {'chat_id': destination['chat_id'], 'rich_message': rich}
            if operation == 'send':
                if destination.get('message_thread_id'):
                    params['message_thread_id'] = destination['message_thread_id']
                message = self.api(token, 'sendRichMessage', params)
            else:
                params['message_id'] = test['message_id']
                message = self.api(token, 'editMessageText', params)
            if not isinstance(message, dict) or not message.get('message_id') or not message.get('rich_message'):
                raise BridgeError('Telegram non ha confermato un Rich Message. Controlla la chat prima di ripetere il test.')
            if operation == 'edit' and message['message_id'] != test['message_id']:
                raise BridgeError('Telegram ha restituito un message_id diverso: modifica non confermata.')
            with self.lock:
                self.state['test'] = {'message_id': message['message_id'], 'revision': revision,
                                      'updated_at': int(time.time())}
                self.save()
            return (f'Rich Message inviato: message_id {message["message_id"]}.' if operation == 'send'
                    else f'Rich Message aggiornato: message_id {message["message_id"]} invariato, revisione {revision}.')

    def register_message(self, message, token):
        with self.operations:
            with self.lock:
                registration = copy.deepcopy(self.registration)
                current = copy.deepcopy(self.state)
            if token != current.get('token') or not registration or registration['expires'] < time.time():
                return
            text = message.get('text', '')
            bot_name = current['bot'].get('username', '')
            match = re.fullmatch(r'/zabbix_talk(?:@([A-Za-z0-9_]+))?\s+([a-f0-9]{32})', text.strip())
            if not match or (match[1] and match[1].lower() != bot_name.lower()):
                return
            if not secrets.compare_digest(match[2], registration['code']):
                return
            try:
                chat_id = message['chat']['id']
                chat = self.permissions(token, chat_id, current['bot']['id'])
                sender = message.get('from', {})
                if sender.get('is_bot') or message.get('sender_chat') or not sender.get('id'):
                    raise BridgeError('Invia il comando dal tuo account personale, senza modalità amministratore anonimo.')
                actor = self.api(token, 'getChatMember', {'chat_id': chat_id, 'user_id': sender['id']})
                if actor.get('status') not in ('creator', 'administrator'):
                    raise BridgeError('Il comando di collegamento deve essere inviato da un amministratore del gruppo Telegram.')
                thread = message.get('message_thread_id') if message.get('is_topic_message') else None
                destination = {'chat_id': chat_id, 'title': chat.get('title', str(chat_id)),
                               'message_thread_id': thread}
                with self.lock:
                    if (current.get('destination', {}).get('chat_id'), current.get('destination', {}).get('message_thread_id')) != (chat_id, thread):
                        self.state.pop('test', None)
                    self.state['destination'] = destination
                    self.save()
                    self.registration = None
                    self.registration_error = ''
            except BridgeError as error:
                with self.lock:
                    self.registration_error = str(error)

    def poll(self):
        while not self.stop.is_set():
            with self.lock:
                token = self.state.get('token')
                offset = self.state.get('offset', 0)
            if not token:
                self.stop.wait(1)
                continue
            try:
                updates = self.api(token, 'getUpdates', {'offset': offset, 'timeout': 25,
                                                        'allowed_updates': ['message']})
                for update in updates:
                    if 'message' in update:
                        self.register_message(update['message'], token)
                    with self.lock:
                        if token != self.state.get('token'):
                            break
                        self.state['offset'] = update['update_id'] + 1
                        self.save()
                with self.lock:
                    if token == self.state.get('token'):
                        self.poll_error = ''
                        self.last_poll = time.time()
            except BridgeError as error:
                with self.lock:
                    if token == self.state.get('token'):
                        self.poll_error = str(error)
                self.stop.wait(max(5, error.retry_after))
            except (OSError, ValueError, KeyError, TypeError):
                with self.lock:
                    self.poll_error = 'Ricezione interrotta: controlla il servizio e i permessi dello stato locale.'
                self.stop.wait(5)


class Handler(BaseHTTPRequestHandler):
    def setup(self):
        super().setup()
        self.connection.settimeout(45)

    def reply(self, status, data):
        body = json.dumps(data).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.send_header('Cache-Control', 'no-store')
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == '/health':
            self.reply(200, {'service': 'zabbix-talk', 'status': 'ok'})
        elif self.path == '/status' and isinstance(self.server, ControlServer):
            self.reply(200, self.server.bridge.status())
        else:
            self.reply(404, {'error': 'Percorso non disponibile.'})

    def do_POST(self):
        control = isinstance(self.server, ControlServer)
        if not control and self.path == '/notify':
            if not self.server.bridge.authorized(self.headers.get('Authorization', '')):
                self.reply(401, {'error': 'Autenticazione locale non valida.'})
                return
        elif not control or self.path not in ('/action', '/channel'):
            self.reply(404, {'error': 'Percorso non disponibile.'})
            return
        try:
            length = int(self.headers.get('Content-Length', 0))
            if not 0 < length <= 16384:
                raise ValueError
            data = json.loads(self.rfile.read(length))
            if not isinstance(data, dict) or not isinstance(data.get('token', ''), str):
                raise ValueError
            if self.path == '/notify':
                result = self.server.bridge.notify(data)
            elif self.path == '/channel':
                result = self.server.bridge.channel()
            else:
                result = {'message': self.server.bridge.action(data.get('operation'), data.get('token', ''))}
            self.reply(200, result)
        except (ValueError, TypeError):
            self.reply(400, {'error': 'Richiesta non valida.'})
        except BridgeError as error:
            self.reply(400, {'error': str(error)})
        except (OSError, sqlite3.Error):
            self.reply(500, {'error': 'Impossibile salvare lo stato locale. Controlla il servizio prima di ripetere il test.'})

    def log_message(self, format, *args):
        # Request URLs, bodies and exception traces may contain credentials.
        pass


class ControlServer(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True

    def handle_error(self, request, client_address):
        logging.error('Control request failed; no request data logged')


if __name__ == '__main__':
    logging.basicConfig(level=logging.INFO, format='%(levelname)s %(message)s')
    try:
        process_lock = open('/run/zabbix-talk/poller.lock', 'w')
        fcntl.flock(process_lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        bridge = Bridge()
        if os.path.exists(SOCKET):
            os.unlink(SOCKET)
        with ControlServer(SOCKET, Handler) as control, ThreadingHTTPServer(('127.0.0.1', 8765), Handler) as health:
            control.bridge = health.bridge = bridge
            os.chown(SOCKET, -1, grp.getgrnam('www-data').gr_gid)
            os.chmod(SOCKET, 0o660)
            threading.Thread(target=bridge.poll, daemon=True).start()
            threading.Thread(target=control.serve_forever, daemon=True).start()
            logging.info('Zabbix Talk ready: local health and protected control socket')
            health.serve_forever()
    except (OSError, ValueError):
        logging.error('Bridge startup failed; check runtime/state permissions and single service instance')
        raise SystemExit(1) from None
