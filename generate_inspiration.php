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
                    // Remove contentReference patterns from Claude Code
                    $text = preg_replace('/:contentReference\[.*?\]\{.*?\}/', '', $text);
                    // Remove LaTeX formatting: $...$, \text{...}, \%, etc.
                    $text = preg_replace('/\$([^$]+)\$/', '$1', $text); // Remove $ delimiters
                    $text = preg_replace('/\\\\text\{([^}]+)\}/', '$1', $text); // Remove \text{...}
                    $text = str_replace('\\%', '%', $text); // Unescape \%
                    $text = str_replace('\\$', '$', $text); // Unescape \$
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
                // Remove contentReference patterns from Claude Code
                $text = preg_replace('/:contentReference\[.*?\]\{.*?\}/', '', $text);
                // Remove LaTeX formatting: $...$, \text{...}, \%, etc.
                $text = preg_replace('/\$([^$]+)\$/', '$1', $text); // Remove $ delimiters
                $text = preg_replace('/\\\\text\{([^}]+)\}/', '$1', $text); // Remove \text{...}
                $text = str_replace('\\%', '%', $text); // Unescape \%
                $text = str_replace('\\$', '$', $text); // Unescape \$
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
    // Instagram square post dimensions
    $target_width = 1080;
    $target_height = 1080;

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
    $target_aspect = 1.0; // Instagram square

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

    // Enable alpha blending for gradient overlay
    imagealphablending($image, true);

    // Apply black gradient starting lower to show more of the image
    // - 45% to 57.5%: fade from 100% transparent to 100% opaque
    // - 57.5% to 100%: stay 100% opaque black
    // This ensures text area has solid black background even with padding
    $gradient_start = (int)($height * 0.40); // Start gradient
    $gradient_full_opacity = (int)($height * 0.60); // Full opacity by 57.5%

    for ($y = $gradient_start; $y < $height; $y++) {
        if ($y >= $gradient_full_opacity) {
            // 100% opaque black from here to bottom (applies to padding too)
            $overlay_color = imagecolorallocate($image, 0, 0, 0);
            imagefilledrectangle($image, 0, $y, $width, $height, $overlay_color);
            break;
        } else {
            // Fade from transparent to opaque across full width
            $progress = ($y - $gradient_start) / ($gradient_full_opacity - $gradient_start);
            $alpha = (int)(127 * (1 - $progress)); // 127 (transparent) to 0 (opaque)

            $overlay_color = imagecolorallocatealpha($image, 0, 0, 0, $alpha);
            imagefilledrectangle($image, 0, $y, $width, $y, $overlay_color);
        }
    }

    // Text styling - Clean, bold, Instagram-style
    $font_size = (int)($width * 0.035); // Smaller for better fit
    $line_height = (int)($font_size * 1.5); // Slightly tighter line spacing
    $padding_left = (int)($width * 0.08); // 8% left padding
    $padding_right = (int)($width * 0.08); // 8% right padding
    $padding_bottom = (int)($height * 0.08); // 8% bottom padding to prevent cutoff
    $max_text_width = $width - $padding_left - $padding_right;

    // Try to use a bold sans-serif font (similar to Instagram)
    $font_paths = [
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        '/System/Library/Fonts/Helvetica.ttc',
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
        $line_height_multiplier = ($sentence_count >= 3) ? 1.4 : 1.5;
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
    $black_region_start = (int)($height * 0.575); // 57.5% where black is solid
    $black_region_end = $height - $padding_bottom;

    // Get sentence count first (quick pass)
    $sentence_count = 0;
    foreach ($sentences as $sentence) {
        if (!empty(trim($sentence))) {
            $sentence_count++;
        }
    }

    // Hardcoded position and font size by sentence count for easy adjustment
    $positioning = [
        1 => ['start' => 0.78, 'max_font' => 0.059],
        2 => ['start' => 0.70, 'max_font' => 0.055],  // Higher position for bigger font
        3 => ['start' => 0.66, 'max_font' => 0.053],  // Lower position, smaller font
        4 => ['start' => 0.60, 'max_font' => 0.049],  // Even lower
        5 => ['start' => 0.55, 'max_font' => 0.045],  // Lowest
    ];

    // Use config for this sentence count, or default to 5+ if more
    $config = $positioning[$sentence_count] ?? $positioning[5];
    $text_start_position = $config['start'];
    $max_font_multiplier = $config['max_font'];

    // Available height is from where text WILL start to the bottom
    $text_will_start_at = (int)($height * $text_start_position);
    $available_height = $black_region_end - $text_will_start_at;

    // Font size range
    $min_font_size = (int)($width * 0.025);
    $max_font_size = (int)($width * $max_font_multiplier);

    // Find the LARGEST font size that fits
    $best_font_size = $min_font_size;
    for ($test_size = $max_font_size; $test_size >= $min_font_size; $test_size -= 1) {
        $result = $calculate_layout($test_size);
        if ($result['total_height'] <= $available_height) {
            $best_font_size = $test_size;
            break; // Found the largest that fits
        }
    }

    // Use the optimal font size
    $layout = $calculate_layout($best_font_size);
    $font_size = $best_font_size;
    $line_height = $layout['line_height'];
    $total_text_height = $layout['total_height'];
    $sentence_data = $layout['layout_data'];

    log_message("  Optimal font size: $font_size (height: $total_text_height / $available_height available)\n");

    // Apply the hardcoded position (already calculated above)
    $start_y = (int)($height * $text_start_position);

    // Ensure text fits without cutting off bottom
    if ($start_y + $total_text_height > $black_region_end) {
        $start_y = $black_region_end - $total_text_height;
    }

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

            // Main text - pure white
            imagettftext(
                $image,
                $font_size,
                0,
                $padding_left,
                $current_y,
                $text_color,
                $font_file,
                $line
            );

            $current_y += $line_height;
        }

        $current_y += (int)($line_height * 0.5); // Space after sentence
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

    try {
        // Parse the yml file
        log_message("Parsing $yml_file...\n");
        $parsed_data = parse_inspiration_yml($yml_file);
        $pages = $parsed_data['pages'];
        $search_phrases = $parsed_data['search_phrases'];
        log_message("Found " . count($pages) . " pages\n");

        // Search for images using Google Images (get 3x pages for retry pool)
        $image_urls = [];
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

        // Track which images we've used (both URLs and content hashes)
        $image_index = 0;
        $used_urls = [];
        $used_hashes = [];

        // Process each page
        foreach ($pages as $page_index => $page) {
            $page_num = $page['page_number'];
            $text = $page['text'];

            log_message("Processing page $page_num...\n");

            // Download original image
            $temp_image = "$output_dir/temp_$page_num.jpg";
            $final_image = "$output_dir/page_$page_num.jpg";

            // Skip if final image already exists
            if (file_exists($final_image)) {
                log_message("  Page $page_num already exists, skipping...\n");
                continue;
            }

            // Try to download a unique image, with retries from the pool
            $download_success = false;
            $attempts = 0;
            $max_attempts = min(20, count($image_urls) - $image_index); // Try up to 20 images or until pool exhausted

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
                    // Check if this is a duplicate image by content hash
                    $image_hash = md5_file($temp_image);

                    if (in_array($image_hash, $used_hashes)) {
                        log_message("  Duplicate image content detected, trying next...\n");
                        @unlink($temp_image);
                        continue;
                    }

                    // Success - unique image found
                    $download_success = true;
                    $used_urls[] = $image_url;
                    $used_hashes[] = $image_hash;
                    log_message("  Successfully downloaded unique image\n");
                } else {
                    log_message("  Failed, trying next image...\n");
                }
            }

            if (!$download_success) {
                throw new Exception("Failed to download any unique image for page $page_num after $attempts attempts");
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
