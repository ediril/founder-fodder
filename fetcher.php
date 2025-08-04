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
    private const ACTIVE_URL = "https://news.ycombinator.com/active";

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
        if ($storyType === 'active') {
            return $this->fetchActiveStories();
        }

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

    /**
     * Fetch active stories by scraping the HTML page
     */
    private function fetchActiveStories(int $limit = 200): array
    {
        logMessage('Fetching active stories from web page...');

        $pagePath = 'active';
        $stories = [];
        $seenIds = [];

        while ($pagePath && count($stories) < $limit) {
            $html = fetchUrl('https://news.ycombinator.com/' . $pagePath);
            if ($html === false) {
                logMessage('Failed to fetch ' . $pagePath);
                break;
            }

            [$pageStories, $nextPath] = $this->parseActivePage($html);
            foreach ($pageStories as $story) {
                if (count($stories) >= $limit) break;
                if (isset($seenIds[$story['id']])) continue; // skip duplicates
                $seenIds[$story['id']] = true;
                $stories[] = $story;
            }

            $pagePath = $nextPath;
        }

        // Return just the story IDs for consistency with other fetch methods
        return array_column($stories, 'id');
    }

    /**
     * Parse a single /active page; return [stories, nextPath].
     */
    private function parseActivePage(string $html): array
    {
        $dom = createUtf8DOMDocument($html);
        $xpath = new \DOMXPath($dom);

        $stories = [];

        // Iterate through every top level row (class="athing")
        foreach ($xpath->query('//tr[contains(@class, "athing")]') as $athing) {
            if (!($athing instanceof \DOMElement)) continue;
            $id = (int)$athing->getAttribute('id');

            // title & url - modern HN structure uses titleline span
            $titleAnchor = $xpath->query('.//span[@class="titleline"]/a', $athing)->item(0);
            if (!$titleAnchor) continue;
            $title = html_entity_decode($titleAnchor->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $url = $titleAnchor->getAttribute('href');

            // domain
            $domain = '';
            $domainSpan = $xpath->query('.//span[@class="sitestr"]', $athing)->item(0);
            if ($domainSpan) {
                $domain = html_entity_decode($domainSpan->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            // subtext row is the next element row
            $sub = $athing->nextSibling;
            while ($sub && $sub->nodeType !== XML_ELEMENT_NODE) {
                $sub = $sub->nextSibling;
            }
            if (!$sub) continue;

            $score = 0;
            $scoreSpan = $xpath->query('.//span[@class="score"]', $sub)->item(0);
            if ($scoreSpan) {
                $score = (int)filter_var($scoreSpan->nodeValue, FILTER_SANITIZE_NUMBER_INT);
            }

            // Extract username from the subtext
            $username = '';
            $userAnchor = $xpath->query('.//a[@class="hnuser"]', $sub)->item(0);
            if ($userAnchor) {
                $username = html_entity_decode($userAnchor->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            // age -> get title attr for absolute time if available
            $ageSpan = $xpath->query('.//span[@class="age"]', $sub)->item(0);
            $timestamp = 0;
            if ($ageSpan) {
                $titleAttr = $ageSpan->getAttribute('title');
                if ($titleAttr) {
                    // HN title format: "2025-06-19T10:22:06 1750328526" (ISO date + space + unix timestamp)
                    if (preg_match('/(\d{10,})$/', $titleAttr, $matches)) {
                        // Extract the Unix timestamp from the end
                        $timestamp = (int)$matches[1];
                    } else {
                        // Fallback: try to parse the ISO date part
                        $isoDate = preg_split('/\s+/', $titleAttr)[0];
                        $timestamp = strtotime($isoDate);
                    }
                }
            }

            // comments link - improved parsing
            $commentsUrl = '';
            $commentsCount = 0;
            
            // Get all links with item?id= in the subtext
            $allItemLinks = $xpath->query('.//a[contains(@href, "item?id=")]', $sub);
            foreach ($allItemLinks as $link) {
                $linkText = $link->nodeValue;
                // Debug: show what we're finding
                //echo "DEBUG: Found link text: '{$linkText}'\n";
                
                // Check if this link contains comment-related text
                if (preg_match('/comment|discuss/i', $linkText)) {
                    $commentsHref = $link->getAttribute('href');
                    $commentsUrl = 'https://news.ycombinator.com/' . ltrim($commentsHref, '/');
                    
                    // Extract number from comment text (handle &nbsp; as space)
                    $cleanText = str_replace(['&nbsp;', chr(160)], ' ', $linkText);
                    if (preg_match('/(\d+)\s*comment/i', $cleanText, $m)) {
                        $commentsCount = (int)$m[1];
                        break;
                    } elseif (preg_match('/(\d+)\s*discuss/i', $cleanText, $m)) {
                        $commentsCount = (int)$m[1];
                        break;
                    } elseif (preg_match('/(\d+)/', $cleanText, $m)) {
                        // Only use this if the text definitely looks like comments
                        if (stripos($cleanText, 'comment') !== false || stripos($cleanText, 'discuss') !== false) {
                            $commentsCount = (int)$m[1];
                            break;
                        }
                    }
                }
            }
            
            // If we didn't find a comments link, use the generic item link
            if (!$commentsUrl && $allItemLinks->length > 0) {
                $firstLink = $allItemLinks->item(0);
                $commentsHref = $firstLink->getAttribute('href');
                $commentsUrl = 'https://news.ycombinator.com/' . ltrim($commentsHref, '/');
            }

            $stories[] = [
                'id' => $id,
                'title' => $title,
                'url' => $url,
                'domain' => $domain,
                'comments_url' => $commentsUrl,
                'descendants' => $commentsCount, // Use 'descendants' to match HN API format
                'score' => $score,
                'time' => $timestamp, // Use 'time' to match HN API format
                'by' => $username, // Extract username from web scraping
            ];
        }

        // "More" link
        $nextPath = null;
        $more = $xpath->query('//a[@class="morelink"]')->item(0);
        if ($more) {
            $nextPath = $more->getAttribute('href');
        }

        return [$stories, $nextPath];
    }

    private function fetchStoryDetails(int $storyId): ?array
    {
        logMessage("Fetching story details: {$storyId}");

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

            // Determine file extension (default .jpg)
            $ext = '.jpg';
            if (str_contains($contentType, 'png')) {
                $ext = '.png';
            } elseif (str_contains($contentType, 'gif')) {
                $ext = '.gif';
            } elseif (str_contains($contentType, 'webp')) {
                $ext = '.webp';
            }

            $filepath = $this->imgDir . "/{$storyId}{$ext}";

            // Stream download directly to disk to avoid high memory usage
            // Use specialized image downloader with higher size limits
            $success = downloadUrlToFile($imageUrl, $filepath, 10);
            if ($success) {
                echo "Successfully downloaded image for story {$storyId}\n";
            } else {
                echo "Failed to download image for story {$storyId}\n";
            }

        } catch (\Exception $e) {
            echo "Error downloading image for story {$storyId}: " . $e->getMessage() . "\n";
        }
    }

    private function extractImageFromPage(string $url): ?string
    {
        try {
            $html = fetchUrl($url);
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
            $dom = createUtf8DOMDocument($html);
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

            $html = fetchUrl($url);
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

    public static function extractDomain(string $url): string
    {
        if (empty($url)) {
            return "";
        }

        $parsedUrl = parse_url($url);
        return $parsedUrl['host'] ?? "";
    }


    /**
     * Static version of extractImageUrl for use in static contexts
     */
    public static function extractImageUrlStatic(string $url): ?string
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
                    return null;
                }
            }

            $html = fetchUrl($url);
            if ($html === false) {
                return null;
            }

            return self::extractImageFromPageWithHtmlStatic($url, $html);

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Static version of extractImageFromPageWithHtml
     */
    public static function extractImageFromPageWithHtmlStatic(string $url, string $html): ?string
    {
        try {
            $dom = createUtf8DOMDocument($html);
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
                $skip = false;
                foreach ($skipTerms as $term) {
                    if (str_contains(strtolower($src), $term)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) continue;

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
            return null;
        }
    }

    /**
     * Static version of saveStoryImage
     */
    public static function saveStoryImageStatic(int $storyId, ?string $imageUrl, string $imgDir): void
    {
        // Check if image already exists with any extension
        $extensions = ['.jpg', '.png', '.gif', '.webp'];
        foreach ($extensions as $ext) {
            $filepath = $imgDir . "/{$storyId}{$ext}";
            if (file_exists($filepath)) {
                return; // Silently skip if image already exists
            }
        }

        // If no URL or invalid URL, don't save anything
        if (!$imageUrl || !str_starts_with($imageUrl, 'http')) {
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
                $imageUrl = self::extractImageUrlStatic($imageUrl);
                if (!$imageUrl) {
                    return;
                }
            }

            // Determine file extension (default .jpg)
            $ext = '.jpg';
            if (str_contains($contentType, 'png')) {
                $ext = '.png';
            } elseif (str_contains($contentType, 'gif')) {
                $ext = '.gif';
            } elseif (str_contains($contentType, 'webp')) {
                $ext = '.webp';
            }

            $filepath = $imgDir . "/{$storyId}{$ext}";

            // Stream download directly to disk to avoid high memory usage
            // Use specialized image downloader with higher size limits
            $success = downloadUrlToFile($imageUrl, $filepath, 10);
            if ($success) {
                logMessage("Successfully downloaded image for story {$storyId}");
            }

        } catch (\Exception $e) {
            logMessage("Error downloading image for story {$storyId}: " . $e->getMessage());
        }
    }

    private function storeStory(array $story, int $storyTypeId, int $ranking): void
    {
        self::storeStoryWithDependencies($story, $storyTypeId, $ranking, $this->pdo, $this->imgDir);
    }

    /**
     * Public static method to store a story with all dependencies passed as parameters
     */
    public static function storeStoryWithDependencies(array $story, int $storyTypeId, int $ranking, \PDO $pdo, string $imgDir): void
    {
        if (empty($story)) {
            return;
        }

        // Extract fields with defaults for missing values
        $storyId = $story['id'] ?? 0;
        $title = $story['title'] ?? '';
        $url = $story['url'] ?? '';
        $domain = self::extractDomain($url);
        $commentsUrl = $story['comments_url'] ?? "https://news.ycombinator.com/item?id={$storyId}";
        $commentsCount = $story['descendants'] ?? 0;
        $score = $story['score'] ?? 0;
        $user = $story['by'] ?? '';
        $timestamp = $story['time'] ?? 0;
        $fetchedAt = time();

        // Check if image already exists for this story
        $imageExists = false;
        $extensions = ['.jpg', '.png', '.gif', '.webp'];
        foreach ($extensions as $ext) {
            if (file_exists($imgDir . "/{$storyId}{$ext}")) {
                $imageExists = true;
                break;
            }
        }

        // Only try to fetch image if it doesn't exist
        if (!$imageExists && !empty($url)) {
            try {
                $imageUrl = self::extractImageUrlStatic($url);
                self::saveStoryImageStatic($storyId, $imageUrl, $imgDir);
            } catch (\Exception $e) {
                logMessage("Error processing image for story {$storyId}: " . $e->getMessage());
            }
        }

        // Check if story already exists in the database
        $stmt = $pdo->prepare('SELECT id FROM stories WHERE id = ?');
        $stmt->execute([$storyId]);
        $existing = $stmt->fetch();
        if ($existing) {
            // Update existing story
            $stmt = $pdo->prepare('
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
            $stmt = $pdo->prepare('
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
        $stmt = $pdo->prepare('SELECT id FROM ranks WHERE id = ? AND type = ?');
        $stmt->execute([$storyId, $storyTypeId]);
        $existingRank = $stmt->fetch();

        if ($existingRank) {
            // Update existing rank
            $stmt = $pdo->prepare('
                UPDATE ranks
                SET rank = ?
                WHERE id = ? AND type = ?
            ');
            $stmt->execute([$ranking, $storyId, $storyTypeId]);
        } else {
            // Insert new rank
            $stmt = $pdo->prepare('
                INSERT INTO ranks (id, type, rank)
                VALUES (?, ?, ?)
            ');
            $stmt->execute([$storyId, $storyTypeId, $ranking]);
        }
    }

    public function fetchAndStore(string $storyType, int $limit): int
    {
        logMessage("Fetching {$storyType} stories...");
        
        if ($storyType === 'active') {
            return $this->fetchAndStoreActiveStories($limit);
        } else {
            $limit = min($limit, 50);
            logMessage("Limiting {$storyType} stories to {$limit}");
        }

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
            if ($typeId > 0 && !empty($storyIds)) {
                // Build a placeholder list that always matches the parameter count.
                $placeholders = implode(',', array_fill(0, count($storyIds), '?'));

                $stmt = $this->pdo->prepare("\n                    UPDATE ranks \n                    SET rank = rank + ?\n                    WHERE type = ? AND id NOT IN ({$placeholders})\n                ");

                $params = array_merge([$newStoriesCount, $typeId], $storyIds);
                $stmt->execute($params);
            }

            $count = 0;
            foreach ($storyIds as $ranking => $storyId) {
                $story = $this->fetchStoryDetails($storyId);
                if ($story) {
                    // Skip "new" stories with less than 1 comment
                    if ($storyType === 'new' && ($story['descendants'] ?? 0) < 1) {
                        logMessage("Skipping story {$storyId} (new story with < 1 comment)");
                        continue;
                    }
                    
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

    /**
     * Fetch and store active stories using the specialized active scraping logic
     */
    private function fetchAndStoreActiveStories(int $limit): int
    {
        $pagePath = 'active';
        $processed = 0;
        $seenIds = [];
        $ranking = 0;
        $typeId = STORY_TYPES['active'];

        try {
            $this->pdo->beginTransaction();

            // bump existing ranks down so we can insert new ones at top
            $this->pdo->prepare('UPDATE ranks SET rank = rank + ? WHERE type = ?')
                ->execute([$limit, $typeId]);

            while ($pagePath && $processed < $limit) {
                $html = fetchUrl('https://news.ycombinator.com/' . $pagePath);
                if ($html === false) {
                    logMessage('Failed to fetch ' . $pagePath);
                    break;
                }

                [$stories, $nextPath] = $this->parseActivePage($html);
                foreach ($stories as $story) {
                    if ($processed >= $limit) break;
                    if (isset($seenIds[$story['id']])) continue; // skip duplicates across pages
                    $seenIds[$story['id']] = true;

                    logMessage("Fetching: {$story['id']}: {$story['title']}");
                    $this->storeStory($story, $typeId, $ranking);
                    $ranking++;
                    $processed++;

                    if ($processed % 20 === 0) {
                        logMessage("Processed {$processed} active stories");
                    }

                    usleep(100000); // 0.1s polite delay
                }

                $pagePath = $nextPath;
            }

            $this->pdo->commit();
            logMessage("Completed fetching {$processed} active stories");
            
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            logMessage('Error: ' . $e->getMessage());
            throw $e;
        }

        return $processed;
    }

    public function fetchAll(int $limit = 100): int
    {
        $total = 0;
        $allTypes = array_merge(array_keys(self::ENDPOINTS), ['active']);
        
        foreach ($allTypes as $storyType) {
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
     * Remove "active" rankings from stories beyond the first 200 positions.
     * This ensures only the top 200 most active stories maintain their "active" status.
     */
    public function trimActiveStoriesBeyond200(): int
    {
        logMessage("Trimming active stories beyond position 200...");
        
        try {
            $typeId = STORY_TYPES['active'];
            
            // First, get the top 200 active stories by rank (properly ordered)
            $stmt = $this->pdo->prepare('
                SELECT id 
                FROM ranks 
                WHERE type = ? 
                ORDER BY rank ASC 
                LIMIT 200
            ');
            $stmt->execute([$typeId]);
            $top200Ids = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
            
            if (empty($top200Ids)) {
                logMessage("No active stories found to preserve");
                return 0;
            }
            
            // Build placeholders for the NOT IN clause
            $placeholders = implode(',', array_fill(0, count($top200Ids), '?'));
            
            // Delete all active rankings that are NOT in the top 200 list
            $stmt = $this->pdo->prepare("
                DELETE FROM ranks 
                WHERE type = ? AND id NOT IN ({$placeholders})
            ");
            
            // Execute with type ID first, then the story IDs
            $params = array_merge([$typeId], $top200Ids);
            $stmt->execute($params);
            
            $deletedCount = $stmt->rowCount();
            logMessage("Preserved top 200 active stories, removed active ranking from {$deletedCount} stories");
            
            return $deletedCount;
            
        } catch (\Exception $e) {
            logMessage("Error trimming active stories: " . $e->getMessage());
            throw $e;
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
            
            $files = scandir($this->imgDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                // Check if file has an allowed image extension
                $hasValidExtension = false;
                foreach (IMAGE_EXTENSIONS as $ext) {
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

// Command line interface - only run when this file is executed directly
if (php_sapi_name() === 'cli' && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    // Define valid options
    $options = getopt('h', ['type:', 'limit:', 'db:', 'id:', 'trim-active', 'help']);
    
    if (count($options) !== count($argv) - 1) {
        $invalidCount = (count($argv) - 1) - count($options);
        logMessage("{$invalidCount} invalid options found\n");
        exit(1);
    }
    
    if (isset($options['help'])) {
        echo "Usage: php fetcher.php [options]\n";
        echo "Options:\n";
        echo "  --type=TYPE     Type of stories to fetch: top, new, best, active, all (default: all)\n";
        echo "  --limit=LIMIT   Maximum number of stories to fetch per type (default: 100)\n";
        echo "  --db=PATH       Path to SQLite database (default: stories.db)\n";
        echo "  --id=STORY-ID   Fetch a single story by ID\n";
        echo "  --trim-active   Remove active rankings from stories beyond position 200\n";
        echo "  --help, -h      Show this help message\n";
        exit(0);
    }
    
    $type = $options['type'] ?? 'all';
    $limit = (int)($options['limit'] ?? MAX_FETCH);
    $db = $options['db'] ?? DB_PATH;
    $storyId = isset($options['id']) ? (int)$options['id'] : null;
    
    $validTypes = ['top', 'new', 'best', 'active', 'all'];
    if (!in_array($type, $validTypes)) {
        echo "Error: Invalid type '{$type}'. Valid types are: " . implode(', ', $validTypes) . "\n";
        exit(1);
    }
    
    $fetcher = new HackerNewsFetcher($db, "static/img/stories");

    logMessage("Fetcher Version: " . FETCHER_VERSION);
    
    if (isset($options['trim-active'])) {
        // Run active story trimming only
        $deletedCount = $fetcher->trimActiveStoriesBeyond200();
        logMessage("Active story trimming completed. Removed {$deletedCount} rankings.");
        exit(0);
    } elseif ($storyId) {
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