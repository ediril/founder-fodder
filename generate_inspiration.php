<?php
/**
 * Inspiration Story Generator
 *
 * Parses .yml files from inspire/ folder and generates carousel images with text overlays.
 *
 * Usage: php generate_inspiration.php <story-name>
 * Example: php generate_inspiration.php steve-jobs
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

// Configuration
define('INSPIRATION_DIR', 'inspire');
define('ORIGINAL_IMAGES_DIR', '_inspire_imgs');

// Control whether to show logs (enabled for CLI, disabled for web)
$GLOBALS['ENABLE_LOGS'] = php_sapi_name() === 'cli';

/**
 * Log a message (only if logging is enabled)
 */
function log_message(string $message): void {
    if ($GLOBALS['ENABLE_LOGS']) {
        echo $message;
    }
}

/**
 * Parse inspiration story YAML file
 *
 * @param string $file_path Path to .yml file
 * @return array Array with 'pages', 'search_phrases', and optional 'references' keys
 */
function parse_inspiration_yml(string $file_path): array {
    if (!file_exists($file_path)) {
        throw new Exception("File not found: $file_path");
    }

    // Read raw YAML content and preprocess to handle LaTeX formatting
    $yaml_content = file_get_contents($file_path);

    // Replace LaTeX escape sequences that break YAML parsing
    // \% → % (but preserve in quoted strings for now)
    $yaml_content = str_replace('\\%', '%', $yaml_content);
    // \$ → $
    $yaml_content = str_replace('\\$', '$', $yaml_content);

    try {
        $yaml_data = Yaml::parse($yaml_content);
    } catch (Exception $e) {
        throw new Exception("Failed to parse YAML file: " . $e->getMessage());
    }

    if (!is_array($yaml_data)) {
        throw new Exception("Invalid YAML format in file: $file_path");
    }

    $pages = [];
    $references = [];
    $search_phrases = [];

    // Handle nested 'pages' structure
    $page_list = $yaml_data['pages'] ?? $yaml_data;

    if (isset($yaml_data['references'])) {
        $references = $yaml_data['references'];
        // Remove only the em-dash before the URL (keep the one between title and source)
        $references = array_map(function($ref) {
            // Replace em dash (—) or en dash (–) that appears before http with space
            // Using explicit character match with u modifier for UTF-8
            $ref = preg_replace('/\s*(—|–)\s*(https?:\/\/)/u', ' $2', $ref);
            // Normalize multiple spaces to single space
            $ref = preg_replace('/\s+/', ' ', $ref);
            return trim($ref);
        }, $references);
    }

    // Extract search phrases from images array
    if (isset($yaml_data['images']) && is_array($yaml_data['images'])) {
        $search_phrases = array_filter(array_map('trim', $yaml_data['images']));
    }

    // If pages is a list of page objects
    if (is_array($page_list) && isset($page_list[0])) {
        foreach ($page_list as $page_data) {
            if (!is_array($page_data)) {
                continue;
            }

            $page_num = null;
            $text = '';

            // Extract text_N from each page object
            foreach ($page_data as $key => $value) {
                if (preg_match('/^text_(\d+)$/', $key, $matches)) {
                    $page_num = (int)$matches[1];
                    // Handle both string and array formats for text
                    if (is_array($value)) {
                        $text = implode("\n", $value);
                    } else {
                        $text = trim($value);
                    }
                    // Remove contentReference patterns from Claude / OAICite (with or without {...} suffix)
                    $text = preg_replace('/:contentReference\[.*?\](\{.*?\})?/', '', $text);
                    // Remove LaTeX formatting: \text{...}, \%, etc.
                    $text = preg_replace('/\\\\text\{([^}]+)\}/', '$1', $text); // Remove \text{...}
                    $text = str_replace('\\%', '%', $text); // Unescape \%
                    $text = str_replace('\\$', '$', $text); // Unescape \$
                    // Replace em dashes, en dashes, and regular dashes with spaces
                    $text = str_replace(['—', '–', '-'], ' ', $text);
                    // Remove trailing periods from each line
                    $text = preg_replace('/\.\s*$/m', '', $text);
                    $text = trim($text);
                }
            }

            if ($page_num !== null) {
                $pages[$page_num] = [
                    'page_number' => $page_num,
                    'text' => $text
                ];
            }
        }
    } else {
        // Handle flat structure (original format)
        foreach ($page_list as $key => $value) {
            if (preg_match('/^text_(\d+)$/', $key, $matches)) {
                $page_num = (int)$matches[1];

                if (!isset($pages[$page_num])) {
                    $pages[$page_num] = [
                        'page_number' => $page_num,
                        'text' => ''
                    ];
                }

                // Handle both string and array formats for text
                if (is_array($value)) {
                    $text = implode("\n", $value);
                } else {
                    $text = trim($value);
                }
                // Remove contentReference patterns from Claude / OAICite (with or without {...} suffix)
                $text = preg_replace('/:contentReference\[.*?\](\{.*?\})?/', '', $text);
                // Remove LaTeX formatting: $...$, \text{...}, \%, etc.
                $text = preg_replace('/\$([^$]+)\$/', '$1', $text); // Remove $ delimiters
                $text = preg_replace('/\\\\text\{([^}]+)\}/', '$1', $text); // Remove \text{...}
                $text = str_replace('\\%', '%', $text); // Unescape \%
                $text = str_replace('\\$', '$', $text); // Unescape \$
                // Replace em dashes, en dashes, and regular dashes with spaces
                $text = str_replace(['—', '–', '-'], ' ', $text);
                // Remove trailing periods from each line
                $text = preg_replace('/\.\s*$/m', '', $text);
                $pages[$page_num]['text'] = trim($text);
            }
        }
    }

    // Validate that each page has text
    foreach ($pages as $page_num => $page) {
        if (empty($page['text'])) {
            throw new Exception("Missing text for page $page_num");
        }
    }

    // Sort by page number and reindex
    ksort($pages);

    return [
        'pages' => array_values($pages),
        'search_phrases' => $search_phrases,
        'references' => $references
    ];
}

/**
 * Search for person image on Wikipedia
 *
 * @param string $person_name Full name of the person
 * @return string|null Wikipedia image URL or null if not found
 */
function search_wikipedia_image(string $person_name): ?string {
    log_message("  Searching Wikipedia for: {$person_name}\n");

    // First, search for the Wikipedia page
    $search_url = "https://en.wikipedia.org/w/api.php?" . http_build_query([
        'action' => 'query',
        'list' => 'search',
        'srsearch' => $person_name,
        'format' => 'json',
        'srlimit' => 1
    ]);

    $ch = curl_init($search_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'FounderFodder/1.0 (https://founderfodder.com; contact@founderfodder.com)');

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        log_message("  Failed to search Wikipedia\n");
        return null;
    }

    $data = json_decode($response, true);
    if (empty($data['query']['search'])) {
        log_message("  No Wikipedia page found\n");
        return null;
    }

    $page_title = $data['query']['search'][0]['title'];
    log_message("  Found Wikipedia page: {$page_title}\n");

    // Get the page images
    $image_url = "https://en.wikipedia.org/w/api.php?" . http_build_query([
        'action' => 'query',
        'titles' => $page_title,
        'prop' => 'pageimages',
        'format' => 'json',
        'pithumbsize' => 500
    ]);

    $ch = curl_init($image_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'FounderFodder/1.0 (https://founderfodder.com; contact@founderfodder.com)');

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        log_message("  Failed to get page images\n");
        return null;
    }

    $data = json_decode($response, true);
    $pages = $data['query']['pages'] ?? [];

    foreach ($pages as $page) {
        if (isset($page['thumbnail']['source'])) {
            log_message("  Found image: {$page['thumbnail']['source']}\n");
            return $page['thumbnail']['source'];
        }
    }

    log_message("  No image found on Wikipedia page\n");
    return null;
}

/**
 * Crop image to square (centered)
 *
 * @param string $source_path Path to source image
 * @param string $dest_path Path to save cropped image
 * @return bool Success
 */
function crop_to_square(string $source_path, string $dest_path): bool {
    // Get image info
    $image_info = @getimagesize($source_path);
    if ($image_info === false) {
        log_message("  Failed to get image info\n");
        return false;
    }

    $mime_type = $image_info['mime'];
    $width = $image_info[0];
    $height = $image_info[1];

    // Load image based on type
    switch ($mime_type) {
        case 'image/jpeg':
            $source = @imagecreatefromjpeg($source_path);
            break;
        case 'image/png':
            $source = @imagecreatefrompng($source_path);
            break;
        case 'image/gif':
            $source = @imagecreatefromgif($source_path);
            break;
        case 'image/webp':
            $source = @imagecreatefromwebp($source_path);
            break;
        default:
            log_message("  Unsupported image type: $mime_type\n");
            return false;
    }

    if ($source === false) {
        log_message("  Failed to load source image\n");
        return false;
    }

    // Determine square size (use smaller dimension)
    $square_size = min($width, $height);

    // Calculate crop offset to center the crop
    $offset_x = (int)(($width - $square_size) / 2);
    $offset_y = (int)(($height - $square_size) / 2);

    // Create square canvas
    $square = imagecreatetruecolor($square_size, $square_size);

    // Copy and crop
    imagecopy(
        $square,
        $source,
        0, 0,
        $offset_x, $offset_y,
        $square_size, $square_size
    );

    // Save as JPEG
    $success = imagejpeg($square, $dest_path, 90);

    // Clean up
    imagedestroy($source);
    imagedestroy($square);

    return $success;
}

/**
 * Download person profile image for list view
 *
 * @param string $story_name Story slug (e.g., "sara-blakely")
 * @param string $base_dir Base directory path
 * @return bool Success
 */
function download_person_image(string $story_name, string $base_dir): bool {
    $image_path = "$base_dir/" . INSPIRATION_DIR . "/$story_name.jpg";

    // Skip if image already exists
    if (file_exists($image_path)) {
        log_message("Person image already exists: $image_path\n");
        return true;
    }

    log_message("Downloading person image for $story_name...\n");

    // Convert slug to name for search
    $person_name = ucwords(str_replace('-', ' ', $story_name));

    $image_url = null;

    // Try Wikipedia first
    log_message("  Trying Wikipedia...\n");
    $image_url = search_wikipedia_image($person_name);

    // Fall back to Google Image search if Wikipedia fails
    if (!$image_url) {
        log_message("  Wikipedia failed, trying Google Image search...\n");
        $search_phrases = [$person_name];
        $image_urls = search_google_images($search_phrases, 10);

        if (empty($image_urls)) {
            log_message("Could not find person image for $story_name\n");
            return false;
        }

        // Try downloading images until one succeeds
        $temp_path = "$base_dir/" . INSPIRATION_DIR . "/$story_name.tmp.jpg";
        $download_success = false;

        foreach ($image_urls as $url) {
            log_message("  Trying: $url\n");
            if (download_image($url, $temp_path)) {
                $image_url = $url;
                $download_success = true;
                break;
            }
        }

        if (!$download_success) {
            log_message("Could not download any image for $story_name\n");
            return false;
        }
    } else {
        // Download from Wikipedia
        $temp_path = "$base_dir/" . INSPIRATION_DIR . "/$story_name.tmp.jpg";
        if (!download_image($image_url, $temp_path)) {
            log_message("Failed to download person image from Wikipedia\n");
            return false;
        }
    }

    // Crop to square
    log_message("  Cropping to square...\n");
    if (!crop_to_square($temp_path, $image_path)) {
        log_message("  Failed to crop image\n");
        @unlink($temp_path);
        return false;
    }

    // Clean up temp file
    @unlink($temp_path);

    log_message("Successfully downloaded and cropped person image\n");
    return true;
}

/**
 * Download image from URL
 *
 * @param string $url Image URL
 * @param string $save_path Where to save the image
 * @return bool Success
 */
function download_image(string $url, string $save_path): bool {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

    $image_data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($image_data === false) {
        log_message("  cURL Error: $curl_error\n");
        return false;
    }

    if ($http_code !== 200) {
        log_message("  HTTP Error: $http_code\n");
        return false;
    }

    $result = file_put_contents($save_path, $image_data);
    if ($result === false) {
        log_message("  File Write Error: Could not save to $save_path\n");
        return false;
    }

    // Validate that the downloaded file is actually a valid image
    $image_info = @getimagesize($save_path);
    if ($image_info === false) {
        log_message("  Invalid image format\n");
        @unlink($save_path);
        return false;
    }

    return true;
}

/**
 * Calculate a simple perceptual hash for an image to detect similar images
 * Returns a hash string that will be similar for visually similar images
 *
 * @param string $image_path Path to image file
 * @return string|false Perceptual hash or false on error
 */
function calculate_perceptual_hash(string $image_path) {
    // Load image
    $image_info = getimagesize($image_path);
    if ($image_info === false) {
        return false;
    }

    $mime_type = $image_info['mime'];

    // Create image resource based on type
    switch ($mime_type) {
        case 'image/jpeg':
            $img = @imagecreatefromjpeg($image_path);
            break;
        case 'image/png':
            $img = @imagecreatefrompng($image_path);
            break;
        case 'image/gif':
            $img = @imagecreatefromgif($image_path);
            break;
        case 'image/webp':
            $img = @imagecreatefromwebp($image_path);
            break;
        default:
            return false;
    }

    if ($img === false) {
        return false;
    }

    // Resize to small fixed size (8x8) for comparison
    $small = imagecreatetruecolor(8, 8);
    imagecopyresampled($small, $img, 0, 0, 0, 0, 8, 8, imagesx($img), imagesy($img));

    // Convert to grayscale and get average
    $pixels = [];
    $total = 0;
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $rgb = imagecolorat($small, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $gray = (int)(($r + $g + $b) / 3);
            $pixels[] = $gray;
            $total += $gray;
        }
    }
    $average = $total / 64;

    // Create hash based on whether each pixel is above or below average
    $hash = '';
    foreach ($pixels as $pixel) {
        $hash .= ($pixel >= $average) ? '1' : '0';
    }

    imagedestroy($img);
    imagedestroy($small);

    return $hash;
}

/**
 * Calculate Hamming distance between two binary strings
 * Returns number of positions where bits differ
 *
 * @param string $hash1 First hash
 * @param string $hash2 Second hash
 * @return int Hamming distance
 */
function hamming_distance(string $hash1, string $hash2): int {
    if (strlen($hash1) !== strlen($hash2)) {
        return PHP_INT_MAX; // Completely different
    }

    $distance = 0;
    for ($i = 0; $i < strlen($hash1); $i++) {
        if ($hash1[$i] !== $hash2[$i]) {
            $distance++;
        }
    }
    return $distance;
}

/**
 * Search Google Images and return image URLs, prioritizing those with relevant alt-text/captions
 *
 * @param array $search_phrases Array of search phrases to combine
 * @param int $num_results Number of image URLs to return
 * @return array Array of image URLs
 */
function search_google_images(array $search_phrases, int $num_results): array {
    if (empty($search_phrases)) {
        log_message("  Warning: No search phrases provided\n");
        return [];
    }

    // Combine all search phrases
    $combined_query = implode(' ', $search_phrases);

    // Add social media exclusions
    $exclusions = [
        '-site:instagram.com',
        '-site:facebook.com',
        '-site:tiktok.com',
        '-site:twitter.com',
        '-site:x.com',
        '-site:pinterest.com',
        '-site:linkedin.com',
        '-site:snapchat.com'
    ];

    $full_query = $combined_query . ' ' . implode(' ', $exclusions);
    $encoded_query = urlencode($full_query);

    log_message("  Google Images search query: $full_query\n");

    // Construct Google Images search URL
    $search_url = "https://www.google.com/search?q={$encoded_query}&tbm=isch&hl=en";

    // Fetch search results page
    $ch = curl_init($search_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.5',
        'Accept-Encoding: gzip, deflate, br',
        'DNT: 1',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1'
    ]);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');

    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($html === false || $http_code !== 200) {
        log_message("  Error fetching Google Images: HTTP $http_code - $curl_error\n");
        return [];
    }

    // Parse images with metadata from HTML
    $images = [];

    // Method 1: Extract from JSON data embedded in the page
    // Google embeds image data in AF_initDataCallback or similar structures
    if (preg_match_all('/\["(https?:\/\/[^"]+\.(jpg|jpeg|png|webp|gif)[^"]*)"[^\]]*?\],\[.*?"([^"]*)".*?\]/s', $html, $json_matches, PREG_SET_ORDER)) {
        foreach ($json_matches as $match) {
            $url = $match[1];
            $metadata = isset($match[3]) ? $match[3] : '';

            // Skip Google's own images
            if (strpos($url, 'google.com') !== false ||
                strpos($url, 'gstatic.com') !== false ||
                strpos($url, 'googleapis.com') !== false) {
                continue;
            }

            $images[] = [
                'url' => $url,
                'metadata' => strtolower($metadata)
            ];
        }
    }

    // Method 2: Try to parse structured data with more flexible patterns
    // Pattern 1: "ou":"url" ... "pt":"page title"
    if (preg_match_all('/"ou":"(https?:\/\/[^"]+\.(jpg|jpeg|png|webp|gif)[^"]*)"[^}]*?"pt":"([^"]*)"/', $html, $structured_matches, PREG_SET_ORDER)) {
        foreach ($structured_matches as $match) {
            $url = $match[1];
            $page_title = isset($match[3]) ? $match[3] : '';

            if (strpos($url, 'google.com') !== false ||
                strpos($url, 'gstatic.com') !== false ||
                strpos($url, 'googleapis.com') !== false) {
                continue;
            }

            $found = false;
            foreach ($images as &$img) {
                if ($img['url'] === $url) {
                    $found = true;
                    // Append to existing metadata
                    if (!empty($page_title)) {
                        $img['metadata'] .= ' ' . strtolower($page_title);
                    }
                    break;
                }
            }
            unset($img);

            if (!$found) {
                $images[] = [
                    'url' => $url,
                    'metadata' => strtolower($page_title)
                ];
            }
        }
    }

    // Pattern 2: Look for image titles and descriptions in various JSON structures
    if (preg_match_all('/"(https?:\/\/[^"]+\.(jpg|jpeg|png|webp|gif)[^"]*)"[^\]]*?"[,:]"([^"]{10,})"/', $html, $title_matches, PREG_SET_ORDER)) {
        foreach ($title_matches as $match) {
            $url = $match[1];
            $description = isset($match[3]) ? $match[3] : '';

            if (strpos($url, 'google.com') !== false ||
                strpos($url, 'gstatic.com') !== false ||
                strpos($url, 'googleapis.com') !== false) {
                continue;
            }

            foreach ($images as &$img) {
                if ($img['url'] === $url && !empty($description)) {
                    // Append to existing metadata
                    $img['metadata'] .= ' ' . strtolower($description);
                    break;
                }
            }
            unset($img);
        }
    }

    // Method 3: Load HTML into DOM for img tag metadata extraction
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Extract img tags with their metadata
    $img_tags = $xpath->query('//img');
    foreach ($img_tags as $img) {
        $src = $img->getAttribute('src');
        $alt = $img->getAttribute('alt');
        $title = $img->getAttribute('title');
        $aria_label = $img->getAttribute('aria-label');

        // Combine all text metadata
        $metadata = implode(' ', array_filter([$alt, $title, $aria_label]));

        if (!empty($src) && preg_match('/\.(jpg|jpeg|png|webp|gif)/', $src)) {
            // Skip Google's own images
            if (strpos($src, 'google.com') !== false ||
                strpos($src, 'gstatic.com') !== false ||
                strpos($src, 'googleapis.com') !== false) {
                continue;
            }

            // Check if we already have this URL
            $found = false;
            foreach ($images as $img_data) {
                if ($img_data['url'] === $src) {
                    $found = true;
                    // Update metadata if we have more now
                    if (!empty($metadata) && empty($img_data['metadata'])) {
                        $img_data['metadata'] = strtolower($metadata);
                    }
                    break;
                }
            }

            if (!$found && !empty($metadata)) {
                $images[] = [
                    'url' => $src,
                    'metadata' => strtolower($metadata)
                ];
            }
        }
    }

    // Also extract from regex patterns (backup method)
    preg_match_all('/"(https?:\/\/[^"]+\.(jpg|jpeg|png|webp|gif)[^"]*)"/', $html, $matches);
    if (!empty($matches[1])) {
        foreach ($matches[1] as $url) {
            if (strpos($url, 'data:') === 0 ||
                strpos($url, 'google.com') !== false ||
                strpos($url, 'gstatic.com') !== false ||
                strpos($url, 'googleapis.com') !== false) {
                continue;
            }

            // Check if we already have this URL
            $found = false;
            foreach ($images as $img) {
                if ($img['url'] === $url) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $images[] = [
                    'url' => $url,
                    'metadata' => ''
                ];
            }
        }
    }

    // Score images based on search phrase matches in metadata AND URL
    foreach ($images as &$image) {
        $score = 0;
        $metadata_lower = $image['metadata'];
        $url_lower = strtolower(urldecode($image['url'])); // Decode URL to catch encoded keywords

        // Normalize URL: replace hyphens/underscores with spaces for better matching
        $url_normalized = str_replace(['-', '_', '%20'], ' ', $url_lower);

        foreach ($search_phrases as $phrase) {
            $phrase_lower = strtolower($phrase);

            // Score from metadata (if available)
            if (!empty($metadata_lower)) {
                // Use case-insensitive word boundary matching
                $pattern = '/\b' . preg_quote($phrase_lower, '/') . '\b/i';
                $count = preg_match_all($pattern, $metadata_lower, $matches);

                // Also add points for partial matches (substring)
                if ($count === 0) {
                    $count = substr_count($metadata_lower, $phrase_lower) * 0.5;
                }

                $score += $count * 2; // Metadata matches worth 2 points
            }

            // Score from URL (filename and path often contain keywords)
            // Check normalized URL (with spaces instead of hyphens)
            $pattern = '/\b' . preg_quote($phrase_lower, '/') . '\b/i';
            $url_matches = preg_match_all($pattern, $url_normalized, $matches);

            if ($url_matches === 0) {
                // Also check original URL and substring matches
                $url_matches = preg_match_all($pattern, $url_lower, $matches);

                if ($url_matches === 0) {
                    // Substring match as last resort
                    $url_matches = substr_count($url_lower, str_replace(' ', '', $phrase_lower)) * 0.3;
                }
            }

            $score += $url_matches; // URL matches worth 1 point (or 0.3 for substring)
        }

        $image['score'] = $score;
    }
    unset($image);

    // Sort by score (highest first), then by original order
    usort($images, function($a, $b) {
        if ($b['score'] !== $a['score']) {
            return $b['score'] - $a['score'];
        }
        return 0;
    });

    // Extract just the URLs
    $image_urls = array_map(function($img) { return $img['url']; }, $images);

    // Log scoring info
    $scored_count = count(array_filter($images, function($img) { return $img['score'] > 0; }));
    $metadata_count = count(array_filter($images, function($img) { return !empty($img['metadata']); }));
    log_message("  Found " . count($image_urls) . " images ($scored_count with relevance score, $metadata_count with metadata)\n");

    return array_slice($image_urls, 0, $num_results);
}

/**
 * Overlay text on image
 *
 * @param string $image_path Path to source image
 * @param string $text Text to overlay (with \n for line breaks)
 * @param string $output_path Where to save the output image
 * @return bool Success
 */
function overlay_text_on_image(string $image_path, string $text, string $output_path): bool {
    // Instagram portrait post dimensions (4:5 ratio - more vertical space for text)
    $target_width = 1080;
    $target_height = 1350;

    // Load image
    $image_info = getimagesize($image_path);
    if ($image_info === false) {
        log_message("  Image Error: Failed to get image info from $image_path\n");
        return false;
    }

    $mime_type = $image_info['mime'];

    switch ($mime_type) {
        case 'image/jpeg':
            $source = imagecreatefromjpeg($image_path);
            break;
        case 'image/png':
            $source = imagecreatefrompng($image_path);
            break;
        case 'image/webp':
            $source = imagecreatefromwebp($image_path);
            break;
        case 'image/gif':
          $source = imagecreatefromgif($image_path);
          break;
        default:
            log_message("  Image Error: Unsupported image type: $mime_type\n");
            return false;
    }

    if ($source === false) {
        log_message("  Image Error: Failed to load image from $image_path (type: $mime_type)\n");
        return false;
    }

    $src_width = imagesx($source);
    $src_height = imagesy($source);

    // Create target canvas with black background
    $image = imagecreatetruecolor($target_width, $target_height);

    // Disable alpha blending for initial setup to preserve original colors
    imagealphablending($image, false);
    imagesavealpha($image, false);

    $black = imagecolorallocate($image, 0, 0, 0);
    imagefilledrectangle($image, 0, 0, $target_width, $target_height, $black);

    // Calculate aspect ratios
    $src_aspect = $src_width / $src_height;
    $target_aspect = $target_width / $target_height; // 4:5 portrait ratio

    // Resize to fit width, pad bottom if needed
    $new_width = $target_width;
    $new_height = (int)($target_width / $src_aspect);

    // If image is already taller than square, scale to fit height instead
    if ($new_height > $target_height) {
        $new_height = $target_height;
        $new_width = (int)($target_height * $src_aspect);
    }

    // Center horizontally, align to top
    $dest_x = (int)(($target_width - $new_width) / 2);
    $dest_y = 0;

    // Copy resized image onto black canvas
    imagecopyresampled(
        $image,
        $source,
        $dest_x, $dest_y,
        0, 0,
        $new_width, $new_height,
        $src_width, $src_height
    );

    imagedestroy($source);

    $width = $target_width;
    $height = $target_height;
    $visible_height = $new_height; // Actual image height on the canvas

    // Enable alpha blending for gradient overlay
    imagealphablending($image, true);

    // Apply black gradient for text readability with a single smooth fade.
    // Requirements:
    // - Keep full image visible (no cropping)
    // - Adapt based on where the image ends
    // - Be fully black by ~65% of the carousel height for strong text contrast
    if ($visible_height <= 0) {
        $visible_height = $height;
    }

    // For short images, base the gradient on the image height.
    // For taller images, limit the "gentle" part to the top half of the canvas.
    $top_region = min($visible_height, (int)($height * 0.5));
    $gradient_start = (int)($top_region * 0.25); // start around 25% into that region

    // Y position where we are fully black:
    // - For tall images: at ~65% of the canvas height
    // - For short images: at the bottom of the image (so the fade is over the image,
    //   then solid black continues in the padding)
    $full_black_y = (int)min($visible_height, (int)($height * 0.65));
    if ($full_black_y <= $gradient_start) {
        $full_black_y = $gradient_start + 1;
    }

    $fade_length = max(1, $full_black_y - $gradient_start);

    for ($y = $gradient_start; $y < $height; $y++) {
        if ($y >= $full_black_y) {
            // Fully black from here to bottom
            $overlay_color = imagecolorallocate($image, 0, 0, 0);
            imagefilledrectangle($image, 0, $y, $width, $height, $overlay_color);
            break;
        }

        // Single smooth fade from transparent to black
        $progress = ($y - $gradient_start) / $fade_length;
        if ($progress < 0) {
            $progress = 0;
        } elseif ($progress > 1) {
            $progress = 1;
        }

        // Alpha: 127 (fully transparent) -> 0 (fully opaque black)
        $alpha = (int)(127 * (1 - $progress));

        $overlay_color = imagecolorallocatealpha($image, 0, 0, 0, $alpha);
        imagefilledrectangle($image, 0, $y, $width, $y, $overlay_color);
    }

    // Text styling - Clean, bold, Instagram-style
    $font_size = (int)($width * 0.035); // Smaller for better fit
    $line_height = (int)($font_size * 1.5); // Slightly tighter line spacing
    $padding_left = (int)($width * 0.08); // 8% left padding
    $padding_right = (int)($width * 0.08); // 8% right padding
    $padding_bottom = (int)($height * 0.01); // 8% bottom padding to prevent cutoff
    $max_text_width = $width - $padding_left - $padding_right;

    // Try to use Inter font (modern, clean sans-serif)
    $font_paths = [
        '/Users/ediril/Library/Fonts/Inter-VariableFont_opsz,wght.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
    ];

    $font_file = null;
    foreach ($font_paths as $path) {
        if (file_exists($path)) {
            $font_file = $path;
            break;
        }
    }

    if ($font_file === null) {
        log_message("  Font Error: No valid font file found. Tried:\n");
        foreach ($font_paths as $path) {
            log_message("    - $path\n");
        }
        return false;
    }

    log_message("  Using font: $font_file\n");

    // Split text into sentences (separated by newlines in our format)
    $sentences = explode("\n", $text);

    // Helper function to calculate text layout for a given font size
    $calculate_layout = function($test_font_size) use ($sentences, $font_file, $max_text_width, &$sentence_count) {
        // Reduce line height for 3+ sentences to fit larger fonts
        $line_height_multiplier = ($sentence_count > 3) ? 1.4 : 1.5;
        $test_line_height = (int)($test_font_size * $line_height_multiplier);
        $total_height = 0;
        $temp_sentence_count = 0;
        $layout_data = [];

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (empty($sentence)) {
                $total_height += (int)($test_line_height * 0.8);
                $layout_data[] = ['type' => 'empty'];
                continue;
            }

            $temp_sentence_count++;

            // Word wrap the sentence
            $words = explode(' ', $sentence);
            $lines = [];
            $current_line = '';

            foreach ($words as $word) {
                $test_line = empty($current_line) ? $word : $current_line . ' ' . $word;
                $bbox = imagettfbbox($test_font_size, 0, $font_file, $test_line);
                $text_width = $bbox[2] - $bbox[0];

                if ($text_width > $max_text_width && !empty($current_line)) {
                    $lines[] = $current_line;
                    $current_line = $word;
                } else {
                    $current_line = $test_line;
                }
            }
            if (!empty($current_line)) {
                $lines[] = $current_line;
            }

            $sentence_height = count($lines) * $test_line_height + (int)($test_line_height * 0.5);
            $total_height += $sentence_height;
            $layout_data[] = ['type' => 'text', 'lines' => $lines];
        }

        return [
            'total_height' => $total_height,
            'sentence_count' => $temp_sentence_count,
            'layout_data' => $layout_data,
            'line_height' => $test_line_height
        ];
    };

    // Calculate available space (from solid black region to bottom with padding)
    $black_region_start = (int)($height * 0.50); // 50% where black is solid
    $black_region_end = $height - $padding_bottom;
    $available_height = $black_region_end - $black_region_start;

    // Get sentence count first (quick pass)
    $sentence_count = 0;
    foreach ($sentences as $sentence) {
        if (!empty(trim($sentence))) {
            $sentence_count++;
        }
    }

    // Smart font size selection based on sentence count (starting point)
    $base_font_sizes = [
        1 => 46,
        2 => 42,
        3 => 38,
        4 => 35,
        5 => 32,
    ];
    $font_size = $base_font_sizes[$sentence_count] ?? 30;

    // Calculate initial layout
    $layout = $calculate_layout($font_size);
    $total_text_height = $layout['total_height'];

    // If text is too tall for available space, reduce font size
    $max_iterations = 10;
    $iteration = 0;
    while ($total_text_height > ($available_height * 0.95) && $iteration < $max_iterations && $font_size > 24) {
        $font_size -= 2; // Reduce by 2px
        $layout = $calculate_layout($font_size);
        $total_text_height = $layout['total_height'];
        $iteration++;
    }

    $line_height = $layout['line_height'];
    $sentence_data = $layout['layout_data'];

    log_message("  Sentence count: $sentence_count, Font size: $font_size, Text height: $total_text_height, Available: $available_height\n");

    // Smart vertical positioning based on actual text height
    // Position text so it's centered in the bottom half with some bias toward bottom
    $padding_top = (int)($available_height * 0.10); // 10% padding from top of black region
    $padding_bottom_text = (int)($available_height * 0.15); // 15% padding from bottom

    // Calculate starting position to center text with bottom bias
    $text_space_start = $black_region_start + $padding_top;
    $text_space_end = $black_region_end - $padding_bottom_text;
    $text_space_height = $text_space_end - $text_space_start;

    // If text fits comfortably, position it nicely centered with slight bottom bias
    // If text is tight, push it higher to ensure it all fits
    if ($total_text_height < ($text_space_height * 0.7)) {
        // Plenty of room - center more in the black area
        $start_y = $text_space_start + (int)(($text_space_height - $total_text_height) * 0.5);
    } else {
        // Tighter fit - use available space more efficiently
        $start_y = $text_space_start + (int)(($text_space_height - $total_text_height) * 0.3);
    }

    log_message("  Text positioned at: $start_y (black starts at: $black_region_start)\n");

    // Pure white text color for maximum contrast
    $text_color = imagecolorallocate($image, 255, 255, 255);

    // Second pass: actually render the text
    $current_y = $start_y;

    foreach ($sentence_data as $data) {
        if ($data['type'] === 'empty') {
            $current_y += (int)($line_height * 0.8);
            continue;
        }

        // Draw each line with fancy multi-layered shadow for depth
        foreach ($data['lines'] as $line) {
            // Create a glow/halo effect with multiple shadow layers
            $shadow_dark = imagecolorallocatealpha($image, 0, 0, 0, 40);
            $shadow_medium = imagecolorallocatealpha($image, 0, 0, 0, 60);
            $shadow_strong = imagecolorallocatealpha($image, 0, 0, 0, 80);

            // Outer glow (larger offset)
            imagettftext($image, $font_size, 0, $padding_left + 5, $current_y + 5, $shadow_dark, $font_file, $line);
            imagettftext($image, $font_size, 0, $padding_left - 1, $current_y + 5, $shadow_dark, $font_file, $line);
            imagettftext($image, $font_size, 0, $padding_left + 5, $current_y - 1, $shadow_dark, $font_file, $line);

            // Medium shadow layer
            imagettftext($image, $font_size, 0, $padding_left + 3, $current_y + 3, $shadow_medium, $font_file, $line);
            imagettftext($image, $font_size, 0, $padding_left - 1, $current_y + 3, $shadow_medium, $font_file, $line);

            // Strong close shadow for definition
            imagettftext($image, $font_size, 0, $padding_left + 2, $current_y + 2, $shadow_strong, $font_file, $line);
            imagettftext($image, $font_size, 0, $padding_left + 1, $current_y + 1, $shadow_strong, $font_file, $line);

            // Main text - pure white with bold effect
            // Render multiple times with slight offset for bold appearance
            imagettftext($image, $font_size, 0, $padding_left, $current_y, $text_color, $font_file, $line);
            imagettftext($image, $font_size, 0, $padding_left + 1, $current_y, $text_color, $font_file, $line);
            imagettftext($image, $font_size, 0, $padding_left + 2, $current_y, $text_color, $font_file, $line);

            $current_y += $line_height;
        }

        $current_y += (int)($line_height * 0.75); // Space after sentence
    }

    // Save as JPEG
    $success = imagejpeg($image, $output_path, 95);
    imagedestroy($image);

    return $success;
}

/**
 * Generate inspiration story images
 *
 * @param string $story_name Story name (without .yml extension)
 * @return array Result with 'success' and 'message' or 'error'
 */
function generate_inspiration_story(string $story_name): array {
    $base_dir = __DIR__;
    $yml_file = "$base_dir/" . INSPIRATION_DIR . "/$story_name.yml";
    $output_dir = "$base_dir/" . INSPIRATION_DIR . "/$story_name";

    // Check if yml file exists
    if (!file_exists($yml_file)) {
        return [
            'success' => false,
            'error' => "Story file not found: $yml_file"
        ];
    }

    // Create output directory
    if (!is_dir($output_dir)) {
        mkdir($output_dir, 0755, true);
    }

    // Create original images directory for this story
    $original_images_dir = "$base_dir/" . ORIGINAL_IMAGES_DIR . "/$story_name";
    if (!is_dir($original_images_dir)) {
        mkdir($original_images_dir, 0755, true);
    }

    try {
        // Download person profile image for list view (non-blocking)
        log_message("\n");
        log_message(str_repeat('=', 50) . "\n");
        log_message("DOWNLOADING PERSON PROFILE IMAGE\n");
        log_message(str_repeat('=', 50) . "\n");
        download_person_image($story_name, $base_dir);

        // Parse the yml file
        log_message("\n");
        log_message(str_repeat('=', 50) . "\n");
        log_message("PARSING STORY FILE\n");
        log_message(str_repeat('=', 50) . "\n");
        log_message("Parsing $yml_file...\n");
        $parsed_data = parse_inspiration_yml($yml_file);
        $pages = $parsed_data['pages'];
        $search_phrases = $parsed_data['search_phrases'];
        log_message("Found " . count($pages) . " pages\n");

        // Track which images we've used (URLs, MD5 hashes, and perceptual hashes)
        $image_index = 0;
        $used_urls = [];
        $used_md5_hashes = [];
        $used_perceptual_hashes = [];

        // Load hashes of all existing cached images to prevent duplicates
        foreach ($pages as $page) {
            $page_num = $page['page_number'];
            $original_image = "$original_images_dir/$page_num.jpg";
            if (file_exists($original_image)) {
                // MD5 hash for exact duplicates
                $existing_md5 = md5_file($original_image);
                $used_md5_hashes[] = $existing_md5;

                // Perceptual hash for similar images (different crops/sizes)
                $existing_phash = calculate_perceptual_hash($original_image);
                if ($existing_phash !== false) {
                    $used_perceptual_hashes[] = $existing_phash;
                }

                log_message("Loaded hashes for existing image $page_num\n");
            }
        }

        // Determine if we need to fetch new images
        $needs_image_download = false;
        foreach ($pages as $page) {
            $page_num = $page['page_number'];
            $original_image = "$original_images_dir/$page_num.jpg";
            if (!file_exists($original_image)) {
                $needs_image_download = true;
                break;
            }
        }

        // Search for images only if we need to download some
        $image_urls = [];
        if ($needs_image_download) {
            if (!empty($search_phrases)) {
                log_message("Searching for images using " . count($search_phrases) . " search phrases...\n");
                $num_images_needed = count($pages) * 3; // 3x for retry pool
                $image_urls = search_google_images($search_phrases, $num_images_needed);

                if (empty($image_urls)) {
                    throw new Exception("Failed to find any images from Google search");
                } else {
                    log_message("Successfully retrieved " . count($image_urls) . " image URLs for " . count($pages) . " pages\n");
                }
            } else {
                throw new Exception("No search phrases found in YAML file");
            }
        }

        // Process each page
        foreach ($pages as $page) {
            $page_num = $page['page_number'];
            $text = $page['text'];

            log_message("Processing page $page_num...\n");

            // Define paths
            $original_image = "$original_images_dir/$page_num.jpg";
            $final_image = "$output_dir/page_$page_num.jpg";
            $temp_image = "$output_dir/temp_$page_num.jpg";

            // Skip if final image already exists
            if (file_exists($final_image)) {
                log_message("  Page $page_num already exists, skipping...\n");
                continue;
            }

            // Check if we have cached original image
            if (file_exists($original_image)) {
                log_message("  Using cached original image\n");
                // Use cached original
                copy($original_image, $temp_image);
            } else {
                // Download a new unique image
                log_message("  Downloading new image...\n");
                $download_success = false;
                $attempts = 0;
                $max_attempts = min(20, count($image_urls) - $image_index);

                while (!$download_success && $attempts < $max_attempts && $image_index < count($image_urls)) {
                    $image_url = $image_urls[$image_index];
                    $image_index++;
                    $attempts++;

                    // Skip if we've already used this URL
                    if (in_array($image_url, $used_urls)) {
                        log_message("  Skipping duplicate URL (attempt $attempts)\n");
                        continue;
                    }

                    log_message("  Attempt $attempts: Downloading from $image_url...\n");
                    if (download_image($image_url, $temp_image)) {
                        // Check MD5 hash for exact duplicates
                        $image_md5 = md5_file($temp_image);
                        if (in_array($image_md5, $used_md5_hashes)) {
                            log_message("  Duplicate image (exact match) detected, trying next...\n");
                            @unlink($temp_image);
                            continue;
                        }

                        // Check perceptual hash for similar images (crops, resizes)
                        $image_phash = calculate_perceptual_hash($temp_image);
                        if ($image_phash !== false) {
                            $is_similar = false;
                            foreach ($used_perceptual_hashes as $existing_phash) {
                                $distance = hamming_distance($image_phash, $existing_phash);
                                // Threshold: if <= 10 bits differ out of 64, consider it similar
                                // (allows for minor crops/compression differences)
                                if ($distance <= 10) {
                                    $is_similar = true;
                                    log_message("  Similar image detected (distance: $distance), trying next...\n");
                                    break;
                                }
                            }
                            if ($is_similar) {
                                @unlink($temp_image);
                                continue;
                            }
                        }

                        // Success - unique image found
                        $download_success = true;
                        $used_urls[] = $image_url;
                        $used_md5_hashes[] = $image_md5;
                        if ($image_phash !== false) {
                            $used_perceptual_hashes[] = $image_phash;
                        }

                        // Save original image to cache
                        copy($temp_image, $original_image);
                        log_message("  Successfully downloaded and cached unique image\n");
                    } else {
                        log_message("  Failed, trying next image...\n");
                    }
                }

                if (!$download_success) {
                    throw new Exception("Failed to download any unique image for page $page_num after $attempts attempts");
                }
            }

            log_message("  Overlaying text...\n");
            if (!overlay_text_on_image($temp_image, $text, $final_image)) {
                throw new Exception("Failed to overlay text on page $page_num");
            }

            // Clean up temp file
            if (file_exists($temp_image)) {
                unlink($temp_image);
            }

            log_message("  Page $page_num generated successfully!\n");
        }

        return [
            'success' => true,
            'message' => "Successfully generated " . count($pages) . " pages",
            'page_count' => count($pages)
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// CLI execution
if (php_sapi_name() === 'cli') {
    if ($argc < 2) {
        echo "Usage: php generate_inspiration.php <story-name>\n";
        echo "Example: php generate_inspiration.php steve-jobs\n";
        exit(1);
    }

    $story_name = $argv[1];
    echo "Generating inspiration story: $story_name\n";
    echo str_repeat('-', 50) . "\n";

    $result = generate_inspiration_story($story_name);

    if ($result['success']) {
        echo "\n" . str_repeat('-', 50) . "\n";
        echo "✓ " . $result['message'] . "\n";
        exit(0);
    } else {
        echo "\n" . str_repeat('-', 50) . "\n";
        echo "✗ Error: " . $result['error'] . "\n";
        exit(1);
    }
}
