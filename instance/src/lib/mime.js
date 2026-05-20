'use strict';

const MIME_TO_EXT = {
  'image/jpeg': 'jpg',
  'image/jpg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
  'image/gif': 'gif',
  'image/heic': 'heic',
  'video/mp4': 'mp4',
  'video/3gpp': '3gp',
  'video/quicktime': 'mov',
  'video/x-matroska': 'mkv',
  'video/webm': 'webm',
  'audio/ogg': 'ogg',
  'audio/ogg; codecs=opus': 'ogg',
  'audio/mpeg': 'mp3',
  'audio/mp4': 'm4a',
  'audio/aac': 'aac',
  'audio/wav': 'wav',
  'audio/x-wav': 'wav',
  'audio/webm': 'webm',
  'application/pdf': 'pdf',
  'application/zip': 'zip',
  'application/x-rar-compressed': 'rar',
  'application/x-7z-compressed': '7z',
  'application/msword': 'doc',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'docx',
  'application/vnd.ms-excel': 'xls',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
  'application/vnd.ms-powerpoint': 'ppt',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'pptx',
  'text/plain': 'txt',
  'text/csv': 'csv',
};

function mimeToExt(mimeType) {
  if (!mimeType) return '';
  const m = String(mimeType).toLowerCase().split(';')[0].trim();
  return MIME_TO_EXT[m] || '';
}

/**
 * Build a usable filename for a WhatsApp media message.
 * WhatsApp doesn't send a filename for video/audio/voice, so we
 * synthesize "<msgId>.<ext>" from mimeType when needed. Without an
 * extension, Bitrix24 (and many B2C apps) treat the file as a generic
 * .bin and refuse to preview/play.
 */
function buildMediaFilename({ explicit, messageId, mimeType, fallbackBase }) {
  if (explicit && explicit.trim()) return explicit.trim();
  const ext = mimeToExt(mimeType);
  const base = (fallbackBase || messageId || 'media').replace(/[^a-zA-Z0-9._-]/g, '_');
  return ext ? `${base}.${ext}` : `${base}.bin`;
}

module.exports = { mimeToExt, buildMediaFilename };
