<template>
  <section class="page">
    <h1>Capture Receipt</h1>
    <p class="muted">
      Use smart scan to isolate the receipt from background. For long receipts, capture multiple segments and stitch them into one image.
    </p>

    <div class="card camera-capture" style="margin-top: 1rem">
      <div class="scan-mode-controls" role="group" aria-label="Capture options">
        <label class="scan-toggle">
          <input type="checkbox" v-model="smartScan" :disabled="submitting || previewUrl !== ''" />
          Smart scan crop
        </label>
        <label class="scan-toggle">
          <input type="checkbox" v-model="longReceiptMode" :disabled="submitting || previewUrl !== ''" @change="handleLongModeChange" />
          Long receipt mode
        </label>
      </div>

      <p class="muted scan-hint">
        {{ longReceiptMode
          ? 'Capture each section with a small overlap, then tap Finalize Scan.'
          : 'Align receipt inside the guide frame, then tap Capture.' }}
      </p>

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
          <img ref="previewImageEl" :src="previewUrl" alt="Captured receipt preview" class="camera-feed" />

          <svg v-if="manualAdjust" class="edge-overlay" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
            <polygon :points="polygonPoints" />
          </svg>

          <button
            v-if="manualAdjust"
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
          <p class="camera-guide-copy">Fit receipt edges within the frame</p>
        </div>

        <div v-if="!cameraSupported" class="camera-placeholder">
          Camera capture is not available in this browser. Use image upload fallback.
        </div>

        <canvas ref="canvasEl" style="display: none"></canvas>
      </div>

      <div v-if="previewUrl" class="inline" style="margin-top: 0.8rem">
        <label class="scan-toggle">
          <input type="checkbox" v-model="manualAdjust" :disabled="submitting" />
          Manual edge adjust
        </label>
        <button
          type="button"
          class="ghost"
          :disabled="submitting || processingCapture"
          @click="applyAutoEdges"
        >
          Auto Detect Edges
        </button>
      </div>

      <div v-if="longReceiptMode && !previewUrl && segmentPreviews.length > 0" class="segment-strip">
        <div v-for="(segment, index) in segmentPreviews" :key="`${segment}-${index}`" class="segment-thumb">
          <img :src="segment" :alt="`Receipt segment ${index + 1}`" />
        </div>
      </div>

      <p v-if="longReceiptMode && !previewUrl && segmentPreviews.length > 0" class="muted segment-caption">
        {{ segmentPreviews.length }} segment{{ segmentPreviews.length > 1 ? 's' : '' }} captured.
      </p>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="message" class="success">{{ message }}</p>

      <div class="inline" style="margin-top: 1rem">
        <button
          v-if="!previewUrl"
          class="primary"
          :disabled="starting || submitting || processingCapture || !cameraSupported || !stream"
          @click="capturePhoto"
        >
          {{ captureButtonLabel }}
        </button>

        <button
          v-if="!previewUrl && longReceiptMode && segmentPreviews.length > 0"
          type="button"
          class="secondary"
          :disabled="submitting || processingCapture"
          @click="finalizeLongScan"
        >
          {{ processingCapture ? 'Finalizing...' : 'Finalize Scan' }}
        </button>

        <button
          v-if="!previewUrl && longReceiptMode && segmentPreviews.length > 0"
          type="button"
          class="ghost"
          :disabled="submitting || processingCapture"
          @click="resetSegments"
        >
          Reset Segments
        </button>

        <button
          v-if="!previewUrl"
          type="button"
          class="ghost"
          :disabled="starting || submitting || processingCapture || !cameraSupported"
          @click="switchCamera"
        >
          Switch Camera
        </button>

        <button
          v-if="previewUrl"
          class="primary"
          :disabled="submitting"
          @click="uploadCapture"
        >
          {{ submitting ? 'Uploading...' : 'Upload Receipt' }}
        </button>

        <button
          v-if="previewUrl"
          type="button"
          class="ghost"
          :disabled="submitting"
          @click="retake"
        >
          Retake
        </button>
      </div>

      <div style="margin-top: 1rem">
        <label for="fallback-file">Image fallback</label>
        <input
          id="fallback-file"
          type="file"
          accept="image/jpeg,image/png"
          capture="environment"
          :disabled="submitting || processingCapture"
          @change="onFallbackFile"
        />
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
  processCapturedFrame,
  stitchReceiptSegments,
  suggestReceiptCorners
} from '@/utils/receiptScan';

const receipts = useReceiptsStore();

const videoEl = ref<HTMLVideoElement | null>(null);
const previewImageEl = ref<HTMLImageElement | null>(null);
const canvasEl = ref<HTMLCanvasElement | null>(null);
const stream = ref<MediaStream | null>(null);
const capturedBlob = ref<Blob | null>(null);
const previewUrl = ref('');
const segmentPreviews = ref<string[]>([]);
const segments = ref<Blob[]>([]);
const starting = ref(false);
const submitting = ref(false);
const processingCapture = ref(false);
const error = ref('');
const message = ref('');
const facingMode = ref<'environment' | 'user'>('environment');
const smartScan = ref(true);
const longReceiptMode = ref(false);
const manualAdjust = ref(true);
const corners = ref<NormalizedCorner[]>(defaultReceiptCorners());
const activeHandleIndex = ref<number | null>(null);
const cameraSupported = Boolean(navigator.mediaDevices?.getUserMedia);

const captureButtonLabel = computed(() => {
  if (starting.value) {
    return 'Starting camera...';
  }
  if (processingCapture.value) {
    return 'Processing...';
  }
  return longReceiptMode.value ? 'Capture Segment' : 'Capture';
});

const polygonPoints = computed(() =>
  corners.value.map((point) => `${(point.x * 100).toFixed(2)},${(point.y * 100).toFixed(2)}`).join(' ')
);

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

function resetSegments(): void {
  segmentPreviews.value.forEach((url) => URL.revokeObjectURL(url));
  segmentPreviews.value = [];
  segments.value = [];
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

function handleLongModeChange(): void {
  if (!longReceiptMode.value) {
    resetSegments();
  }
}

function constrainCorners(points: NormalizedCorner[]): NormalizedCorner[] {
  const next = points.map((point) => ({
    x: clamp(point.x, 0, 1),
    y: clamp(point.y, 0, 1)
  }));

  const minGap = 0.03;

  next[0].x = Math.min(next[0].x, next[1].x - minGap, next[3].x - minGap);
  next[0].y = Math.min(next[0].y, next[3].y - minGap, next[1].y - minGap);

  next[1].x = Math.max(next[1].x, next[0].x + minGap, next[2].x - minGap);
  next[1].y = Math.min(next[1].y, next[2].y - minGap, next[0].y + 0.3);

  next[2].x = Math.max(next[2].x, next[3].x + minGap, next[1].x);
  next[2].y = Math.max(next[2].y, next[1].y + minGap, next[3].y);

  next[3].x = Math.min(next[3].x, next[2].x - minGap, next[0].x + 0.3);
  next[3].y = Math.max(next[3].y, next[0].y + minGap, next[2].y);

  return next.map((point) => ({
    x: clamp(point.x, 0, 1),
    y: clamp(point.y, 0, 1)
  }));
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
  corners.value = constrainCorners(next);
}

function startHandleDrag(index: number, event: PointerEvent): void {
  if (!manualAdjust.value) {
    return;
  }

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
  try {
    corners.value = await suggestReceiptCorners(capturedBlob.value);
    message.value = 'Edges auto-detected. You can drag corners to refine.';
  } catch {
    error.value = 'Could not auto-detect edges for this image.';
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

    const blob = await processCapturedFrame(canvasEl.value, { smartScan: smartScan.value });

    if (longReceiptMode.value) {
      segments.value.push(blob);
      segmentPreviews.value.push(URL.createObjectURL(blob));
      message.value = `Segment ${segments.value.length} captured. Continue down the receipt, then tap Finalize Scan.`;
      return;
    }

    await setCapturedPreview(blob);
    stopCamera();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to create image capture';
  } finally {
    processingCapture.value = false;
  }
}

async function finalizeLongScan(): Promise<void> {
  if (segments.value.length === 0) {
    error.value = 'Capture at least one segment before finalizing.';
    return;
  }

  processingCapture.value = true;
  error.value = '';

  try {
    const stitched = await stitchReceiptSegments(segments.value);
    await setCapturedPreview(stitched);
    stopCamera();
    message.value = `Long receipt scan ready. ${segments.value.length} segment${segments.value.length > 1 ? 's' : ''} stitched.`;
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to finalize long receipt scan';
  } finally {
    processingCapture.value = false;
  }
}

async function retake(): Promise<void> {
  capturedBlob.value = null;
  clearPreview();
  resetSegments();
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
  resetSegments();
}

async function uploadCapture(): Promise<void> {
  if (!capturedBlob.value) {
    error.value = 'Capture or select an image first.';
    return;
  }

  submitting.value = true;
  clearStatus();

  try {
    let uploadBlob = capturedBlob.value;
    if (manualAdjust.value) {
      uploadBlob = await correctReceiptPerspective(uploadBlob, corners.value, {
        enhance: smartScan.value
      });
    }

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
    resetSegments();
    corners.value = defaultReceiptCorners();
    await startCamera();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to upload capture';
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
  resetSegments();
});

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}
</script>
