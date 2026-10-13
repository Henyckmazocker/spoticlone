<template>
  <div class="card login-card">
    <div class="brand center" style="justify-content:center">
      <span class="brand-mark">♪</span>
      <span class="brand-name">spoticlone</span>
    </div>
    <p class="subtitle center muted">Tu música, en streaming desde tu servidor</p>

    <div v-if="error" class="error-box">{{ error }}</div>

    <!-- Nativo (Capacitor/Android): botón propio que usa el plugin GoogleAuth -->
    <button
      v-if="googleClientId && isNative"
      class="btn"
      style="margin: 18px 0; width: 100%;"
      @click="loginNative"
    >
      Continuar con Google
    </button>

    <!-- Web: botón renderizado por Google Identity Services -->
    <div v-else-if="googleClientId" id="google-btn" class="center" style="margin: 18px 0;"></div>

    <details class="dev-login">
      <summary class="muted">Acceso de desarrollo (token JWT)</summary>
      <p class="muted" style="font-size:12px; margin:10px 0;">
        Pega un JWT firmado con <code>JWT_SECRET</code> (campo <code>user_id</code>) para probar en local sin Google.
      </p>
      <textarea v-model="devToken" rows="3" placeholder="eyJhbGciOi..."></textarea>
      <button class="btn" style="margin-top:10px; width:100%;" :disabled="!devToken.trim()" @click="loginDev">
        Entrar con token
      </button>
    </details>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { Capacitor } from '@capacitor/core';
import { GoogleAuth } from '@codetrix-studio/capacitor-google-auth';
import { useAuthStore } from '@/store/auth';

const router = useRouter();
const auth = useAuthStore();
const error = ref('');
const devToken = ref('');
const googleClientId = process.env.VUE_APP_GOOGLE_CLIENT_ID || '';
const isNative = Capacitor.isNativePlatform();

async function loginNative() {
  error.value = '';
  try {
    const result = await GoogleAuth.signIn();
    const idToken = result?.authentication?.idToken;
    if (!idToken) throw new Error('Google no devolvió idToken');
    await auth.loginWithGoogle(idToken);
    router.push('/home');
  } catch (e) {
    error.value = e?.message || 'Error de inicio de sesión con Google';
  }
}

function loginDev() {
  try {
    auth.loginWithToken(devToken.value.trim());
    router.push('/home');
  } catch (e) {
    error.value = e.message;
  }
}

async function handleGoogleCredential(response) {
  try {
    await auth.loginWithGoogle(response.credential);
    router.push('/home');
  } catch (e) {
    error.value = e.message || 'Error de inicio de sesión';
  }
}

onMounted(() => {
  if (!googleClientId) return;
  if (isNative) {
    GoogleAuth.initialize({ clientId: googleClientId, scopes: ['profile', 'email'], grantOfflineAccess: false });
    return;
  }
  const script = document.createElement('script');
  script.src = 'https://accounts.google.com/gsi/client';
  script.async = true;
  script.onload = () => {
    /* global google */
    window.google?.accounts.id.initialize({ client_id: googleClientId, callback: handleGoogleCredential });
    window.google?.accounts.id.renderButton(document.getElementById('google-btn'), {
      theme: 'filled_black', size: 'large', width: 280
    });
  };
  document.head.appendChild(script);
});
</script>
