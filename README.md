# Work Notes

WordPress plugin for recording and managing work notes related to client instructions and site updates within your WordPress admin.

**Version:** 1.0.5
**License:** GPL-2.0-or-later
**WordPress.org Distribution:** This plugin is prepared for submission to the WordPress.org Plugin Directory with full compliance to WordPress coding standards and security requirements.

---

## ✨ Features

- **Work Notes Management**: Record work notes linked to posts and pages
- **Master Data Management**: Manage requesters and workers with master lists
- **Status Tracking**: Track status (Requested, In Progress, Completed)
- **Date Management**: Implementation date tracking
- **List & Search**: Filter and search functionality with sortable columns
- **Admin Bar Integration**: Quick addition from WordPress admin bar
- **Gutenberg Support**: Full block editor integration with REST API support
- **Security Compliant**: Complete nonce verification and permission checking

---

## 🚀 Installation

### From WordPress.org (Recommended)
1. Navigate to WordPress Admin → Plugins → Add New
2. Search for "Work Notes"
3. Install and activate the plugin

### Manual Installation
1. Upload plugin files to `/wp-content/plugins/work-notes/` directory
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure requesters and workers in Work Notes → Settings

---

## 💻 Usage

### Basic Setup
1. **Configure Master Data**: Go to Work Notes → Settings to set up requesters and workers
2. **Create Work Notes**:
   - **Gutenberg Editor**: Use the "Work Notes" panel in the post sidebar
   - **Classic Editor**: Use the meta box below the post content
   - **Standalone**: Create work notes from Work Notes → Add New

### Work Note Fields
- **Work Title**: Brief description of the work (2-line textarea)
- **Requester**: Select from configured master data
- **Worker**: Select assigned worker
- **Status**: Requested/In Progress/Completed
- **Implementation Date**: When the work was performed
- **Target Post**: Linked post or page (when created from post editor)

---

## ⚙️ Requirements

- **WordPress**: 6.0 or higher
- **PHP**: 8.0 or higher (8.1+ recommended)
- **Tested up to**: WordPress 6.8

---

## 🔧 Development

### Development Setup

```bash
# Clone repository
git clone [repository-url] work-notes
cd work-notes

# Install dependencies
composer install

# Development workflow
vendor/bin/phpcs        # WordPress Coding Standards check
vendor/bin/phpcbf       # Auto-fix coding standards
php -l *.php           # PHP syntax check
```

### Distribution Build

```bash
# Create distribution ZIP (excludes development files)
git archive --format=zip --prefix=work-notes/ HEAD > work-notes.zip
```

The `.gitattributes` file ensures development files are excluded from distribution builds.

---

## 🔐 Security & Compliance

This plugin follows WordPress.org security guidelines:

- **Nonce Verification**: All form submissions use WordPress nonces
- **Permission Checking**: Proper `current_user_can()` checks on all endpoints
- **Data Sanitization**: All input sanitized with WordPress functions
- **Coding Standards**: Full WordPress Coding Standards (WPCS) compliance
- **Plugin Check**: Passes all WordPress Plugin Check requirements

---

## 📝 WordPress.org Submission

This plugin is specifically prepared for WordPress.org Plugin Directory submission:

- ✅ **Security Audit Complete**: All entry points secured with nonce and permission checks
- ✅ **WordPress Coding Standards**: Full WPCS compliance verified
- ✅ **Plugin Check**: All Plugin Check items cleared
- ✅ **PHP 8.x Compatibility**: auth_callback functions updated for PHP 8.x
- ✅ **Distribution Ready**: Proper `.gitattributes` configuration for clean distribution builds

The plugin has undergone comprehensive review and testing to ensure compliance with WordPress.org guidelines and is ready for submission to the official repository.

---

## 📄 License

This project is licensed under the **GPL-2.0-or-later** (GNU General Public License v2.0 or later).

- **Free Usage**: Free to use for personal and commercial purposes
- **Modification & Distribution**: Source code can be modified and redistributed
- **Copyleft**: Modified versions must be released under the same license
- **No Warranty**: Provided as-is without warranty

See the [LICENSE](LICENSE) file for full license terms.

---

## 🔗 Links

- **WordPress.org Plugin Directory**: *Pending submission*
- **GitHub Repository**: [View Source Code](https://github.com/)
- **Support**: WordPress.org support forums (after publication)