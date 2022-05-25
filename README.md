# iw-utils
IndieWeb utilities

## Installation
Clone the repository. Run `composer install --no-dev`.

Copy `.env.example` to just `.env`. In it, update the `APP_*` constants.

Add the following, too:
```
SITE_URL=https://example.org
SITE_PAGE_LIST=/path/to/a/list/of/all/valid/site/URLs
```

Ensure (use a cron job) that `php artisan schedule:run` is called every minute.

(For now) `SITE_PAGE_LIST` should contain all possible Webmention targets, separated by newline characters. (The last page, too, must be followed by `"\n"`.)

Say you're running this at `https://iw.example.org`. Folks can now send webmentions to `https://iw.example.org/webmention`. If their source pages link to one of the pages in `SITE_PAGE_LIST` (and start with `https://example.org`), you should see the database fill up.
