# How to run
```
php -S localhost:8000
```

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
