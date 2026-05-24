# HetznerStorageBox — Paymenter Extension

A [Paymenter](https://paymenter.org) server extension that provisions and manages [Hetzner Storage Boxes](https://www.hetzner.com/storage/storage-box/) as a sellable hosting product.

Each customer service maps 1:1 to a dedicated Hetzner Storage Box. Provisioning, suspension, unsuspension, plan changes, termination, and password resets are all fully automated via the Hetzner Cloud API.

## Features

- Provisions a dedicated Storage Box per service on payment
- Supports all four Hetzner Storage Box plans (BX11–BX41)
- Configurable datacenter location per product (Falkenstein, Nuremberg, Helsinki)
- Enables secure protocols only: SSH/SFTP, Samba/CIFS, WebDAV (HTTPS), FTPS
- Optional SSH public key injection at checkout (RSA, ECDSA, Ed25519)
- Credentials emailed to the customer on provision — passwords are never stored
- Customer-triggered password regeneration from the service panel
- Suspend/unsuspend by toggling external reachability (data always preserved)
- Upgrade and downgrade between Storage Box plans
- Clean termination deletes the Storage Box via API

## Requirements

- Paymenter v1.4.0 or later
- PHP 8.2 or later
- A Hetzner Cloud account with a project and a Read & Write API token
- Laravel Mail configured on your Paymenter installation (used to deliver credentials)

## Installation

### Via Paymenter admin panel

1. Download the latest release zip from the [releases page](https://github.com/sa6bom/paymenter-hetzner-storagebox/releases)
2. In the Paymenter admin panel go to **Extensions → Upload Extension** and upload the zip
3. Enable the extension — this will automatically run the database migration

### Manual installation

```bash
cd /opt/paymenter/current
mkdir -p app/Extensions/Servers/HetznerStorageBox
unzip HetznerStorageBox-v0.1.0.zip -d app/Extensions/Servers/
php artisan app:extension:enable HetznerStorageBox
php artisan migrate
```

## Configuration

### 1. Create a Hetzner Cloud API token

In the [Hetzner Cloud Console](https://console.hetzner.cloud), open your project and go to **Security → API Tokens → Generate API Token**. Select **Read & Write** permission.

### 2. Configure the extension server entry

In Paymenter admin go to **Servers → New Server** and select **HetznerStorageBox** as the extension. Fill in:

| Field | Description |
|---|---|
| Hetzner Cloud API Token | The token generated above |
| Default Location | Datacenter to use when a product has no location override (fsn1 / nbg1 / hel1) |

### 3. Configure a product

Create or edit a product and select your HetznerStorageBox server. Under the **Server** tab configure:

| Field | Description |
|---|---|
| Storage Box Plan | BX11 (1 TB), BX21 (5 TB), BX31 (10 TB), or BX41 (20 TB) |
| Location Override | Optional — leave blank to use the extension default |

### 4. Checkout options

Customers can optionally paste an SSH public key during checkout. If provided, the key is injected into the Storage Box at creation time via the Hetzner API. If not provided, the customer receives password credentials by email and can add SSH keys themselves afterward.

## Customer panel

After provisioning, the customer's service page shows:

- **Hostname** — e.g. `u45321.your-storagebox.de`
- **Username** — e.g. `u45321`
- **Protocols** — a reminder of what is enabled
- **Plan** — the current Storage Box type
- **Regenerate Password** — resets the password via the Hetzner API and emails the new credentials immediately. Use this if credentials are lost or compromised.

## Connecting to the Storage Box

### SFTP

```bash
sftp -P 23 u45321@u45321.your-storagebox.de
```

### rsync / BorgBackup

```bash
rsync -av -e "ssh -p 23" ./data/ u45321@u45321.your-storagebox.de:/
```

### WebDAV

Connect to `https://u45321.your-storagebox.de` with your username and password in any WebDAV client.

### Samba/CIFS

Mount `\\u45321.your-storagebox.de\u45321` with your username and password.

### Adding SSH keys after provisioning

If no SSH key was provided at checkout, connect with your password and run:

```bash
cat ~/.ssh/id_ed25519.pub | ssh -p23 u45321@u45321.your-storagebox.de install-ssh-key
```

## Password policy

Hetzner enforces the following password rules. The extension generates compliant passwords automatically:

- 12–128 characters
- Must contain at least one uppercase letter, one lowercase letter, one digit, and one special character
- Only the following special characters are allowed: `^ ° ! § $ % / ( ) = ? + # - . , ; : ~ * @ { } _ &`
- Must not have appeared in known data breaches

## Security notes

- Passwords are generated at provision/reset time, emailed once, and immediately discarded — they are never written to the database
- SSH public keys provided at checkout are passed directly to the Hetzner API and not stored in Paymenter
- Only secure access protocols are enabled: insecure FTP and plain HTTP WebDAV are not activated
- Suspension disables external reachability via the Hetzner API — data is preserved and access is restored instantly on unsuspension

## Known limitations / v0.1.0 status

This is an initial release. The following are known areas for further testing and improvement:

- Action polling uses a simple sleep loop with a 120-second timeout — adequate for most operations but not suited to high-concurrency environments
- No retry logic on transient API errors
- The `regeneratePassword` action return value (a string message) depends on Paymenter rendering it in the UI — verify this behaves as expected in your Paymenter version
- FTPS availability depends on Hetzner's network-level configuration, not the access settings API

## Changelog

### v0.1.0
- Initial release

## License

MIT — see [LICENSE](LICENSE)

## Author

[Markus Morén](https://moren.it) / [sa6bom](https://github.com/sa6bom)
