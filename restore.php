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
                                    array('silently'=>false, 'help'=>false, 'target'=>'new', 'delete'=>false),
                                    array('s'=>'silently', 'h'=>'help'));

if ($options['help']) {
        echo "
        Restore a backup into a new or existing course:
          \$ php {$argv[0]} [options] backup_file_path{zip|mbz}

        Options:
        -h, --help                      Print out this help
        -s, --silently                  Don't print verbose progess information
            --target=[new|course_id|course_shortname]  Execute restore into the target course (default: new)
        -d, --delete                    Delete existing course content before restore (default: adding)

        Example:
        \$ php {$argv[0]} -s -d --target='abc 123' teste.mbz
        \$ php {$argv[0]} -s teste.zip

        \n";
        die;
}

if (!empty($unrecognized) && preg_match('/.*\.(zip|mbz)$/i', $unrecognized[0])) {
    $backup_file_path = $unrecognized[0];
    unset($unrecognized[0]);
}
if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if (isset($backup_file_path)) {
    if(! is_file($backup_file_path)) {
        cli_error("backup_file_path not exists: '{$backup_file_path}'");
    }
} else {
    cli_error('missing_backup_file_path parameter');
}

$silently = !empty($options['silently']);
$target   = empty($options['target']) ? 'new' : $options['target'];
$delete   = !empty($options['delete']);

echo "\nUnzip backup file:  " . basename($backup_file_path) . " ... \n";

// Unzip backup
$rand_backup_path = 'backup_script_' . date('YmdHis') . '_' . rand();
check_dir_exists($CFG->dataroot . '/temp/backup');
$zip = new zip_packer();
if (!$zip->extract_to_pathname($backup_file_path, $CFG->dataroot . '/temp/backup/' . $rand_backup_path)) {
    cli_error('error_unzip_backup_file');
}

echo "\nUnzip completed.\n";

if($target === 'new') {
    $categoryname = 'Restored Courses';
    $categoryid = $DB->get_field('course_categories', 'id', array('name'=>$categoryname));
    if (!$categoryid) {
        $categoryid = $DB->insert_record('course_categories', (object)array(
            'name' => $categoryname,
            'parent' => 0,
            'visible' => 0
        ));
        $DB->set_field('course_categories', 'path', '/' . $categoryid, array('id'=>$categoryid));
    }

    $shortname = 'restored_course_' . date('His');
    $fullname = "Restored Course from '{$backup_file_path}' " . date('Y-m-d H:i:s');
    $courseid = restore_dbops::create_new_course($fullname, $shortname, $categoryid);

    $msg = 'a new course';

    $target_action = backup::TARGET_NEW_COURSE;

    $delete = false;
} else if(is_numeric($target)) {
    if(!$course = $DB->get_record('course', array('id'=>$target))) {
        cli_error("course id not exists: '{$target}'");
    }
    $msg = "course {$course->id}: {$course->shortname}";
    $courseid = $target;
    $shortname = $course->shortname;
    $fullname = $course->fullname;

    $target_action = backup::TARGET_EXISTING_ADDING;
} else {
    $shortname = addslashes($target);
    if(!$course = $DB->get_record('course', array('shortname'=>$shortname))) {
        cli_error("course shortname not exists: '{$target}'");
    }
    $courseid = $course->id;
    $shortname = $course->shortname;
    $fullname = $course->fullname;
    $msg = "course {$course->id}: {$course->shortname}";

    $target_action = backup::TARGET_EXISTING_ADDING;
}

if ($delete) {
    echo "\nDelete course content...\n";
    restore_dbops::delete_course_content($courseid);
    $target_action = backup::TARGET_EXISTING_DELETING;
}

$str_delete = $delete ? 'deleting' : 'adding';
$str_silently = $silently ? 'silently, ' : '';
echo "\nRestoring ({$str_silently}{$str_delete} content) backup file: '$backup_file_path' into {$msg}\n";

$controller = new restore_controller($rand_backup_path, $courseid,
        backup::INTERACTIVE_NO, backup::MODE_GENERAL, 2,
        $target_action);
echo "STATUS: ", $controller->get_status(), "\n";

if ($controller->get_status() == backup::STATUS_REQUIRE_CONV) {
    echo "\nConverting backup to current Moodle Version...\n";
    $controller->convert();
}

$controller->get_logger()->set_next(new output_indented_logger(backup::LOG_INFO, false, true));

echo "\nPrecheck...\n";
$controller->execute_precheck();

echo "\nRestoring...\n";
$controller->execute_plan();

$DB->update_record('course', (object)array(
    'id' => $courseid,
    'shortname' => $shortname,
    'fullname' => $fullname
));

$courseurl = new moodle_url('/course/view.php', array('id'=>$courseid));

$time_end = time();

$total_time = $time_end - $time_start;

echo "\nRESTORE COMPLETED in {$total_time} seconds\n";
echo "\nCourse URL: {$courseurl}\n\n";

