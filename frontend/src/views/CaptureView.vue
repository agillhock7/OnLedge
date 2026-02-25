<template>
  <section class="page capture-page">
    <h1>Scan Receipt</h1>
    <p class="muted">Capture or choose a receipt photo, adjust edges, then upload.</p>

    <ol class="capture-steps" aria-label="Receipt capture steps">
      <li class="capture-step" :class="{ active: !previewUrl }">1. Capture</li>
      <li class="capture-step" :class="{ active: !!previewUrl }">2. Adjust</li>
      <li class="capture-step" :class="{ active: !!previewUrl }">3. Upload</li>
    </ol>

    <div class="card camera-capture" style="margin-top: 1rem">
      <div v-if="!previewUrl" class="capture-launch">
        <button class="primary capture-primary" :disabled="submitting || processingCapture" @click="openNativeCapture">
          Capture Receipt
        </button>
        <p class="muted capture-launch-copy">This opens your device's native camera/photo picker.</p>
      </div>

      <div v-else class="camera-stage is-preview">
        <div class="preview-editor-wrap">
          <div class="preview-editor">
            <img ref="previewImageEl" :src="previewUrl" alt="Receipt preview" class="camera-feed camera-feed-preview" />

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
        </div>
      </div>

      <p class="muted capture-hint">
        {{ previewUrl
          ? 'Drag corners to match the receipt edges, then upload selected area.'
          : 'Tap Capture Receipt to continue.' }}
      </p>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="message" class="success">{{ message }}</p>

      <div class="capture-actions" v-if="previewUrl">
        <button type="button" class="ghost" :disabled="submitting || processingCapture" @click="clearSelection">Choose Another</button>
        <button type="button" class="ghost" :disabled="submitting || processingCapture" @click="applyAutoEdges">Auto Fit</button>
        <button class="primary" :disabled="submitting || processingCapture" @click="uploadCapture">
          {{ submitting ? 'Uploading...' : 'Upload Selected Area' }}
        </button>
      </div>

      <input
        ref="fileInputEl"
        class="capture-file-input"
        type="file"
        accept="image/*"
        :disabled="submitting || processingCapture"
        @change="onFileSelected"
      />
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';

import { useReceiptsStore } from '@/stores/receipts';
import {
  correctReceiptPerspective,
  defaultReceiptCorners,
  type NormalizedCorner,
  suggestReceiptCorners
} from '@/utils/receiptScan';

const receipts = useReceiptsStore();

const fileInputEl = ref<HTMLInputElement | null>(null);
const previewImageEl = ref<HTMLImageElement | null>(null);
const capturedBlob = ref<Blob | null>(null);
const previewUrl = ref('');
const submitting = ref(false);
const processingCapture = ref(false);
const error = ref('');
const message = ref('');
const corners = ref<NormalizedCorner[]>(defaultReceiptCorners());
const activeHandleIndex = ref<number | null>(null);

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

function openNativeCapture(): void {
  clearStatus();
  fileInputEl.value?.click();
}

function clearSelection(): void {
  capturedBlob.value = null;
  clearPreview();
  corners.value = defaultReceiptCorners();
  clearStatus();
}

async function onFileSelected(event: Event): Promise<void> {
  const target = event.target as HTMLInputElement;
  const file = target.files?.[0];
  if (!file) {
    return;
  }

  clearStatus();
  await setCapturedPreview(file);
  target.value = '';
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

async function uploadCapture(): Promise<void> {
  if (!capturedBlob.value) {
    error.value = 'Capture or choose an image first.';
    return;
  }

  submitting.value = true;
  clearStatus();

  try {
    const uploadBlob = await correctReceiptPerspective(capturedBlob.value, corners.value, { enhance: false });

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

    let processingNote = '';
    if (navigator.onLine && !created.offline) {
      try {
        const processed = await receipts.processReceipt(created.id);
        const aiStage = Array.isArray(processed.explanation)
          ? processed.explanation.find((step) => step?.stage === 'ai_extraction')
          : undefined;

        if (aiStage && aiStage.status !== 'success') {
          const reason = (aiStage.reason ?? '').trim();
          processingNote = reason !== ''
            ? `Upload complete, but extraction needs retry (${reason}).`
            : 'Upload complete, but extraction needs retry.';
        } else if (aiStage && Array.isArray(aiStage.fields_extracted) && aiStage.fields_extracted.length === 0) {
          processingNote = 'Upload complete, but no fields were extracted. Try Auto Fit or adjust corners tighter.';
        }
      } catch (processingError) {
        const reason = processingError instanceof Error ? processingError.message : 'Processing request failed';
        processingNote = `Upload complete, but extraction failed (${reason}).`;
      }
    }

    if (navigator.onLine) {
      message.value = processingNote !== '' ? processingNote : 'Receipt uploaded and extracted successfully.';
    } else {
      message.value = 'Receipt saved offline. It will sync when you reconnect.';
    }

    capturedBlob.value = null;
    clearPreview();
    corners.value = defaultReceiptCorners();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to upload receipt';
  } finally {
    submitting.value = false;
  }
}

onMounted(() => {
  window.addEventListener('pointermove', onGlobalPointerMove);
  window.addEventListener('pointerup', onGlobalPointerUp);
  window.addEventListener('pointercancel', onGlobalPointerUp);
});

onUnmounted(() => {
  window.removeEventListener('pointermove', onGlobalPointerMove);
  window.removeEventListener('pointerup', onGlobalPointerUp);
  window.removeEventListener('pointercancel', onGlobalPointerUp);
  clearPreview();
});
</script>
