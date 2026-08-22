<?php

namespace AiLibrarian;

use Laminas\EventManager\Event;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Laminas\ServiceManager\ServiceLocatorInterface;

require_once __DIR__ . '/vendor/autoload.php';

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Déclare les ACL du module. Sans ça, Omeka bloque l'accès aux
     * contrôleurs personnalisés avec PermissionDeniedException — d'où
     * l'HTTP 500 sur /ai-librarian/ask.
     *
     * On ouvre les deux contrôleurs (public + admin) à tous les rôles ;
     * Omeka applique déjà son propre filtrage d'auth sur les routes /admin,
     * donc pas besoin de restreindre plus finement ici.
     */
    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);

        /** @var \Omeka\Permissions\Acl $acl */
        $acl = $this->getServiceLocator()->get('Omeka\Acl');

        $acl->allow(
            null,
            [
                'AiLibrarian\Controller\SearchController',
                'AiLibrarian\Controller\Admin\IndexController',
            ]
        );
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS ai_librarian_chunk (
                id INT AUTO_INCREMENT PRIMARY KEY,
                item_id INT NOT NULL,
                chunk_index INT NOT NULL,
                content LONGTEXT NOT NULL,
                embedding LONGTEXT NOT NULL,
                embedding_provider VARCHAR(191) NOT NULL,
                created DATETIME NOT NULL,
                INDEX idx_ai_librarian_chunk_item (item_id),
                INDEX idx_ai_librarian_chunk_provider (embedding_provider),
                FULLTEXT INDEX ft_content (content)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    /**
     * Mise à niveau depuis une version antérieure du module. Ajoute l'index
     * FULLTEXT sur content, utilisé par AnswerService pour la recherche
     * hybride BM25 + vectoriel via Reciprocal Rank Fusion.
     */
    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        // Vérifier que l'index n'existe pas déjà avant de tenter la création
        // (SHOW INDEX est plus portable qu'un try/catch DDL).
        $exists = $connection->fetchOne("
            SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'ai_librarian_chunk'
              AND INDEX_NAME = 'ft_content'
        ");
        if (!$exists) {
            $connection->executeStatement(
                'ALTER TABLE ai_librarian_chunk ADD FULLTEXT INDEX ft_content (content)'
            );
        }
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->executeStatement('DROP TABLE IF EXISTS ai_librarian_chunk');
    }

    /**
     * Formulaire de configuration (Admin -> Modules -> AI Librarian ->
     * Configurer). Permet de renseigner les clés API sans passer par les
     * variables d'environnement du conteneur.
     */
    public function getConfigForm(PhpRenderer $renderer)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');

        $fields = [
            'ailibrarian_anthropic_api_key' => [
                'label' => 'Clé API Anthropic',
                'type' => 'password',
                'help' => 'Nécessaire pour activer les réponses sourcées par IA. Laisser vide pour utiliser la variable d\'environnement ANTHROPIC_API_KEY.',
            ],
            'ailibrarian_anthropic_model' => [
                'label' => 'Modèle Claude',
                'type' => 'text',
                'help' => 'Par défaut : claude-opus-4-8.',
            ],
            'ailibrarian_voyage_api_key' => [
                'label' => 'Clé API Voyage AI (embeddings)',
                'type' => 'password',
                'help' => 'Laisser vide pour utiliser la variable d\'environnement VOYAGE_API_KEY, ou pour rester sur le fournisseur local dégradé si aucune des deux n\'est renseignée.',
            ],
        ];

        $html = '<p>Configuration du module AI Librarian. Voir <code>docs/architecture-ia.md</code> pour le détail du fonctionnement.</p>';
        foreach ($fields as $name => $field) {
            $value = htmlspecialchars((string) $settings->get($name, ''));
            $html .= sprintf(
                '<div class="field"><label for="%1$s">%2$s</label>'
                . '<input type="%3$s" name="%1$s" id="%1$s" value="%4$s">'
                . '<p class="notes">%5$s</p></div>',
                $name,
                htmlspecialchars($field['label']),
                $field['type'],
                $value,
                htmlspecialchars($field['help'])
            );
        }

        return $html;
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $params = $controller->getRequest()->getPost();

        foreach ([
            'ailibrarian_anthropic_api_key',
            'ailibrarian_anthropic_model',
            'ailibrarian_voyage_api_key',
        ] as $name) {
            $value = $params->get($name);
            if ($value !== null && $value !== '') {
                $settings->set($name, $value);
            }
        }

        return true;
    }
}
