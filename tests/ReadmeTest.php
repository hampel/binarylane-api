<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Client;
use Hampel\BinaryLane\Api\Endpoint\Endpoint;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * The README is documentation for people who cannot read the source, which makes a wrong
 * example worse than a missing one: it is copied, it fails, and the reader concludes the
 * package is broken rather than that the example is.
 *
 * Renaming a method is also exactly the kind of change that passes every other test in this
 * suite - the suite calls the new name, and only the README still calls the old one.
 *
 * So this reads the README and checks that everything it claims exists. It cannot check that
 * the examples are CORRECT; it can check they are not stale, which is the failure that
 * actually happens.
 */
final class ReadmeTest extends BaseTestCase
{
    private function readme(): string
    {
        $path = dirname(__DIR__) . '/README.md';

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * Every `$binarylane->accessor()->method()` in the README resolves.
     */
    public function testEveryDocumentedEndpointCallExists(): void
    {
        preg_match_all('/\$binarylane->(\w+)\(\)->(\w+)\(/', $this->readme(), $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches, 'the README should show some endpoint calls');

        $checked = 0;

        foreach ($matches as [, $accessor, $method]) {
            $this->assertTrue(
                method_exists(Client::class, $accessor),
                sprintf('the README calls $binarylane->%s(), which Client does not have', $accessor)
            );

            $returns = (string) (new \ReflectionMethod(Client::class, $accessor))->getReturnType();

            $this->assertTrue(
                class_exists($returns),
                sprintf('$binarylane->%s() does not return a class', $accessor)
            );

            $this->assertTrue(
                method_exists($returns, $method),
                sprintf('the README calls $binarylane->%s()->%s(), which %s does not have', $accessor, $method, $returns)
            );

            $checked++;
        }

        $this->assertGreaterThan(20, $checked, 'the README should document rather more than this');
    }

    /**
     * Every class the README names in a `use` statement exists, and every method it calls
     * statically on one is really there.
     */
    public function testEveryDocumentedClassExists(): void
    {
        $readme = $this->readme();

        preg_match_all('/^use (Hampel\\\\BinaryLane\\\\Api\\\\[\w\\\\]+)(?: as \w+)?;$/m', $readme, $uses);

        $this->assertNotEmpty($uses[1]);

        foreach ($uses[1] as $class) {
            $this->assertTrue(
                class_exists($class) || interface_exists($class) || enum_exists($class),
                sprintf('the README imports %s, which does not exist', $class)
            );
        }

        // Static calls written as ClassName::method(
        preg_match_all('/\b([A-Z]\w+)::(\w+)\(/', $readme, $statics, PREG_SET_ORDER);

        $imported = [];

        foreach ($uses[1] as $class) {
            $imported[substr((string) strrchr($class, '\\'), 1)] = $class;
        }

        foreach ($statics as [, $short, $method]) {
            if (!isset($imported[$short])) {
                continue;
            }

            $class = $imported[$short];

            $this->assertTrue(
                method_exists($class, $method) || defined($class . '::' . $method),
                sprintf('the README calls %s::%s(), which does not exist', $short, $method)
            );
        }
    }

    /**
     * Every exception the README's failure table names exists and is one of ours.
     */
    public function testEveryDocumentedExceptionExists(): void
    {
        preg_match_all('/`(\w+Exception)`/', $this->readme(), $matches);

        $names = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($names);

        foreach ($names as $name) {
            $class = 'Hampel\\BinaryLane\\Api\\Exception\\' . $name;

            $this->assertTrue(class_exists($class), sprintf('the README names %s, which does not exist', $name));
            $this->assertTrue(
                is_subclass_of($class, \Hampel\BinaryLane\Api\Exception\ExceptionInterface::class),
                sprintf('%s does not implement the package exception interface', $name)
            );
        }
    }

    /**
     * The README claims complete coverage of the API and names a count of server actions.
     * Both are the kind of claim that rots quietly.
     */
    public function testTheServerActionCountItClaimsIsTheCountItHas(): void
    {
        $this->assertStringContainsString('forty-two server actions', $this->readme());

        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Endpoint/ServerActions.php');

        preg_match_all("/\\\$this->perform\(\\\$serverId, '(\w+)'/", $source, $matches);

        $this->assertCount(
            42,
            array_unique($matches[1]),
            'ServerActions should send exactly the 42 discriminator values the specification declares'
        );
    }

    /**
     * The README's extension example subclasses Endpoint, which is the documented seam.
     */
    public function testTheExtensionPointItDocumentsIsReal(): void
    {
        $readme = $this->readme();

        $this->assertStringContainsString('extends Endpoint', $readme);
        $this->assertStringContainsString('$binarylane->endpoint(', $readme);
        $this->assertTrue((new \ReflectionClass(Endpoint::class))->isAbstract());
        $this->assertSame(
            Endpoint::class,
            (string) (new \ReflectionMethod(Client::class, 'endpoint'))->getReturnType(),
            'endpoint() should hand back the documented base class'
        );
    }
}
