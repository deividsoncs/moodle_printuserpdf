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
 * Resource module version information
 *
 * @package    mod_resource
 * @copyright  2009 Petr Skoda  {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->dirroot.'/mod/resource/lib.php');
require_once($CFG->dirroot.'/mod/resource/locallib.php');
require_once($CFG->libdir.'/completionlib.php');
//FPDF
require_once($CFG->libdir.'/fpdf/fpdf.php');
//FPDI
require_once($CFG->libdir.'/fpdi/src/autoload.php');
use \setasign\Fpdi\Fpdi;
use \setasign\Fpdi\PdfReader;

$id       = optional_param('id', 0, PARAM_INT); // Course Module ID
$r        = optional_param('r', 0, PARAM_INT);  // Resource instance ID
$redirect = optional_param('redirect', 0, PARAM_BOOL);
$forceview = optional_param('forceview', 0, PARAM_BOOL);

if ($r) {
    if (!$resource = $DB->get_record('resource', array('id'=>$r))) {
        resource_redirect_if_migrated($r, 0);
        print_error('invalidaccessparameter');
    }
    $cm = get_coursemodule_from_instance('resource', $resource->id, $resource->course, false, MUST_EXIST);

} else {
    if (!$cm = get_coursemodule_from_id('resource', $id)) {
        resource_redirect_if_migrated(0, $id);
        print_error('invalidcoursemodule');
    }
    $resource = $DB->get_record('resource', array('id'=>$cm->instance), '*', MUST_EXIST);
}

$course = $DB->get_record('course', array('id'=>$cm->course), '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/resource:view', $context);

// Completion and trigger events.
resource_view($resource, $course, $cm, $context);

$PAGE->set_url('/mod/resource/view.php', array('id' => $cm->id));

if ($resource->tobemigrated) {
    resource_print_tobemigrated($resource, $cm, $course);
    die;
}

$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false); // TODO: this is not very efficient!!
if (count($files) < 1) {
    resource_print_filenotfound($resource, $cm, $course);
    die;
} else {
    $file = reset($files);
    unset($files);
}

$resource->mainfile = $file->get_filename();
$displaytype = resource_get_final_display_type($resource);
if ($displaytype == RESOURCELIB_DISPLAY_OPEN || $displaytype == RESOURCELIB_DISPLAY_DOWNLOAD) {
    $redirect = true;
}

// Don't redirect teachers, otherwise they can not access course or module settings.
if ($redirect && !course_get_format($course)->has_view_page() &&
        (has_capability('moodle/course:manageactivities', $context) ||
        has_capability('moodle/course:update', context_course::instance($course->id)))) {
    $redirect = false;
}

if ($redirect && !$forceview) {
    // coming from course page or url index page
    // this redirect trick solves caching problems when tracking views ;-)
    $path = '/'.$context->id.'/mod_resource/content/'.$resource->revision.$file->get_filepath().$file->get_filename();
    $fullurl = moodle_url::make_file_url('/pluginfile.php', $path, $displaytype == RESOURCELIB_DISPLAY_DOWNLOAD);
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    //A alteração da impressão começa aqui... [Calixto em 18-11-2020]
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    //verifico se o arquivo e um .pdf 
    if (strstr($path, '.pdf')){
        $pdf = new Fpdi();
	//construção do 'FrankenStyle' para resgate do arquivo vide: https://docs.moodle.org/dev/File_API_internals
        $frankensPath = $CFG->dataroot . '/filedir/' . substr($file->get_contenthash(), 0, 2) . '/' .  substr($file->get_contenthash(), 2, 2) . '/' . $file->get_contenthash();
        //$pageCount =  $pdf->setSourceFile('/opt/moodledata/temp_pdf/retrato.pdf');
	$pageCount =  $pdf->setSourceFile($frankensPath);
        //$myWayUrl = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename(), false);
	for ($i = 1; $i <= $pageCount; $i++){
    	    //importa a pagina (i)
	    $tplId = $pdf->importPage($i);	    
	    $specs = $pdf->getTemplateSize($tplId);
	    $pdf->addPage();
	    //uso o template de página, atual(retrato ou paisagem).
	    $pdf->useTemplate($tplId,null, null,$specs['h'], $specs['w'], true);

	    // imprimindo o texto sobre a página
	    $pdf->SetFont('Helvetica');
	    $pdf->setFontSize(6.0);
    	    $pdf->SetTextColor(255, 0, 0);
	    $pdf->SetXY(5, 5);

	    $localPath = $CFG->dataroot . '/' . $file->get_contenthash();

	    $pdf->Write(0,/** $frankensPath .*/ ' Acessado em:' . date("d-m-Y H:i:s") . ' u:' . $USER->firstname . ' ' . $USER->lastname . ' i:' . $USER->username);
	}
	$pdf->setAuthor("mr.bogus-cpf.: " . $USER->username);
	$pdf->Output($file->get_filename(), 'D');

//        redirect($fullurl);
    }else{
        //só da continuidade se for qq aquivo distinto de pdf, pois estes não consigo escrever...
        redirect($fullurl);
    }
}

switch ($displaytype) {
    case RESOURCELIB_DISPLAY_EMBED:
        resource_display_embed($resource, $cm, $course, $file);
        break;
    case RESOURCELIB_DISPLAY_FRAME:
        resource_display_frame($resource, $cm, $course, $file);
        break;
    default:
        resource_print_workaround($resource, $cm, $course, $file);
        break;
}

