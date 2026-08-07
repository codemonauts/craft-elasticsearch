# Release Notes for Elasticsearch Plugin

## Unreleased

### Added

- Output the source alias and index when cloning an index.
- The console command to list aliases and indexes indicates whether an index is orphaned.
- The console command to show the source of an element outputs the analyzer result as well.
- Event to manipulate the search parameters before querying Elasticsearch.

### Changed

- The console command to list aliases and indexes return only relevant entries of the current config now. Added the option `--all` to show all available aliases and indexes again. 
- The search analyzer, including the language-aware stopword list, is now defined in the index-level `default` slot instead of a named analyzer, and the `analyzer` parameter was removed from all field mappings. Existing indexes keep working unchanged and no data is at risk; the stopword handling only takes effect on indexes created after this update. Drift detection reports pre-existing indexes as `outdated` — rebuild them via the normal create → reindex → alias-swap flow to pick up the new behaviour. Until rebuilt, search behaviour may differ between index generations on the same plugin version.

### Fixed

- Pasted slugs now find the corresponding element.
- Mapping updates no longer fail with an HTTP 400 `illegal_argument_exception` on indexes created by an earlier version. Field mappings no longer send an `analyzer` parameter, which the cluster treats as "leave unchanged" rather than an (illegal) change to an existing field's analyzer.

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
