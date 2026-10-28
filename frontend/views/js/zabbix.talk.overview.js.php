(() => {
    const root = document.querySelector('.talk-ui');
    const feedback = document.getElementById('talk-refresh-feedback');
    const refreshButtons = document.querySelectorAll('[data-talk-refresh]');
    const destinationLabel = destination => `${destination.title} / ${destination.message_thread_id ? 'Topic ' + destination.message_thread_id : 'Generale'}`;
    let refreshing = false;

    const updateControls = data => {
        const state = data.telegram;
        const connected = data.bridge_reachable;
        const allowed = {
            connect: connected && data.secure_connection,
            verify: connected && !!state.bot,
            register: connected && !!state.bot,
            send: connected && !!state.destination,
            edit: connected && !!state.test,
            select: connected && !!state.destination,
            create_topic: connected && !!state.destination,
            transport: connected && data.notifications.ready,
            prepare_test: connected && data.notifications.ready && !data.notifications.config.fixture,
            problem_test: connected && data.notifications.ready && !!data.notifications.config.fixture,
            recovery_test: connected && data.notifications.ready && !!data.notifications.config.recovery_enabled && !!data.notifications.config.fixture,
            remove_test: !!data.notifications.config.fixture
        };
        root.querySelectorAll('button[name="operation"]').forEach(button => {
            if (Object.hasOwn(allowed, button.value)) button.disabled = !allowed[button.value];
        });
        const select = root.querySelector('#talk-select z-select');
        if (select) {
            for (const [value, destination] of Object.entries(state.destinations || {})) {
                if (!select.getOptionByValue(value)) select.addOption({value, label: destinationLabel(destination)});
            }
            if (state.destination) select.value = `${state.destination.chat_id}:${state.destination.message_thread_id || ''}`;
        }
        const token = document.getElementById('token');
        if (token) token.disabled = !allowed.connect;
        const testId = document.getElementById('talk-test-id');
        if (testId) testId.textContent = state.test ? `message_id ${state.test.message_id}` : '—';
        root.dispatchEvent(new CustomEvent('talk:status', {detail: data}));
    };

    refreshButtons.forEach(button => button.addEventListener('click', async () => {
        if (refreshing) return;
        refreshing = true;
        refreshButtons.forEach(control => control.disabled = true);
        feedback.textContent = <?= json_encode(_('Aggiornamento in corso…')) ?>;
        try {
            const response = await fetch('zabbix.php?action=zabbix.talk.status', {
                credentials: 'same-origin', cache: 'no-store'
            });
            if (!response.ok) throw new Error();
            const data = await response.json();
            if (!data.labels || !data.telegram) throw new Error();
            for (const [key, value] of Object.entries(data.labels)) {
                const label = document.getElementById(`talk-label-${key}`);
                if (label) label.textContent = value;
            }
            const bridge = document.getElementById('talk-label-bridge');
            if (bridge) {
                bridge.classList.toggle('green', data.bridge_reachable);
                bridge.classList.toggle('red', !data.bridge_reachable);
            }
            document.getElementById('talk-label-conflicts')?.classList.toggle('red', !!data.conflicts);
            const registration = document.getElementById('talk-registration');
            if (registration) registration.hidden = !data.telegram.registration;
            const copyFeedback = document.getElementById('talk-copy-feedback');
            if (copyFeedback) copyFeedback.textContent = '';
            updateControls(data);
            feedback.textContent = data.bridge_reachable
                ? (registration && data.telegram.registration
                    ? <?= json_encode(_('Comando non ancora ricevuto: invialo in Telegram e aggiorna il collegamento.')) ?>
                    : <?= json_encode(_('Stato aggiornato.')) ?>)
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

    const copy = document.getElementById('talk-copy');
    copy?.addEventListener('click', async () => {
        const message = document.getElementById('talk-copy-feedback');
        try {
            await writeTextClipboard(document.getElementById('talk-label-command').textContent);
            message.textContent = <?= json_encode(_('Comando copiato. Incollalo nel gruppo o topic scelto in Telegram.')) ?>;
        }
        catch {
            message.textContent = <?= json_encode(_('Copia non riuscita: seleziona il comando e copialo manualmente.')) ?>;
        }
    });
})();
