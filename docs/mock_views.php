<?php
/**
 * Screenshot Stage Renderer for Documentation (Zero Human Faces / Avatars Only)
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$view = $_GET['view'] ?? 'lobby';
$meetingCode = 'meet-demo-2026';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ConnectMeet Screenshot Preview</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <style>
        body { margin: 0; padding: 0; background: var(--bg-dark); color: var(--text-primary); overflow: hidden; height: 100vh; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .avatar-circle { width: 96px; height: 96px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.2rem; font-weight: 700; color: #fff; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
        .avatar-blue { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
        .avatar-purple { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
        .avatar-teal { background: linear-gradient(135deg, #0d9488, #0f766e); }
        .avatar-tile { background: var(--bg-surface); border: 1px solid var(--border-glass); border-radius: 12px; display: flex; flex-direction: column; align-items: center; justify-content: center; position: relative; overflow: hidden; }
        .tile-name-tag { position: absolute; bottom: 12px; left: 14px; background: rgba(0,0,0,0.65); backdrop-filter: blur(8px); padding: 4px 10px; border-radius: 6px; font-size: 0.85rem; display: flex; align-items: center; gap: 6px; }
        .screen-share-content { width: 100%; height: 100%; background: #0f172a; border-radius: 12px; border: 1px solid #334155; display: flex; flex-direction: column; overflow: hidden; }
        .code-header { background: #1e293b; padding: 10px 16px; display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #94a3b8; border-bottom: 1px solid #334155; }
        .code-body { padding: 20px; font-family: Consolas, monospace; font-size: 0.95rem; color: #e2e8f0; line-height: 1.6; }
    </style>
</head>
<body>

<?php if ($view === 'lobby'): ?>
    <!-- 02-lobby: Pre-meeting Lobby with Camera Off Placeholder -->
    <div class="lobby-container" style="display:flex;">
        <div class="lobby-card">
            <div class="lobby-preview-wrapper">
                <div class="lobby-video-container">
                    <div class="lobby-avatar-placeholder" style="display:flex;">
                        <div class="avatar-circle avatar-blue" style="width:84px;height:84px;font-size:1.8rem;margin-bottom:12px;">AR</div>
                        <span style="font-size:0.95rem;color:var(--text-secondary);font-weight:500;">Camera is off</span>
                    </div>
                    <div class="lobby-controls-bar">
                        <button type="button" class="lobby-media-btn" style="background:rgba(255,255,255,0.1);"><i class="fas fa-microphone"></i></button>
                        <button type="button" class="lobby-media-btn" style="background:#ef4444;"><i class="fas fa-video-slash"></i></button>
                    </div>
                </div>
                <div>
                    <div style="display:flex;justify-content:space-between;font-size:0.75rem;color:var(--text-muted);margin-bottom:6px;">
                        <span>Microphone Level (Testing Active)</span>
                        <i class="fas fa-volume-up" style="color:#22c55e;"></i>
                    </div>
                    <div class="lobby-audio-meter">
                        <div class="lobby-audio-meter-fill" style="width: 65%; background: #22c55e;"></div>
                    </div>
                </div>
            </div>
            <div class="lobby-join-panel">
                <div>
                    <h1 class="lobby-meeting-title">Ready to join?</h1>
                    <div style="margin-top:8px;">
                        <span class="lobby-meeting-code-badge">
                            <i class="fas fa-hashtag"></i> <span>meet-demo-2026</span>
                        </span>
                    </div>
                </div>
                <div>
                    <label style="display:block;font-size:0.85rem;color:var(--text-secondary);margin-bottom:6px;">What's your name?</label>
                    <input type="text" class="form-input" value="Alex Rivera (Host)" style="border-color:#3b82f6;">
                </div>
                <div style="display:flex;flex-direction:column;gap:12px;">
                    <button type="button" class="btn btn-primary" style="padding:14px;font-size:1rem;"><i class="fas fa-door-open"></i> Join Now</button>
                    <button type="button" class="btn btn-secondary" style="padding:12px;"><i class="fas fa-desktop"></i> Present / Share Screen</button>
                </div>
                <p style="font-size:0.8rem;color:var(--text-muted);text-align:center;">No one will see or hear you until you join the room.</p>
            </div>
        </div>
    </div>

<?php elseif ($view === 'screenshare'): ?>
    <!-- 03-screen-sharing: Active presentation stage with avatar side tiles -->
    <div class="meeting-layout" style="display:flex;">
        <header class="meeting-top-bar">
            <div class="top-bar-left">
                <div style="display:flex;align-items:center;gap:8px;font-weight:700;"><div class="brand-logo-icon" style="width:28px;height:28px;"><i class="fas fa-video fa-xs"></i></div><span>ConnectMeet</span></div>
                <div class="meeting-timer">14:28</div>
                <div class="meeting-code-pill">meet-demo-2026</div>
            </div>
            <div class="top-bar-right">
                <div class="rec-badge" style="display:flex;"><span class="rec-dot"></span><span>REC</span><span>14:28</span></div>
                <span style="font-size:0.85rem;color:var(--text-secondary);"><i class="fas fa-users"></i> 3 participants</span>
            </div>
        </header>

        <main class="meeting-stage" style="display:flex;gap:16px;padding:16px;">
            <div style="flex:1;height:100%;">
                <div class="screen-share-content">
                    <div class="code-header">
                        <i class="fas fa-circle" style="color:#ef4444;font-size:10px;"></i>
                        <i class="fas fa-circle" style="color:#f59e0b;font-size:10px;"></i>
                        <i class="fas fa-circle" style="color:#10b981;font-size:10px;"></i>
                        <span style="margin-left:12px;font-weight:600;"><i class="fas fa-desktop" style="color:#60a5fa;"></i> Alex Rivera is presenting screen</span>
                    </div>
                    <div class="code-body">
                        <p style="color:#93c5fd;font-weight:600;">// ConnectMeet WebRTC Mesh Conference Architecture</p>
                        <p><span style="color:#c084fc;">const</span> peerConnection = <span style="color:#fcd34d;">new</span> RTCPeerConnection(rtcConfiguration);</p>
                        <p>peerConnection.ontrack = (event) =&gt; { attachRemoteMediaStream(event.streams[0]); };</p>
                        <p style="color:#6ee7b7;">// Automatic Speech Recognition & Live Captioning Active</p>
                        <p><span style="color:#c084fc;">const</span> captionStream = <span style="color:#fcd34d;">new</span> WebSpeechRecognition({ continuous: <span style="color:#fdba74;">true</span> });</p>
                        <div style="margin-top:24px;padding:14px;background:#1e293b;border-radius:8px;border-left:4px solid #3b82f6;">
                            <span style="color:#e2e8f0;font-weight:600;"><i class="fas fa-check-circle" style="color:#22c55e;"></i> Presentation Mode: 1080p 60fps Screen Streaming Enabled</span>
                        </div>
                    </div>
                </div>
            </div>
            <div style="width:260px;display:flex;flex-direction:column;gap:14px;">
                <div class="avatar-tile" style="flex:1;border:2px solid #22c55e;box-shadow:0 0 16px rgba(34,197,94,0.3);">
                    <div class="avatar-circle avatar-blue">AR</div>
                    <div class="tile-name-tag"><i class="fas fa-volume-up" style="color:#22c55e;"></i> Alex Rivera (Speaking)</div>
                </div>
                <div class="avatar-tile" style="flex:1;">
                    <div class="avatar-circle avatar-purple">JD</div>
                    <div class="tile-name-tag"><i class="fas fa-microphone-slash" style="color:#ef4444;"></i> Jordan Diaz</div>
                </div>
                <div class="avatar-tile" style="flex:1;">
                    <div class="avatar-circle avatar-teal">SC</div>
                    <div class="tile-name-tag"><i class="fas fa-microphone-slash" style="color:#ef4444;"></i> Sarah Chen</div>
                </div>
            </div>
        </main>

        <footer class="meeting-bottom-bar">
            <div class="bottom-left-info"><span>ConnectMeet P2P</span></div>
            <div class="controls-cluster">
                <button class="ctrl-btn" style="background:#3b82f6;"><i class="fas fa-microphone"></i></button>
                <button class="ctrl-btn" style="background:#ef4444;"><i class="fas fa-video-slash"></i></button>
                <button class="ctrl-btn" style="background:#22c55e;"><i class="fas fa-desktop"></i></button>
                <button class="ctrl-btn"><i class="fas fa-hand-paper"></i></button>
                <button class="ctrl-btn"><i class="fas fa-smile"></i></button>
                <button class="ctrl-btn"><i class="fas fa-closed-captioning"></i></button>
                <button class="ctrl-btn"><i class="fas fa-record-vinyl" style="color:#ef4444;"></i></button>
                <button class="ctrl-btn btn-leave"><i class="fas fa-phone-slash"></i> Leave</button>
            </div>
            <div class="bottom-right-actions">
                <button class="ctrl-btn"><i class="fas fa-comment-alt"></i></button>
                <button class="ctrl-btn"><i class="fas fa-user-friends"></i></button>
                <button class="ctrl-btn"><i class="fas fa-cog"></i></button>
            </div>
        </footer>
    </div>

<?php elseif ($view === 'captions'): ?>
    <!-- 04-live-captions: Grid with auto language detection captions subtitle overlay -->
    <div class="meeting-layout" style="display:flex;">
        <header class="meeting-top-bar">
            <div class="top-bar-left">
                <div style="display:flex;align-items:center;gap:8px;font-weight:700;"><div class="brand-logo-icon" style="width:28px;height:28px;"><i class="fas fa-video fa-xs"></i></div><span>ConnectMeet</span></div>
                <div class="meeting-timer">08:45</div>
                <div class="meeting-code-pill">meet-demo-2026</div>
            </div>
            <div class="top-bar-right">
                <span style="font-size:0.85rem;color:var(--text-secondary);"><i class="fas fa-users"></i> 3 participants</span>
            </div>
        </header>

        <main class="meeting-stage" style="display:flex;padding:16px;position:relative;">
            <div class="video-grid-wrapper" style="width:100%;height:100%;">
                <div class="video-grid" style="display:grid;grid-template-columns:repeat(3, 1fr);gap:16px;height:100%;">
                    <div class="avatar-tile" style="border:2px solid #22c55e;box-shadow:0 0 16px rgba(34,197,94,0.3);">
                        <div class="avatar-circle avatar-blue">AR</div>
                        <div class="tile-name-tag"><i class="fas fa-volume-up" style="color:#22c55e;"></i> Alex Rivera (Host)</div>
                    </div>
                    <div class="avatar-tile">
                        <div class="avatar-circle avatar-purple">JD</div>
                        <div class="tile-name-tag"><i class="fas fa-microphone-slash" style="color:#ef4444;"></i> Jordan Diaz</div>
                    </div>
                    <div class="avatar-tile">
                        <div class="avatar-circle avatar-teal">SC</div>
                        <div class="tile-name-tag"><i class="fas fa-microphone-slash" style="color:#ef4444;"></i> Sarah Chen</div>
                    </div>
                </div>
            </div>

            <!-- Smart Live Captions Banner -->
            <div class="captions-overlay" style="display:flex;bottom:30px;">
                <div class="captions-bubble" style="background:rgba(15,23,42,0.92);backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,0.15);padding:14px 24px;border-radius:12px;font-size:1.1rem;color:#f8fafc;box-shadow:0 8px 32px rgba(0,0,0,0.4);display:flex;align-items:center;gap:12px;">
                    <i class="fas fa-closed-captioning" style="color:#60a5fa;font-size:1.4rem;"></i>
                    <span><strong style="color:#60a5fa;">Alex Rivera:</strong> "Hello everyone! Welcome to our ConnectMeet review. All WebRTC endpoints are synced."</span>
                    <span style="font-size:0.75rem;background:#1e293b;padding:3px 8px;border-radius:6px;color:#94a3b8;border:1px solid #334155;">Auto-detected language</span>
                </div>
            </div>
        </main>

        <footer class="meeting-bottom-bar">
            <div class="bottom-left-info"><span>ConnectMeet P2P</span></div>
            <div class="controls-cluster">
                <button class="ctrl-btn" style="background:#3b82f6;"><i class="fas fa-microphone"></i></button>
                <button class="ctrl-btn" style="background:#ef4444;"><i class="fas fa-video-slash"></i></button>
                <button class="ctrl-btn"><i class="fas fa-desktop"></i></button>
                <button class="ctrl-btn"><i class="fas fa-hand-paper"></i></button>
                <button class="ctrl-btn"><i class="fas fa-smile"></i></button>
                <button class="ctrl-btn" style="background:#3b82f6;"><i class="fas fa-closed-captioning"></i></button>
                <button class="ctrl-btn"><i class="fas fa-record-vinyl" style="color:#ef4444;"></i></button>
                <button class="ctrl-btn btn-leave"><i class="fas fa-phone-slash"></i> Leave</button>
            </div>
            <div class="bottom-right-actions">
                <button class="ctrl-btn"><i class="fas fa-comment-alt"></i></button>
                <button class="ctrl-btn"><i class="fas fa-user-friends"></i></button>
                <button class="ctrl-btn"><i class="fas fa-cog"></i></button>
            </div>
        </footer>
    </div>

<?php elseif ($view === 'chat'): ?>
    <!-- 05-chat-and-reactions: Side drawer open with chat conversation & floating emoji particles -->
    <div class="meeting-layout" style="display:flex;">
        <header class="meeting-top-bar">
            <div class="top-bar-left">
                <div style="display:flex;align-items:center;gap:8px;font-weight:700;"><div class="brand-logo-icon" style="width:28px;height:28px;"><i class="fas fa-video fa-xs"></i></div><span>ConnectMeet</span></div>
                <div class="meeting-timer">05:12</div>
                <div class="meeting-code-pill">meet-demo-2026</div>
            </div>
            <div class="top-bar-right">
                <span style="font-size:0.85rem;color:var(--text-secondary);"><i class="fas fa-users"></i> 3 participants</span>
            </div>
        </header>

        <main class="meeting-stage" style="display:flex;padding:16px;gap:16px;position:relative;">
            <!-- Floating Animated Emojis -->
            <div style="position:absolute;bottom:40px;left:180px;font-size:2.8rem;filter:drop-shadow(0 4px 12px rgba(0,0,0,0.5));">👍</div>
            <div style="position:absolute;bottom:90px;left:260px;font-size:3rem;filter:drop-shadow(0 4px 12px rgba(0,0,0,0.5));">🎉</div>
            <div style="position:absolute;bottom:150px;left:210px;font-size:2.6rem;filter:drop-shadow(0 4px 12px rgba(0,0,0,0.5));">❤️</div>
            <div style="position:absolute;bottom:220px;left:290px;font-size:2.4rem;filter:drop-shadow(0 4px 12px rgba(0,0,0,0.5));">🔥</div>

            <!-- Video Tiles Area -->
            <div style="flex:1;display:grid;grid-template-columns:1fr 1fr;gap:16px;height:100%;">
                <div class="avatar-tile">
                    <div class="avatar-circle avatar-blue">AR</div>
                    <div class="tile-name-tag"><i class="fas fa-microphone"></i> Alex Rivera</div>
                </div>
                <div class="avatar-tile">
                    <div class="avatar-circle avatar-purple">JD</div>
                    <div class="tile-name-tag"><i class="fas fa-microphone"></i> Jordan Diaz</div>
                </div>
            </div>

            <!-- In-Call Messages Side Drawer -->
            <aside class="side-drawer" style="width:340px;display:flex;flex-direction:column;background:var(--bg-surface);border:1px solid var(--border-glass);border-radius:12px;overflow:hidden;">
                <div class="drawer-header" style="padding:16px;border-bottom:1px solid var(--border-glass);display:flex;justify-content:space-between;align-items:center;">
                    <span class="drawer-title" style="font-weight:600;font-size:1rem;"><i class="fas fa-comments" style="color:#60a5fa;margin-right:8px;"></i> In-Call Messages</span>
                    <button type="button" class="drawer-close-btn" style="background:transparent;border:none;color:#94a3b8;font-size:1.1rem;cursor:pointer;"><i class="fas fa-times"></i></button>
                </div>
                <div class="drawer-body" style="flex:1;padding:16px;display:flex;flex-direction:column;gap:14px;overflow-y:auto;">
                    <div style="background:rgba(255,255,255,0.04);padding:10px 12px;border-radius:8px;">
                        <div style="display:flex;justify-content:space-between;font-size:0.8rem;margin-bottom:4px;"><strong style="color:#60a5fa;">Alex Rivera</strong><span style="color:#94a3b8;">15:20</span></div>
                        <div style="font-size:0.9rem;color:#f1f5f9;">Hey team! Audio and video mesh are working smoothly!</div>
                    </div>
                    <div style="background:rgba(255,255,255,0.04);padding:10px 12px;border-radius:8px;">
                        <div style="display:flex;justify-content:space-between;font-size:0.8rem;margin-bottom:4px;"><strong style="color:#c084fc;">Jordan Diaz</strong><span style="color:#94a3b8;">15:21</span></div>
                        <div style="font-size:0.9rem;color:#f1f5f9;">Confirmed! Screen sharing is crisp and clear.</div>
                    </div>
                    <div style="background:rgba(255,255,255,0.04);padding:10px 12px;border-radius:8px;">
                        <div style="display:flex;justify-content:space-between;font-size:0.8rem;margin-bottom:4px;"><strong style="color:#2dd4bf;">Sarah Chen</strong><span style="color:#94a3b8;">15:22</span></div>
                        <div style="font-size:0.9rem;color:#f1f5f9;">Great job! The live captioning auto-detected my accent instantly.</div>
                    </div>
                </div>
                <div class="drawer-footer" style="padding:14px;border-top:1px solid var(--border-glass);">
                    <div style="display:flex;gap:8px;">
                        <input type="text" class="form-input" value="Sounds great! Let's wrap up." style="flex:1;">
                        <button type="button" class="btn btn-primary" style="padding:10px 14px;"><i class="fas fa-paper-plane"></i></button>
                    </div>
                </div>
            </aside>
        </main>

        <footer class="meeting-bottom-bar">
            <div class="bottom-left-info"><span>ConnectMeet P2P</span></div>
            <div class="controls-cluster">
                <button class="ctrl-btn"><i class="fas fa-microphone"></i></button>
                <button class="ctrl-btn"><i class="fas fa-video-slash"></i></button>
                <button class="ctrl-btn"><i class="fas fa-desktop"></i></button>
                <button class="ctrl-btn"><i class="fas fa-hand-paper"></i></button>
                <button class="ctrl-btn" style="background:#3b82f6;"><i class="fas fa-smile"></i></button>
                <button class="ctrl-btn"><i class="fas fa-closed-captioning"></i></button>
                <button class="ctrl-btn"><i class="fas fa-record-vinyl" style="color:#ef4444;"></i></button>
                <button class="ctrl-btn btn-leave"><i class="fas fa-phone-slash"></i> Leave</button>
            </div>
            <div class="bottom-right-actions">
                <button class="ctrl-btn" style="background:#3b82f6;"><i class="fas fa-comment-alt"></i></button>
                <button class="ctrl-btn"><i class="fas fa-user-friends"></i></button>
                <button class="ctrl-btn"><i class="fas fa-cog"></i></button>
            </div>
        </footer>
    </div>

<?php elseif ($view === 'hostcontrols'): ?>
    <!-- 06-host-controls: End meeting for everyone confirmation modal over avatar stage -->
    <div class="meeting-layout" style="display:flex;">
        <header class="meeting-top-bar">
            <div class="top-bar-left">
                <div style="display:flex;align-items:center;gap:8px;font-weight:700;"><div class="brand-logo-icon" style="width:28px;height:28px;"><i class="fas fa-video fa-xs"></i></div><span>ConnectMeet</span></div>
                <div class="meeting-timer">20:15</div>
                <div class="meeting-code-pill">meet-demo-2026</div>
            </div>
            <div class="top-bar-right">
                <span style="font-size:0.85rem;color:var(--text-secondary);"><i class="fas fa-users"></i> 3 participants</span>
            </div>
        </header>

        <main class="meeting-stage" style="display:flex;padding:16px;">
            <div class="video-grid-wrapper" style="width:100%;height:100%;">
                <div class="video-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;height:100%;">
                    <div class="avatar-tile"><div class="avatar-circle avatar-blue">AR</div><div class="tile-name-tag">Alex Rivera (Host)</div></div>
                    <div class="avatar-tile"><div class="avatar-circle avatar-purple">JD</div><div class="tile-name-tag">Jordan Diaz</div></div>
                </div>
            </div>
        </main>

        <!-- Active Host Leave Modal Backdrop -->
        <div class="modal-backdrop open" style="display:flex;background:rgba(0,0,0,0.7);backdrop-filter:blur(6px);position:fixed;inset:0;align-items:center;justify-content:center;z-index:999;">
            <div class="modal-dialog" style="background:#1e293b;border:1px solid rgba(255,255,255,0.15);border-radius:14px;width:440px;box-shadow:0 20px 45px rgba(0,0,0,0.5);overflow:hidden;">
                <div class="modal-header" style="padding:18px 24px;border-bottom:1px solid rgba(255,255,255,0.08);display:flex;justify-content:space-between;align-items:center;">
                    <h3 class="modal-title" style="margin:0;font-size:1.15rem;display:flex;align-items:center;gap:8px;"><i class="fas fa-phone-slash" style="color:#ef4444;"></i> Leave Meeting</h3>
                    <button type="button" style="background:transparent;border:none;color:#94a3b8;font-size:1rem;cursor:pointer;"><i class="fas fa-times"></i></button>
                </div>
                <div class="modal-body" style="padding:22px 24px;">
                    <p style="color:#cbd5e1;font-size:0.95rem;line-height:1.5;margin:0;">
                        You are the host of this meeting. Would you like to leave alone or terminate the session for all participants?
                    </p>
                </div>
                <div class="modal-footer" style="padding:16px 24px;background:#0f172a;display:flex;justify-content:flex-end;gap:10px;">
                    <button type="button" class="btn btn-secondary" style="padding:10px 16px;">Cancel</button>
                    <button type="button" class="btn btn-secondary" style="padding:10px 16px;">Just Leave</button>
                    <button type="button" class="btn btn-danger" style="padding:10px 18px;background:#ef4444;border-color:#ef4444;font-weight:600;"><i class="fas fa-power-off"></i> End for Everyone</button>
                </div>
            </div>
        </div>

        <footer class="meeting-bottom-bar">
            <div class="bottom-left-info"><span>ConnectMeet P2P</span></div>
            <div class="controls-cluster">
                <button class="ctrl-btn"><i class="fas fa-microphone"></i></button>
                <button class="ctrl-btn"><i class="fas fa-video-slash"></i></button>
                <button class="ctrl-btn"><i class="fas fa-desktop"></i></button>
                <button class="ctrl-btn"><i class="fas fa-hand-paper"></i></button>
                <button class="ctrl-btn"><i class="fas fa-smile"></i></button>
                <button class="ctrl-btn"><i class="fas fa-closed-captioning"></i></button>
                <button class="ctrl-btn"><i class="fas fa-record-vinyl" style="color:#ef4444;"></i></button>
                <button class="ctrl-btn btn-leave" style="background:#ef4444;"><i class="fas fa-phone-slash"></i> Leave</button>
            </div>
            <div class="bottom-right-actions">
                <button class="ctrl-btn"><i class="fas fa-comment-alt"></i></button>
                <button class="ctrl-btn"><i class="fas fa-user-friends"></i></button>
                <button class="ctrl-btn"><i class="fas fa-cog"></i></button>
            </div>
        </footer>
    </div>
<?php endif; ?>

</body>
</html>
