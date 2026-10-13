<template>
  <div>
    <h1>Inicio</h1>

    <section v-if="recTracks.length">
      <h2>{{ mode === 'new' ? 'Mezcla del día' : 'Pistas para ti' }}</h2>
      <TrackList :tracks="recTracks" />
    </section>

    <section v-if="recAlbums.length">
      <h2>{{ mode === 'new' ? 'Novedades en tu biblioteca' : 'Álbumes para ti' }}</h2>
      <div class="grid">
        <AlbumCard v-for="al in recAlbums" :key="al.id" :album="al" :sub="al.artist || ''" />
      </div>
    </section>

    <section v-if="recent.length">
      <h2>Reproducido recientemente</h2>
      <TrackList :tracks="recent" />
    </section>

    <section v-if="most.length">
      <h2>Lo que más escuchas</h2>
      <TrackList :tracks="most" />
    </section>

    <p v-if="!recent.length && !most.length && !recTracks.length && !recAlbums.length" class="muted">
      Aún no has reproducido nada. Ve a <router-link to="/artists" style="color:var(--accent)">Artistas</router-link>
      o <router-link to="/scan" style="color:var(--accent)">escanea tu biblioteca</router-link> para empezar.
    </p>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { apiCall } from '@/services/api';
import { usePlayerStore } from '@/store/player';
import TrackList from '@/components/TrackList.vue';
import AlbumCard from '@/components/AlbumCard.vue';

const player = usePlayerStore();
const recent = ref([]);
const most = ref([]);
const mode = ref('personal');
const recTracksRaw = ref([]);
const recAlbums = ref([]);

// No recomendar lo que está sonando ahora (el servidor no conoce la cola).
const recTracks = computed(() => recTracksRaw.value.filter((t) => t.id !== player.current?.id));

onMounted(async () => {
  try {
    const rec = await apiCall('get_recommendations');
    mode.value = rec.mode;
    recTracksRaw.value = rec.tracks || [];
    recAlbums.value = rec.albums || [];
  } catch (e) { /* */ }
  try { recent.value = (await apiCall('get_recently_played', { limit: 12 })).tracks; } catch (e) { /* */ }
  try { most.value = (await apiCall('get_most_played', { limit: 12 })).tracks; } catch (e) { /* */ }
});
</script>
