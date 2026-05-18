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
