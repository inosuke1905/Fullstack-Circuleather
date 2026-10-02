(() => {
    const center = document.querySelector('[data-notification-center]');
    if (!center) return;

    const trigger = center.querySelector('.notification-trigger');
    const panel = center.querySelector('.notification-panel');
    const list = center.querySelector('.notification-list');
    const empty = center.querySelector('.notification-empty');
    const badge = center.querySelector('.notification-count');
    const toasts = center.querySelector('.notification-toasts');
    const csrfToken = document.body.dataset.csrfToken || '';
    const endpoint = 'actions/notifications.php';
    let polling = false;

    const safeUrl = (url) => typeof url === 'string' && /^\?page=[a-z-]+(?:&[a-zA-Z0-9_=%-]*)*$/.test(url)
        ? url
        : '?page=inventory';

    const activityLine = (item) => {
        const english = (center.dataset.timeLocale || '').startsWith('en');
        const isPiece = (item.target_url || '').startsWith('?page=piece-detail');
        const kind = item.type === 'low_stock' || item.type === 'inventory_activity'
            ? (english ? (isPiece ? 'Leather piece' : 'Batch') : (isPiece ? 'Leerstuk' : 'Batch'))
            : item.type === 'order_activity'
                ? (english ? 'Order' : 'Bestelling')
                : 'Account';
        const date = new Date(String(item.created_at || '').replace(' ', 'T'));
        const time = Number.isNaN(date.getTime())
            ? String(item.created_at || '')
            : new Intl.DateTimeFormat(center.dataset.timeLocale || undefined, {
                dateStyle: 'medium',
                timeStyle: 'short',
            }).format(date);
        const action = item.event_action || 'updated';
        const verb = item.type === 'inventory_activity'
            ? english
                ? ({ created: 'created', deleted: 'deleted', updated: 'changed' }[action] || 'changed')
                : ({ created: 'toegevoegd', deleted: 'verwijderd', updated: 'gewijzigd' }[action] || 'gewijzigd')
            : english ? 'changed' : 'gewijzigd';
        const label = english ? `${kind} ${verb} at` : `${kind} ${verb} om`;
        const actor = item.actor_name || (english ? 'System' : 'Systeem');
        return `${label} ${time} ${english ? 'by' : 'door'} ${actor}`;
    };

    const notifyRead = async (id) => {
        const body = new URLSearchParams({ action: 'read', id: String(id), csrf_token: csrfToken });
        await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin' });
        await poll();
    };

    const buildMessage = (item) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'notification-copy';
        const title = document.createElement('strong');
        title.textContent = item.title || '';
        const message = document.createElement('span');
        message.textContent = item.message || '';
        const activity = document.createElement('small');
        activity.className = 'notification-meta';
        activity.textContent = activityLine(item);
        wrapper.append(title, message, activity);
        return wrapper;
    };

    const showToast = (item) => {
        const toast = document.createElement('article');
        toast.className = 'notification-toast';
        toast.setAttribute('role', 'status');
        const link = document.createElement('a');
        link.href = safeUrl(item.target_url);
        link.append(buildMessage(item));
        link.addEventListener('click', async (event) => {
            event.preventDefault();
            await notifyRead(item.id);
            window.location.href = link.href;
        });
        const close = document.createElement('button');
        close.className = 'notification-dismiss';
        close.type = 'button';
        close.setAttribute('aria-label', center.dataset.closeLabel || 'Dismiss notification');
        close.textContent = '×';
        close.addEventListener('click', () => toast.remove());
        toast.append(link, close);
        toasts.append(toast);
        window.setTimeout(() => toast.remove(), 9000);
    };

    const renderList = (items) => {
        list.replaceChildren();
        for (const item of items) {
            const row = document.createElement('li');
            const link = document.createElement('a');
            link.href = safeUrl(item.target_url);
            link.append(buildMessage(item));
            link.addEventListener('click', async (event) => {
                event.preventDefault();
                await notifyRead(item.id);
                window.location.href = link.href;
            });
            const readButton = document.createElement('button');
            readButton.type = 'button';
            readButton.className = 'notification-mark-read';
            readButton.textContent = center.dataset.readLabel || 'Mark as read';
            readButton.addEventListener('click', () => notifyRead(item.id));
            row.append(link, readButton);
            list.append(row);
        }
        empty.hidden = items.length > 0;
    };

    const poll = async () => {
        if (polling) return;
        polling = true;
        try {
            const response = await fetch(`${endpoint}?action=feed`, { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) return;
            const data = await response.json();
            const unreadCount = Number(data.unreadCount) || 0;
            badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
            badge.hidden = unreadCount === 0;
            trigger.setAttribute('aria-label', unreadCount
                ? `${center.dataset.label || 'Notifications'}, ${unreadCount}`
                : (center.dataset.label || 'Notifications'));
            renderList(Array.isArray(data.recent) ? data.recent : []);
            for (const item of (Array.isArray(data.notifications) ? data.notifications : []).reverse()) {
                showToast(item);
            }
        } catch (error) {
            console.error('Could not load notifications.', error);
        } finally {
            polling = false;
        }
    };

    trigger.addEventListener('click', () => {
        panel.hidden = !panel.hidden;
        trigger.setAttribute('aria-expanded', String(!panel.hidden));
        if (!panel.hidden) poll();
    });
    center.querySelector('.notification-mark-all').addEventListener('click', async () => {
        const body = new URLSearchParams({ action: 'read_all', csrf_token: csrfToken });
        await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin' });
        await poll();
    });
    document.addEventListener('click', (event) => {
        if (!center.contains(event.target)) {
            panel.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        }
    });

    poll();
    window.setInterval(poll, 45000);
})();