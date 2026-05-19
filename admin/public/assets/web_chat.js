'use strict';

// WhatsApp Web-like UI for iqteco-wa-admin
// Все методы (text/file/image/upload/location/contact/forward/edit/delete/markRead)
// идут через /api/instances/{id}/proxy/{method}

(function () {
    const root = document.querySelector('.wweb-root');
    if (!root) return;

    const INSTANCE_ID = root.dataset.instanceId;
    const SELF_WID = root.dataset.instanceWid;
    const POLL_INTERVAL_MS = 5000;

    // --- State ---
    const state = {
        chats: new Map(),          // chatId -> { chatId, name, lastMessage, timestamp, ... }
        activeChatId: null,
        messages: new Map(),       // chatId -> [msg, ...]
        seenIds: new Map(),        // chatId -> Set(idMessage)
        avatars: new Map(),        // chatId -> dataURL/URL
        selected: null,            // idMessage (для forward/reply)
    };

    // --- API ---
    function apiUrl(path) { return `/api/instances/${INSTANCE_ID}${path}`; }

    async function call(method, body, opts = {}) {
        const url = apiUrl(`/proxy/${method}`) + (opts.qs ? '?' + new URLSearchParams(opts.qs).toString() : '');
        const init = {
            method: body || opts.post ? 'POST' : 'GET',
            credentials: 'same-origin',
            headers: opts.multipart ? {} : { 'Content-Type': 'application/json' },
        };
        if (body && !opts.multipart) init.body = JSON.stringify(body);
        else if (body && opts.multipart) init.body = body;
        try {
            const res = await fetch(url, init);
            const text = await res.text();
            try { return JSON.parse(text); } catch { return { _raw: text, _status: res.status }; }
        } catch (e) {
            return { error: 'transport', message: e.message };
        }
    }

    async function fetchChatList() {
        try {
            const res = await fetch(apiUrl('/chat-list?minutes=10080'), { credentials: 'same-origin' });
            return await res.json();
        } catch { return []; }
    }

    async function fetchAvatar(chatId) {
        if (state.avatars.has(chatId)) return state.avatars.get(chatId);
        try {
            const res = await fetch(apiUrl('/avatar') + '?chatId=' + encodeURIComponent(chatId), { credentials: 'same-origin' });
            const d = await res.json().catch(() => ({}));
            const url = d.urlAvatar || '';
            state.avatars.set(chatId, url);   // cache empty result too
            return url;
        } catch {
            state.avatars.set(chatId, '');    // negative cache on network error
            return '';
        }
    }

    // --- Avatar load: concurrency-limited queue + IntersectionObserver ---
    const AVATAR_CONCURRENCY = 4;
    let _avatarActive = 0;
    const _avatarQueue = [];
    let _avatarObserver = null;

    function _applyAvatar(el, url) {
        if (!url) return;
        el.style.backgroundImage = `url("${url}")`;
        el.classList.add('has-image');
        el.textContent = '';
    }

    function _avatarWorker() {
        while (_avatarActive < AVATAR_CONCURRENCY && _avatarQueue.length) {
            const chatId = _avatarQueue.shift();
            _avatarActive++;
            fetchAvatar(chatId).finally(() => {
                _avatarActive--;
                const url = state.avatars.get(chatId);
                if (url) {
                    document.querySelectorAll(
                        '.wweb-avatar[data-avatar="' + (window.CSS && CSS.escape ? CSS.escape(chatId) : chatId.replace(/"/g, '\\"')) + '"]'
                    ).forEach((el) => _applyAvatar(el, url));
                }
                _avatarWorker();
            });
        }
    }

    function ensureAvatarObserver() {
        if (_avatarObserver) return _avatarObserver;
        _avatarObserver = new IntersectionObserver((entries) => {
            for (const e of entries) {
                if (!e.isIntersecting) continue;
                const el = e.target;
                _avatarObserver.unobserve(el);
                const cid = el.dataset.avatar;
                if (!cid) continue;
                if (state.avatars.has(cid)) {
                    _applyAvatar(el, state.avatars.get(cid));
                    continue;
                }
                if (!_avatarQueue.includes(cid)) _avatarQueue.push(cid);
                _avatarWorker();
            }
        }, { root: document.getElementById('wweb-chats'), rootMargin: '120px' });
        return _avatarObserver;
    }

    // --- DOM helpers ---
    const $ = (sel) => document.querySelector(sel);

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])
        );
    }
    function shortJid(jid) {
        if (!jid) return '';
        const m = String(jid).match(/^(\d+)@(c|g)\.us$/);
        return m ? (m[2] === 'g' ? 'group ' + m[1] : '+' + m[1]) : jid;
    }
    function initials(name) {
        const s = String(name || '?').trim();
        if (/^\d/.test(s)) return s.slice(-2);
        return s.split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase();
    }
    function fmtTime(ts) {
        if (!ts) return '';
        const d = new Date(ts * 1000);
        const today = new Date();
        if (d.toDateString() === today.toDateString()) {
            return d.toTimeString().slice(0, 5);
        }
        return d.toLocaleDateString() + ' ' + d.toTimeString().slice(0, 5);
    }
    function normalizeChatId(v) {
        v = String(v || '').trim();
        if (v.endsWith('@c.us') || v.endsWith('@g.us')) return v;
        const digits = v.replace(/\D/g, '');
        return digits ? digits + '@c.us' : '';
    }

    // --- Sidebar render ---
    function renderChats() {
        const list = [...state.chats.values()].sort((a, b) => (b.timestamp || 0) - (a.timestamp || 0));
        const filter = ($('#wweb-search').value || '').toLowerCase();
        const filtered = filter
            ? list.filter(c => (c.name || '').toLowerCase().includes(filter) || (c.chatId || '').includes(filter))
            : list;

        const ul = $('#wweb-chats');
        // Drop any prior IntersectionObserver hooks — the DOM nodes will be replaced
        if (_avatarObserver) _avatarObserver.disconnect();

        if (filtered.length === 0) {
            ul.innerHTML = '<li class="wweb-chats-empty">' + (list.length === 0 ? 'no chats yet' : 'no matches') + '</li>';
            return;
        }
        ul.innerHTML = filtered.map(c => {
            const isActive = c.chatId === state.activeChatId;
            return `<li class="wweb-chat-item ${isActive ? 'active' : ''}" data-chat="${escapeHtml(c.chatId)}">
                <div class="wweb-avatar" data-avatar="${escapeHtml(c.chatId)}">${escapeHtml(initials(c.name))}</div>
                <div>
                    <div class="name">${escapeHtml(c.name || shortJid(c.chatId))}</div>
                    <div class="last">${escapeHtml((c.lastMessage || '').slice(0, 50))}</div>
                </div>
                <div>
                    <div class="time">${escapeHtml(fmtTime(c.timestamp))}</div>
                    ${c.unread ? `<div class="badge-unread">${c.unread}</div>` : ''}
                </div>
            </li>`;
        }).join('');

        // Lazy avatar loading: observe rows, only fetch when scrolled into view
        const ob = ensureAvatarObserver();
        ul.querySelectorAll('.wweb-avatar[data-avatar]').forEach((el) => {
            const cid = el.dataset.avatar;
            if (state.avatars.has(cid)) {
                _applyAvatar(el, state.avatars.get(cid));
            } else {
                ob.observe(el);
            }
        });

        ul.querySelectorAll('.wweb-chat-item').forEach(el => {
            el.addEventListener('click', () => openChat(el.dataset.chat));
        });
    }

    // --- Open chat ---
    async function openChat(chatId) {
        if (!chatId) return;
        state.activeChatId = chatId;
        state.selected = null;
        root.classList.add('has-active');
        $('#wweb-empty').hidden = true;
        $('#wweb-chat').hidden = false;
        const chat = state.chats.get(chatId) || { chatId, name: shortJid(chatId) };
        $('#wweb-chat-name').textContent = chat.name || shortJid(chatId);
        $('#wweb-chat-id').textContent = chatId;
        const avEl = $('#wweb-chat-avatar');
        avEl.textContent = initials(chat.name);
        avEl.style.backgroundImage = '';
        avEl.classList.remove('has-image');
        fetchAvatar(chatId).then(url => {
            if (url && state.activeChatId === chatId) {
                avEl.style.backgroundImage = `url("${url}")`;
                avEl.classList.add('has-image');
                avEl.textContent = '';
            }
        });

        renderChats();
        await loadHistory(chatId);
    }

    async function loadHistory(chatId) {
        const msgs = $('#wweb-messages');
        msgs.innerHTML = '<div class="wweb-empty"><p>loading…</p></div>';
        const r = await call('getChatHistory', { chatId, count: 100 });
        if (!Array.isArray(r)) {
            msgs.innerHTML = '<div class="wweb-empty"><p>error: ' + escapeHtml(JSON.stringify(r)) + '</p></div>';
            return;
        }
        state.messages.set(chatId, r);
        const seen = new Set(r.map(m => m.idMessage));
        state.seenIds.set(chatId, seen);
        renderMessages(chatId);
    }

    function renderMessages(chatId) {
        const msgs = $('#wweb-messages');
        const list = state.messages.get(chatId) || [];
        msgs.innerHTML = list.map(m => renderBubble(m)).join('');
        msgs.scrollTop = msgs.scrollHeight;
        // attach bubble actions
        msgs.querySelectorAll('.wweb-bubble').forEach(el => {
            const id = el.dataset.id;
            const isMine = el.classList.contains('outgoing');
            const actions = el.querySelector('.wweb-bubble-actions');
            if (actions) {
                actions.querySelector('.act-forward')?.addEventListener('click', () => openForwardModal(id));
                if (isMine) {
                    actions.querySelector('.act-edit')?.addEventListener('click', () => openEditModal(id));
                    actions.querySelector('.act-delete')?.addEventListener('click', () => deleteMessage(id));
                }
            }
        });
    }

    function renderBubble(m) {
        const isOutgoing = m.type === 'outgoing' || m.direction === 'outgoing';
        const direction = isOutgoing ? 'outgoing' : 'incoming';
        const id = m.idMessage || '';
        const text = m.textMessage || '';
        const tm = m.typeMessage || 'text';
        const status = m.statusMessage;
        const ackIcon = status === 'read' ? '<span class="ack-read">✓✓</span>'
                      : status === 'delivered' ? '✓✓'
                      : status === 'sent' ? '✓'
                      : status === 'failed' ? '⚠'
                      : '';

        let mediaHtml = '';
        if (/image|video/.test(tm)) {
            mediaHtml = `<img class="media" loading="lazy" src="${apiUrl('/proxy-media/' + encodeURIComponent(id))}" alt="">`;
        } else if (/file|document|audio/.test(tm)) {
            mediaHtml = `<div class="file-card"><div class="icon">📄</div><div class="meta-file">
                <div>${escapeHtml(tm)}</div>
                <a href="${apiUrl('/proxy-media/' + encodeURIComponent(id))}" target="_blank">скачать</a>
            </div></div>`;
        }

        return `<div class="wweb-bubble ${direction}" data-id="${escapeHtml(id)}">
            ${mediaHtml}
            ${text ? `<div class="text">${escapeHtml(text)}</div>` : ''}
            <div class="meta">${fmtTime(m.timestamp)} ${ackIcon}</div>
            <div class="wweb-bubble-actions">
                <button class="act-forward" title="Forward">↪</button>
                ${isOutgoing ? '<button class="act-edit" title="Edit">✏</button><button class="act-delete" title="Delete">🗑</button>' : ''}
            </div>
        </div>`;
    }

    // --- Send ---
    async function sendCurrentInput() {
        const text = $('#wweb-text').value.trim();
        if (!text || !state.activeChatId) return;
        const chatId = state.activeChatId;
        $('#wweb-text').value = '';
        const r = await call('sendMessage', { chatId, message: text });
        if (r && r.idMessage) {
            scheduleRefreshChat(chatId, 800);
        } else {
            toast('Send failed: ' + (r && (r.error || JSON.stringify(r))));
        }
    }

    function scheduleRefreshChat(chatId, delay = 1000) {
        setTimeout(async () => {
            if (state.activeChatId === chatId) await loadHistory(chatId);
            await pollNewMessages();
        }, delay);
    }

    // --- Polling for new messages ---
    async function pollNewMessages() {
        const r = await fetch(apiUrl('/chat-list?minutes=10'), { credentials: 'same-origin' });
        const list = await r.json().catch(() => []);
        if (!Array.isArray(list)) return;
        let changed = false;
        for (const c of list) {
            const existing = state.chats.get(c.chatId);
            if (!existing || (c.timestamp || 0) > (existing.timestamp || 0)) {
                state.chats.set(c.chatId, { ...existing, ...c });
                changed = true;
            }
        }
        if (changed) renderChats();
        // If active chat changed — fetch incremental messages
        if (state.activeChatId) {
            const incoming = await call('lastIncomingMessages', null, { qs: { minutes: 2 } });
            const outgoing = await call('lastOutgoingMessages', null, { qs: { minutes: 2 } });
            const seen = state.seenIds.get(state.activeChatId) || new Set();
            const newOnes = [...(incoming || []), ...(outgoing || [])]
                .filter(m => m.chatId === state.activeChatId && !seen.has(m.idMessage));
            if (newOnes.length) {
                const cur = state.messages.get(state.activeChatId) || [];
                state.messages.set(state.activeChatId, [...cur, ...newOnes]);
                newOnes.forEach(m => seen.add(m.idMessage));
                state.seenIds.set(state.activeChatId, seen);
                renderMessages(state.activeChatId);
            }
        }
    }

    // --- Modals ---
    function openModal(title, html, onMount) {
        $('#wweb-modal-title').textContent = title;
        $('#wweb-modal-body').innerHTML = html;
        $('#wweb-modal').hidden = false;
        if (onMount) onMount($('#wweb-modal-body'));
    }
    function closeModal() {
        $('#wweb-modal').hidden = true;
        $('#wweb-modal-body').innerHTML = '';
    }

    function openNewChatModal() {
        openModal('New chat', `
            <label><span>Phone number</span><input id="m-phone" type="text" placeholder="79991234567"></label>
            <div class="modal-actions">
                <button class="btn" data-act="check">Check</button>
                <button class="btn btn-primary" data-act="open" disabled>Open chat</button>
            </div>
            <div class="modal-result" id="m-result"></div>
        `, (body) => {
            const phoneEl = body.querySelector('#m-phone');
            const result = body.querySelector('#m-result');
            const openBtn = body.querySelector('[data-act="open"]');
            body.querySelector('[data-act="check"]').addEventListener('click', async () => {
                const phone = (phoneEl.value || '').replace(/\D/g, '');
                if (!phone) return;
                result.textContent = 'checking…';
                const r = await call('checkWhatsapp', null, { qs: { phoneNumber: phone } });
                if (r && r.existsWhatsapp) {
                    result.textContent = '✓ has WhatsApp';
                    openBtn.disabled = false;
                    openBtn.onclick = () => {
                        const cid = phone + '@c.us';
                        if (!state.chats.has(cid)) state.chats.set(cid, { chatId: cid, name: '+' + phone, timestamp: 0 });
                        renderChats();
                        closeModal();
                        openChat(cid);
                    };
                } else {
                    result.textContent = '✗ no WhatsApp';
                }
            });
        });
    }

    function openFileUrlModal() {
        const chatId = state.activeChatId;
        if (!chatId) return;
        openModal('Send file by URL', `
            <label><span>URL</span><input id="m-url" type="url" placeholder="https://..."></label>
            <label><span>Filename</span><input id="m-fname" type="text"></label>
            <label><span>Caption</span><input id="m-cap" type="text"></label>
            <div class="modal-actions">
                <button class="btn btn-primary" data-act="send">Send</button>
                <button class="btn" data-act="cancel">Cancel</button>
            </div>
            <div class="modal-result" id="m-result"></div>
        `, (body) => {
            body.querySelector('[data-act="cancel"]').addEventListener('click', closeModal);
            body.querySelector('[data-act="send"]').addEventListener('click', async () => {
                const urlFile = body.querySelector('#m-url').value.trim();
                const fileName = body.querySelector('#m-fname').value.trim();
                const caption = body.querySelector('#m-cap').value.trim();
                if (!urlFile) return;
                body.querySelector('#m-result').textContent = 'sending…';
                const r = await call('sendFileByUrl', { chatId, urlFile, fileName: fileName || undefined, caption: caption || undefined });
                body.querySelector('#m-result').innerHTML = '<pre>' + escapeHtml(JSON.stringify(r, null, 2)) + '</pre>';
                if (r && r.idMessage) {
                    setTimeout(closeModal, 600);
                    scheduleRefreshChat(chatId, 1200);
                }
            });
        });
    }

    function openFileUploadModal(asImage) {
        const chatId = state.activeChatId;
        if (!chatId) return;
        openModal(asImage ? 'Send image (upload)' : 'Send file (upload)', `
            <label><span>File</span><input id="m-file" type="file" ${asImage ? 'accept="image/*"' : ''}></label>
            <label><span>Caption</span><input id="m-cap" type="text"></label>
            <div class="modal-actions">
                <button class="btn btn-primary" data-act="send">Upload &amp; send</button>
                <button class="btn" data-act="cancel">Cancel</button>
            </div>
            <div class="modal-result" id="m-result"></div>
        `, (body) => {
            body.querySelector('[data-act="cancel"]').addEventListener('click', closeModal);
            body.querySelector('[data-act="send"]').addEventListener('click', async () => {
                const fileEl = body.querySelector('#m-file');
                if (!fileEl.files || !fileEl.files[0]) return;
                const caption = body.querySelector('#m-cap').value.trim();
                const fd = new FormData();
                fd.append('chatId', chatId);
                if (caption) fd.append('caption', caption);
                fd.append('file', fileEl.files[0]);
                body.querySelector('#m-result').textContent = 'uploading…';
                const r = await call('sendFileByUpload', fd, { multipart: true });
                body.querySelector('#m-result').innerHTML = '<pre>' + escapeHtml(JSON.stringify(r, null, 2)) + '</pre>';
                if (r && r.idMessage) {
                    setTimeout(closeModal, 600);
                    scheduleRefreshChat(chatId, 1500);
                }
            });
        });
    }

    function openLocationModal() {
        const chatId = state.activeChatId;
        if (!chatId) return;
        openModal('Send location', `
            <label><span>Latitude</span><input id="m-lat" type="number" step="any"></label>
            <label><span>Longitude</span><input id="m-lon" type="number" step="any"></label>
            <label><span>Name</span><input id="m-name" type="text"></label>
            <label><span>Address</span><input id="m-addr" type="text"></label>
            <div class="modal-actions">
                <button class="btn btn-primary" data-act="send">Send</button>
                <button class="btn" data-act="cancel">Cancel</button>
            </div>
            <div class="modal-result" id="m-result"></div>
        `, (body) => {
            body.querySelector('[data-act="cancel"]').addEventListener('click', closeModal);
            body.querySelector('[data-act="send"]').addEventListener('click', async () => {
                const r = await call('sendLocation', {
                    chatId,
                    latitude: parseFloat(body.querySelector('#m-lat').value),
                    longitude: parseFloat(body.querySelector('#m-lon').value),
                    nameLocation: body.querySelector('#m-name').value.trim() || undefined,
                    address: body.querySelector('#m-addr').value.trim() || undefined,
                });
                body.querySelector('#m-result').innerHTML = '<pre>' + escapeHtml(JSON.stringify(r, null, 2)) + '</pre>';
                if (r && r.idMessage) { setTimeout(closeModal, 600); scheduleRefreshChat(chatId, 1200); }
            });
        });
    }

    function openContactModal() {
        const chatId = state.activeChatId;
        if (!chatId) return;
        openModal('Send contact', `
            <label><span>Phone number</span><input id="m-phone" type="text"></label>
            <label><span>First name</span><input id="m-first" type="text"></label>
            <label><span>Last name</span><input id="m-last" type="text"></label>
            <div class="modal-actions">
                <button class="btn btn-primary" data-act="send">Send</button>
                <button class="btn" data-act="cancel">Cancel</button>
            </div>
            <div class="modal-result" id="m-result"></div>
        `, (body) => {
            body.querySelector('[data-act="cancel"]').addEventListener('click', closeModal);
            body.querySelector('[data-act="send"]').addEventListener('click', async () => {
                const r = await call('sendContact', {
                    chatId,
                    contact: {
                        phoneContact: body.querySelector('#m-phone').value.replace(/\D/g, ''),
                        firstName: body.querySelector('#m-first').value.trim(),
                        lastName: body.querySelector('#m-last').value.trim(),
                    },
                });
                body.querySelector('#m-result').innerHTML = '<pre>' + escapeHtml(JSON.stringify(r, null, 2)) + '</pre>';
                if (r && r.idMessage) { setTimeout(closeModal, 600); scheduleRefreshChat(chatId, 1200); }
            });
        });
    }

    function openForwardModal(idMessage) {
        const chats = [...state.chats.keys()];
        openModal('Forward message', `
            <label><span>To chatId (or pick below)</span>
                <input id="m-target" type="text" placeholder="79991234567@c.us">
            </label>
            <ul style="list-style:none;padding:0;margin:0;max-height:200px;overflow:auto;">
                ${chats.map(c => `<li><button class="btn" data-cid="${escapeHtml(c)}" style="display:block;width:100%;text-align:left;margin:2px 0;">${escapeHtml(state.chats.get(c).name || c)}</button></li>`).join('')}
            </ul>
            <div class="modal-actions">
                <button class="btn btn-primary" data-act="send">Forward</button>
                <button class="btn" data-act="cancel">Cancel</button>
            </div>
            <div class="modal-result" id="m-result"></div>
        `, (body) => {
            body.querySelector('[data-act="cancel"]').addEventListener('click', closeModal);
            body.querySelectorAll('[data-cid]').forEach(b => b.addEventListener('click', () => {
                body.querySelector('#m-target').value = b.dataset.cid;
            }));
            body.querySelector('[data-act="send"]').addEventListener('click', async () => {
                const target = normalizeChatId(body.querySelector('#m-target').value);
                if (!target) return;
                const r = await call('forwardMessages', {
                    chatId: target,
                    chatIdFrom: state.activeChatId,
                    messages: [idMessage],
                });
                body.querySelector('#m-result').innerHTML = '<pre>' + escapeHtml(JSON.stringify(r, null, 2)) + '</pre>';
                if (r) setTimeout(closeModal, 800);
            });
        });
    }

    function openEditModal(idMessage) {
        const list = state.messages.get(state.activeChatId) || [];
        const msg = list.find(m => m.idMessage === idMessage);
        const current = msg ? msg.textMessage || '' : '';
        openModal('Edit message', `
            <label><span>New text</span><textarea id="m-text" rows="3">${escapeHtml(current)}</textarea></label>
            <div class="modal-actions">
                <button class="btn btn-primary" data-act="save">Save</button>
                <button class="btn" data-act="cancel">Cancel</button>
            </div>
            <div class="modal-result" id="m-result"></div>
        `, (body) => {
            body.querySelector('[data-act="cancel"]').addEventListener('click', closeModal);
            body.querySelector('[data-act="save"]').addEventListener('click', async () => {
                const r = await call('editMessage', {
                    chatId: state.activeChatId,
                    idMessage,
                    message: body.querySelector('#m-text').value,
                });
                body.querySelector('#m-result').innerHTML = '<pre>' + escapeHtml(JSON.stringify(r, null, 2)) + '</pre>';
                if (r && r.edited) { setTimeout(closeModal, 600); scheduleRefreshChat(state.activeChatId, 1000); }
            });
        });
    }

    async function deleteMessage(idMessage) {
        if (!confirm('Delete message for everyone?')) return;
        const r = await call('deleteMessage', { chatId: state.activeChatId, idMessage });
        if (r && r.deleted) scheduleRefreshChat(state.activeChatId, 600);
        else toast('Delete failed: ' + JSON.stringify(r));
    }

    async function showContactInfo() {
        if (!state.activeChatId) return;
        const r = await call('getContactInfo', { chatId: state.activeChatId });
        openModal('Contact info', `
            <div class="modal-result"><pre>${escapeHtml(JSON.stringify(r, null, 2))}</pre></div>
            <div class="modal-actions"><button class="btn" data-act="close">Close</button></div>
        `, (body) => {
            body.querySelector('[data-act="close"]').addEventListener('click', closeModal);
        });
    }

    function toast(msg) {
        // Simple alert-like — could be replaced with floating toast later
        console.warn(msg);
    }

    // --- Event wiring ---
    $('#wweb-search').addEventListener('input', renderChats);
    $('#wweb-newchat').addEventListener('click', openNewChatModal);
    $('#wweb-refresh').addEventListener('click', () => state.activeChatId && loadHistory(state.activeChatId));
    $('#wweb-mark-read').addEventListener('click', async () => {
        if (!state.activeChatId) return;
        await call('markChatAsRead', { chatId: state.activeChatId });
        toast('marked as read');
    });
    $('#wweb-contact-info').addEventListener('click', showContactInfo);
    $('#wweb-archive').addEventListener('click', async () => {
        if (!state.activeChatId) return;
        await call('archiveChat', { chatId: state.activeChatId });
        toast('archived');
    });

    $('#wweb-modal-close').addEventListener('click', closeModal);
    $('#wweb-modal').addEventListener('click', (e) => {
        if (e.target === $('#wweb-modal')) closeModal();
    });

    // attachment menu
    const attachBtn = $('#wweb-attach');
    const attachMenu = $('#wweb-attach-menu');
    attachBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        attachMenu.hidden = !attachMenu.hidden;
    });
    document.addEventListener('click', (e) => {
        if (!attachMenu.contains(e.target) && e.target !== attachBtn) attachMenu.hidden = true;
    });
    attachMenu.querySelectorAll('button[data-kind]').forEach(b => {
        b.addEventListener('click', () => {
            attachMenu.hidden = true;
            const k = b.dataset.kind;
            if (k === 'file') openFileUploadModal(false);
            else if (k === 'image') openFileUploadModal(true);
            else if (k === 'file_url') openFileUrlModal();
            else if (k === 'location') openLocationModal();
            else if (k === 'contact') openContactModal();
        });
    });

    // input area
    const textEl = $('#wweb-text');
    textEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendCurrentInput(); }
    });
    textEl.addEventListener('input', () => {
        textEl.style.height = 'auto';
        textEl.style.height = Math.min(150, textEl.scrollHeight) + 'px';
    });
    $('#wweb-send').addEventListener('click', sendCurrentInput);

    // --- Bootstrap ---
    (async function init() {
        const initial = await fetchChatList();
        initial.forEach(c => state.chats.set(c.chatId, c));
        renderChats();
        setInterval(pollNewMessages, POLL_INTERVAL_MS);
    })();
})();
