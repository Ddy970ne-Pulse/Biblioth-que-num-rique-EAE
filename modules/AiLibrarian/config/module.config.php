<?php

namespace AiLibrarian;

use AiLibrarian\Controller\Admin\IndexController as AdminIndexController;
use AiLibrarian\Controller\SearchController;
use AiLibrarian\Service\AnswerService;
use AiLibrarian\Service\CorpusIndexer;
use AiLibrarian\Service\Embedding\EmbeddingProviderInterface;
use AiLibrarian\Service\Embedding\LocalHashEmbeddingProvider;
use AiLibrarian\Service\Embedding\VoyageEmbeddingProvider;
use Laminas\Router\Http\Literal;

return [
    'controllers' => [
        'factories' => [
            SearchController::class => function ($container) {
                return new SearchController($container->get(AnswerService::class));
            },
            AdminIndexController::class => function ($container) {
                return new AdminIndexController($container->get(CorpusIndexer::class));
            },
        ],
    ],

    'router' => [
        'routes' => [
            // Recherche publique : /ai-librarian et /ai-librarian/ask
            'ai-librarian' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/ai-librarian',
                    'defaults' => [
                        '__NAMESPACE__' => 'AiLibrarian\Controller',
                        'controller' => SearchController::class,
                        'action' => 'index',
                    ],
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'ask' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/ask',
                            'defaults' => [
                                'action' => 'ask',
                            ],
                        ],
                    ],
                ],
            ],
            // Ajoute une page sous l'admin existant : /admin/ai-librarian
            // et /admin/ai-librarian/reindex
            'admin' => [
                'child_routes' => [
                    'ai-librarian' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/ai-librarian',
                            'defaults' => [
                                '__NAMESPACE__' => 'AiLibrarian\Controller\Admin',
                                'controller' => AdminIndexController::class,
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'reindex' => [
                                'type' => Literal::class,
                                'options' => [
                                    'route' => '/reindex',
                                    'defaults' => [
                                        'action' => 'reindex',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'AI Librarian',
                'route' => 'admin/ai-librarian',
                'resource' => AdminIndexController::class,
            ],
        ],
    ],

    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],

    'service_manager' => [
        'factories' => [
            // Fournisseur d'embeddings actif : Voyage AI si une clé est
            // configurée (réglages du module ou variable d'environnement
            // VOYAGE_API_KEY), sinon repli local dégradé. Voir
            // docs/architecture-ia.md.
            EmbeddingProviderInterface::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                $voyageKey = $settings->get('ailibrarian_voyage_api_key')
                    ?: (getenv('VOYAGE_API_KEY') ?: null);

                if (!empty($voyageKey)) {
                    return new VoyageEmbeddingProvider($voyageKey);
                }

                return new LocalHashEmbeddingProvider();
            },

            CorpusIndexer::class => function ($container) {
                return new CorpusIndexer(
                    $container->get('Omeka\ApiManager'),
                    $container->get('Omeka\Connection'),
                    $container->get(EmbeddingProviderInterface::class)
                );
            },

            AnswerService::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                $apiKey = $settings->get('ailibrarian_anthropic_api_key')
                    ?: (getenv('ANTHROPIC_API_KEY') ?: null);
                $model = $settings->get('ailibrarian_anthropic_model')
                    ?: (getenv('ANTHROPIC_MODEL') ?: 'claude-opus-4-8');

                // Tentative de connexion à Postgres/pgvector. Facultatif :
                // si le service n'est pas lancé ou si l'extension n'est pas
                // dispo, AnswerService retombera sur le fallback MySQL.
                // Variables d'env fournies par docker-compose.yml (service
                // `vectors`).
                $pgvector = null;
                $pgHost = getenv('VECTORS_HOST');
                if ($pgHost) {
                    try {
                        $pgvector = new \PDO(
                            sprintf(
                                'pgsql:host=%s;port=%s;dbname=%s',
                                $pgHost,
                                getenv('VECTORS_PORT') ?: '5432',
                                getenv('VECTORS_DATABASE') ?: 'vectors'
                            ),
                            getenv('VECTORS_USER') ?: 'vectors',
                            getenv('VECTORS_PASSWORD') ?: '',
                            [
                                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                                // Pas de ATTR_PERSISTENT : instable en Apache
                                // mod_php avec pdo_pgsql (connexions bloquées
                                // entre workers). Coût re-connect ~5-10 ms,
                                // négligeable vs latence Voyage + Claude.
                            ]
                        );
                    } catch (\PDOException $e) {
                        // On log dans les erreurs Apache mais on ne fait pas
                        // échouer la construction du service — fallback MySQL.
                        error_log('[AiLibrarian] pgvector indisponible, fallback MySQL : ' . $e->getMessage());
                        $pgvector = null;
                    }
                }

                return new AnswerService(
                    $container->get('Omeka\ApiManager'),
                    $container->get('Omeka\Connection'),
                    $container->get(EmbeddingProviderInterface::class),
                    $apiKey !== '' ? $apiKey : null,
                    $model,
                    $pgvector
                );
            },
        ],
    ],
];
