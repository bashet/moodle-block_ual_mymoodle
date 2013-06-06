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
require_once($CFG->dirroot . '/local/ual_api/lib.php');

class block_ual_mymoodle_renderer extends plugin_renderer_base {

    private $showcode = 0;
    private $showmoodlecourses = 0;
    private $trimmode = block_ual_mymoodle::TRIM_RIGHT;
    private $trimlength = 50;
    private $showhiddencourses = false;
    private $courseid = 0;
    private $MyTree = '';

    /**
     * Prints course hierarchy view
     * @return string
     */
    public function course_hierarchy($showcode, $trimmode, $trimlength, $showmoodlecourses, $showhiddencoursesstudents, $showhiddencoursesstaff, $courseid) {
        $this->showcode = $showcode;
        $this->showmoodlecourses = $showmoodlecourses;
        $this->trimmode = $trimmode;
        $this->trimlength = $trimlength;
        $this->showhiddencoursesstudents = $showhiddencoursesstudents;
        $this->showhiddencoursesstaff = $showhiddencoursesstaff;
        $this->courseid = $courseid;

        return $this->render(new course_hierarchy);
    }

    /**
     * Provides the html contained in the course hierarchy/'My UAL Moodle' block. The hierarchy is passed as a tree.
     *
     * @param render_course_hierarchy $tree
     * @return string
     */
    public function render_course_hierarchy(course_hierarchy $tree) {
        global $CFG;

        $html = ""; // Start with an empty string.

        $displayed_something = false;

        if (!empty($tree->courses) ) {
            
            $htmlid = 'course_hierarchy_'.uniqid();
            $this->page->requires->js_init_call('M.block_ual_mymoodle.init_tree', array(false, $htmlid, $CFG->wwwroot.'/course/view.php?id='.$this->courseid));
            
            $html .= html_writer::start_tag('div', array('id' => $htmlid));
            $html .= $this->htmllize_tree($tree->courses);
            $html .= html_writer::end_tag('div');

            $displayed_something = true;
        }

        // Do we display courses that the user is enrolled on in Moodle but not enrolled on them according to the IDM data?
        if($this->showmoodlecourses && !empty($tree->moodle_courses)) {
            $orphaned_courses = html_writer::start_tag('ul', array('class' => 'orphaned'));
            foreach($tree->moodle_courses as $course) {
                $courselnk = $CFG->wwwroot.'/course/view.php?id='.$course->id;
                $linkhtml = html_writer::link($courselnk,$course->fullname, array('class' => 'orphaned_course'));
                $orphaned_courses .= html_writer::tag('li', $linkhtml);
            }
            $orphaned_courses .= html_writer::end_tag('ul');

            $html .= $orphaned_courses;

            $displayed_something = true;
        }

        if(!$displayed_something) {
            $html .= $this->output->box(get_string('nocourses', 'block_ual_mymoodle'));
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
    protected function htmllize_tree($tree, $indent=0, $render_ul = true) {
        global $CFG, $USER;


        $result = '';

        // We use a latche to indicate whether or not we have rendered the UL tag
        $rendered_start_ul = false;

        if (!empty($tree)) {
            foreach ($tree as $node) {
                $node->event_ul_open = false; //added new variable to the node to determine if there is any <ul> opened which need to be closed later on.

                $name = $node->get_fullname();
                if($this->showcode == 1) {
                    $name .= ' ('.$node->get_idnumber().')';
                }
                $course_fullname = $this->trim($name);

                // What type of node is this?
                $node_type = $node->get_type();
                // Is this course visible?
                $visible = $node->get_visible();
                // By default we *do* show hidden courses - to avoid confusion if your role hasn't been specified in the MIS
                $showhiddencourses = true;
                // Is this a top level (a.k.a 'primary item') link? A primary item could be a programme, course or unit.
                $display_top_level = false;
                // Is this a heading (i.e. displayed in bold)?
                $display_heading = false;
                // Should we display a link to the course?
                $display_link = $visible;
                // Do we display the events belonging to a course?
                $display_events = false;
                // Is the node expanded or collapsed when the tree is first rendered
                $display_expanded = false;

                $type_class = 'unknown';

                // That depends on the type of node...
                switch($node_type) {
                    case ual_course::COURSETYPE_PROGRAMME:
                        $display_heading = false;
                        $display_expanded = true;
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

                $content = '';  // Start with empty content

                // default content is the course name with no other formatting
                $a_attributes = array('class' => $type_class);
                $a_attributes['class'] .= ' indent'.$indent;

                $li_attributes = array();

                // construct a list of class names for the
                $element_class = array();
                $anchor_class = array();

                // is the node hidden?
                if($visible == false) {
                    $anchor_class[] = 'hidden';

                    $ual_api = ual_api::getInstance();
                    if(isset($ual_api)) {
                        $role = $ual_api->get_user_role($USER->username);

                        if($role) {
                            $showhiddencourses = (((strcmp($role, 'STAFF') == 0) && $this->showhiddencoursesstaff) || ((strcmp($role, 'STUDENT') == 0) && $this->showhiddencoursesstudents));
                        }
                    }
                }

                // is the node collapsed or expanded?
                if($display_expanded) {
                    $element_class[] = 'expanded';
                }

                if($display_heading == true) {
                    $anchor_class[] = 'heading';
                }

                if($display_top_level == true) {
                    $anchor_class[] = 'top_level';
                }
                $element_class[] = $type_class;

                if(!empty($element_class)) {
                    $li_attributes = array('class' => implode(' ', $element_class));
                }

                if(!empty($anchor_class)) {
                    $a_attributes['class'] .= ' ';
                    $a_attributes['class'] .= implode(' ', $anchor_class);
                }

                if($visible == true || $showhiddencourses) {
                    // Contruct the content...

                    if($display_link == true) {
                        // Create a link if the user is enrolled on the course (which they should be if the enrolment plugins are working as they should).
                        if($node->get_user_enrolled() == true) {
                            $moodle_url = $CFG->wwwroot.'/course/view.php?id='.$node->get_moodle_course_id();
                            // replace the content...
                            $a_attributes['title'] = $course_fullname;
                            $content = html_writer::link($moodle_url, $course_fullname, $a_attributes);
                        } else {
                            // Display the name but it's not clickable...
                            $content = html_writer::link('#', $course_fullname, $a_attributes);
                        }
                    } else {
                        // Display the name but it's not clickable...
                        $content = html_writer::link('#', $course_fullname, $a_attributes);
                    }

                    if($display_events == true) {
                        // Get events
                        $event_list= '';
                        $events = $this->print_overview($node->get_moodle_course_id());
                        if(!empty($events)) {
                            // Display the events as a nested linked list

                            $event_list = html_writer::start_tag('ul');
                            $node->event_ul_open = true;
                            foreach($events as $courseid=>$mod_events) {
                                if(!empty($mod_events)) {
                                    foreach($mod_events as $mod_type=>$event_html) {
                                        //$event_list .= html_writer::tag('li', $event_html);

                                        $all_events = explode('<div class="assign overview">',$event_html);
                                        foreach($all_events as $my_event){
                                            if(!empty($my_event)){
                                                $my_event = substr($my_event,0,-6); //remove last extra </div> from the string
                                                $my_event = str_replace('div','li',$my_event); //convert all the div into li
                                                $event_list .= html_writer::start_tag('li',array('class'=>'event'));
                                                $event_list .= html_writer::start_tag('ul');
                                                $event_list .= $my_event;
                                                $event_list .= html_writer::end_tag('ul');
                                                $event_list .= html_writer::end_tag('li');
                                            }
                                        }
                                    }
                                }
                            }
                            //We didn't close the ul now. bcoz I will have to add more children in same level
                            if($node->get_children()==null){
                                $event_list .= html_writer::end_tag('ul');
                                $node->event_ul_open = false;
                            }
                            $content .= $event_list;
                        }
                    }
                }

                if($node->get_type() == ual_course::COURSETYPE_UNIT){
                    if($node->get_parents()[0]->event_ul_open == false){
                        if($render_ul && !$rendered_start_ul) {
                            $result .= html_writer::start_tag('ul');
                            $rendered_start_ul = true;
                        }
                    }
                }else {
                    if($render_ul && !$rendered_start_ul) {
                        $result .= html_writer::start_tag('ul');
                        $rendered_start_ul = true;
                    }
                }

                $children = $node->get_children();

                if($content != '') {


                    if ($children == null) {
                        //$content .= html_writer::end_tag('ul');
                        //if($event_list_start==3){
                        //    $content .= html_writer::end_tag('ul');
                        //}
                        $result .= html_writer::tag('li', $content, $li_attributes);
                        //$result .= html_writer::end_tag('ul');
                    } else {
                        // TODO: If this has parents OR it doesn't have parents or children then we need to display it...???

                        // Increase the indent when we recurse...
                        $result .= html_writer::tag('li', $content.$this->htmllize_tree($children, $indent+1,$render_ul=true), $li_attributes);
                        //$result .= html_writer::tag('li', $content, $li_attributes);
                    }


                } else {
                    if ($children != null) {
                        // Don't increase the indent as we haven't actually displayed anything...
                        $result .= $this->htmllize_tree($children, $indent, false);
                    }
                }
                if($node->event_ul_open == true){
                    $result .= html_writer::end_tag('ul');
                }
            }
            //foreach ($tree as $node) {
            if($rendered_start_ul) {
                $result .= html_writer::end_tag('ul');

            }
        }
        return $result;
    }

    protected function rendre_program($tree, $indent=0) {
        global $CFG, $USER;


        $result = '';

        // We use a latche to indicate whether or not we have rendered the UL tag
        $rendered_start_ul = false;

        if (!empty($tree)) {
            foreach ($tree as $node) {

                $name = $node->get_fullname();
                if($this->showcode == 1) {
                    $name .= ' ('.$node->get_idnumber().')';
                }
                $course_fullname = $this->trim($name);

                // What type of node is this?
                $node_type = $node->get_type();
                // Is this course visible?
                $visible = $node->get_visible();
                // By default we *do* show hidden courses - to avoid confusion if your role hasn't been specified in the MIS
                $showhiddencourses = true;
                // Is this a top level (a.k.a 'primary item') link? A primary item could be a programme, course or unit.
                $display_top_level = false;
                // Is this a heading (i.e. displayed in bold)?
                $display_heading = false;
                // Should we display a link to the course?
                $display_link = $visible;
                // Do we display the events belonging to a course?
                $display_events = false;
                // Is the node expanded or collapsed when the tree is first rendered
                $display_expanded = false;
                // Is event started or not
                //$event_list_start = false;

                $type_class = 'unknown';

                // That depends on the type of node...
                switch($node_type) {
                    case ual_course::COURSETYPE_PROGRAMME:
                        $display_heading = false;
                        $display_expanded = true;
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

                $content = '';  // Start with empty content

                // default content is the course name with no other formatting
                $a_attributes = array('class' => $type_class);
                $a_attributes['class'] .= ' indent'.$indent;

                $li_attributes = array();

                // construct a list of class names for the
                $element_class = array();
                $anchor_class = array();

                // is the node hidden?
                if($visible == false) {
                    $anchor_class[] = 'hidden';

                    $ual_api = ual_api::getInstance();
                    if(isset($ual_api)) {
                        $role = $ual_api->get_user_role($USER->username);

                        if($role) {
                            $showhiddencourses = (((strcmp($role, 'STAFF') == 0) && $this->showhiddencoursesstaff) || ((strcmp($role, 'STUDENT') == 0) && $this->showhiddencoursesstudents));
                        }
                    }
                }

                // is the node collapsed or expanded?
                if($display_expanded) {
                    $element_class[] = 'expanded';
                }

                if($display_heading == true) {
                    $anchor_class[] = 'heading';
                }

                if($display_top_level == true) {
                    $anchor_class[] = 'top_level';
                }

                if(!empty($element_class)) {
                    $li_attributes = array('class' => implode(' ', $element_class));
                }

                if(!empty($anchor_class)) {
                    $a_attributes['class'] .= ' ';
                    $a_attributes['class'] .= implode(' ', $anchor_class);
                }

                if($visible == true || $showhiddencourses) {
                    // Contruct the content...

                    if($display_link == true) {
                        // Create a link if the user is enrolled on the course (which they should be if the enrolment plugins are working as they should).
                        if($node->get_user_enrolled() == true) {
                            $moodle_url = $CFG->wwwroot.'/course/view.php?id='.$node->get_moodle_course_id();
                            // replace the content...
                            $a_attributes['title'] = $course_fullname;
                            $content = html_writer::link($moodle_url, $course_fullname, $a_attributes);
                        } else {
                            // Display the name but it's not clickable...
                            $content = html_writer::link('#', $course_fullname, $a_attributes);
                        }
                    } else {
                        // Display the name but it's not clickable...
                        $content = html_writer::link('#', $course_fullname, $a_attributes);
                    }
                    /*TODO:
                     * try to add show_units and hide_units element from php site
                     * Anyway it's making problem for other elements
                     * so it's disabled for time being. will see later on

                    if($type_class=='course'){
                        $content .= html_writer::tag('show_hide',get_string('showunits','block_ual_mymoodle'),array('class'=>'showall','style'=>'display:none'));
                    }
                    */

                    if($display_events == true) {
                        // Get events
                        $event_list= '';
                        $events = $this->print_overview($node->get_moodle_course_id());
                        if(!empty($events)) {
                            // Display the events as a nested linked list

                            $event_list = html_writer::start_tag('ul');
                            $event_list_start = 2;
                            foreach($events as $courseid=>$mod_events) {
                                if(!empty($mod_events)) {
                                    foreach($mod_events as $mod_type=>$event_html) {
                                        //$event_list .= html_writer::tag('li', $event_html);

                                        $all_events = explode('<div class="assign overview">',$event_html);
                                        foreach($all_events as $my_event){
                                            if(!empty($my_event)){
                                                $my_event = substr($my_event,0,-6); //remove last extra </div> from the string
                                                $my_event = str_replace('div','li',$my_event); //convert all the div into li
                                                $event_list .= html_writer::start_tag('li');
                                                $event_list .= html_writer::start_tag('ul',array('class'=>'event'));
                                                $event_list .= $my_event;
                                                $event_list .= html_writer::end_tag('ul');
                                                $event_list .= html_writer::end_tag('li');
                                            }
                                        }
                                    }
                                }
                            }
                            //We didn't close the ul now. bcoz I will have to add more children in same level
                            $content .= $event_list;
                        }
                    }
                }
                if($event_list_start!=3){
                    if($render_ul && !$rendered_start_ul) {
                        $result .= html_writer::start_tag('ul');
                        $rendered_start_ul = true;
                    }
                    if($event_list_start==2){
                        $event_list_start=3;
                    }

                }else{
                    $skip = $skip + 1;
                }


                $children = $node->get_children();

                if($content != '') {


                    if ($children == null) {
                        //$content .= html_writer::end_tag('ul');
                        //if($event_list_start==3){
                        //    $content .= html_writer::end_tag('ul');
                        //}
                        $result .= html_writer::tag('li', $content, $li_attributes);
                        //$result .= html_writer::end_tag('ul');
                    } else {
                        // TODO: If this has parents OR it doesn't have parents or children then we need to display it...???

                        // Increase the indent when we recurse...
                        $result .= html_writer::tag('li', $content.$this->htmllize_tree($children, $indent+1,$render_ul=true, $event_list_start, $skip), $li_attributes);
                        //$result .= html_writer::tag('li', $content, $li_attributes);
                    }


                } else {
                    if ($children != null) {
                        // Don't increase the indent as we haven't actually displayed anything...
                        $result .= $this->htmllize_tree($children, $indent, false);
                    }
                }
                /*
                if($skip==0 && $event_list_start==3){
                    $result .= html_writer::end_tag('ul');
                    $event_list_start = 0;
                }
                */
                $course_parents = $node->get_parents();
                if($course_parents){
                    if($skip == count($course_parents[0]->get_children())){
                        $result .= html_writer::end_tag('ul');
                        $event_list_start = 0;
                    }
                }

            }
            //foreach ($tree as $node) {
            if($rendered_start_ul) {
                $result .= html_writer::end_tag('ul');

            }
        }
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
        // Query for fields the module '_print_overview' functions require (rather than everything).

        // Note also that there is a bug fix we need to cope with (see http://tracker.moodle.org/browse/MDL-35089)
        if(intval($CFG->version) >= 2012062502) {
            $sql = "SELECT id, shortname, modinfo, visible, sectioncache
                    FROM {course} c
                    WHERE c.id='{$courseid}'";
        } else {
            $sql = "SELECT id, shortname, modinfo, visible
                    FROM {course} c
                    WHERE c.id='{$courseid}'";
        }

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


