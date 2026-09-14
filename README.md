# Nawasara Teleport

Teleport SSH SSO admin dashboard for the Nawasara superapp framework. It lists nodes/users/roles, launches browser-based SSH terminals with auto-login impersonation from Keycloak, and audits every access for forensic accountability.

## Features

- Node inventory: lists all SSH nodes registered in the Teleport cluster with online/offline status, hostname, address, labels, and version
- User and role browser: lists Teleport users with their roles and traits, and lists roles with the full RBAC YAML expanded into a detail modal
- Browser SSH terminal: click [Connect] on any node and the terminal opens in a new tab with `xterm.js`, logged in automatically as the admin's Keycloak identity (no password, no key, no separate Teleport credential)
- Reconnect button: when the WebSocket drops (network blip, shell exit, idle timeout), an in-place reconnect mints a fresh cert and rewires the WebSocket without leaving the page
- SSH session audit log: every launch and reconnect attempt is logged to `nawasara_teleport_sessions` with reason, IP, user agent, ticket UUID, and (on close) duration in seconds
- Sessions viewer page: `/nawasara-teleport/sessions` with status filter, node filter, actor filter, search, time-window, hero stats (total/issued/failed/avg duration), and CSV export
- Cross-package audit: the `nawasara/audit` impersonation log shows Teleport sessions alongside webmail/cPanel launch-as events for unified review

## Architecture

This package is the Laravel half of a two-tier system. The other half is [`nawasara/teleport-bridge`](https://github.com/nawasara/teleport-bridge), a Go sidecar that wraps the Teleport API and bridges the WebSocket to the SSH session.

```
Browser           Laravel (this package)         Sidecar (Go)            Teleport
   │                      │                          │                       │
   │  [Connect] click     │                          │                       │
   ├─────────────────────▶│  POST /api/connect       │                       │
   │                      ├─────────────────────────▶│  GenerateUserCerts    │
   │                      │                          ├──────────────────────▶│
   │                      │                          │◀──────────────────────│
   │                      │◀─ {ticket_id, ws_url}    │                       │
   │  open new tab + ws   │                          │                       │
   ├──────────────────────┼─────────────────────────▶│  ALPN dial proxy:443  │
   │                      │                          ├──────────────────────▶│
   │  ◀═══════════════════╪══════════════════════════╪═══ SSH session ═══════│
```

## Installation

```bash
composer require nawasara/teleport
php artisan migrate
php artisan db:seed --class="Nawasara\Teleport\Database\Seeders\PermissionSeeder" --force
```

The package is auto-discovered by Laravel.

## Sidecar setup

This package will not function without the Go sidecar reachable. Set up the sidecar first:

1. Clone `nawasara/teleport-bridge` and follow its [setup guide](https://github.com/nawasara/teleport-bridge#setup-guide)
2. The sidecar needs:
   - `BRIDGE_SECRET`: shared HMAC secret (also stored in Vault on the Laravel side)
   - `TELEPORT_PROXY`: your Teleport proxy hostname:port (e.g. `teleport.example.com:443`)
   - `identity.pem`: Teleport identity file generated via `tsh login` for a dedicated bot user
3. Verify the sidecar is reachable: `curl http://127.0.0.1:9181/api/health` should return `200` with `cluster_name`

## Storing credentials in Vault

1. Open Nawasara → `/nawasara-vault`
2. Choose the **Teleport** group
3. Fill in:
   - **Bridge URL**: sidecar HTTP endpoint, e.g. `http://127.0.0.1:9181`
   - **Bridge Secret**: same HMAC secret set in `BRIDGE_SECRET` on the sidecar
   - **Proxy Address**: Teleport proxy hostname:port, used for display only
4. Save and click **Test Connection**. It should return `Connected to cluster: <cluster_name>`

The package picks up credentials from Vault automatically.

## Verification

Open **Teleport → Nodes** in the sidebar. Your registered SSH nodes should appear with their online status. Click [Connect] on any node to launch a browser SSH terminal.

If something fails:

- Check the sidecar is running: `curl http://127.0.0.1:9181/api/health`
- Check the identity file is valid: on the sidecar host, run the `check-identity-ttl.sh` script bundled with the bridge
- Check Vault credentials match the sidecar `.env`
- Check the admin user has the `teleport.ssh.connect` permission

## Permissions

| Permission | Description |
|---|---|
| `teleport.node.view` | List Teleport nodes |
| `teleport.user.view` | List Teleport users |
| `teleport.role.view` | List Teleport roles |
| `teleport.ssh.connect` | Launch browser SSH terminal (impersonate as Keycloak username) |
| `teleport.session.view` | View SSH session audit log (read-only, meant for compliance reviewers) |

All permissions are auto-assigned to the `developer` role by the seeder.

`teleport.session.view` is intentionally separate from `teleport.ssh.connect` so auditors can read who accessed what without gaining the ability to launch new SSH sessions.

## Routes

| Method | URL | Permission | Purpose |
|---|---|---|---|
| GET | `/nawasara-teleport/nodes` | `teleport.node.view` | Node inventory + Connect launcher |
| GET | `/nawasara-teleport/users` | `teleport.user.view` | Teleport user listing |
| GET | `/nawasara-teleport/roles` | `teleport.role.view` | Teleport role listing |
| GET | `/nawasara-teleport/sessions` | `teleport.session.view` | SSH session audit log |
| GET | `/nawasara-teleport/terminal/{ticket}` | `teleport.ssh.connect` | Fullscreen terminal page (xterm.js + ws) |
| POST | `/nawasara-teleport/terminal/{ticket}/reissue` | `teleport.ssh.connect` | Mint fresh ticket for in-place reconnect |
| POST | `/api/internal/teleport/session-closed` | HMAC bearer (sidecar only) | Webhook receiver for ws-close enrichment (duration_seconds) |

## How auto-login works

1. Admin logs in to Nawasara via Keycloak SSO (handled by `nawasara/core`)
2. Admin clicks [Connect] on a node, and Livewire opens a confirmation modal asking for an access reason
3. On submit, Laravel calls the sidecar `/api/connect` with the admin's Keycloak username, target node, and OS login
4. Sidecar mints an ed25519 ephemeral keypair, signs an SSH cert via the Teleport `GenerateUserCerts` API with the username field set to the Keycloak identity, and issues a single-use UUID v7 ticket
5. Browser opens a new tab with the ticket in the URL; the backend serves a standalone xterm.js page; JS opens a WebSocket to the sidecar
6. Sidecar dials the Teleport proxy via TLS routing (ALPN protocol `teleport-proxy-ssh`), runs the `proxy:host:0@cluster` subsystem, stacks a second SSH client over the pipe, and bridges bytes between the WebSocket and the SSH session

The audit row is written before the cert is minted (so failed launches still appear), and updated on `ws.onclose` with `duration_seconds` and `ended_reason` via a webhook from the sidecar to Laravel.

## Author

**Pringgo J. Saputro** &lt;odyinggo@gmail.com&gt;

## License

MIT
