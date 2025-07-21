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
                <div class="pt-5"><h2 class="title is-4 pt-5 mb-1">July 21, 2025</h2>
                <p>
                Ah, the obssessed mind is a special kind of double-edged sword.. In a flash of brilliance,
                we were able to bring back the mixture of active, top and new stories to the default news feed.
                What was with all the difficulty we had earlier? Who knows, anyway.. You're welcome! 
                </p><p>
                I kept the best stories out of the main feed though because they change very slowly and it is not
                fun to keep seeing the same stories over and over even if it's one or two per page. If you'd like to
                see them, they are still available via the link in the footer.
                </p>
                </div>

                <div class="pt-5"><h2 class="title is-4 pt-5 mb-1">July 20, 2025</h2>
                <p>
                I was travelling recently and I was unable to fix the annoying bug that after a few pages,
                the stories were repeating, ugggh! Sorry about that. In trying to fix this however, AI struggled
                quite a bit, and I gave it some thought as well and it turned out to be a difficult problem to 
                come up with a mixture of stories that don't show the same story on multiple pages. The source of
                difficulty is that a given story might belong to multiple categories: for example, it could be a top 
                story and an active story at the same time.
                </p><p>
                I wanted to enforce a particular mixture ratio but that turned out to be too much trouble and most likely
                not a big deal honestly. So we settled on an actual mixture, like shuffling cards of different colors while
                paying attention to their initial order. I had decided to eliminate new stories that have less than at least a 
                couple of comments since they are mostly noise if noone cares to even comment on them, and that's still in effect
                </p><p>
                Let's see how this new mixture goes. Of course you can still view stories of a single type using the links in the
                footer.
                </p><p>
                Happy reading!
                </p>
                </div>

                <div class="pt-5"><h2 class="title is-4 pt-5 mb-1">July 10, 2025</h2>
                <p>
                I love incrementally improving a project, making it better bit by bit, watching all the accumulation of changes 
                build something great over time!
                </p><p>
                I just pushed a commit to fix the story line-up. Every page now shows 5 active, 3 top, 2 new and 1 best 
                story, with the same ranking they appear at HN. If you don't care for the "mixture of stories" view and want only one
                type of story, I added links to the footer to show stories of a specific type.
                </p>
                </div>

                <div class="pt-5"><h2 class="title is-4 pt-5 mb-1">July 4, 2025</h2>
                <p>
                I wasn't particularly happy about the Active/Top/Best/New selection buttons at the top, so I removed them.
                Active stories were the only ones being shown for a few days. But it is nice to get the other kinds of stories
                into the feed as well, to round out the types of stories shown to readers.
                </p><p>
                So mixed all story types into the feed now. I think that's a much better design than having to decide which
                story type to read. Each story has a "story type icon" indicating which type it is if you care to know.
                </p>
                </div>

                <div class="pt-5"><h2 class="title is-4 pt-5 mb-1">July 1, 2025</h2>
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