/**
 * ConnectMeet - Landing Page Application Logic
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Live Digital Clock
    const clockEl = document.getElementById('liveClock');
    function updateClock() {
        if (!clockEl) return;
        const now = new Date();
        const timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const dateStr = now.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });
        clockEl.textContent = `${timeStr} • ${dateStr}`;
    }
    updateClock();
    setInterval(updateClock, 1000);

    // 2. Load and live-sync display name
    const storedName = localStorage.getItem('cm_display_name') || '';
    const nameInput = document.getElementById('landingDisplayName');
    const savedIndicator = document.getElementById('nameSavedIndicator');
    let saveTimeout = null;

    if (nameInput) {
        nameInput.value = storedName;

        const persistName = () => {
            const val = nameInput.value.trim();
            localStorage.setItem('cm_display_name', val);
            if (savedIndicator && val) {
                savedIndicator.style.display = 'inline-flex';
                savedIndicator.style.opacity = '1';
                clearTimeout(saveTimeout);
                saveTimeout = setTimeout(() => {
                    savedIndicator.style.opacity = '0';
                    setTimeout(() => {
                        if (savedIndicator) savedIndicator.style.display = 'none';
                    }, 300);
                }, 1400);
            }
        };

        nameInput.addEventListener('input', persistName);
        nameInput.addEventListener('change', persistName);
    }

    // 3. Instant Meeting Button
    const btnInstant = document.getElementById('btnStartInstant');
    if (btnInstant) {
        btnInstant.addEventListener('click', async () => {
            if (nameInput && nameInput.value.trim()) {
                localStorage.setItem('cm_display_name', nameInput.value.trim());
            }
            btnInstant.disabled = true;
            btnInstant.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
            try {
                const res = await fetch('api/meetings.php?action=create', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        title: 'Instant Meeting',
                        participant_limit: 8
                    })
                });
                const data = await res.json();
                if (data.success && data.data) {
                    const code = data.data.meeting_code;
                    const hostToken = data.data.host_token;
                    sessionStorage.setItem(`cm_host_${code}`, hostToken);
                    window.location.href = `room.php?meeting=${code}&host_token=${hostToken}`;
                } else {
                    alert('Error creating meeting: ' + (data.message || 'Unknown error'));
                    btnInstant.disabled = false;
                    btnInstant.innerHTML = '<i class="fas fa-video"></i> Start Instant Meeting';
                }
            } catch (err) {
                alert('Connection error: ' + err.message);
                btnInstant.disabled = false;
                btnInstant.innerHTML = '<i class="fas fa-video"></i> Start Instant Meeting';
            }
        });
    }

    // 4. Create Meeting for Later
    const btnForLater = document.getElementById('btnCreateForLater');
    const laterModal = document.getElementById('meetingLaterModal');
    if (btnForLater && laterModal) {
        btnForLater.addEventListener('click', async () => {
            btnForLater.disabled = true;
            try {
                const res = await fetch('api/meetings.php?action=create', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        title: 'Scheduled ConnectMeet Session',
                        participant_limit: 8
                    })
                });
                const data = await res.json();
                if (data.success && data.data) {
                    const info = data.data;
                    document.getElementById('laterMeetingCodeDisplay').textContent = info.meeting_code;

                    // Build join URL dynamically from browser window location to ensure live cPanel domain is used
                    const currentBase = window.location.href.split('?')[0].replace(/index\.php$/i, '').replace(/\/+$/, '');
                    const fullJoinUrl = `${currentBase}/room.php?meeting=${info.meeting_code}`;
                    document.getElementById('laterJoinLinkInput').value = fullJoinUrl;

                    sessionStorage.setItem(`cm_host_${info.meeting_code}`, info.host_token);

                    const joinNowBtn = document.getElementById('btnJoinCreatedLater');
                    if (joinNowBtn) {
                        joinNowBtn.onclick = () => {
                            window.location.href = `room.php?meeting=${info.meeting_code}&host_token=${info.host_token}`;
                        };
                    }

                    laterModal.classList.add('open');
                } else {
                    alert('Failed to generate meeting link');
                }
            } catch (err) {
                alert('Request failed: ' + err.message);
            } finally {
                btnForLater.disabled = false;
            }
        });
    }

    // Copy link in modal
    const copyLinkBtn = document.getElementById('btnCopyLaterLink');
    if (copyLinkBtn) {
        copyLinkBtn.addEventListener('click', () => {
            const input = document.getElementById('laterJoinLinkInput');
            if (input) {
                navigator.clipboard.writeText(input.value).then(() => {
                    const original = copyLinkBtn.innerHTML;
                    copyLinkBtn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    setTimeout(() => copyLinkBtn.innerHTML = original, 2000);
                }).catch(() => {
                    input.select();
                    document.execCommand('copy');
                });
            }
        });
    }

    // 5. Join Existing Meeting by Code or URL
    const joinInput = document.getElementById('joinCodeInput');
    const joinBtn = document.getElementById('btnJoinMeeting');

    function executeJoin() {
        if (!joinInput) return;
        let val = joinInput.value.trim().toLowerCase();
        if (!val) {
            joinInput.focus();
            return;
        }

        // Handle full URL pasted into the input
        if (val.includes('meeting=')) {
            const url = new URL(val, window.location.origin);
            val = url.searchParams.get('meeting') || val;
        }

        // Clean to 10 alpha characters
        const cleanAlpha = val.replace(/[^a-z]/g, '');
        if (cleanAlpha.length === 10) {
            val = `${cleanAlpha.substring(0, 3)}-${cleanAlpha.substring(3, 7)}-${cleanAlpha.substring(7, 10)}`;
        } else if (!/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/.test(val)) {
            alert('Please enter a valid 10-letter meeting code (e.g. abc-defg-hij) or paste a meeting link.');
            joinInput.focus();
            return;
        }

        if (nameInput && nameInput.value.trim()) {
            localStorage.setItem('cm_display_name', nameInput.value.trim());
        }

        window.location.href = `room.php?meeting=${encodeURIComponent(val)}`;
    }

    if (joinBtn) {
        joinBtn.addEventListener('click', executeJoin);
    }
    if (joinInput) {
        joinInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                executeJoin();
            }
        });
    }

    // 6. Generic Modal Close Handlers
    document.querySelectorAll('.modal-close, .btn-modal-close').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.modal-backdrop').forEach(m => m.classList.remove('open'));
        });
    });

    // 7. Shortcuts Modal Trigger
    const btnShortcuts = document.getElementById('btnOpenShortcuts');
    const shortcutsModal = document.getElementById('shortcutsModal');
    if (btnShortcuts && shortcutsModal) {
        btnShortcuts.addEventListener('click', (e) => {
            e.preventDefault();
            shortcutsModal.classList.add('open');
        });
    }
});
