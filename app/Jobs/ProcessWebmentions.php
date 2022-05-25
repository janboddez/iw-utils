<?php

namespace App\Jobs;

use App\Models\Comment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProcessWebmentions extends Job
{
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $webmentions = DB::select('SELECT * FROM webmentions WHERE status = ? ORDER BY created_at ASC LIMIT 5', ['new']);

        if (empty ($webmentions)) {
            return;
        }

        $context = stream_context_create(['http' => [
            'follow_location' => true,
            'ignore_errors' => true, // Don't choke on HTTP (4xx, 5xx) errors.
            'timeout' => 15,
        ]]);

        foreach ($webmentions as $webmention) {
            $html = Cache::remember('html:'.$webmention->source, 3600, function () use ($webmention) {
                return file_get_contents($webmention->source, false, $context);
            });

            if (strpos($html, $webmention->target) === false) {
                DB::update('UPDATE webmentions SET status = ? WHERE id = ?', ['invalid', $webmention->id]);
                continue;
            }

            $data = [
                'author' => 'Anonymous',
                'website' => parse_url($webmention->source, PHP_URL_SCHEME) . '://' . parse_url($webmention->source, PHP_URL_HOST),
                'content' => '&hellip; mentioned this!',
                'type' => 'mention',
                'published' => date('Y-m-d H:i:s'),
            ];

            // Parse in any microformats.
            $this->parseMicroformats($data, $html, $webmention);

            $comment = Comment::updateOrCreate([
                'path' => parse_url($webmention->target, PHP_URL_PATH),
                'source' => $webmention->source,
            ], $data); // Won't actually update if it hasn't changed.

            if ($comment->wasRecentlyCreated) {
                $status = 'created';
            } elseif ($comment->wasChanged()) {
                $status = 'updated';
            } else {
                /* @todo: Investigate how to easily log dupes. (This will never run.) */
                $status = 'duplicate';
            }

            DB::update('UPDATE webmentions SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $webmention->id]);
        }
    }

    protected function parseMicroformats(array &$data, string $html, object $webmention): void
    {
        $mf = \Mf2\parse($html, $webmention->source);

        if (empty($mf['items'][0]['type'][0])) {
            // No relevant microformats found. Leave `$comment` untouched.
            return;
        }

        if ($mf['items'][0]['type'][0] === 'h-entry') {
            // Topmost item is an h-entry. Let's try to parse it.
            $this->parseHentry($data, $mf['items'][0], $webmention);
            return;
        } elseif ($mf['items'][0]['type'][0] === 'h-feed') {
            // Topmost item is an h-feed.
            if (empty($mf['items'][0]['children'])) {
                return;
            }

            if (! is_array($mf['items'][0]['children'])) {
                return;
            }

            // Loop through its children, and parse (only) the first h-entry we
            // encounter.
            foreach ($mf['items'][0]['children'] as $child) {
                if (empty($child['type'][0])) {
                    continue;
                }

                if ($child['type'][0] !== 'h-entry') {
                    continue;
                }

                $this->parseHentry($data, $child, $webmention);
                return;
            }
        }
    }

    protected function parseHentry(array &$data, array $hentry, object $webmention): void
    {
        // Update author name.
        if (! empty($hentry['properties']['author'][0]['properties']['name'][0])) {
            $data['author'] = $hentry['properties']['author'][0]['properties']['name'][0];
        }

        // Update author URL.
        if (! empty($hentry['properties']['author'][0]['properties']['url'][0])) {
            $data['website'] = $hentry['properties']['author'][0]['properties']['url'][0];
        }

        // Update comment datetime.
        if (! empty($hentry['properties']['published'][0])) {
            $data['published'] = date('Y-m-d H:i:s', strtotime($hentry['properties']['published'][0]));
        }

        $postType = 'mention';

        if (! empty($hentry['properties']['in-reply-to']) && in_array($webmention->target, (array) $hentry['properties']['in-reply-to'], true)) {
            $postType = 'reply';
        }

        if (! empty($hentry['properties']['repost-of']) && in_array($webmention->target, (array) $hentry['properties']['repost-of'], true)) {
            $postType = 'repost';
        }

        if (! empty($hentry['properties']['bookmark-of']) && in_array($webmention->target, (array) $hentry['properties']['bookmark-of'], true)) {
            $postType = 'bookmark';
        }

        if (! empty($hentry['properties']['like-of']) && in_array($webmention->target, (array) $hentry['properties']['like-of'], true)) {
            $postType = 'like';
        }

        // Temporarily store unaltered content.
        $content = $data['content'];

        // Overwrite default content based on post type.
        switch ($postType) {
            case 'bookmark':
                $content = '&hellip; bookmarked this!';
                break;

            case 'like':
                $content = '&hellip; liked this!';
                break;

            case 'repost':
                $content = '&hellip; reposted this!';
                break;

            case 'mention':
            case 'reply':
            default:
                if (! empty($hentry['properties']['content'][0]['value']) && mb_strlen($hentry['properties']['content'][0]['value'], 'UTF-8') <= config('max_length', 500)
                 && ! empty($hentry['properties']['content'][0]['html'])) {
                    // If the mention is short enough, store it in its entirety.
                    $content = strip_tags($hentry['properties']['content'][0]['html']);
                } else {
                    // Fetch the bit of text surrounding the link to our page.
                    $context = $this->fetchContext($hentry['properties']['content'][0]['html'], $webmention->target);

                    if (! empty($context)) {
                        // Found context, now store it.
                        $content = $context;
                    } elseif (! empty($hentry['properties']['content'][0]['html'])) {
                        // Simply store an excerpt of the webmention source.
                        $content = Str::words(strip_tags($hentry['properties']['content'][0]['html']), 25, ' &hellip;');
                    }
                }
        }

        $data['content'] = $content;
        $data['type'] = $postType;
    }

    /**
     * Looks for a link to `$target`, and returns some of the text surrounding
     * it.
     *
     * Lifted pretty much straight from WordPress.
     */
    protected function fetchContext(string $html, string $target): string
    {
        // Work around bug in `strip_tags()`.
        $html = str_replace('<!DOC', '<DOC', $html);
        $html = preg_replace('/[\r\n\t ]+/', ' ', $html);
        $html = preg_replace('/<\/*(h1|h2|h3|h4|h5|h6|p|th|td|li|dt|dd|pre|caption|input|textarea|button|body)[^>]*>/', "\n\n", $html);

        // Remove all script and style tags, including their content.
        $html = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $html);
        // Just keep the tag we need.
        $html = strip_tags($html, '<a>');

        $p = explode("\n\n", $html);

        $preg_target = preg_quote($target, '|');

        foreach ($p as $para) {
            if (strpos($para, $target) !== false) {
                preg_match('|<a[^>]+?' . $preg_target . '[^>]*>([^>]+?)</a>|', $para, $context);

                if (empty($context)) {
                    // The URL isn't in a link context; keep looking.
                    continue;
                }

                // We're going to use this fake tag to mark the context in a
                // bit. The marker is needed in case the link text appears more
                // than once in the paragraph.
                $excerpt = preg_replace('|\</?wpcontext\>|', '', $para);

                // Prevent really long link text.
                if (mb_strlen($context[1]) > 100) {
                    $context[1] = mb_substr($context[1], 0, 100) . '&#8230;';
                }

                $marker = '<wpcontext>' . $context[1] . '</wpcontext>'; // Set up our marker.
                $excerpt = str_replace($context[0], $marker, $excerpt);  // Swap out the link for our marker.
                $excerpt = strip_tags($excerpt, '<wpcontext>');          // Strip all tags but our context marker.
                $excerpt = trim($excerpt);
                $preg_marker = preg_quote($marker, '|');
                $excerpt = preg_replace("|.*?\s(.{0,200}$preg_marker.{0,200})\s.*|s", '$1', $excerpt);
                $excerpt = strip_tags($excerpt);

                break;
            }
        }

        if (empty($context)) {
            // Link to target not found.
            return '';
        }

        return '[&#8230;] '.e($excerpt).' [&#8230;]';
    }
}
