<?php

/**
 * Lab-Testroutine für codemonauts/craft-elasticsearch (Craft 5).
 *
 * Aufruf über den generischen Runner:  php craft seed/test elastic
 * (bzw.  just test elastic)
 *
 * Voraussetzung:
 *   - `just seed elastic` wurde ausgeführt (Blog-Section mit 25 Entries),
 *   - das Plugin ist gegen eine erreichbare OpenSearch/Elasticsearch
 *     konfiguriert (Endpoint + Auth).
 *
 * Self-contained: stellt den Index sicher, indexiert die Blog-Entries frisch,
 * erzwingt einen Refresh und prüft dann zwei Blöcke:
 *
 *   Block A — Relevanz über den ES-Pfad des Plugins (Search::searchElements direkt):
 *     1. Precision — eindeutiger Body-Begriff 'uniqueQuokka'  -> genau 1 Treffer
 *     2. Recall    — 'espresso' (Titel + Body + Tag)          -> nur Coffee-Entries
 *     3. Relation  — 'kubernetes' (Tag über das keywords-Feld) -> die 2 DevOps-Entries
 *
 *   Block B — WELCHE Engine bedient die reale UI-Suche (Entry::find()->search(),
 *   via Craft::$app->getSearch())? Zwei Craft-5-Weichen greifen hier:
 *     (a) Das Plugin ersetzt Crafts search-Komponente laut Elastic::init() NUR bei
 *         transition=false (bei transition=true bleibt Crafts DB-Suche aktiv).
 *     (b) Craft 5 ruft den ES-Override searchElements() laut ElementQuery nur auf,
 *         wenn nach 'score' sortiert wird ODER shouldCallSearchElements()===true.
 *         Das Plugin überschreibt shouldCallSearchElements() (=> true; seit Craft 4.8.0
 *         der dafür vorgesehene Weg) und injiziert bei leerem orderBy eine Score-Order
 *         (Guarded-Workaround gegen Crafts ''['score']-TypeError). Dadurch laufen im
 *         Non-Transition-Modus AUCH score-lose Suchen über OpenSearch — statt über
 *         Crafts createDbQuery()/DB-Subquery — und das ohne Fatal.
 *   Bewiesen mit einer Divergenz-Probe: ein Begriff wird aus dem ES-Index entfernt
 *   (bleibt aber in Crafts DB-Index). Non-Transition wird prozesslokal exakt wie
 *   init() nachgestellt (setComponents).
 *     4. transition=true,  Score-Suche   -> Craft-DB   (findet Probe trotz ES-Löschung)
 *     5. transition=false, Score-Suche   -> OpenSearch (findet gelöschte Probe NICHT)
 *     6. transition=false, ohne orderBy  -> OpenSearch (Guarded-Workaround, kein TypeError)
 *     7. Mechanik: shouldCallSearchElements()===true & injiziert Score-Order bei leerem orderBy
 */

use codemonauts\elastic\Elastic;
use codemonauts\elastic\services\Search as ElasticSearch;
use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use yii\console\ExitCode;

return function (Controller $c): int {
    // ── Vorbedingungen ───────────────────────────────────────────────────────
    $settings = Elastic::$settings;
    if (!$settings || $settings->endpoint === '') {
        $c->stderr("Plugin nicht konfiguriert (kein Endpoint). Erst Endpoint + Auth setzen.\n");
        return ExitCode::CONFIG;
    }

    $site = Craft::$app->getSites()->getPrimarySite();
    $indexes = Elastic::$plugin->getIndexes();
    $search = Elastic::$plugin->getSearch();
    $client = Elastic::$plugin->getElasticsearch()->getClient();
    $indexName = $indexes->getIndexName($site);

    $entries = Entry::find()->section('blog')->siteId($site->id)->all();
    if (count($entries) === 0) {
        $c->stderr("Keine Blog-Entries gefunden. Erst 'just seed elastic' ausführen.\n");
        return ExitCode::UNSPECIFIED_ERROR;
    }

    $refresh = fn() => $client->indices()->refresh(['index' => $indexName]);

    // ── Index sicherstellen + Blog-Entries frisch indexieren ─────────────────
    $indexes->ensureIndexForSiteExists($site);
    foreach ($entries as $entry) {
        $search->indexElementAttributes($entry);
    }
    $refresh(); // ES ist near-real-time: sofort suchbar machen.

    // Ergebnisse einsammeln, am Ende gebündelt ausgeben.
    $results = [];
    $record = function (string $name, bool $ok, string $detail = '') use (&$results): void {
        $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    };

    // ══ Block A — Relevanz über den erzwungenen ES-Pfad ══════════════════════
    $titlesForEs = function (string $term) use ($search, $site): array {
        $query = Entry::find()->section('blog')->siteId($site->id);
        $query->search($term);
        $scores = $search->searchElements($query); // [elementId => score]
        $titles = [];
        foreach (array_keys($scores) as $id) {
            $el = Craft::$app->getElements()->getElementById((int)$id, Entry::class, $site->id);
            if ($el !== null) {
                $titles[] = $el->title;
            }
        }
        sort($titles);
        return $titles;
    };

    $coffeeTitles = [
        'The Art of Espresso',
        'Pour-Over Techniques',
        'Understanding Roast Levels',
        'Choosing a Home Grinder',
        'Latte Art for Beginners',
    ];

    $t = $titlesForEs('uniqueQuokka');
    $record(
        "Precision: 'uniqueQuokka' (nur im Body) -> genau 1 Treffer",
        $t === ['Tuning Relevance and Ranking'],
        '[' . implode(', ', $t) . ']'
    );

    $t = $titlesForEs('espresso');
    $record(
        "Recall: 'espresso' (Titel + Body + Tag) -> nur Coffee-Entries",
        count($t) >= 2
            && in_array('The Art of Espresso', $t, true)
            && in_array('Choosing a Home Grinder', $t, true)
            && count(array_diff($t, $coffeeTitles)) === 0,
        '[' . implode(', ', $t) . ']'
    );

    $t = $titlesForEs('kubernetes');
    $record(
        "Relation: 'kubernetes' (Tag via keywords) -> 2 DevOps-Entries",
        $t === ['Kubernetes for Small Teams', 'Scaling Stateful Services'],
        '[' . implode(', ', $t) . ']'
    );

    // ══ Block B — welche Engine bedient die reale UI-Suche? ══════════════════
    // Divergenz-Probe: 'uniqueQuokka' -> Entry "Tuning Relevance and Ranking".
    $probeTerm = 'uniqueQuokka';
    $probe = Entry::find()->section('blog')->siteId($site->id)
        ->title('Tuning Relevance and Ranking')->one();

    // Reale UI-Suche über Craft::$app->getSearch(). $byScore steuert die Craft-5-Weiche:
    // mit ->orderBy('score') ruft ElementQuery searchElements() (ES-Override im Non-
    // Transition-Modus); ohne Score läuft es über createDbQuery() -> immer Crafts DB.
    $uiFindsProbe = function (bool $byScore) use ($site, $probeTerm, $probe): bool {
        $query = Entry::find()->section('blog')->siteId($site->id)->search($probeTerm);
        if ($byScore) {
            $query->orderBy('score');
        }
        $ids = $query->ids();
        return in_array((int)$probe->id, array_map('intval', $ids), true);
    };

    // Probe aus ES entfernen (bleibt in Crafts DB-Index). Danach: DB ja, ES nein.
    Elastic::$plugin->getElements()->delete((int)$probe->id, $site);
    $refresh();

    // (4) transition=true, Score-Suche: Default-Komponente -> Crafts DB bedient die UI.
    $dbFinds = $uiFindsProbe(true);
    $record(
        "Engine @ transition=true (Score-Suche): Craft-DB bedient UI (findet Probe trotz ES-Löschung)",
        !(Craft::$app->getSearch() instanceof ElasticSearch) && $dbFinds,
        "search=" . get_class(Craft::$app->getSearch()) . ", findetProbe=" . ($dbFinds ? 'ja' : 'nein')
    );

    // Komponente exakt wie init() bei transition=false auf die Plugin-Suche swappen.
    Craft::$app->setComponents(['search' => ElasticSearch::class]);

    // (5) transition=false, Score-Suche: -> OpenSearch bedient die UI. Probe wurde aus
    //     ES gelöscht, darf also NICHT mehr auftauchen (Beweis, dass ES bedient).
    $esScoreFinds = $uiFindsProbe(true);
    $record(
        "Engine @ transition=false (Score-Suche): OpenSearch bedient UI (findet gelöschte Probe NICHT)",
        (Craft::$app->getSearch() instanceof ElasticSearch) && !$esScoreFinds,
        "search=" . get_class(Craft::$app->getSearch()) . ", findetProbe=" . ($esScoreFinds ? 'ja' : 'nein')
    );

    // (6) transition=false, OHNE explizites orderBy: dank Guarded-Workaround injiziert das
    //     Plugin eine Score-Order -> Suche läuft über OpenSearch, OHNE den ''['score']-
    //     TypeError -> gelöschte Probe wird NICHT gefunden. (Vor dem Workaround: Fatal;
    //     ohne den Fix überhaupt: createDbQuery -> Craft-DB -> Probe fälschlich gefunden.)
    $esBareFinds = $uiFindsProbe(false);
    $record(
        "Engine @ transition=false (ohne orderBy): Guarded-Workaround -> OpenSearch (findet gelöschte Probe NICHT, kein Fatal)",
        (Craft::$app->getSearch() instanceof ElasticSearch) && !$esBareFinds,
        "search=" . get_class(Craft::$app->getSearch()) . ", findetProbe=" . ($esBareFinds ? 'ja' : 'nein')
    );

    // (7) Mechanik direkt: shouldCallSearchElements() erzwingt den ES-Pfad UND injiziert bei
    //     leerem orderBy die Score-Order (genau das verhindert Crafts ''['score']-TypeError).
    $probeQuery = Entry::find()->section('blog')->siteId($site->id)->search($probeTerm);
    $orderByBefore = $probeQuery->orderBy;
    $forcesEs = Craft::$app->getSearch()->shouldCallSearchElements($probeQuery);
    $orderByAfter = $probeQuery->orderBy;
    $record(
        "Mechanik: shouldCallSearchElements()===true & injiziert Score-Order bei leerem orderBy",
        (Craft::$app->getSearch() instanceof ElasticSearch)
            && $forcesEs === true
            && is_array($orderByAfter) && array_key_exists('score', $orderByAfter),
        "returns=" . ($forcesEs ? 'true' : 'false')
            . ", orderBy: " . var_export($orderByBefore, true) . " -> " . var_export($orderByAfter, true)
    );

    // ── Restore: Probe wieder in ES indexieren (Index bleibt vollständig) ─────
    $search->indexElementAttributes($probe);
    $refresh();

    // ── Ausgabe ──────────────────────────────────────────────────────────────
    $failed = 0;
    foreach ($results as $r) {
        if ($r['ok']) {
            $c->stdout('  PASS  ', Console::FG_GREEN);
        } else {
            $c->stdout('  FAIL  ', Console::FG_RED);
            $failed++;
        }
        $c->stdout($r['name'] . "\n");
        if ($r['detail'] !== '') {
            $c->stdout('        -> ' . $r['detail'] . "\n");
        }
    }

    $total = count($results);
    if ($failed === 0) {
        $c->stdout("\nAll {$total} checks passed (relevance + engine selection in both transition modes).\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
    $c->stderr("\n" . ($total - $failed) . "/{$total} passed, {$failed} failed.\n", Console::FG_RED);
    return ExitCode::UNSPECIFIED_ERROR;
};
