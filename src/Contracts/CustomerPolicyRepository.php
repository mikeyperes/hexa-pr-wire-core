<?php

namespace HexaPrWire\Core\Contracts;

interface CustomerPolicyRepository {
	public function is_customer( int $user_id ): bool;
	public function mode( int $user_id ): string;
	/** @return int[] */
	public function allowed_publications( int $user_id ): array;
	/** @return array<int,string> */
	public function publication_prices( int $user_id ): array;
	/** @param int[] $publication_ids @param array<int|string,mixed> $prices */
	public function save( int $user_id, string $mode, array $publication_ids, array $prices = [] ): void;
}
