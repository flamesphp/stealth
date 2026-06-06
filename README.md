# Stealth

Browser automation for Flames via Chrome DevTools Protocol (CDP).

## Usage

```php
use Flames\Stealth\Stealth;

$browser = Stealth::connect();
$browser->navigate('https://example.com');

echo $browser->title();
echo $browser->content();

$browser->close();
```

Connection defaults are read from `.env`:

- `STEALTH_HOST` — Docker service name (default: `stealth`)
- `STEALTH_REMOTE_PORT` — CDP port inside Docker (default: `9222`)
- `STEALTH_REMOTE_PORT_FORWARDED` — host-mapped port outside Docker
- `STEALTH_VNC_PORT` — raw VNC port inside Docker (default: `5900`)
- `STEALTH_VNC_PORT_FORWARDED` — fallback host VNC port when `STEALTH_VNC_PORT` is unset (default: `15900`)
- `STEALTH_VNC_WEBVIEW_PORT` — noVNC web UI port (default: `6080`)
- `STEALTH_VNC_WEBVIEW_PORT_FORWARDED` — fallback host web UI when `STEALTH_VNC_WEBVIEW_PORT` is unset (default: `15901`)

## VNC Web Viewer

Open `http://localhost:86080` (or the forwarded web port) to watch the browser.
No username or password is required. CORS and `frame-ancestors *` are enabled for iframe embedding.