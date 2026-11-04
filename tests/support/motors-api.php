<?php
/**
 * eBay for the eBay Motors harnesses (3.28.9), played on top of
 * template-world's: every Trading call recorded with the X-EBAY-API-SITEID
 * the real API class would send it with (TCGiant_Sync_API::
 * request_site_id(), which with_site() sets), and every Taxonomy and Metadata
 * read recorded and answered by tree. Require template-world.php first.
 *
 * @package TCGiant_Sync
 */

/** eBay's REST error, as TCGiant_Sync_API::request() hands it back. */
function motors_error( $error_id, $status = 400 ) {
	return new WP_Error( 'api_error', 'eBay API Error ' . $status, array( 'errors' => array( array( 'errorId' => $error_id ) ) ) );
}

/** A node of tree 100, as get_category_node() gives it. */
function motors_node( $id, $name, $parent ) {
	return array( 'id' => (string) $id, 'name' => $name, 'leaf' => true, 'level' => 4, 'parent' => (string) $parent );
}

class Motors_API extends Template_World_API {
	/** @var string[] Each Trading call, as "Call@site". */
	public $sites = array();
	/** @var string[] Each Taxonomy or Metadata read, as "what:tree or marketplace:category or query". */
	public $rest = array();
	/** @var array tree => category => aspects, or a WP_Error. */
	public $tree_aspects = array();
	/** @var array category => tree-100 node, or a WP_Error; 62005 when absent. */
	public $nodes = array();
	/** @var array query => tree-100 suggestions. */
	public $suggest = array();
	/** @var array title => tree-0 suggestions. */
	public $suggest0 = array();
	/** @var array category => getItemConditionPolicies answer, or a WP_Error. */
	public $conds = array();
	/** @var array parent => tree-100 children. */
	public $children = array();
	/** @var array "Call@site" => eBay's answer there, over the answers by call. */
	public $site_answers = array();

	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		// The identifiers' metadata reads (3.30.3) are template-world's
		// $lookups, never a listing call here.
		if ( in_array( $call_name, array( 'GetCategoryFeatures', 'GeteBayDetails' ), true ) ) {
			return parent::trading_api_request( $call_name, $xml_body, $allow_retry, $max_body_bytes );
		}
		$at            = $call_name . '@' . $this->request_site_id();
		$this->sites[] = $at;
		// "Call@site#ItemID" for one listing's answer, over "Call@site".
		$item = preg_match( '#<ItemID>(\d+)</ItemID>#', $xml_body, $m ) ? $m[1] : '';
		foreach ( array( $at . '#' . $item, $at ) as $key ) {
			if ( array_key_exists( $key, $this->site_answers ) ) {
				$this->calls[] = array( $call_name, $xml_body );
				return $this->site_answers[ $key ];
			}
		}
		return parent::trading_api_request( $call_name, $xml_body, $allow_retry, $max_body_bytes );
	}
	public function get_category_aspects( $category_id ) {
		return $this->aspects_of( '0', $category_id );
	}
	public function get_category_aspects_in_tree( $category_id, $tree_id ) {
		return $this->aspects_of( (string) $tree_id, $category_id );
	}
	private function aspects_of( $tree, $category_id ) {
		$this->rest[] = 'aspects:' . $tree . ':' . $category_id;
		return $this->tree_aspects[ $tree ][ (string) $category_id ] ?? $this->aspects;
	}
	public function get_category_node( $category_id, $tree_id ) {
		$this->rest[] = 'node:' . $tree_id . ':' . $category_id;
		return $this->nodes[ (string) $category_id ] ?? motors_error( 62005 );
	}
	public function get_category_suggestions_in_tree( $query, $tree_id ) {
		$this->rest[] = 'suggest:' . $tree_id . ':' . $query;
		return $this->suggest[ (string) $query ] ?? array();
	}
	public function get_suggested_categories( $title ) {
		$this->rest[] = 'suggest:0:' . $title;
		return $this->suggest0[ (string) $title ] ?? array();
	}
	public function get_condition_policies_for( $category_id, $marketplace_id ) {
		$this->rest[] = 'conditions:' . $marketplace_id . ':' . $category_id;
		return $this->conds[ (string) $category_id ] ?? motors_error( 46001 );
	}
	public function get_category_children_in_tree( $category_id, $tree_id ) {
		$this->rest[] = 'children:' . $tree_id . ':' . $category_id;
		return $this->children[ (string) $category_id ] ?? array();
	}
	public function get_category_tree_root() {
		$this->rest[] = 'root:0';
		return array( array( 'id' => '1', 'name' => 'Collectibles', 'leaf' => false ) );
	}
	/** Forget what was recorded. */
	public function clear() {
		$this->sites = array();
		$this->rest  = array();
		$this->calls = array();
	}
}

/** A Trading refusal with eBay's error code, as trading_api_request() hands one back. */
function trading_error( $code, $message, array $params = array(), $severity = 'Error', array $more = array() ) {
	$error = array( 'ErrorCode' => (string) $code, 'ShortMessage' => $message, 'LongMessage' => $message, 'SeverityCode' => $severity );
	if ( $params ) {
		$list = array();
		foreach ( array_values( $params ) as $i => $value ) {
			$list[] = array( '@attributes' => array( 'ParamID' => (string) $i ), 'Value' => (string) $value );
		}
		$error['ErrorParameters'] = 1 === count( $list ) ? $list[0] : $list;
	}
	return new WP_Error( 'api_error', $message, array( 'Ack' => 'Failure', 'Errors' => $more ? array_merge( $more, array( $error ) ) : $error ) );
}

/** eBay's conditions for a Motors Parts & Accessories category. */
function motors_conditions( $category_id, array $conditions ) {
	$list = array();
	foreach ( $conditions as $id => $name ) {
		$list[] = array( 'conditionId' => (string) $id, 'conditionDescription' => $name );
	}
	return array( 'itemConditionPolicies' => array( array( 'categoryId' => (string) $category_id, 'categoryTreeId' => '100', 'itemConditionRequired' => true, 'itemConditions' => $list ) ) );
}

$GLOBALS['API'] = new Motors_API();
$tcg_motors_slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$tcg_motors_slot->setAccessible( true );
}
$tcg_motors_slot->setValue( null, $GLOBALS['API'] );
