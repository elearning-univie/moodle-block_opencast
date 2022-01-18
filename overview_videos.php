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
 * Overview of all videos in a series.
 *
 * @package    block_opencast
 * @copyright  2021 Tamara Gunkel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once('../../config.php');
require_once($CFG->dirroot . '/lib/tablelib.php');

use block_opencast\local\apibridge;
use mod_opencast\local\opencasttype;
use tool_opencast\local\settings_api;

global $PAGE, $OUTPUT, $CFG, $DB, $USER;

$ocinstanceid = optional_param('ocinstanceid', \tool_opencast\local\settings_api::get_default_ocinstance()->id, PARAM_INT);
$series = required_param('series', PARAM_ALPHANUMEXT);

$baseurl = new moodle_url('/blocks/opencast/overview_videos.php', array('ocinstanceid' => $ocinstanceid, 'series' => $series));
$PAGE->set_url($baseurl);
$PAGE->set_context(context_system::instance());

require_login(get_course(SITEID), false);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('pluginname', 'block_opencast'));

$apibridge = apibridge::get_instance($ocinstanceid);
$opencasterror = null;

if (settings_api::num_ocinstances() > 1) {
    $PAGE->set_heading(get_string('pluginname', 'block_opencast') . ': ' . settings_api::get_ocinstance($ocinstanceid)->name);
} else {
    $PAGE->set_heading(get_string('pluginname', 'block_opencast'));
}

$PAGE->navbar->add(get_string('opencastseries', 'block_opencast'),
    new moodle_url('/blocks/opencast/overview.php', array('ocinstanceid' => $ocinstanceid)));
$PAGE->navbar->add(get_string('pluginname', 'block_opencast'), $baseurl);
echo $OUTPUT->header();
echo html_writer::tag('p', get_string('videosoverviewexplanation', 'block_opencast'));

/** @var block_opencast_renderer $renderer */
$renderer = $PAGE->get_renderer('block_opencast');

// Try to retrieve name from opencast.
$ocseries = $apibridge->get_series_by_identifier($series);
if ($ocseries) {
    echo $OUTPUT->heading($ocseries->title);
} else {
    // If that fails use id.
    echo $OUTPUT->heading($series);
}

// TODO verify that teacher has indeed permission to view series.
// TODO handle opencast connection error. Break as soon as first error occurs.

// Build table.
$columns = array('videos', 'linked', 'activities');
$headers = array(get_string('video', 'block_opencast'),
    get_string('embeddedasactivity', 'block_opencast'),
    get_string('embeddedasactivitywolink', 'block_opencast'));
$table = $renderer->create_series_courses_tables('ignore', $headers, $columns, $baseurl);

$videos = $apibridge->get_series_videos($series)->videos;
$activityinstalled = \core_plugin_manager::instance()->get_plugin_info('mod_opencast') != null;

foreach ($videos as $video) {
    $activitylinks = array();
    if ($activityinstalled) {
        $activitylinks = $DB->get_records('opencast', array('ocinstanceid' => $ocinstanceid,
            'opencastid' => $video->identifier, 'type' => opencasttype::EPISODE));
    }

    $row = array();
    $row[] = $video->title;
    $courses = [];
    $courseswoblocklink = [];

    foreach ($activitylinks as $accourse) {
        try {
            // Get activity.
            $moduleid = \block_opencast\local\activitymodulemanager::get_module_for_episode($accourse->course, $video->identifier, $ocinstanceid);

            if (\tool_opencast\seriesmapping::get_record(array('ocinstanceid' => $ocinstanceid,
                'series' => $video->is_part_of, 'courseid' => $accourse->course))) {
                $courses[] = html_writer::link(new moodle_url('/mod/opencast/view.php', array('id' => $moduleid)),
                    get_course($accourse->course)->fullname, array('target' => '_blank'));
            } else {
                $courseswoblocklink[] = html_writer::link(new moodle_url('/mod/opencast/view.php', array('id' => $moduleid)),
                    get_course($accourse->course)->fullname, array('target' => '_blank'));
            }

        } catch (dml_missing_record_exception $ex) {
            continue;
        }
    }

    $row[] = join('<br>', $courses);
    $row[] = join('<br>', $courseswoblocklink);

    $table->add_data($row);
}


$table->finish_html();

if ($opencasterror) {
    \core\notification::error($opencasterror);
}

echo $OUTPUT->footer();