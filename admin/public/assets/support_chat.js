/**
 * Operator support panel on /support.
 * Polls /api/support/chats every 5s for the sidebar and /api/support/chats/{memberId}
 * every 3s while a chat is open. Take-over toggles AI ↔ Human mode per chat.
 */
(function () {
    const POLL_LIST_MS = 5000;
    const POLL_CHAT_MS = 3000;

    const root = document.querySelector('.support-root');
    if (!root) return;
    const operatorEmail = root.dataset.operatorEmail || '';

    // Sidebar
    const $chats = document.getElementById('support-chats');
    const $search = document.getElementById('support-search');
    const $pollStatus = document.getElementById('support-poll-status');
    const $refresh = document.getElementById('support-refresh');

    // Empty state + chat panel
    const $empty = document.getElementById('support-empty');
    const $chat  = document.getElementById('support-chat');
    const $avatar = document.getElementById('support-chat-avatar');
    const $domain = document.getElementById('support-domain');
    const $instance = document.getElementById('support-instance');
    const $statePill = document.getElementById('support-state-pill');
    const $payPill = document.getElementById('support-pay-pill');
    const $plan = document.getElementById('support-plan');
    const $locale = document.getElementById('support-locale');
    const $member = document.getElementById('support-member');
    const $modeLabel = document.getElementById('support-mode-label');
    const $modeBtn = document.getElementById('support-mode-btn');
    const $messages = document.getElementById('support-messages');
    const $text = document.getElementById('support-text');
    const $send = document.getElementById('support-send');

    let activeMemberId = null;
    let activeMode = 'ai';
    let listInflight = false;
    let chatInflight = false;
    let listTimer = null;
    let chatTimer = null;
    let allChats = [];
    let renderedIds = new Set();
    let searchTerm = '';

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
            '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
        })[c]);
    }
    function fmtTime(ms) {
        if (!ms) return '';
        const d = new Date(ms);
        const pad = n => String(n).padStart(2,'0');
        const today = new Date();
        if (d.toDateString() === today.toDateString()) {
            return pad(d.getHours()) + ':' + pad(d.getMinutes());
        }
        return pad(d.getDate()) + '.' + pad(d.getMonth()+1) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    function initials(s) {
        const str = String(s || '?');
        return str.replace(/[^A-Za-zА-Яа-я0-9]/g,'').slice(0,2).toUpperCase() || '?';
    }
    function safeCls(s) {
        return String(s || '').replace(/[^a-zA-Z0-9_-]/g, '');
    }

    async function api(path, opts) {
        opts = opts || {};
        const r = await fetch(path, Object.assign({ credentials: 'same-origin' }, opts));
        const j = await r.json().catch(() => ({}));
        if (!r.ok) {
            const e = new Error(j.error || ('http ' + r.status));
            e.status = r.status; e.body = j; throw e;
        }
        return j;
    }

    function renderChats() {
        const term = searchTerm.toLowerCase();
        const filtered = term
            ? allChats.filter(c =>
                (c.domain || '').toLowerCase().includes(term) ||
                (c.member_id || '').toLowerCase().includes(term) ||
                (c.idInstance || '').toLowerCase().includes(term) ||
                (c.last_message || '').toLowerCase().includes(term))
            : allChats.slice();
        if (filtered.length === 0) {
            $chats.innerHTML = '<li class="wweb-chats-empty">No chats yet.</li>';
            return;
        }
        // Sort: unread first, then by most recent.
        filtered.sort((a, b) => {
            if ((b.unread_for_operator || 0) - (a.unread_for_operator || 0) !== 0)
                return (b.unread_for_operator || 0) - (a.unread_for_operator || 0);
            return (b.updatedAt || 0) - (a.updatedAt || 0);
        });
        const html = filtered.map(c => {
            const active = c.member_id === activeMemberId ? ' active' : '';
            const unread = c.unread_for_operator > 0
                ? `<span class="unread-badge">${c.unread_for_operator}</span>` : '';
            const mode = (c.mode === 'human') ? 'human' : 'ai';
            const domainHtml = escapeHtml(c.domain || c.member_id || '?');
            return `<li class="support-chat-item${active}" data-member-id="${escapeHtml(c.member_id)}">
                <span class="domain">${domainHtml}</span>
                ${unread}
                <span class="last">${escapeHtml(c.last_role ? `[${c.last_role}] ` : '')}${escapeHtml(c.last_message || '')}</span>
                <span class="meta-line">
                    ${c.idInstance ? `#${escapeHtml(c.idInstance)}` : 'no instance'}
                    <span class="pill mode-${mode}">${mode}</span>
                    <span>${escapeHtml(c.state || '')}</span>
                    ${c.paymentStatus ? `<span>· ${escapeHtml(c.paymentStatus)}</span>` : ''}
                    <span style="margin-left:auto">${escapeHtml(fmtTime(c.updatedAt))}</span>
                </span>
            </li>`;
        }).join('');
        $chats.innerHTML = html;
        $chats.querySelectorAll('.support-chat-item').forEach(li => {
            li.addEventListener('click', () => openChat(li.dataset.memberId));
        });
    }

    let pendingUrlId = null;
    function consumePendingUrlId() {
        if (!pendingUrlId) return;
        const exists = allChats.some(c => c.member_id === pendingUrlId);
        const id = pendingUrlId;
        pendingUrlId = null;
        if (exists) openChat(id);
        else openChat(id); // open anyway — chat endpoint will load it
    }

    async function pollList() {
        if (listInflight) return;
        listInflight = true;
        try {
            const j = await api('/api/support/chats');
            allChats = j.chats || [];
            $pollStatus.textContent = `${allChats.length} chats`;
            renderChats();
            consumePendingUrlId();
        } catch (e) {
            $pollStatus.textContent = 'offline';
            if (e.status === 401) {
                location.href = '/login';
                return;
            }
        } finally {
            listInflight = false;
        }
    }

    function updateInfoCard(portal, memberId) {
        $avatar.textContent = initials(portal.domain);
        $domain.textContent = portal.domain || '(unknown)';
        $instance.textContent = portal.idInstance ? `Instance #${portal.idInstance}` : 'no instance';
        const stateRaw = portal.state || 'unknown';
        $statePill.textContent = stateRaw;
        $statePill.className = 'support-state-pill s-' + safeCls(stateRaw);
        const pay = portal.paymentStatus;
        if (pay && pay !== 'unknown' && pay !== 'none') {
            $payPill.hidden = false;
            $payPill.textContent = pay;
            $payPill.className = 'support-pay-pill p-' + safeCls(pay);
        } else {
            $payPill.hidden = true;
        }
        $plan.textContent = portal.planDisplay ? '· ' + portal.planDisplay : '';
        $locale.textContent = portal.locale ? 'locale: ' + portal.locale : '';
        $member.textContent = memberId;
    }

    function updateMode(mode) {
        activeMode = mode;
        const isHuman = mode === 'human';
        $modeLabel.textContent = 'mode: ' + mode;
        $modeLabel.className = 'support-mode-label ' + (isHuman ? 'mode-human' : 'mode-ai');
        $modeBtn.textContent = isHuman ? 'Return to AI' : 'Take over';
        $modeBtn.className = 'support-mode-btn' + (isHuman ? ' is-human' : '');
        $text.disabled = !isHuman;
        $send.disabled = !isHuman;
        $text.placeholder = isHuman
            ? 'Type your reply…'
            : 'AI is replying automatically. Click "Take over" to step in.';
    }

    function renderMessage(m) {
        if (renderedIds.has(m.id)) return;
        renderedIds.add(m.id);
        const role = m.role || 'customer';
        const div = document.createElement('div');
        div.className = 'wweb-bubble role-' + safeCls(role);
        const author = role === 'ai' ? '🤖 AI assistant'
            : role === 'operator' ? ('👤 ' + (m.operator_email || 'operator'))
            : '👤 Customer';
        div.innerHTML = `<div class="author">${escapeHtml(author)}</div>
                         <div class="text">${escapeHtml(m.text)}</div>
                         <div class="time">${escapeHtml(fmtTime(m.ts))}</div>`;
        $messages.appendChild(div);
    }
    function scrollDown() { $messages.scrollTop = $messages.scrollHeight; }

    async function pollChat(markRead) {
        if (!activeMemberId || chatInflight) return;
        chatInflight = true;
        try {
            const q = markRead ? '?mark_read=1' : '';
            const j = await api(`/api/support/chats/${encodeURIComponent(activeMemberId)}${q}`);
            if (j.portal) updateInfoCard(j.portal, activeMemberId);
            if (j.mode)  updateMode(j.mode);
            (j.messages || []).forEach(renderMessage);
            scrollDown();
        } catch (e) {
            if (e.status === 401) { location.href = '/login'; return; }
        } finally {
            chatInflight = false;
        }
    }

    function syncUrl(memberId) {
        const u = new URL(window.location.href);
        if (memberId) u.searchParams.set('id', memberId);
        else u.searchParams.delete('id');
        history.replaceState(null, '', u.pathname + (u.search ? u.search : '') + u.hash);
    }

    function openChat(memberId) {
        if (activeMemberId === memberId) return;
        activeMemberId = memberId;
        renderedIds = new Set();
        $messages.innerHTML = '';
        $empty.hidden = true;
        $chat.hidden = false;
        syncUrl(memberId);
        renderChats(); // refresh active state in sidebar
        pollChat(true);
        if (chatTimer) clearInterval(chatTimer);
        chatTimer = setInterval(() => pollChat(false), POLL_CHAT_MS);
    }

    async function sendMessage() {
        if (!activeMemberId) return;
        const text = $text.value.trim();
        if (!text) return;
        $send.disabled = true;
        try {
            await api(`/api/support/chats/${encodeURIComponent(activeMemberId)}/send`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ text }),
            });
            $text.value = '';
            $text.style.height = 'auto';
            await pollChat(false);
        } catch (e) {
            alert('Failed to send: ' + (e.body && e.body.error || e.message));
        } finally {
            $send.disabled = activeMode !== 'human';
        }
    }

    async function toggleMode() {
        if (!activeMemberId) return;
        const next = activeMode === 'human' ? 'ai' : 'human';
        $modeBtn.disabled = true;
        try {
            await api(`/api/support/chats/${encodeURIComponent(activeMemberId)}/mode`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mode: next }),
            });
            updateMode(next);
            if (next === 'human') $text.focus();
        } catch (e) {
            alert('Failed to switch mode: ' + (e.body && e.body.error || e.message));
        } finally {
            $modeBtn.disabled = false;
        }
    }

    // Wire up listeners
    $refresh.addEventListener('click', pollList);
    $send.addEventListener('click', sendMessage);
    $modeBtn.addEventListener('click', toggleMode);
    $text.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey && !$text.disabled) {
            e.preventDefault();
            sendMessage();
        }
    });
    $text.addEventListener('input', () => {
        $text.style.height = 'auto';
        $text.style.height = Math.min(150, $text.scrollHeight) + 'px';
    });
    $search.addEventListener('input', () => {
        searchTerm = $search.value.trim();
        renderChats();
    });

    // Kick things off
    try {
        const initId = new URL(window.location.href).searchParams.get('id');
        if (initId) pendingUrlId = initId;
    } catch (_) {}
    pollList();
    listTimer = setInterval(pollList, POLL_LIST_MS);

    window.addEventListener('popstate', () => {
        try {
            const id = new URL(window.location.href).searchParams.get('id');
            if (id && id !== activeMemberId) openChat(id);
        } catch (_) {}
    });
})();
