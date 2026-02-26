# Mandatory Course Reminder Plugin

## Overview
This plugin implements an automated reminder and escalation workflow for mandatory courses to ensure compliance and timely course completion.

## Features
- 4-level escalation workflow with automated reminders
- Configurable deadlines per course
- Email batching using Moodle's ad-hoc task queue
- Custom email templates for each escalation level
- Dashboard with queue status and summary
- Notifications in addition to emails

## Escalation Levels
- **Level 1:** 3 days before deadline → Employee
- **Level 2:** 1 day before deadline → Employee
- **Level 3:** 1 week after deadline → Employee + Supervisor
- **Level 4:** 2 weeks after deadline → Employee + Supervisor + SBU Head

## Requirements
- Course custom field: `mandatory_status` (values: Mandatory, Optional)
- User custom field: `SupervisorEmail`
- User custom field: `sbuheademail`

## Installation
1. Copy the plugin to `/local/mandatoryreminder`
2. Visit Site administration → Notifications to install
3. Configure course deadlines in Plugin settings
4. Customize email templates in Plugin settings

## Configuration
- Default deadline: 14 days (configurable per course)
- Access the dashboard at: Site administration → Plugins → Local plugins → Mandatory Reminder Dashboard
- Configure course deadlines at: Site administration → Plugins → Local plugins → Course Deadlines

## License
GNU GPL v3 or later
