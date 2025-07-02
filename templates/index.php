<?php 
$page_title = 'Founder Fodder';

// Set dynamic description based on active tab
$active_tab = $active_tab ?? 'news';
$tab_descriptions = [
    'news' => 'Curated stories for Founders, Makers and Tinkerers.',
    'ventures' => 'Curated SaaS companies - Coming soon!',
    'tools' => 'Curated tools and resources for Founders, Makers and Tinkerers - Coming soon!'
];

// Set dynamic description based on story type for news tab
if ($active_tab === 'news' && isset($stories['type'])) {
    $story_type = $stories['type'];
    $story_type_descriptions = [
        'active' => 'Trending stories for Founders, Makers and Tinkerers',
        'new' => 'Latest tech news and startup stories for Founders, Makers and Tinkerers', 
        'top' => 'Top-rated stories for Founders, Makers and Tinkerers',
        'best' => 'Best-rated stories for Founders, Makers and Tinkerers'
    ];
    $page_description = $story_type_descriptions[$story_type] ?? $tab_descriptions['news'];
    $og_title = "FounderFodder - " . ucfirst($story_type) . " Stories";
} else {
    $page_description = $tab_descriptions[$active_tab] ?? $tab_descriptions['news'];
    $og_title = "FounderFodder - " . ucfirst($active_tab);
}

$og_description = $page_description;

include __DIR__ . '/partials/head.php'; 
include __DIR__ . '/partials/hero.php'; 
?>    
    <section class="section">
        <div class="container">
            <?php if ($active_tab === 'news'): ?>            
                <?php if (empty($stories['stories'])): ?>
                <div class="notification is-warning">
                    No stories found.
                </div>
                <?php else: ?>
                
                <div class="last-updated-card mb-4">
                    <p class="is-size-7 has-text-grey">
                        Last updated: <?php echo $last_updated ? format_time_ago($last_updated) : 'Never'; ?>
                    </p>
                </div>

                <div class="story-list">
                    <?php foreach ($stories['stories'] as $story): ?>
                    <div class="story-card">
                        <div class="story-image">
                            <?php if (!empty($story['image_url'])): ?>
                            <img src="<?php echo htmlspecialchars($story['image_url']); ?>" 
                                alt="<?php echo htmlspecialchars($story['domain']); ?>"
                                onerror="this.style.display='none'; this.parentNode.innerHTML = '<div class=\'image-placeholder\'><i class=\'fa-solid fa-fire\'></i></div>';">
                            <?php else: ?>
                            <div class="image-placeholder">
                                <i class="fa-solid fa-fire"></i>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="story-content">
                            <p class="title is-5 mb-3">
                                <a href="<?php echo htmlspecialchars(!empty($story['url']) ? $story['url'] : $story['comments_url']); ?>">
                                    <?php echo htmlspecialchars($story['title']); ?>
                                </a>
                                <span class="has-text-grey is-size-6">(<?php echo htmlspecialchars(!empty($story['domain']) ? $story['domain'] : 'news.ycombinator.com'); ?>)</span>
                            </p>
                            <p class="subtitle is-6 pt-3 mb-3">
                                <span class="mr-3"><i class="fa-solid fa-clock"></i> <?php echo htmlspecialchars($story['time_ago']); ?></span>
                            </p>
                            <?php
                            $summary_files = $story['summary_files'] ?? ['has_story_summary' => false];
                            $excerpt = "";
                            if ($summary_files['has_story_summary']) {
                                $summary_path = __DIR__ . '/../static/content/' . $story['id'] . '-story-summary.md';
                                $raw_summary = file_get_contents($summary_path);
                                $parts = preg_split('/\R{2,}/', $raw_summary, 2); // split on empty line
                                $excerpt = trim($parts[0]);
                                // Basic cleanup of markdown characters
                                $excerpt = preg_replace('/^[#>*\-\s]+/m', '', $excerpt); // remove markdown bullets/heading chars
                                $excerpt = preg_replace('/\[(.*?)\]\((.*?)\)/', '$1', $excerpt); // remove markdown links
                                if (strlen($excerpt) > 250) {
                                    $excerpt = substr($excerpt, 0, 250) . '...';
                                }
                            } 
                            ?>
                            <div class="story-actions">
                            <?php if (str_contains($excerpt, 'Story is too short to summarize') ||
                                    str_contains($excerpt, 'Story content not detected') ||
                                    empty($excerpt)): ?>
                                <a href="<?php echo htmlspecialchars($story['comments_url']); ?>" class="button is-small">
                                    <span>Comments</span>
                                </a>
                            <?php else: ?>
                                <p class="story-excerpt">
                                    <?php echo htmlspecialchars($excerpt); ?>
                                </p>
                                <p>
                                    <a class="button is-small mt-2" 
                                        href="/summary/<?php echo htmlspecialchars($story['id']); ?>"> Read More</a>
                                </p>
                            <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if (isset($stories['pagination']) && $stories['pagination']['has_more']): ?>
                <div class="has-text-centered mt-6">
                    <a href="/?tab=news&type=<?php echo htmlspecialchars($stories['type']); ?>&page=<?php echo (int)$stories['pagination']['next_page']; ?>" 
                    class="button is-primary is-medium">
                        <span>More Stories</span>
                        <span class="icon">
                            <i class="fa-solid fa-arrow-right"></i>
                        </span>
                    </a>
                </div>
                <?php endif; ?>
                
                <?php endif; ?>
            
            <?php elseif ($active_tab === 'ventures'): ?>
                <div class="has-text-centered">
                    <div class="box">
                        <p class="subtitle is-5">
                            Coming soon...
                        </p>
                    </div>
                </div>
            
            <?php elseif ($active_tab === 'tools'): ?>
                <div class="has-text-centered">
                    <div class="box">
                        <p class="subtitle is-5">
                            Coming soon...
                        </p>
                    </div>
                </div>
            
            <?php endif; ?>
        </div>
    </section>
    
<?php include __DIR__ . '/partials/footer.php'; ?>