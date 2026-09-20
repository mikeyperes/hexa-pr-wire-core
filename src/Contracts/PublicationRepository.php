<?php

namespace HexaPrWire\Core\Contracts;

interface PublicationRepository {
	/** @return array<int,array<string,mixed>> */
	public function all( array $criteria = [] ): array;
	/** @return array<int,array<string,mixed>> */
	public function for_release( int $post_id ): array;
	public function mapped_post_id( int $term_id ): int;
}
