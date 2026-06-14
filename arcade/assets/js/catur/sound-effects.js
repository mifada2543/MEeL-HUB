// sound-effects.js – provides ChessSoundEffects class and a shared instance
export class ChessSoundEffects {
    constructor() {
        this.ctx = null;
        this.initialized = false;
    }

    init() {
        if (!this.initialized) {
            this.ctx = new (window.AudioContext || window.webkitAudioContext)();
            this.initialized = true;
            if (this.ctx.state === 'suspended') this.ctx.resume();
        }
    }

    playTone(type, freq1, freq2, duration, vol) {
        if (!this.ctx) return;
        try {
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = type;
            osc.frequency.setValueAtTime(freq1, this.ctx.currentTime);
            if (freq2) osc.frequency.exponentialRampToValueAtTime(freq2, this.ctx.currentTime + duration);
            gain.gain.setValueAtTime(vol, this.ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, this.ctx.currentTime + duration);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start();
            osc.stop(this.ctx.currentTime + duration);
        } catch (e) {
            console.warn('Audio error:', e);
        }
    }

    playMove() { this.init(); this.playTone('triangle', 320, 120, 0.1, 0.15); }
    playCapture() { this.init(); this.playTone('sawtooth', 250, 100, 0.15, 0.15); this.playTone('square', 180, 80, 0.15, 0.1); }
    playCheck() { this.init(); this.playTone('sine', 520, 660, 0.25, 0.2); }
    playGameOver() { this.init(); this.playTone('triangle', 440, null, 0.5, 0.2); }
}

// Export a singleton instance for convenience
export const sounds = new ChessSoundEffects();
