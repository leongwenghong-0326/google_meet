<?php
/**
 * ConnectMeet - Landing Page
 */
require_once __DIR__ . '/includes/bootstrap.php';
$csrfToken = getCsrfToken();
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
    <title>ConnectMeet - Secure & Premium Video Conferencing</title>
    <meta name="description" content="ConnectMeet is a modern, responsive, web-based video conferencing platform powered by native WebRTC and PHP signaling.">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom Styles -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body>

    <!-- Header Navbar -->
    <header class="navbar">
        <a href="index.php" class="brand">
            <div class="brand-logo-icon">
                <i class="fas fa-video fa-sm"></i>
            </div>
            <span class="brand-text">ConnectMeet</span>
        </a>

        <div class="nav-actions">
            <div class="live-clock" id="liveClock">--:-- • ---, --- --</div>
            <a href="#" id="btnOpenShortcuts" class="btn btn-secondary" title="Keyboard Shortcuts">
                <i class="fas fa-keyboard"></i> Shortcuts
            </a>
        </div>
    </header>

    <!-- Hero Section -->
    <main class="hero-section">
        <div class="hero-content">
            <div class="hero-badge">
                <i class="fas fa-bolt"></i> Real-time WebRTC Audio & Video Mesh
            </div>
            <h1 class="hero-title">
                Premium video meetings for <span>everyone</span>.
            </h1>
            <p class="hero-subtitle">
                Connect, collaborate, and celebrate securely from anywhere. High definition video, screen sharing, live chat, and crystal clear audio in your browser with zero downloads.
            </p>

            <!-- Display Name Configuration -->
            <div class="display-name-wrapper">
                <label for="landingDisplayName" class="display-name-label">
                    <i class="fas fa-user-circle"></i> Your Display Name
                </label>
                <div class="display-name-input-box">
                    <input type="text" id="landingDisplayName" class="display-name-input" placeholder="Enter your name (e.g. Sarah Jenkins)" maxlength="50" autocomplete="name">
                    <span id="nameSavedIndicator" class="display-name-hint"><i class="fas fa-check-circle"></i> Saved</span>
                </div>
            </div>

            <!-- Action Buttons Group -->
            <div class="hero-actions">
                <button type="button" id="btnStartInstant" class="btn btn-primary">
                    <i class="fas fa-video"></i> Start Instant Meeting
                </button>
                <button type="button" id="btnCreateForLater" class="btn btn-secondary">
                    <i class="fas fa-link"></i> Create for Later
                </button>

                <!-- Join with Code Input -->
                <div class="join-group">
                    <i class="fas fa-keyboard" style="color:var(--text-muted);"></i>
                    <input type="text" id="joinCodeInput" placeholder="Enter code (abc-defg-hij)" maxlength="50">
                    <button type="button" id="btnJoinMeeting" class="btn btn-primary" style="padding: 8px 16px;">
                        Join
                    </button>
                </div>
            </div>

            <!-- Value Propositions -->
            <div class="hero-features">
                <div class="feature-item">
                    <i class="fas fa-shield-alt feature-icon"></i>
                    <h2 class="feature-title">End-to-End P2P Mesh</h2>
                    <p class="feature-desc">Direct browser-to-browser media streaming with low latency.</p>
                </div>
                <div class="feature-item">
                    <i class="fas fa-desktop feature-icon"></i>
                    <h2 class="feature-title">Screen Sharing</h2>
                    <p class="feature-desc">Present your deck, spreadsheets, or browser tabs seamlessly.</p>
                </div>
                <div class="feature-item">
                    <i class="fas fa-comments feature-icon"></i>
                    <h2 class="feature-title">Interactive Suite</h2>
                    <p class="feature-desc">Real-time chat, hand-raising, floating reactions, and captions.</p>
                </div>
            </div>
        </div>

        <!-- Hero Visual Mockup -->
        <div class="hero-visual">
            <div class="hero-preview-card">
                <div class="preview-mockup-header">
                    <div class="mockup-dots">
                        <span class="dot-red"></span>
                        <span class="dot-yellow"></span>
                        <span class="dot-green"></span>
                    </div>
                    <span style="font-size:0.8rem;color:var(--text-muted);font-family:monospace;">connectmeet.live/room</span>
                    <div><i class="fas fa-shield-check" style="color:#10b981;font-size:0.85rem;"></i></div>
                </div>
                <div class="preview-mockup-grid">
                    <div class="mockup-tile">
                        <div class="mockup-avatar" style="background:linear-gradient(135deg,#3b82f6,#8b5cf6);">JD</div>
                        <div class="mockup-name">John Doe (Host)</div>
                    </div>
                    <div class="mockup-tile">
                        <div class="mockup-avatar" style="background:linear-gradient(135deg,#10b981,#3b82f6);">AL</div>
                        <div class="mockup-name">Alice Lin</div>
                    </div>
                    <div class="mockup-tile">
                        <div class="mockup-avatar" style="background:linear-gradient(135deg,#f59e0b,#ef4444);">MK</div>
                        <div class="mockup-name">Marcus King</div>
                    </div>
                    <div class="mockup-tile">
                        <div class="mockup-avatar" style="background:linear-gradient(135deg,#8b5cf6,#ec4899);">SJ</div>
                        <div class="mockup-name">Sarah Jenkins</div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal: Meeting Created For Later -->
    <div class="modal-backdrop" id="meetingLaterModal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">Here's your meeting link</h3>
                <button type="button" class="btn btn-secondary btn-icon modal-close" style="width:32px;height:32px;"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <p style="color:var(--text-secondary);font-size:0.92rem;">
                    Copy this link and send it to people that you want to meet with. Be sure to save it so you can use it later, too.
                </p>
                <div style="display:flex;gap:8px;">
                    <input type="text" id="laterJoinLinkInput" class="form-input" readonly>
                    <button type="button" id="btnCopyLaterLink" class="btn btn-secondary">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                </div>
                <div style="font-size:0.85rem;color:var(--text-muted);display:flex;justify-content:space-between;align-items:center;">
                    <span>Meeting code: <strong id="laterMeetingCodeDisplay" style="color:#fff;font-family:monospace;"></strong></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary modal-close">Done</button>
                <button type="button" id="btnJoinCreatedLater" class="btn btn-primary">Join Now</button>
            </div>
        </div>
    </div>

    <!-- Modal: Keyboard Shortcuts -->
    <div class="modal-backdrop" id="shortcutsModal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-keyboard"></i> Keyboard Shortcuts</h3>
                <button type="button" class="btn btn-secondary btn-icon modal-close" style="width:32px;height:32px;"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
                    <tr style="border-bottom:1px solid var(--border-glass);">
                        <td style="padding:10px 0;color:var(--text-secondary);">Toggle Microphone (Mute/Unmute)</td>
                        <td style="padding:10px 0;text-align:right;"><kbd style="background:var(--bg-surface);padding:3px 8px;border-radius:4px;border:1px solid var(--border-glass);">M</kbd></td>
                    </tr>
                    <tr style="border-bottom:1px solid var(--border-glass);">
                        <td style="padding:10px 0;color:var(--text-secondary);">Toggle Camera (Video On/Off)</td>
                        <td style="padding:10px 0;text-align:right;"><kbd style="background:var(--bg-surface);padding:3px 8px;border-radius:4px;border:1px solid var(--border-glass);">V</kbd></td>
                    </tr>
                    <tr style="border-bottom:1px solid var(--border-glass);">
                        <td style="padding:10px 0;color:var(--text-secondary);">Raise / Lower Hand</td>
                        <td style="padding:10px 0;text-align:right;"><kbd style="background:var(--bg-surface);padding:3px 8px;border-radius:4px;border:1px solid var(--border-glass);">H</kbd></td>
                    </tr>
                    <tr style="border-bottom:1px solid var(--border-glass);">
                        <td style="padding:10px 0;color:var(--text-secondary);">Toggle Chat Drawer</td>
                        <td style="padding:10px 0;text-align:right;"><kbd style="background:var(--bg-surface);padding:3px 8px;border-radius:4px;border:1px solid var(--border-glass);">C</kbd></td>
                    </tr>
                    <tr>
                        <td style="padding:10px 0;color:var(--text-secondary);">Toggle Participants Drawer</td>
                        <td style="padding:10px 0;text-align:right;"><kbd style="background:var(--bg-surface);padding:3px 8px;border-radius:4px;border:1px solid var(--border-glass);">P</kbd></td>
                    </tr>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary modal-close">Close</button>
            </div>
        </div>
    </div>

    <!-- Application Script -->
    <script src="assets/js/app.js"></script>
</body>
</html>
