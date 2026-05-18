'use strict';

const GreenApiMapper = require('../src/lib/GreenApiMapper');

function makeMapper(opts = {}) {
  return new GreenApiMapper({
    idInstance: 1101000001,
    apiToken: 'testtoken',
    getWid: () => '7999@c.us',
    mediaBaseUrl: opts.mediaBaseUrl || '',
  });
}

describe('GreenApiMapper.toIncomingMessageReceived', () => {
  it('maps a plain text message', () => {
    const msg = {
      id: { _serialized: 'true_79991234567@c.us_ABC123' },
      from: '79991234567@c.us',
      to: '7999@c.us',
      author: null,
      body: 'hello',
      type: 'chat',
      timestamp: 1700000000,
      _data: { notifyName: 'John' },
    };
    const out = makeMapper().toIncomingMessageReceived(msg);
    expect(out).toMatchObject({
      typeWebhook: 'incomingMessageReceived',
      instanceData: { idInstance: 1101000001, wid: '7999@c.us', typeInstance: 'whatsapp' },
      idMessage: 'true_79991234567@c.us_ABC123',
      timestamp: 1700000000,
      senderData: { chatId: '79991234567@c.us', senderName: 'John' },
      messageData: {
        typeMessage: 'textMessage',
        textMessageData: { textMessage: 'hello' },
      },
    });
  });

  it('maps a group text message with author', () => {
    const msg = {
      id: { _serialized: 'true_120363@g.us_DEF456_79991234567@c.us' },
      from: '120363@g.us',
      author: '79991234567@c.us',
      body: 'group msg',
      type: 'chat',
      timestamp: 1700000001,
      _data: { notifyName: 'Group User' },
    };
    const out = makeMapper().toIncomingMessageReceived(msg);
    expect(out.senderData.chatId).toBe('120363@g.us');
    expect(out.senderData.sender).toBe('79991234567@c.us');
  });

  it('maps image with caption', () => {
    const msg = {
      id: { _serialized: 'true_X' },
      from: '7@c.us',
      type: 'image',
      timestamp: 1,
      body: 'photo caption',
      _data: { mimetype: 'image/jpeg', deprecatedMms3Url: 'https://mmg.whatsapp.net/v/foo.enc' },
    };
    const out = makeMapper().toIncomingMessageReceived(msg);
    expect(out.messageData.typeMessage).toBe('imageMessage');
    expect(out.messageData.imageMessageData.caption).toBe('photo caption');
    expect(out.messageData.imageMessageData.mimeType).toBe('image/jpeg');
  });

  it('builds downloadUrl when mediaBaseUrl is set', () => {
    const msg = {
      id: { _serialized: 'true_X' },
      from: '7@c.us',
      type: 'image',
      timestamp: 1,
      _data: { mimetype: 'image/jpeg' },
    };
    const out = makeMapper({ mediaBaseUrl: 'https://api.wa.iqteco.com' }).toIncomingMessageReceived(msg);
    expect(out.messageData.imageMessageData.downloadUrl)
      .toBe('https://api.wa.iqteco.com/waInstance1101000001/media/testtoken/true_X');
  });

  it('downloadUrl empty when mediaBaseUrl unset', () => {
    const msg = {
      id: { _serialized: 'true_Y' },
      from: '7@c.us',
      type: 'document',
      timestamp: 1,
      _data: { filename: 'doc.pdf', mimetype: 'application/pdf' },
    };
    const out = makeMapper().toIncomingMessageReceived(msg);
    expect(out.messageData.fileMessageData.downloadUrl).toBe('');
    expect(out.messageData.fileMessageData.fileName).toBe('doc.pdf');
  });
});

describe('GreenApiMapper extended webhooks (Phase 4)', () => {
  it('toEditedMessageReceived', () => {
    const msg = {
      id: { _serialized: 'true_X' }, from: '7@c.us', body: 'new text',
      type: 'chat', fromMe: false, _data: { notifyName: 'John' },
    };
    const out = makeMapper().toEditedMessageReceived(msg);
    expect(out).toMatchObject({
      typeWebhook: 'editedMessageReceived',
      idMessage: 'true_X',
      messageData: { typeMessage: 'textMessage', textMessageData: { textMessage: 'new text' } },
    });
  });

  it('toDeletedMessageReceived uses original id', () => {
    const msg = { id: { _serialized: 'revoke_msg' }, from: '7@c.us', fromMe: false };
    const original = { id: { _serialized: 'orig_msg' } };
    const out = makeMapper().toDeletedMessageReceived(msg, original);
    expect(out.idMessage).toBe('orig_msg');
    expect(out.typeWebhook).toBe('deletedMessageReceived');
  });

  it('toIncomingCall offer vs offerVideo', () => {
    const audio = makeMapper().toIncomingCall({ id: 'c1', from: '7@c.us', isVideo: false });
    const video = makeMapper().toIncomingCall({ id: 'c2', from: '7@c.us', isVideo: true });
    expect(audio.status).toBe('offer');
    expect(video.status).toBe('offerVideo');
  });

  it('toGroupChange embeds eventType', () => {
    const out = makeMapper().toGroupChange('groupJoin', { chatId: 'g@g.us', author: '7@c.us' });
    expect(out.typeWebhook).toBe('groupJoin');
    expect(out.chatId).toBe('g@g.us');
  });

  it('toContactChanged carries old/new ids', () => {
    const out = makeMapper().toContactChanged({}, '1@c.us', '2@c.us', true);
    expect(out).toMatchObject({
      typeWebhook: 'contactChanged',
      oldChatId: '1@c.us',
      newChatId: '2@c.us',
      isContact: true,
    });
  });

  it('toDeviceInfo carries battery+plugged', () => {
    const out = makeMapper().toDeviceInfo({ battery: 35, plugged: true });
    expect(out).toMatchObject({
      typeWebhook: 'deviceInfo',
      deviceData: { battery: 35, plugged: true },
    });
  });
});

describe('GreenApiMapper.toStateInstanceChanged', () => {
  it('maps CONNECTED → authorized', () => {
    const out = makeMapper().toStateInstanceChanged('CONNECTED');
    expect(out).toMatchObject({
      typeWebhook: 'stateInstanceChanged',
      stateInstance: 'authorized',
      instanceData: { idInstance: 1101000001 },
    });
  });
});

describe('GreenApiMapper.toOutgoingMessageStatus', () => {
  it('maps ack=3 → read', () => {
    const msg = { id: { _serialized: 'true_X' }, to: '7@c.us', ack: 3 };
    const out = makeMapper().toOutgoingMessageStatus(msg);
    expect(out).toMatchObject({
      typeWebhook: 'outgoingMessageStatus',
      idMessage: 'true_X',
      status: 'read',
      chatId: '7@c.us',
    });
  });
});
