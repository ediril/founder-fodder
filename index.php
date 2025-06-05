<?php
declare(strict_types=1);

require_once __DIR__ . '/banalytiq/banalytiq.php';
record_visit();

require_once __DIR__ . '/defines.php';

/**
 * Connect to the SQLite database
 */
function get_db_connection(): PDO {
    $db_file = DB_PATH;
    if (!file_exists($db_file)) {
        throw new Exception("Database file not found: {$db_file}");
    }
    
    try {
        $conn = new PDO("sqlite:" . $db_file);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $conn;
    } catch (PDOException $e) {
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
}

/**
 * Close the database connection
 */
function close_db_connection(?PDO $conn): void {
    if ($conn !== null) {
        $conn = null;
    }
}

/**
 * Format timestamp as 'time ago'
 */
function format_time_ago(int $timestamp): string {
    $seconds = (int)(time() - $timestamp);
    
    if ($seconds < 60) {
        return "{$seconds} seconds ago";
    } elseif ($seconds < 3600) {
        $minutes = (int)($seconds / 60);
        return "{$minutes} minute" . ($minutes > 1 ? 's' : '') . " ago";
    } elseif ($seconds < 86400) {
        $hours = (int)($seconds / 3600);
        return "{$hours} hour" . ($hours > 1 ? 's' : '') . " ago";
    } else {
        $days = (int)($seconds / 86400);
        return "{$days} day" . ($days > 1 ? 's' : '') . " ago";
    }
}

/**
 * API endpoint to get stories
 */
function get_stories(): array {
    try {
        // Parse request parameters
        $story_type = $_GET['type'] ?? 'top';
        $page = (int)($_GET['page'] ?? 1);
        $per_page = (int)($_GET['per_page'] ?? 30);
        
        // Log debugging info
        debugLog("get_stories called with type: $story_type, page: $page, per_page: $per_page");
        
        // Calculate offset for pagination
        $offset = ($page - 1) * $per_page;
        
        try {
            $conn = get_db_connection();
            debugLog("Database connection established");
        } catch (Exception $e) {
            debugLog("Database connection failed: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Database connection failed: " . $e->getMessage()];
        }
        
        // Check if the database file exists
        if (!file_exists(DB_PATH)) {
            debugLog("Database file not found: " . DB_PATH);
            return ["error" => "Database file not found"];
        }
        
        // Get stories by type, ordered by ranking
        try {
            $stmt = $conn->prepare('
                SELECT id, title, url, domain, comments_url, comments_count, 
                       score, user, timestamp, type, ranking
                FROM stories
                WHERE type = ?
                ORDER BY ranking
                LIMIT ? OFFSET ?
            ');
            $stmt->execute([$story_type, $per_page, $offset]);
            
            // Convert to array of associative arrays
            $stories = [];
            $story_ids = [];
            while ($row = $stmt->fetch()) {
                // Add additional fields
                $row['time_ago'] = format_time_ago((int)$row['timestamp']);
                $row['formatted_time'] = date("Y-m-d H:i:s", (int)$row['timestamp']);
                
                $stories[] = $row;
                $story_ids[] = $row['id'];
            }
            
            // Batch check image and summary files for all stories at once
            if (!empty($story_ids)) {
                $image_files = batch_check_image_files($story_ids);
                $summary_files = batch_check_summary_files($story_ids);
                
                // Add file information to each story
                foreach ($stories as &$story) {
                    // Generate image URL based on story ID if the image exists
                    if ($image_files[$story['id']] ?? false) {
                        $story['image_url'] = "/static/img/stories/{$story['id']}.jpg";
                    } else {
                        $story['image_url'] = "";
                    }
                    
                    $story['summary_files'] = $summary_files[$story['id']] ?? [
                        'has_story_summary' => false,
                        'has_comments_summary' => false
                    ];
                }
                unset($story); // Clean up reference
            }
            
            debugLog("Retrieved " . count($stories) . " stories from database");
            return $stories;
        } catch (Exception $e) {
            debugLog("Database query failed: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Database query failed: " . $e->getMessage()];
        } finally {
            close_db_connection($conn);
        }
    } catch (Exception $e) {
        debugLog("Unexpected error in get_stories: " . $e->getMessage());
        http_response_code(500);
        return ["error" => "Unexpected error: " . $e->getMessage()];
    }
}

/**
 * Get stories for a specific page with pagination
 */
function get_page_stories(string $story_type = 'top', int $page = 1, int $per_page = STORIES_PER_PAGE): array {
    try {
        // Calculate offset for pagination
        $offset = ($page - 1) * $per_page;
        
        debugLog("Getting page stories: type=$story_type, page=$page, per_page=$per_page, offset=$offset");
        
        $conn = get_db_connection();
        
        // Get total count for pagination
        $stmt = $conn->prepare('SELECT COUNT(*) as total FROM stories WHERE type = ?');
        $stmt->execute([$story_type]);
        $result = $stmt->fetch();
        $total = (int)($result['total'] ?? 0);
        
        // Get stories by type, ordered by ranking
        $stmt = $conn->prepare('
            SELECT id, title, url, domain, comments_url, comments_count, 
                   score, user, timestamp, type, ranking
            FROM stories
            WHERE type = ?
            ORDER BY ranking
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([$story_type, $per_page, $offset]);
        
        // Convert to array of associative arrays
        $stories = [];
        $story_ids = [];
        while ($row = $stmt->fetch()) {
            // Add additional fields
            $row['time_ago'] = format_time_ago((int)$row['timestamp']);
            $row['formatted_time'] = date("Y-m-d H:i:s", (int)$row['timestamp']);
            
            $stories[] = $row;
            $story_ids[] = $row['id'];
        }
        
        // Batch check image and summary files for all stories at once
        if (!empty($story_ids)) {
            $image_files = batch_check_image_files($story_ids);
            $summary_files = batch_check_summary_files($story_ids);
            
            // Add file information to each story
            foreach ($stories as &$story) {
                // Generate image URL based on story ID if the image exists
                if ($image_files[$story['id']] ?? false) {
                    $story['image_url'] = "/static/img/stories/{$story['id']}.jpg";
                } else {
                    $story['image_url'] = "";
                }
                
                $story['summary_files'] = $summary_files[$story['id']] ?? [
                    'has_story_summary' => false,
                    'has_comments_summary' => false
                ];
            }
            unset($story); // Clean up reference
        }
        
        // Add pagination info
        $pagination = [
            'current_page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => ceil($total / $per_page),
            'has_more' => ($page * $per_page) < $total,
            'next_page' => ($page * $per_page) < $total ? $page + 1 : null,
        ];
        
        return [
            'stories' => $stories,
            'pagination' => $pagination,
            'type' => $story_type
        ];
    } catch (Exception $e) {
        debugLog("Error getting page stories: " . $e->getMessage());
        return [
            'stories' => [],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total' => 0,
                'total_pages' => 0,
                'has_more' => false,
                'next_page' => null
            ],
            'type' => $story_type,
            'error' => $e->getMessage()
        ];
    } finally {
        close_db_connection($conn);
    }
}

/**
 * Check if image files exist for multiple stories at once
 */
function batch_check_image_files(array $story_ids): array {
    $results = [];
    
    foreach ($story_ids as $story_id) {
        $img_path = "static/img/stories/{$story_id}.jpg";
        $results[$story_id] = file_exists($img_path);
    }
    
    return $results;
}

/**
 * Check if summary files exist for multiple stories at once
 */
function batch_check_summary_files(array $story_ids): array {
    $results = [];
    
    foreach ($story_ids as $story_id) {
        $story_summary_path = "static/content/{$story_id}-story-summary.md";
        $comments_summary_path = "static/content/{$story_id}-comments-summary.md";
        
        $results[$story_id] = [
            'has_story_summary' => file_exists($story_summary_path),
            'has_comments_summary' => file_exists($comments_summary_path)
        ];
    }
    
    return $results;
}

/**
 * Check if summary files exist for a story
 */
function check_summary_files(string $story_id): array {
    $story_summary_path = "static/content/{$story_id}-story-summary.md";
    $comments_summary_path = "static/content/{$story_id}-comments-summary.md";
    
    $has_story_summary = file_exists($story_summary_path);
    $has_comments_summary = file_exists($comments_summary_path);
    
    return [
        'has_story_summary' => $has_story_summary,
        'has_comments_summary' => $has_comments_summary
    ];
}

// Debug function to log requests - remove in production
function debugLog($message) {
    $logEnabled = false; // Set to true when debugging
    if ($logEnabled) {
        file_put_contents('debug.log', date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
    }
}

// Get the base path of the application (important for subdirectory installations)
function getBasePath() {
    $scriptName = dirname($_SERVER['SCRIPT_NAME']);
    return rtrim($scriptName, '/');
}

// Simple router
$request_uri = $_SERVER['REQUEST_URI'];
$base_path = getBasePath();
debugLog("Request URI: $request_uri, Base path: $base_path");

// Remove base path and query string from the request URI
$path = parse_url($request_uri, PHP_URL_PATH);
if (!empty($base_path) && strpos($path, $base_path) === 0) {
    $path = substr($path, strlen($base_path));
}
$path = '/' . ltrim($path, '/');
debugLog("Normalized path: $path");

/**
 * Generate asset URL with version for cache busting
 */
function asset_url(string $path): string {
    $full_path = ltrim($path, '/');
    if (file_exists($full_path)) {
        $version = filemtime($full_path);
        return $path . '?v=' . $version;
    }
    return $path;
}

// Handle requests
if ($path === '/' || $path === '/index.php') {
    // Get stories for the main page
    $story_type = $_GET['type'] ?? 'top';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = STORIES_PER_PAGE; // Use the constant
    
    // Get stories for current page
    $stories = get_page_stories($story_type, $page, $per_page);
    
    $db = new SQLite3(DB_PATH);
    $result = $db->querySingle("select max(fetched_at) as max_ts from stories where type = '" . $story_type . "'", true);
    $last_updated = $result['max_ts'];

    // Include the template with stories data
    include 'templates/index.php';

} elseif (preg_match('/^\/summary\/(\d+)$/', $path, $matches)) {
    // Handle combined summary page
    $story_id = $matches[1];
    
    // Get story details from database
    $db = new SQLite3(DB_PATH);
    $story = $db->querySingle("SELECT * FROM stories WHERE id = " . intval($story_id), true);
    $story['time_ago'] = format_time_ago((int)$story['timestamp']);

    if (!$story) {
        http_response_code(404);
        echo "Story not found";
        exit;
    }
    
    // Check if summary files exist
    $story_summary_file = "static/content/{$story_id}-story-summary.md";
    $comments_summary_file = "static/content/{$story_id}-comments-summary.md";
    
    $has_story_summary = file_exists($story_summary_file);
    $has_comments_summary = file_exists($comments_summary_file);
    
    if (!$has_story_summary && !$has_comments_summary) {
        http_response_code(404);
        echo "No summaries found for this story";
        exit;
    }
    
    // Load summaries if they exist
    $story_summary = '';
    $comments_summary = '';
    $story_summary_last_updated = '';
    $comments_summary_last_updated = '';
    if ($has_story_summary) {
        $story_summary = file_get_contents($story_summary_file);
        $story_summary_last_updated = format_time_ago(filectime($story_summary_file));
    }
    if ($has_comments_summary) {
        $comments_summary = file_get_contents($comments_summary_file);
        $comments_summary_last_updated = format_time_ago(filectime($comments_summary_file));
    }
    
    // Include the summary template
    include 'templates/summary.php';

} elseif (strpos($path, '/static/') === 0) {
    // Serve static files
    $file = ltrim($path, '/');
    debugLog("Static file request: $file");
    if (file_exists($file)) {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        
        // Set cache control headers for static assets
        header('Cache-Control: public, max-age=31536000'); // 1 year
        header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 31536000));
        
        // Set ETag based on file modification time
        $etag = '"' . md5_file($file) . '"';
        header('ETag: ' . $etag);
        
        // Check if client has a cached version
        $client_etag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($client_etag === $etag) {
            http_response_code(304);
            exit;
        }
        
        // Set appropriate content type
        switch ($extension) {
            case 'css':
                header('Content-Type: text/css');
                break;
            case 'js':
                header('Content-Type: application/javascript');
                break;
            case 'jpg':
            case 'jpeg':
                header('Content-Type: image/jpeg');
                break;
            case 'png':
                header('Content-Type: image/png');
                break;
        }
        
        readfile($file);
    } else {
        debugLog("File not found: $file");
        http_response_code(404);
        echo '404 Not Found';
    }
} else {
    // Handle 404
    debugLog("404 Not Found: $path");
    http_response_code(404);
    echo '404 Not Found';
}