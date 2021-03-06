<?php

namespace EducationProgram;

use IContextSource;
use Linker;

/**
 * Student pager, primarily for Special:Students.
 *
 * @since 0.1
 *
 * @ingroup EducationProgram
 *
 * @licence GNU GPL v2+
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */
class StudentActivityPager extends EPPager {

	/**
	 * List of user ids mapped to user names and real names, set in doBatchLookups.
	 * The real names will just hold the user name when no real name is set.
	 * user id => array( user name, real name )
	 *
	 * @since 0.1
	 * @var array
	 */
	protected $userNames = [];

	/**
	 * List of course ids mapped to their titles.
	 * course id => course title
	 *
	 * @since 0.1
	 * @var array
	 */
	protected $courseTitles = [];

	/**
	 * List of course ids pointing to the id of their org.
	 * course id => org id
	 *
	 * @since 0.1
	 * @var array
	 */
	protected $courseOrgs = [];

	/**
	 * List of org ids mapped with their associated names and countries.
	 * org id => array( 'name' => org name, 'country' => country code )
	 *
	 * @since 0.1
	 * @var array
	 */
	protected $orgData = [];

	/**
	 * @param IContextSource $context
	 * @param array $conds
	 */
	public function __construct( IContextSource $context, array $conds = [] ) {
		$this->mDefaultDirection = true;
		parent::__construct( $context, $conds, Students::singleton() );
	}

	/**
	 * @see Pager::getFields()
	 */
	public function getFields() {
		return [
			'id',
			'user_id',
			'last_course',
			'last_active',
		];
	}

	/**
	 * @see TablePager::getRowClass()
	 */
	function getRowClass( $row ) {
		return 'ep-studentactivity-row';
	}

	/**
	 * @see TablePager::getTableClass()
	 */
	public function getTableClass() {
		return 'TablePager ep-studentactivity';
	}

	/**
	 * @see Pager::getFormattedValue()
	 */
	protected function getFormattedValue( $name, $value ) {
		switch ( $name ) {
			case 'user_id':
				if ( array_key_exists( $value, $this->userNames ) ) {
					list( $userName, $realName ) = $this->userNames[$value];
					$displayName = Settings::get( 'useStudentRealNames' ) ? $realName : $userName;

					$value = Linker::userLink( $value, $userName, $displayName )
						. Student::getViewLinksFor( $this->getContext(), $value, $userName );
				} else {
					$value = '';
					wfWarn( 'User id not in $this->userNames in ' . __METHOD__ );
				}
				break;
			case 'last_active':
				$value = htmlspecialchars( $this->getLanguage()->timeanddate( $value ) );
				break;
			case 'last_course':
				if ( array_key_exists( $value, $this->courseTitles ) ) {
					$value = Courses::singleton()->getLinkFor( $this->courseTitles[$value] );
				} else {
					$value = '';
					wfWarn( 'Course id not in $this->courseTitles in ' . __METHOD__ );
				}
				break;
			case 'org_id':
				$courseId = $this->currentObject->getField( 'last_course' );

				if ( array_key_exists( $courseId, $this->courseOrgs ) ) {
					$orgId = $this->courseOrgs[$courseId];

					if ( array_key_exists( $orgId, $this->orgData ) ) {
						$value = $this->orgData[$orgId]['flag'];
						$value .= Orgs::singleton()->getLinkFor( $this->orgData[$orgId]['name'] );
					} else {
						$value = '';
						wfWarn( 'Org id not in $this->orgNames in ' . __METHOD__ );
					}
				} else {
					$value = '';
					wfWarn( 'Course id not in $this->courseOrgs in ' . __METHOD__ );
				}
				break;
		}

		return $value;
	}

	/**
	 * @see Pager::getSortableFields()
	 */
	protected function getSortableFields() {
		return [
		];
	}

	/**
	 * @see EPPager::hasActionsColumn()
	 */
	protected function hasActionsColumn() {
		return false;
	}

	/**
	 * @see IndexPager::getDefaultSort()
	 */
	function getDefaultSort() {
		return 'last_active';
	}

	/**
	 * @see Pager::getFieldNames()
	 */
	public function getFieldNames() {
		$fields = parent::getFieldNames();

		unset( $fields['id'] );

		$fields = wfArrayInsertAfter( $fields, [ 'org_id' => 'org-id' ], 'user_id' );

		return $fields;
	}

	/**
	 * @see IndexPager::doBatchLookups()
	 */
	protected function doBatchLookups() {
		$userIds = [];
		$courseIds = [];

		$userField = $this->table->getPrefixedField( 'user_id' );
		$courseField = $this->table->getPrefixedField( 'last_course' );

		foreach ( $this->mResult as $student ) {
			$userIds[] = (int)$student->$userField;
			$courseIds[] = (int)$student->$courseField;
		}

		if ( !empty( $userIds ) ) {
			$result = wfGetDB( DB_SLAVE )->select(
				'user',
				[ 'user_id', 'user_name', 'user_real_name' ],
				[ 'user_id' => $userIds ],
				__METHOD__
			);

			foreach ( $result as $user ) {
				$real = $user->user_real_name === '' ? $user->user_name : $user->user_real_name;
				$this->userNames[$user->user_id] = [ $user->user_name, $real ];
			}
		}

		if ( !empty( $courseIds ) ) {
			$courses = Courses::singleton()->selectFields(
				[ 'id', 'org_id' , 'title' ],
				[ 'id' => array_unique( $courseIds ) ]
			);

			$orgIds = [];

			foreach ( $courses as $courseData ) {
				$this->courseTitles[$courseData['id']] = $courseData['title'];
				$orgIds[] = $courseData['org_id'];
				$this->courseOrgs[$courseData['id']] = $courseData['org_id'];
			}

			if ( $orgIds !== [] ) {
				$orgs = Orgs::singleton()->selectFields(
					[ 'id', 'name', 'country' ],
					[ 'id' => array_unique( $orgIds ) ]
				);

				foreach ( $orgs as $org ) {
					$this->orgData[$org['id']] = [
						'name' => $org['name'],
						'flag' => $this->getFlagHtml( $org['country'] ),
					];
				}
			}
		}
	}

	protected function getFlagHtml( $country ) {
		$file = false;
		$countryFlags = Settings::get( 'countryFlags' );

		if ( array_key_exists( $country, $countryFlags ) ) {
			$file = wfFindFile( $countryFlags[$country] );
		}

		if ( $file === false ) {
			$file = wfFindFile( Settings::get( 'fallbackFlag' ) );
		}

		if ( $file === false ) {
			wfWarn( 'Could not find fallback flag in ' . __METHOD__ );
			$flag = '';
		} else {
			$thumb = $file->transform( [
				'width' => Settings::get( 'flagWidth' ),
				'height' => Settings::get( 'flagHeight' ),
			] );

			if ( $thumb && !$thumb->isError() ) {
				$flag = $thumb->toHtml() . ' ';
			} else {
				wfWarn( 'Thumb error in ' . __METHOD__ );
				$flag = '';
			}
		}

		return $flag;
	}

	/**
	 * @see Pager::getMsg()
	 */
	protected function getMsg( $messageKey ) {
		return $this->msg( str_replace( 'educationprogram\\', 'ep', strtolower( get_called_class() ) )
			. '-' . str_replace( '_', '-', $messageKey ) )->text();
	}
}
