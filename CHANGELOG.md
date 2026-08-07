# Release Notes for Elasticsearch Plugin

## 2.1.0

> {note} This release changes the index mapping. After updating, rebuild your indexes (create → reindex → alias-swap) so they pick up the current mapping schema; drift detection flags out-of-date indexes.

### Added

- Output the source alias and index when cloning an index.
- The console command to list aliases and indexes indicates whether an index is orphaned.
- The console command to show the source of an element outputs the analyzer result as well.
- Event to manipulate the search parameters before querying Elasticsearch.
- Console command `elastic/index/query` to run a search query against an index and output the raw Elasticsearch response with per-hit score explanations (`explain: true`). Takes a search string and an optional result limit.
- `scoring` setting with per-tier relevance weights (editable on the plugin settings page; optional per-field overrides via `config/elastic.php`), and a `matchBoolPrefix` setting to override the automatic prefix-match capability detection.
- `elastic/migration` (invoked without an action) now lists the available migration commands instead of failing with "Unknown command", and works without a configured endpoint.
- Console commands `elastic/index/export` and `elastic/index/import` to write the current index of a site to an NDJSON file and recreate it on another cluster. The export runs through the configured connection, so an AWS OpenSearch domain is exported with the usual IAM credentials. The index is recreated exactly as exported, including its mapping version; run `elastic/index/reindex` afterwards to lift it to the current schema. Note that searches are filtered against the element IDs the Craft query returns, so an imported index is only useful together with the matching Craft database.

### Changed

- The console command to list aliases and indexes return only relevant entries of the current config now. Added the option `--all` to show all available aliases and indexes again. 
- The search analyzer, including the language-aware stopword list, is now defined in the index-level `default` slot instead of a named analyzer, and the `analyzer` parameter was removed from all field mappings. Existing indexes keep working unchanged and no data is at risk; the stopword handling only takes effect on indexes created after this update. Drift detection reports pre-existing indexes as `outdated` — rebuild them via the normal create → reindex → alias-swap flow to pick up the new behaviour. Until rebuilt, search behaviour may differ between index generations on the same plugin version.
- Every text field now has an additive `.exact` keyword subfield (with a lowercasing normalizer) used for exact, whole-value matching and relevance scoring. Existing indexes keep working, but the exact tier only populates after a rebuild; drift detection reports pre-existing indexes as `outdated`.
- Search scoring is now built from Craft's per-term flags (exact, phrase, sub-word, exclude, attribute) as distinct query clauses instead of a single wildcard `query_string`. Results are ranked by relevance — an exact whole-value match ranks highest — rather than every hit scoring `1`, and OR groups and term exclusion are now handled. Result sets for plain terms are unchanged (only the ordering changes); terms using an exact/attribute flag or a leading `*` may return fewer, more correct results than before. The exact tier requires a rebuilt index.
- The `authentication` connection setting can now be overridden with an environment variable (resolved at runtime, like the other connection settings). Its settings-page field is now an autosuggest input offering the known methods (`none`, `basicauth`, `aws`) and environment variables, replacing the fixed dropdown.
- The plugin settings page is now organised into General, Boosting and Scoring tabs. The former "Tuning" section is now labelled "Boosting".
- Every text field now copies into two catch-all fields, and searches match those instead of a wildcard over all fields, which keeps the number of query clauses independent of how many searchable fields exist. Existing indexes are reported as `outdated` by drift detection and keep being queried field by field until they are rebuilt — their documents carry no catch-all content, so they would otherwise stop returning results. Relevance may shift slightly after a rebuild, because scores are computed on the combined content rather than per field; configured field boosts and per-field scoring weights still apply as separate clauses, and attribute-scoped searches (`title:foo`, `title::foo`) are unaffected.

### Fixed

- Pasted slugs now find the corresponding element.
- Mapping updates no longer fail with an HTTP 400 `illegal_argument_exception` on indexes created by an earlier version. Field mappings no longer send an `analyzer` parameter, which the cluster treats as "leave unchanged" rather than an (illegal) change to an existing field's analyzer.
- Elasticsearch console commands now fail with a clear "no endpoint configured" message and a non-zero exit code instead of an opaque "Unknown component ID: indexes" error when no endpoint is set.
- Fixed a typo in the `elastic/migration/truncate-table` command output.
- The plugin settings page no longer emits a PHP warning when no custom scoring weights are configured.
- Front-end searches no longer error when the Elasticsearch cluster is unreachable; they return no matches and the failure is logged.
- Console commands now report a clear message and a non-zero exit code instead of a stack trace when the cluster is unreachable or the connection is misconfigured.
- The "Elasticsearch Indexes" utility no longer errors out when the cluster is unreachable.
- Saving elements or fields no longer fails when the queue is unavailable; indexing is queued best-effort and failures are logged.
- Console commands now report unreadable or malformed files with a clear message instead of a stack trace.
- `elastic/elements/index` no longer counts and queues elements that never end up in the index. The count included every revision, draft, soft-deleted and archived row of the elements table, so it reported far more elements than were indexed and pushed hundreds of thousands of jobs that indexed nothing.
- `elastic/elements/index` now resolves the site handle to a site ID. The handle was passed to the element query as-is, which silently matched no elements at all, so indexing a single site indexed nothing.
- Drafts are no longer written to the index by `elastic/elements/index`. The after-save handler always skipped them, so the console command put content into the index that the regular indexing path never writes.
- Searches with more than one term no longer fail with `too_many_nested_clauses` on installations with many searchable fields. The query matched fields through a wildcard, which Elasticsearch expands to one clause per field (two, counting the `.exact` subfields), so the clause count grew with the number of fields and exceeded Lucene's limit of 1024 — with around 120 searchable fields a two-term search was already over it.

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
