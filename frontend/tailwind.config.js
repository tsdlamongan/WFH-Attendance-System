/** @type {import('tailwindcss').Config} */

// Design tokens from DESIGN.md (Bugatti design system).
// Monochrome discipline: black canvas, white type, hairline dividers.
// The only non-monochrome tokens are link/warning/success (+ inferred error).
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        canvas: '#000000',
        surface: {
          soft: '#0d0d0d',
          card: '#141414',
          elevated: '#1f1f1f',
        },
        hairline: {
          DEFAULT: '#262626',
          strong: '#3a3a3a',
        },
        ink: '#ffffff',
        body: {
          DEFAULT: '#cccccc',
          strong: '#e6e6e6',
        },
        muted: {
          DEFAULT: '#999999',
          soft: '#666666',
        },
        link: '#c3d9f3',
        warning: '#d4a017',
        success: '#5fa657',
        // Error tone is not in DESIGN.md (Known Gaps) — inferred, kept desaturated
        // to respect the monochrome discipline.
        error: '#c0564b',
      },
      fontFamily: {
        // Bugatti Display -> Saira Condensed (uppercase, wide-tracked headlines)
        display: ['"Saira Condensed"', '-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'sans-serif'],
        // Bugatti Text Regular -> EB Garamond (serif running body copy)
        serif: ['"EB Garamond"', 'Garamond', '"Times New Roman"', 'serif'],
        // Bugatti Monospace -> JetBrains Mono (buttons, captions, nav, dates)
        mono: ['"JetBrains Mono"', 'ui-monospace', '"SF Mono"', '"Cascadia Mono"', 'monospace'],
      },
      fontSize: {
        'display-xl': ['4rem', { lineHeight: '1.1', letterSpacing: '4px' }],
        'display-lg': ['3rem', { lineHeight: '1.15', letterSpacing: '3px' }],
        'display-md': ['2rem', { lineHeight: '1.2', letterSpacing: '2px' }],
        'display-sm': ['1.5rem', { lineHeight: '1.3', letterSpacing: '1.5px' }],
        'title-md': ['1.25rem', { lineHeight: '1.3', letterSpacing: '1px' }],
        'title-sm': ['1rem', { lineHeight: '1.3', letterSpacing: '1.5px' }],
        'caption': ['0.6875rem', { lineHeight: '1.4', letterSpacing: '2px' }],
        'nav-link': ['0.75rem', { lineHeight: '1.4', letterSpacing: '2px' }],
        'button': ['0.875rem', { lineHeight: '1', letterSpacing: '2.5px' }],
        'wordmark': ['0.875rem', { lineHeight: '1', letterSpacing: '6px' }],
      },
      letterSpacing: {
        'wordmark': '6px',
        'display-xl': '4px',
        'display': '3px',
        'display-md': '2px',
        'display-sm': '1.5px',
        'title': '1px',
        'caption': '2px',
        'button': '2.5px',
      },
      spacing: {
        'section': '120px',
      },
    },
  },
  plugins: [],
}
