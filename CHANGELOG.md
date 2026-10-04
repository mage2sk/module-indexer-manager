# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.2.3] - 2026-10-04

### Fixed
- Index Management: live polling no longer replaces the native "Updated" dates with raw UTC values. "Updated", "Last Tracked Run", the details modal and the Run Log now show dates in the admin locale format and the store timezone.
- Index Management: clicking a row "Reindex" or "View" button or a "Mode" cell no longer ticks or unticks the row checkbox, so "Reindex Selected" only runs the rows you selected.
- Index Management: the details modal moves focus to its close button, keeps Tab inside the dialog and returns focus to the "View" button when closed; the "Mode" toggle is a real button that works with Enter and Space; action messages are announced to screen readers.
- Run Log: durations such as 119.6 seconds show as "2m 0s" instead of "1m 60s", and a page number past the end shows the last page instead of the first.
- Configuration: "Notification Email" accepts the documented comma-separated list; "Log Failures Only", "Log Retention (days)" and "Notification Email" are hidden while "Enable Indexer Manager" is No; the "Enable Indexer Manager" comment describes what the setting does.
- Run tracking: `bin/magento indexer:reindex` runs are logged with context `cli` instead of `admin`.
- The "Queue (deferred)" strategy is ignored while "Enable Indexer Manager" is No, matching the hidden setting.

### Added
- Run Log: keyword search (indexer, message, admin user), Indexer, Status and Context filters, sortable columns and an empty state for filters with no matches.
