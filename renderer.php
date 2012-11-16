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
 * Print course hierarchy
 *
 * @package    block_ual_mymoodle
 * @copyright  2012 University of London Computer Centre
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/blocks/ual_mymoodle/lib.php');

class block_ual_mymoodle_renderer extends plugin_renderer_base {

    private $showcode = 0;
    private $trimmode = block_ual_mymoodle::TRIM_RIGHT;
    private $trimlength = 50;

    /**
     * Prints course hierarchy view
     * @return string
     */
    public function course_hierarchy($showcode, $trimmode, $trimlength) {
        $this->showcode = $showcode;
        $this->trimmode = $trimmode;
        $this->trimlength = $trimlength;

        return $this->render(new course_hierarchy);
    }

    /**
     * Provides the html contained in the course hierarchy/'My UAL Moodle' block. The hierarchy is passed as a tree.
     *
     * @param render_course_hierarchy $tree
     * @return string
     */
    public function render_course_hierarchy(course_hierarchy $tree) {
        if (empty($tree) ) {
            $html = $this->output->box(get_string('nocourses', 'block_ual_mymoodle'));
        } else {
            $htmlid = 'course_hierarchy_'.uniqid();
            $html = '<div id="'.$htmlid.'">';
            $html .= $this->htmllize_tree($tree->courses);
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Converts the course hierarchy into something more meaningful.
     *
     * @param $tree
     * @param int $indent
     * @return string
     */
    protected function htmllize_tree($tree, $indent=0) {
        global $CFG, $DB;

        $result = html_writer::start_tag('ul');

        if (empty($tree)) {
            $result .= html_writer::tag('li', get_string('nothingtodisplay'));
        } else {
            foreach ($tree as $node) {

                $name = $node->get_fullname();
                if($this->showcode == 1) {
                    $name .= ' ('.$node->get_idnumber().')';
                }
                $course_fullname = $this->trim($name);
                $node_type = $node->get_type();

                // What id should we include in the rendered HTML?
                $type_id = '';
                // Is this a top level (a.k.a 'primary item') link?
                $display_top_level = false;
                // Is this a heading (i.e. displayed in bold)?
                $display_heading = false;
                // Should we display a link to the course?
                $display_link = true;
                // Do we display the events belonging to a course?
                $display_events = false;
                // That depends on the type of node...
                switch($node_type) {
                    case ual_course::COURSETYPE_PROGRAMME:
                        $display_heading = false;
                        $type_class = 'programme';
                        break;
                    case ual_course::COURSETYPE_ALLYEARS:
                        $type_class = 'course_all_years';
                        break;
                    case ual_course::COURSETYPE_COURSE:
                        $display_events = true;
                        $type_class = 'course';
                        break;
                    case ual_course::COURSETYPE_UNIT:
                        $display_events = true;
                        $type_class = 'unit';
                        break;
                }

                // default content is the course name with no other formatting
                $attributes = array('class' => $type_class);
                // Construct the content...
                $content = html_writer::tag('div', $course_fullname, $attributes);

                if($display_link == true) {
                    // Create a link if the user is enrolled on the course (which they should be if the enrolment plugin is working as it should).
                    if($node->get_user_enrolled() == true) {
                        $attributes['title'] = $course_fullname;
                        $moodle_url = $CFG->wwwroot.'/course/view.php?id='.$node->get_moodle_course_id();
                        // replace the content...
                        $content = html_writer::link($moodle_url, $course_fullname, $attributes);
                    } else {
                        // Display the name but it's not clickable...
                        $content = html_writer::tag('i', $content);
                    }
                }

                if($display_heading == true) {
                    $content = html_writer::tag('strong', $content);
                }

                // A primary item could be a programme, course or unit
                if($indent == 0) {
                    $display_top_level = true;
                }

                if($display_top_level == true) {
                    $content = html_writer::tag('h2', $content);
                }

                if($display_events == true) {
                    // Get events
                    $events = $this->print_overview($node->get_moodle_course_id());
                    if(!empty($events)) {
                        // Display the events as a nested linked list
                        $event_list = html_writer::start_tag('ul', array('id' => 'course_events'));
                        foreach($events as $courseid=>$mod_events) {
                            if(!empty($mod_events)) {
                                foreach($mod_events as $mod_type=>$event_html) {
                                    $event_list .= html_writer::tag('li', $event_html);
                                }
                            }
                        }
                        $event_list .= html_writer::end_tag('ul');

                        $content .= $event_list;
                    }
                }

                $children = $node->get_children();

                if ($children == null) {
                    $result .= html_writer::tag('li', $content, $attributes);

                } else {
                    // If this has parents OR it doesn't have parents or children then we need to display it...???
                    $result .= html_writer::tag('li', $content.$this->htmllize_tree($children, $indent+1), $attributes);
                }
            }
        }
        $result .= html_writer::end_tag('ul');

        return $result;
    }

    /**
     * Trims the text and shorttext properties of this node and optionally
     * all of its children.
     *
     * @param string $text The text to truncate
     * @return string
     */
    private function trim($text) {
        $result = $text;

        switch ($this->trimmode) {
            case block_ual_mymoodle::TRIM_RIGHT :
                if (textlib::strlen($text)>($this->trimlength+3)) {
                    // Truncate the text to $long characters.
                    $result = textlib::substr($text, 0, $this->trimlength).'...';
                }
                break;
            case block_ual_mymoodle::TRIM_LEFT :
                if (textlib::strlen($text)>($this->trimlength+3)) {
                    // Truncate the text to $long characters.
                    $result = '...'.textlib::substr($text, textlib::strlen($text)-$this->trimlength, $this->trimlength);
                }
                break;
            case block_ual_mymoodle::TRIM_CENTER :
                if (textlib::strlen($text)>($this->trimlength+3)) {
                    // Truncate the text to $long characters.
                    $length = ceil($this->trimlength/2);
                    $start = textlib::substr($text, 0, $length);
                    $end = textlib::substr($text, textlib::strlen($text)-$this->trimlength);
                    $result = $start.'...'.$end;
                }
                break;
        }
        return $result;
    }

    private function print_overview($courseid) {
        global $DB, $CFG, $USER;

        // Need course object from DB. Note this is a query for every single course in the tree :-(
        // Query for fields the module '_print_overview' functions require (rather than everything)...
        $sql = "SELECT id, shortname, modinfo, visible
                FROM {course} c
                WHERE c.id='{$courseid}'";

        $courses = $DB->get_records_sql($sql);

        $htmlarray = array();

        if(!empty($courses)) {

            // I know, I know... forum_print_overview needs this information (this code has been copied from 'block_course_overview.php'.
            foreach ($courses as $c) {
                if (isset($USER->lastcourseaccess[$c->id])) {
                    $courses[$c->id]->lastaccess = $USER->lastcourseaccess[$c->id];
                } else {
                    $courses[$c->id]->lastaccess = 0;
                }
            }

            if ($modules = $DB->get_records('modules')) {
                foreach ($modules as $mod) {
                    if (file_exists($CFG->dirroot.'/mod/'.$mod->name.'/lib.php')) {
                        include_once($CFG->dirroot.'/mod/'.$mod->name.'/lib.php');
                        $fname = $mod->name.'_print_overview';
                        if (function_exists($fname)) {
                            $fname($courses, $htmlarray);
                        }
                    }
                }
            }
        }

        return $htmlarray;
    }
}


