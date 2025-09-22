=== Work Notes ===
Contributors: netservice
Tags: notes, workflow, task-management, admin, gutenberg
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 1.0.5
Requires PHP: 8.0
License: GPL-2.0-or-later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

A WordPress plugin for recording and managing work notes related to client instructions and site updates within your WordPress admin.

== Description ==

Work Notes allows you to record and manage work notes linked to posts and pages with requester/worker master management functionality.

= Key Features =

* Record work notes related to posts and pages
* Master management for requesters and workers
* Status management (Requested, In Progress, Completed)
* Implementation date management
* List display and filtering search (sortable by requester/worker columns)
* Quick addition from admin bar
* Gutenberg block editor integration
* REST API support via register_post_meta
* Complete data cleanup on uninstall

= Use Cases =

* Project management for web development agencies
* Recording site maintenance and update tasks
* Progress tracking for client requests

= WordPress.org Distribution =

This plugin is prepared for distribution on WordPress.org Plugin Directory with full compliance to WordPress coding standards and security requirements.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/work-notes` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Work Notes screen to configure the plugin
4. Set up requesters and workers in Settings (recommended)

== Frequently Asked Questions ==

= What user permissions are required? =

Users with Editor role or higher can use this plugin. Settings screen is accessible only to administrators.

= Where is the data stored? =

Data is stored as WordPress custom post type in wp_posts table, with related information in wp_postmeta table.

= What happens to data when the plugin is deleted? =

All work note data is automatically deleted when the plugin is uninstalled.

== Screenshots ==

1. Work Notes list screen - Filter and search by status and requesters
2. Work Note edit screen - Record detailed information including target post, requester, worker
3. Post edit screen with Work Notes display - View and add related work notes

== Changelog ==
= 1.0.5 =
* Security audit and review response completed
* Fixed auth_callback parameter count for PHP 8.x compatibility (4→6 parameters)
* Resolved register_post_meta duplicate registration issues
* Optimized pagination processing with WP_Query implementation
* WordPress Coding Standards (WPCS) full compliance
* Plugin Check all items cleared

= 1.0.4 =
* WordPress.org distribution preparation completed
* Removed independent update mechanism
* Removed distribution endpoint functionality

= 1.0.3 =
* Version update for Plugin Checker compatibility

= 1.0.2 =
* Unified version number to 1.0.2 (full Semantic Versioning compliance)

= 1.00 =
* Work log reminder (Snackbar) functionality implementation
* Multi-user target setting functionality
* Revision-based duplicate prevention
* Added requester and worker columns to admin list
* Column sorting functionality implementation
* REST API support via register_post_meta
* Complete data deletion in uninstall.php
* Enhanced XSS protection and localization improvements
* Asset loading optimization
* WordPress Coding Standards full compliance

= 0.05 =
* Enhanced i18n support
* Security and performance improvements
* WordPress Coding Standards compliance
* Autoload optimization and asset loading improvements
* Enhanced SQL injection protection

= 0.04 =
* Initial release
* Basic work notes functionality
* Master management functionality

== Upgrade Notice ==

= 1.0.5 =
Security audit response and PHP 8.x compatibility fixes. Update recommended.

= 0.05 =
Important update including security enhancements and internationalization support. Update recommended.