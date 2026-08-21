<?php

/**
 * Script CLI pour lancer la réindexation sémantique de la bibliothèque
 * numérique via le module AiLibrarian, en bypassant Apache et ses timeouts.
 *
 * Usage (dans le container omeka) :
 *   php /tmp/reindex.php
 *
 * Placé dans scripts/ côté hôte, copié dans /tmp/reindex.php côté container.
 *
 * Note technique : Omeka S ne charge pas dynamiquement les modules
 * utilisateur (comme AiLibrarian) dans un Application::init() CLI ; le
 * ModuleManager ne les active que pendant $app->run() via un événement Mvc.
 * On contourne en instanciant manuellement CorpusIndexer avec ses
 * dépendances (ApiManager et Connection du core Omeka, plus le provider
 * d'embeddings du module).
 */

chdir('/var/www/html');
require 'bootstrap.php';

// Autoload du module AiLibrarian (Composer local) — le core Omeka ne le
// charge pas puisqu'il ne connait pas encore le module en CLI.
require '/var/www/html/modules/AiLibrarian/vendor/autoload.php';

$app = \Laminas\Mvc\Application::init(require 'application/config/application.config.php');
$container = $app->getServiceManager();

// Dépendances Omeka core (déjà enregistrées par Application::init).
$api = $container->get('Omeka\ApiManager');
$connection = $container->get('Omeka\Connection');
$settings = $container->get('Omeka\Settings');

// Provider d'embeddings : Voyage AI si clé configurée (module settings ou
// variable d'environnement), sinon fallback LocalHash. Réplique la logique
// de la factory du module.
$voyageKey = $settings->get('ailibrarian_voyage_api_key')
    ?: (getenv('VOYAGE_API_KEY') ?: null);

if (!empty($voyageKey)) {
    $provider = new \AiLibrarian\Service\Embedding\VoyageEmbeddingProvider($voyageKey);
    echo "Provider : voyage-3 (cle configuree)" . PHP_EOL;
} else {
    $provider = new \AiLibrarian\Service\Embedding\LocalHashEmbeddingProvider();
    echo "Provider : local-hash (aucune cle Voyage detectee — qualite degradee)" . PHP_EOL;
}

$indexer = new \AiLibrarian\Service\CorpusIndexer($api, $connection, $provider);

echo "Demarrage indexation..." . PHP_EOL;

$start = time();
$processed = $indexer->reindexAll(function ($done, $total) use ($start) {
    if ($done % 25 === 0 || $done === $total) {
        $elapsed = time() - $start;
        $rate = $done / max(1, $elapsed);
        $eta = round(($total - $done) / max(0.1, $rate));
        printf(
            "[%s] %d/%d items (%.1f items/s) ETA %ds\n",
            date('H:i:s'),
            $done,
            $total,
            $rate,
            $eta
        );
    }
});

$duration = time() - $start;
printf(
    "Termine : %d items en %ds (%.1f items/s)\n",
    $processed,
    $duration,
    $processed / max(1, $duration)
);
