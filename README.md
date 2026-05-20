# Hexa PR Wire Publication Sync

This plugin adds a publication-side control layer around Echo RSS.

What it does:

- Detects the active Hexa PR Wire Echo rule on the publication.
- Exposes a secret public job URL for `refetch`, `update`, and `delete`.
- Returns JSON immediately with `job_id`, `status_url`, and `stream_url`.
- Streams live progress with server-sent events, including Echo log output.
- Documents the exact endpoints and payloads in `wp-admin`.

Default workflow:

1. Call `/wp-json/hexa-pr-wire/v1/jobs?token=...&action=refetch`
2. Read the returned JSON.
3. Subscribe to the returned `stream_url`.
4. Poll or open the returned `status_url` for the final structured result.

Important notes:

- `post_ids` are local publication post IDs.
- `slugs` are upstream Hexa PR Wire slugs.
- `update` defaults to `feed_action=reprocess-all` when targets are provided, so older items can be refreshed too.
- The plugin wraps Echo's existing rule runner instead of replacing it.

Core endpoints:

- `POST|GET /wp-json/hexa-pr-wire/v1/jobs`
- `GET /wp-json/hexa-pr-wire/v1/jobs/<job_id>`
- `GET /wp-json/hexa-pr-wire/v1/jobs/<job_id>/stream`

Required query parameter:

- `token=<secret token from Settings > Hexa PR Sync>`

Job actions:

- `action=refetch`
- `action=update`
- `action=delete`

Optional parameters:

- `feed_action=reprocess-all`
- `feed_action=reprocess-old`
- `feed_url=https://hexaprwire.com/?feed=rss_publication&publication=<slug>`
- `slugs=slug-one,slug-two`
- `source_urls=https://hexaprwire.com/post-one,https://hexaprwire.com/post-two`
- `post_ids=123,456`
- `dry_run=1`
- `hard_delete=1`

Examples:

- `https://example.com/wp-json/hexa-pr-wire/v1/jobs?token=TOKEN&action=refetch`
- `https://example.com/wp-json/hexa-pr-wire/v1/jobs?token=TOKEN&action=refetch&dry_run=1`
- `https://example.com/wp-json/hexa-pr-wire/v1/jobs?token=TOKEN&action=update&slugs=example-slug`
- `https://example.com/wp-json/hexa-pr-wire/v1/jobs?token=TOKEN&action=delete&slugs=example-slug&dry_run=1`

Response model:

- Create-job response returns `job_id`, `status_url`, `stream_url`, `action`, `dry_run`, and detected `rule`.
- Status response returns the full job object, including `state`, `status`, `errors`, `logs`, and `result`.
- Stream response emits server-sent events:
  - `status`
  - `log`
  - `summary`

Result fields:

- `feed_items_discovered`
- `new_source_urls`
- `new_live_urls`
- `updated_source_urls`
- `updated_live_urls`
- `unchanged_source_urls`
- `missing_targets`
- `last_url_processed`
- `up_to_date`

Operational assumptions:

- The site has Echo RSS active.
- The publication import rule already points to a Hexa PR Wire `rss_publication` feed.
- Imported posts store Echo metadata such as `echo_post_full_url`, `echo_post_url`, `original_post_slug`, or `original_post_url`.
- `post_ids` always refer to local publication post IDs, not upstream Hexa PR Wire post IDs.

Deployment notes:

- Activate the plugin on the publication site.
- Open `Settings > Hexa PR Sync`.
- Save or rotate the secret token as needed.
- Confirm the detected Echo rule and upstream host.
- Use the built-in test form or call the REST endpoints directly.

Changelog:

- `1.0.2`
  - Added first-load bootstrap so fresh installs self-create their token and job index.
  - Verified cross-site install workflow against `peresdaily.com`.
- `1.0.1`
  - Added async publication control plane for Hexa PR Wire imports.
  - Added JSON job creation and status endpoints.
  - Added SSE progress streaming with live status and summary output.
  - Added refetch, update, and delete orchestration around Echo RSS.
  - Added dry-run reporting, rule auto-detection, and upstream feed validation.
  - Added operator documentation in the WordPress admin and this README.
