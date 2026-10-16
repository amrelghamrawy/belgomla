import makeWASocket, { Browsers, useMultiFileAuthState, type ConnectionState, type WAMessageUpdate, type proto } from 'baileys';
import type { Logger } from 'pino';

export interface SocketEvents {
  'connection.update': Partial<ConnectionState>;
  'messages.update': WAMessageUpdate[];
}

/**
 * The part of a Baileys socket a session uses. Sessions only see this interface, so tests drive them with a
 * fake socket and never open a WhatsApp connection.
 */
export interface WaSocket {
  readonly user: { id: string } | undefined;
  on<E extends keyof SocketEvents>(event: E, handler: (data: SocketEvents[E]) => void): void;
  /** Looks a number (digits, no `+`) up on WhatsApp; `jid` is the chat to send to. */
  onWhatsApp(digits: string): Promise<{ exists: boolean; jid: string } | undefined>;
  /** Sends a text and resolves with the WhatsApp message id. */
  sendText(jid: string, text: string): Promise<string>;
  /** Unlinks this device from the phone (tasks/12 lesson 1). */
  logout(): Promise<void>;
  end(): void;
}

export type SocketFactory = (options: { authDir: string; logger: Logger }) => Promise<WaSocket>;

const RETRY_CACHE_SIZE = 200;

/**
 * The real WhatsApp Web socket. Credentials live in the session folder; full history sync is off (the gateway
 * only sends), and the last sent messages are kept so WhatsApp can ask for a re-send when a recipient could not
 * decrypt one.
 */
export const baileysSocketFactory: SocketFactory = async ({ authDir, logger }) => {
  const { state, saveCreds } = await useMultiFileAuthState(authDir);
  const sent = new Map<string, proto.IMessage>();

  const socket = makeWASocket({
    auth: state,
    logger: logger.child({ module: 'baileys' }, { level: 'warn' }),
    browser: Browsers.ubuntu('BelGomla'),
    markOnlineOnConnect: false,
    // Baileys' default initial sync stays on: it carries the LID ↔ phone mappings sends depend on.
    syncFullHistory: false,
    getMessage: async (key) => (key.id ? sent.get(key.id) : undefined),
  });

  socket.ev.on('creds.update', saveCreds);

  return {
    get user() {
      return socket.user ?? undefined;
    },
    on(event, handler) {
      socket.ev.on(event, handler as never);
    },
    async onWhatsApp(digits) {
      const [result] = (await socket.onWhatsApp(digits)) ?? [];

      return result;
    },
    async sendText(jid, text) {
      const message = await socket.sendMessage(jid, { text });
      const id = message?.key.id;

      if (!id) {
        throw new Error('whatsapp returned no message id');
      }

      if (message.message) {
        sent.set(id, message.message);

        if (sent.size > RETRY_CACHE_SIZE) {
          sent.delete(sent.keys().next().value as string);
        }
      }

      return id;
    },
    logout: () => socket.logout(),
    end: () => {
      void socket.end(undefined);
    },
  };
};
