// Color helpers: parse hex, relative luminance, auto-contrast foreground.

export function parseHex(hex) {
  let h = (hex || '').replace('#', '').trim();
  if (h.length === 3) h = h.split('').map((c) => c + c).join('');
  if (!/^[0-9a-fA-F]{6}$/.test(h)) return null;
  return {
    r: parseInt(h.slice(0, 2), 16),
    g: parseInt(h.slice(2, 4), 16),
    b: parseInt(h.slice(4, 6), 16),
  };
}

// WCAG relative luminance, 0 (black) to 1 (white).
export function luminance(hex) {
  const c = parseHex(hex);
  if (!c) return 0.5;
  const lin = (v) => {
    v /= 255;
    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  };
  return 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b);
}

// Black or white text for a given background color.
export function contrastText(hex) {
  return luminance(hex) > 0.42 ? '#1a1a1a' : '#ffffff';
}

// rgba() string from hex at a given alpha, for tinted chip backgrounds.
export function withAlpha(hex, alpha) {
  const c = parseHex(hex);
  if (!c) return 'rgba(128,128,128,' + alpha + ')';
  return 'rgba(' + c.r + ',' + c.g + ',' + c.b + ',' + alpha + ')';
}

export const DEFAULT_COLOR = '#5b7fd4';

export const PALETTE = [
  '#5b7fd4', '#c95d5d', '#4f9d69', '#c98c3d', '#8e6cc0',
  '#3d9dc9', '#c9569b', '#6b8e23', '#b0713a', '#5d6dc9',
];
