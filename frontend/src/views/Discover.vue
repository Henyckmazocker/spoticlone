<template>
  <div>
    <h1>Descubrir</h1>
    <p v-if="auth.isAdmin" class="muted">Busca en Spotify, descarga desde Soulseek y se añade a tu biblioteca.</p>
    <p v-else class="muted">Busca en Spotify y pide los álbumes que no estén en la biblioteca: el administrador los descargará.</p>

    <input
      type="search"
      v-model="q"
      placeholder="Artista o álbum…"
      @keyup.enter="searchSpotify"
    />
    <div v-if="searching" class="muted" style="margin-top:10px">Buscando en Spotify…</div>
    <p v-if="requestError" class="muted" style="margin-top:10px; color:#e57373">{{ requestError }}</p>

    <!-- Cola de peticiones (solo admin) -->
    <section v-if="auth.isAdmin && adminRequests.length">
      <h2>Peticiones ({{ adminRequests.length }})</h2>
      <div
        v-for="r in adminRequests"
        :key="r.id"
        class="card"
        style="cursor:default; margin-bottom:10px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;"
      >
        <img v-if="r.cover_url" :src="r.cover_url" alt="" style="width:56px; height:56px; border-radius:4px; object-fit:cover;" />
        <div v-else class="cover-placeholder" style="width:56px; height:56px; display:flex; align-items:center; justify-content:center;"><Icon name="album" :size="28" /></div>
        <div style="flex:1; min-width:200px;">
          <div class="card-title">{{ r.title }}</div>
          <div class="card-sub">{{ r.artist }}<span v-if="r.year"> · {{ r.year }}</span></div>
          <div class="card-sub">
            {{ r.users_count }} {{ r.users_count === 1 ? 'usuario' : 'usuarios' }}<span v-if="r.requested_by?.length"> ({{ r.requested_by.join(', ') }})</span>
            · pedida {{ ago(r.created_at) }}
          </div>
          <div v-if="r.status === 'downloading'" class="card-sub">
            Descargando desde {{ ago(r.updated_at || r.created_at) }}, aún sin coincidencia en la biblioteca
          </div>
        </div>
        <span v-if="r.status === 'downloading'" class="badge muted">Descargando</span>
        <button class="btn" :disabled="busyId === r.id" @click="searchForRequest(r)">Buscar en Soulseek</button>
        <button class="btn" :disabled="busyId === r.id" @click="markRequest(r, 'fulfilled')">Marcar en biblioteca</button>
        <button class="btn" :disabled="busyId === r.id" @click="rejectRequest(r)">Rechazar</button>
      </div>
    </section>

    <!-- Mis peticiones (solo no admin) -->
    <section v-if="!auth.isAdmin && requests.mineList.length">
      <h2>Mis peticiones</h2>
      <div
        v-for="r in requests.mineList"
        :key="r.id"
        class="card"
        style="cursor:default; margin-bottom:10px; display:flex; align-items:center; gap:12px;"
      >
        <img v-if="r.cover_url" :src="r.cover_url" alt="" style="width:56px; height:56px; border-radius:4px; object-fit:cover;" />
        <div v-else class="cover-placeholder" style="width:56px; height:56px; display:flex; align-items:center; justify-content:center;"><Icon name="album" :size="28" /></div>
        <div style="flex:1; min-width:0;">
          <div class="card-title">{{ r.title }}</div>
          <div class="card-sub">{{ r.artist }}<span v-if="r.year"> · {{ r.year }}</span></div>
          <div v-if="r.status === 'rejected' && r.reject_reason" class="card-sub">Motivo: {{ r.reject_reason }}</div>
        </div>
        <router-link v-if="r.status === 'fulfilled' && r.album_id" :to="`/album/${r.album_id}`" class="badge">En biblioteca</router-link>
        <span v-else-if="r.status === 'fulfilled'" class="badge">En biblioteca</span>
        <span v-else-if="r.status === 'pending'" class="badge muted">Pendiente</span>
        <span v-else-if="r.status === 'downloading'" class="badge muted">Descargando</span>
        <span v-else class="badge muted">Rechazada</span>
        <button v-if="r.status === 'pending'" class="btn" :disabled="busyId === r.id" @click="cancelRequest(r)">Cancelar</button>
      </div>
    </section>

    <!-- Etapa 1: resultados de Spotify -->
    <section v-if="albums.length">
      <h2>Álbumes</h2>
      <div class="grid">
        <div v-for="al in albums" :key="al.id" class="card" :style="auth.isAdmin ? '' : 'cursor:default'" @click="auth.isAdmin && findSources(al)">
          <img v-if="al.cover" class="card-cover" :src="al.cover" alt="" />
          <div v-else class="card-cover cover-placeholder"><Icon name="album" :size="48" /></div>
          <div class="card-title">{{ al.title }}</div>
          <div class="card-sub">{{ al.artist }}<span v-if="al.year"> · {{ al.year }}</span></div>
          <!-- Estado en la biblioteca: clicar una «En biblioteca» sigue abriendo fuentes (reemplazar). -->
          <span v-if="al.library?.status === 'present'" class="badge">En biblioteca</span>
          <span v-else-if="al.library?.status === 'missing'" class="badge muted">Borrado</span>
          <template v-if="!auth.isAdmin && al.library?.status !== 'present'">
            <span v-if="requests.openSpotifyIds.has(al.id)" class="badge muted">Pedido</span>
            <button v-else class="btn" :disabled="busyId === al.id" @click.stop="requestAlbum(al)">Pedir</button>
          </template>
        </div>
      </div>
    </section>

    <!-- Etapa 2: fuentes en Soulseek para el álbum elegido -->
    <section v-if="auth.isAdmin && selected">
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
import { useAuthStore } from '@/store/auth';
import { useRequestsStore } from '@/store/requests';
import Icon from '@/components/Icon.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';

const store = useSoulseekStore();
const auth = useAuthStore();
const requests = useRequestsStore();
const route = useRoute();

// Peticiones (no admin): id en curso y último error visible (409 ya en biblioteca, 429 límite…).
const busyId = ref(null);
const requestError = ref('');
// Cola del admin y la petición que originó la búsqueda de Soulseek en curso (null = búsqueda normal).
const adminRequests = ref([]);
const selectedRequestId = ref(null);

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

function errMessage(e) {
  return e?.response?.data?.message || e?.response?.message || e?.message || 'No se pudo completar la petición';
}

async function requestAlbum(al) {
  busyId.value = al.id;
  requestError.value = '';
  try {
    await requests.request(al.id);
  } catch (e) {
    requestError.value = errMessage(e);
  } finally {
    busyId.value = null;
  }
}

async function cancelRequest(r) {
  busyId.value = r.id;
  requestError.value = '';
  try {
    await requests.cancel(r.id);
  } catch (e) {
    requestError.value = errMessage(e);
  } finally {
    busyId.value = null;
  }
}

async function loadAdminRequests() {
  try {
    adminRequests.value = await requests.all('open');
  } catch (e) {
    requestError.value = errMessage(e);
  }
}

// Antigüedad legible de un timestamp de MySQL («2026-10-21 10:00:00»).
function ago(ts) {
  if (!ts) return '';
  // MySQL corre en UTC (sin time_zone en dev ni prod) y el TIMESTAMP llega sin zona: sin la «Z», el
  // navegador lo leería como hora local y en CEST todo saldría 2 h más viejo.
  const t = new Date(`${String(ts).replace(' ', 'T')}Z`).getTime();
  if (Number.isNaN(t)) return '';
  const days = Math.floor((Date.now() - t) / 86400000);
  if (days >= 1) return `hace ${days} ${days === 1 ? 'día' : 'días'}`;
  const hours = Math.floor((Date.now() - t) / 3600000);
  return hours >= 1 ? `hace ${hours} h` : 'hace un momento';
}

function searchForRequest(r) {
  findSources({ artist: r.artist, title: r.title, cover: r.cover_url, id: r.spotify_album_id }, r.id);
}

async function resolveRequest(r, status, reason) {
  busyId.value = r.id;
  requestError.value = '';
  try {
    await requests.reject(r.id, status, reason);
    if (selectedRequestId.value === r.id) selectedRequestId.value = null;
    await loadAdminRequests();
  } catch (e) {
    requestError.value = errMessage(e);
  } finally {
    busyId.value = null;
  }
}

function markRequest(r, status) {
  return resolveRequest(r, status);
}

function rejectRequest(r) {
  const reason = window.prompt(`Motivo del rechazo de «${r.title}» (opcional, máx. 255):`, '');
  if (reason === null) return; // cancelado
  return resolveRequest(r, 'rejected', reason.trim().slice(0, 255) || undefined);
}

async function searchSpotify() {
  if (!q.value.trim()) return;
  searching.value = true;
  searchSeq++; // una búsqueda de Soulseek en vuelo ya no pinta nada
  slskLoading.value = false;
  selected.value = null;
  selectedRequestId.value = null;
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

// Cada findSources numera su búsqueda: si llega la respuesta de una anterior (tarda hasta 25 s), se
// descarta, para que no pinte fuentes de otro álbum bajo el `selectedRequestId` actual.
let searchSeq = 0;

async function findSources(album, requestId = null) {
  if (dupAsk.value) answerDup(null); // cambiar de álbum descarta la pregunta pendiente sin decidir
  const seq = ++searchSeq;
  decision.value = null;
  selectedRequestId.value = requestId; // solo «Buscar en Soulseek» de la cola lo rellena
  selected.value = album;
  sources.value = [];
  slskLoading.value = true;
  slskProgress.value = 0;
  try {
    const results = await store.slskSearch(
      { artist: album.artist, album: album.title },
      ({ responses }) => { if (seq === searchSeq) slskProgress.value = responses; }
    );
    if (seq !== searchSeq) return;
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
    if (seq === searchSeq) sources.value = [];
  } finally {
    if (seq === searchSeq) slskLoading.value = false;
  }
}

// «Re-descargar» de un álbum fantasma llega con ?artist=&album=: se busca en Soulseek directamente
// (sin pasar por Spotify), y `selected` lleva esos nombres a downloadFolder.
onMounted(() => {
  if (!auth.isAdmin) requests.mine().catch(() => {});
  else loadAdminRequests();
  const { artist, album } = route.query;
  if (artist && album && auth.isAdmin) {
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
    const requestId = selectedRequestId.value;
    const send = () => store.download({
      username: g.username,
      files: fo.files.map((f) => ({ filename: f.filename, size: f.size })),
      artist: album?.artist,
      album: album?.title,
      onDuplicate: decision.value,
      requestId
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
      if (requestId) await loadAdminRequests(); // la petición pasa a «downloading»
    }
  } catch (e) {
    /* ignore */
  } finally {
    fo.busy = false;
  }
}
</script>
