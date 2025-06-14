
- "Active" tab: based on timestamp of most recent comment sorting stories

- Extract mentioned links in the comments and put them in the summaries

- Create a story endpoint i can share as a link on social media

- Make a podcast from the summaries

- Put estimates of "x min read" both on the story cards and comments

- Check if comments are getting updated every 6 hours within 24 hours

- Fetcher: We can do the fetch and store in one go

- Summarizer: Delete stories, comments and summaries if they were fetched over 1 month ago

- Use HEAD to fetch an image for the url
    The <head> section of a web page typically contains metadata, including references to images such as:
    - Favicons (<link rel="icon" ...>)
    - Open Graph images (<meta property="og:image" ...>)
    - Twitter card images (<meta name="twitter:image" ...>)

- Use "rank" instead of sql offset for pagination

- If there is an archive link in the comments (e.g. https://archive.ph/P2cU1), use it to download the story content (otherwise you will get 403 from the original story url)

