# Mandatory Course Reminder Plugin

## Overview
This plugin implements an automated reminder and escalation workflow for mandatory courses to ensure compliance and timely course completion. **Version 2.0** introduces consolidated emails where each user receives one email containing all their mandatory courses, sorted by priority level.

## Key Features
- **Consolidated Emails**: One email per user (not one per course)
- **Priority-based Sorting**: Level 4 (critical) courses appear first
- **4-level escalation workflow** with automated reminders
- **Hierarchical notifications**: Employee → Supervisor → SBU Head
- Configurable deadlines per course
- Email batching using Moodle's ad-hoc task queue
- Custom HTML email templates with table formatting
- Dashboard with queue status and summary
- In-app notifications in addition to emails

## Escalation Levels
- **Level 1:** 3 days before deadline → Employee only
- **Level 2:** 1 day before deadline → Employee only
- **Level 3:** 1 week after deadline → Employee + Supervisor
- **Level 4:** 2 weeks after deadline → Employee + Supervisor + SBU Head

## Email Structure (v2.0)

### Employee Emails
- **One email** containing all mandatory courses
- **Table format** with columns: Level, Course Name, Deadline, Status, Action
- **Sorted by level**: Level 4 courses at the top
- **Color coding**: Visual priority indicators (red for Level 4, orange for Level 3)

### Supervisor Emails  
- **One email per employee** under supervision
- Shows employee name and all their incomplete courses
- Table with course details and overdue status
- Sorted by level (Level 4 first)

### SBU Head Emails
- **Hierarchical structure**:
  - Grouped by Supervisor
  - For each Supervisor: list of employees
  - For each Employee: table of incomplete courses
- All courses sorted by level (Level 4 first)
- Complete organizational view

## Requirements
- **Course custom field:** `mandatory_status` (values: Mandatory, Optional)
- **User custom field:** `SupervisorEmail` (text field)
- **User custom field:** `sbuheademail` (text field)
- Moodle 4.0 or later

## Installation
1. Copy the plugin to `/local/mandatoryreminder`
2. Visit **Site administration → Notifications** to install
3. Run the upgrade (adds consolidated email support)
4. Configure course deadlines in Plugin settings
5. Customize the new consolidated email templates

## Configuration

### Basic Settings
- **Default deadline:** 14 days (configurable per course)
- **Email batch size:** 50 emails per batch (adjustable)

### Access Points
- **Dashboard:** Site admin → Plugins → Local plugins → Mandatory Reminder Dashboard
- **Course Deadlines:** Site admin → Plugins → Local plugins → Course Deadlines Configuration
- **Student List:** View and send employee reminder emails
- **Management List:** View and send supervisor/SBU head emails

### Email Templates
Configure consolidated templates at: **Site admin → Plugins → Local plugins → Mandatory Course Reminder**

**Templates available:**
- Consolidated Employee Template (uses `{course_table}` placeholder)
- Consolidated Supervisor Template (uses `{employee_table}` placeholder)
- Consolidated SBU Head Template (uses `{manager_table}` placeholder)

**Available placeholders:**
- `{fullname}`, `{firstname}`, `{lastname}` - User information
- `{sitename}` - Moodle site name
- `{course_table}` - Auto-generated table of courses (employee emails)
- `{employee_table}` - Auto-generated employee course table (supervisor emails)
- `{manager_table}` - Auto-generated hierarchical table (SBU head emails)

## Scheduled Task
The plugin includes a scheduled task: **Check mandatory course reminders**
- Default: Runs daily at 2:00 AM
- Can be configured in **Site administration → Server → Scheduled tasks**

## Usage Workflow

1. **Mark courses as mandatory** using the `mandatory_status` custom field
2. **Set course deadlines** via the Course Deadlines Configuration page
3. **Wait for scheduled task** to run (or run manually via CLI)
4. **Review queued emails** in the dashboard
5. **Preview and send** via Student List / Management List pages
6. **Monitor compliance** through the dashboard summary

## CLI Commands

```bash
# Run the reminder check manually
php admin/cli/scheduled_task.php --execute=\\local_mandatoryreminder\\task\\check_reminders

# Process the email queue
php admin/cli/adhoc_task.php --execute

# Test reminder for specific user
php local/mandatoryreminder/cli/test_reminder.php
```

## Upgrade from v1.x to v2.0

**Important:** Version 2.0 changes the email structure significantly.

1. **Backup your database** before upgrading
2. Install the new version
3. Run `php admin/cli/upgrade.php`
4. The upgrade will automatically:
   - Add `courses_data` field to store multiple courses per email
   - Consolidate existing pending queue items
   - Preserve your existing data
5. **Reconfigure templates**: The old per-level templates are deprecated. Configure the new consolidated templates in plugin settings.

See [CHANGELOG.md](CHANGELOG.md) for detailed changes.

## Optimization Benefits

- **80-90% reduction** in email volume
- **Better user experience**: Single digest vs. multiple emails
- **Improved mobile experience**: One email easier to read
- **Clear priorities**: Critical courses always appear first
- **Performance**: Fewer database queries and email sends

## Troubleshooting

### Emails not being sent
1. Check the scheduled task is enabled and running
2. Verify the email queue in the dashboard
3. Check Moodle's outgoing mail configuration
4. Review PHP error logs

### Queue items not being created
1. Ensure courses have the `mandatory_status` custom field set to "Mandatory"
2. Verify users are enrolled with 'student' role
3. Check users have `SupervisorEmail` and `sbuheademail` fields populated
4. Run the scheduled task manually to see detailed logs

### Missing custom fields
1. Go to **Site administration → Users → User profile fields**
2. Add text fields: `SupervisorEmail`, `sbuheademail`
3. Go to **Site administration → Courses → Course custom fields**
4. Add a dropdown field: `mandatory_status` with options "Mandatory" and "Optional"

## License
GNU GPL v3 or later

## Support
For issues and questions, please refer to:
- [CHANGELOG.md](CHANGELOG.md) for version history
- Moodle error logs
- Database upgrade logs
