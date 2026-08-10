# Release Notes for Elasticsearch Plugin

## 3.0.0 - 2026-08-10

### Added

- Craft CMS 5 compatibility.

### Changed

- Requires Craft CMS 5 and PHP 8.2 or later.

## 2.1.0 - 2026-08-08

> {note} This release changes the index mapping. After updating, rebuild your indexes (reindex command) so they pick up the current mapping schema; drift detection flags out-of-date indexes.

### Added

- Output the source alias and index when cloning an index.
- The alias and index listing marks orphaned indexes.
- The element source command outputs the analyzer result as well.
- Event to manipulate the search parameters before querying Elasticsearch.
- Console command `elastic/index/query` to run a search against an index and output the raw response with per-hit score explanations.
- `scoring` setting with per-tier relevance weights, editable on the settings page with per-field overrides via `config/elastic.php`, and a `matchBoolPrefix` setting to override the automatic prefix-match detection.
- `elastic/migration` without an action lists the available migration commands.
- Console commands `elastic/index/export` and `elastic/index/import` to write a site's index to an NDJSON file and recreate it on another cluster, through the configured connection (including AWS IAM). An imported index only works together with the matching Craft database.

### Changed

- The alias and index listing only shows entries of the current config; `--all` restores the full listing.
- The search analyzer, including the language-aware stopword list, moved to the index-level `default` slot, and field mappings no longer set an `analyzer`. Stopword handling only applies to indexes created after this update.
- Every text field has an additive `.exact` keyword subfield (lowercased, `ignore_above` 256) used for exact, whole-value matching and scoring.
- Search scoring is built from Craft's per-term flags as distinct clauses instead of a single wildcard `query_string`, so results are ranked by relevance and OR groups and term exclusion are handled. Terms with an exact/attribute flag or a leading `*` may return fewer, more correct results.
- The `authentication` setting can be overridden with an environment variable. Its settings field is now an autosuggest offering the known methods (`none`, `basicauth`, `aws`) and environment variables, replacing the fixed dropdown.
- The plugin settings page is organised into General, Boosting and Scoring tabs. The former "Tuning" section is now labelled "Boosting".
- Text fields copy into two catch-all fields, which searches match instead of a wildcard over all fields, so the clause count no longer grows with the number of fields. Relevance may shift slightly after a rebuild; field boosts and attribute-scoped searches are unaffected.

### Fixed

- Pasted slugs now find the corresponding element.
- Mapping updates no longer fail with an HTTP 400 `illegal_argument_exception` on indexes created by an earlier version.
- Console commands report a clear message and a non-zero exit code — for a missing endpoint, an unreachable cluster, a misconfigured connection or an unreadable export file — instead of an "Unknown component ID" error or a stack trace.
- Front-end searches no longer error when the cluster is unreachable; they return no matches and log the failure.
- The "Elasticsearch Indexes" utility no longer errors out when the cluster is unreachable.
- Saving elements or fields no longer fails when the queue is unavailable; indexing is best-effort and failures are logged.
- Fixed a typo in the `elastic/migration/truncate-table` command output.
- The settings page no longer emits a PHP warning when no custom scoring weights are configured.
- `elastic/elements/index` no longer counts and queues revisions, drafts, soft-deleted and archived rows, which reported far more elements than were indexed and queued jobs that indexed nothing.
- `elastic/elements/index` resolves the site handle to a site ID; passing a handle previously matched no elements at all.
- Drafts are no longer written to the index by `elastic/elements/index`, matching the after-save handler.
- Searches with more than one term no longer fail with `too_many_nested_clauses` on installations with many searchable fields.

## 2.0.0 - 2022-06-15

### Added

- Craft CMS 4 compatibility
- Indexation of elements can be done without using jobs and queues.

### Changed

- Requires Craft CMS >= 4.0

## 1.1.0 - 2022-04-27

### Added

- Added console command to list all aliases and indexes of the configured Elasticsearch cluster.
- Added workflow to switch back to Craft's database index.
- Added console command to truncate Craft's database index table.
- Field boosting.

### Fixed

- Utility page handles not existing index.
- Let Elasticsearch filter and tokenize the keywords.

## 1.0.0 - 2022-04-08

### Added

- Initial release
