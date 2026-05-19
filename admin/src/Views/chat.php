<?php
use Iqteco\WaAdmin\Services\I18n;
use Iqteco\WaAdmin\Services\View;

$id = (string)$instance['idInstance'];
?>
<h1><?= View::e(I18n::t('chat.title', ['id' => $id])) ?></h1>
<p><a href="/instances/<?= View::e($id) ?>">← <?= View::e(I18n::t('chat.back')) ?></a></p>

<div class="chat-wrap">
    <aside class="chat-side card">
        <h2><?= View::e(I18n::t('chat.history')) ?></h2>
        <div class="chat-tools">
            <label><input type="radio" name="dir" value="incoming" checked> in</label>
            <label><input type="radio" name="dir" value="outgoing"> out</label>
            <button id="refresh-history" class="btn btn-small"><?= View::e(I18n::t('chat.refresh')) ?></button>
        </div>
        <ul id="chat-list" class="chat-list"></ul>
    </aside>

    <section class="chat-main card">
        <h2><?= View::e(I18n::t('chat.send')) ?></h2>
        <form id="send-form">
            <label>
                <span>chatId / phone</span>
                <input id="chatId" type="text" placeholder="79991234567@c.us  или  79991234567" required>
                <button type="button" id="check-wa" class="btn btn-small"><?= View::e(I18n::t('chat.check_wa')) ?></button>
                <span id="check-result" class="muted"></span>
            </label>

            <fieldset>
                <legend><?= View::e(I18n::t('chat.message_type')) ?></legend>
                <label class="radio inline-radio"><input type="radio" name="mtype" value="text" checked> text</label>
                <label class="radio inline-radio"><input type="radio" name="mtype" value="file"> file by URL</label>
                <label class="radio inline-radio"><input type="radio" name="mtype" value="location"> location</label>
            </fieldset>

            <div id="field-text">
                <label><span><?= View::e(I18n::t('chat.text')) ?></span>
                    <textarea id="text" rows="3" placeholder="Hello!"></textarea>
                </label>
            </div>

            <div id="field-file" style="display:none;">
                <label><span>URL</span>
                    <input id="urlFile" type="url" placeholder="https://example.com/file.pdf">
                </label>
                <label><span>fileName</span>
                    <input id="fileName" type="text">
                </label>
                <label><span>caption</span>
                    <input id="caption" type="text">
                </label>
            </div>

            <div id="field-location" style="display:none;">
                <label><span>latitude</span><input id="latitude" type="number" step="any"></label>
                <label><span>longitude</span><input id="longitude" type="number" step="any"></label>
                <label><span>name</span><input id="nameLocation" type="text"></label>
                <label><span>address</span><input id="address" type="text"></label>
            </div>

            <button type="submit" class="btn btn-primary"><?= View::e(I18n::t('chat.send_btn')) ?></button>
        </form>

        <div id="send-result"></div>
    </section>
</div>

<script>
(function () {
    const id = <?= json_encode($id) ?>;
    const api = (method, body) => fetch('/api/instances/' + id + '/proxy/' + method, {
        method: body ? 'POST' : 'GET',
        credentials: 'same-origin',
        headers: body ? { 'Content-Type': 'application/json' } : {},
        body: body ? JSON.stringify(body) : undefined,
    }).then(r => r.json().catch(() => ({ error: 'invalid_response', status: r.status })));

    function normalizeChatId(v) {
        v = v.trim();
        if (v.endsWith('@c.us') || v.endsWith('@g.us')) return v;
        const digits = v.replace(/\D/g, '');
        return digits ? digits + '@c.us' : '';
    }

    const list = document.getElementById('chat-list');
    const result = document.getElementById('send-result');

    async function refreshHistory() {
        const dir = document.querySelector('input[name="dir"]:checked').value;
        const method = dir === 'incoming' ? 'lastIncomingMessages' : 'lastOutgoingMessages';
        list.innerHTML = '<li class="muted">loading…</li>';
        try {
            const r = await api(method);
            if (!Array.isArray(r)) { list.innerHTML = '<li class="muted">' + JSON.stringify(r) + '</li>'; return; }
            if (r.length === 0) { list.innerHTML = '<li class="muted">empty</li>'; return; }
            list.innerHTML = r.map(m => {
                const ts = m.timestamp ? new Date(m.timestamp * 1000).toLocaleString() : '';
                const status = m.statusMessage ? ' [' + m.statusMessage + ']' : '';
                return '<li><div class="msg-meta">' + (m.chatId || '') + ' · ' + ts + status + '</div><div class="msg-body">' + escapeHtml(m.textMessage || m.typeMessage || '(no text)') + '</div></li>';
            }).join('');
        } catch (e) {
            list.innerHTML = '<li class="muted">err: ' + e.message + '</li>';
        }
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    document.getElementById('refresh-history').addEventListener('click', refreshHistory);
    document.querySelectorAll('input[name="dir"]').forEach(el => el.addEventListener('change', refreshHistory));
    refreshHistory();
    setInterval(refreshHistory, 15000);

    document.getElementById('check-wa').addEventListener('click', async () => {
        const phone = document.getElementById('chatId').value.replace(/\D/g, '');
        if (!phone) return;
        document.getElementById('check-result').textContent = '…';
        const r = await api('checkWhatsapp?phoneNumber=' + phone);
        document.getElementById('check-result').textContent = r.existsWhatsapp ? '✓ WA' : '✗ no WA';
    });

    document.querySelectorAll('input[name="mtype"]').forEach(el => el.addEventListener('change', () => {
        const v = el.value;
        document.getElementById('field-text').style.display = v === 'text' ? '' : 'none';
        document.getElementById('field-file').style.display = v === 'file' ? '' : 'none';
        document.getElementById('field-location').style.display = v === 'location' ? '' : 'none';
    }));

    document.getElementById('send-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const chatId = normalizeChatId(document.getElementById('chatId').value);
        if (!chatId) { result.textContent = 'chatId required'; return; }
        const type = document.querySelector('input[name="mtype"]:checked').value;
        let method = 'sendMessage', body = { chatId };
        if (type === 'text') {
            body.message = document.getElementById('text').value;
        } else if (type === 'file') {
            method = 'sendFileByUrl';
            body.urlFile = document.getElementById('urlFile').value;
            body.fileName = document.getElementById('fileName').value || undefined;
            body.caption = document.getElementById('caption').value || undefined;
        } else if (type === 'location') {
            method = 'sendLocation';
            body.latitude = parseFloat(document.getElementById('latitude').value);
            body.longitude = parseFloat(document.getElementById('longitude').value);
            body.nameLocation = document.getElementById('nameLocation').value || undefined;
            body.address = document.getElementById('address').value || undefined;
        }
        result.textContent = 'sending…';
        try {
            const r = await api(method, body);
            result.innerHTML = '<pre>' + escapeHtml(JSON.stringify(r, null, 2)) + '</pre>';
            setTimeout(refreshHistory, 1500);
        } catch (e) {
            result.textContent = 'err: ' + e.message;
        }
    });
})();
</script>
