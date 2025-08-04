<?php

const BATCH_SIZE = 25;
const STORY_CONTENT_NOT_DETECTED = "Story content not detected";
const ERROR_DOWNLOADING_CONTENT = "Error downloading content";

// Security: Only allow execution from command line
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    die('404 Not Found');
}

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../_env', '.founderfodder.env');
$dotenv->load();

// Prompts for AI summarization
const STORY_PROMPT = "Create summary of the following page for main topics and insights. Do not rephrase or mention the title in the summary. Limit to 250 words. Return just the summary:\n------\n{content}";
const COMMENTS_PROMPT = "Create summary of the following discussion for main arguments, opinions, criticisms. Do not rephrase or mention the title. Limit to 600 words. Return just summary:\n------\n{content}";

// File naming constants
const STORY_SUMMARY_FILE = "story-summary.md";
const COMMENTS_SUMMARY_FILE = "comments-summary.md";

// Directory constants
const OUTPUT_DIR = __DIR__ . "/static/content";
const ORIGINALS_DIR = __DIR__ . "/_originals";
// Create these directories if they don't exist
if (!is_dir(OUTPUT_DIR)) {
    mkdir(OUTPUT_DIR, 0755, true);
}
if (!is_dir(ORIGINALS_DIR)) {
    mkdir(ORIGINALS_DIR, 0755, true);
}

// Hacker News API endpoints
const HN_BASE_URL = "https://hacker-news.firebaseio.com/v0";
const HN_ITEM_URL = HN_BASE_URL . "/item";

class StorySummarizer {
    private $outputDir;
    private $originalsDir;
    private $client;
    private $model;
    private $lockHandle;
    private $dryRun;
    private ?\PDO $pdo = null;
    
    public function __construct($dbPath = DB_PATH, $outputDir = OUTPUT_DIR, $originalsDir = ORIGINALS_DIR, $dryRun = false) {
        $this->lockHandle = acquireLock(SUMMARIZER_LOCK_FILE_NAME);

        setup_signal_handlers();
        register_shutdown_function([$this, 'releaseResources']);

        exit_if_other_process_running(FETCHER_LOCK_FILE_NAME);

        // write PID to lock file
        ftruncate($this->lockHandle, 0);
        fwrite($this->lockHandle, getmypid() . "\n" . date('Y-m-d H:i:s') . "\n");
        fflush($this->lockHandle);

        // is the other process running?
        $this->outputDir = $outputDir;
        $this->originalsDir = $originalsDir;
        $this->dryRun = $dryRun;
        
        // Initialize database connection
        $this->pdo = get_db_connection($dbPath);
        
        if ($dryRun) {
            $this->client = null;
            $this->model = null;
        } else {
            $apiKey = $_SERVER['GEMINI_API_KEY'] ?? null;
            if (!$apiKey) {
                throw new Exception('GEMINI_API_KEY environment variable not found');
            }
            
            // Initialize Gemini AI
            $this->client = Gemini::client($apiKey);
            $this->model = $this->client->generativeModel(model: 'gemini-2.5-flash-lite-preview-06-17');
        }
        
        // Create directories if they don't exist
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
        if (!is_dir($this->originalsDir)) {
            mkdir($this->originalsDir, 0755, true);
        }
    }
    
    public function releaseResources(): void
    {
        releaseLock($this->lockHandle, SUMMARIZER_LOCK_FILE_NAME);
        $this->lockHandle = null;
    }

    /**
     * Decode HTML entities in text to their corresponding characters
     */
    private function decodeHtmlEntities($text) {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Fetch and extract main content from a story URL
     */
    private function fetchStoryContent($url) {
        if (!$url || strpos($url, "item?id=") !== false) {
            return "No URL provided or URL is a HN discussion.";
        }
        
        try {
            $content = fetchUrl($url);
            if ($content === false) {
                return "Failed to fetch content from URL.";
            }
            
            // Parse the content with DOMDocument with proper UTF-8 handling
            $dom = createUtf8DOMDocument($content);
            
            // Remove script and style elements
            $scripts = $dom->getElementsByTagName('script');
            for ($i = $scripts->length - 1; $i >= 0; $i--) {
                $scripts->item($i)->parentNode->removeChild($scripts->item($i));
            }
            $styles = $dom->getElementsByTagName('style');
            for ($i = $styles->length - 1; $i >= 0; $i--) {
                $styles->item($i)->parentNode->removeChild($styles->item($i));
            }
            
            // Try to find the main content
            $mainContent = "";
            $article = $dom->getElementsByTagName('article')->item(0) ?? 
                      $dom->getElementsByTagName('main')->item(0) ?? 
                      $dom->getElementById('content') ?? 
                      $dom->getElementsByTagName('body')->item(0);
            
            if ($article) {
                $mainContent = trim($article->textContent);
            }
            
            // Clean up the content (remove excessive newlines, etc.)
            $lines = explode("\n", $mainContent);
            $cleanLines = array_filter(array_map('trim', $lines));
            $mainContent = implode("\n", $cleanLines);
            
            // Decode HTML entities
            $mainContent = $this->decodeHtmlEntities($mainContent);
            
            if (!$mainContent) {
                logMessage(STORY_CONTENT_NOT_DETECTED . " in story HTML\n");
                return STORY_CONTENT_NOT_DETECTED;
            }
            
            return $mainContent;
        } catch (Exception $e) {
            return ERROR_DOWNLOADING_CONTENT . ": " . $e->getMessage();
        }
    }
    
    /**
     * Fetch a single comment by ID from the Hacker News API
     */
    private function fetchComment($commentId) {
        try {
            $url = HN_ITEM_URL . "/{$commentId}.json";
            $content = fetchUrl($url);
            if ($content === false) {
                return null;
            }
            
            $comment = json_decode($content, true);
            if (!$comment || isset($comment['dead']) || isset($comment['deleted'])) {
                return null;
            }
            
            // Decode HTML entities in the comment text
            if (isset($comment['text'])) {
                $comment['text'] = $this->decodeHtmlEntities($comment['text']);
            }
            
            return $comment;
        } catch (Exception $e) {
            echo "Error fetching comment {$commentId}: " . $e->getMessage() . "\n";
            return null;
        }
    }
    
    /**
     * Fetch multiple comments concurrently using curl_multi
     */
    private function fetchCommentsBatch($commentIds, $batchSize = BATCH_SIZE) {
        if (empty($commentIds)) {
            return [];
        }
        
        // Process comments in smaller batches for better reliability
        $batches = array_chunk($commentIds, $batchSize);
        $allComments = [];
        
        foreach ($batches as $batchIndex => $batch) {
            dispatch_signal();
            
            echo "    Processing batch " . ($batchIndex + 1) . "/" . count($batches) . " (" . count($batch) . " comments)...\n";
            
            // Use curl_multi for concurrent requests
            $multiHandle = curl_multi_init();
            $curlHandles = [];
            
            // Set up all curl handles
            foreach ($batch as $commentId) {
                $url = HN_ITEM_URL . "/{$commentId}.json";
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; FounderFodder/1.0)');
                
                curl_multi_add_handle($multiHandle, $ch);
                $curlHandles[$commentId] = $ch;
            }
            
            // Execute all requests concurrently
            $running = null;
            do {
                curl_multi_exec($multiHandle, $running);
                curl_multi_select($multiHandle);
                
                dispatch_signal();
            } while ($running > 0);
            
            // Collect results
            $batchComments = [];
            foreach ($curlHandles as $commentId => $ch) {
                $content = curl_multi_getcontent($ch);
                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);
                
                if ($content !== false) {
                    $comment = json_decode($content, true);
                    if ($comment && !isset($comment['dead']) && !isset($comment['deleted']) && isset($comment['text'])) {
                        $comment['text'] = $this->decodeHtmlEntities($comment['text']);
                        $batchComments[$commentId] = $comment;
                    }
                }
            }
            
            curl_multi_close($multiHandle);
            $allComments = $allComments + $batchComments;
            echo "    Batch " . ($batchIndex + 1) . " completed: " . count($batchComments) . " valid comments\n";
        }
        
        return $allComments;
    }
    
    /**
     * Collect comment IDs breadth-first up to the limit
     */
    private function collectCommentIds($commentIds) {
        $allIds = [];
        $queue = $commentIds; // Start with top-level comment IDs
        
        echo "  Collecting comment IDs breadth-first (max: " . MAX_COMMENTS_TO_FETCH . ")...\n";
        
        // Keep collecting until queue is empty or we have enough IDs
        while (!empty($queue) && count($allIds) < MAX_COMMENTS_TO_FETCH) {
            $remaining = MAX_COMMENTS_TO_FETCH - count($allIds);
            $currentBatch = array_splice($queue, 0, min(BATCH_SIZE, $remaining)); // Process in batches of 20
            $allIds = array_merge($allIds, $currentBatch);
            
            // Fetch this batch to get reply IDs (only if we still need more)
            if (count($allIds) < MAX_COMMENTS_TO_FETCH) {
                $batchComments = $this->fetchCommentsBatch($currentBatch);
                
                // Add reply IDs to the queue for further processing
                foreach ($currentBatch as $commentId) {
                    if (isset($batchComments[$commentId]['kids'])) {
                        $replyIds = $batchComments[$commentId]['kids'];
                        $queue = array_merge($queue, $replyIds);
                    }
                }
            }
            
            echo "    Collected " . count($allIds) . " comment IDs so far...\n";
        }
        
        if (count($allIds) >= MAX_COMMENTS_TO_FETCH) {
            echo "  Reached collection limit of " . MAX_COMMENTS_TO_FETCH . " comment IDs\n";
        }
        
        echo "  Collected " . count($allIds) . " comment IDs total\n";
        
        return $allIds;
    }

    /**
     * Build comment tree from flat comment data
     */
    private function buildCommentTree($topLevelIds, $allComments) {
        $comments = [];
        
        foreach ($topLevelIds as $commentId) {
            if (isset($allComments[$commentId])) {
                $comment = $this->buildCommentWithReplies($commentId, $allComments);
                if ($comment) {
                    $comments[] = $comment;
                }
            }
        }
        
        return $comments;
    }
    
    /**
     * Recursively build a comment with its replies
     */
    private function buildCommentWithReplies($commentId, $allComments) {
        if (!isset($allComments[$commentId])) {
            return null;
        }
        
        $comment = $allComments[$commentId];
        $commentText = $comment['text'] ?? '';
        
        if (!$commentText) {
            return null;
        }
        
        $commentObj = [
            'id' => $commentId,
            'text' => $commentText,
            'by' => $comment['by'] ?? '',
            'time' => $comment['time'] ?? 0,
            'replies' => []
        ];
        
        // Add replies if they exist in our collected comments
        if (isset($comment['kids'])) {
            foreach ($comment['kids'] as $replyId) {
                $reply = $this->buildCommentWithReplies($replyId, $allComments);
                if ($reply) {
                    $commentObj['replies'][] = $reply;
                }
            }
        }
        
        return $commentObj;
    }

    /**
     * Fetch comments using efficient breadth-first approach
     */
    private function fetchCommentsEfficient($topLevelIds) {
        // Step 1: Collect all comment IDs breadth-first (no limit here)
        $allCommentIds = $this->collectCommentIds($topLevelIds);
        
        echo "  Collected " . count($allCommentIds) . " comment IDs total\n";
        echo "  Batch fetching all comments...\n";
        
        // Step 2: Fetch comments in batches and limit by valid comments
        $allComments = [];
        $batches = array_chunk($allCommentIds, 25);
        
        foreach ($batches as $batchIndex => $batch) {
            if (count($allComments) >= MAX_COMMENTS_TO_FETCH) {
                echo "  Reached limit of " . MAX_COMMENTS_TO_FETCH . " valid comments\n";
                break;
            }
            
            $batchComments = $this->fetchCommentsBatch($batch);
            
            // Only add comments up to the limit
            foreach ($batchComments as $commentId => $comment) {
                if (count($allComments) < MAX_COMMENTS_TO_FETCH) {
                    $allComments[$commentId] = $comment;
                } else {
                    break;
                }
            }
            
            echo "  Fetched " . count($allComments) . " valid comments so far...\n";
            
            if (count($allComments) >= MAX_COMMENTS_TO_FETCH) {
                echo "  Reached limit of " . MAX_COMMENTS_TO_FETCH . " valid comments\n";
                break;
            }
        }
        
        echo "  Successfully fetched " . count($allComments) . " valid comments\n";
        echo "  Building comment tree...\n";
        
        // Step 3: Build the tree structure
        $comments = $this->buildCommentTree($topLevelIds, $allComments);
        
        return $comments;
    }
    
    /**
     * Fetch and extract comments from a HN discussion URL using the API
     */
    private function fetchComments($commentsUrl) {
        // Extract the story ID from the comments URL
        // Format is typically: https://news.ycombinator.com/item?id=123456
        $parsedUrl = parse_url($commentsUrl);
        parse_str($parsedUrl['query'] ?? '', $queryParams);
        $storyId = $queryParams['id'] ?? null;
        
        if (!$storyId) {
            return "Could not extract story ID from comments URL.";
        }
        
        // Fetch the story details to get the top-level comment IDs
        $url = HN_ITEM_URL . "/{$storyId}.json";
        $content = fetchUrl($url);
        if ($content === false) {
            return "Failed to fetch story details.";
        }
        
        $story = json_decode($content, true);
        if (!$story || !isset($story['kids'])) {
            return "";
        }
        
        // Fetch all comments using efficient breadth-first approach
        echo "  Fetching comments for story {$storyId} ...\n";
        $comments = $this->fetchCommentsEfficient($story['kids']);
        
        // Format comments for summarization
        $combinedComments = "";
        foreach ($comments as $i => $comment) {
            $combinedComments .= $this->formatComment($comment, $i + 1);
        }
        
        if (!$combinedComments) {
            return "";
        }
        
        // Ensure all HTML entities are decoded
        $combinedComments = $this->decodeHtmlEntities($combinedComments);
        
        return $combinedComments;
    }
    
    /**
     * Format a comment and its replies for summarization
     */
    private function formatComment($comment, $index, $depth = 0) {
        $indent = str_repeat("  ", $depth);
        $result = "{$indent}Comment {$index}:\n{$indent}{$comment['text']}\n\n";
        
        // Add replies
        foreach ($comment['replies'] as $i => $reply) {
            $result .= $this->formatComment($reply, "{$index}." . ($i + 1), $depth + 1);
        }
        
        return $result;
    }
    
    /**
     * Remove unwanted lines from AI-generated content
     */
    private function cleanupContent($body) {
        if (!$body) {
            return $body;
        }
        
        // Split the text into lines
        $lines = explode("\n", $body);
        $cleanedLines = [];
        
        foreach ($lines as $line) {
            $lowerLine = strtolower(trim($line));
            
            // These tend to be at the beginning
            if (strpos($lowerLine, 'summary ') === 0 || 
                strpos($lowerLine, "here's ") === 0 || 
                strpos($lowerLine, 'here is ') === 0 ||
                strpos($lowerLine, 'okay, ') === 0) {
                continue;
            }
            
            // These seem to arrive at the end
            if (strpos($lowerLine, "you like me to") !== false || 
                strpos($lowerLine, "you want me to") !== false ||
                $lowerLine === "---" ||
                strpos($lowerLine, "let me know if ") === 0) {
                break;
            }
            
            $cleanedLines[] = $line;
        }
        
        // Join the lines back together
        return implode("\n", $cleanedLines);
    }
    
    /**
     * Clean up story artifacts older than CLEANUP_RETENTION_DAYS
     * Removes: images, story/comment summaries, and original files
     * Keeps: database records (stories and rankings)
     */
    public function cleanupOldArtifacts(): void
    {
        $cutoffTime = time() - (CLEANUP_RETENTION_DAYS * 24 * 60 * 60);
        logMessage("Starting cleanup of artifacts older than " . date('Y-m-d H:i:s', $cutoffTime) . " (" . CLEANUP_RETENTION_DAYS . " days)");
        
        try {
            // Get stories older than retention period
            $stmt = $this->pdo->prepare("
                SELECT id, fetched_at 
                FROM stories 
                WHERE fetched_at < ?
                ORDER BY fetched_at ASC
            ");
            $stmt->execute([$cutoffTime]);
            $oldStories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($oldStories)) {
                logMessage("No stories older than " . CLEANUP_RETENTION_DAYS . " days found");
                return;
            }
            
            logMessage("Found " . count($oldStories) . " stories older than " . CLEANUP_RETENTION_DAYS . " days");
            
            $deletedCounts = [
                'images' => 0,
                'story_summaries' => 0,
                'comment_summaries' => 0,
                'story_originals' => 0,
                'comment_originals' => 0
            ];
            
            foreach ($oldStories as $story) {
                $storyId = $story['id'];
                $fetchedDate = date('Y-m-d', $story['fetched_at']);
                
                // Delete story images (all supported formats)
                foreach (IMAGE_EXTENSIONS as $ext) {
                    $imagePath = "static/img/stories/{$storyId}{$ext}";
                    if (file_exists($imagePath)) {
                        if (unlink($imagePath)) {
                            $deletedCounts['images']++;
                            logMessage("  Deleted image: {$imagePath} (fetched: {$fetchedDate})");
                        }
                    }
                }
                
                // Delete story summary
                $storySummaryPath = "{$this->outputDir}/{$storyId}-story-summary.md";
                if (file_exists($storySummaryPath)) {
                    if (unlink($storySummaryPath)) {
                        $deletedCounts['story_summaries']++;
                        logMessage("  Deleted story summary: {$storyId}-story-summary.md (fetched: {$fetchedDate})");
                    }
                }
                
                // Delete comment summary
                $commentSummaryPath = "{$this->outputDir}/{$storyId}-comments-summary.md";
                if (file_exists($commentSummaryPath)) {
                    if (unlink($commentSummaryPath)) {
                        $deletedCounts['comment_summaries']++;
                        logMessage("  Deleted comment summary: {$storyId}-comments-summary.md (fetched: {$fetchedDate})");
                    }
                }
                
                // Delete original story file
                $storyOriginalPath = "{$this->originalsDir}/{$storyId}-story.txt";
                if (file_exists($storyOriginalPath)) {
                    if (unlink($storyOriginalPath)) {
                        $deletedCounts['story_originals']++;
                        logMessage("  Deleted story original: {$storyId}-story.txt (fetched: {$fetchedDate})");
                    }
                }
                
                // Delete original comments file
                $commentsOriginalPath = "{$this->originalsDir}/{$storyId}-comments.txt";
                if (file_exists($commentsOriginalPath)) {
                    if (unlink($commentsOriginalPath)) {
                        $deletedCounts['comment_originals']++;
                        logMessage("  Deleted comments original: {$storyId}-comments.txt (fetched: {$fetchedDate})");
                    }
                }
            }
            
            // Log summary
            $totalDeleted = array_sum($deletedCounts);
            logMessage("Cleanup completed:");
            logMessage("  Images: {$deletedCounts['images']}");
            logMessage("  Story summaries: {$deletedCounts['story_summaries']}");
            logMessage("  Comment summaries: {$deletedCounts['comment_summaries']}");
            logMessage("  Story originals: {$deletedCounts['story_originals']}");
            logMessage("  Comment originals: {$deletedCounts['comment_originals']}");
            logMessage("  Total files deleted: {$totalDeleted}");
            
        } catch (Exception $e) {
            logMessage("Error during cleanup: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function summarize($content, $promptType) {
        try {
            // Define prompts based on content type
            if ($promptType === "story") {
                $prompt = str_replace('{content}', $content, STORY_PROMPT);
            } else { // comments
                $prompt = str_replace('{content}', $content, COMMENTS_PROMPT);
            }
            
            if ($this->dryRun) {
                $contentLength = strlen($content);
                $promptLength = strlen($prompt);
                echo "    [DRY-RUN] Would summarize {$promptType} content ({$contentLength} chars, prompt: {$promptLength} chars)\n";
                return "DRY-RUN MODE: This would be a {$promptType} summary generated from {$contentLength} characters of content.";
            }
            
            $result = $this->model->generateContent($prompt);
            return $result->text();

        } catch (ValueError $e) {
            // Check if this is the specific quota/candidate issue
            if (strpos($e->getMessage(), 'quick accessor only works for a single candidate') !== false) {
                echo "ERROR: Gemini API quota exhausted or no candidates returned.\n";
                echo "Message: " . $e->getMessage() . "\n";
                echo "This indicates API quota limits have been reached.\n";
                echo "Exiting gracefully to avoid further quota usage.\n";
                
                // Release resources and exit gracefully
                $this->releaseResources();
                exit(1);
            }
            
            // Re-throw if it's a different ValueError
            throw $e;
            
        } catch (Exception $e) {
            echo "ERROR during summarization: " . $e->getMessage() . "\n";
            return null;
        }
    }

    public function processStory($story, $downloadOnly = false) {
        $storySummaryFile = $this->outputDir . "/{$story['id']}-" . STORY_SUMMARY_FILE;
        if (file_exists($storySummaryFile)) {
            return;
        }

        logMessage("Processing content for story {$story['id']}");
        $storyContent = "";
        $storyFile = $this->originalsDir . "/{$story['id']}-story.txt";
        if (!file_exists($storyFile) && $story['url']) {
            echo "Fetching story ...\n";
            $storyContent = $this->fetchStoryContent($story['url']);
            
            if ($storyContent === STORY_CONTENT_NOT_DETECTED) {
                file_put_contents($storySummaryFile, STORY_CONTENT_NOT_DETECTED);
                return;
            }
            
            if (str_contains($storyContent, ERROR_DOWNLOADING_CONTENT)) {
                logMessage($storyContent);
                return;
            }
            
            if (strlen($storyContent) < MIN_STORY_LENGTH_FOR_SUMMARY) {
                echo "STORY: " . $storyContent . "\n";
                logMessage("  Story is too short to summarize (length: " . strlen($storyContent) . ")\n");
                file_put_contents($storySummaryFile, "Story is too short to summarize");
                return;
            }

            file_put_contents($storyFile, $storyContent);
            logMessage("Saved story to {$storyFile}\n");
        }
        
        if ($downloadOnly) {
            echo "  Download-only mode: skipping summarization\n";
            return;
        }

        if (!$storyContent && file_exists($storyFile)) {
            $storyContent = file_get_contents($storyFile);
            echo "Loaded existing story content...\n";
        }

        if ($storyContent) {
            echo "  Summarizing story (length: " . strlen($storyContent) . ")...\n";
            $startTime = microtime(true);
            
            $storySummary = $this->summarize($storyContent, "story");
            if ($storySummary) {
                $storySummary = $this->cleanupContent($storySummary);
                
                if ($this->dryRun) {
                    echo "  [DRY-RUN] Would save story summary to {$storySummaryFile}\n";
                } else {
                    // Save the story summary
                    file_put_contents($storySummaryFile, $storySummary);
                    echo "  Saved story summary to {$storySummaryFile}\n";
                }
                
                // Calculate and display elapsed time
                $elapsedTime = microtime(true) - $startTime;
                if ($this->dryRun) {
                    echo "  [DRY-RUN] Story {$story['id']} processed in " . number_format($elapsedTime, 2) . " sec\n";
                } else {
                    echo "  Story {$story['id']} summarized in " . number_format($elapsedTime, 2) . " sec\n";
                }
            }
        }
        
        // Clean up memory
        unset($storyContent);
        gc_collect_cycles();
    }

    public function processComments($story, $storyTypes, $downloadOnly = false) {
        if ($storyTypes == STORY_TYPES["new"]) {
            logMessage("  Skipping comments processing for new story: {$story['id']}");
            return;
        }

        $commentsSummaryFile = $this->outputDir . "/{$story['id']}-" . COMMENTS_SUMMARY_FILE;
        if (file_exists($commentsSummaryFile)) {
            if ($story['fetched_at'] + 3600 * 72 < time()) {
                // if it's been more than 72 hours, keep the summary
                return;
            }
            $creationTime = filectime($commentsSummaryFile);
            if ($creationTime + 3600 * 6 > time()) {
                // if it's been less than 6 hours since creation, keep the summary
                return;
            }
            unlink($commentsSummaryFile);
        }

        logMessage("Processing comments for story {$story['id']}");
        $commentsContent = "";
        $commentsFile = $this->originalsDir . "/{$story['id']}-comments.txt";
        if (!file_exists($commentsFile) && $story['comments_url']) {
                    if ($story['comments_count'] > 10) {
            echo "Downloading comments ...\n";
            $commentsContent = $this->fetchComments($story['comments_url']);
            if ($commentsContent) {
                file_put_contents($commentsFile, $commentsContent);
                echo "Comments saved ...\n";
            } else {
                echo "Failed to fetch comments\n";
                return;
            }
            } else {
                echo "Not enough comments to summarize ({$story['comments_count']})\n";
                return;
            }
        }

        if ($downloadOnly) {
            echo "  Download-only mode: skipping summarization\n";
            return;
        }
        
        if (!$commentsContent && file_exists($commentsFile)) {
            $commentsContent = file_get_contents($commentsFile);
            echo "Loaded existing comments ...\n";
        }

        if ($commentsContent) {
            echo "  Summarizing comments (count: {$story['comments_count']})...\n";
            $startTime = microtime(true);
            
            $commentsSummary = $this->summarize($commentsContent, "comments");
            if ($commentsSummary) {
                $commentsSummary = $this->cleanupContent($commentsSummary);
                
                if ($this->dryRun) {
                    echo "  [DRY-RUN] Would save comments {$story['comments_count']} summary to {$commentsSummaryFile}\n";
                } else {
                    // Save the comments summary
                    file_put_contents($commentsSummaryFile, $commentsSummary);
                    echo "  Saved {$story['comments_count']} comments summary\n";
                }
                
                // Calculate and display elapsed time
                $elapsedTime = microtime(true) - $startTime;
                if ($this->dryRun) {
                    echo "  [DRY-RUN] Comments for story {$story['id']} processed in " . number_format($elapsedTime, 2) . " sec\n";
                } else {
                    echo "  Comments for story {$story['id']} summarized in " . number_format($elapsedTime, 2) . " sec\n";
                }
            }
        }
        
        // Clean up memory
        unset($commentsContent);
        gc_collect_cycles();
    }
    
    /**
     * Run the summarization process
     */
    public function run($storyType = "top", $limit = 10, $downloadOnly = false) {
        logMessage("Looking for {$storyType} stories to process (limit: {$limit})...");
        
        // Get type ID for the story type
        $typeId = STORY_TYPES[$storyType] ?? null;
        if (!$typeId) {
            logMessage("Invalid story type: {$storyType}");
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare('
                SELECT s.id, s.title, s.url, s.domain, s.comments_url, s.comments_count, s.fetched_at
                FROM stories s
                INNER JOIN ranks r ON s.id = r.id
                WHERE r.type = ?
                ORDER BY r.rank
            ');
            $stmt->execute([$typeId]);
            
            $storyCnt = 0;
            while ($story = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Monitor memory usage
                $memoryUsage = memory_get_usage(true) / 1024 / 1024; // MB
                
                // Check if memory usage is getting too high
                if ($memoryUsage > 60) {
                    echo "  WARNING: High memory usage: " . number_format($memoryUsage, 1) . " MB, running garbage collection\n";
                    gc_collect_cycles();
                }
                
                $this->processStory($story, $downloadOnly);
                $this->processComments($story, STORY_TYPES[$storyType], $downloadOnly);
                
                $storyCnt++;
                if ($limit > 0 && $storyCnt == $limit) {
                    break;
                }

                // Sleep to be nice to APIs and servers and allow system to recover
                sleep(2);
            }
            
            logMessage("Completed processing of {$storyCnt} stories.");
            
        } catch (PDOException $e) {
            logMessage("Database error: " . $e->getMessage());
        }
    }
    
    /**
     * Process a single story by ID (for debugging purposes)
     */
    public function processStoryById($storyId, $downloadOnly = false) {
        try {
            $stmt = $this->pdo->prepare('
                SELECT s.id, s.title, s.url, s.domain, s.comments_url, s.comments_count, s.fetched_at, 
					GROUP_CONCAT(r.type) AS types
                FROM stories s
                INNER JOIN ranks r ON s.id = r.id
                WHERE s.id = ?
				GROUP BY s.id
            ');
            $stmt->execute([$storyId]);
            
            $story = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($story) {                
                $this->processStory($story, $downloadOnly);
                $this->processComments($story, $story['types'], $downloadOnly);
            } else {
                logMessage("Story ID {$storyId} not found in database.");
            }
            
        } catch (PDOException $e) {
            logMessage("Database error: " . $e->getMessage());
        }
    }
}

$options = getopt('h', [
    'type:',
    'limit:',
    'db:',
    'output:',
    'download-only',
    'dry-run',
    'id:',
    'help'
]);

if (count($options) !== count($argv) - 1) {
    $invalidCount = (count($argv) - 1) - count($options);
    logMessage("{$invalidCount} invalid options found\n");
    exit(1);
}

if (isset($options['help']) || isset($options['h'])) {
    echo "Usage: php summarize.php [options]\n";
    echo "Options:\n";
    echo "  --type=STORY-TYPE  Type of stories to summarize (top, new, best, active, all) [default: all]\n";
    echo "  --limit=N          Maximum number of stories to process per type [default: 100]\n";
    echo "  --db=PATH          Path to SQLite database [default: " . DB_PATH . "]\n";
    echo "  --output=PATH      Output directory for content files [default: " . OUTPUT_DIR . "]\n";
    echo "  --download-only    Download story and comments content without summarizing\n";
    echo "  --dry-run          Process and show what would be summarized without calling Gemini API\n";
    echo "  --id=STORY-ID     Process a single story by ID\n";
    echo "  --help, -h         Show this help message\n";
    exit(0);
}

$storyType = $options['type'] ?? 'all';
$limit = isset($options['limit']) ? (int)$options['limit'] : MAX_SUMMARIZE;
$dbPath = $options['db'] ?? DB_PATH;
$outputDir = $options['output'] ?? OUTPUT_DIR;
$downloadOnly = isset($options['download-only']);
$dryRun = isset($options['dry-run']);
$storyId = isset($options['id']) ? (int)$options['id'] : null;

logMessage("Summarizer Version: " . SUMMARIZER_VERSION);

print("storyType: {$storyType}\n");
print("limit: {$limit}\n");
if (isset($options['db'])) {
    print("dbPath: {$dbPath}\n");
}
if (isset($options['output'])) {
    print("outputDir: {$outputDir}\n");
}
if (isset($options['download-only'])) {
    print("downloadOnly\n");
}
if (isset($options['dry-run'])) {
    print("dryRun: {$dryRun}\n");
}
if (isset($options['story-id'])) {
    print("storyId: {$storyId}\n");
}

try {
    $summarizer = new StorySummarizer($dbPath, $outputDir, ORIGINALS_DIR, $dryRun);

    if ($storyId) {
        $summarizer->processStoryById($storyId, $downloadOnly);
    } else {
        if ($storyType === 'all') {
            // Process all story types with the limit applying to each type
            $types = array_keys(STORY_TYPES);
            
            foreach ($types as $type) {
                logMessage("=== Processing {$type} stories ===");
                $summarizer->run($type, $limit, $downloadOnly);
            }
            logMessage("=== Completed processing all story types ===");
        } else {
            // Process single story type
            $summarizer->run($storyType, $limit, $downloadOnly);
        }
    }

    // Clean up artifacts older than the retention period
    logMessage("=== Starting cleanup of old artifacts ===");
    $summarizer->cleanupOldArtifacts();
    logMessage("=== Cleanup completed ===");
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

