'use strict';

// Tolerant media download for one message.
//
// Upstream msg.downloadMedia() goes through WWebJS.resolveMediaBlob, which
// starts with `Msg.get(id) || Msg.getMessagesById([id])` — the IndexedDB lookup
// that dies with a minified "r" on LID-era accounts (see getChats.js /
// getChatHistory.js). Since 01.07.2026 every incoming media on those accounts
// failed there, the webhook still carried a downloadUrl, and consumers got 404
// on each photo / voice note. This helper never touches getMessagesById: the
// message model is found in memory (Msg collection, then the chat's own msgs),
// and every later step is wrapped so the failing one is named in `steps`.
//
// Returns { data (base64), mimetype, filename, filesize, steps } or
// { error, steps }.
async function downloadMessageMedia(ctx, { msgId, chatId = '' }) {
  const page = ctx.client && ctx.client.pupPage;
  if (!page) return { error: 'no_page', steps: [] };

  return page.evaluate(async (msgId, chatId) => {
    const steps = [];
    const step = (name, ok, extra) => steps.push(Object.assign({ name, ok }, extra || {}));
    const errStr = (e) => String((e && (e.message || e)) || 'error').slice(0, 160);

    const Coll = window.require('WAWebCollections');
    const bare = msgId.includes('_') ? msgId.split('_').slice(2).join('_') : msgId;

    // 1. Locate the message model in memory.
    let msg = null;
    try {
      msg = Coll.Msg.get(msgId) || null;
      if (!msg && chatId) {
        for (const fromMe of ['false', 'true']) {
          msg = Coll.Msg.get(`${fromMe}_${chatId}_${bare}`) || null;
          if (msg) break;
        }
      }
      if (!msg && chatId) {
        let chat = null;
        try { chat = Coll.Chat.get(window.require('WAWebWidFactory').createWid(chatId)); } catch (e) { chat = null; }
        if (!chat) chat = Coll.Chat.get(chatId) || null;
        const findInChat = () => (chat && chat.msgs)
          ? chat.msgs.getModelsArray().find((m) => m && m.id && (m.id.id === bare || m.id._serialized === msgId)) || null
          : null;
        msg = findInChat();
        // Not loaded yet (fresh container, old message): page the chat backwards,
        // the same loader getChatHistory uses. Bounded — a chat with thousands of
        // messages is not walked to the beginning for one photo.
        let pages = 0;
        while (!msg && chat && pages++ < 12) {
          let loaded;
          try { loaded = await window.require('WAWebChatLoadMessages').loadEarlierMsgs({ chat }); } catch (e) { break; }
          if (!loaded || !loaded.length) break;
          msg = findInChat();
        }
        if (chat) step('loadEarlier', !!msg, { pages });
      }
      if (!msg) {
        msg = Coll.Msg.getModelsArray().find((m) => m && m.id && (m.id.id === bare || m.id._serialized === msgId)) || null;
      }
      step('locate', !!msg, { via: msg ? 'memory' : 'none' });
    } catch (e) {
      step('locate', false, { err: errStr(e) });
    }
    if (!msg) return { error: 'message_not_found', steps };

    if (!msg.mediaData) {
      step('mediaData', false, { type: msg.type });
      return { error: 'no_media', steps };
    }
    step('mediaData', true, { stage: msg.mediaData.mediaStage, type: msg.type, mimetype: msg.mimetype, size: msg.size });

    // 2. Ask WA Web to fetch + decrypt the media.
    try {
      await msg.downloadMedia({ downloadEvenIfExpensive: true, rmrReason: 1, isUserInitiated: true });
      step('downloadMedia', true, { stage: msg.mediaData.mediaStage });
    } catch (e) {
      step('downloadMedia', false, { err: errStr(e), stage: msg.mediaData && msg.mediaData.mediaStage });
    }
    const stage = String((msg.mediaData && msg.mediaData.mediaStage) || '');
    if (stage.includes('ERROR') || stage === 'FETCHING') {
      return { error: 'media_stage_' + stage, steps };
    }

    // 3. Take the blob from the in-memory cache or the media object.
    let blob = null;
    try {
      const cache = window.require('WAWebMediaInMemoryBlobCache').InMemoryMediaBlobCache;
      const hash = msg.mediaObject && msg.mediaObject.filehash;
      blob = hash ? cache.get(hash) : null;
      step('blobCache', !!blob, { hash: !!hash });
    } catch (e) {
      step('blobCache', false, { err: errStr(e) });
    }
    if (!blob) {
      try {
        if (msg.mediaObject && msg.mediaObject.mediaBlob) blob = msg.mediaObject.mediaBlob.forceToBlob();
        step('forceToBlob', !!blob);
      } catch (e) {
        step('forceToBlob', false, { err: errStr(e) });
      }
    }
    if (!blob) return { error: 'no_blob', steps };

    // 4. Base64 out.
    try {
      const buf = await blob.arrayBuffer();
      const bytes = new Uint8Array(buf);
      let bin = '';
      const chunk = 0x8000;
      for (let i = 0; i < bytes.length; i += chunk) {
        bin += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
      }
      step('base64', true, { bytes: bytes.length });
      return {
        data: btoa(bin),
        mimetype: msg.mimetype || blob.type || '',
        filename: msg.filename || '',
        filesize: msg.size || bytes.length,
        steps,
      };
    } catch (e) {
      step('base64', false, { err: errStr(e) });
      return { error: 'encode_failed', steps };
    }
  }, msgId, chatId);
}

module.exports = { downloadMessageMedia };
