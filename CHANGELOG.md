# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.2.2] - 2026-10-03

### Fixed
- Reindex Selected: when some indexers are queued and others run straight away, the summary reports both, for example "2 succeeded, 1 queued, 0 failed.", instead of listing only the queued ones.
