<?php
declare(strict_types=1);

/* Copy this file to backend/agent_config.php (same folder) and set a
   long random secret (32+ characters). agent_config.php is gitignored -
   never commit the real secret. The home agent must use the EXACT same
   secret in its config.json ("api_secret"). Treat it like a password:
   anyone who has it can send commands to your devices. */

define('REMOTE_AGENT_SECRET', 'CHANGE_ME_to_a_long_random_string_32_chars_or_more');
