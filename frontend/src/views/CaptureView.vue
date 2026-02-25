<template>
  <section class="page capture-page">
    <h1>Scan Receipt</h1>
    <p class="muted">Capture a receipt, adjust edges, and upload.</p>

    <ol class="capture-steps" aria-label="Receipt capture steps">
      <li class="capture-step" :class="{ active: !previewUrl }">1. Capture</li>
      <li class="capture-step" :class="{ active: !!previewUrl }">2. Adjust</li>
      <li class="capture-step" :class="{ active: !!previewUrl }">3. Upload</li>
    </ol>

    <div class="card camera-capture" style="margin-top: 1rem">
      <div class="camera-stage">
        <video
          v-show="!previewUrl && cameraSupported"
          ref="videoEl"
          class="camera-feed"
          autoplay
          muted
          playsinline
        ></video>

        <div v-if="previewUrl" class="preview-editor">
          <img ref="previewImageEl" :src="previewUrl" alt="Receipt preview" class="camera-feed" />

          <svg class="edge-overlay" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
            <polygon :points="polygonPoints" />
          </svg>

          <button
            v-for="(_, index) in corners"
            :key="index"
            class="corner-handle"
            type="button"
            :style="cornerStyle(index)"
            :aria-label="`Move corner ${index + 1}`"
            @pointerdown="startHandleDrag(index, $event)"
          >
            <span class="visually-hidden">Corner {{ index + 1 }}</span>
          </button>
        </div>

        <div v-if="!previewUrl && cameraSupported" class="camera-overlay" aria-hidden="true">
          <div class="camera-guide">
            <span class="guide-corner tl"></span>
            <span class="guide-corner tr"></span>
            <span class="guide-corner bl"></span>
            <span class="guide-corner br"></span>
          </div>
        </div>

        <div v-if="!cameraSupported" class="camera-placeholder">
          Camera capture is not available in this browser. Use photo upload instead.
        </div>

        <canvas ref="canvasEl" style="display: none"></canvas>
      </div>

      <p class="muted capture-hint">
        {{ previewUrl
          ? 'Drag each corner to match the receipt edges as tightly as possible.'
          : 'Position the receipt inside the frame and tap Capture Receipt.' }}
      </p>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="message" class="success">{{ message }}</p>

      <div class="capture-actions" v-if="!previewUrl">
        <button
          class="primary capture-primary"
          :disabled="starting || submitting || processingCapture || !cameraSupported || !stream"
          @click="capturePhoto"
        >
          {{ starting ? 'Starting Camera...' : processingCapture ? 'Capturing...' : 'Capture Receipt' }}
        </button>

        <button
          type="button"
          class="ghost"
          :disabled="starting || submitting || processingCapture || !cameraSupported"
          @click="switchCamera"
        >
          Switch Camera
        </button>

        <label class="ghost capture-file-label" :class="{ disabled: submitting || processingCapture }">
          Choose Photo
          <input
            class="capture-file-input"
            type="file"
            accept="image/jpeg,image/png"
            capture="environment"
            :disabled="submitting || processingCapture"
            @change="onFallbackFile"
          />
        </label>
      </div>

      <div class="capture-actions" v-else>
        <button type="button" class="ghost" :disabled="submitting || processingCapture" @click="retake">Retake</button>
        <button type="button" class="ghost" :disabled="submitting || processingCapture" @click="applyAutoEdges">Auto Fit</button>
        <button class="primary" :disabled="submitting || processingCapture" @click="uploadCapture">
          {{ submitting ? 'Uploading...' : 'Upload Selected Area' }}
        </button>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';

import { useReceiptsStore } from '@/stores/receipts';
import {
  correctReceiptPerspective,
  defaultReceiptCorners,
  type NormalizedCorner,
  suggestReceiptCorners
} from '@/utils/receiptScan';

const receipts = useReceiptsStore();

const videoEl = ref<HTMLVideoElement | null>(null);
const previewImageEl = ref<HTMLImageElement | null>(null);
const canvasEl = ref<HTMLCanvasElement | null>(null);
const stream = ref<MediaStream | null>(null);
const capturedBlob = ref<Blob | null>(null);
const previewUrl = ref('');
const starting = ref(false);
const submitting = ref(false);
const processingCapture = ref(false);
const error = ref('');
const message = ref('');
const facingMode = ref<'environment' | 'user'>('environment');
const corners = ref<NormalizedCorner[]>(defaultReceiptCorners());
const activeHandleIndex = ref<number | null>(null);
const cameraSupported = Boolean(navigator.mediaDevices?.getUserMedia);

const polygonPoints = computed(() =>
  corners.value.map((point) => `${(point.x * 100).toFixed(2)},${(point.y * 100).toFixed(2)}`).join(' ')
);

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

function clearStatus(): void {
  error.value = '';
  message.value = '';
}

function clearPreview(): void {
  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value);
  }
  previewUrl.value = '';
}

function setPreview(blob: Blob): void {
  clearPreview();
  previewUrl.value = URL.createObjectURL(blob);
}

function stopCamera(): void {
  stream.value?.getTracks().forEach((track) => track.stop());
  stream.value = null;
  if (videoEl.value) {
    videoEl.value.srcObject = null;
  }
}

async function startCamera(): Promise<void> {
  if (!cameraSupported || previewUrl.value) {
    return;
  }

  stopCamera();
  starting.value = true;
  error.value = '';

  try {
    const mediaStream = await navigator.mediaDevices.getUserMedia({
      video: {
        facingMode: { ideal: facingMode.value },
        width: { ideal: 1920 },
        height: { ideal: 1080 }
      },
      audio: false
    });

    stream.value = mediaStream;

    await nextTick();

    if (videoEl.value) {
      videoEl.value.srcObject = mediaStream;
      await videoEl.value.play();
    }
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to access camera';
  } finally {
    starting.value = false;
  }
}

async function switchCamera(): Promise<void> {
  facingMode.value = facingMode.value === 'environment' ? 'user' : 'environment';
  await startCamera();
}

function cornerStyle(index: number): Record<string, string> {
  const point = corners.value[index];
  return {
    left: `${point.x * 100}%`,
    top: `${point.y * 100}%`
  };
}

function updateCornerFromEvent(index: number, event: PointerEvent): void {
  const image = previewImageEl.value;
  if (!image) {
    return;
  }

  const rect = image.getBoundingClientRect();
  if (rect.width <= 0 || rect.height <= 0) {
    return;
  }

  const x = clamp((event.clientX - rect.left) / rect.width, 0, 1);
  const y = clamp((event.clientY - rect.top) / rect.height, 0, 1);

  const next = corners.value.map((point) => ({ ...point }));
  next[index] = { x, y };
  corners.value = next;
}

function startHandleDrag(index: number, event: PointerEvent): void {
  activeHandleIndex.value = index;
  const target = event.currentTarget as HTMLElement | null;
  if (target && typeof target.setPointerCapture === 'function') {
    target.setPointerCapture(event.pointerId);
  }

  updateCornerFromEvent(index, event);
}

function onGlobalPointerMove(event: PointerEvent): void {
  if (activeHandleIndex.value === null) {
    return;
  }

  updateCornerFromEvent(activeHandleIndex.value, event);
}

function onGlobalPointerUp(): void {
  activeHandleIndex.value = null;
}

async function setCapturedPreview(blob: Blob): Promise<void> {
  capturedBlob.value = blob;
  setPreview(blob);
  corners.value = defaultReceiptCorners();

  try {
    corners.value = await suggestReceiptCorners(blob);
  } catch {
    corners.value = defaultReceiptCorners();
  }
}

async function applyAutoEdges(): Promise<void> {
  if (!capturedBlob.value) {
    return;
  }

  processingCapture.value = true;
  error.value = '';

  try {
    corners.value = await suggestReceiptCorners(capturedBlob.value);
    message.value = 'Edges refreshed. Drag corners if needed.';
  } catch {
    error.value = 'Could not auto-fit edges for this image.';
  } finally {
    processingCapture.value = false;
  }
}

async function capturePhoto(): Promise<void> {
  if (!videoEl.value || !canvasEl.value) {
    return;
  }

  clearStatus();
  processingCapture.value = true;

  try {
    const width = videoEl.value.videoWidth || 1280;
    const height = videoEl.value.videoHeight || 720;
    canvasEl.value.width = width;
    canvasEl.value.height = height;

    const context = canvasEl.value.getContext('2d');
    if (!context) {
      throw new Error('Unable to capture from camera');
    }

    context.drawImage(videoEl.value, 0, 0, width, height);

    const blob = await new Promise<Blob>((resolve, reject) => {
      canvasEl.value?.toBlob((result) => {
        if (!result) {
          reject(new Error('Unable to create capture image'));
          return;
        }
        resolve(result);
      }, 'image/jpeg', 0.94);
    });

    await setCapturedPreview(blob);
    stopCamera();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to capture receipt';
  } finally {
    processingCapture.value = false;
  }
}

async function retake(): Promise<void> {
  capturedBlob.value = null;
  clearPreview();
  corners.value = defaultReceiptCorners();
  clearStatus();
  await startCamera();
}

async function onFallbackFile(event: Event): Promise<void> {
  const target = event.target as HTMLInputElement;
  const file = target.files?.[0];
  if (!file) {
    return;
  }

  clearStatus();
  stopCamera();
  await setCapturedPreview(file);
  target.value = '';
}

async function uploadCapture(): Promise<void> {
  if (!capturedBlob.value) {
    error.value = 'Capture or choose an image first.';
    return;
  }

  submitting.value = true;
  clearStatus();

  try {
    const uploadBlob = await correctReceiptPerspective(capturedBlob.value, corners.value, { enhance: true });

    const file = new File(
      [uploadBlob],
      `receipt-${new Date().toISOString().replace(/[:.]/g, '-')}.jpg`,
      { type: uploadBlob.type || 'image/jpeg' }
    );

    const created = await receipts.createReceipt(
      {
        purchased_at: new Date().toISOString().slice(0, 10),
        currency: 'USD'
      },
      file
    );

    if (navigator.onLine && !created.offline) {
      await receipts.processReceipt(created.id).catch(() => {
        // AI extraction is best-effort; upload should still succeed.
      });
    }

    message.value = navigator.onLine
      ? 'Receipt uploaded. AI extraction started.'
      : 'Receipt saved offline. It will sync when you reconnect.';

    capturedBlob.value = null;
    clearPreview();
    corners.value = defaultReceiptCorners();
    await startCamera();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to upload receipt';
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  window.addEventListener('pointermove', onGlobalPointerMove);
  window.addEventListener('pointerup', onGlobalPointerUp);
  window.addEventListener('pointercancel', onGlobalPointerUp);
  await startCamera();
});

onUnmounted(() => {
  window.removeEventListener('pointermove', onGlobalPointerMove);
  window.removeEventListener('pointerup', onGlobalPointerUp);
  window.removeEventListener('pointercancel', onGlobalPointerUp);
  stopCamera();
  clearPreview();
});
</script>
