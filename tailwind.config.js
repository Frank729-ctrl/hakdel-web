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
        bg:          '#080a0f',
        surface:     '#0e1117',
        'surface-2': '#151923',
        border:      '#1e2332',
        'border-2':  '#252c3d',
        accent:      '#4171ff',
        'accent-2':  '#7c5cfc',
        success:     '#10b981',
        danger:      '#ef4444',
        warning:     '#f59e0b',
        ink:         '#e2e8f0',
        muted:       '#64748b',
        dim:         '#94a3b8',
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
        card:      '0 0 0 1px #1e2332',
        glow:      '0 0 20px rgba(65, 113, 255, 0.15)',
        'glow-sm': '0 0 10px rgba(65, 113, 255, 0.1)',
      },
    },
  },
  plugins: [],
}
