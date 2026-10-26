(() => {
    const feedback = document.getElementById('talk-refresh-feedback');
    const refreshButtons = document.querySelectorAll('[data-talk-refresh]');
    let refreshing = false;

    const updateControls = (data) => {
        const connected = data.bridge_reachable;
        const state = data.telegram;
        const allowed = {
            connect: connected && data.secure_connection,
            verify: connected && !!state.bot,
            register: connected && !!state.bot,
            send: connected && !!state.destination,
            edit: connected && !!state.test,
            provision: connected && !!state.destination,
            transport: connected && data.notifications.ready,
            prepare_test: connected && data.notifications.ready && !data.notifications.config.fixture,
            problem_test: connected && data.notifications.ready && !!data.notifications.config.fixture,
            remove_test: !!data.notifications.config.fixture
        };
        document.querySelectorAll('button[name="operation"]').forEach(button => {
            button.disabled = !allowed[button.value];
        });
        document.getElementById('token').disabled = !allowed.connect;
    };

    refreshButtons.forEach(button => button.addEventListener('click', async () => {
        if (refreshing) {
            return;
        }
        refreshing = true;
        refreshButtons.forEach(control => control.disabled = true);
        feedback.textContent = <?= json_encode(_('Aggiornamento in corso…')) ?>;
        try {
            const response = await fetch('zabbix.php?action=zabbix.talk.status', {
                credentials: 'same-origin', cache: 'no-store'
            });
            if (!response.ok) {
                throw new Error();
            }
            const data = await response.json();
            if (!data.labels || !data.telegram) {
                throw new Error();
            }
            for (const [key, value] of Object.entries(data.labels)) {
                document.getElementById(`talk-label-${key}`).textContent = value;
            }
            const bridge = document.getElementById('talk-label-bridge');
            bridge.classList.toggle('green', data.bridge_reachable);
            bridge.classList.toggle('red', !data.bridge_reachable);
            document.getElementById('talk-registration').hidden = !data.telegram.registration;
            document.getElementById('talk-copy-feedback').textContent = '';
            updateControls(data);
            feedback.textContent = data.bridge_reachable
                ? (data.telegram.registration
                    ? <?= json_encode(_('Comando non ancora ricevuto: copialo, invialo in Telegram e aggiorna nuovamente il collegamento.')) ?>
                    : data.telegram.destination
                    ? <?= json_encode(_('Stato aggiornato. Destinazione collegata: puoi eseguire la prova al punto 3.')) ?>
                    : <?= json_encode(_('Stato aggiornato. Il collegamento è ancora in attesa: invia il comando in Telegram e riprova.')) ?>)
                : <?= json_encode(_('Bridge non raggiungibile. Verifica il servizio e riprova.')) ?>;
        }
        catch {
            feedback.textContent = <?= json_encode(_('Stato non aggiornato. Verifica la connessione o accedi nuovamente a Zabbix, poi riprova.')) ?>;
        }
        finally {
            refreshing = false;
            refreshButtons.forEach(control => control.disabled = false);
        }
    }));

    const setup = document.getElementById('talk-notifications');
    setup.addEventListener('submit', event => {
        if (!document.getElementById('confirm').checked) {
            event.preventDefault();
            feedback.textContent = <?= json_encode(_('Seleziona la conferma amministratore prima di attivare le notifiche.')) ?>;
            document.getElementById('confirm').focus();
        }
    });
    const copy = document.getElementById('talk-copy');
    copy.addEventListener('click', async () => {
        const message = document.getElementById('talk-copy-feedback');
        try {
            await writeTextClipboard(document.getElementById('talk-label-command').textContent);
            message.textContent = <?= json_encode(_('Comando copiato. Incollalo nel gruppo o topic scelto in Telegram.')) ?>;
        }
        catch {
            message.textContent = <?= json_encode(_('Copia non riuscita: seleziona il comando e copialo manualmente.')) ?>;
        }
    });
    if (!document.getElementById('talk-registration').hidden) {
        copy.focus();
    }
})();
