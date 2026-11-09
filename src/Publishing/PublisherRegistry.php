<?php
/**
 * Publisher registry.
 *
 * @package CustomPostImageCreator
 */

namespace CPIC\Publishing;

use CPIC\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds one instance per provider. Add-ons register providers through the
 * `cpic/publishers` filter; anything not implementing PublisherInterface is
 * dropped rather than crashing a publish run.
 */
final class PublisherRegistry {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Cached publishers.
	 *
	 * @var array<string,PublisherInterface>|null
	 */
	private $publishers = null;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * All publishers keyed by id.
	 *
	 * @return array<string,PublisherInterface>
	 */
	public function all() {
		if ( null !== $this->publishers ) {
			return $this->publishers;
		}

		$publishers = array(
			'facebook' => new Providers\FacebookPublisher( $this->container ),
			'telegram' => new Providers\TelegramPublisher( $this->container ),
		);

		/**
		 * Register additional publishers.
		 *
		 * @param array<string,PublisherInterface> $publishers Publishers keyed by id.
		 * @param Container                        $container  Container.
		 */
		$filtered = apply_filters( 'cpic/publishers', $publishers, $this->container );

		$this->publishers = array();

		foreach ( (array) $filtered as $id => $publisher ) {
			if ( $publisher instanceof PublisherInterface ) {
				$this->publishers[ (string) $id ] = $publisher;
			}
		}

		return $this->publishers;
	}

	/**
	 * One publisher.
	 *
	 * @param string $id Provider id.
	 * @return PublisherInterface|null
	 */
	public function get( $id ) {
		$all = $this->all();

		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Whether a provider is supported.
	 *
	 * @param string $id Provider id.
	 * @return bool
	 */
	public function has( $id ) {
		return null !== $this->get( $id );
	}

	/**
	 * Provider descriptors for the admin/REST layer (no secrets).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function descriptors() {
		$out = array();

		foreach ( $this->all() as $id => $publisher ) {
			$out[] = array(
				'id'               => (string) $id,
				'label'            => $publisher->label(),
				'credential_fields' => $publisher->credential_fields(),
				'caption_limit'    => $publisher->caption_limit(),
				'preferred_format' => $publisher->preferred_format(),
			);
		}

		return $out;
	}

	/**
	 * Status for diagnostics.
	 *
	 * @return array<string,mixed>
	 */
	public function status() {
		return array(
			'providers' => array_keys( $this->all() ),
			'count'     => count( $this->all() ),
		);
	}
}
