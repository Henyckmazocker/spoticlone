import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { MediaSession } from '@jofr/capacitor-media-session';
import { apiCall, resolveUrl } from '@/services/api';

/**
 * Player store: owns the single <audio> element (created once, lives outside the route tree so
 * playback never stops on navigation) and the playback queue. Components read reactive state
 * (current track, progress, flags) and call actions (play/pause/next/seek/...).
 */
export const usePlayerStore = defineStore('player', () => {
  // The raw audio element is a module-local singleton (not reactive on purpose).
  let audio = null;

  const queue = ref([]);          // array of track objects (with stream_url, cover_url, ...)
  const index = ref(-1);          // current position in queue
  const isPlaying = ref(false);
  const currentTime = ref(0);
  const duration = ref(0);
  const volume = ref(parseFloat(localStorage.getItem('spoticlone_vol') ?? '1'));
  const shuffle = ref(false);
  const repeat = ref('off');      // 'off' | 'one' | 'all'

  let loggedFor = null;           // track id we already logged a play for

  const current = computed(() => (index.value >= 0 ? queue.value[index.value] : null));

  function ensureAudio() {
    if (audio) return audio;
    audio = new Audio();
    audio.preload = 'auto';
    audio.volume = volume.value;

    audio.addEventListener('timeupdate', () => {
      currentTime.value = audio.currentTime;
      maybeLogPlay();
      updatePositionState();
    });
    audio.addEventListener('durationchange', () => {
      duration.value = audio.duration || 0;
      updatePositionState();
    });
    audio.addEventListener('play', () => {
      isPlaying.value = true;
      MediaSession.setPlaybackState({ playbackState: 'playing' }).catch(() => {});
    });
    audio.addEventListener('pause', () => {
      isPlaying.value = false;
      MediaSession.setPlaybackState({ playbackState: 'paused' }).catch(() => {});
    });
    audio.addEventListener('ended', () => onEnded());

    setupMediaSession();
    return audio;
  }

  // ---- Media Session (OS media controls / lock screen). @jofr/capacitor-media-session is a thin
  //      wrapper over the Web Media Session API on web/iOS and a native impl on Android, where it
  //      also runs a foreground service so WebView audio keeps playing in the background. ----
  function setupMediaSession() {
    try {
      MediaSession.setActionHandler({ action: 'play' }, () => play());
      MediaSession.setActionHandler({ action: 'pause' }, () => pause());
      MediaSession.setActionHandler({ action: 'previoustrack' }, () => prev());
      MediaSession.setActionHandler({ action: 'nexttrack' }, () => next());
      MediaSession.setActionHandler({ action: 'seekto' }, (d) => { if (d.seekTime != null) seek(d.seekTime); });
      MediaSession.setActionHandler({ action: 'seekbackward' }, (d) => seek(currentTime.value - (d.seekOffset || 10)));
      MediaSession.setActionHandler({ action: 'seekforward' }, (d) => seek(currentTime.value + (d.seekOffset || 10)));
    } catch (e) { /* Media Session no disponible en este entorno */ }
  }

  function updateMetadata() {
    const t = current.value;
    if (!t) return;
    const artwork = t.cover_url
      ? [{ src: resolveUrl(t.cover_url), sizes: '512x512', type: 'image/jpeg' }]
      : [];
    MediaSession.setMetadata({
      title: t.title || '',
      artist: t.artist || '',
      album: t.album || '',
      artwork
    }).catch(() => {});
  }

  function updatePositionState() {
    const dur = duration.value;
    if (!dur || !isFinite(dur)) return;
    MediaSession.setPositionState({
      duration: dur,
      position: Math.min(currentTime.value, dur),
      playbackRate: 1
    }).catch(() => {});
  }

  function maybeLogPlay() {
    const t = current.value;
    if (!t || loggedFor === t.id) return;
    // Count a play once the user has listened to ~half the track (cap at 30s).
    const threshold = Math.min(30, (duration.value || 9999) / 2);
    if (currentTime.value >= threshold) {
      loggedFor = t.id;
      apiCall('log_play', { trackId: t.id }).catch(() => {});
    }
  }

  async function loadCurrent(autoplay = true) {
    const t = current.value;
    if (!t) return;
    const a = ensureAudio();
    loggedFor = null;
    currentTime.value = 0;
    updateMetadata();
    let url = t.stream_url;
    // Refresh the signed URL if it's missing (e.g. very old queue item).
    if (!url) {
      try {
        const d = await apiCall('get_stream_url', { trackId: t.id });
        url = d.stream_url;
      } catch (e) { /* ignore */ }
    }
    a.src = resolveUrl(url);
    if (autoplay) {
      try { await a.play(); } catch (e) { /* autoplay may be blocked until a gesture */ }
    }
  }

  function onEnded() {
    if (repeat.value === 'one') {
      loadCurrent(true);
      return;
    }
    next();
  }

  // ---- public actions ----
  // Ghost tracks (album deleted from disk, kept for history) come with stream_url = null: they are
  // dropped here, so Favorites/PlaylistDetail "play all" skips them. startIndex is remapped onto the
  // filtered list (a ghost start falls through to the next playable track).
  function setQueueAndPlay(tracks, startIndex = 0) {
    const playable = tracks.filter((t) => t.stream_url);
    if (!playable.length) return;
    const start = Math.max(0, Math.min(startIndex, tracks.length - 1));
    const before = tracks.slice(0, start).filter((t) => t.stream_url).length;
    queue.value = playable;
    index.value = Math.min(before, playable.length - 1);
    loadCurrent(true);
  }

  function playTrack(track) {
    setQueueAndPlay([track], 0);
  }

  function enqueue(tracks) {
    // Same rule as setQueueAndPlay: ghost tracks (stream_url = null) never enter the queue.
    const arr = (Array.isArray(tracks) ? tracks : [tracks]).filter((t) => t.stream_url);
    if (!arr.length) return;
    queue.value.push(...arr);
    if (index.value === -1 && queue.value.length) {
      index.value = 0;
      loadCurrent(true);
    }
  }

  function toggle() {
    const a = ensureAudio();
    if (!current.value) return;
    if (a.paused) a.play().catch(() => {});
    else a.pause();
  }

  function play() { ensureAudio().play().catch(() => {}); }
  function pause() { ensureAudio().pause(); }

  function next() {
    if (!queue.value.length) return;
    if (shuffle.value && queue.value.length > 1) {
      let r = index.value;
      while (r === index.value) r = Math.floor(Math.random() * queue.value.length);
      index.value = r;
      return loadCurrent(true);
    }
    if (index.value < queue.value.length - 1) {
      index.value++;
      loadCurrent(true);
    } else if (repeat.value === 'all') {
      index.value = 0;
      loadCurrent(true);
    } else {
      isPlaying.value = false;
    }
  }

  function prev() {
    if (!queue.value.length) return;
    // Restart current track if we're more than 3s in, else go to previous.
    if (currentTime.value > 3 || index.value === 0) {
      ensureAudio().currentTime = 0;
      return;
    }
    index.value--;
    loadCurrent(true);
  }

  function seek(seconds) {
    const a = ensureAudio();
    a.currentTime = Math.max(0, Math.min(seconds, duration.value || seconds));
  }

  function setVolume(v) {
    volume.value = Math.max(0, Math.min(1, v));
    ensureAudio().volume = volume.value;
    localStorage.setItem('spoticlone_vol', String(volume.value));
  }

  function toggleShuffle() { shuffle.value = !shuffle.value; }
  function cycleRepeat() {
    repeat.value = repeat.value === 'off' ? 'all' : repeat.value === 'all' ? 'one' : 'off';
  }

  // ---- edición de la cola en vivo ----
  // Saltar directamente a una posición de la cola y reproducirla.
  function jumpTo(i) {
    if (i < 0 || i >= queue.value.length) return;
    index.value = i;
    loadCurrent(true);
  }

  // Parar del todo: cola vacía y <audio> sin fuente (también al cerrar/caducar la sesión).
  function stop() {
    queue.value = [];
    index.value = -1;
    const a = ensureAudio();
    a.pause();
    a.removeAttribute('src');
    a.load();
    currentTime.value = 0;
    duration.value = 0;
  }

  // Quitar una pista de la cola manteniendo coherente el índice/la reproducción.
  function removeAt(i) {
    if (i < 0 || i >= queue.value.length) return;
    const wasCurrent = i === index.value;
    queue.value.splice(i, 1);
    if (!queue.value.length) return stop();
    if (i < index.value) {
      index.value--;                       // se desplazó todo lo anterior
    } else if (wasCurrent) {
      // La actual desapareció: ocupa su hueco la siguiente (o la última si era el final).
      index.value = Math.min(index.value, queue.value.length - 1);
      loadCurrent(true);
    }
  }

  // Reordenar la cola (drag & drop) preservando qué pista está sonando.
  function move(from, to) {
    if (from === to || from < 0 || to < 0) return;
    if (from >= queue.value.length || to >= queue.value.length) return;
    const [item] = queue.value.splice(from, 1);
    queue.value.splice(to, 0, item);
    // Recalcular el índice de la pista actual tras el reordenamiento.
    if (from === index.value) {
      index.value = to;
    } else {
      if (from < index.value) index.value--;
      if (to <= index.value) index.value++;
    }
  }

  // Vaciar lo que viene "a continuación", dejando sonando la pista actual.
  function clearUpcoming() {
    if (index.value < 0) { queue.value = []; return; }
    queue.value.splice(index.value + 1);
  }

  // Reemplazar el tramo "a continuación" (tras el índice actual) por una lista reordenada.
  // Lo usa el panel de cola al hacer drag & drop; la pista actual y su índice no se tocan.
  function setUpcoming(tracks) {
    if (index.value < 0) {
      queue.value = tracks.slice();
      index.value = tracks.length ? 0 : -1;
      return;
    }
    queue.value = [...queue.value.slice(0, index.value + 1), ...tracks];
  }

  return {
    queue, index, isPlaying, currentTime, duration, volume, shuffle, repeat, current,
    setQueueAndPlay, playTrack, enqueue, toggle, play, pause, stop, next, prev, seek,
    setVolume, toggleShuffle, cycleRepeat, jumpTo, removeAt, move, clearUpcoming, setUpcoming
  };
});
