<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use App\Shared\Infrastructure\Persistence\Doctrine\Type\IdType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

return App::config([
    'doctrine' => [
        'dbal' => [
            // `Id` is project-wide (ADR 002); the others belong to one context each and are
            // prefixed with it, because this list is flat and shared by every entity manager.
            'types' => [
                // `IdType` is the one type this boilerplate ships, because `Id` is the one value
                // object `Shared` owns. ADR 003 path 2 adds one entry here per coercion your
                // aggregates need, and ADR 005 says the constant lives on the type:
                //
                //     HandleType::NAME => HandleType::class,
                //     BadgeConditionType::NAME => BadgeConditionType::class,
                //
                // A *backed* enum needs no entry at all. Declare one pure and it needs
                // `PureEnumType`, which is path 2 rather than path 1.
                IdType::NAME => IdType::class,
            ],
            'url' => env('DATABASE_URL')->resolve(),

            // IMPORTANT: You MUST configure your server version,
            // either here or in the DATABASE_URL env var (see .env file)
            // 'server_version' => '18',

            'profiling_collect_backtrace' => '%kernel.debug%',
            'use_savepoints' => true,
        ],
        'orm' => [
            'auto_generate_proxy_classes' => true,
            'enable_lazy_ghost_objects' => true,
            'controller_resolver' => [
                'auto_mapping' => false,
            ],

            /*
             * OPTION 3. Two entity managers over ONE DBAL connection.
             *
             * Sharing the connection is the whole point and not an optimisation: two managers on
             * two connections cannot commit together, whatever else you do. On one connection the
             * second flush nests inside the first transaction through savepoints (see
             * `use_savepoints` above), so a projection update and a Pair insert either both land
             * or neither does.
             *
             * What the split buys:
             *  - `ManagerRegistry::getManagerForClass()` routes by class, so nothing ever has to
             *    name an entity manager.
             *  - A query handler would not reach a write-model repository by accident, because the
             *    manager it gets does not have that metadata at all;
             *  - `doctrine:schema:create --em=read_model` builds the projections and only the
             *    projections, which is what saying "replay the read model" means in practice;
             *  - Each side keeps its own mapping conventions, below.
             *
             * One detail worth mentionning: `doctrine:schema:update` is no longer usable.
             * It diffs one manager's metadata against the whole database, and since both managers
             * see the same database, each one drops the other's tables. You'd use `schema:create` per
             * manager in development and migrations everywhere else (which already is a good practice).
             */
            'default_entity_manager' => 'default',
            'entity_managers' => [
                'default' => [
                    'connection' => 'default',
                    'report_fields_where_declared' => true,
                    'validate_xml_mapping' => true,
                    'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
                    'identity_generation_preferences' => [
                        PostgreSQLPlatform::class => 'identity',
                    ],
                    // Off: every mapping below is declared explicitly, which is what keeps the XML
                    // out of the Domain namespaces it describes.
                    'auto_mapping' => false,
                    'mappings' => [
                        // One entry per namespace prefix. Two entries sharing a prefix would fight
                        // over the same slot in Doctrine's MappingDriverChain and the second would
                        // win for every class, so a context's domain mapping is a single directory.

                        // Option 5. The outbox is an ordinary mapped entity on purpose: its row has
                        // to "ride along" the same flush and the same transaction as whatever caused it.
                        'SharedOutbox' => [
                            'type' => 'xml',
                            'dir' => '%kernel.project_dir%/src/Shared/Infrastructure/Persistence/Outbox/Mapping',
                            'prefix' => 'App\Shared\Infrastructure\Persistence\Outbox',
                            'is_bundle' => false,
                        ],
                        // Each bounded context adds ONE entry here, for ADR 003 paths 1 to 3. The
                        // directory is the context's `Infrastructure`, never its `Domain`, which is
                        // what keeps `Doctrine\ORM\Mapping` out of a domain class and the phpat
                        // domain rules intact:
                        //
                        //     'Membership' => [
                        //         'type' => 'xml',
                        //         'dir' => '%kernel.project_dir%/src/Membership/Infrastructure/Persistence/Doctrine/ORM/Mapping',
                        //         'prefix' => 'App\Membership\Domain',
                        //         'is_bundle' => false,
                        //     ],
                        //
                        // ADR 003 path 3's shadow entities are a SECOND entry, and only because the
                        // prefix differs: they are mapped from `Infrastructure` because that is
                        // where a shadow entity lives.
                        //
                        //     'MembershipShadow' => [
                        //         'type' => 'xml',
                        //         'dir' => '%kernel.project_dir%/src/Membership/Infrastructure/Persistence/Doctrine/ORM/ManualMapping',
                        //         'prefix' => 'App\Membership\Infrastructure\Persistence\Doctrine\ORM\Entity',
                        //         'is_bundle' => false,
                        //     ],
                        // ADR 008 and ADR 009, and the one mapping in the tree owned by `Ui` rather
                        // than by a bounded context. A credential has no business behaviour, no
                        // invariant and no language of its own, so giving it a context would produce
                        // a context with one entity and nothing to decide. The trigger that moves it
                        // is written down in ADR 008: when credentials grow invariants (lockout,
                        // password history, MFA enrolment) they have a state machine and a language,
                        // and at that point they are an `Identity` context and the tables go with
                        // them. Default manager, not `read_model`: they are written once and read by
                        // identity, so they are on neither side of that fence.
                        //
                        // Two tables, one directory, because Doctrine's MappingDriverChain gives one
                        // namespace prefix one directory. ADR 009's table says what each is for and
                        // why they are not one: `ui_credential` lives as long as the account, and
                        // `ui_revoked_token` holds a withdrawn token's `jti` until that token would
                        // have expired anyway. A session appears in neither, because a session is
                        // PHP's and is stored nowhere of ours.
                        'UiSecurity' => [
                            'type' => 'xml',
                            'dir' => '%kernel.project_dir%/src/Shared/Infrastructure/Security/Mapping',
                            'prefix' => 'App\Shared\Infrastructure\Security',
                            'is_bundle' => false,
                        ],
                    ],
                ],
                'read_model' => [
                    'connection' => 'default',
                    'report_fields_where_declared' => true,
                    'validate_xml_mapping' => true,
                    // For the sake of the demonstration, we deliberately set a different strategy from the write side.
                    // A projection's columns mirror the field names its consumers read, so `patternLabel` and not `pattern_label`,
                    // and that convention must never leak back into the write model.
                    // Having two managers is what allows to have the distinction.
                    'naming_strategy' => 'doctrine.orm.naming_strategy.default',
                    'auto_mapping' => false,
                    'mappings' => [
                        // Empty, and the manager is declared anyway, because the declaration IS the
                        // decision: ADR 003 path 3 and ADR 004 both rest on a second manager over
                        // the SAME connection, so the second flush nests inside the first
                        // transaction through savepoints. Two managers on two connections cannot
                        // commit together.
                        //
                        // A projection registers here rather than on the default manager so that a
                        // query handler cannot reach a write-model repository by accident: the
                        // manager it gets has never heard of that class.
                        //
                        //     'MembershipReadModel' => [
                        //         'type' => 'xml',
                        //         'dir' => '%kernel.project_dir%/src/Membership/Infrastructure/ReadModel/Mapping',
                        //         'prefix' => 'App\Membership\Infrastructure\ReadModel',
                        //         'is_bundle' => false,
                        //     ],
                        //
                        // Note the limit before relying on it: **DQL does not join across managers
                        // even on one connection**, so a `NOT EXISTS` against the other manager's
                        // table is not available. Project what you need.
                    ],
                ],
            ],
        ],
    ],
    'when@test' => [
        'doctrine' => [
            'dbal' => [
                // "TEST_TOKEN" is typically set by ParaTest
                'dbname_suffix' => '_test'.env('TEST_TOKEN')->default(''),
            ],
        ],
    ],
    'when@prod' => [
        'doctrine' => [
            'orm' => [
                'auto_generate_proxy_classes' => false,
                'proxy_dir' => '%kernel.build_dir%/doctrine/orm/Proxies',
                'query_cache_driver' => [
                    'type' => 'pool',
                    'pool' => 'doctrine.system_cache_pool',
                ],
                'result_cache_driver' => [
                    'type' => 'pool',
                    'pool' => 'doctrine.result_cache_pool',
                ],
            ],
        ],
        'framework' => [
            'cache' => [
                'pools' => [
                    'doctrine.result_cache_pool' => [
                        'adapter' => 'cache.app',
                    ],
                    'doctrine.system_cache_pool' => [
                        'adapter' => 'cache.system',
                    ],
                ],
            ],
        ],
    ],
]);
