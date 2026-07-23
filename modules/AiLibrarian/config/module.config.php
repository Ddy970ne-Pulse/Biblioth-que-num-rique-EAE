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

                return new AnswerService(
                    $container->get('Omeka\ApiManager'),
                    $container->get('Omeka\Connection'),
                    $container->get(EmbeddingProviderInterface::class),
                    $apiKey !== '' ? $apiKey : null,
                    $model
                );
            },
        ],
    ],
];
