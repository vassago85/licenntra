/** A valid 1x1 PNG, enough for upload validation, MIME sniffing and the printed pack. */
const onePixelPng = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
    'base64',
);

export function scan(name: string): { name: string; mimeType: string; buffer: Buffer } {
    return { name: `${name}.png`, mimeType: 'image/png', buffer: onePixelPng };
}
