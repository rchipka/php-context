<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

function calc($array) {
    $total = 0;

    foreach ($array as $depth => $amount) {
        $total += $amount;// * ($depth + 1);
    }

    return $total;// / (sizeof($array));
}

final class ContextTest extends TestCase {
    public function testContextFunction(): void {
        $this->assertInstanceOf(
            Context::class,
            context()
        );
    }

    public function testExplicitEntryExit(): void {
        context('root')->enter();

        $this->assertContains('root', context()->keys);

        context()->exit();
    }

    public function testImplicitEntryExit(): void {
        context('root', function () {
            $this->assertContains('root', context()->keys);
        });
    }

    public function testMatching(): void {

        context('root')->enter();

            context(['mid', 'node'])->enter();

                context(['a', 'child'])->enter();

                $this->assertEquals(calc([1, 0, 0.5]), context()->match([['root'], 'child']));

                $this->assertEquals(calc([1, 0, 0.5]), context()->match([['root'], ['child']]));

                $this->assertEquals(calc([1, 0, 1]), context()->match([['root'], ['a', 'child']]));

                $this->assertEquals(1, context()->match('root'));

                $this->assertEquals(1, context()->match(['root']));

                $this->assertEquals(1, context()->match([['root']]));

                $this->assertEquals(0, context()->match([[['root']]]));

                $this->assertEquals(0, context()->match('nope'));

                $this->assertEquals(0, context()->match(['nope']));

                $this->assertEquals(0, context()->match([['nope']]));

                $this->assertEquals(0, context()->match([['root'], 'nope']));

                $this->assertEquals(0, context()->match([['root'], ['nope']]));

                context()->exit();

            context()->exit();

        context()->exit();
    }

    public function testWithin(): void {

        context('root')->enter();

            context(['mid', 'node'])->enter();

                context(['a', 'child'])->enter();

                $this->assertEquals(calc([1]), context()->within([['root']]));

                $this->assertEquals(calc([0.5]), context()->within([['mid']]));

                $this->assertEquals(calc([1]), context()->within([['mid', 'node']]));

                $this->assertEquals(calc([1, 0.5]), context()->within([['root'], 'mid']));

                context()->exit();

            context()->exit();

        context()->exit();
    }

    public function testGetSet(): void {

        context('root')->enter();

            context(['mid', 'node'])->enter();

                context()->set('test', 123);

                $this->assertEquals(123, context()->get('test'));

                context(['a', 'child'])->enter();
                
                $this->assertEquals(123, context()->get('test'));

                context()->exit();

            context()->exit();

        context()->exit();
    }
}
