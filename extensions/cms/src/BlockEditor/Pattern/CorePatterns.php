<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\Pattern;

use Pulsar\Api\Api;

/**
 * Built-in block patterns for common page layouts.
 *
 * @psalm-api Registered via CorePatterns::register() from the cms
 *            BlockEditor service provider; not new'd by name.
 */
#[Api(since: '1.0.0')]
final class CorePatterns
{
    /**
     * Register all core patterns with the given registry.
     */
    public static function register(PatternRegistry $registry): void
    {
        $registry->register(new BlockPattern(
            name: 'hero-cta',
            title: 'Hero with Call to Action',
            description: 'A hero section with heading, description, and call-to-action buttons',
            category: 'landing',
            blocks: [
                [
                    'type' => 'hero',
                    'data' => [
                        'heading' => 'Welcome to Our Platform',
                        'subheading' => 'Build amazing things with the tools you love',
                        'backgroundUrl' => '',
                        'overlay' => true,
                    ],
                ],
                [
                    'type' => 'button-group',
                    'data' => [
                        'buttons' => [
                            ['text' => 'Get Started', 'url' => '#', 'variant' => 'primary'],
                            ['text' => 'Learn More', 'url' => '#', 'variant' => 'outline'],
                        ],
                    ],
                ],
            ],
            keywords: ['hero', 'landing', 'call to action', 'cta', 'banner'],
        ));

        $registry->register(new BlockPattern(
            name: 'hero-cta-testimonials',
            title: 'Hero + CTA + Testimonials',
            description: 'Complete landing page section with hero, call to action, and social proof',
            category: 'landing',
            blocks: [
                [
                    'type' => 'hero',
                    'data' => [
                        'heading' => 'Transform Your Workflow',
                        'subheading' => 'Join thousands of teams already using our platform',
                    ],
                ],
                [
                    'type' => 'button-group',
                    'data' => [
                        'buttons' => [
                            ['text' => 'Start Free Trial', 'url' => '#', 'variant' => 'primary'],
                        ],
                    ],
                ],
                [
                    'type' => 'testimonial',
                    'data' => [
                        'quote' => 'This platform changed everything for our team.',
                        'author' => 'Jane Smith',
                        'role' => 'CTO, TechCorp',
                    ],
                ],
            ],
            keywords: ['hero', 'testimonial', 'social proof', 'landing'],
        ));

        $registry->register(new BlockPattern(
            name: 'feature-grid',
            title: 'Feature Grid',
            description: 'A 3-column grid showcasing product features with icons',
            category: 'content',
            blocks: [
                [
                    'type' => 'heading',
                    'data' => ['text' => 'Features', 'level' => 2],
                ],
                [
                    'type' => 'paragraph',
                    'data' => ['text' => 'Everything you need to build great products', 'alignment' => 'center'],
                ],
                [
                    'type' => 'columns',
                    'data' => [
                        'columns' => [
                            ['blocks' => [['type' => 'icon', 'data' => ['name' => 'zap']], ['type' => 'heading', 'data' => ['text' => 'Fast', 'level' => 3]], ['type' => 'paragraph', 'data' => ['text' => 'Lightning-fast performance']]]],
                            ['blocks' => [['type' => 'icon', 'data' => ['name' => 'shield']], ['type' => 'heading', 'data' => ['text' => 'Secure', 'level' => 3]], ['type' => 'paragraph', 'data' => ['text' => 'Enterprise-grade security']]]],
                            ['blocks' => [['type' => 'icon', 'data' => ['name' => 'code']], ['type' => 'heading', 'data' => ['text' => 'Extensible', 'level' => 3]], ['type' => 'paragraph', 'data' => ['text' => 'Build anything you need']]]],
                        ],
                    ],
                ],
            ],
            keywords: ['features', 'grid', 'columns', 'showcase'],
        ));

        $registry->register(new BlockPattern(
            name: 'pricing-page',
            title: 'Pricing Page',
            description: 'Pricing table with heading and comparison',
            category: 'commerce',
            blocks: [
                [
                    'type' => 'heading',
                    'data' => ['text' => 'Simple, Transparent Pricing', 'level' => 2],
                ],
                [
                    'type' => 'paragraph',
                    'data' => ['text' => 'Choose the plan that fits your needs', 'alignment' => 'center'],
                ],
                [
                    'type' => 'pricing-table',
                    'data' => [
                        'plans' => [
                            ['name' => 'Starter', 'price' => '$9', 'period' => 'month', 'features' => ['5 Projects', '10 GB Storage', 'Email Support']],
                            ['name' => 'Pro', 'price' => '$29', 'period' => 'month', 'features' => ['Unlimited Projects', '100 GB Storage', 'Priority Support'], 'highlighted' => true],
                            ['name' => 'Enterprise', 'price' => '$99', 'period' => 'month', 'features' => ['Custom Solutions', 'Unlimited Storage', 'Dedicated Support']],
                        ],
                    ],
                ],
            ],
            keywords: ['pricing', 'plans', 'subscription', 'commerce'],
        ));

        $registry->register(new BlockPattern(
            name: 'contact-section',
            title: 'Contact Section',
            description: 'Contact form with heading and description',
            category: 'content',
            blocks: [
                [
                    'type' => 'heading',
                    'data' => ['text' => 'Get in Touch', 'level' => 2],
                ],
                [
                    'type' => 'paragraph',
                    'data' => ['text' => 'Have a question? We would love to hear from you.'],
                ],
                [
                    'type' => 'contact-form',
                    'data' => [
                        'fields' => [
                            ['type' => 'text', 'label' => 'Name', 'required' => true],
                            ['type' => 'email', 'label' => 'Email', 'required' => true],
                            ['type' => 'textarea', 'label' => 'Message', 'required' => true],
                        ],
                        'submitText' => 'Send Message',
                    ],
                ],
            ],
            keywords: ['contact', 'form', 'email', 'support'],
        ));
    }
}
