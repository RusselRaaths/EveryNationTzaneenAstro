<?php
header('Content-Type: application/json');

// Basic config: change the secret or set environment variable UPLOAD_SECRET
$secret = getenv('UPLOAD_SECRET') ?: 'change-me';

// Optional HTTP Basic auth for admin UI/endpoints. Set these env vars on the host to enable.
$basicUser = getenv('UPLOAD_BASIC_USER') ?: '';
$basicPass = getenv('UPLOAD_BASIC_PASS') ?: '';
if ($basicUser && $basicPass) {
  // Validate PHP auth headers
  if (!isset($_SERVER['PHP_AUTH_USER'])) {
    header('WWW-Authenticate: Basic realm="Restricted"');
    header('HTTP/1.0 401 Unauthorized');
    echo json_encode(['error' => 'Authentication required']);
    exit;
  }
  if (!hash_equals($basicUser, $_SERVER['PHP_AUTH_USER']) || !hash_equals($basicPass, $_SERVER['PHP_AUTH_PW'] ?? '')) {
    header('HTTP/1.0 403 Forbidden');
    echo json_encode(['error' => 'Forbidden']);
    exit;
  }
}

// Target folder relative to this script (assumes script is in public_html)
$targetDir = __DIR__ . '/ENTZN Website/Sermon Uploads/';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'Method not allowed']);
  exit;
}

$provided = $_POST['secret'] ?? '';
if ($provided !== $secret) {
  http_response_code(401);
  echo json_encode(['error' => 'Unauthorized']);
  exit;
}

if (!isset($_FILES['file'])) {
  http_response_code(400);
  echo json_encode(['error' => 'No file uploaded']);
  exit;
}

$file = $_FILES['file'];

// Validate allowed mime types (adjust as needed)
$allowed = ['audio/mpeg','audio/mp3','audio/x-m4a','audio/m4a','audio/*'];
// Basic size cap (300MB)
if ($file['size'] > 300 * 1024 * 1024) {
  http_response_code(400);
  echo json_encode(['error' => 'File too large']);
  exit;
}

$basename = preg_replace('/[^A-Za-z0-9._-]/', '-', basename($file['name']));
$timestamp = time();
$targetName = $timestamp . '-' . $basename;

if (!is_dir($targetDir)) {
  if (!mkdir($targetDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create target folder']);
    exit;
  }
}

$targetPath = $targetDir . $targetName;
if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
  http_response_code(500);
  echo json_encode(['error' => 'Failed to move file']);
  exit;
}

// Build public URL (spaces encoded)
$publicPath = '/ENTZN%20Website/Sermon%20Uploads/' . rawurlencode($targetName);
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
// Prefer explicit SITE_DOMAIN env var (set to "entzaneen.co.za") so preview hostnames are not used in returned URLs
$siteDomain = getenv('SITE_DOMAIN') ?: $_SERVER['HTTP_HOST'];
$publicUrl = $protocol . '://' . $siteDomain . $publicPath;

// Create a sermon markdown file in src/content/sermons/
$contentDir = __DIR__ . '/../src/content/sermons/';
if (!is_dir($contentDir)) {
  mkdir($contentDir, 0755, true);
}

$title = trim($_POST['title'] ?? pathinfo($basename, PATHINFO_FILENAME));
$speaker = trim($_POST['speaker'] ?? '');
$series = trim($_POST['series'] ?? '');
$scripture = trim($_POST['scripture'] ?? '');
$summary = trim($_POST['summary'] ?? '');
$date = trim($_POST['date'] ?? date('Y-m-d'));
$tagsRaw = trim($_POST['tags'] ?? '');
$tags = array_filter(array_map('trim', explode(',', $tagsRaw)));

// Slugify filename
$slug = preg_replace('/[^A-Za-z0-9-]+/', '-', strtolower($title ?: pathinfo($basename, PATHINFO_FILENAME)));
$slug = trim($slug, '-');
if ($slug === '') $slug = 'sermon-' . $timestamp;
$mdName = $slug . '-' . $timestamp . '.md';
$mdPath = $contentDir . $mdName;

$frontmatter = "---\n";
$frontmatter .= 'title: ' . json_encode($title) . "\n";
$frontmatter .= 'date: ' . json_encode($date) . "\n";
if ($speaker) $frontmatter .= 'speaker: ' . json_encode($speaker) . "\n";
if ($series) $frontmatter .= 'series: ' . json_encode($series) . "\n";
if ($scripture) $frontmatter .= 'scripture: ' . json_encode($scripture) . "\n";
$frontmatter .= 'audioUrl: ' . json_encode($publicUrl) . "\n";
if (count($tags) > 0) $frontmatter .= 'tags: [' . implode(', ', array_map(function($t){ return json_encode($t); }, $tags)) . "]\n";
$frontmatter .= "draft: false\n";
$frontmatter .= "---\n\n";

$body = $summary ? $summary . "\n" : "";

file_put_contents($mdPath, $frontmatter . $body);

// Optionally push the generated markdown to GitHub using the Contents API
$githubToken = getenv('GITHUB_TOKEN') ?: '';
$githubRepo = getenv('GITHUB_REPO') ?: ''; // format: owner/repo
if ($githubToken && $githubRepo) {
  $apiUrl = 'https://api.github.com/repos/' . $githubRepo . '/contents/src/content/sermons/' . rawurlencode($mdName);
  $payload = json_encode([
    'message' => 'Add sermon: ' . $title,
    'content' => base64_encode($frontmatter . $body),
    'branch' => 'main'
  ]);

  $ch = curl_init($apiUrl);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: token ' . $githubToken,
    'User-Agent: upload-script',
    'Content-Type: application/json'
  ]);

  $resp = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($httpCode >= 200 && $httpCode < 300) {
    $gh = json_decode($resp, true);
    // include github result (path) if needed
    echo json_encode(['url' => $publicUrl, 'markdown' => $mdName, 'github' => $gh['content']['path'] ?? null]);
    exit;
  } else {
    // failed to push to GitHub, still return local result and error
    $err = @json_decode($resp, true);
    echo json_encode(['url' => $publicUrl, 'markdown' => $mdName, 'github_error' => $err]);
    exit;
  }
}

echo json_encode(['url' => $publicUrl, 'markdown' => $mdName]);
exit;

?>
