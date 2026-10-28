<?php

$current_section = $data['section'];
$sections = ['overview' => _('Panoramica'), 'destinations' => _('Destinazioni'),
    'notifications' => _('Notifiche'), 'settings' => _('Impostazioni'), 'tests' => _('Collaudi')];
$url = static fn(string $section): CUrl => (new CUrl('zabbix.php'))
    ->setArgument('action', in_array($section, ['destinations', 'notifications'])
        ? 'zabbix.talk.destinations' : 'zabbix.talk.overview')->setArgument('section', $section);
$links = new CList();
foreach ($sections as $key => $caption) {
    $link = (new CLink($caption, $url($key)))->addClass('ui-tabs-anchor')->setAttribute('data-talk-section', $key);
    if ($key === $current_section) { $link->setAttribute('aria-current', 'page'); }
    $links->addItem((new CListItem($link))->addClass($key === $current_section ? 'ui-tabs-active' : ''));
}
$navigation = (new CTag('nav', true, $links->addClass('ui-tabs-nav')))
    ->setAttribute('aria-label', _('Sezioni Zabbix Talk'))->addClass('talk-navigation');
$section = static fn(string $title, $body): CSection => (new CSection($body))
    ->setHeader(new CTag('h4', true, $title));
$help = static fn(string $title, $body): CTag => (new CTag('details', true, [
    new CTag('summary', true, $title), new CDiv($body)
]))->addClass('talk-help');
$destination_label = static function (array $destination) use ($data): string {
    $label = $destination['title'].' / '.
        (!empty($destination['message_thread_id']) ? _('Topic').' '.$destination['message_thread_id'] : _('Generale'));
    foreach ($data['destinations'] ?? $data['telegram']['destinations'] ?? [] as $other) {
        if ($other['title'] === $destination['title']
                && (string) $other['chat_id'] !== (string) $destination['chat_id']
                && ($other['message_thread_id'] ?? '') === ($destination['message_thread_id'] ?? '')) {
            return $label.' · chat '.$destination['chat_id'];
        }
    }
    return $label;
};
$update_form = static function (string $id) use ($current_section): CForm {
    return (new CForm('post', 'zabbix.php?action=zabbix.talk.update'))->setId($id)
        ->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('zabbix.talk.update')))->removeId())
        ->addItem((new CVar('section', $current_section === 'notifications' ? 'destinations' : $current_section))->removeId());
};
$button = static fn(string $label, string $operation, bool $enabled = true): CSubmitButton =>
    (new CSubmitButton($label, 'operation', $operation))->setEnabled($enabled);
$refresh = static fn(string $label): CSimpleButton => (new CSimpleButton($label))
    ->setAttribute('data-talk-refresh', '1')->addClass(ZBX_STYLE_BTN_ALT);
