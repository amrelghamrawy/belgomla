import { describe, expect, it } from 'vitest';
import { classifyDisconnect, disconnectCode, disconnectReason } from '../src/disconnect.js';
import { Limiter } from '../src/limiter.js';
import { e164Digits, phoneFromJid } from '../src/phone.js';

describe('phone', () => {
  it.each([
    ['egyptian mobile', '+201001234567', '201001234567'],
    ['shortest allowed', '+12345678', '12345678'],
  ])('turns an e.164 number into digits (%s)', (_name, phone, digits) => {
    expect(e164Digits(phone)).toBe(digits);
  });

  it.each([
    ['no plus', '201001234567'],
    ['local format', '01001234567'],
    ['leading zero', '+01001234567'],
    ['letters', '+20100abc4567'],
    ['too long', '+2010012345678901'],
  ])('refuses a number that is not e.164 (%s)', (_name, phone) => {
    expect(e164Digits(phone)).toBeNull();
  });

  it('reads the phone of the linked account from its jid', () => {
    expect(phoneFromJid('201001234567:7@s.whatsapp.net')).toBe('+201001234567');
    expect(phoneFromJid('201001234567@s.whatsapp.net')).toBe('+201001234567');
  });

  it('returns null for a jid without a phone', () => {
    expect(phoneFromJid(undefined)).toBeNull();
    expect(phoneFromJid('12345@lid')).toBeNull();
  });
});

describe('disconnect', () => {
  it.each([
    ['restart required', 515, 'restart', 'restart_required'],
    ['logged out from the phone', 401, 'fatal', 'logged_out'],
    ['forbidden', 403, 'fatal', 'forbidden'],
    ['multidevice mismatch', 411, 'fatal', 'multidevice_mismatch'],
    ['connection replaced', 440, 'fatal', 'connection_replaced'],
    ['bad session', 500, 'fatal', 'bad_session'],
    ['timed out', 408, 'transient', 'timed_out'],
    ['connection closed', 428, 'transient', 'connection_closed'],
    ['unavailable service', 503, 'transient', 'unavailable_service'],
    ['unknown code', 999, 'transient', 'unknown'],
  ])('classifies %s', (_name, code, kind, reason) => {
    expect(classifyDisconnect(code)).toBe(kind);
    expect(disconnectReason(code)).toBe(reason);
  });

  it('treats a close without a code as transient', () => {
    expect(classifyDisconnect(undefined)).toBe('transient');
    expect(disconnectReason(undefined)).toBe('unknown');
  });

  it('reads the status code of a boom error', () => {
    expect(disconnectCode({ output: { statusCode: 401 } })).toBe(401);
    expect(disconnectCode(new Error('plain'))).toBeUndefined();
    expect(disconnectCode(undefined)).toBeUndefined();
  });
});

describe('limiter', () => {
  it('lets the allowed number of sends through within a window', () => {
    const limiter = new Limiter(2, 60_000, () => 1_000);

    expect(limiter.take()).toBe(0);
    expect(limiter.take()).toBe(0);
  });

  it('refuses the next send with the time until the oldest slot frees', () => {
    let now = 1_000;
    const limiter = new Limiter(2, 60_000, () => now);
    limiter.take();
    now = 21_000;
    limiter.take();

    expect(limiter.take()).toBe(40_000);
  });

  it('frees a slot once the window has passed', () => {
    let now = 1_000;
    const limiter = new Limiter(1, 60_000, () => now);
    limiter.take();
    now = 61_000;

    expect(limiter.take()).toBe(0);
  });
});
