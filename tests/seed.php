<?php

/**
 * Lab-Seeder für codemonauts/craft-elasticsearch (Craft 4).
 *
 * Aufruf über den generischen Runner:  php craft seed/run elastic
 * (bzw.  just seed elastic)
 *
 * Legt durchsuchbare Testdaten an, um Volltext-Suche, Ranking und
 * Feld-Boosting des Plugins zu prüfen:
 *   - Felder: summary (PlainText, einzeilig), body (PlainText, mehrzeilig),
 *             topic (Categories), keywords (Tags)  -> mehrere Feldtypen im Mapping
 *   - Category-Group 'topics' + Tag-Group 'keywords'
 *   - Channel-Section 'blog' mit title + allen Feldern im Layout
 *   - ~25 Entries mit bewusst gestreutem Text:
 *       * pro Thema geteilte Begriffe  -> Recall/Ranking testbar
 *       * pro Entry ein EINDEUTIGES Wort (unique*)  -> Precision/Exact-Match testbar
 *
 * Idempotent: alles wird per Handle/Titel geprüft und nur bei Bedarf angelegt.
 * Da Transition-Mode aktiv ist, enqueued jedes saveElement automatisch einen
 * Indexierungs-Job; für einen vollständigen Aufbau danach:
 *   php craft elastic/index/reindex
 *   php craft elastic/elements/index --use-queue=0
 *   php craft elastic/index/stats
 */

use craft\console\Controller;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Categories as CategoriesField;
use craft\fields\PlainText;
use craft\fields\Tags as TagsField;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\TagGroup;
use yii\console\ExitCode;

return function (Controller $c): int {
    $fieldsService = Craft::$app->getFields();
    $sectionsService = Craft::$app->getSections();
    $categoriesService = Craft::$app->getCategories();
    $tagsService = Craft::$app->getTags();
    $elementsService = Craft::$app->getElements();
    $primarySite = Craft::$app->getSites()->getPrimarySite();

    // Eine Feld-Gruppe sicherstellen (frische Craft-Installs haben i.d.R. „Common").
    $groups = $fieldsService->getAllGroups();
    $groupId = $groups[0]->id ?? null;

    // ── 1) Category-Group 'topics' + Tag-Group 'keywords' ────────────────────
    $topicGroup = $categoriesService->getGroupByHandle('topics');
    if (!$topicGroup) {
        $topicGroup = new CategoryGroup([
            'name' => 'Topics',
            'handle' => 'topics',
            'siteSettings' => [
                $primarySite->id => new CategoryGroup_SiteSettings([
                    'siteId' => $primarySite->id,
                    'hasUrls' => false,
                ]),
            ],
        ]);
        if (!$categoriesService->saveGroup($topicGroup)) {
            $c->stderr('Failed to save category group: ' . print_r($topicGroup->getErrors(), true) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $c->stdout("Created category group 'topics'\n");
    } else {
        $c->stdout("Category group 'topics' already exists\n");
    }

    $keywordGroup = $tagsService->getTagGroupByHandle('keywords');
    if (!$keywordGroup) {
        $keywordGroup = new TagGroup(['name' => 'Keywords', 'handle' => 'keywords']);
        if (!$tagsService->saveTagGroup($keywordGroup)) {
            $c->stderr('Failed to save tag group: ' . print_r($keywordGroup->getErrors(), true) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $c->stdout("Created tag group 'keywords'\n");
    } else {
        $c->stdout("Tag group 'keywords' already exists\n");
    }

    // ── 2) Felder: summary, body, topic (Categories), keywords (Tags) ────────
    // Hinweis: Das Plugin indexiert Custom-Felder NUR, wenn $field->searchable === true
    // (Search::indexElementAttributes). Craft-Felder sind das per Default NICHT — daher
    // hier für jedes Feld explizit gesetzt, sonst landet der Feldwert nicht im Index.
    $getOrCreatePlainText = function (string $handle, string $name, bool $multiline) use ($fieldsService, $groupId, $c): ?PlainText {
        $field = $fieldsService->getFieldByHandle($handle);
        if ($field instanceof PlainText) {
            if (!$field->searchable) {
                $field->searchable = true;
                $fieldsService->saveField($field);
            }
            $c->stdout("Field '$handle' already exists\n");
            return $field;
        }
        $field = new PlainText();
        $field->groupId = $groupId;
        $field->name = $name;
        $field->handle = $handle;
        $field->searchable = true;
        $field->multiline = $multiline;
        if ($multiline) {
            $field->initialRows = 6;
        }
        if (!$fieldsService->saveField($field)) {
            $c->stderr("Failed to save field '$handle': " . print_r($field->getErrors(), true) . "\n");
            return null;
        }
        $c->stdout("Created field '$handle'\n");
        return $field;
    };

    $summaryField = $getOrCreatePlainText('summary', 'Summary', false);
    $bodyField = $getOrCreatePlainText('body', 'Body', true);
    if (!$summaryField || !$bodyField) {
        return ExitCode::UNSPECIFIED_ERROR;
    }

    $topicField = $fieldsService->getFieldByHandle('topic');
    if (!$topicField instanceof CategoriesField) {
        $topicField = new CategoriesField();
        $topicField->groupId = $groupId;
        $topicField->name = 'Topic';
        $topicField->handle = 'topic';
        $topicField->searchable = true;
        $topicField->source = 'group:' . $topicGroup->uid;
        if (!$fieldsService->saveField($topicField)) {
            $c->stderr('Failed to save field topic: ' . print_r($topicField->getErrors(), true) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $c->stdout("Created field 'topic'\n");
    } else {
        $c->stdout("Field 'topic' already exists\n");
    }

    $keywordsField = $fieldsService->getFieldByHandle('keywords');
    if (!$keywordsField instanceof TagsField) {
        $keywordsField = new TagsField();
        $keywordsField->groupId = $groupId;
        $keywordsField->name = 'Keywords';
        $keywordsField->handle = 'keywords';
        $keywordsField->searchable = true;
        $keywordsField->source = 'taggroup:' . $keywordGroup->uid;
        if (!$fieldsService->saveField($keywordsField)) {
            $c->stderr('Failed to save field keywords: ' . print_r($keywordsField->getErrors(), true) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $c->stdout("Created field 'keywords'\n");
    } else {
        $c->stdout("Field 'keywords' already exists\n");
    }

    // ── 3) Channel-Section 'blog' mit allen Feldern im Layout ────────────────
    $section = $sectionsService->getSectionByHandle('blog');
    if (!$section) {
        $section = new Section([
            'name' => 'Blog',
            'handle' => 'blog',
            'type' => Section::TYPE_CHANNEL,
            'siteSettings' => [
                new Section_SiteSettings([
                    'siteId' => $primarySite->id,
                    'enabledByDefault' => true,
                    'hasUrls' => false,
                ]),
            ],
        ]);
        if (!$sectionsService->saveSection($section)) {
            $c->stderr('Failed to save section: ' . print_r($section->getErrors(), true) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $c->stdout("Created section 'blog'\n");
    } else {
        $c->stdout("Section 'blog' already exists\n");
    }

    // Feld-Layout (idempotent neu setzen, damit alle Felder sicher enthalten sind).
    $entryType = $sectionsService->getEntryTypesBySectionId($section->id)[0];
    $layout = new FieldLayout(['type' => Entry::class]);
    $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
    $tab->setElements([
        new CustomField($summaryField),
        new CustomField($bodyField),
        new CustomField($topicField),
        new CustomField($keywordsField),
    ]);
    $layout->setTabs([$tab]);
    $entryType->setFieldLayout($layout);
    if (!$sectionsService->saveEntryType($entryType)) {
        $c->stderr('Failed to save entry type: ' . print_r($entryType->getErrors(), true) . "\n");
        return ExitCode::UNSPECIFIED_ERROR;
    }

    // ── 4) Kategorien + Tags anlegen (Relations für die Entries) ─────────────
    $topicTitles = ['Search', 'DevOps', 'Coffee', 'Travel', 'Photography'];
    $categoryIds = [];
    foreach ($topicTitles as $title) {
        $existing = Category::find()->group('topics')->title($title)->one();
        if (!$existing) {
            $existing = new Category();
            $existing->groupId = $topicGroup->id;
            $existing->title = $title;
            if (!$elementsService->saveElement($existing)) {
                $c->stderr("Failed to save category '$title': " . print_r($existing->getErrors(), true) . "\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }
        $categoryIds[$title] = $existing->id;
    }

    $tagIds = [];
    $ensureTag = function (string $title) use (&$tagIds, $keywordGroup, $elementsService, $c): ?int {
        if (isset($tagIds[$title])) {
            return $tagIds[$title];
        }
        $existing = Tag::find()->group('keywords')->title($title)->one();
        if (!$existing) {
            $existing = new Tag();
            $existing->groupId = $keywordGroup->id;
            $existing->title = $title;
            if (!$elementsService->saveElement($existing)) {
                $c->stderr("Failed to save tag '$title': " . print_r($existing->getErrors(), true) . "\n");
                return null;
            }
        }
        return $tagIds[$title] = $existing->id;
    };

    // ── 5) ~25 Entries mit gestreutem, durchsuchbarem Text ───────────────────
    // Jede Zeile: [title, topic, summary, body, tags[]].
    // 'unique…'-Wörter kommen nur je genau einmal vor (Precision-Tests).
    $entries = [
        // Search
        ['Getting Started with Full-Text Search', 'Search', 'A gentle intro to search relevance.', 'This guide introduces full-text search, relevance scoring and tokenization for newcomers. The chapter mascot is the uniqueObelisk.', ['elasticsearch', 'ranking']],
        ['Tuning Relevance and Ranking', 'Search', 'Make the best results rise to the top.', 'Relevance tuning shapes how search ranks documents by term frequency and boosting. Our test critter here is the uniqueQuokka.', ['ranking', 'analyzer']],
        ['OpenSearch versus Elasticsearch', 'Search', 'Two engines, one lineage.', 'Comparing OpenSearch and Elasticsearch for full-text search across features and licensing. Landmark of the day: the uniqueZeppelin.', ['opensearch', 'elasticsearch']],
        ['Analyzers, Tokenizers and Stemming', 'Search', 'How text becomes searchable tokens.', 'Analyzers split text into tokens while stemming folds words to their roots for better search recall. Flavour note: uniqueMarmalade.', ['analyzer', 'indexing']],
        ['Reindexing Strategies at Scale', 'Search', 'Rebuild the index without downtime.', 'Reindexing large datasets keeps the search index fresh as mappings evolve over time. Guided by the uniqueCartographer.', ['indexing', 'ranking']],
        // DevOps
        ['Deploying with Docker Compose', 'DevOps', 'Local stacks in one file.', 'Docker Compose wires services together so a whole stack starts with a single command. Mascot: the uniqueFlamingo.', ['docker', 'ci-cd']],
        ['Kubernetes for Small Teams', 'DevOps', 'Orchestration without the pain.', 'Kubernetes schedules containers across nodes and keeps workloads healthy for small teams. Rock of choice: uniqueBasalt.', ['kubernetes', 'docker']],
        ['Observability and Metrics', 'DevOps', 'See what your system is doing.', 'Observability combines metrics, logs and traces to reveal how a running system behaves. Beacon: the uniqueLighthouse.', ['observability']],
        ['Continuous Integration Pipelines', 'DevOps', 'Ship with confidence.', 'Continuous integration runs tests on every push so regressions surface early in the pipeline. Fruit of the build: uniqueTangerine.', ['ci-cd']],
        ['Scaling Stateful Services', 'DevOps', 'Databases that grow with you.', 'Scaling stateful services demands careful handling of storage, replication and failover. Navigating by the uniqueMeridian.', ['kubernetes', 'observability']],
        // Coffee
        ['The Art of Espresso', 'Coffee', 'Nine bars of pressure.', 'A great espresso balances pressure, grind and time to pull a rich, syrupy shot. Songbird in the cafe: the uniqueNightingale.', ['espresso', 'barista']],
        ['Pour-Over Techniques', 'Coffee', 'Slow coffee, clear cup.', 'Pour-over coffee rewards a steady kettle and an even bloom for a clean, bright cup. Driftwood find: uniqueDriftwood.', ['pour-over', 'barista']],
        ['Understanding Roast Levels', 'Coffee', 'From light to dark.', 'Roast levels shift a coffee from bright and acidic toward bold and smoky flavours. Spice hint: uniqueCardamom.', ['roast']],
        ['Choosing a Home Grinder', 'Coffee', 'Consistency is everything.', 'A burr grinder yields consistent particles that make espresso and pour-over far more repeatable. Shorebird: the uniqueSandpiper.', ['espresso', 'roast']],
        ['Latte Art for Beginners', 'Coffee', 'Milk, meet crema.', 'Latte art starts with silky steamed milk poured with a confident, steady hand. Fine china: uniquePorcelain.', ['barista', 'espresso']],
        // Travel
        ['Hiking the Alpine Trails', 'Travel', 'Above the tree line.', 'Alpine hiking trades effort for panoramic ridgelines and crisp mountain air. Wildflower spotted: the uniqueEdelweiss.', ['mountains', 'hiking']],
        ['A Coastal Road Trip', 'Travel', 'Windows down, salt air.', 'A coastal road trip strings together cliffs, coves and small harbours along the shore. Overhead: the uniqueSeagull.', ['roadtrip', 'coastline']],
        ['Backpacking Essentials', 'Travel', 'Carry less, go further.', 'Smart backpacking is about light gear, good water and dependable footwear on long trails. Vessel of choice: the uniqueCanteen.', ['hiking', 'mountains']],
        ['Hidden Coastal Villages', 'Travel', 'Off the beaten track.', 'Quiet coastal villages hide sheltered harbours and slow mornings away from the crowds. Mooring point: the uniqueHarbor.', ['coastline', 'roadtrip']],
        ['Mountain Photography Basics', 'Travel', 'Peaks in golden light.', 'Mountain photography rewards early starts when summits catch the first golden light. Peak reached: the uniqueSummit.', ['mountains', 'landscape']],
        // Photography
        ['Mastering Aperture', 'Photography', 'Depth of field, controlled.', 'Aperture governs depth of field, isolating a subject or keeping a whole scene sharp. Eye of the lens: the uniqueIris.', ['aperture', 'portrait']],
        ['Long Exposure at Night', 'Photography', 'Painting with time.', 'Long exposure smooths water and streaks light into trails across a night sky. Streaking overhead: the uniqueComet.', ['longexposure', 'landscape']],
        ['Portrait Lighting Setups', 'Photography', 'Flatter every face.', 'Portrait lighting shapes mood with key, fill and rim lights around the subject. Tool of the trade: the uniqueSoftbox.', ['portrait', 'aperture']],
        ['Composing Landscapes', 'Photography', 'Lead the eye.', 'Landscape composition uses lines and layers to lead the eye toward a strong horizon. Meeting of earth and sky: the uniqueHorizon.', ['landscape', 'longexposure']],
        ['Street Photography Ethics', 'Photography', 'Candid, but kind.', 'Street photography balances candid storytelling with respect for the people in frame. Underfoot: the uniqueCobblestone.', ['portrait']],
    ];

    $created = 0;
    $skipped = 0;
    foreach ($entries as [$title, $topic, $summary, $body, $tags]) {
        if (Entry::find()->section('blog')->title($title)->one()) {
            $skipped++;
            continue;
        }

        $tagRelIds = [];
        foreach ($tags as $tagName) {
            $id = $ensureTag($tagName);
            if ($id === null) {
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $tagRelIds[] = $id;
        }

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->title = $title;
        $entry->enabled = true;
        $entry->setFieldValues([
            'summary' => $summary,
            'body' => $body,
            'topic' => [$categoryIds[$topic]],
            'keywords' => $tagRelIds,
        ]);
        if (!$elementsService->saveElement($entry)) {
            $c->stderr("Failed to save entry '$title': " . print_r($entry->getErrors(), true) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $created++;
    }

    $c->stdout("Entries: {$created} created, {$skipped} already existed.\n");
    $c->stdout("Seed complete. Configure the plugin (endpoint + auth) against your\n");
    $c->stdout("OpenSearch/Elasticsearch, then build the index for the first time:\n");
    $c->stdout("  php craft elastic/elements/index '*' 0   # create index + index all elements (sync)\n");
    $c->stdout("  php craft elastic/index/stats            # verify document count\n");
    $c->stdout("Later, to rebuild an existing index: php craft elastic/index/reindex\n");

    return ExitCode::OK;
};
