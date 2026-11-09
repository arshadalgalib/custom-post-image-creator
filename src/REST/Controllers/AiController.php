<?php
/**
 * AI assistance REST controller.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\REST\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/cpic/v1/ai`
 */
final class AiController extends BaseController {

	/**
	 * {@inheritDoc}
	 */
	public function base() {
		return '/ai';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		$use = $this->requires( 'cpic_use_ai' );

		$this->add(
			'/suggest',
			array( 'POST' => array( 'callback' => array( $this, 'suggest' ), 'permission' => $use ) )
		);

		$this->add(
			'/status',
			array( 'GET' => array( 'callback' => array( $this, 'status' ) ) )
		);
	}

	/**
	 * Request suggestions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function suggest( $request ) {
		$limit = $this->container->get( 'rate_limiter' )->allow( 'ai', 'user_' . get_current_user_id() );

		if ( ! $limit ) {
			return $this->fail(
				new \WP_Error( 'cpic_rate_limited', $this->container->get( 'rate_limiter' )->message(), array( 'status' => 429 ) )
			);
		}

		return $this->maybe(
			$this->container->get( 'ai' )->suggest(
				$this->param( $request, 'operation', 'headlines' ),
				array(
					'post_id' => $this->param_int( $request, 'post_id' ),
					'title'   => $this->param( $request, 'title' ),
					'excerpt' => $this->param( $request, 'excerpt' ),
				)
			)
		);
	}

	/**
	 * AI status.
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		$ai = $this->container->get( 'ai' );

		return $this->ok(
			array(
				'enabled'    => $this->container->get( 'settings' )->is_enabled( 'cpic_ai_enabled' ),
				'configured' => $ai->is_configured(),
				'providers'  => $ai->providers(),
				'usage'      => $ai->usage(),
			)
		);
	}
}
