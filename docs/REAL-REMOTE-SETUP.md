# Universal Remote — Himoong Tinuod nga Remote (Setup, Bisaya)

_Gihimo ni Muse para ni Edsiel, 2026-10-23. Human ani nga setup, ang
mga buttons sa website dili na demo: moabot gyud ang command sa imong
TV / soundbar pinaagi sa gamay nga **agent** nga modagan sa imong PC._

```
[Website] -> [MySQL: remote_commands] <-poll- [Agent sa PC] --Wi-Fi--> [TV / Sonos]
```

**Gasto: ₱0.** Kinahanglan: Windows PC sa balay (parehas og Wi-Fi sa
TV), Python, ~15 minutos.

> ⚠️ **Pasidaan sa hits:** ang agent mo-poll matag 2 segundos (~43,000
> ka requests/adlaw) samtang abli siya. Kung duol na sa limit sa free
> InfinityFree, ipataas ang `poll_seconds` sa `agent/config.json`
> ngadto sa 3–5.

---

## Lakang 1 — Server: secret para sa agent

1. Sa `backend/`, i-copy ang `agent_config.example.php` →
   **`agent_config.php`** (sa InfinityFree File Manager o sa imong
   local copy nga i-deploy).
2. Ilisi ang `REMOTE_AGENT_SECRET` og **taas nga random string
   (32+ chars)**. Mao ni ang password sa agent — ayaw i-share, ug
   **dili** ni i-commit sa GitHub (naa na sa .gitignore).

## Lakang 2 — Server: database

Wala nay kinahanglan i-import nga bag-o: ang bag-ong tables
(`remote_commands`, `remote_agent`, `users`) naa na sa
`database/schema.sql`, ug ang repository mismo ang mohimo niini
inig dagan sa site. Kung ang imong live database gihimo sa wala
pa ni nga update, i-run ang duha ka `CREATE TABLE IF NOT EXISTS`
statements para sa `remote_commands` ug `remote_agent` sa
phpMyAdmin kausa — ang `users` table ug ang `user_id` columns
idugang ra sa repository inig unang load.

## Per-user accounts (bag-o)

Kada tawo naay kaugalingong account ug kaugalingong devices:

1. Ablihi ang website → **Create one** → pun-a ang name, email,
   password (min. 8 chars). Ang password naka-hash sa database,
   dili plain text. Automatic nga makadawat kag 3 starter devices
   (Living room TV, Bedroom TV, Soundbar) — imoha na sila,
   edit/delete lang dayon kung gusto ka.
2. Ang laing user nga mo-register makadawat pud og kaugalingong
   set — dili makita o makontrol ang devices sa uban. Ang API
   mo-check sa ownership kada request, ug ang command queue
   naka-record kung kinsa ang nagpadala.
3. **Kung naa nay daan nga devices sa live database** (katong
   global pa sa una): pagkahuman nimo mo-register sa imong unang
   account, i-run kausa sa phpMyAdmin:
   ```
   UPDATE devices SET user_id = 1 WHERE user_id IS NULL;
   ```
   (Ilisi ang `1` sa user_id sa imong account kung dili ikaw ang
   una.) Kung dili ni buhaton, ang daan nga devices walay tag-iya
   ug dili makita bisan kinsa — fail-closed, tinuyo na.
4. Ang home agent **walay nausab**: household-level gihapon siya
   (usa ka agent sa balay mo-execute sa tanang queued commands,
   bisan kinsa pa ang nagpadala). Ang per-user nga proteksyon naa
   sa website/API level — ang agent mosalig sa queue.

## Lakang 3 — Agent sa imong PC

1. I-copy ang folder nga `agent` ngadto sa imong PC (e.g. Desktop).
2. Siguroha ang **Python** (python.org; i-check ang
   **"Add python.exe to PATH"**). Verify: Command Prompt →
   `python --version`.
3. Sa Command Prompt:
   ```
   cd Desktop\agent
   pip install -r requirements.txt
   ```
4. I-rename ang `config.example.json` → `config.json`, pun-a:
   - `api.site_url` = `https://remotee.infinityfreeapp.com`
   - `api.api_secret` = **parehas gyud** sa secret sa Lakang 1
   - `devices."1".ip` — IP sa Samsung TV: TV Settings → General →
     Network → Network Status → IP Settings
   - `devices."2".ip` — LG: Settings → Network → Wi-Fi → Advanced
   - `devices."3".room` — ngalan sa Sonos room (e.g. `Living Room`)
   _(Ang numero nga keys mao ang device_id sa website: 1 = Living
   room TV, 2 = Bedroom TV, 3 = Soundbar. Tip: i-reserve ang IP sa
   mga TV sa router aron dili mausab.)_
5. I-double-click ang **`run.bat`**. Dapat mo-print og
   `[agent] running`. Pasagdi nga abli ang window samtang mogamit
   sa remote.
6. **Unang gamit per TV:** mo-pop up sa TV ang pairing/Allow prompt
   sa unang command — i-press ang **Allow** sa tinuod nga remote.
   Kausa ra ni; ma-save ang token.

## Lakang 4 — Sulayi

1. Ablihi ang website, log in (o pag-register kung bag-o pa).
   Ang pill sa ibabaw dapat mo-ingon og
   **"All systems ready"** (kung "Home agent offline", wala
   modagan ang agent).
2. I-press ang Volume +. Tan-awa ang agent window: dapat mo-print
   og `[cmd] #... Living room TV -> volume-up`, ug motubag gyud
   ang TV.
3. Sulayi ang D-pad — karon sakto na ang wiring (up/down/left/
   right/OK = enter), ug ang app chips (Netflix/YouTube) mo-abli
   gyud sa app sa TV.

## Unsa'y nausab sa website (apil ani nga update)

- **Per-user accounts**: register/login/logout, kada user naay
  kaugalingong devices ug activity; ang password naka-hash; ang
  daan nga devices i-assign sa unang user (tan-awa ang Per-user
  section sa taas).
- **D-pad gi-ayo**: kaniadto ang up kay "home", left kay "back",
  right kay "forward", down kay "channel-down", ug ang OK kay
  play-pause. Karon: up/down/left/right/enter gyud.
- **App chips**: mo-send na og tinuod nga open-app command.
- **"+ Add shortcut"**: naa na'y kaugalingong dialog ug endpoint
  (dili na ang Add-device dialog ang mo-abli).
- **Source button**: mo-cycle sa inputs (HDMI 1 → 2 → 3 → TV →
  AV → Optical), ma-save sa database, ug ma-queue para sa agent.
- **Search**: mo-hide na sa tibuok device row, ug sakto na ang
  "N connected" nga numero pag-clear.
- **Per-device state**: lahi na ang volume/channel/source matag
  device; ang soundbar (audio) dili na magpakita og channel ug
  D-pad controls.
- **Tinuod nga status**: ang "All systems ready" nga pill base na
  sa heartbeat sa agent.
- **Schema seed gi-ayo**: ang devices seed INSERT kaniadto
  guba (sobra nga values) ug naay nasagol nga password-looking
  string — gitangtang na. **Kung tinuod to nga password nga
  gigamit nimo bisag asa, ilisi dayon.**

## Mga limit

- Kinahanglan **naka-on ang agent PC** para moabot ang commands.
  Kung offline ang agent, ang commands mag-queue sa database ug
  modagan ra inig balik niya.
- Ang **LG TV dili ma-power-ON** pinaagi sa API (limit sa webOS).
- Ang Samsung source switching mo-abli sa TV's source menu
  (KEY_SOURCE) — walay direct per-input jump sa ilang API.
- Ang projector (device 4) walay driver — pang-display ra siya
  sa lista hangtod butangan og driver.

## Kung naay dili mogana

- Agent mo-ingon og *"API error: Unauthorized"* → dili parehas
  ang secret sa `agent_config.php` ug `agent/config.json`.
- *"API did not return JSON"* → browser-check sa InfinityFree
  (ang Firebase relay version mao ang fallback kung magpadayon).
- TV dili mo-respond → parehas og Wi-Fi? Sakto ang IP? Gi-Allow
  ba ang pairing prompt?
- Tan-awa ang agent window: matag command ug error mo-print didto.
