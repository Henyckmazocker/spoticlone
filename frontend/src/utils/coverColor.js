/**
 * Extrae un color representativo de una portada para usarlo como fondo ambiental (estilo Spotify).
 * Carga la imagen en un <canvas> pequeño y promedia sus píxeles, saltando los casi-negros para no
 * acabar siempre con un gris apagado. Best-effort: si el canvas se "ensucia" por CORS (la portada
 * viene de otro origen en prod/Capacitor) o falla la carga, rechaza y el llamante usa el fallback.
 */
const cache = new Map(); // url -> { r, g, b }

export function getCoverColor(url) {
  if (!url) return Promise.reject(new Error('no url'));
  if (cache.has(url)) return Promise.resolve(cache.get(url));

  return new Promise((resolve, reject) => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
      try {
        const size = 16;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, size, size);
        const { data } = ctx.getImageData(0, 0, size, size); // lanza si el canvas está tainted

        let r = 0, g = 0, b = 0, count = 0;
        for (let i = 0; i < data.length; i += 4) {
          const pr = data[i], pg = data[i + 1], pb = data[i + 2], pa = data[i + 3];
          if (pa < 200) continue;                       // ignora píxeles transparentes
          if (pr + pg + pb < 40) continue;              // ignora casi-negros (bordes/letterbox)
          r += pr; g += pg; b += pb; count++;
        }
        if (!count) { reject(new Error('sin color')); return; }
        const color = {
          r: Math.round(r / count),
          g: Math.round(g / count),
          b: Math.round(b / count)
        };
        cache.set(url, color);
        resolve(color);
      } catch (e) {
        reject(e);
      }
    };
    img.onerror = reject;
    img.src = url;
  });
}
