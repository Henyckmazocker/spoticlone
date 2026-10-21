<template>
  <div v-if="route.meta.public" class="auth-shell">
    <router-view />
  </div>

  <div v-else class="app-shell">
    <!-- Cabecera solo-móvil: marca + acciones secundarias que no caben en la barra inferior -->
    <header class="mobile-topbar">
      <div class="brand">
        <span class="brand-mark">♪</span>
        <span class="brand-name">spoticlone</span>
      </div>
      <div style="flex:1"></div>
      <button class="icon-btn" :class="{ active: ui.queueOpen }" title="Cola" @click="ui.toggleQueue()"><Icon name="queue" /></button>
      <router-link to="/favorites" class="icon-btn" title="Favoritos"><Icon name="favorite" /></router-link>
      <router-link to="/scan" class="icon-btn" title="Escanear"><Icon name="scan" /></router-link>
      <a v-if="!isNative" class="icon-btn" href="/app/spoticlone.apk" download title="Descargar la app para Android"><Icon name="download" /></a>
      <button class="icon-btn" title="Cerrar sesión" @click="logout"><Icon name="logout" /></button>
    </header>

    <aside class="sidebar">
      <div class="brand">
        <span class="brand-mark">♪</span>
        <span class="brand-name">spoticlone</span>
      </div>
      <nav>
        <router-link to="/home" class="nav-item"><Icon name="home" /> Inicio</router-link>
        <router-link to="/search" class="nav-item"><Icon name="search" /> Buscar</router-link>
        <router-link to="/discover" class="nav-item"><Icon name="discover" /> Descubrir</router-link>
        <router-link to="/descargas" class="nav-item"><Icon name="download" /> Descargas</router-link>
        <router-link to="/artists" class="nav-item"><Icon name="artist" /> Artistas</router-link>
        <div class="nav-sep"></div>
        <router-link to="/playlists" class="nav-item"><Icon name="playlist" /> Playlists</router-link>
        <router-link to="/favorites" class="nav-item"><Icon name="favorite" /> Favoritos</router-link>
        <div class="nav-sep"></div>
        <router-link to="/scan" class="nav-item"><Icon name="scan" /> Escanear</router-link>
      </nav>
      <div style="flex:1"></div>
      <a v-if="!isNative" class="btn-ghost" style="margin-bottom:8px;" href="/app/spoticlone.apk" download><Icon name="download" :size="16" /> App Android</a>
      <button class="btn-ghost" @click="logout"><Icon name="logout" :size="16" /> Cerrar sesión</button>
    </aside>

    <main class="content">
      <router-view />
    </main>

    <div class="player-bar-wrap">
      <PlayerBar />
    </div>

    <!-- Barra de navegación inferior (solo-móvil, tipo Spotify) -->
    <nav class="mobile-nav">
      <router-link to="/home" class="mobile-nav-item"><Icon name="home" :size="22" />Inicio</router-link>
      <router-link to="/search" class="mobile-nav-item"><Icon name="search" :size="22" />Buscar</router-link>
      <router-link to="/discover" class="mobile-nav-item"><Icon name="discover" :size="22" />Descubrir</router-link>
      <router-link to="/artists" class="mobile-nav-item"><Icon name="artist" :size="22" />Artistas</router-link>
      <router-link to="/playlists" class="mobile-nav-item"><Icon name="playlist" :size="22" />Playlists</router-link>
    </nav>

    <QueuePanel />
    <NowPlaying />
  </div>
</template>

<script setup>
import { watch, onUnmounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Capacitor } from '@capacitor/core';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { useSoulseekStore } from '@/store/soulseek';
import PlayerBar from '@/components/PlayerBar.vue';
import QueuePanel from '@/components/QueuePanel.vue';
import NowPlaying from '@/components/NowPlaying.vue';
import Icon from '@/components/Icon.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const ui = useUiStore();
const soulseek = useSoulseekStore();
// Dentro de la APK no se ofrece descargarla
const isNative = Capacitor.isNativePlatform();

async function logout() {
  await auth.logout();   // el watcher de abajo para el sondeo al caer isAuthenticated
  router.push('/login');
}

// Retomar el seguimiento de descargas en cuanto haya sesión: cada sondeo es lo que dispara la
// importación en el backend, así que vive aquí y no en la vista Descargas — una descarga iniciada
// termina de importarse aunque el usuario esté en cualquier otra pantalla. Va por watcher y no por
// onMounted porque App.vue se monta una sola vez, y al arrancar en el login aún no hay sesión.
watch(
  () => auth.isAuthenticated,
  async (authed) => {
    if (!authed) return soulseek.stopPolling();
    try {
      await soulseek.refreshDownloads();
      if (soulseek.hasActive) soulseek.startPolling();
    } catch (e) { /* sin slskd configurado no hay nada que seguir */ }
  },
  { immediate: true }
);
onUnmounted(() => soulseek.stopPolling());
</script>
