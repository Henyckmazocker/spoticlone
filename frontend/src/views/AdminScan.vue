<template>
  <div>
    <h1>Escanear biblioteca</h1>
    <p class="muted" style="max-width:640px">
      Recorre la carpeta de música del servidor, lee las etiquetas de cada archivo y actualiza la
      biblioteca. Es idempotente: re-escanear no duplica nada y solo procesa archivos nuevos o
      modificados.
    </p>

    <button class="btn" :disabled="scanning" @click="scan">
      <Icon name="scan" :size="16" /> {{ scanning ? 'Escaneando…' : 'Escanear ahora' }}
    </button>

    <div v-if="result" style="margin-top:20px" class="card" :style="{ cursor: 'default', maxWidth: '520px' }">
      <h2 style="margin-top:0">Último escaneo</h2>
      <ul class="muted" style="line-height:1.8">
        <li>Archivos vistos: <strong style="color:var(--text)">{{ result.files_seen }}</strong></li>
        <li>Pistas añadidas: <strong style="color:var(--text)">{{ result.tracks_added }}</strong></li>
        <li>Pistas actualizadas: <strong style="color:var(--text)">{{ result.tracks_updated }}</strong></li>
        <li>Errores: <strong style="color:var(--text)">{{ result.errors }}</strong></li>
        <li v-if="result.covers_enriched != null">Carátulas enriquecidas: <strong style="color:var(--text)">{{ result.covers_enriched }}</strong></li>
        <li v-if="result.status">Estado: <strong style="color:var(--text)">{{ result.status }}</strong></li>
        <li v-if="result.message" style="color:var(--danger)">{{ result.message }}</li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useLibraryStore } from '@/store/library';
import Icon from '@/components/Icon.vue';

const lib = useLibraryStore();
const scanning = ref(false);
const result = ref(null);

async function scan() {
  scanning.value = true;
  try {
    result.value = await lib.scanLibrary();
  } catch (e) {
    result.value = { files_seen: 0, tracks_added: 0, tracks_updated: 0, errors: 1, message: e.response?.status === 409 ? 'Ya hay un escaneo en curso' : e.message };
  } finally {
    scanning.value = false;
  }
}

onMounted(async () => {
  try {
    const s = await lib.getScanStatus();
    if (s) result.value = s;
  } catch (e) { /* */ }
});
</script>
