import { decode, encode } from './base64url.ts';

export type JsonObject = { [key: string]: unknown };

/**
 * The parts of the PublicKeyCredential interface the client uses, which older browsers may lack.
 */
export interface PublicKeyCredentialStatics {
  isConditionalMediationAvailable?(): Promise<boolean>;
  signalUnknownCredential?(options: { rpId: string; credentialId: string }): Promise<void>;
  signalAllAcceptedCredentials?(options: { rpId: string; userId: string; allAcceptedCredentialIds: string[] }): Promise<void>;
  signalCurrentUserDetails?(options: { rpId: string; userId: string; name: string; displayName: string }): Promise<void>;
}

function object(value: unknown, what: string): JsonObject {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    throw new TypeError(`${what} isn't an object.`);
  }

  return value as JsonObject;
}

function bytes(value: unknown, what: string): ArrayBuffer {
  if (typeof value !== 'string') {
    throw new TypeError(`${what} isn't base64url.`);
  }

  return decode(value);
}

function descriptors(value: unknown, what: string): PublicKeyCredentialDescriptor[] {
  if (value === undefined) {
    return [];
  }

  if (!Array.isArray(value)) {
    throw new TypeError(`${what} isn't a list.`);
  }

  return value.map((item: unknown) => {
    const descriptor = object(item, what);

    return { ...descriptor, id: bytes(descriptor['id'], `${what}' ID`) } as PublicKeyCredentialDescriptor;
  });
}

/**
 * navigator.credentials.create()'s options, from the server's JSON.
 *
 * @throws TypeError If they aren't WebAuthn's options
 */
export function creationOptions(json: unknown): PublicKeyCredentialCreationOptions {
  const options = object(json, 'The options');
  const user = object(options['user'], 'The user');

  return {
    ...options,
    challenge: bytes(options['challenge'], 'The challenge'),
    user: { ...user, id: bytes(user['id'], 'The user\'s ID') },
    excludeCredentials: descriptors(options['excludeCredentials'], 'The passkeys to exclude'),
  } as PublicKeyCredentialCreationOptions;
}

/**
 * navigator.credentials.get()'s options, from the server's JSON.
 *
 * @throws TypeError If they aren't WebAuthn's options
 */
export function requestOptions(json: unknown): PublicKeyCredentialRequestOptions {
  const options = object(json, 'The options');

  return {
    ...options,
    challenge: bytes(options['challenge'], 'The challenge'),
    allowCredentials: descriptors(options['allowCredentials'], 'The passkeys to allow'),
  } as PublicKeyCredentialRequestOptions;
}

function credential(value: Credential | null): PublicKeyCredential {
  if (value === null || value.type !== 'public-key') {
    throw new TypeError('The browser didn\'t give a passkey.');
  }

  return value as PublicKeyCredential;
}

function common(passkey: PublicKeyCredential): JsonObject {
  return {
    id: passkey.id,
    rawId: encode(passkey.rawId),
    type: passkey.type,
    clientExtensionResults: passkey.getClientExtensionResults(),
    ...(passkey.authenticatorAttachment === null ? {} : { authenticatorAttachment: passkey.authenticatorAttachment }),
  };
}

/**
 * A new passkey, as the JSON YorvoID takes. Encoded here rather than with toJSON(), which some
 * browsers and password managers lack or get wrong.
 */
export function registrationJson(value: Credential | null): JsonObject {
  const passkey = credential(value);
  const response = passkey.response as AuthenticatorAttestationResponse;

  return {
    ...common(passkey),
    response: {
      clientDataJSON: encode(response.clientDataJSON),
      attestationObject: encode(response.attestationObject),
      transports: typeof response.getTransports === 'function' ? response.getTransports() : [],
    },
  };
}

/**
 * A passkey's signature, as the JSON YorvoID and the intent verifier take.
 */
export function assertionJson(value: Credential | null): JsonObject {
  const passkey = credential(value);
  const response = passkey.response as AuthenticatorAssertionResponse;

  return {
    ...common(passkey),
    response: {
      clientDataJSON: encode(response.clientDataJSON),
      authenticatorData: encode(response.authenticatorData),
      signature: encode(response.signature),
      userHandle: response.userHandle === null ? null : encode(response.userHandle),
    },
  };
}
