// ui.js – UI manager for the chess game (ES module)
/**
 * Initialize the UI for the chess game.
 * @param {ChessGame} game – an instance of the ChessGame engine.
 * @param {Object} sounds – the sounds singleton with play methods.
 */
import { SVG_PIECES, UNICODE_PIECES } from './pieces.js';
export function initGameUI(game, sounds) {
    const boardEl = document.getElementById('chess-board');
    const moveHistoryList = document.getElementById('move-history-list');
    const promotionModal = document.getElementById('promotion-modal');

    // ----- Helper functions -----
    function createBoardCell(r, c) {
        const cell = document.createElement('div');
        cell.dataset.row = r;
        cell.dataset.col = c;
        const isDark = (r + c) % 2 === 1;
        cell.className = `relative w-full aspect-square flex items-center justify-center transition-all duration-200 ${isDark ? 'bg-[#b58863]' : 'bg-[#f0d9b5]'}`;
        return { cell, isDark };
    }
    function applyLastMoveHighlight(cell, isDark, r, c) {
        if (!game.lastMove) return;
        const { from, to } = game.lastMove;
        if ((from.r === r && from.c === c) || (to.r === r && to.c === c)) {
            cell.classList.add(isDark ? 'bg-[#aaa23a]' : 'bg-[#cdd16f]');
        }
    }
    function applyActiveSquareHighlight(cell, r, c) {
        if (game.activeSquare && game.activeSquare.r === r && game.activeSquare.c === c) {
            cell.classList.add('bg-[#f6f669]', 'bg-opacity-60', 'z-10');
            cell.classList.remove('bg-[#aaa23a]', 'bg-[#cdd16f]');
        }
    }
    function appendMoveMarker(cell, isCapture) {
        const marker = document.createElement('div');
        if (isCapture) {
            marker.className = 'absolute w-5/6 h-5/6 border-4 border-rose-500/70 rounded-full z-20 pointer-events-none animate-pulse';
        } else {
            marker.className = 'w-4 h-4 rounded-full bg-slate-900/20 z-20 pointer-events-none';
        }
        cell.appendChild(marker);
    }
    function appendPieceSvg(cell, pieceAtCell, r, c) {
        const pieceKey = pieceAtCell.color + pieceAtCell.type;
        const pieceMarkup = SVG_PIECES[pieceKey];
        if (!pieceMarkup) return;
        const isJustPlaced = game.lastMove && game.lastMove.to.r === r && game.lastMove.to.c === c;
        const wrapper = document.createElement('div');
        wrapper.className = `absolute inset-0 flex items-center justify-center z-10 select-none pointer-events-none ${isJustPlaced ? 'piece-anim' : ''}`;
        wrapper.innerHTML = pieceMarkup;
        cell.appendChild(wrapper);
    }
    function appendCoordinateLabels(cell, isDark, r, c) {
        if (c === 0) {
            const rank = document.createElement('span');
            rank.className = `absolute top-0.5 left-1 text-[9px] font-bold z-30 pointer-events-none ${isDark ? 'text-[#f0d9b5]/80' : 'text-[#b58863]/80'}`;
            rank.innerText = 8 - r;
            cell.appendChild(rank);
        }
        if (r === 7) {
            const file = document.createElement('span');
            file.className = `absolute bottom-0 right-1 text-[9px] font-bold z-30 pointer-events-none ${isDark ? 'text-[#f0d9b5]/80' : 'text-[#b58863]/80'}`;
            file.innerText = String.fromCharCode(97 + c);
            cell.appendChild(file);
        }
    }
    function renderBoard() {
        boardEl.innerHTML = '';
        for (let r = 0; r < 8; r++) {
            for (let c = 0; c < 8; c++) {
                const { cell, isDark } = createBoardCell(r, c);
                applyLastMoveHighlight(cell, isDark, r, c);
                applyActiveSquareHighlight(cell, r, c);
                const isValidMove = game.validMoves.some(m => m.r === r && m.c === c);
                const pieceAtCell = game.getPiece(r, c);
                if (isValidMove) appendMoveMarker(cell, !!pieceAtCell);
                if (pieceAtCell) appendPieceSvg(cell, pieceAtCell, r, c);
                appendCoordinateLabels(cell, isDark, r, c);
                cell.addEventListener('click', () => handleCellClick(r, c));
                boardEl.appendChild(cell);
            }
        }
    }

    function handleCellClick(r, c) {
        if (game.isGameOver) return;
        if (game.gameMode === 'ai' && game.turn === 'b') return; // prevent clicks during AI turn
        const clickedPiece = game.getPiece(r, c);
        if (game.activeSquare) {
            const isPossibleMove = game.validMoves.some(m => m.r === r && m.c === c);
            if (isPossibleMove) {
                const result = game.executeMove(game.activeSquare.r, game.activeSquare.c, r, c);
                if (result === 'promotion') { showPromotionModal(); return; }
                updateGameStatus(result);
                renderBoard();
                if (game.gameMode === 'ai' && !game.isGameOver && game.turn === 'b') {
                    setTimeout(triggerAiMove, 800);
                }
            } else if (clickedPiece && clickedPiece.color === game.turn) {
                game.activeSquare = { r, c };
                game.validMoves = game.getValidMoves(r, c);
                renderBoard();
            } else {
                game.activeSquare = null;
                game.validMoves = [];
                renderBoard();
            }
        } else {
            if (clickedPiece && clickedPiece.color === game.turn) {
                game.activeSquare = { r, c };
                game.validMoves = game.getValidMoves(r, c);
                renderBoard();
            }
        }
    }

    function triggerAiMove() {
        if (game.isGameOver || game.turn !== 'b') return;
        const aiDecision = game.getBestMove();
        if (aiDecision) {
            const result = game.executeMove(aiDecision.from.r, aiDecision.from.c, aiDecision.to.r, aiDecision.to.c);
            if (result === 'promotion') {
                // auto‑promote to queen for AI
                game.executeMove(game.promotionPending.from.r, game.promotionPending.from.c, game.promotionPending.to.r, game.promotionPending.to.c, 'q');
                game.promotionPending = null;
                updateGameStatus({ status: 'success', check: game.isKingInCheck('w') });
            } else {
                updateGameStatus(result);
            }
            renderBoard();
        }
    }

    function showPromotionModal() {
        const choicesContainer = document.getElementById('promotion-choices');
        choicesContainer.innerHTML = '';
        const options = ['q', 'r', 'b', 'n'];
        const activeColor = game.turn;
        options.forEach(opt => {
            const btn = document.createElement('button');
            btn.className = 'bg-slate-800 hover:bg-slate-700 p-4 border border-slate-600 rounded-xl flex items-center justify-center transition-all active:scale-95 shadow-md';
            btn.innerHTML = SVG_PIECES[activeColor + opt];
            btn.onclick = () => {
                const pending = game.promotionPending;
                if (pending) {
                    const result = game.executeMove(pending.from.r, pending.from.c, pending.to.r, pending.to.c, opt);
                    game.promotionPending = null;
                    promotionModal.classList.add('hidden');
                    updateGameStatus(result);
                    renderBoard();
                    if (game.gameMode === 'ai' && !game.isGameOver && game.turn === 'b') {
                        setTimeout(triggerAiMove, 800);
                    }
                }
            };
            choicesContainer.appendChild(btn);
        });
        promotionModal.classList.remove('hidden');
    }

    function updateGameStatus(result) {
        document.getElementById('captured-white').innerHTML = game.captured.b.map(p => `<span class="text-slate-300 drop-shadow">${UNICODE_PIECES[p] || p}</span>`).join('');
        document.getElementById('captured-black').innerHTML = game.captured.w.map(p => `<span class="text-emerald-500/80 drop-shadow">${UNICODE_PIECES[p] || p}</span>`).join('');
        // Update move list
        moveHistoryList.innerHTML = '';
        const placeholder = document.getElementById('no-moves-placeholder');
        if (game.history.length === 0) {
            placeholder.classList.remove('hidden');
        } else {
            placeholder.classList.add('hidden');
            for (let i = 0; i < game.history.length; i += 2) {
                const wMove = game.history[i]?.algebraic || '';
                const bMove = game.history[i + 1]?.algebraic || '';
                moveHistoryList.innerHTML += `
                    <tr class="hover:bg-slate-800/40 transition-colors">
                        <td class="py-2.5 text-slate-500 font-bold">${Math.floor(i / 2) + 1}.</td>
                        <td class="py-2.5 font-semibold text-emerald-400">${wMove}</td>
                        <td class="py-2.5 font-semibold text-slate-300">${bMove}</td>
                    </tr>`;
            }
            const container = document.getElementById('move-history-container');
            container.scrollTop = container.scrollHeight;
        }
        // Turn indicator
        const indColor = document.getElementById('turn-indicator-color');
        const indText = document.getElementById('turn-indicator-text');
        if (game.turn === 'w') {
            indColor.className = 'w-4 h-4 rounded-full bg-white border-2 border-slate-300 shadow-[0_0_10px_rgba(255,255,255,0.2)]';
            indText.innerText = 'Putih (Anda)';
        } else {
            indColor.className = 'w-4 h-4 rounded-full bg-slate-800 border-2 border-slate-600 shadow-inner';
            indText.innerText = game.gameMode === 'ai' ? 'Hitam (AI)' : 'Hitam';
        }
        document.getElementById('check-alert-white').classList.toggle('hidden', !game.isKingInCheck('w'));
        document.getElementById('check-alert-black').classList.toggle('hidden', !game.isKingInCheck('b'));
        // Game status badge
        const badge = document.getElementById('game-status-badge');
        if (game.isGameOver) {
            badge.textContent = 'Selesai';
            badge.className = 'px-3 py-1 text-[10px] font-bold rounded-full bg-red-600/10 text-red-400 border border-red-600/20 uppercase tracking-widest shadow-[0_0_10px_rgba(239,68,68,0.1)]';
        } else {
            badge.textContent = 'Aktif';
            badge.className = 'px-3 py-1 text-[10px] font-bold rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 uppercase tracking-widest shadow-[0_0_10px_rgba(16,185,129,0.1)]';
        }
    }

    // Initial render
    renderBoard();
}
