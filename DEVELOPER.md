# Work Notes - Developer Guide

This document outlines the development workflow, quality assurance procedures, build processes, and testing methodologies for the Work Notes WordPress plugin.

---

## 🛠 Development Environment Setup

### Prerequisites

* **PHP**: 8.0+ (recommended: 8.1+)
* **WordPress**: 6.0+ (tested up to 6.8)
* **Composer**: For dependency management
* **Node.js**: 16+ (for future asset compilation)
* **Git**: Version control

### Initial Setup

```bash
# Clone repository
git clone [repository-url] work-notes
cd work-notes

# Install development dependencies
composer install

# Link to WordPress installation
ln -s $(pwd) /path/to/wordpress/wp-content/plugins/work-notes

# Activate plugin in WordPress admin
```

---

## 🔍 Quality Assurance Workflow

### 1. PHP Coding Standards (PHPCS)

#### Setup PHPCS with WordPress Rules

```bash
# Install WordPress Coding Standards (if not already included)
composer require --dev squizlabs/php_codesniffer
composer require --dev wp-coding-standards/wpcs

# Configure PHPCS
vendor/bin/phpcs --config-set installed_paths vendor/wp-coding-standards/wpcs/
```

#### PHPCS Execution

```bash
# Check all PHP files against WordPress standards
vendor/bin/phpcs --standard=WordPress .

# Check specific files
vendor/bin/phpcs --standard=WordPress work-notes.php

# Check with detailed report
vendor/bin/phpcs --standard=WordPress --report=full .

# Check with summary report
vendor/bin/phpcs --standard=WordPress --report=summary .

# Auto-fix issues where possible
vendor/bin/phpcbf --standard=WordPress .
```

#### PHPCS Configuration (phpcs.xml)

The project includes a custom `phpcs.xml` configuration:

```xml
<?xml version="1.0"?>
<ruleset name="Work Notes">
    <description>WordPress Coding Standards for Work Notes Plugin</description>

    <file>.</file>

    <exclude-pattern>vendor/</exclude-pattern>
    <exclude-pattern>node_modules/</exclude-pattern>
    <exclude-pattern>*.js</exclude-pattern>
    <exclude-pattern>*.css</exclude-pattern>

    <rule ref="WordPress">
        <exclude name="WordPress.Files.FileName"/>
    </rule>

    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array" value="work-notes"/>
        </properties>
    </rule>
</ruleset>
```

### 2. WordPress Plugin Check

#### Installation and Setup

```bash
# Install Plugin Check tool
wp plugin install plugin-check --activate

# Or install via Composer (development)
composer require --dev wordpress/plugin-check
```

#### Plugin Check Execution

```bash
# Run via WP-CLI
wp plugin-check check work-notes

# Run specific checks
wp plugin-check check work-notes --checks=plugin_header,file_type

# Run with detailed output
wp plugin-check check work-notes --format=table

# Check for WordPress.org compliance
wp plugin-check check work-notes --include-experimental
```

#### Critical Plugin Check Items

- ✅ **Plugin Header Validation**: Proper plugin information
- ✅ **File Type Security**: No dangerous file types
- ✅ **Function Prefix**: Proper function naming conventions
- ✅ **Text Domain**: Correct internationalization setup
- ✅ **Security**: Nonce verification and permission checks
- ✅ **Performance**: Efficient database queries
- ✅ **Accessibility**: Proper form labels and ARIA attributes

### 3. PHP Syntax and Compatibility

```bash
# Check PHP syntax for all files
find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 -P4 php -l

# Check PHP 8.0 compatibility
vendor/bin/phpcs --standard=PHPCompatibility --runtime-set testVersion 8.0- .

# Check for PHP 8.1 specific features
vendor/bin/phpcs --standard=PHPCompatibility --runtime-set testVersion 8.1- .
```

---

## 🧪 Testing Procedures

### 1. Manual Testing Checklist

#### Core Functionality
- [ ] Work note creation in Gutenberg editor
- [ ] Work note creation in Classic editor
- [ ] Work note creation as standalone CPT
- [ ] Master data management (requesters/workers)
- [ ] Status updates and filtering
- [ ] Admin list table sorting and pagination

#### Security Testing
- [ ] Nonce verification on all forms
- [ ] Permission checks for all user roles
- [ ] Input sanitization and output escaping
- [ ] CSRF protection verification
- [ ] SQL injection prevention

#### Performance Testing
- [ ] Large dataset pagination (1000+ records)
- [ ] Database query optimization
- [ ] Asset loading performance
- [ ] Memory usage monitoring

### 2. Automated Testing (Future Implementation)

```bash
# PHPUnit setup (planned)
composer require --dev phpunit/phpunit
vendor/bin/phpunit

# WordPress testing framework integration
composer require --dev roots/wordpress
```

---

## 📦 Build and Distribution

### 1. Version Management

#### Update Version Numbers

```bash
# Update all version references
# Files to update:
# - work-notes.php (Plugin Header + OFWN_VER constant)
# - readme.txt (Stable tag)
# - CHANGELOG.md
# - package.json (if applicable)
```

#### Version Update Script (Planned)

```bash
#!/bin/bash
# update-version.sh
NEW_VERSION=$1
if [ -z "$NEW_VERSION" ]; then
    echo "Usage: ./update-version.sh 1.0.6"
    exit 1
fi

# Update plugin header
sed -i "s/Version: .*/Version: $NEW_VERSION/" work-notes.php

# Update PHP constant
sed -i "s/define('OFWN_VER', '.*')/define('OFWN_VER', '$NEW_VERSION')/" work-notes.php

# Update readme.txt
sed -i "s/Stable tag: .*/Stable tag: $NEW_VERSION/" readme.txt

echo "Version updated to $NEW_VERSION"
```

### 2. Distribution ZIP Creation

#### Standard Distribution Build

```bash
# Create distribution ZIP using git archive
git archive --format=zip --prefix=work-notes/ HEAD > work-notes-v1.0.5.zip

# Verify contents (should exclude development files)
unzip -l work-notes-v1.0.5.zip | head -20
```

#### .gitattributes Configuration

The project uses `.gitattributes` to exclude development files:

```gitattributes
# Development files excluded from distribution
/.git export-ignore
/.github export-ignore
/vendor export-ignore
/node_modules export-ignore
/composer.json export-ignore
/composer.lock export-ignore
/phpcs.xml export-ignore
*.md export-ignore
/.editorconfig export-ignore
/.gitignore export-ignore
/.gitattributes export-ignore
```

#### Distribution Verification

```bash
# Verify ZIP contents
unzip -l work-notes.zip | grep -E "(vendor|composer|\.md|\.git)" && echo "ERROR: Dev files included" || echo "OK: Clean distribution"

# Test ZIP installation
cd /tmp
unzip work-notes.zip
cp -r work-notes /path/to/test-wordpress/wp-content/plugins/
# Test activation and basic functionality
```

---

## 🚀 CI/CD Pipeline (Planned Implementation)

### GitHub Actions Workflow

#### 1. Code Quality Pipeline (.github/workflows/quality.yml)

```yaml
name: Code Quality

on: [push, pull_request]

jobs:
  phpcs:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
      - run: composer install
      - run: vendor/bin/phpcs --standard=WordPress .

  plugin-check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
      - name: Setup WordPress
        run: |
          wget https://wordpress.org/latest.tar.gz
          tar -xzf latest.tar.gz
          mv wordpress /tmp/wordpress
      - name: Run Plugin Check
        run: |
          cd /tmp/wordpress
          wp plugin install plugin-check --activate
          wp plugin-check check /github/workspace/
```

#### 2. Release Pipeline (.github/workflows/release.yml)

```yaml
name: Release

on:
  push:
    tags:
      - 'v*'

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Create distribution ZIP
        run: |
          git archive --format=zip --prefix=work-notes/ HEAD > work-notes.zip
      - name: Create GitHub Release
        uses: actions/create-release@v1
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
        with:
          tag_name: ${{ github.ref }}
          release_name: Release ${{ github.ref }}
          draft: false
          prerelease: false
      - name: Upload ZIP
        uses: actions/upload-release-asset@v1
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
        with:
          upload_url: ${{ steps.create_release.outputs.upload_url }}
          asset_path: ./work-notes.zip
          asset_name: work-notes.zip
          asset_content_type: application/zip
```

### 3. WordPress.org Deployment (SVN Integration)

```bash
# SVN setup for WordPress.org
svn co https://plugins.svn.wordpress.org/work-notes wp-org-svn

# Automated deployment script
#!/bin/bash
# deploy-to-wp-org.sh
VERSION=$1
SVN_PATH="wp-org-svn"

# Copy files to trunk
rsync -av --exclude-from='.distignore' . $SVN_PATH/trunk/

# Copy to tags
cp -r $SVN_PATH/trunk $SVN_PATH/tags/$VERSION

# SVN operations
cd $SVN_PATH
svn add --force .
svn commit -m "Deploy version $VERSION"
```

---

## 🔧 Development Tools Configuration

### 1. VS Code Settings (.vscode/settings.json)

```json
{
    "php.validate.executablePath": "/usr/bin/php",
    "phpcs.executablePath": "./vendor/bin/phpcs",
    "phpcs.standard": "WordPress",
    "files.associations": {
        "*.php": "php"
    },
    "editor.formatOnSave": true,
    "php.suggest.basic": false
}
```

### 2. Composer Scripts (composer.json)

```json
{
    "scripts": {
        "check": "phpcs --standard=WordPress .",
        "fix": "phpcbf --standard=WordPress .",
        "test": "phpunit",
        "build": "git archive --format=zip --prefix=work-notes/ HEAD > work-notes.zip"
    }
}
```

---

## 📋 Release Checklist

### Pre-Release Testing

- [ ] **PHPCS**: `vendor/bin/phpcs --standard=WordPress .` (0 errors)
- [ ] **Plugin Check**: All critical items pass
- [ ] **PHP 8.x Compatibility**: No warnings or errors
- [ ] **Manual Testing**: All core features working
- [ ] **Security Review**: All entry points secured
- [ ] **Performance Test**: No significant regressions

### Version Release Process

1. **Update version numbers** in all files
2. **Update CHANGELOG.md** with release notes
3. **Run full test suite** and quality checks
4. **Create distribution ZIP** and verify contents
5. **Tag release** in Git: `git tag v1.0.5`
6. **Push to GitHub**: `git push origin v1.0.5`
7. **Deploy to WordPress.org** (if applicable)

### Post-Release

- [ ] Monitor error logs for issues
- [ ] Update documentation if needed
- [ ] Plan next release cycle
- [ ] Monitor user feedback and support requests

---

## 🐛 Debugging and Troubleshooting

### Debug Mode Setup

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('SCRIPT_DEBUG', true);
```

### Common Issues

#### PHPCS Issues
- **Fix**: Use `vendor/bin/phpcbf` for auto-fixes
- **Check**: Verify WordPress coding standards installation

#### Plugin Check Failures
- **Security**: Ensure all forms have nonce verification
- **Performance**: Optimize database queries
- **Accessibility**: Add proper form labels

#### PHP Compatibility
- **auth_callback**: Use 6 parameters for PHP 8.x compatibility
- **register_post_meta**: Avoid duplicate registrations

---

## 📚 Additional Resources

- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
- [Plugin Handbook](https://developer.wordpress.org/plugins/)
- [WordPress.org Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [Plugin Check Documentation](https://github.com/WordPress/plugin-check)
- [PHPCS Documentation](https://github.com/squizlabs/PHP_CodeSniffer/wiki)