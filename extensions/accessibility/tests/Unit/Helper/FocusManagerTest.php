<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Helper;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Helper\FocusManager;

final class FocusManagerTest extends TestCase
{
    private FocusManager $focusManager;

    protected function setUp(): void
    {
        $this->focusManager = new FocusManager();
    }

    #[Test]
    public function trap_attributes_returns_data_focus_trap(): void
    {
        $attrs = $this->focusManager->trapAttributes('modal-1');

        self::assertStringContainsString('data-focus-trap="modal-1"', $attrs);
        self::assertStringContainsString('tabindex="-1"', $attrs);
    }

    #[Test]
    public function trap_attributes_escapes_html(): void
    {
        $attrs = $this->focusManager->trapAttributes('group"xss');

        self::assertStringContainsString('data-focus-trap="group&quot;xss"', $attrs);
        self::assertStringNotContainsString('group"xss', $attrs);
    }

    #[Test]
    public function restore_focus_attributes_returns_correct_data_attr(): void
    {
        $attrs = $this->focusManager->restoreFocusAttributes();

        self::assertSame('data-focus-restore="true"', $attrs);
    }

    #[Test]
    public function skip_to_attributes_returns_data_skip_to(): void
    {
        $attrs = $this->focusManager->skipToAttributes('main-content');

        self::assertStringContainsString('data-skip-to="main-content"', $attrs);
    }

    #[Test]
    public function autofocus_attributes_returns_correct_data_attr(): void
    {
        $attrs = $this->focusManager->autofocusAttributes();

        self::assertSame('data-focus-auto="true"', $attrs);
    }

    #[Test]
    public function roving_tab_attributes_returns_data_and_tabindex(): void
    {
        $attrs = $this->focusManager->rovingtabAttributes('toolbar-1');

        self::assertStringContainsString('data-roving-tab="toolbar-1"', $attrs);
        self::assertStringContainsString('tabindex="-1"', $attrs);
    }
}
