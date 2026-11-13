<?php
/**
 * Reads the OData $metadata document.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Api;

/**
 * Finds which fields a resource offers, so requests only `$select` fields the
 * server has. MLS systems differ (e.g. `LivingArea` vs `LivingAreaRange`), and
 * selecting an unknown field fails the whole request.
 */
final class Metadata {

	/**
	 * Property names of an entity type in a CSDL XML document.
	 *
	 * @param string $xml         The $metadata document.
	 * @param string $entity_type Entity type name, e.g. Property.
	 * @return list<string>
	 * @throws ApiException When the document can't be parsed.
	 */
	public static function fields( string $xml, string $entity_type ): array {
		$previous = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument();
		$ok       = $doc->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $ok ) {
			throw new ApiException( 'Could not parse the $metadata document.', 200 );
		}

		$fields = array();
		foreach ( $doc->getElementsByTagName( 'EntityType' ) as $type ) {
			if ( $type->getAttribute( 'Name' ) !== $entity_type ) {
				continue;
			}
			foreach ( $type->getElementsByTagName( 'Property' ) as $prop ) {
				$fields[] = $prop->getAttribute( 'Name' );
			}
		}
		return array_values( array_unique( array_filter( $fields ) ) );
	}
}
