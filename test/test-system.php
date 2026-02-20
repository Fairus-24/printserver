<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>FIK Print Server - System Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #667eea; padding-bottom: 15px; }
        .test-item { margin: 15px 0; padding: 12px; background: #f9f9f9; border-left: 4px solid #ccc; }
        .test-item.pass { border-left-color: #10b981; }
        .test-item.fail { border-left-color: #ef4444; }
        .test-item strong { color: #333; }
        .test-item .status { float: right; font-weight: bold; }
        .test-item.pass .status { color: #10b981; }
        .test-item.fail .status { color: #ef4444; }
        .debug-section { background: #f0f4ff; padding: 15px; margin-top: 20px; border-radius: 8px; }
        code { background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
        .button-group { margin-top: 20px; }
        button { padding: 10px 20px; margin-right: 10px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5568d3; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
    </style>
</head>
<body>

<div class="container">
    <h1>🖨️ FIK Print Server - System Test</h1>
    
    <div id="testResults">
        <div class="test-item" style="text-align: center; background: #e3f2fd;">
            <strong>Loading tests...</strong>
        </div>
    </div>

    <div class="debug-section">
        <h3>🔧 Debug Information</h3>
        <div id="debugInfo">Loading...</div>
    </div>

    <div class="button-group">
        <button class="btn-primary" onclick="location.reload()">🔄 Reload Test</button>
        <button class="btn-primary" onclick="window.location.href='index.php'">📠 Go to Print Server</button>
        <button class="btn-danger" onclick="if(confirm('Hapus semua file di uploads?')) {fetch('api.php?action=clear_uploads', {method:'POST'}).then(r=>r.json()).then(d=>alert(d.message)).catch(e=>alert('Error: '+e));}" style="display: none;" id="btnClearFiles">🗑️ Clear All Files</button>
    </div>

    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ccc; color: #999; font-size: 12px;">
        <strong>Server Time:</strong> <span id="serverTime">Loading...</span><br>
        <strong>PHP Version:</strong> <?php echo phpversion(); ?><br>
        <strong>Session ID:</strong> <?php echo session_id(); ?> <button onclick="fetch('clear_session.php').then(()=>alert('Session cleared')).catch(e=>alert('Error: '+e));" style="font-size:11px;padding:2px 8px;">Clear</button>
    </div>
</div>

<script>
    async function runTests() {
        const results = [];
        
        // Test 1: API Connection
        try {
            const res = await fetch('api.php?action=get_files');
            const data = await res.json();
            results.push({
                name: 'API Connection (get_files)',
                pass: data.success,
                message: data.success ? `${data.queue_count} file(s) in queue` : data.message
            });
        } catch(e) {
            results.push({ name: 'API Connection (get_files)', pass: false, message: e.message });
        }

        // Test 2: Get Logs
        try {
            const res = await fetch('api.php?action=get_logs');
            const data = await res.json();
            results.push({
                name: 'Get Logs Endpoint',
                pass: data.success,
                message: data.success ? `${data.logs.length} log entries` : data.message
            });
        } catch(e) {
            results.push({ name: 'Get Logs Endpoint', pass: false, message: e.message });
        }

        // Test 3: Debug Info
        try {
            const res = await fetch('api.php?action=debug');
            const data = await res.json();
            results.push({
                name: 'Debug Endpoint',
                pass: data.success,
                message: data.success ? `Uploads: ${data.debug.uploadsReadable ? '✓ Readable' : '✗ Not readable'}` : 'Failed',
                debug: data.debug
            });
        } catch(e) {
            results.push({ name: 'Debug Endpoint', pass: false, message: e.message });
        }

        // Render Results
        const html = results.map((r, i) => `
            <div class="test-item ${r.pass ? 'pass' : 'fail'}">
                <strong>${i+1}. ${r.name}</strong>
                <div class="status">${r.pass ? '✓ PASS' : '✗ FAIL'}</div>
                <div style="clear: both; margin-top: 8px; font-size: 13px; color: #666;">${r.message}</div>
                ${r.debug ? `<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 12px;">
                    <strong>Upload Files Found:</strong> ${r.debug.uploadsList.length}<br>
                    ${r.debug.uploadsList.length > 0 ? '<div style="background: #fff; padding: 8px; margin-top: 5px; border-radius: 4px; max-height: 150px; overflow-y: auto;">' + r.debug.uploadsList.map(f => '<code>' + f + '</code>').join('<br>') + '</div>' : '<em>No files</em>'}
                </div>` : ''}
            </div>
        `).join('');
        
        document.getElementById('testResults').innerHTML = html;
        
        // Show debug for last result
        const debugData = results.find(r => r.debug);
        if (debugData) {
            document.getElementById('debugInfo').innerHTML = '<pre>' + JSON.stringify(debugData.debug, null, 2) + '</pre>';
            document.getElementById('btnClearFiles').style.display = 'inline-block';
        }
    }

    function updateTime() {
        const now = new Date();
        const timeStr = ('0'+now.getDate()).slice(-2) + '/' + ('0'+(now.getMonth()+1)).slice(-2) + '/' + now.getFullYear() + ' ' +
                        ('0'+now.getHours()).slice(-2) + ':' + ('0'+now.getMinutes()).slice(-2) + ':' + ('0'+now.getSeconds()).slice(-2);
        document.getElementById('serverTime').textContent = timeStr;
    }

    runTests();
    updateTime();
    setInterval(updateTime, 1000);
    setInterval(runTests, 10000);
</script>

</body>
</html>
