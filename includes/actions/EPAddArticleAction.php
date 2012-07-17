<?php

/**
 * Add an article-student association.
 * Currently only allows students to associate articles with themselves.
 *
 * @since 0.1
 *
 * @file EPAddArticleAction.php
 * @ingroup EducationProgram
 * @ingroup Action
 *
 * @licence GNU GPL v2+
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */
class EPAddArticleAction extends FormlessAction {

	/**
	 * (non-PHPdoc)
	 * @see Action::getName()
	 */
	public function getName() {
		return 'epaddarticle';
	}

	/**
	 * (non-PHPdoc)
	 * @see FormlessAction::onView()
	 */
	public function onView() {
		$req = $this->getRequest();
		$user = $this->getUser();

		$salt = 'addarticle' . $req->getInt( 'course-id' );
		$title = Title::newFromText( $req->getText( 'addarticlename' ) );

		// TODO: some kind of warning when entering invalid title
		if ( $user->matchEditToken( $req->getText( 'token' ), $salt ) && !is_null( $title ) ) {
			$course = EPCourses::singleton()->selectRow(
				array( 'students', 'name' ),
				array( 'id' => $req->getInt( 'course-id' ) )
			);

			if ( $course !== false && in_array( $user->getId(), $course->getField( 'students' ) ) ) {
				$articleData = array(
					'user_id' => $user->getId(),
					'course_id' => $req->getInt( 'course-id' ),
					'page_id' => $title->getArticleID(),
					'page_title' => $title->getFullText(),
				);

				if ( !EPArticles::singleton()->has( $articleData ) ) {
					$article = EPArticles::singleton()->newRow( $articleData, true );

					if ( $article->save() ) {
						$article->logAdittion( $this->getUser() );
					}
				}
			}
		}

		$returnTo = null;

		if ( $req->getCheck( 'returnto' ) ) {
			$returnTo = Title::newFromText( $req->getText( 'returnto' ) );
		}

		if ( is_null( $returnTo ) ) {
			$returnTo = $this->getTitle();
		}

		$this->getOutput()->redirect( $returnTo->getLocalURL() );
		return '';
	}

}
