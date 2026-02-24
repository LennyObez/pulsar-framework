<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

#[CoversClass(ControlCatalog::class)]
#[CoversClass(Control::class)]
#[CoversClass(ControlStatus::class)]
final class ControlCatalogTest extends TestCase
{
    private ControlCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ControlCatalog();
    }

    #[Test]
    public function registerAndGetReturnsControl(): void
    {
        $control = new Control(
            id: 'TEST-1',
            framework: 'soc2',
            title: 'Test Control',
            description: 'A test control for unit testing.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['feature_a'],
        );

        $this->catalog->register($control);

        $retrieved = $this->catalog->get('TEST-1');

        self::assertNotNull($retrieved);
        self::assertSame('TEST-1', $retrieved->id);
        self::assertSame('soc2', $retrieved->framework);
        self::assertSame('Test Control', $retrieved->title);
        self::assertSame(ControlStatus::Implemented, $retrieved->status);
        self::assertSame(['feature_a'], $retrieved->frameworkFeatures);
    }

    #[Test]
    public function getReturnsNullForUnknownId(): void
    {
        self::assertNull($this->catalog->get('nonexistent'));
    }

    #[Test]
    public function allReturnsAllRegisteredControls(): void
    {
        $control1 = new Control(
            id: 'C-1',
            framework: 'soc2',
            title: 'First',
            description: 'First control.',
            status: ControlStatus::Implemented,
        );
        $control2 = new Control(
            id: 'C-2',
            framework: 'hipaa',
            title: 'Second',
            description: 'Second control.',
            status: ControlStatus::Partial,
        );

        $this->catalog->register($control1);
        $this->catalog->register($control2);

        $all = $this->catalog->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('C-1', $all);
        self::assertArrayHasKey('C-2', $all);
        self::assertSame($control1, $all['C-1']);
        self::assertSame($control2, $all['C-2']);
    }

    #[Test]
    public function byFrameworkFiltersCorrectly(): void
    {
        $this->catalog->register(new Control(
            id: 'S-1',
            framework: 'soc2',
            title: 'SOC2 control',
            description: 'A SOC2 control.',
            status: ControlStatus::Implemented,
        ));
        $this->catalog->register(new Control(
            id: 'H-1',
            framework: 'hipaa',
            title: 'HIPAA control',
            description: 'A HIPAA control.',
            status: ControlStatus::Planned,
        ));
        $this->catalog->register(new Control(
            id: 'S-2',
            framework: 'soc2',
            title: 'Another SOC2',
            description: 'Another SOC2 control.',
            status: ControlStatus::Partial,
        ));

        $soc2Controls = $this->catalog->byFramework('soc2');

        self::assertCount(2, $soc2Controls);
        self::assertSame('S-1', $soc2Controls[0]->id);
        self::assertSame('S-2', $soc2Controls[1]->id);

        $hipaaControls = $this->catalog->byFramework('hipaa');

        self::assertCount(1, $hipaaControls);
        self::assertSame('H-1', $hipaaControls[0]->id);

        self::assertCount(0, $this->catalog->byFramework('gdpr'));
    }

    #[Test]
    public function byStatusFiltersCorrectly(): void
    {
        $this->catalog->register(new Control(
            id: 'A',
            framework: 'soc2',
            title: 'Implemented',
            description: 'Implemented control.',
            status: ControlStatus::Implemented,
        ));
        $this->catalog->register(new Control(
            id: 'B',
            framework: 'hipaa',
            title: 'Partial',
            description: 'Partial control.',
            status: ControlStatus::Partial,
        ));
        $this->catalog->register(new Control(
            id: 'C',
            framework: 'gdpr',
            title: 'Planned',
            description: 'Planned control.',
            status: ControlStatus::Planned,
        ));
        $this->catalog->register(new Control(
            id: 'D',
            framework: 'pci_dss',
            title: 'Not Applicable',
            description: 'Not applicable control.',
            status: ControlStatus::NotApplicable,
        ));

        $implemented = $this->catalog->byStatus(ControlStatus::Implemented);
        self::assertCount(1, $implemented);
        self::assertSame('A', $implemented[0]->id);

        $partial = $this->catalog->byStatus(ControlStatus::Partial);
        self::assertCount(1, $partial);
        self::assertSame('B', $partial[0]->id);

        $planned = $this->catalog->byStatus(ControlStatus::Planned);
        self::assertCount(1, $planned);
        self::assertSame('C', $planned[0]->id);

        $na = $this->catalog->byStatus(ControlStatus::NotApplicable);
        self::assertCount(1, $na);
        self::assertSame('D', $na[0]->id);
    }

    #[Test]
    public function countReturnsNumberOfRegisteredControls(): void
    {
        self::assertSame(0, $this->catalog->count());

        $this->catalog->register(new Control(
            id: 'X-1',
            framework: 'soc2',
            title: 'One',
            description: 'First.',
            status: ControlStatus::Implemented,
        ));
        self::assertSame(1, $this->catalog->count());

        $this->catalog->register(new Control(
            id: 'X-2',
            framework: 'hipaa',
            title: 'Two',
            description: 'Second.',
            status: ControlStatus::Planned,
        ));
        self::assertSame(2, $this->catalog->count());
    }

    #[Test]
    public function registerReplacesControlWithSameId(): void
    {
        $original = new Control(
            id: 'DUP-1',
            framework: 'soc2',
            title: 'Original',
            description: 'Original version.',
            status: ControlStatus::Planned,
        );
        $replacement = new Control(
            id: 'DUP-1',
            framework: 'soc2',
            title: 'Replacement',
            description: 'Updated version.',
            status: ControlStatus::Implemented,
        );

        $this->catalog->register($original);
        $this->catalog->register($replacement);

        self::assertSame(1, $this->catalog->count());
        $result = $this->catalog->get('DUP-1');
        self::assertNotNull($result);
        self::assertSame('Replacement', $result->title);
        self::assertSame(ControlStatus::Implemented, $result->status);
    }

    #[Test]
    public function controlDefaultsToEmptyFrameworkFeatures(): void
    {
        $control = new Control(
            id: 'BARE-1',
            framework: 'gdpr',
            title: 'Bare Control',
            description: 'Control with no features listed.',
            status: ControlStatus::Planned,
        );

        self::assertSame([], $control->frameworkFeatures);
    }
}
