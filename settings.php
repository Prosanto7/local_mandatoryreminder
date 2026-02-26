<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Settings for local_mandatoryreminder
 *
 * @package    local_mandatoryreminder
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_category('local_mandatoryreminder',
        get_string('pluginname', 'local_mandatoryreminder')));

    $settings = new admin_settingpage('local_mandatoryreminder_settings',
        get_string('settings', 'local_mandatoryreminder'));

    if ($ADMIN->fulltree) {
        // Default deadline setting.
        $settings->add(new admin_setting_configtext(
            'local_mandatoryreminder/default_deadline',
            get_string('default_deadline', 'local_mandatoryreminder'),
            get_string('default_deadline_desc', 'local_mandatoryreminder'),
            14,
            PARAM_INT
        ));

        // Email batch size.
        $settings->add(new admin_setting_configtext(
            'local_mandatoryreminder/email_batch_size',
            get_string('email_batch_size', 'local_mandatoryreminder'),
            get_string('email_batch_size_desc', 'local_mandatoryreminder'),
            50,
            PARAM_INT
        ));

        // Level 1 email template.
        $settings->add(new admin_setting_confightmleditor(
            'local_mandatoryreminder/level1_template',
            get_string('level1_template', 'local_mandatoryreminder'),
            get_string('level1_template_desc', 'local_mandatoryreminder'),
            get_string('level1_template_default', 'local_mandatoryreminder')
        ));

        // Level 2 email template.
        $settings->add(new admin_setting_confightmleditor(
            'local_mandatoryreminder/level2_template',
            get_string('level2_template', 'local_mandatoryreminder'),
            get_string('level2_template_desc', 'local_mandatoryreminder'),
            get_string('level2_template_default', 'local_mandatoryreminder')
        ));

        // Level 3 email template (Employee).
        $settings->add(new admin_setting_confightmleditor(
            'local_mandatoryreminder/level3_employee_template',
            get_string('level3_employee_template', 'local_mandatoryreminder'),
            get_string('level3_employee_template_desc', 'local_mandatoryreminder'),
            get_string('level3_employee_template_default', 'local_mandatoryreminder')
        ));

        // Level 3 email template (Supervisor).
        $settings->add(new admin_setting_confightmleditor(
            'local_mandatoryreminder/level3_supervisor_template',
            get_string('level3_supervisor_template', 'local_mandatoryreminder'),
            get_string('level3_supervisor_template_desc', 'local_mandatoryreminder'),
            get_string('level3_supervisor_template_default', 'local_mandatoryreminder')
        ));

        // Level 4 email template (Employee).
        $settings->add(new admin_setting_confightmleditor(
            'local_mandatoryreminder/level4_employee_template',
            get_string('level4_employee_template', 'local_mandatoryreminder'),
            get_string('level4_employee_template_desc', 'local_mandatoryreminder'),
            get_string('level4_employee_template_default', 'local_mandatoryreminder')
        ));

        // Level 4 email template (Supervisor).
        $settings->add(new admin_setting_confightmleditor(
            'local_mandatoryreminder/level4_supervisor_template',
            get_string('level4_supervisor_template', 'local_mandatoryreminder'),
            get_string('level4_supervisor_template_desc', 'local_mandatoryreminder'),
            get_string('level4_supervisor_template_default', 'local_mandatoryreminder')
        ));

        // Level 4 email template (SBU Head).
        $settings->add(new admin_setting_confightmleditor(
            'local_mandatoryreminder/level4_sbuhead_template',
            get_string('level4_sbuhead_template', 'local_mandatoryreminder'),
            get_string('level4_sbuhead_template_desc', 'local_mandatoryreminder'),
            get_string('level4_sbuhead_template_default', 'local_mandatoryreminder')
        ));
    }

    $ADMIN->add('local_mandatoryreminder', $settings);

    // Add course deadline configuration page.
    $ADMIN->add('local_mandatoryreminder', new admin_externalpage(
        'local_mandatoryreminder_courseconfig',
        get_string('course_config', 'local_mandatoryreminder'),
        new moodle_url('/local/mandatoryreminder/course_config.php'),
        'local/mandatoryreminder:configure'
    ));

    // Add dashboard page.
    $ADMIN->add('local_mandatoryreminder', new admin_externalpage(
        'local_mandatoryreminder_dashboard',
        get_string('dashboard', 'local_mandatoryreminder'),
        new moodle_url('/local/mandatoryreminder/dashboard.php'),
        'local/mandatoryreminder:viewdashboard'
    ));
}
