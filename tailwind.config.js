/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './pages/**/*.{js,ts,jsx,tsx,mdx}',
    './components/**/*.{js,ts,jsx,tsx,mdx}',
    './app/**/*.{js,ts,jsx,tsx,mdx}',
  ],
  theme: {
    extend: {
      colors: {
        bg:          '#07060a',
        surface:     '#0d0b0f',
        'surface-2': '#141118',
        border:      '#221b2a',
        'border-2':  '#2c2338',
        accent:      '#e07c3c',
        'accent-2':  '#b84f22',
        success:     '#10b981',
        danger:      '#ef4444',
        warning:     '#f59e0b',
        ink:         '#ede8df',
        muted:       '#6b5d52',
        dim:         '#9e8b7c',
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
        mono: ['JetBrains Mono', 'Fira Code', 'monospace'],
      },
      borderRadius: {
        DEFAULT: '8px',
        lg: '12px',
        xl: '16px',
      },
      boxShadow: {
        card:      '0 0 0 1px #221b2a',
        glow:      '0 0 24px rgba(224, 124, 60, 0.18)',
        'glow-sm': '0 0 12px rgba(224, 124, 60, 0.12)',
        'glow-lg': '0 0 40px rgba(224, 124, 60, 0.22)',
      },
    },
  },
  plugins: [],
}
