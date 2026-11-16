<?php

namespace HexaPrWire\Core\Integrations;

use HexaPrWire\Core\Contracts\Module;

/** Published author/profile lookup and release lists for external site consumers. */
final class ReleaseProfileApi implements Module {
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		$routes = [
			'/authors' => 'find_author',
			'/authors/(?P<id>\d+)' => 'author',
			'/authors/(?P<id>\d+)/releases' => 'author_releases',
			'/verified-profiles' => 'people',
			'/verified-profiles/(?P<id>\d+)' => 'person',
			'/verified-profiles/(?P<id>\d+)/releases' => 'releases',
		];
		foreach ( $routes as $route => $method ) {
			register_rest_route( 'hprwc/v1', $route, [
				'methods' => 'GET',
				'callback' => [ $this, $method ],
				'permission_callback' => '__return_true',
			] );
		}
	}

	public function find_author( \WP_REST_Request $request ): \WP_REST_Response {
		$author = ( new ReleaseProfileRepository() )->find_author( $this->text( $request, 'profile_url' ) );
		return $author ? $this->success( $author ) : $this->missing( 'author' );
	}

	public function author( \WP_REST_Request $request ): \WP_REST_Response {
		$author = ( new ReleaseProfileRepository() )->author( absint( $request['id'] ) );
		return $author ? $this->success( $author ) : $this->missing( 'author' );
	}

	public function author_releases( \WP_REST_Request $request ): \WP_REST_Response {
		$repository = new ReleaseProfileRepository();
		$author = $repository->author( absint( $request['id'] ) );
		if ( ! $author ) {
			return $this->missing( 'author' );
		}
		$data = $repository->releases( [ 'author' => $author['id'] ], $this->page( $request ) );
		return $this->success( [ 'author' => $author ] + $data );
	}

	public function people( \WP_REST_Request $request ): \WP_REST_Response {
		$data = ( new ReleaseProfileRepository() )->people( $this->text( $request, 'q' ), $this->page( $request ) );
		return $this->success( $data );
	}

	public function person( \WP_REST_Request $request ): \WP_REST_Response {
		$person = ( new VerifiedProfiles() )->public_person( absint( $request['id'] ) );
		return $person ? $this->success( $person ) : $this->missing( 'person' );
	}

	public function releases( \WP_REST_Request $request ): \WP_REST_Response {
		$person = ( new VerifiedProfiles() )->public_person( absint( $request['id'] ) );
		if ( ! $person ) {
			return $this->missing( 'person' );
		}
		$data = ( new ReleaseProfileRepository() )->releases( [ 'profile' => $person['id'] ], $this->page( $request ) );
		return $this->success( [ 'profile' => $person ] + $data );
	}

	private function text( \WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );
		return is_string( $value ) ? $value : '';
	}

	private function page( \WP_REST_Request $request ): int {
		return min( 1000, max( 1, absint( $request->get_param( 'page' ) ) ) );
	}

	private function success( array $data ): \WP_REST_Response {
		return new \WP_REST_Response( [ 'success' => true, 'data' => $data ] );
	}

	private function missing( string $kind ): \WP_REST_Response {
		return new \WP_REST_Response( [
			'success' => false,
			'code' => $kind . '_not_found',
			'message' => 'The requested public ' . $kind . ' is unavailable.',
		], 404 );
	}
}
