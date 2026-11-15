# Magento 2 Media Orphan Finder

A Magento 2 module by Shubham Kanungo to find and safely clean up orphaned product media files (images that exist on the server but are no longer linked to any products in the database).

## Features
* Scans the `pub/media/catalog/product` filesystem and compares it against active database image paths.
* Calculates total reclaimable space.
* **Dry-run by default:** Safely audits files without deleting anything.
* **Quarantine:** Moves orphaned files to a safe `var/orphan_media_quarantine` directory instead of hard-deleting them, giving you a chance to review them.

### Module CMD

php bin/magento media:orphan:clean                 // Run a Dry-Run Audit
php bin/magento media:orphan:clean --list          // View a Detailed List of Orphans
php bin/magento media:orphan:clean --quarantine   // Safely Quarantine Orphaned Files

## Installation

### Method 1: Composer (Recommended)
```bash
composer require shubhamkanungoo007/module-media-orphan-finder
php bin/magento module:enable Shubham_MediaOrphanFinder
php bin/magento setup:upgrade
php bin/magento cache:flush
