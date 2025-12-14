<?php
/**
 * Sitemap Generator for FounderFodder
 *
 * Usage: php generate_sitemap.php
 *
 * Generates sitemap.xml with:
 * - Static pages (home, about, legal, privacy, blog, inspiration)
 * - Blog posts from _blog_posts/*.md
 * - Inspiration stories from inspire/*.yml
 * - Story summaries from database (stories that have summary files)
 */

declare(strict_types=1);

require_once __DIR__ . '/common.php';

const SITE_URL = 'https://founderfodder.com';

/**
 * Generate XML for a single URL entry
 */
function url_entry(string $loc, string $lastmod = '', string $changefreq = '', string $priority = ''): string {
    $xml = "  <url>\n";
    $xml .= "    <loc>" . htmlspecialchars($loc) . "</loc>\n";

    if ($lastmod) {
        $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
    }
    if ($changefreq) {
        $xml .= "    <changefreq>{$changefreq}</changefreq>\n";
    }
    if ($priority) {
        $xml .= "    <priority>{$priority}</priority>\n";
    }

    $xml .= "  </url>\n";
    return $xml;
}

/**
 * Get stories that have summary files
 */
function get_stories_with_summaries(): array {
    $stories = [];
    $content_dir = __DIR__ . '/static/content';

    if (!is_dir($content_dir)) {
        return $stories;
    }

    // Find all story summary files
    $files = glob($content_dir . '/*-story-summary.md');

    foreach ($files as $file) {
        $basename = basename($file);
        if (preg_match('/^(\d+)-story-summary\.md$/', $basename, $matches)) {
            $story_id = $matches[1];
            $stories[] = [
                'id' => $story_id,
                'lastmod' => date('Y-m-d', filemtime($file))
            ];
        }
    }

    return $stories;
}

/**
 * Get inspiration stories from yml files
 */
function get_inspiration_stories(): array {
    $stories = [];
    $inspire_dir = __DIR__ . '/inspire';

    if (!is_dir($inspire_dir)) {
        return $stories;
    }

    $files = glob($inspire_dir . '/*.yml');

    foreach ($files as $file) {
        $slug = basename($file, '.yml');
        $stories[] = [
            'slug' => $slug,
            'lastmod' => date('Y-m-d', filemtime($file))
        ];
    }

    return $stories;
}

/**
 * Get blog posts
 */
function get_blog_posts_for_sitemap(): array {
    $posts = [];
    $blog_dir = __DIR__ . '/_blog_posts';

    if (!is_dir($blog_dir)) {
        return $posts;
    }

    $files = glob($blog_dir . '/*.md');

    foreach ($files as $file) {
        $slug = basename($file, '.md');
        $posts[] = [
            'slug' => $slug,
            'lastmod' => date('Y-m-d', filemtime($file))
        ];
    }

    return $posts;
}

// Start building sitemap
$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// Static pages
$xml .= url_entry(SITE_URL . '/', date('Y-m-d'), 'daily', '1.0');
$xml .= url_entry(SITE_URL . '/inspiration', date('Y-m-d'), 'weekly', '0.9');
$xml .= url_entry(SITE_URL . '/blog', date('Y-m-d'), 'weekly', '0.8');
$xml .= url_entry(SITE_URL . '/about', '', 'monthly', '0.5');
$xml .= url_entry(SITE_URL . '/legal', '', 'yearly', '0.3');
$xml .= url_entry(SITE_URL . '/privacy', '', 'yearly', '0.3');

// Inspiration stories
$inspiration_stories = get_inspiration_stories();
foreach ($inspiration_stories as $story) {
    $xml .= url_entry(
        SITE_URL . '/' . $story['slug'],
        $story['lastmod'],
        'monthly',
        '0.8'
    );
}

// Blog posts
$blog_posts = get_blog_posts_for_sitemap();
foreach ($blog_posts as $post) {
    $xml .= url_entry(
        SITE_URL . '/blog/' . $post['slug'],
        $post['lastmod'],
        'monthly',
        '0.7'
    );
}

// Story summaries
$summaries = get_stories_with_summaries();
foreach ($summaries as $summary) {
    $xml .= url_entry(
        SITE_URL . '/summary/' . $summary['id'],
        $summary['lastmod'],
        'weekly',
        '0.6'
    );
}

$xml .= '</urlset>' . "\n";

// Write to file
$sitemap_path = __DIR__ . '/sitemap.xml';
if (file_put_contents($sitemap_path, $xml) !== false) {
    $url_count = count($inspiration_stories) + count($blog_posts) + count($summaries) + 6; // 6 static pages
    echo "Sitemap generated: {$sitemap_path}\n";
    echo "Total URLs: {$url_count}\n";
    echo "  - Static pages: 6\n";
    echo "  - Inspiration stories: " . count($inspiration_stories) . "\n";
    echo "  - Blog posts: " . count($blog_posts) . "\n";
    echo "  - Story summaries: " . count($summaries) . "\n";
} else {
    echo "Error: Failed to write sitemap.xml\n";
    exit(1);
}
