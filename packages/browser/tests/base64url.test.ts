import assert from 'node:assert/strict';
import { test } from 'node:test';
import { decode, encode } from '../src/base64url.ts';

test('encodes without padding, with the URL-safe alphabet', () => {
  assert.equal(encode(new Uint8Array([])), '');
  assert.equal(encode(new Uint8Array([0xfb, 0xff])), '-_8');
  assert.equal(encode(new Uint8Array([0x66, 0x6f, 0x6f, 0x62])), 'Zm9vYg');
  assert.equal(encode(new Uint8Array([0x66, 0x6f, 0x6f]).buffer), 'Zm9v');
});

test('encodes only the bytes a view shows', () => {
  const buffer = new Uint8Array([1, 2, 3, 4, 5]).buffer;

  assert.equal(encode(new Uint8Array(buffer, 1, 3)), encode(new Uint8Array([2, 3, 4])));
  assert.equal(encode(new DataView(buffer, 4, 1)), 'BQ');
});

test('decodes what it encodes', () => {
  const all = new Uint8Array(256).map((_, i) => i);

  assert.deepEqual(new Uint8Array(decode(encode(all))), all);
  assert.deepEqual(new Uint8Array(decode('-_8')), new Uint8Array([0xfb, 0xff]));
  assert.deepEqual(new Uint8Array(decode('')), new Uint8Array([]));
});

test('refuses what isn\'t base64url', () => {
  for (const text of ['+/8', 'Zm9v=', 'Zm9vY', 'Zm 9v', 'Zm9v\n']) {
    assert.throws(() => decode(text), new TypeError(`"${text}" isn't base64url.`));
  }
});
