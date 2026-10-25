<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/../classes/RemoteRepository.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $repository = new RemoteRepository($conn);
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    $currentUser = $sessionUserId > 0 ? $repository->getUserById($sessionUserId) : null;
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET' && ($_GET['action'] ?? 'dashboard') === 'dashboard') {
        if ($currentUser === null) {
            respond(['ok' => false, 'message' => 'Please log in to continue.'], 401);
        }
        respond(['ok' => true, 'data' => ['user' => $currentUser] + $repository->dashboard((int) $currentUser['userId'])]);
    }

    if ($method === 'GET' && ($_GET['action'] ?? '') === 'agent-status') {
        if ($currentUser === null) {
            respond(['ok' => false, 'message' => 'Please log in to continue.'], 401);
        }
        respond(['ok' => true, 'data' => ['agentOnline' => $repository->agentOnline()]]);
    }

    if ($method !== 'POST') {
        respond(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }

    $expectedToken = (string) ($_SESSION['csrf_token'] ?? '');
    $receivedToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($expectedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
        respond(['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.'], 419);
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        respond(['ok' => false, 'message' => 'The request format is invalid.'], 400);
    }

    $action = (string) ($payload['action'] ?? '');
    if ($action === 'register') {
        $user = $repository->registerUser(
            (string) ($payload['name'] ?? ''),
            (string) ($payload['email'] ?? ''),
            (string) ($payload['password'] ?? '')
        );
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['userId'];
        respond(['ok' => true, 'data' => ['user' => $user, 'message' => 'Account created. Welcome!']]);
    }

    if ($action === 'login') {
        $user = $repository->verifyUser(
            (string) ($payload['email'] ?? ''),
            (string) ($payload['password'] ?? '')
        );
        if ($user === null) {
            respond(['ok' => false, 'message' => 'Incorrect email or password.'], 401);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['userId'];
        respond(['ok' => true, 'data' => ['user' => $user, 'message' => 'Welcome back!']]);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        respond(['ok' => true, 'data' => ['message' => 'Logged out.']]);
    }

    // Everything below needs a logged-in account.
    if ($currentUser === null) {
        respond(['ok' => false, 'message' => 'Please log in to continue.'], 401);
    }
    $userId = (int) $currentUser['userId'];

    // Fail-closed ownership gate: a device owned by another account (or
    // with no owner) looks "not found" -- never leak its existence.
    $ownDevice = function (int $deviceId) use ($repository, $userId): void {
        if (!$repository->deviceOwnedBy($deviceId, $userId)) {
            respond(['ok' => false, 'message' => 'The selected device could not be found.'], 404);
        }
    };

    if ($action === 'remove-device') {
        $deviceId = filter_var($payload['deviceId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($deviceId === false) {
            respond(['ok' => false, 'message' => 'Choose a valid device to remove.'], 422);
        }
        $ownDevice((int) $deviceId);
        respond(['ok' => true, 'data' => $repository->removeDevice((int) $deviceId)]);
    }

    if ($action === 'connect') {
        $deviceId = filter_var($payload['deviceId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($deviceId === false) {
            respond(['ok' => false, 'message' => 'Choose a valid device to connect.'], 422);
        }
        $ownDevice((int) $deviceId);
        respond(['ok' => true, 'data' => $repository->connectDevice((int) $deviceId)]);
    }

    if ($action === 'disconnect') {
        $deviceId = filter_var($payload['deviceId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($deviceId === false) {
            respond(['ok' => false, 'message' => 'Choose a valid device to disconnect.'], 422);
        }
        $ownDevice((int) $deviceId);
        respond(['ok' => true, 'data' => $repository->disconnectDevice((int) $deviceId)]);
    }

    if ($action === 'command') {
        $deviceId = filter_var($payload['deviceId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $command = trim((string) ($payload['command'] ?? ''));
        $value = isset($payload['value']) ? trim((string) $payload['value']) : null;
        if ($deviceId === false || $command === '') {
            respond(['ok' => false, 'message' => 'Choose a device and a valid remote action.'], 422);
        }
        $ownDevice((int) $deviceId);
        respond(['ok' => true, 'data' => $repository->runCommand((int) $deviceId, $command, $value !== '' ? $value : null, $userId)]);
    }

    if ($action === 'add-app') {
        $deviceId = filter_var($payload['deviceId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $name = trim((string) ($payload['name'] ?? ''));
        if ($deviceId === false || strlen($name) < 2 || strlen($name) > 80) {
            respond(['ok' => false, 'message' => 'Enter an app name between 2 and 80 characters.'], 422);
        }
        $ownDevice((int) $deviceId);
        respond(['ok' => true, 'data' => $repository->addApp((int) $deviceId, $name)]);
    }

    if ($action === 'set-source') {
        $deviceId = filter_var($payload['deviceId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $source = trim((string) ($payload['source'] ?? ''));
        $allowedSources = ['HDMI 1', 'HDMI 2', 'HDMI 3', 'TV', 'AV', 'Optical'];
        if ($deviceId === false || !in_array($source, $allowedSources, true)) {
            respond(['ok' => false, 'message' => 'Choose a valid input source.'], 422);
        }
        $ownDevice((int) $deviceId);
        respond(['ok' => true, 'data' => $repository->setSource((int) $deviceId, $source, $userId)]);
    }

    if ($action === 'theme') {
        $deviceId = filter_var($payload['deviceId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $themeId = filter_var($payload['themeId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($deviceId === false || $themeId === false) {
            respond(['ok' => false, 'message' => 'Choose a valid device and theme.'], 422);
        }
        $ownDevice((int) $deviceId);
        $repository->setTheme((int) $deviceId, (int) $themeId);
        respond(['ok' => true, 'data' => ['message' => 'Theme updated']]);
    }

    if ($action === 'add-device') {
        $name = trim((string) ($payload['name'] ?? ''));
        $type = trim((string) ($payload['type'] ?? ''));
        $brand = trim((string) ($payload['brand'] ?? ''));
        if (strlen($name) < 2 || strlen($name) > 80 || strlen($type) < 2 || strlen($type) > 50 || strlen($brand) < 2 || strlen($brand) > 50) {
            respond(['ok' => false, 'message' => 'Enter a device name, type, and brand using the suggested lengths.'], 422);
        }
        // Uses a SQL transaction internally (BEGIN -> COMMIT / ROLLBACK).
        $id = $repository->createDevice($name, $type, $brand, $userId);
        respond(['ok' => true, 'data' => ['deviceId' => $id, 'message' => 'Device added']]);
    }

    if ($action === 'add-device-with-apps') {
        $name = trim((string) ($payload['name'] ?? ''));
        $type = trim((string) ($payload['type'] ?? ''));
        $brand = trim((string) ($payload['brand'] ?? ''));
        $apps = is_array($payload['apps'] ?? null) ? $payload['apps'] : [];
        if (strlen($name) < 2 || strlen($name) > 80 || strlen($type) < 2 || strlen($type) > 50 || strlen($brand) < 2 || strlen($brand) > 50) {
            respond(['ok' => false, 'message' => 'Enter a device name, type, and brand using the suggested lengths.'], 422);
        }
        // Demonstrates BEGIN + SAVEPOINT + COMMIT / ROLLBACK TO SAVEPOINT.
        $id = $repository->createDeviceWithApps($name, $type, $brand, $apps, $userId);
        respond(['ok' => true, 'data' => ['deviceId' => $id, 'message' => 'Device added with apps']]);
    }

    respond(['ok' => false, 'message' => 'That action is not available.'], 400);
} catch (InvalidArgumentException $exception) {
    respond(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Universal Remote API error: ' . $exception->getMessage());
    respond(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}

function respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
?>

