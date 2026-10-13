import { CapacitorConfig } from '@capacitor/cli';

const isProd = process.env.CAP_ENV === 'production';

const config: CapacitorConfig = {
  appId: 'com.spoticlone.app',
  appName: 'spoticlone',
  webDir: 'dist',
  server: {
    // Dev: Android emulator reaches the host backend at 10.0.2.2.
    // Prod: point to the real HTTPS API/streaming origin.
    url: isProd ? 'https://spoticlone.dcahomelab.com' : undefined,
    androidScheme: isProd ? 'https' : 'http'
  }
};

export default config;
