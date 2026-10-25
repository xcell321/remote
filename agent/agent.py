#!/usr/bin/env python3
"""
Universal Remote - Home Agent
=============================
Runs on Edsiel's home PC (same Wi-Fi as the TV / soundbar).
Polls the website's agent API (backend/agent_api.php) for commands sent
by the Universal Remote website, executes them on the real device,
and reports the result back so the website can show the true
"Connected" state. The agent always connects OUT to the website -
nothing on the internet needs to reach into the home network.

Device drivers (all free, open-source libraries):
  - Samsung Smart TV : samsungtvws
  - LG webOS TV      : pywebostv
  - Sonos speaker    : soco
  - This PC itself   : pyautogui (media keys, volume, arrows)

Commands are rows in the MySQL table `remote_commands` (status
'pending'), written by the website in its own vocabulary
('volume-up', 'channel-down', 'open-app', ...). Every poll (default
2s) the agent fetches pending rows, translates each command for the
target device, executes it, and posts the result back. A heartbeat
is posted every 30s so the website can show the agent as online.
"""

import json
import os
import time

import requests

HERE = os.path.dirname(os.path.abspath(__file__))
CONFIG_PATH = os.path.join(HERE, "config.json")


# ---------------------------------------------------------------------------
# Website agent-API client (backend/agent_api.php, secret-authenticated)
# ---------------------------------------------------------------------------
class SiteAPI:
    """Talks to the website's agent API over HTTPS with the shared
    secret in the X-Remote-Key header."""

    def __init__(self, cfg):
        self.base = cfg["site_url"].rstrip("/") + "/backend/agent_api.php"
        self.key = cfg["api_secret"]
        self.session = requests.Session()
        self.session.headers.update({
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                          "UniversalRemoteAgent/1.0",
            "X-Remote-Key": self.key,
            "Content-Type": "application/json",
        })
        self._warmup(cfg["site_url"])

    def _warmup(self, site_url):
        # Visit the homepage once so hosting cookie-check cookies
        # (e.g. InfinityFree) are stored in this session.
        try:
            self.session.get(site_url, timeout=15)
            print("[api] session warmed up")
        except Exception as e:
            print("[api] warmup failed (continuing): %s" % e)

    def _call(self, payload):
        r = self.session.post(self.base, json=payload, timeout=15)
        ctype = r.headers.get("Content-Type", "")
        if "json" not in ctype:
            raise RuntimeError(
                "API did not return JSON (HTTP %s) - the hosting "
                "browser-check may be blocking script access" % r.status_code)
        data = r.json()
        if not data.get("ok"):
            raise RuntimeError("API error: %s" % data.get("message"))
        return data

    def poll(self):
        return self._call({"action": "poll"}).get("commands", [])

    def report(self, cmd_id, ok, error=None):
        self._call({"action": "report", "id": cmd_id, "ok": ok, "error": error})

    def heartbeat(self):
        self._call({"action": "heartbeat"})


# The website's command vocabulary -> the drivers' action vocabulary.
SITE_COMMANDS = {
    "power": "power",
    "volume-up": "volume_up",
    "volume-down": "volume_down",
    "mute": "mute",
    "channel-up": "channel_up",
    "channel-down": "channel_down",
    "home": "home",
    "back": "back",
    "up": "up",
    "down": "down",
    "left": "left",
    "right": "right",
    "enter": "enter",
    "play-pause": "play_pause",
    "rewind": "rewind",
    "forward": "fast_forward",
    "source": "source",
    "open-app": "open_app",
}


# ---------------------------------------------------------------------------
# Device drivers - lazy connect, and a failure never kills the agent
# ---------------------------------------------------------------------------
class SamsungDriver:
    """Samsung Smart TV via samsungtvws (WebSocket port 8002).
    The first connection makes the TV show an Allow prompt; the token
    is saved to samsung-token.txt so it only happens once."""

    def __init__(self, dev):
        from samsungtvws import SamsungTVWS

        self.tv = SamsungTVWS(
            host=dev["ip"],
            port=8002,
            token_file=os.path.join(HERE, "samsung-token.txt"),
            name="Universal Remote Agent",
        )
        self.shortcuts = self.tv.shortcuts()

    def execute(self, action, value):
        s = self.shortcuts
        table = {
            "power": s.power,
            "volume_up": s.volume_up,
            "volume_down": s.volume_down,
            "mute": s.mute,
            "channel_up": s.channel_up,
            "channel_down": s.channel_down,
            "up": s.up,
            "down": s.down,
            "left": s.left,
            "right": s.right,
            "enter": s.enter,
            "back": s.back,
            "home": s.home,
            "play": s.play,
            "pause": s.pause,
            "play_pause": s.play,
            "rewind": s.rewind,
            "fast_forward": s.fast_forward,
        }
        if action == "open_app":
            apps = {"netflix": "11101200001", "youtube": "111299001912"}
            app_id = apps.get(str(value).lower())
            if not app_id:
                raise ValueError("unknown app: %s" % value)
            self.tv.open_app(app_id)
            return
        if action == "source":
            # Samsung TVs have no per-input jump key over this API;
            # KEY_SOURCE opens the TV's own source menu.
            send_key = getattr(self.tv, "send_key", None)
            if send_key is None:
                raise ValueError("source switching not supported on this Samsung TV")
            send_key("KEY_SOURCE")
            return
        fn = table.get(action)
        if not fn:
            raise ValueError("unsupported action for Samsung: %s" % action)
        fn()


class LGDriver:
    """LG webOS TV via pywebostv. First register shows a pairing
    prompt on the TV; the client key is saved to lg-key.json."""

    def __init__(self, dev):
        from pywebostv.connection import WebOSClient
        from pywebostv.controls import (
            ApplicationControl,
            InputControl,
            MediaControl,
            SystemControl,
            TvControl,
        )

        key_path = os.path.join(HERE, "lg-key.json")
        store = {}
        if os.path.exists(key_path):
            with open(key_path) as f:
                store = json.load(f)
        client = WebOSClient(dev["ip"], secure=False)
        client.connect()
        for status in client.register(store):
            if status == WebOSClient.PROMPTED:
                print("[lg] >>> Look at the TV and accept the pairing prompt! <<<")
            elif status == WebOSClient.REGISTERED:
                with open(key_path, "w") as f:
                    json.dump(store, f)
                print("[lg] paired, key saved")
        self.client = client
        self.media = MediaControl(client)
        self.tvctl = TvControl(client)
        self.system = SystemControl(client)
        self.apps = ApplicationControl(client)
        self.input = InputControl(client)
        self._input_ready = False

    def _ensure_input(self):
        if not self._input_ready:
            self.input.connect_input()
            self._input_ready = True

    def execute(self, action, value):
        simple = {
            "volume_up": self.media.volume_up,
            "volume_down": self.media.volume_down,
            "mute": lambda: self.media.mute(True),
            "channel_up": self.tvctl.channel_up,
            "channel_down": self.tvctl.channel_down,
            "power": self.system.power_off,  # webOS cannot power ON via API
            "play": self.media.play,
            "pause": self.media.pause,
            "play_pause": self.media.play,
            "rewind": self.media.rewind,
            "fast_forward": self.media.fast_forward,
        }
        if action in simple:
            simple[action]()
            return
        if action == "open_app":
            wanted = str(value).lower()
            for app in self.apps.list_apps():
                title = (app.get("title") or "").lower()
                if wanted in title:
                    self.apps.launch(app)
                    return
            raise ValueError("app not found on LG TV: %s" % value)
        if action == "source":
            switch = getattr(self.tvctl, "switch_input", None)
            if switch is None:
                raise ValueError("source switching not supported on this LG TV")
            switch(str(value).replace(" ", "_"))  # "HDMI 1" -> "HDMI_1"
            return
        # Direction pad / OK / back / home go through the input socket
        pad = {"up", "down", "left", "right", "enter", "back", "home"}
        if action in pad:
            self._ensure_input()
            method = getattr(self.input, action, None)
            if method is None:
                raise ValueError("LG input has no action: %s" % action)
            method()
            return
        raise ValueError("unsupported action for LG: %s" % action)


class SonosDriver:
    """Sonos speaker via soco (UPnP on the local network)."""

    def __init__(self, dev):
        import soco

        self.dev = None
        room = (dev.get("room") or "").lower()
        for zone in soco.discover() or []:
            if room and zone.player_name.lower() == room:
                self.dev = zone
                break
            if not room:
                self.dev = zone
                break
        if self.dev is None:
            raise RuntimeError("Sonos speaker not found (room: %s)" % room)

    def execute(self, action, value):
        d = self.dev
        table = {
            "volume_up": lambda: setattr(d, "volume", min(100, d.volume + 2)),
            "volume_down": lambda: setattr(d, "volume", max(0, d.volume - 2)),
            "mute": lambda: setattr(d, "mute", not d.mute),
            "play": d.play,
            "pause": d.pause,
            "play_pause": lambda: d.pause() if d.get_current_transport_info()
            .get("current_transport_state") == "PLAYING" else d.play(),
            "next": d.next,
            "previous": d.previous,
        }
        fn = table.get(action)
        if not fn:
            raise ValueError("unsupported action for Sonos: %s" % action)
        fn()


class PCDriver:
    """Control this PC itself via pyautogui media / volume keys."""

    def __init__(self, dev):
        import pyautogui

        pyautogui.FAILSAFE = False
        self.g = pyautogui

    def execute(self, action, value):
        keys = {
            "volume_up": "volumeup",
            "volume_down": "volumedown",
            "mute": "volumemute",
            "play_pause": "playpause",
            "play": "playpause",
            "pause": "playpause",
            "next": "nexttrack",
            "previous": "prevtrack",
            "up": "up",
            "down": "down",
            "left": "left",
            "right": "right",
            "enter": "enter",
            "back": "browserback",
            "home": "home",
        }
        key = keys.get(action)
        if not key:
            raise ValueError("unsupported action for PC: %s" % action)
        self.g.press(key)


DRIVERS = {
    "samsung": SamsungDriver,
    "lg": LGDriver,
    "sonos": SonosDriver,
    "pc": PCDriver,
}


# ---------------------------------------------------------------------------
# Agent main loop
# ---------------------------------------------------------------------------
def main():
    if not os.path.exists(CONFIG_PATH):
        print("!! config.json not found.")
        print("   Copy config.example.json to config.json and fill it in first.")
        input("Press Enter to close...")
        return

    with open(CONFIG_PATH) as f:
        config = json.load(f)

    api = SiteAPI(config["api"])
    devices_cfg = config.get("devices", {})
    drivers = {}  # device_id (str) -> driver instance (lazy)
    poll_seconds = float(config.get("poll_seconds", 2))
    heartbeat_seconds = float(config.get("heartbeat_seconds", 30))
    last_heartbeat = 0

    print("[agent] running. Devices configured: %s" % ", ".join(devices_cfg))
    print("[agent] polling %s every %ss (Ctrl+C to stop)"
          % (api.base, poll_seconds))

    while True:
        try:
            now = time.time()
            if now - last_heartbeat > heartbeat_seconds:
                try:
                    api.heartbeat()
                    last_heartbeat = now
                except Exception as e:
                    print("[warn] heartbeat failed: %s" % e)

            for cmd in api.poll():
                cmd_id = int(cmd["command_id"])
                device_id = str(cmd["device_id"])
                site_command = cmd["command"]
                value = cmd.get("value")
                label = cmd.get("device_name") or device_id
                print("[cmd] #%s %s -> %s (%s)" % (cmd_id, label, site_command, value))
                if device_id not in devices_cfg:
                    api.report(cmd_id, False,
                               "no driver configured for device_id %s" % device_id)
                    continue
                action = SITE_COMMANDS.get(site_command)
                if action is None:
                    api.report(cmd_id, False, "unknown command: %s" % site_command)
                    continue
                try:
                    if device_id not in drivers:
                        dtype = devices_cfg[device_id]["type"]
                        drivers[device_id] = DRIVERS[dtype](devices_cfg[device_id])
                        print("[agent] connected to %s (%s)" % (label, dtype))
                    drivers[device_id].execute(action, value)
                    api.report(cmd_id, True)
                except Exception as e:  # a failure never kills the agent
                    print("[error] %s %s failed: %s" % (label, site_command, e))
                    try:
                        api.report(cmd_id, False, str(e))
                    except Exception:
                        pass
        except KeyboardInterrupt:
            print("\n[agent] stopped.")
            break
        except Exception as e:
            print("[warn] loop error (will retry): %s" % e)
        time.sleep(poll_seconds)


if __name__ == "__main__":
    main()
