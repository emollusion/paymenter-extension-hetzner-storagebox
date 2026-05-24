<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $isReset ? 'Your Storage Box — New Credentials' : 'Your Storage Box is Ready' }}</title>
</head>
<body style="font-family: sans-serif; color: #222; max-width: 600px; margin: 0 auto; padding: 24px;">

    <h2 style="color: #d50c2d;">
        {{ $isReset ? 'Your new Storage Box credentials' : 'Your Storage Box is ready' }}
    </h2>

    <p>Hi {{ $user->name ?? $user->email }},</p>

    @if ($isReset)
        <p>Your Storage Box password has been reset as requested. Your new credentials are below.</p>
    @else
        <p>Your Hetzner Storage Box has been provisioned and is ready to use.</p>
    @endif

    <table style="border-collapse: collapse; width: 100%; margin: 24px 0;">
        <tr>
            <td style="padding: 8px 12px; background: #f5f5f5; font-weight: bold; width: 140px;">Hostname</td>
            <td style="padding: 8px 12px; font-family: monospace;">{{ $hostname }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 12px; background: #f5f5f5; font-weight: bold;">Username</td>
            <td style="padding: 8px 12px; font-family: monospace;">{{ $username }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 12px; background: #f5f5f5; font-weight: bold;">Password</td>
            <td style="padding: 8px 12px; font-family: monospace;">{{ $password }}</td>
        </tr>
    </table>

    <h3>Connecting to your Storage Box</h3>

    <p><strong>SFTP (recommended)</strong></p>
    <pre style="background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto;">sftp -P 23 {{ $username }}@{{ $hostname }}</pre>

    <p><strong>WebDAV</strong> — connect to <code>https://{{ $hostname }}</code> with your username and password.</p>
    <p><strong>Samba/CIFS</strong> — mount <code>\\{{ $hostname }}\{{ $username }}</code>.</p>
    <p><strong>FTPS</strong> — connect to <code>{{ $hostname }}</code> on port 21 with explicit TLS.</p>

    <h3>Adding an SSH key (optional)</h3>
    <p>To enable passwordless authentication, connect with your password and run:</p>
    <pre style="background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto;">cat ~/.ssh/id_ed25519.pub | ssh -p23 {{ $username }}@{{ $hostname }} install-ssh-key</pre>

    <p style="margin-top: 32px; color: #666; font-size: 0.9em;">
        If you did not request a password reset, please contact support immediately.<br>
        You can regenerate your password at any time from your client portal.
    </p>

    <p>— Morén IT</p>

</body>
</html>
