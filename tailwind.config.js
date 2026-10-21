/**
 * Konfigurasi Tailwind CSS — sumber kebenaran untuk `assets/css/tailwind.min.css`.
 *
 * LATAR BELAKANG
 * --------------
 * `assets/css/tailwind.min.css` adalah artefak hasil build yang di-commit,
 * tapi sampai 2026-10-14 tidak ada satu pun file konfigurasi atau perintah
 * build-nya di repo. Artinya file itu tidak bisa direproduksi: siapa pun
 * yang mengubah markup lalu ingin membangun ulang CSS tidak punya cara
 * memastikan hasil barunya setara dengan artefak yang sekarang dipakai.
 *
 * Semua nilai di bawah BUKAN tebakan. Diekstrak dari build yang benar-benar
 * menghasilkan `assets/css/tailwind.min.css` saat ini (Tailwind CLI
 * v3.4.19), lalu diverifikasi: 1509/1509 kurung kurawal seimbang,
 * 7 keyframes, dan regresi nol terhadap artefak yang lama.
 * Sumber: `.agents/progress.md` § Khusus T10.
 *
 * Kalau class baru tidak muncul setelah build ulang, jangan langsung
 * menambah class itu ke config untuk "menyelamatkan" satu halaman.
 * Cek dulu apakah glob `content` di bawah benar-benar memindai filenya:
 * hampir setiap class-yang-hilang yang pernah terjadi di repo ini
 * penyebabnya glob yang salah, bukan config yang salah.
 */

/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './**/*.{php,js,html}',
    '!./.git/**',
    '!./vendor/**',
    '!./node_modules/**',
    '!./logs/**',
    '!./temp/**',
    '!./storage/**',
    '!./data_drive/**',
    '!./tests/**',
  ],

  theme: {
    extend: {
      colors: {
        bg: '#080b11',
        muted: '#455060',
        ink: {
          DEFAULT: '#0a0b0f',
          2: '#111318',
          3: '#1a1c24',
        },
        card: '#0e1118',
      },

      fontFamily: {
        sans: ['DM Sans', 'sans-serif'],
        mono: ['IBM Plex Mono', 'monospace'],
        display: ['Fraunces', 'serif'],
        syne: ['Syne', 'sans-serif'],
      },

      keyframes: {
        cardIn: {
          '0%': { opacity: '0', transform: 'translateY(18px) scale(.98)' },
          to: { opacity: '1', transform: 'translateY(0) scale(1)' },
        },
        iconPop: {
          '0%': { transform: 'scale(.5)', opacity: '0' },
          to: { transform: 'scale(1)', opacity: '1' },
        },
        indeterminate: {
          '0%': { transform: 'translateX(-100%) scaleX(.4)' },
          to: { transform: 'translateX(300%) scaleX(.4)' },
        },
      },

      animation: {
        'card-in': 'cardIn .35s cubic-bezier(.22,1,.36,1)',
        'icon-pop': 'iconPop .4s cubic-bezier(.34,1.56,.64,1) .1s both',
        indeterminate: 'indeterminate 1.4s ease-in-out infinite',
      },
    },
  },

  plugins: [],
};

/* reference build: MEeL-C10H15N [tailwind-config] */