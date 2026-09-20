<?php

namespace HexaPrWire\Core\Infrastructure\WordPress;

use HexaPrWire\Core\Contracts\Mailer;

final class WordPressMailer implements Mailer {
	/** @param string[] $recipients */
	public function send( array $recipients, string $subject, string $message ): bool {
		$recipients = array_values( array_unique( array_filter( array_map( 'sanitize_email', $recipients ), 'is_email' ) ) );
		if ( ! $recipients ) {
			return false;
		}
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$domain = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$from = is_string( $domain ) ? 'no-reply@' . preg_replace( '/^www\./', '', $domain ) : '';
		$reply_to = sanitize_email( (string) ( get_option( 'options_website_email', '' ) ?: get_option( 'options_contact_email', '' ) ?: get_option( 'admin_email' ) ) );
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		if ( is_email( $from ) ) {
			$headers[] = 'From: ' . $site_name . ' <' . $from . '>';
		}
		if ( is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $site_name . ' <' . $reply_to . '>';
		}
		$signature = wp_kses_post( (string) get_option( 'options_email_signature', '' ) );
		$separator = '' !== trim( $signature ) ? '<br><br>' : '';
		return wp_mail( $recipients, sanitize_text_field( $subject ), wp_kses_post( $message . $separator . $signature ), $headers );
	}
}
