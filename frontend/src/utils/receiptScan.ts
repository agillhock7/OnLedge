type Rect = {
  x: number;
  y: number;
  width: number;
  height: number;
};

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

function canvasToBlob(canvas: HTMLCanvasElement, type = 'image/jpeg', quality = 0.94): Promise<Blob> {
  return new Promise((resolve, reject) => {
    canvas.toBlob((blob) => {
      if (!blob) {
        reject(new Error('Unable to create image blob'));
        return;
      }
      resolve(blob);
    }, type, quality);
  });
}

function detectReceiptBounds(sourceCanvas: HTMLCanvasElement): Rect | null {
  const maxDim = 360;
  const scale = Math.min(1, maxDim / Math.max(sourceCanvas.width, sourceCanvas.height));
  const width = Math.max(24, Math.round(sourceCanvas.width * scale));
  const height = Math.max(24, Math.round(sourceCanvas.height * scale));

  const sampleCanvas = document.createElement('canvas');
  sampleCanvas.width = width;
  sampleCanvas.height = height;
  const sampleCtx = sampleCanvas.getContext('2d', { willReadFrequently: true });
  if (!sampleCtx) {
    return null;
  }

  sampleCtx.drawImage(sourceCanvas, 0, 0, width, height);
  const image = sampleCtx.getImageData(0, 0, width, height);
  const data = image.data;
  const pixels = width * height;

  const luminance = new Float32Array(pixels);
  let sum = 0;
  for (let i = 0, p = 0; i < data.length; i += 4, p += 1) {
    const l = data[i] * 0.2126 + data[i + 1] * 0.7152 + data[i + 2] * 0.0722;
    luminance[p] = l;
    sum += l;
  }

  const mean = sum / pixels;
  let variance = 0;
  for (let i = 0; i < pixels; i += 1) {
    const delta = luminance[i] - mean;
    variance += delta * delta;
  }
  const stdDev = Math.sqrt(variance / pixels);
  const threshold = clamp(mean + stdDev * 0.55, 122, 245);

  const binary = new Uint8Array(pixels);
  for (let i = 0; i < pixels; i += 1) {
    binary[i] = luminance[i] >= threshold ? 1 : 0;
  }

  const denoised = new Uint8Array(pixels);
  for (let y = 1; y < height - 1; y += 1) {
    for (let x = 1; x < width - 1; x += 1) {
      const idx = y * width + x;
      let count = 0;
      for (let oy = -1; oy <= 1; oy += 1) {
        for (let ox = -1; ox <= 1; ox += 1) {
          if (binary[idx + oy * width + ox] === 1) {
            count += 1;
          }
        }
      }

      if (binary[idx] === 1 && count >= 3) {
        denoised[idx] = 1;
      } else if (binary[idx] === 0 && count >= 7) {
        denoised[idx] = 1;
      }
    }
  }

  const visited = new Uint8Array(pixels);
  const stack = new Int32Array(pixels);
  const minArea = Math.max(1200, Math.round(pixels * 0.03));
  let best: {
    score: number;
    area: number;
    minX: number;
    minY: number;
    maxX: number;
    maxY: number;
    touchesBorder: boolean;
  } | null = null;

  for (let i = 0; i < pixels; i += 1) {
    if (denoised[i] === 0 || visited[i] === 1) {
      continue;
    }

    let top = 0;
    stack[top] = i;
    top += 1;
    visited[i] = 1;

    let area = 0;
    let minX = width;
    let minY = height;
    let maxX = 0;
    let maxY = 0;
    let touchesBorder = false;

    while (top > 0) {
      top -= 1;
      const current = stack[top];
      const y = Math.floor(current / width);
      const x = current - y * width;

      area += 1;
      minX = Math.min(minX, x);
      minY = Math.min(minY, y);
      maxX = Math.max(maxX, x);
      maxY = Math.max(maxY, y);

      if (x <= 1 || x >= width - 2 || y <= 1 || y >= height - 2) {
        touchesBorder = true;
      }

      const neighbors = [current - 1, current + 1, current - width, current + width];
      for (const next of neighbors) {
        if (next < 0 || next >= pixels || visited[next] === 1 || denoised[next] === 0) {
          continue;
        }
        visited[next] = 1;
        stack[top] = next;
        top += 1;
      }
    }

    if (area < minArea) {
      continue;
    }

    const score = area - (touchesBorder ? area * 0.2 : 0);
    if (!best || score > best.score) {
      best = { score, area, minX, minY, maxX, maxY, touchesBorder };
    }
  }

  if (!best) {
    return null;
  }

  const ratio = best.area / pixels;
  const boxWidth = best.maxX - best.minX + 1;
  const boxHeight = best.maxY - best.minY + 1;
  const boxCoverage = (boxWidth * boxHeight) / pixels;

  if (ratio < 0.09) {
    return null;
  }

  if (best.touchesBorder && boxCoverage > 0.92) {
    return null;
  }

  const invScale = 1 / scale;
  const padX = Math.round(sourceCanvas.width * 0.02);
  const padY = Math.round(sourceCanvas.height * 0.02);
  const x = clamp(Math.round(best.minX * invScale) - padX, 0, sourceCanvas.width - 1);
  const y = clamp(Math.round(best.minY * invScale) - padY, 0, sourceCanvas.height - 1);
  const maxX = clamp(Math.round((best.maxX + 1) * invScale) + padX, x + 1, sourceCanvas.width);
  const maxY = clamp(Math.round((best.maxY + 1) * invScale) + padY, y + 1, sourceCanvas.height);

  return {
    x,
    y,
    width: maxX - x,
    height: maxY - y,
  };
}

function centerGuideBounds(sourceCanvas: HTMLCanvasElement): Rect {
  const width = Math.round(sourceCanvas.width * 0.88);
  const height = Math.round(sourceCanvas.height * 0.92);
  return {
    x: Math.round((sourceCanvas.width - width) / 2),
    y: Math.round((sourceCanvas.height - height) / 2),
    width,
    height,
  };
}

function enhanceReceipt(canvas: HTMLCanvasElement): void {
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  if (!ctx) {
    return;
  }

  const image = ctx.getImageData(0, 0, canvas.width, canvas.height);
  const data = image.data;
  const contrast = 1.16;
  const brightness = 6;

  for (let i = 0; i < data.length; i += 4) {
    const r = data[i];
    const g = data[i + 1];
    const b = data[i + 2];

    let nr = (r - 128) * contrast + 128 + brightness;
    let ng = (g - 128) * contrast + 128 + brightness;
    let nb = (b - 128) * contrast + 128 + brightness;

    if ((r + g + b) / 3 > 222) {
      nr = 255;
      ng = 255;
      nb = 255;
    }

    data[i] = clamp(Math.round(nr), 0, 255);
    data[i + 1] = clamp(Math.round(ng), 0, 255);
    data[i + 2] = clamp(Math.round(nb), 0, 255);
  }

  ctx.putImageData(image, 0, 0);
}

type ProcessOptions = {
  smartScan: boolean;
};

export async function processCapturedFrame(sourceCanvas: HTMLCanvasElement, options: ProcessOptions): Promise<Blob> {
  const crop = options.smartScan
    ? (detectReceiptBounds(sourceCanvas) ?? centerGuideBounds(sourceCanvas))
    : {
        x: 0,
        y: 0,
        width: sourceCanvas.width,
        height: sourceCanvas.height,
      };

  const output = document.createElement('canvas');
  output.width = crop.width;
  output.height = crop.height;
  const ctx = output.getContext('2d');
  if (!ctx) {
    throw new Error('Unable to initialize image processing context');
  }

  ctx.drawImage(
    sourceCanvas,
    crop.x,
    crop.y,
    crop.width,
    crop.height,
    0,
    0,
    crop.width,
    crop.height,
  );

  if (options.smartScan) {
    enhanceReceipt(output);
  }

  return canvasToBlob(output, 'image/jpeg', 0.94);
}

type LoadedImage = {
  element: HTMLImageElement;
  width: number;
  height: number;
  revoke: () => void;
};

function loadImageFromBlob(blob: Blob): Promise<LoadedImage> {
  const url = URL.createObjectURL(blob);
  const image = new Image();
  image.decoding = 'async';

  return new Promise((resolve, reject) => {
    image.onload = () => {
      resolve({
        element: image,
        width: image.naturalWidth || image.width,
        height: image.naturalHeight || image.height,
        revoke: () => URL.revokeObjectURL(url),
      });
    };
    image.onerror = () => {
      URL.revokeObjectURL(url);
      reject(new Error('Unable to decode captured image'));
    };
    image.src = url;
  });
}

export async function stitchReceiptSegments(segments: Blob[]): Promise<Blob> {
  if (segments.length === 0) {
    throw new Error('No receipt segments to stitch');
  }

  if (segments.length === 1) {
    return segments[0];
  }

  const loaded = await Promise.all(segments.map((segment) => loadImageFromBlob(segment)));
  try {
    const maxWidth = Math.max(...loaded.map((item) => item.width));
    const targetWidth = clamp(maxWidth, 1080, 2200);
    const gap = Math.max(4, Math.round(targetWidth * 0.004));

    const scaledHeights = loaded.map((item) => Math.round((item.height / item.width) * targetWidth));
    const totalHeight = scaledHeights.reduce((sum, h) => sum + h, 0) + gap * (loaded.length - 1);

    const canvas = document.createElement('canvas');
    canvas.width = targetWidth;
    canvas.height = totalHeight;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
      throw new Error('Unable to initialize stitching canvas');
    }

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    let cursorY = 0;
    loaded.forEach((item, index) => {
      const height = scaledHeights[index];
      ctx.drawImage(item.element, 0, cursorY, targetWidth, height);
      cursorY += height + gap;
    });

    return canvasToBlob(canvas, 'image/jpeg', 0.95);
  } finally {
    loaded.forEach((item) => item.revoke());
  }
}
