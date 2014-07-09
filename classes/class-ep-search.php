<?php

/**
 * EP_Search class will naturally override the WP_Query on a regular search action.
 * This makes it easier to implement and does not cause any issues if the plugin is disabled.
 *
 * @since 0.2.0
 */
class EP_Search {

	/**
	 * Dummy Constructor
	 *
	 * @since 0.2.0
	 */
	public function __construct() {
		/* Initialize via setup() method */
	}

	/**
	 * Return singleton instance of class
	 *
	 * @since 0.2.0
	 * @return EP_Search
	 */
	public static function factory() {
		static $instance = false;

		if ( ! $instance ) {
			$instance = new self();
			$instance->setup();
		}

		return $instance;
	}

	public function setup() {
		if ( ! is_admin() ) {
			$this->init_hooks();
		}
	}

	public function init_hooks() {
		// Do initial test to ensure our Elasticsearch server is functional and that our index exists - otherwise revert to the in-built WordPress search
		if ( ep_is_setup() && ep_is_alive() ) {
			//
			$test = 1;
			$is_alive = ep_is_alive();
		}
	}
}

EP_Search::factory();