'use strict';

module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const chatId = (req.body?.chatId || req.query?.chatId || '').toString();
  if (!chatId) return res.status(400).json({ error: 'chatId required' });
  try {
    const url = await ctx.client.getProfilePicUrl(chatId);
    res.json({ urlAvatar: url || '', reason: url ? '' : 'no_avatar', available: !!url });
  } catch (err) {
    // WA Web убрал WAWebContactProfilePicThumbBridge, и штатный
    // getProfilePicUrl бросает минифицированное «r» на любом чате — 31.08.2026
    // весь инбокс остался без аватаров. Апстрим-фикс (wwebjs PR #201880) ещё
    // не смержен, поэтому тот же запрос делаем сами: локальная коллекция
    // ProfilePicThumb, затем find на сервере. Выкинуть, когда PR доедет.
    try {
      const url = await ctx.client.pupPage.evaluate(async (contactId) => {
        const wid = window.require('WAWebWidFactory').createWid(contactId);
        const pictures = window.require('WAWebCollections').ProfilePicThumb;
        const picture = pictures.get(wid) || (await pictures.find(wid));
        return picture?.eurl;
      }, chatId);
      res.json({ urlAvatar: url || '', reason: url ? '' : 'no_avatar', available: !!url });
    } catch (err2) {
      res.json({ urlAvatar: '', reason: err2.message, available: false });
    }
  }
};
