<?php

/**
 * Page for interacting with a course.
 *
 * @since 0.1
 *
 * @file CoursePage.php
 * @ingroup EducationProgram
 * @ingroup Page
 *
 * @licence GNU GPL v3+
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */
class CoursePage extends EPPage {
	
	/**
	 * (non-PHPdoc)
	 * @see EPPage::getActions()
	 */
	public function getActions() {
		return array(
			'view' => 'ViewCourseAction',
			'edit' => 'EditCourseAction',
			'history' => 'CourseHistoryAction',
			'delete' => 'EPDeleteAction',
		);
	}
	
	/**
	 * (non-PHPdoc)
	 * @see EPPage::getActions()
	 * @return EPPageTable
	 */
	public function getTable() {
		return EPCourses::singleton();
	}
	
}

