<?php

declare(strict_types=1);

/**
 * Two questions a consumer of this framework will eventually ask, answered as a gate.
 *
 * QUESTION 1 — can a consumer substitute or decorate what the framework depends on?
 *
 * A dependency declared as a `final` concrete class cannot be substituted at all:
 * the consumer can neither implement it nor extend it, so a decorator, a spy, a
 * rate-limiting wrapper or a tenant-scoped variant are all impossible. A dependency
 * declared as a non-final concrete class can only be decorated by inheritance, which
 * couples the decorator to the internals of what it wraps and breaks the moment the
 * base class grows a method. Only an interface leaves the seam open.
 *
 * Value objects, DTOs, enums and exceptions are the legitimate exception: they carry
 * data, not behaviour, so there is nothing to substitute. The rule used to recognise
 * them is stated in {@see DataTypeRule} and is structural, never name-based.
 *
 * QUESTION 2 — which properties are mutable, and what is the strongest form each
 * one could take?
 *
 * Every property gets exactly one verdict, and the verdicts are ordered: `readonly`
 * is strictly stronger than `private(set)`, so a property that is only ever written
 * during construction is reported as should-be-readonly even when it already carries
 * `private(set)`. Recommending `private(set)` there would be a downgrade, and
 * catching that downgrade is a large part of why this gate exists.
 *
 * WHY A PARSER AND NOT A PATTERN
 *
 * Neither question survives a regex. Inside a `readonly class` a property written
 * `public string $name;` is already immutable and no textual pattern separates it
 * from a mutable one; `public private(set) array $routes = [];` is already correct
 * for one verdict and a downgrade for another depending on where it is written, and
 * that is a fact about statements elsewhere in the file. Both answers require the
 * syntax tree, so this walks it with nikic/PHP-Parser.
 *
 * WHAT THE LEGALITY RULES ARE, AND WHERE THEY COME FROM
 *
 * Every rule below was measured against the PHP binary that runs this repository
 * (PHP 8.5), not taken from memory, because getting one wrong silently corrupts a
 * whole class of verdicts:
 *
 *   readonly requires a declared type ......................... compile error without
 *   readonly is rejected on static properties ................. compile error
 *   readonly is rejected on properties with a default value ... compile error
 *   readonly is rejected on hooked properties (get or set) .... compile error
 *   readonly rejects indirect modification ($this->a[] = x) ... Error, even in the ctor
 *   readonly may be initialised from a static factory ......... legal, same class scope
 *   readonly may be re-initialised inside __clone() ........... legal since 8.3
 *   readonly may be re-initialised by clone-with .............. legal from inside the class
 *   clone-with on a readonly property from outside ............ Error
 *   asymmetric visibility requires a declared type ............ compile error without
 *   asymmetric visibility does apply to static properties ..... write from outside is an Error
 *   private(set) blocks subclass writes ....................... Error from the subclass scope
 *   protected(set) allows subclass writes ..................... legal
 *   a hooked property with no backing store is unwritable ..... Error, even in the ctor
 *   an interface `set` requirement forbids readonly ........... the implementation cannot
 *                                                               satisfy both
 *
 * An interface property requirement is not a property: it declares no storage, so it is
 * excluded from the census. Counting it reported phantom immutable properties and let
 * writes through an interface-typed receiver be credited to the phantom.
 *
 * Usage:
 *   php tools/ci/assert-substitutability-and-immutability.php
 *   php tools/ci/assert-substitutability-and-immutability.php src/Routing/Router.php
 *   php tools/ci/assert-substitutability-and-immutability.php --question=2 --json
 *   php tools/ci/assert-substitutability-and-immutability.php --generate-baseline
 *   php tools/ci/assert-substitutability-and-immutability.php --strict
 *
 * Positional arguments restrict what is REPORTED. The index is always built over the
 * whole tree, because deciding whether `Foo::$bar` is written from outside its class
 * is a question about every other file. `--index=` narrows the index too, which is
 * only useful when developing this script: types outside the narrowed index resolve
 * to `unknown` and are reported as undecidable rather than guessed at.
 *
 * Exit codes: 0 clean, 1 violations, 2 usage error.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

// Extension classes are deliberately absent from the root composer autoload
// (ADR-0004: no privileged built-in access). Reflection is only ever used here for
// types the AST index does not contain — vendor and PHP core — but a vendor class
// whose parent chain reaches an extension would otherwise be unresolvable, so this
// mirrors what scripts/boundary_check.php and tools/api/generate-snapshot.php do.
if (class_exists(Pulsar\Extensibility\ExtensionAutoloader::class)) {
    Pulsar\Extensibility\ExtensionAutoloader::registerForPaths([__DIR__ . '/../../extensions']);
}

// ---------------------------------------------------------------------------
// Facts — the distilled per-file record the analysis runs on
// ---------------------------------------------------------------------------

/**
 * A single write to a property, already attributed to its declaring class.
 */
final class WriteFact
{
    /**
     * @param string      $kind        assign|compound|incdec|array-dim|by-ref|by-ref-arg|unset|clone-with|promotion|default|hook-backing
     * @param string      $context     constructor|clone|method|static-method|hook|function
     * @param string      $relation    self|factory|peer|subclass|ancestor|foreign
     * @param string|null $writerClass FQCN of the class the write appears in, null at function scope
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $kind,
        public readonly string $context,
        public readonly string $relation,
        public readonly ?string $writerClass,
        public readonly bool $inClosure,
        public readonly bool $viaThis,
    ) {}

    /**
     * Does this write happen while the object is still being built?
     *
     * A write inside a closure never qualifies however it is nested: the closure runs
     * when it is called, not where it is written, so a closure created in a
     * constructor that assigns `$this->cache` is a deferred mutation.
     */
    public function isInitialising(): bool
    {
        if ($this->inClosure) {
            return false;
        }

        if ($this->relation !== 'self' && $this->relation !== 'factory') {
            return false;
        }

        return in_array($this->context, ['constructor', 'clone'], true)
            || in_array($this->kind, ['promotion', 'default', 'clone-with'], true)
            || ($this->relation === 'factory' && $this->context === 'static-method');
    }

    /**
     * Does this write make `readonly` impossible, whenever it happens?
     *
     * Indirect modification and references are rejected by the engine even inside the
     * constructor, so these block `readonly` regardless of when they occur.
     */
    public function blocksReadonlyUnconditionally(): bool
    {
        return in_array($this->kind, ['array-dim', 'by-ref', 'by-ref-arg', 'unset', 'incdec', 'compound'], true);
    }
}

/**
 * One declared property, with everything needed to reach a verdict.
 */
final class PropertyFact
{
    /** @var list<WriteFact> */
    public array $writes = [];

    /** @var list<array{file:string,line:int,detail:string}> */
    public array $undecidable = [];

    /**
     * @param list<string> $typeAtoms  resolved atoms of the declared type, unions and intersections flattened
     * @param string       $getVisibility public|protected|private
     * @param string|null  $setVisibility public|protected|private, null when visibility is symmetric
     */
    public function __construct(
        public readonly string $name,
        public readonly string $declaringClass,
        public readonly ?string $type,
        public readonly array $typeAtoms,
        public readonly string $getVisibility,
        public readonly ?string $setVisibility,
        public readonly bool $isReadonly,
        public readonly bool $isStatic,
        public readonly bool $isPromoted,
        public readonly bool $hasDefault,
        public readonly bool $isHooked,
        public readonly bool $isVirtual,
        public readonly bool $hasSetHook,
        public readonly string $file,
        public readonly int $line,
    ) {}

    /** The visibility that actually governs writes. */
    public function effectiveSetVisibility(): string
    {
        return $this->setVisibility ?? $this->getVisibility;
    }
}

/**
 * One class, interface, trait or enum.
 */
final class ClassFact
{
    /** @var array<string, PropertyFact> */
    public array $properties = [];

    /** @var list<string> */
    public array $instantiates = [];

    /** @var list<string> */
    public array $staticCallTargets = [];

    /** First effectful global function this class calls, if any. */
    public ?string $effectfulCall = null;

    /** @var list<array{file:string,line:int,detail:string}> */
    public array $dynamicWrites = [];

    /**
     * Property requirements declared by an interface, name => set is required.
     *
     * These are not properties. An interface cannot hold state, so counting them in
     * the census reports phantom immutable properties and lets writes through an
     * interface-typed receiver be credited to the phantom instead of the class that
     * actually declares the property.
     *
     * @var array<string, bool>
     */
    public array $propertyRequirements = [];

    /**
     * @param string       $kind class|interface|trait|enum
     * @param list<string> $interfaces
     * @param list<string> $traits
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $kind,
        public readonly bool $isFinal,
        public readonly bool $isAbstract,
        public readonly bool $isReadonly,
        public readonly bool $isAnonymous,
        public readonly ?string $parent,
        public array $interfaces,
        public array $traits,
        public readonly bool $isApi,
        public readonly bool $isInternal,
        public readonly string $file,
        public readonly int $line,
    ) {}
}

/**
 * One place where a class names another class as a dependency.
 */
final class DependencySite
{
    /**
     * @param string $siteKind constructor-parameter|promoted-constructor-parameter|property|return-type
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $dependingClass,
        public readonly string $siteKind,
        public readonly string $member,
        public readonly string $targetFqcn,
    ) {}
}

/**
 * A property write whose declaring class is not yet known.
 *
 * Attribution has to wait until the whole tree is indexed: `$this->handler = $h`
 * inside a subclass may target a property declared three levels up, or in a trait.
 */
final class PendingWrite
{
    public function __construct(
        public readonly string $propertyName,
        public readonly ?string $receiverType,
        public readonly bool $viaThis,
        public readonly bool $isStaticFetch,
        public readonly ?string $writerClass,
        public readonly string $context,
        public readonly bool $inClosure,
        public readonly string $kind,
        public readonly string $file,
        public readonly int $line,
        public readonly bool $receiverIsFresh = false,
    ) {}
}

// ---------------------------------------------------------------------------
// Collector — shared mutable state filled by the per-file visitor
// ---------------------------------------------------------------------------

final class Collector
{
    /** @var array<string, ClassFact> */
    public array $classes = [];

    /** @var list<DependencySite> */
    public array $sites = [];

    /** @var list<PendingWrite> */
    public array $writes = [];

    /** @var list<array{file:string,line:int,class:string,detail:string}> */
    public array $dynamic = [];

    /** @var list<array{file:string,detail:string}> */
    public array $parseErrors = [];

    public int $fileCount = 0;
}

// ---------------------------------------------------------------------------
// FileVisitor — the single AST pass
// ---------------------------------------------------------------------------

final class FileVisitor extends NodeVisitorAbstract
{
    /**
     * Builtin type names that never denote a class.
     *
     * `self`, `static` and `parent` are class references but they point back into the
     * declaring hierarchy, so a dependency on them is not a substitutability question
     * and they are dropped with the rest.
     */
    private const array NON_CLASS_TYPES = [
        'int', 'float', 'string', 'bool', 'array', 'object', 'mixed', 'callable',
        'iterable', 'void', 'never', 'null', 'false', 'true', 'static', 'self',
        'parent', 'resource', '$this',
    ];

    /**
     * Global functions that write through their first argument.
     *
     * There is no way to know the by-reference signature of an arbitrary callee from
     * the AST, so this covers the mutators that actually appear in PHP code and the
     * limitation is reported rather than hidden.
     */
    private const array BY_REF_FIRST_ARG = [
        'sort', 'rsort', 'usort', 'uasort', 'uksort', 'ksort', 'krsort', 'asort',
        'arsort', 'natsort', 'natcasesort', 'shuffle', 'array_push', 'array_pop',
        'array_shift', 'array_unshift', 'array_splice', 'array_walk',
        'array_walk_recursive', 'array_multisort', 'settype', 'reset', 'end',
        'next', 'prev', 'each', 'sscanf',
    ];

    /** Functions that write through a later argument, keyed by zero-based position. */
    private const array BY_REF_OTHER_ARG = [
        'preg_match' => 2,
        'preg_match_all' => 2,
        'str_replace' => 3,
        'str_ireplace' => 3,
        'preg_replace' => 4,
    ];

    /**
     * Global functions that reach outside the object.
     *
     * A deny-list rather than a pure-function allow-list: the allow-list would be the
     * whole standard library and would rot, while this names the categories that make
     * a class a service no matter how much it looks like a record — filesystem,
     * network, process, clock, randomness, environment, session and output. A class
     * that touches any of them is a collaborator a consumer will want to replace, so
     * the value-object rule refuses it.
     */
    private const array EFFECTFUL_FUNCTIONS = [
        'file_get_contents', 'file_put_contents', 'fopen', 'fread', 'fwrite', 'fclose',
        'fgets', 'file', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'scandir', 'glob',
        'is_file', 'is_dir', 'file_exists', 'realpath', 'touch', 'chmod', 'tempnam',
        'curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client', 'socket_create',
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen',
        'mail', 'header', 'setcookie', 'session_start', 'session_id', 'error_log', 'syslog',
        'random_bytes', 'random_int', 'mt_rand', 'rand', 'uniqid', 'shuffle',
        'time', 'microtime', 'hrtime', 'date', 'sleep', 'usleep',
        'getenv', 'putenv', 'ini_set', 'ini_get', 'setlocale',
        'echo', 'print_r', 'var_dump', 'printf', 'vprintf', 'readline',
    ];

    /** @var list<ClassFact> */
    private array $classStack = [];

    /** @var list<array{name:string,isStatic:bool,isClosure:bool,context:string}> */
    private array $funcStack = [];

    /** @var list<array<string, string|null>> */
    private array $scopeTypes = [];

    /**
     * Variables in the current scope that hold an object this scope just constructed.
     *
     * `$item = new self($key); $item->value = $v;` inside a static factory is
     * initialisation, because the object cannot have escaped yet. The same write to a
     * same-class instance that arrived as a parameter is a mutation of somebody else's
     * object, and only provenance separates the two.
     *
     * @var list<array<string, true>>
     */
    private array $scopeFresh = [];

    /** @var list<string> Name of the property whose hook body is being walked. */
    private array $hookStack = [];

    /**
     * Name of the property or parameter currently being declared.
     *
     * A hook body needs to know which property it belongs to, and the hook is a child
     * of that declaration. Keeping a one-element stack is cheaper than running
     * ParentConnectingVisitor, which sets an attribute on every node in the tree to
     * answer this one question.
     *
     * @var list<string>
     */
    private array $declStack = [];

    public function __construct(
        private readonly Collector $collector,
        private readonly string $file,
        private readonly NodeFinder $finder,
    ) {}

    private function currentDeclaration(): string
    {
        return $this->declStack === [] ? '' : $this->declStack[count($this->declStack) - 1];
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->enterClassLike($node);

            return null;
        }

        if ($node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction
            || $node instanceof Node\PropertyHook
        ) {
            $this->enterFunctionLike($node);

            return null;
        }

        if ($node instanceof Node\Stmt\TraitUse) {
            $class = $this->currentClass();

            if ($class !== null) {
                foreach ($node->traits as $traitName) {
                    $class->traits[] = $traitName->toString();
                }
            }

            return null;
        }

        if ($node instanceof Node\Stmt\Property) {
            $this->declStack[] = $node->props === [] ? '' : $node->props[0]->name->toString();
            $this->recordDeclaredProperty($node);

            return null;
        }

        if ($node instanceof Node\Param) {
            $this->declStack[] = $node->var instanceof Node\Expr\Variable && is_string($node->var->name)
                ? $node->var->name
                : '';
            $this->recordParam($node);

            return null;
        }

        if ($node instanceof Node\Expr\New_) {
            $this->recordInstantiation($node);

            return null;
        }

        if ($node instanceof Node\Expr\StaticCall) {
            $this->recordStaticCall($node);

            return null;
        }

        if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignRef) {
            $kind = $node instanceof Node\Expr\AssignRef ? 'by-ref' : 'assign';
            $this->collectWriteTargets($node->var, $kind, $node->getStartLine());
            $this->rememberLocalType($node);

            return null;
        }

        if ($node instanceof Node\Expr\AssignOp) {
            $this->collectWriteTargets($node->var, 'compound', $node->getStartLine());

            return null;
        }

        if ($node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc
            || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec
        ) {
            $this->collectWriteTargets($node->var, 'incdec', $node->getStartLine());

            return null;
        }

        if ($node instanceof Node\Stmt\Unset_) {
            foreach ($node->vars as $var) {
                $this->collectWriteTargets($var, 'unset', $node->getStartLine());
            }

            return null;
        }

        if ($node instanceof Node\Expr\FuncCall) {
            $this->recordFunctionCallWrites($node);

            return null;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classStack);

            return null;
        }

        if ($node instanceof Node\Stmt\Property || $node instanceof Node\Param) {
            array_pop($this->declStack);

            return null;
        }

        if ($node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction
            || $node instanceof Node\PropertyHook
        ) {
            array_pop($this->funcStack);
            array_pop($this->scopeTypes);
            array_pop($this->scopeFresh);

            if ($node instanceof Node\PropertyHook) {
                array_pop($this->hookStack);
            }
        }

        return null;
    }

    // -- class handling -----------------------------------------------------

    private function enterClassLike(Node\Stmt\ClassLike $node): void
    {
        $isAnonymous = $node->name === null;
        $fqcn = $isAnonymous
            ? sprintf('class@anonymous:%s:%d', $this->file, $node->getStartLine())
            : ($node->namespacedName?->toString() ?? $node->name->toString());

        $kind = match (true) {
            $node instanceof Node\Stmt\Interface_ => 'interface',
            $node instanceof Node\Stmt\Trait_ => 'trait',
            $node instanceof Node\Stmt\Enum_ => 'enum',
            default => 'class',
        };

        $flags = $node instanceof Node\Stmt\Class_ ? $node->flags : 0;

        $parent = null;
        $interfaces = [];

        if ($node instanceof Node\Stmt\Class_) {
            $parent = $node->extends?->toString();
            $interfaces = array_map(static fn(Node\Name $n): string => $n->toString(), $node->implements);
        } elseif ($node instanceof Node\Stmt\Interface_) {
            $interfaces = array_map(static fn(Node\Name $n): string => $n->toString(), $node->extends);
        } elseif ($node instanceof Node\Stmt\Enum_) {
            $interfaces = array_map(static fn(Node\Name $n): string => $n->toString(), $node->implements);
        }

        $attributes = [];

        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $attributes[] = $attribute->name->toString();
            }
        }

        $fact = new ClassFact(
            $fqcn,
            $kind,
            (bool) ($flags & Modifiers::FINAL),
            (bool) ($flags & Modifiers::ABSTRACT),
            (bool) ($flags & Modifiers::READONLY),
            $isAnonymous,
            $parent,
            // array_map preserves keys, so the three branches above produce arrays rather
            // than lists even though PhpParser hands them over contiguously.
            array_values($interfaces),
            // Filled in when the TraitUse child nodes are reached: NameResolver runs
            // ahead of this visitor per node, but it has not yet descended into the
            // class body, so reading `use X;` from here would capture the unresolved
            // short name and silently orphan every property the trait declares.
            [],
            $this->hasAttribute($attributes, 'Api'),
            $this->hasAttribute($attributes, 'Internal'),
            $this->file,
            $node->getStartLine(),
        );

        // A second declaration of the same FQCN means two files claim one class;
        // keeping the first is arbitrary but stable, and PSR-4 compliance is already
        // gated separately by tools/ci/assert-psr4-compliance.php.
        $this->collector->classes[$fqcn] ??= $fact;
        $this->classStack[] = $this->collector->classes[$fqcn];
    }

    /**
     * @param list<string> $attributes
     */
    private function hasAttribute(array $attributes, string $short): bool
    {
        foreach ($attributes as $attribute) {
            $parts = explode('\\', $attribute);

            if (end($parts) === $short) {
                return true;
            }
        }

        return false;
    }

    // -- function handling --------------------------------------------------

    private function enterFunctionLike(Node $node): void
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            $name = $node->name->toString();
            $isStatic = $node->isStatic();
            $context = match (true) {
                strtolower($name) === '__construct' => 'constructor',
                strtolower($name) === '__clone' => 'clone',
                $isStatic => 'static-method',
                default => 'method',
            };
            $this->funcStack[] = ['name' => $name, 'isStatic' => $isStatic, 'isClosure' => false, 'context' => $context];
            $this->recordReturnTypeSite($node->returnType, $name, $node->getStartLine());
        } elseif ($node instanceof Node\Stmt\Function_) {
            $name = $node->name->toString();
            $this->funcStack[] = ['name' => $name, 'isStatic' => false, 'isClosure' => false, 'context' => 'function'];
            $this->recordReturnTypeSite($node->returnType, $name, $node->getStartLine());
        } elseif ($node instanceof Node\PropertyHook) {
            $hookName = $node->name->toString();
            $this->funcStack[] = ['name' => $hookName, 'isStatic' => false, 'isClosure' => false, 'context' => 'hook'];
            $this->hookStack[] = $this->currentDeclaration();
        } else {
            $this->funcStack[] = ['name' => '{closure}', 'isStatic' => false, 'isClosure' => true, 'context' => 'closure'];
        }

        $this->scopeTypes[] = [];
        $this->scopeFresh[] = [];
    }

    // -- property declarations ----------------------------------------------

    private function recordDeclaredProperty(Node\Stmt\Property $node): void
    {
        $class = $this->currentClass();

        if ($class === null) {
            return;
        }

        $flags = $node->flags;
        $typeAtoms = $this->typeAtoms($node->type);
        $isHooked = $node->hooks !== [];

        // An interface declares a requirement, not a property: there is no storage, so
        // it belongs in neither the census nor the write attribution. What it does carry
        // is whether implementations must accept writes, which readonlyBlockers() needs.
        if ($class->kind === 'interface') {
            foreach ($node->props as $item) {
                $class->propertyRequirements[$item->name->toString()] = $this->hasSetHook($node->hooks);
                $this->recordPropertySite($node->type, $class->fqcn, 'property', '$' . $item->name->toString(), $item->getStartLine());
            }

            return;
        }

        foreach ($node->props as $item) {
            $name = $item->name->toString();

            $fact = new PropertyFact(
                $name,
                $class->fqcn,
                $this->typeToString($node->type),
                $typeAtoms,
                $this->getVisibility($flags),
                $this->setVisibility($flags),
                (bool) ($flags & Modifiers::READONLY),
                (bool) ($flags & Modifiers::STATIC),
                false,
                $item->default !== null,
                $isHooked,
                $isHooked && !$this->hooksTouchBackingStore($node->hooks, $name),
                $this->hasSetHook($node->hooks),
                $this->file,
                $item->getStartLine(),
            );

            $class->properties[$name] = $fact;

            // A default is an initialising write: it is what the property holds before
            // any constructor runs, and it is also what makes `readonly` impossible.
            if ($item->default !== null) {
                $this->collector->writes[] = new PendingWrite(
                    $name,
                    $class->fqcn,
                    true,
                    (bool) ($flags & Modifiers::STATIC),
                    $class->fqcn,
                    'constructor',
                    false,
                    'default',
                    $this->file,
                    $item->getStartLine(),
                );
            }

            $this->recordPropertySite($node->type, $class->fqcn, 'property', '$' . $name, $item->getStartLine());
        }
    }

    /**
     * @param array<Node\PropertyHook> $hooks Iterated, never indexed: PhpParser hands
     *        these over as a plain array and list-ness would be a demand this makes of
     *        its callers for nothing.
     */
    private function hooksTouchBackingStore(array $hooks, string $propertyName): bool
    {
        foreach ($hooks as $hook) {
            $body = $hook->body;

            if ($body === null) {
                continue;
            }

            $nodes = is_array($body) ? $body : [$body];

            $found = $this->finder->findFirst($nodes, static function (Node $n) use ($propertyName): bool {
                return $n instanceof Node\Expr\PropertyFetch
                    && $n->var instanceof Node\Expr\Variable
                    && $n->var->name === 'this'
                    && $n->name instanceof Node\Identifier
                    && $n->name->toString() === $propertyName;
            });

            if ($found !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<Node\PropertyHook> $hooks Iterated, never indexed: PhpParser hands
     *        these over as a plain array and list-ness would be a demand this makes of
     *        its callers for nothing.
     */
    private function hasSetHook(array $hooks): bool
    {
        foreach ($hooks as $hook) {
            if (strtolower($hook->name->toString()) === 'set') {
                return true;
            }
        }

        return false;
    }

    private function recordParam(Node\Param $node): void
    {
        $function = $this->currentFunction();

        if ($function === null) {
            return;
        }

        // Remember the declared type so `$dependency->prop = x` later in the body can
        // be attributed to a class instead of being written off as unresolvable.
        if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            $atoms = $this->typeAtoms($node->type);
            $this->setLocalType($node->var->name, count($atoms) === 1 ? $atoms[0] : null, false);
        }

        if ($function['context'] !== 'constructor') {
            return;
        }

        $class = $this->currentClass();

        if ($class === null) {
            return;
        }

        $name = $node->var instanceof Node\Expr\Variable && is_string($node->var->name)
            ? $node->var->name
            : '';

        if ($name === '') {
            return;
        }

        $isPromoted = $node->flags !== 0;

        $this->recordPropertySite(
            $node->type,
            $class->fqcn,
            $isPromoted ? 'promoted-constructor-parameter' : 'constructor-parameter',
            '$' . $name,
            $node->getStartLine(),
        );

        if (!$isPromoted) {
            return;
        }

        $flags = $node->flags;
        $isHooked = $node->hooks !== [];

        $class->properties[$name] = new PropertyFact(
            $name,
            $class->fqcn,
            $this->typeToString($node->type),
            $this->typeAtoms($node->type),
            $this->getVisibility($flags),
            $this->setVisibility($flags),
            (bool) ($flags & Modifiers::READONLY),
            false,
            true,
            false,
            $isHooked,
            $isHooked && !$this->hooksTouchBackingStore($node->hooks, $name),
            $this->hasSetHook($node->hooks),
            $this->file,
            $node->getStartLine(),
        );

        $this->collector->writes[] = new PendingWrite(
            $name,
            $class->fqcn,
            true,
            false,
            $class->fqcn,
            'constructor',
            false,
            'promotion',
            $this->file,
            $node->getStartLine(),
        );
    }

    // -- dependency sites ---------------------------------------------------

    private function recordPropertySite(?Node $type, string $owner, string $siteKind, string $member, int $line): void
    {
        foreach ($this->typeAtoms($type) as $atom) {
            $this->collector->sites[] = new DependencySite($this->file, $line, $owner, $siteKind, $member, $atom);
        }
    }

    private function recordReturnTypeSite(?Node $type, string $method, int $line): void
    {
        $class = $this->currentClass();
        $owner = $class->fqcn ?? sprintf('(function %s)', $method);

        foreach ($this->typeAtoms($type) as $atom) {
            $this->collector->sites[] = new DependencySite(
                $this->file,
                $line,
                $owner,
                'return-type',
                $method . '()',
                $atom,
            );
        }
    }

    // -- expressions --------------------------------------------------------

    private function recordInstantiation(Node\Expr\New_ $node): void
    {
        $class = $this->currentClass();

        if ($class === null || !$node->class instanceof Node\Name) {
            return;
        }

        $name = $node->class->toString();

        if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
            return;
        }

        $class->instantiates[] = $name;
    }

    private function recordStaticCall(Node\Expr\StaticCall $node): void
    {
        $class = $this->currentClass();

        if ($class === null || !$node->class instanceof Node\Name) {
            return;
        }

        $name = $node->class->toString();

        if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
            return;
        }

        $class->staticCallTargets[] = $name;
    }

    private function recordFunctionCallWrites(Node\Expr\FuncCall $node): void
    {
        if (!$node->name instanceof Node\Name) {
            return;
        }

        $name = strtolower($node->name->getLast());

        $class = $this->currentClass();

        if ($class !== null && $class->effectfulCall === null
            && in_array($name, self::EFFECTFUL_FUNCTIONS, true)
        ) {
            $class->effectfulCall = $name;
        }

        // PHP 8.5 clone-with parses as a call to `clone`; its second argument names
        // the properties being re-initialised on the cloned object.
        if ($name === 'clone' && count($node->args) >= 2) {
            $this->recordCloneWith($node);

            return;
        }

        if (in_array($name, self::BY_REF_FIRST_ARG, true) && isset($node->args[0])) {
            $argument = $node->args[0];

            if ($argument instanceof Node\Arg) {
                $this->collectWriteTargets($argument->value, 'by-ref-arg', $node->getStartLine());
            }

            return;
        }

        $position = self::BY_REF_OTHER_ARG[$name] ?? null;

        if ($position !== null && isset($node->args[$position])) {
            $argument = $node->args[$position];

            if ($argument instanceof Node\Arg) {
                $this->collectWriteTargets($argument->value, 'by-ref-arg', $node->getStartLine());
            }
        }
    }

    private function recordCloneWith(Node\Expr\FuncCall $node): void
    {
        $subject = $node->args[0];
        $changes = $node->args[1];

        if (!$subject instanceof Node\Arg || !$changes instanceof Node\Arg) {
            return;
        }

        $receiverType = $this->inferType($subject->value);
        $viaThis = $subject->value instanceof Node\Expr\Variable && $subject->value->name === 'this';

        if (!$changes->value instanceof Node\Expr\Array_) {
            $class = $this->currentClass();
            $this->collector->dynamic[] = [
                'file' => $this->file,
                'line' => $node->getStartLine(),
                'class' => $class->fqcn ?? '(function scope)',
                'detail' => 'clone-with whose property map is not a literal array',
            ];

            return;
        }

        foreach ($changes->value->items as $item) {
            if (!$item instanceof Node\ArrayItem) {
                continue;
            }

            if (!$item->key instanceof Node\Scalar\String_) {
                $class = $this->currentClass();
                $this->collector->dynamic[] = [
                    'file' => $this->file,
                    'line' => $item->getStartLine(),
                    'class' => $class->fqcn ?? '(function scope)',
                    'detail' => 'clone-with key is not a literal string',
                ];

                continue;
            }

            $this->pushWrite(
                $item->key->value,
                $receiverType,
                $viaThis,
                false,
                'clone-with',
                $item->getStartLine(),
                $this->isFreshVariable($subject->value),
            );
        }
    }

    /**
     * Find the property being written by an assignment target.
     *
     * `$this->map['k'] = v` writes `map` indirectly; `$this->dep->field = v` writes
     * `field` on whatever `$this->dep` holds and leaves `dep` itself untouched, so the
     * outermost property fetch is the target and its receiver is what must be typed.
     */
    private function collectWriteTargets(Node $target, string $kind, int $line): void
    {
        if ($target instanceof Node\Expr\List_ || $target instanceof Node\Expr\Array_) {
            foreach ($target->items as $item) {
                if ($item instanceof Node\ArrayItem) {
                    $this->collectWriteTargets($item->value, $kind, $line);
                }
            }

            return;
        }

        $indirect = false;

        while ($target instanceof Node\Expr\ArrayDimFetch) {
            $indirect = true;
            $target = $target->var;
        }

        if ($indirect && $kind === 'assign') {
            $kind = 'array-dim';
        }

        if ($target instanceof Node\Expr\PropertyFetch || $target instanceof Node\Expr\NullsafePropertyFetch) {
            if (!$target->name instanceof Node\Identifier) {
                $class = $this->currentClass();
                $this->collector->dynamic[] = [
                    'file' => $this->file,
                    'line' => $line,
                    'class' => $class->fqcn ?? '(function scope)',
                    'detail' => 'write through a computed property name ($obj->{$name} = ...)',
                ];

                return;
            }

            $viaThis = $target->var instanceof Node\Expr\Variable && $target->var->name === 'this';
            $this->pushWrite(
                $target->name->toString(),
                $viaThis ? $this->currentClass()?->fqcn : $this->inferType($target->var),
                $viaThis,
                false,
                $kind,
                $line,
                $this->isFreshVariable($target->var),
            );

            return;
        }

        if ($target instanceof Node\Expr\StaticPropertyFetch) {
            if (!$target->name instanceof Node\VarLikeIdentifier) {
                $class = $this->currentClass();
                $this->collector->dynamic[] = [
                    'file' => $this->file,
                    'line' => $line,
                    'class' => $class->fqcn ?? '(function scope)',
                    'detail' => 'write through a computed static property name',
                ];

                return;
            }

            $owner = $target->class instanceof Node\Name
                ? $this->resolveClassName($target->class->toString())
                : null;

            $this->pushWrite(
                $target->name->toString(),
                $owner,
                $owner !== null && $owner === $this->currentClass()?->fqcn,
                true,
                $kind,
                $line,
            );
        }
    }

    private function pushWrite(
        string $property,
        ?string $receiverType,
        bool $viaThis,
        bool $isStaticFetch,
        string $kind,
        int $line,
        bool $receiverIsFresh = false,
    ): void {
        $function = $this->currentFunction();
        $class = $this->currentClass();

        // Inside a set hook, `$this->x = $v` where x is the hooked property is the
        // backing store doing its job, not a mutation of the object's contract.
        if ($function !== null && $function['context'] === 'hook'
            && $this->hookStack !== [] && end($this->hookStack) === $property && $viaThis
        ) {
            $kind = 'hook-backing';
        }

        $this->collector->writes[] = new PendingWrite(
            $property,
            $receiverType,
            $viaThis,
            $isStaticFetch,
            $class?->fqcn,
            $function['context'] ?? 'function',
            $this->insideClosure(),
            $kind,
            $this->file,
            $line,
            $receiverIsFresh,
        );
    }

    private function insideClosure(): bool
    {
        foreach ($this->funcStack as $frame) {
            if ($frame['isClosure']) {
                return true;
            }
        }

        return false;
    }

    // -- local type inference ------------------------------------------------

    private function rememberLocalType(Node\Expr\Assign|Node\Expr\AssignRef $node): void
    {
        if (!$node->var instanceof Node\Expr\Variable || !is_string($node->var->name)) {
            return;
        }

        $this->setLocalType($node->var->name, $this->inferType($node->expr), $node->expr instanceof Node\Expr\New_);
    }

    private function setLocalType(string $variable, ?string $type, bool $fresh): void
    {
        if ($this->scopeTypes === []) {
            return;
        }

        $index = count($this->scopeTypes) - 1;
        $this->scopeTypes[$index][$variable] = $type;

        if ($fresh) {
            $this->scopeFresh[$index][$variable] = true;
        } else {
            unset($this->scopeFresh[$index][$variable]);
        }
    }

    private function isFreshVariable(Node $expr): bool
    {
        if ($expr instanceof Node\Expr\New_) {
            return true;
        }

        if (!$expr instanceof Node\Expr\Variable || !is_string($expr->name) || $this->scopeFresh === []) {
            return false;
        }

        return isset($this->scopeFresh[count($this->scopeFresh) - 1][$expr->name]);
    }

    /**
     * Best-effort static type of an expression, used only to attribute property writes.
     *
     * Deliberately shallow: `new X`, a typed parameter, a typed property of `$this`,
     * and `$this` itself. Everything else returns null and is reported as an
     * unattributed write rather than guessed.
     */
    private function inferType(Node $expr): ?string
    {
        if ($expr instanceof Node\Expr\Clone_) {
            return $this->inferType($expr->expr);
        }

        if ($expr instanceof Node\Expr\Variable) {
            if ($expr->name === 'this') {
                return $this->currentClass()?->fqcn;
            }

            if (!is_string($expr->name) || $this->scopeTypes === []) {
                return null;
            }

            return $this->scopeTypes[count($this->scopeTypes) - 1][$expr->name] ?? null;
        }

        if ($expr instanceof Node\Expr\New_) {
            if (!$expr->class instanceof Node\Name) {
                return null;
            }

            return $this->resolveClassName($expr->class->toString());
        }

        if ($expr instanceof Node\Expr\PropertyFetch
            && $expr->var instanceof Node\Expr\Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Node\Identifier
        ) {
            $class = $this->currentClass();
            $property = $class?->properties[$expr->name->toString()] ?? null;

            if ($property !== null && count($property->typeAtoms) === 1) {
                return $property->typeAtoms[0];
            }
        }

        return null;
    }

    private function resolveClassName(string $name): ?string
    {
        return match (strtolower($name)) {
            'self', 'static' => $this->currentClass()?->fqcn,
            'parent' => $this->currentClass()?->parent,
            default => $name,
        };
    }

    // -- type helpers --------------------------------------------------------

    /**
     * Flatten a declared type into the class names it names.
     *
     * Unions and intersections are decomposed because each member is independently a
     * dependency; builtins and self-references are dropped.
     *
     * @return list<string>
     */
    private function typeAtoms(?Node $type): array
    {
        if ($type === null) {
            return [];
        }

        if ($type instanceof Node\NullableType) {
            return $this->typeAtoms($type->type);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $atoms = [];

            foreach ($type->types as $member) {
                foreach ($this->typeAtoms($member) as $atom) {
                    $atoms[] = $atom;
                }
            }

            return array_values(array_unique($atoms));
        }

        if ($type instanceof Node\Identifier) {
            return in_array(strtolower($type->toString()), self::NON_CLASS_TYPES, true) ? [] : [$type->toString()];
        }

        if ($type instanceof Node\Name) {
            $name = $type->toString();

            return in_array(strtolower($name), self::NON_CLASS_TYPES, true) ? [] : [$name];
        }

        return [];
    }

    private function typeToString(?Node $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof Node\NullableType) {
            return '?' . $this->typeToString($type->type);
        }

        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(fn(Node $t): string => (string) $this->typeToString($t), $type->types));
        }

        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(fn(Node $t): string => (string) $this->typeToString($t), $type->types));
        }

        if ($type instanceof Node\Identifier || $type instanceof Node\Name) {
            return $type->toString();
        }

        return null;
    }

    private function getVisibility(int $flags): string
    {
        if ($flags & Modifiers::PRIVATE) {
            return 'private';
        }

        if ($flags & Modifiers::PROTECTED) {
            return 'protected';
        }

        return 'public';
    }

    private function setVisibility(int $flags): ?string
    {
        if ($flags & Modifiers::PRIVATE_SET) {
            return 'private';
        }

        if ($flags & Modifiers::PROTECTED_SET) {
            return 'protected';
        }

        if ($flags & Modifiers::PUBLIC_SET) {
            return 'public';
        }

        return null;
    }

    private function currentClass(): ?ClassFact
    {
        return $this->classStack === [] ? null : $this->classStack[count($this->classStack) - 1];
    }

    /**
     * @return array{name:string,isStatic:bool,isClosure:bool,context:string}|null
     */
    private function currentFunction(): ?array
    {
        return $this->funcStack === [] ? null : $this->funcStack[count($this->funcStack) - 1];
    }
}

// ---------------------------------------------------------------------------
// Index — hierarchy queries, write attribution, external type lookup
// ---------------------------------------------------------------------------

final class Index
{
    /** @var array<string, array{kind:string,final:bool,abstract:bool,interfaces:list<string>}|null> */
    private array $externalCache = [];

    /** @var list<array{file:string,line:int,detail:string}> */
    public array $unattributed = [];

    /** @var list<array{file:string,line:int,detail:string}> */
    public array $inferenceConflicts = [];

    /**
     * Every class declaring a property of a given name.
     *
     * Attributing a write whose receiver could not be typed means asking which classes
     * could possibly own that property. Asking it by scanning every class turns the
     * pass quadratic — measurably so: the run over src/ alone did not finish in five
     * minutes before this index existed.
     *
     * @var array<string, list<ClassFact>>
     */
    private array $byPropertyName = [];

    /** @var array<string, bool> */
    private array $subclassCache = [];

    public function __construct(private readonly Collector $collector) {}

    /** @return array<string, ClassFact> */
    public function classes(): array
    {
        return $this->collector->classes;
    }

    public function get(string $fqcn): ?ClassFact
    {
        return $this->collector->classes[ltrim($fqcn, '\\')] ?? null;
    }

    /**
     * Attribute every collected write to the class that actually declares the property.
     */
    public function attributeWrites(): void
    {
        foreach ($this->collector->classes as $class) {
            foreach ($class->properties as $property) {
                $this->byPropertyName[$property->name][] = $class;
            }
        }

        foreach ($this->collector->dynamic as $entry) {
            $class = $this->get($entry['class']);

            if ($class !== null) {
                $class->dynamicWrites[] = [
                    'file' => $entry['file'],
                    'line' => $entry['line'],
                    'detail' => $entry['detail'],
                ];
            }

            if ($class === null) {
                $this->unattributed[] = [
                    'file' => $entry['file'],
                    'line' => $entry['line'],
                    'detail' => $entry['detail'] . ' in ' . $entry['class'],
                ];
            }
        }

        foreach ($this->collector->writes as $write) {
            $this->attributeWrite($write);
        }
    }

    private function attributeWrite(PendingWrite $write): void
    {
        $candidates = [];

        if ($write->receiverType !== null) {
            $declaring = $this->declaringClassOf($write->receiverType, $write->propertyName);

            if ($declaring !== null) {
                $candidates[] = $declaring;
            }
        } else {
            // An unresolved receiver still names a property. Only classes whose
            // set-visibility would actually permit the write are plausible targets;
            // anything else would be a fatal error at runtime, so it cannot be it.
            foreach ($this->byPropertyName[$write->propertyName] ?? [] as $class) {
                $property = $class->properties[$write->propertyName];

                if ($this->writeIsPermitted($class, $property, $write->writerClass)) {
                    $candidates[] = $class;
                }
            }
        }

        if ($candidates === []) {
            $detail = sprintf(
                'write to $%s could not be attributed to a declaring class (receiver type %s)',
                $write->propertyName,
                $write->receiverType ?? 'unknown',
            );

            $this->unattributed[] = [
                'file' => $write->file,
                'line' => $write->line,
                'detail' => $detail,
            ];

            // The global list is not enough. A refactorer works from the per-property
            // verdict, and without the note there it reads "make this readonly" with no
            // caveat on a property that is written somewhere this pass could not resolve.
            foreach ($this->byPropertyName[$write->propertyName] ?? [] as $class) {
                $class->properties[$write->propertyName]->undecidable[] = [
                    'file' => $write->file,
                    'line' => $write->line,
                    'detail' => $detail,
                ];
            }

            return;
        }

        if (count($candidates) > 1) {
            foreach ($candidates as $candidate) {
                $candidate->properties[$write->propertyName]->undecidable[] = [
                    'file' => $write->file,
                    'line' => $write->line,
                    'detail' => sprintf(
                        'a write to $%s with an unresolved receiver matches %d classes; not credited to any',
                        $write->propertyName,
                        count($candidates),
                    ),
                ];
            }

            return;
        }

        $declaring = $candidates[0];
        $property = $declaring->properties[$write->propertyName];

        if (!$this->writeIsPermitted($declaring, $property, $write->writerClass)) {
            // The engine would reject this write, so the receiver inference is wrong.
            // Recording it as a violation would invent an encapsulation defect that
            // cannot exist in code that runs.
            $this->inferenceConflicts[] = [
                'file' => $write->file,
                'line' => $write->line,
                'detail' => sprintf(
                    'inferred a write to %s::$%s from %s, which %s(set) forbids — inference discarded',
                    $declaring->fqcn,
                    $property->name,
                    $write->writerClass ?? 'function scope',
                    $property->effectiveSetVisibility(),
                ),
            ];

            return;
        }

        $property->writes[] = new WriteFact(
            $write->file,
            $write->line,
            $write->kind,
            $write->context,
            $this->relation($declaring, $write),
            $write->writerClass,
            $write->inClosure,
            $write->viaThis,
        );
    }

    /**
     * How the writing class relates to the class that declares the property.
     */
    private function relation(ClassFact $declaring, PendingWrite $write): string
    {
        if ($write->writerClass === null) {
            return 'foreign';
        }

        // A trait's properties are copied into every using class, so a write from the
        // user of the trait is the object writing its own state, not a foreign reach.
        if ($declaring->kind === 'trait' && $this->usesTrait($write->writerClass, $declaring->fqcn)) {
            return $write->viaThis ? 'self' : 'peer';
        }

        if ($write->writerClass === $declaring->fqcn) {
            if ($write->viaThis) {
                return 'self';
            }

            // Same class scope, a different instance. Only an object this scope just
            // built is still under construction; anything else is a peer whose state
            // is being changed after the fact.
            return $write->receiverIsFresh ? 'factory' : 'peer';
        }

        if ($this->isSubclassOf($write->writerClass, $declaring->fqcn)) {
            return 'subclass';
        }

        if ($this->isSubclassOf($declaring->fqcn, $write->writerClass)) {
            return 'ancestor';
        }

        return 'foreign';
    }

    private function writeIsPermitted(ClassFact $declaring, PropertyFact $property, ?string $writer): bool
    {
        // Trait members are compiled into the using class, so its scope is the
        // declaring scope as far as visibility is concerned.
        if ($declaring->kind === 'trait' && $this->usesTrait($writer, $declaring->fqcn)) {
            return true;
        }

        return match ($property->effectiveSetVisibility()) {
            'private' => $writer === $declaring->fqcn,
            'protected' => $writer !== null
                && ($writer === $declaring->fqcn
                    || $this->isSubclassOf($writer, $declaring->fqcn)
                    || $this->isSubclassOf($declaring->fqcn, $writer)),
            default => true,
        };
    }

    /**
     * Does $class compose $trait, directly or through a parent or another trait?
     */
    public function usesTrait(?string $class, string $trait): bool
    {
        if ($class === null) {
            return false;
        }

        $trait = ltrim($trait, '\\');
        $seen = [];
        $queue = [ltrim($class, '\\')];

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($current === null || isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $fact = $this->get($current);

            if ($fact === null) {
                continue;
            }

            foreach ($fact->traits as $used) {
                if (ltrim($used, '\\') === $trait) {
                    return true;
                }

                $queue[] = $used;
            }

            if ($fact->parent !== null) {
                $queue[] = $fact->parent;
            }
        }

        return false;
    }

    /**
     * The class in $fqcn's hierarchy (itself, its traits, then its parents) that
     * declares $property.
     */
    public function declaringClassOf(string $fqcn, string $property): ?ClassFact
    {
        $seen = [];
        $queue = [ltrim($fqcn, '\\')];

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($current === null || isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $class = $this->get($current);

            if ($class === null) {
                continue;
            }

            if (isset($class->properties[$property])) {
                return $class;
            }

            foreach ($class->traits as $trait) {
                $queue[] = $trait;
            }

            if ($class->parent !== null) {
                $queue[] = $class->parent;
            }
        }

        return null;
    }

    public function isSubclassOf(string $child, string $ancestor): bool
    {
        $child = ltrim($child, '\\');
        $ancestor = ltrim($ancestor, '\\');
        $cacheKey = $child . '<' . $ancestor;

        if (isset($this->subclassCache[$cacheKey])) {
            return $this->subclassCache[$cacheKey];
        }

        return $this->subclassCache[$cacheKey] = $this->walkAncestry($child, $ancestor);
    }

    private function walkAncestry(string $child, string $ancestor): bool
    {
        $seen = [];
        $queue = [$child];

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($current === null || isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;

            if ($current === $ancestor && $current !== $child) {
                return true;
            }

            $class = $this->get($current);

            if ($class === null) {
                continue;
            }

            if ($class->parent !== null) {
                $queue[] = $class->parent;
            }

            foreach ($class->interfaces as $interface) {
                $queue[] = $interface;
            }
        }

        return false;
    }

    /**
     * Reflect a type the AST index does not contain: vendor code and PHP core.
     *
     * @return array{kind:string,final:bool,abstract:bool,interfaces:list<string>}|null
     */
    public function external(string $fqcn): ?array
    {
        $fqcn = ltrim($fqcn, '\\');

        if (array_key_exists($fqcn, $this->externalCache)) {
            return $this->externalCache[$fqcn];
        }

        try {
            if (!class_exists($fqcn) && !interface_exists($fqcn) && !enum_exists($fqcn) && !trait_exists($fqcn)) {
                return $this->externalCache[$fqcn] = null;
            }

            $reflection = new ReflectionClass($fqcn);
        } catch (Throwable) {
            return $this->externalCache[$fqcn] = null;
        }

        $kind = match (true) {
            $reflection->isInterface() => 'interface',
            $reflection->isEnum() => 'enum',
            $reflection->isTrait() => 'trait',
            default => 'class',
        };

        return $this->externalCache[$fqcn] = [
            'kind' => $kind,
            'final' => $reflection->isFinal(),
            'abstract' => $reflection->isAbstract(),
            'interfaces' => array_values($reflection->getInterfaceNames()),
        ];
    }
}

// ---------------------------------------------------------------------------
// DataTypeRule — which concrete types are legitimately depended upon
// ---------------------------------------------------------------------------

/**
 * Separates data from collaborators, structurally.
 *
 * A class is data when it is one of:
 *
 *   enum        — an enum, always: there is nothing to substitute in a closed set of
 *                 named constants.
 *   exception   — reachable from Throwable through its parents or interfaces. Catch
 *                 clauses name concrete exception classes by design.
 *   value-object— all five of the following hold:
 *                 (a) every instance property has a declared type, and no property
 *                     type is an interface or a class that is itself a collaborator.
 *                     This is the operative form of "no injected dependencies of its
 *                     own": a class that holds a collaborator is a collaborator.
 *                 (b) it has at least one instance property. A class with no state is
 *                     behaviour only, and is categorised `utility` instead.
 *                 (c) it is immutable: no property is written after construction.
 *                 (d) it has no outbound behaviour: it never instantiates and never
 *                     makes a static call to a class that is itself a collaborator.
 *                     Calls to `utility` classes — stateless static helpers such as a
 *                     coercion or hashing helper — do not count: they can be neither
 *                     injected nor decorated, so they say nothing about whether the
 *                     caller carries data. Accessors, comparisons, formatting and
 *                     `with*()` clone-withs all pass.
 *                 (e) it calls no effectful global function. Without this, a class
 *                     holding one string and reading a file through it would pass every
 *                     other clause and be excused as a record. See EFFECTFUL_FUNCTIONS.
 *   utility     — no instance properties and no constructor parameters: a static helper
 *                 or a marker. Not data, so a dependency on one is still reported under
 *                 question 1; but calling one does not make the caller a service.
 *
 * Cycles (a value object holding another that holds the first) are resolved
 * co-inductively: a class already under evaluation is assumed to be data. Both must
 * still satisfy every other clause independently, so a pair of mutually-referencing
 * services is not laundered into data by the assumption.
 *
 * External types are not ours to change. An external interface counts as a
 * collaborator for clause (a); every other external type counts as a value, because
 * `DateTimeImmutable` on a property is data by any reading and vendor finality is
 * outside the reach of this repository.
 */
final class DataTypeRule
{
    private const array SCALARS = ['int', 'float', 'string', 'bool', 'array', 'iterable', 'mixed', 'object'];

    /** @var array<string, string> */
    private array $memo = [];

    /** @var array<string, true> */
    private array $inProgress = [];

    public function __construct(
        private readonly Index $index,
        private readonly MutabilityAnalyzer $mutability,
    ) {}

    /**
     * @return string one of: enum|exception|value-object|utility|collaborator|external|unknown
     */
    public function categorise(string $fqcn): string
    {
        $fqcn = ltrim($fqcn, '\\');

        if (isset($this->memo[$fqcn])) {
            return $this->memo[$fqcn];
        }

        if (isset($this->inProgress[$fqcn])) {
            return 'value-object';
        }

        $class = $this->index->get($fqcn);

        if ($class === null) {
            $external = $this->index->external($fqcn);

            if ($external === null) {
                return $this->memo[$fqcn] = 'unknown';
            }

            if ($external['kind'] === 'enum') {
                return $this->memo[$fqcn] = 'enum';
            }

            if (in_array(Throwable::class, $external['interfaces'], true)) {
                return $this->memo[$fqcn] = 'exception';
            }

            return $this->memo[$fqcn] = 'external';
        }

        if ($class->kind === 'enum') {
            return $this->memo[$fqcn] = 'enum';
        }

        if ($class->kind === 'interface') {
            return $this->memo[$fqcn] = 'collaborator';
        }

        if ($this->isThrowable($class)) {
            return $this->memo[$fqcn] = 'exception';
        }

        $this->inProgress[$fqcn] = true;

        try {
            $category = $this->categoriseStructurally($class);
        } finally {
            unset($this->inProgress[$fqcn]);
        }

        return $this->memo[$fqcn] = $category;
    }

    private function isThrowable(ClassFact $class): bool
    {
        $seen = [];
        $queue = [$class->fqcn];

        while ($queue !== []) {
            $current = ltrim((string) array_shift($queue), '\\');

            if ($current === '' || isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;

            if (in_array($current, ['Throwable', 'Exception', 'Error'], true)) {
                return true;
            }

            $next = $this->index->get($current);

            if ($next === null) {
                $external = $this->index->external($current);

                if ($external !== null && in_array(Throwable::class, $external['interfaces'], true)) {
                    return true;
                }

                continue;
            }

            if ($next->parent !== null) {
                $queue[] = $next->parent;
            }

            foreach ($next->interfaces as $interface) {
                $queue[] = $interface;
            }
        }

        return false;
    }

    private function categoriseStructurally(ClassFact $class): string
    {
        $instanceProperties = array_filter(
            $class->properties,
            static fn(PropertyFact $p): bool => !$p->isStatic && !$p->isVirtual,
        );

        // (b) no state at all: a static helper or a marker, not a record.
        if ($instanceProperties === []) {
            return 'utility';
        }

        // (e) reaching outside the object makes it a service whatever it holds.
        if ($class->effectfulCall !== null) {
            return 'collaborator';
        }

        foreach ($instanceProperties as $property) {
            // (a) an untyped property cannot be proven to hold data.
            if ($property->type === null) {
                return 'collaborator';
            }

            foreach ($property->typeAtoms as $atom) {
                if (in_array(strtolower($atom), self::SCALARS, true)) {
                    continue;
                }

                if ($this->isCollaboratorType($atom)) {
                    return 'collaborator';
                }
            }

            // (c) immutable.
            if ($this->mutability->hasPostConstructionWrite($property)) {
                return 'collaborator';
            }
        }

        // (d) no outbound behaviour. A `utility` target is neutral: a stateless static
        // helper can be neither injected nor decorated, so calling one says nothing
        // about whether this class carries data.
        foreach ([...$class->instantiates, ...$class->staticCallTargets] as $target) {
            if ($this->index->get($target) !== null && $this->categorise($target) === 'collaborator') {
                return 'collaborator';
            }
        }

        return 'value-object';
    }

    private function isCollaboratorType(string $atom): bool
    {
        $class = $this->index->get($atom);

        if ($class !== null) {
            // Clause (a) is stricter than clause (d): a stateless helper held as a
            // property is a strategy the object depends on, even though calling the
            // same helper statically would be neutral.
            return in_array($this->categorise($atom), ['collaborator', 'utility'], true);
        }

        $external = $this->index->external($atom);

        // An external interface is a seam the value object depends on; every other
        // external type is treated as a value.
        return $external !== null && $external['kind'] === 'interface';
    }
}

// ---------------------------------------------------------------------------
// MutabilityAnalyzer — QUESTION 2
// ---------------------------------------------------------------------------

/**
 * One verdict per property.
 */
final class PropertyVerdict
{
    /**
     * @param list<string> $blockers
     * @param list<string> $evidence
     */
    public function __construct(
        public readonly PropertyFact $property,
        public readonly string $verdict,
        public readonly bool $compliant,
        public readonly string $current,
        public readonly string $recommendation,
        public readonly string $reason,
        public readonly array $blockers,
        public readonly array $evidence,
    ) {}
}

final class MutabilityAnalyzer
{
    public function __construct(private readonly Index $index) {}

    /**
     * Is this property assigned anywhere after the object exists?
     *
     * Used both for the verdict and by {@see DataTypeRule} clause (c).
     */
    public function hasPostConstructionWrite(PropertyFact $property): bool
    {
        if ($property->isReadonly) {
            return false;
        }

        foreach ($property->writes as $write) {
            if ($write->kind === 'hook-backing') {
                continue;
            }

            if (!$write->isInitialising()) {
                return true;
            }
        }

        return false;
    }

    public function verdictFor(ClassFact $class, PropertyFact $property): PropertyVerdict
    {
        $current = $this->describeCurrent($class, $property);

        // A hooked property with no backing store holds nothing; the engine rejects
        // every write to it, including from the constructor. It is immutable by
        // construction and there is nothing to propose.
        if ($property->isVirtual) {
            return new PropertyVerdict(
                $property,
                'already-immutable',
                true,
                $current,
                'none',
                'virtual property (hooks, no backing store) — PHP rejects every write to it',
                [],
                [],
            );
        }

        if ($class->isReadonly || $property->isReadonly) {
            return new PropertyVerdict(
                $property,
                'already-immutable',
                true,
                $current,
                'none',
                $class->isReadonly && !$property->isReadonly
                    ? 'declared inside a readonly class'
                    : 'declared readonly',
                [],
                [],
            );
        }

        // At least one write to this property could not be attributed to a declaring
        // class. It may be this one. Any category below would be a guess presented as a
        // measurement, and the guess that costs most is "readonly" on a property that is
        // in fact written — so the honest verdict is that there is no verdict.
        if ($property->undecidable !== []) {
            return new PropertyVerdict(
                $property,
                'undecidable',
                false,
                $current,
                'resolve the unattributed write(s) below before changing this property',
                'a write to a property of this name could not be attributed to its declaring class',
                array_map(
                    static fn(array $note): string => sprintf('%s:%d %s', $note['file'], $note['line'], $note['detail']),
                    $property->undecidable,
                ),
                [],
            );
        }

        $external = [];
        $mutating = [];
        $unconditional = [];

        foreach ($property->writes as $write) {
            if ($write->kind === 'hook-backing') {
                continue;
            }

            if ($write->relation === 'foreign') {
                $external[] = $write;

                continue;
            }

            if (!$write->isInitialising()) {
                $mutating[] = $write;

                continue;
            }

            if ($write->blocksReadonlyUnconditionally()) {
                $unconditional[] = $write;
            }
        }

        ['hard' => $hard, 'soft' => $soft] = $this->readonlyBlockers($class, $property);
        $blockers = [...$hard, ...$soft];

        // A class that assigns through a computed property name may be writing this
        // very property, and no static reading can tell. Say so on every property it
        // declares rather than presenting a verdict that quietly assumes otherwise.
        foreach ($class->dynamicWrites as $dynamic) {
            $blockers[] = sprintf(
                '%s:%d %s — verdicts for this class cannot account for it',
                $dynamic['file'],
                $dynamic['line'],
                $dynamic['detail'],
            );
        }

        $subclassWrites = array_filter(
            [...$mutating, ...$unconditional],
            static fn(WriteFact $w): bool => $w->relation === 'subclass',
        );
        $recommendedSet = $subclassWrites === [] ? 'private' : 'protected';

        if ($external !== []) {
            return new PropertyVerdict(
                $property,
                'externally-written',
                false,
                $current,
                sprintf('%s(set) — and move the write behind a method on the owner', $recommendedSet),
                'assigned from outside the declaring class; the owner does not control its own state',
                $blockers,
                $this->evidence($external),
            );
        }

        if ($mutating !== []) {
            return new PropertyVerdict(
                $property,
                'should-be-private-set',
                $this->satisfiesSetVisibility($property, $recommendedSet) && $property->type !== null,
                $current,
                $this->setVisibilityRecommendation($property, $recommendedSet),
                'written internally after construction (memoisation, lazy initialisation, or genuine state)',
                $property->type === null ? [...$blockers, 'asymmetric visibility requires a declared type'] : $blockers,
                $this->evidence($mutating),
            );
        }

        if ($hard === [] && $unconditional === []) {
            return new PropertyVerdict(
                $property,
                'should-be-readonly',
                false,
                $current,
                $soft === [] ? 'readonly' : 'readonly, after clearing the note(s) below',
                'written only during construction and never again',
                $blockers,
                $this->evidence(array_slice($property->writes, 0, 4)),
            );
        }

        // Never written after construction, but what the property *is* — static,
        // hooked, or indirectly modified — puts readonly out of reach. Only here is
        // the weaker form the right answer rather than a downgrade.
        $blockers = [...$blockers, ...array_map(
            static fn(WriteFact $w): string => sprintf(
                'indirect modification (%s) at %s:%d — readonly rejects it even in the constructor',
                $w->kind,
                $w->file,
                $w->line,
            ),
            $unconditional,
        )];

        return new PropertyVerdict(
            $property,
            'should-be-private-set',
            $this->satisfiesSetVisibility($property, $recommendedSet) && $property->type !== null,
            $current,
            $this->setVisibilityRecommendation($property, $recommendedSet, ' — readonly is not reachable in this shape'),
            'never written after construction, but readonly is blocked by the declaration itself',
            $blockers,
            $this->evidence($unconditional !== [] ? $unconditional : $property->writes),
        );
    }

    /**
     * What to actually change, given what the declaration already says.
     *
     * A `private` property needs nothing added: private already restricts writes at
     * least as tightly as `private(set)` would, and proposing it anyway would be noise.
     */
    private function setVisibilityRecommendation(
        PropertyFact $property,
        string $recommended,
        string $suffix = '',
    ): string {
        if ($property->type === null) {
            return sprintf('declare a type, then %s(set)', $recommended);
        }

        if ($this->satisfiesSetVisibility($property, $recommended)) {
            return $property->setVisibility !== null
                ? 'no change — the declared set visibility already matches'
                : sprintf(
                    'no change — %s already restricts writes at least as tightly as %s(set)',
                    $property->getVisibility,
                    $recommended,
                );
        }

        return sprintf('%s(set)%s', $recommended, $suffix);
    }

    /**
     * Why `readonly` is not legal as the property stands, split by whether the
     * obstacle can be removed.
     *
     * The distinction decides the verdict. A missing type or a default value is a
     * detail of the declaration that a small edit removes, so the property still
     * *should* be readonly and saying `private(set)` instead would be the downgrade
     * this gate exists to catch. Being static or hooked is a decision about what the
     * property is, and no edit short of changing that reaches readonly.
     *
     * @return array{hard: list<string>, soft: list<string>}
     */
    private function readonlyBlockers(ClassFact $class, PropertyFact $property): array
    {
        $hard = [];
        $soft = [];

        // An interface requiring `set` on this property fixes the access level for every
        // implementation. readonly forbids writes from outside the declaring scope, so a
        // class cannot satisfy both — recommending readonly here produces a declaration
        // PHP refuses to compile.
        $requiredBy = $this->interfaceRequiringSet($class, $property->name);

        if ($requiredBy !== null) {
            $hard[] = sprintf(
                '%s requires `set` access on $%s — readonly cannot satisfy the interface',
                $requiredBy,
                $property->name,
            );
        }

        if ($property->type === null) {
            $soft[] = 'readonly requires a declared type — add one first';
        }

        if ($property->hasDefault) {
            $soft[] = 'readonly rejects a default value — move it into the constructor, '
                . 'and check every construction path initialises the property exactly once';
        }

        if ($property->isStatic) {
            $hard[] = 'readonly is rejected on static properties';
        }

        if ($property->isHooked) {
            $hard[] = 'readonly is rejected on hooked properties';
        }

        return ['hard' => $hard, 'soft' => $soft];
    }

    /**
     * The first interface in the class's hierarchy that requires `set` on this property.
     *
     * @param list<string> $seen guards the cycle an `extends` chain can form
     */
    private function interfaceRequiringSet(ClassFact $class, string $property, array $seen = []): ?string
    {
        foreach ($class->interfaces as $name) {
            if (in_array($name, $seen, true)) {
                continue;
            }

            $seen[] = $name;
            $interface = $this->index->get($name);

            if ($interface === null) {
                continue;
            }

            if (($interface->propertyRequirements[$property] ?? false) === true) {
                return $name;
            }

            $inherited = $this->interfaceRequiringSet($interface, $property, $seen);

            if ($inherited !== null) {
                return $inherited;
            }
        }

        $parent = $class->parent !== null ? $this->index->get($class->parent) : null;

        return $parent !== null ? $this->interfaceRequiringSet($parent, $property, $seen) : null;
    }

    private function satisfiesSetVisibility(PropertyFact $property, string $recommended): bool
    {
        $effective = $property->effectiveSetVisibility();

        return match ($recommended) {
            'private' => $effective === 'private',
            'protected' => in_array($effective, ['private', 'protected'], true),
            default => true,
        };
    }

    private function describeCurrent(ClassFact $class, PropertyFact $property): string
    {
        $parts = [];

        if ($class->isReadonly) {
            $parts[] = '(readonly class)';
        }

        $parts[] = $property->getVisibility;

        if ($property->setVisibility !== null) {
            $parts[] = $property->setVisibility . '(set)';
        }

        if ($property->isStatic) {
            $parts[] = 'static';
        }

        if ($property->isReadonly) {
            $parts[] = 'readonly';
        }

        $parts[] = $property->type ?? '(untyped)';
        $parts[] = '$' . $property->name;

        if ($property->isPromoted) {
            $parts[] = '[promoted]';
        }

        if ($property->isHooked) {
            $parts[] = $property->isVirtual ? '[virtual]' : '[hooked]';
        }

        return implode(' ', $parts);
    }

    /**
     * @param list<WriteFact>|array<int, WriteFact> $writes
     * @return list<string>
     */
    private function evidence(array $writes): array
    {
        $out = [];

        foreach ($writes as $write) {
            $out[] = sprintf(
                '%s:%d %s in %s%s',
                $write->file,
                $write->line,
                $write->kind,
                $write->writerClass ?? 'function scope',
                $write->inClosure ? ' (inside a closure)' : '',
            );
        }

        return array_values(array_unique($out));
    }
}

// ---------------------------------------------------------------------------
// SubstitutabilityAnalyzer — QUESTION 1
// ---------------------------------------------------------------------------

final class SiteVerdict
{
    public function __construct(
        public readonly DependencySite $site,
        public readonly string $verdict,
        public readonly string $targetKind,
        public readonly bool $targetIsFinal,
        public readonly bool $targetIsAbstract,
        public readonly bool $targetIsFirstParty,
        public readonly string $note,
    ) {}
}

final class SubstitutabilityAnalyzer
{
    public function __construct(
        private readonly Index $index,
        private readonly DataTypeRule $dataRule,
    ) {}

    public function verdictFor(DependencySite $site): SiteVerdict
    {
        $target = $this->index->get($site->targetFqcn);
        $category = $this->dataRule->categorise($site->targetFqcn);

        if ($target === null) {
            $external = $this->index->external($site->targetFqcn);

            if ($external === null) {
                return new SiteVerdict($site, 'unknown', 'unknown', false, false, false, 'type could not be resolved');
            }

            $verdict = match (true) {
                $external['kind'] === 'interface' => 'interface',
                in_array($category, ['enum', 'exception'], true) => 'data',
                $external['final'] => 'external-final-concrete',
                default => 'external-concrete',
            };

            return new SiteVerdict(
                $site,
                $verdict,
                $external['kind'],
                $external['final'],
                $external['abstract'],
                false,
                in_array($verdict, ['interface', 'data'], true)
                    ? ''
                    : 'declared outside this repository; finality is not ours to change',
            );
        }

        if ($target->kind === 'interface') {
            return new SiteVerdict($site, 'interface', 'interface', false, false, true, '');
        }

        if ($target->kind === 'trait') {
            return new SiteVerdict($site, 'unknown', 'trait', false, false, true, 'a trait cannot be used as a type');
        }

        if (in_array($category, ['enum', 'exception', 'value-object'], true)) {
            return new SiteVerdict(
                $site,
                'data',
                $target->kind,
                $target->isFinal,
                $target->isAbstract,
                true,
                $category,
            );
        }

        if ($target->isFinal) {
            return new SiteVerdict(
                $site,
                'final-concrete',
                'class',
                true,
                false,
                true,
                'a consumer can neither implement nor extend this: no decorator is possible',
            );
        }

        if ($target->isAbstract) {
            return new SiteVerdict(
                $site,
                'abstract-concrete',
                'class',
                false,
                true,
                true,
                'substitution requires extending an abstract base rather than implementing a contract',
            );
        }

        return new SiteVerdict(
            $site,
            'open-concrete',
            'class',
            false,
            false,
            true,
            'decoration works only by inheritance, which couples the decorator to the internals it wraps',
        );
    }
}

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

/**
 * @return list<string>
 */
function discoverRoots(string $rootDir): array
{
    $roots = [];

    if (is_dir($rootDir . '/src')) {
        $roots[] = $rootDir . '/src';
    }

    // An extension is a directory carrying a pulsar.json manifest (ADR-0004); its PHP
    // lives in <extension>/src. Deriving the list from the manifests rather than from
    // a hard-coded array keeps it correct when an extension is added, and skips the
    // frontend/src trees, which hold TypeScript.
    foreach (['/extensions/*/pulsar.json', '/extensions/*/*/pulsar.json'] as $pattern) {
        foreach (glob($rootDir . $pattern) ?: [] as $manifest) {
            $candidate = dirname($manifest) . '/src';

            if (is_dir($candidate)) {
                $roots[] = $candidate;
            }
        }
    }

    sort($roots);

    return array_values(array_unique($roots));
}

/**
 * @param list<string> $roots
 * @return list<string>
 */
function collectPhpFiles(array $roots): array
{
    $files = [];

    foreach ($roots as $root) {
        if (is_file($root)) {
            $files[] = $root;

            continue;
        }

        if (!is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return array_values(array_unique($files));
}

function relativePath(string $path, string $rootDir): string
{
    $normalised = str_replace('\\', '/', $path);
    $root = str_replace('\\', '/', $rootDir) . '/';

    return str_starts_with($normalised, $root) ? substr($normalised, strlen($root)) : $normalised;
}

/** @var list<string> $arguments */
$arguments = array_values(array_filter($argv ?? [], 'is_string'));
array_shift($arguments);

$rootDir = dirname(__DIR__, 2);
$baselinePath = $rootDir . '/tools/php/substitutability-baseline.json';

$jsonOutput = in_array('--json', $arguments, true);
$strict = in_array('--strict', $arguments, true);
$generateBaseline = in_array('--generate-baseline', $arguments, true);
$timing = in_array('--timing', $arguments, true);
$phaseStart = microtime(true);

$phase = static function (string $label) use ($timing, &$phaseStart): void {
    if ($timing) {
        fwrite(STDERR, sprintf("[timing] %-24s %6.2fs\n", $label, microtime(true) - $phaseStart));
    }

    $phaseStart = microtime(true);
};
$question = 'both';
$indexOverride = null;
$reportPaths = [];

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--question=')) {
        $question = substr($argument, strlen('--question='));

        continue;
    }

    if (str_starts_with($argument, '--index=')) {
        $indexOverride = array_values(array_filter(explode(',', substr($argument, strlen('--index=')))));

        continue;
    }

    if (str_starts_with($argument, '--baseline=')) {
        $baselinePath = substr($argument, strlen('--baseline='));

        continue;
    }

    if (str_starts_with($argument, '--')) {
        if (!in_array($argument, ['--json', '--strict', '--generate-baseline', '--timing'], true)) {
            fwrite(STDERR, sprintf("Unknown option: %s\n", $argument));

            exit(2);
        }

        continue;
    }

    $reportPaths[] = $argument;
}

if (!in_array($question, ['1', '2', 'both'], true)) {
    fwrite(STDERR, "--question must be 1, 2 or both\n");

    exit(2);
}

$indexRoots = $indexOverride === null
    ? discoverRoots($rootDir)
    : array_map(static fn(string $p): string => str_starts_with($p, '/') || preg_match('/^[A-Za-z]:/', $p) === 1
        ? $p
        : $rootDir . '/' . trim($p, '/'), $indexOverride);

$indexFiles = collectPhpFiles($indexRoots);

if ($indexFiles === []) {
    fwrite(STDERR, "No PHP files found to index. Check --index= or the repository layout.\n");

    exit(2);
}

$reportFilter = null;

if ($reportPaths !== []) {
    $resolved = array_map(
        static fn(string $p): string => str_starts_with($p, '/') || preg_match('/^[A-Za-z]:/', $p) === 1
            ? $p
            : $rootDir . '/' . trim($p, '/'),
        $reportPaths,
    );
    $reportFilter = array_map(
        static fn(string $p): string => str_replace('\\', '/', $p),
        collectPhpFiles($resolved),
    );

    if ($reportFilter === []) {
        fwrite(STDERR, "None of the given paths contain PHP files.\n");

        exit(2);
    }
}

$parser = new ParserFactory()->createForNewestSupportedVersion();
$collector = new Collector();
$finder = new NodeFinder();

foreach ($indexFiles as $file) {
    $code = file_get_contents($file);

    if ($code === false) {
        $collector->parseErrors[] = ['file' => $file, 'detail' => 'unreadable'];

        continue;
    }

    try {
        $statements = $parser->parse($code);
    } catch (Throwable $e) {
        $collector->parseErrors[] = ['file' => $file, 'detail' => $e->getMessage()];

        continue;
    }

    if ($statements === null) {
        $collector->parseErrors[] = ['file' => $file, 'detail' => 'parser returned no statements'];

        continue;
    }

    $collector->fileCount++;

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new NameResolver());
    $traverser->addVisitor(new FileVisitor($collector, relativePath($file, $rootDir), $finder));
    $traverser->traverse($statements);
}

$phase('parse + collect');

$index = new Index($collector);
$index->attributeWrites();

$phase('attribute writes');

$mutability = new MutabilityAnalyzer($index);
$dataRule = new DataTypeRule($index, $mutability);
$substitutability = new SubstitutabilityAnalyzer($index, $dataRule);

// Recorded paths are repository-relative, except for files outside the repository
// (a fixture directory passed to --index), which stay absolute. Rebuild both shapes
// the same way or the filter silently matches nothing.
$inReport = static function (string $file) use ($reportFilter, $rootDir): bool {
    if ($reportFilter === null) {
        return true;
    }

    $normalised = str_replace('\\', '/', $file);
    $absolute = preg_match('#^([A-Za-z]:/|/)#', $normalised) === 1
        ? $normalised
        : str_replace('\\', '/', $rootDir) . '/' . $normalised;

    return in_array($absolute, $reportFilter, true);
};

// -- run question 1 ---------------------------------------------------------

/** @var list<SiteVerdict> $siteVerdicts */
$siteVerdicts = [];
$siteCounts = [];

if ($question !== '2') {
    foreach ($collector->sites as $site) {
        if (!$inReport($site->file)) {
            continue;
        }

        $verdict = $substitutability->verdictFor($site);
        $siteVerdicts[] = $verdict;
        $siteCounts[$verdict->verdict] = ($siteCounts[$verdict->verdict] ?? 0) + 1;
    }
}

$phase('question 1');

// -- run question 2 ---------------------------------------------------------

/** @var list<array{class:ClassFact,verdict:PropertyVerdict}> $propertyVerdicts */
$propertyVerdicts = [];
$propertyCounts = [];
$nonCompliant = [];

if ($question !== '1') {
    foreach ($collector->classes as $class) {
        if (!$inReport($class->file)) {
            continue;
        }

        foreach ($class->properties as $property) {
            $verdict = $mutability->verdictFor($class, $property);
            $propertyVerdicts[] = ['class' => $class, 'verdict' => $verdict];
            $key = $verdict->verdict . ($verdict->compliant ? ' (already)' : '');
            $propertyCounts[$key] = ($propertyCounts[$key] ?? 0) + 1;

            if (!$verdict->compliant) {
                $nonCompliant[] = ['class' => $class, 'verdict' => $verdict];
            }
        }
    }
}

$phase('question 2');

// -- baseline ---------------------------------------------------------------

$baselineKey = static function (string $kind, string $file, int $line, string $subject): string {
    return $kind . '|' . $file . '|' . $line . '|' . $subject;
};

$baseline = [];

if (!$generateBaseline && is_file($baselinePath)) {
    /** @var mixed $decoded */
    $decoded = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
    $entries = is_array($decoded) && isset($decoded['entries']) && is_array($decoded['entries'])
        ? $decoded['entries']
        : [];

    /** @var mixed $entry */
    foreach ($entries as $entry) {
        if (is_string($entry)) {
            $baseline[$entry] = true;
        }
    }
}

$blockingSiteVerdicts = $strict
    ? ['final-concrete', 'open-concrete', 'abstract-concrete', 'external-final-concrete']
    : ['final-concrete'];

$violations = [];

foreach ($siteVerdicts as $verdict) {
    if (!in_array($verdict->verdict, $blockingSiteVerdicts, true)) {
        continue;
    }

    $key = $baselineKey('q1', $verdict->site->file, $verdict->site->line, $verdict->site->targetFqcn);

    if (isset($baseline[$key])) {
        continue;
    }

    $violations[] = ['key' => $key, 'kind' => 'q1', 'verdict' => $verdict];
}

foreach ($nonCompliant as $entry) {
    $verdict = $entry['verdict'];
    $key = $baselineKey(
        'q2',
        $verdict->property->file,
        $verdict->property->line,
        $verdict->property->declaringClass . '::$' . $verdict->property->name,
    );

    if (isset($baseline[$key])) {
        continue;
    }

    $violations[] = ['key' => $key, 'kind' => 'q2', 'verdict' => $verdict];
}

if ($generateBaseline) {
    $payload = [
        'generated' => date('c'),
        'note' => 'Entries accepted as pre-existing. Removing an entry re-arms the gate for that site.',
        'entries' => array_values(array_map(static fn(array $v): string => (string) $v['key'], $violations)),
    ];
    file_put_contents($baselinePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    printf("Baseline written: %s (%d entries)\n", relativePath($baselinePath, $rootDir), count($violations));

    exit(0);
}

// -- report -----------------------------------------------------------------

if ($jsonOutput) {
    $payload = [
        'generated' => date('c'),
        'scanned' => [
            'files' => $collector->fileCount,
            'classes' => count($collector->classes),
            'roots' => array_map(static fn(string $r): string => relativePath($r, $rootDir), $indexRoots),
            'reportPaths' => $reportPaths,
        ],
        'substitutability' => [
            'summary' => $siteCounts,
            'sites' => array_map(static fn(SiteVerdict $v): array => [
                'file' => $v->site->file,
                'line' => $v->site->line,
                'dependingClass' => $v->site->dependingClass,
                'siteKind' => $v->site->siteKind,
                'member' => $v->site->member,
                'dependsOn' => $v->site->targetFqcn,
                'verdict' => $v->verdict,
                'targetKind' => $v->targetKind,
                'final' => $v->targetIsFinal,
                'abstract' => $v->targetIsAbstract,
                'firstParty' => $v->targetIsFirstParty,
                'note' => $v->note,
            ], $siteVerdicts),
        ],
        'mutability' => [
            'summary' => $propertyCounts,
            'properties' => array_map(static fn(array $e): array => [
                'file' => $e['verdict']->property->file,
                'line' => $e['verdict']->property->line,
                'class' => $e['verdict']->property->declaringClass,
                'property' => '$' . $e['verdict']->property->name,
                'verdict' => $e['verdict']->verdict,
                'compliant' => $e['verdict']->compliant,
                'current' => $e['verdict']->current,
                'recommendation' => $e['verdict']->recommendation,
                'reason' => $e['verdict']->reason,
                'blockers' => $e['verdict']->blockers,
                'evidence' => $e['verdict']->evidence,
                'undecidable' => $e['verdict']->property->undecidable,
            ], $propertyVerdicts),
        ],
        'undecidable' => [
            'dynamicWrites' => $collector->dynamic,
            'unattributedWrites' => $index->unattributed,
            'inferenceConflicts' => $index->inferenceConflicts,
            'parseErrors' => $collector->parseErrors,
        ],
        'violations' => count($violations),
    ];

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

    exit($violations === [] ? 0 : 1);
}

printf(
    "Indexed %d file(s), %d class-likes, over: %s\n",
    $collector->fileCount,
    count($collector->classes),
    implode(', ', array_map(static fn(string $r): string => relativePath($r, $rootDir), $indexRoots)),
);

if ($reportPaths !== []) {
    printf("Reporting restricted to: %s\n", implode(', ', $reportPaths));
}

if ($question !== '2') {
    echo "\n== QUESTION 1 — interface reachability ==\n\n";

    $labels = [
        'final-concrete' => 'final concrete class       BLOCKS decoration outright',
        'abstract-concrete' => 'abstract class             substitution only by extension',
        'open-concrete' => 'non-final concrete class   decoration only by inheritance',
        'interface' => 'interface                  fine',
        'data' => 'value object / enum / exc  legitimate concrete dependency',
        'external-final-concrete' => 'external final class       not ours to change',
        'external-concrete' => 'external concrete class    not ours to change',
        'unknown' => 'unresolved                 reported below',
    ];

    foreach ($labels as $key => $label) {
        printf("  %-6d %s\n", $siteCounts[$key] ?? 0, $label);
    }

    foreach (['final-concrete', 'abstract-concrete', 'open-concrete', 'external-final-concrete', 'unknown'] as $group) {
        $rows = array_values(array_filter($siteVerdicts, static fn(SiteVerdict $v): bool => $v->verdict === $group));

        if ($rows === []) {
            continue;
        }

        printf("\n  -- %s (%d)\n", $group, count($rows));

        foreach ($rows as $row) {
            printf(
                "  %s:%d  %s  %s %s  ->  %s%s\n",
                $row->site->file,
                $row->site->line,
                $row->site->dependingClass,
                $row->site->siteKind,
                $row->site->member,
                $row->site->targetFqcn,
                $row->targetIsFinal ? ' [final]' : ($row->targetIsAbstract ? ' [abstract]' : ''),
            );
        }
    }
}

if ($question !== '1') {
    echo "\n== QUESTION 2 — property mutability ==\n\n";

    ksort($propertyCounts);

    foreach ($propertyCounts as $key => $count) {
        printf("  %-6d %s\n", $count, $key);
    }

    foreach (['externally-written', 'should-be-readonly', 'should-be-private-set', 'already-immutable'] as $group) {
        $rows = array_values(array_filter(
            $propertyVerdicts,
            static fn(array $e): bool => $e['verdict']->verdict === $group && !$e['verdict']->compliant,
        ));

        if ($rows === []) {
            continue;
        }

        printf("\n  -- %s, not yet expressed in the code (%d)\n", $group, count($rows));

        foreach ($rows as $row) {
            $verdict = $row['verdict'];
            printf(
                "  %s:%d  %s::$%s\n      is:   %s\n      make: %s  (%s)\n",
                $verdict->property->file,
                $verdict->property->line,
                $verdict->property->declaringClass,
                $verdict->property->name,
                $verdict->current,
                $verdict->recommendation,
                $verdict->reason,
            );

            foreach ($verdict->blockers as $blocker) {
                printf("      note: %s\n", $blocker);
            }

            foreach ($verdict->evidence as $evidence) {
                printf("      at:   %s\n", $evidence);
            }

            foreach ($verdict->property->undecidable as $undecided) {
                printf("      ?:    %s:%d %s\n", $undecided['file'], $undecided['line'], $undecided['detail']);
            }
        }
    }
}

$undecidableTotal = count($index->unattributed)
    + count($index->inferenceConflicts)
    + count($collector->parseErrors)
    + count($collector->dynamic);

if ($undecidableTotal > 0) {
    printf("\n== Undecided (%d) ==\n\n", $undecidableTotal);

    foreach ($collector->parseErrors as $error) {
        printf("  parse   %s: %s\n", $error['file'], $error['detail']);
    }

    foreach ($collector->dynamic as $entry) {
        printf("  dynamic %s:%d %s in %s\n", $entry['file'], $entry['line'], $entry['detail'], $entry['class']);
    }

    foreach ($index->unattributed as $entry) {
        printf("  write   %s:%d %s\n", $entry['file'], $entry['line'], $entry['detail']);
    }

    foreach ($index->inferenceConflicts as $entry) {
        printf("  infer   %s:%d %s\n", $entry['file'], $entry['line'], $entry['detail']);
    }
}

if ($violations !== []) {
    fwrite(STDERR, sprintf(
        "\nFAIL: %d unbaselined finding(s).\n\n"
        . "Each one is either a seam a consumer cannot reach, or state the owner does not\n"
        . "control. Fix them, or record the current position with:\n"
        . "  php tools/ci/assert-substitutability-and-immutability.php --generate-baseline\n"
        . "and shrink %s from there.\n",
        count($violations),
        relativePath($baselinePath, $rootDir),
    ));

    exit(1);
}

printf(
    "\nOK: no unbaselined findings (%d dependency site(s), %d propert%s judged).\n",
    count($siteVerdicts),
    count($propertyVerdicts),
    count($propertyVerdicts) === 1 ? 'y' : 'ies',
);

exit(0);
