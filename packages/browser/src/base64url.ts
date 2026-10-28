/**
 * Base64url without padding (RFC 4648 §5), as WebAuthn's JSON uses it.
 */
export function encode(bytes: ArrayBuffer | ArrayBufferView): string {
  const view = bytes instanceof ArrayBuffer ? new Uint8Array(bytes) : new Uint8Array(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  let binary = '';

  for (const byte of view) {
    binary += String.fromCharCode(byte);
  }

  return btoa(binary).replaceAll('+', '-').replaceAll('/', '_').replaceAll('=', '');
}

/**
 * @throws TypeError If it isn't base64url
 */
export function decode(text: string): ArrayBuffer {
  if (!/^[A-Za-z0-9_-]*$/.test(text) || text.length % 4 === 1) {
    throw new TypeError(`"${text}" isn't base64url.`);
  }

  return Uint8Array.from(atob(text.replaceAll('-', '+').replaceAll('_', '/')), (char) => char.charCodeAt(0)).buffer;
}
