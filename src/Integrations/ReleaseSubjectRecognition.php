<?php

namespace HexaPrWire\Core\Integrations;

/** Press-release policy applied to SMP's AI entity proposals. */
final class ReleaseSubjectRecognition {
	public function __construct( private VerifiedProfiles $profiles ) {}

	public function recognize( array $entities, string $title, string $content ): array {
		$plain = $this->plain( $content );
		$title = $this->plain( $title );
		$lead = mb_substr( $plain, 0, 1200 );
		$matches = [];
		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) || 'person' !== ( $entity['entity_type'] ?? '' )
				|| (float) ( $entity['confidence'] ?? 0 ) < 0.9 ) {
				continue;
			}
			$name = trim( sanitize_text_field( (string) ( $entity['name'] ?? '' ) ) );
			if ( '' === $name || ! $this->mentions( $title . ' ' . $plain, $name ) ) {
				continue;
			}
			$in_title = $this->mentions( $title, $name );
			$in_lead = $this->mentions( $lead, $name );
			if ( ! $in_title && ! $in_lead ) {
				continue;
			}
			$matched = false;
			$evidence = $in_title ? 'Named in the release headline.' : 'Named in the opening release text; review before attaching.';
			foreach ( $this->profiles->query( $name ) as $profile ) {
				if ( $this->plain( $profile['name'] ) !== $this->plain( $name ) ) {
					continue;
				}
				$matched = true;
				$profile['evidence'] = $evidence;
				$profile['rank'] = $in_title ? 2 : 1;
				$matches[ $profile['id'] ] = $profile;
			}
			if ( ! $matched ) {
				$matches[ $this->plain( $name ) ] = [
					'id' => 0, 'name' => $name, 'url' => '', 'rank' => $in_title ? 2 : 1,
					'evidence' => $evidence . ' No existing person profile matches this name.',
				];
			}
		}
		usort( $matches, static function ( array $a, array $b ): int {
			return $b['rank'] <=> $a['rank'] ?: strcasecmp( $a['name'], $b['name'] );
		} );
		return array_values( $matches );
	}

	private function plain( string $value ): string {
		$value = preg_replace( '/<\/(?:p|div|h[1-6]|li|blockquote)>/i', ' ', $value ) ?? $value;
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return mb_strtolower( trim( preg_replace( '/\s+/u', ' ', $value ) ?? '' ) );
	}

	private function mentions( string $text, string $name ): bool {
		return 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $this->plain( $name ), '/' ) . '(?![\p{L}\p{N}])/u', $text );
	}
}
