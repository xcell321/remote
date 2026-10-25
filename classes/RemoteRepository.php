<?php
declare(strict_types=1);

require_once __DIR__ . '/Transaction.php';

final class RemoteRepository
{
       public function __construct(private PDO $conn)
    {
        $this->ensureSchema();
    }

    /** Dashboard for ONE user only: only their devices, apps and activity. */
    public function dashboard(int $userId): array
    {
        $deviceStatement = $this->conn->prepare(
            'SELECT device_id, device_name, device_type, brand, connection_status, source_input
             FROM devices WHERE user_id = :user ORDER BY device_id'
        );
        $deviceStatement->execute([':user' => $userId]);
        $devices = $deviceStatement->fetchAll();

        $appStatement = $this->conn->prepare(
            'SELECT a.app_id, a.device_id, a.app_name, a.app_icon, a.launch_url
             FROM app_shortcuts a JOIN devices d ON d.device_id = a.device_id
             WHERE d.user_id = :user ORDER BY a.app_id'
        );
        $appStatement->execute([':user' => $userId]);
        $apps = $appStatement->fetchAll();

        $themes = $this->all('SELECT theme_id, theme_name, color_scheme FROM themes ORDER BY theme_id');

        $logStatement = $this->conn->prepare(
            'SELECT l.log_id, l.device_id, l.action, l.timestamp, d.device_name
             FROM activity_logs l JOIN devices d ON d.device_id = l.device_id
             WHERE d.user_id = :user ORDER BY l.timestamp DESC LIMIT 8'
        );
        $logStatement->execute([':user' => $userId]);
        $logs = $logStatement->fetchAll();

        foreach ($devices as &$device) {
            $device['device_id'] = (int) $device['device_id'];
            $device['apps'] = array_values(array_filter($apps, static fn (array $app): bool => (int) $app['device_id'] === $device['device_id']));
        }
        unset($device);

        return ['devices' => $devices, 'themes' => $themes, 'activity' => $logs];
    }

    /** Register a new account. The user starts with the 3 starter devices. */
    public function registerUser(string $name, string $email, string $password): array
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        if (strlen($name) < 2 || strlen($name) > 80) {
            throw new InvalidArgumentException('Enter a name between 2 and 80 characters.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Use a password with at least 8 characters.');
        }
        if ($this->findUserByEmail($email) !== null) {
            throw new InvalidArgumentException('That email is already registered. Log in instead.');
        }
        return Transaction::run($this->conn, function () use ($name, $email, $password): array {
            $statement = $this->conn->prepare(
                'INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :hash)'
            );
            $statement->execute([
                ':name' => $name,
                ':email' => $email,
                ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $userId = (int) $this->conn->lastInsertId();
            $this->seedStarterDevices($userId);
            return ['userId' => $userId, 'name' => $name, 'email' => $email];
        });
    }

    /** Full user row (including the hash) -- internal use only, never sent out. */
    public function findUserByEmail(string $email): ?array
    {
        $statement = $this->conn->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $statement->execute([':email' => strtolower(trim($email))]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** Public-safe user row (no password hash), or null. */
    public function getUserById(int $userId): ?array
    {
        $statement = $this->conn->prepare('SELECT user_id, name, email FROM users WHERE user_id = :id LIMIT 1');
        $statement->execute([':id' => $userId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        return ['userId' => (int) $row['user_id'], 'name' => $row['name'], 'email' => $row['email']];
    }

    /** Returns the public-safe user row on a correct password, else null. */
    public function verifyUser(string $email, string $password): ?array
    {
        $user = $this->findUserByEmail($email);
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            return null;
        }
        return ['userId' => (int) $user['user_id'], 'name' => $user['name'], 'email' => $user['email']];
    }

    /** Fail-closed ownership check: legacy NULL-owner devices belong to nobody. */
    public function deviceOwnedBy(int $deviceId, int $userId): bool
    {
        $statement = $this->conn->prepare(
            'SELECT 1 FROM devices WHERE device_id = :id AND user_id = :user LIMIT 1'
        );
        $statement->execute([':id' => $deviceId, ':user' => $userId]);
        return (bool) $statement->fetchColumn();
    }

    /** The 3 starter devices every new account gets (same as the old demo set). */
    private function seedStarterDevices(int $userId): void
    {
        $seed = [
            ['Living room TV', 'Smart TV', 'Samsung', 'connected', 'HDMI 1'],
            ['Bedroom TV', 'Smart TV', 'LG', 'offline', 'HDMI 2'],
            ['Soundbar', 'Audio', 'Sonos', 'connected', 'Optical'],
        ];
        $insert = $this->conn->prepare(
            'INSERT INTO devices (user_id, device_name, device_type, brand, connection_status, source_input)
             VALUES (:user, :name, :type, :brand, :status, :source)'
        );
        $deviceIds = [];
        foreach ($seed as $device) {
            $insert->execute([
                ':user' => $userId,
                ':name' => $device[0],
                ':type' => $device[1],
                ':brand' => $device[2],
                ':status' => $device[3],
                ':source' => $device[4],
            ]);
            $deviceIds[] = (int) $this->conn->lastInsertId();
        }
        $appInsert = $this->conn->prepare(
            'INSERT INTO app_shortcuts (device_id, app_name, app_icon, launch_url) VALUES (:device, :name, :icon, NULL)'
        );
        foreach ([[$deviceIds[0], 'Apple TV'], [$deviceIds[0], 'YouTube'], [$deviceIds[0], 'Netflix'], [$deviceIds[1], 'Netflix']] as $app) {
            $appInsert->execute([':device' => $app[0], ':name' => $app[1], ':icon' => strtolower(str_replace(' ', '', $app[1]))]);
        }
        $this->logAction($deviceIds[0], 'Remote connected');
    }

    public function createDevice(string $name, string $type, string $brand, int $userId): int
    {
        return Transaction::run($this->conn, function () use ($name, $type, $brand, $userId): int {
            $statement = $this->conn->prepare(
                'INSERT INTO devices (user_id, device_name, device_type, brand, connection_status, source_input)
                 VALUES (:user, :name, :type, :brand, :status, :source)'
            );
            $statement->execute([
                ':user' => $userId,
                ':name' => $name,
                ':type' => $type,
                ':brand' => $brand,
                ':status' => 'pairing',
                ':source' => 'Not set',
            ]);
            $id = (int) $this->conn->lastInsertId();
            $this->logAction($id, 'Device added');
            return $id;
        });
    }

    
    public function createDeviceWithApps(string $name, string $type, string $brand, array $apps, int $userId): int
    {
        return Transaction::run($this->conn, function () use ($name, $type, $brand, $apps, $userId): int {
            $statement = $this->conn->prepare(
                'INSERT INTO devices (user_id, device_name, device_type, brand, connection_status, source_input)
                 VALUES (:user, :name, :type, :brand, :status, :source)'
            );
            $statement->execute([
                ':user' => $userId,
                ':name' => $name,
                ':type' => $type,
                ':brand' => $brand,
                ':status' => 'pairing',
                ':source' => 'Not set',
            ]);
            $id = (int) $this->conn->lastInsertId();
            $this->logAction($id, 'Device added');

            try {
                Transaction::savepoint($this->conn, 'apps_batch', function () use ($id, $apps): void {
                    $appInsert = $this->conn->prepare(
                        'INSERT INTO app_shortcuts (device_id, app_name, app_icon, launch_url)
                         VALUES (:device, :name, :icon, :url)'
                    );
                    foreach ($apps as $app) {
                        $appInsert->execute([
                            ':device' => $id,
                            ':name' => (string) ($app['name'] ?? ''),
                            ':icon' => (string) ($app['icon'] ?? 'app'),
                            ':url' => $app['url'] ?? null,
                        ]);
                    }
                });
            } catch (Throwable $exception) {
                $this->logAction($id, 'App shortcuts skipped (rolled back to savepoint)');
            }

            return $id;
        });
    }

    public function disconnectDevice(int $deviceId): array
    {
        if (!$this->deviceExists($deviceId)) {
            throw new InvalidArgumentException('The selected device could not be found.');
        }
        return Transaction::run($this->conn, function () use ($deviceId): array {
            $statement = $this->conn->prepare('UPDATE devices SET connection_status = :status WHERE device_id = :id');
            $statement->execute([':status' => 'offline', ':id' => $deviceId]);
            $this->logAction($deviceId, 'Device disconnected');
            return ['deviceId' => $deviceId, 'message' => 'Device disconnected'];
        });
    }

    public function connectDevice(int $deviceId): array
    {
        if (!$this->deviceExists($deviceId)) {
            throw new InvalidArgumentException('The selected device could not be found.');
        }
        return Transaction::run($this->conn, function () use ($deviceId): array {
            $statement = $this->conn->prepare('UPDATE devices SET connection_status = :status WHERE device_id = :id');
            $statement->execute([':status' => 'connected', ':id' => $deviceId]);
            $this->logAction($deviceId, 'Device connected');
            return ['deviceId' => $deviceId, 'message' => 'Device connected'];
        });
    }

    public function removeDevice(int $deviceId): array
    {
        if (!$this->deviceExists($deviceId)) {
            throw new InvalidArgumentException('The selected device could not be found.');
        }
        // DELETE + cascaded rows (layouts/buttons/logs) = all-or-nothing.
        return Transaction::run($this->conn, function () use ($deviceId): array {
            $statement = $this->conn->prepare('DELETE FROM devices WHERE device_id = :id');
            $statement->execute([':id' => $deviceId]);
            return ['deviceId' => $deviceId, 'message' => 'Device removed'];
        });
    }

    public function runCommand(int $deviceId, string $command, ?string $value = null, ?int $userId = null): array
    {
        $allowed = [
            'power' => 'Power toggled',
            'volume-up' => 'Volume increased',
            'volume-down' => 'Volume decreased',
            'mute' => 'Mute toggled',
            'channel-up' => 'Channel increased',
            'channel-down' => 'Channel decreased',
            'home' => 'Home opened',
            'back' => 'Back pressed',
            'up' => 'Navigated up',
            'down' => 'Navigated down',
            'left' => 'Navigated left',
            'right' => 'Navigated right',
            'enter' => 'Selected',
            'play-pause' => 'Playback toggled',
            'rewind' => 'Rewind pressed',
            'forward' => 'Fast-forward pressed',
            'source' => 'Source selector opened',
            'open-app' => 'App opened',
        ];

        if (!isset($allowed[$command])) {
            throw new InvalidArgumentException('That remote action is not available.');
        }
        if (!$this->deviceExists($deviceId)) {
            throw new InvalidArgumentException('The selected device could not be found.');
        }

        $label = $allowed[$command];
        if ($command === 'open-app' && $value !== null && $value !== '') {
            $label = 'Opened ' . $value;
        }
        if ($command === 'source' && $value !== null && $value !== '') {
            $label = 'Input set to ' . $value;
        }

        Transaction::run($this->conn, function () use ($deviceId, $command, $value, $label, $userId): void {
            $this->logAction($deviceId, $label);
            // Queue the command for the home agent, which executes it on
            // the real device (TV / soundbar / PC) and reports back.
            $statement = $this->conn->prepare(
                'INSERT INTO remote_commands (device_id, user_id, command, value, status)
                 VALUES (:device, :user, :command, :value, :status)'
            );
            $statement->execute([
                ':device' => $deviceId,
                ':user' => $userId,
                ':command' => $command,
                ':value' => $value,
                ':status' => 'pending',
            ]);
        });
        return ['command' => $command, 'message' => $label];
    }

    public function addApp(int $deviceId, string $name): array
    {
        if (!$this->deviceExists($deviceId)) {
            throw new InvalidArgumentException('The selected device could not be found.');
        }
        return Transaction::run($this->conn, function () use ($deviceId, $name): array {
            $statement = $this->conn->prepare(
                'INSERT INTO app_shortcuts (device_id, app_name, app_icon, launch_url)
                 VALUES (:device, :name, :icon, NULL)'
            );
            $statement->execute([':device' => $deviceId, ':name' => $name, ':icon' => 'app']);
            $this->logAction($deviceId, 'Shortcut added: ' . $name);
            return ['appId' => (int) $this->conn->lastInsertId(), 'message' => 'Shortcut added'];
        });
    }

    public function setSource(int $deviceId, string $source, ?int $userId = null): array
    {
        if (!$this->deviceExists($deviceId)) {
            throw new InvalidArgumentException('The selected device could not be found.');
        }
        Transaction::run($this->conn, function () use ($deviceId, $source): void {
            $statement = $this->conn->prepare('UPDATE devices SET source_input = :source WHERE device_id = :id');
            $statement->execute([':source' => $source, ':id' => $deviceId]);
        });
        // Route through runCommand so the change is logged AND queued
        // for the home agent like any other command.
        return $this->runCommand($deviceId, 'source', $source, $userId);
    }

    /** Home agent: fetch pending commands (oldest first) and mark them picked. */
    public function pollCommands(int $limit = 10): array
    {
        return Transaction::run($this->conn, function () use ($limit): array {
            $statement = $this->conn->prepare(
                "SELECT c.command_id, c.device_id, c.command, c.value,
                        d.device_name, d.brand, d.device_type
                 FROM remote_commands c
                 JOIN devices d ON d.device_id = c.device_id
                 WHERE c.status = 'pending'
                 ORDER BY c.command_id ASC LIMIT " . (int) $limit
            );
            $statement->execute();
            $rows = $statement->fetchAll();
            if ($rows) {
                $ids = array_map(static fn (array $row): int => (int) $row['command_id'], $rows);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $update = $this->conn->prepare("UPDATE remote_commands SET status = 'picked' WHERE command_id IN ($placeholders)");
                $update->execute($ids);
            }
            return $rows;
        });
    }

    /** Home agent: record the outcome of one queued command. */
    public function reportCommand(int $commandId, bool $ok, ?string $error = null): void
    {
        $statement = $this->conn->prepare(
            'UPDATE remote_commands SET status = :status, error = :error, executed_at = CURRENT_TIMESTAMP
             WHERE command_id = :id'
        );
        $statement->execute([
            ':status' => $ok ? 'done' : 'error',
            ':error' => $error !== null ? substr($error, 0, 250) : null,
            ':id' => $commandId,
        ]);
    }

    /** Home agent: heartbeat ping (single row). */
    public function agentHeartbeat(): void
    {
        if ($this->driver() === 'mysql') {
            $this->conn->exec(
                'INSERT INTO remote_agent (agent_id, last_seen) VALUES (1, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE last_seen = CURRENT_TIMESTAMP'
            );
        } else {
            $this->conn->exec(
                'INSERT INTO remote_agent (agent_id, last_seen) VALUES (1, CURRENT_TIMESTAMP)
                 ON CONFLICT(agent_id) DO UPDATE SET last_seen = CURRENT_TIMESTAMP'
            );
        }
    }

    /** True when the home agent has pinged within the last 45 seconds. */
    public function agentOnline(): bool
    {
        $row = $this->conn->query('SELECT last_seen FROM remote_agent WHERE agent_id = 1')->fetch();
        if (!$row) {
            return false;
        }
        $seen = strtotime((string) $row['last_seen']);
        return $seen !== false && (time() - $seen) < 45;
    }

    public function setTheme(int $deviceId, int $themeId): void
    {
        if (!$this->deviceExists($deviceId)) {
            throw new InvalidArgumentException('The selected device could not be found.');
        }
        if (!$this->themeExists($themeId)) {
            throw new InvalidArgumentException('The selected theme could not be found.');
        }
        Transaction::run($this->conn, function () use ($deviceId, $themeId): void {
            if ($this->driver() === 'mysql') {
                $statement = $this->conn->prepare(
                    'INSERT INTO settings (device_id, setting_name, setting_value)
                     VALUES (:device, :name, :value)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
                );
            } else {
                $statement = $this->conn->prepare(
                    'INSERT INTO settings (device_id, setting_name, setting_value)
                     VALUES (:device, :name, :value)
                     ON CONFLICT(device_id, setting_name) DO UPDATE SET setting_value = excluded.setting_value'
                );
            }
            $statement->execute([':device' => $deviceId, ':name' => 'theme_id', ':value' => (string) $themeId]);
            $this->logAction($deviceId, 'Theme updated');
        });
    }

    private function logAction(int $deviceId, string $action): void
    {
        $statement = $this->conn->prepare('INSERT INTO activity_logs (device_id, action, timestamp) VALUES (:device, :action, CURRENT_TIMESTAMP)');
        $statement->execute([':device' => $deviceId, ':action' => $action]);
    }

    private function deviceExists(int $deviceId): bool
    {
        $statement = $this->conn->prepare('SELECT 1 FROM devices WHERE device_id = :id LIMIT 1');
        $statement->execute([':id' => $deviceId]);
        return (bool) $statement->fetchColumn();
    }

    private function themeExists(int $themeId): bool
    {
        $statement = $this->conn->prepare('SELECT 1 FROM themes WHERE theme_id = :id LIMIT 1');
        $statement->execute([':id' => $themeId]);
        return (bool) $statement->fetchColumn();
    }

    private function all(string $query): array
    {
        return $this->conn->query($query)->fetchAll();
    }

    private function driver(): string
    {
        return (string) $this->conn->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function ensureSchema(): void
    {
        if ($this->driver() === 'mysql') {
            $schemaPath = __DIR__ . '/../database/schema.sql';
            $this->conn->exec((string) file_get_contents($schemaPath));
            // Databases created before the per-user update have no user_id
            // columns (CREATE TABLE IF NOT EXISTS cannot add them), so
            // migrate idempotently on every boot. Fresh installs already
            // have the columns + foreign keys from schema.sql.
            $this->addColumnIfMissing('devices', 'user_id', 'INT UNSIGNED NULL');
            $this->addColumnIfMissing('remote_commands', 'user_id', 'INT UNSIGNED NULL');
            return;
        }

        // Needed so ON DELETE CASCADE works in SQLite (off by default).
        $this->conn->exec('PRAGMA foreign_keys = ON');

        $statements = [
            'CREATE TABLE IF NOT EXISTS users (user_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE TABLE IF NOT EXISTS devices (device_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, device_name TEXT NOT NULL, device_type TEXT NOT NULL, brand TEXT NOT NULL, connection_status TEXT NOT NULL DEFAULT "connected", source_input TEXT NOT NULL DEFAULT "HDMI 1", created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(user_id) REFERENCES users(user_id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS themes (theme_id INTEGER PRIMARY KEY AUTOINCREMENT, theme_name TEXT NOT NULL UNIQUE, color_scheme TEXT NOT NULL DEFAULT "midnight")',
            'CREATE TABLE IF NOT EXISTS remote_layouts (layout_id INTEGER PRIMARY KEY AUTOINCREMENT, device_id INTEGER NOT NULL, theme_id INTEGER NULL, layout_name TEXT NOT NULL, mode TEXT NOT NULL DEFAULT "standard", FOREIGN KEY(device_id) REFERENCES devices(device_id) ON DELETE CASCADE, FOREIGN KEY(theme_id) REFERENCES themes(theme_id) ON DELETE SET NULL)',
            'CREATE TABLE IF NOT EXISTS buttons (button_id INTEGER PRIMARY KEY AUTOINCREMENT, layout_id INTEGER NOT NULL, button_label TEXT NOT NULL, button_function TEXT NOT NULL, position_order INTEGER NOT NULL DEFAULT 0, FOREIGN KEY(layout_id) REFERENCES remote_layouts(layout_id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS settings (setting_id INTEGER PRIMARY KEY AUTOINCREMENT, device_id INTEGER NOT NULL, setting_name TEXT NOT NULL, setting_value TEXT NOT NULL, UNIQUE(device_id, setting_name), FOREIGN KEY(device_id) REFERENCES devices(device_id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS app_shortcuts (app_id INTEGER PRIMARY KEY AUTOINCREMENT, device_id INTEGER NOT NULL, app_name TEXT NOT NULL, app_icon TEXT NOT NULL DEFAULT "app", launch_url TEXT, FOREIGN KEY(device_id) REFERENCES devices(device_id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS channels (channel_id INTEGER PRIMARY KEY AUTOINCREMENT, device_id INTEGER NOT NULL, channel_number TEXT NOT NULL, channel_name TEXT NOT NULL, FOREIGN KEY(device_id) REFERENCES devices(device_id) ON DELETE CASCADE)',
            'CREATE TABLE IF NOT EXISTS activity_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT, device_id INTEGER NOT NULL, action TEXT NOT NULL, timestamp TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(device_id) REFERENCES devices(device_id) ON DELETE CASCADE)',
            'CREATE INDEX IF NOT EXISTS idx_log_device_time ON activity_logs (device_id, timestamp)',
            'CREATE TABLE IF NOT EXISTS remote_commands (command_id INTEGER PRIMARY KEY AUTOINCREMENT, device_id INTEGER NOT NULL, user_id INTEGER NULL, command TEXT NOT NULL, value TEXT NULL, status TEXT NOT NULL DEFAULT "pending", error TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, executed_at TEXT NULL, FOREIGN KEY(device_id) REFERENCES devices(device_id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(user_id) ON DELETE CASCADE)',
            'CREATE INDEX IF NOT EXISTS idx_command_status ON remote_commands (status)',
            'CREATE TABLE IF NOT EXISTS remote_agent (agent_id INTEGER PRIMARY KEY, last_seen TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
        ];
        foreach ($statements as $statement) {
            $this->conn->exec($statement);
        }
        // Local SQLite files created before the per-user update.
        $this->addColumnIfMissing('devices', 'user_id', 'INTEGER NULL');
        $this->addColumnIfMissing('remote_commands', 'user_id', 'INTEGER NULL');

        $this->conn->exec("INSERT OR IGNORE INTO themes (theme_id, theme_name, color_scheme) VALUES (1, 'Midnight', 'midnight'), (2, 'Aurora', 'aurora')");
        // NOTE: no global device seed anymore -- each account gets its own
        // starter devices at registration (see registerUser()).
    }

    /** Idempotent column migration for databases created before a column existed. */
    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        try {
            $result = $this->conn->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            if ($result !== false) {
                return;
            }
            $info = $this->conn->errorInfo();
            $message = (string) ($info[2] ?? '');
            if (stripos($message, 'duplicate column') !== false) {
                return;
            }
            throw new RuntimeException($message !== '' ? $message : "Could not add column {$column} to {$table}.");
        } catch (PDOException $exception) {
            // MySQL: "Duplicate column name 'user_id'".
            // SQLite: "duplicate column name: user_id".
            if (stripos($exception->getMessage(), 'duplicate column') !== false) {
                return;
            }
            throw $exception;
        }
    }
}
?>

