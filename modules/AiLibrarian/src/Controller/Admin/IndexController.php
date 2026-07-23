<?php

namespace AiLibrarian\Controller\Admin;

use AiLibrarian\Service\CorpusIndexer;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Administration du module : déclenchement manuel de la réindexation
 * sémantique du corpus (Admin -> AI Librarian).
 */
class IndexController extends AbstractActionController
{
    private CorpusIndexer $indexer;

    public function __construct(CorpusIndexer $indexer)
    {
        $this->indexer = $indexer;
    }

    public function indexAction()
    {
        return new ViewModel();
    }

    /**
     * Réindexation synchrone. Pour un grand corpus, ceci peut dépasser le
     * délai maximal d'exécution PHP par défaut — envisager de déplacer
     * cette opération vers le système de tâches en arrière-plan d'Omeka S
     * (Omeka\Job) plutôt qu'un traitement synchrone dans le contrôleur.
     */
    public function reindexAction()
    {
        $processed = $this->indexer->reindexAll();

        $this->messenger()->addSuccess(sprintf(
            '%d item(s) réindexé(s) pour la recherche sémantique.',
            $processed
        ));

        return $this->redirect()->toRoute('admin/ai-librarian');
    }
}
