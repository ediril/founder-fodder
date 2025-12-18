<?php
declare(strict_types=1);

require_once __DIR__ . '/banalytiq/banalytiq.php';
record_visit();

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/markdown_parser.php';

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
 * Get stories for a specific page with pagination
 */
function get_page_stories(string $story_type, int $page, int $per_page): array {
    try {
        // Calculate offset for pagination
        $offset = ($page - 1) * $per_page;
        
        debugLog("Getting page stories: type=$story_type, page=$page, per_page=$per_page, offset=$offset");
        
        // Get type ID for the story type
        $type_id = STORY_TYPES[$story_type] ?? null;
        if (!$type_id) {
            debugLog("Invalid story type: $story_type");
            throw new Exception("Invalid story type: $story_type");
        }
        
        $conn = get_db_connection();
        
        // Get total count for pagination
        $stmt = $conn->prepare('SELECT COUNT(*) as total FROM ranks WHERE type = ?');
        $stmt->execute([$type_id]);
        $result = $stmt->fetch();
        $total = (int)($result['total'] ?? 0);
        
        // Get stories by type, ordered by ranking
        $stmt = $conn->prepare('
            SELECT s.id, s.title, s.url, s.domain, s.comments_url, s.comments_count, 
                   s.score, s.user, s.timestamp, r.type, r.rank as ranking
            FROM stories s
            INNER JOIN ranks r ON s.id = r.id
            WHERE r.type = ?
            ORDER BY r.rank
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([$type_id, $per_page, $offset]);
        
        // Convert to array of associative arrays
        $stories = [];
        $story_ids = [];
        while ($row = $stmt->fetch()) {
            // Add additional fields
            $row['time_ago'] = format_time_ago((int)$row['timestamp']);
            $row['formatted_time'] = date("Y-m-d H:i:s", (int)$row['timestamp']);
            
            // Convert type ID back to string for compatibility
            $row['type'] = $story_type;
            
            $stories[] = $row;
            $story_ids[] = $row['id'];
        }
        
        // Batch check image and summary files for all stories at once
        if (!empty($story_ids)) {
            $image_file_types = batch_check_image_files($story_ids);
            $summary_files = batch_check_summary_files($story_ids);
            
            // Add file information to each story
            foreach ($stories as &$story) {
                // Generate image URL based on story ID if the image exists
                if ($image_file_types[$story['id']] ?? false) {
                    $story['image_url'] = "/static/img/stories/{$story['id']}.{$image_file_types[$story['id']]}";
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
 * Get mixed stories with pagination
 */
function get_mixed_stories(int $page = 1): array {
    $total_per_page = ACTIVE_PER_PAGE + TOP_PER_PAGE + NEW_PER_PAGE; // 10 stories per page
    
    try {
        debugLog("Getting mixed stories: page=$page, active=" . ACTIVE_PER_PAGE . ", top=" . TOP_PER_PAGE . ", new=" . NEW_PER_PAGE);
        
        $conn = get_db_connection();
        
        // Calculate offsets for pagination
        $active_offset = ($page - 1) * ACTIVE_PER_PAGE;
        $top_offset = ($page - 1) * TOP_PER_PAGE;
        $new_offset = ($page - 1) * NEW_PER_PAGE;
        
        // Active stories query: can overlap with other types
        $active_sql = '
            SELECT s.id, s.title, s.url, s.domain, s.comments_url, s.comments_count, 
                   s.score, s.user, s.timestamp,
                   r.type as display_category,
                   r.rank as ranking
            FROM stories s
            INNER JOIN ranks r ON s.id = r.id
            WHERE r.type = ?
            ORDER BY r.rank ASC, s.id ASC 
            LIMIT ? OFFSET ?';
        
        // Top stories query: ONLY top stories (not active, not new)
        $top_sql = '
            SELECT s.id, s.title, s.url, s.domain, s.comments_url, s.comments_count, 
                   s.score, s.user, s.timestamp,
                   r.type as display_category,
                   r.rank as ranking
            FROM stories s
            INNER JOIN ranks r ON s.id = r.id
            WHERE r.type = ? 
              AND s.id NOT IN (SELECT id FROM ranks WHERE type = ?)  -- not active
              AND s.id NOT IN (SELECT id FROM ranks WHERE type = ?)  -- not new
            ORDER BY r.rank ASC, s.id ASC 
            LIMIT ? OFFSET ?';
        
        // New stories query: ONLY new stories (not active, not top)  
        $new_sql = '
            SELECT s.id, s.title, s.url, s.domain, s.comments_url, s.comments_count, 
                   s.score, s.user, s.timestamp,
                   r.type as display_category,
                   r.rank as ranking
            FROM stories s
            INNER JOIN ranks r ON s.id = r.id
            WHERE r.type = ?
              AND s.id NOT IN (SELECT id FROM ranks WHERE type = ?)  -- not active
              AND s.id NOT IN (SELECT id FROM ranks WHERE type = ?)  -- not top
            ORDER BY r.rank ASC, s.id ASC 
            LIMIT ? OFFSET ?';
        
        // Prepare queries for each story type
        $active_stmt = $conn->prepare($active_sql);
        $top_stmt = $conn->prepare($top_sql);
        $new_stmt = $conn->prepare($new_sql);
        
        // Execute queries
        $active_stmt->execute([STORY_TYPES['active'], ACTIVE_PER_PAGE, $active_offset]);
        $top_stmt->execute([STORY_TYPES['top'], STORY_TYPES['active'], STORY_TYPES['new'], TOP_PER_PAGE, $top_offset]);
        $new_stmt->execute([STORY_TYPES['new'], STORY_TYPES['active'], STORY_TYPES['top'], NEW_PER_PAGE, $new_offset]);
        
        // Collect stories in order: active, then top, then new
        $mixed_stories = [];
        
        // Add active stories
        while ($row = $active_stmt->fetch()) {
            $row['time_ago'] = format_time_ago((int)$row['timestamp']);
            $row['formatted_time'] = date("Y-m-d H:i:s", (int)$row['timestamp']);
            $row['type'] = 'active';
            $mixed_stories[] = $row;
        }
        
        // Add top stories
        while ($row = $top_stmt->fetch()) {
            $row['time_ago'] = format_time_ago((int)$row['timestamp']);
            $row['formatted_time'] = date("Y-m-d H:i:s", (int)$row['timestamp']);
            $row['type'] = 'top';
            $mixed_stories[] = $row;
        }
        
        // Add new stories
        while ($row = $new_stmt->fetch()) {
            $row['time_ago'] = format_time_ago((int)$row['timestamp']);
            $row['formatted_time'] = date("Y-m-d H:i:s", (int)$row['timestamp']);
            $row['type'] = 'new';
            $mixed_stories[] = $row;
        }
        
        // Get all story IDs for batch processing
        $story_ids = array_column($mixed_stories, 'id');
        
        // Batch check image and summary files for all stories at once
        if (!empty($story_ids)) {
            $image_file_types = batch_check_image_files($story_ids);
            $summary_files = batch_check_summary_files($story_ids);
            
            // Add file information to each story
            foreach ($mixed_stories as &$story) {
                // Generate image URL based on story ID if the image exists
                if ($image_file_types[$story['id']] ?? false) {
                    $story['image_url'] = "/static/img/stories/{$story['id']}.{$image_file_types[$story['id']]}";
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
        
        // Calculate pagination info
        // Get total count for each story type (accounting for exclusions)
        $active_total_stmt = $conn->prepare('SELECT COUNT(*) as total FROM ranks WHERE type = ?');
        
        $top_total_stmt = $conn->prepare('
            SELECT COUNT(*) as total 
            FROM ranks r 
            WHERE r.type = ? 
              AND r.id NOT IN (SELECT id FROM ranks WHERE type = ?)  -- not active
              AND r.id NOT IN (SELECT id FROM ranks WHERE type = ?)  -- not new
        ');
        
        $new_total_stmt = $conn->prepare('
            SELECT COUNT(*) as total 
            FROM ranks r 
            WHERE r.type = ?
              AND r.id NOT IN (SELECT id FROM ranks WHERE type = ?)  -- not active  
              AND r.id NOT IN (SELECT id FROM ranks WHERE type = ?)  -- not top
        ');
        
        $active_total_stmt->execute([STORY_TYPES['active']]);
        $top_total_stmt->execute([STORY_TYPES['top'], STORY_TYPES['active'], STORY_TYPES['new']]);
        $new_total_stmt->execute([STORY_TYPES['new'], STORY_TYPES['active'], STORY_TYPES['top']]);
        
        $active_total = (int)($active_total_stmt->fetch()['total'] ?? 0);
        $top_total = (int)($top_total_stmt->fetch()['total'] ?? 0);
        $new_total = (int)($new_total_stmt->fetch()['total'] ?? 0);
        
        // Calculate total pages based on the limiting factor (which story type runs out first)
        $active_pages = ceil($active_total / ACTIVE_PER_PAGE);
        $top_pages = ceil($top_total / TOP_PER_PAGE);
        $new_pages = ceil($new_total / NEW_PER_PAGE);
        $total_pages = min($active_pages, $top_pages, $new_pages);
        
        $has_more = $page < $total_pages;
        
        $pagination = [
            'per_page' => $total_per_page,
            'total' => $active_total + $top_total + $new_total,
            'has_more' => $has_more,
            'next_page' => $has_more ? $page + 1 : null,
            'current_page' => $page,
            'total_pages' => $total_pages
        ];
        
        return [
            'stories' => $mixed_stories,
            'pagination' => $pagination,
            'type' => 'mixed'
        ];
    } catch (Exception $e) {
        debugLog("Error getting mixed stories: " . $e->getMessage());
        return [
            'stories' => [],
            'pagination' => [
                'per_page' => $total_per_page,
                'total' => 0,
                'has_more' => false,
                'next_page' => null,
                'current_page' => $page,
                'total_pages' => 0
            ],
            'type' => 'mixed',
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
        $results[$story_id] = file_exists($img_path) ? "jpg" : null;
        if (!$results[$story_id]) {
            $img_path = "static/img/stories/{$story_id}.png";
            $results[$story_id] = file_exists($img_path) ? "png" : null;
        }
        if (!$results[$story_id]) {
            $img_path = "static/img/stories/{$story_id}.webp";
            $results[$story_id] = file_exists($img_path) ? "webp" : null;
        }
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
if ($path === null) {
    debugLog("Malformed URL: $request_uri");
    http_response_code(400);
    echo '400 Bad Request - Malformed URL';
    exit;
}

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
    // News section - set active tab
    $active_tab = 'news';

    // Check if a specific story type is requested
    $story_type = $_GET['type'] ?? null;
    
    // Initialize variables
    $stories = null;
    $last_updated = null;
    
    if ($story_type && in_array($story_type, ['top', 'new', 'active', 'best'])) {
        // Get stories for specific type
        $page = max(1, (int)($_GET['page'] ?? 1));
        // Use same per-page count as mixed view
        $per_page = DEFAULT_PER_PAGE;
        $stories = get_page_stories($story_type, $page, $per_page);
        
        $db = get_db_connection();
        // Get last updated time for this story type
        $stmt = $db->prepare("
            SELECT MAX(s.fetched_at) as max_ts
            FROM stories s
            INNER JOIN ranks r ON s.id = r.id
            WHERE r.type = ?");
        $stmt->execute([STORY_TYPES[$story_type]]);
        $result = $stmt->fetch();
        $last_updated = $result['max_ts'] ?? null;
        close_db_connection($db);
    } else {
        // Get mixed stories for the main page (active stories interspersed with top/best)
        $page = max(1, (int)($_GET['page'] ?? 1));
        
        // Get mixed stories with page-based pagination
        $stories = get_mixed_stories($page);
        
        $db = get_db_connection();
        // Get last updated time from active stories (main stream)
        $stmt = $db->prepare("
            SELECT MAX(s.fetched_at) as max_ts
            FROM stories s
            INNER JOIN ranks r ON s.id = r.id
            WHERE r.type = ?");
        $stmt->execute([STORY_TYPES['active']]);
        $result = $stmt->fetch();
        $last_updated = $result['max_ts'] ?? null;
        close_db_connection($db);
    }

    // Include the template with stories data and active tab
    include 'templates/index.php';

} elseif (preg_match('/^\/create-comment-request\/(\d+)$/', $path, $matches)) {
    // Handle request file creation for comment summarization
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    // Validate CSRF token
    $csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!validate_csrf_token($csrf_token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    $story_id = $matches[1];
    $request_file = __DIR__ . "/comment_summary_req/{$story_id}.txt";

    // If request file already exists, request is already submitted
    if (file_exists($request_file)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Request already submitted']);
        exit;
    }

    // Rate limiting per IP address (only trust REMOTE_ADDR to prevent header spoofing)
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip_hash = md5($client_ip);
    $rate_limit_file = __DIR__ . "/comment_summary_req/_rate_limit_{$ip_hash}";

    try {
        // Check server-side rate limiting (15 seconds)
        if (file_exists($rate_limit_file)) {
            $last_request_time = filemtime($rate_limit_file);
            $time_since_last = time() - $last_request_time;

            if ($time_since_last < 15) {
                $remaining_time = 15 - $time_since_last;
                http_response_code(429); // Too Many Requests
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => 'Rate limited',
                    'message' => "Please wait {$remaining_time} seconds before requesting again.",
                    'remaining_seconds' => $remaining_time
                ]);
                exit;
            }
        }

        // Update rate limit file
        touch($rate_limit_file);

        // Create empty request file
        if (file_put_contents($request_file, '') === false) {
            throw new Exception('Failed to create request file');
        }

        // Trigger summarizer in TRUE background (fire and forget)
        // Use CLI PHP binary (not the web server handler like lsphp)
        $php_binary = '/usr/local/bin/php';

        $summarizer_path = __DIR__ . '/summarizer.php';

        // Create a temporary log file for debugging production issues
        $log_file = __DIR__ . "/logs/summarizer_{$story_id}.log";
        if (!is_dir(__DIR__ . '/logs')) {
            @mkdir(__DIR__ . '/logs', 0755, true);
        }

        // Spawn in background - log output for debugging
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: use START command
            $command = "start /B " . escapeshellarg($php_binary) . " " . escapeshellarg($summarizer_path) . " --id={$story_id} > " . escapeshellarg($log_file) . " 2>&1";
            pclose(popen($command, 'r'));
            $return_var = 0; // Assume success on Windows
        } else {
            // Unix/Linux/Mac: use nohup and & to detach
            $command = "nohup " . escapeshellarg($php_binary) . " " . escapeshellarg($summarizer_path) . " --id={$story_id} > " . escapeshellarg($log_file) . " 2>&1 &";
            exec($command, $output, $return_var);
        }

        header('Content-Type: application/json');

        // Since we spawned in background, we can't detect lock conflicts immediately
        // Just return success and let polling detect when it's done
        echo json_encode([
            'success' => true,
            'message' => 'Summarization started in background'
        ]);

    } catch (Exception $e) {
        // Clean up request file if it was created
        if (file_exists($request_file)) {
            unlink($request_file);
        }

        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;

} elseif (preg_match('/^\/summary\/(\d+)$/', $path, $matches)) {
    // Handle combined summary page
    $story_id = $matches[1];
    
    // Get story details from database
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT * FROM stories WHERE id = ?");
    $stmt->execute([$story_id]);
    $story = $stmt->fetch();

    // Bulletproof validation: ensure we have a valid story array with required fields
    if (!$story || !is_array($story) || !isset($story['id'], $story['title'], $story['timestamp'])) {
        close_db_connection($db);
        http_response_code(404);
        echo "Story not found";
        exit;
    }

    close_db_connection($db);
    $story['time_ago'] = format_time_ago((int)$story['timestamp']);
    
    // Check if summary files exist
    $story_summary_file = "static/content/{$story_id}-story-summary.md";
    $comments_summary_file = "static/content/{$story_id}-comments-summary.md";
    $request_file = __DIR__ . "/comment_summary_req/{$story_id}.txt";

    $has_story_summary = file_exists($story_summary_file);
    $has_comments_summary = file_exists($comments_summary_file);
    $has_pending_request = file_exists($request_file);

    // If summary already exists but request file is still there, clean it up
    if ($has_comments_summary && $has_pending_request) {
        unlink($request_file);
        $has_pending_request = false;
        error_log("Cleaned up stale request file for story {$story_id} - summary already exists");
    }
    
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

    // News section - set active tab
    $active_tab = 'news';

    include 'templates/summary.php';

} elseif ($path === '/about') {
    include 'templates/about.php';

} elseif ($path === '/legal') {
    include 'templates/legal.php';

} elseif ($path === '/privacy') {
    include 'templates/privacy.php';

} elseif ($path === '/blog') {
    // List all blog posts
    $posts = get_all_blog_posts();
    include 'templates/blog.php';

} elseif (preg_match('/^\/blog\/(\d{4}-\d{2}-\d{2})$/', $path, $matches)) {
    // Individual blog post
    $date_slug = $matches[1];
    $post = get_blog_post_by_date($date_slug);

    if (!$post) {
        http_response_code(404);
        echo '404 Not Found';
        exit;
    }

    // Parse markdown content to HTML
    $post['html_content'] = parse_markdown($post['content']);

    include 'templates/blog_post.php';

} elseif ($path === '/inspiration') {
    // List all inspiration stories
    $inspiration_dir = __DIR__ . '/inspire';
    $people = [];

    if (is_dir($inspiration_dir)) {
        $files = glob($inspiration_dir . '/*.yml');
        foreach ($files as $file) {
            $slug = basename($file, '.yml');

            // Use filename as display name (convert dashes to spaces and title case)
            $name = ucwords(str_replace('-', ' ', $slug));

            $people[] = [
                'name' => $name,
                'slug' => $slug,
                'mtime' => filemtime($file)
            ];
        }

        // Sort by modification time, most recent first
        usort($people, function($a, $b) {
            return $b['mtime'] - $a['mtime'];
        });
    }

    // Inspiration section - set active tab
    $active_tab = 'inspiration';

    include 'templates/inspiration.php';

} elseif ($path === '/favicon.ico') {
    // Handle favicon requests
    $file = 'favicon.ico';
    if (file_exists($file)) {
        header('Content-Type: image/x-icon');
        header('Cache-Control: public, max-age=31536000'); // 1 year
        readfile($file);
    } else {
        http_response_code(404);
        echo '404 Not Found';
    }
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
            case 'webp':
                header('Content-Type: image/webp');
                break;
            case 'svg':
                header('Content-Type: image/svg+xml');
                break;
        }
        
        readfile($file);
    } else {
        debugLog("File not found: $file");
        http_response_code(404);
        echo '404 Not Found';
    }
} elseif (preg_match('/^\/([a-z0-9\-]+)$/', $path, $matches)) {
    // Try to match inspiration story (catch-all for top-level slugs)
    $story_name = $matches[1];

    // Security: Validate against whitelist of actual inspiration story files
    $allowed_stories = [];
    $inspiration_files = glob(__DIR__ . '/inspire/*.yml');
    foreach ($inspiration_files as $file) {
        $allowed_stories[] = basename($file, '.yml');
    }

    if (!in_array($story_name, $allowed_stories, true)) {
        // Not a valid inspiration story, return 404
        debugLog("404 Not Found: $path (not in whitelist)");
        http_response_code(404);
        echo '404 Not Found';
        exit;
    }

    $yml_file = __DIR__ . '/inspire/' . $story_name . '.yml';

    if (file_exists($yml_file)) {
        // Check if images need to be generated
        require_once __DIR__ . '/generate_inspiration.php';

        $story_dir = __DIR__ . '/inspire/' . $story_name;
        $needs_generation = false;

        if (!is_dir($story_dir)) {
            $needs_generation = true;
        } else {
            // Check if at least page_0.jpg exists
            if (!file_exists("$story_dir/page_0.jpg")) {
                $needs_generation = true;
            }
        }

        // Handle generation as a separate API endpoint
        if ($needs_generation && isset($_GET['gen']) && $_GET['gen'] === '1') {
            // This is an AJAX/iframe generation request
            debugLog("Generating images for inspiration story: $story_name");
            $result = generate_inspiration_story($story_name);

            if (!$result['success']) {
                debugLog("Failed to generate inspiration story: " . $result['error']);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $result['error']]);
                exit;
            }

            // Return success
            echo json_encode(['success' => true]);
            exit;
        }

        // Count pages
        $page_files = glob("$story_dir/page_*.jpg");
        $page_count = count($page_files);

        // If pages don't exist yet, show carousel with modal
        if ($page_count === 0 && $needs_generation) {
            $page_count = 1; // Set to 1 so carousel initializes
            $show_generation_modal = true;
        } else {
            $show_generation_modal = false;
        }

        if ($page_count === 0) {
            http_response_code(500);
            echo "No pages generated for this story";
            exit;
        }

        // Parse YAML file to get references and story text
        $parsed_data = parse_inspiration_yml($yml_file);
        $references = $parsed_data['references'] ?? [];
        $story_pages = $parsed_data['pages'] ?? [];

        // Extract all text for SEO
        $story_text_blocks = [];
        foreach ($story_pages as $page) {
            if (!empty($page['text'])) {
                $story_text_blocks[] = $page['text'];
            }
        }

        // Generate proper display name from slug for SEO
        $display_name = ucwords(str_replace('-', ' ', $story_name));

        // Get first text block as meta description (the "hook" line)
        $first_text = $story_text_blocks[0] ?? '';
        $meta_description = $first_text;
        if (strlen($meta_description) > 160) {
            $meta_description = substr($meta_description, 0, 157) . '...';
        }

        // SEO variables for template
        $page_title = "The {$display_name} Story - Founder Fodder";
        $page_description = $meta_description;
        $og_title = "The {$display_name} Story";
        $og_description = $meta_description;
        $og_type = 'article';

        // Use founder's profile image as OG image
        $profile_image = "/inspire/{$story_name}.jpg";
        if (file_exists(__DIR__ . $profile_image)) {
            $og_image = "https://founderfodder.com{$profile_image}";
            $og_image_alt = $display_name;
        }

        // Get adjacent stories for "Have you read these other stories?" section
        $adjacent_stories = [];
        $inspiration_dir = __DIR__ . '/inspire';
        if (is_dir($inspiration_dir)) {
            $files = glob($inspiration_dir . '/*.yml');
            $all_stories = [];
            foreach ($files as $file) {
                $slug = basename($file, '.yml');
                $all_stories[] = [
                    'slug' => $slug,
                    'name' => ucwords(str_replace('-', ' ', $slug)),
                    'mtime' => filemtime($file)
                ];
            }

            // Sort by modification time, most recent first (same as list view)
            usort($all_stories, function($a, $b) {
                return $b['mtime'] - $a['mtime'];
            });

            // Find current story's position
            $current_index = -1;
            foreach ($all_stories as $index => $story) {
                if ($story['slug'] === $story_name) {
                    $current_index = $index;
                    break;
                }
            }

            // Determine adjacent stories
            if ($current_index !== -1 && count($all_stories) > 1) {
                $has_before = $current_index > 0;
                $has_after = $current_index < count($all_stories) - 1;

                if ($has_before && $has_after) {
                    // Prefer: one before, one after
                    $adjacent_stories[] = $all_stories[$current_index - 1];
                    $adjacent_stories[] = $all_stories[$current_index + 1];
                } elseif (!$has_after && $current_index >= 2) {
                    // No after: 2 before
                    $adjacent_stories[] = $all_stories[$current_index - 2];
                    $adjacent_stories[] = $all_stories[$current_index - 1];
                } elseif (!$has_after && $current_index >= 1) {
                    // Only 1 before available
                    $adjacent_stories[] = $all_stories[$current_index - 1];
                } elseif (!$has_before && $current_index + 2 < count($all_stories)) {
                    // No before: 2 after
                    $adjacent_stories[] = $all_stories[$current_index + 1];
                    $adjacent_stories[] = $all_stories[$current_index + 2];
                } elseif (!$has_before && $current_index + 1 < count($all_stories)) {
                    // Only 1 after available
                    $adjacent_stories[] = $all_stories[$current_index + 1];
                }
            }
        }

        // Inspiration section - set active tab
        $active_tab = 'inspiration';

        include 'templates/inspiration_carousel.php';
    } else {
        // Handle 404
        debugLog("404 Not Found: $path");
        http_response_code(404);
        echo '404 Not Found';
    }
} else {
    // Handle 404
    debugLog("404 Not Found: $path");
    http_response_code(404);
    echo '404 Not Found';
}