<?php

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

const FETCHER_VERSION = "0.1029.001";
const SUMMARIZER_VERSION = "0.1118.002";

const MAX_COMMENTS_TO_FETCH = 2500;
const MAX_FETCH = 200;                  // 20 pages worth
const MAX_SUMMARIZE = 30;               // 3 pages worth
const MIN_STORY_LENGTH_FOR_SUMMARY = 300;
const DEFAULT_PER_PAGE = 20;
const CLEANUP_RETENTION_DAYS = 45;

// Cmposition for mixed stories page
const ACTIVE_PER_PAGE = 9;
const TOP_PER_PAGE = 7;
const NEW_PER_PAGE = 4;

const FETCHER_LOCK_FILE_NAME = 'ff_fetch.lock';
const SUMMARIZER_LOCK_FILE_NAME = 'ff_summarize.lock';
const DB_PATH = "stories.db";
const STORY_TYPES = [
    "top" => 1,
    "new" => 2,
    "best" => 3,
    "active" => 4,
];

const IMAGE_EXTENSIONS = ['.jpg', '.jpeg', '.png', '.gif', '.webp'];

function acquireLock(string $lockName) {
    $lockFile = __DIR__ . '/' . $lockName;       
    $lockHandle = fopen($lockFile, 'c');
    if (!$lockHandle) {
        logMessage("Cannot create lock file: {$lockFile}");
        exit(1);
    }

    // Try to get an exclusive lock
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        logMessage("Another instance of FETCH is already running");
        exit(1);
    }

    logMessage("Acquired process lock (PID: " . getmypid() . ")");

    return $lockHandle;
}

function releaseLock($lockHandle, string $lockFile): void {
    if ($lockHandle) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        
        // Remove the lock file
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        
        logMessage("Released process lock");
    }
}

function handleSignal(int $signal): void {
    logMessage("Received signal {$signal}. Cleaning up and exiting...");
    exit(0);
}

// Handle signals (CTRL+C, etc.)
function dispatch_signal(): void {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
}

declare(ticks = 1);
function setup_signal_handlers(): void {
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGTERM, 'handleSignal');
        pcntl_signal(SIGINT, 'handleSignal');
    }
}

function get_db_connection($dbPath = DB_PATH): PDO
{
    if (!file_exists($dbPath)) {
        throw new Exception("Database file not found: {$dbPath}");
    }

    $pdo = new PDO("sqlite:{$dbPath}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Configure SQLite for better reliability and performance
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA temp_store = MEMORY');
    $pdo->exec('PRAGMA mmap_size = 268435456'); // 256MB
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

function db_integrity_check($pdo): bool {
    // Test basic query
    $stmt = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'");
    $tableCount = $stmt->fetchColumn();
    echo "Connection OK (found {$tableCount} tables)\n";
    
    // Check integrity
    echo "Checking DB integrity... ";
    $stmt = $pdo->query('PRAGMA integrity_check');
    $result = $stmt->fetchColumn();
    
    if ($result === 'ok') {
        echo "HEALTHY\n";
        return true;
    }
    
    echo "FAILED: {$result}\n";
    return false;
}

function close_db_connection(?PDO $conn): void {
    if ($conn !== null) {
        $conn = null;
    }
}

function exit_if_other_process_running(string $lockName, int $timeout = 60 * 2): void {
    if (file_exists(__DIR__ . '/' . $lockName)) {
        logMessage("Another instance of FETCH is already running");
        exit(0); // Exit successfully since this is expected behavior
    }
}

function debugLog($message) {
    $logEnabled = false; // Set to true when debugging
    if ($logEnabled) {
        file_put_contents('debug.log', date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
    }
}

function logMessage(string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
}

function fetchUrl(string $url, int $timeout = 10, array $headers = [], bool $headersOnly = false, int $maxSize = 10 * 1024 * 1024): string|array|false
{
    $defaultHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ];
    
    $allHeaders = array_merge($defaultHeaders, $headers);
    
    $context = stream_context_create([
        'http' => [
            'method' => $headersOnly ? 'HEAD' : 'GET',
            'header' => implode("\r\n", $allHeaders),
            'timeout' => $timeout
        ]
    ]);

    dispatch_signal();

    if ($headersOnly) {
        return @get_headers($url, true, $context);
    } else {
        // Prefer cURL over file_get_contents to avoid internal buffering overhead.
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_USERAGENT      => $defaultHeaders[0],
                CURLOPT_HTTPHEADER     => $allHeaders,
                CURLOPT_MAXFILESIZE    => $maxSize,  // Limit download size
            ]);

            $content = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            
            // Check if download was aborted due to size
            if ($error && strpos($error, 'exceeds file size limit') !== false) {
                logMessage("Download aborted for {$url} - content too large");
                return false;
            }
            
        } else {
            // Fallback if cURL is not available
            $content = @file_get_contents($url, false, $context, 0, $maxSize);
        }

        // Return false if content is false, empty, or just whitespace
        if ($content === false || empty(trim((string)$content))) {
            return false;
        }
        return $content;
    }
}

// Stream-download a URL directly to a local file to avoid holding the whole
// response in memory. Returns true on success, false on failure.
function downloadUrlToFile(string $url, string $destPath, int $timeout = 15, array $headers = []): bool
{
    $defaultHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ];

    $allHeaders = array_merge($defaultHeaders, $headers);

    // Open destination file for writing (binary-safe)
    $fp = fopen($destPath, 'wb');
    if ($fp === false) {
        return false;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE            => $fp,         // write directly to file
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_CONNECTTIMEOUT  => $timeout,
        CURLOPT_TIMEOUT         => $timeout,
        CURLOPT_FAILONERROR     => true,        // treat HTTP >= 400 as error
        CURLOPT_HTTPHEADER      => $allHeaders,
        CURLOPT_USERAGENT       => $allHeaders[0] ?? '',
    ]);

    dispatch_signal();

    $success = curl_exec($ch) !== false;
    curl_close($ch);
    fclose($fp);

    if (!$success) {
        // Remove partial file if download failed
        if (file_exists($destPath)) {
            unlink($destPath);
        }
    }

    return $success;
}

/**
 * Create a DOMDocument configured for proper UTF-8 HTML parsing
 * This prevents encoding issues when parsing HTML content
 */
function createUtf8DOMDocument(string $html): DOMDocument
{
    // Properly handle UTF-8 encoding by adding UTF-8 BOM and meta tag
    $html = "\xEF\xBB\xBF" . '<meta charset="UTF-8">' . $html;
    
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->encoding = 'UTF-8';
    
    // Suppress warnings for malformed HTML
    libxml_use_internal_errors(true);
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    return $dom;
}

/**
 * Parse a blog post markdown file with YAML frontmatter
 *
 * @param string $file_path Path to the markdown file
 * @return array|null Array with keys: title, date, excerpt, content, slug, or null if parsing fails
 */
function parse_blog_post(string $file_path): ?array {
    if (!file_exists($file_path)) {
        return null;
    }

    $content = file_get_contents($file_path);

    // Check for YAML frontmatter (--- at start and end)
    if (!preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches)) {
        return null;
    }

    $frontmatter_yaml = $matches[1];
    $markdown_content = trim($matches[2]);

    try {
        $frontmatter = Yaml::parse($frontmatter_yaml);
    } catch (Exception $e) {
        return null;
    }

    // Extract slug from filename (YYYY-MM-DD.md)
    $filename = basename($file_path, '.md');

    return [
        'title' => $frontmatter['title'] ?? 'Untitled',
        'date' => $frontmatter['date'] ?? $filename,
        'excerpt' => $frontmatter['excerpt'] ?? '',
        'content' => $markdown_content,
        'slug' => $filename,
        'file_path' => $file_path
    ];
}

/**
 * Get all blog posts from _blog_posts/ directory
 *
 * @return array Array of blog posts sorted by date (newest first)
 */
function get_all_blog_posts(): array {
    $blog_dir = __DIR__ . '/_blog_posts';

    if (!is_dir($blog_dir)) {
        return [];
    }

    $posts = [];
    $files = glob($blog_dir . '/*.md');

    foreach ($files as $file) {
        $post = parse_blog_post($file);
        if ($post) {
            $posts[] = $post;
        }
    }

    // Sort by date (newest first)
    usort($posts, function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });

    return $posts;
}

/**
 * Get a specific blog post by date slug
 *
 * @param string $date_slug Date in format YYYY-MM-DD
 * @return array|null Blog post data or null if not found
 */
function get_blog_post_by_date(string $date_slug): ?array {
    $file_path = __DIR__ . '/_blog_posts/' . $date_slug . '.md';
    return parse_blog_post($file_path);
}

/**
 * Generate and store a CSRF token in the session
 *
 * @return string The generated CSRF token
 */
function generate_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token from user input
 *
 * @param string|null $token The token to validate
 * @return bool True if valid, false otherwise
 */
function validate_csrf_token(?string $token): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['csrf_token']) || $token === null) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}