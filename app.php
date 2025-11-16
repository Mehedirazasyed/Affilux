<?php
// app.php - Single-file AFFILUXA website + admin panel + JSON DB
session_start();

// --- Configuration ---
define('ADMIN_USER', 'Syed');
define('ADMIN_PASS', 'Affiluxa@123');

$STUDENTS_FILE = __DIR__ . '/students.json';
$CERTS_FILE    = __DIR__ . '/certificates.json';
$UPLOADS_DIR   = __DIR__ . '/uploads';

// Ensure files/dirs exist
if (!is_dir($UPLOADS_DIR)) @mkdir($UPLOADS_DIR, 0755, true);
if (!file_exists($STUDENTS_FILE)) file_put_contents($STUDENTS_FILE, json_encode([], JSON_PRETTY_PRINT));
if (!file_exists($CERTS_FILE)) file_put_contents($CERTS_FILE, json_encode([], JSON_PRETTY_PRINT));

// Helpers
function read_json($path) {
    $s = @file_get_contents($path);
    if ($s === false) return [];
    $j = json_decode($s, true);
    return is_array($j) ? $j : [];
}
function write_json($path, $data) {
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
}
function send_json($obj) {
    header('Content-Type: application/json');
    echo json_encode($obj, JSON_PRETTY_PRINT);
    exit;
}

// --- Simple API endpoints (AJAX) ---
$action = $_REQUEST['action'] ?? null;

if ($action === 'login') {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    if ($u === ADMIN_USER && $p === ADMIN_PASS) {
        $_SESSION['admin'] = true;
        send_json(['ok' => true]);
    } else {
        send_json(['ok' => false, 'msg' => 'Invalid credentials']);
    }
}

if ($action === 'logout') {
    unset($_SESSION['admin']);
    send_json(['ok' => true]);
}

if ($action === 'load_db') {
    // return students + certificates
    $students = read_json($STUDENTS_FILE);
    $certs = read_json($CERTS_FILE);
    send_json(['ok'=>true,'students'=>$students,'certificates'=>$certs]);
}

if ($action === 'save_student') {
    // expects POST JSON fields or form POST
    $payload = $_POST;
    // minimal validation
    if (empty($payload['certificateNo']) || empty($payload['name'])) {
        send_json(['ok'=>false,'msg'=>'certificateNo and name required']);
    }
    $students = read_json($STUDENTS_FILE);
    // check if editing existing (by originalCertificate if provided)
    $orig = $payload['origCert'] ?? null;
    $record = [
        'certificateNo' => $payload['certificateNo'],
        'name' => $payload['name'],
        'dob' => $payload['dob'] ?? '',
        'email' => $payload['email'] ?? '',
        'mobile' => $payload['mobile'] ?? '',
        'address' => $payload['address'] ?? '',
        'year' => $payload['year'] ?? '',
        'course' => $payload['course'] ?? 'Optical Fibre Splicer — Professional',
        // QR dataURL sent by client (optional)
        'qr' => $payload['qr'] ?? null,
        // signature path (if previously uploaded/use uploaded endpoint)
        'signature' => $payload['signature'] ?? null,
        'created_at' => $payload['created_at'] ?? date('c')
    ];
    if ($orig) {
        $found = false;
        foreach ($students as &$s) {
            if ($s['certificateNo'] === $orig) {
                $s = array_merge($s, $record);
                $found = true;
                break;
            }
        }
        if (!$found) {
            // push new
            $students[] = $record;
        }
    } else {
        // push new (prevent duplicate unless allowed)
        $exists = array_filter($students, function($x) use ($record){ return $x['certificateNo'] === $record['certificateNo']; });
        if ($exists) {
            // allow duplicates only if confirmed by client; for now, reject
            send_json(['ok'=>false,'msg'=>'Certificate no exists. Use edit to update.']);
        }
        $students[] = $record;
    }
    write_json($STUDENTS_FILE, $students);
    send_json(['ok'=>true,'students'=>$students]);
}

if ($action === 'delete_student') {
    $cert = $_POST['certificateNo'] ?? null;
    if (!$cert) send_json(['ok'=>false,'msg'=>'certificateNo required']);
    $students = read_json($STUDENTS_FILE);
    $students = array_filter($students, function($x) use ($cert){ return $x['certificateNo'] !== $cert; });
    write_json($STUDENTS_FILE, array_values($students));
    // also remove from certificates.json if present
    $certs = read_json($CERTS_FILE);
    $certs = array_filter($certs, function($x) use ($cert){ return $x['certificateNo'] !== $cert; });
    write_json($CERTS_FILE, array_values($certs));
    send_json(['ok'=>true]);
}

if ($action === 'import_json') {
    // Accept uploaded JSON file containing array, and merge into students
    if (!isset($_FILES['file'])) send_json(['ok'=>false,'msg'=>'No file uploaded']);
    $tmp = $_FILES['file']['tmp_name'];
    $s = @file_get_contents($tmp);
    if (!$s) send_json(['ok'=>false,'msg'=>'Cannot read file']);
    $arr = json_decode($s, true);
    if (!is_array($arr)) send_json(['ok'=>false,'msg'=>'Invalid JSON'});
    $students = read_json($STUDENTS_FILE);
    $merged = array_merge($students, $arr);
    write_json($STUDENTS_FILE, $merged);
    send_json(['ok'=>true,'count'=>count($merged)]);
}

if ($action === 'export_json') {
    // send students.json for download
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="students.json"');
    readfile($STUDENTS_FILE);
    exit;
}

if ($action === 'upload_signature') {
    if (!isset($_FILES['signature'])) send_json(['ok'=>false,'msg'=>'No file']);
    $f = $_FILES['signature'];
    $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
    $allowed = ['png','jpg','jpeg','webp'];
    if (!in_array(strtolower($ext), $allowed)) send_json(['ok'=>false,'msg'=>'Only PNG/JPG allowed']);
    $dest = $UPLOADS_DIR . '/director_signature.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dest)) send_json(['ok'=>false,'msg'=>'Upload failed']);
    // Normalize to png if needed? we will serve file as-is.
    send_json(['ok'=>true,'path'=> str_replace(__DIR__ . '/', '', $dest)]);
}

// If no API action, render single-file app UI below
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>AFFILUXA — Full System (Single File)</title>

  <!-- Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">

  <!-- Client libs -->
  <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.1/build/qrcode.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

  <style>
  /* Basic theme adapted from your homepage */
  :root{
    --bg:#07070a;
    --card:#0f1116;
    --muted:#98a3ad;
    --accent1:#ff7a18;
    --accent2:#ff9f43;
    --glass: rgba(255,255,255,0.04);
  }
  html,body{height:100%;margin:0;font-family:Inter,system-ui,system-ui,-apple-system,Segoe UI,Roboto,"Helvetica Neue",Arial;background: radial-gradient(800px 320px at 5% 10%, rgba(255,122,24,0.04), transparent 6%), radial-gradient(700px 260px at 95% 85%, rgba(255,159,67,0.03), transparent 6%), var(--bg);color:#e6eef6}
  .wrap{max-width:1200px;margin:18px auto;padding:18px}
  header.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
  header.top .brand{display:flex;gap:10px;align-items:center}
  .logo-mark{width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,var(--accent1),var(--accent2));display:grid;place-items:center;color:#071018;font-weight:800}
  nav.links a{color:var(--muted);margin-right:12px;text-decoration:none}
  main.grid{display:grid;grid-template-columns:1fr 420px;gap:18px}
  .card{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));padding:14px;border-radius:12px;border:1px solid rgba(255,255,255,0.03)}
  .muted{color:var(--muted)}
  table{width:100%;border-collapse:collapse;color:inherit}
  th,td{padding:8px;border-bottom:1px dashed rgba(255,255,255,0.03);text-align:left}
  input,select,textarea{width:100%;padding:8px;border-radius:8px;background:transparent;border:1px solid rgba(255,255,255,0.04);color:inherit}
  .btn{display:inline-flex;gap:8px;align-items:center;padding:8px 12px;border-radius:10px;border:none;cursor:pointer}
  .btn-primary{background:linear-gradient(90deg,var(--accent1),var(--accent2));color:#071018;font-weight:800}
  .btn-ghost{background:transparent;border:1px solid rgba(255,255,255,0.04);color:var(--muted)}
  .small{font-size:13px}
  .qr-thumb{width:64px;height:64px;border-radius:8px;background:#fff;padding:6px}
  .preview-canvas{width:100%;max-width:920px;background:#fff;color:#071018;padding:18px;border-radius:10px}
  .hidden{display:none}
  .login-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;z-index:9999}
  .login-box{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));padding:22px;border-radius:12px;width:360px}
  </style>
</head>
<body>

<div class="wrap">
  <!-- Top -->
  <header class="top">
    <div class="brand">
      <div class="logo-mark">A</div>
      <div>
        <div style="font-weight:800">AFFILUXA</div>
        <div class="muted small">Telecom Training — Admin System</div>
      </div>
    </div>

    <nav class="links">
      <a href="?page=public">Public Site</a>
      <a href="?page=verify">Verify</a>
      <a href="?page=admin">Admin</a>
    </nav>
  </header>

  <?php
  // route pages inside single file
  $page = $_GET['page'] ?? 'public';
  if ($page === 'public'):
  ?>

  <!-- -------------------- PUBLIC HOMEPAGE -------------------- -->
  <main class="grid">
    <section class="card">
      <h2 style="margin:0 0 8px 0">Master Optical Fibre Technology</h2>
      <p class="muted">World-class, hands-on training in fusion splicing, OTDR testing and on-site installations.</p>

      <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn btn-primary" href="?page=apply">Apply Now →</a>
        <a class="btn btn-ghost" href="?page=verify">Verify Certificate</a>
      </div>

      <h3 style="margin-top:16px">Featured Course</h3>
      <div class="card" style="margin-top:8px">
        <strong>Optical Fibre Splicer — Professional</strong>
        <p class="muted small" style="margin:6px 0">Fusion splicing, OTDR & field labs. 2–6 weeks intensive.</p>
      </div>

      <h3 style="margin-top:12px">Why AFFILUXA?</h3>
      <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
        <div class="card" style="min-width:160px">
          <div style="font-weight:700">Hands-on</div>
          <div class="muted small">Real equipment & labs</div>
        </div>
        <div class="card" style="min-width:160px">
          <div style="font-weight:700">Certified</div>
          <div class="muted small">Industry-recognized certificates</div>
        </div>
        <div class="card" style="min-width:160px">
          <div style="font-weight:700">Placement</div>
          <div class="muted small">Hiring partners</div>
        </div>
      </div>
    </section>

    <aside class="card">
      <h4 style="margin:0 0 8px 0">Quick Contact</h4>
      <div class="muted small">Phone</div>
      <div style="font-weight:700">7803871138</div>
      <div class="muted small" style="margin-top:8px">Email</div>
      <div style="font-weight:700">affiluxa@gmail.com</div>
      <div style="margin-top:12px">
        <a class="btn btn-primary" href="?page=admin">Admin Panel</a>
      </div>
    </aside>
  </main>

  <?php elseif ($page === 'verify'): ?>

  <!-- -------------------- VERIFY PAGE -------------------- -->
  <section class="card" style="max-width:720px;margin:auto">
    <h3>Verify Certificate</h3>
    <p class="muted small">Enter certificate number and DOB, or scan QR.</p>

    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
      <input id="v_cert" placeholder="Certificate No">
      <input id="v_dob" type="date">
      <button id="btnVerify" class="btn btn-primary">Verify</button>
      <button id="btnScan" class="btn btn-ghost">Scan QR</button>
    </div>

    <div id="verifyResult" style="margin-top:12px"></div>
  </section>

  <?php elseif ($page === 'apply'): ?>

  <!-- -------------------- APPLY (simple local save to students list) -------------------- -->
  <section class="card" style="max-width:720px;margin:auto">
    <h3>Admission Form</h3>
    <form id="applyForm">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div><label class="muted small">Full name</label><input name="name" required></div>
        <div><label class="muted small">Certificate No</label><input name="certificateNo" required placeholder="AFF-2025-001"></div>
        <div><label class="muted small">DOB</label><input type="date" name="dob"></div>
        <div><label class="muted small">Year</label><input name="year" placeholder="2025"></div>
        <div class="full" style="grid-column:span 2"><label class="muted small">Email</label><input name="email"></div>
        <div class="full" style="grid-column:span 2"><label class="muted small">Mobile</label><input name="mobile"></div>
        <div class="full" style="grid-column:span 2"><label class="muted small">Address</label><textarea name="address"></textarea></div>
      </div>
      <div style="margin-top:10px">
        <button type="submit" class="btn btn-primary">Submit Application</button>
      </div>
    </form>
    <div id="applyNotice" style="margin-top:8px"></div>
  </section>

  <?php elseif ($page === 'admin'): ?>

  <!-- -------------------- ADMIN PANEL -------------------- -->
  <?php if (empty($_SESSION['admin'])): ?>
    <div class="card" style="max-width:480px;margin:auto">
      <h3>Admin Login</h3>
      <div class="muted small">User: <strong>Syed</strong> | Pass: <strong>Affiluxa@123</strong></div>
      <div style="margin-top:10px">
        <input id="loginUser" placeholder="Username" value="">
        <input id="loginPass" placeholder="Password" type="password" style="margin-top:6px">
        <div style="margin-top:8px">
          <button id="loginBtn" class="btn btn-primary">Login</button>
        </div>
        <div id="loginNotice" style="margin-top:8px"></div>
      </div>
    </div>

  <?php else: 
    // admin UI
    ?>
    <div style="display:flex;gap:12px;align-items:center;margin-bottom:12px">
      <h2 style="margin:0">Admin Dashboard</h2>
      <div class="muted small">Logged in as <strong>Syed</strong></div>
      <div style="margin-left:auto">
        <button id="logoutBtn" class="btn btn-ghost">Logout</button>
      </div>
    </div>

    <section style="display:grid;grid-template-columns:1fr 420px;gap:12px">
      <div class="card">
        <div style="display:flex;gap:8px;align-items:center">
          <input id="search" placeholder="Search name or cert no" style="flex:1">
          <button id="btnNew" class="btn btn-primary">Add New</button>
          <button id="btnImport" class="btn btn-ghost">Import JSON</button>
          <button id="btnExport" class="btn btn-ghost">Export JSON</button>
        </div>

        <div style="margin-top:12px" id="studentsWrap">
          <table id="studentsTable">
            <thead><tr><th>Name</th><th>Cert No</th><th>DOB</th><th>Year</th><th>QR</th><th>Actions</th></tr></thead>
            <tbody id="studentsBody"></tbody>
          </table>
        </div>
      </div>

      <aside class="card">
        <h4>Signature & Tools</h4>
        <div class="muted small">Upload Director Signature (used on cert)</div>
        <input id="signatureFile" type="file" accept="image/*" style="margin-top:8px">
        <div style="margin-top:8px"><button id="btnUploadSig" class="btn btn-primary">Upload</button></div>

        <hr style="margin:12px 0">

        <div class="muted small">Generate & preview certificate</div>
        <div style="margin-top:8px">
          <label class="small muted">Select student</label>
          <select id="selStudent" style="width:100%"></select>
          <div style="display:flex;gap:8px;margin-top:8px">
            <button id="btnPreview" class="btn btn-primary">Preview Certificate</button>
            <button id="btnIdCard" class="btn btn-ghost">Generate ID Card</button>
          </div>
        </div>

        <hr style="margin:12px 0">

        <div class="muted small">Data</div>
        <div style="display:flex;gap:8px;margin-top:8px">
          <button id="btnClear" class="btn btn-ghost">Clear all data</button>
        </div>
      </aside>
    </section>

    <!-- Add/Edit Form modal area -->
    <div id="formArea" class="card" style="margin-top:12px;display:none">
      <h4 id="formTitle">New Admission</h4>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div><label class="small muted">Full name</label><input id="f_name"></div>
        <div><label class="small muted">Certificate No</label><input id="f_cert"></div>
        <div><label class="small muted">DOB</label><input id="f_dob" type="date"></div>
        <div><label class="small muted">Year</label><input id="f_year"></div>
        <div class="full" style="grid-column:span 2"><label class="small muted">Course</label><input id="f_course" value="Optical Fibre Splicer — Professional"></div>
        <div class="full" style="grid-column:span 2"><label class="small muted">Email</label><input id="f_email"></div>
        <div class="full" style="grid-column:span 2"><label class="small muted">Mobile</label><input id="f_mobile"></div>
        <div class="full" style="grid-column:span 2"><label class="small muted">Address</label><textarea id="f_address"></textarea></div>
      </div>
      <div style="margin-top:8px;display:flex;gap:8px">
        <button id="saveBtn" class="btn btn-primary">Save</button>
        <button id="cancelBtn" class="btn btn-ghost">Cancel</button>
      </div>
    </div>

    <!-- Certificate preview modal -->
    <div id="previewModal" class="hidden" style="position:fixed;inset:0;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;z-index:9999">
      <div style="background:#fff;padding:14px;border-radius:10px;max-width:960px;width:95%;color:#071018;position:relative">
        <button id="closePreview" style="position:absolute;right:10px;top:10px">✕</button>
        <div id="certPreviewArea" style="overflow:auto"></div>
        <div style="margin-top:8px;display:flex;gap:8px;justify-content:flex-end">
          <button id="downloadCert" class="btn btn-primary">Download PDF</button>
          <button id="printCert" class="btn btn-ghost">Print</button>
        </div>
      </div>
    </div>

  <?php endif; // end admin check ?>

  <?php endif; // end routing ?>

  <footer style="margin-top:18px;text-align:center" class="muted small">© <?= date('Y') ?> AFFILUXA — Single-file system</footer>
</div>

<script>
/* ---------- Client-side JS for the single-file app ---------- */

const API = location.pathname + '?action=';

// Utility fetch wrapper
async function api(action, data) {
  const opts = { method: data ? 'POST' : 'GET' };
  if (data) {
    if (data instanceof FormData) {
      opts.body = data;
    } else {
      opts.headers = {'Content-Type':'application/x-www-form-urlencoded'};
      opts.body = new URLSearchParams(data).toString();
    }
  }
  const res = await fetch(API + action, opts);
  return res.json();
}

/* ---------------- Public: Apply form ---------------- */
const applyForm = document.getElementById('applyForm');
if (applyForm) {
  applyForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = new FormData(applyForm);
    // generate QR for verification JSON: {certNo, dob}
    const cert = form.get('certificateNo');
    const dob = form.get('dob') || '';
    const verifyObj = { certNo: cert, dob: dob, verify: location.origin + location.pathname + '?page=verify&cert=' + encodeURIComponent(cert) + '&dob=' + encodeURIComponent(dob) };
    // generate QR dat
