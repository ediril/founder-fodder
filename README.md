# How to run the site locally
```
$ php -S localhost:8000
```

# How to fetch new content from HN
The following fetches story urls and metadata:
```
$ php fetch.php --type=top --limit=50
```

# How to summarize story and comments
The following will first download and store the text for the story and its comments, then send them to AI to be summarized
```
$ php summarize.php --type=top --limit=50
```

## To debug:
- Start the "Listen for Xdebug" session in VS Code (select "Listen for Xdebug" and press F5).
- Then, from your terminal, run your PHP script with the desired CLI arguments and Xdebug environment variables, like so:
```
php <script.php> [args]
```

# How to deploy new content
TODO

# How to deploy code changes
TODO

# How to init submodules after clone
```
git submodule update --init --recursive
```

# How to update git submodules
```
git submodule update --remote
```

# How to push changes to submodule's repo:
- Commit the change to the submodule
- `git push origin HEAD:main`
- Stage the submodule to update its reference in the parent project
