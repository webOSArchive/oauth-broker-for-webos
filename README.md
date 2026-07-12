# webOS OAuth Broker

A tiny, dependency-free PHP service that lets **legacy webOS apps sign in to modern
services**. One deployment (e.g. `https://oauth.wosa.link`) can broker logins for many apps —
`/box`, `/instapaper`, `/yourapp`, … — each configured with a small file.

It generalizes the pattern proven by
[webOSArchive/instapaper-auth](https://github.com/webOSArchive/instapaper-auth): the device
shows a short code, the user finishes signing in on a real browser, and the device polls for
the resulting tokens.

## Why this exists

webOS devices (TouchPad, Pre, …) can't do modern OAuth themselves:

- their **2009-era TLS stack can't even handshake** with today's OAuth endpoints, and
- their **browsers can't render** modern consent screens (they go blank).

So we move *all* of OAuth off the device and onto a helper the device *can* reach. The device
only ever talks to the broker; the broker does every TLS call to the provider and holds the
client secret.

## The flow

```
  ┌─ webOS device ─┐        ┌──── oauth.wosa.link (broker) ────┐      ┌─ provider ─┐
  │                │        │                                  │      │            │
  │ 1. get-code ───┼───────►│  mint code, park pending record  │      │            │
  │   show "BKF7Q" │        │                                  │      │            │
  │   + the URL    │        │                                  │      │            │
  │                │        │                                  │      │            │
  │        (user opens the URL on a phone/PC, enters the code) │      │            │
  │                │        │  2. start → authorize redirect ──┼─────►│  consent   │
  │                │        │  3. callback ← code ◄────────────┼──────┤  screen    │
  │                │        │     exchange code → tokens ──────┼─────►│  token ep  │
  │                │        │     park tokens against code     │      │            │
  │ 4. check-code ─┼───────►│  return tokens once, then delete │      │            │
  │   store tokens │        │                                  │      │            │
  │ 5. refresh ────┼───────►│  refresh with client secret ─────┼─────►│  token ep  │
  └────────────────┘        └──────────────────────────────────┘      └────────────┘
```

Two flow types are supported per app:

| `flow`             | For                                   | User enters on the helper page      |
|--------------------|---------------------------------------|-------------------------------------|
| `oauth2_authcode`  | Modern OAuth 2.0 (Box, Google, …)     | just the code, then approves consent|
| `oauth1_xauth`     | Legacy xAuth (Instapaper)             | the code + provider user/password   |

## Endpoints

All accept `?app=<name>` (and a pretty `/<app>/…` form if you enable the rewrite):

| Endpoint            | Who calls it       | Purpose                                            |
|---------------------|--------------------|----------------------------------------------------|
| `get-code.php`      | device             | mint a code, return `{code, useUrl, pollSeconds}`  |
| `activate.php`      | user's browser     | the "enter your code" page (pretty URL: `/<app>`)  |
| `start.php`         | user's browser     | form target → xAuth, or redirect to provider       |
| `callback.php`      | provider → browser | exchange auth code, park tokens                    |
| `check-code.php`    | device (polled)    | `{status:"pending"}` → `{status:"ready", …tokens}` |
| `refresh.php`       | device             | refresh an OAuth2 access token, server-side        |
| `index.php`         | anyone             | landing page listing configured apps               |

## Install

Requirements: PHP 7.2+ with cURL (that's it — no Composer, no framework).

```sh
git clone <this repo> oauth-broker
cd oauth-broker
cp config-example.php config.php          # then edit: base URL, cache path
```

Point a web root (or vhost) at this folder. Then add at least one app (below).

**Cache directory.** `config.php`'s `$CACHE_PATH` holds pending-login files (tokens live there
for seconds, until the device claims them). Put it **outside** the web root if you can. The
shipped `.htaccess` also 404s `apps/*/config.php` as a safety net. Make the dir writable by the
web user:

```sh
mkdir -p ../oauth-broker-cache && chown www-data:www-data ../oauth-broker-cache
```

**Pretty URLs (optional).** The shipped `.htaccess` maps `/<app>/get-code` →
`get-code.php?app=<app>` on Apache. On nginx, add:

```nginx
location ~ ^/([a-zA-Z0-9_-]+)/(get-code|check-code|refresh|start)/?$ {
    rewrite ^ /$2.php?app=$1 last;
}
location ~ ^/([a-zA-Z0-9_-]+)/?$ {
    try_files $uri /activate.php?app=$1;
}
```

If you can't do rewrites at all, everything still works via the `?app=` query form — just point
devices at `https://oauth.wosa.link/get-code.php?app=box` etc.

## Adding an app

1. `mkdir apps/myapp`
2. copy `apps/_example/config.php` to `apps/myapp/config.php`
3. fill in the flow + credentials (see the template's inline docs)
4. **OAuth2 only:** register `https://oauth.wosa.link/callback.php` as the redirect URI in the
   provider's developer console

That's the entire server side. `apps/box/config.example.php` and
`apps/instapaper/config.example.php` are worked examples of each flow.

## Security notes

- Real configs (`apps/*/config.php`) and `config.php` are git-ignored — secrets never get
  committed.
- Codes are single-use, expire after `$CACHE_TTL` (default 2h), and are drawn from a
  vowel-free/ambiguity-free alphabet so they're safe to read aloud and type.
- OAuth2 CSRF is covered by a per-attempt nonce carried in `state` and checked at the callback.
- Tokens are handed to the device exactly once, then the record is deleted.

## The device side

Any webOS app wires up in ~3 calls: `get-code` → show the code → poll `check-code` → store
tokens (and `refresh` later). See the top-level `../README.md` (or `../PATTERN.md`) for the
copy-pasteable Enyo recipe, and `boxapp` / `instapaper` for real implementations.
