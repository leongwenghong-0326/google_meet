# ConnectMeet - Complete Installation & Deployment Guide

This guide covers local setup using XAMPP on Windows, production deployment on cPanel hosting, WebRTC STUN/TURN configuration, and media permission troubleshooting.

---

## 1. Local Environment Requirements (XAMPP)

- **Operating System:** Windows 10 or Windows 11
- **Local Server Stack:** XAMPP for Windows
- **Web Server:** Apache 2.4+
- **PHP Version:** PHP 8.2+ or PHP 8.3+
  - Required Extensions: `pdo`, `pdo_mysql`, `openssl`, `json`, `session`, `random`, `mbstring`, `ctype`
- **Database:** MySQL 5.7+ or MariaDB 10.3+
- **Browser:** Recent version of Google Chrome, Microsoft Edge, or Mozilla Firefox (Safari supported where WebRTC APIs are available)

---

## 2. Local XAMPP Setup (Step-by-Step)

### Step 1: Clone or Place the Project
Ensure the project files are located in your XAMPP `htdocs` directory:
```
C:\xampp\htdocs\google_meet\
```
*(Or rename folder to `connectmeet` if desired)*.

### Step 2: Start Apache and MySQL
1. Launch the **XAMPP Control Panel**.
2. Click **Start** next to **Apache**.
3. Click **Start** next to **MySQL**.

### Step 3: Database Initialization
1. Open a terminal / command prompt in your project root:
   ```bash
   cd C:\xampp\htdocs\google_meet
   ```
2. Run the database initialization script:
   ```bash
   php scripts/init_db.php
   ```
   *Alternatively, open phpMyAdmin (`http://localhost/phpmyadmin/`), create a database named `connectmeet_db`, and import `database/install.sql`.*

### Step 4: Configuration File
Inspect `config/config.php`. Verify the database connection settings:
```php
'db' => [
    'host' => 'localhost',
    'port' => 3306,
    'name' => 'connectmeet_db',
    'user' => 'root',
    'pass' => 'Hong2007', // Match your local MySQL root password
    'charset' => 'utf8mb4',
],
```
Also verify the `base_url` corresponds to your folder path:
```php
'base_url' => 'http://localhost/google_meet',
```

### Step 5: Test and Verify
1. Run the automated test suite to ensure all backend components and database operations are functional:
   ```bash
   php tests/test_api.php
   ```
   All 24 test assertions should return `[PASS]`.
2. Open your browser and navigate to:
   ```
   http://localhost/google_meet/
   ```
3. Allow camera and microphone permissions when prompted.

---

## 3. WebRTC Media Permissions & Multi-Computer Testing

### Localhost Secure Context
Modern browsers treat `http://localhost` and `http://127.0.0.1` as **Secure Contexts**, allowing full access to `navigator.mediaDevices.getUserMedia()`, `getDisplayMedia()`, and `MediaRecorder`.

### Testing Across Two Computers on a Local Network (LAN)
If you access `http://192.168.x.x/google_meet/` from another device over plain HTTP, Chrome will block camera and microphone access because non-localhost HTTP is considered insecure.

To test across multiple computers:
1. **Option A (Recommended for Production / Staging):** Enable HTTPS on your local Apache (using XAMPP's SSL or mkcert certificates).
2. **Option B (Chrome Flag for Dev Testing):** On the client computer, navigate to:
   ```
   chrome://flags/#unsafely-treat-insecure-origin-as-secure
   ```
   Add your host IP (e.g. `http://192.168.1.50:80`) to the whitelist and relaunch Chrome.

---

## 4. WebRTC STUN & TURN Configuration

ConnectMeet uses a **Peer-to-Peer (P2P) Mesh** topology. Direct peer connections require NAT traversal.

### Default STUN Configuration
By default, ConnectMeet is configured with Google's public STUN servers:
```php
'webrtc' => [
    'ice_servers' => [
        [
            'urls' => [
                'stun:stun.l.google.com:19302',
                'stun:stun1.l.google.com:19302',
                'stun:stun2.l.google.com:19302'
            ]
        ]
    ]
]
```
STUN works reliably across ordinary residential and open networks.

### When is a TURN Server Required?
If either participant is behind:
- Symmetric NAT
- Corporate / Enterprise firewalls that block UDP
- Restrictive mobile carrier networks (Carrier-Grade NAT)

A **TURN (Traversal Using Relays around NAT)** relay server is required. To add a TURN server (such as Coturn):
```php
'webrtc' => [
    'ice_servers' => [
        [ 'urls' => ['stun:stun.l.google.com:19302'] ],
        [
            'urls' => 'turn:turn.yourdomain.com:3478',
            'username' => 'your_turn_user',
            'credential' => 'your_turn_password'
        ]
    ]
]
```

---

## 5. Deployment on cPanel / Shared Apache Hosting

### Step 1: Upload Files
Upload all project files to `public_html/` or a subfolder (e.g. `public_html/connectmeet/`) via FTP or cPanel File Manager.

### Step 2: Create MySQL Database & User
1. In cPanel, navigate to **MySQL Databases**.
2. Create a database (e.g. `cpaneluser_connectmeet`).
3. Create a database user with a strong password.
4. Assign all privileges on the database to the user.
5. In **phpMyAdmin**, import `database/install.sql`.

### Step 3: Configure Settings
Copy `config/config.example.php` to `config/config.php` and set your credentials:
```php
'app' => [
    'base_url' => 'https://yourdomain.com/connectmeet',
    'debug' => false,
],
'db' => [
    'host' => 'localhost',
    'name' => 'cpaneluser_connectmeet',
    'user' => 'cpaneluser_dbuser',
    'pass' => 'YourStrongPassword',
],
```

### Step 4: Force HTTPS
Ensure your domain has a valid SSL certificate (e.g., Let's Encrypt in cPanel). WebRTC requires HTTPS in all remote production environments.

### Step 5: Schedule Maintenance Cron Job
Set up a recurring cron job in cPanel to remove stale participant sessions and clean up old signaling records every 5 minutes:
```bash
*/5 * * * * /usr/local/bin/php /home/username/public_html/connectmeet/scripts/cleanup.php >/dev/null 2>&1
```

---

## 6. Architecture & Scalability Notes

- **Mesh Topology:** For 2 to 8 participants, direct mesh WebRTC provides the best privacy and requires virtually zero server bandwidth.
- **Future Large-Scale Upgrade (SFU):** If you plan to scale beyond 8–10 concurrent video streams per room, integrate a dedicated Selective Forwarding Unit (SFU) such as LiveKit, mediasoup, or Janus. ConnectMeet's REST signaling protocol can be readily adapted to an SFU gateway.
