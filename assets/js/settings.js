/**
 * ConnectMeet - Settings & Device Management
 * Device enumeration, speaker test, and device preference persistence
 */

class SettingsManager {
    constructor() {
        this.audioInputDevices = [];
        this.videoInputDevices = [];
        this.audioOutputDevices = [];

        this.selectedAudioInput = localStorage.getItem('cm_pref_audio_in') || '';
        this.selectedVideoInput = localStorage.getItem('cm_pref_video_in') || '';
        this.selectedAudioOutput = localStorage.getItem('cm_pref_audio_out') || '';
    }

    /**
     * Enumerate available media devices
     */
    async refreshDevices() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
            console.warn('enumerateDevices is not supported');
            return;
        }

        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            this.audioInputDevices = devices.filter(d => d.kind === 'audioinput');
            this.videoInputDevices = devices.filter(d => d.kind === 'videoinput');
            this.audioOutputDevices = devices.filter(d => d.kind === 'audiooutput');

            return {
                audioInputs: this.audioInputDevices,
                videoInputs: this.videoInputDevices,
                audioOutputs: this.audioOutputDevices
            };
        } catch (err) {
            console.error('Error refreshing media devices:', err);
        }
    }

    /**
     * Populate HTML select elements with discovered devices
     */
    populateSelects(micSelectEl, camSelectEl, speakerSelectEl) {
        if (micSelectEl) {
            micSelectEl.innerHTML = '';
            if (this.audioInputDevices.length === 0) {
                micSelectEl.innerHTML = '<option value="">Default Microphone</option>';
            } else {
                this.audioInputDevices.forEach((dev, idx) => {
                    const opt = document.createElement('option');
                    opt.value = dev.deviceId;
                    opt.textContent = dev.label || `Microphone ${idx + 1}`;
                    if (dev.deviceId === this.selectedAudioInput) opt.selected = true;
                    micSelectEl.appendChild(opt);
                });
            }
        }

        if (camSelectEl) {
            camSelectEl.innerHTML = '';
            if (this.videoInputDevices.length === 0) {
                camSelectEl.innerHTML = '<option value="">Default Camera</option>';
            } else {
                this.videoInputDevices.forEach((dev, idx) => {
                    const opt = document.createElement('option');
                    opt.value = dev.deviceId;
                    opt.textContent = dev.label || `Camera ${idx + 1}`;
                    if (dev.deviceId === this.selectedVideoInput) opt.selected = true;
                    camSelectEl.appendChild(opt);
                });
            }
        }

        if (speakerSelectEl) {
            speakerSelectEl.innerHTML = '';
            const canSelectSink = typeof HTMLMediaElement.prototype.setSinkId === 'function';
            if (!canSelectSink || this.audioOutputDevices.length === 0) {
                speakerSelectEl.innerHTML = '<option value="">Default Speaker (System)</option>';
                if (!canSelectSink) speakerSelectEl.disabled = true;
            } else {
                this.audioOutputDevices.forEach((dev, idx) => {
                    const opt = document.createElement('option');
                    opt.value = dev.deviceId;
                    opt.textContent = dev.label || `Speaker ${idx + 1}`;
                    if (dev.deviceId === this.selectedAudioOutput) opt.selected = true;
                    speakerSelectEl.appendChild(opt);
                });
            }
        }
    }

    /**
     * Play synthetic chime test tone using Web Audio API
     */
    playTestTone() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;

            const ctx = new AudioCtx();
            const now = ctx.currentTime;

            // Two pleasant harmonics
            const osc1 = ctx.createOscillator();
            const osc2 = ctx.createOscillator();
            const gain = ctx.createGain();

            osc1.type = 'sine';
            osc1.frequency.setValueAtTime(523.25, now); // C5
            osc1.frequency.exponentialRampToValueAtTime(659.25, now + 0.15); // E5

            osc2.type = 'triangle';
            osc2.frequency.setValueAtTime(261.63, now); // C4

            gain.gain.setValueAtTime(0.3, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.6);

            osc1.connect(gain);
            osc2.connect(gain);
            gain.connect(ctx.destination);

            osc1.start(now);
            osc2.start(now);
            osc1.stop(now + 0.65);
            osc2.stop(now + 0.65);

            setTimeout(() => ctx.close(), 700);
        } catch (err) {
            console.warn('Audio test tone error:', err);
        }
    }

    /**
     * Change audio output device for all media elements if setSinkId is supported
     */
    async setAudioOutput(deviceId, mediaElements = []) {
        this.selectedAudioOutput = deviceId;
        localStorage.setItem('cm_pref_audio_out', deviceId);

        if (typeof HTMLMediaElement.prototype.setSinkId === 'function') {
            for (const el of mediaElements) {
                try {
                    await el.setSinkId(deviceId);
                } catch (err) {
                    console.warn('Failed to setSinkId on element:', err);
                }
            }
        }
    }
}

window.SettingsManager = SettingsManager;
