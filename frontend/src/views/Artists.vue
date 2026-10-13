<template>
  <div>
    <h1>Artistas</h1>
    <p v-if="loading" class="muted">Cargando…</p>
    <div class="grid">
      <div v-for="a in lib.artists" :key="a.id" class="card" @click="open(a.id)">
        <img v-if="a.image_url" class="card-cover round" :src="a.image_url" alt="" />
        <div v-else class="card-cover round cover-placeholder"><Icon name="artist" :size="48" /></div>
        <div class="card-title">{{ a.name }}</div>
        <div class="card-sub">{{ a.album_count }} álbum(es)</div>
      </div>
    </div>
    <p v-if="!loading && !lib.artists.length" class="muted">
      No hay artistas todavía. <router-link to="/scan" style="color:var(--accent)">Escanea tu biblioteca</router-link>.
    </p>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useLibraryStore } from '@/store/library';
import Icon from '@/components/Icon.vue';

const lib = useLibraryStore();
const router = useRouter();
const loading = ref(true);

function open(id) { router.push({ name: 'artist', params: { id } }); }

onMounted(async () => {
  try { await lib.loadArtists(); } finally { loading.value = false; }
});
</script>
