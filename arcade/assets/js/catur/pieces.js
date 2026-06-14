// pieces.js – export SVG and Unicode piece definitions
export const SVG_PIECES = {
    // White pieces
    'wp': `<svg class="w-[85%] h-[85%]" viewBox="0 0 45 45"><path d="M22.5 9c-2.21 0-4 1.79-4 4 0 .89.29 1.71.78 2.38C17.33 16.5 16 18.59 16 21c0 2.03.94 3.84 2.41 5.03-.83.64-1.41 1.63-1.41 2.75 0 1.2.66 2.23 1.63 2.78C15.6 32.5 14 35.06 14 38h17c0-2.94-1.6-5.5-4.63-6.44.97-.55 1.63-1.58 1.63-2.78 0-1.12-.58-2.11-1.41-2.75C28.06 24.84 29 23.03 29 21c0-2.41-1.33-4.5-3.28-5.62.49-.67.78-1.49.78-2.38 0-2.21-1.79-4-4-4z" fill="#fff" stroke="#000" stroke-width="1.5" stroke-linecap="round"/></svg>`,
    // Add other pieces (wr, wn, wb, wq, wk, bp, br, bn, bb, bq, bk) as needed
};

export const UNICODE_PIECES = {
    'p': '♟', 'r': '♜', 'n': '♞', 'b': '♝', 'q': '♛', 'k': '♚'
};
