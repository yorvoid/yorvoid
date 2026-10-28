import type { PublicKeyCredentialStatics } from '../src/webauthn.ts';

export interface Recorded {
  readonly path: string;
  readonly init: RequestInit;
  readonly body: unknown;
}

type Handler = (body: unknown) => Response | Promise<Response>;

export function json(body: unknown, status = 200, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } });
}

/**
 * The application's YorvoID routes, answering as configured, and remembering what they were sent.
 */
export class FakeServer {
  readonly requests: Recorded[] = [];
  readonly #routes = new Map<string, Handler>();

  on(path: string, handler: Handler | Response | unknown): this {
    this.#routes.set(path, typeof handler === 'function' ? handler as Handler : () => handler instanceof Response ? handler.clone() : json(handler));

    return this;
  }

  readonly fetch = async (input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> => {
    const path = String(input);
    const body: unknown = init.body === undefined ? undefined : JSON.parse(String(init.body));
    this.requests.push({ path, init, body });
    const handler = this.#routes.get(path);

    return handler === undefined ? json({ error: 'Not found' }, 404) : handler(body);
  };

  paths(): string[] {
    return this.requests.map((request) => request.path);
  }
}

export function bytes(...values: number[]): ArrayBuffer {
  return new Uint8Array(values).buffer;
}

export const REQUEST_OPTIONS = { challenge: 'AQID', rpId: 'example.com', allowCredentials: [], userVerification: 'required', timeout: 300000 };

export const CREATION_OPTIONS = {
  challenge: 'BAUG',
  rp: { id: 'example.com', name: 'example.com' },
  user: { id: 'BwgJ', name: 'ana@example.com', displayName: 'ana@example.com' },
  pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
  excludeCredentials: [{ type: 'public-key', id: 'CgsM', transports: ['internal'] }],
};

export function assertion(overrides: { userHandle?: ArrayBuffer | null; attachment?: string | null } = {}): Credential {
  return {
    id: 'AAE',
    rawId: bytes(0, 1),
    type: 'public-key',
    authenticatorAttachment: overrides.attachment === undefined ? 'platform' : overrides.attachment,
    getClientExtensionResults: () => ({ credProps: { rk: true } }),
    response: {
      clientDataJSON: bytes(1),
      authenticatorData: bytes(2),
      signature: bytes(3),
      userHandle: overrides.userHandle === undefined ? bytes(4) : overrides.userHandle,
    },
  } as unknown as Credential;
}

export function attestation(transports?: string[]): Credential {
  return {
    id: 'AAI',
    rawId: bytes(0, 2),
    type: 'public-key',
    authenticatorAttachment: 'cross-platform',
    getClientExtensionResults: () => ({}),
    response: {
      clientDataJSON: bytes(5),
      attestationObject: bytes(6),
      ...(transports === undefined ? {} : { getTransports: () => transports }),
    },
  } as unknown as Credential;
}

/**
 * navigator.credentials: answers with the given credential, or fails with the given error, and
 * remembers the options it was called with.
 */
export interface Call {
  readonly kind: 'create' | 'get';
  readonly options: {
    readonly publicKey?: { readonly challenge: BufferSource; readonly user?: { readonly id: BufferSource } };
    readonly mediation?: CredentialMediationRequirement;
    readonly signal?: AbortSignal;
  };
}

export class FakeCredentials {
  readonly calls: Call[] = [];
  answer: () => Promise<Credential | null>;

  constructor(answer: () => Promise<Credential | null> = async () => assertion()) {
    this.answer = answer;
  }

  readonly get = async (options: CredentialRequestOptions = {}): Promise<Credential | null> => {
    this.calls.push({ kind: 'get', options: options as Call['options'] });

    return this.answer();
  };

  readonly create = async (options: CredentialCreationOptions = {}): Promise<Credential | null> => {
    this.calls.push({ kind: 'create', options: options as Call['options'] });

    return this.answer();
  };
}

/**
 * PublicKeyCredential's static methods, remembering what they were told.
 */
export function statics(conditional = true): PublicKeyCredentialStatics & { told: unknown[][] } {
  const told: unknown[][] = [];

  return {
    told,
    isConditionalMediationAvailable: async () => conditional,
    signalUnknownCredential: async (options) => { told.push(['unknown', options]); },
    signalAllAcceptedCredentials: async (options) => { told.push(['accepted', options]); },
    signalCurrentUserDetails: async (options) => { told.push(['user', options]); },
  };
}

/**
 * A promise that rejects when the signal aborts, as navigator.credentials does.
 */
export function untilAborted(signal: AbortSignal | null | undefined): Promise<Credential | null> {
  return new Promise((_, reject) => {
    signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
  });
}
