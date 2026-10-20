/**
 * ConnectMeet - In-Meeting Controller & Orchestrator
 * Coordinates UI, WebRTC, Signaling, Chat, Screen Sharing, Reactions, Captions, and Recording
 */

class MeetingApp {
    constructor() {
        this.meetingCode = this.getUrlParameter('meeting');
        this.hostToken = this.getUrlParameter('host_token') || sessionStorage.getItem(`cm_host_${this.meetingCode}`) || null;
        this.isHost = !!this.hostToken;

        this.participantId = null;
        this.participantToken = null;
        this.displayName = localStorage.getItem('cm_display_name') || '';

        // Media states
        this.audioEnabled = true;
        this.videoEnabled = true;
        this.isScreenSharing = false;
        this.isHandRaised = false;
        this.isCaptionsActive = false;
        this.isRecording = false;

        // Managers
        this.signaling = null;
        this.webrtc = null;
        this.settings = new SettingsManager();

        // Recording
        this.mediaRecorder = null;
        this.recordedChunks = [];
        this.recordingStartTime = 0;
        this.recordingTimer = null;
        this.recordingAudioContext = null;

        // Captions
        this.speechRecognizer = null;
        this.captionLang = localStorage.getItem('cm_caption_lang') || 'en-US';

        // Meeting participants cache
        this.participants = new Map(); // id -> participantData
        this.unreadMessagesCount = 0;
        this.isChatOpen = false;
        this.isParticipantsOpen = false;
        this.currentOpenDrawer = null;

        this.init();
    }

    getUrlParameter(name) {
        const regex = new RegExp(`[\\?&]${name}=([^&#]*)`);
        const results = regex.exec(location.search);
        return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
    }

    async init() {
        if (!this.meetingCode) {
            window.location.href = 'index.php';
            return;
        }

        // Cache host token in session storage if present in URL
        if (this.hostToken) {
            sessionStorage.setItem(`cm_host_${this.meetingCode}`, this.hostToken);
        }

        this.bindLobbyElements();
        this.bindMeetingControls();
        this.setupKeyboardShortcuts();

        // Global listener to unlock audio/video autoplay upon user interaction
        const unlockAutoplay = () => {
            document.querySelectorAll('video').forEach(vid => {
                if (vid.srcObject && vid.paused) {
                    vid.play().catch(() => {});
                }
            });
        };
        ['click', 'touchstart', 'keydown'].forEach(evt => {
            window.addEventListener(evt, unlockAutoplay, { passive: true });
        });

        // Initialize lobby media preview
        await this.initLobbyPreview();
    }

    // =========================================================================
    // Lobby Phase
    // =========================================================================
    async initLobbyPreview() {
        const nameInput = document.getElementById('lobbyDisplayName');
        if (nameInput) {
            nameInput.value = this.displayName;
        }

        const codeDisplay = document.getElementById('lobbyMeetingCode');
        if (codeDisplay) {
            codeDisplay.textContent = this.meetingCode;
        }

        // Check if meeting exists and is valid
        try {
            const res = await fetch(`api/meetings.php?action=validate&meeting_code=${encodeURIComponent(this.meetingCode)}`);
            const data = await res.json();
            if (!data.success) {
                alert(data.message || 'Invalid or ended meeting');
                window.location.href = 'index.php';
                return;
            }
            const titleEl = document.getElementById('lobbyMeetingTitle');
            if (titleEl && data.data && data.data.title) {
                titleEl.textContent = data.data.title;
            }
        } catch (e) {
            console.warn('Meeting validation check warning:', e);
        }

        // Create temporary WebRTC manager for lobby preview
        this.webrtc = new WebRTCManager({
            onLocalAudioLevel: (lvl) => {
                const meter = document.getElementById('lobbyAudioMeterFill');
                if (meter) meter.style.width = `${lvl}%`;
            }
        });

        const previewResult = await this.webrtc.initLocalMedia(this.audioEnabled, this.videoEnabled);
        this.audioEnabled = previewResult.audioEnabled;
        this.videoEnabled = previewResult.videoEnabled;

        const lobbyVideo = document.getElementById('lobbyLocalVideo');
        if (lobbyVideo && this.webrtc.localStream) {
            lobbyVideo.srcObject = this.webrtc.localStream;
            lobbyVideo.play().catch(() => {});
        }

        this.updateLobbyMediaButtons();
        await this.settings.refreshDevices();
    }

    bindLobbyElements() {
        const toggleMic = document.getElementById('lobbyToggleMic');
        if (toggleMic) {
            toggleMic.onclick = () => {
                this.audioEnabled = !this.audioEnabled;
                this.webrtc.setAudioEnabled(this.audioEnabled);
                this.updateLobbyMediaButtons();
            };
        }

        const toggleCam = document.getElementById('lobbyToggleCam');
        if (toggleCam) {
            toggleCam.onclick = () => {
                this.videoEnabled = !this.videoEnabled;
                this.webrtc.setVideoEnabled(this.videoEnabled);
                this.updateLobbyMediaButtons();
                const avatar = document.getElementById('lobbyAvatar');
                const video = document.getElementById('lobbyLocalVideo');
                if (avatar && video) {
                    if (this.videoEnabled) {
                        avatar.style.display = 'none';
                        video.style.display = 'block';
                    } else {
                        avatar.style.display = 'flex';
                        video.style.display = 'none';
                    }
                }
            };
        }

        const joinBtn = document.getElementById('btnJoinNow');
        if (joinBtn) {
            joinBtn.onclick = () => this.joinMeetingRoom(false);
        }

        const presentBtn = document.getElementById('btnPresentNow');
        if (presentBtn) {
            presentBtn.onclick = () => this.joinMeetingRoom(true);
        }
    }

    updateLobbyMediaButtons() {
        const mic = document.getElementById('lobbyToggleMic');
        if (mic) {
            mic.classList.toggle('off', !this.audioEnabled);
            mic.innerHTML = this.audioEnabled ? '<i class="fas fa-microphone"></i>' : '<i class="fas fa-microphone-slash"></i>';
        }

        const cam = document.getElementById('lobbyToggleCam');
        if (cam) {
            cam.classList.toggle('off', !this.videoEnabled);
            cam.innerHTML = this.videoEnabled ? '<i class="fas fa-video"></i>' : '<i class="fas fa-video-slash"></i>';
        }
    }

    // =========================================================================
    // Join Meeting Execution
    // =========================================================================
    async joinMeetingRoom(startWithScreenShare = false) {
        const nameInput = document.getElementById('lobbyDisplayName');
        let name = nameInput ? nameInput.value.trim() : '';
        if (!name) name = 'Guest-' + Math.floor(Math.random() * 900 + 100);

        this.displayName = name;
        localStorage.setItem('cm_display_name', name);

        const joinBtn = document.getElementById('btnJoinNow');
        if (joinBtn) joinBtn.disabled = true;

        try {
            const res = await fetch('api/participants.php?action=join', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    meeting_code: this.meetingCode,
                    display_name: this.displayName,
                    audio_enabled: this.audioEnabled ? 1 : 0,
                    video_enabled: this.videoEnabled ? 1 : 0,
                    host_token: this.hostToken
                })
            });

            const data = await res.json();
            if (!data.success) {
                alert(data.message || 'Failed to join meeting');
                if (joinBtn) joinBtn.disabled = false;
                return;
            }

            const roomData = data.data;
            this.participantId = roomData.participant_id;
            this.participantToken = roomData.participant_token;
            this.isHost = roomData.is_host;

            // Transition UI from Lobby to Full Meeting Stage
            document.getElementById('lobbyView').style.display = 'none';
            document.getElementById('meetingView').style.display = 'flex';

            // Set meeting code badge in top bar
            const roomCodeBadge = document.getElementById('roomMeetingCode');
            if (roomCodeBadge) roomCodeBadge.textContent = this.meetingCode;

            // Start timer
            this.startMeetingTimer();

            // Setup Signaling client
            this.signaling = new SignalingClient({
                meetingId: roomData.meeting_id,
                participantId: this.participantId,
                participantToken: this.participantToken,
                hostToken: this.hostToken,
                baseUrl: ''
            });

            // Re-bind WebRTC Manager with real room callbacks
            this.setupWebRTCWithSignaling(roomData.ice_servers);

            // Setup Signaling event listeners
            this.setupSignalingListeners();

            // Add self to grid and participants list
            this.addSelfTile();

            // Connect to existing peers in mesh
            (roomData.existing_peers || []).forEach(peer => {
                this.participants.set(peer.id, peer);
                this.createRemoteTile(peer);
                // New entrant initiates WebRTC offer to existing peers
                this.webrtc.connectToPeer(peer.id, true);
            });

            this.updateVideoGridLayout();
            this.renderParticipantsList();

            // Start signaling polling routines
            this.signaling.start();

            // If user clicked "Present" in lobby, initiate screen share immediately
            if (startWithScreenShare) {
                setTimeout(() => this.toggleScreenShare(), 500);
            }

            this.showToast(`Joined as ${this.displayName}`, 'success');

        } catch (err) {
            console.error('Error joining room:', err);
            alert('Failed to connect to room: ' + err.message);
            if (joinBtn) joinBtn.disabled = false;
        }
    }

    setupWebRTCWithSignaling(iceServers) {
        this.webrtc.iceServers = iceServers || this.webrtc.iceServers;
        this.webrtc.signalingClient = this.signaling;
        this.webrtc.localParticipantId = this.participantId;

        this.webrtc.onRemoteStreamAdded = (peerId, stream) => {
            const videoEl = document.getElementById(`video_peer_${peerId}`);
            const tile = document.getElementById(`tile_peer_${peerId}`);
            if (videoEl) {
                if (videoEl.srcObject !== stream) {
                    videoEl.srcObject = stream;
                }
                const tryPlay = () => {
                    videoEl.play().catch(e => {
                        console.info('Playback awaiting user click:', e.name);
                    });
                };
                videoEl.onloadedmetadata = tryPlay;
                tryPlay();

                const hasLiveVideo = stream.getVideoTracks && stream.getVideoTracks().some(t => t.readyState === 'live' && t.enabled);
                if (tile && hasLiveVideo) {
                    tile.classList.remove('video-off');
                }
            }
        };

        // Global user interaction unblocker for browser autoplay policies
        const unlockAllAudio = () => {
            document.querySelectorAll('.video-tile video').forEach(v => {
                if (v.paused) v.play().catch(() => {});
            });
            if (this.webrtc && this.webrtc.audioContext && this.webrtc.audioContext.state === 'suspended') {
                this.webrtc.audioContext.resume().catch(() => {});
            }
        };
        document.addEventListener('click', unlockAllAudio);
        document.addEventListener('touchstart', unlockAllAudio);

        this.webrtc.onRemoteStreamRemoved = (peerId) => {
            const tile = document.getElementById(`tile_peer_${peerId}`);
            if (tile) tile.remove();
            this.participants.delete(peerId);
            this.updateVideoGridLayout();
            this.renderParticipantsList();
        };

        this.webrtc.onActiveSpeaker = (speakerId) => {
            this.highlightActiveSpeaker(speakerId);
        };

        this.webrtc.onStopSpeaker = (speakerId) => {
            this.clearSpeakerHighlight(speakerId);
        };

        this.webrtc.onScreenShareEnded = () => {
            this.isScreenSharing = false;
            const btn = document.getElementById('ctrlToggleScreen');
            if (btn) btn.classList.remove('active');

            const selfTile = document.getElementById('tile_self');
            if (selfTile) selfTile.classList.remove('screen-sharing');

            const selfVideo = document.getElementById('video_self');
            if (selfVideo) {
                selfVideo.classList.remove('no-mirror');
                if (this.webrtc.localStream) {
                    selfVideo.srcObject = this.webrtc.localStream;
                }
            }

            this.updateControlsUI();
            this.updateVideoGridLayout();
            this.signaling.updateState({ screen_sharing: 0 });
            this.showToast('Screen sharing stopped', 'info');
        };
    }

    setupSignalingListeners() {
        // Handle incoming WebRTC signals
        this.signaling.on('signal', (sig) => {
            const fromId = sig.from_participant_id;
            const type = sig.signal_type;
            const payload = sig.signal_payload;

            if (type === 'offer') {
                this.webrtc.handleOffer(fromId, payload);
            } else if (type === 'answer') {
                this.webrtc.handleAnswer(fromId, payload);
            } else if (type === 'candidate') {
                this.webrtc.handleCandidate(fromId, payload);
            }
        });

        // Handle incoming active speaker event
        this.signaling.on('active_speaker', (payload) => {
            if (payload && payload.speaker_id) {
                this.highlightActiveSpeaker(payload.speaker_id);
            }
        });

        // Handle participant events
        this.signaling.on('participant_joined', (p) => {
            if (p.id === this.participantId) return;
            this.participants.set(p.id, p);
            this.createRemoteTile(p);
            this.updateVideoGridLayout();
            this.renderParticipantsList();
            this.showToast(`${p.display_name} joined the meeting`, 'info');

            // Defensive arbitration: only retry if newcomer hasn't connected after 5 seconds and local id is lower
            setTimeout(() => {
                if (!this.webrtc.peers.has(p.id) && this.participants.has(p.id) && Number(this.participantId) < Number(p.id)) {
                    console.info(`Arbitrated fallback WebRTC connection to peer ${p.id}`);
                    this.webrtc.connectToPeer(p.id, true);
                }
            }, 5000);
        });

        this.signaling.on('participant_left', (p) => {
            this.webrtc.closePeer(p.id);
            this.showToast(`${p.display_name || 'A participant'} left`, 'info');
        });

        this.signaling.on('participant_state_changed', (state) => {
            const p = this.participants.get(state.participant_id);
            if (p) {
                Object.assign(p, state);
                this.updateRemoteTileState(state.participant_id, p);
                this.renderParticipantsList();
            }
        });

        this.signaling.on('participant_kicked', (payload) => {
            if (payload.target_participant_id === this.participantId) {
                alert('You have been removed from the meeting by the host.');
                this.cleanAndRedirect('index.php');
            } else {
                this.webrtc.closePeer(payload.target_participant_id);
            }
        });

        this.signaling.on('meeting_ended', () => {
            if (this.isMeetingEndedHandled) return;
            this.isMeetingEndedHandled = true;

            // Immediately disconnect media tracks and stop signaling
            if (this.webrtc) this.webrtc.destroy();
            if (this.signaling) this.signaling.stop();

            // Display clear full-screen notice for all participants
            const notice = document.createElement('div');
            notice.style.cssText = 'position:fixed;inset:0;background:rgba(8,12,20,0.94);backdrop-filter:blur(8px);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:24px;color:#fff;';
            notice.innerHTML = `
                <div style="width:68px;height:68px;border-radius:50%;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);display:flex;align-items:center;justify-content:center;font-size:28px;color:#ef4444;margin-bottom:18px;">
                    <i class="fas fa-phone-slash"></i>
                </div>
                <h2 style="font-size:1.5rem;font-weight:600;margin-bottom:8px;">Meeting Ended</h2>
                <p style="color:var(--text-secondary);font-size:0.95rem;max-width:420px;margin-bottom:24px;line-height:1.5;">The host has ended this meeting for everyone. Returning to home screen...</p>
                <button type="button" class="btn btn-primary" onclick="window.location.href='index.php'" style="padding:10px 24px;border-radius:24px;">Return to Home</button>
            `;
            document.body.appendChild(notice);

            setTimeout(() => {
                this.cleanAndRedirect('index.php');
            }, 2500);
        });

        this.signaling.on('lock_changed', (payload) => {
            this.showToast(payload.is_locked ? 'Meeting has been locked by the host' : 'Meeting has been unlocked', 'info');
        });

        this.signaling.on('reaction', (payload) => {
            this.renderFloatingEmoji(payload.emoji);
        });

        this.signaling.on('caption', (payload) => {
            // User requirement: If live caption is open only at host, only host can see. Participants cannot see until they personally open it.
            if (!this.isCaptionsActive) return;
            this.renderCaptionBanner(payload.display_name, payload.text);
        });

        this.signaling.on('chat_message', (msg) => {
            this.appendChatMessage(msg);
            if (!this.isChatOpen) {
                this.unreadMessagesCount++;
                const badge = document.getElementById('chatUnreadBadge');
                if (badge) {
                    badge.textContent = this.unreadMessagesCount;
                    badge.style.display = 'flex';
                }
            }
        });

        this.signaling.on('participants_sync', (list) => {
            list.forEach(p => {
                if (p.id !== this.participantId) {
                    this.participants.set(p.id, p);
                    this.updateRemoteTileState(p.id, p);
                }
            });
            this.renderParticipantsList();
        });

        this.signaling.on('session_expired', () => {
            alert('Your session has expired or was terminated.');
            this.cleanAndRedirect('index.php');
        });
    }

    // =========================================================================
    // Video Grid Management
    // =========================================================================
    addSelfTile() {
        const grid = document.getElementById('videoGrid');
        if (!grid) return;

        const tile = document.createElement('div');
        tile.className = 'video-tile local';
        tile.id = `tile_self`;

        const initials = this.getInitials(this.displayName);

        tile.innerHTML = `
            <video id="video_self" autoplay muted playsinline></video>
            <div class="avatar-placeholder" id="avatar_self">${initials}</div>
            <button type="button" class="tile-fullscreen-btn" title="Toggle Fullscreen">
                <i class="fas fa-expand"></i>
            </button>
            <div class="tile-badge-name">
                <span>${this.escapeHtml(this.displayName)} (You)</span>
                ${this.isHost ? '<span style="color:#60a5fa;font-size:0.75rem;">[Host]</span>' : ''}
            </div>
            <div class="tile-badge-status" id="status_self"></div>
        `;

        const fsBtn = tile.querySelector('.tile-fullscreen-btn');
        if (fsBtn) {
            fsBtn.onclick = (e) => {
                e.stopPropagation();
                if (!document.fullscreenElement) {
                    tile.requestFullscreen().catch(() => {});
                } else {
                    document.exitFullscreen().catch(() => {});
                }
            };
        }

        grid.appendChild(tile);

        const videoEl = tile.querySelector('video');
        if (this.webrtc.localStream) {
            videoEl.srcObject = this.webrtc.localStream;
            videoEl.play().catch(e => console.warn(e));
        }

        if (!this.videoEnabled) {
            tile.classList.add('video-off');
        }
    }

    createRemoteTile(peer) {
        const grid = document.getElementById('videoGrid');
        if (!grid || document.getElementById(`tile_peer_${peer.id}`)) return;

        const tile = document.createElement('div');
        tile.className = 'video-tile';
        tile.id = `tile_peer_${peer.id}`;

        const initials = this.getInitials(peer.display_name);

        tile.innerHTML = `
            <video id="video_peer_${peer.id}" autoplay playsinline></video>
            <div class="avatar-placeholder" id="avatar_peer_${peer.id}">${initials}</div>
            <button type="button" class="tile-fullscreen-btn" title="Toggle Fullscreen">
                <i class="fas fa-expand"></i>
            </button>
            <div class="tile-badge-name">
                <span>${this.escapeHtml(peer.display_name)}</span>
                ${peer.role === 'host' ? '<span style="color:#60a5fa;font-size:0.75rem;">[Host]</span>' : ''}
            </div>
            <div class="tile-badge-status" id="status_peer_${peer.id}"></div>
        `;

        const fsBtn = tile.querySelector('.tile-fullscreen-btn');
        if (fsBtn) {
            fsBtn.onclick = (e) => {
                e.stopPropagation();
                if (!document.fullscreenElement) {
                    tile.requestFullscreen().catch(() => {});
                } else {
                    document.exitFullscreen().catch(() => {});
                }
            };
        }

        grid.appendChild(tile);

        // Bind stream immediately if it has already arrived from WebRTC
        const existingStream = this.webrtc.getRemoteStream(peer.id);
        if (existingStream) {
            const videoEl = tile.querySelector('video');
            if (videoEl) {
                videoEl.srcObject = existingStream;
                videoEl.onloadedmetadata = () => {
                    videoEl.play().catch(() => {});
                };
                videoEl.play().catch(e => console.warn('Tile creation autoplay caught:', e));
                if (existingStream.getVideoTracks && existingStream.getVideoTracks().length > 0) {
                    tile.classList.remove('video-off');
                }
            }
        }

        this.updateRemoteTileState(peer.id, peer);
    }

    updateRemoteTileState(peerId, state) {
        const tile = document.getElementById(`tile_peer_${peerId}`);
        const statusBox = document.getElementById(`status_peer_${peerId}`);
        if (!tile || !statusBox) return;

        // Check if there is an active video stream, avoid hiding video when live
        const videoEl = tile.querySelector('video');
        const hasLiveVideo = videoEl && videoEl.srcObject && videoEl.srcObject.getVideoTracks && 
            videoEl.srcObject.getVideoTracks().some(t => t.readyState === 'live' && t.enabled);

        if (state.video_enabled === false && !hasLiveVideo) {
            tile.classList.add('video-off');
        } else {
            tile.classList.remove('video-off');
        }

        // Toggle full screen share presentation mode for this tile
        if (state.screen_sharing) {
            tile.classList.add('screen-sharing');
        } else {
            tile.classList.remove('screen-sharing');
        }

        let badges = '';
        if (state.audio_enabled === false) {
            badges += `<span class="status-icon-pill muted"><i class="fas fa-microphone-slash"></i></span>`;
        }
        if (state.hand_raised) {
            badges += `<span class="status-icon-pill hand"><i class="fas fa-hand-paper"></i></span>`;
        }
        statusBox.innerHTML = badges;

        this.updateVideoGridLayout();
    }

    updateVideoGridLayout() {
        const grid = document.getElementById('videoGrid');
        if (!grid) return;

        // Check if any tile (local self or remote peer) is sharing screen
        const hasScreenShare = document.querySelector('.video-tile.screen-sharing') !== null;
        if (hasScreenShare) {
            grid.classList.add('presentation-mode');
        } else {
            grid.classList.remove('presentation-mode');
        }

        const totalCount = 1 + this.participants.size;
        grid.setAttribute('data-count', Math.min(8, totalCount).toString());
    }

    highlightActiveSpeaker(speakerId) {
        if (!speakerId) return;
        const isSelf = speakerId === this.participantId;
        const targetTileId = isSelf ? 'tile_self' : `tile_peer_${speakerId}`;
        const targetTile = document.getElementById(targetTileId);
        if (!targetTile) return;

        // CRITICAL: Ensure ONLY the person who is currently speaking has the green border!
        // Immediately remove active-speaker highlight from all other tiles
        document.querySelectorAll('.video-tile.active-speaker').forEach(tile => {
            if (tile.id !== targetTileId) {
                tile.classList.remove('active-speaker');
            }
        });

        // Apply active speaker highlight only to current speaker
        targetTile.classList.add('active-speaker');

        // Manage debounce timer: if speech ceases, remove border after 800ms completely
        if (!this.speakerTimers) {
            this.speakerTimers = new Map();
        }
        if (this.speakerTimers.has(targetTileId)) {
            clearTimeout(this.speakerTimers.get(targetTileId));
        }

        const timer = setTimeout(() => {
            if (targetTile) {
                targetTile.classList.remove('active-speaker');
            }
            this.speakerTimers.delete(targetTileId);
        }, 800);

        this.speakerTimers.set(targetTileId, timer);
    }

    clearSpeakerHighlight(speakerId) {
        if (!speakerId) return;
        const isSelf = speakerId === this.participantId;
        const targetTileId = isSelf ? 'tile_self' : `tile_peer_${speakerId}`;
        const targetTile = document.getElementById(targetTileId);
        if (targetTile) {
            targetTile.classList.remove('active-speaker');
        }
        if (this.speakerTimers && this.speakerTimers.has(targetTileId)) {
            clearTimeout(this.speakerTimers.get(targetTileId));
            this.speakerTimers.delete(targetTileId);
        }
    }

    // =========================================================================
    // In-Meeting Controls Execution
    // =========================================================================
    bindMeetingControls() {
        // Mic Toggle
        const micBtn = document.getElementById('ctrlToggleMic');
        if (micBtn) {
            micBtn.onclick = () => this.toggleMicrophone();
        }

        // Cam Toggle
        const camBtn = document.getElementById('ctrlToggleCam');
        if (camBtn) {
            camBtn.onclick = () => this.toggleCamera();
        }

        // Screen Share
        const screenBtn = document.getElementById('ctrlToggleScreen');
        if (screenBtn) {
            screenBtn.onclick = () => this.toggleScreenShare();
        }

        // Raise Hand
        const handBtn = document.getElementById('ctrlToggleHand');
        if (handBtn) {
            handBtn.onclick = () => this.toggleRaiseHand();
        }

        // Reactions menu & buttons
        const reactionsMenu = document.getElementById('reactionsPopover');
        const reactionsBtn = document.getElementById('ctrlReactionsBtn');
        if (reactionsBtn && reactionsMenu) {
            reactionsBtn.onclick = (e) => {
                e.stopPropagation();
                reactionsMenu.style.display = reactionsMenu.style.display === 'flex' ? 'none' : 'flex';
            };
            document.addEventListener('click', () => {
                reactionsMenu.style.display = 'none';
            });
        }

        document.querySelectorAll('.reaction-emoji-btn').forEach(btn => {
            btn.onclick = () => {
                const emoji = btn.dataset.emoji || '👍';
                this.sendReaction(emoji);
                if (reactionsMenu) reactionsMenu.style.display = 'none';
            };
        });

        // Captions Toggle
        const captionsBtn = document.getElementById('ctrlToggleCaptions');
        if (captionsBtn) {
            captionsBtn.onclick = () => this.toggleCaptions();
        }

        // Local Recording Toggle
        const recBtn = document.getElementById('ctrlToggleRecord');
        if (recBtn) {
            recBtn.onclick = () => this.toggleRecording();
        }

        // Chat drawer toggle
        const chatBtn = document.getElementById('ctrlToggleChat');
        if (chatBtn) {
            chatBtn.onclick = () => this.openDrawer('chat');
        }

        // Participants drawer toggle
        const partBtn = document.getElementById('ctrlToggleParticipants');
        if (partBtn) {
            partBtn.onclick = () => this.openDrawer('participants');
        }

        // Drawer Close Buttons (Pic 2 fix)
        const closeChatBtn = document.getElementById('closeChatDrawerBtn');
        if (closeChatBtn) {
            closeChatBtn.onclick = () => this.closeAllDrawers();
        }
        const closePartBtn = document.getElementById('closeParticipantsDrawerBtn');
        if (closePartBtn) {
            closePartBtn.onclick = () => this.closeAllDrawers();
        }

        // Meeting details / Copy Link button
        const infoBtn = document.getElementById('ctrlMeetingInfo');
        if (infoBtn) {
            infoBtn.onclick = () => this.copyMeetingInviteLink();
        }

        // Leave / End Meeting
        const leaveBtn = document.getElementById('ctrlLeaveMeeting');
        if (leaveBtn) {
            leaveBtn.onclick = () => this.promptLeaveOrEndMeeting();
        }

        // Chat send form
        const chatForm = document.getElementById('chatForm');
        if (chatForm) {
            chatForm.onsubmit = (e) => {
                e.preventDefault();
                this.sendCurrentChatMessage();
            };
        }

        // Settings modal triggers
        const settingsBtn = document.getElementById('ctrlOpenSettings');
        if (settingsBtn) {
            settingsBtn.onclick = () => this.openSettingsModal();
        }

        // Universal modal close bindings (Ensures 'x' button and 'Cancel' button close any open modal)
        document.querySelectorAll('.modal-close, .btn-modal-close').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                document.querySelectorAll('.modal-backdrop').forEach(modal => {
                    modal.classList.remove('open');
                });
            });
        });

        // Click outside on backdrop to dismiss modal
        document.querySelectorAll('.modal-backdrop').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('open');
                }
            });
        });
    }

    toggleMicrophone() {
        this.audioEnabled = !this.audioEnabled;
        this.webrtc.setAudioEnabled(this.audioEnabled);
        if (!this.audioEnabled) {
            this.clearSpeakerHighlight(this.participantId);
        }
        this.updateControlsUI();
        this.updateSelfStatusBadge();
        this.signaling.updateState({ audio_enabled: this.audioEnabled ? 1 : 0 });
    }

    async toggleCamera() {
        const targetState = !this.videoEnabled;
        try {
            await this.webrtc.setVideoEnabled(targetState);
            this.videoEnabled = targetState;
            this.updateControlsUI();

            const selfTile = document.getElementById('tile_self');
            if (selfTile) {
                selfTile.classList.toggle('video-off', !this.videoEnabled);
            }

            this.signaling.updateState({ video_enabled: this.videoEnabled ? 1 : 0 });
            this.showToast(this.videoEnabled ? 'Camera turned on' : 'Camera turned off', 'info');
        } catch (err) {
            console.warn('Camera toggle failed:', err);
            this.showToast('Could not access camera. It may be in use by another app or window.', 'error');
        }
    }

    async toggleScreenShare() {
        // Enforce browser secure context requirement for screen sharing
        if (!window.isSecureContext && location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            this.showToast('Screen sharing requires a secure HTTPS connection.', 'error');
            alert('Screen sharing is restricted by modern web browsers to secure HTTPS connections only. Please access your site via https:// instead of http://');
            return;
        }

        const btn = document.getElementById('ctrlToggleScreen');
        const selfTile = document.getElementById('tile_self');
        const selfVideo = document.getElementById('video_self');

        if (this.isScreenSharing) {
            await this.webrtc.stopScreenShare();
            this.isScreenSharing = false;
            if (btn) btn.classList.remove('active');

            if (selfTile) selfTile.classList.remove('screen-sharing');
            if (selfVideo) {
                selfVideo.classList.remove('no-mirror');
                if (this.webrtc.localStream) {
                    selfVideo.srcObject = this.webrtc.localStream;
                }
            }

            this.updateVideoGridLayout();
            this.signaling.updateState({ screen_sharing: 0 });
            this.showToast('Screen sharing stopped', 'info');
        } else {
            try {
                const stream = await this.webrtc.startScreenShare();
                this.isScreenSharing = true;
                if (btn) btn.classList.add('active');

                // Update self video preview to screen stream without mirroring
                if (selfTile) selfTile.classList.add('screen-sharing');
                if (selfVideo) {
                    selfVideo.classList.add('no-mirror');
                    selfVideo.srcObject = stream;
                }

                this.updateVideoGridLayout();
                this.signaling.updateState({ screen_sharing: 1 });
                this.showToast('You are presenting your screen', 'success');
            } catch (err) {
                console.warn('Screen share error:', err);
                const errMsg = (err && err.message) ? err.message : '';
                if (errMsg.toLowerCase().includes('permission') || errMsg.toLowerCase().includes('cancel') || errMsg.toLowerCase().includes('denied')) {
                    this.showToast('Screen share was cancelled', 'info');
                } else if (errMsg.includes('HTTPS')) {
                    this.showToast(errMsg, 'error');
                } else {
                    this.showToast('Screen share not available: ' + (errMsg || 'Check browser permissions'), 'error');
                }
            }
        }
    }

    toggleRaiseHand() {
        this.isHandRaised = !this.isHandRaised;
        const btn = document.getElementById('ctrlToggleHand');
        if (btn) btn.classList.toggle('active', this.isHandRaised);

        this.updateSelfStatusBadge();
        this.signaling.updateState({ hand_raised: this.isHandRaised ? 1 : 0 });

        if (this.isHandRaised) {
            this.renderFloatingEmoji('✋');
            this.showToast('You raised your hand', 'info');
        }
    }

    updateSelfStatusBadge() {
        const statusBox = document.getElementById('status_self');
        if (!statusBox) return;

        let badges = '';
        if (!this.audioEnabled) {
            badges += `<span class="status-icon-pill muted"><i class="fas fa-microphone-slash"></i></span>`;
        }
        if (this.isHandRaised) {
            badges += `<span class="status-icon-pill hand"><i class="fas fa-hand-paper"></i></span>`;
        }
        statusBox.innerHTML = badges;
    }

    updateControlsUI() {
        const mic = document.getElementById('ctrlToggleMic');
        if (mic) {
            mic.classList.toggle('off', !this.audioEnabled);
            mic.innerHTML = this.audioEnabled ? '<i class="fas fa-microphone"></i>' : '<i class="fas fa-microphone-slash"></i>';
        }

        const cam = document.getElementById('ctrlToggleCam');
        if (cam) {
            cam.classList.toggle('off', !this.videoEnabled);
            cam.innerHTML = this.videoEnabled ? '<i class="fas fa-video"></i>' : '<i class="fas fa-video-slash"></i>';
        }
    }

    // =========================================================================
    // Emoji Reactions
    // =========================================================================
    sendReaction(emoji) {
        this.renderFloatingEmoji(emoji);
        if (this.signaling) {
            this.signaling.sendReaction(emoji);
        }
    }

    renderFloatingEmoji(emoji) {
        const container = document.getElementById('emojiContainer');
        if (!container) return;

        const el = document.createElement('div');
        el.className = 'flying-emoji';
        el.textContent = emoji;

        // Random horizontal drift
        const randomX = Math.floor(Math.random() * 80) - 20;
        el.style.left = `${randomX}px`;

        container.appendChild(el);
        setTimeout(() => el.remove(), 2500);
    }

    // =========================================================================
    // Live Captions (Web Speech API with Automatic Language Detection)
    // =========================================================================

    buildCaptionOverlayHtml(contentHtml) {
        return `
            <div style="display:flex;align-items:center;width:100%;gap:10px;">
                <span style="color:#60a5fa;font-weight:700;display:inline-flex;align-items:center;gap:4px;">
                    <i class="fas fa-closed-captioning"></i>
                </span>
                <div style="flex:1;text-align:left;line-height:1.4;">
                    ${contentHtml}
                </div>
            </div>
        `;
    }

    startSpeechRecognition() {
        const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRec) return;

        try {
            this.speechRecognizer = new SpeechRec();
            this.speechRecognizer.continuous = true;
            this.speechRecognizer.interimResults = true;
            // Automatically detect language from browser / user environment
            const detectedLang = navigator.language || (navigator.languages && navigator.languages[0]) || 'en-US';
            this.speechRecognizer.lang = detectedLang;

            this.speechRecognizer.onspeechstart = () => {
                if (this.isCaptionsActive) {
                    const overlay = document.getElementById('captionsOverlay');
                    if (overlay && (!overlay.classList.contains('active') || overlay.innerHTML === '')) {
                        overlay.style.display = 'flex';
                        overlay.classList.add('active');
                        overlay.innerHTML = this.buildCaptionOverlayHtml(`<span style="color:#94a3b8;font-style:italic;">Listening...</span>`);
                    }
                }
            };

            this.speechRecognizer.onresult = (event) => {
                let interimTranscript = '';
                let finalTranscript = '';
                for (let i = event.resultIndex; i < event.results.length; ++i) {
                    const item = event.results[i];
                    if (item && item[0]) {
                        if (item.isFinal) {
                            finalTranscript += item[0].transcript;
                        } else {
                            interimTranscript += item[0].transcript;
                        }
                    }
                }
                const text = (finalTranscript || interimTranscript).trim();
                if (text) {
                    this.renderCaptionBanner(this.displayName, text);
                    if (this.signaling) {
                        this.signaling.sendCaption(text);
                    }
                }
            };

            this.speechRecognizer.onerror = (err) => {
                console.warn('Speech recognition notice:', err);
                if (err.error === 'not-allowed') {
                    this.showToast('Microphone access blocked for live captions.', 'error');
                }
            };

            this.speechRecognizer.onend = () => {
                // Auto-restart if user still has captions active
                if (this.isCaptionsActive && this.speechRecognizer) {
                    try {
                        this.speechRecognizer.start();
                    } catch (e) {}
                }
            };

            this.speechRecognizer.start();
        } catch (err) {
            console.error('Failed to start speech recognition:', err);
            this.showToast('Could not start live captions: ' + err.message, 'error');
        }
    }

    showCaptionsListeningStatus() {
        if (!this.isCaptionsActive) return;
        const overlay = document.getElementById('captionsOverlay');
        if (!overlay) return;

        overlay.style.display = 'flex';
        overlay.classList.add('active');
        const content = `<span style="color:#94a3b8;font-style:italic;display:inline-flex;align-items:center;gap:8px;"><i class="fas fa-circle" style="color:#10b981;font-size:0.6rem;animation:pulseRed 1.2s infinite;"></i> Captions on · Listening for speech...</span>`;
        overlay.innerHTML = this.buildCaptionOverlayHtml(content);

        clearTimeout(this.captionTimeout);
        this.captionTimeout = setTimeout(() => {
            if (this.isCaptionsActive && overlay && overlay.textContent.includes('Listening')) {
                overlay.classList.remove('active');
                overlay.style.display = 'none';
                overlay.innerHTML = '';
            }
        }, 5000);
    }

    toggleCaptions() {
        const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
        const btn = document.getElementById('ctrlToggleCaptions');
        const overlay = document.getElementById('captionsOverlay');

        if (!SpeechRec) {
            this.showToast('Live Captions: Web Speech API is not supported in this browser. Please use Chrome or Edge.', 'error');
            return;
        }

        this.isCaptionsActive = !this.isCaptionsActive;
        if (btn) btn.classList.toggle('active', this.isCaptionsActive);

        if (this.isCaptionsActive) {
            this.startSpeechRecognition();
            this.showCaptionsListeningStatus();
            this.showToast('Live captions enabled (Auto-detect Language)', 'success');
        } else {
            if (this.speechRecognizer) {
                try {
                    this.speechRecognizer.stop();
                } catch (e) {}
                this.speechRecognizer = null;
            }
            if (overlay) {
                overlay.classList.remove('active');
                overlay.style.display = 'none';
                overlay.innerHTML = '';
            }
            this.showToast('Live captions disabled', 'info');
        }
    }

    renderCaptionBanner(speaker, text) {
        const overlay = document.getElementById('captionsOverlay');
        // STRICT PERSONAL VIEW: If this user has not turned on captions, never show anything!
        if (!this.isCaptionsActive) {
            if (overlay) {
                overlay.classList.remove('active');
                overlay.style.display = 'none';
                overlay.innerHTML = '';
            }
            return;
        }

        if (!overlay) return;

        overlay.style.display = 'flex';
        overlay.classList.add('active');
        const content = `<span style="color:#60a5fa;font-weight:600;">${this.escapeHtml(speaker)}:</span> ${this.escapeHtml(text)}`;
        overlay.innerHTML = this.buildCaptionOverlayHtml(content);

        clearTimeout(this.captionTimeout);
        // Automatically hide the banner after 4 seconds of silence
        this.captionTimeout = setTimeout(() => {
            if (overlay) {
                overlay.classList.remove('active');
                overlay.style.display = 'none';
                overlay.innerHTML = '';
            }
        }, 4000);
    }

    // =========================================================================
    // Local In-Browser Meeting Recording (MediaRecorder)
    // =========================================================================
    // =========================================================================
    // Local In-Browser Meeting Recording (MediaRecorder with Audio Mixing)
    // =========================================================================
    prepareRecordingStream() {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        let audioCtx = null;
        let mixedAudioTrack = null;

        // 1. Identify primary video track (screen share takes priority, then local camera, then remote presenter)
        let videoTrack = null;
        if (this.isScreenSharing && this.webrtc.screenStream && this.webrtc.screenStream.getVideoTracks().length > 0) {
            videoTrack = this.webrtc.screenStream.getVideoTracks()[0];
        } else if (this.webrtc.localStream && this.webrtc.localStream.getVideoTracks().length > 0) {
            videoTrack = this.webrtc.localStream.getVideoTracks()[0];
        }

        if (!videoTrack) {
            const remoteVideo = document.querySelector('.video-tile:not(.local) video');
            if (remoteVideo && remoteVideo.srcObject && remoteVideo.srcObject.getVideoTracks().length > 0) {
                videoTrack = remoteVideo.srcObject.getVideoTracks()[0];
            }
        }

        // 2. Mix all audio sources (Mic, Screen Audio, Remote Peers) into a single high-fidelity track
        if (AudioCtx) {
            try {
                audioCtx = new AudioCtx();
                const dest = audioCtx.createMediaStreamDestination();
                let hasAudioSource = false;

                // A. Local microphone audio (User's speech)
                if (this.webrtc.localStream && this.webrtc.localStream.getAudioTracks().length > 0) {
                    const micTrack = this.webrtc.localStream.getAudioTracks()[0];
                    try {
                        const micSource = audioCtx.createMediaStreamSource(new MediaStream([micTrack]));
                        micSource.connect(dest);
                        hasAudioSource = true;
                    } catch (e) {
                        console.warn('Could not attach local mic to recording mixer:', e);
                    }
                }

                // B. Screen sharing system / tab audio
                if (this.webrtc.screenStream && this.webrtc.screenStream.getAudioTracks().length > 0) {
                    this.webrtc.screenStream.getAudioTracks().forEach(track => {
                        try {
                            const screenAudioSource = audioCtx.createMediaStreamSource(new MediaStream([track]));
                            screenAudioSource.connect(dest);
                            hasAudioSource = true;
                        } catch (e) {
                            console.warn('Could not attach screen audio to recording mixer:', e);
                        }
                    });
                }

                // C. Remote participants' speech
                document.querySelectorAll('video').forEach(videoEl => {
                    if (videoEl.id !== 'video_self' && videoEl.srcObject && videoEl.srcObject.getAudioTracks().length > 0) {
                        try {
                            const remoteAudioSource = audioCtx.createMediaStreamSource(videoEl.srcObject);
                            remoteAudioSource.connect(dest);
                            hasAudioSource = true;
                        } catch (e) {}
                    }
                });

                if (hasAudioSource && dest.stream.getAudioTracks().length > 0) {
                    mixedAudioTrack = dest.stream.getAudioTracks()[0];
                }
            } catch (err) {
                console.warn('Audio mixer initialization warning:', err);
            }
        }

        // Direct track fallback if Web Audio API mixer is unavailable
        if (!mixedAudioTrack) {
            if (this.webrtc.localStream && this.webrtc.localStream.getAudioTracks().length > 0) {
                mixedAudioTrack = this.webrtc.localStream.getAudioTracks()[0];
            } else if (this.webrtc.screenStream && this.webrtc.screenStream.getAudioTracks().length > 0) {
                mixedAudioTrack = this.webrtc.screenStream.getAudioTracks()[0];
            }
        }

        // Construct composite stream with synchronized video and audio
        const recordStream = new MediaStream();
        if (videoTrack) {
            recordStream.addTrack(videoTrack);
        }
        if (mixedAudioTrack) {
            recordStream.addTrack(mixedAudioTrack);
        }

        this.recordingAudioContext = audioCtx;
        return recordStream;
    }

    async toggleRecording() {
        const recBadge = document.getElementById('recBadge');
        const btn = document.getElementById('ctrlToggleRecord');

        if (this.isRecording) {
            this.stopRecording();
            this.isRecording = false;
            if (btn) {
                btn.classList.remove('active');
                btn.innerHTML = '<i class="fas fa-record-vinyl" style="color:#ef4444;"></i>';
                btn.title = 'Record Meeting (Screen & Audio)';
            }
            if (recBadge) recBadge.classList.remove('active');
            clearInterval(this.recordingTimer);
            this.showToast('Recording stopped. Preparing download...', 'info');
        } else {
            try {
                const recordStream = this.prepareRecordingStream();

                if (!recordStream || recordStream.getTracks().length === 0) {
                    alert('Cannot record: No active screen share, camera, or microphone stream found.');
                    return;
                }

                this.recordedChunks = [];
                const mimeType = MediaRecorder.isTypeSupported('video/webm;codecs=vp8,opus')
                    ? 'video/webm;codecs=vp8,opus'
                    : (MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus')
                        ? 'video/webm;codecs=vp9,opus'
                        : (MediaRecorder.isTypeSupported('video/webm') ? 'video/webm' : ''));

                const options = mimeType ? { mimeType } : {};
                this.mediaRecorder = new MediaRecorder(recordStream, options);

                this.mediaRecorder.ondataavailable = (e) => {
                    if (e.data && e.data.size > 0) {
                        this.recordedChunks.push(e.data);
                    }
                };

                this.mediaRecorder.onstop = () => {
                    const blob = new Blob(this.recordedChunks, { type: mimeType || 'video/webm' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.style.display = 'none';
                    a.href = url;
                    a.download = `ConnectMeet-Recording-${this.meetingCode}-${Date.now()}.webm`;
                    document.body.appendChild(a);
                    a.click();
                    setTimeout(() => {
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                    }, 2000);
                    this.showToast('Recording saved with sound!', 'success');
                };

                this.mediaRecorder.start(1000); // Sample chunk every 1 second
                this.isRecording = true;
                if (btn) {
                    btn.classList.add('active');
                    btn.innerHTML = '<i class="fas fa-stop" style="color:#fff;"></i>';
                    btn.title = 'Stop Recording';
                }
                if (recBadge) recBadge.classList.add('active');

                this.recordingStartTime = Date.now();
                this.recordingTimer = setInterval(() => {
                    const elapsed = Math.floor((Date.now() - this.recordingStartTime) / 1000);
                    const mins = String(Math.floor(elapsed / 60)).padStart(2, '0');
                    const secs = String(elapsed % 60).padStart(2, '0');
                    const recText = document.getElementById('recDuration');
                    if (recText) recText.textContent = `${mins}:${secs}`;
                }, 1000);

                const hasAudio = recordStream.getAudioTracks().length > 0;
                this.showToast(hasAudio ? 'Recording started with synchronized audio' : 'Recording started (Video only)', 'success');

            } catch (err) {
                console.warn('Recording error:', err);
                this.showToast('Failed to start recording: ' + err.message, 'error');
            }
        }
    }

    stopRecording() {
        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
            this.mediaRecorder.stop();
        }
        if (this.recordingAudioContext && this.recordingAudioContext.state !== 'closed') {
            try {
                this.recordingAudioContext.close();
            } catch (e) {}
            this.recordingAudioContext = null;
        }
    }

    // =========================================================================
    // Chat & Participants Drawers (Strictly Mutually Exclusive)
    // =========================================================================
    openDrawer(drawerName) {
        const chatDrawer = document.getElementById('chatDrawer');
        const partDrawer = document.getElementById('participantsDrawer');
        const chatBtn = document.getElementById('ctrlToggleChat');
        const partBtn = document.getElementById('ctrlToggleParticipants');

        // If clicking the currently active drawer, close it
        if (this.currentOpenDrawer === drawerName) {
            this.closeAllDrawers();
            return;
        }

        // Close all drawers first to guarantee strict one-by-one display
        if (chatDrawer) chatDrawer.classList.remove('open');
        if (partDrawer) partDrawer.classList.remove('open');
        if (chatBtn) chatBtn.classList.remove('active');
        if (partBtn) partBtn.classList.remove('active');

        if (drawerName === 'chat') {
            if (chatDrawer) chatDrawer.classList.add('open');
            if (chatBtn) chatBtn.classList.add('active');
            this.currentOpenDrawer = 'chat';
            this.isChatOpen = true;
            this.isParticipantsOpen = false;

            // Clear unread badge
            this.unreadMessagesCount = 0;
            const badge = document.getElementById('chatUnreadBadge');
            if (badge) badge.style.display = 'none';

            // Auto-focus chat input
            setTimeout(() => {
                const input = document.getElementById('chatMessageInput');
                if (input) input.focus();
            }, 100);
        } else if (drawerName === 'participants') {
            if (partDrawer) partDrawer.classList.add('open');
            if (partBtn) partBtn.classList.add('active');
            this.currentOpenDrawer = 'participants';
            this.isParticipantsOpen = true;
            this.isChatOpen = false;

            this.renderParticipantsList();
        }
    }

    closeAllDrawers() {
        const chatDrawer = document.getElementById('chatDrawer');
        const partDrawer = document.getElementById('participantsDrawer');
        const chatBtn = document.getElementById('ctrlToggleChat');
        const partBtn = document.getElementById('ctrlToggleParticipants');

        if (chatDrawer) chatDrawer.classList.remove('open');
        if (partDrawer) partDrawer.classList.remove('open');
        if (chatBtn) chatBtn.classList.remove('active');
        if (partBtn) partBtn.classList.remove('active');

        this.currentOpenDrawer = null;
        this.isChatOpen = false;
        this.isParticipantsOpen = false;
    }

    toggleChatDrawer() {
        this.openDrawer('chat');
    }

    toggleParticipantsDrawer() {
        this.openDrawer('participants');
    }

    async sendCurrentChatMessage() {
        const input = document.getElementById('chatMessageInput');
        if (!input) return;

        const text = input.value.trim();
        if (!text) return;

        input.value = '';
        try {
            await this.signaling.sendChatMessage(text);
        } catch (err) {
            this.showToast('Failed to send message: ' + err.message, 'error');
        }
    }

    appendChatMessage(msg) {
        const container = document.getElementById('chatMessagesContainer');
        if (!container) return;

        const isSelf = msg.participant_id === this.participantId;
        const msgDiv = document.createElement('div');
        msgDiv.className = `chat-msg ${isSelf ? 'self' : ''}`;

        const roleBadge = msg.role === 'host' ? '<span style="color:#60a5fa;">[Host]</span> ' : '';

        msgDiv.innerHTML = `
            <div class="chat-msg-meta">
                <span>${roleBadge}${this.escapeHtml(msg.display_name)}</span>
                <span>${msg.time_formatted || msg.created_at || ''}</span>
            </div>
            <div class="chat-msg-bubble">${this.escapeHtml(msg.message_content)}</div>
        `;

        container.appendChild(msgDiv);
        container.scrollTop = container.scrollHeight;
    }

    renderParticipantsList() {
        const container = document.getElementById('participantsListContainer');
        const countSpan = document.getElementById('participantsDrawerCount');
        const topCountSpan = document.getElementById('meetingParticipantCount');
        if (!container) return;

        const totalCount = 1 + this.participants.size;
        if (countSpan) countSpan.textContent = totalCount;
        if (topCountSpan) topCountSpan.textContent = `${totalCount} participant${totalCount > 1 ? 's' : ''}`;

        let html = '';

        // Add self
        html += `
            <div class="participant-item">
                <div class="participant-info">
                    <div class="participant-avatar-sm">${this.getInitials(this.displayName)}</div>
                    <div>
                        <div class="participant-name-text">${this.escapeHtml(this.displayName)} (You)</div>
                        <div style="font-size:0.75rem;color:var(--text-muted);">${this.isHost ? 'Meeting Host' : 'Participant'}</div>
                    </div>
                </div>
                <div class="participant-actions">
                    <span style="font-size:0.85rem;color:${this.audioEnabled ? '#10b981' : '#ef4444'};">
                        <i class="fas fa-${this.audioEnabled ? 'microphone' : 'microphone-slash'}"></i>
                    </span>
                </div>
            </div>
        `;

        // Add remote peers
        this.participants.forEach((p) => {
            const isPeerHost = p.role === 'host';
            html += `
                <div class="participant-item">
                    <div class="participant-info">
                        <div class="participant-avatar-sm">${this.getInitials(p.display_name)}</div>
                        <div>
                            <div class="participant-name-text">${this.escapeHtml(p.display_name)}</div>
                            <div style="font-size:0.75rem;color:var(--text-muted);">${isPeerHost ? 'Host' : 'Participant'}</div>
                        </div>
                    </div>
                    <div class="participant-actions">
                        <span style="font-size:0.85rem;color:${p.audio_enabled ? '#10b981' : '#ef4444'};">
                            <i class="fas fa-${p.audio_enabled ? 'microphone' : 'microphone-slash'}"></i>
                        </span>
                        ${this.isHost ? `
                            <button class="btn btn-secondary btn-icon" style="width:28px;height:28px;font-size:0.75rem;" title="Remove participant" onclick="window.meetingApp.kickPeer(${p.id})">
                                <i class="fas fa-user-times"></i>
                            </button>
                        ` : ''}
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
    }

    async kickPeer(peerId) {
        if (!confirm('Are you sure you want to remove this participant from the meeting?')) return;
        await this.signaling.kickParticipant(peerId);
    }

    // =========================================================================
    // Leave & End Meeting Flow
    // =========================================================================
    promptLeaveOrEndMeeting() {
        if (this.isHost) {
            const modal = document.getElementById('leaveModal');
            if (modal) {
                modal.classList.add('open');
                return;
            }
        }

        if (confirm('Are you sure you want to leave this meeting?')) {
            this.leaveMeeting();
        }
    }

    async leaveMeeting() {
        if (this.signaling) {
            await this.signaling.leaveMeeting();
        }
        this.cleanAndRedirect('index.php');
    }

    async endMeetingForEveryone() {
        const btn = document.querySelector('#leaveModal .btn-danger');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Ending...';
        }
        try {
            if (this.signaling) {
                const res = await this.signaling.endMeetingForEveryone();
                if (res && res.success === false) {
                    alert('Could not end meeting: ' + (res.message || 'Permission denied'));
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = 'End for Everyone';
                    }
                    return;
                }
            }
        } catch (e) {
            console.error('Error during end meeting request:', e);
        }

        this.cleanAndRedirect('index.php');
    }

    cleanAndRedirect(url) {
        if (this.webrtc) this.webrtc.destroy();
        if (this.signaling) this.signaling.stop();
        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
            this.mediaRecorder.stop();
        }
        window.location.href = url;
    }

    copyMeetingInviteLink() {
        const url = window.location.href.split('?')[0] + `?meeting=${this.meetingCode}`;
        navigator.clipboard.writeText(url).then(() => {
            this.showToast('Meeting link copied to clipboard!', 'success');
        }).catch(() => {
            prompt('Copy meeting link:', url);
        });
    }

    openSettingsModal() {
        const modal = document.getElementById('settingsModal');
        if (!modal) return;

        const micSelect = document.getElementById('settingMicSelect');
        const camSelect = document.getElementById('settingCamSelect');
        const speakerSelect = document.getElementById('settingSpeakerSelect');

        this.settings.populateSelects(micSelect, camSelect, speakerSelect);

        modal.classList.add('open');

        const testToneBtn = document.getElementById('btnTestSpeaker');
        if (testToneBtn) {
            testToneBtn.onclick = () => this.settings.playTestTone();
        }

        const saveBtn = document.getElementById('btnSaveSettings');
        if (saveBtn) {
            saveBtn.onclick = async () => {
                const newAudioIn = micSelect.value;
                const newVideoIn = camSelect.value;
                const newAudioOut = speakerSelect.value;

                localStorage.setItem('cm_pref_audio_in', newAudioIn);
                localStorage.setItem('cm_pref_video_in', newVideoIn);

                await this.settings.setAudioOutput(newAudioOut, Array.from(document.querySelectorAll('video')));

                modal.classList.remove('open');
                this.showToast('Device settings updated', 'success');
            };
        }
    }

    startMeetingTimer() {
        const timerEl = document.getElementById('meetingTimer');
        if (!timerEl) return;
        const startTime = Date.now();
        setInterval(() => {
            const elapsed = Math.floor((Date.now() - startTime) / 1000);
            const mins = String(Math.floor(elapsed / 60)).padStart(2, '0');
            const secs = String(elapsed % 60).padStart(2, '0');
            timerEl.textContent = `${mins}:${secs}`;
        }, 1000);
    }

    setupKeyboardShortcuts() {
        window.addEventListener('keydown', (e) => {
            if (['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) return;

            const key = e.key.toUpperCase();
            if (key === 'M') {
                this.toggleMicrophone();
            } else if (key === 'V') {
                this.toggleCamera();
            } else if (key === 'H') {
                this.toggleRaiseHand();
            } else if (key === 'C') {
                this.toggleChatDrawer();
            } else if (key === 'P') {
                this.toggleParticipantsDrawer();
            } else if (e.key === 'Escape') {
                document.querySelectorAll('.modal-backdrop').forEach(m => m.classList.remove('open'));
                this.closeAllDrawers();
            }
        });
    }

    showToast(message, type = 'info') {
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

        let icon = 'info-circle';
        if (type === 'success') icon = 'check-circle';
        if (type === 'error') icon = 'exclamation-circle';

        toast.innerHTML = `<i class="fas fa-${icon}"></i><span>${this.escapeHtml(message)}</span>`;
        container.appendChild(toast);

        setTimeout(() => toast.remove(), 3500);
    }

    getInitials(name) {
        if (!name) return 'U';
        const parts = name.trim().split(/\s+/);
        if (parts.length >= 2) {
            return (parts[0][0] + parts[1][0]).toUpperCase();
        }
        return name.slice(0, 2).toUpperCase();
    }

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}

window.addEventListener('DOMContentLoaded', () => {
    window.meetingApp = new MeetingApp();
});
