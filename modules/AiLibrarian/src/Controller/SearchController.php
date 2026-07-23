<?php

namespace AiLibrarian\Controller;

use AiLibrarian\Service\AnswerService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Recherche publique : recherche sémantique (facettes/mots-clés) et
 * assistant de questions/réponses sourcé sur le corpus.
 */
class SearchController extends AbstractActionController
{
    private AnswerService $answerService;

    public function __construct(AnswerService $answerService)
    {
        $this->answerService = $answerService;
    }

    public function indexAction()
    {
        $query = (string) $this->params()->fromQuery('q', '');

        $results = [];
        if ($query !== '') {
            $results = $this->answerService->search($query);
        }

        return new ViewModel([
            'query' => $query,
            'results' => $results,
        ]);
    }

    /**
     * Question en langage naturel -> réponse sourcée par Claude, cloisonnée
     * au corpus indexé (voir docs/architecture-ia.md).
     */
    public function askAction()
    {
        $query = (string) $this->params()->fromQuery('q', '');

        $answer = null;
        if ($query !== '') {
            $answer = $this->answerService->ask($query);
        }

        return new ViewModel([
            'query' => $query,
            'answer' => $answer,
        ]);
    }
}
