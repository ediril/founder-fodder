<?php


// Security: Only allow execution from command line
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    die('404 Not Found');
}

require_once __DIR__ . '/common.php';

class HackerNewsFetcher
{
    private const BASE_URL = "https://hacker-news.firebaseio.com/v0";
    private const ENDPOINTS = [
        "top" => self::BASE_URL . "/topstories.json",   // front-page
        "new" => self::BASE_URL . "/newstories.json",   // newest
        "best" => self::BASE_URL . "/beststories.json"  // highest-scoring
    ];
    private const ITEM_URL = self::BASE_URL . "/item";

    private string $imgDir;
    private $lockHandle;
    private ?\PDO $pdo = null;

    public function __construct(string $dbPath = DB_PATH, string $imgDir = "static/img/stories")
    {
        $this->imgDir = $imgDir;
        $this->lockHandle = acquireLock(FETCHER_LOCK_FILE_NAME);

        setup_signal_handlers();
        register_shutdown_function([$this, 'releaseResources']);        
        exit_if_other_process_running(SUMMARIZER_LOCK_FILE_NAME);

        $this->pdo = get_db_connection($dbPath);
        
        // Ensure image directory exists
        if (!is_dir($this->imgDir)) {
            mkdir($this->imgDir, 0755, true);
        }
    }

    public function releaseResources(): void
    {
        // Close database connection explicitly
        if (isset($this->pdo)) {
            $this->pdo = null;
        }

        // release process lock
        releaseLock($this->lockHandle, FETCHER_LOCK_FILE_NAME);
    }

    private function fetchStoryIds(string $storyType): array
    {
        $url = self::ENDPOINTS[$storyType] ?? null;
        if (!$url) {
            echo "Invalid story type: {$storyType}\n";
            return [];
        }

        $response = fetchUrl($url);
        if ($response === false) {
            echo "Error fetching {$storyType} stories\n";
            return [];
        }

        $data = json_decode($response, true);
        return $data ?? [];
    }

    private function fetchStoryDetails(int $storyId): ?array
    {
        $url = self::ITEM_URL . "/{$storyId}.json";
        
        $response = fetchUrl($url);
        if ($response === false) {
            echo "Error fetching story {$storyId}\n";
            return null;
        }

        return json_decode($response, true);
    }

    private function saveStoryImage(int $storyId, ?string $imageUrl): void
    {
        // Check if image already exists with any extension
        $extensions = ['.jpg', '.png', '.gif', '.webp'];
        foreach ($extensions as $ext) {
            $filepath = $this->imgDir . "/{$storyId}{$ext}";
            if (file_exists($filepath)) {
                return; // Silently skip if image already exists
            }
        }

        // If no URL or invalid URL, don't save anything
        if (!$imageUrl || !str_starts_with($imageUrl, 'http')) {
            echo "No valid image URL for story {$storyId}\n";
            return;
        }

        try {
            // First check if the URL is actually an image
            $headers = fetchUrl($imageUrl, 10, [], true);
            $contentType = '';
            if ($headers && isset($headers['Content-Type'])) {
                $contentType = is_array($headers['Content-Type']) 
                    ? end($headers['Content-Type']) 
                    : $headers['Content-Type'];
            }

            // If it's not an image, try to extract an image from the page
            if (!str_starts_with($contentType, 'image/')) {
                $imageUrl = $this->extractImageFromPage($imageUrl);
                if (!$imageUrl) {
                    return;
                }
            }

            // Download the image
            $imageData = fetchUrl($imageUrl, 10);
            if ($imageData === false) {
                echo "Failed to download image for story {$storyId}\n";
                return;
            }

            // Determine file extension
            $ext = '.jpg'; // default
            if (str_contains($contentType, 'png')) {
                $ext = '.png';
            } elseif (str_contains($contentType, 'gif')) {
                $ext = '.gif';
            } elseif (str_contains($contentType, 'webp')) {
                $ext = '.webp';
            }

            $filepath = $this->imgDir . "/{$storyId}{$ext}";
            if (file_put_contents($filepath, $imageData) !== false) {
                echo "Successfully downloaded image for story {$storyId}\n";
            }

        } catch (\Exception $e) {
            echo "Error downloading image for story {$storyId}: " . $e->getMessage() . "\n";
        }
    }

    private function extractImageFromPage(string $url): ?string
    {
        try {
            $html = fetchUrl($url, 10);
            if ($html === false) {
                echo "Failed to fetch or empty HTML content for URL: {$url}\n";
                return null;
            }

            return $this->extractImageFromPageWithHtml($url, $html);
        } catch (\Exception $e) {
            echo "Error extracting image from {$url}: " . $e->getMessage() . "\n";
            return null;
        }
    }
    
    private function extractImageFromPageWithHtml(string $url, string $html): ?string
    {
        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);

            $parsedUrl = parse_url($url);
            $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

            // Try Open Graph image
            $ogImage = $xpath->query('//meta[@property="og:image"]/@content');
            if ($ogImage->length > 0) {
                $imgUrl = $ogImage->item(0)->nodeValue;
                if (!str_starts_with($imgUrl, 'http')) {
                    $imgUrl = $baseUrl . '/' . ltrim($imgUrl, '/');
                }
                return $imgUrl;
            }

            // Try Twitter card image
            $twitterImage = $xpath->query('//meta[@name="twitter:image"]/@content');
            if ($twitterImage->length > 0) {
                $imgUrl = $twitterImage->item(0)->nodeValue;
                if (!str_starts_with($imgUrl, 'http')) {
                    $imgUrl = $baseUrl . '/' . ltrim($imgUrl, '/');
                }
                return $imgUrl;
            }

            // Look for large images in content
            $images = $xpath->query('//img[@src]');
            foreach ($images as $img) {
                if (!($img instanceof \DOMElement)) continue;
                
                $src = $img->getAttribute('src');
                if (empty($src)) continue;

                // Skip icons, logos, avatars, etc.
                $skipTerms = ['icon', 'logo', 'avatar', 'favicon', 'spinner', 'button'];
                if (array_any($skipTerms, fn($term) => str_contains(strtolower($src), $term))) {
                    continue;
                }

                // Make relative URLs absolute
                if (!str_starts_with($src, 'http')) {
                    $src = $baseUrl . '/' . ltrim($src, '/');
                }

                // Check dimensions if available
                $width = $img->getAttribute('width');
                $height = $img->getAttribute('height');

                if ($width && $height && (int)$width >= 300 && (int)$height >= 300) {
                    return $src;
                }
            }

            return null;
        } catch (\Exception $e) {
            echo "Error extracting image from {$url}: " . $e->getMessage() . "\n";
            return null;
        }
    }

    private function extractImageUrl(string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        try {
            $parsedUrl = parse_url($url);
            $path = strtolower($parsedUrl['path'] ?? '');

            // Skip common non-HTML file types
            $skipExtensions = [
                '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx',
                '.txt', '.rtf', '.csv', '.zip', '.rar', '.tar', '.gz',
                '.mp3', '.mp4', '.avi', '.mov', '.wmv', '.flv', '.mkv',
                '.exe', '.dmg', '.iso', '.img', '.bin', '.dat'
            ];

            foreach ($skipExtensions as $ext) {
                if (str_ends_with($path, $ext)) {
                    echo "Skipping image extraction for non-HTML url: {$url}\n";
                    return null;
                }
            }

            print("Fetching page {$url}\n");

            $html = fetchUrl($url, 10);
            if ($html === false) {
                echo "Failed to fetch page {$url} or empty content\n";
                return null;
            }

            return $this->extractImageFromPageWithHtml($url, $html);

        } catch (\Exception $e) {
            echo "Error extracting image from {$url}: " . $e->getMessage() . "\n";
            return null;
        }
    }

    private function extractDomain(string $url): string
    {
        if (empty($url)) {
            return "";
        }

        $parsedUrl = parse_url($url);
        return $parsedUrl['host'] ?? "";
    }

    private function storeStory(array $story, int $storyTypeId, int $ranking): void
    {
        if (empty($story)) {
            return;
        }

        // Extract fields with defaults for missing values
        $storyId = $story['id'] ?? 0;
        $title = $story['title'] ?? '';
        $url = $story['url'] ?? '';
        $domain = $this->extractDomain($url);
        $commentsUrl = "https://news.ycombinator.com/item?id={$storyId}";
        $commentsCount = $story['descendants'] ?? 0;
        $score = $story['score'] ?? 0;
        $user = $story['by'] ?? '';
        $timestamp = $story['time'] ?? 0;
        $fetchedAt = time();

        // Check if image already exists for this story
        $imageExists = false;
        $extensions = ['.jpg', '.png', '.gif', '.webp'];
        foreach ($extensions as $ext) {
            if (file_exists($this->imgDir . "/{$storyId}{$ext}")) {
                $imageExists = true;
                break;
            }
        }

        // Only try to fetch image if it doesn't exist
        if (!$imageExists && !empty($url)) {
            try {
                $imageUrl = $this->extractImageUrl($url);
                $this->saveStoryImage($storyId, $imageUrl);
            } catch (\Exception $e) {
                echo "Error processing image for story {$storyId}: " . $e->getMessage() . "\n";
                $this->saveStoryImage($storyId, null);
            }
        } elseif (!$imageExists) {
            $this->saveStoryImage($storyId, null);
        }

        // Check if story already exists in the database
        // TODO: We can do the fetch and store in one go
        $stmt = $this->pdo->prepare('SELECT id FROM stories WHERE id = ?');
        $stmt->execute([$storyId]);
        $existing = $stmt->fetch();
        if ($existing) {
            // Update existing story
            $stmt = $this->pdo->prepare('
                UPDATE stories
                SET title = ?, url = ?, domain = ?, comments_url = ?,
                    comments_count = ?, score = ?, user = ?,
                    timestamp = ?, fetched_at = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $title, $url, $domain, $commentsUrl, $commentsCount, $score,
                $user, $timestamp, $fetchedAt, $storyId
            ]);
        } else {
            // Insert new story
            $stmt = $this->pdo->prepare('
                INSERT INTO stories
                (id, title, url, domain, comments_url, comments_count, 
                 score, user, timestamp, fetched_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $storyId, $title, $url, $domain, $commentsUrl, $commentsCount,
                $score, $user, $timestamp, $fetchedAt
            ]);
        }

        // Handle ranking data separately
        // Check if rank entry already exists
        $stmt = $this->pdo->prepare('SELECT id FROM ranks WHERE id = ? AND type = ?');
        $stmt->execute([$storyId, $storyTypeId]);
        $existingRank = $stmt->fetch();

        if ($existingRank) {
            // Update existing rank
            $stmt = $this->pdo->prepare('
                UPDATE ranks
                SET rank = ?
                WHERE id = ? AND type = ?
            ');
            $stmt->execute([$ranking, $storyId, $storyTypeId]);
        } else {
            // Insert new rank
            $stmt = $this->pdo->prepare('
                INSERT INTO ranks (id, type, rank)
                VALUES (?, ?, ?)
            ');
            $stmt->execute([$storyId, $storyTypeId, $ranking]);
        }
    }

    public function fetchAndStore(string $storyType = "top", int $limit = 100): int
    {
        logMessage("Fetching {$storyType} stories...");
        $storyIds = $this->fetchStoryIds($storyType);

        if (empty($storyIds)) {
            logMessage("No {$storyType} story IDs found");
            return 0;
        }

        // Limit the number of stories to process
        $storyIds = array_slice($storyIds, 0, $limit);
        $newStoriesCount = count($storyIds);

        try {
            $this->pdo->beginTransaction();
            
            // Get the type ID for this story type
            $typeId = STORY_TYPES[$storyType] ?? 0;
            if ($typeId > 0) {
                // Shift down the rankings of existing stories that aren't being refetched
                $placeholders = str_repeat('?,', count($storyIds) - 1) . '?';
                $stmt = $this->pdo->prepare("
                    UPDATE ranks 
                    SET rank = rank + ?
                    WHERE type = ? AND id NOT IN ({$placeholders})
                ");
                $params = array_merge([$newStoriesCount, $typeId], $storyIds);
                $stmt->execute($params);
            }

            $count = 0;
            foreach ($storyIds as $ranking => $storyId) {
                $story = $this->fetchStoryDetails($storyId);
                if ($story) {
                    $this->storeStory($story, $typeId, $ranking);
                    $count++;

                // Print progress every 10 stories
                if ($count % 10 === 0) {
                    logMessage("Processed {$count}/" . count($storyIds) . " {$storyType} stories");
                }

                    // Small delay to be nice to the API
                    usleep(100000); // 0.1 seconds
                }
            }
            
            $this->pdo->commit();
            logMessage("Completed fetching {$count} {$storyType} stories");
            
            return $count;
            
        } catch (\Exception $e) {
            $this->pdo->rollback();
            logMessage("Error during batch processing: " . $e->getMessage());
            throw $e;
        }
    }

    public function fetchAll(int $limit = 100): int
    {
        $total = 0;
        foreach (array_keys(self::ENDPOINTS) as $storyType) {
            $count = $this->fetchAndStore($storyType, $limit);
            $total += $count;
        }

        logMessage("Total stories fetched: {$total}");
                
        return $total;
    }

    /**
     * Fetch and store a single story by ID
     */
    public function fetchStoryById(int $storyId): bool
    {
        echo "Fetching story {$storyId}...\n";
        
        $story = $this->fetchStoryDetails($storyId);
        if (!$story) {
            echo "Story {$storyId} not found or could not be fetched\n";
            return false;
        }

        try {
            $this->pdo->beginTransaction();
            
            // For single story fetch, we don't have ranking context, so use 0
            // and don't shift existing rankings
            $this->storeStory($story, STORY_TYPES["new"], 0);
            
            $this->pdo->commit();
            echo "Successfully fetched and stored story {$storyId}\n";
            return true;
            
        } catch (\Exception $e) {
            $this->pdo->rollback();
            echo "Error storing story {$storyId}: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Remove images from static/img/stories that don't exist in the database.
     */
    public function cleanupUnusedImages(): void {
        echo "\nCleaning up unused images...\n";
        
        try {
            // Get all story IDs from the database
            $stmt = $this->pdo->prepare("SELECT id FROM stories");
            $stmt->execute();
            $storyIds = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $storyIds[] = (string)$row['id'];
            }
            
            // Check if image directory exists
            if (!is_dir($this->imgDir)) {
                echo "Warning: Image directory {$this->imgDir} does not exist, skipping cleanup.\n";
                return;
            }
            
            // Get all image files in the static/img/stories directory
            $imageFiles = [];
            $allowedExtensions = ['.jpg', '.jpeg', '.png', '.gif', '.webp'];
            
            $files = scandir($this->imgDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                // Check if file has an allowed image extension
                $hasValidExtension = false;
                foreach ($allowedExtensions as $ext) {
                    if (str_ends_with(strtolower($file), $ext)) {
                        $hasValidExtension = true;
                        break;
                    }
                }
                
                if ($hasValidExtension) {
                    $imageFiles[] = $file;
                }
            }
            
            // Find images that don't have corresponding stories in the database
            $unusedImages = [];
            foreach ($imageFiles as $imageFile) {
                // Extract story ID from image filename (assuming format: story_id.ext)
                $storyId = pathinfo($imageFile, PATHINFO_FILENAME);
                if (!in_array($storyId, $storyIds)) {
                    $unusedImages[] = $imageFile;
                }
            }
            
            // Remove unused images
            $removedCount = 0;
            foreach ($unusedImages as $imageFile) {
                $imagePath = $this->imgDir . '/' . $imageFile;
                try {
                    if (unlink($imagePath)) {
                        echo "Removed unused image: {$imageFile}\n";
                        $removedCount++;
                    }
                } catch (\Exception $e) {
                    echo "Error removing image {$imageFile}: " . $e->getMessage() . "\n";
                }
            }
            
            echo "Cleanup complete. Removed {$removedCount} unused images.\n";
            
        } catch (\Exception $e) {
            echo "Error during image cleanup: " . $e->getMessage() . "\n";
        }
    }
}

// Helper function for array_any (not available in PHP 8.3)
if (!function_exists('array_any')) {
    function array_any(array $array, callable $callback): bool
    {
        foreach ($array as $item) {
            if ($callback($item)) {
                return true;
            }
        }
        return false;
    }
}

// Command line interface
if (php_sapi_name() === 'cli') {
    // Define valid options
    $options = getopt('h', ['type:', 'limit:', 'db:', 'id:', 'help']);
    
    if (count($options) !== count($argv) - 1) {
        $invalidCount = (count($argv) - 1) - count($options);
        logMessage("{$invalidCount} invalid options found\n");
        exit(1);
    }
    
    if (isset($options['help'])) {
        echo "Usage: php fetch.php [options]\n";
        echo "Options:\n";
        echo "  --type=TYPE     Type of stories to fetch: top, new, best, all (default: all)\n";
        echo "  --limit=LIMIT   Maximum number of stories to fetch per type (default: 100)\n";
        echo "  --db=PATH       Path to SQLite database (default: stories.db)\n";
        echo "  --id=STORY-ID   Fetch a single story by ID\n";
        echo "  --help, -h      Show this help message\n";
        exit(0);
    }
    
    $type = $options['type'] ?? 'all';
    $limit = (int)($options['limit'] ?? MAX_FETCH);
    $db = $options['db'] ?? DB_PATH;
    $storyId = isset($options['id']) ? (int)$options['id'] : null;
    
    $validTypes = ['top', 'new', 'best', 'all'];
    if (!in_array($type, $validTypes)) {
        echo "Error: Invalid type '{$type}'. Valid types are: " . implode(', ', $validTypes) . "\n";
        exit(1);
    }
    
    $fetcher = new HackerNewsFetcher($db, "static/img/stories");

    logMessage("Fetcher Version: " . FETCHER_VERSION);
    
    if ($storyId) {
        // Fetch single story by ID
        $success = $fetcher->fetchStoryById($storyId);
        if (!$success) {
            exit(1);
        }
    } else {
        // Fetch stories by type
        if ($type === 'all') {
            $fetcher->fetchAll($limit);
        } else {
            $fetcher->fetchAndStore($type, $limit);
        }

        // Clean up unused images after fetching
        $fetcher->cleanupUnusedImages();
    }
} 