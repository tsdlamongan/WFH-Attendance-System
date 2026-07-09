/** @type {import('tailwindcss').Config} */

// Design tokens from DESIGN.md (Kirim.chat design system).
// Neo-brutalist discipline: white canvas, slate-900 text + mandatory 2px borders,
// hard offset drop shadows (0px blur), Success Green primary CTAs, pill buttons.
// Two typefaces only: Outfit (headings/buttons/emphasis) + Plus Jakarta Sans (body/UI).
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        // Surfaces
        canvas: '#ffffff',
        surface: {
          soft: '#fafbfc',      // off-white background layering
          card: '#ffffff',      // cards are white — depth comes from border + shadow
          elevated: '#f1f5f9',  // deeper off-white (nested panels, hovers)
        },
        // Borders / dividers
        hairline: {
          DEFAULT: '#e2e8f0',   // slate-200 light dividers, table rows
          strong: '#1e293b',    // slate-900 — the signature 2px outline
        },
        // Text
        ink: '#1e293b',         // slate-900 — primary text, headings, borders
        body: {
          DEFAULT: '#334155',   // slate-700 — running body copy
          strong: '#1e293b',
        },
        muted: {
          DEFAULT: '#64748b',   // slate-500/400 — secondary / helper text
          soft: '#94a3b8',      // slate-400 — placeholders, disabled, very-secondary
        },
        // Action / brand
        primary: {
          DEFAULT: '#22c55e',   // Success Green — all primary CTAs
          hover: '#16a34a',
          active: '#15803d',
        },
        brand: '#047857',       // Brand Green — focus rings, active nav, inline links
        accent: {
          DEFAULT: '#db2777',   // Hot Pink — premium/accent highlights
          bright: '#ec4899',
        },
        link: '#047857',        // inline anchor links (brand green)
        // Third-party integration brand colors (fixed, do not alter)
        whatsapp: '#25d366',
        instagram: '#e4405f',
        facebook: '#0084ff',
        // Semantic / status
        warning: '#f59e0b',
        success: '#22c55e',
        error: '#e11d48',
      },
      fontFamily: {
        // Plus Jakarta Sans — default body / UI / labels
        sans: ['"Plus Jakarta Sans"', 'system-ui', '-apple-system', 'sans-serif'],
        body: ['"Plus Jakarta Sans"', 'system-ui', '-apple-system', 'sans-serif'],
        // Outfit — headings, buttons, emphasis
        display: ['Outfit', 'system-ui', '-apple-system', 'sans-serif'],
        // Aliases so legacy `font-serif` / `font-mono` usages still resolve sensibly.
        // The system has only two families; both aliases point at the UI face.
        serif: ['"Plus Jakarta Sans"', 'system-ui', '-apple-system', 'sans-serif'],
        mono: ['"Plus Jakarta Sans"', 'system-ui', '-apple-system', 'sans-serif'],
      },
      fontSize: {
        // Kirim.chat scale — sentence case, letter-spacing 0, weight baked in.
        'display-xl': ['3rem', { lineHeight: '1.2', fontWeight: '700', letterSpacing: '0' }],     // 48px H1
        'display-lg': ['2rem', { lineHeight: '1.2', fontWeight: '700', letterSpacing: '0' }],      // 32px H2
        'display-md': ['1.5rem', { lineHeight: '1.2', fontWeight: '700', letterSpacing: '0' }],    // 24px H3
        'display-sm': ['1.125rem', { lineHeight: '1.2', fontWeight: '700', letterSpacing: '0' }],  // 18px H4
        'title-md': ['1.125rem', { lineHeight: '1.35', fontWeight: '600', letterSpacing: '0' }],   // 18px
        'title-sm': ['1rem', { lineHeight: '1.4', fontWeight: '600', letterSpacing: '0' }],        // 16px
        'caption': ['0.75rem', { lineHeight: '1.4', fontWeight: '700', letterSpacing: '0.04em' }], // 12px label
        'nav-link': ['0.875rem', { lineHeight: '1.4', fontWeight: '500', letterSpacing: '0' }],    // 14px
        'button': ['1rem', { lineHeight: '1.5', fontWeight: '700', letterSpacing: '0' }],          // 16px
        'wordmark': ['1.375rem', { lineHeight: '1', fontWeight: '800', letterSpacing: '-0.01em' }],// wordmark
      },
      borderRadius: {
        'input': '8px',    // inputs, tight components
        'card': '16px',    // standard cards
        'card-lg': '32px', // large feature cards / containers
      },
      boxShadow: {
        // Neo-brutalist hard offset shadows — solid slate-900, 0px blur.
        'brutal-sm': '2px 2px 0px 0px #1e293b',
        'brutal': '4px 4px 0px 0px #1e293b',
        'brutal-lg': '8px 8px 0px 0px #1e293b',
        'brutal-xl': '12px 12px 0px 0px #1e293b',
        // Focus ring (brand green halo) as a spread shadow to match spec focus states.
        'focus-brand': '0px 0px 0px 3px rgba(4, 120, 87, 0.35)',
      },
      spacing: {
        'section': '96px',
      },
    },
  },
  plugins: [],
}
