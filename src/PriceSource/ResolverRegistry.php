<?php

namespace Fractured\Dexter\PriceSource;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registry for authoritative source-price resolvers.
 *
 * Responsibilities:
 *
 * - hold the available platform resolvers;
 * - identify which resolver understands a source snapshot;
 * - refuse ambiguous snapshots;
 * - isolate resolver exceptions from the pricing pipeline;
 * - always fail closed to PriceState::unknown().
 *
 * The registry performs no:
 *
 * - HTTP requests;
 * - database writes;
 * - FX conversion;
 * - WooCommerce product writes.
 */
final class ResolverRegistry {

    /**
     * @var ResolverInterface[]
     */
    private $resolvers = [];

    /**
     * @param ResolverInterface[] $resolvers
     */
    public function __construct(
        array $resolvers = []
    ) {
        foreach ( $resolvers as $resolver ) {
            if ( $resolver instanceof ResolverInterface ) {
                $this->register( $resolver );
            }
        }
    }

    /**
     * Register a resolver.
     *
     * Resolver IDs must be unique. Registering another resolver with the
     * same ID replaces the previous instance rather than allowing duplicate
     * platform handlers.
     */
    public function register(
        ResolverInterface $resolver
    ): void {

        $id = trim(
            $resolver->id()
        );

        if ( '' === $id ) {
            return;
        }

        $this->resolvers[ $id ] = $resolver;
    }

    /**
     * Resolve authoritative pricing from a decoded source snapshot.
     *
     * Exactly one resolver must support the snapshot.
     *
     * Zero matches:
     *     The platform/source shape is not understood.
     *
     * Multiple matches:
     *     Resolver definitions overlap, so using either would be unsafe.
     *
     * In both cases we return UNKNOWN.
     *
     * @param \WC_Product         $product
     * @param array<string,mixed> $snapshot
     */
    public function resolve(
        \WC_Product $product,
        array $snapshot
    ): PriceState {

        if ( empty( $snapshot ) ) {
            return PriceState::unknown(
                'registry',
                'Source snapshot is empty.'
            );
        }

        $matches = [];

        foreach ( $this->resolvers as $resolver ) {
            try {
                if ( $resolver->supports( $snapshot ) ) {
                    $matches[] = $resolver;
                }
            } catch ( \Throwable $e ) {
                /*
                 * A broken platform detector must never be allowed to
                 * interrupt a product update.
                 *
                 * Continue checking the remaining resolvers.
                 */
                continue;
            }
        }

        if ( empty( $matches ) ) {
            return PriceState::unknown(
                'registry',
                'No resolver supports the source snapshot.'
            );
        }

        if ( 1 !== count( $matches ) ) {
            $ids = [];

            foreach ( $matches as $resolver ) {
                $ids[] = $resolver->id();
            }

            return PriceState::unknown(
                'registry',
                'Multiple resolvers support the source snapshot: '
                    . implode( ', ', $ids )
            );
        }

        $resolver = $matches[0];

        try {
            $state = $resolver->resolve(
                $product,
                $snapshot
            );
        } catch ( \Throwable $e ) {
            return PriceState::unknown(
                $resolver->id(),
                'Resolver threw an exception: '
                    . $e->getMessage()
            );
        }

        /*
         * Defensive guard.
         *
         * The interface requires PriceState, but keeping this check makes
         * the boundary explicit if implementations change in future.
         */
        if ( ! $state instanceof PriceState ) {
            return PriceState::unknown(
                $resolver->id(),
                'Resolver returned an invalid result.'
            );
        }

        return $state;
    }

    /**
     * Return registered resolver IDs.
     *
     * Useful for diagnostics/tests without exposing mutable resolver
     * instances to callers.
     *
     * @return string[]
     */
    public function ids(): array {
        return array_keys(
            $this->resolvers
        );
    }
}