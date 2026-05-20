'use strict';

const { mapWAStateToGreen, mapAckToGreen } = require('./StateMap');
const { buildMediaFilename, mimeToExt } = require('./mime');

class GreenApiMapper {
  constructor({ idInstance, apiToken, getWid, mediaBaseUrl = '' }) {
    this.idInstance = Number(idInstance);
    this.apiToken = String(apiToken || '');
    this.getWid = getWid || (() => null);
    this.mediaBaseUrl = (mediaBaseUrl || '').replace(/\/$/, '');
  }

  _instanceData() {
    return {
      idInstance: this.idInstance,
      wid: this.getWid(),
      typeInstance: 'whatsapp',
    };
  }

  _downloadUrl(messageId, ext) {
    if (!this.mediaBaseUrl || !messageId) return '';
    const encoded = encodeURIComponent(messageId);
    const suffix = ext ? `.${ext}` : '';
    return `${this.mediaBaseUrl}/waInstance${this.idInstance}/media/${this.apiToken}/${encoded}${suffix}`;
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

    const idMessage = msg.id?._serialized || msg.id?.id || '';

    return {
      typeWebhook: 'incomingMessageReceived',
      instanceData: this._instanceData(),
      timestamp: msg.timestamp ?? Math.floor(Date.now() / 1000),
      idMessage,
      senderData,
      messageData: this._messageData(msg, idMessage),
    };
  }

  toOutgoingMessageReceived(msg) {
    const idMessage = msg.id?._serialized || msg.id?.id || '';
    return {
      typeWebhook: 'outgoingMessageReceived',
      instanceData: this._instanceData(),
      timestamp: msg.timestamp ?? Math.floor(Date.now() / 1000),
      idMessage,
      senderData: {
        chatId: msg.to,
        sender: msg.from,
        senderName: '',
      },
      messageData: this._messageData(msg, idMessage),
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

  toEditedMessageReceived(msg) {
    const idMessage = msg.id?._serialized || msg.id?.id || '';
    return {
      typeWebhook: 'editedMessageReceived',
      instanceData: this._instanceData(),
      timestamp: Math.floor(Date.now() / 1000),
      idMessage,
      senderData: {
        chatId: msg.fromMe ? msg.to : msg.from,
        sender: msg.author || msg.from,
        senderName: msg._data?.notifyName || '',
      },
      messageData: this._messageData(msg, idMessage),
    };
  }

  toDeletedMessageReceived(msg, originalMsg = null) {
    const idMessage = (originalMsg?.id?._serialized) || msg.id?._serialized || '';
    return {
      typeWebhook: 'deletedMessageReceived',
      instanceData: this._instanceData(),
      timestamp: Math.floor(Date.now() / 1000),
      idMessage,
      senderData: {
        chatId: msg.fromMe ? msg.to : msg.from,
        sender: msg.author || msg.from,
      },
    };
  }

  toIncomingCall(call) {
    return {
      typeWebhook: 'incomingCall',
      instanceData: this._instanceData(),
      timestamp: Math.floor(Date.now() / 1000),
      idMessage: call.id || '',
      from: call.from || call.peerJid || '',
      status: call.isVideo ? 'offerVideo' : 'offer',
    };
  }

  toGroupChange(eventType, payload) {
    return {
      typeWebhook: eventType,
      instanceData: this._instanceData(),
      timestamp: Math.floor(Date.now() / 1000),
      ...payload,
    };
  }

  toContactChanged(message, oldId, newId, isContact) {
    return {
      typeWebhook: 'contactChanged',
      instanceData: this._instanceData(),
      timestamp: Math.floor(Date.now() / 1000),
      oldChatId: oldId,
      newChatId: newId,
      isContact: !!isContact,
    };
  }

  toPollMessage(vote, msg) {
    const idMessage = msg?.id?._serialized || msg?.id?.id || '';
    return {
      typeWebhook: 'pollUpdate',
      instanceData: this._instanceData(),
      timestamp: Math.floor(Date.now() / 1000),
      idMessage,
      chatId: msg?.from || msg?.to || '',
      voter: vote?.voter || vote?.id?.remote || '',
      selectedOptions: (vote?.selectedOptions || []).map((o) => o.name || o.localId || ''),
    };
  }

  toDeviceInfo(info) {
    return {
      typeWebhook: 'deviceInfo',
      instanceData: this._instanceData(),
      timestamp: Math.floor(Date.now() / 1000),
      deviceData: {
        battery: info.battery ?? null,
        plugged: info.plugged ?? null,
      },
    };
  }

  _messageData(msg, idMessage) {
    const type = msg.type || 'chat';

    if (type === 'chat' || type === 'text') {
      return {
        typeMessage: 'textMessage',
        textMessageData: { textMessage: msg.body || '' },
      };
    }

    const fileFields = this._fileFields(msg, idMessage);

    if (type === 'image') {
      return {
        typeMessage: 'imageMessage',
        fileMessageData: fileFields,
        imageMessageData: fileFields,
      };
    }
    if (type === 'video') return { typeMessage: 'videoMessage', fileMessageData: fileFields };
    if (type === 'audio' || type === 'ptt') return { typeMessage: 'audioMessage', fileMessageData: fileFields };
    if (type === 'document') return { typeMessage: 'documentMessage', fileMessageData: fileFields };
    if (type === 'sticker') return { typeMessage: 'stickerMessage', fileMessageData: fileFields };

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

    return { typeMessage: type, textMessageData: { textMessage: msg.body || '' } };
  }

  _fileFields(msg, idMessage) {
    const mimeType = msg._data?.mimetype || msg._data?.mimeType || '';
    const explicit = msg._data?.filename || msg._data?.fileName || '';
    const fileName = explicit || buildMediaFilename({
      messageId: idMessage,
      mimeType,
      fallbackBase: msg.id?.id,
    });
    const extFromName = (fileName.match(/\.([a-z0-9]{1,5})$/i) || [])[1] || '';
    const ext = extFromName || mimeToExt(mimeType);
    return {
      downloadUrl: this._downloadUrl(idMessage, ext),
      caption: msg.body || msg._data?.caption || '',
      fileName,
      mimeType,
    };
  }
}

module.exports = GreenApiMapper;
