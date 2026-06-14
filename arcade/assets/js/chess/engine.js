import { sounds } from './audio.js';

export class ChessGame {
    constructor() {
        this.gameMode = 'local';
        this.aiDifficulty = 'easy';
        this.muteSounds = false; // Pengganti variabel global
        this.reset();
    }

    reset() {
        this.board = this.createInitialBoard();
        this.turn = 'w';
        this.history = [];
        this.captured = { w: [], b: [] };
        this.kingPositions = { w: { r: 7, c: 4 }, b: { r: 0, c: 4 } };
        this.castlingRights = { w: { k: true, q: true }, b: { k: true, q: true } };
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
        if (piece.type === 'k') { this.kingPositions[piece.color] = { r: toR, c: toC }; }
        this.board[toR][toC] = piece;
        this.board[fromR][fromC] = null;
        return target;
    }

    undoMovePiece(fromR, fromC, toR, toC, originalPiece, originalTarget) {
        this.board[fromR][fromC] = originalPiece;
        this.board[toR][toC] = originalTarget;
        if (originalPiece.type === 'k') { this.kingPositions[originalPiece.color] = { r: fromR, c: fromC }; }
    }

    getAlgebraicNotation(fromR, fromC, toR, toC, piece, captured, isCastling) {
        if (isCastling === 'k') return 'O-O';
        if (isCastling === 'q') return 'O-O-O';
        const files = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
        const ranks = ['8', '7', '6', '5', '4', '3', '2', '1'];
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
                if (this.history.length > 0) {
                    const lastM = this.history[this.history.length - 1];
                    if (lastM.piece === 'p' && lastM.color === enemyColor && Math.abs(lastM.from.r - lastM.to.r) === 2) {
                        if (lastM.to.r === r && Math.abs(lastM.to.c - c) === 1) {
                            moves.push({ r: r + dir, c: lastM.to.c, isEnPassant: true });
                        }
                    }
                }
                break;
            }
            case 'r':
            case 'b':
            case 'q': {
                const directions = [];
                if (piece.type === 'r' || piece.type === 'q') directions.push([-1, 0], [1, 0], [0, -1], [0, 1]);
                if (piece.type === 'b' || piece.type === 'q') directions.push([-1, -1], [-1, 1], [1, -1], [1, 1]);
                for (let [dr, dc] of directions) {
                    let currR = r + dr;
                    let currC = c + dc;
                    while (currR >= 0 && currR < 8 && currC >= 0 && currC < 8) {
                        const target = this.board[currR][currC];
                        if (!target) { moves.push({ r: currR, c: currC }); } 
                        else {
                            if (target.color === enemyColor) moves.push({ r: currR, c: currC });
                            break;
                        }
                        currR += dr;
                        currC += dc;
                    }
                }
                break;
            }
            case 'n': {
                const knightOffsets = [[-2, -1], [-2, 1], [-1, -2], [-1, 2], [1, -2], [1, 2], [2, -1], [2, 1]];
                for (let [dr, dc] of knightOffsets) {
                    const currR = r + dr;
                    const currC = c + dc;
                    if (currR >= 0 && currR < 8 && currC >= 0 && currC < 8) {
                        const target = this.board[currR][currC];
                        if (!target || target.color === enemyColor) moves.push({ r: currR, c: currC });
                    }
                }
                break;
            }
            case 'k': {
                const kingOffsets = [[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, -1], [1, 0], [1, 1]];
                for (let [dr, dc] of kingOffsets) {
                    const currR = r + dr;
                    const currC = c + dc;
                    if (currR >= 0 && currR < 8 && currC >= 0 && currC < 8) {
                        const target = this.board[currR][currC];
                        if (!target || target.color === enemyColor) moves.push({ r: currR, c: currC });
                    }
                }
                const rights = this.castlingRights[color];
                if (rights.k && !this.board[r][5] && !this.board[r][6]) {
                    moves.push({ r: r, c: c + 2, isCastling: 'k' });
                }
                if (rights.q && !this.board[r][1] && !this.board[r][2] && !this.board[r][3]) {
                    moves.push({ r: r, c: c - 2, isCastling: 'q' });
                }
                break;
            }
        }
        return moves;
    }

    isKingInCheck(color) {
        const kingPos = this.kingPositions[color];
        const enemyColor = color === 'w' ? 'b' : 'w';
        for (let r = 0; r < 8; r++) {
            for (let c = 0; c < 8; c++) {
                const piece = this.board[r][c];
                if (piece && piece.color === enemyColor) {
                    const pseudoMoves = this.getPseudoMoves(r, c);
                    if (pseudoMoves.some(m => m.r === kingPos.r && m.c === kingPos.c)) return true;
                }
            }
        }
        return false;
    }

    getValidMoves(r, c) {
        const piece = this.board[r][c];
        if (!piece || piece.color !== this.turn) return [];
        const pseudoMoves = this.getPseudoMoves(r, c);
        const validMoves = [];

        for (let move of pseudoMoves) {
            const originalPiece = this.board[r][c];
            if (move.isCastling) {
                if (this.isKingInCheck(piece.color)) continue;
                let pathSafe = true;
                const dir = move.isCastling === 'k' ? 1 : -1;
                const passC = c + dir;
                const origTargetPass = this.movePiece(r, c, r, passC, true);
                if (this.isKingInCheck(piece.color)) pathSafe = false;
                this.undoMovePiece(r, c, r, passC, originalPiece, origTargetPass);
                if (!pathSafe) continue;
            }

            let epCapturedPiece = null;
            if (move.isEnPassant) {
                epCapturedPiece = this.board[r][move.c];
                this.board[r][move.c] = null;
            }

            const originalTarget = this.movePiece(r, c, move.r, move.c, true);
            const inCheck = this.isKingInCheck(piece.color);

            if (move.isEnPassant) this.board[r][move.c] = epCapturedPiece;
            this.undoMovePiece(r, c, move.r, move.c, originalPiece, originalTarget);

            if (!inCheck) validMoves.push(move);
        }
        return validMoves;
    }

    hasAnyValidMoves(color) {
        for (let r = 0; r < 8; r++) {
            for (let c = 0; c < 8; c++) {
                const piece = this.board[r][c];
                if (piece && piece.color === color) {
                    if (this.getValidMoves(r, c).length > 0) return true;
                }
            }
        }
        return false;
    }

    executeMove(fromR, fromC, toR, toC, promotedPieceType = null) {
        if (this.isGameOver) return false;
        const piece = this.board[fromR][fromC];
        if (!piece || piece.color !== this.turn) return false;

        const validMoves = this.getValidMoves(fromR, fromC);
        const moveObj = validMoves.find(m => m.r === toR && m.c === toC);
        if (!moveObj) return false;

        const isPawn = piece.type === 'p';
        const reachedEnd = (piece.color === 'w' && toR === 0) || (piece.color === 'b' && toR === 7);
        if (isPawn && reachedEnd && !promotedPieceType) {
            this.promotionPending = { from: { r: fromR, c: fromC }, to: { r: toR, c: toC } };
            return 'promotion';
        }

        let captured = this.board[toR][toC];
        if (moveObj.isEnPassant) {
            captured = this.board[fromR][toC];
            this.board[fromR][toC] = null;
        }

        this.movePiece(fromR, fromC, toR, toC);

        if (moveObj.isCastling === 'k') this.movePiece(fromR, 7, fromR, 5);
        else if (moveObj.isCastling === 'q') this.movePiece(fromR, 0, fromR, 3);

        if (piece.type === 'k') {
            this.castlingRights[piece.color].k = false;
            this.castlingRights[piece.color].q = false;
        }
        if (piece.type === 'r') {
            if (fromC === 0) this.castlingRights[piece.color].q = false;
            if (fromC === 7) this.castlingRights[piece.color].k = false;
        }
        if (captured && captured.type === 'r') {
            if (toC === 0) this.castlingRights[captured.color].q = false;
            if (toC === 7) this.castlingRights[captured.color].k = false;
        }

        if (isPawn && reachedEnd && promotedPieceType) {
            this.board[toR][toC] = { type: promotedPieceType, color: piece.color };
        }

        if (captured) {
            this.captured[this.turn].push(captured.type);
            if (!this.muteSounds) sounds.playCapture();
        } else {
            if (!this.muteSounds) sounds.playMove();
        }

        let notation = this.getAlgebraicNotation(fromR, fromC, toR, toC, piece, captured, moveObj.isCastling);
        if (promotedPieceType) notation += '=' + promotedPieceType.toUpperCase();

        this.history.push({
            from: { r: fromR, c: fromC },
            to: { r: toR, c: toC },
            piece: piece.type,
            color: piece.color,
            captured: captured ? captured.type : null,
            promotedPieceType: promotedPieceType || null,
            algebraic: notation
        });

        this.lastMove = { from: { r: fromR, c: fromC }, to: { r: toR, c: toC } };
        this.turn = this.turn === 'w' ? 'b' : 'w';
        this.activeSquare = null;
        this.validMoves = [];

        const enemyColor = this.turn;
        const enemyInCheck = this.isKingInCheck(enemyColor);

        if (enemyInCheck && !this.muteSounds) sounds.playCheck();

        if (!this.hasAnyValidMoves(enemyColor)) {
            this.isGameOver = true;
            if (!this.muteSounds) sounds.playGameOver();
            return enemyInCheck ? { status: 'checkmate', winner: enemyColor === 'w' ? 'b' : 'w' } : { status: 'stalemate' };
        }

        return { status: 'success', check: enemyInCheck };
    }

    evaluateBoard() {
        const pieceValues = { p: 10, n: 30, b: 30, r: 50, q: 90, k: 1000 };
        let score = 0;
        for (let r = 0; r < 8; r++) {
            for (let c = 0; c < 8; c++) {
                const piece = this.board[r][c];
                if (piece) score += (piece.color === 'w' ? pieceValues[piece.type] : -pieceValues[piece.type]);
            }
        }
        return score;
    }

    getBestMove() {
        const color = 'b';
        let possibleMoves = [];
        for (let r = 0; r < 8; r++) {
            for (let c = 0; c < 8; c++) {
                const piece = this.board[r][c];
                if (piece && piece.color === color) {
                    const moves = this.getValidMoves(r, c);
                    for (let m of moves) possibleMoves.push({ from: { r, c }, to: m, piece: piece });
                }
            }
        }
        if (possibleMoves.length === 0) return null;

        if (this.aiDifficulty === 'easy') return possibleMoves[Math.floor(Math.random() * possibleMoves.length)];

        if (this.aiDifficulty === 'medium') {
            const captures = possibleMoves.filter(move => this.board[move.to.r][move.to.c] !== null || move.to.isEnPassant);
            if (captures.length > 0) {
                captures.sort((a, b) => {
                    const valA = this.board[a.to.r][a.to.c]?.type === 'q' ? 9 : 1;
                    const valB = this.board[b.to.r][b.to.c]?.type === 'q' ? 9 : 1;
                    return valB - valA;
                });
                return captures[0];
            }
            return possibleMoves[Math.floor(Math.random() * possibleMoves.length)];
        }

        let bestScore = Infinity;
        let bestMoves = [];
        for (let move of possibleMoves) {
            const moveObj = move.to;
            const originalPiece = this.board[move.from.r][move.from.c];

            let epCaptured = null;
            if (moveObj.isEnPassant) {
                epCaptured = this.board[move.from.r][moveObj.c];
                this.board[move.from.r][moveObj.c] = null;
            }
            if (moveObj.isCastling === 'k') this.movePiece(move.from.r, 7, move.from.r, 5, true);
            if (moveObj.isCastling === 'q') this.movePiece(move.from.r, 0, move.from.r, 3, true);

            const originalTarget = this.movePiece(move.from.r, move.from.c, moveObj.r, moveObj.c, true);

            let bestOpponentScore = -Infinity;
            for (let r = 0; r < 8; r++) {
                for (let c = 0; c < 8; c++) {
                    const piece = this.board[r][c];
                    if (piece && piece.color === 'w') {
                        const opponentMoves = this.getPseudoMoves(r, c);
                        for (let oppMove of opponentMoves) {
                            const origTargetOpp = this.movePiece(r, c, oppMove.r, oppMove.c, true);
                            const currentScore = this.evaluateBoard();
                            if (currentScore > bestOpponentScore) bestOpponentScore = currentScore;
                            this.undoMovePiece(r, c, oppMove.r, oppMove.c, piece, origTargetOpp);
                        }
                    }
                }
            }

            this.undoMovePiece(move.from.r, move.from.c, moveObj.r, moveObj.c, originalPiece, originalTarget);
            if (moveObj.isCastling === 'k') this.undoMovePiece(move.from.r, 5, move.from.r, 7, this.board[move.from.r][5], null);
            if (moveObj.isCastling === 'q') this.undoMovePiece(move.from.r, 3, move.from.r, 0, this.board[move.from.r][3], null);
            if (moveObj.isEnPassant) this.board[move.from.r][moveObj.c] = epCaptured;

            if (bestOpponentScore < bestScore) {
                bestScore = bestOpponentScore;
                bestMoves = [move];
            } else if (bestOpponentScore === bestScore) {
                bestMoves.push(move);
            }
        }
        return bestMoves[Math.floor(Math.random() * bestMoves.length)];
    }
}