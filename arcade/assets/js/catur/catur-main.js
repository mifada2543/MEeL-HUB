// catur-main.js – entry point that wires engine, UI, and sound modules
import { ChessGame } from './engine.js';
import { sounds } from './sound-effects.js';
import { initGameUI } from './ui.js';

// Create a new game instance and initialize the UI
const game = new ChessGame();
initGameUI(game, sounds);

// Expose for debugging (optional)
window.chessGame = game;
window.chessSounds = sounds;
