# Panth_IndexerManager

Reindex Magento 2 from the admin with strategy options (synchronous or queue) and per-indexer live tracking, status history, and run logs.

## Features (v1.0.0 — basic scaffold)

- Module enable / disable toggle
- Reindex strategy selector (Standard, Queue)
- Live run tracking config (retention, failures-only)
- Failure email notification config
- Admin menu under **Panth Extensions → Indexer Manager**
  - Dashboard
  - Run Log
  - Configuration

Subsequent releases add the dashboard grid, run-log grid, mass actions, queue consumer, and observers that capture indexer events.

## Requirements

- PHP 8.1 – 8.4
- Magento 2.4.x
- `mage2kishan/module-core`

## Installation

### From Packagist (after release)

```
composer require mage2kishan/module-indexer-manager
bin/magento module:enable Panth_IndexerManager
bin/magento setup:upgrade
```

### From local path (during development)

Add a path repository to the project `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "packages/module-indexer-manager", "options": { "symlink": true } }
],
"require": {
    "mage2kishan/module-indexer-manager": "*"
}
```

Then `composer update mage2kishan/module-indexer-manager` and run `setup:upgrade`.

## Admin paths

- Menu: `Panth Extensions → Indexer Manager`
- Config: `Stores → Configuration → Panth → Indexer Manager`
- ACL: `Panth_IndexerManager::indexer_manager`
