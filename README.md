# Ghost1nject-Terminal

**Ghost1nject-Terminal** is a collection of compact, single-file utilities designed to support **authorized penetration testing, security assessments, evidence collection, and controlled cybersecurity laboratory activities**.

The project provides a web-based terminal combined with a technical evidence panel capable of identifying execution, filesystem, upload, transport, and shell capabilities directly from the target application context.

The latest versions also introduce significantly improved persistent shell handling, with platform-specific I/O management designed to provide more stable command execution during authorized assessments.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

![Ghost1nject-Terminal Demo](https://via.placeholder.com/800x400?text=Ghost1nject-Terminal+Preview)

<img width="1912" height="767" alt="Ghost1nject-Terminal interface" src="https://github.com/user-attachments/assets/1a4b404c-c710-4a94-bce2-939e8c457ae9" />

---

## Features

### 🖥️ Stateful Web Terminal

* Execute operating-system commands directly from the browser.
* Persistent working directory between HTTP requests.
* Support for directory navigation such as `cd`, relative paths and absolute paths.
* Command history using arrow keys.
* Clear terminal output without reloading the page.
* Combined stdout/stderr output.
* Improved handling of quoted arguments, pipelines and redirections.

---

### 📡 System & Environment Reconnaissance

The side panel automatically collects useful information without requiring additional commands.

Depending on the platform, this includes:

* Operating system
* Hostname / machine name
* Effective process identity
* Current working directory
* PHP or .NET version
* Web server information
* IIS Application Pool
* Process architecture
* Local users / shell-enabled users
* Temporary directory
* PHP disabled functions
* Relevant runtime capabilities

---

### 📁 Filesystem & Upload Evidence

Ghost1nject performs lightweight, temporary probes to determine whether the application context can write files to the current directory.

Available evidence includes:

* Directory writable status
* File creation capability
* File deletion capability
* Directory permissions where available
* Web document root
* Upload configuration
* Temporary upload directory
* Maximum upload/request size
* Runtime filesystem restrictions

Temporary probe files are removed immediately after validation.

---

### 🧪 Extension Write Probes

The utility can test whether files using potentially relevant server-side extensions can be created in the current directory.

#### Linux PHP

* `.php`
* `.phtml`
* `.phar`

#### Windows IIS / ASPX

* `.aspx`
* `.ashx`
* `.php`

These probes test **file creation only**.

They do **not** automatically execute the generated files and therefore do not claim that a particular extension is interpreted server-side.

---

### 🔎 Shell / Payload Capability Assessment

The evidence panel identifies execution and transport primitives available in the application environment.

#### Linux PHP

Execution primitives may include:

* `system`
* `exec`
* `popen`
* `passthru`
* `shell_exec`
* `proc_open`

Transport capabilities may include:

* `fsockopen`
* PHP sockets extension
* `stream_socket_client`
* `stream_select`

Available shell and terminal-related binaries may include:

* Bash
* `/bin/sh`
* Python 3
* `script`
* `socat`

The panel provides summarized ratings for:

* **Execution**
* **Transport**
* **Interactive capability**
* **Overall capability**

---

#### Windows ASPX

Execution and console capabilities include detection of:

* .NET `Process`
* `cmd.exe`
* Windows PowerShell

Transport capability includes:

* `TcpClient`
* `Socket`
* `NetworkStream`

The Windows panel also provides:

* Execution capability rating
* Transport capability rating
* Console availability
* Interactive capability estimate
* Overall capability rating

These values represent **environment capabilities only**. The evidence panel does not perform outbound connectivity tests.

---

## Persistent Shell Improvements

### Linux PHP — v1.6

The Linux callback architecture was substantially revised compared with the original implementation.

The primary method now uses a persistent shell process instead of interpreting each received command independently.

Key improvements include:

* Persistent shell process
* Persistent shell state
* Improved command parsing
* Better quoted-argument handling
* Continuous stdin/stdout/stderr relay
* Non-blocking stream handling
* `stream_select`-based I/O multiplexing
* Buffered writes
* Partial-write handling
* PTY support when available
* Automatic fallback from PTY to conventional pipes
* Correct stderr handling after PTY fallback
* EOF and disconnect detection
* Process lifecycle monitoring
* Cleaner connection teardown
* Capability-based method selection

When PTY support is available, the resulting session behaves significantly closer to a conventional interactive terminal.

When PTY is unavailable, the utility transparently falls back to standard pipes.

---

### Windows ASPX — v1.9

The ASPX reverse callback was also redesigned from the original line-by-line execution model.

Instead of launching a new `cmd.exe /c` process for every received command, the current version keeps a console process alive for the duration of the connection.

Key improvements include:

* Persistent `cmd.exe` process
* PowerShell fallback
* Persistent working directory and process state
* Continuous stdin forwarding
* Continuous stdout/stderr capture
* Asynchronous I/O using .NET Tasks
* EOF detection
* Automatic reconnect loop
* Shared output queue for stdout and stderr
* Single NetworkStream writer
* Protection against concurrent socket writes
* Multi-producer lifecycle control using `Interlocked`
* Controlled `BlockingCollection` completion
* Reliable output drain during normal shell termination
* Process cleanup after connection termination

The output path is conceptually:

```text
stdout ─┐
        ├── output queue ── single writer ── TCP connection
stderr ─┘
```

This architecture significantly reduces output interleaving and improves reliability when commands produce data simultaneously on stdout and stderr.

---

## Current Payloads

| Platform           | File                             | Language   | Current Architecture                                       |
| ------------------ | -------------------------------- | ---------- | ---------------------------------------------------------- |
| Linux Apache/Nginx | `ghostinject_linux_terminal.php` | PHP        | Persistent shell, multiplexed I/O, PTY/pipes fallback      |
| Windows IIS        | `ghostinject_win_terminal.aspx`  | ASP.NET C# | Persistent cmd/PowerShell process, asynchronous queued I/O |
| Windows PHP        | `ghostinject_win_terminal.php`   | PHP        | Legacy Windows PHP implementation                          |

---

## Usage

Deploy the appropriate file only in systems where you have explicit authorization to perform security testing.

After accessing the utility through the web server, the interface is divided into two primary areas:

### Terminal

Enter operating-system commands directly into the terminal field.

Examples:

```text
whoami
hostname
pwd
id
ls -la
ipconfig
dir
net user
```

Press `Enter` to execute.

Use the up/down arrow keys to navigate local command history.

---

### Evidence Panel

The right-side panel automatically displays information related to:

```text
SYSTEM INFO
FILESYSTEM / UPLOAD
EXTENSION WRITE PROBE
SHELL / PAYLOAD CAPABILITY
LOCAL / SHELL USERS
```

This information can be useful as supporting technical evidence during penetration-testing assessments.

---

## Reverse Callback

Start a TCP listener from the authorized testing workstation:

```bash
nc -lvnp 4444
```

Enter the testing system IP address and listening port in the **Reverse Callback** panel.

The interface reports that the callback process was launched.

A successful launch does not itself prove that outbound connectivity was established; firewall rules, routing, application-pool restrictions and host security controls may still prevent the connection.

---

## Linux PHP Requirements

Recommended environment:

* Linux
* PHP 5.6+ / PHP 7.x / PHP 8.x
* At least one supported command-execution primitive
* PHP CLI for the primary callback method
* `proc_open` for the persistent process method
* `fsockopen` for the primary transport
* `/bin/bash`, `/bin/sh`, or equivalent shell
* Outbound TCP connectivity when reverse callback testing is required

Feature availability depends on `disable_functions`, PHP configuration and operating-system permissions.

---

## Windows ASPX Requirements

Recommended environment:

* Windows Server / Windows
* IIS
* ASP.NET
* .NET Framework 4.x
* Application Pool using CLR v4.0
* Permission to start child processes
* `cmd.exe` or Windows PowerShell
* Outbound TCP connectivity when reverse callback testing is required

The ASPX implementation uses:

```text
System.Threading.Tasks
BlockingCollection<T>
CancellationToken
Interlocked
TcpClient
NetworkStream
Process
```

and therefore targets modern .NET Framework environments rather than legacy ASP.NET 2.0-only deployments.

---

## Security Evidence Considerations

Ghost1nject intentionally distinguishes between **capability** and **confirmed exploitation**.

For example:

```text
Writable directory
```

does not necessarily mean:

```text
Remote code execution
```

Likewise:

```text
.aspx write = YES
```

only confirms that a file with the `.aspx` extension could be created.

It does not automatically prove that IIS will execute that file.

The same principle applies to PHP extension probes.

This distinction helps maintain technically defensible evidence in penetration-testing reports.

---

## Limitations

* Reverse callback execution depends on outbound TCP connectivity.
* IIS Application Pool recycling terminates ASPX background threads and shell processes.
* PHP-FPM/Apache worker lifecycle may terminate PHP background processes.
* Hardened PHP installations may disable execution or socket functions.
* PTY availability varies across Linux environments.
* The Windows ASPX implementation uses redirected process streams and does not currently implement Windows ConPTY.
* Output draining uses safety timeouts to prevent indefinite blocking.
* `Callback launched` indicates that the callback process/thread was started; it does not guarantee successful remote connectivity.
* Extension write probes do not test server-side execution.

---

## Design Goals

Ghost1nject is intentionally designed around a few principles:

* **Single-file deployment**
* **Small footprint**
* **No external framework dependency**
* **Fast technical validation**
* **Useful evidence collection**
* **Clear capability reporting**
* **Cross-platform operation**
* **Minimal setup during authorized assessments**

The project prioritizes practical diagnostics and predictable behavior over unnecessary complexity.

---

## Legal Disclaimer

This project is intended exclusively for:

* Authorized penetration testing
* Security assessments
* Cybersecurity training
* Controlled laboratories
* CTF environments
* Defensive security research

Do not use this software against systems for which you do not have explicit authorization.

Unauthorized access to computer systems may violate applicable laws.

The author and Prodigium Academy assume no responsibility for unauthorized, illegal, or abusive use of this software.

---

## License

MIT — see the `LICENSE` file.

---

## Contributing

Bug reports, compatibility improvements and suggestions that preserve the project's compact and diagnostic-focused design are welcome.

Contributions should prioritize:

* Reliability
* Cross-platform compatibility
* Evidence quality
* Runtime capability detection
* Small code footprint
* Predictable behavior

---

## Author

**Lucas Diniz**
**Prodigium Academy**

Ghost1nject — built to support authorized penetration testing, technical evidence collection and practical cybersecurity laboratories.

If this project is useful to your security workflow, consider starring the repository. ⭐
