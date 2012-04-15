<?php

/**
 * Class representing a single course.
 *
 * @since 0.1
 *
 * @file EPCourse.php
 * @ingroup EducationProgram
 *
 * @licence GNU GPL v2+
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */
class EPCourse extends EPPageObject {

	/**
	 * Field for caching the linked org.
	 *
	 * @since 0.1
	 * @var EPOrg|false
	 */
	protected $org = false;

	/**
	 * Cached array of the linked EPStudent objects.
	 *
	 * @since 0.1
	 * @var array|false
	 */
	protected $students = false;

	/**
	 * Field for caching the instructors.
	 *
	 * @since 0.1
	 * @var {array of EPInstructor}|false
	 */
	protected $instructors = false;
	
	/**
	 * Field for caching the online ambassaords.
	 *
	 * @since 0.1
	 * @var {array of EPOA}|false
	 */
	protected $oas = false;
	
	/**
	 * Field for caching the campus ambassaords.
	 *
	 * @since 0.1
	 * @var {array of EPCA}|false
	 */
	protected $cas = false;

	/**
	 * Returns a list of statuses a term can have.
	 * Keys are messages, values are identifiers.
	 *
	 * @since 0.1
	 *
	 * @return array
	 */
	public static function getStatuses() {
		return array(
			wfMsg( 'ep-course-status-passed' ) => 'passed',
			wfMsg( 'ep-course-status-current' ) => 'current',
			wfMsg( 'ep-course-status-planned' ) => 'planned',
		);
	}

	/**
	 * Returns the message for the provided status identifier.
	 *
	 * @since 0.1
	 *
	 * @param string $status
	 *
	 * @return string
	 */
	public static function getStatusMessage( $status ) {
		static $map = null;

		if ( is_null( $map ) ) {
			$map = array_flip( self::getStatuses() );
		}

		return $map[$status];
	}

	protected static $countMap = array(
		'student_count' => 'students',
		'instructor_count' => 'instructors',
		'oa_count' => 'online_ambs',
		'ca_count' => 'campus_ambs',
	);

	/**
	 * (non-PHPdoc)
	 * @see ORMRow::loadSummaryFields()
	 */
	public function loadSummaryFields( $summaryFields = null ) {
		if ( is_null( $summaryFields ) ) {
			$summaryFields = array( 'org_id' );
		}
		else {
			$summaryFields = (array)$summaryFields;
		}

		$fields = array();

		if ( in_array( 'org_id', $summaryFields ) ) {
			$fields['org_id'] = $this->getField( 'org_id' );
		}

		$this->setFields( $fields );
	}

	/**
	 * (non-PHPdoc)
	 * @see ORMRow::insert()
	 */
	protected function insert( $functionName = null, array $options = null ) {
		$success = parent::insert( $functionName, $options );

		if ( $success && $this->updateSummaries ) {
			EPOrgs::singleton()->updateSummaryFields( array( 'course_count', 'active' ), array( 'id' => $this->getField( 'org_id' ) ) );
		}

		return $success;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see EPRevisionedObject::onRemoved()
	 */
	protected function onRemoved() {
		if ( $this->updateSummaries ) {
			EPOrgs::singleton()->updateSummaryFields( null, array( 'id' => $this->getField( 'org_id' ) ) );
		}

		wfGetDB( DB_MASTER )->delete( 'ep_users_per_course', array( 'upc_course_id' => $this->getId() ) );

		parent::onRemoved();
	}

	/**
	 * (non-PHPdoc)
	 * @see EPRevisionedObject::onUpdated()
	 */
	protected function onUpdated( EPRevisionedObject $originalCourse ) {
		$newUsers = array();
		$changedSummaries = array();

		$roleMap = array(
			'online_ambs' => EP_OA,
			'campus_ambs' => EP_CA,
			'students' => EP_STUDENT,
			'instructors' => EP_INSTRUCTOR,
		);

		$countMap = array_flip( self::$countMap );

		$dbw = wfGetDB( DB_MASTER );

		foreach ( array( 'online_ambs', 'campus_ambs', 'students', 'instructors' ) as $usersField ) {
			if ( $this->hasField( $usersField ) && $originalCourse->getField( $usersField ) !== $this->getField( $usersField ) ) {
				$removedIds = array_diff( $originalCourse->getField( $usersField ), $this->getField( $usersField ) );
				$addedIds = array_diff( $this->getField( $usersField ), $originalCourse->getField( $usersField ) );

				foreach ( $addedIds as $addedId ) {
					$newUsers[] = array(
						'upc_course_id' => $this->getId(),
						'upc_user_id' => $addedId,
						'upc_role' => $roleMap[$usersField],
						'upc_time' => wfTimestampNow(),
					);
				}

				if ( !empty( $removedIds ) || !empty( $addedIds ) ) {
					$changedSummaries[] = $countMap[$usersField];
				}

				if ( !empty( $removedIds ) ) {
					$dbw->delete( 'ep_users_per_course', array(
						'upc_course_id' => $this->getId(),
						'upc_user_id' => $removedIds,
						'upc_role' => $roleMap[$usersField],
					) );
				}
			}
		}

		if ( !empty( $newUsers ) ) {
			$dbw->begin();

			foreach ( $newUsers as $userLink ) {
				$dbw->insert( 'ep_users_per_course', $userLink );
			}

			$dbw->commit();
		}

		if ( $this->updateSummaries ) {
			if ( $this->hasField( 'org_id' ) && $originalCourse->getField( 'org_id' ) !== $this->getField( 'org_id' ) ) {
				$conds = array( 'id' => array( $originalCourse->getField( 'org_id' ), $this->getField( 'org_id' ) ) );
				EPOrgs::singleton()->updateSummaryFields( null, $conds );
			}
			else if ( !empty( $changedSummaries ) ) {
				EPOrgs::singleton()->updateSummaryFields( $changedSummaries, array( 'id' => $originalCourse->getField( 'org_id' ) ) );
			}
		}

		parent::onUpdated( $originalCourse );
	}

	/**
	 * (non-PHPdoc)
	 * @see ORMRow::save()
	 */
	public function save( $functionName = null ) {
		if ( $this->hasField( 'name' ) ) {
			$this->setField( 'name', $GLOBALS['wgLang']->ucfirst( $this->getField( 'name' ) ) );
		}

		foreach ( array( 'student_count', 'instructor_count', 'oa_count', 'ca_count' ) as $summaryField ) {
			$field = self::$countMap[$summaryField];
			if ( $this->hasField( $field ) ) {
				$this->setField( $summaryField, count( $this->getField( $field ) ) );
			}
		}

		return parent::save( $functionName );
	}

	/**
	 * Returns the org associated with this term.
	 *
	 * @since 0.1
	 *
	 * @param string|array|null $fields
	 *
	 * @return EPOrg
	 */
	public function getOrg( $fields = null ) {
		if ( $this->org === false ) {
			$this->org = EPOrgs::singleton()->selectRow( $fields, array( 'id' => $this->loadAndGetField( 'org_id' ) ) );
		}

		return $this->org;
	}

	/**
	 * Display a pager with terms.
	 *
	 * @since 0.1
	 *
	 * @param IContextSource $context
	 * @param array $conditions
	 * @param boolean $readOnlyMode
	 * @param string|false $filterPrefix
	 */
	public static function getPager( IContextSource $context, array $conditions = array(), $readOnlyMode = false, $filterPrefix = false ) {
		$pager = new EPCoursePager( $context, $conditions, $readOnlyMode );

		if ( $filterPrefix !== false ) {
			$pager->setFilterPrefix( $filterPrefix );
		}

		$html = '';
		
		if ( $pager->getNumRows() ) {
			$html .=
				$pager->getFilterControl() .
				$pager->getNavigationBar() .
				$pager->getBody() .
				$pager->getNavigationBar() .
				$pager->getMultipleItemControl();
		}
		else {
			$html .= $pager->getFilterControl( true );
			$html .= $context->msg( 'ep-courses-noresults' )->escaped();
		}

		return $html;
	}

	/**
	 * Adds a control to add a term org to the provided context.
	 * Additional arguments can be provided to set the default values for the control fields.
	 *
	 * @since 0.1
	 *
	 * @param IContextSource $context
	 * @param array $args
	 *
	 * @return string
	 */
	public static function getAddNewControl( IContextSource $context, array $args ) {
		if ( !$context->getUser()->isAllowed( 'ep-course' ) ) {
			return '';
		}

		$html = '';

		$html .= Html::openElement(
			'form',
			array(
				'method' => 'post',
				'action' => EPCourses::singleton()->getTitleFor( 'NAME_PLACEHOLDER' )->getLocalURL( array( 'action' => 'edit' ) ),
			)
		);

		$html .= '<fieldset>';

		$html .= '<legend>' . $context->msg( 'ep-courses-addnew' )->escaped() . '</legend>';

		$html .= '<p>' . $context->msg( 'ep-courses-namedoc' )->escaped() . '</p>';

		$html .= Html::element( 'label', array( 'for' => 'neworg' ), $context->msg( 'ep-courses-neworg' )->plain() );

		$select = new XmlSelect(
			'neworg',
			'neworg',
			array_key_exists( 'org', $args ) ? $args['org'] : false
		);

		$select->addOptions( EPOrgs::singleton()->selectFields( array( 'name', 'id' ) ) );
		$html .= $select->getHTML();

		$html .= '&#160;' . Xml::inputLabel(
			$context->msg( 'ep-courses-newname' )->plain(),
			'newname',
			'newname',
			20,
			array_key_exists( 'name', $args ) ? $args['name'] : false
		);

		$html .= '&#160;' . Xml::inputLabel(
			$context->msg( 'ep-courses-newterm' )->plain(),
			'newterm',
			'newterm',
			10,
			array_key_exists( 'term', $args ) ? $args['term'] : false
		);

		$html .= '&#160;' . Html::input(
			'addnewcourse',
			$context->msg( 'ep-courses-add' )->plain(),
			'submit',
			array(
				'disabled' => 'disabled',
				'class' => 'ep-course-add',
			)
		);

		$html .= Html::hidden( 'isnew', 1 );

		$html .= '</fieldset></form>';

		return $html;
	}

	/**
	 * Adds a control to add a new term to the provided context
	 * or show a message if this is not possible for some reason.
	 *
	 * @since 0.1
	 *
	 * @param IContextSource $context
	 * @param array $args
	 *
	 * @return string
	 */
	public static function getAddNewRegion( IContextSource $context, array $args = array() ) {
		if ( EPOrgs::singleton()->has() ) {
			return EPCourse::getAddNewControl( $context, $args );
		}
		else {
			return $context->msg( 'ep-courses-addorgfirst' )->parse();
		}
	}

	/**
	 * Gets the amount of days left, rounded up to the nearest integer.
	 *
	 * @since 0.1
	 *
	 * @return integer
	 */
	public function getDaysLeft() {
		$timeLeft = (int)wfTimestamp( TS_UNIX, $this->getField( 'end' ) ) - time();
		return (int)ceil( $timeLeft / ( 60 * 60 * 24 ) );
	}

	/**
	 * Gets the amount of days since term start, rounded up to the nearest integer.
	 *
	 * @since 0.1
	 *
	 * @return integer
	 */
	public function getDaysPassed() {
		$daysPassed = time() - (int)wfTimestamp( TS_UNIX, $this->getField( 'start' ) );
		return (int)ceil( $daysPassed / ( 60 * 60 * 24 ) );
	}

	/**
	 * Returns the status of the course.
	 *
	 * @since 0.1
	 *
	 * @return string
	 */
	public function getStatus() {
		if ( $this->getDaysLeft() <= 0 ) {
			$status = 'passed';
		}
		elseif ( $this->getDaysPassed() <= 0 ) {
			$status = 'planned';
		}
		else {
			$status = 'current';
		}

		return $status;
	}

	/**
	 * Returns the students as a list of EPStudent objects.
	 *
	 * @since 0.1
	 *
	 * @return array of EPStudent
	 */
	public function getStudents() {
		return $this->getRoleList( 'students', 'EPStudents', 'students' );
	}

	/**
	 * Returns the instructors as a list of EPInstructor objects.
	 *
	 * @since 0.1
	 *
	 * @return array of EPInstructor
	 */
	public function getInstructors() {
		return $this->getRoleList( 'instructors', 'EPInstructors', 'instructors' );
	}

	/**
	 * Returns the users that have the specified role.
	 *
	 * @since 0.1
	 *
	 * @param string $fieldName Name of the role field.
	 * @param string $tableName Name of the table class in which this role is kept track of.
	 * @param string $classField Name of the field in which the list is cached in this class.
	 *
	 * @return array of EPRole
	 */
	protected function getRoleList( $fieldName, $tableName, $classField ) {
		if ( $this->$classField === false ) {
			$userIds = $this->getField( $fieldName );

			if ( empty( $userIds ) ) {
				$this->$classField = array();
			}
			else {
				$table = $tableName::singleton();

				$this->$classField = $table->select(
					null,
					array( 'user_id' => $userIds )
				);

				// At this point we will have all users that actually have an entry in the role table.
				// But it's possible they do not have such an entry, so create new objects for those.

				$addedIds = array();

				foreach ( $this->$classField as /* EPRole */ $userInRole ) {
					$addedIds[] = $userInRole->getField( 'user_id' );
				}

				foreach ( array_diff( $userIds, $addedIds ) as $remainingId ) {
					array_push( $this->$classField, $table->newFromArray( array( 'user_id' => $remainingId ) ) );
				}
			}
		}

		return $this->$classField;
	}
	
	/**
	 * Returns the campus ambassadors as a list of EPCA objects.
	 *
	 * @since 0.1
	 *
	 * @return array of EPCA
	 */
	public function getCampusAmbassadors() {
		return $this->getRoleList( 'campus_ambs', 'EPCAs', 'cas' );
	}
	
	/**
	 * Returns the online ambassadors as a list of EPOA objects.
	 *
	 * @since 0.1
	 *
	 * @return array of EPOA
	 */
	public function getOnlineAmbassadors() {
		return $this->getRoleList( 'online_ambs', 'EPOAs', 'oas' );
	}
	
	/**
	 * Returns the users that have a certain role as list of EPIRole objects.
	 * 
	 * @since 0.1
	 * 
	 * @param string $roleName
	 * 
	 * @return array of EPIRole
	 * @throws MWException
	 */
	public function getUserWithRole( $roleName ) {
		switch ( $roleName ) {
			case 'instructor':
				return $this->getInstructors();
				break;
			case 'online':
				return $this->getOnlineAmbassadors();
				break;
			case 'campus':
				return $this->getCampusAmbassadors();
				break;
			case 'student':
				return $this->getStudents();
				break;
		}
		
		throw new MWException( 'Invalid role name: ' . $roleName );
	}

	/**
	 * (non-PHPdoc)
	 * @see ORMRow::setField()
	 */
	public function setField( $name, $value ) {
		switch ( $name ) {
			case 'mc':
				$value = str_replace( '_', ' ', $value );
				break;
			case 'instructors':
				$this->instructors = false;
				break;
			case 'students':
				$this->students = false;
				break;
			case 'oas':
				$this->oas = false;
				break;
			case 'cas':
				$this->cas = false;
				break;
		}

		parent::setField( $name, $value );
	}

	/**
	 * Adds a role for a number of users to this course,
	 * by default also saving the course and only
	 * logging the adittion of the users/roles.
	 *
	 * @since 0.1
	 *
	 * @param array|integer $newUsers
	 * @param string $role
	 * @param boolean $save
	 * @param EPRevisionAction|null $revAction
	 *
	 * @return integer|false The amount of enlisted users or false on failiure
	 */
	public function enlistUsers( $newUsers, $role, $save = true, EPRevisionAction $revAction = null ) {
		$roleMap = array(
			'student' => 'students',
			'campus' => 'campus_ambs',
			'online' => 'online_ambs',
			'instructor' => 'instructors',
		);

		$field = $roleMap[$role];
		$users = $this->getField( $field );
		$addedUsers = array_diff( (array)$newUsers, $users );

		if ( empty( $addedUsers ) ) {
			return 0;
		}
		else {
			$this->setField( $field, array_merge( $users, $addedUsers ) );

			$success = true;

			if ( $save ) {
				$this->disableLogging();
				$success = $this->save();
				$this->enableLogging();
			}

			if ( $success && $role === 'student' ) {
				foreach ( $addedUsers as $userId ) {
					$student = EPStudent::newFromUserId( $userId, true, 'id' );
					$student->onEnrolled( $this->getId() );
					$student->save();
				}
			}

			if ( $success && !is_null( $revAction ) ) {
				$action = count( $addedUsers ) == 1 && $revAction->getUser()->getId() === $addedUsers[0] ? 'selfadd' : 'add';
				$this->logRoleChange( $action, $role, $addedUsers, $revAction->getComment() );
			}

			return $success ? count( $addedUsers ) : false;
		}
	}

	/**
	 * Remove the role for a number of users for this course,
	 * by default also saving the course and only
	 * logging the role changes.
	 *
	 * @since 0.1
	 *
	 * @param array|integer $sadUsers
	 * @param string $role
	 * @param boolean $save
	 * @param EPRevisionAction|null $revAction
	 *
	 * @return integer|false The amount of unenlisted users or false on failiure
	 */
	public function unenlistUsers( $sadUsers, $role, $save = true, EPRevisionAction $revAction = null ) {
		$sadUsers = (array)$sadUsers;

		$roleMap = array(
			'student' => 'students',
			'campus' => 'campus_ambs',
			'online' => 'online_ambs',
			'instructor' => 'instructors',
		);

		$field = $roleMap[$role];
		
		$removedUsers = array_intersect( $sadUsers, $this->getField( $field ) );

		if ( empty( $removedUsers ) ) {
			return 0;
		}
		else {
			$this->setField( $field, array_diff( $this->getField( $field ), $sadUsers ) );

			if ( $role === 'student' ) {
				// Get rid of the articles asscoaite associations with the student.
				// No revisioning is implemented here, so this cannot be undone.
				// Might want to add revisioning or just add a 'deleted' flag at some point.
				EPArticles::singleton()->delete( array(
					'course_id' => $this->getId(),
					'user_id' => $removedUsers,
				) );
			}

			$success = true;

			if ( $save ) {
				$this->disableLogging();
				$success = $this->save();
				$this->enableLogging();
			}

			if ( $success && !is_null( $revAction ) ) {
				$action = count( $removedUsers ) == 1 && $revAction->getUser()->getId() === $removedUsers[0] ? 'selfremove' : 'remove';
				$this->logRoleChange( $action, $role, $removedUsers, $revAction->getComment() );
			}

			return $success ? count( $removedUsers ) : false;
		}
	}

	/**
	 * Log a change of the instructors of the course.
	 *
	 * @since 0.1
	 *
	 * @param string $action
	 * @param string $role
	 * @param array $users
	 * @param string $message
	 */
	protected function logRoleChange( $action, $role, array $users, $message ) {
		$names = array();

		$classes = array(
			'instructor' => 'EPInstructor',
			'campus' => 'EPCA',
			'online' => 'EPOA',
			'student' => 'EPStudent',
		);
		
		$class = $classes[$role];
		
		foreach ( $users as $userId ) {
			$names[] = $class::newFromUserId( $userId )->getName();
		}

		$info = array(
			'type' => $role,
			'subtype' => $action,
			'title' => $this->getTitle(),
		);

		if ( in_array( $action, array( 'add', 'remove' ) ) ) {
			$info['parameters'] = array(
				'4::usercount' => count( $names ),
				'5::users' => $names
			);
		}

		if ( $message !== '' ) {
			$info['comment'] = $message;
		}

		EPUtils::log( $info );
	}

	/**
	 * (non-PHPdoc)
	 * @see EPRevionedObject::restoreField()
	 */
	protected function restoreField( $fieldName, $newValue ) {
		if ( $fieldName !== 'org_id'
			|| EPOrgs::singleton()->has( array( 'id' => $newValue ) ) ) {
			parent::restoreField( $fieldName, $newValue );
		}
	}

}