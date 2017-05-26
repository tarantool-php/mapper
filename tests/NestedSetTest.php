<?php

use Tarantool\Mapper\Plugin\NestedSet;
use Tarantool\Mapper\Plugin\Sequence;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Repository;

class NestedSetTest extends TestCase
{
    public function testMappingCasting()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->addPlugin(Sequence::class);
        $nested = $mapper->addPlugin(NestedSet::class);

        $space = $mapper->getSchema()->createSpace('tree', [
            'id' => 'unsigned',
            'parent' => 'unsigned',
            'root' => 'unsigned',
            'depth' => 'unsigned',
            'left' => 'unsigned',
            'right' => 'unsigned',
        ]);

        $nested->addIndexes($space);

        $node1 = $mapper->create('tree', []);
        $this->assertSame($node1->left, 1);
        $this->assertSame($node1->right, 2);

        $node2 = $mapper->create('tree', []);
        $this->assertSame($node2->left, 3);
        $this->assertSame($node2->right, 4);

        $node3 = $mapper->create('tree', ['parent' => 2]);
        $this->assertSame($node3->depth, 1);
        $this->assertSame($node3->left, 4);
        $this->assertSame($node3->right, 5);
        $this->assertSame($node2->right, 6);

        $node4 = $mapper->create('tree', ['parent' => 3]);
        $this->assertSame($node4->depth, 2);
        $this->assertSame($node4->left, 5);
        $this->assertSame($node4->right, 6);
        $this->assertSame($node3->right, 7);
        $this->assertSame($node2->right, 8);

        $node5 = $mapper->create('tree', ['parent' => 1]);
        $this->assertSame($node5->left, 2);
        $this->assertSame($node5->right, 3);
        $this->assertSame($node2->right, 10);
        $mapper->remove($node1);

        $this->assertCount(3, $mapper->find('tree'));
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 6);

        $node7 = $mapper->create('tree', ['parent' => 2]);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 8);

        $node8 = $mapper->create('tree', ['parent' => 2]);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 10);

        $node9 = $mapper->create('tree', ['parent' => 7]);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 12);

        // new root test

        $node1 = $mapper->create('tree', ['root' => 1]);
        $this->assertSame($node1->left, 1);
        $this->assertSame($node1->right, 2);

        $node2 = $mapper->create('tree', ['root' => 1]);
        $this->assertSame($node2->left, 3);
        $this->assertSame($node2->right, 4);

        $node3 = $mapper->create('tree', ['parent' => $node2->id, 'root' => 1]);
        $this->assertSame($node3->depth, 1);
        $this->assertSame($node3->left, 4);
        $this->assertSame($node3->right, 5);
        $this->assertSame($node2->right, 6);

        $node4 = $mapper->create('tree', ['parent' => $node3->id, 'root' => 1]);
        $this->assertSame($node4->depth, 2);
        $this->assertSame($node4->left, 5);
        $this->assertSame($node4->right, 6);
        $this->assertSame($node3->right, 7);
        $this->assertSame($node2->right, 8);

        $node5 = $mapper->create('tree', ['parent' => $node1->id, 'root' => 1]);
        $this->assertSame($node5->left, 2);
        $this->assertSame($node5->right, 3);
        $this->assertSame($node2->right, 10);
        $mapper->remove($node1);

        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 6);

        $node7 = $mapper->create('tree', ['parent' => $node2->id, 'root' => 1]);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 8);

        $node8 = $mapper->create('tree', ['parent' => $node2->id, 'root' => 1]);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 10);

        $node9 = $mapper->create('tree', ['parent' => $node7->id, 'root' => 1]);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 12);

    }
}
