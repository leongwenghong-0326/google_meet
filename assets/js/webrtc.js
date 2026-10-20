/**
 * ConnectMeet - Native WebRTC Engine (Mesh Architecture)
 * Manages local media capture, RTCPeerConnections, ICE candidate queuing, screen sharing, and audio activity detection
 */

class WebRTCManager {
    constructor(config = {}) {
        this.iceServers = config.iceServers || [
            { urls: [
                'stun:stun.cloudflare.com:3478',
                'stun:stun.l.google.com:19302',
                'stun:stun1.l.google.com:19302',
                'stun:stun2.l.google.com:19302',
                'stun:stun.services.mozilla.com:3478',
                'stun:global.stun.twilio.com:3478'
            ]},
            {
                urls: [
                    'turn:openrelay.metered.ca:80',
                    'turn:openrelay.metered.ca:443',
                    'turn:openrelay.metered.ca:443?transport=tcp'
                ],
                username: 'openrelay',
                credential: 'openrelay'
            }
        ];
        this.signalingClient = config.signalingClient;
        this.localParticipantId = config.participantId;

        this.localStream = null;
        this.cameraTrack = null;
        this.screenStream = null;
        this.screenTrack = null;

        // Peer connections dictionary: { [participantId]: RTCPeerConnection }
        this.peers = new Map();
        // Remote media streams dictionary: { [participantId]: MediaStream }
        this.remoteStreams = new Map();
        // Candidate queues for when remote description is not yet set: { [participantId]: [] }
        this.pendingCandidates = new Map();

        // Audio analysis for active speaker detection
        this.audioContext = null;
        this.analyserNode = null;
        this.audioLevelInterval = null;
        this.remoteAudioAnalysers = new Map(); // peerId -> { source, analyser, interval }
        this.dataChannels = new Map(); // peerId -> RTCDataChannel
        this.lastSpeakerBroadcastTime = 0;

        // Event callbacks
        this.onRemoteStreamAdded = config.onRemoteStreamAdded || (() => {});
        this.onRemoteStreamRemoved = config.onRemoteStreamRemoved || (() => {});
        this.onActiveSpeaker = config.onActiveSpeaker || (() => {});
        this.onStopSpeaker = config.onStopSpeaker || (() => {});
        this.onLocalAudioLevel = config.onLocalAudioLevel || (() => {});
        this.onScreenShareEnded = config.onScreenShareEnded || (() => {});
        this.onError = config.onError || ((err) => console.error(err));
    }

    /**
     * Acquire local media stream with safe fallback
     */
    async initLocalMedia(audioWanted = true, videoWanted = true, audioDeviceId = null, videoDeviceId = null) {
        // Stop any existing tracks
        this.stopLocalMedia();

        const constraints = {
            audio: audioWanted ? (audioDeviceId ? { deviceId: { exact: audioDeviceId } } : true) : false,
            video: videoWanted ? {
                deviceId: videoDeviceId ? { exact: videoDeviceId } : undefined,
                width: { ideal: 1280 },
                height: { ideal: 720 },
                facingMode: 'user'
            } : false
        };

        try {
            if (audioWanted || videoWanted) {
                this.localStream = await navigator.mediaDevices.getUserMedia(constraints);
            } else {
                this.localStream = new MediaStream();
            }
        } catch (err) {
            console.warn('Initial getUserMedia failed, attempting fallback...', err);
            // Fallback 1: Try audio only if video failed
            if (videoWanted) {
                try {
                    this.localStream = await navigator.mediaDevices.getUserMedia({ audio: audioWanted });
                    videoWanted = false;
                } catch (audioErr) {
                    console.warn('Audio fallback failed, joining in receive-only mode');
                    this.localStream = new MediaStream();
                    audioWanted = false;
                    videoWanted = false;
                }
            } else {
                this.localStream = new MediaStream();
            }
        }

        // Cache camera video track for easy screen-share restoration
        const videoTracks = this.localStream.getVideoTracks();
        this.cameraTrack = videoTracks.length > 0 ? videoTracks[0] : null;

        // Initialize audio analyser
        this.setupAudioAnalysis();

        return {
            stream: this.localStream,
            audioEnabled: audioWanted && this.localStream.getAudioTracks().length > 0,
            videoEnabled: videoWanted && this.localStream.getVideoTracks().length > 0
        };
    }

    /**
     * Setup AudioContext to detect microphone input level and speaking activity
     */
    setupAudioAnalysis() {
        if (!this.localStream || this.localStream.getAudioTracks().length === 0) return;

        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;

            this.audioContext = new AudioCtx();
            const source = this.audioContext.createMediaStreamSource(this.localStream);
            this.analyserNode = this.audioContext.createAnalyser();
            this.analyserNode.fftSize = 256;
            source.connect(this.analyserNode);

            const bufferLength = this.analyserNode.frequencyBinCount;
            const dataArray = new Uint8Array(bufferLength);

            if (this.audioLevelInterval) clearInterval(this.audioLevelInterval);

            this.audioLevelInterval = setInterval(() => {
                if (!this.analyserNode) return;
                this.analyserNode.getByteFrequencyData(dataArray);

                let sum = 0;
                for (let i = 0; i < bufferLength; i++) {
                    sum += dataArray[i];
                }
                const average = sum / bufferLength;
                const normalized = Math.min(100, Math.round((average / 128) * 100));

                this.onLocalAudioLevel(normalized);

                // Precise Voice Activity Detection: Only trigger if mic is enabled and volume is genuine human speech (>= 28)
                const audioTrack = (this.localStream && this.localStream.getAudioTracks().length > 0)
                    ? this.localStream.getAudioTracks()[0]
                    : null;

                if (audioTrack && audioTrack.enabled && normalized >= 28) {
                    this.consecutiveSpeakingCount = (this.consecutiveSpeakingCount || 0) + 1;
                    // Require at least 2 consecutive frames (200ms) to filter out background clicks or fan spikes
                    if (this.consecutiveSpeakingCount >= 2) {
                        this.onActiveSpeaker(this.localParticipantId);
                        this.broadcastSpeakerState(this.localParticipantId);
                    }
                } else {
                    this.consecutiveSpeakingCount = 0;
                }
            }, 100);
        } catch (err) {
            console.warn('Audio analysis setup warning:', err);
        }
    }

    /**
     * Broadcast local speaking state to all connected peers via WebRTC DataChannel (Sub-10ms P2P)
     */
    broadcastSpeakerState(speakerId) {
        const now = Date.now();
        // Throttle to avoid flooding data channels (every 250ms max per burst)
        if (now - this.lastSpeakerBroadcastTime < 250) return;
        this.lastSpeakerBroadcastTime = now;

        const payload = JSON.stringify({ type: 'active_speaker', speakerId: speakerId });
        if (this.dataChannels) {
            this.dataChannels.forEach((dc) => {
                if (dc && dc.readyState === 'open') {
                    try {
                        dc.send(payload);
                    } catch (e) {
                        // Channel busy
                    }
                }
            });
        }
    }

    /**
     * Broadcast stop speaking state when muted
     */
    broadcastStopSpeaker(speakerId) {
        const payload = JSON.stringify({ type: 'stopped_speaking', speakerId: speakerId });
        if (this.dataChannels) {
            this.dataChannels.forEach((dc) => {
                if (dc && dc.readyState === 'open') {
                    try {
                        dc.send(payload);
                    } catch (e) {
                        // Channel busy
                    }
                }
            });
        }
    }

    /**
     * Setup WebRTC DataChannel for instant P2P state exchange
     */
    setupDataChannel(remoteParticipantId, dc) {
        if (!dc) return;
        this.dataChannels.set(remoteParticipantId, dc);

        dc.onmessage = (event) => {
            try {
                const msg = JSON.parse(event.data);
                if (msg && msg.type === 'active_speaker' && msg.speakerId) {
                    this.onActiveSpeaker(msg.speakerId);
                } else if (msg && msg.type === 'stopped_speaking' && msg.speakerId) {
                    this.onStopSpeaker(msg.speakerId);
                }
            } catch (e) {
                // Ignore malformed packet
            }
        };

        dc.onclose = () => {
            this.dataChannels.delete(remoteParticipantId);
        };

        dc.onerror = (err) => {
            console.warn(`DataChannel warning for peer ${remoteParticipantId}:`, err);
        };
    }

    /**
     * Toggle local microphone track
     */
    setAudioEnabled(enabled) {
        if (!this.localStream) return false;
        this.localStream.getAudioTracks().forEach(track => {
            track.enabled = !!enabled;
        });

        if (!enabled) {
            this.consecutiveSpeakingCount = 0;
            this.onStopSpeaker(this.localParticipantId);
            this.broadcastStopSpeaker(this.localParticipantId);
        }

        return enabled;
    }

    /**
     * Toggle local camera track with dynamic acquisition and peer propagation
     */
    async setVideoEnabled(enabled) {
        if (enabled) {
            let videoTrack = (this.localStream && this.localStream.getVideoTracks().length > 0)
                ? this.localStream.getVideoTracks()[0]
                : null;

            // If no video track currently exists, dynamically acquire one via getUserMedia
            if (!videoTrack) {
                try {
                    const videoConstraints = {
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                        facingMode: 'user'
                    };
                    const freshStream = await navigator.mediaDevices.getUserMedia({ video: videoConstraints });
                    videoTrack = freshStream.getVideoTracks()[0];
                    if (videoTrack) {
                        if (!this.localStream) {
                            this.localStream = new MediaStream();
                        }
                        this.localStream.addTrack(videoTrack);
                        this.cameraTrack = videoTrack;
                    }
                } catch (err) {
                    console.warn('Could not acquire video track:', err);
                    throw err;
                }
            } else {
                videoTrack.enabled = true;
            }

            // Sync with local self video element
            const selfVideo = document.getElementById('video_self');
            if (selfVideo && this.localStream) {
                selfVideo.srcObject = this.localStream;
                selfVideo.play().catch(e => console.warn(e));
            }

            // Propagate new or enabled video track to all active peer connections
            for (const [peerId, pc] of this.peers.entries()) {
                const senders = pc.getSenders();
                const vSender = senders.find(s => s.track && s.track.kind === 'video');
                if (vSender) {
                    try {
                        await vSender.replaceTrack(videoTrack);
                    } catch (e) {
                        console.warn(`Error replacing track for peer ${peerId}:`, e);
                    }
                } else if (videoTrack) {
                    try {
                        pc.addTrack(videoTrack, this.localStream);
                        // Renegotiate with peer if track newly added
                        const offer = await pc.createOffer();
                        await pc.setLocalDescription(offer);
                        if (this.signalingClient) {
                            await this.signalingClient.sendSignal(peerId, 'offer', {
                                sdp: pc.localDescription.sdp,
                                type: pc.localDescription.type
                            });
                        }
                    } catch (e) {
                        console.warn(`Error adding track to peer ${peerId}:`, e);
                    }
                }
            }
            return true;
        } else {
            // Disable video track
            if (this.localStream) {
                this.localStream.getVideoTracks().forEach(track => {
                    track.enabled = false;
                });
            }
            return false;
        }
    }

    getRemoteStream(remoteParticipantId) {
        return this.remoteStreams.get(remoteParticipantId) || null;
    }

    /**
     * Connect to a remote participant in the mesh
     */
    async connectToPeer(remoteParticipantId, isInitiator = false) {
        if (this.peers.has(remoteParticipantId)) {
            console.warn(`Peer connection already exists for participant ${remoteParticipantId}`);
            return this.peers.get(remoteParticipantId);
        }

        const pc = new RTCPeerConnection({
            iceServers: this.iceServers,
            iceCandidatePoolSize: 2
        });
        this.peers.set(remoteParticipantId, pc);
        this.pendingCandidates.set(remoteParticipantId, []);

        // Add local tracks to peer connection
        let hasAudioSender = false;
        let hasVideoSender = false;

        if (this.localStream) {
            this.localStream.getTracks().forEach(track => {
                if (track.kind === 'audio') hasAudioSender = true;
                if (track.kind === 'video') hasVideoSender = true;
                pc.addTrack(track, this.localStream);
            });
        }

        // Guarantee transceivers exist so toggling camera or screen share does not fail negotiation
        if (!hasAudioSender) {
            try { pc.addTransceiver('audio', { direction: 'sendrecv' }); } catch (e) {}
        }
        if (!hasVideoSender) {
            try { pc.addTransceiver('video', { direction: 'sendrecv' }); } catch (e) {}
        }

        // Setup WebRTC DataChannel for instant sub-10ms P2P speaker state sync
        if (isInitiator) {
            try {
                const dc = pc.createDataChannel('speaker_state', { negotiated: false });
                this.setupDataChannel(remoteParticipantId, dc);
            } catch (dcErr) {
                console.warn('DataChannel creation warning:', dcErr);
            }
        } else {
            pc.ondatachannel = (event) => {
                this.setupDataChannel(remoteParticipantId, event.channel);
            };
        }

        // Handle incoming remote media tracks and cache them into a unified MediaStream
        pc.ontrack = (event) => {
            let remoteStream = this.remoteStreams.get(remoteParticipantId);
            if (!remoteStream) {
                remoteStream = new MediaStream();
                this.remoteStreams.set(remoteParticipantId, remoteStream);
            }

            if (event.track) {
                const incomingTracks = (event.streams && event.streams[0]) ? event.streams[0].getTracks() : [event.track];
                incomingTracks.forEach(inTrack => {
                    if (!remoteStream.getTracks().some(t => t.id === inTrack.id)) {
                        // Remove older inactive track of the same kind if switching devices / screen share
                        const oldTracks = remoteStream.getTracks().filter(t => t.kind === inTrack.kind && t.id !== inTrack.id);
                        oldTracks.forEach(ot => {
                            try { remoteStream.removeTrack(ot); } catch (e) {}
                        });
                        remoteStream.addTrack(inTrack);
                    }
                });
            }

            if (event.track && event.track.kind === 'audio') {
                this.setupRemoteAudioAnalysis(remoteParticipantId, remoteStream);
            }

            this.onRemoteStreamAdded(remoteParticipantId, remoteStream);
        };

        // Send local ICE candidates to peer via signaling
        pc.onicecandidate = (event) => {
            if (event.candidate && this.signalingClient) {
                this.signalingClient.sendSignal(remoteParticipantId, 'candidate', event.candidate.toJSON());
            }
        };

        // Monitor connection state
        pc.oniceconnectionstatechange = () => {
            if (pc.iceConnectionState === 'failed') {
                console.warn(`ICE failed for peer ${remoteParticipantId}, restarting ICE...`);
                pc.restartIce();
            } else if (pc.iceConnectionState === 'disconnected' || pc.iceConnectionState === 'closed') {
                this.dataChannels.delete(remoteParticipantId);
                this.onRemoteStreamRemoved(remoteParticipantId);
            }
        };

        // If this participant is designated to initiate the call
        if (isInitiator) {
            try {
                const offer = await pc.createOffer({
                    offerToReceiveAudio: true,
                    offerToReceiveVideo: true
                });
                await pc.setLocalDescription(offer);

                if (this.signalingClient) {
                    await this.signalingClient.sendSignal(remoteParticipantId, 'offer', {
                        sdp: pc.localDescription.sdp,
                        type: pc.localDescription.type
                    });
                }
            } catch (err) {
                console.error(`Error creating offer for peer ${remoteParticipantId}:`, err);
            }
        }

        return pc;
    }

    /**
     * Handle incoming SDP Offer
     */
    async handleOffer(fromParticipantId, offerPayload) {
        let pc = this.peers.get(fromParticipantId);
        if (!pc) {
            pc = await this.connectToPeer(fromParticipantId, false);
        }

        try {
            // Defend against WebRTC Offer Glare / Collision (two peers creating offers simultaneously)
            if (pc.signalingState !== 'stable') {
                const isPolite = Number(this.localParticipantId) > Number(fromParticipantId);
                if (isPolite) {
                    try {
                        await pc.setLocalDescription({ type: 'rollback' });
                    } catch (rollbackErr) {
                        console.info('Rollback notice:', rollbackErr);
                    }
                } else {
                    console.info(`Offer glare resolved: dropping incoming offer from ${fromParticipantId}`);
                    return;
                }
            }

            await pc.setRemoteDescription(new RTCSessionDescription(offerPayload));
            await this.drainPendingCandidates(fromParticipantId);

            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);

            if (this.signalingClient) {
                await this.signalingClient.sendSignal(fromParticipantId, 'answer', {
                    sdp: pc.localDescription.sdp,
                    type: pc.localDescription.type
                });
            }
        } catch (err) {
            console.error(`Error handling offer from peer ${fromParticipantId}:`, err);
        }
    }

    /**
     * Handle incoming SDP Answer
     */
    async handleAnswer(fromParticipantId, answerPayload) {
        const pc = this.peers.get(fromParticipantId);
        if (!pc) {
            console.warn(`No peer connection found for answer from ${fromParticipantId}`);
            return;
        }

        try {
            if (pc.signalingState !== 'have-local-offer') {
                console.warn(`Ignoring answer from ${fromParticipantId} in state ${pc.signalingState}`);
                return;
            }
            await pc.setRemoteDescription(new RTCSessionDescription(answerPayload));
            await this.drainPendingCandidates(fromParticipantId);
        } catch (err) {
            console.error(`Error handling answer from peer ${fromParticipantId}:`, err);
        }
    }

    /**
     * Handle incoming ICE Candidate
     */
    async handleCandidate(fromParticipantId, candidatePayload) {
        const pc = this.peers.get(fromParticipantId);
        if (!candidatePayload) return;

        const candidate = new RTCIceCandidate(candidatePayload);

        if (!pc || !pc.remoteDescription || !pc.remoteDescription.type) {
            // Queue candidate until remote description is set
            if (!this.pendingCandidates.has(fromParticipantId)) {
                this.pendingCandidates.set(fromParticipantId, []);
            }
            this.pendingCandidates.get(fromParticipantId).push(candidate);
            return;
        }

        try {
            await pc.addIceCandidate(candidate);
        } catch (err) {
            console.warn(`Error adding ICE candidate from peer ${fromParticipantId}:`, err);
        }
    }

    /**
     * Drain queued candidates after remote description is ready
     */
    async drainPendingCandidates(participantId) {
        const pc = this.peers.get(participantId);
        const queue = this.pendingCandidates.get(participantId) || [];
        if (!pc || queue.length === 0) return;

        while (queue.length > 0) {
            const candidate = queue.shift();
            try {
                await pc.addIceCandidate(candidate);
            } catch (err) {
                console.warn(`Error applying queued candidate for ${participantId}:`, err);
            }
        }
    }

    /**
     * Start Screen Sharing using getDisplayMedia
     */
    async startScreenShare() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
            if (!window.isSecureContext && location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
                throw new Error('Screen sharing requires a secure HTTPS connection. Please access this meeting via https://');
            }
            throw new Error('Screen sharing is not supported by your browser.');
        }

        try {
            let stream = null;
            try {
                // Try capturing display with system audio
                stream = await navigator.mediaDevices.getDisplayMedia({
                    video: {
                        cursor: 'always',
                        frameRate: { ideal: 30, max: 60 }
                    },
                    audio: true
                });
            } catch (screenErr) {
                // Fallback to video-only if audio capture is not supported or declined
                console.info('Audio capture unavailable for screen share, using video-only');
                stream = await navigator.mediaDevices.getDisplayMedia({
                    video: {
                        cursor: 'always',
                        frameRate: { ideal: 30, max: 60 }
                    },
                    audio: false
                });
            }

            this.screenStream = stream;
            const screenVideoTrack = stream.getVideoTracks()[0];
            if (!screenVideoTrack) {
                throw new Error('No video track found in selected screen share.');
            }
            this.screenTrack = screenVideoTrack;

            // Handle when user stops sharing via native browser floating bar
            screenVideoTrack.onended = () => {
                this.stopScreenShare();
                this.onScreenShareEnded();
            };

            // Seamlessly swap video track in all active mesh RTCPeerConnections
            await this.replaceVideoTrackInPeers(screenVideoTrack);

            return this.screenStream;
        } catch (err) {
            console.error('Failed to start screen share:', err);
            throw err;
        }
    }

    /**
     * Stop Screen Sharing and revert to camera video
     */
    async stopScreenShare() {
        if (this.screenTrack) {
            try { this.screenTrack.stop(); } catch (e) {}
            this.screenTrack = null;
        }
        if (this.screenStream) {
            try {
                this.screenStream.getTracks().forEach(t => t.stop());
            } catch (e) {}
            this.screenStream = null;
        }

        // Restore original camera track if live, or blank
        const trackToRestore = (this.cameraTrack && this.cameraTrack.readyState === 'live') ? this.cameraTrack : null;
        await this.replaceVideoTrackInPeers(trackToRestore);
    }

    /**
     * Replace video track across all mesh peer connections
     */
    async replaceVideoTrackInPeers(newTrack) {
        for (const [peerId, pc] of this.peers.entries()) {
            try {
                const senders = pc.getSenders();
                let videoSender = senders.find(s => (s.track && s.track.kind === 'video') || (s.kind === 'video'));

                if (!videoSender && pc.getTransceivers) {
                    const transceivers = pc.getTransceivers();
                    const vt = transceivers.find(t => 
                        (t.sender && t.sender.track && t.sender.track.kind === 'video') ||
                        (t.receiver && t.receiver.track && t.receiver.track.kind === 'video') ||
                        (t.sender && (t.sender.kind === 'video' || t.kind === 'video'))
                    );
                    if (vt) {
                        videoSender = vt.sender;
                    }
                }

                if (videoSender) {
                    await videoSender.replaceTrack(newTrack);
                } else if (newTrack) {
                    pc.addTrack(newTrack, this.localStream || new MediaStream([newTrack]));
                    // Renegotiate with peer so they receive newly added screen share stream
                    const offer = await pc.createOffer();
                    await pc.setLocalDescription(offer);
                    if (this.signalingClient) {
                        await this.signalingClient.sendSignal(peerId, 'offer', {
                            sdp: pc.localDescription.sdp,
                            type: pc.localDescription.type
                        });
                    }
                }
            } catch (err) {
                console.warn(`Failed to replace video track for peer ${peerId}:`, err);
            }
        }
    }

    /**
     * Close connection with a specific peer
     */
    closePeer(participantId) {
        this.dataChannels.delete(participantId);
        const pc = this.peers.get(participantId);
        if (pc) {
            try { pc.close(); } catch (e) {}
            this.peers.delete(participantId);
        }
        this.pendingCandidates.delete(participantId);
        this.remoteStreams.delete(participantId);
        this.onRemoteStreamRemoved(participantId);
    }

    /**
     * Stop all local media tracks
     */
    stopLocalMedia() {
        if (this.localStream) {
            this.localStream.getTracks().forEach(track => {
                try {
                    track.stop();
                } catch (e) {}
            });
            this.localStream = null;
        }
        if (this.screenTrack) {
            this.screenTrack.stop();
            this.screenTrack = null;
        }
        if (this.audioLevelInterval) {
            clearInterval(this.audioLevelInterval);
            this.audioLevelInterval = null;
        }
        if (this.dataChannels) {
            this.dataChannels.clear();
        }
        if (this.audioContext && this.audioContext.state !== 'closed') {
            try {
                this.audioContext.close();
            } catch (e) {}
            this.audioContext = null;
        }
    }

    /**
     * Complete cleanup and release of all WebRTC resources
     */
    destroy() {
        this.stopLocalMedia();
        this.peers.forEach((pc, id) => {
            try {
                pc.close();
            } catch (e) {}
        });
        this.peers.clear();
        this.pendingCandidates.clear();
    }
}

window.WebRTCManager = WebRTCManager;
