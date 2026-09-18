import { createHmac } from 'node:crypto';

function base32Decode(input: string): Buffer {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of input.replace(/=+$/, '').toUpperCase()) {
        const val = alphabet.indexOf(char);
        if (val === -1) continue;
        bits += val.toString(2).padStart(5, '0');
    }
    const bytes: number[] = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) {
        bytes.push(parseInt(bits.substring(i, i + 8), 2));
    }
    return Buffer.from(bytes);
}

/**
 * RFC 6238 TOTP, matching Fortify/Google2FA's defaults (HMAC-SHA1, 6 digits,
 * 30s step) -- verified against `PragmaRX\Google2FA\Google2FA::verifyKey()`
 * server-side before relying on it here. Lets a Playwright test complete a
 * real 2FA challenge in the browser instead of bypassing auth entirely.
 */
export function totp(
    secretBase32: string,
    { digits = 6, step = 30, timestamp = Date.now() } = {},
): string {
    const key = base32Decode(secretBase32);
    const counter = Math.floor(timestamp / 1000 / step);
    const counterBuffer = Buffer.alloc(8);
    counterBuffer.writeBigUInt64BE(BigInt(counter));

    const hmac = createHmac('sha1', key).update(counterBuffer).digest();
    const offset = hmac[hmac.length - 1] & 0x0f;
    const binCode =
        ((hmac[offset] & 0x7f) << 24) |
        ((hmac[offset + 1] & 0xff) << 16) |
        ((hmac[offset + 2] & 0xff) << 8) |
        (hmac[offset + 3] & 0xff);

    return (binCode % 10 ** digits).toString().padStart(digits, '0');
}
