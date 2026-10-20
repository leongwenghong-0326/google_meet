<?php
/**
 * ConnectMeet - Pre-meeting Lobby & In-Meeting Stage
 */
require_once __DIR__ . '/includes/bootstrap.php';

$meetingCode = $_GET['meeting'] ?? '';
$meetingCode = normalizeMeetingCode($meetingCode);
$hostToken = $_GET['host_token'] ?? '';

if (empty($meetingCode)) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <script>
        if (location.protocol === 'http:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            location.replace('https:' + location.href.substring(5));
        }
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting - ConnectMeet</title>
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom Styles -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <style>
        /* Floating Reactions Popover */
        .reactions-popover {
            position: absolute;
            bottom: 84px;
            background: var(--bg-surface);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-full);
            padding: 6px 12px;
            display: none;
            gap: 8px;
            box-shadow: var(--shadow-elevated);
            z-index: 50;
        }
        .reaction-emoji-btn {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            transition: transform 0.15s ease;
            padding: 4px;
        }
        .reaction-emoji-btn:hover {
            transform: scale(1.3);
        }
    </style>
</head>
<body>

    <!-- ===================================================================
         PHASE 1: PRE-MEETING LOBBY (Green Room)
         =================================================================== -->
    <div id="lobbyView" class="lobby-container">
        <div class="lobby-card">
            <!-- Left: Video & Audio Preview -->
            <div class="lobby-preview-wrapper">
                <div class="lobby-video-container">
                    <video id="lobbyLocalVideo" autoplay muted playsinline></video>
                    <div id="lobbyAvatar" class="lobby-avatar-placeholder" style="display:none;">
                        <div class="lobby-avatar-icon">CM</div>
                        <span style="font-size:0.9rem;color:var(--text-secondary);">Camera is off</span>
                    </div>

                    <!-- Media control toggles inside preview -->
                    <div class="lobby-controls-bar">
                        <button type="button" id="lobbyToggleMic" class="lobby-media-btn" title="Toggle Microphone">
                            <i class="fas fa-microphone"></i>
                        </button>
                        <button type="button" id="lobbyToggleCam" class="lobby-media-btn" title="Toggle Camera">
                            <i class="fas fa-video"></i>
                        </button>
                    </div>
                </div>

                <!-- Microphone Activity Meter -->
                <div>
                    <div style="display:flex;justify-content:space-between;font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;">
                        <span>Microphone Level</span>
                        <i class="fas fa-volume-up"></i>
                    </div>
                    <div class="lobby-audio-meter">
                        <div id="lobbyAudioMeterFill" class="lobby-audio-meter-fill"></div>
                    </div>
                </div>
            </div>

            <!-- Right: Meeting Info & Join Action -->
            <div class="lobby-join-panel">
                <div>
                    <h1 id="lobbyMeetingTitle" class="lobby-meeting-title">Ready to join?</h1>
                    <div style="margin-top:8px;">
                        <span class="lobby-meeting-code-badge">
                            <i class="fas fa-hashtag"></i> <span id="lobbyMeetingCode"><?php echo escapeHtml($meetingCode); ?></span>
                        </span>
                    </div>
                </div>

                <div>
                    <label for="lobbyDisplayName" style="display:block;font-size:0.85rem;color:var(--text-secondary);margin-bottom:6px;">
                        What's your name?
                    </label>
                    <input type="text" id="lobbyDisplayName" class="form-input" placeholder="Your name (e.g. Alex Rivera)" maxlength="50" required>
                </div>

                <div style="display:flex;flex-direction:column;gap:12px;">
                    <button type="button" id="btnJoinNow" class="btn btn-primary" style="padding:14px;">
                        <i class="fas fa-door-open"></i> Join Now
                    </button>
                    <button type="button" id="btnPresentNow" class="btn btn-secondary">
                        <i class="fas fa-desktop"></i> Present / Share Screen
                    </button>
                </div>

                <p style="font-size:0.8rem;color:var(--text-muted);text-align:center;">
                    No one will see or hear you until you join the room.
                </p>
            </div>
        </div>
    </div>

    <!-- ===================================================================
         PHASE 2: IN-MEETING INTERFACE (Conference Room Stage)
         =================================================================== -->
    <div id="meetingView" class="meeting-layout" style="display:none;">
        
        <!-- Top Info Header -->
        <header class="meeting-top-bar">
            <div class="top-bar-left">
                <div style="display:flex;align-items:center;gap:8px;font-weight:700;">
                    <div class="brand-logo-icon" style="width:28px;height:28px;">
                        <i class="fas fa-video fa-xs"></i>
                    </div>
                    <span>ConnectMeet</span>
                </div>
                <div id="meetingTimer" class="meeting-timer">00:00</div>
                <div class="meeting-code-pill" id="roomMeetingCode" title="Click to copy code">
                    <?php echo escapeHtml($meetingCode); ?>
                </div>
            </div>

            <div class="top-bar-right">
                <!-- Recording indicator -->
                <div id="recBadge" class="rec-badge">
                    <span class="rec-dot"></span>
                    <span>REC</span>
                    <span id="recDuration" style="font-family:monospace;font-size:0.75rem;">00:00</span>
                </div>

                <!-- Participant count -->
                <span id="meetingParticipantCount" style="font-size:0.85rem;color:var(--text-secondary);">
                    1 participant
                </span>
            </div>
        </header>

        <!-- Main Video Grid Stage Area -->
        <main class="meeting-stage" id="meetingStage">
            <div class="video-grid-wrapper">
                <div id="videoGrid" class="video-grid" data-count="1">
                    <!-- Video tiles will be dynamically injected here -->
                </div>
            </div>

            <!-- Floating Captions Banner -->
            <div id="captionsOverlay" class="captions-overlay"></div>

            <!-- Floating Emoji Particle Container -->
            <div id="emojiContainer" class="emoji-fly-container"></div>

            <!-- Side Drawer: Chat -->
            <aside id="chatDrawer" class="side-drawer" style="display:none;">
                <div class="drawer-header">
                    <span class="drawer-title"><i class="fas fa-comments"></i> In-Call Messages</span>
                    <button type="button" class="drawer-close-btn" id="closeChatDrawerBtn" title="Close Chat"><i class="fas fa-times"></i></button>
                </div>
                <div class="drawer-body" id="chatMessagesContainer">
                    <p style="font-size:0.8rem;color:var(--text-muted);text-align:center;margin-top:20px;">
                        Messages are shared with everyone in the call.
                    </p>
                </div>
                <div class="drawer-footer">
                    <form id="chatForm" class="chat-input-form">
                        <input type="text" id="chatMessageInput" class="form-input" placeholder="Send a message to everyone..." maxlength="1000" autocomplete="off">
                        <button type="submit" class="btn btn-primary btn-icon" style="flex-shrink:0;">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>
            </aside>

            <!-- Side Drawer: Participants -->
            <aside id="participantsDrawer" class="side-drawer" style="display:none;">
                <div class="drawer-header">
                    <span class="drawer-title">
                        <i class="fas fa-users"></i> People (<span id="participantsDrawerCount">1</span>)
                    </span>
                    <button type="button" class="drawer-close-btn" id="closeParticipantsDrawerBtn" title="Close People"><i class="fas fa-times"></i></button>
                </div>
                <div class="drawer-body" id="participantsListContainer">
                    <!-- Participant list items injected here -->
                </div>
            </aside>
        </main>

        <!-- Bottom Meeting Control Toolbar -->
        <footer class="meeting-bottom-bar">
            <!-- Left Info -->
            <div class="bottom-left-info">
                <span>ConnectMeet P2P</span>
            </div>

            <!-- Center Controls -->
            <div class="controls-cluster">
                <!-- Microphone Toggle -->
                <button type="button" id="ctrlToggleMic" class="ctrl-btn" title="Toggle Microphone (M)">
                    <i class="fas fa-microphone"></i>
                </button>

                <!-- Camera Toggle -->
                <button type="button" id="ctrlToggleCam" class="ctrl-btn" title="Toggle Camera (V)">
                    <i class="fas fa-video"></i>
                </button>

                <!-- Screen Sharing -->
                <button type="button" id="ctrlToggleScreen" class="ctrl-btn" title="Share Screen">
                    <i class="fas fa-desktop"></i>
                </button>

                <!-- Raise Hand -->
                <button type="button" id="ctrlToggleHand" class="ctrl-btn" title="Raise Hand (H)">
                    <i class="fas fa-hand-paper"></i>
                </button>

                <!-- Emoji Reactions -->
                <div style="position:relative;">
                    <button type="button" id="ctrlReactionsBtn" class="ctrl-btn" title="Reactions">
                        <i class="fas fa-smile"></i>
                    </button>
                    <div id="reactionsPopover" class="reactions-popover">
                        <button type="button" class="reaction-emoji-btn" data-emoji="👍">👍</button>
                        <button type="button" class="reaction-emoji-btn" data-emoji="👏">👏</button>
                        <button type="button" class="reaction-emoji-btn" data-emoji="❤️">❤️</button>
                        <button type="button" class="reaction-emoji-btn" data-emoji="🎉">🎉</button>
                        <button type="button" class="reaction-emoji-btn" data-emoji="😂">😂</button>
                        <button type="button" class="reaction-emoji-btn" data-emoji="😮">😮</button>
                        <button type="button" class="reaction-emoji-btn" data-emoji="🔥">🔥</button>
                    </div>
                </div>

                <!-- Captions -->
                <button type="button" id="ctrlToggleCaptions" class="ctrl-btn" title="Live Captions (Web Speech API)">
                    <i class="fas fa-closed-captioning"></i>
                </button>

                <!-- Local Recording -->
                <button type="button" id="ctrlToggleRecord" class="ctrl-btn" title="Record Meeting (Local Audio/Video)">
                    <i class="fas fa-record-vinyl" style="color:#ef4444;"></i>
                </button>

                <!-- Leave Call -->
                <button type="button" id="ctrlLeaveMeeting" class="ctrl-btn btn-leave" title="Leave Meeting">
                    <i class="fas fa-phone-slash"></i> Leave
                </button>
            </div>

            <!-- Right Actions (Chat, People, Settings) -->
            <div class="bottom-right-actions">
                <!-- Copy Meeting Link -->
                <button type="button" id="ctrlMeetingInfo" class="ctrl-btn" title="Copy Meeting Info">
                    <i class="fas fa-info-circle"></i>
                </button>

                <!-- Chat Drawer Button -->
                <button type="button" id="ctrlToggleChat" class="ctrl-btn" title="Chat (C)">
                    <i class="fas fa-comment-alt"></i>
                    <span id="chatUnreadBadge" class="badge-counter" style="display:none;">0</span>
                </button>

                <!-- Participants Drawer Button -->
                <button type="button" id="ctrlToggleParticipants" class="ctrl-btn" title="Participants (P)">
                    <i class="fas fa-user-friends"></i>
                </button>

                <!-- Device Settings -->
                <button type="button" id="ctrlOpenSettings" class="ctrl-btn" title="Settings">
                    <i class="fas fa-cog"></i>
                </button>
            </div>
        </footer>
    </div>

    <!-- ===================================================================
         MODALS & POPUPS
         =================================================================== -->
    
    <!-- Modal: Device Settings -->
    <div class="modal-backdrop" id="settingsModal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-sliders-h"></i> Audio & Video Settings</h3>
                <button type="button" class="btn btn-secondary btn-icon modal-close" style="width:32px;height:32px;" title="Close" onclick="document.querySelectorAll('.modal-backdrop').forEach(m => m.classList.remove('open'))"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div>
                    <label for="settingMicSelect" style="display:block;font-size:0.85rem;color:var(--text-secondary);margin-bottom:6px;">
                        <i class="fas fa-microphone"></i> Microphone
                    </label>
                    <select id="settingMicSelect" class="form-input"></select>
                </div>

                <div>
                    <label for="settingCamSelect" style="display:block;font-size:0.85rem;color:var(--text-secondary);margin-bottom:6px;">
                        <i class="fas fa-video"></i> Camera
                    </label>
                    <select id="settingCamSelect" class="form-input"></select>
                </div>

                <div>
                    <label for="settingSpeakerSelect" style="display:block;font-size:0.85rem;color:var(--text-secondary);margin-bottom:6px;">
                        <i class="fas fa-volume-up"></i> Speaker / Audio Output
                    </label>
                    <div style="display:flex;gap:8px;">
                        <select id="settingSpeakerSelect" class="form-input"></select>
                        <button type="button" id="btnTestSpeaker" class="btn btn-secondary" style="flex-shrink:0;">
                            Test
                        </button>
                    </div>
                </div>

                <div>
                    <label style="display:block;font-size:0.85rem;color:var(--text-secondary);margin-bottom:6px;">
                        <i class="fas fa-language"></i> Live Caption Language
                    </label>
                    <div style="padding:10px 14px;background:rgba(255,255,255,0.04);border:1px solid var(--border-glass);border-radius:var(--radius-sm);color:var(--text-secondary);font-size:0.88rem;display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-wand-magic-sparkles" style="color:#60a5fa;"></i>
                        <span>Auto-detect spoken language</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary modal-close" onclick="document.querySelectorAll('.modal-backdrop').forEach(m => m.classList.remove('open'))">Cancel</button>
                <button type="button" id="btnSaveSettings" class="btn btn-primary">Apply</button>
            </div>
        </div>
    </div>

    <!-- Modal: Leave or End Meeting (Host Only) -->
    <div class="modal-backdrop" id="leaveModal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">Leave Meeting</h3>
                <button type="button" class="btn btn-secondary btn-icon modal-close" style="width:32px;height:32px;" title="Close" onclick="document.querySelectorAll('.modal-backdrop').forEach(m => m.classList.remove('open'))"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <p style="color:var(--text-secondary);font-size:0.95rem;">
                    You are the host of this meeting. Would you like to leave alone or end the session for all participants?
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary modal-close" onclick="document.querySelectorAll('.modal-backdrop').forEach(m => m.classList.remove('open'))">Cancel</button>
                <button type="button" class="btn btn-secondary" onclick="window.meetingApp.leaveMeeting()">Just Leave</button>
                <button type="button" class="btn btn-danger" onclick="window.meetingApp.endMeetingForEveryone()">End for Everyone</button>
            </div>
        </div>
    </div>

    <!-- Toast Notifications Mount Point -->
    <div id="toastContainer" class="toast-container"></div>

    <!-- Frontend Core Modules -->
    <script src="assets/js/signaling.js"></script>
    <script src="assets/js/webrtc.js"></script>
    <script src="assets/js/settings.js"></script>
    <script src="assets/js/meeting.js"></script>
</body>
</html>
