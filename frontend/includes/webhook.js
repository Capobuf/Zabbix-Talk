var p = JSON.parse(value), request = new HttpRequest();
request.setProxy('');
request.addHeader('Content-Type: application/json');
request.addHeader('Authorization: Bearer ' + p.channel_key);
var response = request.post('http://127.0.0.1:8765/notify', JSON.stringify({
    kind: p.kind, eventid: p.eventid, hostid: p.hostid, host: p.host,
    problem: p.problem, severity: p.severity, occurred_at: p.occurred_at,
    severity_name: p.severity_name, duration: p.duration,
    event_value: p.kind === 'recovery' ? p.recovery_value : p.event_value, bot_id: p.bot_id,
    recovery_eventid: p.recovery_eventid, recovered_at: p.recovered_at,
    chat_id: p.chat_id, message_thread_id: p.message_thread_id
}));
if (request.getStatus() !== 200) {
    var detail;
    try { detail = JSON.parse(response).error; } catch (e) { detail = 'Risposta locale non valida.'; }
    throw 'Bridge Zabbix Talk: ' + (detail || 'Invio non confermato.');
}
return response;
