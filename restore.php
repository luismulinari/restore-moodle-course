<?php
define('CLI_SCRIPT', true);

require_once('../../config.php');
require_once($CFG->dirroot . '/lib/formslib.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->libdir.'/clilib.php');

error_reporting(E_ALL);
ini_set("display_errors", "on");
ini_set("log_errors", "on");

$time_start = time();

list($options, $unrecognized) = cli_get_params(
        array('userinfo'        => false,
              'delete'      => false,
              'keep_enrols' => false,
              'keep_groups' => false,
              'silently'    => false,
              'help'        => false,
              'target'      => 'new',
              'category'    => ''),
        array('u' => 'userinfo',
              'd' => 'delete',
              'e' => 'keep_enrols',
              'g' => 'keep_groups',
              's' => 'silently',
              'h' => 'help'));

if ($options['help']) {
        echo "
        Restore a backup into a new or existing course:
          \$ php {$argv[0]} [options] backup_file_path{zip|mbz}

        Options:
        -h, --help                      Print out this help
        -s, --silently                  Don't print verbose progess information
        -d, --delete                    Delete existing course content before restore (default: adding)
        -e, --keep_enrols               Keep roles and enrolments when deleting the course content (default: remove)
        -g, --keep_groups               Keep groups and groupings when deleting the course content default: remove)
        -u, --userinfo                  Include user info when restoring a course
            --target=[new|course_id|course_shortname]  Execute restore into the target course (default: new)
            --category=[category_id|category_idnumber] Where to put the new course (default: 'Restored Courses')

        Example:
        \$ php {$argv[0]} -s -d --target='course_shortname XYZ' teste.mbz
        \$ php {$argv[0]} teste.zip

        \n";
        die;
}

if (!empty($unrecognized) && preg_match('/.*\.(zip|mbz)$/i', $unrecognized[0])) {
    $backup_file_path = $unrecognized[0];
    unset($unrecognized[0]);
}
if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error('Unknown options: ' . $unrecognized);
}

if (isset($backup_file_path)) {
    if(! is_file($backup_file_path)) {
        cli_error("Backup file not exists: '{$backup_file_path}'");
    }
} else {
    cli_error('Missing backup file parameter');
}

$silently = !empty($options['silently']);
$target   = empty($options['target']) ? 'new' : $options['target'];
$category  = isset($options['category']) ? $options['category'] : '';
$delete   = !empty($options['delete']);
$keep_groups = !empty($options['keep_groups']);
$keep_enrols = !empty($options['keep_enrols']);
$userinfo = !empty($options['userinfo']);

$opts = array();
if($silently) $opts[] = 'silently';

if($target === 'new') {
    $target_action = backup::TARGET_NEW_COURSE;
    $delete = false;
    $keep_groups = false;
    $keep_enrols = false;

    $str_target = 'a new course';
    $opts[] = 'adding content';
} else if(is_numeric($target)) {
    if(!$course = $DB->get_record('course', array('id'=>$target))) {
        cli_error("Course id not exists: '{$target}'");
    }
    $courseid = $course->id;
    $shortname = $course->shortname;
    $fullname = $course->fullname;
    $target_action = backup::TARGET_EXISTING_ADDING;

    $str_target = "course {$course->id}: {$course->fullname}";
    $opts[] = $delete ? 'deleting content' : 'adding content';
} else {
    if(!$course = $DB->get_record('course', array('shortname'=>addslashes($target)))) {
        cli_error("Course shortname not exists: '{$target}'");
    }
    $courseid = $course->id;
    $shortname = $course->shortname;
    $fullname = $course->fullname;
    $target_action = backup::TARGET_EXISTING_ADDING;

    $str_target = "course {$course->id}: {$course->fullname}";
    $opts[] = $delete ? 'deleting content' : 'adding content';
}

if ($delete) {
    if ($keep_enrols) $opts[] ='keeping roles/enrolments';
    if ($keep_groups) $opts[] ='keeping groups/groupings';
}

$str_opts = implode(', ', $opts);
show_msg("\nRestoring ({$str_opts})\n");
show_msg("\tfile: '$backup_file_path'\n");
show_msg("\tinto {$str_target}\n");

if($target === 'new') {
    if(empty($category)) {
        $category = 'Restored Courses';
        $cat = $DB->get_record('course_categories', array('idnumber'=>'Restored Courses'));
        if (!$cat) {
            $categoryid = $DB->insert_record('course_categories', (object)array(
                'name' => $categoryname,
                'idnumber' => $category_idnumber,
                'parent' => 0,
                'visible' => 0
            ));
            $DB->set_field('course_categories', 'path', '/' . $categoryid, array('id'=>$categoryid));
            $cat = $DB->get_record('course_categories', array('id'=>$categoryid));
        }
    } else if(is_numeric($category)) {
        $cat = $DB->get_record('course_categories', array('id'=>$category));
    } else {
        $cat = $DB->get_record('course_categories', array('idnumber'=>addslashes($category)));
    }
    if(empty($cat)) {
        cli_error("Unknown category: '{$category}'");
    }

    $shortname = 'restored_course_' . date('His');
    $fullname = "Restored Course from '{$backup_file_path}' " . date('Y-m-d H:i:s');
    $courseid = restore_dbops::create_new_course($fullname, $shortname, $cat->id);
}

show_msg("\nUnziping backup file:  " . basename($backup_file_path) . " ...\n");
$rand_backup_path = 'backup_script_' . date('YmdHis') . '_' . rand();
check_dir_exists($CFG->dataroot . '/temp/backup');
$zip = new zip_packer();
if (!$zip->extract_to_pathname($backup_file_path, $CFG->dataroot . '/temp/backup/' . $rand_backup_path)) {
    cli_error('Error extracting zip backup file');
}
show_msg("\nUnzip completed.\n");

if ($delete) {
    show_msg("\nDeleting course content ...\n");
    $options = array();
    $options['keep_roles_and_enrolments'] = $keep_enrols;
    $options['keep_groups_and_groupings'] = $keep_groups;
    restore_dbops::delete_course_content($courseid, $options);
    $target_action = backup::TARGET_EXISTING_DELETING;
    show_msg("\nDelete completed.\n");
}

$controller = new restore_controller($rand_backup_path, $courseid,
                                     backup::INTERACTIVE_NO, backup::MODE_GENERAL, 2,
                                     $target_action);
if(!$userinfo) {
    $plan = $controller->get_plan();
    $plan->get_setting('users')->set_value(false);
}

/*
$info = $plan->get_info();
$info->original_course_fullname;
$info->original_course_shortname;
$info->original_course_startdate;
$info->activities;
*/

if ($controller->get_status() == backup::STATUS_REQUIRE_CONV) {
    show_msg("\nConverting backup to current Moodle Version...\n");
    $controller->convert();
    show_msg("\nConverte completed.\n");
}

$controller->get_logger()->set_next(new output_indented_logger(backup::LOG_INFO, false, true));

show_msg("\nPrechecking ...\n");
$controller->execute_precheck();
show_msg("\nPrecheck completed ...\n");

show_msg("\nRestoring...\n");
$controller->execute_plan();
show_msg("\nRestore completed ...\n");

$time_end = time();
$total_time = $time_end - $time_start;
show_msg("\nRestore completed in {$total_time} seconds\n");

$courseurl = new moodle_url('/course/view.php', array('id'=>$courseid));
show_msg("\nCourse URL: {$courseurl}\n\n");

// -----------------------------------------------------------------------------------------

function show_msg($msg) {
    global $silently;

    if (!$silently) {
        echo $msg;
    }
}
