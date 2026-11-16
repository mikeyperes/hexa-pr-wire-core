<?php

namespace HexaPrWire\Core\Integrations;

use SMP\VerifiedProfiles\ProfileTypes\ProfileTypes;
use SMP\VerifiedProfiles\Schema\ProfileSchema;

/** Read existing person profiles through SMP's registered types and schema policy. */
final class VerifiedProfiles {
	public function available(): bool {
		return class_exists( ProfileTypes::class ) && class_exists( ProfileSchema::class );
	}

	public function query( string $name ): array {
		$name = trim( sanitize_text_field( $name ) );
		if ( ! $this->available() || mb_strlen( $name ) < 2 ) {
			return [];
		}
		$types = ProfileTypes::post_types();
		if ( ! $types ) {
			return [];
		}
		$posts = get_posts( [
			'post_type' => $types,
			'post_status' => 'publish',
			's' => $name,
			'search_columns' => [ 'post_title' ],
			'posts_per_page' => 20,
			'orderby' => 'title',
			'order' => 'ASC',
		] );
		$profiles = [];
		foreach ( $posts as $post ) {
			$profile = $this->person( $post->ID );
			if ( $profile ) {
				$profiles[] = $profile;
			}
		}
		return $profiles;
	}

	public function person( int $id ): ?array {
		return current_user_can( 'read_post', $id ) ? $this->public_person( $id ) : null;
	}

	public function public_person( int $id ): ?array {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $this->available() || ! $post instanceof \WP_Post || 'publish' !== $post->post_status
			|| '' !== $post->post_password || ! in_array( $post->post_type, ProfileTypes::post_types(), true )
			|| 'person' !== ProfileSchema::profile_type( $id ) ) {
			return null;
		}
		return [
			'id' => $id,
			'name' => get_the_title( $id ),
			'url' => get_permalink( $id ),
			'image_url' => get_the_post_thumbnail_url( $id, 'medium' ) ?: '',
			'entity_type' => 'person',
		];
	}
}
