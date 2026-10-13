<template>
  <div>
    <h1>Descargas</h1>

    <section v-if="store.downloads.length">
      <div v-for="d in store.downloads" :key="d.id" class="card" style="cursor:default; margin-bottom:10px;">
        <div class="card-title">{{ d.album || d.folder }}</div>
        <div class="card-sub">
          <span v-if="d.artist">{{ d.artist }} · </span>
          {{ d.done_files }}/{{ d.expected_files }} pistas · {{ Math.round(d.percent) }}%
          <span v-if="d.state === 'imported'" style="color:var(--accent)"> · ✓ añadido a la biblioteca</span>
          <span v-else-if="d.state === 'failed'" style="color:var(--danger)"> · ⚠ {{ d.error }}</span>
          <span v-else-if="d.state === 'importing'" style="color:var(--accent)"> · importando…</span>
          <span v-else-if="d.state === 'complete'" style="color:var(--accent)"> · completada</span>
          <span v-else-if="d.state === 'queued'"> · en cola</span>
        </div>
        <div class="seek" style="height:6px; margin-top:8px; background:var(--panel-2); border-radius:3px; overflow:hidden;">
          <div :style="{ width: d.percent + '%', height: '100%', background: 'var(--accent)' }"></div>
        </div>
        <div v-if="d.state === 'failed'" style="display:flex; gap:10px; margin-top:8px; flex-wrap:wrap;">
          <!-- Faltan pistas: se reintentan solo esas. Si el fallo fue al importar (ya están todas),
               lo único que queda es repetir la importación. -->
          <button v-if="d.done_files < d.expected_files" class="btn" :disabled="busy === d.id" @click="retryMissing(d)">
            ⟳ Reintentar las que faltan
          </button>
          <button v-if="d.done_files > 0" class="btn" :disabled="busy === d.id" @click="importAnyway(d)">
            ⤓ {{ importLabel(d) }}
          </button>
          <button class="btn-ghost" style="color:var(--danger)" :disabled="busy === d.id" @click="pending = d">Quitar</button>
        </div>

        <!-- Descarga en marcha: la salida cuando la cola remota no avanza. -->
        <div v-else-if="isCancellable(d)" style="display:flex; gap:10px; margin-top:8px;">
          <button class="btn-ghost" style="color:var(--danger)" :disabled="busy === d.id" @click="pending = d">
            ✕ Cancelar descarga
          </button>
        </div>

        <div v-if="notices[d.id]" class="card-sub" style="margin-top:6px; color:var(--danger)">{{ notices[d.id] }}</div>
      </div>
    </section>

    <p v-else class="muted">No hay descargas.</p>

    <ConfirmDialog
      :open="pending !== null"
      :title="pending?.state === 'failed' ? 'Descartar descarga' : 'Cancelar descarga'"
      :message="discardMessage(pending)"
      :confirm-text="pending?.state === 'failed' ? 'Descartar' : 'Cancelar descarga'"
      @confirm="discard(pending)"
      @cancel="pending = null"
    />
  </div>
</template>

<script setup>
import { onMounted, ref, reactive } from 'vue';
import { useSoulseekStore } from '@/store/soulseek';
import ConfirmDialog from '@/components/ConfirmDialog.vue';

// Estados en los que la descarga sigue viva y se puede abortar. 'importing' queda fuera a
// propósito: ahí los ficheros se están moviendo y el backend rechaza quitarla.
const CANCELLABLE = ['queued', 'downloading', 'complete'];

// Esta vista solo pinta: el backend reconcilia contra slskd e importa lo completado en cada
// sondeo, y el sondeo lo mantiene el store (sigue vivo aunque se navegue fuera de aquí).
const store = useSoulseekStore();

const busy = ref(null);            // id de la fila con una acción en curso
const notices = reactive({});      // id → mensaje de error a pie de tarjeta
const pending = ref(null);         // fila esperando confirmación de cancelar/descartar

async function run(d, fn) {
  busy.value = d.id;
  delete notices[d.id];
  try {
    await fn();
  } catch (e) {
    // Los reintentos tienen motivos que el usuario necesita leer ("slskd ya no recuerda…"),
    // así que aquí no se tragan.
    notices[d.id] = e?.message || 'No se pudo completar la acción.';
  } finally {
    busy.value = null;
    await store.refreshDownloads();
  }
}

function importLabel(d) {
  return d.done_files < d.expected_files
    ? `Importar igualmente (${d.done_files}/${d.expected_files})`
    : 'Reintentar importación';
}

const isCancellable = (d) => CANCELLABLE.includes(d.state);

function discardMessage(d) {
  if (!d) return '';
  const name = d.album || d.folder;
  const got = d.done_files > 0 ? ` Se borrarán también las ${d.done_files} pistas ya descargadas.` : '';
  return d.state === 'failed'
    ? `Se descartará «${name}» y desaparecerá de la lista.${got} Esta acción no se puede deshacer.`
    : `Se cancelará la descarga de «${name}» en Soulseek.${got} Esta acción no se puede deshacer.`;
}

const retryMissing = (d) => run(d, () => store.retryMissing(d.id));
const importAnyway = (d) => run(d, () => store.importDownload(d.id, true));

async function discard(d) {
  pending.value = null;
  await run(d, () => store.removeDownloads([d.id]));
}

onMounted(async () => {
  try {
    await store.refreshDownloads();
  } catch (e) { /* sin slskd configurado, la lista queda vacía */ }
  if (store.hasActive) store.startPolling();
});
</script>
