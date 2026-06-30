# OpenAlex Team Publications

[![WordPress Plugin](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPLv2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A WordPress plugin that integrates the `team` Custom Post Type (from TLP Team) with the OpenAlex API to automatically import and manage academic publications for research team members, storing them in teachPress.

## 📋 Table of Contents

- [Features](#-features)
- [Requirements](#-requirements)
- [Installation](#-installation)
- [Configuration](#-configuration)
- [Usage](#-usage)
- [Architecture](#-architecture)
- [OpenAlex API](#-openalex-api)
- [Synchronization](#-synchronization)
- [Frontend](#-frontend)
- [Migration Tools](#-migration-tools)
- [Troubleshooting](#-troubleshooting)
- [Security](#-security)
- [Contributing](#-contributing)
- [License](#-license)

## ✨ Features

### 🔍 Smart Import
- **Automatic deduplication** by OpenAlex Work ID and DOI
- **Complete metadata mapping**: title, authors, DOI, journal, volume, issue, pages, abstract, year
- **Abstract reconstruction** from OpenAlex's inverted index
- **Automatic BibTeX key generation**

### 👥 Author Management
- **Individual author-publication relationships** in teachPress
- **Smart linking**: Team members appear linked to their profiles in author lists
- **Author deduplication** reusing existing teachPress entries
- **Multiple author support** with standard formatting (Lastname, Initials)

### ⚡ Background Processing
- **Asynchronous job queue** using Action Scheduler
- **Non-blocking synchronization** of the admin interface
- **Real-time sync status** per member
- **Duplicate job prevention**

### 🎨 Integrated Frontend
- **Automatic injection** of publications on `single-team.php` pages
- **Year grouping** with responsive design
- **Customizable styles** with inline CSS
- **DOI and publication URL links**
- **Publication type badges** with colors (article, book, conference, thesis, etc.)

### 🛠️ Administration
- **Centralized settings** for API key and email
- **Automatic sync** with configurable intervals (manual, hourly, daily, weekly)
- **Custom columns** in team member listing
- **Quick Edit** for OpenAlex ID
- **Sync status filters**
- **Hide/show individual publications**
- **Author ID migration tool**

### 🚀 Optimization
- **Caching system** using WordPress transients (12 hours)
- **Optimized queries** with proper indexes
- **Respectful rate limiting** for the OpenAlex API
- **Automatic pagination** for authors with many publications

## 📦 Requirements

- **WordPress** 5.0 or higher
- **PHP** 7.4 or higher
- **teachPress** plugin active (for publication management)
- **TLP Team** plugin active (for the `team` Custom Post Type)
- **Action Scheduler** (included in `vendor/action-scheduler/`)

## 🔧 Installation

### 1. Clone or Download the Repository

```bash
cd wp-content/plugins/
git clone https://github.com/icic-uns-conicet/articles-team-teachpress.git openalex-team-publications
```

Or download the ZIP and extract it to `wp-content/plugins/openalex-team-publications/`

### 2. Activate the Plugin

1. Go to **Plugins** in the WordPress admin panel
2. Find **OpenAlex Team Publications**
3. Click **Activate**

### 3. Verify Dependencies

Make sure the following plugins are active:
- ✅ teachPress
- ✅ TLP Team

## ⚙️ Configuration

### Settings Page

Go to **Team → OpenAlex Configuration** in the admin menu.

#### OpenAlex API

| Field | Description | Required |
|-------|-------------|----------|
| **API Key** | OpenAlex API key for authentication and higher rate limit. Get one at openalex.org | Optional |
| **Email for User-Agent** | Email included in the User-Agent for better rate-limiting and policy compliance | Recommended |

#### General

| Field | Description | Values |
|-------|-------------|--------|
| **Automatic Synchronization** | Frequency of automatic sync | Manual, Hourly, Twice Daily, Daily, Weekly |
| **Max publications per member** | Limit of publications to import per member per sync | 10 - 1000 (default: 200) |

### Configure OpenAlex ID for Members

1. Go to **Team → All Members**
2. Edit a member
3. In the **OpenAlex ID** field, enter the author's ID from OpenAlex
   - Example: `https://openalex.org/A1234567890` or simply `A1234567890`
   - Multiple IDs can be separated by `|` (pipe)
4. Save changes

**How to find the OpenAlex ID?**
- Search for the author at openalex.org
- Copy the author profile URL
- The ID is the last part of the URL (e.g., `A1234567890`)

## 📖 Usage

### Manual Synchronization

#### From the Member List
1. Go to **Team → All Members**
2. Hover over a member
3. Click **Sync with OpenAlex** in the quick actions
4. The job will be queued and processed in the background

#### Bulk Synchronization
1. Select multiple members using checkboxes
2. In the **Bulk Actions** dropdown, select **Sync with OpenAlex**
3. Click **Apply**

### View Sync Status

In the member list, the following columns show status:
- **OpenAlex Status**: idle, queued, processing, completed, error
- **Last sync**: Date and time of the last successful sync
- **Publications**: Total number of imported publications

### Manage Publications

#### Hide/Show Publications
1. Go to **Team → Publications**
2. Hover over a publication
3. Click **Hide** or **Show** in quick actions

Hidden publications won't appear on the frontend but remain in the database.

#### View a Member's Publications
1. Go to **Team → Publications**
2. Use the **Team member** filter to see only publications from a specific member

### Frontend

Publications are automatically displayed on each member's individual page (`single-team.php`).

**Frontend features:**
- ✅ Year grouping (most recent first)
- ✅ Color badges by publication type
- ✅ DOI links when available
- ✅ Authors linked to team member profiles
- ✅ Responsive design
- ✅ 12-hour cache for performance optimization

## 🏗️ Architecture

```
openalex-team-publications/
│
├── team-teachpress-integration.php    # Main plugin file
│
├── includes/
│   └── class-helpers.php              # Shared utilities (formatting, mapping, DB, cache)
│
├── core/
│   ├── class-openalex-api.php         # Communication with api.openalex.org
│   ├── class-teachpress-import.php    # Deduplication + insertion into teachPress
│   └── class-job-queue.php            # Background job queue (Action Scheduler)
│
├── admin/
│   ├── class-settings.php             # Settings page
│   ├── class-admin-columns.php        # Custom columns, Quick Edit, filters
│   ├── class-admin-sync.php           # Sync handler (admin-post)
│   └── class-publications-page.php    # Submenu and admin views
│
├── frontend/
│   └── class-single-team.php          # Injection on single-team.php (tlp-team)
│
├── blocks/
│   └── class-blocks.php               # Gutenberg blocks and REST API endpoints
│
├── languages/
│   └── openalex-team-en_US.po         # Translation file
│
└── vendor/
    └── action-scheduler/              # Dependency: Action Scheduler
```

### Synchronization Flow

```
1. User triggers sync (manual or automatic)
   ↓
2. OpenAlex_Job_Queue::enqueue_member_sync() queues the job
   ↓
3. Action Scheduler executes OpenAlex_Job_Queue::process_sync() in background
   ↓
4. OpenAlex_API::fetch_works() fetches publications from OpenAlex (auto-pagination)
   ↓
5. OpenAlex_TeachPress_Import::sync_member() processes each publication:
   - Checks duplicates by OpenAlex Work ID
   - Checks duplicates by DOI
   - Maps OpenAlex fields to teachPress
   - Inserts/updates in teachPress
   - Saves author-publication relationships
   ↓
6. Member sync status is updated
   ↓
7. Member publication cache is cleared
```

## 🌐 OpenAlex API

### Endpoints Used

The plugin uses the `/works` endpoint from the OpenAlex API:

```
GET https://api.openalex.org/works
  ?filter=author.id:{openalex_author_id}
  &per-page=200
  &cursor=*
  &select=id,title,type,publication_year,doi,authorships,biblio,primary_location,abstract_inverted_index
```

### Authentication

- **API Key**: Optional but recommended for higher rate limits
- **User-Agent**: Includes email for better rate limiting
- **Rate Limit**:
  - Without API key: 100,000 requests/day
  - With API key: 100,000 requests/day + priority

### Pagination

The plugin uses **cursor-based pagination** to handle authors with many publications:
- Maximum 10 pages per sync
- 200 publications per page
- Total maximum: 2,000 publications per member (configurable)

## 🔄 Synchronization

### Automatic Synchronization

Configurable in **Team → OpenAlex Configuration**:

| Interval | Description |
|----------|-------------|
| **Manual** | Manual sync only |
| **Hourly** | `wp_schedule_event()` with `hourly` recurrence |
| **Twice Daily** | `twicedaily` recurrence |
| **Daily** | `daily` recurrence (default) |
| **Weekly** | `weekly` recurrence |

### Manual Synchronization

#### From the Interface
1. **Quick action**: Hover over a member → **Sync with OpenAlex**
2. **Bulk action**: Select members → **Bulk Actions** → **Sync with OpenAlex** → **Apply**

#### Programmatically

```php
// Sync a specific member
$result = OpenAlex_Job_Queue::enqueue_member_sync($post_id);

// Check status
$status = OpenAlex_Job_Queue::get_member_status($post_id);
```

### Deduplication

The plugin implements a two-level deduplication system:

1. **By OpenAlex Work ID**: Searches in `teachpress_pub_meta` if a publication with that ID already exists
2. **By DOI**: If no Work ID, searches by DOI in `teachpress_pub`

If a duplicate is found:
- Updates the `openalex_work_id` if necessary
- Ensures the member-publication relationship
- Skips creating a new publication

### Sync States

| State | Description |
|-------|-------------|
| `idle` | No activity |
| `queued` | Queued, awaiting processing |
| `running` | Processing publications |
| `completed` | Sync completed successfully |
| `failed` | Error during sync |

## 🎨 Frontend

### Automatic Injection

The plugin automatically detects when a `single-team.php` page is loaded and injects the publications block using JavaScript at the end of the main container.

**Container selectors (in priority order):**
1. `.tlp-single-container`
2. `.tlp-single-detail`
3. `article.type-team`
4. `main`
5. `#content`
6. `.site-content`

### HTML Structure

```html
<div class="openalex-publications">
  <h3 class="openalex-publications__title">
    Publications <span class="openalex-publications__count">(42)</span>
  </h3>

  <div class="openalex-publications__year-group">
    <h4 class="openalex-publications__year">2024</h4>
    <ul class="openalex-publications__list">
      <li class="openalex-publications__item">
        <span class="openalex-pub-type openalex-pub-type--article">Article</span>
        <span class="openalex-pub-title">
          <a href="https://doi.org/10.1234/example">Publication Title</a>
        </span>
        <span class="openalex-pub-authors">
          <a href="/team/juan-perez">Perez J.P.</a>, Garcia M., et al.
        </span>
        <em class="openalex-pub-journal">Journal Name</em>
        <a class="openalex-pub-doi" href="https://doi.org/10.1234/example">DOI: 10.1234/example</a>
      </li>
    </ul>
  </div>
</div>
```

### Style Customization

Styles are injected inline and can be overridden in your theme:

```css
/* Example: Change badge colors */
.openalex-pub-type--article {
  background: #custom-color;
  color: #custom-text;
}

/* Example: Adjust spacing */
.openalex-publications__item {
  padding: 1em 0;
}
```

### Publication Types

| OpenAlex Type | teachPress Type | Badge | Color |
|---------------|-----------------|-------|-------|
| `article`, `journal-article` | `article` | Article | Green |
| `book-chapter` | `inbook` | Chapter | Pink |
| `book`, `edited-book` | `book` | Book | Dark Pink |
| `proceedings-article`, `conference-paper` | `inproceedings` | Conference | Orange |
| `dissertation`, `thesis` | `phdthesis` | Thesis | Purple |
| `preprint` | `unpublished` | Preprint | Gray |
| `report` | `techreport` | Report | Blue |
| Others | `misc` | Misc | Gray |

## 🔧 Migration Tools

### Migrate Author IDs

**Location**: Team → OpenAlex Configuration → Tools

**Purpose**: Iterates through already imported publications and saves the `openalex_author_id` of each individual authorship.

**When to use?**
- After updating the plugin to a version that supports linking by ID
- When there are two team members with the same last name and links don't work correctly
- To improve link accuracy in author lists

**Safety**:
- ✅ Safe to run multiple times
- ✅ Only affects publications that don't have this data yet
- ✅ Does not modify existing data

**Result**:
- Publications processed
- Author relationships updated
- Errors (if any)

## 🐛 Troubleshooting

### Plugin won't activate

**Problem**: Error when activating the plugin

**Solution**:
1. Verify teachPress is active
2. Verify TLP Team is active
3. Check PHP error logs
4. Ensure you have PHP 7.4 or higher

### Publications aren't imported

**Problem**: When syncing, no publications are imported

**Possible causes**:
1. **Incorrect OpenAlex ID**: Verify the ID is valid at openalex.org
2. **No publications**: The author has no publications indexed in OpenAlex
3. **API error**: Check logs in `wp-content/debug.log` (if `WP_DEBUG_LOG` is active)

**Solution**:

```php
// Enable logging in wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Author links don't work

**Problem**: Team members don't appear linked in author lists

**Solution**:
1. Run **Author ID Migration** at Team → OpenAlex Configuration → Tools
2. Verify all members have their OpenAlex ID configured
3. Clear transients cache:

```bash
// Run once in functions.php or WP-CLI
wp transient delete --all
```

### Sync stays "Queued"

**Problem**: Status doesn't change from "queued"

**Possible causes**:
1. **Action Scheduler isn't working**: Verify WordPress cron is active
2. **Stuck job**: There may be a previous failed job

**Solution**:

```bash
# Check pending jobs with WP-CLI
wp action-scheduler list --hook=openalex_sync_member_background

# Cancel pending jobs
wp action-scheduler cancel --hook=openalex_sync_member_background
```

Or use the **Action Scheduler** plugin to view and manage jobs.

### Publications don't appear on frontend

**Problem**: Publications were imported but don't show on `single-team.php`

**Possible causes**:
1. **Cache**: Publications are cached
2. **Hidden publications**: Publications are marked as hidden
3. **Wrong template**: Theme doesn't use `single-team.php`

**Solution**:

```php
// Clear cache for a specific member
OpenAlex_Helpers::clear_member_publications_cache($post_id);

// Or clear all cache
wp transient delete --all
```

### "teachPress is not active" error

**Problem**: Sync fails with this message

**Solution**:
1. Verify teachPress is installed and active
2. Verify `TP_Publications` and `TP_Authors` classes exist
3. Check teachPress version (must be compatible)

## 📊 Database Structure

The plugin uses the following teachPress tables:

### `wp_teachpress_pub`
Main publications table.

### `wp_teachpress_pub_meta`
Publication metadata. The plugin adds:
- `openalex_work_id`: Work ID in OpenAlex
- `openalex_member_id`: Team member post ID
- `openalex_author_id_{author_id}`: Author's ID in OpenAlex
- `openalex_hidden`: If publication is hidden (1/0)

### `wp_teachpress_authors`
Authors registered in teachPress.

### `wp_teachpress_rel_pub_auth`
Many-to-many relationships between publications and authors.

## 🔒 Security

- ✅ Validation and sanitization of all inputs
- ✅ Nonces on all forms
- ✅ Permission verification (`manage_options`, `edit_posts`)
- ✅ Output escaping (`esc_html`, `esc_attr`, `esc_url`)
- ✅ SQL query preparation (`$wpdb->prepare`)
- ✅ Does not store sensitive credentials in code

## 🤝 Contributing

Contributions are welcome. To contribute:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📄 License

This project is licensed under the GPL v2 or later License.

## 👤 Author

**Carlos Lorenzetti**
ICIC-UNS-CONICET

## 🙏 Acknowledgments

- OpenAlex for providing open access to academic data
- teachPress for the publication management system
- TLP Team for the team Custom Post Type
- Action Scheduler for the job queue system

## 📞 Support

To report issues or request features:
- GitHub Issues

---

**Version**: 4.2
**Last updated**: June 2026
