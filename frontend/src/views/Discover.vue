<template>
  <div>
    <h1>Descubrir</h1>
    <p class="muted">Busca en Spotify, descarga desde Soulseek y se añade a tu biblioteca.</p>

    <input
      type="search"
      v-model="q"
      placeholder="Artista o álbum…"
      @keyup.enter="searchSpotify"
    />
    <div v-if="searching" class="muted" style="margin-top:10px">Buscando en Spotify…</div>

    <!-- Etapa 1: resultados de Spotify -->
    <section v-if="albums.length">
      <h2>Álbumes</h2>
      <div class="grid">
        <div v-for="al in albums" :key="al.id" class="card" @click="findSources(al)">
          <img v-if="al.cover" class="card-cover" :src="al.cover" alt="" />
          <div v-else class="card-cover cover-placeholder"><Icon name="album" :size="48" /></div>
          <div class="card-title">{{ al.title }}</div>
          <div class="card-sub">{{ al.artist }}<span v-if="al.year"> · {{ al.year }}</span></div>
          <!-- Estado en la biblioteca: clicar una «En biblioteca» sigue abriendo fuentes (reemplazar). -->
          <span v-if="al.library?.status === 'present'" class="badge">En biblioteca</span>
          <span v-else-if="al.library?.status === 'missing'" class="badge muted">Borrado</span>
        </div>
      </div>
    </section>

    <!-- Etapa 2: fuentes en Soulseek para el álbum elegido -->
    <section v-if="selected">
      <h2>Soulseek — {{ selected.artist }} · {{ selected.title }}</h2>
      <div v-if="slskLoading" class="muted">
        Buscando fuentes en Soulseek…
        <span v-if="slskProgress"> {{ slskProgress }} usuarios respondiendo</span>
      </div>
      <p v-else-if="!sources.length" class="muted">Sin fuentes. Prueba con otro álbum o término.</p>

      <p v-if="queuedAny" class="muted" style="margin-bottom:10px">
        <router-link to="/descargas" style="color:var(--accent)">Ver progreso en Descargas →</router-link>
      </p>

      <div v-for="g in sources" :key="g.username" class="card" style="cursor:default; margin-bottom:10px;">
        <div class="card-title">{{ g.username }}</div>
        <div class="card-sub" style="margin-bottom:8px;">
          {{ g.files.length }} pistas ·
          {{ g.formats }} ·
          {{ g.hasSlot ? '🟢 slot libre' : '🟠 en cola (' + g.queue + ')' }}
        </div>
        <div
          v-for="fo in g.folders"
          :key="fo.path"
          style="display:flex; justify-content:space-between; align-items:center; gap:12px; padding:6px 0; border-top:1px solid var(--border);"
        >
          <div class="card-sub" style="min-width:0; word-break:break-word;">
            📁 {{ fo.path }} · {{ fo.count }} pistas · {{ fo.formats }}
          </div>
          <button class="btn" :disabled="fo.queued || fo.skipped || fo.busy" @click="downloadFolder(g, fo)">
            {{ fo.queued ? 'Encolado ✓' : fo.skipped ? 'Omitido' : '⬇ Descargar' }}
          </button>
        </div>
      </div>
    </section>

    <!-- Álbum ya en la biblioteca: Reemplazar = confirmar, Omitir = cancelar (clic fuera también). -->
    <ConfirmDialog
      :open="!!dupAsk"
      :title="`Ya tienes «${dupAsk?.title ?? ''}»`"
      :message="`«${dupAsk?.title ?? ''}» de ${dupAsk?.artist ?? ''} ya está en tu biblioteca. Reemplazar lo sustituye por esta descarga al importarla, conservando escuchas y favoritos; Omitir no descarga nada.`"
      confirm-text="Reemplazar"
      cancel-text="Omitir"
      @confirm="answerDup('replace')"
      @cancel="answerDup('skip')"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useSoulseekStore } from '@/store/soulseek';
import Icon from '@/components/Icon.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';

const store = useSoulseekStore();
const route = useRoute();

const q = ref('');
const searching = ref(false);
const albums = ref([]);
const selected = ref(null);
const slskLoading = ref(false);
const slskProgress = ref(0);
const sources = ref([]);
const queuedAny = ref(false);
// Qué hacer si el álbum elegido ya está en la biblioteca: null (preguntar) | 'replace' | 'skip'.
// Una sola pregunta por álbum: CD1 y CD2 comparten la respuesta; findSources la resetea.
const decision = ref(null);
// Diálogo abierto: { title, artist, resolve }. Mientras está abierto, otra carpeta espera la misma respuesta.
const dupAsk = ref(null);
let dupPending = null;

async function searchSpotify() {
  if (!q.value.trim()) return;
  searching.value = true;
  selected.value = null;
  sources.value = [];
  try {
    const d = await store.spotifySearch(q.value.trim());
    albums.value = d.albums || [];
  } catch (e) {
    albums.value = [];
  } finally {
    searching.value = false;
  }
}

async function findSources(album) {
  if (dupAsk.value) answerDup(null); // cambiar de álbum descarta la pregunta pendiente sin decidir
  decision.value = null;
  selected.value = album;
  sources.value = [];
  slskLoading.value = true;
  slskProgress.value = 0;
  try {
    const results = await store.slskSearch(
      { artist: album.artist, album: album.title },
      ({ responses }) => { slskProgress.value = responses; }
    );
    // Group flat file list by user.
    const byUser = {};
    for (const r of results) {
      (byUser[r.username] ??= []).push(r);
    }
    sources.value = Object.entries(byUser).map(([username, files]) => ({
      username,
      files,
      folders: groupByFolder(files),
      hasSlot: files.some((f) => f.has_slot),
      queue: files[0]?.queue ?? 0,
      formats: [...new Set(files.map((f) => f.extension.toUpperCase()))].join('/')
    })).sort((a, b) => {
      // FLAC primero, luego slot libre, luego más pistas.
      const fa = a.files.some((f) => f.extension === 'flac') ? 1 : 0;
      const fb = b.files.some((f) => f.extension === 'flac') ? 1 : 0;
      return (fb - fa) || (Number(b.hasSlot) - Number(a.hasSlot)) || (b.files.length - a.files.length);
    });
  } catch (e) {
    sources.value = [];
  } finally {
    slskLoading.value = false;
  }
}

// «Re-descargar» de un álbum fantasma llega con ?artist=&album=: se busca en Soulseek directamente
// (sin pasar por Spotify), y `selected` lleva esos nombres a downloadFolder.
onMounted(() => {
  const { artist, album } = route.query;
  if (artist && album) {
    q.value = `${artist} ${album}`;
    findSources({ artist: String(artist), title: String(album) });
  }
});

// Carpeta contenedora de un filename de Soulseek (separador '\' de Windows o '/').
function fileDir(fn) {
  const norm = String(fn).replace(/\\/g, '/');
  const i = norm.lastIndexOf('/');
  return i >= 0 ? norm.slice(0, i) : '';
}

// Agrupa los archivos de un origen por su carpeta, para ver qué álbumes contiene realmente.
function groupByFolder(files) {
  const fmap = {};
  for (const f of files) (fmap[fileDir(f.filename)] ??= []).push(f);
  return Object.entries(fmap).map(([path, ff]) => ({
    path: path || '(raíz)',
    files: ff,
    count: ff.length,
    formats: [...new Set(ff.map((f) => f.extension.toUpperCase()))].join('/'),
    queued: false,
    skipped: false,
    busy: false
  })).sort((a, b) => {
    // FLAC primero, luego más pistas.
    const fa = a.files.some((f) => f.extension === 'flac') ? 1 : 0;
    const fb = b.files.some((f) => f.extension === 'flac') ? 1 : 0;
    return (fb - fa) || (b.count - a.count);
  });
}

// Pregunta Reemplazar/Omitir; resuelve 'replace' | 'skip' | null (pregunta descartada).
function askDuplicate(dup) {
  if (!dupPending) {
    dupPending = new Promise((resolve) => { dupAsk.value = { ...dup, resolve }; });
  }
  return dupPending;
}

function answerDup(choice) {
  const ask = dupAsk.value;
  dupAsk.value = null;
  dupPending = null;
  ask?.resolve(choice);
}

async function downloadFolder(g, fo) {
  const album = selected.value;
  fo.busy = true;
  try {
    const send = () => store.download({
      username: g.username,
      files: fo.files.map((f) => ({ filename: f.filename, size: f.size })),
      artist: album?.artist,
      album: album?.title,
      onDuplicate: decision.value
    });
    let r = await send();
    if (r?.duplicate) {
      const choice = await askDuplicate(r.duplicate);
      if (!choice || selected.value !== album) return; // se cambió de álbum: no se hace nada
      decision.value = choice;
      if (choice === 'skip') { fo.skipped = true; return; }
      r = await send();
    }
    if (r?.skipped) {
      fo.skipped = true;
    } else {
      fo.queued = true;
      queuedAny.value = true;
    }
  } catch (e) {
    /* ignore */
  } finally {
    fo.busy = false;
  }
}
</script>
