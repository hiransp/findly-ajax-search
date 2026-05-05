# WordPress.org Plugin Submission Guide

## Pre-Submission Checklist

Run through this before zipping and submitting:

- [ ] Version in plugin header matches `FINDLY_VERSION` constant (both `1.0.0`)
- [ ] `readme.txt` `Stable tag` matches the version (`1.0.0`)
- [ ] All `index.php` files present (root, includes/, assets/, assets/css/, assets/js/, languages/)
- [ ] `languages/findly-ajax-search.pot` file exists and is up to date
- [ ] `uninstall.php` exists and cleans up all data
- [ ] `LICENSE` file exists (GPL-2.0)
- [ ] No `.zip` files in the repo
- [ ] No debug/console.log statements in JS
- [ ] No `var_dump`, `print_r`, `error_log` calls in PHP (except the intentional security logging)
- [ ] All user-facing strings use i18n functions with text domain `findly-ajax-search`
- [ ] Test: activate plugin without WooCommerce — dismissible admin notice appears
- [ ] Test: activate plugin with WooCommerce — settings page works under WooCommerce menu
- [ ] Test: shortcode `[findly_ajax_search]` renders search box on frontend
- [ ] Test: search returns results and preview panel works
- [ ] Test: mobile overlay mode works on small screens
- [ ] Test: uninstall removes all `findly_` options and transients from `wp_options`

## Creating the Submission ZIP

```bash
# From the plugin root directory:
make clean && make zip
```

This creates `findly-ajax-search.zip` with the correct structure:

```
findly-ajax-search/
├── findly-ajax-search.php
├── readme.txt
├── uninstall.php
├── index.php
├── LICENSE
├── includes/
│   ├── index.php
│   ├── class-search-handler.php
│   ├── class-settings.php
│   └── class-shortcode.php
├── assets/
│   ├── index.php
│   ├── css/
│   │   ├── index.php
│   │   ├── ajax-search.css
│   │   └── admin-settings.css
│   └── js/
│       ├── index.php
│       └── ajax-search.js
└── languages/
    ├── index.php
    └── findly-ajax-search.pot
```

## Submitting to WordPress.org

1. Go to https://wordpress.org/plugins/developers/add/
2. Upload `findly-ajax-search.zip`
3. Wait for the automated checks to pass
4. The Plugin Review Team will manually review (typically 1-5 business days, can be longer)
5. You'll receive email updates at your wordpress.org account email

## What to Expect from the Review

- Reviewers check for security issues (XSS, SQL injection, CSRF, capability checks)
- They check for proper escaping/sanitization of all I/O
- They verify GPL compatibility
- They check that the plugin doesn't include obfuscated code
- They may request changes — respond promptly to keep your place in the queue
- After approval, you get SVN access to your plugin's repository

## After Approval — SVN Setup

WordPress.org plugins use SVN (not Git). After approval you'll receive SVN credentials.

```bash
# Check out your plugin's SVN repo
svn co https://plugins.svn.wordpress.org/findly-ajax-search/ svn-findly-ajax-search
cd svn-findly-ajax-search

# Copy plugin files to trunk/
cp -r /path/to/findly-ajax-search/* trunk/
# (exclude: .git, .github, .gitignore, .distignore, Makefile, CLAUDE.md, README.md, PRE_SUBMISSION.md, *.zip)

# Add all files
svn add trunk/* --force

# Create the first tag
svn cp trunk tags/1.0.0

# Add assets for the plugin directory page (banners, icons, screenshots)
# These go in the assets/ directory at the SVN root (NOT inside trunk/)
# svn add assets/banner-772x250.png
# svn add assets/icon-128x128.png
# svn add assets/screenshot-1.png
# etc.

# Commit everything
svn ci -m "Initial release 1.0.0"
```

## SVN Assets Directory

The `assets/` directory at the SVN root (NOT in the plugin zip) contains branding for the wordpress.org listing:

| File | Size | Purpose |
|------|------|---------|
| `banner-772x250.png` | 772x250px | Plugin page banner |
| `banner-1544x500.png` | 1544x500px | Retina banner |
| `icon-128x128.png` | 128x128px | Plugin icon |
| `icon-256x256.png` | 256x256px | Retina icon |
| `icon.svg` | any | Vector icon (preferred over PNGs) |
| `screenshot-1.png` | any | Matches `== Screenshots ==` in readme.txt |
| `screenshot-2.png` | any | Second screenshot |
| ... | ... | Additional screenshots |

These are **not** included in the plugin zip — they live only in the SVN `assets/` directory.

## Releasing Updates

```bash
cd svn-findly-ajax-search

# Update trunk/ with new files
# Update version in: findly-ajax-search.php (header + constant), readme.txt (Stable tag)

# Create a new tag
svn cp trunk tags/1.1.0

# Commit
svn ci -m "Release 1.1.0 — description of changes"
```

The `Stable tag` in `readme.txt` determines which tag wordpress.org serves to users.
