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
 * External functions and services declarations.
 *
 * @package    local_mandatoryreminder
 * @copyright  2026 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_mandatoryreminder_preview_email' => [
        'classname'   => 'local_mandatoryreminder_external',
        'methodname'  => 'preview_email',
        'classpath'   => 'local/mandatoryreminder/externallib.php',
        'description' => 'Preview email content before sending',
        'type'        => 'read',
        'ajax'        => true,
    ],
    'local_mandatoryreminder_queue_single_email' => [
        'classname'   => 'local_mandatoryreminder_external',
        'methodname'  => 'queue_single_email',
        'classpath'   => 'local/mandatoryreminder/externallib.php',
        'description' => 'Queue a single email for sending',
        'type'        => 'write',
        'ajax'        => true,
    ],
    'local_mandatoryreminder_queue_selected_emails' => [
        'classname'   => 'local_mandatoryreminder_external',
        'methodname'  => 'queue_selected_emails',
        'classpath'   => 'local/mandatoryreminder/externallib.php',
        'description' => 'Queue selected emails for sending',
        'type'        => 'write',
        'ajax'        => true,
    ],
    'local_mandatoryreminder_queue_all_pending' => [
        'classname'   => 'local_mandatoryreminder_external',
        'methodname'  => 'queue_all_pending',
        'classpath'   => 'local/mandatoryreminder/externallib.php',
        'description' => 'Queue all pending employee emails for sending',
        'type'        => 'write',
        'ajax'        => true,
    ],
];
