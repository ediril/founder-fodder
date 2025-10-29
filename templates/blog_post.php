<?php
$page_title = htmlspecialchars($post['title']) . ' - Blog - Founder Fodder';
include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/hero.php';
?>
<section class="section">
    <div class="container">
        <div class="content is-medium">
            <div class="box">
                <div class="mb-5">
                    <a href="/blog" class="button is-light">
                        <span class="icon">
                            <i class="fas fa-arrow-left"></i>
                        </span>
                        <span>Back to Blog</span>
                    </a>
                </div>

                <h1 class="title is-3 mb-2">
                    <?php echo htmlspecialchars($post['title']); ?>
                </h1>
                <p class="has-text-grey-dark is-size-6 mb-5">
                    <?php echo htmlspecialchars($post['date']); ?>
                </p>

                <div class="content">
                    <?php echo $post['html_content']; ?>
                </div>
                <br><br>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>
