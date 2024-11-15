<?php
/**
 * Class containing all the necessary methods to run queries on the database.
 *
 * @package     Connections
 * @subpackage  SQL
 * @copyright   Copyright (c) 2013, Steven A. Zahm
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       unknown
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Connections_Directory\Query\Taxonomy as Taxonomy_Query;
use Connections_Directory\Request;
use Connections_Directory\Taxonomy\Registry as Taxonomy_Registry;
use Connections_Directory\Taxonomy\Term as Taxonomy_Term;
use Connections_Directory\Utility\_;
use Connections_Directory\Utility\_array;
use Connections_Directory\Utility\_format;
use Connections_Directory\Utility\_parse;
use Connections_Directory\Utility\_string;
use Connections_Directory\Utility\Convert\_length;

/**
 * Class cnRetrieve
 *
 * @phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
 * @phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital
 */
class cnRetrieve {

	/**
	 * The result count from the query with no limit.
	 *
	 * @var integer
	 */
	public $resultCountNoLimit;

	/**
	 * The $atts['meta_query'] can have two different structures when passed to
	 * {@see cnMeta_Query::parse_query_vars()}, they are:
	 *
	 * array(
	 *     'meta_key'     => (string),
	 *     'meta_value'   => (string|array),
	 *     'meta_type'    => (string),
	 *     'meta_compare' => (string)
	 * )
	 *
	 * OR
	 *
	 * array(
	 *     'meta_query' =>
	 *         array(
	 *             'key'     => (string),
	 *             'value'   => (string|array),
	 *             'compare' => (string),
	 *             'type'    => (string)
	 *         ),
	 *         [array(...),]
	 * )
	 *
	 * The latter, 'meta_query', can have multiple arrays.
	 *
	 * @since unknown
	 *
	 * @param array $atts
	 *
	 * @return array
	 */
	public function entries( $atts = array() ) {

		/** @var $wpdb wpdb */
		global $wpdb;

		// Grab an instance of the Connections object.
		$instance = Connections_Directory();
		$request  = Request::get();

		$select[]   = CN_ENTRY_TABLE . '.*';
		$from[]     = CN_ENTRY_TABLE;
		$join       = array();
		$where[]    = 'WHERE 1=1';
		$having     = array();
		$orderBy    = array();
		$random     = false;
		$visibility = array();

		/*
		 * // START -- Set the default attributes array. \\
		 */
		$defaults['list_type'] = null;
		$defaults['category']  = '';
		// Map category attributes to new attribute names and set defaults.
		$defaults['category__and']         = _array::get( $atts, 'category_in', array() );
		$defaults['category__not_in']      = _array::get( $atts, 'exclude_category', array() );
		$defaults['category_name__in']     = _array::get( $atts, 'category_name', array() );
		$defaults['category_slug__in']     = _array::get( $atts, 'category_slug', array() );
		$defaults['char']                  = '';
		$defaults['id']                    = null;
		// $defaults['id__in']                = null; // @todo Need to implement.
		$defaults['id__not_in']            = null;
		$defaults['slug']                  = null;
		$defaults['family_name']           = null;
		$defaults['last_name']             = null;
		$defaults['title']                 = null;
		$defaults['organization']          = null;
		$defaults['department']            = null;
		$defaults['district']              = null;
		$defaults['county']                = null;
		$defaults['city']                  = null;
		$defaults['state']                 = null;
		$defaults['zip_code']              = null;
		$defaults['country']               = null;
		$defaults['visibility']            = null;
		$defaults['process_user_caps']     = true;
		$defaults['status']                = array( 'approved' );
		$defaults['order_by']              = array( 'sort_column', 'last_name', 'first_name' );
		$defaults['limit']                 = null;
		$defaults['offset']                = 0;
		$defaults['meta_query']            = array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$defaults['allow_public_override'] = false;
		$defaults['private_override']      = false;
		$defaults['search_terms']          = null;

		// $atts vars to support showing entries within a specified radius.
		$defaults['near_addr'] = null;
		$defaults['latitude']  = null;
		$defaults['longitude'] = null;
		$defaults['radius']    = 10;
		$defaults['unit']      = 'mi';

		$defaults['parse_request']    = ! _array::get( $atts, 'lock', false );
		$defaults['suppress_filters'] = false;

		$atts = cnSanitize::args( $atts, $defaults );

		_format::toBoolean( $atts['parse_request'] );
		_format::toBoolean( $atts['process_user_caps'] );
		_format::toBoolean( $atts['suppress_filters'] );
		/*
		 * // END -- Set the default attributes array if not supplied. \\
		 */

		/*
		 * Process the query vars.
		 * NOTE: these will override any values supplied via $atts, which include via the shortcode.
		 */
		if ( ( $request->isAjax() || ! is_admin() ) && $atts['parse_request'] ) {

			$this->parseRequest( $atts );
		}

		/*
		 * // START -- Reset some of the $atts based if category_slug__in or entry slug
		 * is being used. This is done to prevent query conflicts. This should be safe because
		 * if a specific entry or category is being queried the other $atts can be discarded.
		 * This has to be done in order to reconcile values passed via the shortcode and the
		 * query string values.
		 *
		 * @TODO I know there are more scenarios to consider ... but this is all I can think of at the moment.
		 * Thought --- maybe resetting to the default $atts should be done --- something to consider.
		 */
		if ( ! empty( $atts['slug'] ) || ! empty( $atts['category_slug__in'] ) ) {

			$atts['list_type']        = null;
			$atts['category']         = '';
			$atts['category__and']    = '';
			$atts['category__not_in'] = '';
		}

		if ( ! empty( $atts['slug'] ) ) {

			$atts['list_type'] = null;
			$atts['near_addr'] = null;
			$atts['latitude']  = null;
			$atts['longitude'] = null;
			$atts['radius']    = 10;
			$atts['unit']      = 'mi';
		}
		/*
		 * // END -- Reset.
		 */

		$this->parseTaxonomyQuery( $atts, $join, $where );

		/*
		 * // START --> Set up the query to only return the entries that match the supplied IDs.
		 *    NOTE: This includes the entry IDs returned for category__and.
		 */
		if ( ! empty( $atts['id'] ) ) {

			$atts['id'] = wp_parse_id_list( $atts['id'] );

			// Set query string to return specific entries.
			$where[] = 'AND ' . CN_ENTRY_TABLE . '.id IN (\'' . implode( "', '", $atts['id'] ) . '\')';
		}
		/*
		 * // END --> Set up the query to only return the entries that match the supplied IDs.
		 */

		if ( ! empty( $atts['id__not_in'] ) ) {

			$atts['id__not_in'] = wp_parse_id_list( $atts['id__not_in'] );

			// Set query string to exclude specific entries.
			$where[] = 'AND ' . CN_ENTRY_TABLE . '.id NOT IN (\'' . implode( "', '", $atts['id__not_in'] ) . '\')';
		}

		/*
		 * // START --> Set up the query to only return the entries that match the supplied search terms.
		 */
		if ( ! empty( $atts['search_terms'] ) ) {
			$searchResults = $this->search( array( 'terms' => $atts['search_terms'] ) );
			// print_r($searchResults);

			// If there were no results, add a WHERE clause that will not return results when performing the whole query.
			if ( empty( $searchResults ) ) {

				$where[] = 'AND 1=2';

			} else {

				// Set $atts['order_by'] to the order the entry IDs were returned.
				// This is to support the relevancy order results being returned by self::search().
				$atts['order_by'] = 'id|SPECIFIED';
				$atts['id']       = $searchResults;

				// Set the entry IDs to be the search results.
				$where[] = 'AND ' . CN_ENTRY_TABLE . '.id IN (\'' . implode( "', '", $searchResults ) . '\')';
			}
		}
		/*
		 * // END --> Set up the query to only return the entries that match the supplied search terms.
		 */


		/*
		 * // START --> Set up the query to only return the entry that matches the supplied slug.
		 */
		if ( ! empty( $atts['slug'] ) ) {
			// Trim the white space from the ends.
			$atts['slug'] = trim( $atts['slug'] );

			//phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$where[] = $wpdb->prepare( 'AND ' . CN_ENTRY_TABLE . '.slug = %s', $atts['slug'] );
		}
		/*
		 * // END --> Set up the query to only return the entry that matches the supplied slug.
		 */

		/*
		 * // START --> Set up the query to only return the entries that match the supplied entry type.
		 */
		if ( ! empty( $atts['list_type'] ) ) {

			_parse::stringList( $atts['list_type'], ',' );

			$permittedEntryTypes = array( 'individual', 'organization', 'family', 'connection_group' );

			// Set query string for entry type.
			if ( (bool) array_intersect( $atts['list_type'], $permittedEntryTypes ) ) {

				// Compatibility code to make sure any occurrences of the deprecated entry type connections_group
				// is changed to entry type family.
				$atts['list_type'] = str_replace( 'connection_group', 'family', $atts['list_type'] );

				$where[] = cnQuery::where( array( 'field' => 'entry_type', 'value' => $atts['list_type'] ) );
			}
		}
		/*
		 * // END --> Set up the query to only return the entries that match the supplied entry type.
		 */

		/*
		 * // START --> Set up the query to only return the entries that match the supplied filters.
		 */
		$where[] = cnQuery::where( array( 'field' => 'family_name', 'value' => $atts['family_name'] ) );
		$where[] = cnQuery::where( array( 'field' => 'last_name', 'value' => $atts['last_name'] ) );
		$where[] = cnQuery::where( array( 'field' => 'title', 'value' => $atts['title'] ) );
		$where[] = cnQuery::where( array( 'field' => 'organization', 'value' => $atts['organization'] ) );
		$where[] = cnQuery::where( array( 'field' => 'department', 'value' => $atts['department'] ) );

		if ( ! empty( $atts['district'] ) || ! empty( $atts['county'] ) || ! empty( $atts['city'] ) || ! empty( $atts['state'] ) || ! empty( $atts['zip_code'] ) || ! empty( $atts['country'] ) ) {

			if ( ! isset( $join['address'] ) ) {
				$join['address'] = 'INNER JOIN ' . CN_ENTRY_ADDRESS_TABLE . ' ON ( ' . CN_ENTRY_TABLE . '.id = ' . CN_ENTRY_ADDRESS_TABLE . '.entry_id )';
			}

			$where[] = cnQuery::where( array( 'table' => CN_ENTRY_ADDRESS_TABLE, 'field' => 'district', 'value' => $atts['district'] ) );
			$where[] = cnQuery::where( array( 'table' => CN_ENTRY_ADDRESS_TABLE, 'field' => 'county', 'value' => $atts['county'] ) );
			$where[] = cnQuery::where( array( 'table' => CN_ENTRY_ADDRESS_TABLE, 'field' => 'city', 'value' => $atts['city'] ) );
			$where[] = cnQuery::where( array( 'table' => CN_ENTRY_ADDRESS_TABLE, 'field' => 'state', 'value' => $atts['state'] ) );
			$where[] = cnQuery::where( array( 'table' => CN_ENTRY_ADDRESS_TABLE, 'field' => 'zipcode', 'value' => $atts['zip_code'] ) );
			$where[] = cnQuery::where( array( 'table' => CN_ENTRY_ADDRESS_TABLE, 'field' => 'country', 'value' => $atts['country'] ) );
		}

		if ( 0 < strlen( $atts['char'] ) ) {

			$initialChar = function_exists( 'mb_substr' ) ? mb_substr( $atts['char'], 0, 1 ) : substr( $atts['char'], 0, 1 );
			$having[]    = $wpdb->prepare( 'HAVING sort_column LIKE %s', $wpdb->esc_like( $initialChar ) . '%' );
		}
		/*
		 * // END --> Set up the query to only return the entries that match the supplied filters.
		 */

		/*
		 * // START --> Set up the query to only return the entries based on user permissions.
		 */
		if ( $atts['process_user_caps'] ) {

			$where = self::setQueryVisibility( $where, $atts );
		}
		/*
		 * // END --> Set up the query to only return the entries based on user permissions.
		 */

		/*
		 * // START --> Set up the query to only return the entries based on status.
		 */
		$where = self::setQueryStatus( $where, $atts );
		/*
		 * // END --> Set up the query to only return the entries based on status.
		 */

		/*
		 * // START --> Geo-limit the query.
		 */
		// $atts['latitude'] = 40.3663671;
		// $atts['longitude'] = -75.8876941;

		if ( ! empty( $atts['latitude'] ) && ! empty( $atts['longitude'] ) ) {
			$earthRadius = 6371;  // Earth's radius in (SI) km.

			// Convert the supplied radius from the supplied unit to (SI) km.
			$atts['radius'] = _length::convert( $atts['radius'], $atts['unit'] )->to( 'km' );

			// Limiting bounding box (in degrees).
			$minLat = $atts['latitude'] - rad2deg( $atts['radius'] / $earthRadius );
			$maxLat = $atts['latitude'] + rad2deg( $atts['radius'] / $earthRadius );
			$minLng = $atts['longitude'] - rad2deg( $atts['radius'] / $earthRadius / cos( deg2rad( $atts['latitude'] ) ) );
			$maxLng = $atts['longitude'] + rad2deg( $atts['radius'] / $earthRadius / cos( deg2rad( $atts['latitude'] ) ) );

			// Convert origin of geographic circle to radians.
			$atts['latitude']  = deg2rad( $atts['latitude'] );
			$atts['longitude'] = deg2rad( $atts['longitude'] );

			// Add the SELECT statement that adds the `radius` column.
			$select[] = $wpdb->prepare( 'acos(sin(%f)*sin(radians(latitude)) + cos(%f)*cos(radians(latitude))*cos(radians(longitude)-%f))*6371 AS distance', $atts['latitude'], $atts['latitude'], $atts['longitude'] );

			// Create a sub-query that will limit the rows that have the cosine law applied to within the bounding box.
			$geoSubselect = $wpdb->prepare( '(SELECT entry_id FROM ' . CN_ENTRY_ADDRESS_TABLE . ' WHERE latitude>%f AND latitude<%f AND longitude>%f AND longitude<%f) AS geo_bound', $minLat, $maxLat, $minLng, $maxLng ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			// The sub-query needs to be added to the beginning of the array so the inner joins on the other tables are joined to the CN_ENTRY_TABLE.
			array_unshift( $from, $geoSubselect );

			// Add the JOIN for the address table. NOTE: This JOIN is also set in the ORDER BY section. The 'address' index is to make sure it does not get added to the query twice.
			if ( ! isset( $join['address'] ) ) {
				$join['address'] = 'INNER JOIN ' . CN_ENTRY_ADDRESS_TABLE . ' ON ( ' . CN_ENTRY_TABLE . '.id = ' . CN_ENTRY_ADDRESS_TABLE . '.entry_id )';
			}

			// Add the WHERE statement to limit the query to a geographic circle per the defined radius.
			$where[] = $wpdb->prepare( 'AND acos(sin(%f)*sin(radians(latitude)) + cos(%f)*cos(radians(latitude))*cos(radians(longitude)-%f))*6371 < %f', $atts['latitude'], $atts['latitude'], $atts['longitude'], $atts['radius'] );

			// This is required otherwise addresses the user may not have permissions to view will be included in the query
			// which could be confusing since entries could appear to be outside the search radius when in fact the entry
			// is within the search radius, it is just the address used to determine that is not viewable to the user.
			// $where[] = 'AND ' . CN_ENTRY_ADDRESS_TABLE . '.visibility IN (\'' . implode( "', '", (array) $visibility ) . '\')';
			$where = self::setQueryVisibility( $where, array( 'table' => CN_ENTRY_ADDRESS_TABLE ) );

			// Temporarily set the sort order to 'radius' for testing.
			// $atts['order_by'] = array('radius');
		}
		/*
		 * // END --> Geo-limit the query.
		 */

		/*
		 * // START --> Build the ORDER BY query segment.
		 */
		//if ( empty($atts['order_by']) )
		//{
		// Set up the default sort order if none were supplied.
		//$orderBy = array('sort_column', 'last_name', 'first_name');
		//}
		//else
		//{
		$orderFields = array(
			'id',
			'date_added',
			'date_modified',
			'first_name',
			'last_name',
			'title',
			'organization',
			'department',
			'city',
			'state',
			'zipcode',
			'country',
			// 'birthday',
			// 'anniversary',
			'sort_column',
		);

		$orderFlags = array(
			'SPECIFIED'         => 'SPECIFIED',
			'RANDOM'            => 'RANDOM',
			'ASC'               => 'ASC',
			'SORT_ASC'          => 'ASC',       // Alias for ASC.
			'DESC'              => 'DESC',
			'SORT_DESC'         => 'DESC',      // Alias for DESC.
			'NUMERIC'           => '+0',
			'SORT_NUMERIC'      => '+0',        // Alias for NUMERIC.
			'SORT_NUMERIC_ASC'  => '+0',        // Alias for NUMERIC.
			'NUMERIC_DESC'      => '+0 DESC',
			'SORT_NUMERIC_DESC' => '+0 DESC',   // Alias for NUMERIC_DESC.
		);

		$orderByAtts = array();

		// If a geo-bound query is being performed the `radius` order field can be used.
		if ( ! empty( $atts['latitude'] ) && ! empty( $atts['longitude'] ) ) {

			array_push( $orderFields, 'distance' );
		}

		// Get registered date types.
		$dateTypes = array_keys( cnOptions::getDateTypeOptions() );

		// Add the registered activate date types as valid order_by field options.
		$orderFields = array_merge( $orderFields, $dateTypes );

		// Convert to an array.
		_parse::stringList( $atts['order_by'], ',' );

		// For each field the sort order can be defined.
		foreach ( $atts['order_by'] as $orderByField ) {
			$orderByAtts[] = explode( '|', $orderByField );
		}

		// Build the ORDER BY query segment.
		foreach ( $orderByAtts as $field ) {
			// Trim any spaces the user may have supplied and set it to be lowercase.
			$field[0] = strtolower( trim( $field[0] ) );

			// Check to make sure the supplied field is one of the valid fields to order by.
			if ( in_array( $field[0], $orderFields ) || _string::startsWith( 'meta_key:', $field[0] ) ) {
				// The date_modified actually maps to the `ts` column in the db.
				if ( 'date_modified' === $field[0] ) {
					$field[0] = 'ts';
				}

				// If one of the order fields is an address region add the INNER JOIN to the CN_ENTRY_ADDRESS_TABLE.
				if ( 'city' === $field[0] || 'state' === $field[0] || 'zipcode' === $field[0] || 'country' === $field[0] ) {

					if ( ! isset( $join['address'] ) ) {
						$join['address'] = 'INNER JOIN ' . CN_ENTRY_ADDRESS_TABLE . ' ON ( ' . CN_ENTRY_TABLE . '.id = ' . CN_ENTRY_ADDRESS_TABLE . '.entry_id )';
					}
				}

				if ( _string::startsWith( 'meta_key:', $field[0] ) ) {

					// Extract the meta key name from $field[0].
					$meta = explode( ':', $field[0] );

					// Ensure the meta key does exist and is not empty before altering the query.
					if ( isset( $meta[1] ) && ! empty( $meta[1] ) ) {

						isset( $k ) ? $k++ : $k = 0;
						$atts                   = cnArray::add( $atts, "meta_query.meta_query.{$k}.key", $meta[1] );

						if ( 1 < count( $atts['meta_query']['meta_query'] ) ) {

							$field[0] = 'mt' . ( count( $atts['meta_query']['meta_query'] ) - 1 ) . '.meta_value';

						} else {

							$field[0] = CN_ENTRY_TABLE_META . '.meta_value';
						}
					}
				}

				// If we're ordering by anniversary or birthday, we need to convert the string to a UNIX timestamp, so it is properly ordered.
				// Otherwise, it is sorted as a string which can give some very odd results compared to what is expected.
				//if ( $field[0] == 'anniversary' || $field[0] == 'birthday' ) {
				//
				//	$field[0] = 'FROM_UNIXTIME( ' . $field[0] . ' )';
				//}

				if ( in_array( $field[0], $dateTypes ) ) {

					if ( ! isset( $join['date'] ) ) {
						$join['date'] = 'INNER JOIN ' . CN_ENTRY_DATE_TABLE . ' ON ( ' . CN_ENTRY_TABLE . '.id = ' . CN_ENTRY_DATE_TABLE . '.entry_id )';
					}

					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$where[] = $wpdb->prepare( 'AND ' . CN_ENTRY_DATE_TABLE . '.type = %s', $field[0] );

					$field[0] = 'date';
				}

				// Check to see if an order flag was set and is a valid order flag.
				if ( isset( $field[1] ) ) {
					// Trim any spaces the user might have added and change the string to uppercase.
					$field[1] = strtoupper( trim( $field[1] ) );

					// If a user included a sort flag that is invalid/misspelled it is skipped since it can not be used.
					if ( array_key_exists( $field[1], $orderFlags ) ) {

						/*
						 * The SPECIFIED and RANDOM order flags are special use and should only be used with the id sort field.
						 * Set the default sort flag if it was use on any other sort field than id.
						 */
						if ( ( 'SPECIFIED' === $orderFlags[ $field[1] ] || 'RANDOM' === $orderFlags[ $field[1] ] ) && 'id' !== $field[0] ) {
							$field[1] = 'SORT_ASC';
						}

						switch ( $orderFlags[ $field[1] ] ) {

							/*
							 * Order the results based on the order of the supplied entry IDs
							 */
							case 'SPECIFIED':
								if ( ! empty( $atts['id'] ) ) {
									$orderBy = array( 'FIELD( ' . CN_ENTRY_TABLE . '.id, ' . implode( ', ', (array) $atts['id'] ) . ' )' );
								}
								break;

							/*
							 * Randomize the order of the results.
							 */
							case 'RANDOM':
								$random = true;
								break;

								/*
								 * Return the results in ASC or DESC order.
								 */
							default:
								$orderBy[] = $field[0] . ' ' . $orderFlags[ $field[1] ];
								break;
						}

					} else {

						$orderBy[] = $field[0];
					}

				} else {

					$orderBy[] = $field[0];
				}
			}
		}
		// }

		if ( ! empty( $atts['meta_query'] ) ) {

			$metaQuery = new cnMeta_Query();
			$metaQuery->parse_query_vars( $atts['meta_query'] );
			$metaClause = $metaQuery->get_sql( 'entry', CN_ENTRY_TABLE, 'id' );

			$join['meta']  = $metaClause['join'];
			$where['meta'] = $metaClause['where'];
		}

		$orderBy = empty( $orderBy ) ? 'ORDER BY sort_column, last_name, first_name' : 'ORDER BY ' . implode( ', ', $orderBy );
		/*
		 * // END --> Build the ORDER BY query segment.
		 */

		/*
		 * // START --> Set up the query LIMIT and OFFSET.
		 */
		$limit  = empty( $atts['limit'] ) ? '' : $wpdb->prepare( ' LIMIT %d ', $atts['limit'] );
		$offset = empty( $atts['offset'] ) ? '' : $wpdb->prepare( ' OFFSET %d ', $atts['offset'] );
		/*
		 * // END --> Set up the query LIMIT and OFFSET.
		 */

		/*
		 * // START --> Build the SELECT query segment.
		 */
		$select[] = 'CASE `entry_type`
						  WHEN \'individual\' THEN `last_name`
						  WHEN \'organization\' THEN `organization`
						  WHEN \'connection_group\' THEN `family_name`
						  WHEN \'family\' THEN `family_name`
						END AS `sort_column`';
		/*
		 * // END --> Build the SELECT query segment.
		 */

		if ( ! $atts['suppress_filters'] ) {

			$pieces = array( 'select', 'from', 'join', 'where', 'having', 'orderBy', 'limit', 'offset' );

			/**
			 * Filter the query SQL clauses.
			 *
			 * @since 8.5.14
			 *
			 * @param array $pieces Terms query SQL clauses.
			 */
			$clauses = apply_filters( 'cn_entry_query_clauses', compact( $pieces ) );

			foreach ( $pieces as $piece ) {

				$$piece = isset( $clauses[ $piece ] ) ? $clauses[ $piece ] : '';
			}
		}

		/**
		 * NOTES:
		 *
		 * Many queries can produce multiple results per entry ID when we really only want it once.
		 * For example an entry maybe return once for each category it is assigned or once for each
		 * address an entry has that is within the search radius.
		 *
		 * Simply adding `GROUP BY CN_ENTRY_TABLE.id seems to fix this, but may be incorrect and might fail
		 * on db/s other than MySQL such as Oracle.
		 *
		 * Very useful links that provide more details that require further study:
		 *
		 * @link http://www.psce.com/blog/2012/05/15/mysql-mistakes-do-you-use-group-by-correctly/
		 * @link http://rpbouman.blogspot.com/2007/05/debunking-group-by-myths.html
		 */

		if ( $random ) {

			$seed = _string::stripNonNumeric( _::getIP() ) . date( 'Hdm', current_time( 'timestamp', 1 ) );

			$seed = apply_filters( 'cn_entry_query_random_seed', $seed, $atts );

			$sql = 'SELECT SQL_CALC_FOUND_ROWS *, RAND(' . $seed . ') AS random FROM ( SELECT DISTINCT ' . implode( ', ', $select ) . ' FROM ' . implode( ', ', $from ) . ' ' . implode( ' ', $join ) . ' ' . implode( ' ', $where ) . ' GROUP BY ' . CN_ENTRY_TABLE . '.id ' . implode( ' ', $having ) . ') AS T ORDER BY random' . $limit . $offset;
			// print_r($sql);

		} else {

			$sql = 'SELECT SQL_CALC_FOUND_ROWS DISTINCT ' . implode( ', ', $select ) . ' FROM ' . implode( ', ', $from ) . ' ' . implode( ' ', $join ) . ' ' . implode( ' ', $where ) . ' GROUP BY ' . CN_ENTRY_TABLE . '.id ' . implode( ' ', $having ) . ' ' . $orderBy . ' ' . $limit . $offset;
			// print_r($sql);
		}

		// if ( ! $results = $this->results( $sql ) ) {

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$results = $wpdb->get_results( $sql );

			// $this->cache( $sql, $results );

			// The most recent query to have been executed by cnRetrieve::entries.
			$instance->lastQuery = $wpdb->last_query;

			// The most recent query error to have been generated by cnRetrieve::entries.
			$instance->lastQueryError = $wpdb->last_error;

			// ID generated for an AUTO_INCREMENT column by the most recent INSERT query.
			$instance->lastInsertID = $wpdb->insert_id;

			// The number of rows returned by the last query.
			$instance->resultCount = $wpdb->num_rows;

			// The number of rows returned by the last query without the limit clause set.
			$foundRows                    = $wpdb->get_results( 'SELECT FOUND_ROWS()' );
			$instance->resultCountNoLimit = $foundRows[0]->{'FOUND_ROWS()'};
			$this->resultCountNoLimit     = $foundRows[0]->{'FOUND_ROWS()'};

		// }

		// The total number of entries based on user permissions.
		// $instance->recordCount         = self::recordCount( array( 'public_override' => $atts['allow_public_override'], 'private_override' => $atts['private_override'] ) );

		// The total number of entries based on user permissions with the status set to 'pending'
		// $instance->recordCountPending  = self::recordCount( array( 'public_override' => $atts['allow_public_override'], 'private_override' => $atts['private_override'], 'status' => array( 'pending' ) ) );

		// The total number of entries based on user permissions with the status set to 'approved'
		// $instance->recordCountApproved = self::recordCount( array( 'public_override' => $atts['allow_public_override'], 'private_override' => $atts['private_override'], 'status' => array( 'approved' ) ) );

		/*
		 * ONLY in the admin.
		 *
		 * Reset the pagination filter for the current user, remove the offset from the query and re-run the
		 * query if the offset for the query is greater than the record count with no limit set in the query.
		 *
		 */
		if ( is_admin() && $atts['offset'] > $instance->resultCountNoLimit ) {

			$instance->currentUser->resetFilterPage( 'manage' );
			unset( $atts['offset'] );
			$results = $this->entries( $atts );

		} elseif ( $atts['offset'] > $instance->resultCountNoLimit ) {

			/*
			 * This is for the front end, reset the page and offset and re-run the query if the offset
			 * is greater than the record count with no limit.
			 *
			 * @TODO  this should somehow be precessed in the parse_request action hook so the URL
			 * permalink and query vars can be properly updated.
			 */

			set_query_var( 'cn-pg', 0 );
			Request::get()->setVar( 'cn-pg', 0 );

			// $atts['parse_request'] = false;
			$atts['offset']        = 0;
			$results               = $this->entries( $atts );
		}

		return $results;
	}

	/**
	 * Process the request query.
	 *
	 * @internal
	 * @since 10.3
	 *
	 * @param array $atts
	 *
	 * @return array
	 */
	private function parseRequest( &$atts ) {

		/** @var $wpdb wpdb */
		global $wpdb;

		$request = Request::get();

		foreach ( $request->getQueryVars() as $queryKey => $queryVar ) {

			switch ( $queryKey ) {

				case 'cn-cat':
					$atts['category'] = $queryVar;
					break;

				case 'cn-cat-in':
					$atts['category__and'] = $queryVar;
					break;

				case 'cn-cat-slug':
					$atts['category_slug__in'] = $queryVar;
					break;

				case 'cn-tag':
					$atts['tag'] = $queryVar;
					break;

				case 'cn-country':
					$atts['country'] = $queryVar;
					break;

				case 'cn-postal-code':
					$atts['zip_code'] = $queryVar;
					break;

				case 'cn-region':
					$atts['state'] = $queryVar;
					break;

				case 'cn-locality':
					$atts['city'] = $queryVar;
					break;

				case 'cn-county':
					$atts['county'] = $queryVar;
					break;

				case 'cn-district':
					$atts['district'] = $queryVar;
					break;

				case 'cn-organization':
					$atts['organization'] = $queryVar;
					break;

				case 'cn-department':
					$atts['department'] = $queryVar;
					break;

				case 'cn-char':
					$atts['char'] = $queryVar;
					break;

				case 'cn-s':
					$atts['search_terms'] = $queryVar;
					break;

				case 'cn-pg':
					$atts['offset'] = ( ! empty( $queryVar ) ) ? ( $queryVar - 1 ) * $atts['limit'] : $atts['offset'];
					$atts['offset'] = ( $atts['offset'] > 0 ) ? $atts['offset'] : null;
					break;

				case 'cn-entry-slug':
					// NOTE: The entry slug is saved in the DB URL encoded, so there's no need to urldecode().
					$atts['slug'] = $queryVar;
					break;

				case 'cn-near-coord':
					if ( ! empty( $queryVar ) ) {

						$queryCoord        = explode( ',', $queryVar );
						$atts['latitude']  = $wpdb->prepare( '%f', $queryCoord[0] );
						$atts['longitude'] = $wpdb->prepare( '%f', $queryCoord[1] );

						// Get the radius, otherwise the default of 10.
						$atts['radius'] = $wpdb->prepare( '%f', $request->getVar( 'cn-radius', _array::get( $atts, 'radius', 10 ) ) );

						// Sanitize and set the unit.
						$atts['unit'] = sanitize_key( $request->getVar( 'cn-unit', _array::get( $atts, 'unit', 'mi' ) ) );
					}
					break;

				default:
					// Set additional request query key/values pairs.
					_array::set( $atts, "request.{$queryKey}", $queryVar );
			}
		}

		return $atts;
	}

	/**
	 * NOTE: This is the Connections equivalent of parse_tax_query() found in ../wp-includes/class-wp-query.php
	 * {@see WP_Query::parse_tax_query()}
	 *
	 * @internal
	 * @since 10.3
	 *
	 * @param array $atts
	 * @param array $join
	 * @param array $where
	 */
	private function parseTaxonomyQuery( $atts, &$join, &$where ) {

		// $request   = Request::get();
		$tax_query = array();
		$q         = array();

		$q['cat']               = _array::get( $atts, 'category', '' );
		$q['category__and']     = wp_parse_id_list( _array::get( $atts, 'category__and', array() ) );
		$q['category__in']      = wp_parse_id_list( _array::get( $atts, 'category__in', array() ) );
		$q['category__not_in']  = wp_parse_id_list( _array::get( $atts, 'category__not_in', array() ) );
		$q['category_name__in'] = _array::get( $atts, 'category_name__in', array() );
		$q['category_slug__in'] = _array::get( $atts, 'category_slug__in', array() );
		$q['tag']               = _array::get( $atts, 'tag', '' );
		$q['tag_id']            = _array::get( $atts, 'tag_id', '' );
		$q['tag__and']          = _array::get( $atts, 'tag__and', array() );
		$q['tag__in']           = _array::get( $atts, 'tag__in', array() );
		$q['tag__not_in']       = _array::get( $atts, 'tag__not_in', array() );
		$q['tag_slug__and']     = _array::get( $atts, 'tag_slug__and', array() );
		$q['tag_slug__in']      = _array::get( $atts, 'tag_slug__in', array() );
		$q['taxonomy']          = _array::get( $atts, 'taxonomy', '' );
		$q['term']              = _array::get( $atts, 'term', '' );

		_parse::stringList( $q['category_name__in'], ',' );
		_parse::stringList( $q['category_slug__in'], ',' );

		if ( ! empty( $q['taxonomy'] ) && ! empty( $q['term'] ) ) {

			$tax_query[] = array(
				'taxonomy' => $q['taxonomy'],
				'terms'    => array( $q['term'] ),
				'field'    => 'slug',
			);
		}

		foreach ( Taxonomy_Registry::get()->getTaxonomies() as $taxonomy ) {

			if ( 'tag' === $taxonomy->getSlug() /*$taxonomy->isBuiltin()*/ ) {
				continue; // Handled further down in the $q['tag'] block.
			}

			// $q[ $taxonomy->getQueryVar() ] = $request->getVar( $taxonomy->getQueryVar() );
			$q[ $taxonomy->getQueryVar() ] = _array::get( $atts, "request.{$taxonomy->getQueryVar()}", '' );

			if ( $taxonomy->getQueryVar() && ! empty( $q[ $taxonomy->getQueryVar() ] ) ) {

				$tax_query_defaults = array(
					'taxonomy' => $taxonomy->getSlug(),
					'field'    => 'slug',
				);

				if ( $taxonomy->isHierarchical() ) {
					$q[ $taxonomy->getQueryVar() ] = wp_basename( $q[ $taxonomy->getQueryVar() ] );
				}

				$term = $q[ $taxonomy->getQueryVar() ];

				if ( is_array( $term ) ) {
					$term = implode( ',', $term );
				}

				if ( strpos( $term, '+' ) !== false ) {
					$terms = preg_split( '/[+]+/', $term );
					foreach ( $terms as $term ) {
						$tax_query[] = array_merge(
							$tax_query_defaults,
							array(
								'terms' => array( $term ),
							)
						);
					}
				} else {
					$tax_query[] = array_merge(
						$tax_query_defaults,
						array(
							'terms' => preg_split( '/[,]+/', $term ),
						)
					);
				}
			}
		}

		// If query string 'cat' is an array, implode it.
		if ( is_array( $q['cat'] ) ) {
			$q['cat'] = implode( ',', $q['cat'] );
		}

		// Category stuff.
		// @todo Add isSingular() test to Request.
		if ( ! empty( $q['cat'] ) /*&& ! $this->is_singular*/ ) {
			$cat_in     = array();
			$cat_not_in = array();

			$cat_array = preg_split( '/[,\s]+/', urldecode( $q['cat'] ) );
			$cat_array = array_map( 'intval', $cat_array );
			$q['cat']  = implode( ',', $cat_array );

			foreach ( $cat_array as $cat ) {
				if ( $cat > 0 ) {
					$cat_in[] = $cat;
				} elseif ( $cat < 0 ) {
					$cat_not_in[] = abs( $cat );
				}
			}

			if ( ! empty( $cat_in ) ) {
				$tax_query[] = array(
					'taxonomy'         => 'category',
					'terms'            => $cat_in,
					'field'            => 'term_id',
					'include_children' => true,
				);
			}

			if ( ! empty( $cat_not_in ) ) {
				$tax_query[] = array(
					'taxonomy'         => 'category',
					'terms'            => $cat_not_in,
					'field'            => 'term_id',
					'operator'         => 'NOT IN',
					'include_children' => true,
				);
			}

			unset( $cat_array, $cat_in, $cat_not_in );
		}

		if ( ! empty( $q['category__and'] ) && 1 === count( (array) $q['category__and'] ) ) {
			$q['category__and'] = (array) $q['category__and'];
			if ( ! isset( $q['category__in'] ) ) {
				$q['category__in'] = array();
			}
			$q['category__in'][] = absint( reset( $q['category__and'] ) );
			unset( $q['category__and'] );
		}

		if ( ! empty( $q['category__in'] ) ) {
			$q['category__in'] = array_map( 'absint', array_unique( (array) $q['category__in'] ) );
			$tax_query[]       = array(
				'taxonomy'         => 'category',
				'terms'            => $q['category__in'],
				'field'            => 'term_id',
				'include_children' => false,
			);
		}

		if ( ! empty( $q['category__not_in'] ) ) {
			$q['category__not_in'] = array_map( 'absint', array_unique( (array) $q['category__not_in'] ) );
			$tax_query[]           = array(
				'taxonomy'         => 'category',
				'terms'            => $q['category__not_in'],
				'operator'         => 'NOT IN',
				'include_children' => true, // WP core defaults to `false`, to match legacy Connections, set default to `true`.
			);
		}

		if ( ! empty( $q['category__and'] ) ) {
			$q['category__and'] = array_map( 'absint', array_unique( (array) $q['category__and'] ) );
			$tax_query[]        = array(
				'taxonomy'         => 'category',
				'terms'            => $q['category__and'],
				'field'            => 'term_id',
				'operator'         => 'AND',
				'include_children' => false,
			);
		}

		if ( ! empty( $q['category_name__in'] ) ) {
			$q['category_name__in'] = array_map( 'htmlspecialchars', array_unique( (array) $q['category_name__in'] ) );
			$tax_query[]            = array(
				'taxonomy'         => 'category',
				'terms'            => $q['category_name__in'],
				'field'            => 'name',
				'operator'         => 'IN',
				'include_children' => true,
			);
		}

		if ( ! empty( $q['category_slug__in'] ) ) {
			$q['category_slug__in'] = array_map( 'sanitize_title', array_unique( (array) $q['category_slug__in'] ) );
			$tax_query[]            = array(
				'taxonomy'         => 'category',
				'terms'            => $q['category_slug__in'],
				'field'            => 'slug',
				'operator'         => 'IN',
				'include_children' => true,
			);
		}

		// If query string 'tag' is array, implode it.
		if ( is_array( $q['tag'] ) ) {
			$q['tag'] = implode( ',', $q['tag'] );
		}

		// Tag stuff.

		if ( '' !== $q['tag'] /*&& ! $this->is_singular && $this->query_vars_changed*/ ) {
			if ( strpos( $q['tag'], ',' ) !== false ) {
				$tags = preg_split( '/[,\r\n\t ]+/', $q['tag'] );
				foreach ( (array) $tags as $tag ) {
					$tag                 = sanitize_term_field( 'slug', $tag, 0, 'tag', 'db' );
					$q['tag_slug__in'][] = $tag;
				}
			} elseif ( preg_match( '/[+\r\n\t ]+/', $q['tag'] ) || ! empty( $q['cat'] ) ) {
				$tags = preg_split( '/[+\r\n\t ]+/', $q['tag'] );
				foreach ( (array) $tags as $tag ) {
					$tag                  = sanitize_term_field( 'slug', $tag, 0, 'tag', 'db' );
					$q['tag_slug__and'][] = $tag;
				}
			} else {
				$q['tag']            = sanitize_term_field( 'slug', $q['tag'], 0, 'tag', 'db' );
				$q['tag_slug__in'][] = $q['tag'];
			}
		}

		if ( ! empty( $q['tag_id'] ) ) {
			$q['tag_id'] = absint( $q['tag_id'] );
			$tax_query[] = array(
				'taxonomy' => 'tag',
				'terms'    => $q['tag_id'],
			);
		}

		if ( ! empty( $q['tag__in'] ) ) {
			$q['tag__in'] = array_map( 'absint', array_unique( (array) $q['tag__in'] ) );
			$tax_query[]  = array(
				'taxonomy' => 'tag',
				'terms'    => $q['tag__in'],
			);
		}

		if ( ! empty( $q['tag__not_in'] ) ) {
			$q['tag__not_in'] = array_map( 'absint', array_unique( (array) $q['tag__not_in'] ) );
			$tax_query[]      = array(
				'taxonomy' => 'tag',
				'terms'    => $q['tag__not_in'],
				'operator' => 'NOT IN',
			);
		}

		if ( ! empty( $q['tag__and'] ) ) {
			$q['tag__and'] = array_map( 'absint', array_unique( (array) $q['tag__and'] ) );
			$tax_query[]   = array(
				'taxonomy' => 'tag',
				'terms'    => $q['tag__and'],
				'operator' => 'AND',
			);
		}

		if ( ! empty( $q['tag_slug__in'] ) ) {
			$q['tag_slug__in'] = array_map( 'sanitize_title_for_query', array_unique( (array) $q['tag_slug__in'] ) );
			$tax_query[]       = array(
				'taxonomy' => 'tag',
				'terms'    => $q['tag_slug__in'],
				'field'    => 'slug',
			);
		}

		if ( ! empty( $q['tag_slug__and'] ) ) {
			$q['tag_slug__and'] = array_map( 'sanitize_title_for_query', array_unique( (array) $q['tag_slug__and'] ) );
			$tax_query[]        = array(
				'taxonomy' => 'tag',
				'terms'    => $q['tag_slug__and'],
				'field'    => 'slug',
				'operator' => 'AND',
			);
		}

		$query  = new Taxonomy_Query( $tax_query );
		$clause = $query->get_sql( CN_ENTRY_TABLE, 'id' );

		$join[]  = $clause['join'];
		$where[] = $clause['where'];
	}

	/**
	 * Retrieve a single entry by either `id`, `email`, or `slug`.
	 *
	 * @since 8.42
	 *
	 * @param string     $field The field to get the entry by.
	 * @param int|string $value The value of the field to match.
	 *
	 * @return bool|object
	 */
	public static function getEntryBy( $field, $value ) {

		/** @var $wpdb wpdb */
		global $wpdb;

		$validFields = array( 'id', 'email', 'slug' );

		if ( ! in_array( $field, $validFields ) ) {

			return false;
		}

		switch ( $field ) {

			case 'id':
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$sql = $wpdb->prepare( 'SELECT * FROM ' . CN_ENTRY_TABLE . ' WHERE id=%d', $value );
				break;

			case 'slug':
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$sql = $wpdb->prepare( 'SELECT * FROM ' . CN_ENTRY_TABLE . ' WHERE slug=%s', $value );
				break;

			case 'email':
				$email = self::emailAddresses(
					array(
						'fields'  => 'ids',
						'address' => $value,
						'limit'   => 1,
					)
				);

				if ( empty( $email ) ) {

					return false;

				} else {

					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$sql = $wpdb->prepare( 'SELECT * FROM ' . CN_ENTRY_TABLE . ' WHERE id=%d', $email[0]['entry_id'] );
				}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->get_row( $sql );

		if ( is_null( $result ) ) {

			return false;

		} else {

			return $result;
		}
	}

	/**
	 * Retrieve a single entry by either `id` or `slug`.
	 *
	 * @since unknown
	 *
	 * @param int|string $slid The entry `id` or `slug`.
	 *
	 * @return false|object The entry data.
	 */
	public function entry( $slid ) {

		/** @var $wpdb wpdb */
		global $wpdb;

		if ( ctype_digit( (string) $slid ) ) {

			$field = 'id';

		} else {

			$field = 'slug';
		}

		$value = $slid;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CN_ENTRY_TABLE . ' WHERE ' . $field . '=%s', $value ) );

		if ( is_null( $result ) ) {

			return false;

		} else {

			return $result;
		}
	}

	/**
	 * @param array $atts
	 *
	 * @return array
	 */
	public static function individuals( $atts = array() ) {

		/** @var wpdb $wpdb */
		global $wpdb;

		$out     = array();
		$where[] = 'WHERE 1=1';

		$defaults = array(
			'status'                => array( 'approved' ),
			'visibility'            => array(),
			'allow_public_override' => false,
			'private_override'      => false,
		);

		$atts = wp_parse_args( $atts, $defaults );

		// Limit the results to the "individual" entry type.
		$where[] = 'AND `entry_type` = \'individual\'';

		// Limit the characters that are queried based on if the current user can view public, private or unlisted entries.
		$where = self::setQueryVisibility( $where, $atts );

		// Limit the characters that are queried based on if the current user can view approved and/or pending entries.
		$where = self::setQueryStatus( $where, $atts );

		// Create the "Last Name, First Name".
		$select = '`id`, CONCAT( `last_name`, \', \', `first_name` ) as name';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( 'SELECT DISTINCT ' . $select . ' FROM ' . CN_ENTRY_TABLE . ' ' . implode( ' ', $where ) . ' ORDER BY `last_name`' );

		foreach ( $results as $row ) {

			$out[ $row->id ] = $row->name;
		}

		return $out;
	}

	/**
	 * Retrieve the unique initial characters of all entries in the entry table sorted by character.
	 *
	 * @since 0.7.4
	 *
	 * @param array $atts
	 *
	 * @return string[]
	 */
	public static function getCharacters( $atts = array() ) {

		/** @var wpdb $wpdb */
		global $wpdb;
		$where[] = 'WHERE 1=1';

		$defaults = array(
			'status'                => array( 'approved' ),
			'visibility'            => array(),
			'allow_public_override' => false,
			'private_override'      => false,
		);

		$atts = wp_parse_args( $atts, $defaults );

		// Limit the characters that are queried based on if the current user can view public, private or unlisted entries.
		$where = self::setQueryVisibility( $where, $atts );

		// Limit the characters that are queried based on if the current user can view approved and/or pending entries.
		$where = self::setQueryStatus( $where, $atts );

		$select = 'SUBSTRING( CASE `entry_type`
					  WHEN \'individual\' THEN `last_name`
					  WHEN \'organization\' THEN `organization`
					  WHEN \'connection_group\' THEN `family_name`
					  WHEN \'family\' THEN `family_name`
					END, 1, 1 ) AS `char`';

		$results = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'SELECT DISTINCT ' . $select . ' FROM ' . CN_ENTRY_TABLE . ' ' . implode( ' ', $where ) . ' ORDER BY `char`'
		);

		return array_filter( $results, 'is_string' );
	}

	/**
	 * Set up the query to only return the entries based on user permissions.
	 *
	 * @param array $where
	 * @param array $atts
	 *
	 * @internal
	 * @since 0.7.4
	 *
	 * @return array
	 */
	public static function setQueryVisibility( $where, $atts = array() ) {

		// Grab an instance of the Connections object.
		$instance = Connections_Directory();

		$visibility = array();

		$defaults = array(
			'table'                 => CN_ENTRY_TABLE,
			'visibility'            => array(),
			'allow_public_override' => false,
			'private_override'      => false,
		);

		$atts = cnSanitize::args( $atts, $defaults );

		if ( is_user_logged_in() ) {

			if ( empty( $atts['visibility'] ) ) {

				if ( current_user_can( 'connections_view_public' ) || ! cnOptions::loginRequired() ) {

					$visibility[] = 'public';
				}

				if ( current_user_can( 'connections_view_private' ) ) {
					$visibility[] = 'private';
				}

				if ( current_user_can( 'connections_view_unlisted' ) &&
					 ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) ) {

					$visibility[] = 'unlisted';
				}

				// var_dump( $visibility );
				if ( empty( $visibility ) ) {
					$visibility[] = 'none';
				}

			} else {

				// Convert the supplied entry statuses $atts['visibility'] to an array.
				_parse::stringList( $atts['visibility'] );

				$visibility = $atts['visibility'];
			}

		} else {
			// var_dump( $connections->options->getAllowPublic() ); die;

			// Display the 'public' entries if the user is not required to be logged in.
			if ( ! cnOptions::loginRequired() ) {
				$visibility[] = 'public';
			}

			// Display the 'public' entries if the public override shortcode option is enabled.
			if ( $instance->options->getAllowPublicOverride() ) {
				if ( true === $atts['allow_public_override'] ) {
					$visibility[] = 'public';
				}
			}

			// Display the 'public' & 'private' entries if the private override shortcode option is enabled.
			if ( $instance->options->getAllowPrivateOverride() ) {
				// If the user can view private entries then they should be able to view public entries too, so we'll add it. Just check to see if it is already set first.
				if ( ! in_array( 'public', $visibility ) && true === $atts['private_override'] ) {
					$visibility[] = 'public';
				}

				if ( true === $atts['private_override'] ) {
					$visibility[] = 'private';
				}
			}

			if ( empty( $visibility ) ) {
				$visibility[] = 'none';
			}
		}

		$where[] = cnQuery::where( array( 'table' => $atts['table'], 'field' => 'visibility', 'value' => $visibility ) );

		return $where;
	}

	/**
	 * Set up the query to only return the entries based on status.
	 *
	 * @internal
	 * @since 0.7.4
	 *
	 * @param array $where
	 * @param array $atts
	 *
	 * @return array
	 */
	public static function setQueryStatus( $where, $atts = array() ) {

		$valid     = array( 'approved', 'pending' );
		$permitted = array( 'approved' );
		$defaults  = array(
			'status' => array( 'approved' ),
		);

		$atts = cnSanitize::args( $atts, $defaults );

		// Convert the supplied entry statuses $atts['status'] to an array.
		$status = _parse::stringList( $atts['status'], ',' );

		if ( is_user_logged_in() ) {

			// If 'all' was supplied, set the array to all the permitted entry status types.
			if ( in_array( 'all', $status ) ) {

				$status = $valid;
			}

			// If the current user can edit entries, then they should have permission to view both approved and pending.
			if ( current_user_can( 'connections_edit_entry' ) || current_user_can( 'connections_edit_entry_moderated' ) ) {

				$permitted = array( 'approved', 'pending' );
			}

		} else {

			// A non-logged in user should only have permission to view approved entries.
			$status = array( 'approved' );
		}

		$status = array_intersect( $permitted, $status );

		// Permit only the supported statuses to be queried.
		$status = array_intersect( $status, $valid );

		$where[] = cnQuery::where( array( 'field' => 'status', 'value' => $status ) );

		return $where;
	}

	/**
	 * Query the CN_ENTRY_DATE_TABLE for upcoming date events. Will return an array if entry ID/s
	 * or an array of objects ordered from sooner to later over spanning the course of n-days.
	 *
	 * @since unknown
	 *
	 * @param array $atts {
	 *     Optional.
	 *
	 *     @type string       $type                  The date event type to query. Default is 'birthday'.
	 *                                               Accepts any array keys @see cnOptions::getDateOptions().
	 *     @type int          $days                  The number of days to look forward. Default is 30.
	 *     @type bool         $today                 Whether of not to include events occurring today. Default is FALSE.
	 *     @type array|string $visibility
	 *     @type bool         $allow_public_override Default is FALSE.
	 *     @type bool         $private_override      Default is FALSE.
	 *     @type string       $return                What to return. Default is 'data'. Accepts data|id.
	 * }
	 *
	 * @return array An array of entry ID/s or an array of objects.
	 */
	public function upcoming( $atts = array() ) {

		/** @var $wpdb wpdb */
		global $wpdb;

		$permitted = array_keys( cnOptions::getDateTypeOptions() );
		$where     = array();
		$results   = array();

		$defaults = array(
			'type'                  => 'birthday',
			'days'                  => 30,
			'today'                 => true,
			'visibility'            => array(),
			'allow_public_override' => false,
			'private_override'      => false,
			'return'                => 'data', // Valid options are `data` which are the results returned from self::entries() or `id` which are the entry ID/s.
			'process_user_caps'     => true,
			'from_timestamp'        => current_time( 'timestamp' ),
		);

		$atts = cnSanitize::args( $atts, $defaults );
		cnFormatting::toBoolean( $atts['process_user_caps'] );

		/*
		 * Now that date types can be disabled, if the type being requested is off or invalid, return `0` results
		 * instead of defaulting to the `birthday` date type as was done prior to versions >= 8.28.3.
		 */
		if ( ! in_array( $atts['type'], $permitted ) ) {

			return $results;
		}

		$where[] = $wpdb->prepare( 'AND `type` = %s', $atts['type'] );

		// Respect the date visibility set by the user when adding the date.
		if ( $atts['process_user_caps'] ) {

			$where = self::setQueryVisibility( $where, array_merge( $atts, array( 'table' => CN_ENTRY_DATE_TABLE ) ) );
		}

		// Get timestamp.
		$time = $atts['from_timestamp'];

		// Get today's date, formatted for use in the query.
		$date = gmdate( 'Y-m-d', (int) $atts['from_timestamp'] );

		// Whether to include the event occurring today or not.
		$includeToday = $atts['today'] ? '<=' : '<';

		/*
		 * @todo Add option to filter out results less than a year old.
		 */
		// $where[] = $wpdb->prepare( 'AND 0 < ( YEAR( %s ) - YEAR( date ) )', $date );

		$sql = $wpdb->prepare(
			'SELECT entry_id AS id, date FROM ' . CN_ENTRY_DATE_TABLE . ' WHERE '
			. '  ( YEAR( DATE_ADD( %s, INTERVAL %d DAY ) )'
			. ' - YEAR( date ) )'
			. ' - ( MID( DATE_ADD( %s, INTERVAL %d DAY ), 6, 5 )'
			. ' < MID( date, 6, 5 ) )'
			. ' > ( YEAR( %s )'
			. ' - YEAR( date ) )'
			. ' - ( MID( %s, 6, 5 )'
			. ' ' . $includeToday . ' MID( date, 6, 5 ) )'
			. ' ' . implode( ' ', $where ),
			$date,
			absint( $atts['days'] ),
			$date,
			absint( $atts['days'] ),
			$date,
			$date
		);
		// print_r($sql);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$upcoming = $wpdb->get_results( $sql );
		// var_dump($upcoming);

		// The date is stored in YYYY-MM-DD format, we must convert to UNIX time.
		// We need to use PHP to do the conversion because MySQL UNIX_TIMESTAMP() will return 0 for pre 1970-01-01 dates.
		if ( ! empty( $upcoming ) ) {

			foreach ( $upcoming as $row ) {

				// Append the time/timezone offset so strtotime() does not shift it to the local server timezone.
				$row->date = strtotime( $row->date . ' 00:00:00+0000' );
			}
		}

		// We need to query the main table for anniversaries or birthdays, so we can capture any that may have been
		// added before the implementation of the CN_ENTRY_DATE_TABLE table.
		if ( 'anniversary' === $atts['type'] || 'birthday' === $atts['type'] ) {

			$exclude = array();

			// Reset the WHERE clause.
			$where = array();

			// Only select the entries with a date.
			$where[] = sprintf( 'AND ( `%s` != \'\' )', $atts['type'] );

			// Exclude any entries that already exist in the previous query results.
			foreach ( $upcoming as $row ) {

				$exclude[] = $row->id;
			}

			if ( ! empty( $exclude ) ) {
				$where[] = 'AND `id` NOT IN (\'' . implode( '\', \'', $exclude ) . '\')';
			}

			// Only return entries in which the user has permission to view.
			$where = self::setQueryVisibility( $where, array_merge( $atts, array( 'table' => CN_ENTRY_TABLE ) ) );

			/*
			 * The FROM_UNIXTIME function will return the date offset to the local system timezone.
			 * The dates were not saved in GMT time and since FROM_UNIXTIME is adjusting for the local system timezone
			 * it could cause dates to shift days. The solution is to take the timezone shifted date from FROM_UNIXTIME
			 * and convert it using CONVERT_TZ from the local system timezone to GMT.
			 */
			$sql = $wpdb->prepare(
				'SELECT `id`, ' . $atts['type'] . ' AS `date` FROM ' . CN_ENTRY_TABLE . ' WHERE '
				. '  ( YEAR( DATE_ADD( %s, INTERVAL %d DAY ) )'
				. ' - YEAR( CONVERT_TZ( FROM_UNIXTIME( `' . $atts['type'] . '` ), @@session.time_zone, \'+00:00\' ) ) )'
				. ' - ( MID( DATE_ADD( %s, INTERVAL %d DAY ), 5, 6 )'
				. ' < MID( CONVERT_TZ( FROM_UNIXTIME( `' . $atts['type'] . '` ), @@session.time_zone, \'+00:00\' ), 5, 6 ) )'
				. ' > ( YEAR( %s )'
				. ' - YEAR( CONVERT_TZ( FROM_UNIXTIME( `' . $atts['type'] . '` ), @@session.time_zone, \'+00:00\' ) ) )'
				. ' - ( MID( %s, 5, 6 )'
				. ' ' . $includeToday . ' MID( CONVERT_TZ( FROM_UNIXTIME( `' . $atts['type'] . '` ), @@session.time_zone, \'+00:00\' ), 5, 6 ) )'
				. ' ' . implode( ' ', $where ),
				$date,
				absint( $atts['days'] ),
				$date,
				absint( $atts['days'] ),
				$date,
				$date
			);
			// print_r($sql);

			// At this point there is likely little need to provide backwards support, lets remove it for now.
			// $legacy = $wpdb->get_results( $sql );
			// var_dump($legacy);

			// if ( ! empty( $legacy ) ) $upcoming = array_merge( $upcoming, $legacy );
		}

		if ( ! empty( $upcoming ) ) {

			$ids = array();
			$ts  = array();

			/*
			 * The SQL returns an array sorted by the birthday and/or anniversary date. However, the year-end wrap needs to be accounted for.
			 * Otherwise earlier months of the year show before the later months in the year. Example Jan before Dec. The desired output is to show
			 * Dec then Jan dates.  This function checks to see if the month is a month earlier than the current month. If it is the year is changed to the following year rather than the current.
			 * After a new list is built, it is resorted based on the date.
			 */
			foreach ( $upcoming as $row ) {

				$ids[] = $row->id;

				if ( gmmktime( 23, 59, 59, gmdate( 'm', $row->date ), gmdate( 'd', $row->date ), gmdate( 'Y', $time ) ) < $time ) {

					$ts[] = gmmktime( 0, 0, 0, gmdate( 'm', $row->date ), gmdate( 'd', $row->date ), gmdate( 'Y', $time ) + 1 );

				} else {

					$ts[] = gmmktime( 0, 0, 0, gmdate( 'm', $row->date ), gmdate( 'd', $row->date ), gmdate( 'Y', $time ) );
				}

			}

			array_multisort( $ts, SORT_ASC, SORT_NUMERIC, $ids );
			// var_dump( $ids );

			switch ( $atts['return'] ) {

				case 'id':
					return $ids;

				default:
					return $this->entries(
						array(
							'id'               => $ids,
							'order_by'         => 'id|SPECIFIED',
							'parse_request'    => false,
							'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters
						)
					);

			}

		}

		return $results;
	}

	/**
	 * Retrieve the entry categories.
	 *
	 * @since unknown
	 *
	 * @param int $id
	 *
	 * @return array|false|WP_Error An array of categories associated to an entry.
	 */
	public function entryCategories( $id ) {

		return self::entryTerms( $id, 'category' );
	}

	/**
	 * Retrieve the entry terms by taxonomy.
	 *
	 * NOTE: This is the Connections equivalent of @see get_the_terms() in WordPress core ../wp-includes/category-template.php
	 *
	 * @since 8.2
	 *
	 * @param int    $id
	 * @param string $taxonomy
	 * @param array  $atts     Optional. An array of arguments. @see cnTerm::getRelationships() for accepted arguments.
	 *
	 * @return array|false|WP_Error An array of terms by taxonomy associated to an entry.
	 */
	public static function entryTerms( $id, $taxonomy, $atts = array() ) {

		/** @todo Check that entry exists */
		// if ( ! $id = get_entry( $id ) ) {
		// 	return false;
		// }

		$terms = cnTerm::getRelationshipsCache( $id, $taxonomy );

		if ( false === $terms ) {

			$terms = cnTerm::getRelationships( $id, $taxonomy, $atts );

			if ( ! is_wp_error( $terms ) ) {

				$to_cache = array();

				foreach ( $terms as $key => $term ) {

					$to_cache[ $key ] = $term->data;
				}

				wp_cache_add( $id, $to_cache, "cn_{$taxonomy}_relationships" );
			}

		} else {

			// This differs from the core WP function because $terms only needs to run through cnTerm::get() on a cache hit
			// otherwise it is unnecessarily run twice. once in cnTerm::getRelationships() on cache miss, once here.
			// Moving this logic to the else statement make sure it is only fun once on the cache hit.
			$terms = array_map( array( 'cnTerm', 'get' ), $terms );
		}

		/**
		 * Filter the list of terms attached to the given entry.
		 *
		 * @since 8.2
		 *
		 * @param array|WP_Error $terms    List of attached terms, or WP_Error on failure.
		 * @param int            $id       Post ID.
		 * @param string         $taxonomy Name of the taxonomy.
		 * @param array          $atts     An array of arguments for retrieving terms for the given object.
		 */
		$terms = apply_filters( 'cn_get_object_terms', $terms, $id, $taxonomy, $atts );

		if ( empty( $terms ) ) {

			return false;
		}

		return $terms;
	}

	/**
	 * Returns an indexed array of objects the addresses per the defined options.
	 *
	 * @param array $atts {
	 *     @type string       $fields    The fields to return.
	 *                                   Default: all
	 *                                   Accepts: all, ids, locality, regions, postal-code, country
	 *     @type int          $id        The entry ID in which to retrieve the addresses for.
	 *     @type bool         $preferred Whether to return only the preferred address.
	 *                                   Default: false
	 *     @type array|string $type      The address types to return.
	 *                                   Default: array() which will return all registered address types.
	 *                                   Accepts: home, work, school, other and any other registered types.
	 *     @type array|string $district  Return address in the defined districts.
	 *     @type array|string $county    Return address in the defined counties.
	 *     @type array|string $city      Return address in the defined cities.
	 *     @type array|string $state     Return address in the defined states.
	 *     @type array|string $country   Return address in the defined countries.
	 *     @type array        $coordinates {
	 *         Return the addresses at the specific coordinates.
	 *         @type float $latitude
	 *         @type float $longitude
	 *     }
	 * }
	 * @param bool  $saving Set as TRUE if adding a new entry or updating an existing entry.
	 *
	 * @return array
	 */
	public static function addresses( $atts = array(), $saving = false ) {

		/** @var wpdb $wpdb */
		global $wpdb;

		$where = array( 'WHERE 1=1' );

		$defaults = array(
			'fields'      => 'all',
			'id'          => null,
			'preferred'   => false,
			'type'        => array(),
			'visibility'  => null,
			'district'    => array(),
			'county'      => array(),
			'city'        => array(),
			'state'       => array(),
			'zipcode'     => array(),
			'country'     => array(),
			'coordinates' => array(),
			'limit'       => null,
		);

		$atts = cnSanitize::args( $atts, $defaults );

		/**
		 * @var string       $fields
		 * @var int          $id
		 * @var bool         $preferred
		 * @var array|string $type
		 * @var array|string $district
		 * @var array|string $county
		 * @var array|string $city
		 * @var array|string $state
		 * @var array|string $zipcode
		 * @var array|string $country
		 * @var array        $coordinates
		 * @var null|int     $limit
		 */
		extract( $atts );

		/*
		 * Convert these to values to an array if they were supplied as a comma delimited string
		 */
		_parse::stringList( $type );
		_parse::stringList( $district );
		_parse::stringList( $county );
		_parse::stringList( $city );
		_parse::stringList( $state );
		_parse::stringList( $zipcode );
		_parse::stringList( $country );

		switch ( $atts['fields'] ) {

			case 'ids':
				$select = array( 'a.id', 'a.entry_id' );
				break;

			case 'district':
				$select = array( 'a.district' );
				break;

			case 'county':
				$select = array( 'a.county' );
				break;

			case 'locality':
				$select = array( 'a.city' );
				break;

			case 'region':
				$select = array( 'a.state' );
				break;

			case 'postal-code':
				$select = array( 'a.zipcode' );
				break;

			case 'country':
				$select = array( 'a.country' );
				break;

			default:
				$select = array( 'a.*' );
		}

		if ( ! empty( $id ) ) {

			$where[] = $wpdb->prepare( 'AND `entry_id` = %d', $id );
		}

		if ( ! empty( $preferred ) ) {

			$where[] = $wpdb->prepare( 'AND `preferred` = %d', (bool) $preferred );
		}

		if ( ! empty( $type ) ) {

			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared
			$where[] = $wpdb->prepare( 'AND `type` IN (' . cnQuery::prepareINPlaceholders( $type ) . ')', $type );
		}

		if ( ! empty( $city ) ) {

			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared
			$where[] = $wpdb->prepare( 'AND `city` IN (' . cnQuery::prepareINPlaceholders( $city ) . ')', $city );
		}

		if ( ! empty( $state ) ) {

			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared
			$where[] = $wpdb->prepare( 'AND `state` IN (' . cnQuery::prepareINPlaceholders( $state ) . ')', $state );
		}

		if ( ! empty( $zipcode ) ) {

			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared
			$where[] = $wpdb->prepare( 'AND `zipcode` IN (' . cnQuery::prepareINPlaceholders( $zipcode ) . ')', $zipcode );
		}

		if ( ! empty( $country ) ) {

			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared
			$where[] = $wpdb->prepare( 'AND `country` IN (' . cnQuery::prepareINPlaceholders( $country ) . ')', $country );
		}

		if ( ! empty( $coordinates ) ) {

			if ( ! empty( $coordinates['latitude'] ) && ! empty( $coordinates['longitude'] ) ) {

				$where[] = $wpdb->prepare( 'AND `latitude` = %f', $coordinates['latitude'] );
				$where[] = $wpdb->prepare( 'AND `longitude` = %f', $coordinates['longitude'] );
			}
		}

		// Limit the characters that are queried based on if the current user can view public, private or unlisted entries.
		if ( ! $saving || ! is_null( $atts['visibility'] ) ) {

			$where = self::setQueryVisibility( $where, array( 'table' => 'a', 'visibility' => $atts['visibility'] ) );
		}

		$limit = is_null( $atts['limit'] ) ? '' : sprintf( ' LIMIT %d', $atts['limit'] );

		$sql = sprintf(
			'SELECT %1$s FROM %2$s AS a %3$s ORDER BY `order`%4$s',
			implode( ', ', $select ),
			CN_ENTRY_ADDRESS_TABLE,
			implode( ' ', $where ),
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $sql, ARRAY_A );

		return $results;
	}

	/**
	 * Returns as an array of objects containing the phone numbers per the defined options.
	 *
	 * @param array $atts {
	 *     Optional. An array of arguments.
	 *
	 *     @type int          $id        The entry ID in which to retrieve the phone numbers for.
	 *     @type bool         $preferred Whether to return only the preferred phone numbers.
	 *                                   Default: false
	 *     @type array|string $type      The types to return.
	 *                                   Default: array() which will return all registered types.
	 *                                   Accepts: homephone, homefax, cellphone, workphone, workfax and any other registered types.
	 *     @type int          $limit     The number to limit the results to.
	 * }
	 * @param bool  $saving Set as TRUE if adding a new entry or updating an existing entry.
	 *
	 * @return array
	 */
	public static function phoneNumbers( $atts = array(), $saving = false ) {

		/** @var wpdb $wpdb */
		global $wpdb;

		$where = array( 'WHERE 1=1' );

		$defaults = array(
			'fields'    => 'all',
			'id'        => null,
			'preferred' => false,
			'type'      => array(),
			'limit'     => null,
		);

		$atts = cnSanitize::args( $atts, $defaults );

		/**
		 * @var string       $fields
		 * @var int          $id
		 * @var bool         $preferred
		 * @var array|string $type
		 * @var null|int     $limit
		 */
		extract( $atts );

		/*
		 * Convert these to values to an array if they were supplied as a comma delimited string
		 */
		_parse::stringList( $type );

		switch ( $atts['fields'] ) {

			case 'ids':
				$select = array( 'p.id', 'p.entry_id' );
				break;

			case 'number':
				$select = array( 'p.number' );
				break;

			default:
				$select = array( 'p.*' );
		}

		if ( is_numeric( $id ) && ! empty( $id ) ) {

			$where[] = $wpdb->prepare( 'AND `entry_id` = "%d"', $id );
		}

		if ( $preferred ) {

			$where[] = $wpdb->prepare( 'AND `preferred` = %d', (bool) $preferred );
		}

		if ( ! empty( $type ) ) {

			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared
			$where[] = $wpdb->prepare( 'AND `type` IN (' . cnQuery::prepareINPlaceholders( $type ) . ')', $type );
		}

		if ( ! $saving ) {
			$where = self::setQueryVisibility( $where, array( 'table' => 'p' ) );
		}

		$limit = is_null( $atts['limit'] ) ? '' : sprintf( ' LIMIT %d', $atts['limit'] );

		$sql = sprintf(
			'SELECT %1$s FROM %2$s AS p %3$s ORDER BY `order`%4$s',
			implode( ', ', $select ),
			CN_ENTRY_PHONE_TABLE,
			implode( ' ', $where ),
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $sql, ARRAY_A );

		return $results;
	}

	/**
	 * Returns as an array of objects containing the email addresses per the defined options.
	 *
	 * @param array $atts {
	 *     Optional. An array of arguments.
	 *
	 *     @type int          $id        The entry ID in which to retrieve the email addresses for.
	 *     @type string       $address   The email address to return.
	 *     @type int          $position  The position the of the attached address. Example `0` for the first address.
	 *     @type bool         $preferred Whether to return only the preferred email address.
	 *                                   Default: false
	 *     @type array|string $type      The types to return.
	 *                                   Default: array() which will return all registered types.
	 *                                   Accepts: personal, work and any other registered types.
	 *     @type int          $limit     The number to limit the results to.
	 * }
	 *
	 * @param bool  $saving Set as TRUE if adding a new entry or updating an existing entry.
	 *
	 * @return array
	 */
	public static function emailAddresses( $atts = array(), $saving = false ) {

		/** @var wpdb $wpdb */
		global $wpdb;

		$where[] = 'WHERE 1=1';

		$defaults = array(
			'fields'    => 'all',
			'id'        => null,
			'address'   => '',
			'position'  => null,
			'preferred' => false,
			'type'      => array(),
			'limit'     => null,
		);

		$atts = cnSanitize::args( $atts, $defaults );

		/**
		 * @var string       $fields
		 * @var int          $id
		 * @var bool         $preferred
		 * @var array|string $type
		 * @var null|int     $limit
		 */
		extract( $atts );

		/*
		 * Convert these to values to an array if they were supplied as a comma delimited string
		 */
		_parse::stringList( $type );

		switch ( $atts['fields'] ) {

			case 'ids':
				$select = array( 'e.id', 'e.entry_id' );
				break;

			case 'address':
				$select = array( 'e.address' );
				break;

			default:
				$select = array( 'e.*' );
		}

		if ( is_numeric( $id ) && ! empty( $id ) ) {

			$where[] = $wpdb->prepare( 'AND `entry_id`=%d', $id );
		}

		if ( is_email( $atts['address'] ) ) {

			$where[] = $wpdb->prepare( 'AND address=%s', sanitize_email( $atts['address'] ) );
		}

		if ( is_numeric( $atts['position'] ) ) {

			$where[] = $wpdb->prepare( 'AND `order`=%d', $atts['position'] );
		}

		if ( $preferred ) {

			$where[] = $wpdb->prepare( 'AND `preferred`=%d', (bool) $preferred );
		}

		if ( ! empty( $type ) ) {

			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared
			$where[] = $wpdb->prepare( 'AND `type` IN (' . cnQuery::prepareINPlaceholders( $type ) . ')', $type );
		}

		if ( ! $saving ) {
			$where = self::setQueryVisibility( $where, array( 'table' => 'e' ) );
		}

		$limit = is_null( $atts['limit'] ) ? '' : sprintf( ' LIMIT %d', $atts['limit'] );

		$sql = sprintf(
			'SELECT %1$s FROM %2$s AS e %3$s ORDER BY `order`%4$s',
			implode( ', ', $select ),
			CN_ENTRY_EMAIL_TABLE,
			implode( ' ', $where ),
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Returns as an array of objects containing the IM IDs per the defined options.
	 *
	 * @param array $atts {
	 *     Optional. An array of arguments.
	 *
	 *     @type int          $id        The entry ID in which to retrieve the messenger IDs for.
	 *     @type bool         $preferred Whether to return only the preferred messenger IDs.
	 *                                   Default: false
	 *     @type array|string $type      The types to return.
	 *                                   Default: array() which will return all registered types.
	 *                                   Accepts: aim, yahoo, jabber, messenger, skype and any other registered types.
	 *     @type int          $limit     The number to limit the results to.
	 * }
	 * @param bool  $saving Set as TRUE if adding a new entry or updating an existing entry.
	 *
	 * @return array
	 */
	public static function imIDs( $atts = array(), $saving = false ) {

		/**  @var wpdb $wpdb */
		global $wpdb;

		$where[] = 'WHERE 1=1';

		$defaults = array(
			'fields'    => 'all',
			'id'        => null,
			'preferred' => false,
			'type'      => array(),
			'limit'     => null,
		);

		$atts = cnSanitize::args( $atts, $defaults );

		/**
		 * @var string       $fields
		 * @var int          $id
		 * @var bool         $preferred
		 * @var array|string $type
		 * @var null|int     $limit
		 */
		extract( $atts );

		/*
		 * Convert these to values to an array if they were supplied as a comma delimited string
		 */
		_parse::stringList( $type );

		switch ( $atts['fields'] ) {

			case 'ids':
				$select = array( 'i.id', 'i.entry_id' );
				break;

			case 'uid':
				$select = array( 'i.uid' );
				break;

			default:
				$select = array( 'i.*' );
		}

		if ( is_numeric( $id ) && ! empty( $id ) ) {

			$where[] = $wpdb->prepare( 'AND `entry_id` = "%d"', $id );
		}

		if ( $preferred ) {

			$where[] = $wpdb->prepare( 'AND `preferred` = %d', (bool) $preferred );
		}

		if ( ! empty( $type ) ) {

			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared
			$where[] = $wpdb->prepare( 'AND `type` IN (' . cnQuery::prepareINPlaceholders( $type ) . ')', $type );
		}

		if ( ! $saving ) {
			$where = self::setQueryVisibility( $where, array( 'table' => 'i' ) );
		}

		$limit = is_null( $atts['limit'] ) ? '' : sprintf( ' LIMIT %d', $atts['limit'] );

		$sql = sprintf(
			'SELECT %1$s FROM %2$s AS i %3$s ORDER BY `order`%4$s',
			implode( ', ', $select ),
			CN_ENTRY_MESSENGER_TABLE,
			implode( ' ', $where ),
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $sql, ARRAY_A );

		return $results;
	}

	/**
	 * Returns as an array of objects containing the social media networks per the defined options.
	 *
	 * @param array $atts {
	 *     Optional. An array of arguments.
	 *
	 *     @type int          $id        The entry ID in which to retrieve the social media networks for.
	 *     @type bool         $preferred Whether to return only the preferred social media networks.
	 *                                   Default: false
	 *     @type array|string $type      The types to return.
	 *                                   Default: array() which will return all registered types.
	 *                                   Accepts: Any other registered types.
	 *     @type int          $limit     The number to limit the results to.
	 * }
	 * @param bool  $saving Set as TRUE if adding a new entry or updating an existing entry.
	 *
	 * @return array
	 */
	public static function socialMedia( $atts = array(), $saving = false ) {

		/** @var wpdb $wpdb */
		global $wpdb;

		$where = array( 'WHERE 1=1' );

		$defaults = array(
			'fields'    => 'all',
			'id'        => null,
			'preferred' => false,
			'type'      => array(),
			'limit'     => null,
		);

		$atts = cnSanitize::args( $atts, $defaults );

		/**
		 * @var string       $fields
		 * @var int          $id
		 * @var bool         $preferred
		 * @var array|string $type
		 * @var null|int     $limit
		 */
		extract( $atts );

		/*
		 * Convert these to values to an array if they were supplied as a comma delimited string
		 */
		_parse::stringList( $type );

		switch ( $atts['fields'] ) {

			case 'ids':
				$select = array( 's.id', 's.entry_id' );
				break;

			case 'url':
				$select = array( 's.url' );
				break;

			default:
				$select = array( 's.*' );
		}

		if ( is_numeric( $id ) && ! empty( $id ) ) {

			$where[] = $wpdb->prepare( 'AND `entry_id` = "%d"', $id );
		}

		if ( $preferred ) {

			$where[] = $wpdb->prepare( 'AND `preferred` = %d', (bool) $preferred );
		}

		if ( ! empty( $type ) ) {

			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared
			$where[] = $wpdb->prepare( 'AND `type` IN (' . cnQuery::prepareINPlaceholders( $type ) . ')', $type );
		}

		if ( ! $saving ) {
			$where = self::setQueryVisibility( $where, array( 'table' => 's' ) );
		}

		$limit = is_null( $atts['limit'] ) ? '' : sprintf( ' LIMIT %d', $atts['limit'] );

		$sql = sprintf(
			'SELECT %1$s FROM %2$s AS s %3$s ORDER BY `order`%4$s',
			implode( ', ', $select ),
			CN_ENTRY_SOCIAL_TABLE,
			implode( ' ', $where ),
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $sql, ARRAY_A );

		return $results;
	}

	/**
	 * Returns as an array of objects containing the links per the defined options.
	 *
	 * @param array $atts {
	 *     Optional. An array of arguments.
	 *
	 *     @type int          $id        The entry ID in which to retrieve the links for.
	 *     @type bool         $preferred Whether to return only the preferred links.
	 *                                   Default: false
	 *     @type array|string $type      The types to return.
	 *                                   Default: array() which will return all registered types.
	 *                                   Accepts: blog, website and any other registered types.
	 *     @type int          $limit     The number to limit the results to.
	 * }
	 * @param bool  $saving Set as TRUE if adding a new entry or updating an existing entry.
	 *
	 * @return array
	 */
	public static function links( $atts = array(), $saving = false ) {

		/** @var wpdb $wpdb */
		global $wpdb;

		$where = array( 'WHERE 1=1' );

		$defaults = array(
			'fields'    => 'all',
			'id'        => null,
			'preferred' => false,
			'image'     => false,
			'logo'      => false,
			'type'      => array(),
			'limit'     => null,
		);

		$atts = cnSanitize::args( $atts, $defaults );

		/**
		 * @var string       $fields
		 * @var int          $id
		 * @var bool         $preferred
		 * @var bool         $image
		 * @var bool         $logo
		 * @var array|string $type
		 * @var null|int     $limit
		 */
		extract( $atts );

		/*
		 * Convert these to values to an array if they were supplied as a comma delimited string
		 */
		_parse::stringList( $type );

		switch ( $atts['fields'] ) {

			case 'ids':
				$select = array( 'l.id', 'l.entry_id' );
				break;

			case 'url':
				$select = array( 'l.url' );
				break;

			default:
				$select = array( 'l.*' );
		}

		if ( is_numeric( $id ) && ! empty( $id ) ) {

			$where[] = $wpdb->prepare( 'AND `entry_id` = "%d"', $id );
		}

		if ( $preferred ) {

			$where[] = $wpdb->prepare( 'AND `preferred` = %d', (bool) $preferred );
		}

		if ( $image ) {

			$where[] = $wpdb->prepare( 'AND `image` = %d', (bool) $image );
		}

		if ( $logo ) {

			$where[] = $wpdb->prepare( 'AND `logo` = %d', (bool) $logo );
		}

		if ( ! empty( $type ) ) {

			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared
			$where[] = $wpdb->prepare( 'AND `type` IN (' . cnQuery::prepareINPlaceholders( $type ) . ')', $type );
		}

		if ( ! $saving ) {
			$where = self::setQueryVisibility( $where, array( 'table' => 'l' ) );
		}

		$limit = is_null( $atts['limit'] ) ? '' : sprintf( ' LIMIT %d', $atts['limit'] );

		$sql = sprintf(
			'SELECT %1$s FROM %2$s AS l %3$s ORDER BY `order`%4$s',
			implode( ', ', $select ),
			CN_ENTRY_LINK_TABLE,
			implode( ' ', $where ),
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $sql, ARRAY_A );

		return $results;
	}


	/**
	 * Returns as an array of objects containing the dates per the defined options.
	 *
	 * @param array $atts {
	 *     Optional. An array of arguments.
	 *
	 *     @type int          $id        The entry ID in which to retrieve the dates for.
	 *     @type bool         $preferred Whether to return only the preferred dates.
	 *                                   Default: false
	 *     @type array|string $type      The types to return.
	 *                                   Default: array() which will return all registered types.
	 *                                   Accepts: Any other registered types.
	 *     @type int          $limit     The number to limit the results to.
	 * }
	 * @param bool  $saving Set as TRUE if adding a new entry or updating an existing entry.
	 *
	 * @return array
	 */
	public static function dates( $atts = array(), $saving = false ) {

		/** @var wpdb $wpdb */
		global $wpdb;

		$where = array( 'WHERE 1=1' );

		$defaults = array(
			'fields'    => 'all',
			'id'        => null,
			'preferred' => false,
			'type'      => array(),
			'limit'     => null,
		);

		$atts = cnSanitize::args( $atts, $defaults );

		/**
		 * @var string       $fields
		 * @var int          $id
		 * @var bool         $preferred
		 * @var array|string $type
		 * @var null|int     $limit
		 */
		extract( $atts );

		/*
		 * Convert these to values to an array if they were supplied as a comma delimited string
		 */
		_parse::stringList( $type );

		switch ( $atts['fields'] ) {

			case 'ids':
				$select = array( 'd.id', 'd.entry_id' );
				break;

			case 'date':
				$select = array( 'd.date' );
				break;

			default:
				$select = array( 'd.*' );
		}

		if ( is_numeric( $id ) && ! empty( $id ) ) {

			$where[] = $wpdb->prepare( 'AND `entry_id` = "%d"', $id );
		}

		if ( $preferred ) {

			$where[] = $wpdb->prepare( 'AND `preferred` = %d', (bool) $preferred );
		}

		if ( ! empty( $type ) ) {

			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared
			$where[] = $wpdb->prepare( 'AND `type` IN (' . cnQuery::prepareINPlaceholders( $type ) . ')', $type );
		}

		if ( ! $saving ) {
			$where = self::setQueryVisibility( $where, array( 'table' => 'd' ) );
		}

		$limit = is_null( $atts['limit'] ) ? '' : sprintf( ' LIMIT %d', $atts['limit'] );

		$sql = sprintf(
			'SELECT %1$s FROM %2$s AS d %3$s ORDER BY `order`%4$s',
			implode( ', ', $select ),
			CN_ENTRY_DATE_TABLE,
			implode( ' ', $where ),
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $sql, ARRAY_A );

		return $results;
	}

	/**
	 * Retrieve all country names attached to directory entries.
	 *
	 * @since 10.4.66
	 *
	 * @return array
	 */
	public static function countries(): array {

		global $wpdb;

		$sql = 'SELECT `country` FROM ' . CN_ENTRY_ADDRESS_TABLE . ' WHERE `country` <> "" GROUP BY `country` ORDER BY `country` ASC';

		$result = $wpdb->get_col( $sql );

		return array_filter( $result );
	}

	/**
	 * Retrieve all region (state/province) names attached to directory entries.
	 *
	 * @since 10.4.66
	 *
	 * @param string $inCountry
	 *
	 * @return array
	 */
	public static function regions( string $inCountry = '' ): array {

		global $wpdb;

		$where = 0 < strlen( $inCountry ) ? $wpdb->prepare( ' WHERE `country` = %s', $inCountry ) : '';

		$sql = 'SELECT DISTINCT `state` from ' . CN_ENTRY_ADDRESS_TABLE . $where . ' ORDER BY `state`';

		// NOTE: Ensure `$sql` is fully prepared!
		$result = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_filter( $result );
	}

	/**
	 * Return an array of entry ID/s found with the supplied search terms.
	 *
	 * @todo Return exact matches when searching by name for both the individual and organization entry types.
	 * @todo Add option to return exact matches only, to name for both the individual and organization entry types.
	 * @todo Allow the fields for each table to be defined as a comma delimited list, convert an array and validate
	 *       against of list of valid table fields.
	 * @todo Add a filter to allow the search fields to be changed.
	 *
	 * Resources used:
	 *  http://devzone.zend.com/26/using-mysql-full-text-searching/
	 *  http://onlamp.com/onlamp/2003/06/26/fulltext.html
	 *
	 * @since 0.7.2.0
	 *
	 * @param array $atts [optional]
	 *
	 * @return array
	 */
	public function search( $atts = array() ) {

		/** @var wpdb $wpdb */
		global $wpdb;

		$results = array();
		$scored  = array();
		$fields  = cnSettingsAPI::get( 'connections', 'search', 'fields' );

		$fields = apply_filters( 'cn_search_fields', $fields );

		// If no search fields are set, return an empty array.
		if ( empty( $fields ) ) {
			return array();
		}

		/*
		 * // START -- Set the default attributes array. \\
		 */
		$defaults['terms'] = array();

		if ( in_array( 'family_name', $fields ) ) {
			$defaults['fields']['entry'][] = 'family_name';
		}

		if ( in_array( 'first_name', $fields ) ) {
			$defaults['fields']['entry'][] = 'first_name';
		}

		if ( in_array( 'middle_name', $fields ) ) {
			$defaults['fields']['entry'][] = 'middle_name';
		}

		if ( in_array( 'last_name', $fields ) ) {
			$defaults['fields']['entry'][] = 'last_name';
		}

		if ( in_array( 'title', $fields ) ) {
			$defaults['fields']['entry'][] = 'title';
		}

		if ( in_array( 'organization', $fields ) ) {
			$defaults['fields']['entry'][] = 'organization';
		}

		if ( in_array( 'department', $fields ) ) {
			$defaults['fields']['entry'][] = 'department';
		}
		if ( in_array( 'contact_first_name', $fields ) ) {
			$defaults['fields']['entry'][] = 'contact_first_name';
		}

		if ( in_array( 'contact_last_name', $fields ) ) {
			$defaults['fields']['entry'][] = 'contact_last_name';
		}

		if ( in_array( 'bio', $fields ) ) {
			$defaults['fields']['entry'][] = 'bio';
		}

		if ( in_array( 'notes', $fields ) ) {
			$defaults['fields']['entry'][] = 'notes';
		}


		if ( in_array( 'address_line_1', $fields ) ) {
			$defaults['fields']['address'][] = 'line_1';
		}

		if ( in_array( 'address_line_2', $fields ) ) {
			$defaults['fields']['address'][] = 'line_2';
		}
		if ( in_array( 'address_line_3', $fields ) ) {
			$defaults['fields']['address'][] = 'line_3';
		}

		if ( in_array( 'address_line_4', $fields ) ) {
			$defaults['fields']['address'][] = 'line_4';
		}

		if ( in_array( 'address_district', $fields ) ) {
			$defaults['fields']['address'][] = 'district';
		}

		if ( in_array( 'address_county', $fields ) ) {
			$defaults['fields']['address'][] = 'county';
		}

		if ( in_array( 'address_city', $fields ) ) {
			$defaults['fields']['address'][] = 'city';
		}

		if ( in_array( 'address_state', $fields ) ) {
			$defaults['fields']['address'][] = 'state';
		}

		if ( in_array( 'address_zipcode', $fields ) ) {
			$defaults['fields']['address'][] = 'zipcode';
		}

		if ( in_array( 'address_country', $fields ) ) {
			$defaults['fields']['address'][] = 'country';
		}

		if ( in_array( 'phone_number', $fields ) ) {
			$defaults['fields']['phone'][] = 'number';
		}

		$defaults['fields']['meta'] = array_diff(
			$fields,
			array( 'family_name', 'first_name', 'middle_name', 'last_name', 'title', 'organization', 'department', 'contact_first_name', 'contact_last_name', 'bio', 'notes' ),
			array( 'address_line_1', 'address_line_2', 'address_line_3', 'address_line_4', 'address_district', 'address_county', 'address_city', 'address_state', 'address_zipcode', 'address_country' ),
			array( 'phone_number' )
		);

		$atts = wp_parse_args( $atts, apply_filters( 'cn_search_atts', $defaults ) );

		// @todo Validate each fields array to ensure only permitted fields will be used.
		/*
		 * // END -- Set the default attributes array if not supplied. \\
		 */

		// If no search terms were entered, return an empty array.
		if ( empty( $atts['terms'] ) ) {
			return array();
		}

		// If value is a string, stripe the white space and covert to an array.
		// if ( ! is_array( $atts['terms'] ) ) $atts['terms'] = explode( ' ', trim( $atts['terms'] ) );

		// Trim any white space from around the terms in the array.
		// array_walk( $atts['terms'] , 'trim' );

		$original = $atts['terms'];

		$atts['terms'] = _parse::stringList( $atts['terms'], '\s' );

		array_unshift( $atts['terms'], $original );

		$atts['terms'] = array_unique( $atts['terms'] );

		$atts['terms'] = apply_filters( 'cn_search_terms', $atts['terms'] );

		// Remove any single characters and stop words from terms.
		$atts['terms'] = $this->parse_search_terms( $atts['terms'] );

		// If no search terms are left after removing stop words, return an empty array.
		if ( empty( $atts['terms'] ) ) {
			return array();
		}

		/*
		 * Perform search using FULLTEXT if enabled.
		 *
		 * Perform the search on each table individually because joining the tables doesn't scale when
		 * there are a large number of entries.
		 *
		 * NOTES:
		 *     The following is the error reported by MySQL when DB does not support FULLTEXT:  'The used table type doesn't support FULLTEXT indexes'
		 *     If DB does not support FULLTEXT the query will fail and the $results will be an empty array.
		 *
		 * FULLTEXT Restrictions as noted here: http://onlamp.com/onlamp/2003/06/26/fulltext.html
		 *
		 *     Some default behaviors of these restrictions can be changed in your my.cnf or using the SET command
		 *
		 *     FULLTEXT indices are NOT supported in InnoDB tables.
		 *     MySQL requires that you have at least three rows of data in your result set before it will return any results.
		 *     By default, if a search term appears in more than 50% of the rows then MySQL will not return any results.
		 *     By default, your search query must be at least four characters long and may not exceed 254 characters.
		 *     MySQL has a default stop words file that has a list of common words (i.e., the, that, has) which are not returned in your search. In other words, searching for the will return zero rows.
		 *     According to MySQL's manual, the argument to AGAINST() must be a constant string. In other words, you cannot search for values returned within the query.
		 */
		if ( cnSettingsAPI::get( 'connections', 'search', 'fulltext_enabled' ) ) {

			$terms      = array();
			$shortwords = array();

			/*
			 * Remove any short words from the FULLTEXT query since the db will drop them anyway.
			 * Add the short words to a separate array to be used to do a LIKE query.
			 */
			foreach ( $atts['terms'] as $key => $term ) {

				if ( strlen( $term ) >= 1 && strlen( $term ) <= 3 ) {

					unset( $atts['terms'][ $key ] );

					$shortwords[] = $term;
				}
			}

			if ( ! empty( $atts['terms'] ) ) {

				// Make each term required, functional AND query.
				$terms = apply_filters( 'cn_search_fulltext_terms', '+' . implode( ' +', $atts['terms'] ), $atts['terms'] );
			}

			/*
			 * Only search the primary records if at least one fields is selected to be searched.
			 */
			if ( ! empty( $atts['fields']['entry'] ) ) {

				$select = array();
				$from   = array();
				$where  = array();
				$like   = array();

				$select[] = 'SELECT ' . CN_ENTRY_TABLE . '.id';

				/*
				 * Set up the SELECT to return the results scored by relevance.
				 */
				if ( ! empty( $terms ) ) {

					$select[] = $wpdb->prepare(
						'MATCH (' . implode( ', ', $atts['fields']['entry'] ) . ') AGAINST (%s) AS score',
						$terms
					);
				}

				$from[] = CN_ENTRY_TABLE;

				/*
				 * If there are long word terms, perform a FULLTEXT query.
				 */
				if ( ! empty( $terms ) ) {

					$where[] = $wpdb->prepare(
						'MATCH (' . implode( ', ', $atts['fields']['entry'] ) . ') AGAINST (%s IN BOOLEAN MODE)',
						$terms
					);
				}

				/*
				 * If there are no long words and there are short words, perform a LIKE query for the short words.
				 */
				if ( empty( $terms ) && ! empty( $shortwords ) ) {

					foreach ( $shortwords as $word ) {

						/**
						 * Allow plugins to alter the short word LIKE query.
						 *
						 * By default, the short word LIKE query will only return results with entries matching words that
						 * begin with the short word. This is done to match the FULLTEXT search query which only returns
						 * results with terms that being with the search term.
						 *
						 * To alter the LIKE query to return results for short word where entries contain the short word,
						 * rather than begins with, use this example filter:
						 *
						 * <code>
						 * add_filter( 'cn_search_like_shortword', 'my_custom_search_like_shortword', 10, 2 );
						 * function my_custom_search_like_shortword( $esc_word, $word ) {
						 *
						 *     return '%' . $wpdb->esc_like( $word ) . '%';
						 * }
						 * </code>
						 *
						 * @since 8.5.27
						 *
						 * @param string $word The short word where the $word is escaped for a LIKE query with a
						 *                     trailing `%` so the LIKE query will return results that begin with $word.
						 * @param string $word The short word to perform the LIKE query with.
						 */
						$word = apply_filters( 'cn_search_like_shortword', $wpdb->esc_like( $word ) . '%', $word );

						//phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared
						$like[] = $wpdb->prepare( implode( ' LIKE %s OR ', $atts['fields']['entry'] ) . ' LIKE %s ', array_fill( 0, count( $atts['fields']['entry'] ), $word ) );
					}

					$where[] = '( ' . implode( ') OR (', $like ) . ')';

				}

				/*
				 * Return the query results ordered by the relevance score.
				 */
				$orderBy = empty( $terms ) ? '' : ' ORDER BY score';

				$sql = implode( ', ', $select ) . ' FROM ' . implode( ',', $from ) . ' WHERE ' . implode( ' AND ', $where ) . $orderBy;

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$scored = $wpdb->get_results( $sql, ARRAY_A );
			}

			/*
			 * Only search the address records if at least one fields is selected to be searched.
			 */
			if ( ! empty( $atts['fields']['address'] ) ) {

				$select = array();
				$from   = array();
				$where  = array();
				$like   = array();

				$select[] = 'SELECT ' . CN_ENTRY_ADDRESS_TABLE . '.entry_id';

				/*
				 * Set up the SELECT to return the results scored by relevance.
				 */
				if ( ! empty( $terms ) ) {

					$select[] = $wpdb->prepare(
						'MATCH (' . implode( ', ', $atts['fields']['address'] ) . ') AGAINST (%s) AS score',
						$terms
					);
				}

				$from[] = CN_ENTRY_ADDRESS_TABLE;

				/*
				 * If there are long word terms, perform a FULLTEXT query.
				 */
				if ( ! empty( $terms ) ) {

					$where[] = $wpdb->prepare(
						'MATCH (' . implode( ', ', $atts['fields']['address'] ) . ') AGAINST (%s IN BOOLEAN MODE)',
						$terms
					);
				}

				/*
				 * If there are no long words and there are short words, perform a LIKE query for the short words.
				 */
				if ( empty( $terms ) && ! empty( $shortwords ) ) {

					foreach ( $shortwords as $word ) {

						$word = apply_filters( 'cn_search_like_shortword', $wpdb->esc_like( $word ) . '%', $word );

						//phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared
						$like[] = $wpdb->prepare( implode( ' LIKE %s OR ', $atts['fields']['address'] ) . ' LIKE %s ', array_fill( 0, count( $atts['fields']['address'] ), $word ) );
					}

					$where[] = '( ' . implode( ') OR (', $like ) . ')';

				}

				/*
				 * Return the query results ordered by the relevance score.
				 */
				$orderBy = empty( $terms ) ? '' : ' ORDER BY score';

				$sql = implode( ', ', $select ) . ' FROM ' . implode( ',', $from ) . ' WHERE ' . implode( ' AND ', $where ) . $orderBy;

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$ids = $wpdb->get_results( $sql, ARRAY_A );

				/*
				 * If any results are returned merge them in to the $scored results.
				 */
				if ( ! empty( $ids ) ) {
					$scored = array_merge( $scored, $ids );
				}
			}

			/*
			 * Only search the phone records if the field is selected to be searched.
			 */
			if ( ! empty( $atts['fields']['phone'] ) ) {

				$select = array();
				$from   = array();
				$where  = array();
				$like   = array();

				$select[] = 'SELECT ' . CN_ENTRY_PHONE_TABLE . '.entry_id';

				/*
				 * Set up the SELECT to return the results scored by relevance.
				 */
				if ( ! empty( $terms ) ) {

					$select[] = $wpdb->prepare(
						'MATCH (' . implode( ', ', $atts['fields']['phone'] ) . ') AGAINST (%s) AS score',
						$terms
					);
				}

				$from[] = CN_ENTRY_PHONE_TABLE;

				/*
				 * If there are long word terms, perform a FULLTEXT query.
				 */
				if ( ! empty( $terms ) ) {

					$where[] = $wpdb->prepare(
						'MATCH (' . implode( ', ', $atts['fields']['phone'] ) . ') AGAINST (%s IN BOOLEAN MODE)',
						$terms
					);
				}

				/*
				 * If there are no long words and there are short words, perform a LIKE query for the short words.
				 */
				if ( empty( $terms ) && ! empty( $shortwords ) ) {

					foreach ( $shortwords as $word ) {

						$word = apply_filters( 'cn_search_like_shortword', $wpdb->esc_like( $word ) . '%', $word );

						//phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared
						$like[] = $wpdb->prepare( implode( ' LIKE %s OR ', $atts['fields']['phone'] ) . ' LIKE %s ', array_fill( 0, count( $atts['fields']['phone'] ), $word ) );
					}

					$where[] = '( ' . implode( ') OR (', $like ) . ')';

				}

				/*
				 * Return the query results ordered by the relevance score.
				 */
				$orderBy = empty( $terms ) ? '' : ' ORDER BY score';

				$sql = implode( ', ', $select ) . ' FROM ' . implode( ',', $from ) . ' WHERE ' . implode( ' AND ', $where ) . $orderBy;

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$ids = $wpdb->get_results( $sql, ARRAY_A );

				/*
				 * If any results are returned merge them in to the $scored results.
				 */
				if ( ! empty( $ids ) ) {
					$scored = array_merge( $scored, $ids );
				}
			}

			/*
			 * Only search the meta records if at least one registered fields is selected to be searched.
			 */
			if ( ! empty( $atts['fields']['meta'] ) ) {

				$metaTerms = $atts['terms'] + $shortwords;

				$select = array();
				$from   = array();
				$where  = array( '1=1' );
				$meta   = array( 'meta_query' => array( 'relation' => 'OR' ) );

				$select[] = 'SELECT ' . CN_ENTRY_TABLE . '.id';
				$from[]   = CN_ENTRY_TABLE;

				foreach ( $metaTerms as $term ) {

					foreach ( $atts['fields']['meta'] as $meta_key ) {

						$meta['meta_query'][] = array(
							'compare' => 'LIKE',
							'key'     => $meta_key,
							'value'   => $term,
						);
					}
				}

				$metaQuery = new cnMeta_Query( $meta );
				$metaSQL   = $metaQuery->get_sql( 'entry', CN_ENTRY_TABLE, 'id' );
				$join      = $metaSQL['join'];
				$where[]   = $metaSQL['where'];

				$sql = implode( ', ', $select ) . ' FROM ' . implode( ',', $from ) . $join . ' WHERE ' . implode( ' ', $where );

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$ids = $wpdb->get_results( $sql, ARRAY_A );

				/*
				 * If any results are returned merge them in to the $scored results.
				 */
				if ( ! empty( $ids ) ) {
					$scored = array_merge( $scored, $ids );
				}
			}

			$scored = apply_filters( 'cn_search_scored_results', $scored, $terms );

			/*
			 * The query results are stored in the $scored array ordered by relevance.
			 * Only the entry ID/s are needed to be returned. Set up the $results array
			 * with only the entry ID/s in the same order as returned by the relevance score.
			 */
			foreach ( $scored as $entry ) {

				$results[] = isset( $entry['id'] ) ? $entry['id'] : $entry['entry_id'];
			}

		}

		/*
		 * If no results are found, perhaps to the way MySQL performs FULLTEXT queries, FULLTEXT search being disabled
		 * or the DB not supporting FULLTEXT, run a LIKE query.
		 *
		 * Perform the search on each table individually because joining the tables doesn't scale when
		 * there are a large number of entries.
		 */
		if (
			(
				( cnSettingsAPI::get( 'connections', 'search', 'keyword_enabled' ) && empty( $results ) ) ||
				( cnSettingsAPI::get( 'connections', 'search', 'fulltext_enabled' ) && empty( $results ) )
			) &&
			! empty( $atts['terms'] ) ) {

			/*
			 * Only search the primary records if at least one fields is selected to be searched.
			 */
			if ( ! empty( $atts['fields']['entry'] ) ) {

				$like = array(); // Reset the like array.

				foreach ( $atts['terms'] as $term ) {
					/*
					 * Attempt to secure the query using $wpdb->prepare() and like_escape()
					 *
					 * Since $wpdb->prepare() required var for each directive in the query string we'll use array_fill
					 * where the count based on the number of columns that will be searched.
					 */
					//phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared
					$like[] = $wpdb->prepare( implode( ' LIKE %s OR ', $atts['fields']['entry'] ) . ' LIKE %s ', array_fill( 0, count( $atts['fields']['entry'] ), '%' . $wpdb->esc_like( $term ) . '%' ) );
				}

				$sql = 'SELECT ' . CN_ENTRY_TABLE . '.id
									FROM ' . CN_ENTRY_TABLE . '
									WHERE (' . implode( ') OR (', $like ) . ')';
				// print_r($sql);

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$results = array_merge( $results, $wpdb->get_col( $sql ) );
				// print_r($results);die;
			}

			/*
			 * Only search the address records if at least one fields is selected to be searched.
			 */
			if ( ! empty( $atts['fields']['address'] ) ) {

				$like = array(); // Reset the like array.

				foreach ( $atts['terms'] as $term ) {
					/*
					 * Attempt to secure the query using $wpdb->prepare() and like_escape()
					 *
					 * Since $wpdb->prepare() required var for each directive in the query string we'll use array_fill
					 * where the count based on the number of columns that will be searched.
					 */
					//phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared
					$like[] = $wpdb->prepare( implode( ' LIKE %s OR ', $atts['fields']['address'] ) . ' LIKE %s ', array_fill( 0, count( $atts['fields']['address'] ), '%' . $wpdb->esc_like( $term ) . '%' ) );
				}

				$sql = 'SELECT ' . CN_ENTRY_ADDRESS_TABLE . '.entry_id
									FROM ' . CN_ENTRY_ADDRESS_TABLE . '
									WHERE (' . implode( ') OR (', $like ) . ')';
				// print_r($sql);

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$results = array_merge( $results, $wpdb->get_col( $sql ) );
				// print_r($results);
			}

			/*
			 * Only search the phone records if the field is selected to be searched.
			 */
			if ( ! empty( $atts['fields']['phone'] ) ) {

				$like = array(); // Reset the like array.

				foreach ( $atts['terms'] as $term ) {
					/*
					 * Attempt to secure the query using $wpdb->prepare() and like_escape()
					 *
					 * Since $wpdb->prepare() required var for each directive in the query string we'll use array_fill
					 * where the count based on the number of columns that will be searched.
					 */
					//phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared
					$like[] = $wpdb->prepare( implode( ' LIKE %s OR ', $atts['fields']['phone'] ) . ' LIKE %s ', array_fill( 0, count( $atts['fields']['phone'] ), '%' . $wpdb->esc_like( $term ) . '%' ) );
				}

				$sql = 'SELECT ' . CN_ENTRY_PHONE_TABLE . '.entry_id
									FROM ' . CN_ENTRY_PHONE_TABLE . '
									WHERE (' . implode( ') OR (', $like ) . ')';
				// print_r($sql);

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$results = array_merge( $results, $wpdb->get_col( $sql ) );
				// print_r($results);
			}

		}

		return apply_filters( 'cn_search_results', $results, $atts );
	}

	/**
	 * Ripped from WP core. Unfortunately it can not be used
	 * directly because it is a protected method in the WP_Query class.
	 *
	 * Check if the terms are suitable for searching.
	 *
	 * Uses an array of stop words (terms) that are excluded from the separate
	 * term matching when searching for posts. The list of English stop words is
	 * the approximate search engines list, and is translatable.
	 *
	 * @see WP_Query::parse_search_terms()
	 *
	 * @since 8.1
	 *
	 * @param array $terms Terms to check.
	 *
	 * @return array Terms that are not stop words.
	 */
	protected function parse_search_terms( $terms ) {
		$strtolower = function_exists( 'mb_strtolower' ) ? 'mb_strtolower' : 'strtolower';
		$checked    = array();

		$stopwords = $this->get_search_stopwords();

		foreach ( $terms as $term ) {
			// Keep before/after spaces when term is for exact match.
			if ( preg_match( '/^".+"$/', $term ) ) {
				$term = trim( $term, "\"'" );
			} else {
				$term = trim( $term, "\"' " );
			}

			// Avoid single A-Z.
			if ( ! $term || ( 1 === strlen( $term ) && preg_match( '/^[a-z]$/i', $term ) ) ) {
				continue;
			}

			if ( in_array( call_user_func( $strtolower, $term ), $stopwords, true ) ) {
				continue;
			}

			$checked[] = $term;
		}

		return $checked;
	}

	/**
	 * Ripped from WP core. Unfortunately it can not be used
	 * directly because it is a protected method in the WP_Query class.
	 *
	 * Retrieve stop words used when parsing search terms.
	 *
	 * @see WP_Query::get_search_stopwords()
	 *
	 * @since 8.1
	 *
	 * @return array Stop words.
	 */
	protected function get_search_stopwords() {
		if ( isset( $this->stopwords ) ) {
			return $this->stopwords;
		}

		/*
		 * translators: This is a comma-separated list of very common words that should be excluded from a search,
		 * like a, an, and the. These are usually called "stop words". You should not simply translate these individual
		 * words into your language. Instead, look for and provide commonly accepted stop words in your language.
		 */
		$words = explode(
			',',
			_x(
				'about,an,are,as,at,be,by,com,for,from,how,in,is,it,of,on,or,that,the,this,to,was,what,when,where,who,will,with,www',
				'Comma-separated list of search stop words in your language',
				'connections'
			)
		);

		$stopwords = array();
		foreach ( $words as $word ) {
			$word = trim( $word, "\r\n\t " );
			if ( $word ) {
				$stopwords[] = $word;
			}
		}

		/**
		 * Filter stop words used when parsing search terms.
		 *
		 * @since 3.7.0
		 *
		 * @param array $stopwords Stop words.
		 */
		$this->stopwords = apply_filters( 'wp_search_stopwords', $stopwords );
		return $this->stopwords;
	}

	/**
	 * Total record count based on current user permissions.
	 *
	 * @since unknown
	 *
	 * @global $wpdb
	 *
	 * @param array $atts
	 *
	 * @return int
	 */
	public static function recordCount( $atts ) {

		/**  @var wpdb $wpdb */
		global $wpdb;

		$where[] = 'WHERE 1=1';

		$defaults = array(
			'public_override'  => true,
			'private_override' => true,
			'status'           => array(),
		);

		$atts = cnSanitize::args( $atts, $defaults );

		$where = self::setQueryVisibility( $where, $atts );
		$where = self::setQueryStatus( $where, $atts );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_var( 'SELECT COUNT(`id`) FROM ' . CN_ENTRY_TABLE . ' ' . implode( ' ', $where ) );

		return ! empty( $results ) ? $results : 0;
	}

	/**
	 * Remove the entries from the list where the date added was not recorded.
	 *
	 * This is more or less a hack to remove the entries from the list where the date added was not recorded which would be entries added
	 * before 0.7.1.1.
	 *
	 * @internal
	 * @since 0.7.1.6
	 *
	 * @param array $results
	 *
	 * @return array
	 */
	public function removeUnknownDateAdded( $results ) {
		foreach ( $results as $key => $entry ) {
			if ( empty( $entry->date_added ) ) {
				unset( $results[ $key ] );
			}
		}

		return $results;
	}

	/**
	 * Returns all the category terms.
	 *
	 * @return array
	 */
	public function categories() {

		return cnTerm::tree( 'category' );
	}

	/**
	 * Returns category by ID.
	 *
	 * @param integer $id
	 *
	 * @return Taxonomy_Term|WP_Error
	 */
	public function category( $id ) {

		return cnTerm::get( $id, 'category' );
	}

	/**
	 * Retrieve the children of the supplied parent.
	 *
	 * @param string     $field
	 * @param int|string $value
	 *
	 * @return array
	 */
	public function categoryChildren( $field, $value ) {

		/** @var connectionsLoad $connections */
		global $connections;

		return $connections->term->getTermChildrenBy( $field, $value, 'category' );
	}
}
