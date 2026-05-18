'use strict';

const { mapWAStateToGreen, mapAckToGreen } = require('./StateMap');

class GreenApiMapper {
  constructor({ idInstance, getWid }) {
    this.idInstance = Number(idInstance);
    this.getWid = getWid || (() => null);
  }

  _instanceData() {
    return {
      idInstance: this.idInstance,
      wid: this.getWid(),
      typeInstance: 'whatsapp',
    };
  }

  toStateInstanceChanged(waState) {
    return {
      typeWebhook: 'stateInstanceChanged',
      instanceData: this._instanceData(),
      timestamp: Math.floor(Date.now() / 1000),
      stateInstance: mapWAStateToGreen(waState),
    };
  }

  toStatusInstanceChanged(status) {
    return {
      typeWebhook: 'statusInstanceChanged',
      instanceData: this._instanceData(),
      timestamp: Math.floor(Date.now() / 1000),
      statusInstance: status,
    };
  }

  toIncomingMessageReceived(msg) {
    const senderData = {
      chatId: msg.from,
      sender: msg.author || msg.from,
      chatName: msg._data?.notifyName || '',
      senderName: msg._data?.notifyName || msg.author || msg.from,
    };
    if (msg.author) senderData.senderContactName = msg._data?.verifiedName || '';

    return {
      typeWebhook: 'incomingMessageReceived',
      instanceData: this._instanceData(),
      timestamp: msg.timestamp ?? Math.floor(Date.now() / 1000),
      idMessage: msg.id?._serialized || msg.id?.id || '',
      senderData,
      messageData: this._messageData(msg),
    };
  }

  toOutgoingMessageReceived(msg) {
    return {
      typeWebhook: 'outgoingMessageReceived',
      instanceData: this._instanceData(),
      timestamp: msg.timestamp ?? Math.floor(Date.now() / 1000),
      idMessage: msg.id?._serialized || msg.id?.id || '',
      senderData: {
        chatId: msg.to,
        sender: msg.from,
        senderName: '',
      },
      messageData: this._messageData(msg),
    };
  }

  toOutgoingAPIMessageReceived(msg) {
    return {
      ...this.toOutgoingMessageReceived(msg),
      typeWebhook: 'outgoingAPIMessageReceived',
    };
  }

  toOutgoingMessageStatus(msg) {
    return {
      typeWebhook: 'outgoingMessageStatus',
      instanceData: this._instanceData(),
      timestamp: Math.floor(Date.now() / 1000),
      idMessage: msg.id?._serialized || msg.id?.id || '',
      status: mapAckToGreen(msg.ack),
      chatId: msg.to,
      sendByApi: msg._data?.self === 'in' ? false : true,
    };
  }

  _messageData(msg) {
    const type = msg.type || 'chat';

    if (type === 'chat' || type === 'text') {
      return {
        typeMessage: 'textMessage',
        textMessageData: { textMessage: msg.body || '' },
      };
    }

    if (type === 'image') {
      return {
        typeMessage: 'imageMessage',
        fileMessageData: this._fileFields(msg),
        imageMessageData: this._fileFields(msg),
      };
    }

    if (type === 'video') {
      return {
        typeMessage: 'videoMessage',
        fileMessageData: this._fileFields(msg),
      };
    }

    if (type === 'audio' || type === 'ptt') {
      return {
        typeMessage: 'audioMessage',
        fileMessageData: this._fileFields(msg),
      };
    }

    if (type === 'document') {
      return {
        typeMessage: 'documentMessage',
        fileMessageData: this._fileFields(msg),
      };
    }

    if (type === 'sticker') {
      return {
        typeMessage: 'stickerMessage',
        fileMessageData: this._fileFields(msg),
      };
    }

    if (type === 'location') {
      return {
        typeMessage: 'locationMessage',
        locationMessageData: {
          nameLocation: msg.location?.name || '',
          address: msg.location?.address || '',
          latitude: msg.location?.latitude ?? null,
          longitude: msg.location?.longitude ?? null,
        },
      };
    }

    if (type === 'vcard' || type === 'contact_card') {
      return {
        typeMessage: 'contactMessage',
        contactMessageData: { displayName: msg._data?.displayName || '', vcard: msg.body || '' },
      };
    }

    return {
      typeMessage: type,
      textMessageData: { textMessage: msg.body || '' },
    };
  }

  _fileFields(msg) {
    return {
      downloadUrl: msg._data?.deprecatedMms3Url || msg._data?.directPath || '',
      caption: msg.body || msg._data?.caption || '',
      fileName: msg._data?.filename || msg._data?.fileName || '',
      mimeType: msg._data?.mimetype || msg._data?.mimeType || '',
    };
  }
}

module.exports = GreenApiMapper;
