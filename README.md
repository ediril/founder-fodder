# How to run the site locally
```
php -S localhost:8000
```

# How to fetch new content from HN
TODO

# How to summarize new content
TODO

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
