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

			// Ensure that cross site search is not active
			// If you'd like to use cross site search on a multisite instance, check out the EP_Query class
			$config = ep_get_option( 0 );
			if ( 0 === $config['cross_site_search_active'] ) {
				// Checks to see if we need to worry about found_posts
//				add_filter( 'post_limits_request', array( $this, 'filter__post_limits_request' ), 999, 2 );

				// Replaces the standard search query with one that fetches the posts based on post IDs supplied by ES
				add_filter( 'posts_request',       array( $this, 'filter__posts_request' ),         5, 2 );

				// Nukes the FOUND_ROWS() database query
//				add_filter( 'found_posts_query',   array( $this, 'filter__found_posts_query' ),     5, 2 );

				// Since the FOUND_ROWS() query was nuked, we need to supply the total number of found posts
//				add_filter( 'found_posts',         array( $this, 'filter__found_posts' ),           5, 2 );

				// Add our custom query var for advanced searches
//				add_filter( 'query_vars',          array( $this, 'query_vars' ) );
			}
		}
	}

	public function filter__posts_request( $sql, &$query ) {
		global $wpdb;

		if ( ! $query->is_main_query() || ! $query->is_search() ) {
			return $sql;
		}

		$page = ( $query->get( 'paged' ) ) ? absint( $query->get( 'paged' ) ) : 1;

		// Start building the WP-style search query args
		// They'll be translated to ES format args later
		$es_wp_query_args = array(
			'query'          => $query->get( 's' ),
			'posts_per_page' => $query->get( 'posts_per_page' ),
			'paged'          => $page,
		);

		$query_vars = $this->parse_query( $query );

		/**
		 * Set taxonomy terms
		 */
		if ( ! empty( $query_vars['terms'] ) ) {
			$es_wp_query_args['terms'] = $query_vars['terms'];
		}

		/**
		 * Meta query
		 */
		$meta_query = get_query_var( 'meta_query' );
		if ( isset( $meta_query ) ) {
			$es_wp_query_args['meta_query'] = $meta_query;
		}

		/**
		 * Set post types
		 */
		$es_wp_query_args['post_type'] = $query_vars['post_type'];

		/**
		 * Set date range
		 */
		if ( $query->get( 'year' ) ) {
			if ( $query->get( 'monthnum' ) ) {
				// Padding
				$date_monthnum = sprintf( '%02d', $query->get( 'monthnum' ) );

				if ( $query->get( 'day' ) ) {
					// Padding
					$date_day = sprintf( '%02d', $query->get( 'day' ) );

					$date_start = $query->get( 'year' ) . '-' . $date_monthnum . '-' . $date_day . ' 00:00:00';
					$date_end   = $query->get( 'year' ) . '-' . $date_monthnum . '-' . $date_day . ' 23:59:59';
				} else {
					$days_in_month = date( 't', mktime( 0, 0, 0, $query->get( 'monthnum' ), 14, $query->get( 'year' ) ) ); // 14 = middle of the month so no chance of DST issues

					$date_start = $query->get( 'year' ) . '-' . $date_monthnum . '-01 00:00:00';
					$date_end   = $query->get( 'year' ) . '-' . $date_monthnum . '-' . $days_in_month . ' 23:59:59';
				}
			} else {
				$date_start = $query->get( 'year' ) . '-01-01 00:00:00';
				$date_end   = $query->get( 'year' ) . '-12-31 23:59:59';
			}

			$es_wp_query_args['date_range'] = array( 'gte' => $date_start, 'lte' => $date_end );
		}

		/**
		 * Advanced search fields
		 */
		if ( ! empty( $this->sp ) ) {
			# Date from and to
			if ( ! empty( $this->sp['f'] ) && $gte = strtotime( $this->sp['f'] ) ) {
				$es_wp_query_args['date_range']['gte'] = date( 'Y-m-d 00:00:00', $gte );
			}
			if ( ! empty( $this->sp['t'] ) && $lte = strtotime( $this->sp['t'] ) ) {
				$es_wp_query_args['date_range']['lte'] = date( 'Y-m-d 23:59:59', $lte );
			}
		}

		if ( ! empty( $es_wp_query_args['date_range'] ) && empty( $es_wp_query_args['date_range']['field'] ) ) {
			$es_wp_query_args['date_range']['field'] = 'post_date';
		}

		/**
		 * Ordering
		 */
		# Set results sorting
		if ( $orderby = $query->get( 'orderby' ) ) {
			if ( in_array( $orderby, array( 'date', 'relevance' ) ) )
				$es_wp_query_args['orderby'] = $orderby;
		}

		# Set sort ordering
		if ( $order = strtolower( $query->get( 'order' ) ) ) {
			if ( isset( $es_wp_query_args['orderby'] ) && 'date' == $es_wp_query_args['orderby'] && in_array( $order, array( 'asc', 'desc' ) ) )
				$es_wp_query_args['order'] = $order;
		}

		/**
		 * Facets
		 */
		if ( ! empty( $this->facets ) ) {
			$es_wp_query_args['facets'] = $this->facets;
		}

		// You can use this filter to modify the search query parameters, such as controlling the post_type.
		// These arguments are in the format for wpcom_search_api_wp_to_es_args(), i.e. WP-style.
		$es_wp_query_args = apply_filters( 'ewp_search_wp_query_args', $es_wp_query_args, $query );

		// Convert the WP-style args into ES args
		$es_query_args = $this->wp_to_es_args( $es_wp_query_args );

		// Do the actual search query!
		# @todo hook this up to our new search mechanism
		$this->search_result = $this->search( $es_query_args );

		if ( is_wp_error( $this->search_result ) || ! is_array( $this->search_result ) || empty( $this->search_result['hits'] ) || empty( $this->search_result['hits']['hits'] ) ) {
			$this->found_posts = 0;
			return "SELECT * FROM $wpdb->posts WHERE 1=0 /* SearchPress search results */";
		}

		$post_ids = $this->get_result_post_ids( $this->search_result['hits']['hits'] );
		if ( '' === $post_ids ) {
			return '';
		}

		// Total number of results for paging purposes
		$this->found_posts = $this->search_result['hits']['total'];

		// Replace the search SQL with one that fetches the exact posts we want in the order we want
		$post_ids_string = implode( ',', array_map( 'absint', $post_ids ) );
		return "SELECT * FROM {$wpdb->posts} WHERE {$wpdb->posts}.ID IN( {$post_ids_string} ) ORDER BY FIELD( {$wpdb->posts}.ID, {$post_ids_string} ) /* SearchPress search results */";
	}

	/**
	 * Parse the query
	 *
	 * @param $query
	 *
	 * @return array
	 */
	public function parse_query( $query ) {
		$vars = array();

		# Taxonomy filters
		$terms = $this->get_valid_taxonomy_query_vars( $query );
		if ( ! empty( $terms ) ) {
			$vars['terms'] = $terms;
		}

		# Post type filters
		# @todo limit to post types enabled in settings
		$public_post_types = array_values( get_post_types( array( 'exclude_from_search' => false ) ) );

		if ( $query->get( 'post_type' ) && 'any' != $query->get( 'post_type' ) ) {
			$post_types = (array) $query->get( 'post_type' );
		} elseif ( ! empty( $_GET['post_type'] ) ) {
			$post_types = explode( ',', $_GET['post_type'] );
		} else {
			$post_types = false;
		}

		$vars['post_type'] = array();

		# Validate post types, making sure they exist and are not excluded from search
		if ( $post_types ) {
			foreach ( (array) $post_types as $post_type ) {
				if ( in_array( $post_type, $public_post_types ) ) {
					$vars['post_type'][] = $post_type;
				}
			}
		}

		if ( empty( $vars['post_type'] ) )
			$vars['post_type'] = $public_post_types;

		return $vars;
	}

	/**
	 * Get valid taxonomy query variables
	 *
	 * @param bool $query
	 *
	 * @return array
	 */
	public function get_valid_taxonomy_query_vars( $query = false ) {
		$taxonomies = get_taxonomies( array( 'public' => true ), $output = 'objects' );
		$query_vars = wp_list_pluck( $taxonomies, 'query_var' );
		if ( $query ) {
			$return = array();
			foreach ( $query->query as $qv => $value ) {
				if ( in_array( $qv, $query_vars ) ) {
					$taxonomy = array_search( $qv, $query_vars );
					$return[ $taxonomy ] = $value;
				}
			}
			return $return;
		}
		return $query_vars;
	}

	/**
	 * Translate WordPress's query arguments to Elasticsearch's DSL
	 *
	 * @param $args
	 *
	 * @return array
	 */
	public function wp_to_es_args( $args ) {
		$defaults = array(
			'query'          => null,    // Search phrase
			'query_fields'   => array(
				'post_title',
				'post_excerpt',
				'post_content',
				'post_author_name',
				'terms.category.name',
				'terms.post_tag.name'
			),

			'post_type'      => null,  // string or an array
			'terms'          => array(), // ex: array( 'taxonomy-1' => 'slug', 'taxonomy-2' => 'slug-a,slug-b', 'taxonomy-3' => 'slug-c+slug-d+slug-e' )

			'author'         => null,    // id or an array of ids
			'author_name'    => array(), // string or an array

			'date_range'     => null,    // array( 'field' => 'date', 'gt' => 'YYYY-MM-dd', 'lte' => 'YYYY-MM-dd' ); date formats: 'YYYY-MM-dd' or 'YYYY-MM-dd HH:MM:SS'

			'orderby'        => null,    // Defaults to 'relevance' if query is set, otherwise 'date'. Pass an array for multiple orders.
			'order'          => 'DESC',

			'posts_per_page' => 10,
			'offset'         => null,
			'paged'          => null,

			/**
			 * Facets. Examples:
			 * array(
			 *     'Tag'       => array( 'type' => 'taxonomy', 'taxonomy' => 'post_tag', 'count' => 10 ) ),
			 *     'Post Type' => array( 'type' => 'post_type', 'count' => 10 ) ),
			 * );
			 */
			//				'facets'         => array(
			//				     'Tag'       => array( 'type' => 'taxonomy', 'taxonomy' => 'post_tag', 'count' => 10 ),
			//				)
		);

		$raw_args = $args; // Keep a copy

		$args = wp_parse_args( $args, $defaults );

		$es_query_args = array(
			'size'    => absint( $args['posts_per_page'] ),
		);

		// ES "from" arg (offset)
		if ( $args['offset'] ) {
			$es_query_args['from'] = absint( $args['offset'] );
		} elseif ( $args['paged'] ) {
			$es_query_args['from'] = max( 0, ( absint( $args['paged'] ) - 1 ) * $es_query_args['size'] );
		}

		if ( ! is_array( $args['author_name'] ) ) {
			$args['author_name'] = array( $args['author_name'] );
		}

		// ES stores usernames, not IDs, so transform
		if ( ! empty( $args['author'] ) ) {
			if ( ! is_array( $args['author'] ) )
				$args['author'] = array( $args['author'] );
			foreach ( $args['author'] as $author ) {
				$user = get_user_by( 'id', $author );

				if ( $user && ! empty( $user->user_login ) ) {
					$args['author_name'][] = $user->user_login;
				}
			}
		}

		// Build the filters from the query elements.
		// Filters rock because they are cached from one query to the next
		// but they are cached as individual filters, rather than all combined together.
		// May get performance boost by also caching the top level boolean filter too.
		$filters = array();

		if ( $args['post_type'] ) {
			if ( ! is_array( $args['post_type'] ) ) {
				$args['post_type'] = array( $args['post_type'] );
			}
			$filters[] = array( 'terms' => array( 'post_type.raw' => $args['post_type'] ) );
		}

		if ( $args['author_name'] ) {
			$filters[] = array( 'terms' => array( 'post_author.login' => $args['author_name'] ) );
		}

		if ( !empty( $args['date_range'] ) && isset( $args['date_range']['field'] ) ) {
			$field = $args['date_range']['field'];
			unset( $args['date_range']['field'] );
			$filters[] = array( 'range' => array( $field => $args['date_range'] ) );
		}

		if ( is_array( $args['terms'] ) ) {
			foreach ( $args['terms'] as $tax => $terms ) {
				if ( strpos( $terms, ',' ) ) {
					$terms = explode( ',', $terms );
					$comp = 'or';
				} else {
					$terms = explode( '+', $terms );
					$comp = 'and';
				}

				$terms = (array) $terms;
				if ( count( $terms ) ) {
					$tax_fld = 'terms.' . $tax . '.slug';
					foreach ( $terms as $term ) {
						if ( 'and' == $comp ) {
							$filters[] = array( 'term' => array( $tax_fld => $term ) );
						} else {
							$or[] = array( 'term' => array( $tax_fld => $term ) );
						}
					}

					if ( 'or' == $comp ) {
						$filters[] = array( 'or' => $or );
					}
				}
			}
		}

		if ( ! empty( $filters ) ) {
			$es_query_args['filter'] = array( 'and' => $filters );
		} else {
			$es_query_args['filter'] = array( 'match_all' => new stdClass() );
		}

		// Allow for dynamic adjusting of query filters
		$es_query_args['filter'] = apply_filters( 'ewp_search_query_filter', $es_query_args['filter'], $args );

		// Fill in the query
		// todo: add auto phrase searching
		// todo: add fuzzy searching to correct for spelling mistakes
		// todo: boost title, tag, and category matches
		if ( isset( $args['query'] ) && ! isset( $args['s'] ) ) {
			$args_query = $args['query'];
		} else if ( ! isset( $args['query'] ) && isset( $args['s'] ) ) {
			$args_query = $args['s'];
		} else if ( isset( $args['query'] ) && isset( $args['s'] ) ) {
			$args_query = $args['query'] . ' ' . $args['s'];
		} else {
			$args_query = null;
		}

		if ( ! empty( $args_query ) ) {
			// Allow for modification of included fields as needed
			$query_fields = array(
				'post_title',
				'post_excerpt',
				'post_content',
			);
			$query_fields = apply_filters( 'ewp_query_fields', $query_fields, $args );

			//				$multi_match = array( array( 'multi_match' => array(
			//					'query'    => $args_query,
			//					'fields'   => $args['query_fields'],
			//					'operator' => 'and'
			//				) ) );
			// @todo add filter to turn on / off fuzzy matching/multi match
			//				$multi_match = $this->setup_multi_match_query( $multi_match );
			//
			//				$es_query_args['query']['bool']['must'] = $multi_match;
			$fuzziness = apply_filters( 'ewp_fuzziness', 0.5, $args );

			$es_query_args['query']['bool']['must'] = array(
				'fuzzy_like_this' => array(
					'fields' => $query_fields,
					'like_text' => $args_query,
					'min_similarity' => $fuzziness,
				)
			);

			if ( ! $args['orderby'] ) {
				$args['orderby'] = array( 'relevance' );
			}
		} else {
			if ( ! $args['orderby'] ) {
				$args['orderby'] = array( 'date' );
			}
		}

		// Validate the "order" field
		switch ( strtolower( $args['order'] ) ) {
			case 'asc':
				$args['order'] = 'asc';
				break;
			case 'desc':
			default:
				$args['order'] = 'desc';
				break;
		}

		$es_query_args['sort'] = array();
		foreach ( (array) $args['orderby'] as $orderby ) {
			// Translate orderby from WP field to ES field
			// todo: add support for sorting by title, num likes, num comments, num views, etc
			switch ( $orderby ) {
				case 'relevance' :
					$es_query_args['sort'][] = array( '_score' => array( 'order' => $args['order'] ) );
					break;
				case 'date' :
					$es_query_args['sort'][] = array( 'post_date' => array( 'order' => $args['order'] ) );
					break;
				case 'ID' :
				case 'id' :
					$es_query_args['sort'][] = array( 'post_id' => array( 'order' => $args['order'] ) );
					break;
				case 'author' :
					$es_query_args['sort'][] = array( 'author.raw' => array( 'order' => $args['order'] ) );
					break;
			}
		}
		if ( empty( $es_query_args['sort'] ) )
			unset( $es_query_args['sort'] );

		// Facets
		if ( ! isset( $args['facets'] ) ) {
			$args['facets'] = array();
		}
		$args['facets'] = apply_filters( 'ewp_facets', $args['facets'], $args );
		if ( ! empty( $args['facets'] ) ) {
			foreach ( (array) $args['facets'] as $label => $facet ) {
				switch ( $facet['type'] ) {

					case 'taxonomy':
						$es_query_args['facets'][ $label ] = array(
							'terms' => array(
								'field' => "terms.{$facet['taxonomy']}.slug",
								'size' => $facet['count'],
							),
						);

						break;

					case 'post_type':
						$es_query_args['facets'][ $label ] = array(
							'terms' => array(
								'field' => 'post_type',
								'size' => $facet['count'],
							),
						);

						break;

					case 'date_histogram':
						$es_query_args['facets'][ $label ] = array(
							'date_histogram' => array(
								'interval' => $facet['interval'],
								'field'    => ( ! empty( $facet['field'] ) && 'post_date_gmt' == $facet['field'] ) ? 'date_gmt' : 'date',
								'size'     => $facet['count'],
							),
						);

						break;
				}
			}
		}

		return $es_query_args;
	}
}

EP_Search::factory();