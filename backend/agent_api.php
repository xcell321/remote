<?php
declare(strict_types=1);

/* =====================================================================
   Universal Remote - Home Agent API
   ---------------------------------------------------------------------
   This endpoint is for the agent program running on the home PC.
   It does NOT use browser sessions or CSRF tokens; instead every
   request must carry the shared secret in the X-Remote-Key header.
   The secret lives in backend/agent_config.php (never committed -
   copy agent_config.example.php and set your own long random value).

   POST JSON actions:
     {"action": "poll"}                                  -> pending commands
     {"action": "report", "id": 12, "ok": true}          -> record a result
     {"action": "heartbeat"}                             -> agent alive ping
   ===================================================================== */

require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/../classes/RemoteRepository.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$configPath = __DIR__ . '/agent_config.php';
if (!file_exists($configPath)) {
    respondAgent(['ok' => false, 'message' => 'Agent API is not configured on the server.'], 503);
}
require_once $configPath;

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respondAgent(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }

    $expected = defined('REMOTE_AGENT_SECRET') ? (string) REMOTE_AGENT_SECRET : '';
    $received = (string) ($_SERVER['HTTP_X_REMOTE_KEY'] ?? '');
    if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
        respondAgent(['ok' => false, 'message' => 'Unauthorized.'], 403);
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        respondAgent(['ok' => false, 'message' => 'The request format is invalid.'], 400);
    }

    $repository = new RemoteRepository($conn);
    $action = (string) ($payload['action'] ?? '');

    if ($action === 'poll') {
        respondAgent(['ok' => true, 'commands' => $repository->pollCommands(10)]);
    }

    if ($action === 'report') {
        $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            respondAgent(['ok' => false, 'message' => 'A valid command id is required.'], 422);
        }
        $ok = filter_var($payload['ok'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $error = isset($payload['error']) ? (string) $payload['error'] : null;
        $repository->reportCommand((int) $id, $ok, $error);
        respondAgent(['ok' => true]);
    }

    if ($action === 'heartbeat') {
        $repository->agentHeartbeat();
        respondAgent(['ok' => true]);
    }

    respondAgent(['ok' => false, 'message' => 'That action is not available.'], 400);
} catch (Throwable $exception) {
    error_log('Universal Remote Agent API error: ' . $exception->getMessage());
    respondAgent(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}

function respondAgent(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
