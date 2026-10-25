-- Universal Remote schema
-- MySQL 8+ compatible. The PHP repository also creates the equivalent SQLite tables automatically.

-- Per-user accounts: every device, command and activity row belongs to
-- one user. A user is deleted -> their devices (and cascaded rows) go too.
CREATE TABLE IF NOT EXISTS users (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS devices (
    device_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    device_name VARCHAR(80) NOT NULL,
    device_type VARCHAR(50) NOT NULL,
    brand VARCHAR(50) NOT NULL,
    connection_status ENUM('connected', 'offline', 'pairing') NOT NULL DEFAULT 'connected',
    source_input VARCHAR(50) NOT NULL DEFAULT 'HDMI 1',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_device_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_device_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS themes (
    theme_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    theme_name VARCHAR(80) NOT NULL,
    color_scheme VARCHAR(30) NOT NULL DEFAULT 'midnight',
    UNIQUE KEY uq_theme_name (theme_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS remote_layouts (
    layout_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id INT UNSIGNED NOT NULL,
    theme_id INT UNSIGNED NULL,
    layout_name VARCHAR(80) NOT NULL,
    mode VARCHAR(30) NOT NULL DEFAULT 'standard',
    CONSTRAINT fk_layout_device FOREIGN KEY (device_id) REFERENCES devices(device_id) ON DELETE CASCADE,
    CONSTRAINT fk_layout_theme FOREIGN KEY (theme_id) REFERENCES themes(theme_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS buttons (
    button_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    layout_id INT UNSIGNED NOT NULL,
    button_label VARCHAR(40) NOT NULL,
    button_function VARCHAR(80) NOT NULL,
    position_order INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_button_layout FOREIGN KEY (layout_id) REFERENCES remote_layouts(layout_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_shortcuts (
    app_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id INT UNSIGNED NOT NULL,
    app_name VARCHAR(80) NOT NULL,
    app_icon VARCHAR(20) NOT NULL DEFAULT 'app',
    launch_url VARCHAR(255) NULL,
    CONSTRAINT fk_app_device FOREIGN KEY (device_id) REFERENCES devices(device_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS channels (
    channel_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id INT UNSIGNED NOT NULL,
    channel_number VARCHAR(20) NOT NULL,
    channel_name VARCHAR(80) NOT NULL,
    CONSTRAINT fk_channel_device FOREIGN KEY (device_id) REFERENCES devices(device_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    setting_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id INT UNSIGNED NOT NULL,
    setting_name VARCHAR(80) NOT NULL,
    setting_value VARCHAR(120) NOT NULL,
    UNIQUE KEY uq_device_setting (device_id, setting_name),
    CONSTRAINT fk_setting_device FOREIGN KEY (device_id) REFERENCES devices(device_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_logs (
    log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id INT UNSIGNED NOT NULL,
    action VARCHAR(100) NOT NULL,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_device FOREIGN KEY (device_id) REFERENCES devices(device_id) ON DELETE CASCADE,
    INDEX idx_log_device_time (device_id, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO themes (theme_id, theme_name, color_scheme) VALUES
(1, 'Midnight', 'midnight'), (2, 'Aurora', 'aurora');

-- NOTE: devices are no longer seeded globally. Each account gets its own
-- starter devices (Living room TV, Bedroom TV, Soundbar) at registration
-- time -- see RemoteRepository::registerUser(). Existing live databases:
-- assign the old global devices to the first user once, e.g.
--   UPDATE devices SET user_id = 1 WHERE user_id IS NULL;

-- Real-device command queue: the website inserts rows (status 'pending'),
-- the home agent polls, executes them on the real device, and reports back.
CREATE TABLE IF NOT EXISTS remote_commands (
    command_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    command VARCHAR(50) NOT NULL,
    value VARCHAR(100) NULL,
    status ENUM('pending', 'picked', 'done', 'error') NOT NULL DEFAULT 'pending',
    error VARCHAR(250) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    executed_at TIMESTAMP NULL,
    CONSTRAINT fk_command_device FOREIGN KEY (device_id) REFERENCES devices(device_id) ON DELETE CASCADE,
    CONSTRAINT fk_command_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_command_status (status),
    INDEX idx_command_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Single-row heartbeat so the website can show whether the home agent is online.
CREATE TABLE IF NOT EXISTS remote_agent (
    agent_id TINYINT UNSIGNED PRIMARY KEY,
    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
