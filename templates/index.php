<?php 
$page_title = 'Founder Fodder';

// Set dynamic description based on story type
$story_type = $stories['type'] ?? 'active';
$story_type_descriptions = [
    'active' => 'Currently trending stories for founders and entrepreneurs',
    'new' => 'Latest tech news and startup stories for founders and makers', 
    'top' => 'Top-rated stories for entrepreneurs and technology enthusiasts',
    'best' => 'Best-rated stories for entrepreneurs and technology enthusiasts'
];

$page_description = $story_type_descriptions[$story_type] ?? 'Inspiration for Founders, Makers and Tinkerers. Curated stories from Hacker News for entrepreneurs and builders.';
$og_title = "FounderFodder - " . ucfirst($story_type) . " Stories";
$og_description = $page_description;

include __DIR__ . '/partials/head.php'; 
?>
    <section class="hero is-small">
        <div class="hero-body">
            <div class="container has-text-centered">
                <h1 class="title is-2">
                    <i class="fa-solid fa-fire mr-2"></i> Founder Fodder
                </h1>
                <p class="subtitle">
                    Inspiration for Founders, Makers and Tinkerers
                </p>
            </div>
        </div>
    </section>
    
    <section class="section">
        <div class="container">
            <div class="buttons is-centered story-filters mb-2">
                <a href="/?type=active" class="button <?php echo ($stories['type'] === 'active') ? 'is-primary' : ''; ?>">
                    <span>Active</span>
                </a>
                <a href="/?type=new" class="button <?php echo ($stories['type'] === 'new') ? 'is-primary' : ''; ?>">
                    <span>New</span>
                </a>
                <a href="/?type=top" class="button <?php echo ($stories['type'] === 'top') ? 'is-primary' : ''; ?>">
                    <span>Top</span>
                </a>
                <a href="/?type=best" class="button <?php echo ($stories['type'] === 'best') ? 'is-primary' : ''; ?>">
                    <span>Best</span>
                </a>
            </div>
            
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
                            <a href="<?php echo htmlspecialchars($story['url']); ?>">
                                <?php echo htmlspecialchars($story['title']); ?>
                            </a>
                            <span class="has-text-grey is-size-6">(<?php echo htmlspecialchars($story['domain']); ?>)</span>
                        </p>
                        <p class="subtitle is-6 pt-3 mb-3">
                            <span class="mr-3"><i class="fa-solid fa-clock"></i> <?php echo htmlspecialchars($story['time_ago']); ?></span>
                            <span class="mr-3"><i class="fa-solid fa-arrow-up"></i> <?php echo (int)$story['score']; ?></span>
                            <span class="mr-3"><i class="fa-solid fa-comment"></i> <?php echo (int)$story['comments_count']; ?></span>
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
            <?php if ($stories['pagination']['has_more']): ?>
            <div class="has-text-centered mt-6">
                <a href="/?type=<?php echo htmlspecialchars($stories['type']); ?>&page=<?php echo (int)$stories['pagination']['next_page']; ?>" 
                   class="button is-primary is-medium">
                    <span>More Stories</span>
                    <span class="icon">
                        <i class="fa-solid fa-arrow-right"></i>
                    </span>
                </a>
            </div>
            <?php endif; ?>
            
            <?php endif; ?>
        </div>
    </section>
    
<?php include __DIR__ . '/partials/footer.php'; ?>