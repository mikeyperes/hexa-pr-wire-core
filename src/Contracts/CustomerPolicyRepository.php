<?php

namespace HexaPrWire\Core\Contracts;

interface CustomerPolicyRepository {
	public const ACCESS_UNRESTRICTED = 'unrestricted';
	public const ACCESS_RESTRICTED = 'restricted';
	public const ACCESS_EXCLUDED = 'excluded';

	public function is_customer( int $user_id ): bool;
	public function mode( int $user_id ): string;
	public function publication_access_mode( int $user_id ): string;
	public function publication_access_configured( int $user_id ): bool;
	/** @return int[] */
	public function allowed_publications( int $user_id ): array;
	/** @return int[] Excluded publications; with descendants, excluding a group also excludes its current and future outlets. */
	public function excluded_publications( int $user_id, bool $with_descendants = true ): array;
	/** @return array<int,string> */
	public function publication_prices( int $user_id ): array;
	/** @param int[] $publication_ids @param array<int|string,mixed> $prices @param int[]|null $excluded_publication_ids null keeps the stored exclusions */
	public function save( int $user_id, string $mode, array $publication_ids, array $prices = [], string $publication_access_mode = self::ACCESS_UNRESTRICTED, ?array $excluded_publication_ids = null ): void;
}
