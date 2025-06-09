<?php 
$page_title = 'Founder Fodder';
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
            <div class="buttons is-centered story-filters mb-5">
                <a href="/?type=top" class="button <?php echo ($stories['type'] === 'top') ? 'is-primary' : ''; ?>">
                    <span class="icon"><i class="fa-solid fa-arrow-trend-up"></i></span>
                    <span>Top</span>
                </a>
                <a href="/?type=best" class="button <?php echo ($stories['type'] === 'best') ? 'is-primary' : ''; ?>">
                    <span class="icon"><i class="fa-solid fa-star"></i></span>
                    <span>Best</span>
                </a>
                <a href="/?type=new" class="button <?php echo ($stories['type'] === 'new') ? 'is-primary' : ''; ?>">
                    <span class="icon"><i class="fa-solid fa-clock"></i></span>
                    <span>New</span>
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
                            <span class="has-text-grey is-size-6 ml-2">(<?php echo htmlspecialchars($story['domain']); ?>)</span>
                        </p>
                        <p class="subtitle is-6 pt-3 mb-3">
                            <span class="mr-3"><i class="fa-solid fa-clock"></i> <?php echo htmlspecialchars($story['time_ago']); ?></span>
                            <span class="mr-3"><i class="fa-solid fa-arrow-up"></i> <?php echo (int)$story['score']; ?></span>
                            <span class="mr-3"><i class="fa-solid fa-comment"></i> <?php echo (int)$story['comments_count']; ?></span>
                        </p>
                        <div class="story-actions">
                            <?php 
                            $summary_files = $story['summary_files'] ?? ['has_story_summary' => false, 'has_comments_summary' => false];
                            if ($summary_files['has_story_summary'] || $summary_files['has_comments_summary']): 
                            ?>
                            <a href="/summary/<?php echo htmlspecialchars($story['id']); ?>" class="button is-small">
                                <span>Summary</span>
                            </a>
                            <?php else: ?>
                            <a href="<?php echo htmlspecialchars($story['comments_url']); ?>" class="button is-small">
                                <span>Comments</span>
                            </a>
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