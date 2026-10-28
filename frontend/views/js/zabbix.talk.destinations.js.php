(() => {
    const root = document.querySelector('.talk-ui');
    const form = document.getElementById('talk-routing');
    const section = form.querySelector('[name="section"]');
    let dirty = false;
    const showSection = name => {
        const notifications = name === 'notifications';
        document.getElementById('talk-panel-destinations').hidden = notifications;
        document.getElementById('talk-routing-destinations').hidden = notifications;
        document.getElementById('talk-panel-notifications').hidden = !notifications;
        section.value = name;
        const action = new URL(form.action);
        action.searchParams.set('section', name);
        form.action = action.href;
        root.querySelectorAll('[data-talk-section]').forEach(link => {
            const active = link.dataset.talkSection === name;
            link.parentElement.classList.toggle('ui-tabs-active', active);
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
        const title = `Zabbix Talk · ${notifications ? 'Notifiche' : 'Destinazioni'}`;
        document.getElementById('page-title-general').textContent = title;
        document.title = title;
    };
    root.querySelectorAll('[data-talk-section]').forEach(link => {
        if (!['destinations', 'notifications'].includes(link.dataset.talkSection)) return;
        link.addEventListener('click', event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
            event.preventDefault();
            showSection(link.dataset.talkSection);
            history.pushState(null, '', link.href);
        });
    });
    window.addEventListener('popstate', () => showSection(new URL(location.href).searchParams.get('section') === 'notifications' ? 'notifications' : 'destinations'));
    form.addEventListener('change', event => {
        if (event.target.name === 'confirm') return;
        dirty = true;
        document.getElementById('talk-unsaved').textContent = <?= json_encode(_('Modifiche non salvate.')) ?>;
    });
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', event => {
        if (dirty) { event.preventDefault(); event.returnValue = ''; }
    });
    root.addEventListener('talk:status', event => {
        const data = event.detail;
        document.getElementById('talk-destination-feedback').textContent = data.labels.diagnostics;
        if (!data.bridge_reachable) return;
        const destinations = data.telegram.destinations || {};
        const current = data.telegram.destination;
        const currentKey = current ? `${current.chat_id}:${current.message_thread_id || ''}` : '';
        document.getElementById('talk-topic-group').textContent = current ? current.title : '—';
        const table = document.getElementById('talk-registered');
        if (Object.keys(destinations).length) {
            table.classList.remove('no-data', 'no-data-without-icon');
            table.querySelector('tbody').replaceChildren();
            for (const [key, destination] of Object.entries(destinations)) {
                const row = document.createElement('tr');
                const labels = [destination.title, destination.message_thread_id ? `Topic ${destination.message_thread_id}` : 'Generale',
                    key === currentKey ? <?= json_encode(_('Per collaudi e nuovo topic')) ?> : '—'];
                for (const label of labels) {
                    const cell = document.createElement('td'); cell.textContent = label; row.append(cell);
                }
                const details = document.createElement('details');
                details.className = 'talk-help';
                const summary = document.createElement('summary');
                summary.textContent = <?= json_encode(_('Dettagli tecnici')) ?>;
                const chat = document.createElement('div');
                chat.textContent = `chat_id ${destination.chat_id}`;
                details.append(summary, chat); row.firstElementChild.append(details);
                const action = document.createElement('td');
                const selectForm = document.getElementById('talk-select-template').content.firstElementChild.cloneNode(true);
                selectForm.removeAttribute('id');
                selectForm.querySelector('[name="destination_key"]').value = key;
                action.append(selectForm); row.append(action); table.querySelector('tbody').append(row);
                form.querySelectorAll('z-select[name^="mapping["]').forEach(select => {
                    if (!select.getOptionByValue(key)) select.addOption({value: key, label: `${destination.title} / ${destination.message_thread_id ? 'Topic ' + destination.message_thread_id : 'Generale'}`});
                });
            }
        }
    });
})();
