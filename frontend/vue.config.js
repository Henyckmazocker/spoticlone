const { defineConfig } = require('@vue/cli-service');

// In mobile (Capacitor) builds assets must load via relative paths.
const isMobile = process.env.VUE_APP_MODE === 'mobile';

// Same-origin proxy: forward the JSON API and the binary endpoints to the backend, so the
// browser's <audio>/<img> can hit /stream.php and /cover.php without CORS or mixed-content.
const backendProxy = { target: 'http://backend:80', changeOrigin: true };

module.exports = defineConfig({
  transpileDependencies: true,
  publicPath: isMobile ? './' : '/',
  devServer: {
    host: '0.0.0.0',
    port: 8080,
    allowedHosts: 'all',
    headers: { 'Cache-Control': 'no-store' },
    hot: false,
    liveReload: false,
    client: false,
    proxy: {
      '/api': { ...backendProxy, pathRewrite: { '^/api': '/index.php' } },
      '/stream.php': backendProxy,
      '/cover.php': backendProxy
    }
  }
});
