<?php
$page_title = htmlspecialchars($person_name) . ' - Inspiration - Founder Fodder';
include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/hero.php';
?>
<section class="section">
    <div class="container">
        <div class="content is-medium">
            <div class="box">
                <nav class="breadcrumb" aria-label="breadcrumbs">
                    <ul>
                        <li><a href="/inspiration">Inspiration</a></li>
                        <li class="is-active"><a href="#" aria-current="page"><?php echo htmlspecialchars($person_name); ?></a></li>
                    </ul>
                </nav>

                <h1 class="title is-3"><?php echo htmlspecialchars($person_name); ?></h1>

                <div class="content">
                    <?php echo $story_content; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>
