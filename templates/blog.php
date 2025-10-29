<?php
$page_title = 'Blog - Founder Fodder';
include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/hero.php';
?>
<section class="section">
    <div class="container">
        <div class="content is-medium">
            <div class="box p-5">
                <h1 class="title is-3 mb-0">Blog</h1>
                <?php if (empty($posts)): ?>
                    <p class="has-text-grey">No blog posts available yet.</p>
                <?php else: ?>
                    <?php foreach ($posts as $post): ?>
                        <h2 class="title is-4 pt-2 mb-1">
                            <span class="has-text-grey-dark is-size-4 pr-2">
                                <?php echo htmlspecialchars($post['date']); ?>
                            </span>
                            <a href="/blog/<?php echo htmlspecialchars($post['slug']); ?>">
                                <?php echo htmlspecialchars($post['title']); ?>
                            </a>
                        </h2>
                    <?php endforeach; ?>
                <?php endif; ?>
               <br><br>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?> 