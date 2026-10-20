<?php

namespace HexaPrWire\Core\Admin;

use HexaPrWire\Core\Contracts\Module;

/**
 * The outlet picker on the release editor.
 *
 * - Administrators keep the full hierarchy; ticking a group ticks its outlets.
 * - Everyone else sees only the outlets themselves (no group names), in one
 *   flat list with "Select all".
 * - A single-outlet order is locked to the purchased outlet: no picker, just
 *   the outlet's name (also enforced on save by AccessController).
 */
final class PublicationPicker implements Module {
	public const LOCK_META = '_hprwc_locked_publication_term';

	public function register(): void {
		add_action( 'add_meta_boxes_post', [ $this, 'boxes' ], 20 );
		add_action( 'admin_footer-post.php', [ $this, 'admin_tree_script' ] );
		add_action( 'admin_footer-post-new.php', [ $this, 'admin_tree_script' ] );
	}

	public function boxes( \WP_Post $post ): void {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}
		remove_meta_box( 'publicationdiv', 'post', 'side' );
		add_meta_box( 'hprwc-publications', 'Publications', [ $this, 'render' ], 'post', 'side', 'default' );
	}

	public function render( \WP_Post $post ): void {
		$locked = (int) get_post_meta( $post->ID, self::LOCK_META, true );
		if ( $locked > 0 ) {
			$term = get_term( $locked, 'publication' );
			echo '<p>This release goes to <strong>' . esc_html( $term instanceof \WP_Term ? $term->name : 'the purchased publication' ) . '</strong>.</p>';
			echo '<input type="hidden" name="tax_input[publication][]" value="' . esc_attr( (string) $locked ) . '">';
			return;
		}
		$outlets = self::outlets();
		$selected = wp_get_object_terms( $post->ID, 'publication', [ 'fields' => 'ids' ] );
		$selected = is_wp_error( $selected ) ? [] : array_map( 'absint', $selected );

		echo '<input type="hidden" name="tax_input[publication][]" value="0">';
		echo '<p><label><input type="checkbox" id="hprwc-select-all"> <strong>Select all</strong></label></p>';
		echo '<div id="hprwc-outlets" style="max-height:320px;overflow:auto">';
		foreach ( $outlets as $term ) {
			echo '<label style="display:block;margin:0 0 4px"><input type="checkbox" name="tax_input[publication][]" value="' . esc_attr( (string) $term->term_id ) . '"' . checked( in_array( (int) $term->term_id, $selected, true ), true, false ) . '> ' . esc_html( $term->name ) . '</label>';
		}
		echo '</div><script>(function(){var all=document.getElementById("hprwc-select-all"),boxes=document.querySelectorAll("#hprwc-outlets input");if(!all)return;var sync=function(){all.checked=boxes.length>0&&Array.prototype.every.call(boxes,function(b){return b.checked;});};all.addEventListener("change",function(){boxes.forEach(function(b){b.checked=all.checked;});});boxes.forEach(function(b){b.addEventListener("change",sync);});sync();})();</script>';
	}

	/**
	 * Publications a release can actually go to: terms without child outlets, by name.
	 *
	 * @return \WP_Term[]
	 */
	public static function outlets(): array {
		$terms = get_terms( [ 'taxonomy' => 'publication', 'hide_empty' => false ] );
		$terms = is_wp_error( $terms ) ? [] : $terms;
		$parents = array_flip( array_map( static fn( \WP_Term $term ): int => (int) $term->parent, $terms ) );
		$outlets = array_values( array_filter( $terms, static fn( \WP_Term $term ): bool => ! isset( $parents[ (int) $term->term_id ] ) ) );
		usort( $outlets, static fn( \WP_Term $a, \WP_Term $b ): int => strnatcasecmp( $a->name, $b->name ) );
		return $outlets;
	}

	public function admin_tree_script(): void {
		if ( ! current_user_can( 'manage_options' ) || 'post' !== get_post_type() ) {
			return;
		}
		echo '<script>(function(){var list=document.getElementById("publicationchecklist");if(!list)return;list.addEventListener("change",function(e){var box=e.target;if(!box||box.type!=="checkbox")return;var li=box.closest("li");if(!li)return;li.querySelectorAll("ul input[type=checkbox]").forEach(function(child){child.checked=box.checked;});});})();</script>';
	}
}
