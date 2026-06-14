// engine.js – Chess game engine
import { sounds } from './sound-effects.js';
export class ChessGame {
    constructor() {
        this.gameMode = 'local';
        this.aiDifficulty = 'easy';
        this.reset();
    }
    reset() {
        this.board = this.createInitialBoard();
        this.turn = 'w';
        this.history = [];
        this.captured = { w: [], b: [] };
        this.kingPositions = { w: { r: 7, c: 4 }, b: { r: 0, c: 4 } };
        this.activeSquare = null;
        this.validMoves = [];
        this.lastMove = null;
        this.isGameOver = false;
        this.promotionPending = null;
    }
    createInitialBoard() {
        const board = Array(8).fill(null).map(() => Array(8).fill(null));
        const majorBlack = ['r', 'n', 'b', 'q', 'k', 'b', 'n', 'r'];
        for (let c = 0; c < 8; c++) {
            board[0][c] = { type: majorBlack[c], color: 'b' };
            board[1][c] = { type: 'p', color: 'b' };
        }
        const majorWhite = ['r', 'n', 'b', 'q', 'k', 'b', 'n', 'r'];
        for (let c = 0; c < 8; c++) {
            board[6][c] = { type: 'p', color: 'w' };
            board[7][c] = { type: majorWhite[c], color: 'w' };
        }
        return board;
    }
    getPiece(r, c) {
        if (r < 0 || r >= 8 || c < 0 || c >= 8) return null;
        return this.board[r][c];
    }
    movePiece(fromR, fromC, toR, toC, simulate = false) {
        const piece = this.board[fromR][fromC];
        const target = this.board[toR][toC];
        if (piece.type === 'k') {
            this.kingPositions[piece.color] = { r: toR, c: toC };
        }
        this.board[toR][toC] = piece;
        this.board[fromR][fromC] = null;
        return target;
    }
    undoMovePiece(fromR, fromC, toR, toC, originalPiece, originalTarget) {
        this.board[fromR][fromC] = originalPiece;
        this.board[toR][toC] = originalTarget;
        if (originalPiece.type === 'k') {
            this.kingPositions[originalPiece.color] = { r: fromR, c: fromC };
        }
    }
    getAlgebraicNotation(fromR, fromC, toR, toC, piece, captured) {
        const files = ['a','b','c','d','e','f','g','h'];
        const ranks = ['8','7','6','5','4','3','2','1'];
        let notation = '';
        if (piece.type !== 'p') notation += piece.type.toUpperCase();
        else if (captured) notation += files[fromC];
        if (captured) notation += 'x';
        notation += files[toC] + ranks[toR];
        return notation;
    }
    getPseudoMoves(r, c) {
        const piece = this.board[r][c];
        if (!piece) return [];
        const moves = [];
        const color = piece.color;
        const enemyColor = color === 'w' ? 'b' : 'w';
        switch (piece.type) {
            case 'p': {
                const dir = color === 'w' ? -1 : 1;
                const startRank = color === 'w' ? 6 : 1;
                if (r + dir >= 0 && r + dir < 8 && !this.board[r + dir][c]) {
                    moves.push({ r: r + dir, c: c });
                    if (r === startRank && !this.board[r + 2 * dir][c]) {
                        moves.push({ r: r + 2 * dir, c: c });
                    }
                }
                const capCols = [c - 1, c + 1];
                for (let col of capCols) {
                    if (col >= 0 && col < 8 && r + dir >= 0 && r + dir < 8) {
                        const target = this.board[r + dir][col];
                        if (target && target.color === enemyColor) moves.push({ r: r + dir, c: col });
                    }
                }
                break;
            }
            case 'r':
            case 'b':
            case 'q': {
                const directions = [];
                if (piece.type === 'r' || piece.type === 'q') directions.push([-1,0],[1,0],[0,-1],[0,1]);
                if (piece.type === 'b' || piece.type === 'q') directions.push([-1,-1],[-1,1],[1,-1],[1,1]);
                for (let [dr, dc] of directions) {
                    let cr = r + dr, cc = c + dc;
                    while (cr >=0 && cr <8 && cc >=0 && cc <8) {
                        const target = this.board[cr][cc];
                        if (!target) moves.push({ r: cr, c: cc });
                        else {
                            if (target.color === enemyColor) moves.push({ r: cr, c: cc });
                            break;
                        }
                        cr += dr; cc += dc;
                    }
                }
                break;
            }
            case 'n': {
                const offsets = [[-2,-1],[-2,1],[-1,-2],[-1,2],[1,-2],[1,2],[2,-1],[2,1]];
                for (let [dr, dc] of offsets) {
                    const cr = r + dr, cc = c + dc;
                    if (cr>=0 && cr<8 && cc>=0 && cc<8) {
                        const target = this.board[cr][cc];
                        if (!target || target.color === enemyColor) moves.push({ r: cr, c: cc });
                    }
                }
                break;
            }
            case 'k': {
                const offsets = [[-1,-1],[-1,0],[-1,1],[0,-1],[0,1],[1,-1],[1,0],[1,1]];
                for (let [dr, dc] of offsets) {
                    const cr = r + dr, cc = c + dc;
                    if (cr>=0 && cr<8 && cc>=0 && cc<8) {
                        const target = this.board[cr][cc];
                        if (!target || target.color === enemyColor) moves.push({ r: cr, c: cc });
                    }
                }
                break;
            }
        }
        return moves;
    }
    isKingInCheck(color) {
        const kingPos = this.kingPositions[color];
        const enemyColor = color === 'w' ? 'b' : 'w';
        for (let r=0;r<8;r++) {
            for (let c=0;c<8;c++) {
                const piece = this.board[r][c];
                if (piece && piece.color===enemyColor) {
                    const pseudo = this.getPseudoMoves(r,c);
                    if (pseudo.some(m=>m.r===kingPos.r && m.c===kingPos.c)) return true;
                }
            }
        }
        return false;
    }
    getValidMoves(r,c) {
        const piece = this.board[r][c];
        if (!piece || piece.color!==this.turn) return [];
        const pseudoMoves = this.getPseudoMoves(r,c);
        const valid = [];
        for (let move of pseudoMoves) {
            const origPiece = this.board[r][c];
            const origTarget = this.movePiece(r,c,move.r,move.c,true);
            const inCheck = this.isKingInCheck(piece.color);
            this.undoMovePiece(r,c,move.r,move.c,origPiece,origTarget);
            if (!inCheck) valid.push(move);
        }
        return valid;
    }
    hasAnyValidMoves(color) {
        for (let r=0;r<8;r++) {
            for (let c=0;c<8;c++) {
                const piece = this.board[r][c];
                if (piece && piece.color===color) {
                    if (this.getValidMoves(r,c).length>0) return true;
                }
            }
        }
        return false;
    }
    executeMove(fromR, fromC, toR, toC, promotedPieceType=null) {
        if (this.isGameOver) return false;
        const piece = this.board[fromR][fromC];
        if (!piece || piece.color!==this.turn) return false;
        const valid = this.getValidMoves(fromR, fromC);
        const isValid = valid.some(m=>m.r===toR && m.c===toC);
        if (!isValid) return false;
        const isPawn = piece.type==='p';
        const reachedEnd = (piece.color==='w' && toR===0) || (piece.color==='b' && toR===7);
        if (isPawn && reachedEnd && !promotedPieceType) {
            this.promotionPending = { from:{r:fromR,c:fromC}, to:{r:toR,c:toC} };
            return 'promotion';
        }
        const captured = this.movePiece(fromR, fromC, toR, toC);
        if (isPawn && reachedEnd && promotedPieceType) {
            this.board[toR][toC] = { type: promotedPieceType, color: piece.color };
        }
        if (captured) this.captured[this.turn].push(captured.type);
        // Note: sound playing is handled by UI layer
        let notation = this.getAlgebraicNotation(fromR,fromC,toR,toC,piece,captured);
        if (promotedPieceType) notation += '=' + promotedPieceType.toUpperCase();
        this.history.push({ from:{r:fromR,c:fromC}, to:{r:toR,c:toC}, piece:piece.type, color:piece.color, captured: captured?captured.type:null, algebraic: notation });
        this.lastMove = { from:{r:fromR,c:fromC}, to:{r:toR,c:toC} };
        this.turn = this.turn==='w'?'b':'w';
        this.activeSquare = null; this.validMoves = [];
        const enemyColor = this.turn;
        const enemyInCheck = this.isKingInCheck(enemyColor);
        if (!this.hasAnyValidMoves(enemyColor)) {
            this.isGameOver = true;
            return enemyInCheck ? { status:'checkmate', winner: enemyColor==='w'?'b':'w' } : { status:'stalemate' };
        }
        return { status:'success', check: enemyInCheck };
    }
    // AI logic (simplified) – kept for completeness
    evaluateBoard(){ const values={p:10,n:30,b:30,r:50,q:90,k:1000}; let score=0; for(let r=0;r<8;r++) for(let c=0;c<8;c++){ const p=this.board[r][c]; if(p) score += (p.color==='w'?values[p.type]:-values[p.type]); } return score; }
    getBestMove(){ const color='b'; let moves=[]; for(let r=0;r<8;r++) for(let c=0;c<8;c++){ const p=this.board[r][c]; if(p && p.color===color){ const vm=this.getValidMoves(r,c); for(let m of vm) moves.push({from:{r,c}, to:m, piece:p}); } }
        if(!moves.length) return null;
        if(this.aiDifficulty==='easy') return moves[Math.floor(Math.random()*moves.length)];
        // medium & hard omitted for brevity
        return moves[0];
    }
}
