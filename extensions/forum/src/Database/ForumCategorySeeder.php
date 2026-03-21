<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Database;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Seeder\SeederInterface;
use Pulsar\Extension\Forum\Category\Category;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Category\CategoryTranslation;
use Pulsar\Extension\Forum\Category\CategoryTranslationRepositoryInterface;
use Pulsar\Extension\Forum\Support\UuidGenerator;

/**
 * Seeds default forum categories with translations in EN, FR, NL, DE.
 *
 * Creates 13 top-level categories covering all standard forum sections:
 * announcements, general discussion, getting started, and topic-specific areas.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ForumCategorySeeder implements SeederInterface
{
    public function __construct(
        private CategoryRepositoryInterface $categories,
        private CategoryTranslationRepositoryInterface $translations,
    ) {}

    public function identifier(): string
    {
        return 'forum:categories';
    }

    public function run(ConnectionInterface $connection): void
    {
        foreach (self::definitions() as $sortOrder => $definition) {
            $categoryId = UuidGenerator::v7();

            $category = Category::create(
                id: $categoryId,
                slug: $definition['slug'],
                sortOrder: $sortOrder,
            );

            $this->categories->save($category);

            foreach ($definition['translations'] as $locale => $texts) {
                $translation = CategoryTranslation::create(
                    id: UuidGenerator::v7(),
                    categoryId: $categoryId,
                    locale: $locale,
                    name: $texts['name'],
                    description: $texts['description'],
                );

                $this->translations->save($translation);
            }
        }
    }

    /**
     * @return list<array{
     *     slug: string,
     *     translations: array<string, array{name: string, description: string}>
     * }>
     */
    public static function definitions(): array
    {
        return [
            [
                'slug' => 'announcements',
                'translations' => [
                    'en' => ['name' => 'Announcements', 'description' => 'Official announcements and news from the team.'],
                    'fr' => ['name' => 'Annonces', 'description' => 'Annonces officielles et nouvelles de l\'equipe.'],
                    'nl' => ['name' => 'Aankondigingen', 'description' => 'Officiele aankondigingen en nieuws van het team.'],
                    'de' => ['name' => 'Ankuendigungen', 'description' => 'Offizielle Ankuendigungen und Neuigkeiten vom Team.'],
                ],
            ],
            [
                'slug' => 'general',
                'translations' => [
                    'en' => ['name' => 'General Discussion', 'description' => 'Open discussion about anything related to the platform.'],
                    'fr' => ['name' => 'Discussion generale', 'description' => 'Discussion ouverte sur tout ce qui concerne la plateforme.'],
                    'nl' => ['name' => 'Algemene discussie', 'description' => 'Open discussie over alles wat met het platform te maken heeft.'],
                    'de' => ['name' => 'Allgemeine Diskussion', 'description' => 'Offene Diskussion ueber alles, was die Plattform betrifft.'],
                ],
            ],
            [
                'slug' => 'getting-started',
                'translations' => [
                    'en' => ['name' => 'Getting Started', 'description' => 'Guides and help for new users setting up for the first time.'],
                    'fr' => ['name' => 'Premiers pas', 'description' => 'Guides et aide pour les nouveaux utilisateurs.'],
                    'nl' => ['name' => 'Aan de slag', 'description' => 'Handleidingen en hulp voor nieuwe gebruikers.'],
                    'de' => ['name' => 'Erste Schritte', 'description' => 'Anleitungen und Hilfe fuer neue Benutzer.'],
                ],
            ],
            [
                'slug' => 'core',
                'translations' => [
                    'en' => ['name' => 'Core Framework', 'description' => 'Discussion about the core framework, architecture, and internals.'],
                    'fr' => ['name' => 'Framework de base', 'description' => 'Discussion sur le framework de base, l\'architecture et les aspects internes.'],
                    'nl' => ['name' => 'Kernframework', 'description' => 'Discussie over het kernframework, architectuur en interne werking.'],
                    'de' => ['name' => 'Kern-Framework', 'description' => 'Diskussion ueber das Kern-Framework, Architektur und interne Strukturen.'],
                ],
            ],
            [
                'slug' => 'extensions',
                'translations' => [
                    'en' => ['name' => 'Extensions', 'description' => 'Discussion about building and using extensions.'],
                    'fr' => ['name' => 'Extensions', 'description' => 'Discussion sur le developpement et l\'utilisation des extensions.'],
                    'nl' => ['name' => 'Extensies', 'description' => 'Discussie over het bouwen en gebruiken van extensies.'],
                    'de' => ['name' => 'Erweiterungen', 'description' => 'Diskussion ueber das Erstellen und Verwenden von Erweiterungen.'],
                ],
            ],
            [
                'slug' => 'security',
                'translations' => [
                    'en' => ['name' => 'Security', 'description' => 'Security best practices, vulnerability reports, and hardening guides.'],
                    'fr' => ['name' => 'Securite', 'description' => 'Bonnes pratiques de securite, rapports de vulnerabilites et guides de renforcement.'],
                    'nl' => ['name' => 'Beveiliging', 'description' => 'Beveiligingspraktijken, kwetsbaarheidsrapporten en beveiligingsgidsen.'],
                    'de' => ['name' => 'Sicherheit', 'description' => 'Bewehrte Sicherheitspraktiken, Schwachstellenberichte und Haertungsleitfaeden.'],
                ],
            ],
            [
                'slug' => 'performance',
                'translations' => [
                    'en' => ['name' => 'Performance', 'description' => 'Optimization tips, benchmarks, and performance tuning strategies.'],
                    'fr' => ['name' => 'Performance', 'description' => 'Conseils d\'optimisation, benchmarks et strategies de reglage des performances.'],
                    'nl' => ['name' => 'Prestaties', 'description' => 'Optimalisatietips, benchmarks en prestatiestrategieen.'],
                    'de' => ['name' => 'Leistung', 'description' => 'Optimierungstipps, Benchmarks und Strategien zur Leistungsoptimierung.'],
                ],
            ],
            [
                'slug' => 'compliance',
                'translations' => [
                    'en' => ['name' => 'Compliance', 'description' => 'Regulatory compliance, audit trails, and certification discussions.'],
                    'fr' => ['name' => 'Conformite', 'description' => 'Conformite reglementaire, pistes d\'audit et discussions sur la certification.'],
                    'nl' => ['name' => 'Naleving', 'description' => 'Regelgeving, audittrails en certificeringsdiscussies.'],
                    'de' => ['name' => 'Compliance', 'description' => 'Regulatorische Compliance, Audit-Trails und Zertifizierungsdiskussionen.'],
                ],
            ],
            [
                'slug' => 'showcase',
                'translations' => [
                    'en' => ['name' => 'Showcase', 'description' => 'Show off projects and sites built with the framework.'],
                    'fr' => ['name' => 'Vitrine', 'description' => 'Presentez vos projets et sites construits avec le framework.'],
                    'nl' => ['name' => 'Showroom', 'description' => 'Toon projecten en sites gebouwd met het framework.'],
                    'de' => ['name' => 'Showcase', 'description' => 'Zeigen Sie Projekte und Websites, die mit dem Framework erstellt wurden.'],
                ],
            ],
            [
                'slug' => 'feature-requests',
                'translations' => [
                    'en' => ['name' => 'Feature Requests', 'description' => 'Suggest new features and vote on community proposals.'],
                    'fr' => ['name' => 'Demandes de fonctionnalites', 'description' => 'Suggerez de nouvelles fonctionnalites et votez sur les propositions.'],
                    'nl' => ['name' => 'Functieverzoeken', 'description' => 'Stel nieuwe functies voor en stem op voorstellen van de gemeenschap.'],
                    'de' => ['name' => 'Funktionswuensche', 'description' => 'Schlagen Sie neue Funktionen vor und stimmen Sie ueber Vorschlaege ab.'],
                ],
            ],
            [
                'slug' => 'bug-reports',
                'translations' => [
                    'en' => ['name' => 'Bug Reports', 'description' => 'Report bugs and track issue resolutions.'],
                    'fr' => ['name' => 'Rapports de bugs', 'description' => 'Signalez des bugs et suivez les resolutions.'],
                    'nl' => ['name' => 'Bugrapporten', 'description' => 'Meld bugs en volg de oplossingen.'],
                    'de' => ['name' => 'Fehlerberichte', 'description' => 'Melden Sie Fehler und verfolgen Sie Loesungen.'],
                ],
            ],
            [
                'slug' => 'contributing',
                'translations' => [
                    'en' => ['name' => 'Contributing', 'description' => 'Contributor guidelines, pull request discussions, and development workflow.'],
                    'fr' => ['name' => 'Contribuer', 'description' => 'Lignes directrices pour les contributeurs et discussions sur les pull requests.'],
                    'nl' => ['name' => 'Bijdragen', 'description' => 'Richtlijnen voor bijdragers, pull request discussies en ontwikkelworkflow.'],
                    'de' => ['name' => 'Mitwirken', 'description' => 'Richtlinien fuer Mitwirkende, Pull-Request-Diskussionen und Entwicklungsworkflow.'],
                ],
            ],
            [
                'slug' => 'off-topic',
                'translations' => [
                    'en' => ['name' => 'Off-Topic', 'description' => 'Anything that does not fit into the other categories.'],
                    'fr' => ['name' => 'Hors-sujet', 'description' => 'Tout ce qui ne rentre pas dans les autres categories.'],
                    'nl' => ['name' => 'Off-topic', 'description' => 'Alles wat niet in de andere categorieen past.'],
                    'de' => ['name' => 'Off-Topic', 'description' => 'Alles, was nicht in die anderen Kategorien passt.'],
                ],
            ],
        ];
    }
}
