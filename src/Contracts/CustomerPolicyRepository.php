<?php

namespace HexaPrWire\Core\Contracts;

interface CustomerPolicyRepository {
	public function is_customer( int $user_id ): bool;
	public function mode( int $user_id ): string;
	public function publication_access_mode( int $user_id ): string;
	public function publication_access_configured( int $user_id ): bool;
	/** @return int[] */
	public function allowed_publications( int $user_id ): array;
	/** @return array<int,string> */
	public function publication_prices( int $user_id ): array;
	/** @param int[] $publication_ids @param array<int|string,mixed> $prices */
	public function save( int $user_id, string $mode, array $publication_ids, array $prices = [], string $publication_access_mode = 'unrestricted' ): void;
}
