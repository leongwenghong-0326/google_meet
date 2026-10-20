/**
 * ConnectMeet - WebRTC Signaling & Real-time Communication Client
 * Handles HTTP REST-based polling for SDP offers/answers, ICE candidates, events, and chat
 */

class SignalingClient {
    constructor(config = {}) {
        this.meetingId = config.meetingId || null;
        this.participantId = config.participantId || null;
        this.participantToken = config.participantToken || null;
        this.hostToken = config.hostToken || null;
        this.baseUrl = config.baseUrl || '';

        this.signalingIntervalMs = config.signalingIntervalMs || 1000;
        this.eventsIntervalMs = config.eventsIntervalMs || 1200;
        this.heartbeatIntervalMs = (config.heartbeatIntervalSec || 3) * 1000;
        this.chatIntervalMs = config.chatIntervalMs || 1500;

        this.lastEventId = 0;
        this.lastMessageId = 0;

        this.pollingTimers = {};
        this.isRunning = false;
        this.eventListeners = {};

        // Backoff and concurrency locks
        this.isPollingSignals = false;
        this.isPollingEvents = false;
        this.isPollingChat = false;
        this.isSendingHeartbeat = false;
    }

    /**
     * Resolve robust API URL whether baseUrl is set or empty
     */
    getApiUrl(endpoint) {
        if (this.baseUrl && this.baseUrl.trim() !== '') {
            return `${this.baseUrl.replace(/\/+$/, '')}/api/${endpoint}`;
        }
        return `api/${endpoint}`;
    }

    /**
     * Subscribe to signaling/system events
     */
    on(event, handler) {
        if (!this.eventListeners[event]) {
            this.eventListeners[event] = [];
        }
        this.eventListeners[event].push(handler);
    }

    /**
     * Dispatch event to listeners
     */
    emit(event, data) {
        const handlers = this.eventListeners[event] || [];
        handlers.forEach(fn => {
            try {
                fn(data);
            } catch (err) {
                console.error(`Error in event handler for ${event}:`, err);
            }
        });
    }

    /**
     * Initialize active polling routines
     */
    start() {
        if (this.isRunning) return;
        this.isRunning = true;

        this.pollSignalsLoop();
        this.pollEventsLoop();
        this.pollChatLoop();
        this.heartbeatLoop();
    }

    /**
     * Stop all polling and release timers
     */
    stop() {
        this.isRunning = false;
        Object.keys(this.pollingTimers).forEach(key => {
            clearTimeout(this.pollingTimers[key]);
            delete this.pollingTimers[key];
        });
    }

    /**
     * Send WebRTC signal (offer / answer / candidate)
     */
    async sendSignal(toParticipantId, signalType, payload) {
        if (!this.meetingId || !this.participantId || !this.participantToken) return;

        try {
            const res = await fetch(this.getApiUrl('signaling.php?action=send'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    meeting_id: this.meetingId,
                    from_participant_id: this.participantId,
                    to_participant_id: toParticipantId,
                    participant_token: this.participantToken,
                    signal_type: signalType,
                    signal_payload: payload
                })
            });
            const data = await res.json();
            return data;
        } catch (err) {
            console.error('Failed to send WebRTC signal:', err);
        }
    }

    /**
     * Poll incoming signals targeted to this client
     */
    async pollSignalsLoop() {
        if (!this.isRunning) return;

        if (!this.isPollingSignals) {
            this.isPollingSignals = true;
            try {
                const url = this.getApiUrl(`signaling.php?action=poll&meeting_id=${this.meetingId}&participant_id=${this.participantId}&participant_token=${encodeURIComponent(this.participantToken)}`);
                const res = await fetch(url);
                if (res.ok) {
                    const data = await res.json();
                    if (data.success && data.data && data.data.signals) {
                        data.data.signals.forEach(sig => {
                            this.emit('signal', sig);
                        });
                    }
                }
            } catch (err) {
                console.warn('Signaling poll request error:', err);
            } finally {
                this.isPollingSignals = false;
            }
        }

        if (this.isRunning) {
            this.pollingTimers.signals = setTimeout(() => this.pollSignalsLoop(), this.signalingIntervalMs);
        }
    }

    /**
     * Poll meeting events (joined, left, kicked, reactions, captions, etc.)
     */
    async pollEventsLoop() {
        if (!this.isRunning) return;

        if (!this.isPollingEvents) {
            this.isPollingEvents = true;
            try {
                const url = this.getApiUrl(`events.php?action=poll&meeting_id=${this.meetingId}&participant_id=${this.participantId}&participant_token=${encodeURIComponent(this.participantToken)}&last_event_id=${this.lastEventId}`);
                const res = await fetch(url);
                if (res.ok) {
                    const data = await res.json();
                    if (data.success && data.data) {
                        this.lastEventId = data.data.last_event_id;
                        (data.data.events || []).forEach(evt => {
                            this.emit(evt.event_type, evt.event_payload);
                            this.emit('event', evt);
                        });
                    }
                }
            } catch (err) {
                console.warn('Events poll request error:', err);
            } finally {
                this.isPollingEvents = false;
            }
        }

        if (this.isRunning) {
            this.pollingTimers.events = setTimeout(() => this.pollEventsLoop(), this.eventsIntervalMs);
        }
    }

    /**
     * Send heartbeat to keep session alive and synchronize participants
     */
    async heartbeatLoop() {
        if (!this.isRunning) return;

        if (!this.isSendingHeartbeat) {
            this.isSendingHeartbeat = true;
            try {
                const res = await fetch(this.getApiUrl('participants.php?action=heartbeat'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        meeting_id: this.meetingId,
                        participant_id: this.participantId,
                        participant_token: this.participantToken
                    })
                });
                if (res.ok) {
                    const data = await res.json();
                    if (data.success && data.data) {
                        if (data.data.meeting_status === 'ended') {
                            this.emit('meeting_ended', { reason: 'Meeting ended by host' });
                            this.stop();
                            return;
                        }
                        if (data.data.participants) {
                            this.emit('participants_sync', data.data.participants);
                        }
                    }
                } else if (res.status === 401 || res.status === 410) {
                    this.emit('meeting_ended', { reason: 'Meeting ended' });
                    this.stop();
                    return;
                }
            } catch (err) {
                console.warn('Heartbeat error:', err);
            } finally {
                this.isSendingHeartbeat = false;
            }
        }

        if (this.isRunning) {
            this.pollingTimers.heartbeat = setTimeout(() => this.heartbeatLoop(), this.heartbeatIntervalMs);
        }
    }

    /**
     * Poll in-meeting chat messages
     */
    async pollChatLoop() {
        if (!this.isRunning) return;

        if (!this.isPollingChat) {
            this.isPollingChat = true;
            try {
                const url = this.getApiUrl(`chat.php?action=poll&meeting_id=${this.meetingId}&participant_id=${this.participantId}&participant_token=${encodeURIComponent(this.participantToken)}&last_message_id=${this.lastMessageId}`);
                const res = await fetch(url);
                if (res.ok) {
                    const data = await res.json();
                    if (data.success && data.data) {
                        this.lastMessageId = data.data.last_message_id;
                        (data.data.messages || []).forEach(msg => {
                            this.emit('chat_message', msg);
                        });
                    }
                }
            } catch (err) {
                console.warn('Chat poll error:', err);
            } finally {
                this.isPollingChat = false;
            }
        }

        if (this.isRunning) {
            this.pollingTimers.chat = setTimeout(() => this.pollChatLoop(), this.chatIntervalMs);
        }
    }

    /**
     * Send chat message
     */
    async sendChatMessage(message) {
        try {
            const res = await fetch(this.getApiUrl('chat.php?action=send'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    meeting_id: this.meetingId,
                    participant_id: this.participantId,
                    participant_token: this.participantToken,
                    message: message
                })
            });
            return await res.json();
        } catch (err) {
            console.error('Failed to send chat message:', err);
            throw err;
        }
    }

    /**
     * Send reaction
     */
    async sendReaction(emoji) {
        try {
            const res = await fetch(this.getApiUrl('events.php?action=send_reaction'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    meeting_id: this.meetingId,
                    participant_id: this.participantId,
                    participant_token: this.participantToken,
                    emoji: emoji
                })
            });
            return await res.json();
        } catch (err) {
            console.error('Failed to send reaction:', err);
        }
    }

    /**
     * Send caption
     */
    async sendCaption(text) {
        try {
            const res = await fetch(this.getApiUrl('events.php?action=send_caption'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    meeting_id: this.meetingId,
                    participant_id: this.participantId,
                    participant_token: this.participantToken,
                    caption_text: text
                })
            });
            return await res.json();
        } catch (err) {
            console.error('Failed to broadcast caption:', err);
        }
    }

    /**
     * Update participant media / hand state
     */
    async updateState(updates) {
        try {
            const res = await fetch(this.getApiUrl('participants.php?action=update_state'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    meeting_id: this.meetingId,
                    participant_id: this.participantId,
                    participant_token: this.participantToken,
                    ...updates
                })
            });
            return await res.json();
        } catch (err) {
            console.error('Failed to update participant state:', err);
        }
    }

    /**
     * Leave meeting
     */
    async leaveMeeting() {
        this.stop();
        try {
            await fetch(this.getApiUrl('participants.php?action=leave'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    meeting_id: this.meetingId,
                    participant_id: this.participantId,
                    participant_token: this.participantToken
                })
            });
        } catch (err) {
            console.warn('Error sending leave request:', err);
        }
    }

    /**
     * Host kicks a participant
     */
    async kickParticipant(targetParticipantId) {
        if (!this.hostToken) return;
        try {
            const res = await fetch(this.getApiUrl('participants.php?action=kick'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    meeting_id: this.meetingId,
                    target_participant_id: targetParticipantId,
                    host_token: this.hostToken
                })
            });
            return await res.json();
        } catch (err) {
            console.error('Failed to kick participant:', err);
        }
    }

    /**
     * Host ends meeting for everyone
     */
    async endMeetingForEveryone() {
        try {
            const res = await fetch(this.getApiUrl('meetings.php?action=end_meeting'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    meeting_id: this.meetingId,
                    host_token: this.hostToken,
                    participant_id: this.participantId,
                    participant_token: this.participantToken
                })
            });
            return await res.json();
        } catch (err) {
            console.error('Failed to end meeting:', err);
        }
    }

    /**
     * Host toggles room lock
     */
    async toggleRoomLock(isLocked) {
        if (!this.hostToken) return;
        try {
            const res = await fetch(this.getApiUrl('meetings.php?action=toggle_lock'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    meeting_id: this.meetingId,
                    host_token: this.hostToken,
                    is_locked: isLocked ? 1 : 0
                })
            });
            return await res.json();
        } catch (err) {
            console.error('Failed to toggle room lock:', err);
        }
    }
}

window.SignalingClient = SignalingClient;
