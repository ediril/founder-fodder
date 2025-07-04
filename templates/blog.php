<?php 
$page_title = 'Blog - Founder Fodder';
include __DIR__ . '/partials/head.php'; 
include __DIR__ . '/partials/hero.php'; 
?>
<section class="section">
    <div class="container">
        <div class="content is-medium">
            <div class="box px-2">
                <h1 class="title is-3 mb-0">Blog</h1>
                <div><h2 class="title is-4 mt-4 mb-1">July 4, 2025</h2>
                <p>
                I wasn't particularly happy about the Active/Top/Best/New selection buttons at the top, so I removed them.
                Active stories were the only ones being shown for a few days. But it is nice to get the other kinds of stories
                into the feed as well, to round out the types of stories shown to readers.
                </p><p></p>
                So mixed all story types into the feed now. I think that's a much better design than having to decide which
                story type to read. Each story has a "story type icon" indicating which type it is if you care to know.
                </p>
                </div>
                <div><h2 class="title is-4 mt-4 mb-1">July 1, 2025</h2>
                    <p>
                    I had the idea for this site for quite some time but never got around to it even though I read Hacker News
                    regularly. That is, until vibe coding became a thing and I thought it is the perfect well-defined mini-project.
                    </p><p>
                    I got started a couple of months ago, late on a Friday night. 
                    It didn't take long at all to get the very basic version running that fetches new, top and best stories from hacker news API. 
                    I used it when I went to bed and remembered dark mode is a must! Then it was another day or so to add summarization and 
                    the cron job to run fetching and summarizing periodically on the web server. I did run into several small bugs that I spent less than an 
                    hour a day crushing over the next few days.
                    </p><p>
                    Using the site some more, I realized I really like the active stories the most. That's where I've been getting most 
                    of my news for the past several decades. Sadly, there is no active story feed in the hacker news API for some reason
                    and I had left it out to keep it simple. So we had to vibe code a solution for that.
                    </p><p>
                    As I kept using and adding more to the site, it turned into a more serious project and I want to keep going. Clearly,
                    there is no future for the site if it's just a Hacker News mirror. I've been following several folks on X and I got
                    the idea that online directories are a good idea. So, the short-term roadmap is to start steering away from HN a bit,
                    I'll be inserting links I find to be interesting and still on-point of course, and figure out a way to turn this site
                    into some kind of an online directory.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?> 