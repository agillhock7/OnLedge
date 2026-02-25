<template>
  <section class="page">
    <h1>Capture Receipt</h1>
    <p class="muted">Point camera, tap capture, and upload. OnLedge handles processing after upload.</p>

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

        <img v-if="previewUrl" :src="previewUrl" alt="Captured receipt" class="camera-feed" />

        <div v-if="!cameraSupported" class="camera-placeholder">
          Camera capture is not available in this browser. Use image upload fallback.
        </div>

        <canvas ref="canvasEl" style="display: none"></canvas>
      </div>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="message" class="success">{{ message }}</p>

      <div class="inline" style="margin-top: 1rem">
        <button
          v-if="!previewUrl"
          class="primary"
          :disabled="starting || submitting || !cameraSupported || !stream"
          @click="capturePhoto"
        >
          {{ starting ? 'Starting camera...' : 'Capture' }}
        </button>

        <button
          v-if="!previewUrl"
          type="button"
          class="ghost"
          :disabled="starting || submitting || !cameraSupported"
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
          :disabled="submitting"
          @change="onFallbackFile"
        />
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref } from 'vue';

import { useReceiptsStore } from '@/stores/receipts';

const receipts = useReceiptsStore();

const videoEl = ref<HTMLVideoElement | null>(null);
const canvasEl = ref<HTMLCanvasElement | null>(null);
const stream = ref<MediaStream | null>(null);
const capturedBlob = ref<Blob | null>(null);
const previewUrl = ref('');
const starting = ref(false);
const submitting = ref(false);
const error = ref('');
const message = ref('');
const facingMode = ref<'environment' | 'user'>('environment');
const cameraSupported = Boolean(navigator.mediaDevices?.getUserMedia);

function clearPreview(): void {
  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value);
  }
  previewUrl.value = '';
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

function capturePhoto(): void {
  if (!videoEl.value || !canvasEl.value) {
    return;
  }

  error.value = '';
  message.value = '';

  const width = videoEl.value.videoWidth || 1280;
  const height = videoEl.value.videoHeight || 720;
  canvasEl.value.width = width;
  canvasEl.value.height = height;

  const context = canvasEl.value.getContext('2d');
  if (!context) {
    error.value = 'Unable to capture from camera';
    return;
  }

  context.drawImage(videoEl.value, 0, 0, width, height);

  canvasEl.value.toBlob((blob) => {
    if (!blob) {
      error.value = 'Unable to create image capture';
      return;
    }

    capturedBlob.value = blob;
    clearPreview();
    previewUrl.value = URL.createObjectURL(blob);
    stopCamera();
  }, 'image/jpeg', 0.92);
}

async function retake(): Promise<void> {
  capturedBlob.value = null;
  clearPreview();
  await startCamera();
}

function onFallbackFile(event: Event): void {
  const target = event.target as HTMLInputElement;
  const file = target.files?.[0];
  if (!file) {
    return;
  }

  error.value = '';
  message.value = '';
  stopCamera();

  capturedBlob.value = file;
  clearPreview();
  previewUrl.value = URL.createObjectURL(file);
}

async function uploadCapture(): Promise<void> {
  if (!capturedBlob.value) {
    error.value = 'Capture or select an image first.';
    return;
  }

  submitting.value = true;
  error.value = '';
  message.value = '';

  try {
    const file = new File(
      [capturedBlob.value],
      `receipt-${new Date().toISOString().replace(/[:.]/g, '-')}.jpg`,
      { type: capturedBlob.value.type || 'image/jpeg' }
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
        // Processing is best-effort; upload should still succeed.
      });
    }

    message.value = navigator.onLine
      ? 'Receipt uploaded. Processing queued.'
      : 'Receipt saved offline. It will sync when you reconnect.';

    capturedBlob.value = null;
    clearPreview();
    await startCamera();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unable to upload capture';
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  await startCamera();
});

onUnmounted(() => {
  stopCamera();
  clearPreview();
});
</script>
