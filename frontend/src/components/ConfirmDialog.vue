<template>
  <Teleport to="body">
    <div v-if="open" class="confirm-overlay" @click.self="$emit('cancel')">
      <div class="confirm-box" role="dialog" aria-modal="true">
        <div class="confirm-title">{{ title }}</div>
        <p class="confirm-msg">{{ message }}</p>
        <div class="confirm-actions">
          <button class="btn-ghost" @click="$emit('cancel')">{{ cancelText }}</button>
          <button class="btn-danger" @click="$emit('confirm')">{{ confirmText }}</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: 'Confirmar' },
  message: { type: String, default: '' },
  confirmText: { type: String, default: 'Eliminar' },
  cancelText: { type: String, default: 'Cancelar' }
});
defineEmits(['confirm', 'cancel']);
</script>

<style scoped>
.confirm-overlay {
  position: fixed; inset: 0; z-index: 100;
  background: rgba(0, 0, 0, .6);
  display: grid; place-items: center; padding: 16px;
}
.confirm-box {
  width: 100%; max-width: 420px;
  background: var(--bg-elevated); border: 1px solid var(--border);
  border-radius: 12px; padding: 22px;
}
.confirm-title { font-size: 18px; font-weight: 800; margin-bottom: 8px; }
.confirm-msg { color: var(--muted); margin: 0 0 20px; line-height: 1.5; }
.confirm-actions { display: flex; justify-content: flex-end; gap: 12px; }
.btn-danger {
  display: inline-flex; align-items: center; gap: 8px;
  background: var(--danger); color: #fff; border: none;
  border-radius: 999px; padding: 10px 22px; font-weight: 700; cursor: pointer;
}
.btn-danger:hover { filter: brightness(1.1); }
</style>
