<?php
declare(strict_types=1);

// Harden the session cookie: not readable from JavaScript, and sent
// only over HTTPS when the site runs on HTTPS (InfinityFree does).
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
session_start();
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrading');
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/classes/RemoteRepository.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

$repository = new RemoteRepository($conn);
$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentUser = $sessionUserId > 0 ? $repository->getUserById($sessionUserId) : null;
if ($sessionUserId > 0 && $currentUser === null) {
    unset($_SESSION['user_id']);
}
$devices = [];
$themes = [];
$activity = [];
if ($currentUser !== null) {
    $dashboard = $repository->dashboard((int) $currentUser['userId']);
    $devices = $dashboard['devices'];
    $themes = $dashboard['themes'];
    $activity = $dashboard['activity'];
}
$selectedDevice = $devices[0] ?? null;
$connectedDeviceCount = count(array_filter($devices, static fn (array $device): bool => $device['connection_status'] !== 'offline'));

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function deviceIcon(string $type): string
{
    return match (strtolower($type)) {
        'audio', 'soundbar', 'speaker' => 'A',
        'projector' => 'P',
        default => 'TV',
    };
}

function relativeTime(string $timestamp): string
{
    $time = strtotime($timestamp);
    if (!$time) return 'Recently';
    $difference = max(0, time() - $time);
    if ($difference < 60) return 'Just now';
    if ($difference < 3600) return floor($difference / 60) . ' min ago';
    if ($difference < 86400) return floor($difference / 3600) . ' hr ago';
    return date('M j', $time);
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = $parts[0][0] ?? 'U';
    $last = count($parts) > 1 ? ($parts[count($parts) - 1][0] ?? '') : '';
    return strtoupper($first . $last);
}

function firstName(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    return $parts[0] ?? $name;
}
?>
<?php if ($currentUser === null): ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#090b0f">
    <title>Universal Remote — Log in</title>
    <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="auth-shell">
    <div class="auth-card">
        <a class="brand" href="index.php" aria-label="Universal Remote home">
            <span class="brand-mark" aria-hidden="true">⌁</span>
            <span>Universal<small>Remote</small></span>
        </a>
        <div id="loginPanel">
            <h1>Welcome back</h1>
            <p class="muted small">Log in to control your own devices.</p>
            <form id="loginForm" novalidate>
                <div class="form-grid">
                    <div class="field"><label for="loginEmail">Email</label><input id="loginEmail" name="email" type="email" placeholder="you@example.com" required></div>
                    <div class="field"><label for="loginPassword">Password</label><input id="loginPassword" name="password" type="password" placeholder="Your password" minlength="8" required></div>
                </div>
                <p class="form-message" id="loginMessage" role="alert"></p>
                <div class="modal-actions"><button class="btn btn-primary" type="submit">Log in</button></div>
            </form>
            <p class="auth-switch">No account yet? <button type="button" data-auth-show="register">Create one</button></p>
        </div>
        <div id="registerPanel" hidden>
            <h1>Create your account</h1>
            <p class="muted small">You start with your own Living room TV, Bedroom TV and Soundbar — edit them after.</p>
            <form id="registerForm" novalidate>
                <div class="form-grid">
                    <div class="field"><label for="registerName">Name</label><input id="registerName" name="name" type="text" placeholder="Edsiel Ursal" minlength="2" maxlength="80" required></div>
                    <div class="field"><label for="registerEmail">Email</label><input id="registerEmail" name="email" type="email" placeholder="you@example.com" required></div>
                    <div class="field"><label for="registerPassword">Password</label><input id="registerPassword" name="password" type="password" placeholder="At least 8 characters" minlength="8" required></div>
                </div>
                <p class="form-message" id="registerMessage" role="alert"></p>
                <div class="modal-actions"><button class="btn btn-primary" type="submit">Create account</button></div>
            </form>
            <p class="auth-switch">Already have an account? <button type="button" data-auth-show="login">Log in</button></p>
        </div>
    </div>
</div>
<div class="toast" id="toast" role="status" aria-live="polite"><span aria-hidden="true">✓</span><span id="toastMessage">Done</span></div>
<script>
window.REMOTE_APP = <?= json_encode(['csrfToken' => $_SESSION['csrf_token']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>
<script src="assets/app.js" defer></script>
</body>
</html>
<?php exit; endif; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#090b0f">
    <meta name="description" content="A simple, universal remote control for your home devices.">
    <title>Universal Remote</title>
    <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" aria-label="Primary navigation">
        <a class="brand" href="index.php" aria-label="Universal Remote home">
            <span class="brand-mark" aria-hidden="true">⌁</span>
            <span>Universal<small>Remote</small></span>
        </a>
        <p class="nav-label">Workspace</p>
        <nav class="nav-list">
            <button class="nav-item active" type="button" data-view="remote"><span class="nav-icon" aria-hidden="true">⌘</span>Remote</button>
            <button class="nav-item" type="button" data-view="devices"><span class="nav-icon" aria-hidden="true">▣</span>Devices</button>
            <button class="nav-item" type="button" data-view="activity"><span class="nav-icon" aria-hidden="true">◷</span>Activity</button>
        </nav>
        <div class="sidebar-spacer"></div>
        <button class="nav-item" type="button" data-open="settings"><span class="nav-icon" aria-hidden="true">⚙</span>Settings</button>
        <div class="profile-card" aria-label="Current profile">
            <div class="avatar"><?= e(initials($currentUser['name'])) ?></div>
            <div class="profile-copy"><strong><?= e($currentUser['name']) ?></strong><span><?= e($currentUser['email']) ?></span></div>
            <button class="icon-button" type="button" data-open="profile" aria-label="Open profile menu">•••</button>
        </div>
    </aside>

    <main class="content">
        <header class="topbar">
            <div>
                <p class="eyebrow" id="currentDate">Loading date…</p>
                <h1>Good <span id="greetingPeriod">evening</span>, <?= e(firstName($currentUser['name'])) ?></h1>
            </div>
            <div class="topbar-actions">
                <label class="search" aria-label="Search devices and apps">
                    <span aria-hidden="true">⌕</span>
                    <input id="searchInput" type="search" placeholder="Search" autocomplete="off">
                </label>
                <span class="connection-pill"><span class="status-dot" aria-hidden="true"></span><span>All systems ready</span></span>
            </div>
        </header>

        <div class="dashboard-grid">
            <section class="left-column" aria-label="Remote workspace">
                <article class="panel remote-panel" id="remoteView">
                    <?php if ($selectedDevice): ?>
                    <div class="remote-top">
                        <div class="device-title">
                            <div class="device-emblem" id="deviceEmblem" aria-hidden="true"><?= e(deviceIcon($selectedDevice['device_type'])) ?></div>
                            <div>
                                <h2 id="activeDeviceName"><?= e($selectedDevice['device_name']) ?></h2>
                                <p class="device-subtitle" id="activeDeviceMeta"><?= e($selectedDevice['brand']) ?> · <?= e($selectedDevice['device_type']) ?></p>
                            </div>
                        </div>
                        <div class="device-actions">
                            <button class="icon-button" type="button" data-command="power" aria-label="Power <?= e($selectedDevice['device_name']) ?>">⏻</button>
                            <button class="icon-button" type="button" data-open="settings" aria-label="Remote settings">⚙</button>
                        </div>
                    </div>

                    <div class="now-playing" aria-label="Now playing">
                        <div class="now-playing-copy">
                            <p class="now-playing-label">Recently played</p>
                            <h3>Looper</h3>
                            <p>Continue watching · 42 min remaining</p>
                        </div>
                        <div class="poster" aria-hidden="true"><strong>LOOPER</strong><span>TIME HAS A PLAN</span></div>
                    </div>

                    <div class="quick-row" aria-label="App shortcuts">
                        <button class="quick-chip active" type="button" data-app-filter="all"><span aria-hidden="true">●</span>All apps</button>
                        <?php foreach (($selectedDevice['apps'] ?? []) as $app): ?>
                            <button class="quick-chip" type="button" data-app-filter="<?= e(strtolower($app['app_name'])) ?>" data-app-name="<?= e($app['app_name']) ?>"><?= e($app['app_name']) ?></button>
                        <?php endforeach; ?>
                        <button class="quick-chip" type="button" data-open="add-shortcut"><span aria-hidden="true">＋</span>Add shortcut</button>
                    </div>

                    <div class="remote-control" aria-label="Remote controls">
                        <div class="control-rail">
                            <span class="rail-label">Volume</span>
                            <span class="rail-value" id="volumeValue">24</span>
                            <div class="rail-buttons">
                                <button class="remote-btn" type="button" data-command="volume-up" aria-label="Increase volume">＋</button>
                                <button class="remote-btn" type="button" data-command="mute" aria-label="Mute">⌁</button>
                                <button class="remote-btn" type="button" data-command="volume-down" aria-label="Decrease volume">−</button>
                            </div>
                        </div>
                        <div class="dial" role="group" aria-label="Directional controls">
                            <button class="dial-nav dial-up" type="button" data-command="up" aria-label="Navigate up">⌃</button>
                            <button class="dial-nav dial-left" type="button" data-command="left" aria-label="Navigate left">‹</button>
                            <button class="dial-nav dial-right" type="button" data-command="right" aria-label="Navigate right">›</button>
                            <button class="dial-nav dial-down" type="button" data-command="down" aria-label="Navigate down">⌄</button>
                            <button class="dial-center" type="button" data-command="enter" aria-label="Select">OK</button>
                        </div>
                        <div class="control-rail">
                            <span class="rail-label">Channel</span>
                            <span class="rail-value" id="channelValue">07</span>
                            <div class="rail-buttons">
                                <button class="remote-btn" type="button" data-command="channel-up" aria-label="Increase channel">＋</button>
                                <button class="remote-btn" type="button" data-command="home" aria-label="Open home">⌂</button>
                                <button class="remote-btn" type="button" data-command="channel-down" aria-label="Decrease channel">−</button>
                            </div>
                        </div>
                    </div>
                    <div class="remote-footer">
                        <button class="remote-btn wide" type="button" data-command="rewind" aria-label="Rewind">◀◀</button>
                        <button class="remote-btn wide" type="button" data-command="play-pause" aria-label="Play or pause">▶Ⅱ</button>
                        <button class="remote-btn wide" type="button" data-command="forward" aria-label="Fast forward">▶▶</button>
                        <button class="remote-btn wide" type="button" id="sourceButton" aria-label="Change source">↗</button>
                    </div>
                    <p class="command-feedback" id="commandFeedback" role="status" aria-live="polite">Select a control to send a command.</p>
                    <?php else: ?>
                    <div class="no-results">Add a device to start using your universal remote.</div>
                    <?php endif; ?>
                </article>

                <section class="feature-row" aria-label="Remote features">
                    <article class="feature-card"><div class="feature-icon" aria-hidden="true">↔</div><strong>One remote</strong><p>Switch between all your home devices in one tap.</p></article>
                    <article class="feature-card"><div class="feature-icon" aria-hidden="true">★</div><strong>Quick access</strong><p>Keep your favorite actions close when you need them.</p></article>
                    <article class="feature-card"><div class="feature-icon" aria-hidden="true">◌</div><strong>Always in sync</strong><p>See connection status before sending a command.</p></article>
                </section>
            </section>

            <aside class="right-column">
                <section class="panel devices-panel" id="devicesView" aria-labelledby="devicesHeading">
                    <div class="panel-head"><div><h2 id="devicesHeading">My devices</h2><p>Choose a device to control</p></div><span class="small muted" id="deviceCount"><?= $connectedDeviceCount ?> connected</span></div>
                    <div class="devices-list" id="deviceList">
                        <?php foreach ($devices as $index => $device): ?>
                            <div class="device-row">
                                <button class="device-card<?= $index === 0 ? ' selected' : '' ?>" type="button" data-device-id="<?= (int) $device['device_id'] ?>" data-device-name="<?= e(strtolower($device['device_name'] . ' ' . $device['brand'] . ' ' . $device['device_type'])) ?>">
                                    <span class="device-emblem" aria-hidden="true"><?= e(deviceIcon($device['device_type'])) ?></span>
                                    <span class="device-copy"><strong><?= e($device['device_name']) ?></strong><span><?= e($device['brand']) ?> · <?= e($device['device_type']) ?></span><span class="device-status<?= $device['connection_status'] === 'offline' ? ' offline' : '' ?>"><span class="status-dot" aria-hidden="true"></span><?= e(ucfirst($device['connection_status'])) ?></span></span>
                                    <span aria-hidden="true">›</span>
                                </button>
                                <div class="device-row-actions"><button class="connection-btn<?= $device['connection_status'] === 'offline' ? ' connect' : ' disconnect' ?>" type="button" data-connection-action="<?= $device['connection_status'] === 'offline' ? 'connect' : 'disconnect' ?>" data-device-id="<?= (int) $device['device_id'] ?>" data-device-label="<?= e($device['device_name']) ?>"><?= $device['connection_status'] === 'offline' ? 'Connect' : 'Disconnect' ?></button><button class="remove-btn" type="button" data-remove-device="<?= (int) $device['device_id'] ?>" data-device-label="<?= e($device['device_name']) ?>">Remove</button></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="add-device" type="button" data-open="add-device"><span aria-hidden="true">＋</span>Add new device</button>
                </section>

                <section class="panel" id="activityView" aria-labelledby="activityHeading">
                    <div class="panel-head"><div><h2 id="activityHeading">Recent activity</h2><p>Your latest remote actions</p></div><button class="icon-button" type="button" data-open="activity" aria-label="View all activity">↗</button></div>
                    <div class="activity-list" id="activityList">
                        <?php if ($activity): foreach ($activity as $item): ?>
                            <div class="activity-row"><div class="activity-mark" aria-hidden="true">⌁</div><div class="activity-copy"><strong><?= e($item['action']) ?></strong><span><?= e($item['device_name']) ?> · <?= e(relativeTime($item['timestamp'])) ?></span></div></div>
                        <?php endforeach; else: ?><div class="empty-activity">Your remote history will appear here.</div><?php endif; ?>
                    </div>
                </section>
            </aside>
        </div>
    </main>
</div>

<nav class="mobile-nav" aria-label="Mobile navigation">
    <button class="nav-item active" type="button" data-view="remote"><span class="nav-icon" aria-hidden="true">⌘</span>Remote</button>
    <button class="nav-item" type="button" data-view="devices"><span class="nav-icon" aria-hidden="true">▣</span>Devices</button>
    <button class="nav-item" type="button" data-view="activity"><span class="nav-icon" aria-hidden="true">◷</span>Activity</button>
    <button class="nav-item" type="button" data-open="settings"><span class="nav-icon" aria-hidden="true">⚙</span>Settings</button>
</nav>

<div class="modal-backdrop" id="addDeviceModal" hidden>
    <section class="modal" role="dialog" aria-modal="true" aria-labelledby="addDeviceTitle">
        <div class="modal-head"><div><h2 id="addDeviceTitle">Add a device</h2><p>Give your device a name so it is easy to find.</p></div><button class="icon-button" type="button" data-close-modal aria-label="Close add device dialog">×</button></div>
        <div class="modal-body">
            <form id="addDeviceForm" novalidate>
                <div class="form-grid">
                    <div class="field"><label for="deviceName">Device name</label><input id="deviceName" name="name" type="text" placeholder="Living room TV" minlength="2" maxlength="80" required></div>
                    <div class="field"><label id="deviceTypeLabel">Device type</label><div class="custom-select" data-custom-select><button class="select-trigger" type="button" data-select-trigger aria-haspopup="listbox" aria-expanded="false" aria-labelledby="deviceTypeLabel deviceTypeText"><span id="deviceTypeText" data-select-label>Choose a type</span><span class="select-chevron" aria-hidden="true">⌄</span></button><div class="select-menu" role="listbox" hidden><button type="button" role="option" data-select-option data-value="">Choose a type</button><button type="button" role="option" data-select-option data-value="Smart TV">Smart TV</button><button type="button" role="option" data-select-option data-value="Streaming box">Streaming box</button><button type="button" role="option" data-select-option data-value="Audio">Audio</button><button type="button" role="option" data-select-option data-value="Projector">Projector</button><button type="button" role="option" data-select-option data-value="Air conditioner">Air conditioner</button></div><input id="deviceType" name="type" type="hidden" value="" data-select-value></div></div>
                    <div class="field"><label for="deviceBrand">Brand</label><input id="deviceBrand" name="brand" type="text" placeholder="Samsung" minlength="2" maxlength="50" required></div>
                </div>
                <p class="form-message" id="formMessage" role="alert"></p>
                <div class="modal-actions"><button class="btn btn-ghost" type="button" data-close-modal>Cancel</button><button class="btn btn-primary" type="submit">Add device</button></div>
            </form>
        </div>
    </section>
</div>

<div class="modal-backdrop" id="addShortcutModal" hidden>
    <section class="modal" role="dialog" aria-modal="true" aria-labelledby="addShortcutTitle">
        <div class="modal-head"><div><h2 id="addShortcutTitle">Add a shortcut</h2><p>Add an app shortcut to the active device.</p></div><button class="icon-button" type="button" data-close-modal aria-label="Close add shortcut dialog">×</button></div>
        <div class="modal-body">
            <form id="addShortcutForm" novalidate>
                <div class="field"><label for="shortcutName">App name</label><input id="shortcutName" name="name" type="text" placeholder="Netflix" minlength="2" maxlength="80" required></div>
                <p class="form-message" id="shortcutMessage" role="alert"></p>
                <div class="modal-actions"><button class="btn btn-ghost" type="button" data-close-modal>Cancel</button><button class="btn btn-primary" type="submit">Add shortcut</button></div>
            </form>
        </div>
    </section>
</div>

<div class="modal-backdrop" id="profileModal" hidden>
    <section class="modal" role="dialog" aria-modal="true" aria-labelledby="profileTitle">
        <div class="modal-head"><div><h2 id="profileTitle"><?= e($currentUser['name']) ?></h2><p><?= e($currentUser['email']) ?></p></div><button class="icon-button" type="button" data-close-modal aria-label="Close profile dialog">×</button></div>
        <div class="modal-body">
            <div class="profile-card"><div class="avatar"><?= e(initials($currentUser['name'])) ?></div><div class="profile-copy"><strong><?= e($currentUser['name']) ?></strong><span><?= e($currentUser['email']) ?> · Universal Remote</span></div></div>
            <div class="modal-actions"><button class="btn btn-danger" type="button" data-logout>Log out</button><button class="btn btn-ghost" type="button" data-profile-action="clear-search">Clear search</button><button class="btn btn-primary" type="button" data-open="settings">Open settings</button></div>
            <p class="form-message" id="profileMessage" role="status"></p>
        </div>
    </section>
</div>

<div class="modal-backdrop" id="settingsModal" hidden>
    <section class="modal" role="dialog" aria-modal="true" aria-labelledby="settingsTitle">
        <div class="modal-head"><div><h2 id="settingsTitle">Remote settings</h2><p>Personalize the active device experience.</p></div><button class="icon-button" type="button" data-close-modal aria-label="Close settings dialog">×</button></div>
        <div class="modal-body">
            <div class="field"><label id="themeSelectLabel">Remote theme</label><div class="custom-select" data-custom-select><button class="select-trigger" type="button" data-select-trigger aria-haspopup="listbox" aria-expanded="false" aria-labelledby="themeSelectLabel themeSelectText"><span id="themeSelectText" data-select-label>Midnight</span><span class="select-chevron" aria-hidden="true">⌄</span></button><div class="select-menu" role="listbox" hidden><?php foreach ($themes as $theme): ?><button type="button" role="option" data-select-option data-value="<?= (int) $theme['theme_id'] ?>"><?= e($theme['theme_name']) ?></button><?php endforeach; ?></div><input id="themeSelect" type="hidden" value="1" data-select-value></div></div>
            <p class="form-message" id="settingsMessage" role="status"></p>
            <div class="modal-actions"><button class="btn btn-ghost" type="button" data-close-modal>Done</button><button class="btn btn-primary" type="button" id="saveTheme">Save theme</button></div>
        </div>
    </section>
</div>

<div class="modal-backdrop" id="activityModal" hidden>
    <section class="modal" role="dialog" aria-modal="true" aria-labelledby="activityAllTitle">
        <div class="modal-head"><div><h2 id="activityAllTitle">All activity</h2><p>Your full remote history.</p></div><button class="icon-button" type="button" data-close-modal aria-label="Close activity dialog">×</button></div>
        <div class="modal-body">
            <div class="activity-list" id="allActivityList" style="padding:0">
                <div class="empty-activity">Loading…</div>
            </div>
            <div class="modal-actions"><button class="btn btn-ghost" type="button" data-close-modal>Done</button></div>
        </div>
    </section>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"><span aria-hidden="true">✓</span><span id="toastMessage">Done</span></div>
<script>
window.REMOTE_APP = <?= json_encode([
    'csrfToken' => $_SESSION['csrf_token'],
    'user' => $currentUser,
    'activeDeviceId' => $selectedDevice ? (int) $selectedDevice['device_id'] : null,
    'devices' => $devices,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>
<script src="assets/app.js" defer></script>
</body>
</html>
