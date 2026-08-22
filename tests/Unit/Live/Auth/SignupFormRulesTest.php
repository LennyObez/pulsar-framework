<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Password\PasswordHasherInterface;
use Pulsar\Live\Auth\SignupForm;
use Pulsar\Live\LiveForm;

use function str_repeat;

/**
 * Signup accepts the passwords people choose, and refuses the ones it should.
 *
 * `LiveForm::validateMin()` and `validateMax()` used to run both of their branches:
 * a value that is a string AND numeric was judged by length and then again by its
 * numeric value. Under `max:4096` that refused every all-digit password of five
 * characters or more — 1234567890 is ten characters and one and a quarter billion.
 *
 * The test drives {@see LiveForm::validate()}, which is the code the signup surface
 * runs. An earlier version of it used ValidatorBuilder instead — a different rule
 * interpreter that LiveForm never calls, and which understands `min_length` where
 * this one understands only `min`. It would have passed against rules the form does
 * not use, which is the shape of assertion this whole exercise exists to catch.
 */
#[CoversClass(SignupForm::class)]
#[CoversClass(LiveForm::class)]
final class SignupFormRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptablePasswords(): iterable
    {
        yield 'a passphrase' => ['correct horse battery staple'];
        yield 'letters and digits' => ['hunter2hunter2'];
        yield 'symbols' => ['#Tr0ub4dor&3!'];
        yield 'exactly the floor' => ['abcdefgh'];
        yield 'unicode' => ['motorhead-2026'];

        // The case the adversary named. Ten characters, and numerically far above
        // the 4096 character cap that the numeric branch was comparing it against.
        yield 'all digits, above the cap when read as a number' => ['1234567890'];
        yield 'all digits, exactly at the floor' => ['12345678'];
    }

    #[Test]
    #[DataProvider('acceptablePasswords')]
    public function aPasswordAPersonWouldChooseIsAccepted(string $password): void
    {
        $form = $this->form($password);

        self::assertTrue($form->validate(), 'rejected: ' . json_encode($form->errors()));
    }

    #[Test]
    public function aPasswordBelowTheFloorIsRefused(): void
    {
        $form = $this->form('short');

        self::assertFalse($form->validate());
        self::assertNotSame([], $form->fieldErrors('password'));
    }

    #[Test]
    public function aPasswordAboveTheCapIsRefused(): void
    {
        $form = $this->form(str_repeat('a', PasswordHasherInterface::MAX_LENGTH + 1));

        self::assertFalse($form->validate());
        self::assertNotSame([], $form->fieldErrors('password'));
    }

    /**
     * The same defect on the name field: a numeric-looking name was measured twice.
     */
    #[Test]
    public function anAllDigitNameIsJudgedByItsLength(): void
    {
        $form = $this->form('correct horse battery staple');
        $form->name = '12345';

        self::assertTrue($form->validate(), 'rejected: ' . json_encode($form->errors()));

        $form = $this->form('correct horse battery staple');
        $form->name = '1';

        self::assertFalse($form->validate(), 'one character is below the two-character floor');
    }

    /**
     * A form filled from request data must not be able to argue the floor down.
     */
    #[Test]
    public function theConfiguredMinimumCannotGoBelowTheFrameworkFloor(): void
    {
        $form = $this->form('abc');
        $form->requireAtLeast(1);

        self::assertFalse($form->validate(), 'three characters must not become acceptable');
        self::assertNotSame([], $form->fieldErrors('password'));
    }

    private function form(string $password): SignupForm
    {
        $form = new SignupForm();
        $form->name = 'Ada Lovelace';
        $form->email = 'ada@example.com';
        $form->password = $password;
        $form->password_confirmation = $password;

        return $form;
    }
}
