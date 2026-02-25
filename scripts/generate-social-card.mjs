import { deflateSync } from 'node:zlib';
import { writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const WIDTH = 1200;
const HEIGHT = 630;
const __dirname = dirname(fileURLToPath(import.meta.url));
const OUTPUT = resolve(__dirname, '../frontend/public/social-card.png');

const data = Buffer.alloc(WIDTH * HEIGHT * 4, 0);

function clamp(value, min, max) {
  return Math.max(min, Math.min(max, value));
}

function mix(a, b, t) {
  return Math.round(a + (b - a) * t);
}

function setPixel(x, y, r, g, b, a = 255) {
  if (x < 0 || y < 0 || x >= WIDTH || y >= HEIGHT) {
    return;
  }

  const i = (y * WIDTH + x) * 4;
  data[i] = clamp(r, 0, 255);
  data[i + 1] = clamp(g, 0, 255);
  data[i + 2] = clamp(b, 0, 255);
  data[i + 3] = clamp(a, 0, 255);
}

function blendPixel(x, y, r, g, b, alpha) {
  if (x < 0 || y < 0 || x >= WIDTH || y >= HEIGHT) {
    return;
  }

  const i = (y * WIDTH + x) * 4;
  const bgA = data[i + 3] / 255;
  const fgA = clamp(alpha, 0, 255) / 255;
  const outA = fgA + bgA * (1 - fgA);

  if (outA <= 0) {
    return;
  }

  data[i] = Math.round((r * fgA + data[i] * bgA * (1 - fgA)) / outA);
  data[i + 1] = Math.round((g * fgA + data[i + 1] * bgA * (1 - fgA)) / outA);
  data[i + 2] = Math.round((b * fgA + data[i + 2] * bgA * (1 - fgA)) / outA);
  data[i + 3] = Math.round(outA * 255);
}

function fillRect(x, y, w, h, color) {
  for (let py = y; py < y + h; py += 1) {
    for (let px = x; px < x + w; px += 1) {
      setPixel(px, py, color[0], color[1], color[2], color[3] ?? 255);
    }
  }
}

function fillRoundedRect(x, y, w, h, radius, color) {
  const rr = radius * radius;
  for (let py = y; py < y + h; py += 1) {
    for (let px = x; px < x + w; px += 1) {
      let inside = true;

      if (px < x + radius && py < y + radius) {
        const dx = px - (x + radius);
        const dy = py - (y + radius);
        inside = (dx * dx + dy * dy) <= rr;
      } else if (px > x + w - radius - 1 && py < y + radius) {
        const dx = px - (x + w - radius - 1);
        const dy = py - (y + radius);
        inside = (dx * dx + dy * dy) <= rr;
      } else if (px < x + radius && py > y + h - radius - 1) {
        const dx = px - (x + radius);
        const dy = py - (y + h - radius - 1);
        inside = (dx * dx + dy * dy) <= rr;
      } else if (px > x + w - radius - 1 && py > y + h - radius - 1) {
        const dx = px - (x + w - radius - 1);
        const dy = py - (y + h - radius - 1);
        inside = (dx * dx + dy * dy) <= rr;
      }

      if (inside) {
        setPixel(px, py, color[0], color[1], color[2], color[3] ?? 255);
      }
    }
  }
}

function fillCircle(cx, cy, radius, color) {
  const rr = radius * radius;
  for (let y = cy - radius; y <= cy + radius; y += 1) {
    for (let x = cx - radius; x <= cx + radius; x += 1) {
      const dx = x - cx;
      const dy = y - cy;
      if (dx * dx + dy * dy <= rr) {
        blendPixel(x, y, color[0], color[1], color[2], color[3] ?? 255);
      }
    }
  }
}

// Background gradient and atmospheric highlight.
for (let y = 0; y < HEIGHT; y += 1) {
  for (let x = 0; x < WIDTH; x += 1) {
    const tx = x / (WIDTH - 1);
    const ty = y / (HEIGHT - 1);
    const t = Math.min(1, tx * 0.68 + ty * 0.38);

    let r;
    let g;
    let b;
    if (t < 0.62) {
      const lt = t / 0.62;
      r = mix(14, 27, lt);
      g = mix(49, 89, lt);
      b = mix(61, 112, lt);
    } else {
      const lt = (t - 0.62) / 0.38;
      r = mix(27, 221, lt);
      g = mix(89, 132, lt);
      b = mix(112, 54, lt);
    }

    setPixel(x, y, r, g, b, 255);
  }
}

for (let y = 0; y < HEIGHT; y += 1) {
  for (let x = 0; x < WIDTH; x += 1) {
    const dx = x - WIDTH * 0.86;
    const dy = y - HEIGHT * 0.17;
    const dist = Math.sqrt(dx * dx + dy * dy);
    const falloff = clamp(1 - dist / 340, 0, 1);
    if (falloff > 0) {
      blendPixel(x, y, 255, 255, 255, Math.round(60 * falloff));
    }
  }
}

fillRoundedRect(160, 95, 440, 440, 56, [245, 250, 247, 255]);
fillRoundedRect(160, 95, 440, 440, 56, [255, 255, 255, 26]);
fillRect(220, 180, 300, 14, [160, 184, 189, 255]);
fillRect(220, 228, 300, 14, [160, 184, 189, 255]);
fillRect(220, 276, 236, 14, [160, 184, 189, 255]);

fillCircle(380, 392, 116, [16, 48, 58, 255]);
fillCircle(380, 392, 76, [241, 171, 74, 255]);
fillCircle(380, 392, 39, [255, 223, 171, 255]);
fillCircle(412, 360, 16, [255, 255, 255, 190]);

// Brand block on right side.
fillRoundedRect(662, 168, 376, 292, 36, [14, 43, 54, 190]);
fillRect(720, 260, 260, 12, [89, 129, 143, 255]);
fillRect(720, 295, 220, 12, [89, 129, 143, 255]);
fillRect(720, 330, 180, 12, [89, 129, 143, 255]);

// Minimal geometric "O" glyph to suggest OnLedge wordmark area without font dependency.
fillCircle(736, 218, 26, [241, 171, 74, 255]);
fillCircle(736, 218, 13, [14, 43, 54, 255]);

function crc32(buffer) {
  let c = 0xffffffff;
  for (let i = 0; i < buffer.length; i += 1) {
    c ^= buffer[i];
    for (let k = 0; k < 8; k += 1) {
      c = (c & 1) ? (0xedb88320 ^ (c >>> 1)) : (c >>> 1);
    }
  }
  return (c ^ 0xffffffff) >>> 0;
}

function chunk(type, payload) {
  const typeBuf = Buffer.from(type, 'ascii');
  const len = Buffer.alloc(4);
  len.writeUInt32BE(payload.length, 0);

  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(Buffer.concat([typeBuf, payload])), 0);

  return Buffer.concat([len, typeBuf, payload, crc]);
}

function writePng() {
  const signature = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);

  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(WIDTH, 0);
  ihdr.writeUInt32BE(HEIGHT, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 6; // color type RGBA
  ihdr[10] = 0; // compression
  ihdr[11] = 0; // filter
  ihdr[12] = 0; // interlace

  const stride = WIDTH * 4;
  const raw = Buffer.alloc((stride + 1) * HEIGHT);
  for (let y = 0; y < HEIGHT; y += 1) {
    const rowStart = y * (stride + 1);
    raw[rowStart] = 0;
    data.copy(raw, rowStart + 1, y * stride, y * stride + stride);
  }

  const idat = deflateSync(raw, { level: 9 });
  const png = Buffer.concat([
    signature,
    chunk('IHDR', ihdr),
    chunk('IDAT', idat),
    chunk('IEND', Buffer.alloc(0)),
  ]);

  writeFileSync(OUTPUT, png);
}

writePng();
console.log(`Wrote ${OUTPUT}`);
