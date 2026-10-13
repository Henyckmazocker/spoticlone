<template>
  <svg
    class="icon"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    :fill="filled ? 'currentColor' : 'none'"
    :stroke="filled ? 'none' : 'currentColor'"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    v-html="path"
  />
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: '1.25em' },
  // Solid variant on demand (e.g. a favorite heart); icons can also be solid by definition.
  filled: { type: Boolean, default: false }
});

// Iconos estilo Lucide/Spotify. Los de "stroke" usan currentColor en el trazo; los marcados
// como `filled` (corazón relleno, play, pause, shuffle…) rellenan con currentColor.
// El reproductor usa glifos sólidos (play/pause/skip/shuffle/repeat) como Spotify.
const ICONS = {
  // --- Navegación ---
  home: { d: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h5v-6h4v6h5V9.5"/>' },
  search: { d: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/>' },
  discover: { d: '<circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2 5-5 2 2-5z" fill="currentColor" stroke="none"/>' },
  artist: { d: '<rect x="9" y="2" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0"/><path d="M12 18v3"/><path d="M8 21h8"/>' },
  playlist: { d: '<path d="M4 6h16"/><path d="M4 12h10"/><path d="M4 18h10"/><circle cx="18" cy="16" r="2.5" fill="currentColor" stroke="none"/><path d="M20.5 16V9l-2 .6" stroke="currentColor"/>' },
  favorite: { d: '<path d="M12 20.5 4.2 13a4.6 4.6 0 0 1 6.5-6.5l1.3 1.3 1.3-1.3a4.6 4.6 0 1 1 6.5 6.5z"/>' },
  scan: { d: '<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v5h-5"/>' },
  logout: { d: '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/>' },
  queue: { d: '<path d="M3 6h13"/><path d="M3 12h13"/><path d="M3 18h9"/><path d="M16 18l5-3-5-3z" fill="currentColor" stroke="none"/>' },
  'chevron-down': { d: '<path d="m6 9 6 6 6-6"/>' },

  // --- Controles del reproductor (sólidos) ---
  shuffle: { d: '<path d="M16 3h5v5"/><path d="M4 20 21 3"/><path d="M21 16v5h-5"/><path d="m15 15 6 6"/><path d="M4 4l5 5"/>' },
  prev: { d: '<path d="M6 5v14"/><path d="M20 5 9 12l11 7z"/>', filled: true },
  next: { d: '<path d="M18 5v14"/><path d="M4 5l11 7L4 19z"/>', filled: true },
  play: { d: '<path d="M7 4.5v15l13-7.5z"/>', filled: true },
  pause: { d: '<rect x="6" y="4.5" width="4" height="15" rx="1"/><rect x="14" y="4.5" width="4" height="15" rx="1"/>', filled: true },
  repeat: { d: '<path d="M17 2l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>' },
  'repeat-one': { d: '<path d="M17 2l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/><text x="12" y="15" font-size="8" font-weight="700" text-anchor="middle" fill="currentColor" stroke="none">1</text>' },
  volume: { d: '<path d="M11 5 6 9H3v6h3l5 4z" fill="currentColor" stroke="none"/><path d="M16 9a4 4 0 0 1 0 6"/><path d="M19 6a8 8 0 0 1 0 12"/>' },

  // --- Placeholders de carátula ---
  album: { d: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.4" fill="currentColor" stroke="none"/>' },
  track: { d: '<path d="M9 18V5l10-2v13"/><circle cx="6" cy="18" r="3" fill="currentColor" stroke="none"/><circle cx="16" cy="16" r="3" fill="currentColor" stroke="none"/>' },

  // --- Acciones en listas ---
  remove: { d: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>' },
  trash: { d: '<path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/>' },
  add: { d: '<path d="M12 5v14"/><path d="M5 12h14"/>' },
  download: { d: '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>' },
  drag: { d: '<circle cx="9" cy="6" r="1.4" fill="currentColor" stroke="none"/><circle cx="9" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="9" cy="18" r="1.4" fill="currentColor" stroke="none"/><circle cx="15" cy="6" r="1.4" fill="currentColor" stroke="none"/><circle cx="15" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="15" cy="18" r="1.4" fill="currentColor" stroke="none"/>' }
};

const def = computed(() => ICONS[props.name] || ICONS.play);
const path = computed(() => def.value.d);
const filled = computed(() => props.filled || !!def.value.filled);
</script>

<style scoped>
.icon { display: inline-block; vertical-align: middle; flex: none; }
</style>
